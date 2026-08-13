<?php

namespace Panda\Integrations\CodeIgniter;

use CodeIgniter\Config\BaseService;
use CodeIgniter\Config\Config;
use Panda\Crypto\KeyProvider;
use Panda\Crypto\LocalKeyProvider;
use Panda\Crypto\KeyProviderFactory;
use Panda\Crypto\FieldCipher;
use Panda\Audit\AuditLogger;
use Panda\Telemetry\TelemetryClient;

class PandaService extends BaseService
{
    /**
     * Return the FieldCipher service.
     */
    public static function fieldCipher(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('fieldCipher');
        }

        // Get key provider driver from config (defaults to 'local')
        $driver = env('PANDA_KEY_PROVIDER_DRIVER', 'local');

        // Create key provider based on driver
        $keyProvider = self::createKeyProvider($driver);

        // CodeIgniter 4 uses Services::logger() for logging
        $ciLogger = null;
        if (class_exists('\Config\Services')) {
            try {
                $ciLogger = \Config\Services::logger();
            } catch (\Exception $e) {
                // Fail-safe if logger is not ready
            }
        }

        $auditLogger = new AuditLogger($ciLogger);
        $telemetry = new TelemetryClient();

        return new FieldCipher($keyProvider, $auditLogger, $telemetry);
    }

    /**
     * Create KeyProvider instance based on driver configuration.
     *
     * @param string $driver Driver type: 'local', 'vault', or 'infisical'
     * @return KeyProvider
     */
    private static function createKeyProvider(string $driver): KeyProvider
    {
        return match ($driver) {
            'vault' => KeyProviderFactory::create('vault', [
                'vaultUrl' => env('PANDA_VAULT_URL'),
                'vaultToken' => env('PANDA_VAULT_TOKEN'),
                'mountPath' => env('PANDA_VAULT_MOUNT_PATH', 'secret'),
            ]),
            'infisical' => KeyProviderFactory::create('infisical', [
                'siteUrl' => env('PANDA_INFISICAL_SITE_URL', 'https://app.infisical.com'),
                'clientId' => env('PANDA_INFISICAL_CLIENT_ID'),
                'clientSecret' => env('PANDA_INFISICAL_CLIENT_SECRET'),
                'projectId' => env('PANDA_INFISICAL_PROJECT_ID'),
                'environment' => env('PANDA_INFISICAL_ENVIRONMENT', 'dev'),
                'cacheTTL' => env('PANDA_INFISICAL_CACHE_TTL', 300),
            ]),
            default => KeyProviderFactory::create('local'),
        };
    }
}
