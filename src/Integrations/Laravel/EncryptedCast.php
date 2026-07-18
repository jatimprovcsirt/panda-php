<?php

namespace Panda\Integrations\Laravel;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Panda\Crypto\FieldCipher;
use Panda\Crypto\Envelope;

class EncryptedCast implements CastsAttributes
{
    private string $kid;

    /**
     * Create a new cast instance.
     * The cast can be configured on models as:
     * 'nik' => EncryptedCast::class . ':opd-dukcapil-key-1'
     */
    public function __construct(?string $kid = null)
    {
        $this->kid = $kid ?: (getenv('PANDA_DEFAULT_KID') ?: 'default-key');
    }

    /**
     * Cast the given value (from database JSON envelope to masked plaintext).
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value) || $value === '') {
            return $value;
        }

        /** @var FieldCipher $cipher */
        $cipher = app(FieldCipher::class);

        try {
            // Check if it's already an envelope JSON
            $envelope = Envelope::fromJson($value);
            return $cipher->decrypt($envelope, 'laravel', $key);
        } catch (\Exception $e) {
            // Dual-read / shadow mode: fallback to returning raw value if not yet encrypted
            return $value;
        }
    }

    /**
     * Prepare the given value for storage (encrypt plaintext and return JSON envelope).
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if (is_null($value) || $value === '') {
            return $value;
        }

        /** @var FieldCipher $cipher */
        $cipher = app(FieldCipher::class);

        $envelope = $cipher->encrypt($value, $this->kid, 'laravel', $key);
        return $envelope->toJson();
    }

    /**
     * Decrypt the given raw envelope value to plaintext (unmasked).
     */
    public static function decryptRawValue($value, string $fieldName = 'unknown'): ?string
    {
        if (is_null($value) || $value === '') {
            return $value;
        }

        /** @var FieldCipher $cipher */
        $cipher = app(FieldCipher::class);

        try {
            $envelope = Envelope::fromJson($value);
            return $cipher->decryptRaw($envelope, 'laravel-raw', $fieldName);
        } catch (\Exception $e) {
            // Shadow mode / dual-read fallback
            return $value;
        }
    }
}
