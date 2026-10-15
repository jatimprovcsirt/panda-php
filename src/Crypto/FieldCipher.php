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

    /**
     * The telemetry client and the token are gone from this class.
     *
     * They existed so every cipher carried both and could make a network
     * request inside encrypt() and decryptRaw() — once per operation, from a
     * library whose promise is that it works without the portal. Counting is
     * now a process-local counter increment (TelemetryClient::count), and the
     * token comes from the credentials `panda init` wrote, at report time.
     */
    public function __construct(KeyProvider $keys, ?AuditLogger $audit = null)
    {
        $this->keys = $keys;
        $this->audit = $audit ?? new AuditLogger();
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

        TelemetryClient::count('encrypts');

        return $envelope;
    }

    /**
     * Decrypt Envelope and return masked plaintext by default.
     */
    public function decrypt(Envelope $envelope, string $actor = 'system', string $field = 'unknown', array $maskingConfig = []): string
    {
        // The label goes in rather than being logged here as well. The shared
        // implementation logs every failure, so logging again here wrote two
        // audit entries per masked read — one of them claiming a raw decrypt,
        // which is the entry the whole audit trail exists to make meaningful.
        $rawPlaintext = $this->decryptRawImpl($envelope, $actor, $field, 'decrypt_masked');
        $masked = Masker::mask($rawPlaintext, $maskingConfig);

        $this->audit->log($actor, $field, $envelope->kid, 'decrypt_masked', 'success');
        TelemetryClient::count('decrypts');

        return $masked;
    }

    /**
     * Decrypt Envelope and return raw plaintext.
     */
    public function decryptRaw(Envelope $envelope, string $actor = 'system', string $field = 'unknown'): string
    {
        $plaintext = $this->decryptRawImpl($envelope, $actor, $field, 'decrypt_raw');

        $this->audit->log($actor, $field, $envelope->kid, 'decrypt_raw', 'success');
        TelemetryClient::count('decryptsRaw');

        return $plaintext;
    }

    /**
     * The shared implementation, private and uncounted.
     *
     * Both public methods count themselves, and count once.
     */
    private function decryptRawImpl(Envelope $envelope, string $actor, string $field, string $label): string
    {
        if ($envelope->v !== 1) {
            $this->audit->log($actor, $field, $envelope->kid, $label, 'unsupported_envelope_version');
            throw new EnvelopeVersionException("Unsupported envelope version: {$envelope->v}");
        }

        if (strtolower($envelope->alg) !== 'aes-256-gcm') {
            $this->audit->log($actor, $field, $envelope->kid, $label, 'unsupported_algorithm');
            throw new UnsupportedAlgorithmException("Unsupported algorithm: {$envelope->alg}");
        }

        try {
            $key = $this->keys->getKey($envelope->kid);
        } catch (KeyNotFoundException $e) {
            $this->audit->log($actor, $field, $envelope->kid, $label, 'key_not_found');
            throw $e;
        }

        $nonce = base64_decode($envelope->nonce, true);
        if ($nonce === false || strlen($nonce) !== 12) {
            $this->audit->log($actor, $field, $envelope->kid, $label, 'malformed_envelope');
            throw new MalformedEnvelopeException("Invalid nonce in envelope.");
        }

        $combined = base64_decode($envelope->ciphertext, true);
        if ($combined === false || strlen($combined) < 16) {
            $this->audit->log($actor, $field, $envelope->kid, $label, 'malformed_envelope');
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
            $this->audit->log($actor, $field, $envelope->kid, $label, 'auth_failure');
            throw new AuthenticationFailedException("Ciphertext decryption / authentication failed.");
        }

        // No success entry here. The two public methods log their own,
        // because only they know which one ran.
        return $plaintext;
    }
}
