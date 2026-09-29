<?php

declare(strict_types=1);

namespace App\Support;

class FirmwareAlreadyRunning
{
    public static function matches(mixed $reportedVersion, string $targetVersion): bool
    {
        $reported = trim((string) $reportedVersion);
        $target = trim($targetVersion);

        return $reported !== '' && $target !== '' && $reported === $target;
    }

    public static function shouldOffer(mixed $reportedVersion, string $targetVersion): bool
    {
        $target = trim($targetVersion);
        if ($target === '') {
            return false;
        }

        $reported = trim((string) $reportedVersion);
        if ($reported === '') {
            return true;
        }
        if ($reported === $target) {
            return false;
        }

        return version_compare($target, $reported, '>') === true;
    }
}
