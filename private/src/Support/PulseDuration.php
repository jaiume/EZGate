<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ConfigService;

class PulseDuration
{
    public const MIN = 50;
    public const MAX = 10000;
    public const FALLBACK = 500;

    public static function appDefault(): int
    {
        $ms = (int) ConfigService::get('app.pulse_duration_ms', self::FALLBACK);
        return self::isValid($ms) ? $ms : self::FALLBACK;
    }

    public static function stored(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ms = (int) $value;
        return self::isValid($ms) ? $ms : null;
    }

    public static function resolve(?int $boardOverride, ?int $siteOverride): int
    {
        if ($boardOverride !== null && self::isValid($boardOverride)) {
            return $boardOverride;
        }
        if ($siteOverride !== null && self::isValid($siteOverride)) {
            return $siteOverride;
        }

        return self::appDefault();
    }

    public static function resolveFromBoard(array $board): int
    {
        return self::resolve(
            self::stored($board['pulse_duration_ms'] ?? null),
            self::stored($board['site_pulse_duration_ms'] ?? null)
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: true, value: ?int}|array{ok: false, message: string}
     */
    public static function fromForm(array $body): array
    {
        $mode = (string) ($body['pulse_mode'] ?? 'inherit');
        if ($mode !== 'custom') {
            return ['ok' => true, 'value' => null];
        }

        $raw = $body['pulse_duration_ms'] ?? '';
        if ($raw === null || $raw === '') {
            return ['ok' => false, 'message' => 'Enter a pulse duration between 50 and 10000 ms.'];
        }

        $ms = (int) $raw;
        if (!self::isValid($ms)) {
            return ['ok' => false, 'message' => 'Pulse duration must be between 50 and 10000 ms.'];
        }

        return ['ok' => true, 'value' => $ms];
    }

    public static function isValid(int $ms): bool
    {
        return $ms >= self::MIN && $ms <= self::MAX;
    }
}
