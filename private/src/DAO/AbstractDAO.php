<?php

declare(strict_types=1);

namespace App\DAO;

class AbstractDAO
{
    public function __construct(protected ManagedPdo $db)
    {
    }

    public function setLongRunning(bool $longRunning): void
    {
        $this->db->setLongRunning($longRunning);
    }

    public function whileLongRunning(callable $fn): mixed
    {
        $this->setLongRunning(true);
        try {
            return $fn();
        } finally {
            $this->setLongRunning(false);
        }
    }
}
