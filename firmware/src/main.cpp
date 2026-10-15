#include <Arduino.h>
#include <WiFi.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <WiFiClientSecure.h>
#include <vector>
#include <algorithm>
#include <Update.h>
#include <mbedtls/sha256.h>
#include <esp_ota_ops.h>
#include <esp_partition.h>
#include <esp_timer.h>
#include <esp_sleep.h>
#include <esp_system.h>
#include <esp_netif.h>
#include <nvs_flash.h>
#include <cstring>
#include <cstdio>
#include <time.h>
#include "config.h"
#include "ezgate_logo.h"
#include "lora_keys.h"
#include "lora_link.h"

// Keep the version tag in the binary so the portal can read it from an uploaded .bin.
const char EZG_VERSION_TAG[] __attribute__((used)) = EZG_FIRMWARE_VERSION_TAG;

Preferences prefs;
WebServer setupServer(80);

enum class Mode { Setup, Service };

Mode mode = Mode::Setup;
unsigned long lastHeartbeatMs = 0;
int wifiHeartbeatSeconds = EZG_HEARTBEAT_SECONDS;
int loraHeartbeatSeconds = EZG_LORA_HEARTBEAT_SECONDS;
unsigned long lastPortalOkMs = 0;
unsigned long ledCycleMs = 0;
unsigned long heartbeatFlashUntilMs = 0;
bool ledOn = false;
bool lastInternetOk = false;
bool lastPortalAttemptOk = false;
bool ntpStarted = false;
bool heartbeatFlash = false;
int pulseMs = EZG_DEFAULT_PULSE_MS;
String virtualDeviceId;
String configVersion;
String friendlyName;
String internetHost = EZG_PING_HOST;
String ntpServer1 = EZG_NTP_SERVER_1;
String ntpServer2 = EZG_NTP_SERVER_2;
int siteId = 0;
String hardwareId;
int pendingCommandId = 0;
int reportedCommandId = 0;
uint32_t lastOpenCmd = 0;
bool pulseRequested = false;
unsigned long pulseUntilMs = 0;
bool configButtonWasDown = false;

struct WifiNet {
    String ssid;
    String password;
};

std::vector<WifiNet> siteNetworks;

struct SeenNet {
    String ssid;
    int rssi;
    const char *security;
    uint8_t bssid[6];
    int32_t channel;
};

std::vector<SeenNet> lastNeighborhood;
bool pendingScanAck = false;
bool otaBusy = false;
bool otaSession = false;
bool otaChunkDue = false;
bool otaShaReady = false;
bool otaPendingVerify = false;
String otaVersion;
String otaChecksum;
String otaUrl;
int otaSize = 0;
int otaOffset = 0;
int otaChunkSize = EZG_OTA_CHUNK_BYTES;
int otaWifiChunk = EZG_OTA_CHUNK_BYTES;
int otaLoraChunk = EZG_OTA_LORA_CHUNK_BYTES;
bool loraOnly = false;
bool wifiRoamActive = false;
bool wifiFloorDropped = false;
bool roamScanWanted = false;
bool scanReportToPortal = false;
int wifiRssiFloor = EZG_WIFI_RSSI_FLOOR_DBM;
int wifiRssiMargin = EZG_WIFI_RSSI_MARGIN_DBM;
unsigned long lastRoamScanMs = 0;
unsigned long wifiWeakSinceMs = 0;
unsigned long wifiAboveFloorSinceMs = 0;
unsigned long lastRoamSwitchMs = 0;
int loraFreqHz = EZG_LORA_FREQ_HZ;
int loraRssiFloor = EZG_LORA_RSSI_FLOOR_DBM;
int isolationMisses = 3;
int loraHopLimit = 3;
int loraTxRetries = 3;
int loraDiscMinMs = 50;
int loraDiscMaxMs = 250;
int loraStaggerMs = 200;
int loraRouteExpiry = 3;
int loraBeaconMultiple = 5;
int loraOtaMax = 1;
int loraOtaAirtimeMs = 400;
bool loraLogRadio = true;
mbedtls_sha256_context otaSha;
unsigned long loopStartedMs = 0;
bool fallbackVersionCached = false;
String cachedFallbackVersion;

enum class JoinPhase { Idle, Connecting, GettingIp, Provisioning, Ready, Restored, Failed };
JoinPhase joinPhase = JoinPhase::Idle;
bool joinIsChange = false;
bool setupHttpStarted = false;
unsigned long lastReconnectAttemptMs = 0;
bool staConnecting = false;
unsigned long staConnectStartMs = 0;
size_t staNetIndex = 0;
bool staTuned = false;
bool scanWanted = false;
bool scanAsyncStarted = false;
String joinSsid;
String joinPassword;
String joinError;
String joinIp;
String joinFailedAt;
uint8_t joinBssid[6];
int32_t joinChannel = 0;
bool joinHasBssid = false;
bool joinViaLora = false;
unsigned long joinStartedMs = 0;
unsigned long phaseEnteredMs = 0;
bool provisionYielded = false;
JsonDocument sitesDoc;

void startSetupAp();
void leaveRecovery(const char *why);
void sendLoraHeartbeatIfDue();
void considerRoam();
void applyStaTuning();
void serviceSta();
void serviceAsyncScan();
void serviceRelay();
void serviceLed();
void flashHeartbeatLed();
void startRelayPulse();
void applyConfigObject(JsonObjectConst cfg);
void applyVirtualDevices(JsonArrayConst devices);
bool handleOpenCommand(uint32_t cmd);
void beginOtaSession(JsonObjectConst fw);
void abortOtaSession(const char *why);
void finishOtaSession();
int readGateState();
String fallbackFirmwareVersion();
void confirmFirmware();
void rebootBoard();
void sendHeartbeat();
const char *wifiAuthLabel(uint8_t mode);
String stripRssiLabel(const String &raw);
void enterJoinPhase(JoinPhase phase) {
    joinPhase = phase;
    phaseEnteredMs = millis();
    if (phase == JoinPhase::Connecting) {
        Serial.println("Connecting to WiFi");
    } else if (phase == JoinPhase::GettingIp) {
        Serial.println("Getting IP address");
    } else if (phase == JoinPhase::Provisioning) {
        Serial.println("Provisioning on portal");
    } else if (phase == JoinPhase::Failed) {
        Serial.print("Setup failed: ");
        Serial.println(joinError);
    }
}

bool joinDwellDone() {
    return millis() - phaseEnteredMs >= 800;
}

const char *joinPhaseName() {
    switch (joinPhase) {
        case JoinPhase::Connecting:
            return "connecting";
        case JoinPhase::GettingIp:
            return "getting_ip";
        case JoinPhase::Provisioning:
            return "provisioning";
        case JoinPhase::Ready:
            return "ready";
        case JoinPhase::Restored:
            return "restored";
        case JoinPhase::Failed:
            return "failed";
        default:
            return "idle";
    }
}

String macId() {
    uint64_t mac = ESP.getEfuseMac();
    char buf[17];
    snprintf(buf, sizeof(buf), "%012llX", (unsigned long long) mac);
    return String(buf);
}

bool loraLinkStarted = false;
bool usbHostAttached = false;

void ezgNoteUsbCommand() {
    usbHostAttached = true;
}

bool ezgUsbHostAttached() {
    return usbHostAttached;
}

void ezgUsbPrint(const String &line) {
    Serial.println(line);
    Serial.flush();
}

int clampHeartbeatSeconds(int seconds, int fallback) {
    if (seconds < 2 || seconds > 120) {
        return fallback;
    }
    return seconds;
}

int currentHeartbeatSeconds() {
    if (WiFi.status() == WL_CONNECTED) {
        return wifiHeartbeatSeconds;
    }
    return loraHeartbeatSeconds;
}

void ezgSetWifiHeartbeatSeconds(int seconds) {
    seconds = clampHeartbeatSeconds(seconds, wifiHeartbeatSeconds);
    if (wifiHeartbeatSeconds == seconds) {
        return;
    }
    wifiHeartbeatSeconds = seconds;
    prefs.begin("ezgate", false);
    prefs.putInt("hb_sec", wifiHeartbeatSeconds);
    prefs.end();
}

void ezgSetLoraHeartbeatSeconds(int seconds) {
    seconds = clampHeartbeatSeconds(seconds, loraHeartbeatSeconds);
    if (loraHeartbeatSeconds == seconds) {
        return;
    }
    loraHeartbeatSeconds = seconds;
    prefs.begin("ezgate", false);
    prefs.putInt("hb_lora_sec", loraHeartbeatSeconds);
    prefs.end();
}

void ezgSetHeartbeatSeconds(int seconds) {
    ezgSetLoraHeartbeatSeconds(seconds);
}

void applyHeartbeatIntervals(JsonVariantConst data) {
    int wifi = data["wifi_heartbeat_interval_seconds"] | 0;
    int lora = data["lora_heartbeat_interval_seconds"] | 0;
    int legacy = data["heartbeat_interval_seconds"] | 0;
    if (wifi >= 2) {
        ezgSetWifiHeartbeatSeconds(wifi);
    }
    if (lora >= 2) {
        ezgSetLoraHeartbeatSeconds(lora);
    }
    if (wifi < 2 && lora < 2 && legacy >= 2) {
        ezgSetWifiHeartbeatSeconds(legacy);
        ezgSetLoraHeartbeatSeconds(legacy);
    }
}

void ezgApplyConfigVersionUnix(uint32_t unix) {
    if (unix < 1700000000UL) {
        return;
    }
    time_t t = (time_t) unix;
    struct tm tm;
    if (gmtime_r(&t, &tm) == nullptr) {
        return;
    }
    char buf[24];
    snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d",
        tm.tm_year + 1900, tm.tm_mon + 1, tm.tm_mday,
        tm.tm_hour, tm.tm_min, tm.tm_sec);
    if (configVersion == buf) {
        return;
    }
    configVersion = buf;
    prefs.begin("ezgate", false);
    prefs.putString("cfg_ver", configVersion);
    prefs.end();
}

String nvsString(const char *key, const String &fallback) {
    if (!prefs.isKey(key)) {
        return fallback;
    }
    return prefs.getString(key, fallback);
}

void initNvs() {
    esp_err_t err = nvs_flash_init();
    if (err == ESP_ERR_NVS_NO_FREE_PAGES || err == ESP_ERR_NVS_NEW_VERSION_FOUND) {
        nvs_flash_erase();
        err = nvs_flash_init();
    }
    if (err != ESP_OK) {
        Serial.print("NVS init fail ");
        Serial.println((int) err);
    }
}

void loadConfig() {
    if (!prefs.begin("ezgate", false)) {
        Serial.println("ezgate NVS unavailable");
        return;
    }
    siteId = prefs.getInt("site_id", 0);
    pulseMs = prefs.getInt("pulse_ms", EZG_DEFAULT_PULSE_MS);
    configVersion = nvsString("cfg_ver", "");
    friendlyName = nvsString("name", "");
    virtualDeviceId = nvsString("vd_id", "");
    internetHost = nvsString("net_host", EZG_PING_HOST);
    ntpServer1 = nvsString("ntp1", EZG_NTP_SERVER_1);
    ntpServer2 = nvsString("ntp2", EZG_NTP_SERVER_2);
    if (!internetHost.length()) {
        internetHost = EZG_PING_HOST;
    }
    if (!ntpServer1.length()) {
        ntpServer1 = EZG_NTP_SERVER_1;
    }
    if (!ntpServer2.length()) {
        ntpServer2 = EZG_NTP_SERVER_2;
    }
    otaWifiChunk = prefs.getInt("ota_wifi", EZG_OTA_CHUNK_BYTES);
    otaLoraChunk = prefs.getInt("ota_lora", EZG_OTA_LORA_CHUNK_BYTES);
    loraOnly = prefs.getBool("lora_only", false);
    wifiRssiFloor = prefs.getInt("roam_rssi", EZG_WIFI_RSSI_FLOOR_DBM);
    wifiRssiMargin = prefs.getInt("roam_hyst", EZG_WIFI_RSSI_MARGIN_DBM);
    loraFreqHz = prefs.getInt("lora_hz", EZG_LORA_FREQ_HZ);
    loraRssiFloor = prefs.getInt("lora_floor", EZG_LORA_RSSI_FLOOR_DBM);
    wifiHeartbeatSeconds = clampHeartbeatSeconds(
        prefs.getInt("hb_sec", EZG_HEARTBEAT_SECONDS),
        EZG_HEARTBEAT_SECONDS
    );
    loraHeartbeatSeconds = clampHeartbeatSeconds(
        prefs.getInt("hb_lora_sec", wifiHeartbeatSeconds),
        wifiHeartbeatSeconds
    );
    isolationMisses = prefs.getInt("iso_n", 3);
    loraHopLimit = prefs.getInt("hop_lim", 3);
    loraTxRetries = prefs.getInt("tx_try", 3);
    loraDiscMinMs = prefs.getInt("disc_min", 50);
    loraDiscMaxMs = prefs.getInt("disc_max", 250);
    loraStaggerMs = prefs.getInt("stagger", 200);
    loraRouteExpiry = prefs.getInt("rt_exp", 3);
    loraBeaconMultiple = prefs.getInt("bcn_mul", 5);
    if (loraBeaconMultiple < 1) {
        loraBeaconMultiple = 5;
    }
    loraOtaMax = prefs.getInt("ota_max", 1);
    loraOtaAirtimeMs = prefs.getInt("ota_air", 400);
    loraLogRadio = prefs.getBool("lora_log", true);
    if (otaWifiChunk < 32) {
        otaWifiChunk = EZG_OTA_CHUNK_BYTES;
    }
    if (otaLoraChunk < 32) {
        otaLoraChunk = EZG_OTA_LORA_CHUNK_BYTES;
    }
    if (isolationMisses < 1) {
        isolationMisses = 3;
    }
    if (wifiRssiFloor < -100 || wifiRssiFloor > -50) {
        wifiRssiFloor = EZG_WIFI_RSSI_FLOOR_DBM;
    }
    if (wifiRssiMargin < 0 || wifiRssiMargin > 30) {
        wifiRssiMargin = EZG_WIFI_RSSI_MARGIN_DBM;
    }
    if (loraRssiFloor < -140 || loraRssiFloor > -40) {
        loraRssiFloor = EZG_LORA_RSSI_FLOOR_DBM;
    }
    int count = prefs.getInt("wifi_n", 0);
    siteNetworks.clear();
    for (int i = 0; i < count; i++) {
        WifiNet net;
        net.ssid = nvsString(("ssid" + String(i)).c_str(), "");
        net.password = nvsString(("pass" + String(i)).c_str(), "");
        if (net.ssid.length()) {
            siteNetworks.push_back(net);
        }
    }
    prefs.end();
}

