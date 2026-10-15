#pragma once

#include <stddef.h>
#include <stdint.h>

bool loraRadioBegin(float mhz);
bool loraRadioSend(const uint8_t *data, size_t len);
bool loraRadioChannelBusy();
int loraRadioReceive(uint8_t *data, size_t maxLen);
int loraRadioRssi();
void loraRadioStandby();
void loraRadioListen();
