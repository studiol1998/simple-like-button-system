# Pollyanna v0.4.1 外部Origin・CORS対応 事前調査

調査日：2026-10-06  
対象実装：`v0.4.1-installer-guide`（`4752db2`）  
対象文書：`df62299`

## 1. 現行v0.4.1の通信仕様

`assets/like.js` は、スクリプト自身のURLから次のAPI URLを生成する。

- `../api/state.php`
- `../api/like.php`

`state.php` は初期表示時の状態復元と、件数表示中の定期更新で呼ばれる。`like.php` はクリック時に呼ばれる。どちらも次の形式である。

```js
fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
});
```

| 項目 | 現行仕様 |
| --- | --- |
| HTTPメソッド | `POST` |
| Content-Type | `application/json` |
| credentials | 未指定。既定値は `same-origin` |
| mode | 未指定。既定値は `cors` |
| 独自ヘッダー | なし。明示指定は `Content-Type` のみ |
| Cookie | 公開APIでは利用していない |

外部Originでは `credentials: same-origin` によりPollyanna側Cookieは送信されない。公開APIはセッション認証を利用していないため、現行機能への直接の影響はない。

## 2. 同一Origin通信への影響

Fetch標準では、CORSリクエスト、およびGET・HEAD以外のリクエストへOriginヘッダーが付与される。現行の通信は既定で `mode: cors` のPOSTであるため、現在のChrome・Firefoxによる通常の同一Origin通信でも、基本的に実際のOriginが送信される。

例：

```http
Origin: https://eraminan.jp
```

したがって、Origin必須にしても一般的なChrome・Firefoxでの現行利用は通常壊れない。ただし、次の互換性に影響する。

- 非ブラウザクライアント
- 一部のWebViewや特殊クライアント
- Originを削除するプロキシやセキュリティ製品
- 手動API確認ツール
- 将来のサーバー間連携

Refererは `Referrer-Policy` により省略でき、ブラウザ以外では偽装可能である。Originがない場合の許可判定をRefererで代替することは推奨しない。Refererは診断用の補助情報に限定する。

## 3. 外部Origin通信を追加する場合の必要変更

許可済み外部Originへは、要求元の具体的Originをそのまま返す。

```http
Access-Control-Allow-Origin: https://example.com
Vary: Origin
```

`Access-Control-Allow-Origin: *` は使用しない。

必要な処理は次のとおり。

1. Originヘッダーを取得する。
2. Originを構文解析・正規化する。
3. Pollyanna本体Originか確認する。
4. 外部Origin許可テーブルと照合する。
5. サブドメイン許可を判定する。
6. 許可済みの場合だけ具体的Originをレスポンスへ設定する。
7. 不許可Originは403とする。
8. preflightはPOST処理やJSON解析を行わず終了する。
9. 実際のPOSTでも同じOrigin判定を行う。
10. 422、429、503等のエラー応答にも必要なCORSヘッダーを付ける。

エラー応答にCORSヘッダーがなければ、外部ページはJSONエラーを読めず、一般的なCORSエラーとしてしか扱えない。

## 4. CORS / OPTIONSの必要条件

外部Originからの現在のリクエストはpreflight対象となる。POSTはCORS safelist対象のメソッドだが、`Content-Type: application/json` はsafelisted Content-Typeではない。

現行の `state.php` と `like.php` はPOST以外を405にするため、OPTIONSは失敗し、実際のPOSTは送信されない。

許可済みOriginに対する推奨preflight応答：

```http
HTTP/1.1 204 No Content
Access-Control-Allow-Origin: https://example.com
Access-Control-Allow-Methods: POST
Access-Control-Allow-Headers: Content-Type
Vary: Origin
```

- 許可メソッドは `POST` のみとする。
- 許可ヘッダーは `Content-Type` のみとする。
- 現行APIでは `Access-Control-Allow-Credentials` は不要である。
- `Access-Control-Max-Age` は任意とする。
- preflight処理はJSON bodyの読み込み前に行う。

## 5. Originなしリクエストの扱いに関する推奨

初期実装では次を推奨する。

### Originあり

- 本体Originまたは許可済み外部Originなら処理する。
- 不許可Originなら403とする。
- `Origin: null` は拒否する。

### Originなし

- 従来互換のため処理を許可する。
- CORSヘッダーは返さない。
- 外部Origin許可機能による保護対象外であることを仕様へ明記する。

Originなしを許可すると、curl等の直接送信は防げない。一方、Originなしをすべて拒否すると、非ブラウザクライアントや一部の特殊環境を破壊する。

ブラウザ専用APIとして厳格化する場合は、設定で厳格モードを選べるようにするか、次のメジャー仕様でOrigin必須を明記する方が安全である。

## 6. 外部Origin設定のDB設計案

現行Pollyannaには汎用設定テーブルがなく、設定の多くは `config/*.json` に保存される。外部Originは複数行の追加・一覧・物理削除が必要なため、新規テーブルが適切である。

推奨テーブル名：

```text
pollyanna_allowed_origins
```

推奨列：

