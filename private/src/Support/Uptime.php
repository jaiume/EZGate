<?php

declare(strict_types=1);

namespace App\Support;

class Uptime
{
    public static function label(mixed $seconds): ?string
    {
        if ($seconds === null || $seconds === '') {
            return null;
        }
        if (!is_numeric($seconds)) {
            return null;
        }

        $total = (int) $seconds;
        if ($total < 0) {
            return null;
        }

        $days = intdiv($total, 86400);
        $hours = intdiv($total % 86400, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        if ($secs > 0 || $parts === []) {
            $parts[] = $secs . 's';
        }

        return implode(' ', $parts);
    }
}
