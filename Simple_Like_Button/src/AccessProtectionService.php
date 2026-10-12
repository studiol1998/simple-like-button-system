<?php
declare(strict_types=1);

/** Public API protection; independent from browser/identifier Like limits. */
final class AccessProtectionService
{
    public const BLOCK_SECONDS = 172800;
    public const HISTORY_SECONDS = 5184000;
    private PDO $pdo;
    private ?Closure $clock;

    public function __construct(PDO $pdo, ?Closure $clock = null)
    {
        $this->pdo = $pdo;
        $this->clock = $clock;
    }

    public static function defaults(): array
    {
        return ['enabled' => true, 'auto_block' => false,
            'state' => ['window' => 60, 'threshold' => 2400],
            'like' => ['window' => 60, 'threshold' => 240]];
    }

    public static function validate(array $settings): array
    {
        foreach (['enabled', 'auto_block'] as $key) {
            if (!is_bool($settings[$key] ?? null)) throw new InvalidArgumentException('Invalid protection switch.');
        }
        foreach (['state', 'like'] as $api) {
            $rule = $settings[$api] ?? null;
            if (!is_array($rule) || !is_int($rule['window'] ?? null) || $rule['window'] < 10 || $rule['window'] > 3600
                || !is_int($rule['threshold'] ?? null) || $rule['threshold'] < 1 || $rule['threshold'] > 1000000) {
                throw new InvalidArgumentException('Invalid access detection rule.');
            }
        }
        return ['enabled' => $settings['enabled'], 'auto_block' => $settings['auto_block'],
            'state' => ['window' => $settings['state']['window'], 'threshold' => $settings['state']['threshold']],
            'like' => ['window' => $settings['like']['window'], 'threshold' => $settings['like']['threshold']]];
    }

