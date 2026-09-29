<?php

declare(strict_types=1);

namespace App\Support;

class HardwareId
{
    public static function normalize(string $raw): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $raw) ?? '');
        if (strlen($hex) !== 12 || !ctype_xdigit($hex)) {
            return null;
        }

        return $hex;
    }

    /**
     * Identifier the firmware reports (Arduino ESP.getEfuseMac printed as 12 hex chars).
     * esptool/Web Serial MAC bytes are the reverse of that value on ESP32-S3.
     */
    public static function fromUsbMac(string $raw): ?string
    {
        $mac = self::normalize($raw);
        if ($mac === null) {
            return null;
        }

        return self::reverseBytes($mac);
    }

    /**
     * @return list<string>
     */
    public static function candidates(string $raw): array
    {
        $mac = self::normalize($raw);
        if ($mac === null) {
            return [];
        }

        $reversed = self::reverseBytes($mac);
        if ($reversed === $mac) {
            return [$mac];
        }

        return [$reversed, $mac];
    }

    public static function reverseBytes(string $hex12): string
    {
        return implode('', array_reverse(str_split($hex12, 2)));
    }
}
