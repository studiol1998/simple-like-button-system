<?php
declare(strict_types=1);

final class PollyannaInstallerState
{
    public const UNINSTALLED = 'uninstalled';
    public const PARTIAL = 'partial';
    public const COMPLETE = 'complete';

    private const REQUIRED_TABLES = [
        'pollyanna_likes',
        'pollyanna_rate_limit_events',
        'pollyanna_lifetime_totals',
        'pollyanna_period_aggregates',
        'pollyanna_hourly_aggregates',
        'pollyanna_identifier_urls',
        'pollyanna_allowed_origins',
        'pollyanna_access_buckets',
        'pollyanna_access_history',
        'pollyanna_access_blocks',
    ];

    public static function isWritableDirectory(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    public static function shouldLockAdmin(string $installDirectory, string $configPath, string $credentialsPath, string $completionPath): bool
    {
        return is_dir($installDirectory)
            && self::detect($configPath, $credentialsPath, $completionPath) !== self::UNINSTALLED;
    }

    public static function detect(string $configPath, string $credentialsPath, string $completionPath): string
    {
        $hasAnyArtifact = is_file($configPath) || is_file($credentialsPath) || is_file($completionPath);
        if (!$hasAnyArtifact) {
            return self::UNINSTALLED;
        }
        if (
            self::loadConfig($configPath) !== null
            && self::hasValidCredentials($credentialsPath)
            && self::hasValidCompletionMarker($completionPath)
        ) {
            return self::COMPLETE;
        }
        return self::PARTIAL;
    }

    public static function loadConfig(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $config = require $path;
        } catch (Throwable) {
            return null;
        }
        if (!is_array($config) || !is_array($config['database'] ?? null)) {
            return null;
        }
        $database = $config['database'];
        $secret = $config['app_secret'] ?? null;
        if (
            !is_string($database['dsn'] ?? null)
            || $database['dsn'] === ''
            || !is_string($database['username'] ?? null)
            || !is_string($database['password'] ?? null)
            || !is_string($secret)
            || preg_match('/\A[a-f0-9]{64}\z/i', $secret) !== 1
        ) {
            return null;
        }
        return $config;
    }

    public static function hasValidCredentials(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }
        try {
            $credentials = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        return is_array($credentials)
            && is_string($credentials['password_hash'] ?? null)
            && $credentials['password_hash'] !== ''
            && password_get_info($credentials['password_hash'])['algo'] !== null;
    }

    public static function hasValidCompletionMarker(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }
        try {
            $completion = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        return is_array($completion) && ($completion['completed'] ?? false) === true;
    }

    public static function verifyRecoverable(string $configPath, string $credentialsPath): void
    {
        $config = self::loadConfig($configPath);
        if ($config === null) {
            throw new RuntimeException('The saved configuration is missing or invalid.');
        }
        if (!self::hasValidCredentials($credentialsPath)) {
            throw new RuntimeException('The saved administrator credentials are missing or invalid.');
        }

        $database = $config['database'];
        $pdo = new PDO($database['dsn'], $database['username'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        foreach (self::REQUIRED_TABLES as $table) {
            $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        }
    }

    public static function writeCompletionMarker(string $path): void
    {
        $json = json_encode(
            ['completed' => true, 'installed_at' => gmdate('c')],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        self::atomicWrite($path, $json);
        if (!self::hasValidCompletionMarker($path)) {
            throw new RuntimeException('The installation completion marker could not be verified.');
        }
    }

    private static function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('The completion marker directory is not writable.');
        }
        $temporary = tempnam($directory, '.pollyanna-install-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary completion marker.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Could not write a temporary completion marker.');
            }
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Could not replace an incomplete completion marker.');
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Could not publish the completion marker.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
