<?php
/**
 * 外部連携（Slack 等）用の API エンドポイント。
 *
 * ブラウザではなくプログラムから叩くための入口。認証は連携トークンだけで行い、
 * Cookie もセッションも使わない（そのため CSRF トークンは要求しない）。
 *
 *   POST /integration.php
 *   Authorization: Bearer dbi_xxxxxxxx...
 *   Content-Type: application/json
 *
 *   {"action": "state"}
 *   {"action": "respond", "call_id": 12, "response": "in1", "responder": "田中"}
 *
 * 応答は api.php と同じく、処理後の画面状態一式を返す。
 */

declare(strict_types=1);

namespace Doorbell;

require __DIR__ . '/../src/bootstrap.php';

try {
    if (!Integration::enabled()) {
        throw new AppError('外部連携APIは無効です。', 'integration_disabled', 403);
    }

    $ip     = Http::clientIp();
    $limits = (array) Config::get('rate_limit');

    Http::rateLimit('req', $ip, (int) $limits['max_requests']);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new AppError('POST で呼び出してください。', 'method_not_allowed', 405);
    }

    $input = Http::input();

    // 総当たりでトークンを探られないように、認証の試行そのものを制限する。
    // 成功したぶんは戻すので、正規の利用が制限に掛かることはない
    Http::rateLimit('integration_auth', $ip, (int) $limits['max_logins']);

    $integration = Integration::authenticate(bearerToken($input));
    if ($integration === null) {
        throw new AppError('連携トークンが正しくありません。', 'invalid_token', 401);
    }

    Http::rateLimitRelease('integration_auth', $ip);
    Integration::touch((int) $integration['id']);

    $doorbellId = (string) $integration['doorbell_id'];
    $label      = (string) $integration['label'];
    $action     = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'state':
            Http::json(['ok' => true, 'state' => state($doorbellId, $label)]);
            // no break（json() は exit する）

        case 'respond':
            // 応答者名は連携側から受け取る（Slack で押した人の名前など）。
            // 空なら連携の名前を使う。応答メッセージ自体はサーバー側の定義しか使わない
            $responder = Doorbell::normalizeDisplayName((string) ($input['responder'] ?? ''));

            Doorbell::respondBy(
                $doorbellId,
                $responder === '' ? $label : $responder,
                (int) ($input['call_id'] ?? 0),
                (string) ($input['response'] ?? ''),
            );
            Http::json(['ok' => true, 'state' => state($doorbellId, $label)]);
            // no break（json() は exit する）

        default:
            throw new AppError('不明な操作です。', 'unknown_action', 404);
    }
} catch (AppError $e) {
    Http::fail($e);
} catch (\Throwable $e) {
    error_log('doorbell integration api error: ' . $e);
    Http::json(['ok' => false, 'code' => 'server_error', 'message' => 'サーバーエラーが発生しました。'], 500);
}

/**
 * 連携トークンを取り出す。
 *
 * Authorization ヘッダを優先するが、共有ホスティングでは PHP まで届かないことがあるため
 * リクエストボディの token も受け付ける。URL のクエリでは受け取らない
 * （アクセスログやリファラにトークンが残ってしまうため）。
 */
function bearerToken(array $input): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/\ABearer\s+(\S+)\z/i', trim($header), $m) === 1) {
        return $m[1];
    }

    return (string) ($input['token'] ?? '');
}

/** 連携に返す状態（親機の画面状態＋応答ボタンの定義） */
function state(string $doorbellId, string $label): array
{
    return [
        ...Doorbell::monitorState($doorbellId, $label, 'integration'),
        'responses' => Doorbell::responseLabels(),
    ];
}
