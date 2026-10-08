<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = 'pollyanna_phase2_test_' . bin2hex(random_bytes(6));
$host = getenv('POLLYANNA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('POLLYANNA_TEST_DB_PORT') ?: '3306';
$user = getenv('POLLYANNA_TEST_DB_USER') ?: 'root';
$password = getenv('POLLYANNA_TEST_DB_PASSWORD') ?: '';
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sql = file_get_contents(dirname(__DIR__) . '/install/install.sql');
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', (string) $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) $pdo->exec($statement);
    $pdo->exec('DROP TABLE pollyanna_allowed_origins');
    $migration = file_get_contents(dirname(__DIR__) . '/install/upgrade-allowed-origins.sql');
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', (string) $migration, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) $pdo->exec($statement);
    $columns = $pdo->query("SHOW COLUMNS FROM pollyanna_allowed_origins")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id', 'scheme', 'host', 'port', 'include_subdomains', 'created_at'] as $column) {
        if (!in_array($column, $columns, true)) throw new RuntimeException("Missing column: {$column}");
    }
    $insert = $pdo->prepare('INSERT INTO pollyanna_allowed_origins (scheme, host, port, include_subdomains, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))');
    $insert->execute(['https', 'example.com', 443, 1]);
    try { $insert->execute(['https', 'example.com', 443, 0]); throw new RuntimeException('Duplicate origin was accepted.'); }
    catch (PDOException $expected) {}
    $pdo->exec('DELETE FROM pollyanna_allowed_origins WHERE host = \'example.com\'');
    if ((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_allowed_origins')->fetchColumn() !== 0) throw new RuntimeException('Physical deletion failed.');
    echo "Phase 2 database tests passed.\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
}