void saveConfig() {
    prefs.begin("ezgate", false);
    prefs.putInt("site_id", siteId);
    prefs.putInt("pulse_ms", pulseMs);
    prefs.putString("cfg_ver", configVersion);
    prefs.putString("name", friendlyName);
    prefs.putString("vd_id", virtualDeviceId);
    prefs.putString("net_host", internetHost);
    prefs.putString("ntp1", ntpServer1);
    prefs.putString("ntp2", ntpServer2);
    prefs.putInt("ota_wifi", otaWifiChunk);
    prefs.putInt("ota_lora", otaLoraChunk);
    prefs.putBool("lora_only", loraOnly);
    prefs.putInt("roam_rssi", wifiRssiFloor);
    prefs.putInt("roam_hyst", wifiRssiMargin);
    prefs.putInt("lora_hz", loraFreqHz);
    prefs.putInt("lora_floor", loraRssiFloor);
    prefs.putInt("hb_sec", wifiHeartbeatSeconds);
    prefs.putInt("hb_lora_sec", loraHeartbeatSeconds);
    prefs.putInt("iso_n", isolationMisses);
    prefs.putInt("hop_lim", loraHopLimit);
    prefs.putInt("tx_try", loraTxRetries);
    prefs.putInt("disc_min", loraDiscMinMs);
    prefs.putInt("disc_max", loraDiscMaxMs);
    prefs.putInt("stagger", loraStaggerMs);
    prefs.putInt("rt_exp", loraRouteExpiry);
    prefs.putInt("bcn_mul", loraBeaconMultiple);
    prefs.putInt("ota_max", loraOtaMax);
    prefs.putInt("ota_air", loraOtaAirtimeMs);
    prefs.putBool("lora_log", loraLogRadio);
    prefs.putInt("wifi_n", (int) siteNetworks.size());
    for (size_t i = 0; i < siteNetworks.size(); i++) {
        prefs.putString(("ssid" + String(i)).c_str(), siteNetworks[i].ssid);
        prefs.putString(("pass" + String(i)).c_str(), siteNetworks[i].password);
    }
    prefs.end();
}

void clearConfig() {
    prefs.begin("ezgate", false);
    prefs.clear();
    prefs.end();
    siteId = 0;
    pulseMs = EZG_DEFAULT_PULSE_MS;
    virtualDeviceId = "";
    configVersion = "";
    friendlyName = "";
    internetHost = EZG_PING_HOST;
    ntpServer1 = EZG_NTP_SERVER_1;
    ntpServer2 = EZG_NTP_SERVER_2;
    otaWifiChunk = EZG_OTA_CHUNK_BYTES;
    otaLoraChunk = EZG_OTA_LORA_CHUNK_BYTES;
    otaChunkSize = EZG_OTA_CHUNK_BYTES;
    ntpStarted = false;
    loraOnly = false;
    wifiHeartbeatSeconds = EZG_HEARTBEAT_SECONDS;
    loraHeartbeatSeconds = EZG_LORA_HEARTBEAT_SECONDS;
    wifiRoamActive = false;
    wifiFloorDropped = false;
    wifiAboveFloorSinceMs = 0;
    wifiRssiFloor = EZG_WIFI_RSSI_FLOOR_DBM;
    wifiRssiMargin = EZG_WIFI_RSSI_MARGIN_DBM;
    loraRssiFloor = EZG_LORA_RSSI_FLOOR_DBM;
    siteNetworks.clear();
}

bool isConfigured() {
    return siteId > 0;
}

bool ssidIsConfigured(const String &ssid) {
    for (size_t i = 0; i < siteNetworks.size(); i++) {
        if (siteNetworks[i].ssid == ssid) {
            return true;
        }
    }
    return false;
}

String configuredSsidList() {
    String out;
    for (size_t i = 0; i < siteNetworks.size(); i++) {
        if (out.length()) {
            out += ", ";
        }
        out += siteNetworks[i].ssid;
    }
    return out;
}

void setLed(bool on) {
    pinMode(EZG_LED_PIN, OUTPUT);
    digitalWrite(EZG_LED_PIN, on ? HIGH : LOW);
    ledOn = on;
}

void flashHeartbeatLed() {
    heartbeatFlash = true;
    heartbeatFlashUntilMs = millis() + (unsigned long) EZG_LED_HEARTBEAT_FLASH_MS;
    setLed(true);
}

int ledGroupCount() {
    unsigned long window = (unsigned long) isolationMisses * (unsigned long) wifiHeartbeatSeconds * 1000UL;
    if (window < 1000UL) {
        window = 15000UL;
    }
    bool wifiFresh = WiFi.status() == WL_CONNECTED && lastPortalOkMs != 0
        && (millis() - lastPortalOkMs) < window;
    if (loraLinkPortalOk() || wifiFresh) {
        return 0;
    }
    if (WiFi.status() != WL_CONNECTED && !loraLinkRadioOk() && !loraLinkHasParent()) {
        return 2;
    }
    if (WiFi.status() == WL_CONNECTED && !lastInternetOk) {
        return 3;
    }
    return 4;
}

void serviceLed() {
    if (otaBusy) {
        setLed((millis() / 80UL) % 2UL == 0);
        return;
    }
    if (mode == Mode::Setup) {
        heartbeatFlash = false;
        unsigned long period = (unsigned long) EZG_LED_SETUP_ON_MS + (unsigned long) EZG_LED_SETUP_OFF_MS;
        unsigned long t = millis() - ledCycleMs;
        if (t >= period) {
            ledCycleMs += period;
            t = millis() - ledCycleMs;
            if (t >= period) {
                ledCycleMs = millis();
                t = 0;
            }
        }
        setLed(t < (unsigned long) EZG_LED_SETUP_ON_MS);
        return;
    }
    int blinks = ledGroupCount();
    if (blinks == 0) {
        if (heartbeatFlash && millis() >= heartbeatFlashUntilMs) {
            setLed(false);
            heartbeatFlash = false;
        }
        return;
    }
    heartbeatFlash = false;
    unsigned long slot = (unsigned long) EZG_LED_BLINK_ON_MS + (unsigned long) EZG_LED_BLINK_OFF_MS;
    unsigned long period = slot * (unsigned long) blinks + (unsigned long) EZG_LED_PAUSE_MS;
    unsigned long t = millis() - ledCycleMs;
    if (t >= period) {
        ledCycleMs += period;
        t = millis() - ledCycleMs;
        if (t >= period) {
            ledCycleMs = millis();
            t = 0;
        }
    }
    bool on = false;
    for (int i = 0; i < blinks; i++) {
        unsigned long start = (unsigned long) i * slot;
        if (t >= start && t < start + (unsigned long) EZG_LED_BLINK_ON_MS) {
            on = true;
            break;
        }
    }
    setLed(on);
}

bool checkInternet() {
    if (WiFi.status() != WL_CONNECTED) {
        return false;
    }
    WiFiClient client;
    client.setTimeout((EZG_INTERNET_CONNECT_TIMEOUT_MS + 999) / 1000);
    bool ok = false;
    IPAddress ip;
    if (ip.fromString(internetHost)) {
        ok = client.connect(ip, 443);
        if (!ok) {
            ok = client.connect(ip, 53);
        }
    } else {
        ok = client.connect(internetHost.c_str(), 443);
    }
    client.stop();
    Serial.print("Internet ");
    Serial.println(ok ? "ok" : "failed");
    return ok;
}

void ensureNtp() {
    if (!lastInternetOk) {
        return;
    }
    if (ntpStarted) {
        return;
    }
    configTime(0, 0, ntpServer1.c_str(), ntpServer2.c_str());
    ntpStarted = true;
    Serial.println("NTP started");
}

void refreshLinkStatus() {
    if (WiFi.status() != WL_CONNECTED) {
        lastInternetOk = false;
        if (!loraLinkPortalOk()) {
            lastPortalAttemptOk = false;
        }
        return;
    }
    lastInternetOk = checkInternet();
    if (lastInternetOk) {
        ensureNtp();
    }
}

String portalUrl(const char *path) {
    String url = EZG_PORTAL_HTTPS ? "https://" : "http://";
    url += EZG_PORTAL_HOST;
    url += path;
    return url;
}

bool beginHttp(HTTPClient &http, WiFiClientSecure &client, const String &url) {
    http.setConnectTimeout(EZG_HTTP_CONNECT_TIMEOUT_MS);
    http.setTimeout(EZG_HTTP_TIMEOUT_MS);
    client.setHandshakeTimeout((EZG_HTTP_CONNECT_TIMEOUT_MS + 999) / 1000);
    Serial.print("Portal ");
    Serial.println(url);
    if (url.startsWith("https://")) {
        client.setInsecure();
        return http.begin(client, url);
    }
    return http.begin(url);
}

bool httpGetJson(const String &url, JsonDocument &doc) {
    HTTPClient http;
    WiFiClientSecure client;
    if (!beginHttp(http, client, url)) {
        Serial.println("Portal begin failed");
        return false;
    }
    int code = http.GET();
    Serial.print("Portal GET ");
    Serial.println(code);
    bool ok = false;
    if (code == 200) {
        DeserializationError err = deserializeJson(doc, http.getString());
        ok = !err;
        if (!ok) {
            Serial.println("Portal JSON parse failed");
        }
    }
    http.end();
    return ok;
}

bool httpPostJson(const String &url, const String &body, JsonDocument &doc) {
    HTTPClient http;
    WiFiClientSecure client;
    if (!beginHttp(http, client, url)) {
        Serial.println("Portal begin failed");
        return false;
    }
    http.addHeader("Content-Type", "application/json");
    int code = http.POST(body);
    Serial.print("Portal POST ");
    Serial.println(code);
    bool ok = false;
    if (code == 200) {
        DeserializationError err = deserializeJson(doc, http.getString());
        ok = !err;
        if (!ok) {
            Serial.println("Portal JSON parse failed");
        }
    }
    http.end();
    return ok;
}

bool ezgHttpGetBytes(const String &url, uint8_t *buf, int want, int &got) {
    got = 0;
    if (buf == nullptr || want <= 0) {
        return false;
    }
    HTTPClient http;
    WiFiClientSecure client;
    http.setConnectTimeout(EZG_HTTP_CONNECT_TIMEOUT_MS);
    http.setTimeout(EZG_OTA_TIMEOUT_MS);
    client.setHandshakeTimeout(12);
    bool began;
    if (url.startsWith("https://")) {
        client.setInsecure();
        began = http.begin(client, url);
    } else {
        began = http.begin(url);
    }
    if (!began) {
        return false;
    }
    int code = http.GET();
    if (code != 200 && code != 206) {
        http.end();
        return false;
    }
    WiFiClient *stream = http.getStreamPtr();
    int remain = want;
    while (remain > 0 && http.connected()) {
        int avail = stream->available();
        if (avail <= 0) {
            delay(1);
            yield();
            continue;
        }
        int n = stream->readBytes(buf + got, remain);
        if (n <= 0) {
            break;
        }
        got += n;
        remain -= n;
        serviceRelay();
        serviceLed();
    }
    http.end();
    return got > 0;
}

void applyStaTuning() {
    WiFi.setSleep(false);
    WiFi.setAutoReconnect(true);
    esp_netif_t *netif = esp_netif_get_handle_from_ifkey("WIFI_STA_DEF");
    if (netif != nullptr) {
        esp_netif_dns_info_t dns;
        memset(&dns, 0, sizeof(dns));
        dns.ip.type = ESP_IPADDR_TYPE_V4;
        dns.ip.u_addr.ip4.addr = ESP_IP4TOADDR(8, 8, 8, 8);
        esp_netif_set_dns_info(netif, ESP_NETIF_DNS_MAIN, &dns);
        dns.ip.u_addr.ip4.addr = ESP_IP4TOADDR(1, 1, 1, 1);
        esp_netif_set_dns_info(netif, ESP_NETIF_DNS_BACKUP, &dns);
    }
    staTuned = true;
}

