# Like Button System Ver 0.1

独立型PHPいいねシステム

## 機能
- ❤️いいねボタン
- AJAX通信
- 6時間10回制限
- メンテナンスモード
- 総数取得API

## ディレクトリ構成

like/
├── config.php
├── like.js
├── api/
│   ├── like.php
│   └── count.php
├── classes/
│   └── LikeService.php
└── sql/
    └── install.sql

## インストール

1. install.sql を実行
2. config.php のDB設定を変更
3. likeフォルダをサーバーへ配置
4. HTMLへ以下を記述

```html
<div class="like-area" data-page-id="novel_001"></div>
<script src="/like/like.js"></script>
```

## 管理モード

like_settings テーブルの mode を変更

enabled      : 通常運用
maintenance  : メンテナンス
disabled     : 停止
