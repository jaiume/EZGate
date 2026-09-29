#include "lora_chunk.h"

#include <cstdlib>
#include <cstring>

static_assert(sizeof(LoraChunkHdr) == LORA_CHUNK_HDR_BYTES, "LoraChunkHdr must be 16 bytes");

uint32_t loraChunkCrc32(const uint8_t *data, size_t len) {
    uint32_t crc = 0xFFFFFFFFu;
    for (size_t i = 0; i < len; i++) {
        crc ^= data[i];
        for (int b = 0; b < 8; b++) {
            uint32_t mask = (uint32_t) -(int) (crc & 1u);
            crc = (crc >> 1) ^ (0xEDB88320u & mask);
        }
    }
    return ~crc;
}

uint16_t loraChunkMaxData(uint16_t want) {
    if (want < 32) {
        want = 192;
    }
    if (want > LORA_CHUNK_MAX_DATA) {
        want = LORA_CHUNK_MAX_DATA;
    }
    return want;
}

bool loraChunkPack(uint8_t *out, uint8_t &outLen, const LoraChunkHdr &hdr, const uint8_t *data) {
    if (out == nullptr || hdr.len > LORA_CHUNK_MAX_DATA || (hdr.len > 0 && data == nullptr)) {
        return false;
    }
    uint16_t need = (uint16_t) (LORA_CHUNK_HDR_BYTES + hdr.len);
    if (need > LORA_CHUNK_MAX_PLAIN) {
        return false;
    }
    memcpy(out, &hdr, LORA_CHUNK_HDR_BYTES);
    if (hdr.len) {
        memcpy(out + LORA_CHUNK_HDR_BYTES, data, hdr.len);
    }
    outLen = (uint8_t) need;
    return true;
}

bool loraChunkUnpack(const uint8_t *plain, uint8_t plen, LoraChunkHdr &hdr, const uint8_t *&data) {
    if (plain == nullptr || plen < LORA_CHUNK_HDR_BYTES) {
        return false;
    }
    memcpy(&hdr, plain, LORA_CHUNK_HDR_BYTES);
    if (hdr.len == 0 || hdr.len > LORA_CHUNK_MAX_DATA) {
        return false;
    }
    if (plen < LORA_CHUNK_HDR_BYTES + hdr.len) {
        return false;
    }
    if (hdr.offset > hdr.total || hdr.total == 0) {
        return false;
    }
    if ((uint32_t) hdr.offset + hdr.len > hdr.total) {
        return false;
    }
    data = plain + LORA_CHUNK_HDR_BYTES;
    return true;
}

void loraChunkRxReset(LoraChunkRx &rx) {
    if (rx.buf != nullptr) {
        free(rx.buf);
    }
    memset(&rx, 0, sizeof(rx));
}

int loraChunkRxFeed(LoraChunkRx &rx, const LoraChunkHdr &hdr, const uint8_t *data) {
    if (data == nullptr || hdr.len == 0) {
        return -1;
    }
    bool ram = hdr.kind != LORA_CHUNK_KIND_FIRMWARE;
    if (ram && hdr.total > LORA_CHUNK_MAX_RAM) {
        loraChunkRxReset(rx);
        return -1;
    }
    if (!rx.active || hdr.offset == 0) {
        loraChunkRxReset(rx);
        if (ram) {
            rx.buf = (uint8_t *) malloc((size_t) hdr.total + 1);
            if (rx.buf == nullptr) {
                return -1;
            }
            memset(rx.buf, 0, (size_t) hdr.total + 1);
        }
        rx.active = true;
        rx.kind = hdr.kind;
        rx.total = hdr.total;
        rx.crc = hdr.crc;
        rx.got = 0;
    }
    if (hdr.kind != rx.kind || hdr.total != rx.total || hdr.crc != rx.crc) {
        return 0;
    }
    if (hdr.offset != rx.got) {
        return 0;
    }
    if (ram && rx.buf != nullptr) {
        memcpy(rx.buf + hdr.offset, data, hdr.len);
    }
    rx.got += hdr.len;
    if (rx.got < rx.total) {
        return 1;
    }
    if (ram) {
        if (rx.buf == nullptr || loraChunkCrc32(rx.buf, rx.total) != rx.crc) {
            loraChunkRxReset(rx);
            return -1;
        }
        rx.buf[rx.total] = 0;
    }
    return 2;
}
