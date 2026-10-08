# Pollyanna モバイル・WebView Origin/CORS追加調査

調査日：2026-10-06

対象：Pollyanna v0.4.1

関連調査：`OriginCorsResearch.md`

## 1. 結論

Pollyanna v1.0では、公開APIに対して次の**厳格方式**を採用することを推奨する。

```text
Originあり
  Pollyanna本体Origin          → 許可
  許可済み外部Origin           → CORSヘッダーを付けて許可
  その他                       → 拒否

Origin: null                   → 拒否
Originヘッダーなし             → 拒否
```

理由は次のとおりである。

- 現行の `fetch() + POST + application/json` はFetch標準上、同一OriginとクロスOriginのどちらでもOriginヘッダーを送る。
- Chrome・Edge・Firefox・Safari、および通常のHTTP/HTTPSページを表示するChromium WebView・WKWebViewは、このWeb標準を前提にできる。
- クロスOriginの `application/json` POSTではpreflightが発生し、OPTIONSと実POSTの両方でOriginを検証できる。
- Originなしを許可すると、許可リストを経由せずAPIを呼べる経路が残り、管理画面の「外部サイトからの利用許可」という仕様が弱くなる。
- `Origin: null` を許可すると、`file:`、`data:`、sandboxed iframe、ローカルHTML等を同じ一つの値で許可することになり、安全に登録元を限定できない。

この推奨は、Pollyannaの正式な設置対象を**HTTP/HTTPSで公開されたWebページ**と定義することが前提である。アプリがローカルHTML、`file:`、`data:`、独自schemeからPollyannaを利用する構成は、v1.0の正式サポート対象外とするのが妥当である。

通常のSNSアプリ内ブラウザは、リンク先のHTTP/HTTPSページをWebView等で表示する限り、Origin必須化だけを理由に動かなくなるとは考えにくい。ただし、各アプリがWebView設定、コンテンツブロック、通信介入、ストレージ方針を変更できるため、アプリ内ブラウザ全般を一括して正式保証することはできない。主要アプリは実機確認のうえベストエフォートとする。

Origin/CORSは「どのWebページからAPIを呼べるか」を制御する。localStorageと `client_token` は「同じブラウザー環境を継続識別できるか」を支える。両者は別問題であり、Originを厳格化しても、別ブラウザー利用やストレージ削除による押下制限の再取得は防げない。

## 2. 調査対象

### 現行Pollyannaの通信

`assets/like.js` は `state.php` と `like.php` へ、概ね次の通信を行う。

```javascript
fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
});
```

| 項目 | 現行値 |
| --- | --- |
| method | `POST` |
| Content-Type | `application/json` |
| credentials | 未指定。既定値は `same-origin` |
| mode | 未指定。既定値は `cors` |
| 独自header | なし |
| 公開APIのCookie認証 | なし |

localStorageには次のキーを保存する。

```text
pollyanna.client-token.v1
pollyanna.liked-identifiers.v1
```

### 対象環境

- PC：Windows Chrome・Edge・Firefox・Brave、macOS Safari・Chrome・Firefox
- Android：Chrome・Firefox・Brave、Android System WebView、WebView系アプリ内ブラウザ
- iOS / iPadOS：Safari・Chrome・Edge・Brave、WKWebView、WKWebView系アプリ内ブラウザ

### エンジンの整理

| 環境 | 主なエンジン | 注意点 |
| --- | --- | --- |
| Windows Chrome・Edge・Brave | Chromium / Blink | 同系統だが、プライバシー設定や拡張機能は異なる |
| Windows Firefox | Gecko | Fetch/CORSは同じWeb標準に従う |
| macOS Safari | WebKit | WebKit固有のストレージ保護がある |
| macOS Chrome・Brave | Chromium / Blink | macOS上でもChromium系 |
| macOS Firefox | Gecko | Firefox系 |
| Android Chrome・Brave・多くのWebView | Chromium系 | Android System WebViewはアプリ側設定の影響を受ける |
| Android Firefox | GeckoView / Gecko系 | Android System WebViewとは別系統 |
| iOS / iPadOS Safari・一般的なWKWebView | WebKit | アプリごとのデータストア設定が可能 |
| iOS / iPadOSの他社ブラウザ | 多くはWebKit系 | EU・日本等では認可された代替エンジンが利用可能になり、名称だけでエンジンを断定できない |

