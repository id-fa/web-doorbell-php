<?php
/**
 * 外部連携（Slack 等）の発行・認証。
 *
 * 連携は「そのIDの呼び出しを外部から扱うための鍵」で、次の 2 方向を担う。
 *
 * - 送信: 呼び出し・応答・不在確定を webhook_url へ通知する（Webhook が配送する）
 * - 受信: トークンで integration.php を叩き、応答待ちの取得と応答を行う
 *
 * トークンはそれ自体がログイン情報なので、ハッシュにして保存し発行時だけ表示する。
 */

declare(strict_types=1);

namespace Doorbell;

final class Integration
{
    /** 連携トークンの目印（誤って他の値を貼られたときに気づけるようにする） */
    private const TOKEN_PREFIX = 'dbi_';

    /** 同一リクエスト内で何度も引かないためのキャッシュ（ポーリングのたびに評価されるため） */
    private static array $targetCache = [];
    private static array $existsCache = [];

    /** 連携機能そのものが有効か */
    public static function enabled(): bool
    {
        return (bool) Config::get('integration_api');
    }

    /**
     * 連携を新規発行する。トークンと署名シークレットはここでしか取得できない。
     *
     * @return array{id: int, token: string, secret: string, doorbell_id: string, label: string, webhook_url: string}
     */
    public static function create(string $doorbellIdInput, string $label, string $webhookUrl): array
    {
        $doorbellId = Doorbell::normalizeId($doorbellIdInput);
        $label      = self::normalizeLabel($label);
        $url        = self::normalizeWebhookUrl($webhookUrl);

        if ($label === '') {
            throw new AppError('連携の名前を入力してください。', 'invalid_input');
        }

        $stmt = Database::pdo()->prepare('SELECT 1 FROM doorbell_ids WHERE doorbell_id = :id');
        $stmt->execute([':id' => $doorbellId]);
        if ($stmt->fetchColumn() === false) {
            throw new AppError('指定されたIDは見つかりませんでした。', 'unknown_id', 404);
        }

        $token  = self::TOKEN_PREFIX . bin2hex(random_bytes(24));
        $secret = bin2hex(random_bytes(32));

        Database::pdo()->prepare(<<<'SQL'
            INSERT INTO integrations (doorbell_id, label, token_hash, webhook_url, secret, created_at)
            VALUES (:id, :label, :hash, :url, :secret, :now)
        SQL)->execute([
            ':id'     => $doorbellId,
            ':label'  => $label,
            ':hash'   => self::tokenHash($token),
            ':url'    => $url,
            ':secret' => $secret,
            ':now'    => time(),
        ]);

        self::$targetCache = [];
        self::$existsCache = [];

        return [
            'id'          => (int) Database::pdo()->lastInsertId(),
            'token'       => $token,
            'secret'      => $secret,
            'doorbell_id' => $doorbellId,
            'label'       => $label,
            'webhook_url' => $url,
        ];
    }

    /** 発行済みの連携一覧（管理画面用。トークンは復元できないため含まない） */
    public static function listAll(?string $doorbellIdInput = null): array
    {
        $only = $doorbellIdInput === null ? '' : Doorbell::normalizeId($doorbellIdInput);
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT i.*, (SELECT COUNT(*) FROM webhook_events e WHERE e.integration_id = i.id) AS pending
              FROM integrations i
             WHERE :one = '' OR i.doorbell_id = :one
             ORDER BY i.doorbell_id ASC, i.created_at ASC
        SQL);
        $stmt->execute([':one' => $only]);

