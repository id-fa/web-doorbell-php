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

    /**
     * 設置場所のベースURL（末尾スラッシュ付き）。
     *
     * ホスト名はリクエストヘッダ由来で偽装できるため、管理画面での表示以外に使わないこと。
     */
    public static function baseUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        // ホスト名として現れうる文字だけを残す（表示先に別のURLを混ぜ込ませない）
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?? '';

        return (self::isHttps() ? 'https' : 'http') . '://' . ($host === '' ? 'localhost' : $host) . self::basePath();
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

    /**
     * 値の先頭だけを見せて残りを伏せる（`mask_secrets` 用）。
     *
     * どれがどれか見分けられる程度は残す。伏せた部分は復元できないので、
     * 表示ではなく「一覧に秘匿値を並べない」ことが目的。
     */
    public static function mask(string $value, int $keep = 6): string
    {
        return mb_strlen($value) <= $keep ? $value : mb_substr($value, 0, $keep) . '…（以下伏せ字）';
    }

    /** URL のホストまでを見せて、以降のパスを伏せる */
    public static function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            return self::mask($url);
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . '/…（以下伏せ字）';
    }

    /**
     * IPアドレスの後半を伏せる（`mask_client_ip` 用）。
     *
     * 個々の利用者を特定できないようにしつつ、「同じ回線か違う回線か」は
     * 見分けられる程度に前半を残す。
     * IPv4 は上位2オクテット、IPv6 は上位2ブロックまで。
     */
    public static function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $ip);

            return $octets[0] . '.' . $octets[1] . '.x.x';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // 「::」の短縮表記があると文字列を割っても位置が合わないので、
            // 16バイトに展開してから先頭2ブロック（4バイト）を取り出す
            $hex    = bin2hex((string) inet_pton($ip));
            $blocks = array_map(
                static fn (string $block): string => ltrim($block, '0') ?: '0',
                [substr($hex, 0, 4), substr($hex, 4, 4)],
            );

            return implode(':', $blocks) . ':…（以下伏せ字）';
        }

        return self::mask($ip, 3);
    }
}
