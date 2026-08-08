<?php
/**
 * メイン画面（ログインフォーム / 親機 / 子機）。
 * 画面の切り替えは assets/app.js が行う。
 */

declare(strict_types=1);

namespace Doorbell;

require __DIR__ . '/../src/bootstrap.php';

Http::startSession();

$bootstrapData = [
    'csrfToken'       => Http::csrfToken(),
    'config'          => Config::publicValues(),
    'responses'       => Doorbell::RESPONSES,
    'responseLabels'  => Doorbell::RESPONSE_LABELS,
    'noAnswerMessage' => Doorbell::NO_ANSWER_MESSAGE,
    'loggedIn'        => isset($_SESSION['device_id'], $_SESSION['device_session']),
];

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1f2937">
<meta name="robots" content="noindex, nofollow">
<title>ドアベル</title>
<link rel="stylesheet" href="assets/style.css?v=3">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔔</text></svg>">
</head>
<body>
<div id="app">

  <!-- ログイン画面 -->
  <section class="screen" id="screen-login" hidden>
    <div class="card">
      <h1 class="title">🔔 ドアベル</h1>
      <form id="login-form" autocomplete="off" novalidate>
        <label class="field">
          <span class="field-label">ID</span>
          <input type="text" id="input-id" inputmode="numeric" placeholder="1234-5678"
                 maxlength="16" autocomplete="username" required>
        </label>
        <label class="field">
          <span class="field-label">パスワード</span>
          <input type="password" id="input-password" placeholder="親機／子機のパスワード"
                 maxlength="64" autocomplete="current-password" required>
        </label>
        <label class="field">
          <span class="field-label">表示名</span>
          <input type="text" id="input-name" placeholder="受付 / 玄関 など" maxlength="32" required>
        </label>
        <p class="error" id="login-error" role="alert" hidden></p>
        <button type="submit" class="btn btn-primary" id="login-submit">ログイン</button>
      </form>
      <p class="hint">パスワードによって親機・子機が自動的に判定されます。</p>
    </div>
  </section>

  <!-- 親機画面 -->
  <section class="screen" id="screen-parent" hidden>
    <header class="bar">
      <div class="bar-main">
        <span class="badge badge-parent">親機</span>
        <strong class="bar-name" id="parent-name"></strong>
      </div>
      <button type="button" class="btn btn-quiet" data-action="logout">ログアウト</button>
    </header>

    <p class="notice notice-ok" id="parent-toast" role="status" hidden></p>

    <div class="stage" id="parent-idle">
      <div class="stage-center">
        <div class="pulse" aria-hidden="true">🔔</div>
        <p class="stage-title">待ち受け中</p>
        <p class="stage-sub" id="parent-idle-sub">呼び出しをお待ちしています</p>
      </div>
      <section class="devices">
        <h2 class="devices-title" id="parent-children-title">子機の状態</h2>
        <ul class="devices-list" id="parent-children"></ul>
        <p class="notice notice-warn" id="parent-children-empty" hidden>稼働中の子機がありません</p>
      </section>

      <section class="history">
        <h2 class="history-title">応答履歴</h2>
        <ul class="history-list" id="parent-history"></ul>
        <p class="history-empty" id="parent-history-empty">まだ履歴はありません</p>
      </section>
    </div>

    <!-- 呼び出しが1件のとき -->
    <div class="stage stage-alert" id="parent-calling" hidden>
      <p class="stage-sub">呼び出しがありました</p>
      <p class="caller" id="parent-caller"></p>
      <p class="repeat" id="parent-repeat" hidden></p>
      <p class="countdown">応答待ち <span id="parent-countdown">--</span> 秒</p>
      <div class="responses" id="parent-responses"></div>
    </div>

    <!-- 呼び出しが2件以上のとき -->
    <div class="stage stage-alert" id="parent-multi" hidden>
      <p class="stage-title" id="parent-multi-count"></p>
      <p class="stage-sub">残り時間の短い順に並んでいます</p>
      <div class="call-cards" id="parent-call-cards"></div>
    </div>

    <div class="stage" id="parent-answered" hidden>
      <p class="stage-title">応答を送信しました</p>
      <p class="stage-sub" id="parent-answered-message"></p>
    </div>
  </section>

  <!-- 子機画面 -->
  <section class="screen" id="screen-child" hidden>
    <header class="bar">
      <div class="bar-main">
        <span class="badge badge-child">子機</span>
        <strong class="bar-name" id="child-name"></strong>
      </div>
      <button type="button" class="btn btn-quiet" data-action="logout">ログアウト</button>
    </header>

    <div class="stage" id="child-idle">
      <p class="notice notice-warn" id="child-parent-offline" hidden>親機の電源が入っていないようです</p>
      <button type="button" class="call-button" id="call-button">
        <span class="call-icon" aria-hidden="true">🔔</span>
        <span class="call-text">呼び出す</span>
      </button>
      <section class="history">
        <h2 class="history-title">応答履歴</h2>
        <ul class="history-list" id="child-history"></ul>
        <p class="history-empty" id="child-history-empty">まだ履歴はありません</p>
      </section>
    </div>

    <div class="stage stage-alert" id="child-waiting" hidden>
      <div class="pulse" aria-hidden="true">🔔</div>
      <p class="stage-title">呼び出し中…</p>
      <p class="stage-sub" id="child-waiting-sub">応答をお待ちください</p>
      <p class="countdown">残り <span id="child-countdown">--</span> 秒</p>
    </div>

    <div class="stage" id="child-answered" hidden>
      <p class="stage-sub" id="child-responder"></p>
      <p class="answer-message" id="child-answer-message"></p>
      <p class="stage-sub" id="child-auto-return"></p>
      <button type="button" class="btn btn-primary" id="child-back">TOPに戻る</button>
    </div>
  </section>

  <p class="error global-error" id="global-error" role="alert" hidden></p>

  <!-- 長時間サーバーに接続できずポーリングを停止したときの表示 -->
  <div class="overlay" id="disconnected" role="alertdialog" aria-labelledby="disconnected-title" hidden>
    <div class="overlay-card">
      <p class="overlay-icon" aria-hidden="true">⚠️</p>
      <h2 class="overlay-title" id="disconnected-title">サーバーに接続できません</h2>
      <p class="overlay-text" id="disconnected-detail"></p>
      <p class="overlay-text">通信を停止しました。呼び出しの受信・送信はできません。</p>
      <button type="button" class="btn btn-primary" id="reconnect">再接続する</button>
    </div>
  </div>
</div>

<script id="bootstrap-data" type="application/json"><?= json_encode(
    $bootstrapData,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
) ?></script>
<script src="assets/app.js?v=3"></script>
</body>
</html>
