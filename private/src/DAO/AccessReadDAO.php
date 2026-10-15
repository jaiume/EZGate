<?php

declare(strict_types=1);

namespace App\DAO;

class AccessReadDAO extends AbstractDAO
{
    /**
     * @return list<array<string, mixed>>
     */
    public function matchingEvent(int $boardId, int $boardEventId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ar.*, vd.public_id
             FROM access_reads ar
             JOIN virtual_devices vd ON vd.id = ar.virtual_device_id
             WHERE vd.board_id = ? AND ar.board_event_id = ?'
        );
        $stmt->execute([$boardId, $boardEventId]);
        return $stmt->fetchAll();
    }

    public function insert(
        int $virtualDeviceId,
        string $accesstagNumber,
        ?string $readAt,
        bool $inList,
        ?int $boardEventId
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO access_reads (virtual_device_id, accesstag_number, read_at, in_list, board_event_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $virtualDeviceId,
            $accesstagNumber,
            $readAt,
            $inList ? 1 : 0,
            $boardEventId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function pendingForBoard(int $boardId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ar.*, vd.public_id
             FROM access_reads ar
             JOIN virtual_devices vd ON vd.id = ar.virtual_device_id
             WHERE vd.board_id = ?
             ORDER BY ar.id ASC'
        );
        $stmt->execute([$boardId]);
        return $stmt->fetchAll();
    }

    public function pendingAll(): array
    {
        $stmt = $this->db->query(
            'SELECT ar.*, vd.public_id
             FROM access_reads ar
             JOIN virtual_devices vd ON vd.id = ar.virtual_device_id
             ORDER BY ar.id ASC'
        );
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /**
     * @param list<int> $ids
     */
    public function deleteIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare('DELETE FROM access_reads WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
    }
}
