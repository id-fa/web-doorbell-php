<?php
/**
 * HTTP まわりの共通処理（セッション、JSON 入出力、IP 取得、レート制限）。
 */

declare(strict_types=1);

namespace Doorbell;

/** クライアントへそのまま返してよいエラー */
final class AppError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'error',
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}

final class Http
{
    /** セッションを開始する（Cookie は HttpOnly / SameSite=Lax） */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = self::isHttps();
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::basePath(),
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
        session_name('doorbell_sid');
        session_start();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        return (string) $_SESSION['csrf_token'];
    }

    /** CSRF トークンを検証する（不一致なら AppError） */
    public static function requireCsrf(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals(self::csrfToken(), $sent)) {
            throw new AppError('セッションの有効期限が切れました。画面を再読み込みしてください。', 'csrf', 419);
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (Config::get('trust_proxy') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return false;
    }

    /** Cookie のスコープに使うベースパス */
    private static function basePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $dir    = rtrim(str_replace('\\', '/', \dirname($script)), '/');
        return $dir === '' ? '/' : $dir . '/';
    }

    /** クライアントIPアドレスを取得する */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        if (Config::get('trust_proxy')) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                // 最も左（オリジナルのクライアント）を採用する
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    /** リクエストボディ（JSON もしくはフォーム）を配列で取得する */
    public static function input(): array
    {
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($type, 'application/json') !== false) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $_POST;
    }

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fail(AppError $e): never
    {
        self::json(['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
    }

    /**
     * 簡易レート制限。時間窓内の上限を超えたら AppError を投げる。
     *
     * @param string $name  制限の種類（requests / login / call など）
     * @param string $key   IPアドレス等の識別子
     */
    public static function rateLimit(string $name, string $key, int $max): void
    {
        $limits = (array) Config::get('rate_limit');
        $window = max(1, (int) ($limits['window'] ?? 60));
        $now    = time();
        $bucket = $name . ':' . $key;
        $pdo    = Database::pdo();

        // 期限切れバケットを掃除しつつ加算する
        $pdo->prepare('DELETE FROM rate_limits WHERE window_start < :limit')
            ->execute([':limit' => $now - $window * 10]);

        $pdo->prepare(<<<'SQL'
            INSERT INTO rate_limits (bucket, hits, window_start) VALUES (:bucket, 1, :now)
            ON CONFLICT (bucket) DO UPDATE SET
                hits         = CASE WHEN rate_limits.window_start < :threshold THEN 1 ELSE rate_limits.hits + 1 END,
                window_start = CASE WHEN rate_limits.window_start < :threshold THEN :now ELSE rate_limits.window_start END
        SQL)->execute([':bucket' => $bucket, ':now' => $now, ':threshold' => $now - $window]);

        $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = :bucket');
        $stmt->execute([':bucket' => $bucket]);
        $hits = (int) ($stmt->fetchColumn() ?: 0);

        if ($hits > $max) {
            throw new AppError(
                'アクセスが集中しています。しばらく待ってからお試しください。',
                'rate_limited',
                429,
            );
        }
    }

    /** レート制限のカウンタを減らす（ログイン成功時など） */
    public static function rateLimitRelease(string $name, string $key): void
    {
        Database::pdo()
            ->prepare('UPDATE rate_limits SET hits = MAX(0, hits - 1) WHERE bucket = :bucket')
            ->execute([':bucket' => $name . ':' . $key]);
    }

    public static function h(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
