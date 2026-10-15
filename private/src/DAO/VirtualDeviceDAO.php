<?php

declare(strict_types=1);

namespace App\DAO;

class VirtualDeviceDAO extends AbstractDAO
{
    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT vd.*, b.hardware_id, b.site_id, b.last_heartbeat_at, b.last_status, b.last_connectivity_json,
                    b.board_type_id, b.friendly_name AS board_friendly_name,
                    s.pulse_duration_ms AS site_pulse_duration_ms,
                    t.name AS board_type_name, t.relay_count, t.wiegand_count, t.has_lora, t.has_wifi, t.has_ethernet
             FROM virtual_devices vd
             JOIN boards b ON b.id = vd.board_id
             JOIN board_types t ON t.id = b.board_type_id
             LEFT JOIN sites s ON s.id = b.site_id
             WHERE vd.public_id = ?
             LIMIT 1'
        );
        $stmt->execute([$publicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT vd.*, b.hardware_id, b.site_id, b.last_heartbeat_at, b.friendly_name AS board_friendly_name,
                    s.pulse_duration_ms AS site_pulse_duration_ms,
                    t.name AS board_type_name, t.relay_count, t.wiegand_count, t.has_lora
             FROM virtual_devices vd
             JOIN boards b ON b.id = vd.board_id
             JOIN board_types t ON t.id = b.board_type_id
             LEFT JOIN sites s ON s.id = b.site_id
             WHERE vd.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByBoardId(int $boardId): array
    {
        $stmt = $this->db->prepare(
            'SELECT vd.*, b.hardware_id, b.site_id, s.pulse_duration_ms AS site_pulse_duration_ms,
                    t.relay_count, t.wiegand_count, t.has_lora, t.name AS board_type_name
             FROM virtual_devices vd
             JOIN boards b ON b.id = vd.board_id
             JOIN board_types t ON t.id = b.board_type_id
             LEFT JOIN sites s ON s.id = b.site_id
             WHERE vd.board_id = ?
             ORDER BY vd.id ASC'
        );
        $stmt->execute([$boardId]);
        return $stmt->fetchAll();
    }

    public function findUnused(): array
    {
        $stmt = $this->db->query(
            'SELECT vd.*, b.hardware_id, b.friendly_name AS board_friendly_name, b.site_id,
                    t.name AS board_type_name, t.relay_count, t.wiegand_count
             FROM virtual_devices vd
             JOIN boards b ON b.id = vd.board_id
             JOIN board_types t ON t.id = b.board_type_id
             WHERE b.site_id IS NULL AND t.relay_count > 1
             ORDER BY b.hardware_id ASC, vd.id ASC'
        );
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function findMultiRelayBoards(): array
    {
        $stmt = $this->db->query(
            'SELECT b.*, s.name AS site_name, t.name AS board_type_name, t.relay_count, t.wiegand_count, t.has_lora
             FROM boards b
             JOIN board_types t ON t.id = b.board_type_id
             LEFT JOIN sites s ON s.id = b.site_id
             WHERE t.relay_count > 1
             ORDER BY s.name ASC, b.friendly_name ASC, b.hardware_id ASC'
        );
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function relays(int $virtualDeviceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM virtual_device_relays WHERE virtual_device_id = ? ORDER BY relay_number ASC'
        );
        $stmt->execute([$virtualDeviceId]);
        return $stmt->fetchAll();
    }

    public function relayNumbersOnBoard(int $boardId, ?int $exceptDeviceId = null): array
    {
        $sql = 'SELECT r.relay_number
                FROM virtual_device_relays r
                JOIN virtual_devices vd ON vd.id = r.virtual_device_id
                WHERE vd.board_id = ?';
        $params = [$boardId];
        if ($exceptDeviceId !== null) {
            $sql .= ' AND vd.id <> ?';
            $params[] = $exceptDeviceId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $numbers = [];
        foreach ($stmt->fetchAll() as $row) {
            $numbers[] = (int) $row['relay_number'];
        }
        return $numbers;
    }

    public function readerTaken(int $boardId, int $reader, ?int $exceptDeviceId = null): bool
    {
        $sql = 'SELECT id FROM virtual_devices WHERE board_id = ? AND wiegand_reader = ?';
        $params = [$boardId, $reader];
        if ($exceptDeviceId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptDeviceId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }

    /**
     * @param list<array{relay: int, pulse: ?int}> $relays
     */
    public function replaceRelays(int $virtualDeviceId, array $relays): void
    {
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('DELETE FROM virtual_device_relays WHERE virtual_device_id = ?');
            $delete->execute([$virtualDeviceId]);
            $insert = $this->db->prepare(
                'INSERT INTO virtual_device_relays (virtual_device_id, relay_number, pulse_duration_ms) VALUES (?, ?, ?)'
            );
            foreach ($relays as $relay) {
                $insert->execute([$virtualDeviceId, $relay['relay'], $relay['pulse']]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function numbers(int $virtualDeviceId, bool $baseline): array
    {
        $table = $baseline ? 'virtual_device_accesstag_baseline' : 'virtual_device_accesstags';
        $stmt = $this->db->prepare(
            'SELECT accesstag_number FROM ' . $table . ' WHERE virtual_device_id = ? ORDER BY accesstag_number ASC'
        );
        $stmt->execute([$virtualDeviceId]);
        $numbers = [];
        foreach ($stmt->fetchAll() as $row) {
            $numbers[] = (string) $row['accesstag_number'];
        }
        return $numbers;
    }

    public function countNumbers(int $virtualDeviceId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n FROM virtual_device_accesstags WHERE virtual_device_id = ?'
        );
        $stmt->execute([$virtualDeviceId]);
        $row = $stmt->fetch();
        return (int) ($row['n'] ?? 0);
    }

    /**
     * @param list<string> $numbers
     */
    public function replaceNumbers(int $virtualDeviceId, array $numbers, bool $baseline): void
    {
        $table = $baseline ? 'virtual_device_accesstag_baseline' : 'virtual_device_accesstags';
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('DELETE FROM ' . $table . ' WHERE virtual_device_id = ?');
            $delete->execute([$virtualDeviceId]);
            $insert = $this->db->prepare(
                'INSERT INTO ' . $table . ' (virtual_device_id, accesstag_number) VALUES (?, ?)'
            );
            foreach ($numbers as $number) {
                $insert->execute([$virtualDeviceId, $number]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{reads_endpoint: ?string, reads_token: ?string}|null
     */
    public function readsDestination(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT reads_endpoint, reads_token FROM virtual_devices WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'reads_endpoint' => $row['reads_endpoint'] !== null ? (string) $row['reads_endpoint'] : null,
            'reads_token' => $row['reads_token'] !== null ? (string) $row['reads_token'] : null,
        ];
    }

    public function setReadsDestination(int $id, string $endpoint, string $token): bool
    {
        $current = $this->readsDestination($id);
        if ($current === null) {
            return false;
        }
        if ($current['reads_endpoint'] === $endpoint && $current['reads_token'] === $token) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE virtual_devices
             SET reads_endpoint = ?, reads_token = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$endpoint, $token, $id]);

        return true;
    }

    public function setPushedVersion(int $id, string $version): void
    {
        $stmt = $this->db->prepare(
            'UPDATE virtual_devices SET accesstag_list_pushed_version = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$version, $id]);
    }

    public function confirmList(int $id, string $version): void
    {
        $this->db->beginTransaction();
        try {
            $copy = $this->db->prepare(
                'DELETE FROM virtual_device_accesstag_baseline WHERE virtual_device_id = ?'
            );
            $copy->execute([$id]);
            $insert = $this->db->prepare(
                'INSERT INTO virtual_device_accesstag_baseline (virtual_device_id, accesstag_number)
                 SELECT virtual_device_id, accesstag_number FROM virtual_device_accesstags WHERE virtual_device_id = ?'
            );
            $insert->execute([$id]);
            $stmt = $this->db->prepare(
                'UPDATE virtual_devices
                 SET accesstag_list_version = ?, updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            );
            $stmt->execute([$version, $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function create(int $boardId, string $publicId, ?string $friendlyName, ?int $wiegandReader): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO virtual_devices (board_id, public_id, friendly_name, wiegand_reader)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$boardId, $publicId, $friendlyName, $wiegandReader]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, ?string $friendlyName, ?int $wiegandReader): void
    {
        $stmt = $this->db->prepare(
            'UPDATE virtual_devices
             SET friendly_name = ?, wiegand_reader = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$friendlyName, $wiegandReader, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM virtual_devices WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function newPublicId(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $id = 'vd_' . bin2hex(random_bytes(4));
            if ($this->findByPublicId($id) === null) {
                return $id;
            }
        }

        return 'vd_' . bin2hex(random_bytes(8));
    }
}
