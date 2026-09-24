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
            'SELECT COUNT(*) FROM pollyanna_likes WHERE identifier = :identifier'
        );
        $statement->execute([':identifier' => $identifier]);

        return (int) $statement->fetchColumn();
    }
}
