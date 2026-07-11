<?php

namespace Panda\Cli;

use Panda\Crypto\TokenValidator;
use Panda\Exception\InvalidTokenException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InitCommand extends Command
{
    protected static $defaultName = 'init';

    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Initialize local PANDA master key')
            ->addOption('kid', 'k', InputOption::VALUE_REQUIRED, 'Key Identifier', 'default-key')
            ->addOption(
                'token',
                't',
                InputOption::VALUE_REQUIRED,
                'Per-application access token (falls back to PANDA_ACCESS_TOKEN env var). '
                . 'Required to generate a new master key (FR-28). '
                . 'This is the only command that requires a token — encrypt/decrypt/mask never need one.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Step 1: Token gate (FR-28/FR-29)
        // This is the ONLY network-dependent step (post-portal) and the ONLY place
        // in the entire SDK where portal reachability can legitimately block an operation.
        $token = $input->getOption('token') ?: getenv('PANDA_ACCESS_TOKEN');

        try {
            TokenValidator::validateToken($token ?: '');
        } catch (InvalidTokenException $e) {
            $output->writeln("<error>Token gate failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        // Demo token warning (Technical PRD Section 14.3)
        if (TokenValidator::isDemoToken($token) && !TokenValidator::isLocalEnvironment()) {
            $output->writeln(
                "<comment>⚠️ WARNING: You are using the static DEMO TOKEN outside a local development environment.\n"
                . "Please register your application at the PANDA portal to obtain a per-application live token.</comment>"
            );
        }

        $output->writeln("<info>✓ token gate passed (format check, pre-portal — Section 14.2)</info>");

        // Step 2: Key generation (FR-12, unchanged)
        // The key is generated locally via CSPRNG and NEVER transmitted over the network.
        $kid = $input->getOption('kid');
        $normalizedKid = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $kid));
        $envVarName = "PANDA_KEY_{$normalizedKid}";

        // Generate 32 bytes key
        $keyBytes = random_bytes(32);
        $keyBase64 = base64_encode($keyBytes);

        $envFile = getcwd() . '/.env';
        $exampleFile = getcwd() . '/.env.example';

        // Write to .env
        $this->appendEnv($envFile, $envVarName, $keyBase64);
        // Write placeholder to .env.example
        $this->appendEnv($exampleFile, $envVarName, '');

        $output->writeln("<info>✓ local master key generated, written to .env</info>");
        $output->writeln("Generated key for KID: <comment>{$kid}</comment>");
        $output->writeln("Variable written to .env: <comment>{$envVarName}</comment>");
        $output->writeln("<comment>Keep this key secret and secure!</comment>");
        $output->writeln(
            "# From here on, this application never contacts the portal again for\n"
            . "# encrypt/decrypt/mask, rotate, or migrate — Section 9, Section 14."
        );

        return Command::SUCCESS;
    }

    private function appendEnv(string $filePath, string $name, string $value): void
    {
        $content = '';
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
        }

        // Check if variable already exists
        if (preg_match("/^{$name}=/m", $content)) {
            // Replace existing value
            $content = preg_replace("/^{$name}=.*/m", "{$name}={$value}", $content);
        } else {
            // Append
            $content .= "\n{$name}={$value}\n";
        }

        file_put_contents($filePath, trim($content) . "\n");
    }
}