void ingestScanResults(std::vector<SeenNet> &out, int n) {
    out.clear();
    for (int i = 0; i < n; i++) {
        String ssid = WiFi.SSID(i);
        if (!ssid.length()) {
            continue;
        }
        int rssi = WiFi.RSSI(i);
        const char *security = wifiAuthLabel((uint8_t) WiFi.encryptionType(i));
        uint8_t bssid[6] = {0};
        const uint8_t *bssidPtr = WiFi.BSSID(i);
        if (bssidPtr != nullptr) {
            memcpy(bssid, bssidPtr, 6);
        }
        int32_t channel = WiFi.channel(i);
        bool found = false;
        for (auto &hit : out) {
            if (hit.ssid == ssid) {
                found = true;
                if (rssi > hit.rssi) {
                    hit.rssi = rssi;
                    hit.security = security;
                    memcpy(hit.bssid, bssid, 6);
                    hit.channel = channel;
                }
                break;
            }
        }
        if (!found) {
            SeenNet hit;
            hit.ssid = ssid;
            hit.rssi = rssi;
            hit.security = security;
            memcpy(hit.bssid, bssid, 6);
            hit.channel = channel;
            out.push_back(hit);
        }
    }
    WiFi.scanDelete();
    std::sort(out.begin(), out.end(), [](const SeenNet &a, const SeenNet &b) {
        return a.rssi > b.rssi;
    });
}

void serviceSta() {
    if (!loraLinkPortalOk()) {
        unsigned long window = (unsigned long) isolationMisses * (unsigned long) wifiHeartbeatSeconds * 1000UL;
        if (window < 1000UL) {
            window = 15000UL;
        }
        bool wifiFresh = WiFi.status() == WL_CONNECTED && lastPortalOkMs != 0
            && (millis() - lastPortalOkMs) < window;
        if (!wifiFresh) {
            lastPortalAttemptOk = false;
        }
    }
    if (wifiRoamActive || wifiFloorDropped) {
        return;
    }
    if (loraOnly && (loraLinkPortalOk() || loraLinkHasParent())) {
        return;
    }
    if (joinPhase == JoinPhase::Connecting || joinPhase == JoinPhase::GettingIp
        || joinPhase == JoinPhase::Provisioning || joinPhase == JoinPhase::Ready) {
        return;
    }
    if (siteNetworks.empty()) {
        staConnecting = false;
        return;
    }
    if (WiFi.status() == WL_CONNECTED) {
        staConnecting = false;
        applyStaTuning();
        return;
    }
    staTuned = false;
    lastInternetOk = false;
    if (staConnecting) {
        if (millis() - staConnectStartMs >= (unsigned long) EZG_WIFI_CONNECT_TIMEOUT_MS) {
            Serial.println("STA join timeout");
            WiFi.disconnect(false, false);
            staConnecting = false;
            staNetIndex++;
            if (staNetIndex >= siteNetworks.size()) {
                staNetIndex = 0;
            }
            if (!loraLinkHasParent()) {
                lastReconnectAttemptMs = staNetIndex == 0 ? millis() : 0;
            }
        }
        return;
    }
    if (loraLinkHasParent() && loraLinkUplinkBusy()) {
        return;
    }
    unsigned long reconnectWait = loraLinkHasParent() ? 60000UL : (unsigned long) EZG_RECONNECT_MS;
    if (lastReconnectAttemptMs != 0
        && millis() - lastReconnectAttemptMs < reconnectWait) {
        return;
    }
    lastReconnectAttemptMs = millis();
    if (staNetIndex >= siteNetworks.size()) {
        staNetIndex = 0;
    }
    WiFi.persistent(false);
    WiFi.setAutoReconnect(true);
    WiFi.mode(mode == Mode::Setup ? WIFI_AP_STA : WIFI_STA);
    Serial.print("STA join ");
    Serial.println(siteNetworks[staNetIndex].ssid);
    WiFi.begin(siteNetworks[staNetIndex].ssid.c_str(), siteNetworks[staNetIndex].password.c_str());
    staConnecting = true;
    staConnectStartMs = millis();
}

void offerLoraScanUplink() {
    if (!loraLinkHasParent()) {
        return;
    }
    if (WiFi.status() == WL_CONNECTED && !loraOnly && !wifiRoamActive) {
        return;
    }
    JsonDocument doc;
    doc["hardware_id"] = hardwareId;
    doc["status"] = "lora";
    doc["scanned"] = true;
    doc["config_version"] = configVersion;
    doc["firmware_version"] = EZG_FIRMWARE_VERSION;
    doc["uptime_seconds"] = (unsigned long) (esp_timer_get_time() / 1000000LL);
    JsonObject connectivity = doc["connectivity"].to<JsonObject>();
    JsonObject current = connectivity["current"].to<JsonObject>();
    loraLinkFillConnectivity(current);
    JsonObject stats = doc["radio_stats"].to<JsonObject>();
    loraLinkFillRadioStats(stats);
    JsonArray others = connectivity["other"].to<JsonArray>();
    size_t n = lastNeighborhood.size();
    if (n > 24) {
        n = 24;
    }
    for (size_t i = 0; i < n; i++) {
        const auto &hit = lastNeighborhood[i];
        JsonObject opt = others.add<JsonObject>();
        opt["type"] = "wifi";
        opt["ssid"] = hit.ssid;
        opt["rssi"] = hit.rssi;
        opt["security"] = hit.security;
    }
    String js;
    serializeJson(doc, js);
    Serial.print("EZG uplink scan ");
    Serial.println((int) js.length());
    loraLinkSendUplinkJson(js);
}

void serviceAsyncScan() {
    if (scanAsyncStarted) {
        int16_t n = WiFi.scanComplete();
        if (n == WIFI_SCAN_RUNNING) {
            return;
        }
        scanAsyncStarted = false;
        if (n >= 0) {
            ingestScanResults(lastNeighborhood, n);
            if (scanReportToPortal) {
                pendingScanAck = true;
                offerLoraScanUplink();
            }
        } else {
            WiFi.scanDelete();
        }
        scanReportToPortal = false;
        if ((loraOnly || wifiRoamActive) && WiFi.status() != WL_CONNECTED
            && mode != Mode::Setup && !wifiFloorDropped) {
            WiFi.mode(WIFI_OFF);
        }
        return;
    }
    if (otaSession || otaBusy || staConnecting) {
        return;
    }
    if (!scanWanted && !roamScanWanted) {
        return;
    }
    if (WiFi.status() != WL_CONNECTED) {
        if (!roamScanWanted && !scanWanted) {
            return;
        }
        if (WiFi.getMode() == WIFI_OFF) {
            WiFi.mode(WIFI_STA);
        }
    }
    scanReportToPortal = scanWanted;
    scanWanted = false;
    roamScanWanted = false;
    int16_t rc = WiFi.scanNetworks(true, true, false, 120);
    if (rc == WIFI_SCAN_RUNNING) {
        scanAsyncStarted = true;
    } else if (rc >= 0) {
        ingestScanResults(lastNeighborhood, rc);
        if (scanReportToPortal) {
            pendingScanAck = true;
            offerLoraScanUplink();
        }
        scanReportToPortal = false;
        if ((loraOnly || wifiRoamActive) && WiFi.status() != WL_CONNECTED
            && mode != Mode::Setup && !wifiFloorDropped) {
            WiFi.mode(WIFI_OFF);
        }
    } else {
        scanReportToPortal = false;
    }
}

// Service-mode scan used only to see whether a configured site SSID is usable
// (reconnect now; later, LoRa fallback probing the same list). Not a neighborhood
// report for connectivity.other.
bool connectBestWifi(bool keepAp) {
    if (siteNetworks.empty()) {
        return false;
    }
    WiFi.persistent(false);
    WiFi.setAutoReconnect(true);
    WiFi.setSleep(false);
    WiFi.mode(keepAp ? WIFI_AP_STA : WIFI_STA);
    delay(keepAp ? 50 : 100);
    for (size_t idx = 0; idx < siteNetworks.size(); idx++) {
        Serial.print("STA join ");
        Serial.println(siteNetworks[idx].ssid);
        WiFi.begin(siteNetworks[idx].ssid.c_str(), siteNetworks[idx].password.c_str());
        unsigned long start = millis();
        while (WiFi.status() != WL_CONNECTED && millis() - start < EZG_WIFI_CONNECT_TIMEOUT_MS) {
            serviceLed();
            if (keepAp) {
                setupServer.handleClient();
            }
            delay(50);
        }
        if (WiFi.status() == WL_CONNECTED) {
            applyStaTuning();
            Serial.print("STA IP ");
            Serial.println(WiFi.localIP());
            return true;
        }
        WiFi.disconnect(false, false);
        delay(keepAp ? 50 : 100);
        if (keepAp) {
            setupServer.handleClient();
        }
    }
    return false;
}

bool passwordForSsid(const String &ssid, String &pass) {
    for (size_t i = 0; i < siteNetworks.size(); i++) {
        if (siteNetworks[i].ssid == ssid) {
            pass = siteNetworks[i].password;
            return true;
        }
    }
    return false;
}

bool isCurrentAp(const SeenNet &hit) {
    if (WiFi.status() != WL_CONNECTED || WiFi.SSID() != hit.ssid) {
        return false;
    }
    const uint8_t *bssid = WiFi.BSSID();
    return bssid != nullptr && memcmp(bssid, hit.bssid, 6) == 0;
}

bool bestConfiguredWifi(SeenNet &best) {
    bool found = false;
    for (const auto &hit : lastNeighborhood) {
        if (!ssidIsConfigured(hit.ssid)) {
            continue;
        }
        if (!found || hit.rssi > best.rssi) {
            best = hit;
            found = true;
        }
    }
    return found;
}

bool joinSeenWifi(const SeenNet &hit) {
    String pass;
    if (!passwordForSsid(hit.ssid, pass)) {
        return false;
    }
    if (lastRoamSwitchMs != 0 && millis() - lastRoamSwitchMs < (unsigned long) EZG_WIFI_ROAM_COOLDOWN_MS) {
        return false;
    }
    lastRoamSwitchMs = millis();
    wifiRoamActive = false;
    wifiWeakSinceMs = 0;
    Serial.print("EZG wifi roam ");
    Serial.print(hit.ssid);
    Serial.print(" ");
    Serial.println(hit.rssi);
    WiFi.persistent(false);
    WiFi.setAutoReconnect(true);
    WiFi.disconnect(false, false);
    WiFi.mode((mode == Mode::Setup || wifiFloorDropped) ? WIFI_AP_STA : WIFI_STA);
    WiFi.begin(hit.ssid.c_str(), pass.c_str(), hit.channel, hit.bssid);
    staConnecting = true;
    staConnectStartMs = millis();
    return true;
}

void considerRoam() {
    if (otaSession || otaBusy || loraOnly) {
        return;
    }
    if (joinPhase == JoinPhase::Connecting || joinPhase == JoinPhase::GettingIp
        || joinPhase == JoinPhase::Provisioning || joinPhase == JoinPhase::Ready
        || staConnecting) {
        return;
    }
    if (mode != Mode::Service && !(mode == Mode::Setup && wifiFloorDropped)) {
        return;
    }

    int hold = wifiRssiFloor + wifiRssiMargin;

    if (mode == Mode::Setup && wifiFloorDropped) {
        if (WiFi.status() == WL_CONNECTED) {
            return;
        }
        if (lastRoamScanMs == 0 || millis() - lastRoamScanMs >= (unsigned long) EZG_WIFI_ROAM_SCAN_MS) {
            roamScanWanted = true;
            lastRoamScanMs = millis();
        }
        SeenNet best;
        if (bestConfiguredWifi(best) && best.rssi >= wifiRssiFloor) {
            if (wifiAboveFloorSinceMs == 0) {
                wifiAboveFloorSinceMs = millis();
            }
            if (millis() - wifiAboveFloorSinceMs >= (unsigned long) EZG_WIFI_ROAM_SCAN_MS) {
                joinSeenWifi(best);
            }
            return;
        }
        wifiAboveFloorSinceMs = 0;
        return;
    }

    if (WiFi.status() == WL_CONNECTED) {
        int rssi = WiFi.RSSI();
        if (rssi == 0) {
            return;
        }
        if (rssi >= hold) {
            wifiWeakSinceMs = 0;
            if (wifiRoamActive) {
                wifiRoamActive = false;
            }
            return;
        }
        if (wifiWeakSinceMs == 0) {
            wifiWeakSinceMs = millis();
        }
        if (lastRoamScanMs == 0 || millis() - lastRoamScanMs >= (unsigned long) EZG_WIFI_ROAM_SCAN_MS) {
            roamScanWanted = true;
            lastRoamScanMs = millis();
        }
        SeenNet best;
        if (bestConfiguredWifi(best) && !isCurrentAp(best)
            && best.rssi >= rssi + wifiRssiMargin && best.rssi >= hold) {
            joinSeenWifi(best);
            return;
        }
        bool waited = lastNeighborhood.size() > 0
            || (wifiWeakSinceMs != 0 && millis() - wifiWeakSinceMs >= (unsigned long) EZG_WIFI_ROAM_SCAN_MS);
        if (!waited) {
            return;
        }
        if (rssi < wifiRssiFloor) {
            bool haveLora = loraLinkHasParent() || loraLinkHasGatewayPeer() || loraLinkPortalOk();
            if (haveLora) {
                if (!wifiRoamActive) {
                    wifiRoamActive = true;
                    Serial.print("EZG wifi below floor ");
                    Serial.print(rssi);
                    Serial.println(" dBm; drop WiFi for LoRa");
                }
                return;
            }
            if (!wifiFloorDropped) {
                wifiFloorDropped = true;
                wifiRoamActive = true;
                wifiAboveFloorSinceMs = 0;
                Serial.print("EZG wifi below floor ");
                Serial.print(rssi);
                Serial.println(" dBm; recovery AP");
                WiFi.setAutoReconnect(false);
                WiFi.disconnect(true, false);
                startSetupAp();
            }
            return;
        }
        if (loraLinkHasGatewayPeer()) {
            if (!wifiRoamActive) {
                wifiRoamActive = true;
                Serial.print("EZG wifi weak ");
                Serial.print(rssi);
                Serial.println(" dBm; drop WiFi for LoRa");
            }
        }
        return;
    }

    if (!wifiRoamActive) {
        return;
    }
    if (lastRoamScanMs != 0 && millis() - lastRoamScanMs < (unsigned long) EZG_WIFI_ROAM_REPROBE_MS) {
        return;
    }
    lastRoamScanMs = millis();
    roamScanWanted = true;
    SeenNet best;
    if (bestConfiguredWifi(best) && best.rssi >= hold) {
        joinSeenWifi(best);
        return;
    }
    if (WiFi.getMode() != WIFI_OFF && !scanAsyncStarted && !staConnecting
        && mode != Mode::Setup && !wifiFloorDropped) {
        WiFi.setAutoReconnect(false);
        WiFi.mode(WIFI_OFF);
    }
}

