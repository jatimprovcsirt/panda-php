<?php

namespace Panda\Crypto;

use Panda\Exception\KeyNotFoundException;

class LocalKeyProvider implements KeyProvider
{
    private array $keys = [];

    public function __construct(array $keys = [])
    {
        $this->keys = $keys;
    }

    /**
     * Set a key directly in memory.
     */
    public function setKey(string $kid, string $keyBytes): void
    {
        $this->keys[$kid] = $keyBytes;
    }

    public function getKey(string $kid): string
    {
        // 1. Check in-memory array first
        if (isset($this->keys[$kid])) {
            return $this->keys[$kid];
        }

        // 2. Normalize kid for env variable (e.g. opd-dukcapil-key-1 -> PANDA_KEY_OPD_DUKCAPIL_KEY_1)
        $normalizedKid = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $kid));
        $envKeyName = "PANDA_KEY_" . $normalizedKid;

        // Try direct getenv first, then normalized
        $val = getenv("PANDA_KEY_" . $kid) ?: getenv($envKeyName);
        if (!$val && isset($_ENV)) {
            $val = $_ENV["PANDA_KEY_" . $kid] ?? $_ENV[$envKeyName] ?? null;
        }
        if (!$val && isset($_SERVER)) {
            $val = $_SERVER["PANDA_KEY_" . $kid] ?? $_SERVER[$envKeyName] ?? null;
        }

        if (!$val) {
            throw new KeyNotFoundException("Encryption key not found for kid: {$kid} (expected env variable: {$envKeyName})");
        }

        $decoded = base64_decode($val, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new KeyNotFoundException("Invalid key format for kid: {$kid}. Key must be base64-encoded and decode to exactly 32 bytes.");
        }

        return $decoded;
    }
}
