<?php

namespace Panda\BlindIndex;

class BlindIndexer
{
    /**
     * Normalize the input plaintext based on type.
     */
    public static function normalize(string $plaintext, string $normalization): string
    {
        switch (strtolower($normalization)) {
            case 'digits':
            case 'nik':
            case 'phone':
                return preg_replace('/\D/', '', $plaintext);
            case 'lowercase':
            case 'email':
                return strtolower(trim($plaintext));
            case 'trim':
                return trim($plaintext);
            case 'none':
            case 'raw':
            default:
                return $plaintext;
        }
    }

    /**
     * Generate deterministic blind index.
     */
    public static function generate(string $plaintext, string $key, string $normalization = 'lowercase'): string
    {
        $normalized = self::normalize($plaintext, $normalization);
        $hmac = hash_hmac('sha256', $normalized, $key, true);
        return base64_encode($hmac);
    }
}
