<?php
/**
 * Slack 連携の中継サンプル（このリポジトリのテスト対象ではありません）。
 *
 * ドアベル本体は「汎用の通知」と「トークン認証の応答API」しか持たない。
 * Slack のボタンで応答するには、その 2 つと Slack のあいだに立つ中継が 1 つ要る。
 * このファイルがその中継の最小実装で、次の 2 つの入口を兼ねている。
 *
 *   ┌─ 子機がコール
 *   │
 *   ├→ [ドアベル] --(1) 通知 POST--> [このファイル] --> Slack にボタン付きメッセージ
 *   │
 *   └← [ドアベル] <--(3) respond--- [このファイル] <-- (2) Slack でボタンが押される
 *
 * ------------------------------------------------------------------
 * 設置手順
 * ------------------------------------------------------------------
 * 1. このファイルを Web から見える場所に置く（ドアベル本体とは別でも同じサーバーでもよい）
 *
 * 2. ドアベルのID発行画面でIDの詳細を開き、「外部連携」を発行する
 *    - 通知先URL: このファイルのURL（例 https://example.com/slack_relay.php）
 *    - 表示された「連携トークン」と「署名シークレット」を控える
 *    ※ 通知先URLのホストは config.php の `webhook_allowed_hosts` に追加しておくこと
 *
 * 3. Slack App を作る（https://api.slack.com/apps → Create New App）
 *    - Incoming Webhooks を有効にして、通知したいチャンネルのURLを取得
 *    - Interactivity & Shortcuts を有効にして、Request URL にこのファイルのURLを設定
 *    - Basic Information の Signing Secret を控える
 *
 * 4. 下の設定を埋める（環境変数か、直接書き換える）
 *
 * ------------------------------------------------------------------
 * 注意
 * ------------------------------------------------------------------
 * - Slack は 3 秒以内に 200 を返すことを求める。ここでは Slack への応答を先に返し、
 *   ドアベルへの respond はその後で行う
 * - 通知は再送されることがある（`X-Doorbell-Delivery` が同じなら同一の通知）。
 *   同じ呼び出しのメッセージを何度も出したくない場合は、この値を記録して弾く
 */

declare(strict_types=1);

// ------------------------------------------------------------------
// 設定
// ------------------------------------------------------------------

/** ドアベルの外部連携API（末尾は integration.php） */
const DOORBELL_ENDPOINT = 'https://example.com/integration.php';

/** ID詳細画面で発行した連携トークン（dbi_ で始まる） */
const DOORBELL_TOKEN = 'dbi_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

/** 同じく署名シークレット。通知が本物かの検証に使う */
const DOORBELL_SECRET = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

/** Slack の Incoming Webhook URL */
const SLACK_WEBHOOK = 'https://hooks.slack.com/services/XXX/YYY/ZZZ';

/** Slack App の Signing Secret。ボタン押下が本物かの検証に使う */
const SLACK_SIGNING_SECRET = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

// ------------------------------------------------------------------
// 入口の振り分け
// ------------------------------------------------------------------

$raw = file_get_contents('php://input') ?: '';

try {
    if (isset($_SERVER['HTTP_X_SLACK_SIGNATURE'])) {
        handleSlackAction($raw);   // (2) Slack でボタンが押された
    } else {
        handleDoorbellEvent($raw); // (1) ドアベルからの通知
    }
} catch (RuntimeException $e) {
    http_response_code(400);
    error_log('slack relay: ' . $e->getMessage());
    echo $e->getMessage();
}

// ------------------------------------------------------------------
// (1) ドアベル → Slack
// ------------------------------------------------------------------

/**
 * ドアベルからの通知を Block Kit のメッセージにして Slack へ送る。
 *
 * 応答待ち（call）のときだけボタンを付ける。応答済み・不在確定は結果の共有なので文章だけでよい。
 */
