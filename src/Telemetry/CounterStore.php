<?php

declare(strict_types=1);

namespace Panda\Telemetry;

/**
 * Where the operation counters live between requests.
 *
 * In a single-process runtime they would live in the process. PHP-FPM is not
 * one: a worker handles some requests and is then recycled, and its memory
 * goes with it. A counter that lives only there sees one slice of the traffic
 * and dies before it reports, so quiet installations — which is the government
 * deployment profile — lose their counts. The bias falls on exactly the
 * installations most worth counting.
 *
 * **Two tiers, on purpose.** The hot path increments process-local memory: one
 * array addition, no lock, no syscall. At request shutdown the accumulated
 * batch is merged into the shared store below in a single operation. Writing
 * to a shared store per operation would put a lock and a file write inside
 * `encrypt()`, which is the shape of problem this whole change exists to
 * remove.
 *
 * Three back-ends, in order, per panda-spec/telemetry-format.md:
 *
 *   APCu   — shared memory, atomic, visible to every worker in the pool.
 *            Absent on many hosts, which is why it is not alone.
 *   file   — flock-protected JSON. Needs nothing installed, and unlike APCu it
 *            survives a php-fpm restart.
 *   memory — process-local, the lossiest, and reached only when neither of the
 *            others can be used. It **says so** rather than degrading in
 *            silence: a statistics figure that is quietly low is worse than
 *            one that is known to be.
 */
abstract class CounterStore
{
    /** @var list<string> */
    public const NAMES = ['encrypts', 'decrypts', 'decryptsRaw'];

    /**
     * The best store this host can offer.
     *
     * @return array{0: CounterStore, 1: string} The store and the backend name.
     */
    public static function best(): array
    {
        if (ApcuCounterStore::available()) {
            return [new ApcuCounterStore(), 'apcu'];
        }

        $file = self::defaultFile();
        if ($file !== null && FileCounterStore::usable($file)) {
            return [new FileCounterStore($file), 'file'];
        }

        return [new MemoryCounterStore(), 'memory'];
    }

    /**
     * Where the file back-end writes.
     *
     * Inside `.panda`, beside the credentials, because it belongs to the same
     * installation — and because a path derived from the working directory is
     * the one thing that cannot end up somewhere unexpected.
     */
    public static function defaultFile(): ?string
    {
        $dir = getcwd();
        if ($dir === false) {
            return null;
        }
        return $dir . DIRECTORY_SEPARATOR . '.panda' . DIRECTORY_SEPARATOR . 'telemetry-counters.json';
    }

    /** Merge a batch in. One store operation per request, not per operation. */
    abstract public function addMany(array $counters): void;

    /** @return array<string, int> */
    abstract public function snapshot(): array;

    /** Remove what was reported, once the portal has acknowledged it. */
    abstract public function commit(array $sent): void;

    /** Seconds since the current window opened; 0 when nothing is pending. */
    abstract public function windowSeconds(): int;

    /** @param array<string, int> $counters */
    public static function total(array $counters): int
    {
        $sum = 0;
        foreach (self::NAMES as $name) {
            $sum += (int) ($counters[$name] ?? 0);
        }
        return $sum;
    }

    /** @return array<string, int> */
    public static function zeroes(): array
    {
        return array_fill_keys(self::NAMES, 0);
    }

    /**
     * Subtract, never below zero.
     *
     * Subtracting rather than zeroing keeps anything counted while the request
     * was in flight; zeroing would discard it and nothing would report that.
     */
    protected static function subtract(array $counters, array $sent): array
    {
        $out = self::zeroes();
        foreach (self::NAMES as $name) {
            $out[$name] = max(0, (int) ($counters[$name] ?? 0) - (int) ($sent[$name] ?? 0));
        }
        return $out;
    }

    /** @return array<string, int> */
    protected static function clean(array $counters): array
    {
        $out = self::zeroes();
        foreach (self::NAMES as $name) {
            $out[$name] = max(0, (int) ($counters[$name] ?? 0));
        }
        return $out;
    }
}

/**
 * Shared memory, visible to every worker in the pool.
 */
final class ApcuCounterStore extends CounterStore
{
    private const PREFIX = 'panda_tel_';
    private const WINDOW_KEY = 'panda_tel_window';

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function addMany(array $counters): void
    {
        $added = false;
        foreach (CounterStore::NAMES as $name) {
            $n = (int) ($counters[$name] ?? 0);
            if ($n <= 0) {
                continue;
            }
            $key = self::PREFIX . $name;
            // A step, not a loop: apcu_inc is atomic, so two workers merging at
            // once cannot lose each other's count the way a fetch-modify-store
            // would.
            if (apcu_fetch($key) === false) {
                apcu_store($key, 0);
            }
            apcu_inc($key, $n);
            $added = true;
        }

        if ($added && apcu_fetch(self::WINDOW_KEY) === false) {
            apcu_store(self::WINDOW_KEY, time());
        }
    }

