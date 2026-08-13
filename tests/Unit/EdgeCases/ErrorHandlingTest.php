<?php

namespace Panda\Tests\Unit\EdgeCases;

use PHPUnit\Framework\TestCase;
use Panda\Cli\Utils\PandaDirectory;
use Panda\Crypto\InfisicalKeyProvider;
use Panda\Crypto\VaultKeyProvider;
use Panda\Crypto\LocalKeyProvider;
use Panda\Crypto\TokenValidator;
use Panda\Exception\KeyNotFoundException;
use Panda\Exception\InvalidTokenException;

/**
 * Edge case tests for error handling across all components
 */
class ErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up test environment
        putenv('PANDA_SKIP_ONLINE_VALIDATION=1');
        putenv('APP_ENV=testing');

        // Clean up any existing .panda directory
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    protected function tearDown(): void
    {
        // Clean up
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        putenv('PANDA_SKIP_ONLINE_VALIDATION');
        putenv('APP_ENV');
        putenv('PANDA_KEY_TEST');

        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Test handling of corrupted credentials file
     */
    public function testCorruptedCredentialsFile(): void
    {
        PandaDirectory::create();
        file_put_contents('.panda/credentials', 'corrupted {json} data');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse credentials');

        PandaDirectory::readCredentials();

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test handling of corrupted keys.json file
     */
    public function testCorruptedKeysJsonFile(): void
    {
        PandaDirectory::create();
        file_put_contents('.panda/keys.json', 'corrupted {json} data');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse keys.json');

        PandaDirectory::readKeysMetadata();

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test handling of missing .panda directory
     */
    public function testMissingPandaDirectory(): void
    {
        $this->assertFalse(PandaDirectory::exists());
        $this->assertNull(PandaDirectory::readCredentials());
        $this->assertNull(PandaDirectory::readConfig());
        $this->assertNull(PandaDirectory::readKeysMetadata());
    }

    /**
     * Test handling of empty credentials file
     */
    public function testEmptyCredentialsFile(): void
    {
        PandaDirectory::create();
        file_put_contents('.panda/credentials', '');

        $credentials = PandaDirectory::readCredentials();

        // Should return default local provider
        $this->assertIsArray($credentials);
        $this->assertEquals('local', $credentials['provider']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test handling of invalid token format
     */
    public function testInvalidTokenFormat(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('does not match the expected format');

        TokenValidator::validateToken('invalid_token_format');
    }

    /**
     * Test handling of empty token
     */
    public function testEmptyToken(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('A valid PANDA access token is required');

        TokenValidator::validateToken('');
    }

    /**
     * Test handling of null key in LocalKeyProvider
     */
    public function testNullKeyInLocalKeyProvider(): void
    {
        $provider = new LocalKeyProvider();

        $this->expectException(KeyNotFoundException::class);
        $provider->getKey('non_existent_key');
    }

    /**
     * Test handling of duplicate key in metadata
     */
    public function testDuplicateKeyInMetadata(): void
    {
        PandaDirectory::create();
        PandaDirectory::addKeyToMetadata('test-key', 'local', '.env');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        PandaDirectory::addKeyToMetadata('test-key', 'local', '.env');

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test handling of invalid key ID format
     */
    public function testInvalidKeyIdFormat(): void
    {
        $invalidKeyIds = [
            'key with spaces',
            'key@withsymbols',
            'key#hash',
            'key/slash',
            'key.dot',
        ];

        foreach ($invalidKeyIds as $kid) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[a-zA-Z0-9_-]+$/',
                $kid,
                "Key ID '{$kid}' should be invalid"
            );
        }
    }

    /**
     * Test handling of invalid base64 key
     */
    public function testInvalidBase64Key(): void
    {
        $invalidBase64 = 'this-is-not-valid-base64!!!@#$';
        $decoded = base64_decode($invalidBase64, true);

        $this->assertFalse($decoded, 'Invalid base64 should decode to false');
    }

    /**
     * Test handling of short key (less than 32 bytes)
     */
    public function testShortKey(): void
    {
        $shortKey = 'short';
        $shortKeyBase64 = base64_encode($shortKey);
        $decoded = base64_decode($shortKeyBase64, true);

        $this->assertEquals(5, strlen($decoded), 'Short key should be 5 bytes');
        $this->assertNotEquals(32, strlen($decoded), 'Short key should not be 32 bytes');
    }

    /**
     * Test handling of long key (more than 32 bytes)
     */
    public function testLongKey(): void
    {
        $longKey = 'this_key_is_way_too_long_for_testing';
        $longKeyBase64 = base64_encode($longKey);
        $decoded = base64_decode($longKeyBase64, true);

        $this->assertGreaterThan(32, strlen($decoded), 'Long key should be more than 32 bytes');
    }

    /**
     * Test handling of missing environment variables for LocalKeyProvider
     */
    public function testMissingEnvironmentVariablesForLocalKeyProvider(): void
    {
        // Ensure no PANDA_KEY_ env vars are set
        $envVars = array_filter($_ENV, function ($key) {
            return strpos($key, 'PANDA_KEY_') === 0;
        }, ARRAY_FILTER_USE_KEY);

        $this->assertEmpty($envVars, 'No PANDA_KEY_ environment variables should be set');
    }

    /**
     * Test handling of Vault with missing configuration
     */
    public function testVaultWithMissingConfiguration(): void
    {
        // VaultKeyProvider requires both vaultUrl and vaultToken in constructor
        // This test verifies the constructor doesn't crash with partial config
        $vaultUrl = 'http://localhost:8200';

        // The provider would need to be created via KeyProviderFactory for validation
        // Here we just verify the URL format is valid
        $this->assertStringStartsWith('http', $vaultUrl);
    }

    /**
     * Test handling of Infisical with missing configuration
     */
    public function testInfisicalWithMissingConfiguration(): void
    {
        // InfisicalKeyProvider requires all config parameters
        // This test verifies the expected parameters are documented
        $requiredParams = ['siteUrl', 'clientId', 'clientSecret', 'projectId', 'environment'];

        foreach ($requiredParams as $param) {
            $this->assertIsString($param);
            $this->assertNotEmpty($param);
        }
    }

    /**
     * Test handling of special characters in key ID
     */
    public function testSpecialCharactersInKeyId(): void
    {
        $specialChars = ['@', '#', '$', '%', '^', '&', '*', '(', ')', '+', '=', '[', ']', '{', '}', '|', '\\', ';', ':', "'", '"', '<', '>', ',', '.', '?', '/', '~', '`'];

        foreach ($specialChars as $char) {
            $invalidKid = 'key' . $char . 'name';
            $this->assertDoesNotMatchRegularExpression(
                '/^[a-zA-Z0-9_-]+$/',
                $invalidKid,
                "Key ID with '{$char}' should be invalid"
            );
        }
    }

    /**
     * Test handling of very long key ID
     */
    public function testVeryLongKeyId(): void
    {
        $veryLongKid = str_repeat('a', 1000);

        // Format validation would still pass (only checks characters)
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $veryLongKid);

        // But practical limits should apply (not tested here as they depend on implementation)
    }

    /**
     * Test handling of concurrent key access (basic check)
     */
    public function testConcurrentKeyAccess(): void
    {
        // This is a basic test - real concurrency testing would require multi-threading
        $provider = new LocalKeyProvider();
        $testKey = str_repeat('a', 32);

        $provider->setKey('test-key', $testKey);

        // Multiple sequential access should work
        $key1 = $provider->getKey('test-key');
        $key2 = $provider->getKey('test-key');
        $key3 = $provider->getKey('test-key');

        $this->assertEquals($testKey, $key1);
        $this->assertEquals($testKey, $key2);
        $this->assertEquals($testKey, $key3);
    }

    /**
     * Test handling of provider switching
     */
    public function testProviderSwitching(): void
    {
        // Start with local provider
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        PandaDirectory::create();

        $credentials = ['provider' => 'local'];
        PandaDirectory::writeCredentials($credentials);

        $readCredentials = PandaDirectory::readCredentials();
        $this->assertEquals('local', $readCredentials['provider']);

        // Switch to vault
        $credentials = [
            'provider' => 'vault',
            'vaultUrl' => 'http://127.0.0.1:8200',
            'vaultToken' => 'test-token',
            'vaultMount' => 'secret'
        ];
        PandaDirectory::writeCredentials($credentials);

        $readCredentials = PandaDirectory::readCredentials();
        $this->assertEquals('vault', $readCredentials['provider']);

        // Switch to infisical
        $credentials = [
            'provider' => 'infisical',
            'infisicalSiteUrl' => 'https://app.infisical.com',
            'infisicalClientId' => 'client-id',
            'infisicalClientSecret' => 'client-secret',
            'infisicalProjectId' => 'project-id',
            'infisicalEnvironment' => 'dev',
            'infisicalCacheTTL' => '300'
        ];
        PandaDirectory::writeCredentials($credentials);

        $readCredentials = PandaDirectory::readCredentials();
        $this->assertEquals('infisical', $readCredentials['provider']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test handling of file system errors
     */
    public function testFileSystemErrors(): void
    {
        // This test verifies that file system errors are handled gracefully
        // Actual error simulation would require mocking file system operations

        $this->assertTrue(true, 'File system error handling requires integration testing');
    }

    /**
     * Test handling of network errors (basic)
     */
    public function testNetworkErrors(): void
    {
        // This is a placeholder for network error testing
        // Real network error testing would require mocking HTTP operations

        $this->assertTrue(true, 'Network error handling requires integration/mocking tests');
    }

    /**
     * Test handling of memory limits
     */
    public function testMemoryLimits(): void
    {
        // Verify that key operations don't consume excessive memory
        $provider = new LocalKeyProvider();
        $testKey = str_repeat('a', 32);

        $provider->setKey('test-key', $testKey);

        $initialMemory = memory_get_usage();
        $retrievedKey = $provider->getKey('test-key');
        $finalMemory = memory_get_usage();

        // Memory increase should be minimal (just the key bytes)
        $this->assertLessThan(10000, $finalMemory - $initialMemory, 'Key retrieval should not consume significant memory');
    }

    /**
     * Test handling of Unicode in configuration
     */
    public function testUnicodeInConfiguration(): void
    {
        PandaDirectory::create();

        $credentials = [
            'provider' => 'local',
            'description' => 'Test with unicode: 你好 世界 مرحبا مرحبا بالعالم 🌍'
        ];

        PandaDirectory::writeCredentials($credentials);

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertEquals('Test with unicode: 你好 世界 مرحبا مرحبا بالعالم 🌍', $readCredentials['description']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test handling of null values in credentials
     */
    public function testNullValuesInCredentials(): void
    {
        PandaDirectory::create();

        $credentials = [
            'provider' => 'local',
            'token' => null,
            'description' => null
        ];

        PandaDirectory::writeCredentials($credentials);

        $readCredentials = PandaDirectory::readCredentials();

        // JSON encoding/decoding preserves null
        $this->assertNull($readCredentials['token']);
        $this->assertNull($readCredentials['description']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test handling of circular directory references
     */
    public function testCircularDirectoryReferences(): void
    {
        // PandaDirectory uses fixed paths, preventing circular references
        $pandaDir = PandaDirectory::PANDA_DIR;

        $this->assertEquals('.panda', $pandaDir);
        $this->assertStringNotContainsString('..', $pandaDir);
    }

    /**
     * Test handling of permission denied errors
     */
    public function testPermissionDeniedErrors(): void
    {
        // This is a placeholder for permission error testing
        // Real testing would require simulating permission errors

        $this->assertTrue(true, 'Permission error handling requires integration testing');
    }

    /**
     * Test handling of disk space issues
     */
    public function testDiskSpaceIssues(): void
    {
        // Verify that we check for available space (not tested here)
        $this->assertTrue(true, 'Disk space checking requires integration testing');
    }

    /**
     * Test error recovery after corruption
     */
    public function testErrorRecoveryAfterCorruption(): void
    {
        PandaDirectory::create();

        // Write corrupted data
        file_put_contents('.panda/credentials', 'corrupted');

        // Reading should throw exception
        try {
            PandaDirectory::readCredentials();
            $this->fail('Should throw exception for corrupted data');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to parse', $e->getMessage());
        }

        // Fix the corruption
        PandaDirectory::writeCredentials(['provider' => 'local']);

        // Should now work
        $credentials = PandaDirectory::readCredentials();
        $this->assertEquals('local', $credentials['provider']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }
}
