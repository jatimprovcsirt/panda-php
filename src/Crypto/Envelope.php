<?php

namespace Panda\Crypto;

use Panda\Exception\MalformedEnvelopeException;

final class Envelope
{
    public function __construct(
        public readonly int $v,
        public readonly string $alg,
        public readonly string $kid,
        public readonly string $nonce,
        public readonly string $ciphertext
    ) {}

    /**
     * Parse envelope from JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new MalformedEnvelopeException("Invalid JSON envelope format.");
        }

        foreach (['v', 'alg', 'kid', 'nonce', 'ciphertext'] as $field) {
            if (!isset($data[$field])) {
                throw new MalformedEnvelopeException("Missing required envelope field: {$field}");
            }
        }

        if (!is_int($data['v'])) {
            throw new MalformedEnvelopeException("Envelope version ('v') must be an integer.");
        }

        return new self(
            $data['v'],
            (string)$data['alg'],
            (string)$data['kid'],
            (string)$data['nonce'],
            (string)$data['ciphertext']
        );
    }

    /**
     * Serialize envelope to JSON.
     */
    public function toJson(): string
    {
        // Maintain key order for test-vector compatibility
        return json_encode([
            'v' => $this->v,
            'alg' => $this->alg,
            'kid' => $this->kid,
            'nonce' => $this->nonce,
            'ciphertext' => $this->ciphertext
        ], JSON_UNESCAPED_SLASHES);
    }
}
