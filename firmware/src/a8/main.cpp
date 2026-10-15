#include <Arduino.h>
#include <ETH.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <SPIFFS.h>
#include <Update.h>
#include <WebServer.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <Wire.h>
#include <esp_ota_ops.h>
#include <mbedtls/sha256.h>

#include <ArduinoJson.h>

#include <algorithm>
#include <ctime>
#include <esp_timer.h>
#include <vector>

#define EZG_BOARD_TYPE "Kincony KC868-A8"
#define EZG_FIRMWARE_VERSION "1.0.1"
const char EZG_VERSION_TAG[] __attribute__((used)) = "EZGVER:" EZG_FIRMWARE_VERSION;
#define EZG_PORTAL_HOST "ezgate-dev.stuckbendix.com"
#define EZG_AP_PASSWORD "ezgate-setup"
#define EZG_PCF_ADDR 0x24
#define EZG_LIST_LIMIT 200
#define EZG_KEY_IDLE_MS 200
#define EZG_FRAME_GAP_MS 25

struct RelayCfg {
    int relay;
    int pulseMs;
};

struct Device {
    String id;
    String name;
    int wiegand;
    std::vector<RelayCfg> relays;
    std::vector<String> tags;
    String version;
    String hash;
    String remoteVersion;
    String remoteHash;
    bool replace;
    int pendingCommand;
};

struct CachedRead {
    String readAt;
    String deviceId;
    String tag;
    bool inList;
    uint32_t eventId;
};

Preferences prefs;
WebServer setupServer(80);
String hardwareId;
String configVersion;
int siteId = 0;
int wifiHeartbeatSeconds = 3;
std::vector<String> wifiSsids;
std::vector<String> wifiPasses;
std::vector<Device> devices;
std::vector<CachedRead> cachedReads;
uint8_t relayBits = 0xFF;
uint32_t relayOffAt[8] = {0, 0, 0, 0, 0, 0, 0, 0};
bool setupApOn = false;
bool ethernetLink = false;
uint32_t lastHeartbeatMs = 0;
uint32_t nextEventId = 1;
bool usbAttached = false;

struct WiePort {
    volatile uint64_t bits;
    volatile int count;
    volatile uint32_t lastMs;
    volatile uint64_t readyBits;
    volatile int readyCount;
    int reader;
    String keypad;
    uint32_t keypadMs;
};

WiePort readers[2];
portMUX_TYPE wieMux = portMUX_INITIALIZER_UNLOCKED;

String macId() {
    uint64_t mac = ESP.getEfuseMac();
    char buf[17];
    snprintf(buf, sizeof(buf), "%012llX", (unsigned long long) mac);
    return String(buf);
}

String sha256Lines(const std::vector<String> &tags) {
    std::vector<String> sorted = tags;
    std::sort(sorted.begin(), sorted.end());
    mbedtls_sha256_context ctx;
    mbedtls_sha256_init(&ctx);
    mbedtls_sha256_starts_ret(&ctx, 0);
    for (const String &tag : sorted) {
        mbedtls_sha256_update_ret(&ctx, (const uint8_t *) tag.c_str(), tag.length());
        mbedtls_sha256_update_ret(&ctx, (const uint8_t *) "\n", 1);
    }
    uint8_t out[32];
    mbedtls_sha256_finish_ret(&ctx, out);
    mbedtls_sha256_free(&ctx);
    char hex[65];
    for (int i = 0; i < 32; i++) {
        sprintf(hex + (i * 2), "%02x", out[i]);
    }
    hex[64] = 0;
    return String(hex);
}

void writeRelayBits() {
    Wire.beginTransmission(EZG_PCF_ADDR);
    Wire.write(relayBits);
    Wire.endTransmission();
}

void setRelayOn(int relay, bool on) {
    if (relay < 1 || relay > 8) {
        return;
    }
    uint8_t mask = (uint8_t) (1 << (relay - 1));
    if (on) {
        relayBits &= (uint8_t) ~mask;
    } else {
        relayBits |= mask;
    }
    writeRelayBits();
}

void pulseRelays(const Device &device) {
    for (const RelayCfg &relay : device.relays) {
        int ms = relay.pulseMs >= 50 ? relay.pulseMs : 500;
        setRelayOn(relay.relay, true);
        relayOffAt[relay.relay - 1] = millis() + (uint32_t) ms;
    }
}

