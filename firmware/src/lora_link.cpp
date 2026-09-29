#include "lora_link.h"

#include "config.h"
#include "lora_chunk.h"
#include "lora_keys.h"
#include "lora_radio.h"

#include <WiFi.h>
#include <cstring>
#include <cstdio>
#include <cstdlib>
#include <ctime>
#include <freertos/FreeRTOS.h>
#include <freertos/queue.h>
#include <freertos/task.h>

extern String hardwareId;
extern int siteId;
extern String friendlyName;
extern String configVersion;
extern int wifiHeartbeatSeconds;
extern int loraHeartbeatSeconds;
extern int pulseMs;
extern unsigned long lastPortalOkMs;
extern bool lastPortalAttemptOk;
extern bool lastInternetOk;
extern int pendingCommandId;
extern bool pendingScanAck;
extern bool scanWanted;
extern bool otaSession;
extern int otaOffset;
extern int otaSize;
extern int otaLoraChunk;
extern String otaVersion;
extern String otaChecksum;
extern bool loraOnly;
extern bool wifiRoamActive;
extern int loraFreqHz;
extern int isolationMisses;
extern int loraHopLimit;
extern int loraTxRetries;
extern int loraDiscMinMs;
extern int loraDiscMaxMs;
extern int loraStaggerMs;
extern int loraRouteExpiry;
extern int loraOtaMax;
extern int loraOtaAirtimeMs;
extern bool loraLogRadio;

extern bool ezgWifiConnected();
extern bool ezgIsService();
extern bool ezgHttpPostJson(const String &url, const String &body, JsonDocument &doc);
extern bool ezgHttpGetBytes(const String &url, uint8_t *buf, int want, int &got);
extern String ezgPortalUrl(const char *path);
extern void ezgStartRelayPulse();
extern void ezgSetHeartbeatSeconds(int seconds);
extern void ezgSetWifiHeartbeatSeconds(int seconds);
extern bool ezgHandleOpenCommand(uint32_t cmd);
extern void ezgFlashHeartbeatLed();
extern void ezgApplyConfigObject(JsonObjectConst cfg);
extern void ezgApplyConfigJson(const char *js, size_t n);
extern bool ezgBeginOtaMeta(const String &version, const String &checksum, int size, int chunkSize);
extern bool ezgWriteOtaBytes(const uint8_t *data, int len);
extern int ezgReadGateState();
extern String ezgFallbackFirmware();
extern unsigned long ezgUptimeSeconds();
extern void ezgConfirmFirmware();
extern void ezgRebootBoard();

