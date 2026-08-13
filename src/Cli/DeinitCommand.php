<?php

namespace Panda\Cli;

use Panda\Cli\Utils\PandaDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * PANDA Deinit Command - Remove PANDA configuration
 */
class DeinitCommand extends Command
{
    protected static $defaultName = 'deinit';
    protected static $defaultDescription = 'Remove PANDA configuration and .panda directory';

    protected function configure(): void
    {
        $this
            ->setName('deinit')
            ->setDescription('Remove PANDA configuration and .panda directory')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $io->title('PANDA Deinitialization');

        $pandaPath = getcwd() . DIRECTORY_SEPARATOR . PandaDirectory::PANDA_DIR;

        // Check if .panda exists
        if (!is_dir($pandaPath)) {
            $io->warning('No PANDA configuration found (.panda directory does not exist).');
            return Command::SUCCESS;
        }

        // Warning prompt
        if (!$input->getOption('force')) {
            $question = new Question(
                '⚠️  This will permanently delete your PANDA configuration including credentials and keys metadata.' . PHP_EOL .
                'Are you sure you want to continue? (yes/no): '
            );
            $question->setValidator(function ($answer) {
                $answer = strtolower(trim($answer));
                if ($answer !== 'yes' && $answer !== 'y' && $answer !== 'no' && $answer !== 'n') {
                    throw new \RuntimeException('Please answer "yes" or "no"');
                }
                return $answer;
            });

            $answer = $helper->ask($input, $output, $question);

            if (strtolower($answer) !== 'yes' && strtolower($answer) !== 'y') {
                $io->note('Deinitialization cancelled.');
                return Command::SUCCESS;
            }
        }

        // Remove .panda directory
        $io->text('Removing .panda directory...');

        try {
            PandaDirectory::remove();
            $io->success('.panda directory removed');
        } catch (\RuntimeException $e) {
            $io->error("Failed to remove .panda directory: " . $e->getMessage());
        }

        // Clean up .env files
        $io->section('Cleaning up .env files');

        $envPath = '.env';
        $examplePath = '.env.example';

        // PANDA environment variables to remove
        $pandaVars = [
            'PANDA_ACCESS_TOKEN',
            'PANDA_DEFAULT_KID',
            'PANDA_KEY_PROVIDER_DRIVER',
            'PANDA_VAULT_URL',
            'PANDA_VAULT_TOKEN',
            'PANDA_VAULT_MOUNT_PATH',
            'PANDA_INFISICAL_SITE_URL',
            'PANDA_INFISICAL_CLIENT_ID',
            'PANDA_INFISICAL_CLIENT_SECRET',
            'PANDA_INFISICAL_PROJECT_ID',
            'PANDA_INFISICAL_ENVIRONMENT',
            'PANDA_INFISICAL_CACHE_TTL',
        ];

        foreach ([$envPath, $examplePath] as $envFile) {
            if (!file_exists($envFile)) {
                continue;
            }

            $content = file_get_contents($envFile);
            $modified = false;

            // Remove specific PANDA variables
            foreach ($pandaVars as $varName) {
                $regex = "/^{$varName}=.*\\r?\\n?/m";
                if (preg_match($regex, $content)) {
                    $content = preg_replace($regex, '', $content);
                    $modified = true;
                    $io->text("  ✓ Removed {$varName} from " . basename($envFile));
                }
            }

            // Remove PANDA_KEY_* variables
            $keyRegex = "/^PANDA_KEY_.*=.*\\r?\\n?/m";
            if (preg_match($keyRegex, $content)) {
                $content = preg_replace($keyRegex, '', $content);
                $modified = true;
                $io->text("  ✓ Removed PANDA_KEY_* variables from " . basename($envFile));
            }

            if ($modified) {
                // Clean up extra blank lines
                $content = preg_replace('/\n{3,}/', "\n\n", $content);
                file_put_contents($envFile, trim($content) . "\n");
            }
        }

        $io->success('PANDA deinitialization complete!');
        $io->note("To start fresh, run: panda init");

        return Command::SUCCESS;
    }
}
