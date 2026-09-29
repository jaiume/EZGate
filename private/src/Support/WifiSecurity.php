<?php

declare(strict_types=1);

namespace App\Support;

class WifiSecurity
{
    public const OPEN = 'open';
    public const WPA2 = 'wpa2';
    public const WPA3 = 'wpa3';

    public static function normalize(mixed $value): ?string
    {
        $raw = strtolower(trim((string) $value));
        return match ($raw) {
            'open', 'none', '0', 'wifi_auth_open' => self::OPEN,
            'wpa2', 'wpa', 'wpa2-psk', 'wpa-psk', 'wpa/wpa2', '2', '3', '4',
            'wifi_auth_wpa_psk', 'wifi_auth_wpa2_psk', 'wifi_auth_wpa_wpa2_psk' => self::WPA2,
            'wpa3', 'wpa3-sae', 'wpa2/wpa3', '6', '7',
            'wifi_auth_wpa3_psk', 'wifi_auth_wpa2_wpa3_psk' => self::WPA3,
            default => null,
        };
    }

    public static function label(string $security): string
    {
        return match ($security) {
            self::OPEN => 'Open',
            self::WPA2 => 'WPA2',
            self::WPA3 => 'WPA3',
            default => '—',
        };
    }

    public static function prefer(?string $current, ?string $incoming): ?string
    {
        $rank = static fn (?string $value): int => match ($value) {
            self::WPA3 => 3,
            self::WPA2 => 2,
            self::OPEN => 1,
            default => 0,
        };
        return $rank($incoming) > $rank($current) ? $incoming : $current;
    }

    public static function passwordFor(string $security, string $password): ?string
    {
        if ($security !== self::WPA2 && $security !== self::WPA3) {
            return null;
        }
        $password = trim($password);
        return $password === '' ? null : $password;
    }
}
