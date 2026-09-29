<?php

declare(strict_types=1);

namespace App\DAO;

class BoardDAO extends AbstractDAO
{
    public function findAllWithSite(): array
    {
        $stmt = $this->db->query(
            'SELECT b.*, s.name AS site_name, s.pulse_duration_ms AS site_pulse_duration_ms, tf.version AS target_firmware_version, tf.file_size AS target_firmware_size
             FROM boards b
             LEFT JOIN sites s ON s.id = b.site_id
             LEFT JOIN firmware_versions tf ON tf.id = b.target_firmware_version_id
             ORDER BY b.hardware_id ASC'
        );
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, s.name AS site_name, s.pulse_duration_ms AS site_pulse_duration_ms, tf.version AS target_firmware_version, tf.file_size AS target_firmware_size
             FROM boards b
             LEFT JOIN sites s ON s.id = b.site_id
             LEFT JOIN firmware_versions tf ON tf.id = b.target_firmware_version_id
             WHERE b.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByHardwareId(string $hardwareId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, s.name AS site_name, s.pulse_duration_ms AS site_pulse_duration_ms, tf.version AS target_firmware_version, tf.file_size AS target_firmware_size
             FROM boards b
             LEFT JOIN sites s ON s.id = b.site_id
             LEFT JOIN firmware_versions tf ON tf.id = b.target_firmware_version_id
             WHERE b.hardware_id = ?
             LIMIT 1'
        );
        $stmt->execute([$hardwareId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $hardwareId, ?string $friendlyName = null, ?int $siteId = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO boards (hardware_id, friendly_name, site_id, config_updated_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$hardwareId, $friendlyName, $siteId]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, ?string $friendlyName, ?int $siteId, ?int $pulseDurationMs): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET friendly_name = ?, site_id = ?, pulse_duration_ms = ?, config_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$friendlyName, $siteId, $pulseDurationMs, $id]);
    }

    public function updateSiteId(int $id, ?int $siteId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET site_id = ?, config_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$siteId, $id]);
    }

    public function wipeToInventory(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET site_id = NULL,
                 target_firmware_version_id = NULL,
                 ota_version = NULL,
                 ota_offset = NULL,
                 config_updated_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM boards WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function recordUsbFirmwareLoad(int $id, string $version): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET target_firmware_version_id = NULL,
                 reported_firmware_version = ?,
                 reported_fallback_firmware_version = NULL,
                 ota_version = NULL,
                 ota_offset = NULL,
                 config_updated_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$version, $id]);
    }

    public function bumpConfigVersion(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards SET config_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function bumpConfigVersionForSite(int $siteId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards SET config_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE site_id = ?'
        );
        $stmt->execute([$siteId]);
    }

    public function ensureLoraCredential(int $id, string $credential): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET lora_credential = COALESCE(lora_credential, ?), updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$credential, $id]);
    }

    public function setLoraOnly(int $id, bool $loraOnly): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET lora_only = ?, config_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND lora_only <> ?'
        );
        $flag = $loraOnly ? 1 : 0;
        $stmt->execute([$flag, $id, $flag]);
    }

    public function recordHeartbeat(
        int $id,
        string $status,
        ?string $gateState,
        ?string $wifiSsid,
        ?int $wifiRssi,
        ?string $connectivityJson,
        ?string $reportedConfigVersion,
        ?string $reportedFirmwareVersion = null,
        ?string $reportedFallbackFirmwareVersion = null,
        ?int $uptimeSeconds = null,
        ?string $otaVersion = null,
        ?int $otaOffset = null
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET last_status = ?,
                 last_gate_state = ?,
                 last_wifi_ssid = ?,
                 last_wifi_rssi = ?,
                 last_connectivity_json = ?,
                 reported_config_version = COALESCE(?, reported_config_version),
                 reported_firmware_version = ?,
                 reported_fallback_firmware_version = ?,
                 last_uptime_seconds = COALESCE(?, last_uptime_seconds),
                 ota_version = ?,
                 ota_offset = ?,
                 last_heartbeat_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([
            $status,
            $gateState,
            $wifiSsid,
            $wifiRssi,
            $connectivityJson,
            $reportedConfigVersion,
            $reportedFirmwareVersion,
            $reportedFallbackFirmwareVersion,
            $uptimeSeconds,
            $otaVersion,
            $otaOffset,
            $id,
        ]);
    }

    public function setTargetFirmware(int $boardId, int $firmwareVersionId, string $runningVersion): int
    {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET target_firmware_version_id = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ?
               AND (reported_firmware_version IS NULL OR reported_firmware_version <> ?)'
        );
        $stmt->execute([$firmwareVersionId, $boardId, $runningVersion]);
        return $stmt->rowCount();
    }

    public function setTargetFirmwareForSite(int $siteId, int $firmwareVersionId, string $runningVersion): int
    {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET target_firmware_version_id = ?, updated_at = UTC_TIMESTAMP()
             WHERE site_id = ?
               AND (reported_firmware_version IS NULL OR reported_firmware_version <> ?)'
        );
        $stmt->execute([$firmwareVersionId, $siteId, $runningVersion]);
        return $stmt->rowCount();
    }

    public function setTargetFirmwareForFleet(int $firmwareVersionId, string $runningVersion): int
    {
        $stmt = $this->db->prepare(
            'UPDATE boards
             SET target_firmware_version_id = ?, updated_at = UTC_TIMESTAMP()
             WHERE reported_firmware_version IS NULL OR reported_firmware_version <> ?'
        );
        $stmt->execute([$firmwareVersionId, $runningVersion]);
        return $stmt->rowCount();
    }

    public function setTargetFirmwareForIds(array $boardIds, int $firmwareVersionId, string $runningVersion): int
    {
        $ids = [];
        foreach ($boardIds as $boardId) {
            $id = (int) $boardId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "UPDATE boards
             SET target_firmware_version_id = ?, updated_at = UTC_TIMESTAMP()
             WHERE id IN ($placeholders)
               AND (reported_firmware_version IS NULL OR reported_firmware_version <> ?)"
        );
        $stmt->execute(array_merge([$firmwareVersionId], array_values($ids), [$runningVersion]));
        return $stmt->rowCount();
    }

    public function requestScan(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards SET scan_requested = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function clearScanRequested(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards SET scan_requested = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function requestReboot(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards SET reboot_requested = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function clearRebootRequested(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards SET reboot_requested = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function countAll(): int
    {
        $stmt = $this->db->query('SELECT COUNT(*) FROM boards');
        return (int) ($stmt === false ? 0 : $stmt->fetchColumn());
    }

    public function countBySite(int $siteId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM boards WHERE site_id = ?');
        $stmt->execute([$siteId]);
        return (int) $stmt->fetchColumn();
    }
    public function countReportingVersion(string $version): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM boards WHERE reported_firmware_version = ?'
        );
        $stmt->execute([$version]);
        return (int) $stmt->fetchColumn();
    }

    public function countTargetingVersion(int $firmwareVersionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM boards WHERE target_firmware_version_id = ?'
        );
        $stmt->execute([$firmwareVersionId]);
        return (int) $stmt->fetchColumn();
    }

    public function clearSiteAssignments(int $siteId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE boards SET site_id = NULL, config_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE site_id = ?'
        );
        $stmt->execute([$siteId]);
    }
}