## 3. Originヘッダーの挙動

### Web標準

Fetch標準では、Originヘッダーは次のHTTP fetchで使われる。

- CORS response taintingとなるリクエスト
- methodがGET・HEAD以外のリクエスト

現行Pollyannaのfetchはmode未指定のため既定値が `cors` であり、methodはPOSTである。このため、通常のHTTP/HTTPS文書から行う同一Origin POSTとクロスOrigin POSTの双方で、文書のOriginが送信される。

```http
Origin: https://example.com
```

`Origin` はスクリプトから任意設定できる一般ヘッダーではなく、ブラウザーが生成する。Referrer-Policyが `no-referrer` でも、現行と同じ既定のcors modeによるfetch POSTでは実Originが送られる。

### 通常ケース

| 通信 | Origin | 備考 |
| --- | --- | --- |
| HTTP/HTTPSページから同一OriginへPOST | 送信される | 現行Pollyannaの標準構成 |
| HTTP/HTTPSページから別OriginへPOST | 送信される | CORS判定対象 |
| 外部OriginからJSON POST | preflightにも実POSTにも送信される | 許可時のみ実POSTへ進む |

### 特殊ケース

Origin値が `null` になり得る代表例は次のとおり。

- `file:` や `data:` 等、通常のHTTP/HTTPSとは異なる不透明なOrigin
- `allow-same-origin` のないsandboxed iframe
- `Content-Security-Policy: sandbox` で同一Origin能力を外した文書
- 作成方法や読み込み方により不透明なOriginとなる文書
- Android WebViewで有効なHTTP/HTTPS base URLを与えず、文字列やローカルコンテンツを読み込んだ場合

これらは、一般利用者がSNSのリンクから普通のHTTPSサイトを開くケースとは異なる。

Originヘッダー自体が欠落する可能性は、非ブラウザークライアント、古い・独自実装、壊れたプロキシ、アプリ独自のネットワーク差し替え等に残る。ただし、標準準拠ブラウザーが通常のHTTP/HTTPSページから現行形式のfetch POSTを行う基本ケースではない。

## 4. Android

### Chrome・Brave

Chromium系の通常ブラウザーとしてFetch/CORS仕様に従う。HTTP/HTTPSページからの現行POSTでは、同一Origin・クロスOriginともOriginヘッダーを送ることを前提にできる。クロスOriginのJSON POSTはpreflight対象である。

localStorageは通常利用できるが、次の場合には消失または別領域になる。

- ユーザーがサイトデータを削除した
- プライベートブラウズのセッションが終了した
- ブラウザーをアンインストールした
- OSやブラウザーが保存データを整理した
- 別ブラウザー、別プロファイルを使用した

### Firefox

Android FirefoxはGecko系である。Fetch/CORSとWeb Storageは標準機能として利用できる。Chromium系ブラウザーとはアプリも保存領域も異なるため、同じサイトでも `client_token` は共有されない。

### Android System WebView

HTTPS URLを通常ナビゲーションとして読み込み、JavaScriptとDOM Storageが有効なら、現行Pollyannaが必要とするfetchとlocalStorageを利用できる。

ただし、Android WebViewの `setDomStorageEnabled` はアプリ側が制御し、Android公式APIでは既定値がfalseとされている。アプリが有効にしていなければlocalStorageは利用できない。また、JavaScript自体もアプリ側設定に依存する。

Pollyanna v0.4.1の `getClientToken()` はlocalStorageの読み書きを例外処理で包んでいない。このためlocalStorageへのアクセスが例外になれば、ウィジェット初期化または押下処理が止まる可能性がある。この点はCORSとは別のWebView互換性リスクである。

`loadData()`、不適切な `loadDataWithBaseURL()`、`file:` URL等で表示されたローカルHTMLは `window.origin` が `null` になり得る。この種のWebViewアプリを厳格方式で拒否することは仕様どおりであり、v1.0ではサポート対象外とする。

## 5. iOS / iPadOS

### Safari

HTTP/HTTPSページからのFetch/CORSはWebKitの標準実装として扱える。現行POSTではOriginが送られ、外部OriginへのJSON POSTではpreflightが発生する。

通常モードではlocalStorageを利用できる。一方、WebKitのプライベートブラウズは一時的なセッションであり、Cookieその他の状態は永続化されない。タブやセッションの終了等により `client_token` と押下済み状態が失われ得る。