void serviceRelayOffs() {
    uint32_t now = millis();
    for (int i = 0; i < 8; i++) {
        if (relayOffAt[i] != 0 && (int32_t) (now - relayOffAt[i]) >= 0) {
            relayOffAt[i] = 0;
            setRelayOn(i + 1, false);
        }
    }
}

bool tagInList(const Device &device, const String &tag) {
    for (const String &have : device.tags) {
        if (have == tag) {
            return true;
        }
    }
    return false;
}

Device *deviceForReader(int reader) {
    for (Device &device : devices) {
        if (device.wiegand == reader) {
            return &device;
        }
    }
    return nullptr;
}

void saveDevices() {
    JsonDocument doc;
    JsonArray arr = doc["devices"].to<JsonArray>();
    for (const Device &device : devices) {
        JsonObject item = arr.add<JsonObject>();
        item["id"] = device.id;
        item["name"] = device.name;
        item["wiegand"] = device.wiegand;
        item["version"] = device.version;
        item["hash"] = device.hash;
        JsonArray relays = item["relays"].to<JsonArray>();
        for (const RelayCfg &relay : device.relays) {
            JsonObject row = relays.add<JsonObject>();
            row["relay"] = relay.relay;
            row["pulse_ms"] = relay.pulseMs;
        }
        JsonArray tags = item["tags"].to<JsonArray>();
        for (const String &tag : device.tags) {
            tags.add(tag);
        }
    }
    File file = SPIFFS.open("/devices.json", "w");
    if (!file) {
        return;
    }
    serializeJson(doc, file);
    file.close();
}

void loadDevices() {
    devices.clear();
    File file = SPIFFS.open("/devices.json", "r");
    if (!file) {
        return;
    }
    JsonDocument doc;
    if (deserializeJson(doc, file)) {
        file.close();
        return;
    }
    file.close();
    for (JsonObjectConst item : doc["devices"].as<JsonArrayConst>()) {
        Device device;
        device.id = item["id"] | "";
        device.name = item["name"] | "";
        device.wiegand = item["wiegand"] | 0;
        device.version = item["version"] | "";
        device.hash = item["hash"] | "";
        device.replace = false;
        device.pendingCommand = 0;
        for (JsonObjectConst row : item["relays"].as<JsonArrayConst>()) {
            RelayCfg relay;
            relay.relay = row["relay"] | 0;
            relay.pulseMs = row["pulse_ms"] | 500;
            if (relay.relay >= 1 && relay.relay <= 8) {
                device.relays.push_back(relay);
            }
        }
        if (!(device.wiegand > 0 && device.relays.empty())) {
            for (JsonVariantConst tag : item["tags"].as<JsonArrayConst>()) {
                String text = tag.as<String>();
                text.trim();
                if (text.length()) {
                    device.tags.push_back(text);
                }
            }
        } else {
            device.version = "";
            device.hash = "";
        }
        if (device.id.length()) {
            devices.push_back(device);
        }
    }
}

void loadPrefs() {
    prefs.begin("ezgate", true);
    configVersion = prefs.getString("cfg_ver", "");
    siteId = prefs.getInt("site_id", 0);
    wifiHeartbeatSeconds = prefs.getInt("hb_sec", 3);
    if (wifiHeartbeatSeconds < 2) {
        wifiHeartbeatSeconds = 3;
    }
    int count = prefs.getInt("wifi_n", 0);
    wifiSsids.clear();
    wifiPasses.clear();
    for (int i = 0; i < count && i < 8; i++) {
        wifiSsids.push_back(prefs.getString(("ssid" + String(i)).c_str(), ""));
        wifiPasses.push_back(prefs.getString(("pass" + String(i)).c_str(), ""));
    }
    prefs.end();
}

void savePrefs() {
    prefs.begin("ezgate", false);
    prefs.putString("cfg_ver", configVersion);
    prefs.putInt("site_id", siteId);
    prefs.putInt("hb_sec", wifiHeartbeatSeconds);
    prefs.putInt("wifi_n", (int) wifiSsids.size());
    for (size_t i = 0; i < wifiSsids.size() && i < 8; i++) {
        prefs.putString(("ssid" + String(i)).c_str(), wifiSsids[i]);
        prefs.putString(("pass" + String(i)).c_str(), wifiPasses[i]);
    }
    prefs.end();
}

