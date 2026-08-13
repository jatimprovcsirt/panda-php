<?php

namespace Panda\Crypto;

use Panda\Exception\KeyNotFoundException;

/**
 * InfisicalKeyProvider fetches encryption keys from Infisical
 *
 * This provider uses Universal Auth (Machine Identity credentials) to authenticate
 * with Infisical and retrieve encryption keys. Keys are cached in memory with a
 * configurable TTL to reduce API calls.
 */
class InfisicalKeyProvider implements KeyProvider
{
    private string $siteUrl;
    private string $clientId;
    private string $clientSecret;
    private string $projectId;
    private string $environment;
    private int $cacheTTL;
    private array $cache = [];
    private bool $authenticated = false;
    private ?string $accessToken = null;
    private int $tokenExpires = 0;

    /**
     * InfisicalKeyProvider constructor
     *
     * @param string $siteUrl Infisical site URL (e.g., https://app.infisical.com)
     * @param string $clientId Machine Identity Client ID
     * @param string $clientSecret Machine Identity Client Secret
     * @param string $projectId Infisical Project ID
     * @param string $environment Environment name (dev, prod, etc.)
     * @param int $cacheTTL Cache TTL in seconds (default: 300)
     */
    public function __construct(
        string $siteUrl,
        string $clientId,
        string $clientSecret,
        string $projectId,
        string $environment,
        int $cacheTTL = 300
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->projectId = $projectId;
        $this->environment = $environment;
        $this->cacheTTL = $cacheTTL;
    }

    /**
     * Ensures we have a valid access token for Infisical API
     *
     * @throws KeyNotFoundException if authentication fails
     */
    private function ensureAuthenticated(): void
    {
        // Check if we have a valid cached token
        if ($this->authenticated && $this->accessToken !== null && time() < $this->tokenExpires) {
            return;
        }

        $authUrl = $this->siteUrl . '/api/v1/auth/universal-auth/login';
        $payload = json_encode([
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret
        ]);

        $ch = curl_init($authUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // Verify SSL by default
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // Allow insecure connections if explicitly configured (not recommended for production)
        if (getenv('PANDA_INFISICAL_INSECURE') === '1') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            throw new KeyNotFoundException(
                "Infisical authentication failed (HTTP {$httpCode}): {$error}"
            );
        }

        $data = json_decode($response, true);
        if (!isset($data['accessToken'])) {
            throw new KeyNotFoundException('Failed to parse Infisical auth response');
        }

        $this->accessToken = $data['accessToken'];
        // Token expires in 9.5 minutes (Infisical tokens are 10min)
        $this->tokenExpires = time() + 570; // 9.5 minutes
        $this->authenticated = true;
    }

    /**
     * Retrieve the 32-byte encryption key for the given key identifier
     *
     * @param string $kid Key identifier
     * @return string Raw 32-byte key material
     * @throws KeyNotFoundException if key is not found or invalid
     */
    public function getKey(string $kid): string
    {
        // Check cache first
        if (isset($this->cache[$kid]) && $this->cache[$kid]['expires'] > time()) {
            return $this->cache[$kid]['key'];
        }

        // Ensure we have a valid access token
        $this->ensureAuthenticated();

        // Build API URL for fetching secret
        $queryParams = http_build_query([
            'projectId' => $this->projectId,
            'environment' => $this->environment,
            'secretPath' => $kid
        ]);
        $apiUrl = $this->siteUrl . '/api/v3/secrets/raw?' . $queryParams;

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // Verify SSL by default
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // Allow insecure connections if explicitly configured
        if (getenv('PANDA_INFISICAL_INSECURE') === '1') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            throw new KeyNotFoundException(
                "Could not retrieve key '{$kid}' from Infisical (HTTP {$httpCode}): {$error}"
            );
        }

        $data = json_decode($response, true);
        $secretValue = $data['secretValue'] ?? $data['secret'] ?? null;

        if ($secretValue === null) {
            throw new KeyNotFoundException("Key '{$kid}' not found in Infisical response");
        }

        $decoded = base64_decode($secretValue, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new KeyNotFoundException(
                "Key '{$kid}' is not a valid 32-byte base64 string"
            );
        }

        // Cache the key
        $this->cache[$kid] = [
            'key' => $decoded,
            'expires' => time() + $this->cacheTTL
        ];

        return $decoded;
    }
}
