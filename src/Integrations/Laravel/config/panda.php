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
     | Supports two authentication methods:
     | 1. Token-based: Use 'token' option
     | 2. AppRole-based: Use 'role_id' and 'secret_id' options (auto-renewal)
     |
     */
    'vault' => [
        'url' => env('PANDA_VAULT_URL'),
        'token' => env('PANDA_VAULT_TOKEN'),
        'mount_path' => env('PANDA_VAULT_MOUNT_PATH', 'secret'),
        'namespace' => env('PANDA_VAULT_NAMESPACE'),

        // AppRole authentication (alternative to token)
        'role_id' => env('PANDA_VAULT_ROLE_ID'),
        'secret_id' => env('PANDA_VAULT_SECRET_ID'),
        'auth_method' => env('PANDA_VAULT_AUTH_METHOD'), // 'token' or 'approle'
        'token_ttl' => env('PANDA_VAULT_TOKEN_TTL', 3600), // Token TTL in seconds
        'cache_ttl' => env('PANDA_VAULT_CACHE_TTL', 300), // Key cache TTL in seconds
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
