#include "lora_keys.h"

#include "config.h"
#include <Preferences.h>
#include <mbedtls/gcm.h>

#include <cstring>

extern String hardwareId;
extern bool ezgApplyUsbConfigJson(const String &json);
extern bool ezgClearUsbConfig();

namespace {

Preferences sec;
uint8_t boardKey[16];
uint8_t siteKey[16];
bool haveBoard = false;
bool haveSite = false;
uint16_t keyId = 1;
uint32_t counter = 0;
String usbLine;

int hexVal(char c) {
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

bool parseHex32(const String &hex, uint8_t out[16]) {
    String t = hex;
    t.trim();
    t.toUpperCase();
    if (t.length() != 32) {
        return false;
    }
    for (int i = 0; i < 16; i++) {
        int hi = hexVal(t[i * 2]);
        int lo = hexVal(t[i * 2 + 1]);
        if (hi < 0 || lo < 0) {
            return false;
        }
        out[i] = (uint8_t) ((hi << 4) | lo);
    }
    return true;
}

bool parseHexBytes(const String &hex, uint8_t *out, size_t want) {
    String t = hex;
    t.trim();
    t.toUpperCase();
    if (t.length() != (int) (want * 2)) {
        return false;
    }
    for (size_t i = 0; i < want; i++) {
        int hi = hexVal(t[(int) (i * 2)]);
        int lo = hexVal(t[(int) (i * 2 + 1)]);
        if (hi < 0 || lo < 0) {
            return false;
        }
        out[i] = (uint8_t) ((hi << 4) | lo);
    }
    return true;
}

void persistCounter() {
    sec.begin("ezgsec", false);
    sec.putUInt("ctr", counter);
    sec.end();
}

void persistBoard() {
    sec.begin("ezgsec", false);
    sec.putBytes("bkey", boardKey, 16);
    sec.putBool("hb", true);
    sec.end();
}

void persistSite() {
    sec.begin("ezgsec", false);
    sec.putBytes("skey", siteKey, 16);
    sec.putUShort("kid", keyId);
    sec.putBool("hs", true);
    sec.end();
}

void fillNonce(uint8_t nonce[12], const uint8_t *srcMac, uint32_t ctr) {
    memcpy(nonce, srcMac, 6);
    nonce[6] = (uint8_t) (ctr >> 24);
    nonce[7] = (uint8_t) (ctr >> 16);
    nonce[8] = (uint8_t) (ctr >> 8);
    nonce[9] = (uint8_t) ctr;
    nonce[10] = (uint8_t) (keyId >> 8);
    nonce[11] = (uint8_t) keyId;
}

bool gcmCrypt(bool encrypt, const uint8_t key[16], const uint8_t nonce[12], const uint8_t *in, size_t inLen, uint8_t *out, uint8_t tag[16]) {
    mbedtls_gcm_context ctx;
    mbedtls_gcm_init(&ctx);
    int rc = mbedtls_gcm_setkey(&ctx, MBEDTLS_CIPHER_ID_AES, key, 128);
    if (rc != 0) {
        mbedtls_gcm_free(&ctx);
        return false;
    }
    if (encrypt) {
        rc = mbedtls_gcm_crypt_and_tag(&ctx, MBEDTLS_GCM_ENCRYPT, inLen, nonce, 12, nullptr, 0, in, out, 16, tag);
    } else {
        rc = mbedtls_gcm_auth_decrypt(&ctx, inLen, nonce, 12, nullptr, 0, tag, 16, in, out);
    }
    mbedtls_gcm_free(&ctx);
    return rc == 0;
}

void handleUsbLine(const String &line) {
    String t = line;
    t.trim();
    if (!t.length() || !t.startsWith("EZG")) {
        return;
    }
    if (t == "EZGUSB" || t.startsWith("EZGUSB ")) {
        ezgNoteUsbCommand();
        ezgUsbPrint(String("EZGUSB ok ") + EZG_FIRMWARE_VERSION + " " + hardwareId + " " + EZG_BOARD_TYPE);
        return;
    }
    if (t == "EZGCLR" || t.startsWith("EZGCLR ")) {
        ezgNoteUsbCommand();
        ezgUsbPrint("EZGCLR ok");
        ezgClearUsbConfig();
        return;
    }
    if (t.startsWith("EZGCFG")) {
        ezgNoteUsbCommand();
        t = t.substring(6);
        t.trim();
        if (ezgApplyUsbConfigJson(t)) {
            ezgUsbPrint("EZGCFG ok");
        } else {
            ezgUsbPrint("EZGCFG fail");
        }
        return;
    }
    if (!t.startsWith("EZGKEY")) {
        return;
    }
    t = t.substring(6);
    t.trim();
    if (!t.length()) {
        return;
    }
    ezgNoteUsbCommand();
    if (!loraKeysSetBoardHex(t)) {
        ezgUsbPrint("EZGKEY fail");
        return;
    }
    ezgUsbPrint("EZGKEY ok");
}

}  // namespace

bool loraKeysBegin() {
    if (!sec.begin("ezgsec", false)) {
        usbLine = "";
        return true;
    }
    haveBoard = sec.getBool("hb", false) && sec.getBytes("bkey", boardKey, 16) == 16;
    haveSite = sec.getBool("hs", false) && sec.getBytes("skey", siteKey, 16) == 16;
    keyId = sec.getUShort("kid", 1);
    counter = sec.getUInt("ctr", 0);
    sec.end();
    counter += 256;
    persistCounter();
    usbLine = "";
    return true;
}

void loraKeysServiceUsb() {
    while (Serial.available() > 0) {
        char c = (char) Serial.read();
        if (c == '\r') {
            continue;
        }
        if (c == '\n') {
            handleUsbLine(usbLine);
            usbLine = "";
            continue;
        }
        if (usbLine.length() < 3072) {
            usbLine += c;
        }
    }
}

bool loraKeysHaveBoard() {
    return haveBoard;
}

bool loraKeysHaveSite() {
    return haveSite;
}

uint16_t loraKeysId() {
    return keyId;
}

void loraKeysSetId(uint16_t id) {
    if (id == 0) {
        return;
    }
    keyId = id;
    persistSite();
}

bool loraKeysSetBoardHex(const String &hex) {
    if (!parseHex32(hex, boardKey)) {
        return false;
    }
    haveBoard = true;
    persistBoard();
    return true;
}

bool loraKeysSetSiteHex(const String &hex) {
    if (!parseHex32(hex, siteKey)) {
        return false;
    }
    haveSite = true;
    persistSite();
    return true;
}

bool loraKeysUnwrapSite(const String &wrappedHex) {
    if (!haveBoard) {
        return false;
    }
    uint8_t blob[44];
    if (!parseHexBytes(wrappedHex, blob, 44)) {
        return false;
    }
    uint8_t nonce[12];
    memcpy(nonce, blob, 12);
    uint8_t tag[16];
    memcpy(tag, blob + 28, 16);
    uint8_t plain[16];
    if (!gcmCrypt(false, boardKey, nonce, blob + 12, 16, plain, tag)) {
        return false;
    }
    memcpy(siteKey, plain, 16);
    haveSite = true;
    persistSite();
    return true;
}

bool loraKeysEncrypt(const uint8_t *srcMac, const uint8_t *plain, size_t plainLen, uint8_t *out, size_t *outLen, uint32_t *counterOut) {
    if (!haveSite || srcMac == nullptr || plain == nullptr || out == nullptr || outLen == nullptr) {
        return false;
    }
    if (plainLen + 16 > *outLen) {
        return false;
    }
    uint8_t nonce[12];
    uint32_t ctr = ++counter;
    if ((ctr & 0x1F) == 0) {
        persistCounter();
    }
    fillNonce(nonce, srcMac, ctr);
    uint8_t tag[16];
    if (!gcmCrypt(true, siteKey, nonce, plain, plainLen, out, tag)) {
        return false;
    }
    memcpy(out + plainLen, tag, 16);
    *outLen = plainLen + 16;
    if (counterOut != nullptr) {
        *counterOut = ctr;
    }
    return true;
}

bool loraKeysDecrypt(const uint8_t *srcMac, uint32_t counterIn, const uint8_t *in, size_t inLen, uint8_t *plain, size_t *plainLen) {
    if (!haveSite || srcMac == nullptr || in == nullptr || plain == nullptr || plainLen == nullptr) {
        return false;
    }
    if (inLen < 16) {
        return false;
    }
    size_t body = inLen - 16;
    if (body > *plainLen) {
        return false;
    }
    uint8_t tag[16];
    memcpy(tag, in + body, 16);
    uint8_t nonce[12];
    fillNonce(nonce, srcMac, counterIn);
    if (!gcmCrypt(false, siteKey, nonce, in, body, plain, tag)) {
        return false;
    }
    *plainLen = body;
    return true;
}
