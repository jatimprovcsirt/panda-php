<?php

namespace Panda\Crypto;

use Panda\Exception\KeyNotFoundException;

interface KeyProvider
{
    /**
     * Retrieve the 32-byte encryption key for the given key identifier (kid).
     *
     * @param string $kid
     * @return string Raw 32-byte key material.
     * @throws KeyNotFoundException if key is unknown.
     */
    public function getKey(string $kid): string;
}
