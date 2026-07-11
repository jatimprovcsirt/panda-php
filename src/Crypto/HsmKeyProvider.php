<?php

namespace Panda\Crypto;

use Panda\Exception\PandaException;

class HsmKeyProvider implements KeyProvider
{
    public function getKey(string $kid): string
    {
        throw new PandaException("HSM Key Provider (PKCS#11 tier) is deferred to Phase 5+ and is not implemented in this version.");
    }
}
