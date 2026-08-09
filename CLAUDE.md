# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## プロジェクト概要

親機・子機の双方を Web ブラウザで動かすポーリング方式の簡易ドアベル。
PHP 8.4 + SQLite(PDO) + バニラ JS のみで、フレームワーク・パッケージマネージャ・ビルド工程は一切ない。
編集したファイルがそのまま動作するため、ビルドコマンドは存在しない。

UI 文言・コード内コメント・コミットメッセージはすべて日本語で書く。

## コマンド

```bash
# テスト（構文チェック + ロジック + HTTP統合）
bash tests/run.sh

# 個別に実行する
php  tests/logic_test.php   # ロジックのみ
bash tests/e2e_test.sh      # HTTP統合のみ（専用サーバーを自動で起動・停止する）

# 開発サーバー（ドキュメントルートは必ず public/）
php -S 127.0.0.1:8791 -t public

# 構文チェックのみ
for f in src/*.php public/*.php config/*.php tests/*.php; do php -l "$f"; done
node --check public/assets/app.js
node --check public/assets/admin.js

# 送信待ちの通知を手で配送する（CLI では自動実行しない）
php -r "require 'src/bootstrap.php'; Doorbell\Webhook::dispatch();"

# DB をリセット（スキーマは初回アクセス時に自動生成される）
rm -f data/doorbell.sqlite data/doorbell.sqlite-shm data/doorbell.sqlite-wal

# ロジックを CLI から直接叩いて確認する
php -r "require 'src/bootstrap.php'; use Doorbell\Doorbell; print_r(Doorbell::issueId('127.0.0.1'));"
```

テストは一時ファイルの SQLite を使うため `data/` の実データを壊さない。
DB の場所は環境変数 `DOORBELL_DB_PATH`、設定ファイルの場所は `DOORBELL_CONFIG_PATH` で
上書きできる（`Config::load()` が解釈する）。これは CLI と組み込みサーバー
（SAPI が `cli` / `cli-server`）でのみ有効。php-fpm 等の本番 SAPI では無視される。
`logic_test.php` は設置環境の `config/config.php` に影響されないよう、一時 config を書いて
`DOORBELL_CONFIG_PATH` で差し替えている（既定値＋外部連携を有効にしたもの）。
HTTP 経由で既定と違う設定を検証したいときは、`e2e_test.sh` の子機URL／外部連携のブロックのように
一時 config を書いて別ポートにサーバーを立てる。

ブラウザで手動確認するときの注意:

- 管理画面 `http://127.0.0.1:8791/admin.php`（既定パスワード `1234`）で ID とパスワードを発行する
- 親機と子機は**同じブラウザプロファイルでは同時にログインできない**（セッション Cookie を共有するため）。
  別ブラウザ／シークレットウィンドウ／chrome-devtools MCP の `isolatedContext` を使う
- テストを追加するときは、`e2e_test.sh` の `api()` のようにリクエストボディをファイル経由で渡す。
  Git Bash から native curl へ非 ASCII の引数を直接渡すと文字化けする

## アーキテクチャ

### 公開領域と非公開領域

`public/` だけがドキュメントルート。`config/` `src/` `data/` `tests/` は公開ディレクトリの外に置き、
それぞれの `.htaccess` でも直接アクセスを拒否している。この境界は崩さないこと。
リポジトリ直下の `.htaccess` は、ドキュメントルートを変更できない環境向けのフォールバック。

### 3 つのエントリポイント

| ファイル | 役割 |
| --- | --- |
| `public/index.php` | メイン画面の HTML シェルのみ。全画面（ログイン／親機／子機）の DOM を最初から出力し、切り替えは JS が行う |
| `public/api.php` | 親機・子機の全操作。`action` で分岐する単一エンドポイント |
| `public/admin.php` | ID 発行管理。ここだけ従来型のサーバーサイドレンダリング + POST（`assets/admin.js` はコピー補助のみ）。**一覧（`admin.php`）と ID 詳細（`admin.php?id=…`）の 2 画面**を 1 ファイルで出し分ける。子機URL・外部連携・削除はすべて詳細画面 |
| `public/integration.php` | 外部連携（Slack 等）向け。Cookie を使わずトークンだけで認証する機械向けの入口 |