ユーザーがSafariのWebサイトデータを削除した場合もlocalStorageを継続識別に使えない。OSのプライバシー機能や将来のストレージ方針変更も考慮し、localStorageを永続的な本人識別子として扱ってはならない。

### Chrome・Edge・Brave等

一般的な配布版はWebKit系の実装を利用してきたが、2026年時点ではEUおよび日本などで、認可された代替ブラウザーエンジンを利用できる制度がある。したがって、iOS上のアプリ名だけから常にWebKitと断定する文書は将来性を欠く。

いずれのエンジンでも、正式対応候補はFetch/CORS/Web StorageのWeb標準に適合する通常ブラウザーとする。アプリ間でlocalStorageが共有される前提は置かない。

### WKWebView

WKWebViewは `WKWebsiteDataStore` を持ち、アプリは永続データストアまたは非永続データストアを選択できる。既定構成は永続データストアだが、アプリがprivate相当の非永続ストアを選ぶと、終了後のlocalStorage保持を期待できない。

WKWebViewのデータ領域はホストアプリの構成に依存する。Safari、別ブラウザー、SNSアプリ内ブラウザとの共有は前提にできない。同じアプリでも、異なるデータストアやWebView構成を使えば分離される可能性がある。

## 6. WebView・アプリ内ブラウザ

### 通常のリンク表示

SNSやメッセージアプリが受け取ったHTTPS URLをChromium WebViewまたはWKWebViewで普通に表示する場合、ページはそのHTTPS Originを持つ。ページ内の現行fetch POSTはOriginを送るため、厳格方式と両立する。

### アプリごとの差

ホストアプリは次を変更できる。

- JavaScriptの有効・無効
- DOM Storageの有効・無効
- 永続／非永続データストア
- キャッシュやデータの削除時期
- ナビゲーション許可範囲
- コンテンツブロック
- リクエストの差し替えや独自scheme
- ローカルHTMLへの置換

このため「WKWebViewだから必ず動く」「Android WebViewだから必ずlocalStorageが残る」とは断定できない。

### 主要利用例の扱い

LINE、X、Facebook、Instagram等のアプリ内ブラウザは代表的な実機確認対象にできる。ただし、各社が内部実装や設定を予告なく変更でき、OS・アプリ版・地域でも差が出る。個別アプリを恒久的な正式対応対象に含めるより、次の表現が妥当である。

> HTTP/HTTPSページを標準的なWebViewで表示し、JavaScript、Fetch API、localStorageが利用できるアプリ内ブラウザでは動作を想定します。アプリ固有のWebView設定やプライバシー機能により、状態が保持されない場合があります。

## 7. localStorage

### 基本単位

localStorageはOrigin単位で分離される。さらに、ブラウザーアプリ、プロファイル、WebViewデータストア、プライベートセッション等の境界でも共有されない場合がある。

| ケース | 保存・共有の見込み |
| --- | --- |
| 同じブラウザー、同じ通常プロファイル、同じOrigin | 通常は保持される |
| 同一ブラウザーの別Origin | 共有されない |
| SafariとiOS Chrome | 共有を前提にできない |
| Android ChromeとFirefox | 共有されない |
| 通常ブラウザーとSNSアプリ内ブラウザ | 共有を前提にできない |
| 異なるSNSアプリ | 共有されないと考えるべき |
| 同一アプリの異なるWebViewデータストア | 分離される可能性がある |
| プライベートブラウズ | 一時的。終了後の保持を保証できない |
| ブラウザーデータ削除 | 削除される |
| アプリ削除・再インストール | 削除されると考えるべき |
| OSによる整理 | 永続性を保証できない |

PollyannaはlocalStorageを補助的な匿名ブラウザー識別とUI状態復元に利用している。アカウント認証や不正防止用の永続IDとして扱う設計ではない。

## 8. client_token / client_hash / rate limitへの影響

同じ人物・同じ端末でも、次の環境は異なる `client_token` を生成し得る。

```text
Safari
Chrome
Firefox
SNSアプリAのアプリ内ブラウザ
SNSアプリBのアプリ内ブラウザ
通常モード
プライベートモード
データ削除後の同じブラウザ
```

現行Pollyannaでは、サーバーが `client_token` とアプリ秘密鍵から `client_hash` を作る。そのためtokenが変わればhashも変わり、サーバーからは別クライアントに見える。

