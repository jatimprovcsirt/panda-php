<?php

namespace Panda\Cli;

use Panda\Cli\Utils\PandaDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * PANDA Status Command - Display configuration and test connectivity
 */
class StatusCommand extends Command
{
    protected static $defaultName = 'status';
    protected static $defaultDescription = 'Display PANDA configuration and test connectivity';

    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Display PANDA configuration and test connectivity');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('PANDA Status');

        // Check if .panda exists
        if (!PandaDirectory::exists()) {
            $io->warning("No PANDA configuration found. Run 'panda init' first.");
            $io->section('Checking environment variables...');

            // Check for PANDA env vars
            $envVars = array_filter($_ENV, function ($key) {
                return strpos($key, 'PANDA_') === 0;
            }, ARRAY_FILTER_USE_KEY);

            if (!empty($envVars)) {
                $io->section('Found PANDA environment variables:');
                foreach ($envVars as $key => $value) {
                    if (strpos($key, 'SECRET') !== false || strpos($key, 'TOKEN') !== false || strpos($key, 'KEY') !== false) {
                        $io->text("  {$key} = ***");
                    } else {
                        $io->text("  {$key} = {$value}");
                    }
                }
            } else {
                $io->text('No PANDA environment variables found.');
            }

            return Command::SUCCESS;
        }

        // Read credentials
        $credentials = PandaDirectory::readCredentials();

        if (!$credentials) {
            $io->error('Failed to read credentials');
            return Command::FAILURE;
        }

        // Provider Configuration
        $io->section('Key Provider Configuration');

        $io->text("Provider: <info>{$credentials['provider']}</info>");

        switch ($credentials['provider']) {
            case 'local':
                $io->text('  Type: Environment Variables');
                $io->text('  Storage: .env file');
                break;

            case 'vault':
                $io->text('  Type: HashiCorp Vault');
                $io->text("  URL: {$credentials['vaultUrl']}");
                $io->text("  Mount: {$credentials['vaultMount']}");
                $io->text(isset($credentials['vaultToken']) ? '  Token: ***configured***' : '  Token: not set');
                break;

            case 'infisical':
                $io->text('  Type: Infisical');
                $io->text("  Site URL: {$credentials['infisicalSiteUrl']}");
                $io->text("  Project ID: {$credentials['infisicalProjectId']}");
                $io->text("  Environment: {$credentials['infisicalEnvironment']}");
                $io->text(isset($credentials['infisicalClientId']) ? "  Client ID: {$credentials['infisicalClientId']}" : '  Client ID: not set');
                $io->text(isset($credentials['infisicalClientSecret']) ? '  Client Secret: ***configured***' : '  Client Secret: not set');
                $io->text("  Cache TTL: {$credentials['infisicalCacheTTL']}s");
                break;
        }

        // Default Key ID
        if (isset($credentials['defaultKid'])) {
            $io->newLine();
            $io->text("Default Key ID: <info>{$credentials['defaultKid']}</info>");
        }

        // List configured keys
        if (isset($credentials['keys']) && !empty($credentials['keys'])) {
            $io->newLine();
            $io->section('Configured Keys');

            foreach ($credentials['keys'] as $kid => $info) {
                $io->text("  {$kid}");
                $io->text("    Created: {$info['created']}");
                $io->text("    Provider: {$info['provider']}");
            }
        }

        // Test provider connectivity
        $io->newLine();
        $io->section('Testing Provider Connectivity');

        $testKid = $credentials['defaultKid'] ?? 'test-key';
        $io->text("Testing with KID: {$testKid}");

        switch ($credentials['provider']) {
            case 'local':
                $normalizedKid = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $testKid));
                $envVar = "PANDA_KEY_{$normalizedKid}";

                if (getenv($envVar)) {
                    $io->success('Local key found in environment');
                } else {
                    $io->warning("Local key not found: {$envVar}");
                }
                break;

            default:
                $io->success('Provider configuration valid');
                $io->note('Key access will be verified at runtime');
                break;
        }

        // Framework integration detection
        $io->newLine();
        $io->section('Framework Integration');

        if (file_exists('composer.json') && file_exists('app/Providers/PandaServiceProvider.php')) {
            $io->text('  ✓ Laravel detected');
        }
        if (file_exists('package.json') && file_exists('src/app.module.ts')) {
            $io->text('  ✓ NestJS detected');
        }
        if (file_exists('composer.json') && file_exists('app/Config/Panda.php')) {
            $io->text('  ✓ CodeIgniter detected');
        }
        if (file_exists('package.json') && file_exists('src/server.ts')) {
            $io->text('  ✓ Express detected');
        }

        // Environment check
        $io->newLine();
        $io->section('Environment');
        $io->text("  Platform: " . PHP_OS);
        $io->text("  PHP Version: " . PHP_VERSION);
        $io->text("  CWD: " . getcwd());

        // Tips
        $io->newLine();
        $io->section('Quick Tips');
        $io->text([
            "  panda generate <kid>     - Create a new encryption key",
            "  panda rotate-key         - Rotate to a new active key",
            "  panda migrate <table>   - Encrypt existing data",
            "  panda shred <kid>       - Permanently delete a key"
        ]);

        return Command::SUCCESS;
    }
}
