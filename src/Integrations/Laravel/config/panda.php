<?php

return [
    /*
     |--------------------------------------------------------------------------
     | PANDA Key Provider Driver
     |--------------------------------------------------------------------------
     |
     | Determines which key provider to use for encryption/decryption.
     | Options: 'local', 'vault', 'infisical'
     |
     */
    'key_provider_driver' => env('PANDA_KEY_PROVIDER_DRIVER', 'local'),

    /*
     |--------------------------------------------------------------------------
     | PANDA Default Key Identifier
     |--------------------------------------------------------------------------
     |
     | This is the default key identifier (KID) used for encryption and
     | decryption when no specific KID is provided.
     |
     */
    'default_kid' => env('PANDA_DEFAULT_KID', 'default-key'),

    /*
     |--------------------------------------------------------------------------
     | PANDA Access Token
     |--------------------------------------------------------------------------
     |
     | Used for the init-time token gate to initialize local master keys.
     |
     */
    'access_token' => env('PANDA_ACCESS_TOKEN'),

    /*
     |--------------------------------------------------------------------------
     | HashiCorp Vault Configuration
     |--------------------------------------------------------------------------
     |
     | Configuration for HashiCorp Vault KV v2 secrets engine.
     |
     */
    'vault' => [
        'url' => env('PANDA_VAULT_URL'),
        'token' => env('PANDA_VAULT_TOKEN'),
        'mount_path' => env('PANDA_VAULT_MOUNT_PATH', 'secret'),
    ],

    /*
     |--------------------------------------------------------------------------
     | Infisical Configuration
     |--------------------------------------------------------------------------
     |
     | Configuration for Infisical secrets management.
     |
     */
    'infisical' => [
        'site_url' => env('PANDA_INFISICAL_SITE_URL', 'https://app.infisical.com'),
        'client_id' => env('PANDA_INFISICAL_CLIENT_ID'),
        'client_secret' => env('PANDA_INFISICAL_CLIENT_SECRET'),
        'project_id' => env('PANDA_INFISICAL_PROJECT_ID'),
        'environment' => env('PANDA_INFISICAL_ENVIRONMENT', 'dev'),
        'cache_ttl' => env('PANDA_INFISICAL_CACHE_TTL', 300),
    ],
];
