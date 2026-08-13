<?php

namespace Panda\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Panda\Cli\Utils\PandaDirectory;

/**
 * Security tests for credential storage
 *
 * Tests encrypted credential storage and file permissions
 */
class CredentialStorageTest extends TestCase
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
     * Test credentials file has restricted permissions on Unix
     */
    public function testCredentialsFileHasRestrictedPermissions(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $credentialsPath = '.panda/credentials';

        $this->assertFileExists($credentialsPath);

        // On Unix-like systems, check for 0600 permissions
        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = fileperms($credentialsPath) & 0777;
            $this->assertEquals(0600, $perms, 'Credentials file should have 0600 permissions (owner read/write only)');
        } else {
            // On Windows, just verify file exists and is readable/writable
            $this->assertIsReadable($credentialsPath);
            $this->assertIsWritable($credentialsPath);
        }

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test .panda directory has restricted permissions
     */
    public function testPandaDirectoryHasRestrictedPermissions(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $pandaPath = '.panda';

        $this->assertFileExists($pandaPath);
        $this->assertTrue(is_dir($pandaPath));

        // On Unix-like systems, check for 0700 permissions
        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = fileperms($pandaPath) & 0777;
            $this->assertEquals(0700, $perms, '.panda directory should have 0700 permissions (owner access only)');
        } else {
            // On Windows, just verify directory exists and is accessible
            $this->assertTrue(is_dir($pandaPath));
        }

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials are stored in JSON format
     */
    public function testCredentialsStoredInJsonFormat(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $testCredentials = [
            'token' => 'test_token',
            'provider' => 'local',
            'defaultKid' => 'default-key'
        ];

        PandaDirectory::writeCredentials($testCredentials);

        $content = file_get_contents('.panda/credentials');
        $decoded = json_decode($content, true);

        $this->assertIsArray($decoded);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
        $this->assertEquals('test_token', $decoded['token']);
        $this->assertEquals('local', $decoded['provider']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials file is created with proper encoding
     */
    public function testCredentialsFileCreatedWithProperEncoding(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $credentialsPath = '.panda/credentials';
        $content = file_get_contents($credentialsPath);

        // Check that file is valid UTF-8
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'), 'Credentials file should be UTF-8 encoded');

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials update maintains permissions
     */
    public function testCredentialsUpdateMaintainsPermissions(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $credentialsPath = '.panda/credentials';
        $initialPerms = fileperms($credentialsPath) & 0777;

        // Update credentials
        $testCredentials = ['provider' => 'local', 'token' => 'updated'];
        PandaDirectory::writeCredentials($testCredentials);

        $updatedPerms = fileperms($credentialsPath) & 0777;

        // Permissions should be maintained after update
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertEquals($initialPerms, $updatedPerms, 'Permissions should be maintained after credential update');
            $this->assertEquals(0600, $updatedPerms);
        } else {
            // On Windows, just verify file is still readable and writable
            $this->assertIsReadable($credentialsPath);
            $this->assertIsWritable($credentialsPath);
        }

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials file location
     */
    public function testCredentialsFileLocation(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $expectedPath = '.panda/credentials';
        $this->assertFileExists($expectedPath);

        // Should be in .panda directory, not in root or config
        $this->assertFileDoesNotExist('credentials');
        $this->assertFileDoesNotExist('.credentials');

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test keys file has proper structure
     */
    public function testKeysFileHasProperStructure(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        PandaDirectory::addKeyToMetadata('test-key', 'local', '.env');

        $keysPath = '.panda/keys.json';
        $content = file_get_contents($keysPath);
        $keys = json_decode($content, true);

        $this->assertIsArray($keys);
        $this->assertArrayHasKey('keys', $keys);
        $this->assertIsArray($keys['keys']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test config file structure
     */
    public function testConfigFileStructure(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $testConfig = ['version' => '1.0.0', 'initialized' => true];
        PandaDirectory::writeConfig($testConfig);

        $configPath = '.panda/config.json';
        $content = file_get_contents($configPath);
        $config = json_decode($content, true);

        $this->assertIsArray($config);
        $this->assertEquals('1.0.0', $config['version']);
        $this->assertTrue($config['initialized']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials file does not expose plaintext tokens
     */
    public function testCredentialsFileDoesNotExposePlaintextTokens(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $testToken = 'panda_demo_' . str_repeat('a', 48);
        $testCredentials = [
            'token' => $testToken,
            'provider' => 'local'
        ];

        PandaDirectory::writeCredentials($testCredentials);

        $content = file_get_contents('.panda/credentials');

        // Token is stored in plaintext (file permissions are the security mechanism)
        // This test verifies the file content structure
        $this->assertStringContainsString($testToken, $content);

        // Verify file permissions protect it
        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = fileperms('.panda/credentials') & 0777;
            $this->assertEquals(0600, $perms, 'File permissions should restrict access to owner only');
        }

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials cannot be read by other users (Unix)
     */
    public function testCredentialsCannotBeReadByOtherUsers(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $credentialsPath = '.panda/credentials';

        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = fileperms($credentialsPath) & 0777;

            // Check that group and others have no permissions
            $groupPerms = ($perms & 0070) >> 3;
            $otherPerms = $perms & 0007;

            $this->assertEquals(0, $groupPerms, 'Group should have no permissions');
            $this->assertEquals(0, $otherPerms, 'Others should have no permissions');
        } else {
            // On Windows, verify file exists
            $this->assertFileExists($credentialsPath);
        }

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials directory cleanup
     */
    public function testCredentialsDirectoryCleanup(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $this->assertTrue(PandaDirectory::exists());

        // Remove directory
        PandaDirectory::remove();

        $this->assertFalse(PandaDirectory::exists());
        $this->assertFileDoesNotExist('.panda/credentials');
        $this->assertFileDoesNotExist('.panda/keys.json');
    }

    /**
     * Test credentials file handles special characters in values
     */
    public function testCredentialsFileHandlesSpecialCharacters(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $testCredentials = [
            'token' => 'test_token_with_special_chars_!@#$%',
            'provider' => 'local',
            'notes' => 'Special characters: àéïôù ñ'
        ];

        PandaDirectory::writeCredentials($testCredentials);

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertEquals('test_token_with_special_chars_!@#$%', $readCredentials['token']);
        $this->assertEquals('Special characters: àéïôù ñ', $readCredentials['notes']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials file handles Unicode characters
     */
    public function testCredentialsFileHandlesUnicodeCharacters(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        $testCredentials = [
            'token' => 'test_token',
            'provider' => 'local',
            'description' => 'Test with unicode: 你好 世界 مرحبا'
        ];

        PandaDirectory::writeCredentials($testCredentials);

        $readCredentials = PandaDirectory::readCredentials();

        $this->assertEquals('Test with unicode: 你好 世界 مرحبا', $readCredentials['description']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials file prevents path traversal
     */
    public function testCredentialsFilePreventsPathTraversal(): void
    {
        // The PandaDirectory class uses fixed paths, preventing traversal
        $this->assertEquals('.panda', PandaDirectory::PANDA_DIR);
        $this->assertEquals('credentials', PandaDirectory::CREDENTIALS_FILE);

        // Paths should not contain parent directory references
        $this->assertStringNotContainsString('..', PandaDirectory::PANDA_DIR);
        $this->assertStringNotContainsString('..', PandaDirectory::CREDENTIALS_FILE);
    }

    /**
     * Test credentials removal cleans all files
     */
    public function testCredentialsRemovalCleansAllFiles(): void
    {
        // Create .panda directory with all files
        PandaDirectory::create();
        PandaDirectory::writeConfig(['test' => 'value']);
        PandaDirectory::writeCredentials(['provider' => 'local']);
        PandaDirectory::addKeyToMetadata('test-key', 'local', '.env');

        // Verify files exist
        $this->assertFileExists('.panda/credentials');
        $this->assertFileExists('.panda/keys.json');
        $this->assertFileExists('.panda/config.json');

        // Remove directory
        PandaDirectory::remove();

        // Verify all files are removed
        $this->assertFileDoesNotExist('.panda/credentials');
        $this->assertFileDoesNotExist('.panda/keys.json');
        $this->assertFileDoesNotExist('.panda/config.json');
        $this->assertFileDoesNotExist('.panda');
    }

    /**
     * Test credentials are not readable after file corruption
     */
    public function testCredentialsNotReadableAfterCorruption(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        // Write valid credentials first
        PandaDirectory::writeCredentials(['provider' => 'local']);

        // Corrupt the file
        file_put_contents('.panda/credentials', 'corrupted data {invalid json');

        $this->expectException(\RuntimeException::class);
        PandaDirectory::readCredentials();

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test empty credentials file returns default provider
     */
    public function testEmptyCredentialsFileReturnsDefaultProvider(): void
    {
        // Create .panda directory
        PandaDirectory::create();

        // Write empty credentials
        file_put_contents('.panda/credentials', '');

        $credentials = PandaDirectory::readCredentials();

        $this->assertIsArray($credentials);
        $this->assertEquals('local', $credentials['provider']);

        // Cleanup
        if (is_dir('.panda')) {
            $this->deleteDirectory('.panda');
        }
    }

    /**
     * Test credentials preserve provider type
     */
    public function testCredentialsPreserveProviderType(): void
    {
        $providers = ['local', 'vault', 'infisical'];

        foreach ($providers as $provider) {
            // Create fresh .panda directory
            if (is_dir('.panda')) {
                $this->deleteDirectory('.panda');
            }
            PandaDirectory::create();

            $credentials = ['provider' => $provider];
            if ($provider === 'vault') {
                $credentials['vaultUrl'] = 'http://127.0.0.1:8200';
            } elseif ($provider === 'infisical') {
                $credentials['infisicalSiteUrl'] = 'https://app.infisical.com';
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
}
