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
}
