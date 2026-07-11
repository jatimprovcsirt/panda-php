<?php

namespace Panda\Tests;

use PHPUnit\Framework\TestCase;
use Panda\Crypto\LocalKeyProvider;
use Panda\Crypto\FieldCipher;
use Panda\Crypto\Envelope;
use Panda\Masking\Masker;
use Panda\BlindIndex\BlindIndexer;
use Panda\Exception\AuthenticationFailedException;
use Panda\Exception\EnvelopeVersionException;
use Panda\Exception\UnsupportedAlgorithmException;
use Panda\Exception\MalformedEnvelopeException;
use Exception;

class FieldCipherTest extends TestCase
{
    private array $vectors;

    protected function setUp(): void
    {
        $vectorPath = __DIR__ . '/../spec/vectors/vectors.json';
        if (!file_exists($vectorPath)) {
            $vectorPath = __DIR__ . '/../../panda-spec/vectors/vectors.json';
        }
        if (!file_exists($vectorPath)) {
            $this->fail("Test vectors file not found at: {$vectorPath}");
        }
        $this->vectors = json_decode(file_get_contents($vectorPath), true);
    }

    public function testPositiveVectors(): void
    {
        foreach ($this->vectors['positive'] as $vector) {
            $keyProvider = new LocalKeyProvider();
            $keyProvider->setKey($vector['kid'], base64_decode($vector['key']));
            $cipher = new FieldCipher($keyProvider);

            // 1. Decrypt raw from vector envelope
            $envelopeData = $vector['envelope'];
            $envelope = new Envelope(
                $envelopeData['v'],
                $envelopeData['alg'],
                $envelopeData['kid'],
                $envelopeData['nonce'],
                $envelopeData['ciphertext']
            );

            $decryptedRaw = $cipher->decryptRaw($envelope);
            $this->assertEquals($vector['plaintext'], $decryptedRaw, "Failed raw decryption for: " . $vector['description']);

            // 2. Decrypt masked and check expected masking
            $decryptedMasked = $cipher->decrypt($envelope);
            $expectedMasked = Masker::mask($vector['plaintext']);
            $this->assertEquals($expectedMasked, $decryptedMasked, "Failed masked decryption for: " . $vector['description']);

            // 3. Encrypt and decrypt roundtrip
            $newEnvelope = $cipher->encrypt($vector['plaintext'], $vector['kid']);
            $roundtripDecrypted = $cipher->decryptRaw($newEnvelope);
            $this->assertEquals($vector['plaintext'], $roundtripDecrypted, "Failed roundtrip encryption/decryption for: " . $vector['description']);
        }
    }

    public function testNegativeVectors(): void
    {
        foreach ($this->vectors['negative'] as $vector) {
            $keyProvider = new LocalKeyProvider();
            $keyProvider->setKey($vector['kid'], base64_decode($vector['key']));
            $cipher = new FieldCipher($keyProvider);

            $envelopeData = $vector['envelope'];
            $envelope = new Envelope(
                $envelopeData['v'],
                $envelopeData['alg'],
                $envelopeData['kid'],
                $envelopeData['nonce'],
                $envelopeData['ciphertext']
            );

            $expectedError = $vector['expected_error'];
            $exceptionClass = null;

            switch ($expectedError) {
                case 'AuthenticationFailed':
                    $exceptionClass = AuthenticationFailedException::class;
                    break;
                case 'UnsupportedEnvelopeVersion':
                    $exceptionClass = EnvelopeVersionException::class;
                    break;
                case 'UnsupportedAlgorithm':
                    $exceptionClass = UnsupportedAlgorithmException::class;
                    break;
                case 'MalformedEnvelope':
                    $exceptionClass = MalformedEnvelopeException::class;
                    break;
            }

            if ($exceptionClass) {
                $this->expectException($exceptionClass);
            }

            try {
                $cipher->decryptRaw($envelope);
            } catch (Exception $e) {
                if ($exceptionClass && is_a($e, $exceptionClass)) {
                    // Success: exception matched
                    throw $e;
                }
                $this->fail("Unexpected exception " . get_class($e) . " thrown for " . $vector['description'] . ". Expected: " . $expectedError);
            }

            $this->fail("Expected exception " . $expectedError . " was not thrown for: " . $vector['description']);
        }
    }

    public function testBlindIndexing(): void
    {
        $key = "thisisasecretkeyof32byteslength!";
        
        $nik = "32-01-01-250199-0001";
        $email = " User@Example.COM  ";

        $nikIndex = BlindIndexer::generate($nik, $key, 'nik');
        $this->assertEquals(BlindIndexer::generate("3201012501990001", $key, 'none'), $nikIndex);

        $emailIndex = BlindIndexer::generate($email, $key, 'email');
        $this->assertEquals(BlindIndexer::generate("user@example.com", $key, 'none'), $emailIndex);
    }
}
