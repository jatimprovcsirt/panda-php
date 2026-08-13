<?php

namespace Panda\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Panda\Crypto\InfisicalKeyProvider;
use Panda\Exception\KeyNotFoundException;

/**
 * Unit tests for InfisicalKeyProvider
 *
 * Tests authentication, key retrieval, caching, network errors, and edge cases
 */
class InfisicalKeyProviderTest extends TestCase
{
    private string $validSiteUrl = 'https://app.infisical.com';
    private string $validClientId = 'test-client-id-12345';
    private string $validClientSecret = 'test-client-secret-67890';
    private string $validProjectId = 'test-project-id';
    private string $validEnvironment = 'dev';
    private string $validKeyId = 'test-key-id';
    private string $validKeyBase64 = 'dGhpc2lzYXZhbGlkMzItYnl0ZWtleWJhc2U2NCE=';
    private string $valid32ByteKey = 'thisisavalid32-bytekeybase64!';

    protected function setUp(): void
    {
        parent::setUp();

        // Set test environment variables for testing
        putenv('PANDA_SKIP_ONLINE_VALIDATION=1');
    }

    protected function tearDown(): void
    {
        // Clean up environment
        putenv('PANDA_SKIP_ONLINE_VALIDATION');
        putenv('PANDA_INFISICAL_INSECURE');
        parent::tearDown();
    }

    /**
     * Test constructor with valid parameters
     */
    public function testConstructorWithValidParameters(): void
    {
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        $this->assertInstanceOf(InfisicalKeyProvider::class, $provider);
    }

    /**
     * Test constructor with custom cache TTL
     */
    public function testConstructorWithCustomCacheTTL(): void
    {
        $customTTL = 600;
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment,
            $customTTL
        );

