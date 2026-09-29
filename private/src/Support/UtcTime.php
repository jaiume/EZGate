<?php

declare(strict_types=1);

namespace App\Support;

class UtcTime
{
    public static function toIso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace('T', ' ', $trimmed);
        $normalized = preg_replace('/(?:Z|[+-]\d{2}:?\d{2})$/', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        $utc = new \DateTimeZone('UTC');
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, $utc);
        if ($parsed === false) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $normalized, $utc);
        }
        if ($parsed === false) {
            return null;
        }

        return $parsed->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
    }

    public static function markup(mixed $value, string $empty = '—'): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return htmlspecialchars($empty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $text = (string) $value;
        $iso = self::toIso($text);
        if ($iso === null) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $utcLabel = substr($iso, 0, 10) . ' ' . substr($iso, 11, 8) . ' UTC';

        return sprintf(
            '<time datetime="%s" data-utc title="%s">%s</time>',
            htmlspecialchars($iso, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($utcLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($utcLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