void clearStored() {
    prefs.begin("ezgate", false);
    prefs.clear();
    prefs.end();
    SPIFFS.remove("/devices.json");
    devices.clear();
    wifiSsids.clear();
    wifiPasses.clear();
    siteId = 0;
    configVersion = "";
}

bool portalPost(const char *path, const String &body, JsonDocument &response) {
    WiFiClientSecure client;
    client.setInsecure();
    HTTPClient http;
    String url = String("https://") + EZG_PORTAL_HOST + path;
    if (!http.begin(client, url)) {
        return false;
    }
    http.setTimeout(6000);
    http.addHeader("Content-Type", "application/json");
    int code = http.POST(body);
    bool ok = false;
    if (code > 0) {
        ok = deserializeJson(response, http.getString()) == DeserializationError::Ok;
    }
    http.end();
    return ok;
}

void queueRead(const String &deviceId, const String &tag, bool inList) {
    CachedRead row;
    time_t now = time(nullptr);
    if (now > 1700000000) {
        char buf[25];
        struct tm tm;
        gmtime_r(&now, &tm);
        snprintf(buf, sizeof(buf), "%04d-%02d-%02dT%02d:%02d:%02dZ",
            tm.tm_year + 1900, tm.tm_mon + 1, tm.tm_mday, tm.tm_hour, tm.tm_min, tm.tm_sec);
        row.readAt = buf;
    }
    row.deviceId = deviceId;
    row.tag = tag;
    row.inList = inList;
    row.eventId = nextEventId++;
    cachedReads.push_back(row);
}

bool flushReads() {
    if (cachedReads.empty() || (WiFi.status() != WL_CONNECTED && !ethernetLink)) {
        return false;
    }
    JsonDocument body;
    body["hardware_id"] = hardwareId;
    JsonArray reads = body["reads"].to<JsonArray>();
    for (const CachedRead &row : cachedReads) {
        JsonObject item = reads.add<JsonObject>();
        if (row.readAt.length()) {
            item["read_at"] = row.readAt;
        } else {
            item["read_at"] = nullptr;
        }
        item["virtual_device"] = row.deviceId;
        item["accesstag_number"] = row.tag;
        item["in_list"] = row.inList;
        item["board_event_id"] = row.eventId;
    }
    String payload;
    serializeJson(body, payload);
    JsonDocument response;
    if (!portalPost("/api/reads", payload, response) || !(response["success"] | false)) {
        return false;
    }
    JsonArray accepted = response["data"]["accepted"].as<JsonArray>();
    std::vector<CachedRead> left;
    for (const CachedRead &row : cachedReads) {
        bool keep = true;
        for (JsonVariantConst id : accepted) {
            if ((uint32_t) (id.as<unsigned long>()) == row.eventId) {
                keep = false;
                break;
            }
        }
        if (keep) {
            left.push_back(row);
        }
    }
    cachedReads.swap(left);
    return cachedReads.empty();
}

void applyDeviceConfig(Device &device, JsonObjectConst cfg) {
    if (cfg["friendly_name"].is<const char *>()) {
        device.name = String(cfg["friendly_name"].as<const char *>());
    }
    if (!cfg["wiegand"].isNull()) {
        device.wiegand = cfg["wiegand"] | 0;
    }
    if (cfg["relays"].is<JsonArrayConst>()) {
        device.relays.clear();
        for (JsonObjectConst row : cfg["relays"].as<JsonArrayConst>()) {
            RelayCfg relay;
            relay.relay = row["relay"] | 0;
            relay.pulseMs = row["pulse_duration_ms"] | 500;
            if (relay.relay >= 1 && relay.relay <= 8) {
                device.relays.push_back(relay);
            }
        }
    }
    if (device.wiegand > 0 && device.relays.empty()) {
        device.tags.clear();
        device.version = "";
        device.hash = "";
        device.remoteVersion = "";
        device.remoteHash = "";
        device.replace = false;
    }
}

Device *findDevice(const String &id) {
    for (Device &device : devices) {
        if (device.id == id) {
            return &device;
        }
    }
    return nullptr;
}