| 機能 | 影響 |
| --- | --- |
| いいね数 | 同じidentifierなら全環境の押下を同じ対象へ集計する |
| ローカル押下済み状態 | localStorage領域ごとに分かれる |
| サーバー側押下状態 | `client_hash` が変われば別クライアント扱いになる |
| rate limit | 新しいtokenでは別枠になり得る |
| ページ再読込 | 同じ保存領域が残っていれば復元できる |
| ブラウザー変更・データ削除 | 押下状態と制限枠を引き継げない |

これはOrigin許可では解決しない。Origin/CORSを厳格化しても、許可済みサイトを別ブラウザーで開く行為やlocalStorage削除は防止できない。

## 9. CORS / preflight

`POST` 自体はCORS safelist対象のメソッドだが、`Content-Type: application/json` はCORS safelisted request-headerのContent-Type値ではない。したがって、クロスOrigin通信ではPC・Android・iOS・標準WebViewのいずれでもpreflight対象になる。

推奨フロー：

```text
OPTIONS
  ↓
Originを構文解析・許可リスト照合
  ↓
Access-Control-Request-Method が POST か確認
  ↓
Access-Control-Request-Headers が許可範囲内か確認
  ↓
許可済みなら204と具体的なCORSヘッダー
  ↓
ブラウザーが実POST
  ↓
実POSTでもOriginを再検証
```

推奨レスポンス例：

```http
HTTP/1.1 204 No Content
Access-Control-Allow-Origin: https://example.com
Access-Control-Allow-Methods: POST
Access-Control-Allow-Headers: Content-Type
Vary: Origin
```

現行はcredentials未指定で外部OriginへCookieを送らず、公開APIもCookie認証を使わないため、`Access-Control-Allow-Credentials` は不要である。

OPTIONSの許可だけで終わらせてはならない。preflightキャッシュ中や非ブラウザークライアントから実POSTが直接届くため、実POSTにも同じOrigin判定が必要である。

標準仕様から外れるモバイル固有のpreflight方式は確認できなかった。WebViewのネットワーク処理をアプリが独自に差し替える場合は標準挙動を保証できず、実機・実アプリ確認の範囲となる。

## 10. Originなしの場合

### 推奨

公開APIでは拒否する。

```http
HTTP/1.1 403 Forbidden
```

Originなしを許可すると、次の通信が許可リストを回避できる。

- curl等の非ブラウザークライアント
- Originを付けない独自アプリ
- Originを除去する中継
- Web標準に従わないクライアント

Originは偽装可能なので、拒否してもBot認証にはならない。それでも、管理画面の外部Origin許可設定を通常のブラウザー通信へ一貫して適用できる利点がある。

互換方式が必要なら、公開API全体で黙って許可するのではなく、将来の明示的な上級者設定として分離すべきである。v1.0初期仕様には含めないことを推奨する。

## 11. Origin:nullの場合

### 推奨

常に拒否し、`Access-Control-Allow-Origin: null` は返さない。

`null` は単一の安全な呼び出し元を表さない。`file:`、`data:`、sandbox等、互いに異なる不透明Originが同じ `null` として直列化される。悪意ある文書もnull Originを作り得るため、許可リストの代わりにならない。

| ケース | v1.0での提案 |
| --- | --- |
| HTTP/HTTPSの通常ページ | 正式対象 |
| SNSアプリ内でHTTPSページを表示 | ベストエフォート対象 |
| `file:` から利用 | サポート対象外 |
| `data:` から利用 | サポート対象外 |
| sandboxでOriginを失った文書 | サポート対象外 |
| WebViewのローカルHTML | サポート対象外。HTTPS配信へ変更を案内 |
| アプリ独自scheme | サポート対象外 |

## 12. Referer fallback

Originがない場合にRefererで許可判定する方式は採用しない。

理由：

- Referrer-Policyにより送信を停止できる。
- ブラウザーやプライバシー機能が短縮・削除できる。
- HTTPSからHTTPへの遷移等、送信されない条件がある。
- WebViewやアプリが独自に変更できる。
- 非ブラウザークライアントは偽装できる。
- Originなし・Refererありを許可すると判定規則が複雑になり、誤許可と環境依存不具合が増える。

Refererはログや障害調査の補助情報には利用できる。ただし、許可判定、認証、Originの代替には使わない。

