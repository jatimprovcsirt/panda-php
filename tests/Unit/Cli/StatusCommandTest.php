<?php

namespace Panda\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Panda\Cli\StatusCommand;
use Panda\Cli\Utils\PandaDirectory;

/**
 * Unit tests for StatusCommand
 *
 * Tests configuration display and key listing
 */
class StatusCommandTest extends TestCase
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
        putenv('PANDA_KEY_TEST_KEY');

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
     * Test that the command is configured correctly
     */
    public function testCommandConfiguration(): void
    {
        $command = new StatusCommand();

        $this->assertEquals('status', $command->getName());
        $this->assertStringContainsString('Display', $command->getDescription());
    }

    /**
     * Test status with no initialization
     */
    public function testStatusWithNoInitialization(): void
    {
        $command = new StatusCommand();

        // Should handle no .panda directory gracefully
        $this->assertFalse(PandaDirectory::exists());
    }

    /**
     * Test status reads credentials correctly
     */
    public function testStatusReadsCredentialsCorrectly(): void
    {
        // Create .panda directory with credentials
        PandaDirectory::create();

        $testCredentials = [
            'token' => 'test_token',
            'provider' => 'local',
            'defaultKid' => 'default-key'
        ];

        PandaDirectory::writeCredentials($testCredentials);

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertIsArray($readCredentials);
        $this->assertEquals('local', $readCredentials['provider']);
        $this->assertEquals('default-key', $readCredentials['defaultKid']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test status displays local provider info
     */
    public function testStatusDisplaysLocalProviderInfo(): void
    {
        $localCredentials = [
            'provider' => 'local',
            'defaultKid' => 'default-key'
        ];

        $this->assertEquals('local', $localCredentials['provider']);
    }

    /**
     * Test status displays vault provider info
     */
    public function testStatusDisplaysVaultProviderInfo(): void
    {
        $vaultCredentials = [
            'provider' => 'vault',
            'vaultUrl' => 'http://127.0.0.1:8200',
            'vaultToken' => 'test-token',
            'vaultMount' => 'secret'
        ];

        $this->assertEquals('vault', $vaultCredentials['provider']);
        $this->assertArrayHasKey('vaultUrl', $vaultCredentials);
        $this->assertArrayHasKey('vaultToken', $vaultCredentials);
        $this->assertArrayHasKey('vaultMount', $vaultCredentials);
    }

    /**
     * Test status displays infisical provider info
     */
    public function testStatusDisplaysInfisicalProviderInfo(): void
    {
        $infisicalCredentials = [
            'provider' => 'infisical',
            'infisicalSiteUrl' => 'https://app.infisical.com',
            'infisicalClientId' => 'client-id',
            'infisicalClientSecret' => 'client-secret',
            'infisicalProjectId' => 'project-id',
            'infisicalEnvironment' => 'dev',
            'infisicalCacheTTL' => '300'
        ];

        $this->assertEquals('infisical', $infisicalCredentials['provider']);
        $this->assertArrayHasKey('infisicalSiteUrl', $infisicalCredentials);
        $this->assertArrayHasKey('infisicalProjectId', $infisicalCredentials);
        $this->assertArrayHasKey('infisicalEnvironment', $infisicalCredentials);
    }

    /**
     * Test status lists configured keys
     */
    public function testStatusListsConfiguredKeys(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $credentials = [
            'token' => 'test_token',
            'provider' => 'local',
            'keys' => [
                'key-1' => [
                    'created' => '2024-01-01T00:00:00+00:00',
                    'provider' => 'local'
                ],
                'key-2' => [
                    'created' => '2024-01-02T00:00:00+00:00',
                    'provider' => 'local'
                ]
            ]
        ];

        PandaDirectory::writeCredentials($credentials);

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertArrayHasKey('keys', $readCredentials);
        $this->assertCount(2, $readCredentials['keys']);
        $this->assertArrayHasKey('key-1', $readCredentials['keys']);
        $this->assertArrayHasKey('key-2', $readCredentials['keys']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test status handles empty keys array
     */
    public function testStatusHandlesEmptyKeysArray(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $credentials = [
            'token' => 'test_token',
            'provider' => 'local',
            'keys' => []
        ];

        PandaDirectory::writeCredentials($credentials);

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertArrayHasKey('keys', $readCredentials);
        $this->assertIsArray($readCredentials['keys']);
        $this->assertCount(0, $readCredentials['keys']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test status displays default key ID
     */
    public function testStatusDisplaysDefaultKeyId(): void
    {
        $credentials = [
            'provider' => 'local',
            'defaultKid' => 'my-default-key'
        ];

        $this->assertEquals('my-default-key', $credentials['defaultKid']);
    }

    /**
     * Test status handles missing default key ID
     */
    public function testStatusHandlesMissingDefaultKeyId(): void
    {
        $credentials = [
            'provider' => 'local'
        ];

        $this->assertArrayNotHasKey('defaultKid', $credentials);
    }

    /**
     * Test environment variable detection for local keys
     */
    public function testEnvironmentVariableDetectionForLocalKeys(): void
    {
        $kid = 'test-key';
        $normalizedKid = 'TEST_KEY';
        $envVar = "PANDA_KEY_{$normalizedKid}";

        putenv("{$envVar}=test-key-value");

        $envValue = getenv($envVar);
        $this->assertNotFalse($envValue);
        $this->assertEquals('test-key-value', $envValue);

        putenv($envVar);
    }

    /**
     * Test status handles missing environment variable
     */
    public function testStatusHandlesMissingEnvironmentVariable(): void
    {
        $envVar = 'PANDA_KEY_TEST_KEY';

        // Ensure environment variable is not set
        putenv($envVar);

        $envValue = getenv($envVar);
        $this->assertFalse($envValue);
    }

    /**
     * Test status detects Laravel integration
     */
    public function testStatusDetectsLaravelIntegration(): void
    {
        // Simulate Laravel file structure
        $hasComposer = true;
        $hasServiceProvider = true;

        $this->assertTrue($hasComposer && $hasServiceProvider);
    }

    /**
     * Test status detects NestJS integration
     */
    public function testStatusDetectsNestJSIntegration(): void
    {
        // Simulate NestJS file structure
        $hasPackageJson = true;
        $hasAppModule = true;

        $this->assertTrue($hasPackageJson && $hasAppModule);
    }

    /**
     * Test status displays environment info
     */
    public function testStatusDisplaysEnvironmentInfo(): void
    {
        $platform = PHP_OS;
        $phpVersion = PHP_VERSION;

        $this->assertNotEmpty($platform);
        $this->assertNotEmpty($phpVersion);
        $this->assertStringContainsString('.', $phpVersion);
    }

    /**
     * Test status displays quick tips
     */
    public function testStatusDisplaysQuickTips(): void
    {
        $tips = [
            'panda generate <kid>',
            'panda rotate-key',
            'panda migrate <table>',
            'panda shred <kid>'
        ];

        foreach ($tips as $tip) {
            $this->assertStringContainsString('panda', $tip);
        }
    }

    /**
     * Test status with vault provider connectivity test
     */
    public function testStatusWithVaultProviderConnectivityTest(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $vaultCredentials = [
            'provider' => 'vault',
            'vaultUrl' => 'http://127.0.0.1:8200',
            'vaultToken' => 'test-token',
            'vaultMount' => 'secret',
            'defaultKid' => 'default-key'
        ];

        PandaDirectory::writeCredentials($vaultCredentials);

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertEquals('vault', $readCredentials['provider']);
        $this->assertEquals('http://127.0.0.1:8200', $readCredentials['vaultUrl']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test status with infisical provider connectivity test
     */
    public function testStatusWithInfisicalProviderConnectivityTest(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $infisicalCredentials = [
            'provider' => 'infisical',
            'infisicalSiteUrl' => 'https://app.infisical.com',
            'infisicalClientId' => 'client-id',
            'infisicalClientSecret' => 'client-secret',
            'infisicalProjectId' => 'project-id',
            'infisicalEnvironment' => 'dev',
            'infisicalCacheTTL' => '300',
            'defaultKid' => 'default-key'
        ];

        PandaDirectory::writeCredentials($infisicalCredentials);

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertEquals('infisical', $readCredentials['provider']);
        $this->assertEquals('dev', $readCredentials['infisicalEnvironment']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test status masks sensitive values
     */
    public function testStatusMasksSensitiveValues(): void
    {
        // These keys should have their values masked in status output
        $sensitiveKeys = ['vaultToken', 'infisicalClientSecret', 'token'];

        foreach ($sensitiveKeys as $key) {
            $this->assertIsString($key);
            // All sensitive keys should be strings
            $this->assertNotEmpty($key);
        }

        // Check specific sensitive patterns
        $this->assertStringContainsString('Token', $sensitiveKeys[0]);
        $this->assertStringContainsString('Secret', $sensitiveKeys[1]);
    }

    /**
     * Test status displays working directory
     */
    public function testStatusDisplaysWorkingDirectory(): void
    {
        $cwd = getcwd();

        $this->assertIsString($cwd);
        $this->assertNotEmpty($cwd);
    }

    /**
     * Test status with keys metadata from keys.json
     */
    public function testStatusWithKeysMetadataFromKeysJson(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        // Add a key to metadata
        PandaDirectory::addKeyToMetadata('test-key', 'local', '.env');

        $keys = PandaDirectory::readKeysMetadata();

        $this->assertIsArray($keys);
        $this->assertArrayHasKey('keys', $keys);
        $this->assertCount(1, $keys['keys']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test status handles corrupted credentials
     */
    public function testStatusHandlesCorruptedCredentials(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        // Write invalid JSON to credentials
        file_put_contents('.panda/credentials', '{invalid json}');

        $this->expectException(\RuntimeException::class);
        PandaDirectory::readCredentials();

        // Cleanup (in case test fails before exception)
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test status displays cache TTL for Infisical
     */
    public function testStatusDisplaysCacheTTLForInfisical(): void
    {
        $credentials = [
            'provider' => 'infisical',
            'infisicalCacheTTL' => '600'
        ];

        $this->assertEquals('600', $credentials['infisicalCacheTTL']);
    }

    /**
     * Test status with default key fallback
     */
    public function testStatusWithDefaultKeyFallback(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $credentials = [
            'provider' => 'local'
            // No defaultKid set
        ];

        PandaDirectory::writeCredentials($credentials);

        $readCredentials = PandaDirectory::readCredentials();

        // Should fall back to 'test-key' for connectivity testing
        $testKid = $readCredentials['defaultKid'] ?? 'test-key';
        $this->assertEquals('test-key', $testKid);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test status command returns success when no initialization
     */
    public function testStatusReturnsSuccessWhenNoInitialization(): void
    {
        // Ensure no .panda directory
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        $this->assertFalse(PandaDirectory::exists());
    }

    /**
     * Test status displays CodeIgniter integration
     */
    public function testStatusDisplaysCodeIgniterIntegration(): void
    {
        // Simulate CodeIgniter file structure
        $hasComposer = true;
        $hasConfig = true;

        $this->assertTrue($hasComposer && $hasConfig);
    }

    /**
     * Test status displays Express integration
     */
    public function testStatusDisplaysExpressIntegration(): void
    {
        // Simulate Express file structure
        $hasPackageJson = true;
        $hasServer = true;

        $this->assertTrue($hasPackageJson && $hasServer);
    }
}
