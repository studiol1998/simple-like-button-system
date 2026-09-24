<?php
declare(strict_types=1);

function pollyanna_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $path = dirname(__DIR__) . '/config/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Pollyanna configuration is missing.');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('Pollyanna configuration is invalid.');
    }

    $database = $loaded['database'] ?? null;
    $secret = $loaded['app_secret'] ?? null;
    if (
        !is_array($database)
        || !isset($database['dsn'], $database['username'], $database['password'])
        || !is_string($database['dsn'])
        || !is_string($database['username'])
        || !is_string($database['password'])
        || !is_string($secret)
        || preg_match('/\A[a-f0-9]{64}\z/i', $secret) !== 1
    ) {
        throw new RuntimeException('Pollyanna configuration is incomplete.');
    }

    $config = $loaded;
    return $config;
}

function pollyanna_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = pollyanna_config();
    $pdo = new PDO(
        $config['database']['dsn'],
        $config['database']['username'],
        $config['database']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function pollyanna_count_display_enabled(): bool
{
    return (pollyanna_config()['show_like_count'] ?? false) === true;
}

function pollyanna_json_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