| 列 | 用途 |
| --- | --- |
| `id` | 管理画面で削除対象を特定する |
| `scheme` | `http` または `https` |
| `host` | 正規化済みホスト |
| `port` | 正規化済み実効ポート |
| `include_subdomains` | サブドメインを許可するか |
| `created_at` | 登録日時 |

ポート省略時は次の実効ポートへ正規化する。

- HTTP：80
- HTTPS：443

一意制約候補：

```text
UNIQUE (scheme, host, port)
```

削除時は許可設定行だけを物理削除する。Likes、集計テーブル、identifier URLテーブルとは外部キーで接続しない。これにより、削除しても過去のLikes、集計値、identifier、URL対応は残る。

## 7. Pollyanna本体Originの判定

現行configにはPollyanna本体Originが保存されていない。インストーラーが生成する `config/config.php` にも、DB設定、秘密鍵、各種初期設定だけが保存される。

HTTP Hostをリクエストごとに無条件で信用する方法には、Hostヘッダー汚染やリバースプロキシ設定ミスの問題がある。`X-Forwarded-Host` や `X-Forwarded-Proto` も、信頼するプロキシを限定せず利用してはならない。

推奨方法：

1. インストール時に本体Origin候補を算出する。
2. インストーラーの確認画面へ表示する。
3. 管理者が確認・修正する。
4. 正規化済みの本体Originをconfigへ保存する。
5. API判定では保存済みOriginを使用する。

既存環境には管理画面または専用アップグレード手順で追加する必要がある。同一インストールを複数Originで公開する場合は、標準Originを1個に固定し、その他を外部Originとして登録する方法が単純である。

## 8. サブドメイン判定方法

候補Originはscheme・host・portへ分解し、次をすべて確認する。

1. schemeが一致する。
2. portが一致する。
3. hostが完全一致する、または、
4. `include_subdomains = true` かつ候補hostが `"." + 登録host` で終わる。

概念例：

```text
candidateHost === allowedHost
OR
candidateHost ends with "." + allowedHost
```

`example.com` を登録した場合：

| 候補 | 判定 |
| --- | --- |
| `example.com` | 許可 |
| `www.example.com` | 許可 |
| `a.b.example.com` | 許可 |
| `fake-example.com` | 拒否 |
| `example.com.example.net` | 拒否 |
| `example.net` | 拒否 |

単純な部分一致や `endsWith("example.com")` は使用しない。

schemeは完全一致させる。非標準portも別Originとして扱い、サブドメイン許可時も同じportだけを許可するのが安全である。

正規化項目：

- schemeを小文字化
- DNS hostを小文字化
- 末尾ドットの統一
- IDNをpunycodeへ統一
- IPv6をURLパーサーで処理
- パス、query、fragment、userinfoを登録Originとして拒否

`co.uk` 等のpublic suffixへサブドメイン許可を付けると範囲が広すぎる。厳密に防ぐにはPublic Suffix Listが必要である。IPアドレスと `localhost` ではサブドメイン許可を無効にするのが妥当である。

## 9. URL自動登録との整合性

現行実装は `state.php` と `like.php` の両方でidentifierとURLを登録する。

- `state.php`：ページ表示時に登録する。
- `like.php`：レート制限判定より前に登録するため、Like不成立でも登録され得る。

新仕様では、次の順番へ変更する必要がある。

1. JavaScriptが `data-url`、`link rel="canonical"`、`meta property="og:url"`、`location.href` の順にURLを取得する。
2. queryとfragmentを除去する。
3. `state.php` にはURLを送らない。
4. `like.php` だけにURLを送る。
5. リクエストOriginを許可判定する。
6. Likeを正常に記録する。
7. Like成立後にidentifierとURLを登録する。

登録URLのOriginについても、本体Originまたは許可済み外部Originへ含まれることを確認する。

canonicalが表示ページとは別Originを指す場合がある。例えば表示ページが `www.example.com`、canonicalが `example.com` の場合、両Originが許可済みでなければURL登録できない。

推奨方針：

- リクエストOriginとURL Originの両方を許可対象とする。
- 両者の完全一致までは要求しない。
- URL登録失敗で成立済みLikeを取り消さず、URL登録エラーだけをログへ残す。

## 10. localStorage / client_hash / rate limitへの影響

`like.js` は埋め込みページの実行環境で動くため、localStorageはPollyanna本体ではなく設置ページのOriginへ保存される。

使用キー：

```text
pollyanna.client-token.v1
pollyanna.liked-identifiers.v1
```

異なるOriginは別localStorageとなる。

```text
https://eraminan.jp
https://dry.eraminan.jp
https://example.com
```

同じ利用者・ブラウザでも、各Originで別の `client_token` が生成される。サーバーは `client_token` を秘密鍵でHMAC化して `client_hash` を作るため、各Originは別利用者として扱われる。

影響：

- いいね数：同じidentifierなら全Originで共有される。
- 押下済み状態：Originごとに分離される。
- rate limit：Originごとに別枠となる。