void rebootBoard() {
    Serial.println("Rebooting");
    Serial.flush();
    delay(50);
    // Deep sleep resets the radio more completely than ESP.restart() after AP+STA.
    esp_sleep_enable_timer_wakeup(100000ULL);
    esp_deep_sleep_start();
}

void restartIntoNewFirmware() {
    Serial.println("Rebooting into new firmware");
    Serial.flush();
    delay(100);
    ESP.restart();
    esp_restart();
}

void refreshOtaPendingVerify() {
    const esp_partition_t *running = esp_ota_get_running_partition();
    if (running == nullptr) {
        otaPendingVerify = false;
        return;
    }
    esp_ota_img_states_t state;
    if (esp_ota_get_state_partition(running, &state) != ESP_OK) {
        otaPendingVerify = false;
        return;
    }
    otaPendingVerify = (state == ESP_OTA_IMG_PENDING_VERIFY);
    if (otaPendingVerify) {
        Serial.println("Firmware pending stable confirm");
    }
}

void confirmFirmware() {
    if (!otaPendingVerify) {
        return;
    }
    if (esp_ota_mark_app_valid_cancel_rollback() == ESP_OK) {
        Serial.println("Firmware confirmed stable");
        otaPendingVerify = false;
    }
}

void confirmFirmwareIfStable() {
    if (!otaPendingVerify || loopStartedMs == 0) {
        return;
    }
    if (millis() - loopStartedMs < (unsigned long) EZG_OTA_STABLE_MS) {
        return;
    }
    confirmFirmware();
}

void rollbackUnconfirmedFirmware() {
    Serial.println("New firmware did not stay in a stable loop; rolling back");
    Serial.flush();
    delay(50);
    if (esp_ota_mark_app_invalid_rollback_and_reboot() == ESP_OK) {
        return;
    }
    if (Update.canRollBack()) {
        Update.rollBack();
    }
    rebootBoard();
}

void rollbackIfUnstableReset() {
    if (!otaPendingVerify) {
        return;
    }
    switch (esp_reset_reason()) {
        case ESP_RST_PANIC:
        case ESP_RST_TASK_WDT:
        case ESP_RST_INT_WDT:
        case ESP_RST_WDT:
            rollbackUnconfirmedFirmware();
            break;
        default:
            break;
    }
}

void considerIsolation() {
    unsigned long window = (unsigned long) isolationMisses * (unsigned long) currentHeartbeatSeconds() * 1000UL;
    if (window < 1000UL) {
        window = (unsigned long) EZG_ISOLATION_SECONDS * 1000UL;
    }
    if (loraLinkPortalOk()) {
        return;
    }
    if (lastPortalOkMs > 0 && millis() - lastPortalOkMs <= window) {
        return;
    }
    if (loraLinkHasParent()) {
        return;
    }
    if (wifiFloorDropped) {
        return;
    }
    if (wifiRoamActive && loraLinkHasGatewayPeer()) {
        return;
    }
    Serial.println("Isolation: no portal path, recovery AP");
    startSetupAp();
}

String resolvePortalUrl(const String &url) {
    if (url.startsWith("http://") || url.startsWith("https://")) {
        return url;
    }
    if (url.startsWith("/")) {
        return portalUrl(url.c_str());
    }
    return portalUrl(("/" + url).c_str());
}

void abortOtaSession(const char *why) {
    if (why && why[0]) {
        Serial.print("OTA abort ");
        Serial.println(why);
    }
    if (otaShaReady) {
        mbedtls_sha256_free(&otaSha);
        otaShaReady = false;
    }
    if (Update.isRunning()) {
        Update.abort();
    }
    otaSession = false;
    otaChunkDue = false;
    otaBusy = false;
    otaOffset = 0;
    otaSize = 0;
    otaChunkSize = otaWifiChunk >= 32 ? otaWifiChunk : EZG_OTA_CHUNK_BYTES;
    otaVersion = "";
    otaChecksum = "";
    otaUrl = "";
}

void beginOtaSession(JsonObjectConst fw) {
    if (mode != Mode::Service || otaBusy) {
        return;
    }
    String version = fw["version"].is<const char *>() ? String(fw["version"].as<const char *>()) : "";
    String checksum = fw["checksum"].is<const char *>() ? String(fw["checksum"].as<const char *>()) : "";
    String url = fw["url"].is<const char *>() ? String(fw["url"].as<const char *>()) : "";
    int expectedSize = fw["size"] | 0;
    int chunkSize = (WiFi.status() != WL_CONNECTED && loraLinkHasParent())
        ? (otaLoraChunk >= 32 ? otaLoraChunk : EZG_OTA_LORA_CHUNK_BYTES)
        : (otaWifiChunk >= 32 ? otaWifiChunk : EZG_OTA_CHUNK_BYTES);
    checksum.toLowerCase();
    if (!version.length() || version == EZG_FIRMWARE_VERSION) {
        if (otaSession) {
            abortOtaSession("running version");
        }
        return;
    }
    if (checksum.length() != 64 || !url.length() || expectedSize <= 0) {
        Serial.println("OTA offer missing checksum, URL, or size");
        return;
    }
    if (chunkSize < 32) {
        chunkSize = EZG_OTA_CHUNK_BYTES;
    }
    url = resolvePortalUrl(url);
    if (otaSession && otaVersion == version && otaChecksum == checksum && otaSize == expectedSize) {
        otaUrl = url;
        otaChunkSize = chunkSize;
        otaChunkDue = true;
        return;
    }
    abortOtaSession("");
    if (!Update.begin((size_t) expectedSize)) {
        Serial.print("OTA begin write failed: ");
        Serial.println(Update.errorString());
        return;
    }
    mbedtls_sha256_init(&otaSha);
    mbedtls_sha256_starts_ret(&otaSha, 0);
    otaShaReady = true;
    otaSession = true;
    otaVersion = version;
    otaChecksum = checksum;
    otaUrl = url;
    otaSize = expectedSize;
    otaOffset = 0;
    otaChunkSize = chunkSize;
    otaChunkDue = true;
    Serial.print("OTA ");
    Serial.print(version);
    Serial.print(" ");
    Serial.print(otaSize);
    Serial.print(" bytes, chunk ");
    Serial.println(otaChunkSize);
}

void finishOtaSession() {
    if (!otaSession || !otaShaReady) {
        abortOtaSession("finish without session");
        return;
    }
    unsigned char hash[32];
    mbedtls_sha256_finish_ret(&otaSha, hash);
    mbedtls_sha256_free(&otaSha);
    otaShaReady = false;
    String got;
    got.reserve(64);
    for (int i = 0; i < 32; i++) {
        if (hash[i] < 16) {
            got += '0';
        }
        got += String((unsigned int) hash[i], HEX);
    }
    got.toLowerCase();
    if (otaOffset != otaSize || got != otaChecksum) {
        Serial.print("OTA checksum failed got ");
        Serial.print(got);
        Serial.print(" expected ");
        Serial.println(otaChecksum);
        abortOtaSession("checksum");
        return;
    }
    if (!Update.end(true)) {
        Serial.print("OTA finish failed: ");
        Serial.println(Update.errorString());
        abortOtaSession("end");
        return;
    }
    otaSession = false;
    otaChunkDue = false;
    otaBusy = false;
    Serial.println("OTA applied");
    restartIntoNewFirmware();
}

bool ezgBeginOtaMeta(const String &version, const String &checksum, int size, int chunkSize) {
    JsonDocument fw;
    fw["version"] = version;
    fw["checksum"] = checksum;
    fw["size"] = size;
    fw["url"] = "/api/firmware/download";
    if (chunkSize >= 32) {
        otaLoraChunk = chunkSize;
    }
    beginOtaSession(fw.as<JsonObject>());
    return otaSession;
}

bool ezgWriteOtaBytes(const uint8_t *data, int len) {
    if (!otaSession || data == nullptr || len <= 0) {
        return false;
    }
    if (Update.write(const_cast<uint8_t *>(data), (size_t) len) != (size_t) len) {
        abortOtaSession("write");
        return false;
    }
    mbedtls_sha256_update_ret(&otaSha, data, (size_t) len);
    otaOffset += len;
    Serial.print("OTA ");
    Serial.print(otaOffset);
    Serial.print("/");
    Serial.println(otaSize);
    if (otaOffset >= otaSize) {
        finishOtaSession();
    }
    return true;
}

void applyConfigObject(JsonObjectConst cfg) {
    if (!cfg["version"].isNull()) {
        String ver = cfg["version"].as<String>();
        ver.trim();
        if (ver.length()) {
            configVersion = ver;
        }
    }
    if (!cfg["site_id"].isNull()) {
        siteId = cfg["site_id"].as<int>();
    }
    if (cfg["virtual_devices"].is<JsonArrayConst>()) {
        applyVirtualDevices(cfg["virtual_devices"].as<JsonArrayConst>());
    }
    if (cfg["internet_host"].is<const char *>()) {
        String host = String(cfg["internet_host"].as<const char *>());
        host.trim();
        if (host.length()) {
            internetHost = host;
        }
    }
    bool ntpChanged = false;
    if (cfg["ntp_server_1"].is<const char *>()) {
        String server = String(cfg["ntp_server_1"].as<const char *>());
        server.trim();
        if (server.length() && server != ntpServer1) {
            ntpServer1 = server;
            ntpChanged = true;
        }
    }
    if (cfg["ntp_server_2"].is<const char *>()) {
        String server = String(cfg["ntp_server_2"].as<const char *>());
        server.trim();
        if (server.length() && server != ntpServer2) {
            ntpServer2 = server;
            ntpChanged = true;
        }
    }
    if (ntpChanged) {
        ntpStarted = false;
    }
    int wifiChunk = cfg["ota_chunk_bytes"] | 0;
    if (wifiChunk >= 32) {
        otaWifiChunk = wifiChunk;
    }
    int loraChunk = cfg["ota_lora_chunk_bytes"] | 0;
    if (loraChunk >= 32) {
        otaLoraChunk = loraChunk;
    }
    if (cfg["wifi_rssi_floor_dbm"].is<int>() || cfg["wifi_roam_rssi_dbm"].is<int>()) {
        int v = cfg["wifi_rssi_floor_dbm"].is<int>()
            ? cfg["wifi_rssi_floor_dbm"].as<int>()
            : cfg["wifi_roam_rssi_dbm"].as<int>();
        if (v >= -100 && v <= -50) {
            wifiRssiFloor = v;
        }
    }
    if (cfg["wifi_rssi_margin_dbm"].is<int>() || cfg["wifi_roam_hysteresis_dbm"].is<int>()) {
        int v = cfg["wifi_rssi_margin_dbm"].is<int>()
            ? cfg["wifi_rssi_margin_dbm"].as<int>()
            : cfg["wifi_roam_hysteresis_dbm"].as<int>();
        if (v >= 0 && v <= 30) {
            wifiRssiMargin = v;
        }
    }
    if (cfg["wifi_networks"].is<JsonArrayConst>()) {
        siteNetworks.clear();
        for (JsonObjectConst net : cfg["wifi_networks"].as<JsonArrayConst>()) {
            WifiNet item;
            item.ssid = stripRssiLabel(String(net["ssid"] | ""));
            item.password = String(net["password"] | "");
            if (item.ssid.length()) {
                siteNetworks.push_back(item);
            }
        }
    }
    loraLinkApplyConfig(cfg);
    saveConfig();
}

