<?php
/**
 * 全エントリポイント共通の初期化。
 */

declare(strict_types=1);

namespace Doorbell;

// 内部エラーの詳細をブラウザに出さない（ログには残す）
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Doorbell\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Doorbell\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// AppError は Http.php に同居しているため先に読み込んでおく
require_once __DIR__ . '/Http.php';

// 有効期限つきで運用する場合（デモ設置等）、期限切れのIDを毎リクエストで確実に消す。
// 掃除と違って「もう使えない」ことが動作に直結するため、確率的な実行では足りない。
// id_lifetime が 0（既定）のときは何もしない
try {
    Doorbell::expireOldIds();
} catch (\Throwable $e) {
    error_log('doorbell id expiry failed: ' . $e->getMessage());
}

// 稀に古いデータを掃除する（cron を用意しなくても運用できるようにするため）
if (random_int(1, 200) === 1) {
    try {
        Doorbell::cleanup();
    } catch (\Throwable $e) {
        error_log('doorbell cleanup failed: ' . $e->getMessage());
    }
}

/*
 * 外部連携への通知を配送する。
 *
 * cron がないため、送信待ちの通知は「次に誰かがアクセスしたとき」に送る。
 * 送信先が遅いと画面の応答まで遅くなるので、レスポンスを返し切ってから実行する。
 * CLI では自動実行しない（テストやコマンドから明示的に Webhook::dispatch() を呼ぶ）。
 */
if (PHP_SAPI !== 'cli') {
    register_shutdown_function(static function (): void {
        try {
            if (!Webhook::hasPending()) {
                return;
            }
            // php-fpm ではここでレスポンスを閉じ、以降の送信をクライアントに待たせない
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            Webhook::dispatch();
        } catch (\Throwable $e) {
            error_log('doorbell webhook dispatch failed: ' . $e->getMessage());
        }
    });
}
