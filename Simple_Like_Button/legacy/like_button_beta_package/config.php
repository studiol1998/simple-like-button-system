<?php
/**
 * DB接続設定
 */
$pdo = new PDO(
    'mysql:host=localhost;dbname=like_db;charset=utf8mb4',
    'db_user',
    'db_password',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);
