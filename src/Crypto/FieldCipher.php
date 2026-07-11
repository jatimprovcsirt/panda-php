<?php

namespace Panda\Crypto;

use Panda\Audit\AuditLogger;
use Panda\Telemetry\TelemetryClient;
use Panda\Masking\Masker;
use Panda\Exception\KeyNotFoundException;
use Panda\Exception\EnvelopeVersionException;
use Panda\Exception\UnsupportedAlgorithmException;
use Panda\Exception\MalformedEnvelopeException;
use Panda\Exception\AuthenticationFailedException;
use Exception;

class FieldCipher
{
    private KeyProvider $keys;
    private AuditLogger $audit;
    private TelemetryClient $telemetry;
    private ?string $telemetryToken;

    public function __construct(
        KeyProvider $keys,
        ?AuditLogger $audit = null,
        ?TelemetryClient $telemetry = null,
        ?string $telemetryToken = null
    ) {
        $this->keys = $keys;
        $this->audit = $audit ?? new AuditLogger();
        $this->telemetry = $telemetry ?? new TelemetryClient();
        $this->telemetryToken = $telemetryToken ?: (getenv('PANDA_PORTAL_TOKEN') ?: null);
    }

    /**
     * Encrypt plaintext string and return Envelope.
     */
    public function encrypt(string $plaintext, string $kid, string $actor = 'system', string $field = 'unknown'): Envelope
    {
        try {
            $key = $this->keys->getKey($kid);
        } catch (KeyNotFoundException $e) {
            $this->audit->log($actor, $field, $kid, 'encrypt', 'key_not_found');
            throw $e;
        }

        $nonce = random_bytes(12); // CSPRNG IV
        
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            $this->audit->log($actor, $field, $kid, 'encrypt', 'encryption_failed');
            throw new Exception("Encryption failed.");
        }

        $combinedCiphertext = $ciphertext . $tag;

        $envelope = new Envelope(
            1,
            'aes-256-gcm',
            $kid,
            base64_encode($nonce),
            base64_encode($combinedCiphertext)
        );

        $this->audit->log($actor, $field, $kid, 'encrypt', 'success');

        // Report telemetry fail-silently if token is present
        if ($this->telemetryToken) {
            $this->telemetry->report($this->telemetryToken, $key);
        }

        return $envelope;
    }

    /**
     * Decrypt Envelope and return masked plaintext by default.
     */
    public function decrypt(Envelope $envelope, string $actor = 'system', string $field = 'unknown', array $maskingConfig = []): string
    {
        try {
            $rawPlaintext = $this->decryptRaw($envelope, $actor, $field);
            $masked = Masker::mask($rawPlaintext, $maskingConfig);
            $this->audit->log($actor, $field, $envelope->kid, 'decrypt_masked', 'success');
            return $masked;
        } catch (Exception $e) {
            // Log outcome in decryptRaw has already logged the failure type
            throw $e;
        }
    }

    /**
     * Decrypt Envelope and return raw plaintext.
     */
    public function decryptRaw(Envelope $envelope, string $actor = 'system', string $field = 'unknown'): string
    {
        if ($envelope->v !== 1) {
            $this->audit->log($actor, $field, $envelope->kid, 'decrypt_raw', 'unsupported_envelope_version');
            throw new EnvelopeVersionException("Unsupported envelope version: {$envelope->v}");
        }

        if (strtolower($envelope->alg) !== 'aes-256-gcm') {
            $this->audit->log($actor, $field, $envelope->kid, 'decrypt_raw', 'unsupported_algorithm');
            throw new UnsupportedAlgorithmException("Unsupported algorithm: {$envelope->alg}");
        }

        try {
            $key = $this->keys->getKey($envelope->kid);
        } catch (KeyNotFoundException $e) {
            $this->audit->log($actor, $field, $envelope->kid, 'decrypt_raw', 'key_not_found');
            throw $e;
        }

        $nonce = base64_decode($envelope->nonce, true);
        if ($nonce === false || strlen($nonce) !== 12) {
            $this->audit->log($actor, $field, $envelope->kid, 'decrypt_raw', 'malformed_envelope');
            throw new MalformedEnvelopeException("Invalid nonce in envelope.");
        }

        $combined = base64_decode($envelope->ciphertext, true);
        if ($combined === false || strlen($combined) < 16) {
            $this->audit->log($actor, $field, $envelope->kid, 'decrypt_raw', 'malformed_envelope');
            throw new MalformedEnvelopeException("Invalid ciphertext in envelope.");
        }

        $ctLen = strlen($combined) - 16;
        $ciphertext = substr($combined, 0, $ctLen);
        $tag = substr($combined, $ctLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            $this->audit->log($actor, $field, $envelope->kid, 'decrypt_raw', 'auth_failure');
            throw new AuthenticationFailedException("Ciphertext decryption / authentication failed.");
        }

        $this->audit->log($actor, $field, $envelope->kid, 'decrypt_raw', 'success');

        // Report telemetry fail-silently if token is present
        if ($this->telemetryToken) {
            $this->telemetry->report($this->telemetryToken, $key);
        }

        return $plaintext;
    }
}