        return array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'doorbellId' => Doorbell::formatId((string) $row['doorbell_id']),
            'label'      => (string) $row['label'],
            'webhookUrl' => (string) $row['webhook_url'],
            'createdAt'  => date('Y-m-d H:i', (int) $row['created_at']),
            'lastUsedAt' => (int) $row['last_used_at'] > 0 ? date('Y-m-d H:i', (int) $row['last_used_at']) : '—',
            'pending'    => (int) $row['pending'],
            'lockedFor'  => self::lockRemaining((int) $row['created_at']),
        ], $stmt->fetchAll());
    }

    /** 連携を失効させる（送信待ちの通知も一緒に消える） */
    public static function delete(int $id): bool
    {
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare('SELECT created_at FROM integrations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $createdAt = $stmt->fetchColumn();

        if ($createdAt === false) {
            return false;
        }
        Doorbell::guardDeletion((int) $createdAt);

        $delete = $pdo->prepare('DELETE FROM integrations WHERE id = :id');
        $delete->execute([':id' => $id]);
        self::$targetCache = [];
        self::$existsCache = [];

        return $delete->rowCount() > 0;
    }

    /** 失効できるようになるまでの残り秒数（管理画面の表示用）。機能が無効なら null */
    private static function lockRemaining(int $createdAt): ?int
    {
        $grace = Config::int('deletion_grace_seconds');

        return $grace > 0 ? max(0, $createdAt + $grace - time()) : null;
    }

    /**
     * トークンから連携レコードを取得する。
     *
     * 見つからない場合と形式が違う場合を区別しない（有効なトークンを探る手がかりを与えない）。
     */
    public static function authenticate(string $token): ?array
    {
        if (!self::enabled() || preg_match('/\A' . self::TOKEN_PREFIX . '[0-9a-f]{48}\z/', $token) !== 1) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM integrations WHERE token_hash = :hash');
        $stmt->execute([':hash' => self::tokenHash($token)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** 連携が使われたことを記録する（管理画面の「最終利用」表示に使う） */
    public static function touch(int $id): void
    {
        Database::pdo()
            ->prepare('UPDATE integrations SET last_used_at = :now WHERE id = :id')
            ->execute([':now' => time(), ':id' => $id]);
    }

    /**
     * このIDに連携があるか。
     *
     * 子機の「親機の電源が入っていないようです」表示の判定に使う。
     * 連携があれば外部（Slack 等）から応答できるので、親機がポーリングしていなくても
     * 呼び出しは届く。オフライン表示のままにすると実態と食い違ってしまう。
     */
    public static function existsFor(string $doorbellId): bool
    {
        if (!self::enabled()) {
            return false;
        }
        if (isset(self::$existsCache[$doorbellId])) {
            return self::$existsCache[$doorbellId];
        }

        $stmt = Database::pdo()->prepare('SELECT 1 FROM integrations WHERE doorbell_id = :id LIMIT 1');
        $stmt->execute([':id' => $doorbellId]);

        return self::$existsCache[$doorbellId] = $stmt->fetchColumn() !== false;
    }

    /**
     * 通知の送信先がある連携（webhook_url が設定されているもの）。
     *
     * @return list<array{id: int, url: string, secret: string}>
     */
    public static function targets(string $doorbellId): array
    {
        if (!self::enabled()) {
            return [];
        }
        if (isset(self::$targetCache[$doorbellId])) {
            return self::$targetCache[$doorbellId];
        }

        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT id, webhook_url, secret FROM integrations
             WHERE doorbell_id = :id AND webhook_url <> ''
        SQL);
        $stmt->execute([':id' => $doorbellId]);

        return self::$targetCache[$doorbellId] = array_map(static fn (array $row): array => [
            'id'     => (int) $row['id'],
            'url'    => (string) $row['webhook_url'],
            'secret' => (string) $row['secret'],
        ], $stmt->fetchAll());
    }

    /**
     * 通知の送信先URLを検証する。空文字（送信なし）も許可する。
     *
     * 許可リスト方式にしているのは、管理画面から内部ネットワークのURLを登録されると
     * サーバーを踏み台にして外から届かない場所へリクエストを送れてしまうため（SSRF）。
     */
    public static function normalizeWebhookUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new AppError('通知先URLは https:// で始まる必要があります。', 'invalid_webhook');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new AppError('通知先URLにユーザー情報やポート番号は指定できません。', 'invalid_webhook');
        }

        $allowed = (array) Config::get('webhook_allowed_hosts');
        if (!in_array(strtolower((string) $parts['host']), $allowed, true)) {
            throw new AppError(
                '通知先として許可されていないホストです（許可: ' . (implode(', ', $allowed) ?: 'なし') . '）。',
                'invalid_webhook',
            );
        }

        return $url;
    }

    private static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function normalizeLabel(string $label): string
    {
        $label = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $label) ?? '');

        return mb_substr($label, 0, 32);
    }
}