void noteRemoteList(Device &device, JsonObjectConst item) {
    if (device.wiegand <= 0 || device.relays.empty()) {
        device.remoteVersion = "";
        device.remoteHash = "";
        device.replace = false;
        return;
    }
    if (item["accesstag_list_version"].isNull()) {
        device.remoteVersion = "";
        device.remoteHash = "";
    } else {
        device.remoteVersion = item["accesstag_list_version"].as<String>();
        device.remoteHash = item["accesstag_list_hash"] | "";
    }
    if (item["accesstag_list_replace"] | false) {
        device.replace = true;
    }
}

bool pullList(Device &device) {
    if (device.wiegand <= 0 || device.relays.empty()) {
        return true;
    }
    if (device.remoteVersion == device.version && device.remoteHash == device.hash && !device.replace) {
        return true;
    }
    std::vector<String> staging = device.replace || device.version.length() == 0 ? std::vector<String>() : device.tags;
    int offset = 0;
    String targetVersion = device.remoteVersion;
    String listHash;
    bool replace = device.replace || device.version.length() == 0;
    while (true) {
        JsonDocument body;
        body["hardware_id"] = hardwareId;
        body["virtual_device"] = device.id;
        if (device.version.length() && !replace) {
            body["from_version"] = device.version;
            body["from_hash"] = device.hash;
        } else {
            body["from_version"] = nullptr;
            body["from_hash"] = nullptr;
            body["full"] = true;
        }
        body["offset"] = offset;
        body["limit"] = EZG_LIST_LIMIT;
        String payload;
        serializeJson(body, payload);
        JsonDocument response;
        if (!portalPost("/api/accesstag-list", payload, response) || !(response["success"] | false)) {
            return false;
        }
        JsonObject data = response["data"].as<JsonObject>();
        String version = data["version"] | "";
        if (targetVersion.length() && version != targetVersion) {
            offset = 0;
            staging = device.tags;
            continue;
        }
        targetVersion = version;
        listHash = data["list_hash"] | "";
        if (data["replace"] | false) {
            if (offset == 0) {
                staging.clear();
            }
            replace = true;
        }
        int total = data["total"] | 0;
        JsonArray changes = data["changes"].as<JsonArray>();
        for (JsonObjectConst change : changes) {
            String op = change["op"] | "";
            String tag = change["accesstag"] | "";
            if (op == "remove") {
                staging.erase(std::remove(staging.begin(), staging.end(), tag), staging.end());
            } else if (op == "add" && tag.length()) {
                bool have = false;
                for (const String &existing : staging) {
                    if (existing == tag) {
                        have = true;
                        break;
                    }
                }
                if (!have) {
                    staging.push_back(tag);
                }
            }
        }
        offset += (int) changes.size();
        if (offset >= total || changes.size() == 0) {
            break;
        }
    }
    if (sha256Lines(staging) != listHash) {
        device.replace = true;
        return false;
    }
    device.tags.swap(staging);
    device.version = targetVersion;
    device.hash = listHash;
    device.remoteVersion = targetVersion;
    device.remoteHash = listHash;
    device.replace = false;
    saveDevices();
    return true;
}

void applyHeartbeatData(JsonObject data) {
    if (data["config"].is<JsonObject>()) {
        JsonObject cfg = data["config"].as<JsonObject>();
        if (cfg["version"].is<const char *>()) {
            configVersion = String(cfg["version"].as<const char *>());
        }
        if (cfg["site_id"].is<int>()) {
            siteId = cfg["site_id"].as<int>();
        }
        int seconds = data["wifi_heartbeat_interval_seconds"] | wifiHeartbeatSeconds;
        if (seconds >= 2 && seconds <= 3600) {
            wifiHeartbeatSeconds = seconds;
        }
        if (cfg["wifi_networks"].is<JsonArray>()) {
            wifiSsids.clear();
            wifiPasses.clear();
            for (JsonObject net : cfg["wifi_networks"].as<JsonArray>()) {
                String ssid = net["ssid"] | "";
                ssid.trim();
                if (ssid.length()) {
                    wifiSsids.push_back(ssid);
                    wifiPasses.push_back(net["password"] | "");
                }
            }
        }
        savePrefs();
    }
    int seconds = data["wifi_heartbeat_interval_seconds"] | 0;
    if (seconds >= 2 && seconds <= 3600 && seconds != wifiHeartbeatSeconds) {
        wifiHeartbeatSeconds = seconds;
        savePrefs();
    }
    if (!data["virtual_devices"].is<JsonArray>()) {
        return;
    }
    for (JsonObject item : data["virtual_devices"].as<JsonArray>()) {
        String id = item["id"] | "";
        id.trim();
        if (!id.length()) {
            continue;
        }
        Device *device = findDevice(id);
        if (device == nullptr) {
            Device created;
            created.id = id;
            created.wiegand = 0;
            created.replace = false;
            created.pendingCommand = 0;
            devices.push_back(created);
            device = &devices.back();
        }
        if (item["config"].is<JsonObject>()) {
            applyDeviceConfig(*device, item["config"].as<JsonObjectConst>());
        }
        noteRemoteList(*device, item);
        if (item["open"].is<JsonObject>() && !device->relays.empty()) {
            device->pendingCommand = item["open"]["command_id"] | 0;
            pulseRelays(*device);
        }
    }
    saveDevices();
    for (Device &device : devices) {
        pullList(device);
    }
}

