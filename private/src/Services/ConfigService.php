<?php

declare(strict_types=1);

namespace App\Services;

class ConfigService
{
    private static ?array $config = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$config === null) {
            $path = BASE_DIR . '/config/config.ini';
            if (!is_file($path)) {
                self::$config = [];
            } else {
                $parsed = parse_ini_file($path, true, INI_SCANNER_TYPED);
                self::$config = $parsed !== false ? $parsed : [];
            }
        }

        if (str_contains($key, '.')) {
            [$section, $name] = explode('.', $key, 2);
            return self::$config[$section][$name] ?? $default;
        }

        foreach (self::$config as $section) {
            if (is_array($section) && array_key_exists($key, $section)) {
                return $section[$key];
            }
        }

        return $default;
    }

    public static function reset(): void
    {
        self::$config = null;
    }
}
