<?php

namespace Panda\Integrations\Laravel;

use Illuminate\Support\ServiceProvider;
use Panda\Crypto\KeyProvider;
use Panda\Crypto\KeyProviderFactory;
use Panda\Crypto\FieldCipher;
use Panda\Audit\AuditLogger;
use Panda\Telemetry\TelemetryClient;

class PandaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyProvider::class, function ($app) {
            $driver = config('panda.key_provider_driver', 'local');

            return match ($driver) {
                'vault' => KeyProviderFactory::create('vault', [
                    'vaultUrl' => config('panda.vault.url'),
                    'vaultToken' => config('panda.vault.token'),
                    'mountPath' => config('panda.vault.mount_path', 'secret'),
                    'namespace' => config('panda.vault.namespace'),
                    'roleId' => config('panda.vault.role_id'),
                    'secretId' => config('panda.vault.secret_id'),
                    'authMethod' => config('panda.vault.auth_method'),
                    'tokenTTL' => config('panda.vault.token_ttl', 3600),
                    'cacheTTL' => config('panda.vault.cache_ttl', 300),
                ]),
                'infisical' => KeyProviderFactory::create('infisical', [
                    'siteUrl' => config('panda.infisical.site_url'),
                    'clientId' => config('panda.infisical.client_id'),
                    'clientSecret' => config('panda.infisical.client_secret'),
                    'projectId' => config('panda.infisical.project_id'),
                    'environment' => config('panda.infisical.environment', 'dev'),
                    'cacheTTL' => config('panda.infisical.cache_ttl', 300),
                ]),
                default => KeyProviderFactory::create('local'),
            };
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
