<?php

declare(strict_types=1);

namespace App\Support;

class ConnectivityGraph
{
    /**
     * @param list<array<string, mixed>> $boards
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function forSite(string $siteName, array $boards): array
    {
        $nodes = [
            [
                'id' => 'portal',
                'kind' => 'portal',
                'label' => 'Portal',
                'online' => true,
            ],
        ];
        $seen = ['portal' => true];
        $edges = [];
        $edgeSeen = [];

        foreach ($boards as $board) {
            $boardId = 'board:' . (int) ($board['id'] ?? 0);
            if (!isset($seen[$boardId])) {
                $label = trim((string) ($board['friendly_name'] ?: $board['hardware_id'] ?? ''));
                $nodes[] = [
                    'id' => $boardId,
                    'kind' => 'board',
                    'label' => $label !== '' ? $label : $boardId,
                    'online' => !empty($board['online']),
                    'role' => self::role($board),
                ];
                $seen[$boardId] = true;
            }
        }

        foreach ($boards as $board) {
            $boardId = 'board:' . (int) ($board['id'] ?? 0);
            $current = self::current($board);
            $type = strtolower(trim((string) ($current['type'] ?? $board['last_status'] ?? '')));
            $ssid = WifiSsid::from($current['ssid'] ?? ($board['last_wifi_ssid'] ?? ''));

            if ($type === 'lora') {
                $parentId = self::parentBoardId($current, $boards);
                if ($parentId !== null && isset($seen[$parentId])) {
                    $rssi = $current['parent_rssi'] ?? ($current['radio_stats']['parent_rssi'] ?? null);
                    $rssi = is_numeric($rssi) ? (int) $rssi : null;
                    self::addEdge($edges, $edgeSeen, $boardId, $parentId, 'lora', $rssi);
                }
                continue;
            }

            if ($type === 'wifi' || $ssid !== '') {
                if ($ssid === '') {
                    self::addEdge($edges, $edgeSeen, $boardId, 'portal', 'wifi');
                    continue;
                }
                $wifiId = 'wifi:' . $ssid;
                if (!isset($seen[$wifiId])) {
                    $nodes[] = [
                        'id' => $wifiId,
                        'kind' => 'wifi',
                        'label' => $ssid,
                        'online' => true,
                    ];
                    $seen[$wifiId] = true;
                }
                self::addEdge($edges, $edgeSeen, $boardId, $wifiId, 'wifi');
                self::addEdge($edges, $edgeSeen, $wifiId, 'portal', 'uplink');
            }
        }

        $labels = [];
        foreach ($nodes as $node) {
            $labels[$node['id']] = $node['label'];
        }
        foreach ($edges as &$edge) {
            $edge['from_label'] = $labels[$edge['from']] ?? $edge['from'];
            $edge['to_label'] = $labels[$edge['to']] ?? $edge['to'];
        }
        unset($edge);

        return [
            'site' => $siteName,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    /**
     * @param array<string, mixed> $board
     */
    private static function role(array $board): string
    {
        $current = self::current($board);
        $stats = is_array($current['radio_stats'] ?? null) ? $current['radio_stats'] : [];
        $role = strtolower(trim((string) ($stats['role'] ?? $current['role'] ?? '')));
        if (in_array($role, ['leaf', 'relay', 'gateway'], true)) {
            return $role;
        }
        $type = strtolower(trim((string) ($current['type'] ?? '')));
        if ($type === 'wifi') {
            return 'gateway';
        }
        if ($type === 'lora') {
            return 'leaf';
        }

        return 'offline';
    }

    /**
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    private static function current(array $board): array
    {
        $connectivity = [];
        if (!empty($board['last_connectivity_json'])) {
            $decoded = json_decode((string) $board['last_connectivity_json'], true);
            if (is_array($decoded)) {
                $connectivity = $decoded;
            }
        } elseif (isset($board['connectivity']) && is_array($board['connectivity'])) {
            $connectivity = $board['connectivity'];
        }
        $current = $connectivity['current'] ?? [];

        return is_array($current) ? $current : [];
    }

    /**
     * @param array<string, mixed> $current
     * @param list<array<string, mixed>> $boards
     */
    private static function parentBoardId(array $current, array $boards): ?string
    {
        $parent = $current['parent'] ?? $current['gateway'] ?? $current['parent_hardware_id'] ?? null;
        $hardwareId = '';
        $numericId = 0;
        $name = '';
        if (is_array($parent)) {
            $hardwareId = trim((string) ($parent['hardware_id'] ?? ''));
            $name = trim((string) ($parent['friendly_name'] ?? ''));
            $numericId = isset($parent['id']) && is_numeric($parent['id']) ? (int) $parent['id'] : 0;
        } elseif (is_string($parent) || is_int($parent)) {
            $key = trim((string) $parent);
            if (ctype_digit($key)) {
                $numericId = (int) $key;
            } else {
                $hardwareId = $key;
                $name = $key;
            }
        }
        foreach ($boards as $board) {
            if ($numericId > 0 && (int) ($board['id'] ?? 0) === $numericId) {
                return 'board:' . $numericId;
            }
            if ($hardwareId !== '' && (string) ($board['hardware_id'] ?? '') === $hardwareId) {
                return 'board:' . (int) ($board['id'] ?? 0);
            }
            if ($name !== '' && trim((string) ($board['friendly_name'] ?? '')) === $name) {
                return 'board:' . (int) ($board['id'] ?? 0);
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $edges
     * @param array<string, true> $edgeSeen
     */
    private static function addEdge(array &$edges, array &$edgeSeen, string $from, string $to, string $kind, ?int $rssi = null): void
    {
        $key = $from . '>' . $to . '>' . $kind;
        if (isset($edgeSeen[$key])) {
            return;
        }
        $edgeSeen[$key] = true;
        $edge = [
            'from' => $from,
            'to' => $to,
            'kind' => $kind,
        ];
        if ($rssi !== null) {
            $edge['rssi'] = $rssi;
        }
        $edges[] = $edge;
    }
}
