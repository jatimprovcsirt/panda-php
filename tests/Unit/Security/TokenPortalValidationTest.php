<?php

/**
 * Intercepts curl inside the Panda\Crypto namespace.
 *
 * PHP resolves an unqualified function call to the current namespace first and
 * only then to the global one, so defining these here means
 * TokenValidator's curl calls land on them instead of on the real extension.
 * It is the standard way to test code that talks to a network without
 * standing up a server — and it keeps the suite from depending on whether a
 * government portal happens to be reachable.
 */
namespace Panda\Crypto;

class CurlMock
{
    /**
     * Whether the mocks are intercepting.
     *
     * These functions live in the Panda\Crypto namespace, so once this file
     * is loaded they shadow the real curl for every other test in the
     * process too — which broke InfisicalKeyProviderTest, whose key fetch
     * also calls curl and suddenly received a stdClass where it expected a
     * CurlHandle. So they delegate to the real extension unless a test has
     * explicitly switched them on.
     */
    public static bool $enabled = false;

    /** What curl_exec returns. */
    public static string $response = '';

    /** What curl_getinfo reports as the status. */
    public static int $httpCode = 200;

    /** What curl_error reports. Empty means no transport failure. */
    public static string $error = '';

    public static int $executions = 0;
    public static string $url = '';
    public static string $postFields = '';
    public static array $headers = [];
    public static ?int $timeout = null;
    public static ?int $connectTimeout = null;
    public static bool $post = false;

    public static function reset(): void
    {
        self::$response = '';
        self::$httpCode = 200;
        self::$error = '';
        self::$executions = 0;
        self::$url = '';
        self::$postFields = '';
        self::$headers = [];
        self::$timeout = null;
        self::$connectTimeout = null;
        self::$post = false;
    }

    /** Configure the portal's answer. */
    public static function answers(string $body, int $status = 200): void
    {
        self::$response = $body;
        self::$httpCode = $status;
        self::$error = '';
    }

    /** Configure a transport failure — nothing answered at all. */
    public static function isUnreachable(string $error = 'Connection refused'): void
    {
        self::$response = '';
        self::$httpCode = 0;
        self::$error = $error;
    }
}

function curl_init($url = null)
{
    if (!CurlMock::$enabled) {
        return \curl_init($url);
    }
    CurlMock::$url = (string) $url;
    return new \stdClass();
}

function curl_setopt_array($handle, array $options): bool
{
    if (!CurlMock::$enabled) {
        return \curl_setopt_array($handle, $options);
    }
    if (isset($options[CURLOPT_POSTFIELDS])) {
        CurlMock::$postFields = (string) $options[CURLOPT_POSTFIELDS];
    }
    if (isset($options[CURLOPT_HTTPHEADER])) {
        CurlMock::$headers = $options[CURLOPT_HTTPHEADER];
    }
    if (isset($options[CURLOPT_TIMEOUT])) {
        CurlMock::$timeout = (int) $options[CURLOPT_TIMEOUT];
    }
    if (isset($options[CURLOPT_CONNECTTIMEOUT])) {
        CurlMock::$connectTimeout = (int) $options[CURLOPT_CONNECTTIMEOUT];
    }
    if (isset($options[CURLOPT_POST])) {
        CurlMock::$post = (bool) $options[CURLOPT_POST];
    }
    return true;
}

function curl_exec($handle)
{
    if (!CurlMock::$enabled) {
        return \curl_exec($handle);
    }
    CurlMock::$executions++;
    return CurlMock::$response;
}

function curl_getinfo($handle, $option = null)
{
    if (!CurlMock::$enabled) {
        return \curl_getinfo($handle, $option);
    }
    return CurlMock::$httpCode;
}

function curl_error($handle): string
{
    if (!CurlMock::$enabled) {
        return \curl_error($handle);
    }
    return CurlMock::$error;
}

function curl_close($handle): void
{
    if (!CurlMock::$enabled) {
        \curl_close($handle);
    }
}

