<?php

namespace Panda\Crypto;

use Panda\Exception\KeyNotFoundException;

/**
 * VaultKeyProvider fetches encryption keys from HashiCorp Vault KV v2
 *
 * Supports two authentication methods:
 * 1. Token-based authentication (vaultToken)
 * 2. AppRole authentication (roleId + secretId with automatic token renewal)
 *
 * Keys are cached in memory with a configurable TTL to reduce API calls.
 */
class VaultKeyProvider implements KeyProvider
{
    private string $vaultUrl;
    private ?string $vaultToken;
    private string $mountPath;
    private string $namespace;
    private string $authMethod;

    // AppRole-based auth
    private ?string $roleId;
    private ?string $secretId;
    private ?string $currentToken;
    private int $tokenExpires;

    // Caching
    private array $cache = [];
    private int $cacheTTL;
    private int $tokenTTL;

    /**
     * Constructor with support for both token and AppRole authentication
     *
     * @param array|string $config Configuration array or vault URL (legacy support)
     * @param string|null $vaultToken Vault token (legacy support)
     * @param string|null $mountPath KV mount path (legacy support)
     */
    public function __construct($config, ?string $vaultToken = null, ?string $mountPath = null)
    {
        // Support legacy constructor: (vaultUrl, vaultToken, mountPath)
        if (is_string($config)) {
            $this->initFromLegacyConstructor($config, $vaultToken, $mountPath);
            return;
        }

        // New config-based constructor
        $this->initFromConfig($config);
    }

    /**
     * Initialize from legacy constructor parameters
     */
    private function initFromLegacyConstructor(string $vaultUrl, ?string $vaultToken, ?string $mountPath): void
    {
        $this->vaultUrl = rtrim($vaultUrl, '/');
        $this->vaultToken = $vaultToken ?? '';
        $this->mountPath = trim($mountPath ?? 'secret', '/');
        $this->namespace = '';
        $this->authMethod = 'token';
        $this->roleId = null;
        $this->secretId = null;
        $this->currentToken = null;
        $this->tokenExpires = 0;
        $this->cacheTTL = 300; // 5 minutes default
        $this->tokenTTL = 3600; // 1 hour default

        if (empty($this->vaultToken)) {
            throw new \InvalidArgumentException('VaultKeyProvider with token auth requires vaultToken');
        }
    }

    /**
     * Initialize from configuration array
     */
    private function initFromConfig(array $config): void
    {
        $this->vaultUrl = rtrim($config['vaultUrl'] ?? '', '/');
        $this->vaultToken = $config['vaultToken'] ?? null;
        $this->mountPath = trim($config['mountPath'] ?? 'secret', '/');
        $this->namespace = $config['namespace'] ?? '';
        $this->cacheTTL = (int)($config['cacheTTL'] ?? 300);
        $this->tokenTTL = (int)($config['tokenTTL'] ?? 3600);

        // Determine auth method
        $this->roleId = $config['roleId'] ?? null;
        $this->secretId = $config['secretId'] ?? null;

        if ($this->roleId && $this->secretId) {
            $this->authMethod = 'approle';
        } else {
            $this->authMethod = 'token';
        }

        $this->currentToken = null;
        $this->tokenExpires = 0;

        // Validate configuration based on auth method
        if ($this->authMethod === 'token' && empty($this->vaultToken)) {
            throw new \InvalidArgumentException('VaultKeyProvider with token auth requires vaultToken');
        }
        if ($this->authMethod === 'approle' && (!$this->roleId || !$this->secretId)) {
            throw new \InvalidArgumentException('VaultKeyProvider with AppRole auth requires roleId and secretId');
        }
    }

    /**
     * Ensures we have a valid Vault token
     *
     * @return string Valid Vault token
     * @throws KeyNotFoundException if authentication fails
     */
    private function ensureToken(): string
    {
        // If using token-based auth, use the provided token
        if ($this->authMethod === 'token') {
            return $this->vaultToken;
        }

        // If using AppRole, check if we need to fetch/renew token
        if ($this->currentToken && time() < $this->tokenExpires) {
            return $this->currentToken;
        }

        // Fetch new AppRole token
        return $this->fetchAppRoleToken();
    }

    /**
     * Fetches a new Vault token using AppRole authentication
     *
     * @return string New Vault token
     * @throws KeyNotFoundException if authentication fails
     */
    private function fetchAppRoleToken(): string
    {
        $url = "{$this->vaultUrl}/v1/auth/approle/login";
        $payload = json_encode([
            'role_id' => $this->roleId,
            'secret_id' => $this->secretId
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        if (getenv('PANDA_VAULT_INSECURE') === '1') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new KeyNotFoundException("AppRole authentication failed (HTTP {$httpCode})");
        }

        $data = json_decode($response, true);
        $this->currentToken = $data['auth']['client_token'] ?? null;

        if (!$this->currentToken) {
            throw new KeyNotFoundException('No client_token in AppRole response');
        }

        // Set token expiry (use token TTL if provided, otherwise default to lease duration)
        $leaseDuration = $data['auth']['lease_duration'] ?? $this->tokenTTL;
        // Refresh 5 minutes (300 seconds) before expiry
        $this->tokenExpires = time() + ($leaseDuration - 300);

        return $this->currentToken;
    }

    /**
     * Checks if a cached key is still valid
     *
     * @param string $kid Key identifier
     * @return bool True if cached key is valid
     */
    private function isCachedKeyValid(string $kid): bool
    {
        if (!isset($this->cache[$kid])) {
            return false;
        }

        $cached = $this->cache[$kid];
        return isset($cached['expires']) && $cached['expires'] > time();
    }

    public function getKey(string $kid): string
    {
        // Check cache first (with TTL)
        if ($this->isCachedKeyValid($kid)) {
            return $this->cache[$kid]['key'];
        }

        // Ensure we have a valid token
        $token = $this->ensureToken();

        // Build the Vault API URL for KV v2
        $path = $this->namespace
            ? "{$this->namespace}/{$this->mountPath}/data/{$kid}"
            : "{$this->mountPath}/data/{$kid}";
        $url = "{$this->vaultUrl}/v1/{$path}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Vault-Token: {$token}",
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        if (getenv('PANDA_VAULT_INSECURE') === '1') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 404) {
            throw new KeyNotFoundException("Key '{$kid}' not found in Vault");
        }

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

        // Cache the key with TTL
        $this->cache[$kid] = [
            'key' => $decoded,
            'expires' => time() + $this->cacheTTL
        ];

        return $decoded;
    }
}

