<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\AccessReadDAO;
use App\DAO\VirtualDeviceDAO;
use App\Support\AppLog;

class EntryZenReadService
{
    public function __construct(
        private AccessReadDAO $accessReadDAO,
        private VirtualDeviceDAO $virtualDeviceDAO
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function push(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        $groups = [];
        $held = false;
        foreach ($rows as $row) {
            $destination = $this->destination((int) ($row['virtual_device_id'] ?? 0));
            if ($destination === null) {
                $held = true;
                continue;
            }
            $key = $destination['endpoint'] . "\n" . $destination['token'];
            $groups[$key]['endpoint'] = $destination['endpoint'];
            $groups[$key]['token'] = $destination['token'];
            $groups[$key]['rows'][] = $row;
        }

        $ok = !$held;
        foreach ($groups as $group) {
            if (!$this->post($group['endpoint'], $group['token'], $group['rows'])) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function pushPending(): void
    {
        $this->push($this->accessReadDAO->pendingAll());
    }

    public function pushForBoard(int $boardId): void
    {
        $this->push($this->accessReadDAO->pendingForBoard($boardId));
    }

    /**
     * @return array{endpoint: string, token: string}|null
     */
    private function destination(int $deviceId): ?array
    {
        $stored = $deviceId > 0 ? $this->virtualDeviceDAO->readsDestination($deviceId) : null;
        $endpoint = trim((string) ($stored['reads_endpoint'] ?? ''));
        $token = trim((string) ($stored['reads_token'] ?? ''));
        if ($endpoint !== '' && $token !== '') {
            return ['endpoint' => $endpoint, 'token' => $token];
        }
        if ($endpoint !== '' || $token !== '') {
            return null;
        }

        $url = trim((string) ConfigService::get('entryzen.reads_url', ''));
        $key = trim((string) ConfigService::get('entryzen.api_key', ''));
        if ($url === '' || $key === '') {
            return null;
        }

        return ['endpoint' => $url, 'token' => $key];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function post(string $url, string $token, array $rows): bool
    {
        $reads = [];
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
            $reads[] = [
                'read_at' => $this->readAt($row['read_at'] ?? null),
                'virtual_device' => (string) $row['public_id'],
                'accesstag_number' => (string) $row['accesstag_number'],
                'in_list' => !empty($row['in_list']),
            ];
        }

        $body = json_encode(['reads' => $reads], JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return false;
        }

        $timeout = (int) ConfigService::get('entryzen.timeout', 5);
        $timeout = max(1, min(30, $timeout));
        $handle = curl_init($url);
        if ($handle === false) {
            return false;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($raw) || $status !== 200) {
            AppLog::info('entryzen reads failed status=' . $status);
            return false;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            AppLog::info('entryzen reads rejected');
            return false;
        }

        $this->accessReadDAO->deleteIds($ids);
        return true;
    }

    private function readAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $stamp = strtotime($text . ' UTC');
        if ($stamp === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $stamp);
    }
}
