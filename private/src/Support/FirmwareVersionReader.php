<?php

declare(strict_types=1);

namespace App\Support;

class FirmwareVersionReader
{
    public const MARKER = 'EZGVER:';
    public const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[A-Za-z0-9._+-]+$/';
    public const ESP_IMAGE_MAGIC = "\xE9";
    public const MIN_APP_BYTES = 262144;

    public static function isEspAppImage(string $data): bool
    {
        return strlen($data) >= self::MIN_APP_BYTES
            && isset($data[0])
            && $data[0] === self::ESP_IMAGE_MAGIC;
    }

    public static function fromFile(string $path): ?string
    {
        $data = file_get_contents($path);
        if (!is_string($data) || $data === '') {
            return null;
        }

        return self::fromBytes($data);
    }

    public static function fromBytes(string $data): ?string
    {
        $fromMarker = self::readMarkedVersion($data);
        if ($fromMarker !== null) {
            return $fromMarker;
        }

        return self::readEspAppDescVersion($data);
    }

    private static function readMarkedVersion(string $data): ?string
    {
        $offset = 0;
        $markerLen = strlen(self::MARKER);
        while (($pos = strpos($data, self::MARKER, $offset)) !== false) {
            $rest = substr($data, $pos + $markerLen, 64);
            $end = strcspn($rest, "\0\r\n ");
            $version = substr($rest, 0, $end);
            $valid = self::validVersion($version);
            if ($valid !== null) {
                return $valid;
            }
            $offset = $pos + 1;
        }

        return null;
    }

    public static function accepted(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::validVersion(trim((string) $value));
    }

    private static function readEspAppDescVersion(string $data): ?string
    {
        $magic = "\x32\x54\xCD\xAB";
        $offset = 0;
        while (($pos = strpos($data, $magic, $offset)) !== false) {
            $version = rtrim(substr($data, $pos + 16, 32), "\0");
            $valid = self::validVersion($version);
            if ($valid !== null) {
                return $valid;
            }
            $offset = $pos + 1;
        }

        return null;
    }

    private static function validVersion(string $version): ?string
    {
        $version = trim($version);
        if ($version === '' || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            return null;
        }

        return $version;
    }
}
