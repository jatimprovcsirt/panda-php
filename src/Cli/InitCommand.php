<?php

namespace Panda\Cli;

use Panda\Cli\Utils\PandaDirectory;
use Panda\Crypto\TokenValidator;
use Panda\Exception\InvalidTokenException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

class InitCommand extends Command
{
    protected static $defaultName = 'init';

    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Initialize PANDA with interactive wizard')
            ->addOption('kid', 'k', InputOption::VALUE_REQUIRED, 'Key Identifier (quick mode)', 'default-key')
            ->addOption(
                'token',
                't',
                InputOption::VALUE_OPTIONAL,
                'Per-application access token'
            )
            ->addOption('quick', 'q', InputOption::VALUE_NONE, 'Skip wizard, use quick setup with local provider')
            ->addOption('reinit', null, InputOption::VALUE_NONE, 'Reinitialize even if already initialized')
            ->addOption('skip-key-gen', null, InputOption::VALUE_NONE, 'Skip first key generation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        // Check if already initialized
        if (PandaDirectory::exists() && !$input->getOption('reinit')) {
            $io->warning('PANDA is already initialized.');
            $io->note('Use --reinit to reinitialize.');
            return Command::SUCCESS;
        }

        $token = $input->getOption('token') ?: getenv('PANDA_ACCESS_TOKEN');

        if ($input->getOption('quick')) {
            return $this->runQuickInit($io, $token, $input->getOption('skip-key-gen'));
        }

        return $this->runWizard($input, $output, $io, $helper, $token, $input->getOption('skip-key-gen'));
    }

    private function runQuickInit(SymfonyStyle $io, ?string $token, bool $skipKeyGen): int
    {
        $io->title('PANDA Quick Init');

        // Validate token
        if ($token) {
            try {
                TokenValidator::validateToken($token);
                $io->success('Token validated');
            } catch (InvalidTokenException $e) {
                $io->error("Token gate failed: " . $e->getMessage());
                return Command::FAILURE;
            }

            if (TokenValidator::isDemoToken($token) && !TokenValidator::isLocalEnvironment()) {
                $io->warning('You are using the static DEMO TOKEN outside a local development environment.');
                $io->note('Please register your application at the PANDA portal to obtain a per-application live token.');
            }
        }

        // Create .panda directory
        PandaDirectory::create();

        // Store credentials
        $credentials = [
            'token' => $token,
            'provider' => 'local',
        ];

        PandaDirectory::writeCredentials($credentials);

        // Generate default key
        if (!$skipKeyGen) {
            $this->generateAndStoreKey($io, 'default-key', $credentials, 'Default PANDA key');
        }

        $io->success('PANDA initialized successfully!');
        $io->note("Run 'panda status' to verify configuration.");

        return Command::SUCCESS;
    }

