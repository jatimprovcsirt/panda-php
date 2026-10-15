<?php

declare(strict_types=1);

namespace Panda\Telemetry;

use Panda\Cli\Utils\PandaDirectory;

/**
 * Ongoing telemetry reporting (FR-14, FR-16).
 *
 * IMPORTANT: this handles ONGOING telemetry only. It is opt-out via
 * `PANDA_TELEMETRY_DISABLED=1`, and it always fails silently: a DNS failure, a
 * timeout, a refused connection or any non-2xx response is swallowed and never
 * reaches the caller. The init-time token gate (FR-28/FR-29) is a separate
 * mechanism, mandatory and fail-closed, and this flag does not affect it.
 *
 * What it does NOT do any more is run inside an operation. It used to be
 * called from `encrypt()` and `decryptRaw()` — once per operation — and the
 * cipher had to hold a token for it, taken from `PANDA_PORTAL_TOKEN`, which
 * nothing in this project has ever set. Counting is now a process-local array
 * addition (see `count`), and the token comes from the credentials
 * `panda init` wrote, at report time.
 */
final class TelemetryClient
{
    /** Whichever is reached first, per panda-spec/telemetry-format.md. */
    public const FLUSH_INTERVAL_SECONDS = 300;
    public const FLUSH_VOLUME = 1000;

    private const TIMEOUT_SECONDS = 2;

    /** @var array<string,int> Counted this request, not yet merged into the store. */
    private static array $pending = ['encrypts' => 0, 'decrypts' => 0, 'decryptsRaw' => 0];

    private static ?CounterStore $store = null;

    private static string $backend = 'memory';

    private static bool $shutdownRegistered = false;

    private static bool $saidNoToken = false;

    // ── the hot path ──────────────────────────────────────────────────────

