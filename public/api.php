<?php
/**
 * メイン画面（親機・子機）用の API エンドポイント。
 *
 * すべて POST + JSON。CSRF トークンを X-CSRF-Token ヘッダで送ること。
 */

declare(strict_types=1);

namespace Doorbell;

require __DIR__ . '/../src/bootstrap.php';

Http::startSession();

try {
    $ip     = Http::clientIp();
    $limits = (array) Config::get('rate_limit');

    Http::rateLimit('req', $ip, (int) $limits['max_requests']);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new AppError('POST で呼び出してください。', 'method_not_allowed', 405);
    }

    Http::requireCsrf();

    $input  = Http::input();
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'login':
            $device = Doorbell::login(
                (string) ($input['doorbell_id'] ?? ''),
                (string) ($input['password'] ?? ''),
                (string) ($input['display_name'] ?? ''),
                (string) ($input['device_key'] ?? ''),
                $ip,
            );

            // セッション固定化攻撃を避けるため、ログイン成功時にIDを再生成する
            $csrf = $_SESSION['csrf_token'];
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = $csrf;
            $_SESSION['device_id']  = (int) $device['id'];

            Http::rateLimitRelease('login', $ip);
            Http::json([
                'ok'        => true,
                'deviceKey' => (string) $device['device_key'],
                'state'     => state($device),
            ]);
            // no break（json() は exit する）

        case 'logout':
            if (isset($_SESSION['device_id'])) {
                Doorbell::logout((int) $_SESSION['device_id']);
                unset($_SESSION['device_id']);
            }
            Http::json(['ok' => true]);

        case 'state':
            $device = currentDevice();
            Doorbell::touch((int) $device['id'], $ip);
            Http::json(['ok' => true, 'state' => state($device)]);

        case 'call':
            $device = currentDevice();
            if ((string) $device['role'] !== 'child') {
                throw new AppError('子機のみ呼び出しできます。', 'forbidden', 403);
            }
            Http::rateLimit('call', $ip, (int) $limits['max_calls']);
            Doorbell::touch((int) $device['id'], $ip);
            Doorbell::call($device);
            Http::json(['ok' => true, 'state' => state($device)]);

        case 'respond':
            $device = currentDevice();
            if ((string) $device['role'] !== 'parent') {
                throw new AppError('親機のみ応答できます。', 'forbidden', 403);
            }
            Doorbell::touch((int) $device['id'], $ip);
            Doorbell::respond($device, (int) ($input['call_id'] ?? 0), (string) ($input['response'] ?? ''));
            Http::json(['ok' => true, 'state' => state($device)]);

        default:
            throw new AppError('不明な操作です。', 'unknown_action', 404);
    }
} catch (AppError $e) {
    Http::fail($e);
} catch (\Throwable $e) {
    error_log('doorbell api error: ' . $e);
    Http::json(['ok' => false, 'code' => 'server_error', 'message' => 'サーバーエラーが発生しました。'], 500);
}

/** ログイン済み端末を取得する（未ログインならエラー） */
function currentDevice(): array
{
    $deviceId = (int) ($_SESSION['device_id'] ?? 0);
    $device   = $deviceId > 0 ? Doorbell::device($deviceId) : null;

    if ($device === null) {
        unset($_SESSION['device_id']);
        throw new AppError('ログインしていません。', 'unauthenticated', 401);
    }

    return $device;
}

/** 役割に応じた画面状態を返す */
function state(array $device): array
{
    return (string) $device['role'] === 'parent'
        ? Doorbell::parentState($device)
        : Doorbell::childState($device);
}
