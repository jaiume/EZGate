#pragma once

#include <Arduino.h>
#include <stddef.h>
#include <stdint.h>

void ezgNoteUsbCommand();
void ezgUsbPrint(const String &line);
bool ezgUsbHostAttached();
bool loraKeysBegin();
void loraKeysServiceUsb();
bool loraKeysHaveBoard();
bool loraKeysHaveSite();
uint16_t loraKeysId();
void loraKeysSetId(uint16_t id);
bool loraKeysSetBoardHex(const String &hex);
bool loraKeysSetSiteHex(const String &hex);
bool loraKeysUnwrapSite(const String &wrappedHex);
bool loraKeysEncrypt(const uint8_t *srcMac, const uint8_t *plain, size_t plainLen, uint8_t *out, size_t *outLen, uint32_t *counterOut);
bool loraKeysDecrypt(const uint8_t *srcMac, uint32_t counterIn, const uint8_t *in, size_t inLen, uint8_t *plain, size_t *plainLen);
