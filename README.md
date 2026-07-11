# PANDA PHP SDK

**PANDA (Private And Secure Data Access)** — AES-256-GCM field-level encryption SDK for PHP applications.

## Requirements

- PHP ≥ 8.1 with `ext-openssl`
- Composer

## Installation

```bash
composer require jatimprovcsirt/panda-php
```

## Quick Start

```php
use Panda\Crypto\LocalKeyProvider;
use Panda\Crypto\FieldCipher;

// 1. Generate a key (run once, requires a valid token per FR-28)
//    php bin/panda init --kid opd-dukcapil-key-1 --token panda_demo_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2

// 2. Load key from environment
$keyProvider = new LocalKeyProvider();
$cipher = new FieldCipher($keyProvider);

// 3. Encrypt
$envelope = $cipher->encrypt('3201012501990001', 'opd-dukcapil-key-1');
$json = $envelope->toJson(); // Store this in your TEXT column

// 4. Decrypt (masked by default)
$masked = $cipher->decrypt($envelope);
// → "32************01"

// 5. Decrypt (full value — audited)
$raw = $cipher->decryptRaw($envelope);
// → "3201012501990001"
```

## Laravel Integration

```php
// In your Model:
class Citizen extends Model
{
    protected $casts = [
        'nik' => \Panda\Integrations\Laravel\EncryptedCast::class,
    ];
}

// Write — encrypted transparently on save:
$citizen->nik = '3201012501990001';
$citizen->save();

// Read — masked by default:
$citizen->nik; // "32************01"
```

Register the service provider in `config/app.php` or via auto-discovery:

```php
Panda\Integrations\Laravel\PandaServiceProvider::class
```

## CLI Commands

```bash
php bin/panda init --kid <key-id> --token <token>   # Generate local 256-bit key (requires token)
php bin/panda rotate-key --new-kid <kid>           # Rotate to a new key (no token needed)
php bin/panda shred <kid>                    # Permanently delete a key
php bin/panda migrate <table> <col> <kid>    # Encrypt plaintext column
php bin/panda migrate --dry-run <table> ...  # Estimate migration scope
```

## Blind Indexing (Searchable Encryption)

```php
use Panda\BlindIndex\BlindIndexer;

$index = BlindIndexer::generate($nik, $blindIndexKey, 'nik');
// Store in a separate VARCHAR(64) indexed column for WHERE lookups
```

## Security

See [SECURITY.md](docs/security/SECURITY.md) for responsible disclosure.

## License

MIT
