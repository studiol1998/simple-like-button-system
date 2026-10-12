<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/AccessProtectionService.php';
require_once dirname(__DIR__) . '/src/LikeService.php';
require_once dirname(__DIR__) . '/src/AggregationService.php';
$host = getenv('POLLYANNA_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('POLLYANNA_TEST_DB_PORT') ?: '3306';
$user = getenv('POLLYANNA_TEST_DB_USER') ?: 'root';
$password = getenv('POLLYANNA_TEST_DB_PASSWORD') ?: '';
$connect = static fn (string $name): PDO => new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
if (($argv[1] ?? '') === '--worker') {
    $name = $argv[2] ?? '';
    if (!preg_match('/\Apollyanna_access_test_[a-f0-9]{12}\z/', $name)) exit(2);
    $service = new AccessProtectionService($connect($name));
    $settings = AccessProtectionService::defaults(); $settings['auto_block'] = true; $settings['like']['threshold'] = 10;
    for ($i = 0; $i < 10; $i++) $service->record('like', str_repeat('a', 64), $settings);
    exit;
}
$name = 'pollyanna_access_test_' . bin2hex(random_bytes(6));
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
$count = 0;
$assert = static function (bool $ok, string $message) use (&$count): void { $count++; if (!$ok) throw new RuntimeException($message); };
try {
    $pdo = $connect($name);
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', (string) file_get_contents(dirname(__DIR__) . '/install/install.sql'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sql) $pdo->exec($sql);
    // Additive migration is safe to repeat and does not change old data.
    $pdo->exec("INSERT INTO pollyanna_lifetime_totals VALUES ('preserved', 9)");
    for ($i = 0; $i < 2; $i++) foreach (preg_split('/;\s*(?:\r?\n|\z)/', (string) file_get_contents(dirname(__DIR__) . '/install/upgrade-access-protection.sql'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sql) $pdo->exec($sql);
    $assert((int) $pdo->query('SELECT total_likes FROM pollyanna_lifetime_totals')->fetchColumn() === 9, 'Migration preserves totals');
    $now = 1700000000;
    $service = new AccessProtectionService($pdo, static function () use (&$now): int { return $now; });
    $assert($service->available(), 'Schema available');
    $settings = AccessProtectionService::defaults(); $settings['like']['threshold'] = 2;
    $hash = AccessProtectionService::ipHash('192.0.2.1', str_repeat('a', 64));
    $assert($hash !== hash_hmac('sha256', '192.0.2.1', str_repeat('a', 64)), 'HMAC purpose separation');
    $assert($hash !== AccessProtectionService::ipHash('192.0.2.1', str_repeat('b', 64)), 'Secret controls hash');
    $assert($service->record('like', $hash, $settings) === 0, 'Normal request');
    $assert($service->record('like', $hash, $settings) === 0, 'Threshold is inclusive');
    $assert($service->record('like', $hash, $settings) === 0, 'Detection only does not block');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_history')->fetchColumn() === 1, 'Detection history created');
    $service->record('like', $hash, $settings);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_history')->fetchColumn() === 1, 'Detection histories bounded per window');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_blocks')->fetchColumn() === 0, 'No block in detection-only mode');
    $settings['auto_block'] = true;
    $assert($service->record('like', $hash, $settings) === 172800, 'Auto block lasts 48 hours');
    $now += 172799;
    $assert($service->record('state', $hash, $settings) === 1, 'Block covers both APIs until expiry');
    $now++;
    $assert($service->record('state', $hash, $settings) === 0, 'Exact expiry releases block');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_blocks')->fetchColumn() === 0, 'Expired block hash removed');
    $assert($pdo->query('SELECT release_method FROM pollyanna_access_history WHERE blocked = 1')->fetchColumn() === 'automatic', 'Automatic release recorded');
    for ($i = 0; $i < 3; $i++) $retry = $service->record('like', $hash, $settings);
    $assert($retry === 172800, 'Reblock is a new 48 hours');
    $id = (int) $pdo->query('SELECT id FROM pollyanna_access_blocks')->fetchColumn();
    $assert($service->release($id, $settings), 'Manual release');
    $assert(!$service->release($id, $settings), 'Repeated release reports failure');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_buckets')->fetchColumn() === 0, 'Release resets all counters');
    $assert($service->record('like', $hash, $settings) === 0, 'Released connection starts fresh');
    $service->record('like', $hash, $settings);
    $assert($service->record('like', $hash, $settings) === 172800, 'Recurrent concentration reblocks');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_history WHERE blocked = 1')->fetchColumn() === 3, 'New history for each block');
    $settings['auto_block'] = false;
    $expires = $pdo->query('SELECT expires_at FROM pollyanna_access_blocks')->fetchColumn();
    $assert($service->record('state', $hash, $settings) === 172800, 'Auto block OFF retains active block');
    $settings['enabled'] = false;
    $assert($service->record('like', $hash, $settings) === 172800, 'Detection OFF retains active block');
    $assert($pdo->query('SELECT expires_at FROM pollyanna_access_blocks')->fetchColumn() === $expires, 'Switches do not extend block');
    $disabledHash = str_repeat('f', 64);
    for ($i = 0; $i < 5; $i++) $assert($service->record('like', $disabledHash, $settings) === 0, 'Detection OFF does not create blocks');
    $invalid = $settings; $invalid['auto_block'] = true;
    $assert(AccessProtectionService::validate($invalid)['auto_block'] === false, 'Inconsistent legacy switches normalized');
    $service->overview($settings);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_buckets')->fetchColumn() === 0, 'Detection OFF removes counters');
    $now += 172800;
    $service->overview($settings);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_blocks')->fetchColumn() === 0, 'Expired IP hashes removed');
    $assert($service->record('like', $hash, $settings) === 0, 'Expired block remains released with detection OFF');
    $latest = strtotime((string) $pdo->query('SELECT MAX(detected_at) FROM pollyanna_access_history')->fetchColumn() . ' UTC');
    $now = $latest + 5183999;
    $service->overview($settings);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_history')->fetchColumn() > 0, 'History retained before 60-day boundary');
    $now++;
    $service->overview($settings);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_history')->fetchColumn() === 0, 'History deleted at 60-day boundary');
    $settings = AccessProtectionService::defaults();
    // 100 distinct identifiers polling every five seconds for two minutes.
    for ($second = 0; $second < 120; $second += 5) {
        $now += 5;
        for ($visitor = 0; $visitor < 100; $visitor++) $assert($service->record('state', $hash, $settings) === 0, 'Normal polling not blocked');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_history')->fetchColumn() === 0, 'Normal polling not detected');
    $settings['like']['threshold'] = 2;
    for ($i = 0; $i < 3; $i++) $service->record('like', $hash, $settings);
    $assert($pdo->query('SELECT api FROM pollyanna_access_history')->fetchColumn() === 'like', 'API counts independent');
    $now += 60;
    $service->overview($settings);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_buckets')->fetchColumn() === 0, 'Short-term IP hashes deleted');
    $columns = $pdo->query('SHOW COLUMNS FROM pollyanna_access_history')->fetchAll(PDO::FETCH_COLUMN);
    $assert(!in_array('ip_hash', $columns, true) && !in_array('ip', $columns, true), 'History contains no IP or IP hash');
    foreach (['pollyanna_access_buckets', 'pollyanna_access_blocks'] as $table) {
        $columns = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
        $assert(!in_array('ip', $columns, true) && !in_array('ip_address', $columns, true), 'No raw IP column');
    }
    $likeService = new LikeService($pdo);
    $assert($likeService->recordWithinLimit('regression', str_repeat('c', 64), 1), 'Existing Like accepted');
    $assert(!$likeService->recordWithinLimit('regression', str_repeat('c', 64), 1), 'Existing Like limit retained');
    $assert((new AggregationService($pdo))->aggregateBatch(500, 'UTC') === 1, 'Existing aggregation retained');
    foreach (['pollyanna_access_buckets', 'pollyanna_access_blocks', 'pollyanna_access_history'] as $table) $pdo->exec('DELETE FROM ' . $table);
    $workers = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start worker');
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Concurrent worker: ' . $output);
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_access_blocks')->fetchColumn() === 1, 'Concurrent block registered once');
    $row = $pdo->query('SELECT request_count FROM pollyanna_access_history')->fetchColumn();
    $assert((int) $row === 11, 'Concurrent threshold crossed exactly once at 11');
    echo "Access protection database tests passed ({$count} assertions).\n";
} finally {
    $server->exec("DROP DATABASE `{$name}`");
}