void sendHeartbeat() {
    if (WiFi.status() != WL_CONNECTED && !ethernetLink) {
        return;
    }
    flushReads();
    JsonDocument body;
    body["hardware_id"] = hardwareId;
    body["board_type"] = EZG_BOARD_TYPE;
    body["status"] = ethernetLink ? "ethernet" : (WiFi.status() == WL_CONNECTED ? "wifi" : "isolated");
    body["config_version"] = configVersion;
    body["firmware_version"] = EZG_FIRMWARE_VERSION;
    body["uptime_seconds"] = (unsigned long) (esp_timer_get_time() / 1000000LL);
    body["internet"] = ethernetLink || WiFi.status() == WL_CONNECTED;
    time_t now = time(nullptr);
    if (now > 1700000000) {
        body["unix_time"] = (unsigned long) now;
    }
    JsonObject connectivity = body["connectivity"].to<JsonObject>();
    JsonObject current = connectivity["current"].to<JsonObject>();
    if (ethernetLink) {
        current["type"] = "ethernet";
    } else if (WiFi.status() == WL_CONNECTED) {
        current["type"] = "wifi";
        current["ssid"] = WiFi.SSID();
        current["rssi"] = WiFi.RSSI();
    }
    JsonArray reported = body["virtual_devices"].to<JsonArray>();
    for (const Device &device : devices) {
        JsonObject item = reported.add<JsonObject>();
        item["id"] = device.id;
        if (device.wiegand > 0 && !device.relays.empty() && device.version.length()) {
            item["accesstag_list_version"] = device.version;
            item["accesstag_list_hash"] = device.hash.length() ? device.hash : sha256Lines(device.tags);
        }
        if (device.pendingCommand > 0) {
            item["completed_command_id"] = device.pendingCommand;
            item["received_command_id"] = device.pendingCommand;
        }
    }
    String payload;
    serializeJson(body, payload);
    JsonDocument response;
    if (!portalPost("/api/heartbeat", payload, response) || !(response["success"] | false)) {
        return;
    }
    for (Device &device : devices) {
        device.pendingCommand = 0;
    }
    if (response["data"].is<JsonObject>()) {
        applyHeartbeatData(response["data"].as<JsonObject>());
    }
    if (response["data"]["reboot"] | false) {
        ESP.restart();
    }
}

void handleRead(int reader, const String &tag) {
    Device *device = deviceForReader(reader);
    if (device == nullptr || !tag.length()) {
        return;
    }
    bool listed = !device->relays.empty() && device->version.length() && tagInList(*device, tag);
    if (listed) {
        pulseRelays(*device);
    }
    queueRead(device->id, tag, listed);
    flushReads();
}

String bitsToDecimal(uint64_t bits, int width) {
    uint64_t data = bits;
    if (width == 26) {
        data = (bits >> 1) & 0xFFFFFFULL;
    } else if (width == 34) {
        data = (bits >> 1) & 0xFFFFFFFFULL;
    }
    char buf[24];
    snprintf(buf, sizeof(buf), "%llu", (unsigned long long) data);
    return String(buf);
}