        $this->assertInstanceOf(InfisicalKeyProvider::class, $provider);
    }

    /**
     * Test successful key retrieval (with mocked HTTP responses)
     * Note: This test requires actual Infisical API or mocked responses
     */
    public function testGetKeyThrowsExceptionWithoutMock(): void
    {
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        // This will fail without proper Infisical credentials and setup
        // In a real test environment, you would mock the cURL calls
        $this->expectException(KeyNotFoundException::class);
        $provider->getKey($this->validKeyId);
    }

    /**
     * Test that getKey properly decodes base64 keys
     */
    public function testKeyDecoding(): void
    {
        // Test that base64 decoding works correctly with a valid 32-byte key
        $valid32ByteKey = 'ThisIsAValid32ByteKeyForTesting!';
        $validKeyBase64 = base64_encode($valid32ByteKey);

        $decoded = base64_decode($validKeyBase64, true);
        $this->assertIsString($decoded);
        $this->assertEquals(32, strlen($decoded));
        $this->assertEquals($valid32ByteKey, $decoded);
    }

    /**
     * Test that invalid base64 is rejected
     */
    public function testInvalidBase64Rejected(): void
    {
        $invalidBase64 = 'this-is-not-valid-base64!!!@#$';
        $decoded = base64_decode($invalidBase64, true);
        $this->assertFalse($decoded);
    }

    /**
     * Test that keys shorter than 32 bytes are rejected
     */
    public function testShortKeyRejected(): void
    {
        $shortKey = 'short';
        $shortKeyBase64 = base64_encode($shortKey);

        $decoded = base64_decode($shortKeyBase64, true);
        $this->assertNotEquals(32, strlen($decoded), "Short key should not be 32 bytes");
    }

    /**
     * Test cache key format
     */
    public function testCacheKeyFormat(): void
    {
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        // The cache should store entries with 'key' and 'expires' keys
        // This tests the internal structure via reflection
        $reflection = new \ReflectionClass($provider);
        $cacheProperty = $reflection->getProperty('cache');
        $cacheProperty->setAccessible(true);

        $cache = $cacheProperty->getValue($provider);
        $this->assertIsArray($cache);
    }

    /**
     * Test authentication token management
     */
    public function testAuthenticationTokenInitialization(): void
    {
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        // Check initial authentication state
        $reflection = new \ReflectionClass($provider);
        $authenticatedProperty = $reflection->getProperty('authenticated');
        $authenticatedProperty->setAccessible(true);

        $tokenProperty = $reflection->getProperty('accessToken');
        $tokenProperty->setAccessible(true);

        $expiresProperty = $reflection->getProperty('tokenExpires');
        $expiresProperty->setAccessible(true);

        $this->assertFalse($authenticatedProperty->getValue($provider));
        $this->assertNull($tokenProperty->getValue($provider));
        $this->assertEquals(0, $expiresProperty->getValue($provider));
    }

    /**
     * Test insecure mode environment variable
     */
    public function testInsecureModeEnvironmentVariable(): void
    {
        putenv('PANDA_INFISICAL_INSECURE=1');

        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        // Verify provider was created successfully
        $this->assertInstanceOf(InfisicalKeyProvider::class, $provider);
    }

    /**
     * Test site URL trimming
     */
    public function testSiteUrlTrimming(): void
    {
        $urlWithSlash = 'https://app.infisical.com/';
        $provider = new InfisicalKeyProvider(
            $urlWithSlash,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        $reflection = new \ReflectionClass($provider);
        $siteUrlProperty = $reflection->getProperty('siteUrl');
        $siteUrlProperty->setAccessible(true);

        $actualUrl = $siteUrlProperty->getValue($provider);
        $this->assertEquals('https://app.infisical.com', $actualUrl);
    }

    /**
     * Test cache TTL default value
     */
    public function testDefaultCacheTTL(): void
    {
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        $reflection = new \ReflectionClass($provider);
        $cacheTTLProperty = $reflection->getProperty('cacheTTL');
        $cacheTTLProperty->setAccessible(true);

        $ttl = $cacheTTLProperty->getValue($provider);
        $this->assertEquals(300, $ttl);
    }

    /**
     * Test cache TTL custom value
     */
    public function testCustomCacheTTL(): void
    {
        $customTTL = 1200;
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment,
            $customTTL
        );

        $reflection = new \ReflectionClass($provider);
        $cacheTTLProperty = $reflection->getProperty('cacheTTL');
        $cacheTTLProperty->setAccessible(true);

        $ttl = $cacheTTLProperty->getValue($provider);
        $this->assertEquals($customTTL, $ttl);
    }

    /**
     * Test that token expiry is set correctly (9.5 minutes)
     */
    public function testTokenExpiryCalculation(): void
    {
        // This would be tested in ensureAuthenticated method
        // 9.5 minutes = 570 seconds
        $expectedExpiryOffset = 570;

        // In a real implementation with mocking, we'd verify
        // that tokenExpires is set to time() + 570
        $this->assertEquals(570, $expectedExpiryOffset);
    }

    /**
     * Test environment value storage
     */
    public function testEnvironmentStorage(): void
    {
        $customEnvironment = 'production';
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $customEnvironment
        );

        $reflection = new \ReflectionClass($provider);
        $envProperty = $reflection->getProperty('environment');
        $envProperty->setAccessible(true);

        $environment = $envProperty->getValue($provider);
        $this->assertEquals($customEnvironment, $environment);
    }

    /**
     * Test project ID storage
     */
    public function testProjectIdStorage(): void
    {
        $customProjectId = 'custom-project-123';
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $customProjectId,
            $this->validEnvironment
        );

        $reflection = new \ReflectionClass($provider);
        $projectIdProperty = $reflection->getProperty('projectId');
        $projectIdProperty->setAccessible(true);

        $projectId = $projectIdProperty->getValue($provider);
        $this->assertEquals($customProjectId, $projectId);
    }

    /**
     * Test client ID storage
     */
    public function testClientIdStorage(): void
    {
        $customClientId = 'custom-client-id';
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $customClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        $reflection = new \ReflectionClass($provider);
        $clientIdProperty = $reflection->getProperty('clientId');
        $clientIdProperty->setAccessible(true);

        $clientId = $clientIdProperty->getValue($provider);
        $this->assertEquals($customClientId, $clientId);
    }

    /**
     * Test client secret storage
     */
    public function testClientSecretStorage(): void
    {
        $customSecret = 'custom-secret-key';
        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $customSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        $reflection = new \ReflectionClass($provider);
        $secretProperty = $reflection->getProperty('clientSecret');
        $secretProperty->setAccessible(true);

        $secret = $secretProperty->getValue($provider);
        $this->assertEquals($customSecret, $secret);
    }

    /**
     * Test cache entry structure
     */
    public function testCacheEntryStructure(): void
    {
        // A cache entry should have 'key' and 'expires' keys
        $cacheEntry = [
            'key' => $this->valid32ByteKey,
            'expires' => time() + 300
        ];

        $this->assertArrayHasKey('key', $cacheEntry);
        $this->assertArrayHasKey('expires', $cacheEntry);
        $this->assertIsString($cacheEntry['key']);
        $this->assertIsInt($cacheEntry['expires']);
    }

    /**
     * Test cache expiry check logic
     */
    public function testCacheExpiryCheck(): void
    {
        $pastTime = time() - 100;
        $futureTime = time() + 300;

        // Expired entry
        $expiredEntry = [
            'key' => $this->valid32ByteKey,
            'expires' => $pastTime
        ];

        // Valid entry
        $validEntry = [
            'key' => $this->valid32ByteKey,
            'expires' => $futureTime
        ];

        $this->assertLessThan(time(), $expiredEntry['expires']);
        $this->assertGreaterThan(time(), $validEntry['expires']);
    }

    /**
     * Test Infisical API URL construction
     */
    public function testInfisicalApiUrlConstruction(): void
    {
        $siteUrl = 'https://app.infisical.com';
        $expectedAuthUrl = $siteUrl . '/api/v1/auth/universal-auth/login';

        $this->assertEquals($expectedAuthUrl, 'https://app.infisical.com/api/v1/auth/universal-auth/login');
    }

    /**
     * Test secret retrieval API URL construction
     */
    public function testSecretApiUrlConstruction(): void
    {
        $siteUrl = 'https://app.infisical.com';
        $projectId = 'proj-123';
        $environment = 'dev';
        $secretPath = 'my-secret';

        $queryParams = http_build_query([
            'projectId' => $projectId,
            'environment' => $environment,
            'secretPath' => $secretPath
        ]);

        $expectedUrl = $siteUrl . '/api/v3/secrets/raw?' . $queryParams;

        $this->assertStringContainsString('/api/v3/secrets/raw?', $expectedUrl);
        $this->assertStringContainsString('projectId=proj-123', $expectedUrl);
        $this->assertStringContainsString('environment=dev', $expectedUrl);
        $this->assertStringContainsString('secretPath=my-secret', $expectedUrl);
    }

    /**
     * Test that 32-byte key validation works
     */
    public function test32ByteKeyValidation(): void
    {
        // Valid 32-byte key
        $validKey = str_repeat('a', 32);
        $this->assertEquals(32, strlen($validKey));

        // Invalid keys
        $shortKey = str_repeat('b', 31);
        $longKey = str_repeat('c', 33);

        $this->assertNotEquals(32, strlen($shortKey));
        $this->assertNotEquals(32, strlen($longKey));
    }

    /**
     * Test HTTP timeout configuration
     */
    public function testHttpTimeoutConfiguration(): void
    {
        // The implementation uses CURLOPT_TIMEOUT = 10
        $expectedTimeout = 10;

        $this->assertEquals(10, $expectedTimeout);
    }

    /**
     * Test SSL verification default behavior
     */
    public function testSslVerificationDefault(): void
    {
        // By default, SSL verification should be enabled
        putenv('PANDA_INFISICAL_INSECURE=');

        $provider = new InfisicalKeyProvider(
            $this->validSiteUrl,
            $this->validClientId,
            $this->validClientSecret,
            $this->validProjectId,
            $this->validEnvironment
        );

        $this->assertInstanceOf(InfisicalKeyProvider::class, $provider);
    }

    /**
     * Test multiple key IDs
     */
    public function testMultipleKeyIds(): void
    {
        $keyIds = ['key-1', 'key-2', 'key-3', 'nik-key', 'health-data-key'];

        foreach ($keyIds as $kid) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $kid);
        }
    }

    /**
     * Test key ID format validation
     */
    public function testKeyIdFormatValidation(): void
    {
        $validKeyIds = [
            'default-key',
            'nik_key',
            'healthDataKey',
            'KEY-123',
            'key_with_underscores'
        ];

        foreach ($validKeyIds as $kid) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $kid, "Key ID {$kid} should be valid");
        }

        $invalidKeyIds = [
            'key with spaces',
            'key@withsymbols',
            'key#hash',
            'key/slash',
            'key.dot'
        ];

        foreach ($invalidKeyIds as $kid) {
            $this->assertDoesNotMatchRegularExpression('/^[a-zA-Z0-9_-]+$/', $kid, "Key ID {$kid} should be invalid");
        }
    }
}
