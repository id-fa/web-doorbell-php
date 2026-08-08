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

// 稀に古いデータを掃除する（cron を用意しなくても運用できるようにするため）
if (random_int(1, 200) === 1) {
    try {
        Doorbell::cleanup();
    } catch (\Throwable $e) {
        error_log('doorbell cleanup failed: ' . $e->getMessage());
    }
}