    private function runWizard(InputInterface $input, OutputInterface $output, SymfonyStyle $io, $helper, ?string $token, bool $skipKeyGen): int
    {
        $io->title('PANDA Initialization Wizard');

        // Step 1: Authentication
        $io->section('Step 1: Authentication');

        if (!$token) {
            $question = new Question('Enter your PANDA access token (or press Enter to skip): ');
            $token = $helper->ask($input, $output, $question);
        }

        if ($token) {
            try {
                TokenValidator::validateToken($token);
                $io->success('Token format validated');
            } catch (InvalidTokenException $e) {
                $io->warning('Token validation failed: ' . $e->getMessage());
                if (!$io->confirm('Continue anyway?', false)) {
                    $io->note('Initialization cancelled.');
                    return Command::SUCCESS;
                }
            }
        }

        // Create .panda directory
        PandaDirectory::create();

        // Step 2: Select provider
        $io->section('Step 2: Key Provider Selection');
        $io->text('Where should encryption keys be stored?');

        $question = new ChoiceQuestion(
            'Enter choice (default: Local)',
            ['Local (environment variables)', 'HashiCorp Vault', 'Infisical'],
            0
        );
        $choice = $helper->ask($input, $output, $question);

        $credentials = ['token' => $token];

        switch ($choice) {
            case 1: // Vault
                $credentials = $this->setupVault($input, $output, $io, $helper, $token);
                break;
            case 2: // Infisical
                $credentials = $this->setupInfisical($input, $output, $io, $helper, $token);
                break;
            default: // Local
                $credentials['provider'] = 'local';
                break;
        }

        // Step 3: Default Key ID
        $io->section('Step 3: Default Key Configuration');

        $question = new Question('Default Key ID [default: default-key]: ', 'default-key');
        $defaultKid = $helper->ask($input, $output, $question);
        $credentials['defaultKid'] = $defaultKid;

        // Write credentials
        PandaDirectory::writeCredentials($credentials);

        // Step 4: Generate key
        $io->section('Step 4: Key Generation');

        if (!$skipKeyGen) {
            $this->generateAndStoreKey($io, $defaultKid, $credentials, 'Default key from wizard');
        }

        // Step 5: Framework selection
        $io->section('Step 5: Framework Integration');

        $question = new ChoiceQuestion(
            'Select your framework (default: None/Skip)',
            ['Laravel', 'NestJS', 'CodeIgniter', 'Express', 'None/Skip'],
            4
        );
        $framework = $helper->ask($input, $output, $question);

        switch ($framework) {
            case 'Laravel':
                $this->printLaravelInstructions($io, $credentials, $defaultKid);
                break;
            case 'NestJS':
                $this->printNestJSInstructions($io, $credentials, $defaultKid);
                break;
        }

        $io->success('PANDA initialization complete!');
        $io->note([
            "Next steps:",
            "  - Use 'panda generate <kid>' to create encryption keys",
            "  - Use 'panda status' to verify configuration",
            "  - Use 'panda migrate' to encrypt existing data"
        ]);

        return Command::SUCCESS;
    }

    private function setupVault(InputInterface $input, OutputInterface $output, SymfonyStyle $io, $helper, ?string $token): array
    {
        $io->text('Configuring HashiCorp Vault...');

        $question = new Question('Vault URL [default: http://127.0.0.1:8200]: ', 'http://127.0.0.1:8200');
        $vaultUrl = $helper->ask($input, $output, $question);

        // Authentication method selection
        $io->text("\nSelect Vault authentication method:");
        $io->text('  1. AppRole (Recommended - Auto-renewal, production-ready)');
        $io->text('  2. Token (Advanced - Manual renewal)');

        $question = new Question('Choose method [default: 1]: ', '1');
        $authChoice = $helper->ask($input, $output, $question);

        $vaultAuthMethod = 'token';
        $vaultToken = null;
        $vaultRoleId = null;
        $vaultSecretId = null;

        if ($authChoice === '1') {
            // AppRole authentication
            $vaultAuthMethod = 'approle';
            $io->text("\nUsing AppRole authentication (auto-renewal enabled)");

            $question = new Question('Role ID: ');
            $vaultRoleId = $helper->ask($input, $output, $question);

            $question = new Question('Secret ID: ');
            $question->setHidden(true);
            $vaultSecretId = $helper->ask($input, $output, $question);

            // Optional: Token TTL
            $io->text("\nToken TTL (Optional)");
            $io->text('  Token lifetime before auto-renewal. Default: 3600s (1 hour)');
            $io->text('  Press Enter to use default, or enter custom value (300-86400 seconds)');

            $question = new Question('Token TTL in seconds [default: 3600]: ', '3600');
            $vaultTokenTTL = $helper->ask($input, $output, $question);
        } else {
            // Token authentication
            $vaultAuthMethod = 'token';
            $io->text("\nUsing Token authentication (manual renewal required)");

            $question = new Question('Vault Token: ');
            $question->setHidden(true);
            $vaultToken = $helper->ask($input, $output, $question);
        }

        $question = new Question('Mount path [default: secret]: ', 'secret');
        $vaultMount = $helper->ask($input, $output, $question);

        $result = [
            'token' => $token,
            'provider' => 'vault',
            'vaultUrl' => $vaultUrl,
            'vaultAuthMethod' => $vaultAuthMethod,
            'vaultMount' => $vaultMount
        ];

        if ($vaultToken) {
            $result['vaultToken'] = $vaultToken;
        }
        if ($vaultRoleId) {
            $result['vaultRoleId'] = $vaultRoleId;
        }
        if ($vaultSecretId) {
            $result['vaultSecretId'] = $vaultSecretId;
        }
        if (isset($vaultTokenTTL)) {
            $result['vaultTokenTTL'] = $vaultTokenTTL;
        }

        return $result;
    }