void applyVirtualDevices(JsonArrayConst devices) {
    if (devices.isNull()) {
        return;
    }
    bool changed = false;
    for (JsonObjectConst vd : devices) {
        String id = vd["id"].is<const char *>() ? String(vd["id"].as<const char *>()) : "";
        id.trim();
        if (id.length() && id != virtualDeviceId) {
            virtualDeviceId = id;
            changed = true;
        }
        JsonObjectConst cfg = vd["config"].as<JsonObjectConst>();
        if (!cfg.isNull()) {
            if (cfg["friendly_name"].is<const char *>()) {
                friendlyName = String(cfg["friendly_name"].as<const char *>());
                changed = true;
            }
            JsonArrayConst relays = cfg["relays"].as<JsonArrayConst>();
            if (!relays.isNull() && relays.size() > 0) {
                int ms = relays[0]["pulse_duration_ms"] | 0;
                if (ms >= 50 && ms <= 10000 && ms != pulseMs) {
                    pulseMs = ms;
                    changed = true;
                }
            }
        }
        if (vd["open"].is<JsonObjectConst>()) {
            handleOpenCommand((uint32_t) (vd["open"]["command_id"] | 0));
        }
    }
    if (changed) {
        saveConfig();
    }
}

void startRelayPulse() {
    pinMode(EZG_RELAY_PIN, OUTPUT);
    digitalWrite(EZG_RELAY_PIN, HIGH);
    pulseUntilMs = millis() + (unsigned long) pulseMs;
    pulseRequested = true;
}

bool handleOpenCommand(uint32_t cmd) {
    pendingCommandId = (int) cmd;
    if (cmd != 0 && cmd == lastOpenCmd) {
        return true;
    }
    if (cmd != 0) {
        lastOpenCmd = cmd;
    }
    startRelayPulse();
    return false;
}

void serviceRelay() {
    if (pulseRequested && millis() >= pulseUntilMs) {
        digitalWrite(EZG_RELAY_PIN, LOW);
        pulseRequested = false;
    }
}

void pumpOtaService() {
    serviceRelay();
    serviceLed();
    yield();
}

class OtaChunkSink : public Stream {
public:
    OtaChunkSink(uint8_t *buffer, int capacity) : buf(buffer), cap(capacity), filled(0) {}

    size_t write(uint8_t c) override {
        if (filled >= cap) {
            return 0;
        }
        buf[filled++] = c;
        return 1;
    }

    size_t write(const uint8_t *data, size_t n) override {
        if (n == 0 || filled >= cap) {
            return 0;
        }
        int room = cap - filled;
        if ((int) n > room) {
            n = (size_t) room;
        }
        memcpy(buf + filled, data, n);
        filled += (int) n;
        pumpOtaService();
        return n;
    }

    int available() override { return 0; }
    int read() override { return -1; }
    int peek() override { return -1; }
    void flush() override {}

    uint8_t *buf;
    int cap;
    int filled;
};

void fetchOneOtaChunk() {
    if (!otaSession || otaBusy || otaOffset >= otaSize) {
        return;
    }
    int want = otaChunkSize;
    if (want < 32) {
        want = EZG_OTA_CHUNK_BYTES;
    }
    if (otaOffset + want > otaSize) {
        want = otaSize - otaOffset;
    }
    if (want <= 0) {
        finishOtaSession();
        return;
    }

    String url = otaUrl;
    url += url.indexOf('?') >= 0 ? '&' : '?';
    url += "offset=";
    url += String(otaOffset);
    url += "&length=";
    url += String(want);

    HTTPClient http;
    WiFiClientSecure client;
    http.setConnectTimeout(EZG_HTTP_CONNECT_TIMEOUT_MS);
    http.setTimeout(EZG_OTA_TIMEOUT_MS);
    client.setHandshakeTimeout(12);
    Serial.print("OTA chunk ");
    Serial.print(otaOffset);
    Serial.print("+");
    Serial.print(want);
    Serial.print(" ");
    Serial.println(url);

    bool began;
    if (url.startsWith("https://")) {
        client.setInsecure();
        began = http.begin(client, url);
    } else {
        began = http.begin(url);
    }
    if (!began) {
        Serial.println("OTA chunk begin failed");
        return;
    }

    otaBusy = true;
    int code = http.GET();
    Serial.print("OTA GET ");
    Serial.println(code);
    if (code != 200 && code != 206) {
        http.end();
        otaBusy = false;
        return;
    }

    uint8_t *mem = (uint8_t *) malloc((size_t) want);
    if (mem == nullptr) {
        Serial.println("OTA chunk malloc failed");
        http.end();
        otaBusy = false;
        return;
    }

    OtaChunkSink sink(mem, want);
    int got = http.writeToStream(&sink);
    http.end();
    otaBusy = false;
    if (got != want || sink.filled != want) {
        Serial.print("OTA chunk short ");
        Serial.print(sink.filled);
        Serial.print("/");
        Serial.println(want);
        free(mem);
        return;
    }
    if (Update.write(mem, (size_t) want) != (size_t) want) {
        Serial.print("OTA write failed: ");
        Serial.println(Update.errorString());
        free(mem);
        abortOtaSession("write");
        return;
    }
    mbedtls_sha256_update_ret(&otaSha, mem, (size_t) want);
    free(mem);
    otaOffset += want;
    Serial.print("OTA ");
    Serial.print(otaOffset);
    Serial.print("/");
    Serial.println(otaSize);
    if (otaOffset >= otaSize) {
        finishOtaSession();
    }
}

int readGateState() {
#if EZG_GATE_STATE_ENABLED
    pinMode(EZG_GATE_STATE_PIN, INPUT_PULLUP);
    return digitalRead(EZG_GATE_STATE_PIN) == LOW ? 1 : 0;
#else
    return -1;
#endif
}

bool isEzgVersionLabel(const char *version) {
    if (version == nullptr || version[0] == '\0') {
        return false;
    }
    size_t n = 0;
    int dots = 0;
    for (const char *p = version; *p; p++) {
        n++;
        if (n > 64) {
            return false;
        }
        char c = *p;
        bool ok = (c >= '0' && c <= '9')
            || (c >= 'A' && c <= 'Z')
            || (c >= 'a' && c <= 'z')
            || c == '.' || c == '_' || c == '+' || c == '-';
        if (!ok) {
            return false;
        }
        if (c == '.') {
            dots++;
        }
    }
    return dots >= 2 && version[0] >= '0' && version[0] <= '9';
}

String ezgverFromPartition(const esp_partition_t *part) {
    if (part == nullptr) {
        return "";
    }
    uint8_t marker[7];
    const uint8_t encoded[] = { 0xE0, 0xFF, 0xE2, 0xF3, 0xE0, 0xF7, 0x9F };
    for (size_t k = 0; k < 7; k++) {
        marker[k] = (uint8_t) (encoded[k] ^ 0xA5);
    }
    const size_t markerLen = 7;
    const size_t keep = markerLen + 64;
    uint8_t buf[1088];
    size_t overlap = 0;
    size_t offset = 0;
    while (offset < part->size) {
        size_t toRead = sizeof(buf) - overlap;
        if (offset + toRead > part->size) {
            toRead = part->size - offset;
        }
        if (toRead == 0) {
            break;
        }
        if (esp_partition_read(part, offset, buf + overlap, toRead) != ESP_OK) {
            break;
        }
        size_t filled = overlap + toRead;
        size_t searchEnd = filled >= markerLen ? filled - markerLen + 1 : 0;
        for (size_t i = 0; i < searchEnd; i++) {
            if (memcmp(buf + i, marker, markerLen) != 0) {
                continue;
            }
            char ver[65];
            size_t n = 0;
            for (size_t src = i + markerLen; src < filled && n < 64; src++) {
                char c = (char) buf[src];
                if (c == '\0' || c == '\r' || c == '\n' || c == ' ') {
                    break;
                }
                ver[n++] = c;
            }
            ver[n] = '\0';
            if (isEzgVersionLabel(ver)) {
                return String(ver);
            }
        }
        if (filled > keep) {
            overlap = keep;
            memmove(buf, buf + filled - overlap, overlap);
        } else {
            overlap = filled;
        }
        offset += toRead;
    }
    return "";
}

String fallbackFirmwareVersion() {
    if (fallbackVersionCached) {
        return cachedFallbackVersion;
    }
    const esp_partition_t *running = esp_ota_get_running_partition();
    const esp_partition_t *other = esp_ota_get_next_update_partition(running);
    String found = ezgverFromPartition(other);
    if (!found.length() && other != nullptr) {
        esp_app_desc_t desc;
        if (esp_ota_get_partition_description(other, &desc) == ESP_OK && isEzgVersionLabel(desc.version)) {
            found = String(desc.version);
        }
    }
    fallbackVersionCached = true;
    cachedFallbackVersion = found;
    return cachedFallbackVersion;
}

const char *wifiAuthLabel(uint8_t mode) {
    switch (mode) {
        case WIFI_AUTH_OPEN:
            return "open";
        case WIFI_AUTH_WPA3_PSK:
        case WIFI_AUTH_WPA2_WPA3_PSK:
            return "wpa3";
        default:
            return "wpa2";
    }
}

void collectUniqueScan(std::vector<SeenNet> &out) {
    int n = WiFi.scanNetworks(false, true, false, 120);
    if (n < 0) {
        out.clear();
        return;
    }
    ingestScanResults(out, n);
}

void ezgApplyOwnHeartbeat(bool ok, const char *json) {
    if (!ok || json == nullptr) {
        lastPortalAttemptOk = false;
        loraLinkNoteWifiPortal(false);
        return;
    }
    JsonDocument response;
    if (deserializeJson(response, json)) {
        lastPortalAttemptOk = false;
        loraLinkNoteWifiPortal(false);
        return;
    }
    if (!(response["success"] | false)) {
        lastPortalAttemptOk = false;
        loraLinkNoteWifiPortal(false);
        return;
    }
    lastPortalAttemptOk = true;
    loraLinkNoteWifiPortal(true);
    pendingScanAck = false;
    lastPortalOkMs = millis();
    pendingCommandId = 0;
    flashHeartbeatLed();
    applyHeartbeatIntervals(response["data"]);
    if (response["data"]["config"].is<JsonObject>()) {
        applyConfigObject(response["data"]["config"].as<JsonObject>());
    }
    applyVirtualDevices(response["data"]["virtual_devices"].as<JsonArrayConst>());
    if (response["data"]["scan"] | false) {
        scanWanted = true;
    }
    if (response["data"]["reboot"] | false) {
        Serial.println("Portal requested reboot");
        confirmFirmware();
        delay(250);
        rebootBoard();
    }
    bool offered = false;
    if (mode == Mode::Service && response["data"]["firmware"].is<JsonObject>()) {
        offered = true;
        beginOtaSession(response["data"]["firmware"].as<JsonObject>());
    }
    if (!offered && otaSession) {
        abortOtaSession("offer withdrawn");
    }
}

void sendHeartbeat() {
    refreshLinkStatus();
    JsonDocument body;
    body["hardware_id"] = hardwareId;
    body["status"] = WiFi.status() == WL_CONNECTED ? "wifi" : "isolated";
    int gate = readGateState();
    if (gate >= 0) {
        body["gate_state"] = gate ? "closed" : "open";
    }
    body["config_version"] = configVersion;
    body["firmware_version"] = EZG_FIRMWARE_VERSION;
    if (otaSession) {
        body["ota_version"] = otaVersion;
        body["ota_offset"] = otaOffset;
    }
    body["uptime_seconds"] = (unsigned long) (esp_timer_get_time() / 1000000LL);
    body["internet"] = lastInternetOk;
    time_t now = time(nullptr);
    if (now > 1700000000) {
        body["unix_time"] = (unsigned long) now;
    }
    String fallbackVersion = fallbackFirmwareVersion();
    if (fallbackVersion.length()) {
        body["fallback_firmware_version"] = fallbackVersion;
    }
    body["board_type"] = EZG_BOARD_TYPE;
    if (virtualDeviceId.length()) {
        JsonArray devices = body["virtual_devices"].to<JsonArray>();
        JsonObject device = devices.add<JsonObject>();
        device["id"] = virtualDeviceId;
        if (pendingCommandId > 0) {
            device["completed_command_id"] = pendingCommandId;
            device["received_command_id"] = pendingCommandId;
        }
    }
    bool ackScan = pendingScanAck;
    if (ackScan) {
        body["scanned"] = true;
    }
    JsonObject connectivity = body["connectivity"].to<JsonObject>();
    JsonObject current = connectivity["current"].to<JsonObject>();
    if (WiFi.status() == WL_CONNECTED) {
        current["type"] = "wifi";
        current["ssid"] = WiFi.SSID();
        current["rssi"] = WiFi.RSSI();
    } else {
        loraLinkFillConnectivity(current);
    }
    JsonObject stats = body["radio_stats"].to<JsonObject>();
    loraLinkFillRadioStats(stats);
    JsonArray others = connectivity["other"].to<JsonArray>();
    String currentSsid = WiFi.SSID();
    for (const auto &hit : lastNeighborhood) {
        JsonObject opt = others.add<JsonObject>();
        opt["type"] = "wifi";
        opt["ssid"] = hit.ssid;
        opt["rssi"] = hit.rssi;
        opt["security"] = hit.security;
        if (currentSsid.length() && currentSsid == hit.ssid && !current["security"].is<const char *>()) {
            current["security"] = hit.security;
        }
    }

    String payload;
    serializeJson(body, payload);
    if (!loraLinkEnqueueOwnHeartbeat(payload)) {
        lastPortalAttemptOk = false;
        loraLinkNoteWifiPortal(false);
    }
}

