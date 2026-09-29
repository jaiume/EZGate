<?php

declare(strict_types=1);

namespace App\DAO;

class SiteDAO extends AbstractDAO
{
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM sites ORDER BY name ASC');
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sites WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $name): int
    {
        $stmt = $this->db->prepare('INSERT INTO sites (name) VALUES (?)');
        $stmt->execute([$name]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, ?int $pulseDurationMs): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sites SET name = ?, pulse_duration_ms = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$name, $pulseDurationMs, $id]);
    }

    public function ensureLoraKey(int $id, string $keyHex): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sites
             SET lora_key = COALESCE(lora_key, ?), updated_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$keyHex, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sites WHERE id = ?');
        $stmt->execute([$id]);
    }
}
