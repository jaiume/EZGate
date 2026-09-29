<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\BoardDAO;
use App\DAO\SiteDAO;
use App\DAO\WifiNetworkDAO;
use App\Support\LoraKeys;
use App\Support\PulseDuration;
use App\Support\WifiSecurity;
use App\Support\WifiSsid;

class SetupService
{
    public function __construct(
        private SiteDAO $siteDAO,
        private WifiNetworkDAO $wifiNetworkDAO,
        private BoardDAO $boardDAO
    ) {
    }

    public function listSitesForSetup(string $hardwareId = ''): array
    {
        $sites = [];
        foreach ($this->siteDAO->findAll() as $site) {
            $sites[] = [
                'id' => (int) $site['id'],
                'name' => $site['name'],
            ];
        }

        $boardPayload = null;
        if ($hardwareId !== '') {
            $board = $this->boardDAO->findByHardwareId($hardwareId);
            if ($board !== null) {
                $boardPayload = [
                    'friendly_name' => trim((string) ($board['friendly_name'] ?? '')),
                    'site_id' => $board['site_id'] !== null ? (int) $board['site_id'] : null,
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Sites loaded.',
            'data' => [
                'sites' => $sites,
                'board' => $boardPayload,
            ],
            'error' => null,
        ];
    }

    public function completeSetup(array $payload): array
    {
        $hardwareId = trim((string) ($payload['hardware_id'] ?? ''));
        $friendlyName = trim((string) ($payload['friendly_name'] ?? ''));
        $siteId = (int) ($payload['site_id'] ?? 0);
        $ssid = WifiSsid::from($payload['wifi_ssid'] ?? '');
        $password = (string) ($payload['wifi_password'] ?? '');

        if ($hardwareId === '' || $friendlyName === '' || $siteId < 1) {
            return $this->fail('Board identifier, friendly name, and site are required.', 'VALIDATION_ERROR');
        }

        $site = $this->siteDAO->findById($siteId);
        if ($site === null) {
            return $this->fail('Site not found.', 'NOT_FOUND');
        }

        if ($ssid !== '') {
            $security = trim($password) === '' ? WifiSecurity::OPEN : WifiSecurity::WPA2;
            $storedPassword = WifiSecurity::passwordFor($security, $password) ?? '';
            $existing = $this->wifiNetworkDAO->findBySiteAndSsid($siteId, $ssid);
            if ($existing !== null) {
                $this->wifiNetworkDAO->updateCredentials((int) $existing['id'], $storedPassword, $security);
            } else {
                $this->wifiNetworkDAO->create($siteId, $ssid, $storedPassword, $security);
            }
        }

        $board = $this->boardDAO->findByHardwareId($hardwareId);
        if ($board === null) {
            $id = $this->boardDAO->create($hardwareId, $friendlyName, $siteId);
        } else {
            $id = (int) $board['id'];
            $pulse = PulseDuration::stored($board['pulse_duration_ms'] ?? null);
            $this->boardDAO->update($id, $friendlyName, $siteId, $pulse);
        }

        $this->boardDAO->bumpConfigVersionForSite($siteId);
        if (empty($site['lora_key'])) {
            $this->siteDAO->ensureLoraKey($siteId, LoraKeys::randomHex());
            $site = $this->siteDAO->findById($siteId) ?? $site;
        }
        $saved = $this->boardDAO->findById($id);
        if ($saved !== null && empty($saved['lora_credential'])) {
            $this->boardDAO->ensureLoraCredential($id, LoraKeys::randomHex());
            $saved = $this->boardDAO->findById($id) ?? $saved;
        }
        $wrapped = null;
        $cred = strtoupper(trim((string) ($saved['lora_credential'] ?? '')));
        $siteKey = strtoupper(trim((string) ($site['lora_key'] ?? '')));
        if ($cred !== '' && $siteKey !== '') {
            $wrapped = LoraKeys::wrapSiteKey($cred, $siteKey);
        }

        return [
            'success' => true,
            'message' => 'Board configured onto the site.',
            'data' => [
                'board_id' => $id,
                'config_version' => $saved['config_updated_at'] ?? null,
                'lora_key_id' => (int) ($site['lora_key_id'] ?? 1),
                'lora_site_key_wrapped' => $wrapped,
            ],
            'error' => null,
        ];
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
