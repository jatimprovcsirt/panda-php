<?php

namespace Panda\Integrations\Laravel;

use Illuminate\Support\ServiceProvider;
use Panda\Crypto\KeyProvider;
use Panda\Crypto\LocalKeyProvider;
use Panda\Crypto\FieldCipher;
use Panda\Audit\AuditLogger;
use Panda\Telemetry\TelemetryClient;

class PandaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyProvider::class, function ($app) {
            // Defaults to LocalKeyProvider. Adopters can override this binding for Vault or HSM.
            return new LocalKeyProvider();
        });

        $this->app->singleton(AuditLogger::class, function ($app) {
            // Integrates with Laravel's default logger
            $laravelLogger = $app->make('log');
            return new AuditLogger($laravelLogger);
        });

        $this->app->singleton(TelemetryClient::class, function ($app) {
            return new TelemetryClient();
        });

        $this->app->singleton(FieldCipher::class, function ($app) {
            return new FieldCipher(
                $app->make(KeyProvider::class),
                $app->make(AuditLogger::class),
                $app->make(TelemetryClient::class)
            );
        });
    }

    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__ . '/config/panda.php' => config_path('panda.php'),
        ], 'panda-config');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\MakeFieldCommand::class,
            ]);
        }
    }
}
