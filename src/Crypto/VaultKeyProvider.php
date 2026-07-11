<?php

namespace Panda\Crypto;

use Panda\Exception\KeyNotFoundException;

class VaultKeyProvider implements KeyProvider
{
    private string $vaultUrl;
    private string $vaultToken;
    private string $mountPath; // e.g. "secret"
    private array $cache = [];

    public function __construct(string $vaultUrl, string $vaultToken, string $mountPath = 'secret')
    {
        $this->vaultUrl = rtrim($vaultUrl, '/');
        $this->vaultToken = $vaultToken;
        $this->mountPath = trim($mountPath, '/');
    }

    public function getKey(string $kid): string
    {
        if (isset($this->cache[$kid])) {
            return $this->cache[$kid];
        }

        // Vault KV v2 API path: /v1/{mount}/data/{kid}
        // It expects JSON response: {"data": {"data": {"key": "base64..."}}}
        $url = "{$this->vaultUrl}/v1/{$this->mountPath}/data/{$kid}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Vault-Token: {$this->vaultToken}",
            "Accept: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        // In government environments, self-signed certificates are common.
        // We will default to verifying, but if needed, verification can be disabled via environment.
        if (getenv('PANDA_VAULT_INSECURE') === '1') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new KeyNotFoundException("Could not retrieve key '{$kid}' from Vault (HTTP status code: {$httpCode})");
        }

        $data = json_decode($response, true);
        $keyVal = $data['data']['data']['key'] ?? $data['data']['data']['value'] ?? null;

        if (!$keyVal) {
            throw new KeyNotFoundException("Key '{$kid}' not found in Vault response data structure.");
        }

        $decoded = base64_decode($keyVal, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new KeyNotFoundException("Key '{$kid}' retrieved from Vault is not a valid 32-byte base64 string.");
        }

        $this->cache[$kid] = $decoded;
        return $decoded;
    }
}
