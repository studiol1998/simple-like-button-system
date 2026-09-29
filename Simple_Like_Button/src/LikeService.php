<?php
declare(strict_types=1);

final class LikeService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function record(string $identifier, string $clientHash): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pollyanna_likes (identifier, client_hash, created_at) '
            . 'VALUES (:identifier, :client_hash, UTC_TIMESTAMP(6))'
        );
        $statement->execute([
            ':identifier' => $identifier,
            ':client_hash' => $clientHash,
        ]);
    }

    public function recordWithinLimit(string $identifier, string $clientHash, int $limit): bool
    {
        $lockName = 'pollyanna-rate-' . substr(hash('sha256', $clientHash . "\0" . $identifier), 0, 48);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
        $lock->execute([':lock_name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the Like rate-limit lock.');
        }

        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare(
                'DELETE FROM pollyanna_rate_limit_events '
                . 'WHERE identifier = :identifier AND client_hash = :client_hash '
                . 'AND created_at <= UTC_TIMESTAMP(6) - INTERVAL 24 HOUR'
            )->execute([
                ':identifier' => $identifier,
                ':client_hash' => $clientHash,
            ]);

            $count = $this->pdo->prepare(
                'SELECT COUNT(*) FROM pollyanna_rate_limit_events '
                . 'WHERE identifier = :identifier AND client_hash = :client_hash '
                . 'AND created_at > UTC_TIMESTAMP(6) - INTERVAL 24 HOUR'
            );
            $count->execute([
                ':identifier' => $identifier,
                ':client_hash' => $clientHash,
            ]);
            if ((int) $count->fetchColumn() >= $limit) {
                $this->pdo->commit();
                return false;
            }

            $event = $this->pdo->prepare(
                'INSERT INTO pollyanna_rate_limit_events (identifier, client_hash, created_at) '
                . 'VALUES (:identifier, :client_hash, UTC_TIMESTAMP(6))'
            );
            $event->execute([
                ':identifier' => $identifier,
                ':client_hash' => $clientHash,
            ]);

            $this->record($identifier, $clientHash);
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute([':lock_name' => $lockName]);
        }
    }

    public function hasLiked(string $identifier, string $clientHash): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM pollyanna_likes '
            . 'WHERE identifier = :identifier AND client_hash = :client_hash '
            . 'LIMIT 1'
        );
        $statement->execute([
            ':identifier' => $identifier,
            ':client_hash' => $clientHash,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function count(string $identifier): int
    {
        $statement = $this->pdo->prepare(
            'SELECT '
            . 'COALESCE((SELECT total_likes FROM pollyanna_lifetime_totals '
            . 'WHERE identifier = :aggregate_identifier), 0) '
            . '+ (SELECT COUNT(*) FROM pollyanna_likes WHERE identifier = :pending_identifier)'
        );
        $statement->execute([
            ':aggregate_identifier' => $identifier,
            ':pending_identifier' => $identifier,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function pendingCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM pollyanna_likes')->fetchColumn();
    }
}
