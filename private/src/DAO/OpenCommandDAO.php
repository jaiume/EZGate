<?php

declare(strict_types=1);

namespace App\DAO;

class OpenCommandDAO extends AbstractDAO
{
    /**
     * @return array{id: int, created: bool}
     */
    public function queue(int $boardId, int $ttlSeconds): array
    {
        $this->expireOld();
        $existing = $this->findNewestInFlight($boardId);
        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->expireOlderPending($boardId, $id);
            return ['id' => $id, 'created' => false];
        }

        $ttlSeconds = max(1, $ttlSeconds);
        $stmt = $this->db->prepare(
            'INSERT INTO open_commands (board_id, status, expires_at)
             VALUES (?, \'pending\', DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))'
        );
        $stmt->execute([$boardId, $ttlSeconds]);
        $id = (int) $this->db->lastInsertId();
        $this->expireOlderPending($boardId, $id);
        return ['id' => $id, 'created' => true];
    }

    public function findPendingForBoard(int $boardId): ?array
    {
        $this->expireOld();
        $stmt = $this->db->prepare(
            'SELECT * FROM open_commands
             WHERE board_id = ? AND status = \'pending\'
               AND delivered_at IS NULL
               AND expires_at IS NOT NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$boardId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $this->expireOlderPending($boardId, (int) $row['id']);
        return $row;
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

    private function findNewestInFlight(int $boardId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM open_commands
             WHERE board_id = ? AND status = \'pending\'
               AND expires_at IS NOT NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$boardId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function expireOlderPending(int $boardId, int $keepId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE open_commands
             SET status = \'expired\', completed_at = UTC_TIMESTAMP()
             WHERE board_id = ? AND status = \'pending\' AND id < ?'
        );
        $stmt->execute([$boardId, $keepId]);
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
