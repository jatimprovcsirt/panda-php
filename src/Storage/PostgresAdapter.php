<?php

namespace Panda\Storage;

use PDO;

class PostgresAdapter
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Check if the current PostgreSQL connection is secured via TLS.
     */
    public function isTlsSecured(): bool
    {
        $stmt = $this->pdo->query("SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()");
        if ($stmt) {
            $val = $stmt->fetchColumn();
            return (bool)$val;
        }
        return false;
    }
}
