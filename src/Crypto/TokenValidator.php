<?php

namespace Panda\Crypto;

use Panda\Exception\InvalidTokenException;

/**
 * Validates PANDA access tokens for the init-time token gate (FR-28/FR-29).
 *
 * Token validation is phase-dependent:
 * - Pre-portal (current): local format check only — the token must match
 *   the expected shape (panda_demo_ or panda_live_ prefix + 48 hex chars).
 * - Post-portal (Phase 6+): a synchronous HTTPS call to the portal's
 *   validation endpoint will be added here. The format check remains as a
 *   fast pre-flight; the live call is the authoritative check.
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
     * Format: panda_(demo|live)_<48 hex characters>
     */
    public const TOKEN_PATTERN = '/^panda_(demo|live)_[a-f0-9]{48}$/';

    /**
     * The static demo token shipped in .env.example and documentation.
     */
    public const DEMO_TOKEN = 'panda_demo_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2';

    /**
     * Validate the given access token.
     *
     * Pre-portal: performs a local format check only.
     * Post-portal: will additionally call the portal validation endpoint.
     *
     * @throws InvalidTokenException if the token is missing, empty, or malformed.
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

        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            throw new InvalidTokenException(
                "The provided token does not match the expected format.\n" .
                "Expected: panda_(demo|live)_<48 hex characters>\n" .
                "Got: " . substr($token, 0, 20) . (strlen($token) > 20 ? '...' : '')
            );
        }

        // Pre-portal phase: format check is sufficient (Technical PRD Section 14.2).
        // Post-portal phase (Phase 6+): add live HTTPS validation call here.
        // The call would be blocking and fail-closed — if it fails, init refuses
        // to generate a key. This is the one exception to the SDK's fail-silent
        // portal interaction policy.
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
        return in_array(getenv('APP_ENV'), ['local', 'dev', 'development', 'testing'])
            || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])
            || (php_sapi_name() === 'cli' && getenv('APP_ENV') !== 'production');
    }
}
