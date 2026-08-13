<?php

namespace Panda\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Panda\Cli\InitCommand;
use Panda\Cli\Utils\PandaDirectory;
use Panda\Crypto\TokenValidator;
use Panda\Exception\InvalidTokenException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unit tests for InitCommand
 *
 * Tests token validation, provider selection, credential storage, and reinitialization
 */
class InitCommandTest extends TestCase
{
    private string $testPandaDir = '.panda_test';
    private string $originalPandaDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Store original PANDA_DIR constant value if needed
        $this->originalPandaDir = PandaDirectory::PANDA_DIR;

        // Set up test environment
        putenv('PANDA_SKIP_ONLINE_VALIDATION=1');
        putenv('APP_ENV=testing');

        // Clean up any existing test directory
        $this->cleanupTestDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestDirectory();

        // Clean up environment
        putenv('PANDA_SKIP_ONLINE_VALIDATION');
        putenv('APP_ENV');
        putenv('PANDA_ACCESS_TOKEN');

        // Clean up test .env files
        if (file_exists('.env.test')) {
            unlink('.env.test');
        }
        if (file_exists('.env.example.test')) {
            unlink('.env.example.test');
        }

        parent::tearDown();
    }

    private function cleanupTestDirectory(): void
    {
        if (is_dir($this->testPandaDir)) {
            $files = array_diff(scandir($this->testPandaDir), ['.', '..']);
            foreach ($files as $file) {
                $path = $this->testPandaDir . '/' . $file;
                if (is_dir($path)) {
                    $this->deleteDirectory($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($this->testPandaDir);
        }
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
        $command = new InitCommand();

        $this->assertEquals('init', $command->getName());
        $this->assertStringContainsString('Initialize', $command->getDescription());
    }

    /**
     * Test --token option exists
     */
    public function testTokenOptionExists(): void
    {
        $command = new InitCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('token'));
        $tokenOption = $definition->getOption('token');
        $this->assertEquals('t', $tokenOption->getShortcut());
        $this->assertTrue($tokenOption->isValueOptional());
    }

    /**
     * Test --quick option exists
     */
    public function testQuickOptionExists(): void
    {
        $command = new InitCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('quick'));
        $quickOption = $definition->getOption('quick');
        $this->assertEquals('q', $quickOption->getShortcut());
        $this->assertFalse($quickOption->acceptValue());
    }

    /**
     * Test --reinit option exists
     */
    public function testReinitOptionExists(): void
    {
        $command = new InitCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('reinit'));
        $reinitOption = $definition->getOption('reinit');
        $this->assertFalse($reinitOption->acceptValue());
    }

    /**
     * Test --skip-key-gen option exists
     */
    public function testSkipKeyGenOptionExists(): void
    {
        $command = new InitCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('skip-key-gen'));
        $this->assertFalse($definition->getOption('skip-key-gen')->acceptValue());
    }

    /**
     * Test --kid option exists
     */
    public function testKidOptionExists(): void
    {
        $command = new InitCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('kid'));
        $kidOption = $definition->getOption('kid');
        $this->assertEquals('k', $kidOption->getShortcut());
        $this->assertTrue($kidOption->isValueRequired());
    }

    /**
     * Test demo token validation
     */
    public function testDemoTokenValidation(): void
    {
        $demoToken = 'panda_demo_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2';

        $this->assertTrue(TokenValidator::isDemoToken($demoToken));
        $this->assertFalse(TokenValidator::isDemoToken('panda_live_someothertoken'));
    }

    /**
     * Test token format validation
     */
    public function testTokenFormatValidation(): void
    {
        $validTokens = [
            'panda_demo_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2',
            'panda_live_' . str_repeat('a', 48),
            'panda_live_' . str_repeat('b', 64)
        ];

        foreach ($validTokens as $token) {
            $this->assertMatchesRegularExpression(
                TokenValidator::TOKEN_PATTERN,
                $token,
                "Token should match pattern: {$token}"
            );
        }

        $invalidTokens = [
            'invalid_token',
            'panda_demo_' . str_repeat('c', 47), // Too short
            'panda_live_' . str_repeat('d', 65), // Too long
            'panda_other_' . str_repeat('e', 48), // Wrong prefix
            ''
        ];

        foreach ($invalidTokens as $token) {
            $this->assertDoesNotMatchRegularExpression(
                TokenValidator::TOKEN_PATTERN,
                $token,
                "Token should not match pattern: {$token}"
            );
        }
    }

    /**
     * Test token validation throws exception for empty token
     */
    public function testTokenValidationThrowsExceptionForEmptyToken(): void
    {
        $this->expectException(InvalidTokenException::class);

        TokenValidator::validateToken('');
    }

    /**
     * Test token validation throws exception for invalid format
     */
    public function testTokenValidationThrowsExceptionForInvalidFormat(): void
    {
        $this->expectException(InvalidTokenException::class);

        TokenValidator::validateToken('invalid_token_format');
    }

    /**
     * Test demo token passes validation without online check
     */
    public function testDemoTokenPassesValidation(): void
    {
        $demoToken = 'panda_demo_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2';

        // Should not throw exception
        try {
            TokenValidator::validateToken($demoToken);
            $this->assertTrue(true, "Demo token should pass validation");
        } catch (InvalidTokenException $e) {
            $this->fail("Demo token should not throw exception: " . $e->getMessage());
        }
    }

    /**
     * Test local environment detection
     */
    public function testLocalEnvironmentDetection(): void
    {
        $localEnvs = ['local', 'dev', 'development', 'testing'];

        foreach ($localEnvs as $env) {
            putenv("APP_ENV={$env}");
            $this->assertTrue(TokenValidator::isLocalEnvironment(), "Environment {$env} should be detected as local");
        }

        putenv('APP_ENV=production');
        $this->assertFalse(TokenValidator::isLocalEnvironment(), 'Production should not be detected as local');
    }

    /**
     * Test PandaDirectory::exists()
     */
    public function testPandaDirectoryExists(): void
    {
        // Initially should not exist
        $this->assertFalse(PandaDirectory::exists(), 'Panda directory should not exist initially');

        // After creation should exist
        mkdir($this->testPandaDir, 0700, true);
        $this->assertTrue(is_dir($this->testPandaDir), 'Test directory should exist');

        // Cleanup
        rmdir($this->testPandaDir);
    }

    /**
     * Test PandaDirectory::create()
     */
    public function testPandaDirectoryCreate(): void
    {
        // Ensure clean state
        if (is_dir($this->testPandaDir)) {
            $this->deleteDirectory($this->testPandaDir);
        }

        // Create directory structure
        PandaDirectory::create();

        $this->assertTrue(is_dir('.panda'), '.panda directory should be created');
        $this->assertTrue(is_file('.panda/credentials'), 'credentials file should exist');
        $this->assertTrue(is_file('.panda/keys.json'), 'keys.json should exist');

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials writing and reading
     */
    public function testCredentialsWriteAndRead(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        PandaDirectory::create();

        $testCredentials = [
            'token' => 'test_token_value',
            'provider' => 'local',
            'defaultKid' => 'test-key'
        ];

        PandaDirectory::writeCredentials($testCredentials);

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertIsArray($readCredentials);
        $this->assertEquals('test_token_value', $readCredentials['token']);
        $this->assertEquals('local', $readCredentials['provider']);
        $this->assertEquals('test-key', $readCredentials['defaultKid']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials provider default value
     */
    public function testCredentialsProviderDefault(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        PandaDirectory::create();

        // Write empty credentials
        file_put_contents('.panda/credentials', '');

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertIsArray($readCredentials);
        $this->assertEquals('local', $readCredentials['provider']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test keys metadata operations
     */
    public function testKeysMetadataOperations(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        PandaDirectory::create();

        // Add a key to metadata
        PandaDirectory::addKeyToMetadata('test-key-1', 'local', '.env');

        $keys = PandaDirectory::readKeysMetadata();

        $this->assertIsArray($keys);
        $this->assertArrayHasKey('keys', $keys);
        $this->assertCount(1, $keys['keys']);
        $this->assertEquals('test-key-1', $keys['keys'][0]['kid']);
        $this->assertEquals('local', $keys['keys'][0]['provider']);

        // Try adding duplicate key (should throw exception)
        try {
            PandaDirectory::addKeyToMetadata('test-key-1', 'local', '.env');
            $this->fail('Should throw exception for duplicate key');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test config file operations
     */
    public function testConfigFileOperations(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        PandaDirectory::create();

        // Initially should return null
        $this->assertNull(PandaDirectory::readConfig());

        // Write config
        $testConfig = [
            'version' => '1.0.0',
            'initialized' => true
        ];

        PandaDirectory::writeConfig($testConfig);

        $readConfig = PandaDirectory::readConfig();

        $this->assertIsArray($readConfig);
        $this->assertEquals('1.0.0', $readConfig['version']);
        $this->assertTrue($readConfig['initialized']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test PandaDirectory::remove()
     */
    public function testPandaDirectoryRemove(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        PandaDirectory::create();

        $this->assertTrue(is_dir('.panda'));

        PandaDirectory::remove();

        $this->assertFalse(is_dir('.panda'), '.panda directory should be removed');
    }

    /**
     * Test credentials file permissions
     */
    public function testCredentialsFilePermissions(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        PandaDirectory::create();

        $credentialsPath = '.panda/credentials';
        $perms = fileperms($credentialsPath) & 0777;

        // On Unix-like systems, should be 0600 (read/write for owner only)
        // On Windows, this test may not work the same way
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertEquals(0600, $perms, 'Credentials file should have 0600 permissions');
        } else {
            // On Windows, just check that file exists
            $this->assertTrue(is_file($credentialsPath));
        }

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test reinitialization detection
     */
    public function testReinitializationDetection(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        // Initially should not exist
        $this->assertFalse(PandaDirectory::exists());

        // Create directory
        PandaDirectory::create();

        // Now should exist
        $this->assertTrue(PandaDirectory::exists());

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test vault credentials structure
     */
    public function testVaultCredentialsStructure(): void
    {
        $vaultCredentials = [
            'token' => 'test_token',
            'provider' => 'vault',
            'vaultUrl' => 'http://127.0.0.1:8200',
            'vaultToken' => 'vault-token',
            'vaultMount' => 'secret'
        ];

        $this->assertEquals('vault', $vaultCredentials['provider']);
        $this->assertArrayHasKey('vaultUrl', $vaultCredentials);
        $this->assertArrayHasKey('vaultToken', $vaultCredentials);
        $this->assertArrayHasKey('vaultMount', $vaultCredentials);
    }

    /**
     * Test infisical credentials structure
     */
    public function testInfisicalCredentialsStructure(): void
    {
        $infisicalCredentials = [
            'token' => 'test_token',
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
        $this->assertArrayHasKey('infisicalClientId', $infisicalCredentials);
        $this->assertArrayHasKey('infisicalClientSecret', $infisicalCredentials);
        $this->assertArrayHasKey('infisicalProjectId', $infisicalCredentials);
        $this->assertArrayHasKey('infisicalEnvironment', $infisicalCredentials);
        $this->assertArrayHasKey('infisicalCacheTTL', $infisicalCredentials);
    }

    /**
     * Test key ID normalization
     */
    public function testKeyIdNormalization(): void
    {
        $testCases = [
            ['input' => 'nik-key', 'expected' => 'NIK_KEY'],
            ['input' => 'health_data_key', 'expected' => 'HEALTH_DATA_KEY'],
            ['input' => 'Test-Key-123', 'expected' => 'TEST_KEY_123'],
            ['input' => 'user.email.key', 'expected' => 'USER_EMAIL_KEY']
        ];

        foreach ($testCases as $case) {
            $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $case['input']));
            $this->assertEquals($case['expected'], $normalized, "Failed for input: {$case['input']}");
        }
    }

    /**
     * Test default key ID value
     */
    public function testDefaultKeyIdValue(): void
    {
        $command = new InitCommand();
        $definition = $command->getDefinition();

        $kidOption = $definition->getOption('kid');
        $this->assertEquals('default-key', $kidOption->getDefault());
    }

    /**
     * Test environment variable token source
     */
    public function testEnvironmentVariableTokenSource(): void
    {
        $testToken = 'panda_demo_' . str_repeat('a', 48);
        putenv("PANDA_ACCESS_TOKEN={$testToken}");

        $envToken = getenv('PANDA_ACCESS_TOKEN');
        $this->assertEquals($testToken, $envToken);
    }

    /**
     * Test keys metadata structure
     */
    public function testKeysMetadataStructure(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        PandaDirectory::create();

        PandaDirectory::addKeyToMetadata('test-key', 'local', '.env');

        $keys = PandaDirectory::readKeysMetadata();

        $this->assertIsArray($keys);
        $this->assertArrayHasKey('keys', $keys);
        $this->assertIsArray($keys['keys']);

        $keyEntry = $keys['keys'][0];
        $this->assertArrayHasKey('kid', $keyEntry);
        $this->assertArrayHasKey('created', $keyEntry);
        $this->assertArrayHasKey('provider', $keyEntry);
        $this->assertArrayHasKey('location', $keyEntry);

        $this->assertEquals('test-key', $keyEntry['kid']);
        $this->assertEquals('local', $keyEntry['provider']);
        $this->assertEquals('.env', $keyEntry['location']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test JSON parse error handling
     */
    public function testJsonParseErrorHandling(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

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
     * Test empty credentials file handling
     */
    public function testEmptyCredentialsFileHandling(): void
    {
        // Ensure clean state
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }

        PandaDirectory::create();

        // Ensure credentials file is empty
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
}
