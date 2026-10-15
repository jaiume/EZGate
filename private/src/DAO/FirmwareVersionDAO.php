<?php

declare(strict_types=1);

namespace App\DAO;

class FirmwareVersionDAO extends AbstractDAO
{
    public function findAllWithCounts(): array
    {
        $stmt = $this->db->query(
            'SELECT fv.*, bt.name AS board_type_name,
                    (SELECT COUNT(*) FROM boards b WHERE b.reported_firmware_version = fv.version AND b.board_type_id = fv.board_type_id) AS running_count,
                    (SELECT COUNT(*) FROM boards b
                      WHERE b.target_firmware_version_id = fv.id
                        AND b.board_type_id = fv.board_type_id
                        AND (b.reported_firmware_version IS NULL OR b.reported_firmware_version <> fv.version)
                    ) AS pending_count
             FROM firmware_versions fv
             JOIN board_types bt ON bt.id = fv.board_type_id
             ORDER BY fv.created_at DESC, fv.id DESC'
        );
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function findAll(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM firmware_versions ORDER BY created_at DESC, id DESC'
        );
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM firmware_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByVersion(string $version): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM firmware_versions WHERE version = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$version]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByVersionAndType(string $version, int $boardTypeId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM firmware_versions WHERE version = ? AND board_type_id = ? LIMIT 1'
        );
        $stmt->execute([$version, $boardTypeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(
        int $boardTypeId,
        string $version,
        ?string $notes,
        string $checksum,
        string $originalFilename,
        string $storedFilename,
        int $fileSize
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO firmware_versions
                (board_type_id, version, notes, checksum, original_filename, stored_filename, file_size, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$boardTypeId, $version, $notes, $checksum, $originalFilename, $storedFilename, $fileSize]);
        return (int) $this->db->lastInsertId();
    }

    public function updateFile(
        int $id,
        string $checksum,
        string $originalFilename,
        string $storedFilename,
        int $fileSize
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE firmware_versions
             SET checksum = ?, original_filename = ?, stored_filename = ?, file_size = ?
             WHERE id = ?'
        );
        $stmt->execute([$checksum, $originalFilename, $storedFilename, $fileSize, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM firmware_versions WHERE id = ?');
        $stmt->execute([$id]);
    }
}