String htmlEscape(const String &in) {
    String out;
    for (size_t i = 0; i < in.length(); i++) {
        char c = in[i];
        if (c == '&') out += "&amp;";
        else if (c == '<') out += "&lt;";
        else if (c == '>') out += "&gt;";
        else if (c == '"') out += "&quot;";
        else if (c == '\'') out += "&#39;";
        else out += c;
    }
    return out;
}

int hexNibble(char c) {
    if (c >= '0' && c <= '9') {
        return c - '0';
    }
    if (c >= 'A' && c <= 'F') {
        return c - 'A' + 10;
    }
    if (c >= 'a' && c <= 'f') {
        return c - 'a' + 10;
    }
    return -1;
}

String hexEncode(const String &in) {
    static const char *digits = "0123456789ABCDEF";
    String out;
    out.reserve(in.length() * 2);
    for (size_t i = 0; i < in.length(); i++) {
        uint8_t b = (uint8_t) in[i];
        out += digits[b >> 4];
        out += digits[b & 0x0F];
    }
    return out;
}

String hexEncodeBytes(const uint8_t *data, size_t len) {
    static const char *digits = "0123456789ABCDEF";
    String out;
    out.reserve(len * 2);
    for (size_t i = 0; i < len; i++) {
        out += digits[data[i] >> 4];
        out += digits[data[i] & 0x0F];
    }
    return out;
}

String hexDecodeString(const String &hex) {
    if (hex.length() < 2 || hex.length() % 2 != 0) {
        return "";
    }
    String out;
    out.reserve(hex.length() / 2);
    for (size_t i = 0; i < hex.length(); i += 2) {
        int hi = hexNibble(hex[i]);
        int lo = hexNibble(hex[i + 1]);
        if (hi < 0 || lo < 0) {
            return "";
        }
        out += (char) ((hi << 4) | lo);
    }
    return out;
}

bool hexDecodeBytes(const String &hex, uint8_t *out, size_t len) {
    if (hex.length() != len * 2) {
        return false;
    }
    for (size_t i = 0; i < len; i++) {
        int hi = hexNibble(hex[i * 2]);
        int lo = hexNibble(hex[i * 2 + 1]);
        if (hi < 0 || lo < 0) {
            return false;
        }
        out[i] = (uint8_t) ((hi << 4) | lo);
    }
    return true;
}

bool parseJoinTarget(const String &raw, String &ssid) {
    joinHasBssid = false;
    joinChannel = 0;
    memset(joinBssid, 0, 6);
    if (raw.startsWith("ezg1:")) {
        String rest = raw.substring(5);
        int p1 = rest.indexOf(':');
        String ssidHex = p1 < 0 ? rest : rest.substring(0, p1);
        ssid = hexDecodeString(ssidHex);
        if (p1 >= 0) {
            String rest2 = rest.substring(p1 + 1);
            int p2 = rest2.indexOf(':');
            String bssidHex = p2 < 0 ? rest2 : rest2.substring(0, p2);
            if (hexDecodeBytes(bssidHex, joinBssid, 6)) {
                joinHasBssid = true;
            }
            if (p2 >= 0) {
                joinChannel = rest2.substring(p2 + 1).toInt();
            }
        }
        return ssid.length() > 0;
    }
    ssid = stripRssiLabel(raw);
    return ssid.length() > 0;
}

bool staHasRoutableIp() {
    IPAddress ip = WiFi.localIP();
    if (ip == IPAddress(0, 0, 0, 0)) {
        return false;
    }
    return !(ip == WiFi.softAPIP());
}

bool staAssociated() {
    return WiFi.status() == WL_CONNECTED && staHasRoutableIp();
}

void failJoin(const char *at, const String &err, bool dropSta = false) {
    joinFailedAt = at;
    joinError = err;
    if (dropSta) {
        WiFi.disconnect(false, false);
    }
    enterJoinPhase(JoinPhase::Failed);
}

String stripRssiLabel(const String &raw) {
    if (!raw.endsWith(" dBm)") || raw.length() < 8) {
        return raw;
    }
    int open = raw.lastIndexOf(" (");
    int dBmSpace = (int) raw.length() - 5;
    if (open < 0 || dBmSpace <= open + 2) {
        return raw;
    }
    for (int i = open + 2; i < dBmSpace; i++) {
        char c = raw[i];
        if (c == '-' && i == open + 2) {
            continue;
        }
        if (c < '0' || c > '9') {
            return raw;
        }
    }
    return raw.substring(0, open);
}

String wifiScanOptionsHtml() {
    std::vector<SeenNet> nets;
    collectUniqueScan(nets);
    if (nets.empty()) {
        return "<p class='muted'>No WiFi networks found. Reopen this page to scan again.</p>";
    }
    String html = "<div class='nets'>";
    for (const auto &hit : nets) {
        html += "<label class='net'><input type='radio' name='ssid' value=\"ezg1:";
        html += hexEncode(hit.ssid);
        html += ":";
        html += hexEncodeBytes(hit.bssid, 6);
        html += ":";
        html += String(hit.channel);
        html += "\" required> <span class='ssid'>";
        html += htmlEscape(hit.ssid);
        html += "</span> <span class='muted'>(";
        html += String(hit.rssi);
        html += " dBm)</span></label>";
    }
    html += "</div>";
    return html;
}

String setupPage(const String &title, const String &body) {
    String html = "<!DOCTYPE html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    html += "<title>" + title + "</title><style>";
    html += ":root{--ez-primary:#ff5733;--ez-primary-hover:#f7421a;--ez-bg:#f4f4f4;--ez-ink:#0b1220;--ez-muted:#5a6578}";
    html += "body{font-family:system-ui,sans-serif;background:var(--ez-bg);color:var(--ez-ink);margin:0;line-height:1.45}";
    html += ".wrap{max-width:28rem;margin:2.5rem auto;padding:0 1rem 2rem}";
    html += ".logo{display:block;width:140px;height:auto;margin:0 auto 1.25rem}";
    html += ".card{background:#fff;border-radius:.6rem;box-shadow:0 1px 3px rgba(11,18,32,.08);padding:1.25rem 1.35rem}";
    html += "h1{font-size:1.35rem;margin:0 0 .5rem}";
    html += "p{color:var(--ez-muted)}";
    html += "ol.steps{list-style:none;padding:0;margin:1.25rem 0}";
    html += "ol.steps li{padding:.6rem 0 .6rem 1.85rem;position:relative;color:#8a93a3;border-bottom:1px solid #eee}";
    html += "ol.steps li:before{content:'';position:absolute;left:0;top:.85rem;width:.7rem;height:.7rem;border-radius:50%;border:2px solid #d0d5dd;box-sizing:border-box}";
    html += "ol.steps li.active{color:var(--ez-ink);font-weight:600}";
    html += "ol.steps li.active:before{border-color:var(--ez-primary);background:var(--ez-primary);box-shadow:0 0 0 4px rgba(255,87,51,.2);animation:pulse 1s ease-in-out infinite}";
    html += "ol.steps li.done{color:var(--ez-primary)}";
    html += "ol.steps li.done:before{border-color:var(--ez-primary);background:var(--ez-primary)}";
    html += "ol.steps li.fail{color:#b42318}";
    html += "ol.steps li.fail:before{border-color:#b42318;background:#b42318}";
    html += "@keyframes pulse{50%{opacity:.45}}";
    html += "select,input{font:inherit;width:100%;box-sizing:border-box;margin-top:.3rem;padding:.6rem .7rem;border:1px solid #d0d5dd;border-radius:.4rem;background:#fff;color:var(--ez-ink)}";
    html += "select:focus,input:focus{outline:0;border-color:rgba(255,87,51,.55);box-shadow:0 0 0 .2rem rgba(255,87,51,.25)}";
    html += ".nets{margin:.35rem 0 0;border:1px solid #d0d5dd;border-radius:.4rem;background:#fff;max-height:14rem;overflow:auto}";
    html += "label.net{display:flex;align-items:baseline;gap:.45rem;margin:0;padding:.55rem .7rem;border-bottom:1px solid #eee;font-weight:400;color:var(--ez-ink)}";
    html += "label.net:last-child{border-bottom:0}";
    html += "label.net input{width:auto;margin:0}";
    html += "label.net .ssid{white-space:pre-wrap;flex:1}";
    html += "button{font:inherit;width:100%;box-sizing:border-box;margin-top:.75rem;padding:.7rem;background:var(--ez-primary);color:#fff;border:0;border-radius:.4rem;font-weight:600}";
    html += "button:hover{background:var(--ez-primary-hover)}";
    html += "button.secondary{background:#fff;color:var(--ez-ink);border:1px solid #d0d5dd}";
    html += "button.danger{background:#fff;color:#b42318;border:1px solid #b42318}";
    html += "details{margin:1.25rem 0}summary{cursor:pointer;font-weight:600;color:var(--ez-ink)}";
    html += ".err{color:#b42318;white-space:pre-wrap}.muted{color:var(--ez-muted);font-size:.95rem}";
    html += "a{color:var(--ez-primary)}a:hover{color:var(--ez-primary-hover)}";
    html += "code{font-size:.95em}";
    html += "</style></head><body><div class='wrap'>";
    html += "<img class='logo' src='/logo.png' alt='EZGate'>";
    html += "<div class='card'>" + body + "</div></div></body></html>";
    return html;
}

String setupStepsHtml(const char *wifiClass, const char *ipClass, const char *portalClass, const char *apClass) {
    String html = "<ol class='steps'>";
    html += "<li id='s-wifi' class='";
    html += wifiClass;
    html += "'>Connecting to WiFi</li>";
    html += "<li id='s-ip' class='";
    html += ipClass;
    html += "'>Getting IP Address</li>";
    html += "<li id='s-portal' class='";
    html += portalClass;
    html += "'>Provisioning on Portal</li>";
    html += "<li id='s-ap' class='";
    html += apClass;
    html += "'>Disabling Local WiFi AP</li></ol>";
    return html;
}

String progressPageHtml() {
    String body = "<h1>EZGate setup</h1><p>Board " + htmlEscape(hardwareId) + "</p>";
    body += setupStepsHtml("active", "", "", "");
    body += "<p id='detail' class='muted'>Stay on this EZGate network until setup finishes.</p>";
    body += "<p id='retry' style='display:none'><a href='/'>Try again</a></p>";
    body += "<script>";
    body += "function paint(p,err,detail,failAt){";
    body += "const ids=['s-wifi','s-ip','s-portal','s-ap'];";
    body += "const n={idle:-1,connecting:0,getting_ip:1,provisioning:2,ready:2,restored:2,failed:-1,finishing:3}[p];";
    body += "ids.forEach((id,i)=>{const el=document.getElementById(id);el.className=n<0?'':(i<n?'done':(i===n?'active':''));});";
    body += "const d=document.getElementById('detail');";
    body += "if(p==='failed'){const idx={wifi:0,ip:1,portal:2}[failAt];const f=(idx===undefined)?0:idx;";
    body += "ids.forEach((id,i)=>{document.getElementById(id).className=i<f?'done':(i===f?'fail':'');});";
    body += "d.className='err';d.textContent=err||'Setup failed.';document.getElementById('retry').style.display='block';return;}";
    body += "d.className='muted';d.textContent=detail||'Stay on this EZGate network until setup finishes.';";
    body += "if(p==='ready'){document.getElementById('s-portal').className='done';location.href='/site';}";
    body += "if(p==='restored'){document.getElementById('s-portal').className='done';location.href='/resume';}}";
    body += "async function tick(){try{const s=await(await fetch('/status')).json();paint(s.phase,s.error,s.detail,s.fail_at);";
    body += "if(s.phase!=='ready'&&s.phase!=='failed'&&s.phase!=='restored')setTimeout(tick,350);}catch(e){setTimeout(tick,1200);}}tick();";
    body += "</script>";
    return setupPage("EZGate setup", body);
}

void handleProgress() {
    setupServer.send(200, "text/html; charset=utf-8", progressPageHtml());
}

void handleLogo() {
    setupServer.sendHeader("Cache-Control", "max-age=86400");
    setupServer.send_P(200, "image/png", (PGM_P) EZG_LOGO_PNG, EZG_LOGO_PNG_LEN);
}

