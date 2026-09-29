<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\OpenCommandDAO;
use App\DAO\SiteDAO;
use App\DAO\WifiNetworkDAO;
use App\Support\BoardPresenter;
use App\Support\ConnectivityGraph;
use App\Support\LoraKeys;
use App\Support\WifiSecurity;
use App\Support\WifiSsid;
use App\Support\PulseDuration;

class SiteService
{
    public function __construct(
        private SiteDAO $siteDAO,
        private WifiNetworkDAO $wifiNetworkDAO,
        private BoardDAO $boardDAO,
        private OpenCommandDAO $openCommandDAO,
        private BoardService $boardService
    ) {
    }

    public function listSites(): array
    {
        $cutoff = $this->boardService->isolationCutoff();

        $boardsBySite = [];
        foreach ($this->boardDAO->findAllWithSite() as $board) {
            if ($board['site_id'] === null || $board['site_id'] === '') {
                continue;
            }
            $siteId = (int) $board['site_id'];
            $presented = $this->boardService->present($board, $cutoff);
            $presented['open_status'] = BoardPresenter::openStatus(
                $this->openCommandDAO->findLatestForBoard((int) $board['id'])
            );
            $boardsBySite[$siteId][] = $presented;
        }

        $ssidsBySite = [];
        foreach ($this->wifiNetworkDAO->findAll() as $network) {
            $ssid = WifiSsid::from($network['ssid'] ?? '');
            if ($ssid === '') {
                continue;
            }
            $ssidsBySite[(int) $network['site_id']][$ssid] = $ssid;
        }

        $sites = [];
        foreach ($this->siteDAO->findAll() as $site) {
            $id = (int) $site['id'];
            $sites[] = $this->presentSiteGroup(
                $id,
                $site['name'],
                $boardsBySite[$id] ?? [],
                array_values($ssidsBySite[$id] ?? [])
            );
        }

        return [
            'success' => true,
            'message' => 'Sites loaded.',
            'data' => ['sites' => $sites],
            'error' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $boards
     * @param list<string> $configuredSsids
     * @return array<string, mixed>
     */
    private function presentSiteGroup(int $id, string $name, array $boards, array $configuredSsids): array
    {
        $onlineCount = 0;
        foreach ($boards as $board) {
            if (!empty($board['online'])) {
                $onlineCount++;
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'board_count' => count($boards),
            'online_count' => $onlineCount,
            'offline_count' => count($boards) - $onlineCount,
            'boards' => $boards,
            'configured_ssids' => $configuredSsids,
            'graph' => ConnectivityGraph::forSite($name, $boards),
        ];
    }

    public function getSite(int $id): array
    {
        $site = $this->siteDAO->findById($id);
        if ($site === null) {
            return $this->fail('Site not found.', 'NOT_FOUND');
        }

        $networks = $this->presentWifiNetworks($id, $this->wifiNetworkDAO->findBySiteId($id));
        $site = $this->presentSitePulse($site);

        return [
            'success' => true,
            'message' => 'Site loaded.',
            'data' => [
                'site' => $site,
                'wifi_networks' => $networks,
            ],
            'error' => null,
        ];
    }

    public function createSite(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return $this->fail('Site name is required.', 'VALIDATION_ERROR');
        }

        $id = $this->siteDAO->create($name);
        $this->siteDAO->ensureLoraKey($id, LoraKeys::randomHex());
        return [
            'success' => true,
            'message' => 'Site created.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function updateSite(int $id, string $name, ?int $pulseDurationMs): array
    {
        $site = $this->siteDAO->findById($id);
        if ($site === null) {
            return $this->fail('Site not found.', 'NOT_FOUND');
        }

        $name = trim($name);
        if ($name === '') {
            return $this->fail('Site name is required.', 'VALIDATION_ERROR');
        }

        $previousPulse = PulseDuration::stored($site['pulse_duration_ms'] ?? null);
        $this->siteDAO->update($id, $name, $pulseDurationMs);
        if ($previousPulse !== $pulseDurationMs) {
            $this->boardDAO->bumpConfigVersionForSite($id);
        }

        return [
            'success' => true,
            'message' => 'Site updated.',
            'data' => ['id' => $id],
            'error' => null,
        ];
    }

    public function deleteSite(int $id): array
    {
        $site = $this->siteDAO->findById($id);
        if ($site === null) {
            return $this->fail('Site not found.', 'NOT_FOUND');
        }

        $this->boardDAO->clearSiteAssignments($id);
        $this->siteDAO->delete($id);
        return [
            'success' => true,
            'message' => 'Site deleted.',
            'data' => null,
            'error' => null,
        ];
    }

    public function addWifi(int $siteId, string $ssid, string $password, string $security): array
    {
        $site = $this->siteDAO->findById($siteId);
        if ($site === null) {
            return $this->fail('Site not found.', 'NOT_FOUND');
        }

        $ssid = WifiSsid::from($ssid);
        if ($ssid === '') {
            return $this->fail('SSID is required.', 'VALIDATION_ERROR');
        }

        $normalized = WifiSecurity::normalize($security);
        if ($normalized === WifiSecurity::OPEN) {
            $storedPassword = '';
        } elseif ($normalized === WifiSecurity::WPA2 || $normalized === WifiSecurity::WPA3) {
            $storedPassword = WifiSecurity::passwordFor($normalized, $password);
            if ($storedPassword === null) {
                return $this->fail('A password is required for WPA2 and WPA3 networks.', 'VALIDATION_ERROR');
            }
        } else {
            return $this->fail('Choose Open, WPA2, or WPA3.', 'VALIDATION_ERROR');
        }

        $existing = $this->wifiNetworkDAO->findBySiteAndSsid($siteId, $ssid);
        if ($existing !== null) {
            $this->wifiNetworkDAO->updateCredentials((int) $existing['id'], $storedPassword, $normalized);
        } else {
            $this->wifiNetworkDAO->create($siteId, $ssid, $storedPassword, $normalized);
        }

        $this->boardDAO->bumpConfigVersionForSite($siteId);

        return [
            'success' => true,
            'message' => 'WiFi network saved for the site.',
            'data' => null,
            'error' => null,
        ];
    }

    public function deleteWifi(int $siteId, int $wifiId): array
    {
        $network = $this->wifiNetworkDAO->findById($wifiId);
        if ($network === null || (int) $network['site_id'] !== $siteId) {
            return $this->fail('WiFi network not found.', 'NOT_FOUND');
        }

        $this->wifiNetworkDAO->delete($wifiId);
        $this->boardDAO->bumpConfigVersionForSite($siteId);

        return [
            'success' => true,
            'message' => 'WiFi network removed.',
            'data' => null,
            'error' => null,
        ];
    }

    private function presentWifiNetworks(int $siteId, array $networks): array
    {
        $usage = $this->wifiUsageBySsid($siteId);
        $reported = $this->reportedSecurityBySsid($siteId);
        $presented = [];
        foreach ($networks as $network) {
            $ssid = (string) $network['ssid'];
            $counts = $usage[$ssid] ?? ['direct' => 0, 'routed' => 0];
            $security = $reported[$ssid] ?? null;
            $presented[] = [
                'id' => (int) $network['id'],
                'ssid' => $ssid,
                'security' => $security,
                'security_label' => $security !== null ? WifiSecurity::label($security) : '—',
                'direct_count' => $counts['direct'],
                'routed_count' => $counts['routed'],
            ];
        }

        return $presented;
    }

    /**
     * @return array<string, string>
     */
    private function reportedSecurityBySsid(int $siteId): array
    {
        $found = [];
        foreach ($this->boardDAO->findAllWithSite() as $board) {
            if ((int) ($board['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            $connectivity = [];
            if (!empty($board['last_connectivity_json'])) {
                $decoded = json_decode((string) $board['last_connectivity_json'], true);
                if (is_array($decoded)) {
                    $connectivity = $decoded;
                }
            }
            $current = is_array($connectivity['current'] ?? null) ? $connectivity['current'] : [];
            $this->noteReportedSecurity(
                $found,
                $current['ssid'] ?? ($board['last_wifi_ssid'] ?? null),
                $current['security'] ?? ($current['encryption'] ?? ($current['auth'] ?? null))
            );
            $others = is_array($connectivity['other'] ?? null) ? $connectivity['other'] : [];
            foreach ($others as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $this->noteReportedSecurity(
                    $found,
                    $option['ssid'] ?? null,
                    $option['security'] ?? ($option['encryption'] ?? ($option['auth'] ?? null))
                );
            }
        }

        return $found;
    }

    /**
     * @param array<string, string> $found
     */
    private function noteReportedSecurity(array &$found, mixed $ssid, mixed $raw): void
    {
        $ssid = WifiSsid::from($ssid);
        $security = WifiSecurity::normalize($raw);
        if ($ssid === '' || $security === null) {
            return;
        }
        $found[$ssid] = WifiSecurity::prefer($found[$ssid] ?? null, $security) ?? $security;
    }

    /**
     * @return array<string, array{direct: int, routed: int}>
     */
    private function wifiUsageBySsid(int $siteId): array
    {
        $snapshots = [];
        foreach ($this->boardDAO->findAllWithSite() as $board) {
            if ((int) ($board['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            $snapshots[] = $this->boardLinkSnapshot($board);
        }

        $byKey = [];
        foreach ($snapshots as $snap) {
            $byKey[$snap['hardware_id']] = $snap;
            if ($snap['friendly_name'] !== '') {
                $byKey['name:' . $snap['friendly_name']] = $snap;
            }
            $byKey['id:' . $snap['id']] = $snap;
        }

        $usage = [];
        foreach ($snapshots as $snap) {
            if ($snap['type'] === 'wifi' && $snap['ssid'] !== '') {
                $ssid = $snap['ssid'];
                $usage[$ssid]['direct'] = ($usage[$ssid]['direct'] ?? 0) + 1;
                $usage[$ssid]['routed'] = $usage[$ssid]['routed'] ?? 0;
            }
        }

        foreach ($snapshots as $snap) {
            if ($snap['type'] !== 'lora') {
                continue;
            }
            $gateway = $this->wifiGatewayForLora($snap, $byKey, []);
            if ($gateway === null || $gateway['ssid'] === '') {
                continue;
            }
            $ssid = $gateway['ssid'];
            $usage[$ssid]['direct'] = $usage[$ssid]['direct'] ?? 0;
            $usage[$ssid]['routed'] = ($usage[$ssid]['routed'] ?? 0) + 1;
        }

        return $usage;
    }

    /**
     * @return array{id: int, hardware_id: string, friendly_name: string, type: string, ssid: string, parent: mixed}
     */
    private function boardLinkSnapshot(array $board): array
    {
        $connectivity = [];
        if (!empty($board['last_connectivity_json'])) {
            $decoded = json_decode((string) $board['last_connectivity_json'], true);
            if (is_array($decoded)) {
                $connectivity = $decoded;
            }
        }
        $current = is_array($connectivity['current'] ?? null) ? $connectivity['current'] : [];
        $type = strtolower(trim((string) ($current['type'] ?? $board['last_status'] ?? '')));
        $ssid = WifiSsid::from($current['ssid'] ?? ($board['last_wifi_ssid'] ?? ''));
        if ($type !== 'lora' && $ssid !== '') {
            $type = 'wifi';
        } elseif ($type !== 'lora') {
            $type = $type === 'wifi' ? 'wifi' : '';
        }

        return [
            'id' => (int) $board['id'],
            'hardware_id' => (string) $board['hardware_id'],
            'friendly_name' => trim((string) ($board['friendly_name'] ?? '')),
            'type' => $type,
            'ssid' => $ssid,
            'parent' => $current['parent'] ?? $current['gateway'] ?? $current['parent_hardware_id'] ?? null,
        ];
    }

    /**
     * @param array<string, array{id: int, hardware_id: string, friendly_name: string, type: string, ssid: string, parent: mixed}> $byKey
     * @param array<string, true> $seen
     * @return array{id: int, hardware_id: string, friendly_name: string, type: string, ssid: string, parent: mixed}|null
     */
    private function wifiGatewayForLora(array $snap, array $byKey, array $seen): ?array
    {
        $id = $snap['hardware_id'];
        if (isset($seen[$id])) {
            return null;
        }
        $seen[$id] = true;

        $parent = $this->resolveParent($snap['parent'], $byKey);
        if ($parent === null) {
            return null;
        }
        if ($parent['type'] === 'wifi') {
            return $parent;
        }
        if ($parent['type'] === 'lora') {
            return $this->wifiGatewayForLora($parent, $byKey, $seen);
        }

        return null;
    }

    /**
     * @param array<string, array{id: int, hardware_id: string, friendly_name: string, type: string, ssid: string, parent: mixed}> $byKey
     * @return array{id: int, hardware_id: string, friendly_name: string, type: string, ssid: string, parent: mixed}|null
     */
    private function resolveParent(mixed $parent, array $byKey): ?array
    {
        if (is_array($parent)) {
            $hardwareId = trim((string) ($parent['hardware_id'] ?? ''));
            if ($hardwareId !== '' && isset($byKey[$hardwareId])) {
                return $byKey[$hardwareId];
            }
            $name = trim((string) ($parent['friendly_name'] ?? ''));
            if ($name !== '' && isset($byKey['name:' . $name])) {
                return $byKey['name:' . $name];
            }
            if (isset($parent['id']) && is_numeric($parent['id'])) {
                $key = 'id:' . (int) $parent['id'];
                if (isset($byKey[$key])) {
                    return $byKey[$key];
                }
            }
            return null;
        }

        if (!is_string($parent) && !is_int($parent)) {
            return null;
        }
        $key = trim((string) $parent);
        if ($key === '') {
            return null;
        }
        if (isset($byKey[$key])) {
            return $byKey[$key];
        }
        if (ctype_digit($key) && isset($byKey['id:' . (int) $key])) {
            return $byKey['id:' . (int) $key];
        }
        if (isset($byKey['name:' . $key])) {
            return $byKey['name:' . $key];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function presentSitePulse(array $site): array
    {
        $override = PulseDuration::stored($site['pulse_duration_ms'] ?? null);
        $site['pulse_inherit'] = $override === null;
        $site['pulse_resolved'] = PulseDuration::resolve(null, $override);
        $site['pulse_default'] = PulseDuration::appDefault();
        $site['pulse_duration_ms'] = $override;

        return $site;
    }

    private function fail(string $message, string $code): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => ['code' => $code, 'details' => []],
        ];
    }
}
