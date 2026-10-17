<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/AggregationService.php';

// In-memory regression tests. Translate MySQL syntax only in this test adapter.
// This does not validate MySQL locks, isolation or query plans.
final class ReviewPDO extends PDO
{
    public bool $failCleanup = false;
    public int $cleanupCalls = 0;
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    private function translate(string $sql): string
    {
        $sql = str_replace(['SELECT DATABASE()', ' FOR UPDATE'], ["SELECT 'review_memory'", ''], $sql);
        if (str_contains($sql, 'GET_LOCK(') || str_contains($sql, 'RELEASE_LOCK(')) return 'SELECT 1 WHERE :lock_name IS NOT NULL';
        $sql = preg_replace('/ON DUPLICATE KEY UPDATE (\w+) = \1 \+ VALUES\(\1\)/', 'ON CONFLICT DO UPDATE SET $1 = $1 + excluded.$1', $sql);
        return $sql;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->translate($query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query($this->translate($query));
    }
    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'DELETE FROM pollyanna_rate_limit_events ')) {
            if ($this->inTransaction()) throw new RuntimeException('Cleanup must follow the aggregation commit.');
            $this->cleanupCalls++;
            if ($this->failCleanup) throw new RuntimeException('Simulated cleanup failure.');
            if (!preg_match('/LIMIT (\d+)\z/', $statement, $match)) throw new RuntimeException('Cleanup is not bounded.');
            $cutoff = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));
            $statement = "DELETE FROM pollyanna_rate_limit_events WHERE id IN (SELECT id FROM pollyanna_rate_limit_events WHERE created_at <= '" . $cutoff . "' ORDER BY id ASC LIMIT " . $match[1] . ')';
        }
        return parent::exec($this->translate($statement));
    }
}

$pdo = new ReviewPDO();
$pdo->exec('CREATE TABLE pollyanna_likes (id INTEGER PRIMARY KEY, identifier TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE pollyanna_rate_limit_events (id INTEGER PRIMARY KEY, identifier TEXT, client_hash TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE pollyanna_lifetime_totals (identifier TEXT PRIMARY KEY, total_likes INTEGER)');
$pdo->exec('CREATE TABLE pollyanna_period_aggregates (period_type TEXT, period_start TEXT, identifier TEXT, like_count INTEGER, PRIMARY KEY(period_type,period_start,identifier))');
$pdo->exec('CREATE TABLE pollyanna_hourly_aggregates (month_start TEXT, hour_of_day INTEGER, like_count INTEGER, PRIMARY KEY(month_start,hour_of_day))');
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($label); };
$service = new AggregationService($pdo);
$old = gmdate('Y-m-d H:i:s', strtotime('-25 hours'));
$recent = gmdate('Y-m-d H:i:s', strtotime('-1 hour'));
$insert = $pdo->prepare('INSERT INTO pollyanna_rate_limit_events VALUES (?, ?, ?, ?)');
for ($i = 1; $i <= 5; $i++) $insert->execute([$i, 'abandoned-' . $i, 'client-' . $i, $old]);
$insert->execute([6, 'active', 'active-client', $recent]);
$assert($service->aggregateBatch(2, 'UTC') === 0, 'Empty aggregation succeeds');
$assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_rate_limit_events')->fetchColumn() === 4, 'Only one bounded cleanup batch removed');
$service->aggregateBatch(2, 'UTC'); $service->aggregateBatch(2, 'UTC');
$assert((int) $pdo->query('SELECT COUNT(*) FROM pollyanna_rate_limit_events')->fetchColumn() === 1, 'Abandoned targets are eventually removed');
$assert($pdo->query('SELECT identifier FROM pollyanna_rate_limit_events')->fetchColumn() === 'active', 'Active quota preserved');

$pdo->prepare('INSERT INTO pollyanna_likes VALUES (?, ?, ?)')->execute([1, 'new-like', $recent]);
$assert($service->aggregateBatch(2, 'UTC') === 1, 'Nonempty aggregation succeeds');
$assert((int) $pdo->query("SELECT total_likes FROM pollyanna_lifetime_totals WHERE identifier = 'new-like'")->fetchColumn() === 1, 'Like total preserved');
$pdo->failCleanup = true;
$assert($service->aggregateBatch(2, 'UTC') === 0, 'Cleanup failure does not fail completed aggregation');
$pdo->failCleanup = false;
$before = $pdo->cleanupCalls;
try { $service->aggregateBatch(0, 'UTC'); $assert(false, 'Invalid batch rejected'); }
catch (InvalidArgumentException) { $assert($pdo->cleanupCalls === $before, 'Invalid batch cannot reach cleanup'); }

// Force the daily-row count compaction path within the dashboard's 12-month range.
$date = gmdate('Y-m-01', strtotime('-2 months'));
$pdo->exec('DELETE FROM pollyanna_period_aggregates');
$pdo->beginTransaction();
$insert = $pdo->prepare("INSERT INTO pollyanna_period_aggregates VALUES ('day', ?, ?, 1)");
for ($i = 0; $i < AggregationService::DAILY_ROW_LIMIT + 2; $i++) $insert->execute([$date, sprintf('target-%05d', $i)]);
$pdo->commit();
$admin = (string) file_get_contents(dirname(__DIR__) . '/admin/index.php');
if (!preg_match('/SELECT period_start, like_count FROM pollyanna_period_aggregates WHERE period_type [^"\r\n]+/', $admin, $match)) throw new RuntimeException('Dashboard monthly SQL not found');
$monthlyTotal = static function () use ($pdo, $date, $match): int {
    $statement = $pdo->prepare($match[0]); $statement->execute([':start' => $date]);
    return array_sum(array_column($statement->fetchAll(), 'like_count'));
};
$before = $monthlyTotal();
$service->aggregateBatch(2, 'UTC');
$assert((int) $pdo->query("SELECT COUNT(*) FROM pollyanna_period_aggregates WHERE period_type = 'month'")->fetchColumn() === 2, 'Daily rows compacted into monthly rows');
$assert($monthlyTotal() === $before, 'Dashboard total unchanged after daily-to-month compaction');
$service->aggregateBatch(2, 'UTC');
$assert($monthlyTotal() === $before, 'Repeated aggregation does not double count monthly rows');
echo "Aggregation review tests passed ($checks assertions; in-memory SQLite adapter, not a MySQL concurrency test).\n";