void handleStatus() {
    JsonDocument doc;
    doc["phase"] = joinPhaseName();
    doc["error"] = joinError;
    String detail;
    if (joinPhase == JoinPhase::Connecting) {
        detail = "Associating with " + joinSsid + "...";
    } else if (joinPhase == JoinPhase::GettingIp) {
        detail = "Waiting for a DHCP address...";
    } else if (joinPhase == JoinPhase::Provisioning) {
        detail = String("Contacting ") + EZG_PORTAL_HOST + "...";
    } else if (joinPhase == JoinPhase::Ready) {
        detail = "IP " + joinIp;
    } else if (joinPhase == JoinPhase::Restored) {
        detail = "Portal configuration loaded.";
    } else if (joinPhase == JoinPhase::Failed) {
        detail = joinError;
    }
    if (joinIp.length() && joinPhase != JoinPhase::Failed) {
        if (detail.length()) {
            detail += " ";
        }
        detail += "IP " + joinIp + ".";
    }
    doc["detail"] = detail;
    doc["ip"] = joinIp;
    doc["configured"] = isConfigured();
    doc["searching"] = configuredSsidList();
    doc["sta"] = WiFi.status() == WL_CONNECTED;
    doc["portal_ready"] = isConfigured() && lastPortalOkMs > 0
        && (millis() - lastPortalOkMs) < 15000UL
        && mode == Mode::Setup;
    doc["fail_at"] = joinFailedAt;
    String json;
    serializeJson(doc, json);
    setupServer.send(200, "application/json", json);
}

void serviceJoin() {
    if (mode != Mode::Setup) {
        return;
    }
    if (joinPhase == JoinPhase::Connecting) {
        if (staAssociated()) {
            enterJoinPhase(JoinPhase::GettingIp);
        } else if (millis() - joinStartedMs > (unsigned long) EZG_WIFI_CONNECT_TIMEOUT_MS) {
            failJoin("wifi", "Could not associate with \"" + joinSsid + "\". The password may be wrong, or the network name may include a trailing space.", true);
        }
        return;
    }
    if (joinPhase == JoinPhase::GettingIp) {
        if (staHasRoutableIp()) {
            joinIp = WiFi.localIP().toString();
            if (joinDwellDone()) {
                enterJoinPhase(JoinPhase::Provisioning);
                provisionYielded = false;
            }
        } else if (millis() - joinStartedMs > (unsigned long) EZG_WIFI_CONNECT_TIMEOUT_MS + 8000UL) {
            failJoin("ip", "Connected but did not get an IP address.", true);
        }
        return;
    }
    if (joinPhase == JoinPhase::Provisioning) {
        if (!joinDwellDone()) {
            return;
        }
        if (!provisionYielded) {
            provisionYielded = true;
            return;
        }
        if (joinViaLora) {
            if (loraLinkPortalOk() && siteId > 0) {
                saveConfig();
                enterJoinPhase(JoinPhase::Restored);
                Serial.println("LoRa setup dest_ok");
            } else if (millis() - phaseEnteredMs > 25000UL) {
                joinViaLora = false;
                failJoin("lora", "LoRa gateway did not finish setup.");
            }
            return;
        }
        JsonDocument doc;
        if (joinIsChange && siteId > 0) {
            JsonDocument body;
            body["hardware_id"] = hardwareId;
            body["friendly_name"] = friendlyName.length() ? friendlyName : hardwareId;
            body["site_id"] = siteId;
            body["wifi_ssid"] = joinSsid;
            body["wifi_password"] = joinPassword;
            String payload;
            serializeJson(body, payload);
            JsonDocument response;
            if (!httpPostJson(portalUrl("/api/setup/complete"), payload, response) || !(response["success"] | false)) {
                failJoin("portal", "Connected, but the portal did not accept the WiFi change.");
                return;
            }
            siteNetworks.clear();
            WifiNet net;
            net.ssid = joinSsid;
            net.password = joinPassword;
            siteNetworks.push_back(net);
            if (response["data"]["config_version"].is<const char *>()) {
                configVersion = String(response["data"]["config_version"].as<const char *>());
            }
            saveConfig();
            sendHeartbeat();
            enterJoinPhase(JoinPhase::Restored);
            Serial.println("WiFi change saved");
            return;
        }

        sendHeartbeat();
        if (isConfigured()) {
            enterJoinPhase(JoinPhase::Restored);
            Serial.println("Portal configuration restored");
            return;
        }

        if (!httpGetJson(portalUrl("/api/setup/sites") + "?hardware_id=" + hardwareId, doc) || !(doc["success"] | false)) {
            failJoin("portal", String("Could not reach the portal at ") + EZG_PORTAL_HOST + ".");
            return;
        }
        sitesDoc.clear();
        sitesDoc = doc;
        enterJoinPhase(JoinPhase::Ready);
        Serial.println("Portal provisioning ok");
    }
}

void handleSetupRoot() {
    if (joinPhase == JoinPhase::Connecting || joinPhase == JoinPhase::GettingIp || joinPhase == JoinPhase::Provisioning) {
        handleProgress();
        return;
    }
    if (joinPhase == JoinPhase::Ready) {
        setupServer.sendHeader("Location", "/site");
        setupServer.send(302, "text/plain", "");
        return;
    }
    if (joinPhase == JoinPhase::Restored) {
        setupServer.sendHeader("Location", "/resume");
        setupServer.send(302, "text/plain", "");
        return;
    }
    if (isConfigured()) {
        String body = "<h1>Reconnecting</h1>";
        body += "<p>Board " + htmlEscape(hardwareId);
        if (friendlyName.length()) {
            body += " (" + htmlEscape(friendlyName) + ")";
        }
        body += "</p>";
        if (joinPhase == JoinPhase::Failed && joinError.length()) {
            body += "<p class='err'>" + htmlEscape(joinError) + "</p>";
        }
        body += "<p>Searching for <strong>";
        body += htmlEscape(configuredSsidList());
        body += "</strong></p>";
        body += "<p id='sta' class='muted'>The board keeps retrying this network in the background.</p>";
        body += "<details><summary>Change WiFi</summary>";
        body += "<form method='POST' action='/change-wifi'>";
        body += "<p>WiFi network</p>";
        body += wifiScanOptionsHtml();
        body += "<p>Password<br><input name='password' type='password'></p>";
        body += "<button type='submit'>Use this WiFi</button></form></details>";
        body += "<form method='POST' action='/clear' onsubmit=\"return confirm('Clear local configuration and start setup again? The portal still has this board.');\">";
        body += "<button class='danger' type='submit'>Clear configuration and set up again</button></form>";
        body += "<script>";
        body += "async function tick(){try{const s=await(await fetch('/status')).json();";
        body += "const el=document.getElementById('sta');";
        body += "if(s.phase==='restored'||s.portal_ready){location.href='/resume';return;}";
        body += "el.textContent=s.sta?'Associated with WiFi; checking the portal...':'Not associated yet; retrying '+ (s.searching||'') + '.';";
        body += "setTimeout(tick,800);}catch(e){setTimeout(tick,1200);}}tick();";
        body += "</script>";
        setupServer.send(200, "text/html; charset=utf-8", setupPage("Reconnecting", body));
        return;
    }
    String body = "<h1>EZGate setup</h1><p>Board " + htmlEscape(hardwareId) + "</p>";
    if (joinPhase == JoinPhase::Failed && joinError.length()) {
        body += "<p class='err'>" + htmlEscape(joinError) + "</p>";
    }
    body += "<form method='POST' action='/join'>";
    body += "<p>WiFi network</p>";
    body += wifiScanOptionsHtml();
    body += "<p>Password<br><input name='password' type='password'></p>";
    body += "<button type='submit'>Join WiFi</button></form>";
    if (loraLinkHeardCount() > 0) {
        body += "<h2>Or join over LoRa</h2><p class='muted'>A gateway for these sites is in range.</p>";
        body += "<form method='POST' action='/complete-lora'>";
        body += "<p>Friendly name<br><input name='friendly_name' required value='" + htmlEscape(hardwareId) + "'></p>";
        body += "<p>Site<br><select name='site_id'>";
        for (int i = 0; i < loraLinkHeardCount(); i++) {
            int sid = 0;
            String sname;
            if (!loraLinkHeardAt(i, sid, sname)) {
                continue;
            }
            body += "<option value='" + String(sid) + "'>" + htmlEscape(sname.length() ? sname : String("Site ") + String(sid)) + "</option>";
        }
        body += "</select></p><button type='submit'>Use LoRa</button></form>";
    }
    setupServer.send(200, "text/html; charset=utf-8", setupPage("EZGate setup", body));
}

void beginJoin(const String &ssid, const String &password, bool changing) {
    joinPassword = password;
    joinIsChange = changing;
    joinError = "";
    joinFailedAt = "";
    joinIp = "";
    provisionYielded = false;
    if (!parseJoinTarget(ssid, joinSsid)) {
        failJoin("wifi", "Choose a WiFi network.");
        handleSetupRoot();
        return;
    }
    Serial.print("Joining SSID len=");
    Serial.print(joinSsid.length());
    Serial.print(" hex=");
    Serial.println(hexEncode(joinSsid));
    WiFi.scanDelete();
    WiFi.disconnect(false, false);
    delay(150);
    WiFi.persistent(false);
    WiFi.mode(WIFI_AP_STA);
    applyStaTuning();
    if (joinHasBssid) {
        WiFi.begin(joinSsid.c_str(), joinPassword.c_str(), joinChannel, joinBssid);
    } else {
        WiFi.begin(joinSsid.c_str(), joinPassword.c_str());
    }
    joinStartedMs = millis();
    enterJoinPhase(JoinPhase::Connecting);
    handleProgress();
}

void handleJoin() {
    beginJoin(setupServer.arg("ssid"), setupServer.arg("password"), false);
}

void handleChangeWifi() {
    beginJoin(setupServer.arg("ssid"), setupServer.arg("password"), true);
}

void handleClear() {
    clearConfig();
    joinIsChange = false;
    joinPhase = JoinPhase::Idle;
    joinError = "";
    joinFailedAt = "";
    handleSetupRoot();
}

void stopSetupAp() {
    setupServer.stop();
    setupHttpStarted = false;
    WiFi.softAPdisconnect(true);
    delay(200);
}

void handleResume() {
    if (!isConfigured() && WiFi.status() != WL_CONNECTED) {
        handleSetupRoot();
        return;
    }
    String pageBody = "<h1>Connected</h1>";
    pageBody += setupStepsHtml("done", "done", "done", "active");
    pageBody += "<p class='muted'>Portal configuration loaded. Disabling the EZGate access point...</p>";
    pageBody += "<script>setTimeout(function(){document.getElementById('s-ap').className='done';},800);</script>";
    setupServer.send(200, "text/html; charset=utf-8", setupPage("Connected", pageBody));
    delay(1600);
    stopSetupAp();
    if (WiFi.status() == WL_CONNECTED) {
        WiFi.mode(WIFI_STA);
        applyStaTuning();
    }
    mode = Mode::Service;
    joinPhase = JoinPhase::Idle;
    joinIsChange = false;
    lastPortalOkMs = millis();
    lastHeartbeatMs = 0;
}

void handleSite() {
    if (joinPhase != JoinPhase::Ready) {
        handleSetupRoot();
        return;
    }
    String body = "<h1>Choose site</h1>";
    body += "<p class='muted'>Board " + htmlEscape(hardwareId) + " - IP " + htmlEscape(joinIp) + "</p>";
    body += setupStepsHtml("done", "done", "done", "");
    JsonArray sites = sitesDoc["data"]["sites"].as<JsonArray>();
    if (sites.isNull() || sites.size() == 0) {
        body += "<p class='err'>No sites in the portal yet. Add a site, then try again.</p>";
        body += "<p><a href='/'>Back</a></p>";
        setupServer.send(200, "text/html; charset=utf-8", setupPage("Choose site", body));
        return;
    }
    String name = friendlyName;
    if (sitesDoc["data"]["board"]["friendly_name"].is<const char *>()) {
        String fromPortal = String(sitesDoc["data"]["board"]["friendly_name"].as<const char *>());
        if (fromPortal.length()) {
            name = fromPortal;
        }
    }
    int selectedSite = siteId;
    if (!sitesDoc["data"]["board"]["site_id"].isNull()) {
        selectedSite = sitesDoc["data"]["board"]["site_id"].as<int>();
    }
    body += "<form method='POST' action='/complete'>";
    body += "<input type='hidden' name='wifi_ssid' value='" + htmlEscape(joinSsid) + "'>";
    body += "<input type='hidden' name='wifi_password' value='" + htmlEscape(joinPassword) + "'>";
    body += "<p>Friendly name<br><input name='friendly_name' required value='" + htmlEscape(name) + "'></p>";
    body += "<p>Site<br><select name='site_id'>";
    for (JsonObject site : sites) {
        int id = site["id"].as<int>();
        body += "<option value='" + String(id) + "'";
        if (id == selectedSite) {
            body += " selected";
        }
        body += ">" + htmlEscape(String(site["name"] | "")) + "</option>";
    }
    body += "</select></p><button type='submit'>Finish setup</button></form>";
    setupServer.send(200, "text/html; charset=utf-8", setupPage("Choose site", body));
}

