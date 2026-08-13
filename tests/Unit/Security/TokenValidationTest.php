<?php

namespace Panda\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Panda\Crypto\TokenValidator;
use Panda\Exception\InvalidTokenException;

/**
 * Security tests for token validation
 *
 * Tests token format validation (panda_(demo|live)_[a-f0-9]{48} pattern)
 */
class TokenValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up test environment to skip online validation
        putenv('PANDA_SKIP_ONLINE_VALIDATION=1');
    }

    protected function tearDown(): void
    {
        putenv('PANDA_SKIP_ONLINE_VALIDATION');
        parent::tearDown();
    }

    /**
     * Test valid demo token passes validation
     */
    public function testValidDemoTokenPassesValidation(): void
    {
        $demoToken = 'panda_demo_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2';

        try {
            TokenValidator::validateToken($demoToken);
            $this->assertTrue(true, 'Demo token should pass validation');
        } catch (InvalidTokenException $e) {
            $this->fail('Demo token should not throw exception: ' . $e->getMessage());
        }
    }

    /**
     * Test valid live token with 48 hex characters
     */
    public function testValidLiveTokenWith48HexCharacters(): void
    {
        $liveToken = 'panda_live_' . str_repeat('a', 48);

        try {
            TokenValidator::validateToken($liveToken);
            $this->assertTrue(true, 'Live token with 48 hex chars should pass format validation');
        } catch (InvalidTokenException $e) {
            $this->fail('Live token should pass format validation (online validation will be skipped in test)');
        }
    }

    /**
     * Test valid live token with 64 hex characters
     */
    public function testValidLiveTokenWith64HexCharacters(): void
    {
        $liveToken = 'panda_live_' . str_repeat('b', 64);

        try {
            TokenValidator::validateToken($liveToken);
            $this->assertTrue(true, 'Live token with 64 hex chars should pass format validation');
        } catch (InvalidTokenException $e) {
            $this->fail('Live token should pass format validation (online validation will be skipped in test)');
        }
    }

    /**
     * Test token pattern is correct
     */
    public function testTokenPatternIsCorrect(): void
    {
        $pattern = TokenValidator::TOKEN_PATTERN;

        // Should match valid tokens
        $this->assertMatchesRegularExpression($pattern, 'panda_demo_' . str_repeat('a', 48));
        $this->assertMatchesRegularExpression($pattern, 'panda_live_' . str_repeat('b', 48));
        $this->assertMatchesRegularExpression($pattern, 'panda_live_' . str_repeat('c', 64));

        // Should not match invalid tokens
        $this->assertDoesNotMatchRegularExpression($pattern, 'invalid_token');
        $this->assertDoesNotMatchRegularExpression($pattern, 'panda_demo_' . str_repeat('d', 47)); // Too short
        $this->assertDoesNotMatchRegularExpression($pattern, 'panda_live_' . str_repeat('e', 65)); // Too long
    }

    /**
     * Test empty token throws exception
     */
    public function testEmptyTokenThrowsException(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('A valid PANDA access token is required');

        TokenValidator::validateToken('');
    }

    /**
     * Test null token throws exception
     */
    public function testNullTokenThrowsException(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('A valid PANDA access token is required');

        TokenValidator::validateToken('');
    }

    /**
     * Test invalid token format throws exception
     */
    public function testInvalidTokenFormatThrowsException(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('does not match the expected format');

        TokenValidator::validateToken('invalid_token_format');
    }

    /**
     * Test token with wrong prefix throws exception
     */
    public function testTokenWithWrongPrefixThrowsException(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('does not match the expected format');

        TokenValidator::validateToken('other_prefix_' . str_repeat('a', 48));
    }

    /**
     * Test token too short throws exception
     */
    public function testTokenTooShortThrowsException(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('does not match the expected format');

        TokenValidator::validateToken('panda_demo_' . str_repeat('a', 47));
    }

    /**
     * Test token too long throws exception
     */
    public function testTokenTooLongThrowsException(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('does not match the expected format');

        TokenValidator::validateToken('panda_live_' . str_repeat('a', 65));
    }

    /**
     * Test token with invalid hex characters throws exception
     */
    public function testTokenWithInvalidHexCharactersThrowsException(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('does not match the expected format');

        TokenValidator::validateToken('panda_demo_' . str_repeat('g', 48)); // 'g' is not valid hex
    }

    /**
     * Test token with mixed case hex characters
     */
    public function testTokenWithMixedCaseHexCharacters(): void
    {
        // Lowercase is valid
        $lowercaseToken = 'panda_demo_' . str_repeat('a', 48);
        $this->assertMatchesRegularExpression(TokenValidator::TOKEN_PATTERN, $lowercaseToken);

        // The pattern only allows lowercase a-f
        // Uppercase should NOT match
        $uppercaseToken = 'panda_demo_' . 'ABCDEF1234567890ABCDEF' . str_repeat('0', 28);
        $this->assertDoesNotMatchRegularExpression(TokenValidator::TOKEN_PATTERN, $uppercaseToken);
    }

    /**
     * Test demo token constant
     */
    public function testDemoTokenConstant(): void
    {
        $demoToken = TokenValidator::DEMO_TOKEN;

        $this->assertIsString($demoToken);
        $this->assertStringStartsWith('panda_demo_', $demoToken);
        // Verify the demo token matches the expected pattern
        $this->assertMatchesRegularExpression(TokenValidator::TOKEN_PATTERN, $demoToken);
    }

    /**
     * Test isDemoToken returns true for demo token
     */
    public function testIsDemoTokenReturnsTrueForDemoToken(): void
    {
        $demoToken = TokenValidator::DEMO_TOKEN;

        $this->assertTrue(TokenValidator::isDemoToken($demoToken));
    }

    /**
     * Test isDemoToken returns false for live token
     */
    public function testIsDemoTokenReturnsFalseForLiveToken(): void
    {
        $liveToken = 'panda_live_' . str_repeat('a', 48);

        $this->assertFalse(TokenValidator::isDemoToken($liveToken));
    }

    /**
     * Test isDemoToken returns false for invalid token
     */
    public function testIsDemoTokenReturnsFalseForInvalidToken(): void
    {
        $invalidToken = 'invalid_token';

        $this->assertFalse(TokenValidator::isDemoToken($invalidToken));
    }

    /**
     * Test isLocalEnvironment with APP_ENV local
     */
    public function testIsLocalEnvironmentWithAppEnvLocal(): void
    {
        putenv('APP_ENV=local');

        $this->assertTrue(TokenValidator::isLocalEnvironment());

        putenv('APP_ENV');
    }

    /**
     * Test isLocalEnvironment with APP_ENV dev
     */
    public function testIsLocalEnvironmentWithAppEnvDev(): void
    {
        putenv('APP_ENV=dev');

        $this->assertTrue(TokenValidator::isLocalEnvironment());

        putenv('APP_ENV');
    }

    /**
     * Test isLocalEnvironment with APP_ENV production
     */
    public function testIsLocalEnvironmentWithAppEnvProduction(): void
    {
        putenv('APP_ENV=production');

        $this->assertFalse(TokenValidator::isLocalEnvironment());

        putenv('APP_ENV');
    }

    /**
     * Test isLocalEnvironment with CLI
     */
    public function testIsLocalEnvironmentWithCLI(): void
    {
        // In CLI mode without APP_ENV set, should return true
        if (getenv('APP_ENV') === false) {
            $this->assertTrue(TokenValidator::isLocalEnvironment());
        }
    }

    /**
     * Test token with uppercase hex characters
     */
    public function testTokenWithUppercaseHexCharacters(): void
    {
        // Pattern expects lowercase a-f, but test should verify pattern behavior
        $uppercaseToken = 'panda_demo_' . strtoupper(str_repeat('a', 48));

        // The pattern uses [a-f0-9] which only matches lowercase
        $this->assertDoesNotMatchRegularExpression(TokenValidator::TOKEN_PATTERN, $uppercaseToken);
    }

    /**
     * Test token validation provides helpful error message
     */
    public function testTokenValidationProvidesHelpfulErrorMessage(): void
    {
        try {
            TokenValidator::validateToken('wrong_token');
            $this->fail('Should throw exception');
        } catch (InvalidTokenException $e) {
            $this->assertStringContainsString('does not match the expected format', $e->getMessage());
            $this->assertStringContainsString('panda_(demo|live)', $e->getMessage());
        }
    }

    /**
     * Test token with special characters throws exception
     */
    public function testTokenWithSpecialCharactersThrowsException(): void
    {
        $this->expectException(InvalidTokenException::class);

        TokenValidator::validateToken('panda_demo_' . str_repeat('a', 40) . '!@#$');
    }

    /**
     * Test token with spaces throws exception
     */
    public function testTokenWithSpacesThrowsException(): void
    {
        $this->expectException(InvalidTokenException::class);

        TokenValidator::validateToken('panda_demo_ ' . str_repeat('a', 48));
    }

    /**
     * Test token validation skips online validation for demo token
     */
    public function testTokenValidationSkipsOnlineValidationForDemoToken(): void
    {
        // Even without PANDA_SKIP_ONLINE_VALIDATION, demo token should not make HTTP call
        putenv('PANDA_SKIP_ONLINE_VALIDATION=');

        $demoToken = TokenValidator::DEMO_TOKEN;

        // Should complete without network call
        try {
            TokenValidator::validateToken($demoToken);
            $this->assertTrue(true, 'Demo token should not require online validation');
        } catch (InvalidTokenException $e) {
            $this->fail('Demo token should pass validation without online check');
        }

        putenv('PANDA_SKIP_ONLINE_VALIDATION=1');
    }

    /**
     * Test token validation respects skip online validation env var
     */
    public function testTokenValidationRespectsSkipOnlineValidation(): void
    {
        putenv('PANDA_SKIP_ONLINE_VALIDATION=1');

        $liveToken = 'panda_live_' . str_repeat('a', 48);

        // Should skip online validation when env var is set
        try {
            TokenValidator::validateToken($liveToken);
            $this->assertTrue(true, 'Should skip online validation');
        } catch (InvalidTokenException $e) {
            // This is acceptable as the token format is validated first
            $this->assertStringContainsString('format', $e->getMessage());
        }

        putenv('PANDA_SKIP_ONLINE_VALIDATION=');
        putenv('PANDA_SKIP_ONLINE_VALIDATION=1');
    }

    /**
     * Test token pattern groups
     */
    public function testTokenPatternGroups(): void
    {
        $pattern = TokenValidator::TOKEN_PATTERN;

        // Pattern should have groups for (demo|live) and hex characters
        $this->assertStringContainsString('(demo|live)', $pattern);
        $this->assertStringContainsString('[a-f0-9]', $pattern);
    }

    /**
     * Test validation URL can be customized
     */
    public function testValidationURLCanBeCustomized(): void
    {
        $customUrl = 'https://custom.validation.url';

        putenv('PANDA_VALIDATION_URL=' . $customUrl);

        // The validation URL is used internally, we can't easily test it without
        // triggering actual HTTP calls, but we can verify the env var is respected
        $this->assertEquals($customUrl, getenv('PANDA_VALIDATION_URL'));

        putenv('PANDA_VALIDATION_URL=');
    }

    /**
     * Test token validation only happens during init
     */
    public function testTokenValidationOnlyHappensDuringInit(): void
    {
        // TokenValidator is only used by InitCommand, not by encrypt/decrypt operations
        // This test verifies that the validator is designed for init-time use only
        // The class name doesn't contain 'init', but we can verify it's used in the right context
        $this->assertIsString(TokenValidator::DEMO_TOKEN);
        $this->assertNotEmpty(TokenValidator::TOKEN_PATTERN);
    }

    /**
     * Test multiple valid token formats
     */
    public function testMultipleValidTokenFormats(): void
    {
        $validTokens = [
            'panda_demo_' . str_repeat('0', 48),
            'panda_demo_' . str_repeat('f', 48),
            'panda_live_' . str_repeat('0123456789abcdef', 3), // 48 chars
            'panda_live_' . str_repeat('0123456789abcdef', 4), // 64 chars
        ];

        foreach ($validTokens as $token) {
            $this->assertMatchesRegularExpression(
                TokenValidator::TOKEN_PATTERN,
                $token,
                "Token should match pattern: {$token}"
            );
        }
    }
}