namespace {

const uint8_t MAGIC = 0xE7;
const uint8_t T_BEACON = 1;
const uint8_t T_PROBE = 2;
const uint8_t T_PROBE_ACK = 3;
const uint8_t T_UP = 4;
const uint8_t T_DOWN = 5;
const uint8_t T_ACK = 6;
const uint8_t T_OTA = 7;
const uint8_t T_SETUP = 8;
const uint8_t T_CHUNK = 9;

const uint8_t D_OPEN = 1;
const uint8_t D_SCAN = 2;
const uint8_t D_REBOOT = 3;
const uint8_t D_CONFIG = 4;
const uint8_t D_FW = 5;
const uint8_t D_INTERVAL = 6;
const uint8_t D_SETUP_OK = 7;

const uint8_t P_OPEN = 0;
const uint8_t P_HB = 1;
const uint8_t P_CONFIG = 2;
const uint8_t P_OTA = 3;

struct __attribute__((packed)) LoraHdr {
    uint8_t magic;
    uint8_t type;
    uint8_t hop_htg;
    uint16_t site;
    uint8_t src[6];
    uint8_t dst[6];
    uint16_t pkt;
    uint32_t ctr;
    uint8_t plen;
};

struct Peer {
    uint8_t mac[6];
    uint8_t hops;
    int rssi;
    uint16_t epoch;
    unsigned long heardMs;
    bool child;
    char name[17];
    int site;
};

struct HeardSite {
    int site;
    char name[17];
    uint8_t gw[6];
    unsigned long heardMs;
};

struct HttpJob {
    uint8_t prio;
    uint8_t kind;
    uint8_t src[6];
    char *body;
    int otaOff;
    int otaLen;
};

struct OtaHold {
    bool ready;
    bool active;
    uint8_t dst[6];
    uint32_t offset;
    uint16_t len;
    uint32_t total;
    char version[17];
    char checksum[65];
    char url[160];
    uint8_t data[199];
};

enum { JOB_HB = 1, JOB_SETUP = 2, JOB_OTA = 3 };

uint8_t selfMac[6];
uint8_t bcast[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
uint8_t parentMac[6];
uint8_t gwMac[6];
bool haveParent = false;
bool radioUp = false;
uint8_t hopsToGw = 255;
uint16_t gwEpoch = 0;
uint16_t pktSeq = 0;
uint16_t lastSentPkt = 0;
unsigned long lastTxMs = 0;
int parentRssi = 0;
unsigned long lastRadioOkMs = 0;
unsigned long lastLoraPortalOkMs = 0;
unsigned long lastBeaconMs = 0;
unsigned long lastProbeMs = 0;
unsigned long lastHbLoraMs = 0;
unsigned long airWindowStart = 0;
unsigned long airUsedMs = 0;
unsigned long lastHeardParentMs = 0;
uint16_t statsTx = 0, statsRx = 0, statsRetry = 0, statsFail = 0;
uint16_t dupSuppress = 0, probeAnswers = 0;
uint16_t dropOpen = 0, dropHb = 0, dropCfg = 0, dropOta = 0;
uint16_t lastDup[16];
uint8_t lastDupN = 0;
Peer peers[8];
HeardSite heard[6];
QueueHandle_t httpQ = nullptr;
QueueHandle_t downQ = nullptr;
OtaHold otaHold;
int otaActive = 0;
bool wifiPortalConfirmed = false;
bool setupPending = false;
int setupSite = 0;
String setupName;
bool loraPortalOkFlag = false;
portMUX_TYPE chunkMux = portMUX_INITIALIZER_UNLOCKED;
LoraChunkRx chunkRx = {};

struct ChunkTx {
    bool active;
    bool waitAck;
    uint8_t kind;
    uint8_t dst[6];
    uint32_t total;
    uint32_t offset;
    uint32_t crc;
    uint16_t waitLen;
    uint16_t waitPkt;
    unsigned long waitMs;
    unsigned long startedMs;
    uint8_t *ram;
};

ChunkTx chunkTx = {};
struct ChunkPend {
    uint8_t kind;
    uint8_t dst[6];
    uint32_t len;
    uint8_t *ram;
};
ChunkPend chunkPend = {};
uint8_t lastRxKind = 0;
uint32_t lastRxGot = 0;
uint32_t lastRxTotal = 0;

const char *chunkKindName(uint8_t kind) {
    if (kind == LORA_CHUNK_KIND_FIRMWARE) {
        return "firmware";
    }
    if (kind == LORA_CHUNK_KIND_UPLINK) {
        return "uplink";
    }
    return "config";
}

void logRadio(const char *msg) {
    if (!loraLogRadio || ezgUsbHostAttached()) {
        return;
    }
    Serial.print("EZG lora ");
    Serial.println(msg);
}

void logRadioNum(const char *msg, int n) {
    if (!loraLogRadio || ezgUsbHostAttached()) {
        return;
    }
    Serial.print("EZG lora ");
    Serial.print(msg);
    Serial.println(n);
}

void macFromId(const String &id, uint8_t mac[6]) {
    memset(mac, 0, 6);
    String t = id;
    t.toUpperCase();
    int n = 0;
    for (size_t i = 0; i + 1 < t.length() && n < 6; i += 2) {
        mac[n++] = (uint8_t) strtoul(t.substring(i, i + 2).c_str(), nullptr, 16);
    }
}

String macHex(const uint8_t mac[6]) {
    char buf[13];
    snprintf(buf, sizeof(buf), "%02X%02X%02X%02X%02X%02X", mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
    return String(buf);
}

bool macEq(const uint8_t a[6], const uint8_t b[6]) {
    return memcmp(a, b, 6) == 0;
}

bool macBcast(const uint8_t a[6]) {
    return macEq(a, bcast);
}

bool isDup(uint16_t pkt) {
    for (uint8_t i = 0; i < lastDupN && i < 16; i++) {
        if (lastDup[i] == pkt) {
            dupSuppress++;
            return true;
        }
    }
    if (lastDupN < 16) {
        lastDup[lastDupN++] = pkt;
    } else {
        memmove(lastDup, lastDup + 1, 15 * sizeof(uint16_t));
        lastDup[15] = pkt;
    }
    return false;
}

Peer *findPeer(const uint8_t mac[6]) {
    for (int i = 0; i < 8; i++) {
        if (peers[i].heardMs && macEq(peers[i].mac, mac)) {
            return &peers[i];
        }
    }
    return nullptr;
}

Peer *touchPeer(const uint8_t mac[6], uint8_t hops, int rssi, uint16_t epoch) {
    Peer *p = findPeer(mac);
    if (p == nullptr) {
        int slot = 0;
        unsigned long oldest = UINT32_MAX;
        for (int i = 0; i < 8; i++) {
            if (peers[i].heardMs == 0) {
                slot = i;
                break;
            }
            if (peers[i].heardMs < oldest) {
                oldest = peers[i].heardMs;
                slot = i;
            }
        }
        p = &peers[slot];
        memset(p, 0, sizeof(*p));
        memcpy(p->mac, mac, 6);
    }
    p->hops = hops;
    p->rssi = rssi;
    p->epoch = epoch;
    p->heardMs = millis();
    return p;
}

int childCount() {
    int n = 0;
    unsigned long ttl = (unsigned long) loraRouteExpiry * (unsigned long) loraHeartbeatSeconds * 1000UL;
    if (ttl < 1000) {
        ttl = 30000;
    }
    for (int i = 0; i < 8; i++) {
        if (peers[i].child && millis() - peers[i].heardMs < ttl) {
            n++;
        }
    }
    return n;
}

const char *roleName() {
    if (loraLinkIsGateway()) {
        return "gateway";
    }
    if (childCount() > 0) {
        return "relay";
    }
    return "leaf";
}

void noteAir(unsigned long ms) {
    if (millis() - airWindowStart > (unsigned long) loraHeartbeatSeconds * 1000UL) {
        airWindowStart = millis();
        airUsedMs = 0;
    }
    airUsedMs += ms;
}

unsigned long estAir(size_t bytes) {
    return 40UL + (unsigned long) bytes;
}

bool sendRaw(const uint8_t *frame, size_t len) {
    bool ok = false;
    for (int attempt = 0; attempt <= loraTxRetries; attempt++) {
        if (attempt > 0) {
            statsRetry++;
            delay(10 + (int) (esp_random() % 20));
        }
        if (loraRadioSend(frame, len)) {
            ok = true;
            break;
        }
        statsFail++;
        logRadio("TX fail/retry");
    }
    if (ok) {
        statsTx++;
        lastTxMs = millis();
        noteAir(estAir(len));
    }
    return ok;
}

bool sendFrame(uint8_t type, const uint8_t *dst, uint8_t hop, const uint8_t *plain, uint8_t plen, bool encrypt) {
    uint8_t frame[255];
    LoraHdr hdr;
    memset(&hdr, 0, sizeof(hdr));
    hdr.magic = MAGIC;
    hdr.type = type;
    hdr.hop_htg = (uint8_t) (((hop & 0x0F) << 4) | (hopsToGw > 15 ? 15 : hopsToGw));
    hdr.site = (uint16_t) siteId;
    memcpy(hdr.src, selfMac, 6);
    memcpy(hdr.dst, dst, 6);
    hdr.pkt = ++pktSeq;
    lastSentPkt = hdr.pkt;
    hdr.plen = plen;
    uint8_t cipher[231];
    size_t clen = sizeof(cipher);
    if (encrypt) {
        uint32_t ctr = 0;
        if (!loraKeysEncrypt(selfMac, plain, plen, cipher, &clen, &ctr)) {
            logRadio("encrypt fail");
            return false;
        }
        hdr.ctr = ctr;
        hdr.plen = (uint8_t) clen;
        memcpy(frame, &hdr, sizeof(hdr));
        memcpy(frame + sizeof(hdr), cipher, clen);
        return sendRaw(frame, sizeof(hdr) + clen);
    }
    hdr.ctr = 0;
    memcpy(frame, &hdr, sizeof(hdr));
    if (plen) {
        memcpy(frame + sizeof(hdr), plain, plen);
    }
    return sendRaw(frame, sizeof(hdr) + plen);
}

bool decryptFrame(const LoraHdr &hdr, const uint8_t *body, uint8_t *plain, uint8_t &plen) {
    size_t out = 215;
    if (!loraKeysDecrypt(hdr.src, hdr.ctr, body, hdr.plen, plain, &out)) {
        return false;
    }
    plen = (uint8_t) out;
    return true;
}

bool ours(const uint8_t *dst) {
    return macEq(dst, selfMac) || macBcast(dst);
}

void selectParent() {
    unsigned long ttl = (unsigned long) loraRouteExpiry * (unsigned long) loraHeartbeatSeconds * 1000UL;
    if (ttl < 1000) {
        ttl = 30000;
    }
    int bestHops = 255;
    int bestRssi = -999;
    int best = -1;
    for (int i = 0; i < 8; i++) {
        if (peers[i].heardMs == 0 || millis() - peers[i].heardMs > ttl) {
            continue;
        }
        if (macEq(peers[i].mac, selfMac)) {
            continue;
        }
        if (peers[i].hops >= loraHopLimit) {
            continue;
        }
        if (peers[i].hops >= hopsToGw && haveParent) {
            continue;
        }
        if (peers[i].hops < bestHops || (peers[i].hops == bestHops && peers[i].rssi > bestRssi)) {
            bestHops = peers[i].hops;
            bestRssi = peers[i].rssi;
            best = i;
        }
    }
    if (best < 0) {
        if (haveParent) {
            logRadio("parent loss");
        }
        haveParent = false;
        hopsToGw = loraLinkIsGateway() ? 0 : 255;
        return;
    }
    bool changed = !haveParent || !macEq(parentMac, peers[best].mac);
    memcpy(parentMac, peers[best].mac, 6);
    haveParent = true;
    hopsToGw = (uint8_t) (peers[best].hops + 1);
    parentRssi = peers[best].rssi;
    gwEpoch = peers[best].epoch;
    lastHeardParentMs = peers[best].heardMs;
    if (changed) {
        logRadioNum("parent select hops ", hopsToGw);
    }
}

void sendBeacon() {
    uint8_t p[24];
    memset(p, 0, sizeof(p));
    p[0] = (uint8_t) (siteId & 0xFF);
    p[1] = (uint8_t) ((siteId >> 8) & 0xFF);
    p[2] = hopsToGw > 250 ? 0 : hopsToGw;
    p[3] = (uint8_t) (gwEpoch & 0xFF);
    p[4] = (uint8_t) ((gwEpoch >> 8) & 0xFF);
    String nm = friendlyName.length() ? friendlyName : hardwareId;
    uint8_t nlen = nm.length() > 16 ? 16 : (uint8_t) nm.length();
    p[5] = nlen;
    memcpy(p + 6, nm.c_str(), nlen);
    sendFrame(T_BEACON, bcast, 0, p, (uint8_t) (6 + nlen), false);
}

void sendProbe() {
    uint8_t p[2] = {0, 0};
    sendFrame(T_PROBE, bcast, 0, p, 2, false);
    logRadio("probe");
}

void answerProbe(const uint8_t *src, int rssi) {
    if (rssi < EZG_LORA_WEAK_RSSI) {
        logRadio("probe suppress");
        return;
    }
    if (hopsToGw >= loraHopLimit && !loraLinkIsGateway()) {
        logRadio("probe suppress");
        return;
    }
    uint8_t p[4];
    p[0] = loraLinkIsGateway() ? 0 : hopsToGw;
    p[1] = (uint8_t) (gwEpoch & 0xFF);
    p[2] = (uint8_t) ((gwEpoch >> 8) & 0xFF);
    p[3] = (uint8_t) siteId;
    if (sendFrame(T_PROBE_ACK, src, 0, p, 4, loraKeysHaveSite())) {
        probeAnswers++;
        logRadio("probe answer");
    }
}

void rememberHeard(int sid, const char *name, const uint8_t *gw) {
    if (sid <= 0) {
        return;
    }
    for (int i = 0; i < 6; i++) {
        if (heard[i].site == sid) {
            heard[i].heardMs = millis();
            memcpy(heard[i].gw, gw, 6);
            if (name && name[0]) {
                strncpy(heard[i].name, name, 16);
                heard[i].name[16] = 0;
            }
            return;
        }
    }
    for (int i = 0; i < 6; i++) {
        if (heard[i].site == 0) {
            heard[i].site = sid;
            heard[i].heardMs = millis();
            memcpy(heard[i].gw, gw, 6);
            strncpy(heard[i].name, name ? name : "", 16);
            heard[i].name[16] = 0;
            return;
        }
    }
}

void handleBeacon(const LoraHdr &hdr, const uint8_t *p, uint8_t plen, int rssi) {
    if (plen < 6) {
        return;
    }
    int sid = p[0] | (p[1] << 8);
    uint8_t hops = p[2];
    uint16_t epoch = p[3] | (p[4] << 8);
    char name[17];
    memset(name, 0, sizeof(name));
    uint8_t nlen = p[5] > 16 ? 16 : p[5];
    if (plen >= 6 + nlen) {
        memcpy(name, p + 6, nlen);
    }
    rememberHeard(sid, name, hdr.src);
    if (hops == 0) {
        memcpy(gwMac, hdr.src, 6);
    }
    if (siteId > 0 && sid != siteId) {
        return;
    }
    Peer *peer = touchPeer(hdr.src, hops, rssi, epoch);
    strncpy(peer->name, name, 16);
    peer->name[16] = 0;
    peer->site = sid;
    lastRadioOkMs = millis();
    if (!ezgWifiConnected() || loraOnly) {
        selectParent();
    }
}

void handleProbeAck(const LoraHdr &hdr, const uint8_t *plain, uint8_t plen, int rssi) {
    if (plen < 4) {
        return;
    }
    uint8_t hops = plain[0];
    uint16_t epoch = plain[1] | (plain[2] << 8);
    touchPeer(hdr.src, hops, rssi, epoch);
    lastRadioOkMs = millis();
    if (!ezgWifiConnected() || loraOnly) {
        selectParent();
    }
}

bool enqueueJob(uint8_t prio, uint8_t kind, const uint8_t src[6], const String &body, int otaOff, int otaLen) {
    if (httpQ == nullptr) {
        return false;
    }
    HttpJob job;
    memset(&job, 0, sizeof(job));
    job.prio = prio;
    job.kind = kind;
    memcpy(job.src, src, 6);
    job.otaOff = otaOff;
    job.otaLen = otaLen;
    job.body = (char *) malloc(body.length() + 1);
    if (job.body == nullptr) {
        return false;
    }
    memcpy(job.body, body.c_str(), body.length() + 1);
    if (xQueueSend(httpQ, &job, 0) == pdTRUE) {
        return true;
    }
    HttpJob worst;
    if (xQueueReceive(httpQ, &worst, 0) == pdTRUE) {
        HttpJob drop = worst.prio >= job.prio ? worst : job;
        HttpJob keep = worst.prio >= job.prio ? job : worst;
        if (drop.prio == P_OPEN) {
            dropOpen++;
        } else if (drop.prio == P_HB) {
            dropHb++;
        } else if (drop.prio == P_CONFIG) {
            dropCfg++;
        } else {
            dropOta++;
        }
        logRadioNum("queue drop prio ", drop.prio);
        free(drop.body);
        if (xQueueSend(httpQ, &keep, 0) != pdTRUE) {
            free(keep.body);
            return false;
        }
        return drop.prio != job.prio || drop.kind != job.kind || memcmp(drop.src, job.src, 6) != 0;
    }
    free(job.body);
    dropHb++;
    return false;
}

struct DownJob {
    uint8_t dst[6];
    uint8_t kind;
    uint8_t plen;
    uint8_t payload[180];
};

bool enqueueDown(const uint8_t *dst, uint8_t kind, const uint8_t *payload, uint8_t plen) {
    if (downQ == nullptr) {
        return false;
    }
    DownJob job;
    memset(&job, 0, sizeof(job));
    memcpy(job.dst, dst, 6);
    job.kind = kind;
    job.plen = plen > 180 ? 180 : plen;
    if (payload != nullptr && job.plen) {
        memcpy(job.payload, payload, job.plen);
    }
    return xQueueSend(downQ, &job, 0) == pdTRUE;
}

void sendDown(const uint8_t *dst, uint8_t kind, const uint8_t *payload, uint8_t plen) {
    enqueueDown(dst, kind, payload, plen);
}

bool chunkTxBusy() {
    portENTER_CRITICAL(&chunkMux);
    bool busy = chunkTx.active;
    portEXIT_CRITICAL(&chunkMux);
    return busy;
}

void drainDown() {
    if (downQ == nullptr) {
        return;
    }
    if (millis() - lastTxMs < 150UL) {
        return;
    }
    if (chunkTxBusy()) {
        DownJob peek;
        if (xQueuePeek(downQ, &peek, 0) != pdTRUE || peek.kind != D_OPEN) {
            portENTER_CRITICAL(&chunkMux);
            bool waiting = chunkTx.waitAck;
            portEXIT_CRITICAL(&chunkMux);
            if (waiting) {
                return;
            }
        }
    }
    DownJob job;
    if (xQueueReceive(downQ, &job, 0) != pdTRUE) {
        return;
    }
    uint8_t buf[181];
    buf[0] = job.kind;
    if (job.plen) {
        memcpy(buf + 1, job.payload, job.plen);
    }
    sendFrame(T_DOWN, job.dst, 0, buf, (uint8_t) (1 + job.plen), true);
}

void chunkPendClear() {
    if (chunkPend.ram != nullptr) {
        free(chunkPend.ram);
        chunkPend.ram = nullptr;
    }
    memset(&chunkPend, 0, sizeof(chunkPend));
}

void chunkTxClearLocked() {
    if (chunkTx.ram != nullptr) {
        free(chunkTx.ram);
        chunkTx.ram = nullptr;
    }
    memset(&chunkTx, 0, sizeof(chunkTx));
}

void chunkTxBegin(const uint8_t *dst, uint8_t kind, const uint8_t *data, uint32_t len);

void chunkTxKickPend() {
    if (chunkPend.ram == nullptr || chunkPend.len == 0) {
        return;
    }
    uint8_t dst[6];
    uint8_t kind = chunkPend.kind;
    uint32_t len = chunkPend.len;
    uint8_t *ram = chunkPend.ram;
    memcpy(dst, chunkPend.dst, 6);
    memset(&chunkPend, 0, sizeof(chunkPend));
    chunkTxBegin(dst, kind, ram, len);
    free(ram);
}

void chunkPendStore(const uint8_t *dst, uint8_t kind, const uint8_t *data, uint32_t len) {
    chunkPendClear();
    chunkPend.ram = (uint8_t *) malloc(len);
    if (chunkPend.ram == nullptr) {
        Serial.println("EZG chunk pend fail");
        return;
    }
    memcpy(chunkPend.ram, data, len);
    memcpy(chunkPend.dst, dst, 6);
    chunkPend.kind = kind;
    chunkPend.len = len;
}

void chunkTxBegin(const uint8_t *dst, uint8_t kind, const uint8_t *data, uint32_t len) {
    if (dst == nullptr || data == nullptr || len == 0) {
        return;
    }
    if (kind != LORA_CHUNK_KIND_FIRMWARE && len > LORA_CHUNK_MAX_RAM) {
        Serial.print("EZG chunk too big ");
        Serial.println((int) len);
        return;
    }
    uint32_t crc = loraChunkCrc32(data, len);
    portENTER_CRITICAL(&chunkMux);
    if (chunkTx.active && chunkTx.kind == kind && chunkTx.crc == crc && chunkTx.total == len) {
        memcpy(chunkTx.dst, dst, 6);
        portEXIT_CRITICAL(&chunkMux);
        return;
    }
    if (chunkTx.active && kind == LORA_CHUNK_KIND_UPLINK && chunkTx.kind != LORA_CHUNK_KIND_UPLINK) {
        portEXIT_CRITICAL(&chunkMux);
        chunkPendStore(dst, kind, data, len);
        return;
    }
    chunkTxClearLocked();
    chunkTx.ram = (uint8_t *) malloc(len);
    if (chunkTx.ram == nullptr) {
        portEXIT_CRITICAL(&chunkMux);
        Serial.println("EZG chunk alloc fail");
        return;
    }
    memcpy(chunkTx.ram, data, len);
    memcpy(chunkTx.dst, dst, 6);
    chunkTx.kind = kind;
    chunkTx.total = len;
    chunkTx.offset = 0;
    chunkTx.crc = crc;
    chunkTx.waitAck = false;
    chunkTx.waitLen = 0;
    chunkTx.waitPkt = 0;
    chunkTx.startedMs = millis();
    chunkTx.active = true;
    portEXIT_CRITICAL(&chunkMux);
    Serial.print("EZG chunk start ");
    Serial.print(chunkKindName(kind));
    Serial.print(" ");
    Serial.println((int) len);
}

void drainChunks() {
    if (millis() - lastTxMs < 150UL) {
        return;
    }
    uint8_t pkt[215];
    uint8_t pktLen = 0;
    uint8_t dst[6];
    LoraChunkHdr hdr;
    memset(&hdr, 0, sizeof(hdr));
    memset(dst, 0, 6);
    bool timedOut = false;

    portENTER_CRITICAL(&chunkMux);
    if (chunkTx.active && chunkTx.startedMs > 0 && millis() - chunkTx.startedMs > 20000UL) {
        chunkTxClearLocked();
        portEXIT_CRITICAL(&chunkMux);
        Serial.println("EZG chunk abort");
        chunkTxKickPend();
        return;
    }
    if (chunkTx.active && chunkTx.waitAck) {
        if (millis() - chunkTx.waitMs < 400UL) {
            portEXIT_CRITICAL(&chunkMux);
            return;
        }
        chunkTx.waitAck = false;
        timedOut = true;
    }
    if (chunkTx.active && chunkTx.ram != nullptr && chunkTx.offset < chunkTx.total && !chunkTx.waitAck) {
        uint16_t cap = loraChunkMaxData((uint16_t) otaLoraChunk);
        uint32_t left = chunkTx.total - chunkTx.offset;
        uint16_t n = left > cap ? cap : (uint16_t) left;
        hdr.kind = chunkTx.kind;
        hdr.flags = (chunkTx.offset + n >= chunkTx.total) ? LORA_CHUNK_FLAG_LAST : 0;
        hdr.total = chunkTx.total;
        hdr.offset = chunkTx.offset;
        hdr.len = n;
        hdr.crc = chunkTx.crc;
        if (loraChunkPack(pkt, pktLen, hdr, chunkTx.ram + chunkTx.offset)) {
            memcpy(dst, chunkTx.dst, 6);
            chunkTx.waitLen = n;
        } else {
            pktLen = 0;
        }
    }
    portEXIT_CRITICAL(&chunkMux);

    if (pktLen > 0) {
        if (sendFrame(T_CHUNK, dst, 0, pkt, pktLen, true)) {
            portENTER_CRITICAL(&chunkMux);
            chunkTx.waitAck = true;
            chunkTx.waitPkt = lastSentPkt;
            chunkTx.waitMs = millis();
            portEXIT_CRITICAL(&chunkMux);
            Serial.print(timedOut ? "EZG chunk retry " : "EZG chunk offset ");
            Serial.println((int) hdr.offset);
        } else {
            Serial.println("EZG chunk send fail");
        }
        return;
    }

    if (chunkTx.active) {
        return;
    }
    if (!otaHold.ready || !otaHold.active) {
        return;
    }
    if (airUsedMs >= (unsigned long) loraOtaAirtimeMs) {
        return;
    }
    uint16_t cap = loraChunkMaxData((uint16_t) otaLoraChunk);
    uint16_t n = otaHold.len > cap ? cap : otaHold.len;
    if (n == 0) {
        return;
    }
    hdr.kind = LORA_CHUNK_KIND_FIRMWARE;
    hdr.flags = (otaHold.offset + n >= otaHold.total) ? LORA_CHUNK_FLAG_LAST : 0;
    hdr.total = otaHold.total;
    hdr.offset = otaHold.offset;
    hdr.len = n;
    hdr.crc = 0;
    if (!loraChunkPack(pkt, pktLen, hdr, otaHold.data)) {
        return;
    }
    if (!sendFrame(T_CHUNK, otaHold.dst, 0, pkt, pktLen, true)) {
        return;
    }
    Serial.print("EZG chunk fw offset ");
    Serial.println((int) otaHold.offset);
    uint32_t next = otaHold.offset + n;
    otaHold.ready = false;
    if (next >= otaHold.total) {
        otaHold.active = false;
        otaActive = otaActive > 0 ? otaActive - 1 : 0;
    } else {
        enqueueJob(P_OTA, JOB_OTA, otaHold.dst, String(otaHold.url), (int) next, cap);
    }
}

void onChunkAck(uint16_t pkt) {
    portENTER_CRITICAL(&chunkMux);
    if (!chunkTx.active || !chunkTx.waitAck || chunkTx.waitPkt != pkt) {
        portEXIT_CRITICAL(&chunkMux);
        return;
    }
    chunkTx.offset += chunkTx.waitLen;
    chunkTx.waitAck = false;
    chunkTx.waitLen = 0;
    bool done = chunkTx.offset >= chunkTx.total;
    if (done) {
        chunkTxClearLocked();
    }
    portEXIT_CRITICAL(&chunkMux);
    if (done) {
        Serial.println("EZG chunk done");
        chunkTxKickPend();
    }
}

bool handleChunk(const uint8_t *plain, uint8_t plen, const uint8_t *src) {
    LoraChunkHdr hdr;
    const uint8_t *data = nullptr;
    if (!loraChunkUnpack(plain, plen, hdr, data)) {
        Serial.println("EZG chunk parse fail");
        return false;
    }
    if (hdr.kind == LORA_CHUNK_KIND_FIRMWARE) {
        if (!otaSession) {
            return false;
        }
        if ((int) hdr.offset != otaOffset) {
            if ((int) hdr.offset < otaOffset) {
                return true;
            }
            Serial.print("EZG chunk fw skip ");
            Serial.println((int) hdr.offset);
            return false;
        }
        ezgWriteOtaBytes(data, (int) hdr.len);
        lastRxKind = hdr.kind;
        lastRxGot = (uint32_t) otaOffset;
        lastRxTotal = hdr.total;
        Serial.print("EZG chunk fw offset ");
        Serial.println((int) hdr.offset);
        return true;
    }
    int st = loraChunkRxFeed(chunkRx, hdr, data);
    if (st < 0) {
        Serial.println("EZG chunk fail");
        return false;
    }
    if (st == 0) {
        return chunkRx.active && hdr.kind == chunkRx.kind && hdr.crc == chunkRx.crc
            && (uint32_t) hdr.offset + hdr.len <= chunkRx.got;
    }
    lastRxKind = chunkRx.kind;
    lastRxGot = chunkRx.got;
    lastRxTotal = chunkRx.total;
    Serial.print("EZG chunk got ");
    Serial.print(chunkKindName(chunkRx.kind));
    Serial.print(" ");
    Serial.print((int) chunkRx.got);
    Serial.print("/");
    Serial.println((int) chunkRx.total);
    if (st == 2 && chunkRx.buf != nullptr) {
        if (chunkRx.kind == LORA_CHUNK_KIND_CONFIG) {
            ezgApplyConfigJson((const char *) chunkRx.buf, chunkRx.total);
            Serial.println("EZG config applied");
        } else if (chunkRx.kind == LORA_CHUNK_KIND_UPLINK) {
            if (loraLinkIsGateway()) {
                uint8_t origin[6];
                memcpy(origin, src, 6);
                JsonDocument extra;
                if (deserializeJson(extra, chunkRx.buf, chunkRx.total) == DeserializationError::Ok
                    && extra["hardware_id"].is<const char *>()) {
                    macFromId(String(extra["hardware_id"].as<const char *>()), origin);
                }
                enqueueJob(P_HB, JOB_HB, origin, String((const char *) chunkRx.buf), 0, 0);
                Serial.println("EZG uplink posted");
            } else if (haveParent) {
                chunkTxBegin(parentMac, LORA_CHUNK_KIND_UPLINK, chunkRx.buf, chunkRx.total);
                Serial.println("EZG uplink hop");
            }
        }
        lastRxGot = chunkRx.total;
        loraChunkRxReset(chunkRx);
    }
    return true;
}

void sendAck(const uint8_t *dst, uint16_t pkt) {
    uint8_t p[2];
    p[0] = (uint8_t) (pkt & 0xFF);
    p[1] = (uint8_t) (pkt >> 8);
    sendFrame(T_ACK, dst, 0, p, 2, true);
}

void applyDown(const uint8_t *plain, uint8_t plen) {
    if (plen < 1) {
        return;
    }
    uint8_t kind = plain[0];
    const uint8_t *p = plain + 1;
    uint8_t n = (uint8_t) (plen - 1);
    if (kind == D_OPEN && n >= 4) {
        uint32_t cmd = p[0] | (p[1] << 8) | (p[2] << 16) | (p[3] << 24);
        logRadioNum("open command_id received ", (int) cmd);
        if (ezgHandleOpenCommand(cmd)) {
            logRadio("open dup");
        } else {
            logRadio("open executed");
        }
    } else if (kind == D_SCAN) {
        scanWanted = true;
    } else if (kind == D_REBOOT) {
        ezgConfirmFirmware();
        delay(200);
        ezgRebootBoard();
    } else if (kind == D_CONFIG && n > 2) {
        char js[181];
        uint8_t copy = n > 180 ? 180 : n;
        memcpy(js, p, copy);
        js[copy] = 0;
        JsonDocument cfg;
        if (deserializeJson(cfg, js) == DeserializationError::Ok) {
            ezgApplyConfigObject(cfg.as<JsonObject>());
            logRadio("config applied");
        } else {
            logRadio("config parse fail");
        }
    } else if (kind == D_FW && n > 8) {
        uint32_t size = p[0] | (p[1] << 8) | (p[2] << 16) | (p[3] << 24);
        uint16_t chunk = p[4] | (p[5] << 8);
        uint8_t vlen = p[6];
        if (vlen > 16) {
            vlen = 16;
        }
        if (n < 7 + vlen + 64) {
            return;
        }
        String ver = String((const char *) (p + 7)).substring(0, vlen);
        String sum;
        for (int i = 0; i < 64; i++) {
            sum += (char) p[7 + vlen + i];
        }
        ezgBeginOtaMeta(ver, sum, (int) size, chunk > 0 ? chunk : otaLoraChunk);
        logRadioNum("OTA meta size ", (int) size);
    } else if (kind == D_INTERVAL && n >= 2) {
        int iv = p[0] | (p[1] << 8);
        ezgSetHeartbeatSeconds(iv);
        if (n >= 4) {
            ezgSetWifiHeartbeatSeconds(p[2] | (p[3] << 8));
        }
        loraPortalOkFlag = true;
        lastLoraPortalOkMs = millis();
        lastPortalOkMs = millis();
        lastPortalAttemptOk = true;
        pendingScanAck = false;
        ezgFlashHeartbeatLed();
        logRadio("dest_ok");
    } else if (kind == D_SETUP_OK && n > 2) {
        JsonDocument cfg;
        if (deserializeJson(cfg, p, n) == DeserializationError::Ok) {
            if (cfg["site_id"].is<int>()) {
                siteId = cfg["site_id"].as<int>();
            }
            ezgApplyConfigObject(cfg.as<JsonObject>());
            loraPortalOkFlag = true;
            lastLoraPortalOkMs = millis();
            lastPortalOkMs = millis();
            lastPortalAttemptOk = true;
            setupPending = false;
            logRadio("setup dest_ok");
        }
    }
}

void handleOta(const uint8_t *plain, uint8_t plen) {
    if (plen < 10) {
        return;
    }
    uint32_t off = plain[0] | (plain[1] << 8) | (plain[2] << 16) | (plain[3] << 24);
    uint16_t len = plain[4] | (plain[5] << 8);
    uint32_t total = plain[6] | (plain[7] << 8) | (plain[8] << 16) | (plain[9] << 24);
    if (len == 0 || plen < 10 + len) {
        return;
    }
    (void) total;
    if ((int) off != otaOffset && otaSession) {
        logRadioNum("OTA chunk offset ", (int) off);
    }
    if (!otaSession) {
        return;
    }
    if ((int) off != otaOffset) {
        return;
    }
    ezgWriteOtaBytes(plain + 10, len);
    logRadioNum("OTA chunk offset ", (int) off);
}

uint32_t unixFromVersionString(const char *text) {
    int y = 0, mo = 0, d = 0, h = 0, mi = 0, s = 0;
    if (text == nullptr || text[0] == 0) {
        return 0;
    }
    if (sscanf(text, "%d-%d-%d %d:%d:%d", &y, &mo, &d, &h, &mi, &s) != 6
        && sscanf(text, "%d-%d-%dT%d:%d:%d", &y, &mo, &d, &h, &mi, &s) != 6) {
        return 0;
    }
    if (y < 1970 || mo < 1 || mo > 12 || d < 1 || d > 31) {
        return 0;
    }
    static const int mdays[] = {0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334};
    int days = (y - 1970) * 365 + (y - 1969) / 4;
    days += mdays[mo - 1] + d - 1;
    if (mo > 2 && (y % 4 == 0) && (y % 100 != 0 || y % 400 == 0)) {
        days++;
    }
    return (uint32_t) days * 86400UL + (uint32_t) h * 3600UL + (uint32_t) mi * 60UL + (uint32_t) s;
}

uint32_t configVersionUnix() {
    return unixFromVersionString(configVersion.c_str());
}

void packOwnHb(uint8_t *p, uint8_t &plen) {
    memset(p, 0, 64);
    uint8_t flags = 0;
    if (ezgWifiConnected()) {
        flags |= 0x01;
    }
    if (lastInternetOk) {
        flags |= 0x02;
    }
    if (otaSession) {
        flags |= 0x04;
    }
    if (pendingScanAck) {
        flags |= 0x08;
    }
    if (loraOnly) {
        flags |= 0x40;
    }
    int gate = ezgReadGateState();
    if (gate >= 0) {
        flags |= 0x10;
        if (gate) {
            flags |= 0x20;
        }
    }
    p[0] = flags;
    p[1] = (uint8_t) (WiFi.RSSI() & 0xFF);
    uint32_t up = (uint32_t) ezgUptimeSeconds();
    memcpy(p + 2, &up, 4);
    int maj = 1, mino = 0, pat = 0;
    sscanf(EZG_FIRMWARE_VERSION, "%d.%d.%d", &maj, &mino, &pat);
    p[6] = (uint8_t) maj;
    p[7] = (uint8_t) mino;
    p[8] = (uint8_t) pat;
    uint32_t oo = (uint32_t) otaOffset;
    memcpy(p + 9, &oo, 4);
    p[13] = loraLinkIsGateway() ? 0 : (childCount() > 0 ? 1 : 2);
    p[14] = hopsToGw > 15 ? 15 : hopsToGw;
    p[15] = (uint8_t) childCount();
    memcpy(p + 16, haveParent ? parentMac : selfMac, 6);
    memcpy(p + 22, loraLinkIsGateway() ? selfMac : gwMac, 6);
    p[28] = (uint8_t) parentRssi;
    memcpy(p + 29, &statsTx, 2);
    memcpy(p + 31, &statsRx, 2);
    memcpy(p + 33, &statsRetry, 2);
    memcpy(p + 35, &statsFail, 2);
    p[37] = dropOpen > 255 ? 255 : (uint8_t) dropOpen;
    p[38] = dropHb > 255 ? 255 : (uint8_t) dropHb;
    p[39] = dropCfg > 255 ? 255 : (uint8_t) dropCfg;
    p[40] = dropOta > 255 ? 255 : (uint8_t) dropOta;
    uint16_t done = pendingCommandId > 0 ? (uint16_t) pendingCommandId : 0;
    memcpy(p + 41, &done, 2);
    memcpy(p + 43, &gwEpoch, 2);
    uint16_t iv = (uint16_t) loraHeartbeatSeconds;
    memcpy(p + 45, &iv, 2);
    uint32_t cv = configVersionUnix();
    memcpy(p + 47, &cv, 4);
    uint8_t ck = 0;
    uint32_t cgot = 0, ctot = 0;
    if (chunkRx.active) {
        ck = chunkRx.kind;
        cgot = chunkRx.got;
        ctot = chunkRx.total;
    } else if (lastRxTotal > 0 && lastRxGot < lastRxTotal) {
        ck = lastRxKind;
        cgot = lastRxGot;
        ctot = lastRxTotal;
    }
    p[51] = ck;
    memcpy(p + 52, &cgot, 4);
    memcpy(p + 56, &ctot, 4);
    plen = 60;
}

String jsonFromCompact(const uint8_t src[6], const uint8_t *p, uint8_t plen) {
    JsonDocument doc;
    String hid = macHex(src);
    doc["hardware_id"] = hid;
    uint8_t flags = plen > 0 ? p[0] : 0;
    doc["status"] = "lora";
    if (flags & 0x10) {
        doc["gate_state"] = (flags & 0x20) ? "closed" : "open";
    }
    if (plen >= 9) {
        char ver[16];
        snprintf(ver, sizeof(ver), "%u.%u.%u", p[6], p[7], p[8]);
        doc["firmware_version"] = ver;
    } else {
        doc["firmware_version"] = EZG_FIRMWARE_VERSION;
    }
    if (plen >= 13) {
        uint32_t up = 0, oo = 0;
        memcpy(&up, p + 2, 4);
        memcpy(&oo, p + 9, 4);
        doc["uptime_seconds"] = up;
        if (flags & 0x04) {
            doc["ota_offset"] = oo;
        }
    }
    if (flags & 0x08) {
        doc["scanned"] = true;
    }
    uint16_t done = 0;
    if (plen >= 43) {
        memcpy(&done, p + 41, 2);
        if (done) {
            doc["completed_command_id"] = done;
            doc["received_command_id"] = done;
        }
    }
    JsonObject conn = doc["connectivity"].to<JsonObject>();
    JsonObject cur = conn["current"].to<JsonObject>();
    cur["type"] = "lora";
    if (plen >= 28) {
        cur["parent"] = macHex(p + 16);
        cur["gateway"] = macHex(p + 22);
        cur["hops_to_gateway"] = p[14];
        cur["parent_rssi"] = (int8_t) p[28];
    }
    JsonObject st = doc["radio_stats"].to<JsonObject>();
    const char *roles[] = {"gateway", "relay", "leaf"};
    uint8_t role = plen > 13 ? p[13] : 2;
    st["role"] = roles[role > 2 ? 2 : role];
    if (plen >= 28) {
        st["parent"] = macHex(p + 16);
        st["gateway"] = macHex(p + 22);
        st["hops_to_gateway"] = p[14];
        st["parent_rssi"] = (int8_t) p[28];
        st["child_count"] = p[15];
    }
    if (plen >= 41) {
        uint16_t tx = 0, rx = 0, rt = 0, fl = 0;
        memcpy(&tx, p + 29, 2);
        memcpy(&rx, p + 31, 2);
        memcpy(&rt, p + 33, 2);
        memcpy(&fl, p + 35, 2);
        st["tx"] = tx;
        st["rx"] = rx;
        st["retry"] = rt;
        st["fail"] = fl;
        st["queue_drop_open"] = p[37];
        st["queue_drop_hb"] = p[38];
        st["queue_drop_config"] = p[39];
        st["queue_drop_ota"] = p[40];
    }
    st["radio_ok_age_ms"] = 0;
    st["portal_ok_age_ms"] = 0;
    if (plen >= 47) {
        uint16_t iv = 0;
        memcpy(&iv, p + 45, 2);
        st["heartbeat_interval"] = iv;
    }
    if (plen >= 51) {
        uint32_t cv = 0;
        memcpy(&cv, p + 47, 4);
        if (cv > 0) {
            time_t t = (time_t) cv;
            struct tm tm;
            if (gmtime_r(&t, &tm) != nullptr) {
                char buf[24];
                snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d",
                    tm.tm_year + 1900, tm.tm_mon + 1, tm.tm_mday,
                    tm.tm_hour, tm.tm_min, tm.tm_sec);
                doc["config_version"] = buf;
            }
        }
    }
    if (plen >= 60) {
        uint32_t got = 0, tot = 0;
        memcpy(&got, p + 52, 4);
        memcpy(&tot, p + 56, 4);
        if (p[51] > 0 || tot > 0) {
            st["chunk_kind"] = chunkKindName(p[51]);
            st["chunk_got"] = got;
            st["chunk_total"] = tot;
        }
    }
    doc["relayed_by"] = hardwareId;
    String out;
    serializeJson(doc, out);
    return out;
}

void handleUp(const LoraHdr &hdr, const uint8_t *plain, uint8_t plen, uint8_t hop) {
    Peer *p = findPeer(hdr.src);
    if (p != nullptr) {
        p->child = true;
        p->heardMs = millis();
    } else {
        touchPeer(hdr.src, (uint8_t) (hopsToGw + 1), parentRssi, gwEpoch)->child = true;
    }
    lastRadioOkMs = millis();
    sendAck(hdr.src, hdr.pkt);
    if (loraLinkIsGateway()) {
        String json = jsonFromCompact(hdr.src, plain, plen);
        uint8_t prio = P_HB;
        if (plen >= 43) {
            uint16_t done = 0;
            memcpy(&done, plain + 41, 2);
            if (done) {
                prio = P_OPEN;
            }
        }
        enqueueJob(prio, JOB_HB, hdr.src, json, 0, 0);
        return;
    }
    if (haveParent && hop < loraHopLimit) {
        sendFrame(T_UP, parentMac, (uint8_t) (hop + 1), plain, plen, true);
        logRadio("relay up");
    }
}

void handleSetup(const LoraHdr &hdr, const uint8_t *plain, uint8_t plen) {
    if (!loraLinkIsGateway() || plen < 3) {
        if (haveParent) {
            sendFrame(T_SETUP, parentMac, 1, plain, plen, false);
        }
        return;
    }
    JsonDocument doc;
    if (deserializeJson(doc, plain, plen) != DeserializationError::Ok) {
        return;
    }
    String body;
    serializeJson(doc, body);
    enqueueJob(P_CONFIG, JOB_SETUP, hdr.src, body, 0, 0);
}

void handleFwdDown(const LoraHdr &hdr, const uint8_t *plain, uint8_t plen, uint8_t hop) {
    if (macEq(hdr.dst, selfMac)) {
        applyDown(plain, plen);
        sendAck(hdr.src, hdr.pkt);
        return;
    }
    if (hop >= loraHopLimit) {
        return;
    }
    Peer *child = findPeer(hdr.dst);
    if (child != nullptr && child->child) {
        sendFrame(T_DOWN, hdr.dst, (uint8_t) (hop + 1), plain, plen, true);
        logRadio("relay down");
    }
}

void onRx(const uint8_t *frame, int len, int rssi) {
    if (len < (int) sizeof(LoraHdr)) {
        return;
    }
    LoraHdr hdr;
    memcpy(&hdr, frame, sizeof(hdr));
    if (hdr.magic != MAGIC) {
        return;
    }
    statsRx++;
    lastRadioOkMs = millis();
    if (!ours(hdr.dst) && hdr.type != T_BEACON && hdr.type != T_PROBE) {
        if (hdr.type == T_DOWN || hdr.type == T_OTA || hdr.type == T_UP || hdr.type == T_CHUNK) {
            // fall through for relay if we are parent path
        } else {
            return;
        }
    }
    if (isDup(hdr.pkt)) {
        return;
    }
    const uint8_t *body = frame + sizeof(LoraHdr);
    int bodyLen = len - (int) sizeof(LoraHdr);
    uint8_t hop = (uint8_t) ((hdr.hop_htg >> 4) & 0x0F);
    uint8_t htg = (uint8_t) (hdr.hop_htg & 0x0F);
    uint8_t plain[215];
    uint8_t plen = 0;
    bool enc = hdr.type == T_UP || hdr.type == T_DOWN || hdr.type == T_ACK || hdr.type == T_OTA
        || hdr.type == T_PROBE_ACK || hdr.type == T_CHUNK;
    if (enc && hdr.plen > 16 && loraKeysHaveSite()) {
        if (bodyLen < hdr.plen || !decryptFrame(hdr, body, plain, plen)) {
            if (hdr.type == T_PROBE_ACK && bodyLen >= 4) {
                memcpy(plain, body, bodyLen < 200 ? bodyLen : 200);
                plen = (uint8_t) (bodyLen < 200 ? bodyLen : 200);
            } else {
                return;
            }
        }
    } else {
        plen = hdr.plen < bodyLen ? hdr.plen : (uint8_t) bodyLen;
        memcpy(plain, body, plen);
    }
    if (hdr.type == T_BEACON) {
        handleBeacon(hdr, plain, plen, rssi);
    } else if (hdr.type == T_PROBE) {
        if (siteId > 0 && (loraLinkIsGateway() || haveParent)) {
            answerProbe(hdr.src, rssi);
        }
        (void) htg;
    } else if (hdr.type == T_PROBE_ACK) {
        handleProbeAck(hdr, plain, plen, rssi);
    } else if (hdr.type == T_UP) {
        handleUp(hdr, plain, plen, hop);
    } else if (hdr.type == T_DOWN) {
        handleFwdDown(hdr, plain, plen, hop);
    } else if (hdr.type == T_OTA) {
        if (macEq(hdr.dst, selfMac)) {
            handleOta(plain, plen);
        } else if (findPeer(hdr.dst) && hop < loraHopLimit) {
            sendFrame(T_OTA, hdr.dst, (uint8_t) (hop + 1), plain, plen, true);
        }
    } else if (hdr.type == T_CHUNK) {
        if (macEq(hdr.dst, selfMac)) {
            if (handleChunk(plain, plen, hdr.src)) {
                sendAck(hdr.src, hdr.pkt);
            }
        } else if (findPeer(hdr.dst) && hop < loraHopLimit) {
            sendFrame(T_CHUNK, hdr.dst, (uint8_t) (hop + 1), plain, plen, true);
            logRadio("relay chunk");
        }
    } else if (hdr.type == T_SETUP) {
        handleSetup(hdr, plain, plen);
    } else if (hdr.type == T_ACK) {
        lastRadioOkMs = millis();
        if (plen >= 2) {
            uint16_t acked = (uint16_t) (plain[0] | (plain[1] << 8));
            onChunkAck(acked);
        }
    }
}

void processHbResponse(const uint8_t *src, JsonObjectConst data) {
    JsonObjectConst cfg = data["config"].as<JsonObjectConst>();
    if (!cfg.isNull()) {
        String js;
        serializeJson(cfg, js);
        Serial.print("EZG config json ");
        Serial.println((int) js.length());
        if (js.length() > 2) {
            chunkTxBegin(src, LORA_CHUNK_KIND_CONFIG, (const uint8_t *) js.c_str(), (uint32_t) js.length());
        }
    } else if (!data["config"].isNull()) {
        Serial.println("EZG config not object");
    }
    if (data["open"] | false) {
        uint32_t cmd = data["command_id"] | 0;
        uint8_t p[4] = {
            (uint8_t) cmd, (uint8_t) (cmd >> 8), (uint8_t) (cmd >> 16), (uint8_t) (cmd >> 24)
        };
        sendDown(src, D_OPEN, p, 4);
        logRadioNum("open queued ", (int) cmd);
    }
    if (data["scan"] | false) {
        sendDown(src, D_SCAN, nullptr, 0);
    }
    if (data["reboot"] | false) {
        sendDown(src, D_REBOOT, nullptr, 0);
    }
    int loraIv = data["lora_heartbeat_interval_seconds"] | 0;
    if (loraIv < 2) {
        loraIv = data["heartbeat_interval_seconds"] | loraHeartbeatSeconds;
    }
    if (loraIv < 2) {
        loraIv = loraHeartbeatSeconds;
    }
    int wifiIv = data["wifi_heartbeat_interval_seconds"] | wifiHeartbeatSeconds;
    if (wifiIv < 2) {
        wifiIv = wifiHeartbeatSeconds;
    }
    uint8_t ivb[4] = {
        (uint8_t) loraIv, (uint8_t) (loraIv >> 8),
        (uint8_t) wifiIv, (uint8_t) (wifiIv >> 8)
    };
    sendDown(src, D_INTERVAL, ivb, 4);
    JsonObjectConst fw = data["firmware"].as<JsonObjectConst>();
    if (!fw.isNull() && otaActive < loraOtaMax) {
        otaHold.active = true;
        memcpy(otaHold.dst, src, 6);
        otaHold.total = fw["size"] | 0;
        otaHold.offset = 0;
        String ver = fw["version"].is<const char *>() ? String(fw["version"].as<const char *>()) : "";
        String sum = fw["checksum"].is<const char *>() ? String(fw["checksum"].as<const char *>()) : "";
        String url = fw["url"].is<const char *>() ? String(fw["url"].as<const char *>()) : "";
        strncpy(otaHold.version, ver.c_str(), 16);
        otaHold.version[16] = 0;
        strncpy(otaHold.checksum, sum.c_str(), 64);
        otaHold.checksum[64] = 0;
        strncpy(otaHold.url, url.c_str(), 159);
        otaHold.url[159] = 0;
        otaActive++;
        uint8_t meta[7 + 16 + 64];
        memset(meta, 0, sizeof(meta));
        uint32_t sz = otaHold.total;
        memcpy(meta, &sz, 4);
        uint16_t chunk = loraChunkMaxData((uint16_t) otaLoraChunk);
        memcpy(meta + 4, &chunk, 2);
        meta[6] = (uint8_t) strlen(otaHold.version);
        memcpy(meta + 7, otaHold.version, meta[6]);
        memcpy(meta + 7 + meta[6], otaHold.checksum, 64);
        sendDown(src, D_FW, meta, (uint8_t) (7 + meta[6] + 64));
        enqueueJob(P_OTA, JOB_OTA, src, String(otaHold.url), 0, otaLoraChunk);
    }
}

void httpWorker(void *) {
    for (;;) {
        HttpJob job;
        if (xQueueReceive(httpQ, &job, portMAX_DELAY) != pdTRUE) {
            continue;
        }
        if (job.kind == JOB_OTA) {
            if (job.body != nullptr) {
                String url = job.body;
                url += url.indexOf('?') >= 0 ? '&' : '?';
                url += "offset=";
                url += String(job.otaOff);
                url += "&length=";
                url += String(job.otaLen > 0 ? job.otaLen : otaLoraChunk);
                int got = 0;
                int want = job.otaLen > 0 ? job.otaLen : otaLoraChunk;
                uint16_t cap = loraChunkMaxData((uint16_t) otaLoraChunk);
                if (want > (int) cap) {
                    want = cap;
                }
                Serial.print("EZG http OTA ");
                Serial.println(job.otaOff);
                if (ezgHttpGetBytes(url, otaHold.data, want, got) && got > 0) {
                    otaHold.len = (uint16_t) got;
                    otaHold.offset = (uint32_t) job.otaOff;
                    otaHold.ready = true;
                }
            }
            free(job.body);
            continue;
        }
        JsonDocument resp;
        String url = job.kind == JOB_SETUP ? ezgPortalUrl("/api/setup/complete") : ezgPortalUrl("/api/heartbeat");
        Serial.print("EZG http POST ");
        Serial.println(job.kind == JOB_SETUP ? "setup" : "heartbeat");
        bool ok = job.body != nullptr && ezgHttpPostJson(url, String(job.body), resp);
        if (ok && (resp["success"] | false)) {
            if (job.kind == JOB_SETUP) {
                String js;
                JsonDocument cfg;
                cfg["site_id"] = resp["data"]["board_id"] | 0;
                if (resp["data"]["lora_site_key_wrapped"].is<const char *>()) {
                    cfg["lora_site_key_wrapped"] = resp["data"]["lora_site_key_wrapped"];
                }
                if (resp["data"]["lora_key_id"].is<int>()) {
                    cfg["lora_key_id"] = resp["data"]["lora_key_id"];
                }
                if (resp["data"]["config_version"].is<const char *>()) {
                    cfg["version"] = resp["data"]["config_version"];
                }
                serializeJson(cfg, js);
                sendDown(job.src, D_SETUP_OK, (const uint8_t *) js.c_str(), (uint8_t) js.length());
            } else if (resp["data"].is<JsonObject>()) {
                processHbResponse(job.src, resp["data"].as<JsonObject>());
            }
        } else {
            Serial.println("EZG http fail");
        }
        free(job.body);
    }
}

}  // namespace

void loraLinkBegin() {
    macFromId(hardwareId, selfMac);
    memset(peers, 0, sizeof(peers));
    memset(heard, 0, sizeof(heard));
    memset(&otaHold, 0, sizeof(otaHold));
    loraChunkRxReset(chunkRx);
    chunkTxClearLocked();
    chunkPendClear();
    hopsToGw = 255;
    float mhz = loraFreqHz > 0 ? (loraFreqHz / 1000000.0f) : 915.0f;
    radioUp = loraRadioBegin(mhz);
    if (!radioUp) {
        return;
    }
    httpQ = xQueueCreate(6, sizeof(HttpJob));
    downQ = xQueueCreate(8, sizeof(DownJob));
    if (httpQ != nullptr) {
        xTaskCreatePinnedToCore(httpWorker, "ezghttp", 8192, nullptr, 1, nullptr, 0);
    }
    logRadio("radio up");
}

void loraLinkApplyConfig(JsonObjectConst cfg) {
    if (cfg["lora_frequency_hz"].is<int>()) {
        loraFreqHz = cfg["lora_frequency_hz"].as<int>();
    }
    if (cfg["isolation_misses"].is<int>()) {
        isolationMisses = cfg["isolation_misses"].as<int>();
    }
    if (cfg["lora_hop_limit"].is<int>()) {
        loraHopLimit = cfg["lora_hop_limit"].as<int>();
    }
    if (cfg["lora_tx_retries"].is<int>()) {
        loraTxRetries = cfg["lora_tx_retries"].as<int>();
    }
    if (cfg["lora_discovery_delay_min_ms"].is<int>()) {
        loraDiscMinMs = cfg["lora_discovery_delay_min_ms"].as<int>();
    }
    if (cfg["lora_discovery_delay_max_ms"].is<int>()) {
        loraDiscMaxMs = cfg["lora_discovery_delay_max_ms"].as<int>();
    }
    if (cfg["lora_stagger_jitter_ms"].is<int>()) {
        loraStaggerMs = cfg["lora_stagger_jitter_ms"].as<int>();
    }
    if (cfg["lora_route_expiry_intervals"].is<int>()) {
        loraRouteExpiry = cfg["lora_route_expiry_intervals"].as<int>();
    }
    if (cfg["ota_max_per_gateway"].is<int>()) {
        loraOtaMax = cfg["ota_max_per_gateway"].as<int>();
    }
    if (cfg["ota_airtime_budget_ms"].is<int>()) {
        loraOtaAirtimeMs = cfg["ota_airtime_budget_ms"].as<int>();
    }
    if (cfg["lora_log_radio"].is<bool>()) {
        loraLogRadio = cfg["lora_log_radio"].as<bool>();
    }
    if (cfg["lora_only"].is<bool>()) {
        loraOnly = cfg["lora_only"].as<bool>();
    }
    if (cfg["lora_key_id"].is<int>()) {
        loraKeysSetId((uint16_t) cfg["lora_key_id"].as<int>());
    }
    if (cfg["lora_site_key_wrapped"].is<const char *>()) {
        loraKeysUnwrapSite(String(cfg["lora_site_key_wrapped"].as<const char *>()));
    }
}

void loraLinkFillRadioStats(JsonObject dest) {
    dest["role"] = roleName();
    dest["parent"] = haveParent ? macHex(parentMac) : "";
    dest["gateway"] = loraLinkIsGateway() ? hardwareId : (haveParent ? macHex(gwMac) : "");
    dest["hops_to_gateway"] = hopsToGw > 15 ? -1 : hopsToGw;
    dest["gateway_epoch"] = gwEpoch;
    dest["parent_rssi"] = parentRssi;
    dest["child_count"] = childCount();
    dest["tx"] = statsTx;
    dest["rx"] = statsRx;
    dest["retry"] = statsRetry;
    dest["fail"] = statsFail;
    dest["dup_suppress"] = dupSuppress;
    dest["probe_answers"] = probeAnswers;
    dest["queue_drop_open"] = dropOpen;
    dest["queue_drop_hb"] = dropHb;
    dest["queue_drop_config"] = dropCfg;
    dest["queue_drop_ota"] = dropOta;
    dest["radio_ok_age_ms"] = lastRadioOkMs ? (millis() - lastRadioOkMs) : -1;
    dest["portal_ok_age_ms"] = lastLoraPortalOkMs ? (millis() - lastLoraPortalOkMs) : -1;
    dest["heartbeat_interval"] = loraHeartbeatSeconds;
    if (chunkTx.active) {
        dest["chunk_kind"] = chunkKindName(chunkTx.kind);
        dest["chunk_got"] = chunkTx.offset;
        dest["chunk_total"] = chunkTx.total;
    } else if (chunkRx.active) {
        dest["chunk_kind"] = chunkKindName(chunkRx.kind);
        dest["chunk_got"] = chunkRx.got;
        dest["chunk_total"] = chunkRx.total;
    } else if (lastRxTotal > 0 && lastRxGot < lastRxTotal) {
        dest["chunk_kind"] = chunkKindName(lastRxKind);
        dest["chunk_got"] = lastRxGot;
        dest["chunk_total"] = lastRxTotal;
    }
}

void loraLinkFillConnectivity(JsonObject current) {
    current["type"] = "lora";
    if (haveParent) {
        current["parent"] = macHex(parentMac);
    }
    current["gateway"] = loraLinkIsGateway() ? hardwareId : (haveParent ? macHex(parentMac) : "");
    current["hops_to_gateway"] = hopsToGw > 15 ? -1 : (int) hopsToGw;
    current["parent_rssi"] = parentRssi;
}

bool loraLinkHasParent() {
    return haveParent;
}

bool loraLinkHasGatewayPeer() {
    unsigned long ttl = (unsigned long) loraRouteExpiry * (unsigned long) loraHeartbeatSeconds * 1000UL;
    if (ttl < 1000UL) {
        ttl = 30000UL;
    }
    for (int i = 0; i < 8; i++) {
        if (peers[i].heardMs == 0 || millis() - peers[i].heardMs > ttl) {
            continue;
        }
        if (macEq(peers[i].mac, selfMac)) {
            continue;
        }
        if (peers[i].hops == 0) {
            return true;
        }
    }
    return false;
}

bool loraLinkIsGateway() {
    return ezgWifiConnected() && wifiPortalConfirmed && !loraOnly && !wifiRoamActive;
}

bool loraLinkRadioOk() {
    return radioUp && lastRadioOkMs > 0 && (millis() - lastRadioOkMs) < 30000UL;
}

bool loraLinkPortalOk() {
    return loraPortalOkFlag && lastLoraPortalOkMs > 0
        && (millis() - lastLoraPortalOkMs) < (unsigned long) isolationMisses * (unsigned long) loraHeartbeatSeconds * 1000UL;
}

bool loraLinkShouldDropWifi() {
    if (!ezgWifiConnected()) {
        return false;
    }
    if (wifiRoamActive) {
        return true;
    }
    return loraOnly && loraLinkPortalOk();
}

void loraLinkNoteWifiPortal(bool ok) {
    wifiPortalConfirmed = ok;
    if (!ok) {
        return;
    }
    lastPortalAttemptOk = true;
    if (loraOnly || wifiRoamActive) {
        return;
    }
    haveParent = false;
    hopsToGw = 0;
    memcpy(gwMac, selfMac, 6);
    gwEpoch++;
}

bool loraLinkSendOwnHeartbeat() {
    if (!haveParent || !loraKeysHaveSite()) {
        return false;
    }
    uint8_t p[64];
    uint8_t plen = 0;
    packOwnHb(p, plen);
    delay((int) (esp_random() % (unsigned) (loraStaggerMs > 1 ? loraStaggerMs : 1)));
    bool ok = sendFrame(T_UP, parentMac, 0, p, plen, true);
    lastHbLoraMs = millis();
    if (ok) {
        logRadio("up radio_ok");
    }
    return ok;
}

bool loraLinkSendUplinkJson(const String &json) {
    if (!haveParent || !loraKeysHaveSite()) {
        return false;
    }
    if (json.length() < 3) {
        return false;
    }
    chunkTxBegin(parentMac, LORA_CHUNK_KIND_UPLINK, (const uint8_t *) json.c_str(), (uint32_t) json.length());
    return true;
}

int loraLinkHeardCount() {
    int n = 0;
    for (int i = 0; i < 6; i++) {
        if (heard[i].site > 0 && millis() - heard[i].heardMs < 60000UL) {
            n++;
        }
    }
    return n;
}

bool loraLinkHeardAt(int i, int &siteIdOut, String &nameOut) {
    int n = 0;
    for (int k = 0; k < 6; k++) {
        if (heard[k].site > 0 && millis() - heard[k].heardMs < 60000UL) {
            if (n == i) {
                siteIdOut = heard[k].site;
                nameOut = String(heard[k].name);
                return true;
            }
            n++;
        }
    }
    return false;
}

bool loraLinkSetupViaHeard(int wantSite, const String &friendly) {
    uint8_t dst[6];
    bool found = false;
    for (int i = 0; i < 6; i++) {
        if (heard[i].site == wantSite) {
            memcpy(dst, heard[i].gw, 6);
            found = true;
            break;
        }
    }
    if (!found) {
        return false;
    }
    JsonDocument doc;
    doc["hardware_id"] = hardwareId;
    doc["friendly_name"] = friendly;
    doc["site_id"] = wantSite;
    doc["wifi_ssid"] = "";
    doc["wifi_password"] = "";
    String body;
    serializeJson(doc, body);
    setupPending = true;
    setupSite = wantSite;
    setupName = friendly;
    return sendFrame(T_SETUP, dst, 0, (const uint8_t *) body.c_str(), (uint8_t) body.length(), false);
}

const char *loraLinkRoleName() {
    return roleName();
}

unsigned long loraLinkLastRadioOkMs() {
    return lastRadioOkMs;
}

int loraLinkHops() {
    return hopsToGw > 15 ? -1 : hopsToGw;
}

void loraLinkService() {
    if (!radioUp) {
        return;
    }
    uint8_t buf[255];
    int n = loraRadioReceive(buf, sizeof(buf));
    if (n > 0) {
        onRx(buf, n, loraRadioRssi());
    }
    drainDown();
    drainChunks();
    unsigned long ttl = (unsigned long) loraRouteExpiry * (unsigned long) loraHeartbeatSeconds * 1000UL;
    if (haveParent && ttl > 0 && lastHeardParentMs > 0 && millis() - lastHeardParentMs > ttl) {
        logRadio("parent loss");
        haveParent = false;
        hopsToGw = 255;
    }
    if (loraLinkIsGateway()) {
        haveParent = false;
        hopsToGw = 0;
        if (!chunkTxBusy() && millis() - lastTxMs >= 150UL && millis() - lastBeaconMs > 3000UL) {
            lastBeaconMs = millis();
            sendBeacon();
        }
        if (otaHold.active && !otaHold.ready && otaActive > 0 && otaHold.url[0] && otaHold.offset < otaHold.total) {
            // worker fetches; kick next chunk after TX
        }
        return;
    }
    if (siteId > 0 && !haveParent && millis() - lastProbeMs > 2000UL) {
        int span = loraDiscMaxMs - loraDiscMinMs;
        if (span < 1) {
            span = 1;
        }
        delay(loraDiscMinMs + (int) (esp_random() % (unsigned) span));
        lastProbeMs = millis();
        sendProbe();
    }
}

