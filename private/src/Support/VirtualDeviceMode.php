<?php

declare(strict_types=1);

namespace App\Support;

final class VirtualDeviceMode
{
    /**
     * @param array<string, mixed> $device
     */
    public static function hasReader(array $device): bool
    {
        return isset($device['wiegand_reader'])
            && $device['wiegand_reader'] !== null
            && $device['wiegand_reader'] !== '';
    }

    /**
     * @param array<string, mixed> $device
     */
    public static function operation(array $device, bool $hasRelays): ?string
    {
        if (!self::hasReader($device)) {
            return null;
        }

        return $hasRelays ? 'autonomous' : 'reader_only';
    }
}
