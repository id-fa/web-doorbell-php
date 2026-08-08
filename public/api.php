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
            // 総当たり（ID の列挙・パスワード試行）と、bcrypt 検証による CPU 消費を抑える。
            // 管理画面とは別のバケットを使う。共有すると、こちらのログイン成功で
            // 管理画面側の試行回数を巻き戻せてしまうため
            Http::rateLimit('login_device', $ip, (int) $limits['max_logins']);

            $device = Doorbell::login(
                (string) ($input['doorbell_id'] ?? ''),
                (string) ($input['password'] ?? ''),
                (string) ($input['display_name'] ?? ''),
                (string) ($input['device_key'] ?? ''),
                $ip,
            );

            establishSession($device);
            Http::rateLimitRelease('login_device', $ip);
            Http::json([
                'ok'        => true,
                'deviceKey' => (string) $device['device_key'],
                'state'     => state($device),
            ]);
            // no break（json() は exit する）

        case 'link_login':
            // 子機URL（トークン）だけでログインする。URLを知っていれば誰でも子機になれるため、
            // 設定で明示的に有効にしたときだけ受け付ける
            if (!Config::get('child_link_login')) {
                throw new AppError('子機URLによるログインは無効です。', 'link_disabled', 403);
            }

            Http::rateLimit('login_link', $ip, (int) $limits['max_logins']);

            $device = Doorbell::loginByLink(
                (string) ($input['token'] ?? ''),
                (string) ($input['device_key'] ?? ''),
                $ip,
            );

            establishSession($device);
            Http::rateLimitRelease('login_link', $ip);
            Http::json([
                'ok'        => true,
                'deviceKey' => (string) $device['device_key'],
                'state'     => state($device),
            ]);
            // no break（json() は exit する）

        case 'logout':
            // セッションが既に無効なら（期限切れ・別ウィンドウでの再ログイン）、
            // パスワードを求めずにログイン画面へ戻す
            $device = Doorbell::device(
                (int) ($_SESSION['device_id'] ?? 0),
                (string) ($_SESSION['device_session'] ?? ''),
            );

            if ($device !== null) {
                // 置きっぱなしの子機を勝手にログアウトされないよう、パスワードを再確認する
                if (Doorbell::logoutNeedsPassword($device)) {
                    // ログイン用のバケットとは分ける。共有すると、こちらの成功で
                    // ログイン側の試行回数を巻き戻せてしまうため
                    Http::rateLimit('logout_device', $ip, (int) $limits['max_logins']);

                    if (!Doorbell::verifyPassword($device, (string) ($input['password'] ?? ''))) {
                        throw new AppError('パスワードが正しくありません。', 'invalid_password', 403);
                    }

                    Http::rateLimitRelease('logout_device', $ip);
                }

                Doorbell::logout((int) $device['id']);
            }

            unset($_SESSION['device_id'], $_SESSION['device_session']);
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

/** ログイン成功時にセッションを張り直す（セッション固定化攻撃を避けるためIDを再生成する） */
function establishSession(array $device): void
{
    $csrf = $_SESSION['csrf_token'];
    session_regenerate_id(true);
    $_SESSION['csrf_token']     = $csrf;
    $_SESSION['device_id']      = (int) $device['id'];
    $_SESSION['device_session'] = (string) $device['session_key'];
}

/** ログイン済み端末を取得する（未ログイン、または同じ端末が別セッションでログインし直したならエラー） */
function currentDevice(): array
{
    $deviceId   = (int) ($_SESSION['device_id'] ?? 0);
    $sessionKey = (string) ($_SESSION['device_session'] ?? '');
    $device     = $deviceId > 0 ? Doorbell::device($deviceId, $sessionKey) : null;

    if ($device === null) {
        unset($_SESSION['device_id'], $_SESSION['device_session']);
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
