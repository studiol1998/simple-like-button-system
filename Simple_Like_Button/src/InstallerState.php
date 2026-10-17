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
        if (file_exists(dirname($configPath) . '/.installer-in-progress')) return self::PARTIAL;
        $hasAnyArtifact = file_exists($configPath) || file_exists($credentialsPath) || file_exists($completionPath);
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
        self::createExclusive($path, $json);
        if (!self::hasValidCompletionMarker($path)) {
            throw new RuntimeException('The installation completion marker could not be verified.');
        }
    }

    /** Never replace existing installer artifacts, even between checking and writing. */
    public static function createExclusive(string $path, string $contents): void
    {
        $stream = @fopen($path, 'x');
        if ($stream === false) throw new RuntimeException('Installer artifact already exists or cannot be created.');
        try {
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = @fwrite($stream, substr($contents, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Could not write installer artifact.');
                $offset += $written;
            }
            if (!fflush($stream)) throw new RuntimeException('Could not flush installer artifact.');
        } finally { fclose($stream); }
        // A partial newly created file is intentionally retained on failure.
    }

    private static function locked(string $configPath, callable $work): void
    {
        $lock = @fopen(dirname($configPath) . '/.installer.lock', 'c');
        if ($lock === false) throw new RuntimeException('Installer locking is unavailable.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another installer is running.');
            $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function install(string $configPath, string $credentialsPath, string $completionPath, callable $work): void
    {
        self::locked($configPath, static function () use ($configPath, $credentialsPath, $completionPath, $work): void {
            clearstatcache();
            if (self::detect($configPath, $credentialsPath, $completionPath) !== self::UNINSTALLED) {
                throw new RuntimeException('Installation already started or completed.');
            }
            $progress = dirname($configPath) . '/.installer-in-progress';
            self::createExclusive($progress, json_encode(['started_at' => gmdate('c')], JSON_THROW_ON_ERROR) . PHP_EOL);
            $work();
            self::verifyRecoverable($configPath, $credentialsPath);
            if (!self::hasValidCompletionMarker($completionPath)) throw new RuntimeException('Installation completion is missing.');
            if (!unlink($progress)) throw new RuntimeException('Installation finished, but progress cleanup requires review.');
        });
    }

    public static function recover(string $configPath, string $credentialsPath, string $completionPath, bool $stoppedConfirmed): void
    {
        self::locked($configPath, static function () use ($configPath, $credentialsPath, $completionPath, $stoppedConfirmed): void {
            clearstatcache();
            if (self::detect($configPath, $credentialsPath, $completionPath) !== self::PARTIAL) throw new RuntimeException('Installation is not partial.');
            $progress = dirname($configPath) . '/.installer-in-progress';
            if (!$stoppedConfirmed) throw new RuntimeException('Confirm that the previous installer stopped before recovery.');
            self::verifyRecoverable($configPath, $credentialsPath);
            if (!self::hasValidCompletionMarker($completionPath)) self::writeCompletionMarker($completionPath);
            if (file_exists($progress) && !unlink($progress)) throw new RuntimeException('Progress cleanup requires review.');
        });
    }
}
