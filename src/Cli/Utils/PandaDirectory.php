<?php

namespace Panda\Cli\Utils;

/**
 * Panda directory utilities
 * Manages .panda/ directory structure and files
 */
class PandaDirectory
{
    public const PANDA_DIR = '.panda';
    public const CONFIG_FILE = 'config.json';
    public const CREDENTIALS_FILE = 'credentials';
    public const KEYS_FILE = 'keys.json';

    /**
     * Check if .panda directory exists
     */
    public static function exists(): bool
    {
        return is_dir(self::PANDA_DIR);
    }

    /**
     * Create .panda directory structure
     */
    public static function create(): void
    {
        $pandaPath = self::PANDA_DIR;

        if (!is_dir($pandaPath)) {
            mkdir($pandaPath, 0700, true);
        }

        // Create empty credentials file with restricted permissions
        $credentialsPath = $pandaPath . DIRECTORY_SEPARATOR . self::CREDENTIALS_FILE;
        if (!file_exists($credentialsPath)) {
            file_put_contents($credentialsPath, '');
            chmod($credentialsPath, 0600);
        }

        // Create empty keys.json
        $keysPath = $pandaPath . DIRECTORY_SEPARATOR . self::KEYS_FILE;
        if (!file_exists($keysPath)) {
            file_put_contents($keysPath, json_encode(['keys' => []], JSON_PRETTY_PRINT));
        }
    }

    /**
     * Read config.json
     *
     * @return array|null
     */
    public static function readConfig(): ?array
    {
        $configPath = self::PANDA_DIR . DIRECTORY_SEPARATOR . self::CONFIG_FILE;

        if (!file_exists($configPath)) {
            return null;
        }

        $content = file_get_contents($configPath);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse config.json: " . json_last_error_msg());
        }

        return $config;
    }

    /**
     * Write config.json
     */
    public static function writeConfig(array $config): void
    {
        $configPath = self::PANDA_DIR . DIRECTORY_SEPARATOR . self::CONFIG_FILE;
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
    }

    /**
     * Read credentials
     *
     * @return array|null
     */
    public static function readCredentials(): ?array
    {
        $credentialsPath = self::PANDA_DIR . DIRECTORY_SEPARATOR . self::CREDENTIALS_FILE;

        if (!file_exists($credentialsPath)) {
            return null;
        }

        $content = file_get_contents($credentialsPath);

        if (empty($content)) {
            return ['provider' => 'local'];
        }

        $creds = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse credentials: " . json_last_error_msg());
        }

        return $creds;
    }

    /**
     * Write credentials
     */
    public static function writeCredentials(array $credentials): void
    {
        $credentialsPath = self::PANDA_DIR . DIRECTORY_SEPARATOR . self::CREDENTIALS_FILE;
        file_put_contents($credentialsPath, json_encode($credentials, JSON_PRETTY_PRINT));
        chmod($credentialsPath, 0600);
    }

    /**
     * Read keys metadata
     *
     * @return array|null
     */
    public static function readKeysMetadata(): ?array
    {
        $keysPath = self::PANDA_DIR . DIRECTORY_SEPARATOR . self::KEYS_FILE;

        if (!file_exists($keysPath)) {
            return null;
        }

        $content = file_get_contents($keysPath);
        $keys = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse keys.json: " . json_last_error_msg());
        }

        return $keys;
    }

    /**
     * Write keys metadata
     */
    public static function writeKeysMetadata(array $keys): void
    {
        $keysPath = self::PANDA_DIR . DIRECTORY_SEPARATOR . self::KEYS_FILE;
        file_put_contents($keysPath, json_encode($keys, JSON_PRETTY_PRINT));
    }

    /**
     * Add key to metadata
     */
    public static function addKeyToMetadata(string $kid, string $provider, string $location): void
    {
        $keys = self::readKeysMetadata() ?: ['keys' => []];

        // Check if key already exists
        foreach ($keys['keys'] as $key) {
            if ($key['kid'] === $kid) {
                throw new \RuntimeException("Key '{$kid}' already exists");
            }
        }

        $keys['keys'][] = [
            'kid' => $kid,
            'created' => date('c'),
            'provider' => $provider,
            'location' => $location
        ];

        self::writeKeysMetadata($keys);
    }

    /**
     * Remove .panda directory
     */
    public static function remove(): void
    {
        $pandaPath = self::PANDA_DIR;

        if (is_dir($pandaPath)) {
            self::deleteDirectory($pandaPath);
        }
    }

    /**
     * Recursively delete directory
     */
    private static function deleteDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                self::deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
