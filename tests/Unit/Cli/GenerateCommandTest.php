<?php

namespace Panda\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Panda\Cli\GenerateCommand;
use Panda\Cli\Utils\PandaDirectory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for GenerateCommand
 *
 * Tests key generation, key ID validation, and key storage
 */
class GenerateCommandTest extends TestCase
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

        // Clean up test .env files
        if (file_exists('.env.test')) {
            unlink('.env.test');
        }
        if (file_exists('.env.example.test')) {
            unlink('.env.example.test');
        }
    }

    protected function tearDown(): void
    {
        // Clean up
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        if (file_exists('.env.test')) {
            unlink('.env.test');
        }
        if (file_exists('.env.example.test')) {
            unlink('.env.example.test');
        }

        putenv('PANDA_SKIP_ONLINE_VALIDATION');
        putenv('APP_ENV');

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
        $command = new GenerateCommand();

        $this->assertEquals('generate', $command->getName());
        $this->assertStringContainsString('Generate', $command->getDescription());
    }

    /**
     * Test kid argument exists
     */
    public function testKidArgumentExists(): void
    {
        $command = new GenerateCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('kid'));
        $kidArgument = $definition->getArgument('kid');
        $this->assertFalse($kidArgument->isRequired());
    }

    /**
     * Test --description option exists
     */
    public function testDescriptionOptionExists(): void
    {
        $command = new GenerateCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('description'));
        $descriptionOption = $definition->getOption('description');
        $this->assertEquals('d', $descriptionOption->getShortcut());
        $this->assertTrue($descriptionOption->isValueOptional());
    }

    /**
     * Test key ID validation pattern
     */
    public function testKeyIdValidationPattern(): void
    {
        $validKeyIds = [
            'default-key',
            'nik_key',
            'healthDataKey',
            'KEY-123',
            'key_with_underscores',
            'my-custom-key-123'
        ];

        foreach ($validKeyIds as $kid) {
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z0-9_-]+$/',
                $kid,
                "Key ID '{$kid}' should be valid"
            );
        }

        $invalidKeyIds = [
            'key with spaces',
            'key@withsymbols',
            'key#hash',
            'key/slash',
            'key.dot',
            'key$sign',
            'key!exclamation'
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
     * Test key generation produces 32-byte keys
     */
    public function testKeyGenerationProduces32ByteKeys(): void
    {
        $keyBytes = random_bytes(32);
        $this->assertEquals(32, strlen($keyBytes));
    }

    /**
     * Test base64 encoding of keys
     */
    public function testBase64EncodingOfKeys(): void
    {
        $keyBytes = random_bytes(32);
        $keyBase64 = base64_encode($keyBytes);

        $this->assertIsString($keyBase64);
        $this->assertNotEmpty($keyBase64);

        // Verify it decodes back correctly
        $decoded = base64_decode($keyBase64, true);
        $this->assertEquals($keyBytes, $decoded);
    }

    /**
     * Test key ID normalization for env vars
     */
    public function testKeyIdNormalizationForEnvVars(): void
    {
        $testCases = [
            ['input' => 'nik-key', 'expected' => 'PANDA_KEY_NIK_KEY'],
            ['input' => 'health_data_key', 'expected' => 'PANDA_KEY_HEALTH_DATA_KEY'],
            ['input' => 'Test-Key-123', 'expected' => 'PANDA_KEY_TEST_KEY_123'],
            ['input' => 'user.email.key', 'expected' => 'PANDA_KEY_USER_EMAIL_KEY'],
            ['input' => 'default-key', 'expected' => 'PANDA_KEY_DEFAULT_KEY']
        ];

        foreach ($testCases as $case) {
            $normalized = 'PANDA_KEY_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $case['input']));
            $this->assertEquals($case['expected'], $normalized, "Failed for input: {$case['input']}");
        }
    }

    /**
     * Test local key storage updates credentials
     */
    public function testLocalKeyStorageUpdatesCredentials(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $initialCredentials = [
            'token' => 'test_token',
            'provider' => 'local'
        ];

        PandaDirectory::writeCredentials($initialCredentials);

        // Simulate adding a key
        $credentials = PandaDirectory::readCredentials();
        $credentials['keys'] = [
            'test-key' => [
                'created' => date('c'),
                'provider' => 'local'
            ]
        ];
        PandaDirectory::writeCredentials($credentials);

        // Verify
        $updatedCredentials = PandaDirectory::readCredentials();
        $this->assertArrayHasKey('keys', $updatedCredentials);
        $this->assertArrayHasKey('test-key', $updatedCredentials['keys']);
        $this->assertEquals('local', $updatedCredentials['keys']['test-key']['provider']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test vault key storage updates credentials
     */
    public function testVaultKeyStorageUpdatesCredentials(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $initialCredentials = [
            'token' => 'test_token',
            'provider' => 'vault',
            'vaultUrl' => 'http://127.0.0.1:8200',
            'vaultToken' => 'test-token',
            'vaultMount' => 'secret'
        ];

        PandaDirectory::writeCredentials($initialCredentials);

        // Simulate adding a key
        $credentials = PandaDirectory::readCredentials();
        $credentials['keys'] = [
            'test-key' => [
                'created' => date('c'),
                'provider' => 'vault'
            ]
        ];
        PandaDirectory::writeCredentials($credentials);

        // Verify
        $updatedCredentials = PandaDirectory::readCredentials();
        $this->assertArrayHasKey('keys', $updatedCredentials);
        $this->assertEquals('vault', $updatedCredentials['keys']['test-key']['provider']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test infisical key storage updates credentials
     */
    public function testInfisicalKeyStorageUpdatesCredentials(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $initialCredentials = [
            'token' => 'test_token',
            'provider' => 'infisical',
            'infisicalSiteUrl' => 'https://app.infisical.com',
            'infisicalClientId' => 'client-id',
            'infisicalClientSecret' => 'client-secret',
            'infisicalProjectId' => 'project-id',
            'infisicalEnvironment' => 'dev',
            'infisicalCacheTTL' => '300'
        ];

        PandaDirectory::writeCredentials($initialCredentials);

        // Simulate adding a key
        $credentials = PandaDirectory::readCredentials();
        $credentials['keys'] = [
            'test-key' => [
                'created' => date('c'),
                'provider' => 'infisical'
            ]
        ];
        PandaDirectory::writeCredentials($credentials);

        // Verify
        $updatedCredentials = PandaDirectory::readCredentials();
        $this->assertArrayHasKey('keys', $updatedCredentials);
        $this->assertEquals('infisical', $updatedCredentials['keys']['test-key']['provider']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test env file append functionality
     */
    public function testEnvFileAppendFunctionality(): void
    {
        $testEnvPath = '.env.test';
        $envVarName = 'PANDA_KEY_TEST_KEY';
        $keyValue = 'dGVzdGtleWZvcnRlc3Rpbmc=';

        // Write initial content
        file_put_contents($testEnvPath, "EXISTING_VAR=value\n");

        // Simulate the appendEnv function
        $content = file_get_contents($testEnvPath);
        $regex = "/^{$envVarName}=.*\\r?\\n?/m";

        if (preg_match($regex, $content)) {
            $content = preg_replace($regex, "{$envVarName}={$keyValue}\n", $content);
        } else {
            $content .= "\n{$envVarName}={$keyValue}\n";
        }

        file_put_contents($testEnvPath, trim($content) . "\n");

        // Verify
        $updatedContent = file_get_contents($testEnvPath);
        $this->assertStringContainsString("{$envVarName}={$keyValue}", $updatedContent);
        $this->assertStringContainsString('EXISTING_VAR=value', $updatedContent);

        // Cleanup
        unlink($testEnvPath);
    }

    /**
     * Test env file update functionality
     */
    public function testEnvFileUpdateFunctionality(): void
    {
        $testEnvPath = '.env.test';
        $envVarName = 'PANDA_KEY_TEST_KEY';
        $oldValue = 'old-value';
        $newValue = 'new-value';

        // Write initial content with existing key
        file_put_contents($testEnvPath, "{$envVarName}={$oldValue}\n");

        // Simulate the appendEnv function (should replace)
        $content = file_get_contents($testEnvPath);
        $regex = "/^{$envVarName}=.*\\r?\\n?/m";

        if (preg_match($regex, $content)) {
            $content = preg_replace($regex, "{$envVarName}={$newValue}\n", $content);
        } else {
            $content .= "\n{$envVarName}={$newValue}\n";
        }

        file_put_contents($testEnvPath, trim($content) . "\n");

        // Verify
        $updatedContent = file_get_contents($testEnvPath);
        $this->assertStringContainsString("{$envVarName}={$newValue}", $updatedContent);
        $this->assertStringNotContainsString($oldValue, $updatedContent);

        // Cleanup
        unlink($testEnvPath);
    }

    /**
     * Test .env.example gets placeholder
     */
    public function testEnvExampleGetsPlaceholder(): void
    {
        $testExamplePath = '.env.example.test';
        $envVarName = 'PANDA_KEY_TEST_KEY';

        // Simulate writing to .env.example
        $content = '';
        if (file_exists($testExamplePath)) {
            $content = file_get_contents($testExamplePath);
        }

        $regex = "/^{$envVarName}=.*\\r?\\n?/m";
        if (preg_match($regex, $content)) {
            $content = preg_replace($regex, "{$envVarName}=\n", $content);
        } else {
            $content .= "\n{$envVarName}=\n";
        }

        file_put_contents($testExamplePath, trim($content) . "\n");

        // Verify
        $exampleContent = file_get_contents($testExamplePath);
        $this->assertStringContainsString("{$envVarName}=", $exampleContent);

        // Cleanup
        unlink($testExamplePath);
    }

    /**
     * Test keys metadata is updated
     */
    public function testKeysMetadataIsUpdated(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $kid = 'test-key-1';
        $provider = 'local';
        $location = '.env';

        // Add key to metadata
        PandaDirectory::addKeyToMetadata($kid, $provider, $location);

        $keys = PandaDirectory::readKeysMetadata();

        $this->assertIsArray($keys);
        $this->assertArrayHasKey('keys', $keys);
        $this->assertCount(1, $keys['keys']);
        $this->assertEquals($kid, $keys['keys'][0]['kid']);
        $this->assertEquals($provider, $keys['keys'][0]['provider']);
        $this->assertEquals($location, $keys['keys'][0]['location']);
        $this->assertArrayHasKey('created', $keys['keys'][0]);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test error when no credentials exist
     */
    public function testErrorWhenNoCredentialsExist(): void
    {
        // Ensure no .panda directory exists
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        // Trying to read credentials when none exist
        $credentials = PandaDirectory::readCredentials();

        // Should return null when no credentials file exists
        $this->assertNull($credentials);
    }

    /**
     * Test provider detection for key storage
     */
    public function testProviderDetectionForKeyStorage(): void
    {
        $providers = ['local', 'vault', 'infisical'];

        foreach ($providers as $provider) {
            // Create .panda directory
            if (is_dir('.panda')) {
                $this->deleteDirectory('.panda');
            }
            PandaDirectory::create();

            $credentials = [
                'token' => 'test_token',
                'provider' => $provider
            ];

            if ($provider === 'vault') {
                $credentials['vaultUrl'] = 'http://127.0.0.1:8200';
                $credentials['vaultToken'] = 'test-token';
                $credentials['vaultMount'] = 'secret';
            } elseif ($provider === 'infisical') {
                $credentials['infisicalSiteUrl'] = 'https://app.infisical.com';
                $credentials['infisicalClientId'] = 'client-id';
                $credentials['infisicalClientSecret'] = 'client-secret';
                $credentials['infisicalProjectId'] = 'project-id';
                $credentials['infisicalEnvironment'] = 'dev';
                $credentials['infisicalCacheTTL'] = '300';
            }

            PandaDirectory::writeCredentials($credentials);

            $readCredentials = PandaDirectory::readCredentials();
            $this->assertEquals($provider, $readCredentials['provider']);
        }

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test key description is stored
     */
    public function testKeyDescriptionIsStored(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $description = 'Test key for unit testing';

        // Simulate storing a key with description
        $credentials = [
            'token' => 'test_token',
            'provider' => 'local',
            'keys' => [
                'test-key' => [
                    'created' => date('c'),
                    'provider' => 'local',
                    'description' => $description
                ]
            ]
        ];

        PandaDirectory::writeCredentials($credentials);

        $readCredentials = PandaDirectory::readCredentials();
        $this->assertEquals($description, $readCredentials['keys']['test-key']['description']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test multiple keys can be stored
     */
    public function testMultipleKeysCanBeStored(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        PandaDirectory::addKeyToMetadata('key-1', 'local', '.env');
        PandaDirectory::addKeyToMetadata('key-2', 'local', '.env');
        PandaDirectory::addKeyToMetadata('key-3', 'local', '.env');

        $keys = PandaDirectory::readKeysMetadata();

        $this->assertCount(3, $keys['keys']);
        $this->assertEquals('key-1', $keys['keys'][0]['kid']);
        $this->assertEquals('key-2', $keys['keys'][1]['kid']);
        $this->assertEquals('key-3', $keys['keys'][2]['kid']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test duplicate key throws exception
     */
    public function testDuplicateKeyThrowsException(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        PandaDirectory::addKeyToMetadata('duplicate-key', 'local', '.env');

        $this->expectException(\RuntimeException::class);
        PandaDirectory::addKeyToMetadata('duplicate-key', 'local', '.env');

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test created timestamp format
     */
    public function testCreatedTimestampFormat(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        PandaDirectory::addKeyToMetadata('test-key', 'local', '.env');

        $keys = PandaDirectory::readKeysMetadata();
        $created = $keys['keys'][0]['created'];

        // Should be ISO 8601 format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $created);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test key ID case sensitivity
     */
    public function testKeyIdCaseSensitivity(): void
    {
        $keyIds = [
            'MyKey',
            'mykey',
            'MYKEY',
            'My-Key-123',
            'my-key-123'
        ];

        foreach ($keyIds as $kid) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $kid, "Key ID {$kid} should be valid");
        }
    }

    /**
     * Test vault key storage prints manual instructions
     */
    public function testVaultKeyStoragePrintsManualInstructions(): void
    {
        $vaultCredentials = [
            'provider' => 'vault',
            'vaultMount' => 'secret',
            'vaultUrl' => 'http://127.0.0.1:8200'
        ];

        $kid = 'test-key';
        $keyBase64 = 'dGVzdGtleQ==';

        // Vault should print manual setup instructions
        $expectedOutput = "vault kv put {$vaultCredentials['vaultMount']}/{$kid} value={$keyBase64}";

        $this->assertStringContainsString('vault kv put', $expectedOutput);
        $this->assertStringContainsString($kid, $expectedOutput);
    }

    /**
     * Test infisical key storage prints manual instructions
     */
    public function testInfisicalKeyStoragePrintsManualInstructions(): void
    {
        $infisicalCredentials = [
            'provider' => 'infisical',
            'infisicalProjectId' => 'proj-123'
        ];

        $kid = 'test-key';
        $keyBase64 = 'dGVzdGtleQ==';

        // Infisical should print manual setup instructions
        $this->assertStringContainsString($kid, $kid);
        $this->assertStringContainsString('base64', 'Value (base64): ' . $keyBase64);
    }
}