    public function snapshot(): array
    {
        $out = CounterStore::zeroes();
        foreach (CounterStore::NAMES as $name) {
            $value = apcu_fetch(self::PREFIX . $name);
            $out[$name] = is_int($value) ? $value : 0;
        }
        return $out;
    }

    public function commit(array $sent): void
    {
        foreach (CounterStore::NAMES as $name) {
            $n = (int) ($sent[$name] ?? 0);
            if ($n > 0) {
                apcu_dec(self::PREFIX . $name, $n);
            }
        }
        if (CounterStore::total($this->snapshot()) <= 0) {
            apcu_delete(self::WINDOW_KEY);
        }
    }

    public function windowSeconds(): int
    {
        if (CounterStore::total($this->snapshot()) <= 0) {
            return 0;
        }
        $start = apcu_fetch(self::WINDOW_KEY);
        return is_int($start) ? max(0, time() - $start) : 0;
    }
}

/**
 * A lock-protected file. Survives a php-fpm restart; APCu does not.
 */
final class FileCounterStore extends CounterStore
{
    public function __construct(private readonly string $path)
    {
    }

    public static function usable(string $path): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return false;
        }
        return is_writable($dir) || (file_exists($path) && is_writable($path));
    }

    public function addMany(array $counters): void
    {
        if (CounterStore::total($counters) <= 0) {
            return;
        }
        $this->withLock(function (array $data) use ($counters): array {
            $data['counters'] = CounterStore::clean($data['counters']);
            foreach (CounterStore::NAMES as $name) {
                $data['counters'][$name] += (int) ($counters[$name] ?? 0);
            }
            if (($data['window'] ?? 0) === 0) {
                $data['window'] = time();
            }
            return $data;
        });
    }

    public function snapshot(): array
    {
        return CounterStore::clean($this->read()['counters']);
    }

    public function commit(array $sent): void
    {
        $this->withLock(function (array $data) use ($sent): array {
            $data['counters'] = CounterStore::subtract($data['counters'], $sent);
            if (CounterStore::total($data['counters']) <= 0) {
                $data['window'] = 0;
            }
            return $data;
        });
    }

    public function windowSeconds(): int
    {
        $data = $this->read();
        if (CounterStore::total($data['counters']) <= 0 || ($data['window'] ?? 0) === 0) {
            return 0;
        }
        return max(0, time() - (int) $data['window']);
    }

    /** @return array{counters: array<string,int>, window: int} */
    private function read(): array
    {
        if (!file_exists($this->path)) {
            return ['counters' => CounterStore::zeroes(), 'window' => 0];
        }
        $raw = @file_get_contents($this->path);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return ['counters' => CounterStore::zeroes(), 'window' => 0];
        }
        return [
            'counters' => is_array($decoded['counters'] ?? null) ? $decoded['counters'] : CounterStore::zeroes(),
            'window' => (int) ($decoded['window'] ?? 0),
        ];
    }

    /**
     * Read, change, write, under one lock.
     *
     * The lock is held across the read as well as the write: two workers that
     * both read before either writes would each add their batch to the same
     * starting value, and one of the two would vanish.
     */
    private function withLock(callable $mutate): void
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            $raw = stream_get_contents($handle);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = ['counters' => CounterStore::zeroes(), 'window' => 0];
            }
            $data['counters'] = is_array($data['counters'] ?? null)
                ? $data['counters']
                : CounterStore::zeroes();
            $data['window'] = (int) ($data['window'] ?? 0);

            $data = $mutate($data);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}

/**
 * The last resort. Process-local, and it says so.
 */
final class MemoryCounterStore extends CounterStore
{
    /** @var array<string,int> */
    private static array $counters = ['encrypts' => 0, 'decrypts' => 0, 'decryptsRaw' => 0];

    private static int $window = 0;

    public function addMany(array $counters): void
    {
        if (CounterStore::total($counters) <= 0) {
            return;
        }
        foreach (CounterStore::NAMES as $name) {
            self::$counters[$name] += (int) ($counters[$name] ?? 0);
        }
        if (self::$window === 0) {
            self::$window = time();
        }
    }

    public function snapshot(): array
    {
        return CounterStore::clean(self::$counters);
    }

    public function commit(array $sent): void
    {
        self::$counters = CounterStore::subtract(self::$counters, $sent);
        if (CounterStore::total(self::$counters) <= 0) {
            self::$window = 0;
        }
    }

    public function windowSeconds(): int
    {
        if (CounterStore::total(self::$counters) <= 0 || self::$window === 0) {
            return 0;
        }
        return max(0, time() - self::$window);
    }
}
