# Simple Like-button System

Pollyannaは、単独で動作する軽量ないいねボタンシステムです。このディレクトリを実装作業の対象とします。

## 作業方針

- 親ディレクトリの `Pollyanna_Specification_v1.0` にある現行仕様書に従います。
- 指示された範囲に変更を限定し、既存の設計を尊重します。
- `legacy/like_button_beta_package/` は過去の実装を参照するためのものです。そのまま本番環境に配置しないでください。
- コード、仕様書、過去の資料に食い違いがある場合は、独断で決めずに相違点を報告します。

## 旧ベータ版

旧PHPベータ版は `legacy/` に保存しています。現行仕様との対応と注意点は [docs/LegacyMigration.md](docs/LegacyMigration.md) を参照してください。

## 現在の機能

- 日本語・英語のサイト表示と、クリック成功文言の選択
- 同じブラウザ・同じ作品の押下状態の復元
- 作品ごとに匿名ブラウザあたり直近24時間で1〜5回の制限（初期値3回）
- 公開いいね件数の表示切替・色設定・自動更新間隔（初期値5秒、0秒で更新なし）
- 自動集計開始しきい値（初期値1,000件）の設定
- 日別・月別・年別および時間帯別集計
- タイムゾーン変更時のCSVアーカイブと集計値のリセット

## 必要な環境

- PHP 8.0以降。PDO MySQLドライバーを有効にしてください。
- MySQLまたはMariaDB。`install/install.sql` のテーブルはInnoDBを使用します。
- Apacheで運用する場合、`log/.htaccess` によるアーカイブへの直接アクセス拒否が使えます。Apache以外ではWebサーバー側にも同等の拒否設定を追加してください。
- ウィジェットのJavaScript、CSS、APIは同一サイト（同一オリジン）に配置してください。別オリジンへの埋め込みやCORSには対応していません。

## 設置

1. `config/config.example.php` を `config/config.php` にコピーし、DB接続先・ユーザー名・パスワードを設定します。
2. PHP CLIで `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` を実行し、生成された64文字の16進数を `app_secret` に設定します。秘密鍵は設置ごとに変えてください。
3. `install/install.sql` を対象データベースで実行します。
4. PHP実行ユーザーが `config/` に設定JSONを書き込み、`log/` を作成・書き込みできるようにします。`config/config.php` はWebから直接取得できない場所またはWebサーバー設定で保護してください。
5. ウィジェットを表示するHTMLへ次を追加します。`/Simple_Like_Button/` は設置パスに置き換えてください。

```html
<link rel="stylesheet" href="/Simple_Like_Button/assets/like.css?v=1">
<div data-pollyanna-like data-identifier="article-001"></div>
<script defer src="/Simple_Like_Button/assets/like.js"></script>
```

`data-identifier` は作品ごとに安定した一意の値にしてください。空欄・改行を含む値は使えず、最大50文字です。タイトルやURLではなく、内部IDなどを推奨します。同じ作品の複数ページで同じIDを指定すると、件数と押下制限は共有されます。CSSを更新したときは、URLの `v=1` を変更してブラウザーキャッシュを更新してください。

ブラウザー識別子はLocalStorageに保存され、サーバーには秘密鍵でHMAC化した値が保存されます。生のIPアドレスは保存しません。LocalStorageを消去すると、ブラウザーは新しい匿名利用者として扱われます。

## 言語・文言の編集

サイト側の文言は `lang/widget.ja.json` と `lang/widget.en.json` で編集します。クリック成功文言は `success_messages` の `like01` などのキーを維持して値を変更します。選択肢を追加する場合は、同じ連番キーを日英両方のJSONに追加します。JSONにはコメントを書けません。

## ローカル確認ページ

`tests/local-aggregation-admin.php` はログイン機能のないローカルテスト専用ページです。本番環境へ配置・公開しないでください。XAMPPでは `http://localhost/Simple_Like_Button/tests/local-aggregation-admin.php` から開けます。ページを開くと未集計記録を最大30件、一度だけ集計します。

タイムゾーン変更中はサイトAPIがメンテナンス状態になります。未集計分を含む集計データは日時入りの `log/` フォルダーへCSV保存され、保存完了後に稼働中の集計値がリセットされます。アーカイブは自動削除されません。変更操作を中断した場合は、ローカル確認ページを再読み込みして「タイムゾーン変更を再開」から続けてください。
