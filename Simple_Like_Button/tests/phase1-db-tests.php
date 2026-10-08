<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/LikeService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$databaseName = 'pollyanna_phase1_test_' . bin2hex(random_bytes(6));
$databaseHost = getenv('POLLYANNA_TEST_DB_HOST') ?: '127.0.0.1';
$databasePort = getenv('POLLYANNA_TEST_DB_PORT') ?: '3306';
$databaseUser = getenv('POLLYANNA_TEST_DB_USER') ?: 'root';
$databasePassword = getenv('POLLYANNA_TEST_DB_PASSWORD') ?: '';
$server = new PDO(
    'mysql:host=' . $databaseHost . ';port=' . $databasePort . ';charset=utf8mb4',
    $databaseUser,
    $databasePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$server->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');

try {
    $pdo = new PDO(
        'mysql:host=' . $databaseHost . ';port=' . $databasePort . ';dbname=' . $databaseName . ';charset=utf8mb4',
        $databaseUser,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $installSql = file_get_contents(dirname(__DIR__) . '/install/install.sql');
    if (!is_string($installSql)) {
        throw new RuntimeException('Could not read install.sql.');
    }
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', $installSql) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }

    $tables = [
        'pollyanna_likes',
        'pollyanna_rate_limit_events',
        'pollyanna_lifetime_totals',
        'pollyanna_period_aggregates',
        'pollyanna_identifier_urls',
    ];
    foreach ($tables as $table) {
        $pdo->exec('ALTER TABLE `' . $table . '` MODIFY identifier VARCHAR(50) NOT NULL');
    }
    $legacyIdentifier = 'legacy_作品';
    $legacyHash = hash('sha256', 'legacy-client');
    $pdo->prepare('INSERT INTO pollyanna_likes (identifier, client_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP(6))')->execute([$legacyIdentifier, $legacyHash]);
    $pdo->prepare('INSERT INTO pollyanna_rate_limit_events (identifier, client_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP(6))')->execute([$legacyIdentifier, $legacyHash]);
    $pdo->prepare('INSERT INTO pollyanna_lifetime_totals (identifier, total_likes) VALUES (?, 1)')->execute([$legacyIdentifier]);
    $pdo->prepare("INSERT INTO pollyanna_period_aggregates (period_type, period_start, identifier, like_count) VALUES ('day', UTC_DATE(), ?, 1)")->execute([$legacyIdentifier]);
    $pdo->prepare('INSERT INTO pollyanna_identifier_urls (identifier, url_hash, canonical_url, first_seen_at, last_seen_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
        ->execute([$legacyIdentifier, hash('sha256', 'https://example.com/legacy.html'), 'https://example.com/legacy.html']);

    $migrationSql = file_get_contents(dirname(__DIR__) . '/install/upgrade-identifier-100.sql');
    if (!is_string($migrationSql)) {
        throw new RuntimeException('Could not read upgrade-identifier-100.sql.');
    }
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', $migrationSql) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }

    $columnQuery = $pdo->prepare(
        'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    foreach ($tables as $table) {
        $columnQuery->execute([$databaseName, $table, 'identifier']);
        if ((int) $columnQuery->fetchColumn() !== 100) {
            throw new RuntimeException($table . '.identifier is not VARCHAR(100).');
        }
        $legacyRow = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE identifier = ?');
        $legacyRow->execute([$legacyIdentifier]);
        if ((int) $legacyRow->fetchColumn() !== 1) {
            throw new RuntimeException($table . ' did not preserve its legacy row during migration.');
        }
    }

    $identifier = str_repeat('桜', 100);
    $clientHash = hash('sha256', 'phase1-client');
    $service = new LikeService($pdo);
    if (!$service->recordWithinLimit($identifier, $clientHash, 1)) {
        throw new RuntimeException('The first 100-code-point identifier Like was rejected.');
    }
    if ($service->recordWithinLimit($identifier, $clientHash, 1)) {
        throw new RuntimeException('The rate-limited Like was accepted.');
    }

    pollyanna_register_identifier_url($pdo, $identifier, 'https://example.com/a.html?q=1#top');
    pollyanna_register_identifier_url($pdo, $identifier, 'https://example.com/a.html?q=2#other');
    pollyanna_register_identifier_url($pdo, $identifier, 'https://example.com/b.html');
    $urlCount = $pdo->prepare('SELECT COUNT(*) FROM pollyanna_identifier_urls WHERE identifier = ?');
    $urlCount->execute([$identifier]);
    if ((int) $urlCount->fetchColumn() !== 2) {
        throw new RuntimeException('Normalized installation URL deduplication failed.');
    }

    fwrite(STDOUT, "Phase 1 database tests passed.\n");
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
}
