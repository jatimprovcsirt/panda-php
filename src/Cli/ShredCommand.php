<?php

namespace Panda\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ShredCommand extends Command
{
    protected static $defaultName = 'shred';

    protected function configure(): void
    {
        $this
            ->setName('shred')
            ->setDescription('Crypto-shred: permanently delete an encryption key')
            ->addArgument('kid', InputArgument::REQUIRED, 'Key Identifier to destroy')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $kid = $input->getArgument('kid');
        $force = $input->getOption('force');

        if (!$force) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                "<question>Are you absolutely sure you want to shred key '{$kid}'? ALL data encrypted with this key will be permanently lost! (y/n)</question> ",
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln("<info>Action cancelled.</info>");
                return Command::SUCCESS;
            }
        }

        $normalizedKid = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $kid));
        $envVarName = "PANDA_KEY_{$normalizedKid}";

        $envFile = getcwd() . '/.env';
        $exampleFile = getcwd() . '/.env.example';

        $shredded = false;
        foreach ([$envFile, $exampleFile] as $filePath) {
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                if (preg_match("/^{$envVarName}=/m", $content)) {
                    $content = preg_replace("/^{$envVarName}=.*\n?/m", "", $content);
                    file_put_contents($filePath, $content);
                    $shredded = true;
                }
            }
        }

        if ($shredded) {
            $output->writeln("<error>🔥 Key '{$kid}' has been permanently SHREDDED from configuration!</error>");
        } else {
            $output->writeln("<comment>Key '{$kid}' was not found in environment configuration.</comment>");
        }

        return Command::SUCCESS;
    }
}
