<?php

namespace Panda\Storage;

use PDO;

class MySqlAdapter
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Check if the current MySQL connection is secured via TLS.
     */
    public function isTlsSecured(): bool
    {
        $stmt = $this->pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        if ($stmt) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return !empty($row['Value']) && strtolower($row['Value']) !== 'null';
        }
        return false;
    }
}
