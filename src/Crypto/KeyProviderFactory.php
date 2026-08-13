<?php

namespace Panda\Crypto;

use Panda\Exception\KeyNotFoundException;

/**
 * Factory for creating KeyProvider instances based on driver type
 */
class KeyProviderFactory
{
    /**
     * Create a KeyProvider instance based on the driver type
     *
     * @param string $driver Type of key provider ('local', 'vault', or 'infisical')
     * @param array $config Configuration array for the selected provider
     * @return KeyProvider
     * @throws KeyNotFoundException if required config is missing
     */
    public static function create(string $driver, array $config = []): KeyProvider
    {
        return match ($driver) {
            'vault' => self::createVaultProvider($config),
            'infisical' => self::createInfisicalProvider($config),
            default => new LocalKeyProvider($config['initialKeys'] ?? []),
        };
    }

    /**
     * Create a VaultKeyProvider with validation
     *
     * @param array $config
     * @return VaultKeyProvider
     * @throws KeyNotFoundException
     */
    private static function createVaultProvider(array $config): VaultKeyProvider
    {
        if (!isset($config['vaultUrl'], $config['vaultToken'])) {
            throw new KeyNotFoundException(
                'VaultKeyProvider requires vaultUrl and vaultToken configuration'
            );
        }

        return new VaultKeyProvider(
            $config['vaultUrl'],
            $config['vaultToken'],
            $config['mountPath'] ?? 'secret'
        );
    }

    /**
     * Create an InfisicalKeyProvider with validation
     *
     * @param array $config
     * @return InfisicalKeyProvider
     * @throws KeyNotFoundException
     */
    private static function createInfisicalProvider(array $config): InfisicalKeyProvider
    {
        $required = ['siteUrl', 'clientId', 'clientSecret', 'projectId', 'environment'];
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new KeyNotFoundException(
                    "InfisicalKeyProvider requires {$key} configuration"
                );
            }
        }

        return new InfisicalKeyProvider(
            $config['siteUrl'],
            $config['clientId'],
            $config['clientSecret'],
            $config['projectId'],
            $config['environment'],
            $config['cacheTTL'] ?? 300
        );
    }
}