    public static function ipHash(string $ip, string $secret): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) throw new InvalidArgumentException('Invalid connection address.');
        // Domain separation: the browser-token HMAC uses neither this key nor this message.
        $key = hash_hmac('sha256', 'pollyanna.access-protection.key.v1', $secret, true);
        return hash_hmac('sha256', $packed, $key);
    }

    public function available(): bool
    {
        try {
            foreach (['pollyanna_access_buckets', 'pollyanna_access_blocks', 'pollyanna_access_history'] as $table) {
                $this->pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
            }
            return true;
        } catch (PDOException) { return false; }
    }

    private function locked(callable $work): mixed
    {
        // A database-scoped short lock also serializes expiry, manual release and detection.
        $name = 'pollyanna-access-' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 2)');
        $lock->execute([$name]);
        if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Access protection is busy.');
        try {
            $this->pdo->beginTransaction();
            $now = $this->clock !== null ? ($this->clock)() : (int) $this->pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
            $result = $work($now);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
        }
    }

    private function execute(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    private function prune(int $now, array $settings): void
    {
        $date = gmdate('Y-m-d H:i:s', $now);
        $expired = $this->execute('SELECT ip_hash, history_id, expires_at FROM pollyanna_access_blocks WHERE expires_at <= ?', [$date])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expired as $block) {
            $this->execute("UPDATE pollyanna_access_history SET released_at = ?, release_method = 'automatic' WHERE id = ? AND released_at IS NULL", [$block['expires_at'], $block['history_id']]);
            $this->execute('DELETE FROM pollyanna_access_buckets WHERE ip_hash = ?', [$block['ip_hash']]);
        }
        $this->execute('DELETE FROM pollyanna_access_blocks WHERE expires_at <= ?', [$date]);
        foreach (['state', 'like'] as $api) {
            $this->execute('DELETE FROM pollyanna_access_buckets WHERE api = ? AND bucket_at <= ?', [$api, gmdate('Y-m-d H:i:s', $now - $settings[$api]['window'])]);
        }
        if (!$settings['enabled']) $this->execute('DELETE FROM pollyanna_access_buckets');
        $this->execute('DELETE FROM pollyanna_access_history WHERE detected_at <= ?', [gmdate('Y-m-d H:i:s', $now - self::HISTORY_SECONDS)]);
    }

    /** A nonzero result is the remaining block duration, never a Like quota error. */
    public function record(string $api, string $hash, array $settings): int
    {
        $settings = self::validate($settings);
        if (!in_array($api, ['state', 'like'], true) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) throw new InvalidArgumentException('Invalid access input.');
        return $this->locked(function (int $now) use ($api, $hash, $settings): int {
            $this->prune($now, $settings);
            $block = $this->execute('SELECT expires_at FROM pollyanna_access_blocks WHERE ip_hash = ?', [$hash])->fetchColumn();
            if ($block !== false && $settings['auto_block']) return max(1, strtotime((string) $block . ' UTC') - $now);
            if (!$settings['enabled']) return 0;
            $date = gmdate('Y-m-d H:i:s', $now);
            $this->execute('INSERT INTO pollyanna_access_buckets (ip_hash, api, bucket_at, request_count, reported) VALUES (?, ?, ?, 1, 0) ON DUPLICATE KEY UPDATE request_count = LEAST(request_count + 1, 1000000000)', [$hash, $api, $date]);
            $row = $this->execute('SELECT SUM(request_count) AS total, MAX(reported) AS reported FROM pollyanna_access_buckets WHERE ip_hash = ? AND api = ? AND bucket_at > ?', [$hash, $api, gmdate('Y-m-d H:i:s', $now - $settings[$api]['window'])])->fetch(PDO::FETCH_ASSOC);
            $count = (int) $row['total'];
            if ($count <= $settings[$api]['threshold'] || ((int) $row['reported'] === 1 && !$settings['auto_block'])) return 0;
            $blocking = $settings['auto_block'];
            $this->execute('INSERT INTO pollyanna_access_history (detected_at, api, window_seconds, request_count, blocked, block_started_at) VALUES (?, ?, ?, ?, ?, ?)', [$date, $api, $settings[$api]['window'], $count, (int) $blocking, $blocking ? $date : null]);
            $historyId = (int) $this->pdo->lastInsertId();
            $this->execute('UPDATE pollyanna_access_buckets SET reported = 1 WHERE ip_hash = ? AND api = ? AND bucket_at = ?', [$hash, $api, $date]);
            if (!$blocking) return 0;
            $this->execute('INSERT INTO pollyanna_access_blocks (ip_hash, started_at, expires_at, history_id) VALUES (?, ?, ?, ?)', [$hash, $date, gmdate('Y-m-d H:i:s', $now + self::BLOCK_SECONDS), $historyId]);
            $this->execute('DELETE FROM pollyanna_access_buckets WHERE ip_hash = ?', [$hash]);
            return self::BLOCK_SECONDS;
        });
    }

    public function release(int $id, array $settings): bool
    {
        $settings = self::validate($settings);
        return $this->locked(function (int $now) use ($id, $settings): bool {
            $this->prune($now, $settings);
            $block = $this->execute('SELECT ip_hash, history_id FROM pollyanna_access_blocks WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
            if (!$block) return false;
            $this->execute("UPDATE pollyanna_access_history SET released_at = ?, release_method = 'manual' WHERE id = ?", [gmdate('Y-m-d H:i:s', $now), $block['history_id']]);
            $this->execute('DELETE FROM pollyanna_access_buckets WHERE ip_hash = ?', [$block['ip_hash']]);
            $this->execute('DELETE FROM pollyanna_access_blocks WHERE id = ?', [$id]);
            return true;
        });
    }

    public function maintain(array $settings): void
    {
        $settings = self::validate($settings);
        $this->locked(function (int $now) use ($settings): void { $this->prune($now, $settings); });
    }

    public function overview(array $settings, int $page = 1): array
    {
        $settings = self::validate($settings);
        return $this->locked(function (int $now) use ($settings, $page): array {
            $this->prune($now, $settings);
            $blocks = $this->execute('SELECT b.id, b.started_at, b.expires_at, h.api, h.request_count, h.window_seconds FROM pollyanna_access_blocks b JOIN pollyanna_access_history h ON h.id = b.history_id ORDER BY b.id DESC')->fetchAll(PDO::FETCH_ASSOC);
            $total = (int) $this->execute('SELECT COUNT(*) FROM pollyanna_access_history')->fetchColumn();
            $page = max(1, min($page, max(1, (int) ceil($total / 50))));
            $history = $this->execute('SELECT id, detected_at, api, window_seconds, request_count, blocked, block_started_at, released_at, release_method FROM pollyanna_access_history ORDER BY id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50))->fetchAll(PDO::FETCH_ASSOC);
            $recent = (int) $this->execute('SELECT COUNT(*) FROM pollyanna_access_history WHERE (api = ? AND detected_at > ?) OR (api = ? AND detected_at > ?)', ['state', gmdate('Y-m-d H:i:s', $now - $settings['state']['window']), 'like', gmdate('Y-m-d H:i:s', $now - $settings['like']['window'])])->fetchColumn();
            return ['blocks' => $blocks, 'history' => $history, 'total' => $total, 'page' => $page, 'recent' => $recent];
        });
    }
}

function pollyanna_access_settings(): array
{
    $path = dirname(__DIR__) . '/config/access_protection_settings.json';
    if (!is_file($path)) return AccessProtectionService::defaults();
    $settings = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($settings)) throw new RuntimeException('Invalid access protection settings.');
    return AccessProtectionService::validate($settings);
}

function pollyanna_save_access_settings(array $settings): void
{
    $settings = AccessProtectionService::validate($settings);
    $directory = dirname(__DIR__) . '/config';
    $temporary = tempnam($directory, '.access-');
    if ($temporary === false) throw new RuntimeException('Could not create access settings.');
    try {
        if (file_put_contents($temporary, json_encode($settings, JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false
            || !rename($temporary, $directory . '/access_protection_settings.json')) throw new RuntimeException('Could not save access settings.');
    } finally { if (is_file($temporary)) unlink($temporary); }
}
