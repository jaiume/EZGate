<?php

declare(strict_types=1);

namespace App\Support;

/**
 * On-wire LoRa blob chunker. Firmware packs the same 16-byte header
 * (kind, flags, total, offset, len, crc32) then payload. One mechanism
 * carries config JSON, firmware images, and uplink JSON (scan results).
 */
class LoraChunk
{
    public const KIND_CONFIG = 1;
    public const KIND_FIRMWARE = 2;
    public const KIND_UPLINK = 3;
    public const FLAG_LAST = 0x01;
    public const HEADER_BYTES = 16;
    public const MAX_PLAIN = 215;
    public const MAX_DATA = 199;
    public const MAX_RAM = 4096;

    public static function maxData(int $want): int
    {
        if ($want < 32) {
            $want = 192;
        }

        return max(32, min(self::MAX_DATA, $want));
    }

    public static function crc32(string $data): int
    {
        $raw = hash('crc32b', $data, true);
        $parts = unpack('N', $raw);

        return (int) ($parts[1] ?? 0);
    }

    /**
     * @return list<array{kind:int,flags:int,total:int,offset:int,len:int,crc:int,data:string,frame:string}>
     */
    public static function split(string $blob, int $kind, int $chunkBytes = 192): array
    {
        $size = strlen($blob);
        if ($size < 1 || ($kind !== self::KIND_FIRMWARE && $size > self::MAX_RAM)) {
            return [];
        }
        $chunkBytes = self::maxData($chunkBytes);
        $crc = self::crc32($blob);
        $out = [];
        $offset = 0;
        while ($offset < $size) {
            $len = min($chunkBytes, $size - $offset);
            $data = substr($blob, $offset, $len);
            $last = ($offset + $len) >= $size;
            $flags = $last ? self::FLAG_LAST : 0;
            $header = pack('C2V2vV', $kind, $flags, $size, $offset, $len, $crc);
            $out[] = [
                'kind' => $kind,
                'flags' => $flags,
                'total' => $size,
                'offset' => $offset,
                'len' => $len,
                'crc' => $crc,
                'data' => $data,
                'frame' => $header . $data,
            ];
            $offset += $len;
        }

        return $out;
    }

    /**
     * @param list<string> $frames
     */
    public static function join(array $frames): ?string
    {
        if ($frames === []) {
            return null;
        }
        $parts = [];
        $total = null;
        $crc = null;
        $kind = null;
        foreach ($frames as $frame) {
            $parsed = self::parse($frame);
            if ($parsed === null) {
                return null;
            }
            if ($kind === null) {
                $kind = $parsed['kind'];
                $total = $parsed['total'];
                $crc = $parsed['crc'];
            }
            if ($parsed['kind'] !== $kind || $parsed['total'] !== $total || $parsed['crc'] !== $crc) {
                return null;
            }
            $parts[$parsed['offset']] = $parsed['data'];
        }
        ksort($parts, SORT_NUMERIC);
        $blob = implode('', $parts);
        if ($total === null || strlen($blob) !== $total) {
            return null;
        }
        if (self::crc32($blob) !== $crc) {
            return null;
        }

        return $blob;
    }

    /**
     * @return array{kind:int,flags:int,total:int,offset:int,len:int,crc:int,data:string}|null
     */
    public static function parse(string $frame): ?array
    {
        if (strlen($frame) < self::HEADER_BYTES) {
            return null;
        }
        $h = unpack('Ckind/Cflags/Vtotal/Voffset/vlen/Vcrc', substr($frame, 0, self::HEADER_BYTES));
        if (!is_array($h)) {
            return null;
        }
        $len = (int) $h['len'];
        if ($len < 1 || strlen($frame) < self::HEADER_BYTES + $len) {
            return null;
        }
        if ($len > self::MAX_DATA) {
            return null;
        }

        return [
            'kind' => (int) $h['kind'],
            'flags' => (int) $h['flags'],
            'total' => (int) $h['total'],
            'offset' => (int) $h['offset'],
            'len' => $len,
            'crc' => (int) $h['crc'],
            'data' => substr($frame, self::HEADER_BYTES, $len),
        ];
    }
}
