<?php

namespace Panda\BlindIndex;

class BlindIndexer
{
    /**
     * The characters PANDA calls whitespace, as a PCRE character class.
     *
     * Written out rather than left to trim(), because trim() removes ASCII
     * whitespace only. It leaves a non-breaking space in place — and a
     * non-breaking space is what arrives whenever a value is pasted out of a
     * word processor or a spreadsheet. The other implementations remove it, so
     * a value indexed by this SDK and searched by another did not match, with
     * no error to explain why.
     *
     * This is the Unicode White_Space property, which is the set pinned in
     * panda-spec/blind-index-format.md. It is pinned rather than derived
     * because every language's built-in trim() means something slightly
     * different: PHP's removes ASCII only, JavaScript's removes a byte-order
     * mark, Python's removes four file-separator control characters, and Go's
     * removes none of those. Only the specification can say which is right.
     */
    private const WHITESPACE =
        '\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}'
        . '\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    /**
     * Remove leading and trailing whitespace.
     */
    private static function trimWhitespace(string $plaintext): string
    {
        $trimmed = preg_replace(
            '/^[' . self::WHITESPACE . ']+|[' . self::WHITESPACE . ']+$/u',
            '',
            $plaintext
        );

        // preg_replace returns null when the subject is not valid UTF-8, which
        // the /u modifier makes it check. Falling back to a byte trim keeps the
        // result deterministic, which is the only property a blind index
        // actually needs — a value that cannot be normalised properly still
        // has to normalise to the same thing every time.
        return $trimmed ?? trim($plaintext);
    }

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
                return mb_strtolower(self::trimWhitespace($plaintext), 'UTF-8');
            case 'trim':
                return self::trimWhitespace($plaintext);
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
