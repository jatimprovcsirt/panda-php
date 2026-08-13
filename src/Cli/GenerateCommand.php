<?php

namespace Panda\Cli;

use Panda\Cli\Utils\PandaDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * PANDA Generate Command - Generate new encryption keys
 */
class GenerateCommand extends Command
{
    protected static $defaultName = 'generate';
    protected static $defaultDescription = 'Generate a new encryption key';

    protected function configure(): void
    {
        $this
            ->setName('generate')
            ->setDescription('Generate a new encryption key (interactive or specify kid)')
            ->addArgument('kid', InputArgument::OPTIONAL, 'Key Identifier')
            ->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'Key description');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $kid = $input->getArgument('kid');
        $description = $input->getOption('description');

        // If no kid provided, run interactive mode
        if (!$kid) {
            $io->title('PANDA Key Generation');

            $question = new Question('Enter Key ID (e.g., nik-key, health-data-key): ');
            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new \RuntimeException('Key ID is required');
                }
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $answer)) {
                    throw new \RuntimeException('Key ID must contain only alphanumeric characters, hyphens, and underscores');
                }
                return $answer;
            });
            $kid = $helper->ask($input, $output, $question);

            $question = new Question('Description (optional): ');
            $description = $helper->ask($input, $output, $question);
        }

        // Validate key ID format
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $kid)) {
            $io->error("Invalid key ID: must contain only alphanumeric characters, hyphens, and underscores");
            return Command::FAILURE;
        }

        // Read credentials
        $credentials = PandaDirectory::readCredentials();

        if (!$credentials) {
            $io->error("No PANDA configuration found. Run 'panda init' first");
            return Command::FAILURE;
        }

        // Generate and store key
        if ($this->generateAndStoreKey($output, $kid, $credentials, $description)) {
            $io->success("Key '{$kid}' generated successfully!");
            $io->note("To use this key:");
            $io->text("  \$cipher->encrypt(\$value, '{$kid}', \$context, \$field)");
            return Command::SUCCESS;
        }

        return Command::FAILURE;
    }

    private function generateAndStoreKey(OutputInterface $output, string $kid, array &$credentials, ?string $description): bool
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
                return $this->storeLocalKey($output, $kid, $keyBase64, $credentials);

            case 'vault':
                return $this->storeVaultKey($output, $kid, $keyBase64, $credentials);

            case 'infisical':
                return $this->storeInfisicalKey($output, $kid, $keyBase64, $credentials);

            default:
                $output->writeln("No provider configured, using local storage");
                return $this->storeLocalKey($output, $kid, $keyBase64, $credentials);
        }
    }

    private function storeLocalKey(OutputInterface $output, string $kid, string $keyBase64, array &$credentials): bool
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

        // Add to keys metadata
        try {
            PandaDirectory::addKeyToMetadata($kid, 'local', '.env');
        } catch (\RuntimeException $e) {
            $output->writeln("<comment>{$e->getMessage()}</comment>");
        }

        return true;
    }

    private function storeVaultKey(OutputInterface $output, string $kid, string $keyBase64, array &$credentials): bool
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

        // Add to keys metadata
        try {
            PandaDirectory::addKeyToMetadata($kid, 'vault', $credentials['vaultMount']);
        } catch (\RuntimeException $e) {
            $output->writeln("<comment>{$e->getMessage()}</comment>");
        }

        return true;
    }

    private function storeInfisicalKey(OutputInterface $output, string $kid, string $keyBase64, array &$credentials): bool
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

        // Add to keys metadata
        try {
            PandaDirectory::addKeyToMetadata($kid, 'infisical', $credentials['infisicalProjectId']);
        } catch (\RuntimeException $e) {
            $output->writeln("<comment>{$e->getMessage()}</comment>");
        }

        return true;
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