    private function setupInfisical(InputInterface $input, OutputInterface $output, SymfonyStyle $io, $helper, ?string $token): array
    {
        $io->text('Configuring Infisical...');

        $question = new Question('Infisical Site URL [default: https://app.infisical.com]: ', 'https://app.infisical.com');
        $siteUrl = $helper->ask($input, $output, $question);

        $question = new Question('Machine Identity Client ID: ');
        $clientId = $helper->ask($input, $output, $question);

        $question = new Question('Machine Identity Client Secret: ');
        $question->setHidden(true);
        $clientSecret = $helper->ask($input, $output, $question);

        $question = new Question('Project ID: ');
        $projectId = $helper->ask($input, $output, $question);

        $question = new Question('Environment [default: dev]: ', 'dev');
        $environment = $helper->ask($input, $output, $question);

        $question = new Question('Cache TTL in seconds [default: 300]: ', '300');
        $cacheTTL = $helper->ask($input, $output, $question);

        return [
            'token' => $token,
            'provider' => 'infisical',
            'infisicalSiteUrl' => $siteUrl,
            'infisicalClientId' => $clientId,
            'infisicalClientSecret' => $clientSecret,
            'infisicalProjectId' => $projectId,
            'infisicalEnvironment' => $environment,
            'infisicalCacheTTL' => $cacheTTL
        ];
    }

    private function generateAndStoreKey(OutputInterface $output, string $kid, array &$credentials, ?string $description): void
    {
        $output->writeln("Generating key for KID: {$kid}");

        if ($description) {
            $output->writeln("Description: {$description}");
        }

        // Generate 32-byte random key
        $keyBytes = random_bytes(32);
        $keyBase64 = base64_encode($keyBytes);

        switch ($credentials['provider']) {
            case 'local':
                $this->storeLocalKey($output, $kid, $keyBase64, $credentials);
                break;
            case 'vault':
                $this->storeVaultKey($output, $kid, $keyBase64, $credentials);
                break;
            case 'infisical':
                $this->storeInfisicalKey($output, $kid, $keyBase64, $credentials);
                break;
        }
    }

