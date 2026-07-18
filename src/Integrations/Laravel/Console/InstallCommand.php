<?php

namespace Panda\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'panda:install';

    protected $description = 'Install and initialize PANDA configuration and environment variables';

    public function handle(): int
    {
        $this->info('Initializing PANDA Laravel Integration...');

        // 1. Publish config
        $this->call('vendor:publish', [
            '--provider' => 'Panda\\Integrations\\Laravel\\PandaServiceProvider',
            '--tag' => 'panda-config',
        ]);

        // 2. Add PANDA_DEFAULT_KID to .env if not exists
        $envFile = base_path('.env');
        if (File::exists($envFile)) {
            $content = File::get($envFile);
            
            $envChanges = false;
            if (!str_contains($content, 'PANDA_DEFAULT_KID=')) {
                $content .= "\nPANDA_DEFAULT_KID=default-key\n";
                $envChanges = true;
            }
            
            if (!str_contains($content, 'PANDA_ACCESS_TOKEN=')) {
                $content .= "PANDA_ACCESS_TOKEN=\n";
                $envChanges = true;
            }

            if ($envChanges) {
                File::put($envFile, $content);
                $this->info('Added PANDA environment variables to .env');
            }
        }

        $this->info('PANDA installation completed successfully!');
        return 0;
    }
}
