<?php

namespace Panda\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Panda\Cli\InitCommand;
use Panda\Cli\Utils\PandaDirectory;
use Panda\Crypto\TokenValidator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--provider` on the non-interactive path.
 *
 * panda-node had this flag and the other three did not, so `init --quick` here
 * could only ever configure a local provider: Vault and Infisical were
 * unreachable from a CI pipeline in three of the four SDKs. The flag set is
 * pinned in panda-spec/cli-flags.md and checked by verify_interop.js.
 *
 * The variable names are read from the command's own constants rather than
 * written out again. A test that restates a rule asserts its own copy of it —
 * which is how the token pattern's second copy survived a release, and how
 * this file's sibling asserted the `-q` shortcut that stopped `init` loading.
 */
class InitCommandProviderTest extends TestCase
{
    private string $workDir;
    private string $originalDir;

    /** Every provider variable, so each test states the environment it needs. */
    private const PROVIDER_VARS = [
        'PANDA_VAULT_URL',
        'PANDA_VAULT_AUTH_METHOD',
        'PANDA_VAULT_ROLE_ID',
        'PANDA_VAULT_SECRET_ID',
        'PANDA_VAULT_TOKEN',
        'PANDA_VAULT_MOUNT_PATH',
        'PANDA_VAULT_TOKEN_TTL',
        'PANDA_INFISICAL_SITE_URL',
        'PANDA_INFISICAL_CLIENT_ID',
        'PANDA_INFISICAL_CLIENT_SECRET',
        'PANDA_INFISICAL_PROJECT_ID',
        'PANDA_INFISICAL_ENVIRONMENT',
        'PANDA_INFISICAL_CACHE_TTL',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        putenv('PANDA_SKIP_ONLINE_VALIDATION=1');
        foreach (self::PROVIDER_VARS as $name) {
            putenv($name);
        }

        $this->originalDir = getcwd();
        $this->workDir = sys_get_temp_dir() . '/panda-provider-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalDir);
        $this->deleteDirectory($this->workDir);

        putenv('PANDA_SKIP_ONLINE_VALIDATION');
        foreach (self::PROVIDER_VARS as $name) {
            putenv($name);
        }

        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function runInit(array $input): CommandTester
    {
        // Registered with an application rather than run bare: the command
        // asks for the `question` helper, which only exists once a command
        // belongs to an application. Running it bare cannot reach the code
        // under test at all, and the failure looks like a missing helper
        // rather than a missing assertion.
        $application = new Application();
        $application->setAutoExit(false);
        $command = new InitCommand();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->setInputs([]);
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function credentials(): array
    {
        return PandaDirectory::readCredentials() ?: [];
    }

    public function testProviderOptionExistsAndTakesAValue(): void
    {
        $option = (new InitCommand())->getDefinition()->getOption('provider');

        $this->assertTrue($option->isValueRequired());
        // No shortcut, as `--quick` has none: Symfony reserves shorter letters
        // for its own globals, and this project has already lost a command to
        // a shortcut collision.
        $this->assertNull($option->getShortcut());
    }

    public function testRegistersWithTheApplicationWithoutOptionCollisions(): void
    {
        $application = new Application();
        $command = new InitCommand();
        $application->add($command);

        $command->mergeApplicationDefinition();

        $this->assertTrue($command->getDefinition()->hasOption('provider'));
    }

    public function testVaultWithoutItsVariablesFailsClosed(): void
    {
        $tester = $this->runInit([
            '--quick' => true,
            '--token' => TokenValidator::DEMO_TOKEN,
            '--kid' => 'k1',
            '--provider' => 'vault',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString(InitCommand::VAULT_REQUIRED_VARS[0], $tester->getDisplay());
        $this->assertDirectoryDoesNotExist(
            $this->workDir . '/' . PandaDirectory::PANDA_DIR,
            'wrote a configuration it could not complete'
        );
    }

    public function testVaultRecordsAnApproleConfiguration(): void
    {
        putenv('PANDA_VAULT_URL=https://vault.example:8200');
        putenv('PANDA_VAULT_ROLE_ID=role-1');
        putenv('PANDA_VAULT_SECRET_ID=secret-1');

        $tester = $this->runInit([
            '--quick' => true,
            '--token' => TokenValidator::DEMO_TOKEN,
            '--kid' => 'k1',
            '--provider' => 'vault',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $credentials = $this->credentials();
        $this->assertSame('vault', $credentials['provider']);
        $this->assertSame('https://vault.example:8200', $credentials['vaultUrl']);
        $this->assertSame('approle', $credentials['vaultAuthMethod']);
        $this->assertSame('role-1', $credentials['vaultRoleId']);
        $this->assertSame('secret-1', $credentials['vaultSecretId']);
    }

    public function testVaultInfersTokenAuthentication(): void
    {
        putenv('PANDA_VAULT_URL=https://vault.example:8200');
        putenv('PANDA_VAULT_TOKEN=hvs.token');

        $tester = $this->runInit([
            '--quick' => true,
            '--token' => TokenValidator::DEMO_TOKEN,
            '--kid' => 'k1',
            '--provider' => 'vault',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $credentials = $this->credentials();
        $this->assertSame('token', $credentials['vaultAuthMethod']);
        $this->assertSame('hvs.token', $credentials['vaultToken']);
    }

    public function testVaultRejectsAnUnknownAuthMethod(): void
    {
        putenv('PANDA_VAULT_URL=https://vault.example:8200');
        putenv('PANDA_VAULT_AUTH_METHOD=kerberos');

        $tester = $this->runInit([
            '--quick' => true,
            '--token' => TokenValidator::DEMO_TOKEN,
            '--kid' => 'k1',
            '--provider' => 'vault',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('kerberos', $tester->getDisplay());
    }

    public function testInfisicalNamesEveryVariableItIsMissing(): void
    {
        // Nothing set, so all three are missing and all three must be named.
        // A refusal that says only "Infisical is not configured" sends the
        // reader to the documentation; one that lists the variables sends them
        // to their shell.
        $tester = $this->runInit([
            '--quick' => true,
            '--token' => TokenValidator::DEMO_TOKEN,
            '--kid' => 'k1',
            '--provider' => 'infisical',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        foreach (InitCommand::INFISICAL_REQUIRED_VARS as $name) {
            $this->assertStringContainsString($name, $display, "{$name} not named in the refusal");
        }
    }

    public function testInfisicalRecordsItsConfiguration(): void
    {
        putenv('PANDA_INFISICAL_CLIENT_ID=cid');
        putenv('PANDA_INFISICAL_CLIENT_SECRET=csecret');
        putenv('PANDA_INFISICAL_PROJECT_ID=pid');

        $tester = $this->runInit([
            '--quick' => true,
            '--token' => TokenValidator::DEMO_TOKEN,
            '--kid' => 'k1',
            '--provider' => 'infisical',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $credentials = $this->credentials();
        $this->assertSame('infisical', $credentials['provider']);
        $this->assertSame('pid', $credentials['infisicalProjectId']);
        $this->assertSame('dev', $credentials['infisicalEnvironment'], 'the safe default was not applied');
    }

    public function testUnknownProviderIsRefused(): void
    {
        $tester = $this->runInit([
            '--quick' => true,
            '--token' => TokenValidator::DEMO_TOKEN,
            '--provider' => 'kerberos',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('kerberos', $tester->getDisplay());
    }

    public function testNoProviderStillMeansLocal(): void
    {
        $tester = $this->runInit([
            '--quick' => true,
            '--token' => TokenValidator::DEMO_TOKEN,
            '--kid' => 'k1',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('local', $this->credentials()['provider']);
        $this->assertStringContainsString('PANDA_KEY_K1=', (string) file_get_contents('.env'));
    }

    public function testANonLocalProviderWritesNoLocalKey(): void
    {
        putenv('PANDA_VAULT_URL=https://vault.example:8200');
        putenv('PANDA_VAULT_TOKEN=hvs.token');

        $this->runInit([
            '--quick' => true,
            '--token' => TokenValidator::DEMO_TOKEN,
            '--kid' => 'k1',
            '--provider' => 'vault',
        ]);

        $env = file_exists('.env') ? (string) file_get_contents('.env') : '';
        $this->assertStringNotContainsString(
            'PANDA_KEY_',
            $env,
            'a key generated "for" a secret manager is a second key nothing reads'
        );
    }
}
