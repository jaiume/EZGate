<?php

declare(strict_types=1);

namespace App\Support;

final class OtaProgress
{
    /**
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    public static function attach(array $board): array
    {
        $board['ota_bytes_sent'] = null;
        $board['ota_bytes_total'] = null;
        $board['ota_percent'] = null;
        $board['ota_progress_label'] = null;

        if (empty($board['firmware_pending'])) {
            return $board;
        }

        $total = isset($board['target_firmware_size']) ? (int) $board['target_firmware_size'] : 0;
        if ($total > 0) {
            $board['ota_bytes_total'] = $total;
        }

        if (!array_key_exists('ota_offset', $board) || $board['ota_offset'] === null || $board['ota_offset'] === '') {
            return $board;
        }

        $otaVersion = trim((string) ($board['ota_version'] ?? ''));
        $target = trim((string) ($board['target_firmware_version'] ?? ''));
        if ($otaVersion !== '' && $target !== '' && $otaVersion !== $target) {
            return $board;
        }
        if ($total <= 0) {
            return $board;
        }

        $sent = max(0, (int) $board['ota_offset']);
        if ($sent > $total) {
            $sent = $total;
        }
        $percent = (int) floor(($sent * 100) / $total);

        $board['ota_bytes_sent'] = $sent;
        $board['ota_percent'] = $percent;
        $board['ota_progress_label'] = number_format($sent) . ' / ' . number_format($total) . ' bytes (' . $percent . '%)';

        return $board;
    }
}
