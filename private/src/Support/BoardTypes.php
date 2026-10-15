<?php

declare(strict_types=1);

namespace App\Support;

class BoardTypes
{
    public const HELTEC = 'Heltec Wireless Stick Lite V3';
    public const A8 = 'Kincony KC868-A8';

    public static function fromChip(string $chip): ?string
    {
        $chip = trim($chip);
        if ($chip === '') {
            return null;
        }
        if (stripos($chip, 'ESP32-S3') !== false) {
            return self::HELTEC;
        }
        if (strcasecmp($chip, 'ESP32') === 0) {
            return self::A8;
        }

        return null;
    }
}
