<?php

namespace Panda\Tests;

use PHPUnit\Framework\TestCase;
use Panda\Crypto\TokenValidator;
use Panda\Exception\InvalidTokenException;

class TokenValidatorTest extends TestCase
{
    public function testValidateDemoTokenSuccess(): void
    {
        $demoToken = TokenValidator::DEMO_TOKEN;
        $this->assertTrue(TokenValidator::isDemoToken($demoToken));
        
        // Should not throw
        TokenValidator::validateToken($demoToken);
    }

    public function testValidateLiveTokenSuccess(): void
    {
        $liveToken = 'panda_live_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2';
        $this->assertFalse(TokenValidator::isDemoToken($liveToken));
        
        // Should not throw
        TokenValidator::validateToken($liveToken);
    }

    public function testValidateTokenEmptyThrows(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('required to initialize');
        TokenValidator::validateToken('');
    }

    public function testValidateTokenMalformedThrows(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('does not match the expected format');
        TokenValidator::validateToken('invalid_prefix_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2');
    }

    public function testValidateTokenInvalidHexThrows(): void
    {
        $this->expectException(InvalidTokenException::class);
        TokenValidator::validateToken('panda_live_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4beG'); // G is not hex
    }
}
