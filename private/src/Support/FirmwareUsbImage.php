<?php

declare(strict_types=1);

namespace App\Support;

class FirmwareUsbImage
{
    public const APP_ADDRESS = 0x10000;

    /** @var array<string, array{suffix: string, address: int}> */
    public const PARTS = [
        'bootloader' => ['suffix' => '.bootloader.bin', 'address' => 0x0],
        'partitions' => ['suffix' => '.partitions.bin', 'address' => 0x8000],
        'boot_app0' => ['suffix' => '.boot_app0.bin', 'address' => 0xE000],
    ];

    public static function prefix(string $storedFilename): string
    {
        return str_ends_with($storedFilename, '.bin')
            ? substr($storedFilename, 0, -4)
            : $storedFilename;
    }

    public static function sidecarFilename(string $storedFilename, string $part): ?string
    {
        if (!isset(self::PARTS[$part])) {
            return null;
        }

        return self::prefix($storedFilename) . self::PARTS[$part]['suffix'];
    }

    public static function hasFactory(string $directory, string $storedFilename): bool
    {
        $app = $directory . '/' . $storedFilename;
        if (!is_file($app) || filesize($app) <= 0) {
            return false;
        }

        foreach (array_keys(self::PARTS) as $part) {
            $name = self::sidecarFilename($storedFilename, $part);
            if ($name === null) {
                return false;
            }
            $path = $directory . '/' . $name;
            if (!is_file($path) || filesize($path) <= 0) {
                return false;
            }
        }

        return true;
    }
}
