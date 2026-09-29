<?php

declare(strict_types=1);

namespace App\Support;

class LoraDownlinkConfig
{
    public const MAX_BYTES = 96;

    public static function isLoraUplink(array $board): bool
    {
        if (strcasecmp(trim((string) ($board['last_status'] ?? '')), 'lora') === 0) {
            return true;
        }
        $raw = $board['last_connectivity_json'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $decoded = json_decode($raw, true);
        $type = is_array($decoded) ? ($decoded['current']['type'] ?? '') : '';

        return is_string($type) && strcasecmp($type, 'lora') === 0;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function compact(array $config): array
    {
        $candidates = [
            'version' => self::versionString($config['version'] ?? null),
            'lora_only' => !empty($config['lora_only']),
            'pulse_duration_ms' => $config['pulse_duration_ms'] ?? null,
        ];
        $out = [];
        foreach ($candidates as $key => $value) {
            if ($value === null && $key !== 'lora_only') {
                continue;
            }
            $try = $out;
            $try[$key] = $value;
            $json = json_encode($try, JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || strlen($json) >= self::MAX_BYTES) {
                break;
            }
            $out = $try;
        }

        return $out;
    }

    private static function versionString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return gmdate('Y-m-d H:i:s', $value->getTimestamp());
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $text, $match) === 1) {
            return str_replace('T', ' ', $match[1]);
        }

        return $text;
    }
}
