<?php

namespace Panda\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RotateKeyCommand extends Command
{
    protected static $defaultName = 'rotate-key';

    protected function configure(): void
    {
        $this
            ->setName('rotate-key')
            ->setDescription('Rotate encryption key')
            ->addOption('new-kid', 'n', InputOption::VALUE_REQUIRED, 'New Key Identifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $newKid = $input->getOption('new-kid');
        if (!$newKid) {
            $output->writeln("<error>Error: --new-kid is required.</error>");
            return Command::FAILURE;
        }

        $normalizedKid = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $newKid));
        $envVarName = "PANDA_KEY_{$normalizedKid}";

        // Generate 32 bytes key
        $keyBytes = random_bytes(32);
        $keyBase64 = base64_encode($keyBytes);

        $envFile = getcwd() . '/.env';
        $exampleFile = getcwd() . '/.env.example';

        // Write new key
        $this->appendEnv($envFile, $envVarName, $keyBase64);
        $this->appendEnv($exampleFile, $envVarName, '');

        // Set default write KID
        $this->appendEnv($envFile, 'PANDA_DEFAULT_KID', $newKid);
        $this->appendEnv($exampleFile, 'PANDA_DEFAULT_KID', '');

        $output->writeln("<info>Key rotated successfully!</info>");
        $output->writeln("New active write KID: <comment>{$newKid}</comment>");
        $output->writeln("New key written to .env: <comment>{$envVarName}</comment>");

        return Command::SUCCESS;
    }

    private function appendEnv(string $filePath, string $name, string $value): void
    {
        $content = '';
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
        }

        if (preg_match("/^{$name}=/m", $content)) {
            $content = preg_replace("/^{$name}=.*/m", "{$name}={$value}", $content);
        } else {
            $content .= "\n{$name}={$value}\n";
        }

        file_put_contents($filePath, trim($content) . "\n");
    }
}