`src/bootstrap.php` がオートローダを兼ねる（`Doorbell\` → `src/`）。
`AppError` は `src/Http.php` に同居しているため bootstrap で先に読み込んでいる。

### サーバーは状態を返すだけ

`api.php` のどのアクションも、処理後に**役割に応じた画面状態一式**（`Doorbell::parentState()` /
`childState()`）を返す。クライアントはこの状態を丸ごと受け取って描画するので、
差分プロトコルや個別の取得 API はない。**新しい情報を画面に出したいときは、この 2 つの
state に項目を足すのが唯一の入口。**

state の主な中身:

- 親機: `activeCalls`（応答待ちの呼び出し全件・古い順）、`children`（子機の稼働状態）、`history`
- 子機: `currentCall`、`parentOnline`、`history`

親機の state は `Doorbell::monitorState()`（ID と表示名だけを取る）に切り出してあり、
`integration.php` も同じものを返す。親機向けの項目を足すと連携にも自動的に載る。

### 時間に依存する判定はすべてポーリング時に評価する

cron やバックグラウンドジョブは存在しない。以下はすべて誰かがアクセスした瞬間に計算される。

- 不在判定: `expireStaleCalls()` が `waiting` かつ期限超過の呼び出しを `no_answer` に確定する。
  `parentState()` / `childState()` / `call()` / `respond()` の先頭で必ず呼ぶ
- オンライン判定: `devices.last_seen_at` が `activeWindow()`（= `polling_interval × parent_offline_polls`）
  以内かどうか。ログアウトは `last_seen_at = 0` で枠を即時解放する
- 古いデータの掃除: `bootstrap.php` が約 1/200 の確率で `Doorbell::cleanup()` を実行する
- 外部連携への通知: `Webhook::emit()` は `webhook_events` に積むだけ。実際の送信は
  `bootstrap.php` が登録した shutdown 関数が、**レスポンスを返し切ってから**行う
  （`fastcgi_finish_request()` があれば呼ぶ）。CLI では自動実行しない
- IDの自動失効: `Doorbell::expireOldIds()` を `bootstrap.php` が**毎リクエスト**呼ぶ。
  「もう使えない」ことが動作に直結するので、cleanup のような確率実行にしない。
  `id_lifetime` が 0（既定）なら DB に触らず即座に返る

### 認証と端末の同一性は別物

- **認証**は PHP セッション（`$_SESSION['device_id']` + `$_SESSION['device_session']`）。
  ログイン成功時に `session_regenerate_id()`
- **端末の同一性**は `localStorage` の `device_key`（クライアント由来なので信頼しない）。
  再ログインしても同じ `devices` 行を使い回すことで、子機の履歴が継続し、
  同時接続数のカウントも二重にならない
- **1端末につき有効なセッションは1つ**。ログインのたびに `devices.session_key` を作り直し、
  `Doorbell::device()` がセッション側の値と照合する。`device_key` はクライアントが自由に選べるので、
  これがないと同じ値を送るだけで `max_children_per_id` / `max_parents_per_id` を回避できてしまう
- **子機のログアウトはパスワード必須**（`child_logout_password`、既定 true）。無人の場所に置いた
  子機を勝手にログアウトされないための仕様。`api.php` の `logout` が `Doorbell::verifyPassword()` で
  検証し、クライアントは**成功応答を受け取るまでログイン画面へ戻さない**
  （通信エラーで戻すと、回線を切るだけで確認をすり抜けられてしまう）
- **子機URL**（`child_link_login`、既定 false）は `child_links` のトークンだけでログインする経路。
  トークン＝ログイン情報なので、**設定が無効なら `api.php` も `index.php` もトークンを一切扱わない**。
  ログイン後の処理は通常ログインと同じ `Doorbell::registerDevice()` を通す（上限・セッション鍵の扱いを揃えるため）。
  クライアントはセッション切れ時に `relogin()` で自動的に入り直し（無人端末をログイン画面で止めない）、
  子機画面のログアウトボタン（`#child-logout`）は非表示にする（開き直せば再ログインされるため意味がない）
- **外部連携**（`integration_api`、既定 false）は `integrations` のトークンだけで認証する第三の経路。
  Cookie を読まないので CSRF トークンは要求しない（読まない以上、ブラウザからの偽装リクエストでは認証できない）。
  **連携からの応答で `devices` の行を作らないこと。** 作ると `max_parents_per_id` の枠を消費し、
  子機から見た親機のオンライン判定にも混ざる。応答は `Doorbell::respondBy()`（ID と応答者名だけを取る）を通す

### 呼び出しのライフサイクル

`waiting` → `answered`（親機が応答）または `no_answer`（不在判定）。
応答待ち中の連打は新しい行を作らず `calls.call_count` を加算してまとめる。
履歴表示では `groupHistory()` が**同じ子機の連続した `no_answer`** をさらに 1 件に集約する
（親機の履歴は子機をまたぐため、`device_id` が同じ場合のみまとめる）。