    /**
     * Record one operation. An array addition and nothing else.
     *
     * The first call in a request also registers the shutdown hook that
     * merges this batch into the shared store and reports it if the window
     * has closed. Doing it here rather than per operation is the whole point:
     * a lock and a file write inside `encrypt()` would be the same class of
     * mistake as the HTTP call it replaces.
     */
    public static function count(string $name): void
    {
        if (self::disabled() || !isset(self::$pending[$name])) {
            return;
        }

        self::$pending[$name]++;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'onShutdown']);
        }
    }

    /**
     * Merge this request's batch, then report if the window has closed.
     *
     * `fastcgi_finish_request()` first where it exists: the client already has
     * its response, and a report must never be something a request waits on.
     */
    public static function onShutdown(): void
    {
        if (self::disabled()) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            self::store()->addMany(self::$pending);
            self::$pending = ['encrypts' => 0, 'decrypts' => 0, 'decryptsRaw' => 0];

            if (self::due()) {
                self::flush();
            }
        } catch (\Throwable) {
            // Fail-silent. A statistics client must not be able to break a
            // request that has already been answered.
        }
    }

    // ── reporting ─────────────────────────────────────────────────────────

    /** @return bool true when the portal acknowledged the report. */
    public static function flush(): bool
    {
        if (self::disabled()) {
            return false;
        }

        $store = self::store();
        $counters = $store->snapshot();
        if (CounterStore::total($counters) <= 0) {
            return false;
        }

        $token = self::accessToken();
        if ($token === null) {
            self::warnNoToken();
            return false;
        }

        $body = TelemetryPayload::usage([
            'token' => $token,
            'sdkVersion' => self::sdkVersion(),
            'windowSeconds' => $store->windowSeconds(),
            'counters' => $counters,
        ]);

        if (!self::post($body)) {
            // The counters stay where they are, so the next window carries
            // them. That is what keeps a portal outage from quietly discarding
            // the operations that happened during it.
            return false;
        }

        $store->commit($counters);
        return true;
    }

    /**
     * Generate an encryption key, and report that one exists.
     *
     * The one definition of "a key was generated", so the event cannot be
     * forgotten at one of the call sites — which is how `decrypt`, the most
     * common read path of all, came to be uncounted. Every key-producing path
     * goes through here: quick init, the wizard, `panda generate` and
     * `rotate-key`.
     */
    public static function newEncryptionKey(): string
    {
        $key = random_bytes(32);
        self::reportKey($key);
        return $key;
    }

    /** Report a generated key. Sent once per key, and the only carrier of the fingerprint. */
    public static function reportKey(string $keyBytes): void
    {
        if (self::disabled()) {
            return;
        }

        $token = self::accessToken();
        if ($token === null) {
            return;
        }

        self::post(TelemetryPayload::key($token, self::sdkVersion(), $keyBytes));
    }

    // ── internals ─────────────────────────────────────────────────────────

    public static function disabled(): bool
    {
        $flag = getenv('PANDA_TELEMETRY_DISABLED');
        return $flag === '1' || $flag === 'true';
    }

    /** Which counter store this host ended up with. Reported once, in the log. */
    public static function backend(): string
    {
        self::store();
        return self::$backend;
    }

    private static function store(): CounterStore
    {
        if (self::$store === null) {
            [$store, $backend] = CounterStore::best();
            self::$store = $store;
            self::$backend = $backend;

            if ($backend === 'memory') {
                // Said out loud rather than degraded in silence. A figure that
                // is quietly low, because workers were recycled before they
                // reported, is worse than one known to be low.
                error_log(
                    'panda: telemetry counters are process-local (no APCu, and ' .
                    '.panda/telemetry-counters.json is not writable). Counts will ' .
                    'be under-reported on a multi-worker host. See ' .
                    'panda-spec/telemetry-format.md.'
                );
            }
        }

        return self::$store;
    }

    private static function due(): bool
    {
        $store = self::store();
        $seconds = $store->windowSeconds();
        if ($seconds <= 0) {
            return false;
        }
        if ($seconds >= self::FLUSH_INTERVAL_SECONDS) {
            return true;
        }
        return CounterStore::total($store->snapshot()) >= self::FLUSH_VOLUME;
    }

    /**
     * The access token, from where `panda init` put it.
     *
     * `PANDA_PORTAL_TOKEN` is still honoured for an application that would
     * rather hold it in the environment, but it is no longer the only source —
     * and it was the reason nothing was ever reported, because nothing sets it.
     */
    private static function accessToken(): ?string
    {
        $credentials = PandaDirectory::readCredentials();
        if (is_array($credentials) && !empty($credentials['token'])) {
            return (string) $credentials['token'];
        }

        $fromEnv = getenv('PANDA_PORTAL_TOKEN');
        return $fromEnv === false || $fromEnv === '' ? null : $fromEnv;
    }

    /**
     * The SDK's real version, from Composer's own record of the installed
     * package rather than a constant here.
     *
     * A constant would be a second definition of the number in `composer.json`,
     * free to drift from it — which is precisely what happened to the version
     * strings in `panda-py` and `panda-node`, in the shipped packages, for
     * several releases.
     */
    public static function sdkVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = \Composer\InstalledVersions::getPrettyVersion('jatimprovcsirt/panda-php');
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            } catch (\Throwable) {
                // Not installed as a dependency. Fall through.
            }
        }

        $manifest = dirname(__DIR__, 2) . '/composer.json';
        if (is_file($manifest)) {
            $decoded = json_decode((string) file_get_contents($manifest), true);
            if (is_array($decoded) && !empty($decoded['version'])) {
                return (string) $decoded['version'];
            }
        }

        return 'unknown';
    }

    private static function warnNoToken(): void
    {
        if (self::$saidNoToken) {
            return;
        }
        self::$saidNoToken = true;
        if (getenv('PANDA_TELEMETRY_DEBUG') === '1') {
            error_log('panda: telemetry has no access token to report under; run `panda init`.');
        }
    }

    private static function post(string $body): bool
    {
        try {
            $ch = curl_init(TelemetryPayload::TELEMETRY_URL);
            if ($ch === false) {
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            ]);

            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return $status >= 200 && $status < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Testing seam: the counters are static state and a test needs a clean one. */
    public static function resetForTests(): void
    {
        self::$pending = ['encrypts' => 0, 'decrypts' => 0, 'decryptsRaw' => 0];
        self::$store = null;
        self::$backend = 'memory';
        self::$shutdownRegistered = false;
        self::$saidNoToken = false;
    }
}