## 13. Pollyanna v1.0への推奨仕様

### 採用案

**A. 厳格方式**を推奨する。

```text
Originなし  → 403
Origin:null → 403
```

### 判断表

| 観点 | 評価 |
| --- | --- |
| セキュリティ | 許可リストを全公開APIへ一貫適用できる |
| PC互換性 | 現行形式の標準ブラウザーfetchでは問題になりにくい |
| Android互換性 | HTTP/HTTPSのChrome・Firefox・Braveでは問題になりにくい |
| iOS / iPadOS互換性 | HTTP/HTTPSの標準ブラウザーでは問題になりにくい |
| WebView互換性 | 通常のHTTP/HTTPS表示は対象。ローカルHTMLは対象外になる |
| アプリ内ブラウザ互換性 | 標準的なWebViewでは動作想定。アプリ固有差は実機確認が必要 |
| 汎用Likeシステム | 許可Originの管理意図が利用者に説明しやすい |
| 実装複雑さ | Originなしfallbackがなく、判定規則が単純 |

### 仕様へ明記する事項

1. 設置ページはHTTPまたはHTTPSで公開する。
2. `file:`、`data:`、独自scheme、null Originは対応しない。
3. 外部Originではpreflightが必要である。
4. アプリ内ブラウザではJavaScript・Fetch・localStorageが有効である必要がある。
5. localStorageが消えると、押下済み状態と匿名クライアント識別を引き継げない。
6. Origin許可はブラウザー向けの利用元制御であり、Bot認証ではない。

## 14. 対応ブラウザ方針

| 区分 | 提案 | 範囲 |
| --- | --- | --- |
| PC | 正式対応 | サポート期間内のChrome、Edge、Firefox、Safari。BraveはChromium互換として動作確認対象 |
| Android | 正式対応 | サポート期間内のChrome、Firefox。Braveは動作確認対象 |
| iOS / iPadOS | 正式対応 | サポート期間内のSafari。Chrome、Edge、Braveは動作確認対象 |
| Android System WebView | ベストエフォート | JavaScriptとDOM Storageが有効で、HTTP/HTTPSページを表示する構成 |
| WKWebView | ベストエフォート | JavaScriptと永続または利用可能なWebsite Data Storeを使うHTTP/HTTPS構成 |
| SNS等のアプリ内ブラウザ | ベストエフォート | 主要アプリを実機確認するが、アプリ固有設定と更新は保証しない |
| `file:`・`data:`・独自scheme | サポート対象外 | Originがnullまたは安全に登録できないため |
| JavaScriptまたはlocalStorage無効 | サポート対象外 | 現行ウィジェットの必須機能を満たさないため |

対応ブラウザーの具体的な最低バージョンは、実機・BrowserStack等で確認後に別途決定する。

## 15. 実機確認が必要な項目

### 共通機能チェック

- [ ] 初期状態取得
- [ ] いいね押下
- [ ] 件数更新
- [ ] 同一identifierを持つ複数ボタンの同期
- [ ] ページ再読込
- [ ] 押下状態復元
- [ ] localStorage保持
- [ ] rate limit
- [ ] 同一Origin通信
- [ ] 許可済み外部Origin通信
- [ ] preflightのOPTIONS成功
- [ ] 実POSTにもOriginが付くこと
- [ ] 未許可Origin拒否
- [ ] Originなし拒否
- [ ] `Origin: null` 拒否
- [ ] APIエラー内容を許可済み外部ページから読めること

### PC

- [ ] Windows Chrome
- [ ] Windows Edge
- [ ] Windows Firefox
- [ ] Windows Brave
- [ ] macOS Safari
- [ ] macOS Chrome
- [ ] macOS Firefox

### Android

- [ ] Chrome通常モード
- [ ] Chromeシークレットモード
- [ ] Firefox
- [ ] Brave
- [ ] Android System WebViewでDOM Storage有効
- [ ] Android System WebViewでDOM Storage無効時の失敗表示
- [ ] 代表的SNSアプリ内ブラウザ
- [ ] アプリ終了・再起動後のlocalStorage
- [ ] ブラウザーデータ削除後のtoken再生成

### iOS / iPadOS

