<?php

namespace Panda\Integrations\Laravel;

use Panda\BlindIndex\BlindIndexer;
use Panda\Crypto\KeyProvider;
use Panda\Crypto\Envelope;

trait HasEncryptedFields
{
    /**
     * Boot the trait and hook into Eloquent saving event.
     */
    protected static function bootHasEncryptedFields(): void
    {
        static::saving(function ($model) {
            $model->processEncryptedFieldsAndBlindIndices();
        });
    }

    /**
     * Automatically encrypt and update blind index values on save.
     */
    public function processEncryptedFieldsAndBlindIndices(): void
    {
        if (!property_exists($this, 'encryptedFields') || !is_array($this->encryptedFields)) {
            return;
        }

        foreach ($this->encryptedFields as $field => $config) {
            if (empty($config['blind_index'])) {
                continue;
            }

            // Determine blind index column name (defaults to {$field}_bidx)
            $blindIndexColumn = is_string($config['blind_index'])
                ? $config['blind_index']
                : "{$field}_bidx";

            // Process if the field is dirty/modified
            if ($this->isDirty($field) || ($this->exists === false && isset($this->attributes[$field]))) {
                $envelopeJson = $this->attributes[$field] ?? null;

                if (is_null($envelopeJson) || $envelopeJson === '') {
                    $this->attributes[$blindIndexColumn] = null;
                    continue;
                }

                // Retrieve raw plaintext to generate index
                try {
                    $envelope = Envelope::fromJson($envelopeJson);
                    $plaintext = app(\Panda\Crypto\FieldCipher::class)->decryptRaw($envelope, 'laravel-blind-index', $field);
                } catch (\Exception $e) {
                    // Fallback to value itself if not encrypted (dual-read / shadow / raw mode)
                    $plaintext = $envelopeJson;
                }

                // Resolve blind index key
                $kid = $config['kid'] ?? (getenv('PANDA_DEFAULT_KID') ?: 'default-key');
                $blindIndexKid = $config['blind_index_kid'] ?? "{$kid}-idx";

                /** @var KeyProvider $keyProvider */
                $keyProvider = app(KeyProvider::class);
                $blindIndexKey = $keyProvider->getKey($blindIndexKid);

                $normalization = $config['normalization'] ?? 'lowercase';
                $blindIndexValue = BlindIndexer::generate($plaintext, $blindIndexKey, $normalization);

                $this->attributes[$blindIndexColumn] = $blindIndexValue;
            }
        }
    }

    /**
     * Get the raw decrypted value of an encrypted attribute.
     */
    public function getRawEncrypted(string $key): ?string
    {
        $rawAttribute = $this->getAttributes()[$key] ?? null;
        return EncryptedCast::decryptRawValue($rawAttribute, $key);
    }

    /**
     * Query scope to search by encrypted field via its blind index.
     */
    public function scopeWhereEncrypted($query, string $field, string $value)
    {
        if (!property_exists($this, 'encryptedFields') || !isset($this->encryptedFields[$field])) {
            return $query->where($field, $value);
        }

        $config = $this->encryptedFields[$field];
        if (empty($config['blind_index'])) {
            throw new \InvalidArgumentException("Field '{$field}' is not configured for blind indexing.");
        }

        $blindIndexColumn = is_string($config['blind_index'])
            ? $config['blind_index']
            : "{$field}_bidx";

        $kid = $config['kid'] ?? (getenv('PANDA_DEFAULT_KID') ?: 'default-key');
        $blindIndexKid = $config['blind_index_kid'] ?? "{$kid}-idx";

        /** @var KeyProvider $keyProvider */
        $keyProvider = app(KeyProvider::class);
        $blindIndexKey = $keyProvider->getKey($blindIndexKid);

        $normalization = $config['normalization'] ?? 'lowercase';
        $blindIndexValue = BlindIndexer::generate($value, $blindIndexKey, $normalization);

        return $query->where($blindIndexColumn, $blindIndexValue);
    }
}
