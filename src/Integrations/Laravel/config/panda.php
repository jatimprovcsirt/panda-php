<?php

return [
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
];
