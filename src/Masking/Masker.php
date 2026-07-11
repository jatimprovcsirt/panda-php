<?php

namespace Panda\Masking;

class Masker
{
    /**
     * Masks the given plaintext based on configuration.
     */
    public static function mask(string $plaintext, array $config = []): string
    {
        $keepLeading = $config['keepLeading'] ?? 2;
        $keepTrailing = $config['keepTrailing'] ?? 2;
        $maskChar = $config['maskChar'] ?? '*';

        $len = mb_strlen($plaintext, 'UTF-8');

        // If string is empty or extremely short, mask all characters.
        if ($len <= ($keepLeading + $keepTrailing)) {
            return str_repeat($maskChar, $len);
        }

        $leading = mb_substr($plaintext, 0, $keepLeading, 'UTF-8');
        $trailing = mb_substr($plaintext, $len - $keepTrailing, $keepTrailing, 'UTF-8');
        $maskLength = $len - $keepLeading - $keepTrailing;
        $maskedMiddle = str_repeat($maskChar, $maskLength);

        return $leading . $maskedMiddle . $trailing;
    }
}
