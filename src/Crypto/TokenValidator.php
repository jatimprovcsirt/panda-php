<?php

namespace Panda\Crypto;

use Panda\Exception\InvalidTokenException;

/**
 * Validates PANDA access tokens for the init-time token gate (FR-28/FR-29).
 *
 * Token validation behavior:
 * - Local format check is performed first (fast pre-flight).
 * - The demo token skips all online validation - no HTTP call is ever made.
 * - All other tokens require a successful portal validation check.
 *
 * This validator is used ONLY by the `init` CLI command. It is NOT used by
 * encrypt/decrypt/mask operations, which have zero portal dependency (FR-29).
 *
 * Note: because the SDK source is public, this check is a soft, social gate
 * for legitimate-usage inventory — not a cryptographic access-control boundary.
 * See Technical PRD Section 14.2.
 */
class TokenValidator
{
    /**
     * Regex pattern for a valid PANDA access token.
     * Format: panda_(demo|live)_<48 or 64 hex characters>
     */
    public const TOKEN_PATTERN = '/^panda_(demo|live)_([a-f0-9]{48}|[a-f0-9]{64})$/';

    /**
     * The static demo token shipped in .env.example and documentation.
     */
    public const DEMO_TOKEN = 'panda_demo_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2';

    /**
     * The production portal validation endpoint.
     */
    private const DEFAULT_VALIDATION_URL = 'https://csirt.jatimprov.go.id/panda/api/v1/tokens/validate';

    /**
     * Get the validation URL from environment or default.
     */
    private static function getValidationURL(): string
    {
        return getenv('PANDA_VALIDATION_URL') ?: self::DEFAULT_VALIDATION_URL;
    }

    /**
     * Check if online validation should be skipped (test mode).
     */
    private static function shouldSkipOnlineValidation(): bool
    {
        return getenv('PANDA_SKIP_ONLINE_VALIDATION') === '1';
    }

    /**
     * Performs a synchronous HTTPS call to validate the token against the PANDA portal.
     * This is blocking and fail-closed.
     *
     * @throws InvalidTokenException if validation fails for any reason.
     */
    private static function validateTokenOnline(string $token): void
    {
        $ch = curl_init(self::getValidationURL());

        $payload = json_encode(['token' => $token]);
        if ($payload === false) {
            throw new InvalidTokenException(
                'Failed to encode validation request. Please try again later.'
            );
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new InvalidTokenException(
                'Unable to reach the PANDA portal for token validation. Please check your network connection and try again.'
            );
        }

        if ($httpCode !== 200) {
            throw new InvalidTokenException(
                "PANDA portal returned unexpected status code {$httpCode}. Please try again later."
            );
        }

        $result = json_decode($response, true);
        if ($result === null || !isset($result['valid'])) {
            throw new InvalidTokenException(
                'Failed to parse portal response. Please try again later.'
            );
        }

        if (!$result['valid']) {
            $reason = $result['reason'] ?? 'invalid';
            switch ($reason) {
                case 'revoked':
                    throw new InvalidTokenException(
                        'The provided token has been revoked by the portal administrator.'
                    );
                case 'already_activated':
                    throw new InvalidTokenException(
                        'The provided token has already been used. Each token can only be activated once.'
                    );
                default:
                    throw new InvalidTokenException(
                        'The provided token is invalid or not recognized by the PANDA portal.'
                    );
            }
        }
    }

    /**
     * Validate the given access token.
     *
     * Performs a local format check followed by portal validation (except for demo token).
     *
     * @throws InvalidTokenException if the token is missing, empty, malformed, or fails portal validation.
     */
    public static function validateToken(string $token): void
    {
        if (empty($token)) {
            throw new InvalidTokenException(
                "A valid PANDA access token is required to initialize a new application.\n" .
                "Provide it via --token <value> or the PANDA_ACCESS_TOKEN environment variable.\n" .
                "Register your application at the PANDA portal to obtain a token,\n" .
                "or use the demo token for local development (see .env.example)."
            );
        }

        if (!self::validateTokenFormat($token)) {
            throw new InvalidTokenException(
                "The provided token does not match the expected format.\n" .
                "Expected: panda_(demo|live)_<48 or 64 hex characters>\n" .
                "Got: " . substr($token, 0, 20) . (strlen($token) > 20 ? '...' : '')
            );
        }

        // Demo token skips all online validation - no HTTP call is ever made.
        if (self::isDemoToken($token)) {
            return;
        }

        // Test mode: skip online validation when PANDA_SKIP_ONLINE_VALIDATION=1
        if (self::shouldSkipOnlineValidation()) {
            return;
        }

        // For non-demo tokens, perform online validation.
        // This is blocking and fail-closed — if it fails, init refuses to generate a key.
        self::validateTokenOnline($token);
    }

    /**
     * Is this token well-formed?
     *
     * Distinct from validateToken(): that one consults the portal and throws.
     * This one answers a question about the string, without network access,
     * so it is what the cross-language vector runner and the CLI wizard both
     * need. It exists as a method rather than as a preg_match at each call
     * site because the pattern was written out at more than one call site and
     * the copies had drifted.
     */
    public static function validateTokenFormat(string $token): bool
    {
        return (bool) preg_match(self::TOKEN_PATTERN, $token);
    }

    /**
     * Check if the given token is the static demo token.
     */
    public static function isDemoToken(string $token): bool
    {
        return $token === self::DEMO_TOKEN;
    }

    /**
     * Check whether the current environment appears to be a local/dev environment.
     * Used to determine whether to warn about demo token usage.
     */
    public static function isLocalEnvironment(): bool
    {
        return in_array(getenv('APP_ENV'), ['local', 'dev', 'development', 'testing'], true)
            || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true)
            || (php_sapi_name() === 'cli' && getenv('APP_ENV') !== 'production');
    }
}
