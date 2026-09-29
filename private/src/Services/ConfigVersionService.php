<?php

declare(strict_types=1);

namespace App\Services;

class ConfigVersionService
{
    public function shouldSendConfig(?string $portalVersion, ?string $boardVersion): bool
    {
        $portal = $this->normalize($portalVersion);
        if ($portal === null) {
            return false;
        }

        $board = $this->normalize($boardVersion);
        if ($board === null) {
            return true;
        }

        return $portal > $board;
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $timestamp = strtotime($trimmed . ' UTC');
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