void consumeFrame(WiePort &port) {
    int count = 0;
    uint64_t bits = 0;
    portENTER_CRITICAL(&wieMux);
    count = port.readyCount;
    bits = port.readyBits;
    port.readyCount = 0;
    port.readyBits = 0;
    portEXIT_CRITICAL(&wieMux);
    if (count == 26 || count == 34) {
        port.keypad = "";
        handleRead(port.reader, bitsToDecimal(bits, count));
        return;
    }
    if (count == 4) {
        int digit = (int) (bits & 0xF);
        if (digit <= 9) {
            port.keypad += String(digit);
            port.keypadMs = millis();
        }
    }
}

void serviceKeypad(WiePort &port) {
    if (!port.keypad.length()) {
        return;
    }
    if ((millis() - port.keypadMs) < EZG_KEY_IDLE_MS) {
        return;
    }
    String tag = port.keypad;
    while (tag.length() > 1 && tag.charAt(0) == '0') {
        tag.remove(0, 1);
    }
    port.keypad = "";
    handleRead(port.reader, tag);
}

void IRAM_ATTR onWieBit(WiePort *port, int bit) {
    uint32_t now = millis();
    portENTER_CRITICAL_ISR(&wieMux);
    if (port->count > 0 && (now - port->lastMs) > EZG_FRAME_GAP_MS) {
        port->readyBits = port->bits;
        port->readyCount = port->count;
        port->count = 0;
        port->bits = 0;
    }
    if (port->count < 40) {
        port->bits = (port->bits << 1) | (uint64_t) (bit & 1);
        port->count++;
    }
    port->lastMs = now;
    portEXIT_CRITICAL_ISR(&wieMux);
}

void IRAM_ATTR isrR1D0() { onWieBit(&readers[0], 0); }
void IRAM_ATTR isrR1D1() { onWieBit(&readers[0], 1); }
void IRAM_ATTR isrR2D0() { onWieBit(&readers[1], 0); }
void IRAM_ATTR isrR2D1() { onWieBit(&readers[1], 1); }

bool connectSavedWifi() {
    if (wifiSsids.empty()) {
        return false;
    }
    WiFi.mode(WIFI_STA);
    for (size_t i = 0; i < wifiSsids.size(); i++) {
        WiFi.begin(wifiSsids[i].c_str(), wifiPasses[i].c_str());
        uint32_t start = millis();
        while (WiFi.status() != WL_CONNECTED && millis() - start < 12000) {
            serviceRelayOffs();
            delay(50);
        }
        if (WiFi.status() == WL_CONNECTED) {
            return true;
        }
        WiFi.disconnect(false, false);
    }
    return false;
}

void startEthernet() {
#if ESP_ARDUINO_VERSION_MAJOR >= 3
    ETH.begin(ETH_PHY_LAN8720, 0, 23, 18, -1, ETH_CLOCK_GPIO17_OUT);
#else
    ETH.begin(0, -1, 23, 18, ETH_PHY_LAN8720, ETH_CLOCK_GPIO17_OUT);
#endif
}

void handleSetupPost() {
    String ssid = setupServer.arg("ssid");
    String pass = setupServer.arg("password");
    ssid.trim();
    if (!ssid.length()) {
        setupServer.send(400, "text/plain", "SSID required");
        return;
    }
    wifiSsids.clear();
    wifiPasses.clear();
    wifiSsids.push_back(ssid);
    wifiPasses.push_back(pass);
    savePrefs();
    setupServer.send(200, "text/plain", "Saved. The board will try this Wi-Fi network.");
    connectSavedWifi();
}

void startSetupAp() {
    if (setupApOn) {
        return;
    }
    String ssid = "EZGate-" + hardwareId.substring(hardwareId.length() > 4 ? hardwareId.length() - 4 : 0);
    WiFi.mode(WIFI_AP_STA);
    WiFi.softAP(ssid.c_str(), EZG_AP_PASSWORD);
    setupServer.on("/", HTTP_GET, []() {
        setupServer.send(200, "text/html",
            "<form method=post action=/wifi>SSID <input name=ssid> Password <input name=password type=password><button>Save</button></form>");
    });
    setupServer.on("/wifi", HTTP_POST, handleSetupPost);
    setupServer.begin();
    setupApOn = true;
}