例えば上限3回で許可Originが3個なら、同じ利用者が各Originで3回ずつ、合計9回押せる可能性がある。サブドメインもlocalStorageが分離されるため同様である。

初版では「レート制限は匿名ブラウザーかつOrigin単位」と明記する案が現実的である。複数Origin間で同一利用者を安定して識別するには、第三者Cookie制限など別の問題が生じる。

## 11. セキュリティ上の限界

### 防げるもの

- 未許可サイト上の通常JavaScriptからAPIレスポンスを読むこと
- 未許可サイトからpreflightを経たJSON POSTを成立させること
- 管理者が許可していないOriginからの一般的なブラウザー利用
- `Access-Control-Allow-Origin: *` による全面公開

### 防げないもの

- curl、Bot、サーバー間通信によるOrigin偽装
- Referer偽装
- 許可済みサイトのXSSや改ざん
- 許可サブドメインの乗っ取り
- DNS乗っ取り
- localStorageの削除・書き換え
- client tokenの再生成
- 分散アクセス
- 許可サイト管理者による任意identifier・URL送信
- URLが実際の表示ページであることの証明

Origin許可は認証機構ではない。現行の入力検証、rate limit、JSONサイズ制限、URL検証、DBトランザクション、同時実行ロック、メンテナンス状態は引き続き必要である。必要に応じてWebサーバー側のアクセス頻度制限も併用する。

## 12. 実装する場合に変更が必要なファイル

### 実装

- `assets/like.js`
- `api/state.php`
- `api/like.php`
- `src/bootstrap.php`
- 新規Originポリシー共通処理
- `admin/index.php`
- `assets/admin.js`
- `assets/admin.css`
- `lang/admin.ja.json`
- `lang/admin.en.json`
- `install/install.sql`
- `install/index.php`
- `config/config.example.php`
- 既存環境向けマイグレーションSQL
- HTML確認ページ

### 文書

- README日英
- API仕様
- セキュリティ設計
- DB設計
- 管理画面設計
- インストーラー設計

### 共通処理の配置案

保守性を優先する場合は次を推奨する。

```text
src/OriginPolicy.php
api/bootstrap.php
```

`OriginPolicy.php` はOrigin解析、DB照合、サブドメイン判定、URL Origin検証を担当する。`api/bootstrap.php` は共通bootstrap読込、Origin判定、OPTIONS処理、CORSヘッダー設定を担当する。

`src/bootstrap.php` を読み込むだけでCORS処理を自動実行する設計は避ける。管理画面などAPI以外からも読み込まれるためである。

## 13. 推奨実装順序

1. Originなしリクエストの扱いを決定する。
2. 本体Originの保存方法を決定する。
3. scheme・host・portの正規化規則を決定する。
4. DBテーブルと既存環境用マイグレーションを設計する。
5. 共通Originポリシーを実装する。
6. OPTIONS/preflight処理を実装する。
7. 実際のPOSTにも同じOrigin判定を適用する。
8. エラーを含むAPI応答へ必要なCORSヘッダーを適用する。
9. 管理画面の登録・一覧・物理削除を実装する。
10. インストーラーで本体Originを確認・保存する。
11. URL自動取得とquery・fragment除去を実装する。
12. URL登録をLike成立後へ移動する。
13. URL Originの許可判定を実装する。
14. localStorageとrate limitの仕様を確定する。
15. 同一Origin、外部Origin、サブドメイン、拒否ケースを確認する。
16. READMEと仕様書を更新する。

## 14. 未確定事項

1. Originなしを許可するか厳格に拒否するか。
2. `Origin: null` を全面拒否するか。
3. 本体Originをインストーラーで保存するか。
4. 既存インストールへ本体Originをどう追加するか。
5. 同一インストールを複数の本体Originで公開できるようにするか。
6. 非標準portをサブドメインへも引き継ぐか。
7. IDNを許可するか、punycode入力に限定するか。
8. public suffixへのサブドメイン許可をどう防ぐか。
9. IPアドレス・localhostでサブドメイン許可を禁止するか。
10. URL Originが不許可の場合、Like自体も拒否するか。
11. URL登録失敗時に成立済みLikeを維持するか。
12. canonicalが別Originの場合の運用。
13. `meta property="og"` は `og:url` の意味か。
14. rate limitをOrigin単位とするか。
15. 押下状態を複数Originで共有する必要があるか。
16. 外部Origin登録数に上限を設けるか。
17. 登録前にドメイン所有確認を行うか。
18. preflightのキャッシュ時間を設定するか。
19. Origin判定失敗をログへ記録するか。
20. 管理画面で削除確認を表示するか。

## 参考資料

- [WHATWG Fetch Standard](https://fetch.spec.whatwg.org/)
- [MDN: Origin header](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Origin)
- [MDN: Cross-Origin Resource Sharing](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CORS)
- [MDN: Referrer-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referrer-Policy)

## 調査時の変更範囲

この文書を作成するまでの調査では、実装コード、DB、SQL、設定ファイル、既存ドキュメント、テストファイル、バージョン番号、Git commit・tag・pushを変更していない。