履歴の並び順は `COALESCE(responded_at, last_called_at) DESC`。複数子機が並行して呼び出すと
作成順と決着順がずれ、`id` 順では表示時刻が前後するため。

## 変更時の注意点

### SQLite の型親和性（過去に実バグを踏んでいる）

PDO は既定でパラメータを文字列としてバインドする。比較の**左辺が式**だと親和性が働かず、
SQLite の型順序（INTEGER < TEXT）で比較されて常に真になる。

```sql
-- NG: created_at + 60 <= '1786198802' が常に真になる
WHERE created_at + :timeout <= :now
-- OK: 左辺をカラムにし、しきい値は PHP 側で計算する
WHERE created_at <= :deadline
```

### その他

- `AppError` のエラー種別プロパティは `errorCode`。`code` は `Exception::$code` と衝突して致命的エラーになる
- `public/assets/*` を編集したら `index.php` / `admin.php` の `?v=N` を上げる
- `api.php` は POST + `X-CSRF-Token` ヘッダ必須。CSRF トークンは `index.php` が
  `#bootstrap-data` の JSON で渡す
- 応答メッセージはキー（`in1` / `in5` / `away`）だけを受け取り、文言はサーバーの
  `Doorbell::RESPONSES` を使う。クライアントから任意の文字列を送らせない
- 設定項目を増やすときは `Config::DEFAULTS` と `config/config.sample.php` の両方に追加する。
  クライアントに渡す必要があるものだけ `Config::publicValues()` に載せる（秘匿値を混ぜない）
- 通知先URLは **https と `webhook_allowed_hosts` に限定**し、リダイレクトも追わない。
  管理画面から任意のURLを登録できると、サーバーを踏み台にして内部ネットワークへ
  リクエストを送れてしまう（SSRF）。この制限を緩めないこと
- **削除ロック（`deletion_grace_seconds`）はサーバー側で強制する。** ボタンを
  `disabled` にするだけでは、フォームを直接送れば消せてしまう。判定は
  `Doorbell::guardDeletion()` に集約してあり、`deleteId()` / `deleteChildLink()` /
  `Integration::delete()` の 3 か所から呼ぶ。削除系を増やすときはここも通すこと
- **伏せ字（`mask_secrets`）は表示だけの機能。** モデル側は生の値を返し、
  `admin.php` が `Http::mask()` / `Http::maskUrl()` を通して出す。伏せた値は
  復元できないので、コピーボタンも一緒に隠す
- `examples/` はサンプルコード置き場。テストの対象外で、公開ディレクトリの外に置く
  （`.htaccess` とリポジトリ直下の `.htaccess` の両方で遮断している）
- `config/config.php` は `.gitignore` 済み。既定の管理パスワードは `1234` のまま

### ログイン失敗の応答を変えない

`Doorbell::login()` は、ID が存在しない場合とパスワードが違う場合で
**応答も処理時間も同じ**でなければならない。区別できると 8 桁の ID を総当たりで発見できる。

- エラーは種別・文言とも `invalid_credentials` に統一する。ID 専用のエラーを足さない
- 検証は常に 2 回（親機・子機）行う。ID がなければ `dummyHash()` で代用する
- `password_hash()` は `PASSWORD_DEFAULT` ではなく `BCRYPT_COST` を明示して使う。
  既定コストは PHP のバージョンで変わり（8.4 で 10 → 12）、ダミー側とずれると
  応答時間の差だけで ID の存在が判別できてしまう

### クライアント側で壊しやすい設計判断

- **親機の呼び出しカードは差分更新**する（`renderCallCards()`）。ポーリングのたびに作り直すと、
  応答ボタンを押す瞬間に要素が入れ替わって誤タップになる。新しい呼び出しは常に末尾に追加する
- 呼び出し 1 件と 2 件以上で親機のステージが切り替わる（`showParentStage()`）
- リングトーンは音源ファイルを使わず Web Audio API で合成する。ブラウザの自動再生制限があるため、
  **ユーザー操作（ログインボタン・コールボタン・再接続ボタン）の中で `Ringtone.unlock()` を呼ぶ**
- 接続不能が `offline_stop_seconds` 続いたらポーリングを停止して全画面のオーバーレイを出す。
  古い画面のまま「待ち受け中」に見える状態を作らないための仕様なので、無効化しないこと

## 参照

運用・設置手順・全設定項目の一覧は `README.md` を参照。
