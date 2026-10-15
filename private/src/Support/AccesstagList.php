<?php

declare(strict_types=1);

namespace App\Support;

class AccesstagList
{
    public const MAX_LIMIT = 200;
    public const LORA_LIMIT = 40;

    /**
     * @param list<string> $numbers
     */
    public static function hash(array $numbers): string
    {
        $unique = self::normalize($numbers);
        $payload = '';
        foreach ($unique as $number) {
            $payload .= $number . "\n";
        }

        return hash('sha256', $payload);
    }

    /**
     * @param list<string> $numbers
     * @return list<string>
     */
    public static function normalize(array $numbers): array
    {
        $unique = [];
        foreach ($numbers as $number) {
            $text = trim((string) $number);
            if ($text !== '') {
                $unique[$text] = $text;
            }
        }
        $unique = array_values($unique);
        sort($unique, SORT_STRING);

        return $unique;
    }

    /**
     * @param list<string> $baseline
     * @param list<string> $pushed
     * @return list<array{op: string, accesstag: string}>
     */
    public static function changes(array $baseline, array $pushed, bool $replace): array
    {
        $next = self::normalize($pushed);
        if ($replace) {
            $out = [];
            foreach ($next as $number) {
                $out[] = ['op' => 'add', 'accesstag' => $number];
            }

            return $out;
        }

        $base = self::normalize($baseline);
        $removed = array_values(array_diff($base, $next));
        $added = array_values(array_diff($next, $base));
        sort($removed, SORT_STRING);
        sort($added, SORT_STRING);
        $out = [];
        foreach ($removed as $number) {
            $out[] = ['op' => 'remove', 'accesstag' => $number];
        }
        foreach ($added as $number) {
            $out[] = ['op' => 'add', 'accesstag' => $number];
        }

        return $out;
    }

    public static function shouldReplace(
        ?string $fromVersion,
        ?string $fromHash,
        ?string $confirmedVersion,
        string $baselineHash,
        bool $full
    ): bool {
        if ($full || $fromVersion === null || $fromVersion !== $confirmedVersion) {
            return true;
        }
        $fromHash = $fromHash !== null ? strtolower(trim($fromHash)) : null;
        if ($fromHash === null || strlen($fromHash) !== strlen($baselineHash)) {
            return true;
        }

        return !hash_equals($baselineHash, $fromHash);
    }

    public static function hashesMatch(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }
        $left = strtolower(trim($left));
        $right = strtolower(trim($right));
        if ($left === '' || strlen($left) !== strlen($right)) {
            return false;
        }

        return hash_equals($right, $left);
    }
}