- [ ] Safari通常モード
- [ ] Safariプライベートブラウズ
- [ ] Chrome
- [ ] Edge
- [ ] Brave
- [ ] 永続 `WKWebsiteDataStore` のWKWebView
- [ ] 非永続 `WKWebsiteDataStore` のWKWebView
- [ ] 代表的SNSアプリ内ブラウザ
- [ ] アプリ終了・再起動後のlocalStorage
- [ ] Webサイトデータ削除後のtoken再生成

### 観測するHTTP情報

- [ ] OPTIONSのOrigin
- [ ] OPTIONSのAccess-Control-Request-Method
- [ ] OPTIONSのAccess-Control-Request-Headers
- [ ] POSTのOrigin
- [ ] Refererの有無と値。ただし判定には使わない
- [ ] レスポンスのAccess-Control-Allow-Origin
- [ ] レスポンスのVary: Origin

## 16. 未確定事項

1. 正式対応ブラウザーの最低バージョン。
2. 動作確認対象とするSNS・メッセージアプリ名とバージョン。
3. Android WebViewでlocalStorageが利用できない場合、エラー表示を追加するか。
4. localStorageアクセスをtry/catchし、一時的なtokenで機能を縮退させるか。
5. プライベートブラウズを正式対応に含めるか、状態非永続の制限付き対応とするか。
6. Originなし通信を必要とする上級者向けAPIモードを将来用意するか。
7. WebView向けにHTTPSでホストする確認ページを提供するか。
8. アプリ内ブラウザの不具合報告に必要な情報収集方法。
9. `Sec-Fetch-Site` 等のFetch Metadataを補助判定に利用するか。非対応クライアントもあるため必須条件にはしない方がよい。
10. iOS / iPadOSの代替エンジン版を個別の正式対応対象に含めるか。

## 17. 参照した仕様・公式資料

### Web標準・ブラウザー共通

- [WHATWG Fetch Standard — Origin header / CORS protocol](https://fetch.spec.whatwg.org/)
- [MDN — Origin header](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Origin)
- [MDN — Cross-Origin Resource Sharing (CORS)](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CORS)
- [MDN — Access-Control-Allow-Origin](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Access-Control-Allow-Origin)
- [MDN — Referrer-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referrer-Policy)
- [MDN — Web Storage API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Storage_API)

### Android

- [Android Developers — WebSettings.setDomStorageEnabled](https://developer.android.com/reference/android/webkit/WebSettings#setDomStorageEnabled(boolean))
- [Android Developers — WebView](https://developer.android.com/reference/android/webkit/WebView)

### Apple / WebKit

- [Apple Developer — WKWebViewConfiguration.websiteDataStore](https://developer.apple.com/documentation/webkit/wkwebviewconfiguration/websitedatastore)
- [WebKit — Tracking Prevention in WebKit](https://webkit.org/tracking-prevention/)
- [WebKit — App-Bound Domains](https://webkit.org/blog/10882/app-bound-domains/)
- [Apple Developer — Using alternative browser engines in the European Union](https://developer.apple.com/support/alternative-browser-engines/)
- [Apple Developer — 日本での代替ブラウザエンジンの使用](https://developer.apple.com/jp/support/alternative-browser-engines-jp/)
- [Apple Support — Safariの履歴、キャッシュ、Cookieを削除する](https://support.apple.com/105082)

## 情報の確度

| 区分 | 本報告での扱い |
| --- | --- |
| Web標準として保証される挙動 | Fetch標準のOrigin付与、CORS、preflightを根拠とした |
| エンジン・OS公式資料で確認できる挙動 | Android WebViewのDOM Storage設定、WKWebsiteDataStore、WebKitの一時ストレージを根拠とした |
| 主要ブラウザーで一般に期待できる挙動 | HTTP/HTTPSページのFetch/CORSとlocalStorage。正式化前に対象版で確認する |
| アプリ固有で変わる挙動 | WebView設定、アプリ内ブラウザのデータ保持、通信介入。断定せずベストエフォートとした |
| 実機確認が必要な事項 | 個別アプリ、プライベートモード、アプリ終了後の保持、OS・地域別代替エンジン |

## 今回の変更範囲

今回、Pollyannaの実装ファイルには変更を加えていない。PHP、JavaScript、CSS、DB、SQL、installer、admin、config、README、既存設計書、既存テスト、バージョン番号は変更していない。Git commit、tag、pushも行っていない。

新規作成したファイルは、本調査報告書 `OriginCorsMobileResearch.md` のみである。
