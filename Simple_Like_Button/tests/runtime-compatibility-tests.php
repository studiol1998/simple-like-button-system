<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/LikeService.php';
require_once dirname(__DIR__) . '/src/TimezoneChangeService.php';

// Test only PHP session plumbing, never the application's actual session store.
final class RegressionMemorySession implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public array $records = [];
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string { return $this->records[$id] ?? ''; }
    public function write(string $id, string $data): bool { $this->records[$id] = $data; return true; }
    public function destroy(string $id): bool { unset($this->records[$id]); return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
    public function validateId(string $id): bool { return array_key_exists($id, $this->records); }
    public function updateTimestamp(string $id, string $data): bool { return $this->write($id, $data); }
}
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($label);
};
$assert(session_status() === PHP_SESSION_NONE, 'No inherited active session');
$handler = new RegressionMemorySession();
session_set_save_handler($handler, true);
session_name('pollyanna_regression_only');
session_id('unrecognized-regression-session');
$assert(session_start(['use_cookies' => 0, 'use_strict_mode' => 1, 'cache_limiter' => '']), 'In-memory session starts');
$assert(session_id() !== 'unrecognized-regression-session', 'Strict mode rejects an unknown session ID');
$oldId = session_id();
$_SESSION['unicode'] = '桜😀';
$_SESSION['authenticated'] = true;
$assert(session_regenerate_id(true), 'Session ID rotation succeeds');
$assert(session_id() !== $oldId && !isset($handler->records[$oldId]), 'Old session destroyed');
$id = session_id();
$assert(session_write_close(), 'Session data written to memory');
session_id($id);
$assert(session_start(['use_cookies' => 0, 'use_strict_mode' => 1, 'cache_limiter' => '']), 'Session reopens');
$assert($_SESSION === ['unicode' => '桜😀', 'authenticated' => true], 'Session Unicode and types survive reopen');
$_SESSION = [];
$assert(session_destroy() && !isset($handler->records[$id]), 'Session logout plumbing destroys data');

// Isolated PDO: no MySQL connection, app configuration, or persistent database.
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE pollyanna_likes (identifier TEXT, client_hash TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE pollyanna_lifetime_totals (identifier TEXT PRIMARY KEY, total_likes INTEGER)');
$identifier = '桜😀_01';
$insert = $pdo->prepare('INSERT INTO pollyanna_likes VALUES (?, ?, ?)');
$insert->execute([$identifier, 'client', '2026-01-01 00:00:00']);
$pdo->prepare('INSERT INTO pollyanna_lifetime_totals VALUES (?, ?)')->execute([$identifier, 4]);
$service = new LikeService($pdo);
$assert($service->hasLiked($identifier, 'client'), 'Actual LikeService reads an existing Like');
$assert(!$service->hasLiked($identifier, 'other'), 'Client lookup stays separate');
$assert($service->count($identifier) === 5, 'Actual LikeService combines pending and aggregate counts');
$assert($service->count("' OR 1=1 --") === 0, 'Identifier lookup is parameterized');
$pdo->beginTransaction();
$insert->execute(['rolled-back', 'client', '2026-01-01 00:00:00']);
$pdo->rollBack();
$assert(!$service->hasLiked('rolled-back', 'client'), 'PDO rollback preserves prior state');
try {
    $pdo->query('SELECT * FROM missing_regression_table');
    $assert(false, 'PDO throws on SQL failure');
} catch (PDOException) {
    $assert(true, 'PDO exception mode works');
}

// PHP date primitives used by aggregation; full timezone workflow needs MySQL/files.
$utc = new DateTimeZone('UTC');
$tokyo = new DateTimeZone('Asia/Tokyo');
$assert((new DateTimeImmutable('2026-12-31 15:00:00', $utc))->setTimezone($tokyo)->format('Y-m-d H:i:s') === '2027-01-01 00:00:00', 'Tokyo year boundary');
$newYork = new DateTimeZone('America/New_York');
$assert((new DateTimeImmutable('2026-03-08 06:59:59', $utc))->setTimezone($newYork)->format('H:i:s P') === '01:59:59 -05:00', 'Before DST jump');
$assert((new DateTimeImmutable('2026-03-08 07:00:00', $utc))->setTimezone($newYork)->format('H:i:s P') === '03:00:00 -04:00', 'After DST jump');
$assert((new DateTimeImmutable('2026-11-01 05:30:00', $utc))->setTimezone($newYork)->format('H:i P') === '01:30 -04:00', 'First repeated DST hour');
$assert((new DateTimeImmutable('2026-11-01 06:30:00', $utc))->setTimezone($newYork)->format('H:i P') === '01:30 -05:00', 'Second repeated DST hour');
$invalidTimezoneRejected = false;
try { new DateTimeZone('Invalid/Pollyanna'); }
catch (Throwable) { $invalidTimezoneRejected = true; }
$assert($invalidTimezoneRejected, 'Invalid timezone rejected');

// Exercise the real private CSV writer without changing its production visibility.
// Only this exact tempnam-owned file is created/deleted; no recursive cleanup.
$csvPath = tempnam(sys_get_temp_dir(), 'pollyanna-stage1-');
if ($csvPath === false) throw new RuntimeException('Cannot allocate isolated CSV fixture.');
try {
    $pdo->exec('DELETE FROM pollyanna_lifetime_totals');
    $fixtures = [['=SUM(1,2)', 1], ['+formula', 2], ['-formula', 3], ['@formula', 4], ['桜😀,"引用"', 5]];
    $put = $pdo->prepare('INSERT INTO pollyanna_lifetime_totals VALUES (?, ?)');
    foreach ($fixtures as $row) $put->execute($row);
    $writer = new ReflectionMethod(TimezoneChangeService::class, 'writeCsv');
    $writer->invoke(new TimezoneChangeService($pdo), $csvPath, ['identifier', 'total_likes'],
        'SELECT identifier, total_likes FROM pollyanna_lifetime_totals ORDER BY total_likes');
    $handle = fopen($csvPath, 'rb');
    if ($handle === false) throw new RuntimeException('Cannot read isolated CSV fixture.');
    try {
        $assert(fgetcsv($handle, 0, ',', '"', '\\') === ['identifier', 'total_likes'], 'Actual CSV header');
        foreach ($fixtures as [$value, $count]) {
            $expected = $count <= 4 ? "'" . $value : $value;
            $assert(fgetcsv($handle, 0, ',', '"', '\\') === [$expected, (string) $count], 'Actual CSV Unicode, quoting and formula safety');
        }
        $assert(fgetcsv($handle, 0, ',', '"', '\\') === false, 'CSV has exactly the expected rows');
    } finally { fclose($handle); }
} finally {
    if (!unlink($csvPath)) throw new RuntimeException('Cannot remove isolated CSV fixture.');
}
echo "Runtime compatibility tests passed ($checks assertions; memory sessions/SQLite, isolated CSV, date primitives).\n";
