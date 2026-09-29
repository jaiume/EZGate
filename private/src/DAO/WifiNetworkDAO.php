<?php

declare(strict_types=1);

namespace App\DAO;

class WifiNetworkDAO extends AbstractDAO
{
    public function findAll(): array
    {
        $stmt = $this->db->query(
            'SELECT id, site_id, ssid, security, created_at FROM site_wifi_networks ORDER BY ssid ASC'
        );
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function findBySiteId(int $siteId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, site_id, ssid, password, security, created_at FROM site_wifi_networks WHERE site_id = ? ORDER BY ssid ASC'
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM site_wifi_networks WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBySiteAndSsid(int $siteId, string $ssid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM site_wifi_networks WHERE site_id = ? AND ssid = ? LIMIT 1'
        );
        $stmt->execute([$siteId, $ssid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $siteId, string $ssid, string $password, string $security): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO site_wifi_networks (site_id, ssid, password, security) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$siteId, $ssid, $password, $security]);
        return (int) $this->db->lastInsertId();
    }

    public function updateCredentials(int $id, string $password, string $security): void
    {
        $stmt = $this->db->prepare(
            'UPDATE site_wifi_networks SET password = ?, security = ? WHERE id = ?'
        );
        $stmt->execute([$password, $security, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM site_wifi_networks WHERE id = ?');
        $stmt->execute([$id]);
    }
}