void handleComplete() {
    JsonDocument body;
    body["hardware_id"] = hardwareId;
    body["friendly_name"] = setupServer.arg("friendly_name");
    body["site_id"] = setupServer.arg("site_id").toInt();
    body["wifi_ssid"] = stripRssiLabel(setupServer.arg("wifi_ssid"));
    body["wifi_password"] = setupServer.arg("wifi_password");
    String payload;
    serializeJson(body, payload);
    JsonDocument response;
    if (!httpPostJson(portalUrl("/api/setup/complete"), payload, response) || !(response["success"] | false)) {
        String page = "<h1>Setup</h1><p class='err'>Portal rejected setup.</p><p><a href='/site'>Back</a></p>";
        setupServer.send(200, "text/html; charset=utf-8", setupPage("Setup", page));
        return;
    }
    friendlyName = setupServer.arg("friendly_name");
    siteId = setupServer.arg("site_id").toInt();
    siteNetworks.clear();
    WifiNet net;
    net.ssid = stripRssiLabel(setupServer.arg("wifi_ssid"));
    net.password = setupServer.arg("wifi_password");
    if (net.ssid.length()) {
        siteNetworks.push_back(net);
    }
    if (response["data"]["config_version"].is<const char *>()) {
        configVersion = String(response["data"]["config_version"].as<const char *>());
    }
    if (response["data"]["lora_key_id"].is<int>()) {
        loraKeysSetId((uint16_t) response["data"]["lora_key_id"].as<int>());
    }
    if (response["data"]["lora_site_key_wrapped"].is<const char *>()) {
        loraKeysUnwrapSite(String(response["data"]["lora_site_key_wrapped"].as<const char *>()));
    }
    saveConfig();
    Serial.println("Disabling local WiFi AP");
    String pageBody = "<h1>Setup complete</h1>";
    pageBody += setupStepsHtml("done", "done", "done", "active");
    pageBody += "<p class='muted'>Disabling the EZGate access point and rebooting...</p>";
    pageBody += "<script>setTimeout(function(){document.getElementById('s-ap').className='done';},800);</script>";
    setupServer.send(200, "text/html; charset=utf-8", setupPage("Setup complete", pageBody));
    delay(1600);
    setupServer.stop();
    WiFi.softAPdisconnect(true);
    delay(300);
    confirmFirmware();
    rebootBoard();
}

void handleCompleteLora() {
    String name = setupServer.arg("friendly_name");
    int sid = setupServer.arg("site_id").toInt();
    name.trim();
    if (!name.length() || sid < 1) {
        joinError = "Choose a heard LoRa site and a name.";
        handleSetupRoot();
        return;
    }
    friendlyName = name;
    if (!loraLinkSetupViaHeard(sid, name)) {
        joinError = "Could not reach a LoRa gateway for that site.";
        handleSetupRoot();
        return;
    }
    joinViaLora = true;
    enterJoinPhase(JoinPhase::Provisioning);
    String body = "<h1>LoRa setup</h1><p>Asking a nearby gateway to register this board...</p>";
    setupServer.send(200, "text/html; charset=utf-8", setupPage("LoRa setup", body));
}

void startSetupAp() {
    mode = Mode::Setup;
    if (joinPhase != JoinPhase::Connecting && joinPhase != JoinPhase::GettingIp
        && joinPhase != JoinPhase::Provisioning && joinPhase != JoinPhase::Ready
        && joinPhase != JoinPhase::Restored) {
        joinPhase = JoinPhase::Idle;
    }
    WiFi.persistent(false);
    WiFi.mode(WIFI_AP_STA);
    String ssid = String(EZG_AP_SSID_PREFIX) + hardwareId.substring(hardwareId.length() > 4 ? hardwareId.length() - 4 : 0);
    bool ok = WiFi.softAP(ssid.c_str(), EZG_AP_PASSWORD);
    if (!ezgUsbHostAttached()) {
        Serial.print(isConfigured() ? "Recovery AP " : "Setup AP ");
        Serial.print(ssid);
        Serial.print(ok ? " started. Open http://192.168.4.1" : " FAILED");
        Serial.println();
    }
    if (!setupHttpStarted) {
        setupServer.on("/", HTTP_GET, handleSetupRoot);
        setupServer.on("/logo.png", HTTP_GET, handleLogo);
        setupServer.on("/join", HTTP_POST, handleJoin);
        setupServer.on("/change-wifi", HTTP_POST, handleChangeWifi);
        setupServer.on("/clear", HTTP_POST, handleClear);
        setupServer.on("/resume", HTTP_GET, handleResume);
        setupServer.on("/progress", HTTP_GET, handleProgress);
        setupServer.on("/status", HTTP_GET, handleStatus);
        setupServer.on("/site", HTTP_GET, handleSite);
        setupServer.on("/complete", HTTP_POST, handleComplete);
        setupServer.on("/complete-lora", HTTP_POST, handleCompleteLora);
        setupServer.begin();
        setupHttpStarted = true;
    }
}

void enterService() {
    mode = Mode::Service;
    joinPhase = JoinPhase::Idle;
    joinIsChange = false;
    lastPortalOkMs = millis();
    lastHeartbeatMs = 0;
}

void sendLoraHeartbeatIfDue() {
    loraLinkPollUplink();
}

void leaveRecovery(const char *why) {
    Serial.print("Portal reachable; dropping setup AP");
    if (why && why[0]) {
        Serial.print(" (");
        Serial.print(why);
        Serial.print(")");
    }
    Serial.println();
    stopSetupAp();
    if (loraOnly || (wifiRoamActive && !wifiFloorDropped)) {
        WiFi.setAutoReconnect(false);
        WiFi.disconnect(true, false);
        WiFi.mode(WIFI_OFF);
    } else if (WiFi.status() == WL_CONNECTED) {
        WiFi.mode(WIFI_STA);
        applyStaTuning();
    }
    enterService();
}

void serviceRecovery() {
    if (!isConfigured()) {
        return;
    }
    if (joinPhase == JoinPhase::Connecting || joinPhase == JoinPhase::GettingIp
        || joinPhase == JoinPhase::Provisioning || joinPhase == JoinPhase::Ready) {
        return;
    }
    if (wifiFloorDropped) {
        if (loraLinkPortalOk() || loraLinkHasParent() || loraLinkHasGatewayPeer()) {
            wifiFloorDropped = false;
            wifiRoamActive = true;
            wifiAboveFloorSinceMs = 0;
            leaveRecovery("lora");
            return;
        }
        if (WiFi.status() == WL_CONNECTED) {
            int rssi = WiFi.RSSI();
            if (rssi == 0) {
                return;
            }
            if (rssi >= wifiRssiFloor) {
                wifiFloorDropped = false;
                wifiRoamActive = false;
                wifiAboveFloorSinceMs = 0;
                leaveRecovery("wifi");
                return;
            }
            wifiAboveFloorSinceMs = 0;
            WiFi.setAutoReconnect(false);
            WiFi.disconnect(true, false);
            WiFi.mode(WIFI_AP_STA);
        }
        sendLoraHeartbeatIfDue();
        return;
    }
    bool wifiPortal = WiFi.status() == WL_CONNECTED && lastPortalOkMs > 0
        && (millis() - lastPortalOkMs) < 15000UL;
    bool loraPortal = loraLinkPortalOk();
    const char *why = loraPortal ? "lora" : "wifi";
    if (joinPhase == JoinPhase::Restored) {
        if (WiFi.softAPgetStationNum() == 0 && (wifiPortal || loraPortal)) {
            leaveRecovery(why);
        }
        return;
    }
    if (wifiPortal || loraPortal) {
        if (WiFi.softAPgetStationNum() == 0) {
            leaveRecovery(why);
        } else {
            enterJoinPhase(JoinPhase::Restored);
        }
        return;
    }
    if (WiFi.status() == WL_CONNECTED) {
        if (millis() - lastHeartbeatMs >= (unsigned long) wifiHeartbeatSeconds * 1000UL) {
            lastHeartbeatMs = millis();
            sendHeartbeat();
        }
        return;
    }
    sendLoraHeartbeatIfDue();
    serviceSta();
}

void checkConfigButton() {
    pinMode(EZG_CONFIG_BUTTON_PIN, INPUT_PULLUP);
    bool down = digitalRead(EZG_CONFIG_BUTTON_PIN) == LOW;
    if (down && !configButtonWasDown) {
        unsigned long start = millis();
        while (digitalRead(EZG_CONFIG_BUTTON_PIN) == LOW && millis() - start < 50) {
            delay(10);
        }
        if (millis() - start >= 30) {
            clearConfig();
            delay(200);
            confirmFirmware();
            rebootBoard();
        }
    }
    configButtonWasDown = down;
}

bool ezgWifiConnected() {
    return WiFi.status() == WL_CONNECTED;
}

bool ezgIsService() {
    return mode == Mode::Service;
}

bool ezgHttpPostJson(const String &url, const String &body, JsonDocument &doc) {
    return httpPostJson(url, body, doc);
}

String ezgPortalUrl(const char *path) {
    return portalUrl(path);
}

void ezgStartRelayPulse() {
    startRelayPulse();
}

bool ezgHandleOpenCommand(uint32_t cmd) {
    return handleOpenCommand(cmd);
}

void ezgFlashHeartbeatLed() {
    flashHeartbeatLed();
}

void ezgApplyConfigObject(JsonObjectConst cfg) {
    applyConfigObject(cfg);
}

String ezgVirtualDeviceId() {
    return virtualDeviceId;
}

void ezgSetVirtualDeviceId(const String &id) {
    String next = id;
    next.trim();
    if (!next.length() || next == virtualDeviceId) {
        return;
    }
    virtualDeviceId = next;
    saveConfig();
}

void ezgApplyConfigJson(const char *js, size_t n) {
    if (js == nullptr || n < 2) {
        return;
    }
    JsonDocument doc;
    if (deserializeJson(doc, js, n) != DeserializationError::Ok) {
        Serial.println("config parse fail");
        return;
    }
    applyConfigObject(doc.as<JsonObject>());
}

int ezgReadGateState() {
    return readGateState();
}

String ezgFallbackFirmware() {
    return fallbackFirmwareVersion();
}

unsigned long ezgUptimeSeconds() {
    return (unsigned long) (esp_timer_get_time() / 1000000LL);
}

void ezgConfirmFirmware() {
    confirmFirmware();
}

void ezgRebootBoard() {
    rebootBoard();
}

bool ezgApplyUsbConfigJson(const String &json) {
    JsonDocument doc;
    if (deserializeJson(doc, json)) {
        return false;
    }
    applyConfigObject(doc.as<JsonObject>());
    if (siteId < 1) {
        return false;
    }
    enterService();
    staConnecting = false;
    lastReconnectAttemptMs = 0;
    WiFi.disconnect(false, false);
    return true;
}

bool ezgClearUsbConfig() {
    clearConfig();
    joinIsChange = false;
    joinPhase = JoinPhase::Idle;
    joinError = "";
    joinFailedAt = "";
    lastPortalOkMs = 0;
    lastPortalAttemptOk = false;
    staConnecting = false;
    lastReconnectAttemptMs = 0;
    WiFi.disconnect(true, false);
    startSetupAp();
    return true;
}

void setup() {
    Serial.begin(115200);
    delay(200);
    asm volatile ("" : : "m"(EZG_VERSION_TAG));
    pinMode(EZG_RELAY_PIN, OUTPUT);
    digitalWrite(EZG_RELAY_PIN, LOW);
    setLed(false);
    hardwareId = macId();
    refreshOtaPendingVerify();
    rollbackIfUnstableReset();
    initNvs();
    loadConfig();
    loraKeysBegin();
    if (!loraOnly && !siteNetworks.empty()) {
        connectBestWifi(false);
    }
    if (isConfigured()) {
        enterService();
    } else {
        startSetupAp();
    }
    loopStartedMs = millis();
}

void loop() {
    loraKeysServiceUsb();
    confirmFirmwareIfStable();
    checkConfigButton();
    if (!loraLinkStarted) {
        loraLinkStarted = true;
        loraLinkBegin();
    }
    loraLinkService();
    loraLinkPollOwnHeartbeat();
    if (loraLinkShouldDropWifi() && WiFi.status() == WL_CONNECTED) {
        if (!ezgUsbHostAttached()) {
            if (loraOnly) {
                Serial.println("EZG lora drop WiFi after portal_ok");
            } else {
                Serial.println("EZG drop WiFi");
            }
        }
        WiFi.setAutoReconnect(false);
        WiFi.disconnect(true, false);
        WiFi.mode((mode == Mode::Setup || wifiFloorDropped) ? WIFI_AP_STA : WIFI_OFF);
    }
    serviceRelay();
    serviceLed();
    serviceSta();
    serviceAsyncScan();
    if (mode == Mode::Setup) {
        setupServer.handleClient();
        serviceJoin();
        if (wifiFloorDropped) {
            considerRoam();
        }
        serviceRecovery();
        return;
    }

    if (WiFi.status() != WL_CONNECTED) {
        sendLoraHeartbeatIfDue();
        considerRoam();
        considerIsolation();
        return;
    }

    if (loraLinkHasParent() && (loraOnly || wifiRoamActive || !lastPortalAttemptOk)) {
        sendLoraHeartbeatIfDue();
    }
    if (millis() - lastHeartbeatMs >= (unsigned long) wifiHeartbeatSeconds * 1000UL) {
        lastHeartbeatMs = millis();
        sendHeartbeat();
    }
    if (otaChunkDue && !pulseRequested && WiFi.status() == WL_CONNECTED) {
        otaChunkDue = false;
        fetchOneOtaChunk();
    }

    considerRoam();
    considerIsolation();
}
