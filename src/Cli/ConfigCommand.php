<?php

namespace Panda\Cli;

use Panda\Cli\Utils\PandaDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * PANDA Config Command - Display configuration details
 */
class ConfigCommand extends Command
{
    protected static $defaultName = 'config';
    protected static $defaultDescription = 'Display PANDA configuration details';

    protected function configure(): void
    {
        $this
            ->setName('config')
            ->setDescription('Display PANDA configuration details');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('PANDA Configuration');

        // Read credentials
        $credentials = PandaDirectory::readCredentials();

        if (!$credentials) {
            $io->warning("No PANDA configuration found. Run 'panda init' first.");
            return Command::SUCCESS;
        }

        // Display provider configuration
        $io->section('Provider Configuration');

        $provider = $credentials['provider'] ?? 'unknown';
        $io->text("Provider: <info>{$provider}</info>");

        switch ($provider) {
            case 'local':
                $io->text('  Type: Environment Variables');
                $io->text('  Storage: .env file');
                $io->text('  Keys stored as: PANDA_KEY_<KID>');
                break;

            case 'vault':
                $io->text('  Type: HashiCorp Vault');
                $io->text("  URL: {$credentials['vaultUrl']}");
                $vaultMount = $credentials['vaultMount'] ?? 'secret';
                $io->text("  Mount Path: {$vaultMount}");
                $io->text(isset($credentials['vaultToken']) ? '  Token: ***configured***' : '  Token: not set');
                $io->text('  Keys stored at: <mount>/<kid>');
                break;

            case 'infisical':
                $io->text('  Type: Infisical Secrets Manager');
                $io->text("  Site URL: {$credentials['infisicalSiteUrl']}");
                $io->text("  Project ID: {$credentials['infisicalProjectId']}");
                $io->text("  Environment: {$credentials['infisicalEnvironment']}");
                $io->text(isset($credentials['infisicalClientId']) ? "  Client ID: {$credentials['infisicalClientId']}" : '  Client ID: not set');
                $io->text(isset($credentials['infisicalClientSecret']) ? '  Client Secret: ***configured***' : '  Client Secret: not set');
                $io->text("  Cache TTL: {$credentials['infisicalCacheTTL']}s");
                $io->text('  Keys stored as: secrets in Infisical');
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

        // Display .env.example template
        $io->newLine();
        $io->section('Environment Variables Template');

        switch ($provider) {
            case 'local':
                $io->text([
                    '# Local Key Provider',
                    'PANDA_KEY_PROVIDER_DRIVER=local',
                    'PANDA_DEFAULT_KID=default-key',
                    'PANDA_KEY_DEFAULT_KEY=<base64-encoded-key>'
                ]);
                break;

            case 'vault':
                $vaultMount = $credentials['vaultMount'] ?? 'secret';
                $io->text([
                    '# Vault Key Provider',
                    'PANDA_KEY_PROVIDER_DRIVER=vault',
                    'PANDA_DEFAULT_KID=default-key',
                    "PANDA_VAULT_URL={$credentials['vaultUrl']}",
                    'PANDA_VAULT_TOKEN=<vault-token>',
                    "PANDA_VAULT_MOUNT_PATH={$vaultMount}"
                ]);
                break;

            case 'infisical':
                $io->text([
                    '# Infisical Key Provider',
                    'PANDA_KEY_PROVIDER_DRIVER=infisical',
                    'PANDA_DEFAULT_KID=default-key',
                    "PANDA_INFISICAL_SITE_URL={$credentials['infisicalSiteUrl']}",
                    "PANDA_INFISICAL_CLIENT_ID=" . ($credentials['infisicalClientId'] ?? '<client-id>'),
                    'PANDA_INFISICAL_CLIENT_SECRET=<client-secret>',
                    "PANDA_INFISICAL_PROJECT_ID={$credentials['infisicalProjectId']}",
                    "PANDA_INFISICAL_ENVIRONMENT={$credentials['infisicalEnvironment']}",
                    "PANDA_INFISICAL_CACHE_TTL={$credentials['infisicalCacheTTL']}"
                ]);
                break;
        }

        // Current working directory info
        $io->newLine();
        $io->section('Working Directory');
        $io->text("  Path: " . getcwd());

        $pandaPath = getcwd() . DIRECTORY_SEPARATOR . PandaDirectory::PANDA_DIR;
        if (is_dir($pandaPath)) {
            $io->text('  .panda directory: ✓ exists');
        } else {
            $io->text('  .panda directory: ✗ not found');
        }

        // Environment info
        $io->newLine();
        $io->section('Environment');
        $io->text("  Platform: " . PHP_OS);
        $io->text("  PHP Version: " . PHP_VERSION);

        return Command::SUCCESS;
    }
}
