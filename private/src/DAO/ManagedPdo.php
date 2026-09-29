<?php

declare(strict_types=1);

namespace App\DAO;

use PDO;
use PDOException;
use PDOStatement;

class ManagedPdo
{
    private ?PDO $pdo = null;
    private int $longRunningDepth = 0;

    public function __construct(
        private string $dsn,
        private ?string $user = null,
        private ?string $password = null,
        private ?array $options = null
    ) {
    }

    public function setLongRunning(bool $longRunning): void
    {
        if ($longRunning) {
            $this->longRunningDepth++;
            $this->closeIfIdle();
            return;
        }

        $this->longRunningDepth = max(0, $this->longRunningDepth - 1);
        if ($this->longRunningDepth > 0) {
            $this->closeIfIdle();
        }
    }

    public function isLongRunning(): bool
    {
        return $this->longRunningDepth > 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->connect()->prepare($query, $options);
    }

    public function query(string $query): PDOStatement|false
    {
        return $this->connect()->query($query);
    }

    public function exec(string $statement): int|false
    {
        return $this->connect()->exec($statement);
    }

    public function beginTransaction(): bool
    {
        return $this->connect()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->requireConnection()->commit();
    }

    public function rollBack(): bool
    {
        return $this->requireConnection()->rollBack();
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->requireConnection()->lastInsertId($name);
    }

    public function inTransaction(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        return $this->pdo->inTransaction();
    }

    private function connect(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, $this->options);
            $this->pdo->exec("SET time_zone = '+00:00'");
        }

        return $this->pdo;
    }

    private function requireConnection(): PDO
    {
        if ($this->pdo === null) {
            throw new PDOException('No active database connection');
        }

        return $this->pdo;
    }

    private function closeIfIdle(): void
    {
        if ($this->pdo !== null && !$this->pdo->inTransaction()) {
            $this->pdo = null;
        }
    }
}
