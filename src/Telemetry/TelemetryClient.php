<?php

namespace Panda\Telemetry;

use Exception;

/**
 * Fail-silent ongoing telemetry client (FR-14, FR-16).
 *
 * IMPORTANT: This client handles ONGOING telemetry only — it is opt-out via
 * PANDA_TELEMETRY_DISABLED=1, and always fails silently without blocking
 * encrypt/decrypt operations.
 *
 * The init-time token gate (FR-28/FR-29) is a SEPARATE mechanism implemented
 * in TokenValidator and the InitCommand. It is mandatory, fail-closed, and
 * NOT affected by the PANDA_TELEMETRY_DISABLED flag. See Technical PRD
 * Section 14 (comparison table) for the deliberate separation.
 */
class TelemetryClient
{
    private const DEMO_TOKEN = 'panda_demo_76d08573d453cdbdb4bf5704d13f8f54d57cd3c47c9b4be2';
    private const TELEMETRY_URL = 'https://portal.panda.gov.id/api/telemetry'; // Placeholder URL

    private bool $disabled;

    public function __construct()
    {
        // This opt-out flag governs ONLY this ongoing telemetry client.
        // It has NO effect on the mandatory init-time token gate (FR-28).
        $this->disabled = (getenv('PANDA_TELEMETRY_DISABLED') === '1') 
            || (getenv('PANDA_TELEMETRY_DISABLED') === 'true');
    }

    /**
     * Report telemetry data fail-silently.
     */
    public function report(string $token, string $keyBytes, string $sdkVersion = '0.1.0'): void
    {
        if ($this->disabled) {
            return;
        }

        // Warn if the demo token is being used in production heuristic:
        // (heuristic: check if not running on localhost/127.0.0.1 or APP_ENV/NODE_ENV is production)
        if ($token === self::DEMO_TOKEN) {
            $isLocal = in_array(getenv('APP_ENV'), ['local', 'dev', 'development', 'testing'])
                || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'])
                || (php_sapi_name() === 'cli' && getenv('APP_ENV') !== 'production');

            if (!$isLocal) {
                error_log("⚠️ PANDA WARNING: The static DEMO TOKEN is being used outside a local development environment. Please register your agency to get a live token!");
            }
        }

        try {
            $fingerprint = hash('sha256', $keyBytes);
            $payload = [
                'token' => $token,
                'keyFingerprint' => $fingerprint,
                'sdkVersion' => $sdkVersion,
                'language' => 'php'
            ];

            $ch = curl_init(self::TELEMETRY_URL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Hard 2-second timeout

            // Fail silent: execute without checking HTTP status or errors
            curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            // Fail-silent by design. Do nothing.
        }
    }
}
