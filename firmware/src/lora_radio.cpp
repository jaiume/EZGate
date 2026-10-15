#include "lora_radio.h"

#include "config.h"

#include <Arduino.h>
#include <RadioLib.h>

namespace {

SX1262 radio = new Module(EZG_LORA_CS_PIN, EZG_LORA_DIO1_PIN, EZG_LORA_RST_PIN, EZG_LORA_BUSY_PIN);
volatile bool rxFlag = false;
bool started = false;

void IRAM_ATTR onDio1() {
    rxFlag = true;
}

}  // namespace

bool loraRadioBegin(float mhz) {
    pinMode(EZG_LORA_VEXT_PIN, OUTPUT);
    digitalWrite(EZG_LORA_VEXT_PIN, LOW);
    delay(20);
    int16_t st = radio.begin(mhz, EZG_LORA_BW_KHZ, EZG_LORA_SF, EZG_LORA_CR, 0x12, EZG_LORA_POWER_DBM, 8, 1.8, false);
    if (st != RADIOLIB_ERR_NONE) {
        Serial.print("EZG lora radio begin fail ");
        Serial.println(st);
        return false;
    }
    radio.setCRC(true);
    radio.setDio2AsRfSwitch(true);
    radio.setDio1Action(onDio1);
    st = radio.startReceive();
    started = st == RADIOLIB_ERR_NONE;
    if (!started) {
        Serial.print("EZG lora rx fail ");
        Serial.println(st);
    }
    return started;
}

bool loraRadioChannelBusy() {
    if (!started) {
        return false;
    }
    radio.clearDio1Action();
    int16_t st = radio.scanChannel();
    rxFlag = false;
    radio.setDio1Action(onDio1);
    radio.startReceive();
    return st == RADIOLIB_LORA_DETECTED;
}

bool loraRadioSend(const uint8_t *data, size_t len) {
    if (!started || data == nullptr || len == 0 || len > 255) {
        return false;
    }
    radio.clearDio1Action();
    int16_t st = radio.transmit(const_cast<uint8_t *>(data), (size_t) len);
    radio.setDio1Action(onDio1);
    rxFlag = false;
    radio.startReceive();
    return st == RADIOLIB_ERR_NONE;
}

int loraRadioReceive(uint8_t *data, size_t maxLen) {
    if (!started || !rxFlag || data == nullptr || maxLen == 0) {
        return 0;
    }
    rxFlag = false;
    size_t n = radio.getPacketLength();
    if (n == 0 || n > maxLen) {
        n = maxLen;
    }
    int16_t st = radio.readData(data, n);
    radio.startReceive();
    if (st != RADIOLIB_ERR_NONE) {
        return 0;
    }
    return (int) n;
}

int loraRadioRssi() {
    return (int) radio.getRSSI();
}

void loraRadioStandby() {
    if (started) {
        radio.standby();
    }
}

void loraRadioListen() {
    if (started) {
        radio.startReceive();
    }
}