function handleDoorbellEvent(string $raw): void
{
    verifyDoorbellSignature($raw);

    $event = json_decode($raw, true);
    if (!is_array($event)) {
        throw new RuntimeException('通知の形式が不正です');
    }

    // ドアベル側が用意した1行。そのまま本文に使える
    $text    = (string) ($event['text'] ?? '呼び出しがありました');
    $message = ['text' => $text];

    if (($event['event'] ?? '') === 'call') {
        $callId = (int) ($event['call']['id'] ?? 0);

        // 応答ボタンはサーバーが返す定義から組み立てる（文言を中継側で決めない）
        $buttons = [];
        foreach ((array) ($event['responses'] ?? []) as $key => $label) {
            $buttons[] = [
                'type'      => 'button',
                'text'      => ['type' => 'plain_text', 'text' => (string) $label],
                'action_id' => 'doorbell_' . $key,
                // 押されたときに必要な情報をまとめて持たせる（Slack がそのまま返してくる）
                'value'     => json_encode(['call_id' => $callId, 'response' => (string) $key]),
            ];
        }

        $message['blocks'] = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
            ['type' => 'actions', 'elements' => $buttons],
        ];
    }

    post(SLACK_WEBHOOK, json_encode($message, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);

    // ドアベル側は 2xx で「配送できた」と判断し、送信待ちから消す
    http_response_code(200);
    echo 'ok';
}

/** 通知がドアベル本体から来たものか検証する */
function verifyDoorbellSignature(string $raw): void
{
    $sent     = (string) ($_SERVER['HTTP_X_DOORBELL_SIGNATURE'] ?? '');
    $expected = 'sha256=' . hash_hmac('sha256', $raw, DOORBELL_SECRET);

    if ($sent === '' || !hash_equals($expected, $sent)) {
        throw new RuntimeException('通知の署名が一致しません');
    }
}

// ------------------------------------------------------------------
// (2)(3) Slack → ドアベル
// ------------------------------------------------------------------

/**
 * Slack でボタンが押されたときの処理。
 *
 * Slack は 3 秒以内の応答を求めるので、先にメッセージを差し替えてから
 * ドアベルへ応答を送る。
 */
function handleSlackAction(string $raw): void
{
    verifySlackSignature($raw);

    // Interactivity の本体は application/x-www-form-urlencoded の payload に入っている
    parse_str($raw, $form);
    $payload = json_decode((string) ($form['payload'] ?? ''), true);
    if (!is_array($payload)) {
        throw new RuntimeException('payload を読み取れません');
    }

    $action = $payload['actions'][0] ?? null;
    $value  = json_decode((string) ($action['value'] ?? ''), true);
    if (!is_array($value)) {
        throw new RuntimeException('ボタンの値を読み取れません');
    }

    // 押した人の名前を応答者としてドアベルに残す
    $responder = (string) ($payload['user']['name'] ?? 'Slack');

    $result = post(DOORBELL_ENDPOINT, json_encode([
        'action'    => 'respond',
        'call_id'   => (int) $value['call_id'],
        'response'  => (string) $value['response'],
        'responder' => $responder,
    ], JSON_UNESCAPED_UNICODE), [
        'Content-Type: application/json',
        'Authorization: Bearer ' . DOORBELL_TOKEN,
    ]);

    $answer = json_decode($result, true);
    $done   = is_array($answer) && ($answer['ok'] ?? false) === true;

    // 元のメッセージを結果で置き換える（ボタンを残さない）
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'replace_original' => true,
        'text' => $done
            ? "✅ {$responder} が応答しました"
            : '⚠️ 応答できませんでした：' . (string) ($answer['message'] ?? '不明なエラー'),
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Slack からのリクエストか検証する。
 * https://api.slack.com/authentication/verifying-requests-from-slack
 */
function verifySlackSignature(string $raw): void
{
    $timestamp = (string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '');

    // 古いリクエストの使い回しを防ぐ
    if ($timestamp === '' || abs(time() - (int) $timestamp) > 300) {
        throw new RuntimeException('リクエストが古すぎます');
    }

    $expected = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$raw}", SLACK_SIGNING_SECRET);
    if (!hash_equals($expected, $signature)) {
        throw new RuntimeException('Slack の署名が一致しません');
    }
}

// ------------------------------------------------------------------
// 共通
// ------------------------------------------------------------------

/**
 * JSON を POST して本文を返す。
 *
 * @param list<string> $headers
 */
function post(string $url, string $body, array $headers): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl を初期化できません');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);

    $result = curl_exec($ch);
    if ($result === false) {
        throw new RuntimeException('送信に失敗しました: ' . curl_error($ch));
    }

    return (string) $result;
}
