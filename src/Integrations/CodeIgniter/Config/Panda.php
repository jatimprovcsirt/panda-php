<?php

namespace Panda\Integrations\CodeIgniter\Config;

use CodeIgniter\Config\BaseConfig;

class Panda extends BaseConfig
{
    /**
     * Key Provider Driver
     *
     * Determines which key provider to use for encryption/decryption.
     * Options: 'local', 'vault', 'infisical'
     *
     * @var string
     */
    public $keyProviderDriver = 'local';

    /**
     * Default Key Identifier
     *
     * The default key identifier (KID) used for encryption and
     * decryption when no specific KID is provided.
     *
     * @var string
     */
    public $defaultKid = 'default-key';

    /**
     * HashiCorp Vault Configuration
     *
     * Configuration for HashiCorp Vault KV v2 secrets engine.
     *
     * @var array
     */
    public $vault = [
        'url' => null,
        'token' => null,
        'mountPath' => 'secret',
    ];

    /**
     * Infisical Configuration
     *
     * Configuration for Infisical secrets management.
     *
     * @var array
     */
    public $infisical = [
        'siteUrl' => 'https://app.infisical.com',
        'clientId' => null,
        'clientSecret' => null,
        'projectId' => null,
        'environment' => 'dev',
        'cacheTTL' => 300,
    ];

    /**
     * PANDA Access Token
     *
     * Used for the init-time token gate to initialize local master keys.
     *
     * @var string|null
     */
    public $accessToken = null;

    /**
     * Auto-discover configuration from environment variables.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        // Auto-populate from environment variables
        $this->keyProviderDriver = env('PANDA_KEY_PROVIDER_DRIVER', $this->keyProviderDriver);
        $this->defaultKid = env('PANDA_DEFAULT_KID', $this->defaultKid);
        $this->accessToken = env('PANDA_ACCESS_TOKEN', $this->accessToken);

        // Vault configuration
        $this->vault = [
            'url' => env('PANDA_VAULT_URL'),
            'token' => env('PANDA_VAULT_TOKEN'),
            'mountPath' => env('PANDA_VAULT_MOUNT_PATH', 'secret'),
        ];

        // Infisical configuration
        $this->infisical = [
            'siteUrl' => env('PANDA_INFISICAL_SITE_URL', $this->infisical['siteUrl']),
            'clientId' => env('PANDA_INFISICAL_CLIENT_ID'),
            'clientSecret' => env('PANDA_INFISICAL_CLIENT_SECRET'),
            'projectId' => env('PANDA_INFISICAL_PROJECT_ID'),
            'environment' => env('PANDA_INFISICAL_ENVIRONMENT', 'dev'),
            'cacheTTL' => env('PANDA_INFISICAL_CACHE_TTL', 300),
        ];
    }
}