    private function storeLocalKey(OutputInterface $output, string $kid, string $keyBase64, array &$credentials): void
    {
        $normalizedKid = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $kid));
        $envVarName = "PANDA_KEY_{$normalizedKid}";

        $envPath = '.env';
        $examplePath = '.env.example';

        $this->appendEnv($envPath, $envVarName, $keyBase64);
        $this->appendEnv($examplePath, $envVarName, '');

        $output->writeln("✓ Key stored in .env as {$envVarName}");
        $output->writeln("<comment>⚠️  IMPORTANT: Commit .env to your secret management system!</comment>");

        // Update credentials keys metadata
        if (!isset($credentials['keys'])) {
            $credentials['keys'] = [];
        }
        $credentials['keys'][$kid] = [
            'created' => date('c'),
            'provider' => 'local'
        ];

        PandaDirectory::writeCredentials($credentials);
    }

    private function storeVaultKey(OutputInterface $output, string $kid, string $keyBase64, array &$credentials): void
    {
        $output->writeln("Storing key in Vault at {$credentials['vaultMount']}/{$kid}");
        $output->writeln("Note: Manual setup required. Use Vault CLI or UI:");
        $output->writeln("  vault kv put {$credentials['vaultMount']}/{$kid} value={$keyBase64}");

        // Update credentials keys metadata
        if (!isset($credentials['keys'])) {
            $credentials['keys'] = [];
        }
        $credentials['keys'][$kid] = [
            'created' => date('c'),
            'provider' => 'vault'
        ];

        PandaDirectory::writeCredentials($credentials);
    }

    private function storeInfisicalKey(OutputInterface $output, string $kid, string $keyBase64, array &$credentials): void
    {
        $output->writeln("Storing key in Infisical at path: {$kid}");
        $output->writeln("Note: Manual setup may be required. Create secret in Infisical UI:");
        $output->writeln("  Path: {$kid}");
        $output->writeln("  Value (base64): {$keyBase64}");

        // Update credentials keys metadata
        if (!isset($credentials['keys'])) {
            $credentials['keys'] = [];
        }
        $credentials['keys'][$kid] = [
            'created' => date('c'),
            'provider' => 'infisical'
        ];

        PandaDirectory::writeCredentials($credentials);
    }

    private function printLaravelInstructions(OutputInterface $output, array $credentials, string $defaultKid): void
    {
        $output->writeln("\nFor Laravel, add these to your .env:");
        $output->writeln("PANDA_KEY_PROVIDER_DRIVER={$credentials['provider']}");
        $output->writeln("PANDA_DEFAULT_KID={$defaultKid}");

        if ($credentials['provider'] === 'vault') {
            $output->writeln("PANDA_VAULT_URL={$credentials['vaultUrl']}");
            $output->writeln("PANDA_VAULT_AUTH_METHOD={$credentials['vaultAuthMethod']}");
            if ($credentials['vaultAuthMethod'] === 'approle') {
                $output->writeln("PANDA_VAULT_ROLE_ID={$credentials['vaultRoleId']}");
                $output->writeln("PANDA_VAULT_SECRET_ID=***");
            } else {
                $output->writeln("PANDA_VAULT_TOKEN=***");
            }
            if (isset($credentials['vaultTokenTTL'])) {
                $output->writeln("PANDA_VAULT_TOKEN_TTL={$credentials['vaultTokenTTL']}");
            }
            $output->writeln("PANDA_VAULT_MOUNT_PATH={$credentials['vaultMount']}");
        } elseif ($credentials['provider'] === 'infisical') {
            $output->writeln("PANDA_INFISICAL_SITE_URL={$credentials['infisicalSiteUrl']}");
            $output->writeln("PANDA_INFISICAL_CLIENT_ID={$credentials['infisicalClientId']}");
            $output->writeln("PANDA_INFISICAL_CLIENT_SECRET=***");
            $output->writeln("PANDA_INFISICAL_PROJECT_ID={$credentials['infisicalProjectId']}");
            $output->writeln("PANDA_INFISICAL_ENVIRONMENT={$credentials['infisicalEnvironment']}");
        }
    }

    private function printNestJSInstructions(OutputInterface $output, array $credentials, string $defaultKid): void
    {
        $output->writeln("\nFor NestJS, use PandaModule.forRoot() in your app.module.ts:");
        $output->writeln("PandaModule.forRoot({");
        $output->writeln("  keyProvider: '{$credentials['provider']}',");
        if ($credentials['provider'] === 'infisical') {
            $output->writeln("  infisical: {");
            $output->writeln("    siteUrl: '{$credentials['infisicalSiteUrl']}',");
            $output->writeln("    clientId: '{$credentials['infisicalClientId']}',");
            $output->writeln("    clientSecret: '***',");
            $output->writeln("    projectId: '{$credentials['infisicalProjectId']}',");
            $output->writeln("    environment: '{$credentials['infisicalEnvironment']}'");
            $output->writeln("  }");
        }
        $output->writeln("})");
    }

    private function appendEnv(string $filePath, string $name, string $value): void
    {
        $content = '';
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
        }

        $regex = "/^{$name}=.*\\r?\\n?/m";
        if (preg_match($regex, $content)) {
            $content = preg_replace($regex, "{$name}={$value}\n", $content);
        } else {
            $content .= "\n{$name}={$value}\n";
        }

        file_put_contents($filePath, trim($content) . "\n");
    }
}
