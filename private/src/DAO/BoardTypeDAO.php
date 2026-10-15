<?php

declare(strict_types=1);

namespace App\DAO;

class BoardTypeDAO extends AbstractDAO
{
    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM board_types WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM board_types WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM board_types ORDER BY name ASC');
        return $stmt === false ? [] : $stmt->fetchAll();
    }
}
