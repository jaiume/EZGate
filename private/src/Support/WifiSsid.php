<?php

declare(strict_types=1);

namespace App\Support;

class WifiSsid
{
    public static function from(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $ssid = (string) $value;
        if (preg_match('/^(.*) \(-?\d+ dBm\)$/', $ssid, $matches) === 1) {
            return $matches[1];
        }

        return $ssid;
    }

    public static function orNull(mixed $value): ?string
    {
        $ssid = self::from($value);
        return $ssid === '' ? null : $ssid;
    }
}