void stopSetupAp() {
    if (!setupApOn) {
        return;
    }
    setupServer.stop();
    WiFi.softAPdisconnect(true);
    WiFi.mode(WIFI_STA);
    setupApOn = false;
}

void serviceLinks() {
    ethernetLink = ETH.linkUp();
    bool wifiUp = WiFi.status() == WL_CONNECTED;
    if (!ethernetLink && !wifiUp) {
        startSetupAp();
    } else {
        stopSetupAp();
    }
    if (setupApOn) {
        setupServer.handleClient();
    }
}

void handleUsbLine(const String &line) {
    String text = line;
    text.trim();
    if (text == "EZGUSB" || text.startsWith("EZGUSB ")) {
        usbAttached = true;
        Serial.print("EZGUSB ok ");
        Serial.print(EZG_FIRMWARE_VERSION);
        Serial.print(" ");
        Serial.print(hardwareId);
        Serial.print(" ");
        Serial.println(EZG_BOARD_TYPE);
        return;
    }
    if (text == "EZGCLR" || text.startsWith("EZGCLR ")) {
        usbAttached = true;
        Serial.println("EZGCLR ok");
        clearStored();
        return;
    }
    if (text.startsWith("EZGCFG")) {
        usbAttached = true;
        text = text.substring(6);
        text.trim();
        JsonDocument doc;
        if (deserializeJson(doc, text) || !(doc["site_id"].is<int>()) || doc["site_id"].as<int>() < 1) {
            Serial.println("EZGCFG fail");
            return;
        }
        JsonObject data = doc.as<JsonObject>();
        JsonDocument wrap;
        wrap["config"] = data;
        if (data["virtual_devices"].is<JsonArray>()) {
            wrap["virtual_devices"] = data["virtual_devices"];
        }
        applyHeartbeatData(wrap.as<JsonObject>());
        Serial.println("EZGCFG ok");
        connectSavedWifi();
        return;
    }
    if (text.startsWith("EZGKEY")) {
        usbAttached = true;
        Serial.println("EZGKEY fail");
    }
}

void serviceSerial() {
    static String line;
    while (Serial.available()) {
        char c = (char) Serial.read();
        if (c == '\n' || c == '\r') {
            if (line.length()) {
                handleUsbLine(line);
                line = "";
            }
        } else if (line.length() < 1800) {
            line += c;
        }
    }
}

void setup() {
    asm volatile ("" : : "m"(EZG_VERSION_TAG));
    Serial.begin(115200);
    hardwareId = macId();
    Wire.begin(4, 5);
    writeRelayBits();
    SPIFFS.begin(true);
    loadPrefs();
    loadDevices();
    readers[0].reader = 1;
    readers[1].reader = 2;
    pinMode(14, INPUT_PULLUP);
    pinMode(13, INPUT_PULLUP);
    pinMode(32, INPUT_PULLUP);
    pinMode(33, INPUT_PULLUP);
    attachInterrupt(digitalPinToInterrupt(14), isrR1D0, FALLING);
    attachInterrupt(digitalPinToInterrupt(13), isrR1D1, FALLING);
    attachInterrupt(digitalPinToInterrupt(32), isrR2D0, FALLING);
    attachInterrupt(digitalPinToInterrupt(33), isrR2D1, FALLING);
    WiFi.mode(WIFI_STA);
    startEthernet();
    uint32_t start = millis();
    while (!ETH.linkUp() && millis() - start < 4000) {
        delay(50);
    }
    ethernetLink = ETH.linkUp();
    if (!ethernetLink) {
        connectSavedWifi();
    }
    serviceLinks();
    configTime(0, 0, "pool.ntp.org", "time.google.com");
}

void loop() {
    serviceSerial();
    serviceRelayOffs();
    consumeFrame(readers[0]);
    consumeFrame(readers[1]);
    serviceKeypad(readers[0]);
    serviceKeypad(readers[1]);
    serviceLinks();
    if (!ethernetLink && WiFi.status() != WL_CONNECTED && !wifiSsids.empty() && !setupApOn) {
        connectSavedWifi();
    }
    if ((ethernetLink || WiFi.status() == WL_CONNECTED)
        && millis() - lastHeartbeatMs > (uint32_t) wifiHeartbeatSeconds * 1000UL) {
        lastHeartbeatMs = millis();
        sendHeartbeat();
    }
}