namespace Panda\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Panda\Crypto\CurlMock;
use Panda\Crypto\TokenValidator;
use Panda\Exception\InvalidTokenException;

/**
 * Tests for the portal half of the token gate.
 *
 * TokenValidationTest sets PANDA_SKIP_ONLINE_VALIDATION=1 in setUp, so the
 * portal path was never exercised anywhere in this repository. That is how
 * `if (!$result['valid'])` survived: in PHP a non-empty string is truthy, so
 * a response of {"valid": "false"} — a body explicitly saying *no* — was read
 * as approval and a key was generated. panda-go refused the same body,
 * because decoding a string into a Go bool fails, and that is what surfaced it.
 */
class TokenPortalValidationTest extends TestCase
{
    private const LIVE_TOKEN = 'panda_live_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        CurlMock::reset();
        CurlMock::$enabled = true;
        // The portal path must be live for these tests. Several below assert
        // it is not consulted, using a mock that would record the call.
        putenv('PANDA_SKIP_ONLINE_VALIDATION');
        putenv('PANDA_VALIDATION_URL');
    }

    protected function tearDown(): void
    {
        putenv('PANDA_SKIP_ONLINE_VALIDATION');
        putenv('PANDA_VALIDATION_URL');
        CurlMock::reset();
        CurlMock::$enabled = false;
        parent::tearDown();
    }

    // ── The happy path ────────────────────────────────────────────────

    public function testAcceptsATokenThePortalSaysIsValid(): void
    {
        CurlMock::answers('{"valid": true}');

        TokenValidator::validateToken(self::LIVE_TOKEN);

        $this->addToAssertionCount(1);
    }

    public function testPostsTheTokenAsJsonToThePortal(): void
    {
        CurlMock::answers('{"valid": true}');

        TokenValidator::validateToken(self::LIVE_TOKEN);

        $this->assertSame(
            'https://csirt.jatimprov.go.id/panda/api/v1/tokens/validate',
            CurlMock::$url
        );
        $this->assertTrue(CurlMock::$post);
        $this->assertSame(
            ['token' => self::LIVE_TOKEN],
            json_decode(CurlMock::$postFields, true)
        );
        $this->assertContains('Content-Type: application/json', CurlMock::$headers);
    }

    public function testWaitsNoLongerThanFiveSeconds(): void
    {
        CurlMock::answers('{"valid": true}');

        TokenValidator::validateToken(self::LIVE_TOKEN);

        $this->assertSame(5, CurlMock::$timeout);
        $this->assertSame(5, CurlMock::$connectTimeout);
    }

    public function testHonoursACustomValidationUrl(): void
    {
        putenv('PANDA_VALIDATION_URL=https://portal.test/validate');
        CurlMock::answers('{"valid": true}');

        TokenValidator::validateToken(self::LIVE_TOKEN);

        $this->assertSame('https://portal.test/validate', CurlMock::$url);
    }

    // ── The portal refuses ────────────────────────────────────────────

    public function testRejectsARevokedToken(): void
    {
        CurlMock::answers('{"valid": false, "reason": "revoked"}');

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessageMatches('/revoked/');

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    public function testRejectsAnAlreadyActivatedToken(): void
    {
        CurlMock::answers('{"valid": false, "reason": "already_activated"}');

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessageMatches('/already been used/');

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    public function testRejectsAnUnrecognisedReason(): void
    {
        CurlMock::answers('{"valid": false, "reason": "invalid"}');

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessageMatches('/not recognized/');

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    public function testRejectsValidFalseWithNoReason(): void
    {
        CurlMock::answers('{"valid": false}');

        $this->expectException(InvalidTokenException::class);

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    /**
     * The dangerous ones. Only the JSON boolean true is consent; every value
     * below is truthy in PHP and was previously read as approval.
     */
    public static function truthyButNotTrueProvider(): array
    {
        return [
            'the string "false" — a response saying no' => ['{"valid": "false"}'],
            'the string "true"' => ['{"valid": "true"}'],
            'the string "no"' => ['{"valid": "no"}'],
            'the number 1' => ['{"valid": 1}'],
            'the number 0' => ['{"valid": 0}'],
            'null' => ['{"valid": null}'],
            'an empty array' => ['{"valid": []}'],
            'an object' => ['{"valid": {}}'],
        ];
    }

    /** @dataProvider truthyButNotTrueProvider */
    public function testOnlyTheBooleanTrueIsConsent(string $body): void
    {
        CurlMock::answers($body);

        $this->expectException(InvalidTokenException::class);

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    public function testRejectsABodyWithNoValidField(): void
    {
        CurlMock::answers('{"status": "ok"}');

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessageMatches('/parse/');

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    // ── Fail closed ───────────────────────────────────────────────────

    public function testRejectsATransportFailure(): void
    {
        CurlMock::isUnreachable('Connection refused');

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessageMatches('/Unable to reach/');

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    public function testRejectsA500(): void
    {
        CurlMock::answers('', 500);

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessageMatches('/500/');

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    public function testRejectsA404(): void
    {
        CurlMock::answers('', 404);

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessageMatches('/404/');

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    public function testRejectsAnHtmlBody(): void
    {
        // What a captive portal or a block page returns.
        CurlMock::answers('<html><body>Blocked</body></html>');

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessageMatches('/parse/');

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    public function testRejectsAnEmptyBody(): void
    {
        CurlMock::answers('');

        $this->expectException(InvalidTokenException::class);

        TokenValidator::validateToken(self::LIVE_TOKEN);
    }

    // ── The escape hatches ────────────────────────────────────────────

    public function testTheDemoTokenMakesNoRequest(): void
    {
        TokenValidator::validateToken(TokenValidator::DEMO_TOKEN);

        $this->assertSame(0, CurlMock::$executions);
    }

    public function testSkipOnlineValidationMakesNoRequest(): void
    {
        putenv('PANDA_SKIP_ONLINE_VALIDATION=1');

        TokenValidator::validateToken(self::LIVE_TOKEN);

        $this->assertSame(0, CurlMock::$executions);
    }

    public function testAPandaDemoTokenThatIsNotTheStaticOneGoesToThePortal(): void
    {
        // The prefix is not a pass. Only the published string skips the
        // portal, or anyone could mint one locally.
        CurlMock::answers('{"valid": true}');

        TokenValidator::validateToken('panda_demo_' . str_repeat('a', 48));

        $this->assertSame(1, CurlMock::$executions);
    }

    // ── Failures before the portal ────────────────────────────────────

    public function testAnEmptyTokenNeverReachesThePortal(): void
    {
        try {
            TokenValidator::validateToken('');
        } catch (InvalidTokenException $e) {
            $this->assertSame(0, CurlMock::$executions);
            return;
        }
        $this->fail('An empty token should have been rejected');
    }

    public function testAMalformedTokenNeverReachesThePortal(): void
    {
        try {
            TokenValidator::validateToken('panda_live_' . str_repeat('A', 48));
        } catch (InvalidTokenException $e) {
            $this->assertSame(0, CurlMock::$executions);
            return;
        }
        $this->fail('An uppercase token should have been rejected');
    }

    /**
     * The engine quirk that made the same bytes valid in two SDKs and not the
     * other two: in PCRE, `$` also matches immediately before a trailing
     * newline, so this string matched here and in Python while Node and Go
     * rejected it. The `D` modifier anchors at the end of the string.
     */
    public function testATrailingNewlineNeverReachesThePortal(): void
    {
        foreach (["\n", "\r", "\r\n"] as $suffix) {
            CurlMock::reset();
            try {
                TokenValidator::validateToken(self::LIVE_TOKEN . $suffix);
            } catch (InvalidTokenException $e) {
                $this->assertSame(0, CurlMock::$executions, 'suffix: ' . json_encode($suffix));
                continue;
            }
            $this->fail('A trailing ' . json_encode($suffix) . ' should have been rejected');
        }
    }
}
