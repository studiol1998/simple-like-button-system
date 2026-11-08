# Origin / CORS 設計（v1.0 Phase 2）

公開ウィジェットAPIの `api/state.php` と `api/like.php` は `Origin` を必須とする。`Origin: null`、構文不正、未許可Origin、Originなしは403とし、Refererへのフォールバックは行わない。管理画面とインストーラーにはこの制限を適用しない。

本体サイト群は、設定ファイルの `site_origin`、`site_base_domain`、`site_base_domain_include_subdomains` で管理する。外部サイトは `pollyanna_allowed_origins` にscheme、host、実効port、サブドメイン許可を保存する。schemeとportは必ず一致させ、hostは完全一致、またはサブドメイン許可時に `.` と許可hostを末尾に持つ場合だけ一致とする。localhostとIPにはサブドメイン許可を適用しない。

OriginはHTTP/HTTPSのみ受け付け、schemeとDNS hostを小文字化し、既定portを表示から除く。path、query、fragment、ユーザー情報は拒否する。許可済みの外部Originには要求Originを指定した `Access-Control-Allow-Origin` と `Vary: Origin` を返す。ワイルドカードとcredentialsは使用しない。

OPTIONSは要求Originを通常と同じ方法で検証し、要求methodがPOST、要求headerがContent-Typeだけの場合に204を返す。POSTでも改めてOriginを検証する。

Like成立後のpage URLは別途Originを検証する。不許可の場合はLikeを成功のまま維持し、URLを登録せず、サーバーログへ記録する。

## 更新とテスト

既存環境は `install/upgrade-allowed-origins.sql` を実行し、管理画面で本体Originと基準ドメインを保存する。単体試験は `tests/phase2-origin-tests.php`、DB試験は `tests/phase2-db-tests.php` をCLIで実行する。