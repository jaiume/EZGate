<?php

declare(strict_types=1);

namespace App\DAO;

class OpenCommandDAO extends AbstractDAO
{
    /**
     * @return array{id: int, created: bool}
     */
    public function queue(int $boardId, int $virtualDeviceId, int $ttlSeconds): array
    {
        $this->expireOld();
        $existing = $this->findNewestInFlight($virtualDeviceId);
        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->expireOlderPending($virtualDeviceId, $id);
            return ['id' => $id, 'created' => false];
        }

        $ttlSeconds = max(1, $ttlSeconds);
        $stmt = $this->db->prepare(
            'INSERT INTO open_commands (board_id, virtual_device_id, status, expires_at)
             VALUES (?, ?, \'pending\', DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))'
        );
        $stmt->execute([$boardId, $virtualDeviceId, $ttlSeconds]);
        $id = (int) $this->db->lastInsertId();
        $this->expireOlderPending($virtualDeviceId, $id);
        return ['id' => $id, 'created' => true];
    }

    /**
     * Newest in-flight command for each virtual device on the board.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForBoard(int $boardId): array
    {
        $this->expireOld();
        $stmt = $this->db->prepare(
            'SELECT * FROM open_commands
             WHERE board_id = ? AND status = \'pending\'
               AND virtual_device_id IS NOT NULL
               AND expires_at IS NOT NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC'
        );
        $stmt->execute([$boardId]);
        $kept = [];
        foreach ($stmt->fetchAll() as $row) {
            $deviceId = (int) $row['virtual_device_id'];
            if (isset($kept[$deviceId])) {
                continue;
            }
            $kept[$deviceId] = $row;
            $this->expireOlderPending($deviceId, (int) $row['id']);
        }
        return array_values($kept);
    }

    public function markDelivered(int $id, int $boardId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE open_commands
             SET delivered_at = COALESCE(delivered_at, UTC_TIMESTAMP())
             WHERE id = ? AND board_id = ? AND status = \'pending\''
        );
        $stmt->execute([$id, $boardId]);
    }

    public function findLatestForBoard(int $boardId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM open_commands WHERE board_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$boardId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markCompleted(int $id, int $boardId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE open_commands
             SET status = \'completed\',
                 delivered_at = COALESCE(delivered_at, UTC_TIMESTAMP()),
                 completed_at = UTC_TIMESTAMP()
             WHERE id = ? AND board_id = ? AND status = \'pending\''
        );
        $stmt->execute([$id, $boardId]);
        return $stmt->rowCount() > 0;
    }

    private function findNewestInFlight(int $virtualDeviceId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM open_commands
             WHERE virtual_device_id = ? AND status = \'pending\'
               AND expires_at IS NOT NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$virtualDeviceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function expireOlderPending(int $virtualDeviceId, int $keepId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE open_commands
             SET status = \'expired\', completed_at = UTC_TIMESTAMP()
             WHERE virtual_device_id = ? AND status = \'pending\' AND id < ?'
        );
        $stmt->execute([$virtualDeviceId, $keepId]);
    }

    private function expireOld(): void
    {
        $this->db->exec(
            "UPDATE open_commands
             SET status = 'expired', completed_at = UTC_TIMESTAMP()
             WHERE status = 'pending'
               AND (expires_at IS NULL OR expires_at <= UTC_TIMESTAMP())"
        );
    }
}
