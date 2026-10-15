#pragma once

#include <stddef.h>
#include <stdint.h>

const uint8_t LORA_CHUNK_KIND_CONFIG = 1;
const uint8_t LORA_CHUNK_KIND_FIRMWARE = 2;
const uint8_t LORA_CHUNK_KIND_UPLINK = 3;
const uint8_t LORA_CHUNK_KIND_LIST = 4;
const uint8_t LORA_CHUNK_FLAG_LAST = 0x01;
const uint8_t LORA_CHUNK_HDR_BYTES = 16;
const uint16_t LORA_CHUNK_MAX_PLAIN = 215;
const uint16_t LORA_CHUNK_MAX_DATA = 199;
const uint32_t LORA_CHUNK_MAX_RAM = 4096;

struct __attribute__((packed)) LoraChunkHdr {
    uint8_t kind;
    uint8_t flags;
    uint32_t total;
    uint32_t offset;
    uint16_t len;
    uint32_t crc;
};

struct LoraChunkRx {
    bool active;
    uint8_t kind;
    uint32_t total;
    uint32_t got;
    uint32_t crc;
    uint8_t *buf;
};

uint32_t loraChunkCrc32(const uint8_t *data, size_t len);
uint16_t loraChunkMaxData(uint16_t want);
bool loraChunkPack(uint8_t *out, uint8_t &outLen, const LoraChunkHdr &hdr, const uint8_t *data);
bool loraChunkUnpack(const uint8_t *plain, uint8_t plen, LoraChunkHdr &hdr, const uint8_t *&data);
void loraChunkRxReset(LoraChunkRx &rx);
int loraChunkRxFeed(LoraChunkRx &rx, const LoraChunkHdr &hdr, const uint8_t *data);
