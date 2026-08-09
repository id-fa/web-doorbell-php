<?php
/**
 * 外部連携への通知（アウトボックス方式）。
 *
 * cron やバックグラウンドジョブは用意しないという方針に合わせ、送信は 2 段構えにする。
 *
 * 1. 呼び出し・応答・不在確定の時点では webhook_events に積むだけ（DB への INSERT のみ）
 * 2. 実際の送信は、次に誰かがアクセスしたリクエストの終了後に行う（bootstrap.php が呼ぶ）
 *
 * 親機・子機が polling_interval ごとにアクセスしてくるため、常駐プロセスがなくても
 * 「数秒以内に必ず配送の機会がある」状態になる。
 */

declare(strict_types=1);

namespace Doorbell;

final class Webhook
{
    /** 1リクエストで配送を試みる最大件数（レスポンス後の処理を長引かせない） */
    private const BATCH = 5;

    /** 再送間隔の上限（秒） */
    private const MAX_BACKOFF = 600;

    /**
     * 通知を送信待ち行列に積む。
     *
     * @param string $event 'call' | 'answered' | 'no_answer'
     * @param array  $call  Doorbell::exportCall() の戻り値
     */
    public static function emit(string $doorbellId, string $event, array $call): void
    {
        $targets = Integration::targets($doorbellId);
        if ($targets === []) {
            return;
        }

        $payload = json_encode([
            // text は人が読むための1行。Slack の Incoming Webhook はこれを本文として表示するので、
            // 中継を挟まなくても通知先URLに直接指定できる（構造化された情報は下の項目を使う）
            'text'       => self::summary($doorbellId, $event, $call),
            'event'      => $event,
            'doorbellId' => Doorbell::formatId($doorbellId),
            'occurredAt' => time(),
            'call'       => $call,
            'responses'  => Doorbell::responseLabels(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        $now  = time();
        $stmt = Database::pdo()->prepare(<<<'SQL'
            INSERT INTO webhook_events (integration_id, event, payload, next_attempt_at, created_at)
            VALUES (:integration, :event, :payload, :now, :now)
        SQL);

        foreach ($targets as $target) {
            $stmt->execute([
                ':integration' => $target['id'],
                ':event'       => $event,
                ':payload'     => $payload,
                ':now'         => $now,
            ]);
        }
    }

    /**
     * 通知を1行の文章にする。
     *
     * 子機名・応答者名は利用者が決める文字列なので、Slack の記法として解釈されないよう
     * `&` `<` `>` をエスケープする（Slack が指定しているエスケープ規則）。
     */
    private static function summary(string $doorbellId, string $event, array $call): string
    {
        $child  = self::escape((string) ($call['childName'] ?? ''));
        $id     = Doorbell::formatId($doorbellId);
        $count  = (int) ($call['callCount'] ?? 1);
        $repeat = $count > 1 ? "（{$count}回）" : '';

        return match ($event) {
            'call'      => "🔔 {$child} から呼び出しがあります{$repeat} [{$id}]",
            'answered'  => sprintf(
                '✅ %s の呼び出しに %s が応答しました：%s [%s]',
                $child,
                self::escape((string) ($call['responder'] ?? '')),
                self::escape((string) ($call['message'] ?? '')),
                $id,
            ),
            'no_answer' => "⚠️ {$child} の呼び出しに応答がないまま終了しました{$repeat} [{$id}]",
            default     => "{$child} [{$id}]",
        };
    }

    private static function escape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    /** 送信待ちの通知があるか（レスポンス後の処理を無駄に走らせないための事前確認） */
    public static function hasPending(): bool
    {
        if (!Integration::enabled()) {
            return false;
        }

        $stmt = Database::pdo()->prepare('SELECT 1 FROM webhook_events WHERE next_attempt_at <= :now LIMIT 1');
        $stmt->execute([':now' => time()]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * 送信待ちの通知を配送する。
     *
     * 同時に複数のリクエストが配送しようとするため、送信の前に次回時刻を進めて予約する。
     * 予約できなかった（＝他のリクエストが先に取った）行は飛ばし、二重送信を避ける。
     */
    public static function dispatch(): void
    {
        if (!Integration::enabled()) {
            return;
        }

        $pdo = Database::pdo();
        $now = time();
        $max = Config::int('webhook_max_attempts');

        // 比較の左辺は必ずカラムにする（SQLite の型親和性。式にすると常に真になる）。
        // LIMIT はパラメータではなく定数を埋め込む（文字列としてバインドされるのを避ける）
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT e.id, e.event, e.payload, e.attempts, i.webhook_url, i.secret
              FROM webhook_events e
              JOIN integrations i ON i.id = e.integration_id
             WHERE e.next_attempt_at <= :now
             ORDER BY e.id ASC
             LIMIT
        SQL . ' ' . self::BATCH);
        $stmt->execute([':now' => $now]);

        $claim  = $pdo->prepare(<<<'SQL'
            UPDATE webhook_events SET attempts = :attempts, next_attempt_at = :retry
             WHERE id = :id AND next_attempt_at <= :now
        SQL);
        $remove = $pdo->prepare('DELETE FROM webhook_events WHERE id = :id');

        foreach ($stmt->fetchAll() as $row) {
            $id       = (int) $row['id'];
            $attempts = (int) $row['attempts'] + 1;

            $claim->execute([
                ':attempts' => $attempts,
                ':retry'    => $now + self::backoff($attempts),
                ':id'       => $id,
                ':now'      => $now,
            ]);
            if ($claim->rowCount() === 0) {
                continue; // 他のリクエストが先に送信を始めている
            }

            $body   = (string) $row['payload'];
            $result = self::post((string) $row['webhook_url'], $body, [
                'Content-Type: application/json; charset=utf-8',
                'X-Doorbell-Event: ' . (string) $row['event'],
                'X-Doorbell-Delivery: ' . $id,
                'X-Doorbell-Signature: sha256=' . hash_hmac('sha256', $body, (string) $row['secret']),
            ]);

            if ($result['ok']) {
                $remove->execute([':id' => $id]);
                continue;
            }

            // 失敗の理由を残す。設置環境の問題（TLS 証明書が検証できない、名前が引けない等）は
            // 「届かない」という結果だけでは切り分けられないため。
            // 毎回出すとログが埋まるので、最初の失敗と諦めたときだけ記録する
            if ($attempts === 1 || $attempts >= $max) {
                error_log(sprintf(
                    'doorbell webhook %s: event=%s delivery=%d attempts=%d/%d %s',
                    $attempts >= $max ? 'giving up' : 'delivery failed',
                    (string) $row['event'],
                    $id,
                    $attempts,
                    $max,
                    $result['detail'],
                ));
            }

            // 何度試しても届かない送信先を溜め込まない
            if ($attempts >= $max) {
                $remove->execute([':id' => $id]);
            }
        }
    }

    /** 失敗するたびに間隔を空ける（10秒 → 20秒 → 40秒 …、上限 MAX_BACKOFF） */
    private static function backoff(int $attempts): int
    {
        return (int) min(self::MAX_BACKOFF, 10 * 2 ** min(8, $attempts - 1));
    }

    /**
     * 実際に POST する。2xx が返れば成功とみなす。
     *
     * リダイレクトは追わない。許可リストで検証した送信先から、別のホストへ
     * 飛ばされてしまうのを防ぐため。
     *
     * @param  list<string> $headers
     * @return array{ok: bool, detail: string} detail は失敗の理由（ログ用）
     */
    private static function post(string $url, string $body, array $headers): array
    {
        $timeout = Config::int('webhook_timeout');

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'detail' => 'curl を初期化できません'];
            }

            $options = [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout,
            ];
            // 古い libcurl では使えないため、あるときだけ指定する
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = 'https';
            }

            curl_setopt_array($ch, $options);
            $sent   = curl_exec($ch) !== false;
            $error  = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if (!$sent) {
                return ['ok' => false, 'detail' => 'curl: ' . $error];
            }

            return self::result($status);
        }

        // curl がない環境向けのフォールバック（allow_url_fopen が必要）
        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'max_redirects' => 0,
        ]]);

        $sent = @file_get_contents($url, false, $context);
        if ($sent === false && !isset($http_response_header)) {
            return ['ok' => false, 'detail' => 'file_get_contents: ' . (error_get_last()['message'] ?? '送信できません')];
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#\AHTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return self::result($status);
    }

    /** HTTP ステータスから配送結果を組み立てる（2xx 以外は失敗） */
    private static function result(int $status): array
    {
        return $status >= 200 && $status < 300
            ? ['ok' => true, 'detail' => '']
            : ['ok' => false, 'detail' => 'HTTP ' . $status];
    }
}
