<?php
/**
 * Like Button System Ver 0.1
 * いいね機能の中核クラス
 */
class LikeService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * システム設定取得
     */
    public function getMode(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM like_settings WHERE id=1"
        );

        return $stmt->fetch();
    }

    /**
     * IPをハッシュ化して保存
     */
    public function getIpHash(): string
    {
        return hash('sha256', $_SERVER['REMOTE_ADDR']);
    }

    /**
     * 6時間10回制限
     */
    public function canLike(): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM likes
            WHERE ip_hash=?
            AND created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR)
        ");

        $stmt->execute([$this->getIpHash()]);

        return $stmt->fetchColumn() < 10;
    }

    /**
     * いいね登録
     */
    public function addLike(string $pageId): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO likes(page_id, ip_hash)
            VALUES(?, ?)
        ");

        return $stmt->execute([
            $pageId,
            $this->getIpHash()
        ]);
    }

    /**
     * ページ別いいね数取得
     */
    public function getCount(string $pageId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM likes
            WHERE page_id=?
        ");

        $stmt->execute([$pageId]);

        return (int)$stmt->fetchColumn();
    }
}
