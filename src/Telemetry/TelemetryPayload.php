<?php

declare(strict_types=1);

namespace Panda\Telemetry;

/**
 * The telemetry payloads, as pinned in panda-spec/telemetry-format.md.
 *
 * Separate from the client that sends them, and pure, so the format can be
 * checked without a portal. `verify_interop.js` section 11 gives every SDK the
 * same counters and asserts they produce the same fields.
 *
 * It is separate for a second reason, which is the defect it exists to
 * prevent: the SDK and the portal disagreed about the field names — the SDK
 * sent `keyFingerprint`/`sdkVersion`/`language` and the portal read
 * `eventType`/`metadata` — and nothing noticed, because the endpoint was never
 * reached. A payload built in one typed place, against a written contract, is
 * a payload someone can compare against the receiver.
 */
final class TelemetryPayload
{
    /** What this SDK calls itself in the payload. */
    public const LANGUAGE = 'php';

    /**
     * The base URL every portal endpoint is derived from.
     *
     * `TelemetryClient` used to carry its own literal here — a hostname that
     * does not exist — while the token gate next door had the right one. Two
     * definitions of one host is how they came apart, so there is now one.
     */
    public const PORTAL_BASE_URL = 'https://csirt.jatimprov.go.id/panda/api/v1';

    public const TELEMETRY_URL = self::PORTAL_BASE_URL . '/telemetry';

    /**
     * @param array{token: string, sdkVersion: string, windowSeconds: int, counters: array<string, int>} $input
     */
    public static function usage(array $input): string
    {
        $counters = $input['counters'];

        // Every counter is written, including the ones at zero. Omitting an
        // unmoved counter would be indistinguishable, at the receiver, from an
        // older SDK that never sent the field — and an idle installation has
        // to be tellable from an out-of-date one.
        $payload = [
            'eventType' => 'usage',
            // json_encode would turn an integer beyond PHP_INT_MAX into a
            // float, and a float counter is a wrong counter. Cast is explicit
            // so the failure, if a count ever gets that large, is a visible
            // overflow rather than a silent loss of precision.
            'token' => $input['token'],
            'sdkVersion' => $input['sdkVersion'],
            'language' => self::LANGUAGE,
            'windowSeconds' => (int) $input['windowSeconds'],
            'counters' => [
                'encrypts' => (int) $counters['encrypts'],
                'decrypts' => (int) $counters['decrypts'],
                'decryptsRaw' => (int) $counters['decryptsRaw'],
            ],
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    public static function key(string $token, string $sdkVersion, string $keyBytes): string
    {
        return (string) json_encode([
            'eventType' => 'key',
            'token' => $token,
            'sdkVersion' => $sdkVersion,
            'language' => self::LANGUAGE,
            'keyFingerprint' => self::keyFingerprint($keyBytes),
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * `base64(SHA-256(key bytes))` — the bytes, not their base64 text.
     *
     * Hashing the encoded form is the mistake that survives every round-trip
     * test, because the implementation agrees with itself and only disagrees
     * with the other three. A vector in `vectors.json` hashes a key whose
     * base64 encoding is itself, so the two readings differ.
     */
    public static function keyFingerprint(string $keyBytes): string
    {
        return base64_encode(hash('sha256', $keyBytes, true));
    }
}
