<?php
/**
 * ドアベルシステムの中核ロジック（ID発行・ログイン・呼び出し・応答）。
 */

declare(strict_types=1);

namespace Doorbell;

use PDO;

final class Doorbell
{
    /** 親機の応答ボタン（キー => 子機へ送るメッセージ） */
    public const RESPONSES = [
        'in1'  => '1分以内に応対します',
        'in5'  => '5分以内に応対します',
        'away' => '担当者が離席中のため応対できません',
    ];

    /** 複数の呼び出しを並べて表示するときに使う短縮ラベル */
    public const RESPONSE_LABELS = [
        'in1'  => '1分以内',
        'in5'  => '5分以内',
        'away' => '離席中',
    ];

    /** 不在判定時に子機へ送るメッセージ */
    public const NO_ANSWER_MESSAGE = '応答がありません。不在のようです。';

    /** 発行するパスワードに使う文字（0/O/1/l/I など紛らわしい文字は除外） */
    private const PASSWORD_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /**
     * bcrypt のコスト。
     *
     * PASSWORD_DEFAULT を使わず明示的に固定する。既定値は PHP のバージョンで変わるため
     * （8.4 で 10 → 12 に変更）、ID が存在しないときのダミー検証とコストがずれ、
     * 応答時間の差から ID の存在を判定できてしまうのを防ぐ。
     */
    private const BCRYPT_COST = 12;

    // ------------------------------------------------------------------
    // ID 発行
    // ------------------------------------------------------------------

    /**
     * 新しいドアベルIDと親機/子機パスワードを発行する。
     *
     * @return array{doorbell_id: string, parent_password: string, child_password: string}
     */
    public static function issueId(string $ip): array
    {
        $pdo = Database::pdo();

        $total = (int) $pdo->query('SELECT COUNT(*) FROM doorbell_ids')->fetchColumn();
        if ($total >= Config::int('max_ids_per_instance')) {
            throw new AppError(
                'このサーバーで発行できるID数の上限（' . Config::int('max_ids_per_instance') . '件）に達しています。',
                'limit_instance',
            );
        }

        $usedByIp = self::idsRelatedToIp($ip);
        if (count($usedByIp) >= Config::int('max_ids_per_ip')) {
            throw new AppError(
                '同一IPアドレスから発行できるID数の上限（' . Config::int('max_ids_per_ip') . '件）に達しています。',
                'limit_ip',
            );
        }

        $parentPassword = self::randomPassword();
        $childPassword  = self::randomPassword();
        $now            = time();

        // ID の重複を避けるため、挿入に失敗したら別の ID で数回リトライする
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $doorbellId = self::randomDoorbellId();
            try {
                $pdo->prepare(<<<'SQL'
                    INSERT INTO doorbell_ids
                        (doorbell_id, parent_password_hash, child_password_hash, created_ip, created_at)
                    VALUES (:id, :parent, :child, :ip, :now)
                SQL)->execute([
                    ':id'     => $doorbellId,
                    ':parent' => self::hashPassword($parentPassword),
                    ':child'  => self::hashPassword($childPassword),
                    ':ip'     => $ip,
                    ':now'    => $now,
                ]);

                return [
                    'doorbell_id'     => $doorbellId,
                    'parent_password' => $parentPassword,
                    'child_password'  => $childPassword,
                ];
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE')) {
                    throw $e;
                }
            }
        }

        throw new AppError('IDの発行に失敗しました。もう一度お試しください。', 'issue_failed', 500);
    }

    /** 発行済みIDの一覧（パスワードは復元できないため含まない） */
    public static function listIds(): array
    {
        return array_map(self::exportId(...), self::idRows());
    }

    /** 1件のIDの概要。存在しなければ null */
    public static function idSummary(string $doorbellIdInput): ?array
    {
        $rows = self::idRows(self::normalizeId($doorbellIdInput));

        return $rows === [] ? null : self::exportId($rows[0]);
    }

    /** @return list<array> 一覧・詳細で共通の集計クエリ */
    private static function idRows(?string $doorbellId = null): array
    {
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT
                i.doorbell_id,
                i.created_at,
                i.created_ip,
                (SELECT COUNT(*) FROM devices d
                  WHERE d.doorbell_id = i.doorbell_id AND d.role = 'parent' AND d.last_seen_at >= :active) AS parents,
                (SELECT COUNT(*) FROM devices d
                  WHERE d.doorbell_id = i.doorbell_id AND d.role = 'child' AND d.last_seen_at >= :active) AS children,
                (SELECT COUNT(*) FROM child_links l WHERE l.doorbell_id = i.doorbell_id) AS links,
                (SELECT COUNT(*) FROM integrations g WHERE g.doorbell_id = i.doorbell_id) AS integrations
            FROM doorbell_ids i
            WHERE :one = '' OR i.doorbell_id = :one
            ORDER BY i.created_at DESC
        SQL);
        $stmt->execute([':active' => time() - self::activeWindow(), ':one' => $doorbellId ?? '']);

        return $stmt->fetchAll();
    }

    private static function exportId(array $row): array
    {
        $createdAt = (int) $row['created_at'];
        $lifetime  = Config::int('id_lifetime');

        return [
            'doorbell_id' => self::formatId((string) $row['doorbell_id']),
            'raw_id'      => (string) $row['doorbell_id'],
            'created_at'  => date('Y-m-d H:i', $createdAt),
            'created_ip'  => (string) $row['created_ip'],
            'parents'     => (int) $row['parents'],
            'children'    => (int) $row['children'],
            'links'       => (int) $row['links'],
            'integrations' => (int) $row['integrations'],
            // 自動失効・削除ロック（どちらも無効なら null）
            'expires_in'  => $lifetime > 0 ? max(0, $createdAt + $lifetime - time()) : null,
            'locked_for'  => self::deletionLockRemaining($createdAt),
        ];
    }

    public static function countIds(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM doorbell_ids')->fetchColumn();
    }

    /** 指定IDを削除する（関連する端末・履歴・子機URL・外部連携も削除される） */
    public static function deleteId(string $doorbellId): bool
    {
        $id   = self::normalizeId($doorbellId);
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare('SELECT created_at FROM doorbell_ids WHERE doorbell_id = :id');
        $stmt->execute([':id' => $id]);
        $createdAt = $stmt->fetchColumn();

        if ($createdAt === false) {
            return false;
        }
        self::guardDeletion((int) $createdAt);

        $delete = $pdo->prepare('DELETE FROM doorbell_ids WHERE doorbell_id = :id');
        $delete->execute([':id' => $id]);

        return $delete->rowCount() > 0;
    }

    /**
     * 発行直後の削除・失効を拒否する。
     *
     * 管理パスワードを共有して設置する場合（デモ等）、発行した本人が使い始める前に
     * 第三者に消されると使えないままになる。`deletion_grace_seconds` が 0 なら何もしない。
     */
    public static function guardDeletion(int $createdAt): void
    {
        $remaining = self::deletionLockRemaining($createdAt);
        if ($remaining === null || $remaining <= 0) {
            return;
        }

        throw new AppError(
            sprintf(
                '発行から%sのあいだは削除・失効できません（あと%s）。',
                self::duration(Config::int('deletion_grace_seconds')),
                self::duration($remaining),
            ),
            'deletion_locked',
            403,
        );
    }

    /** 削除できるようになるまでの残り秒数。機能が無効なら null */
    private static function deletionLockRemaining(int $createdAt): ?int
    {
        $grace = Config::int('deletion_grace_seconds');

        return $grace > 0 ? max(0, $createdAt + $grace - time()) : null;
    }

    /**
     * 発行から `id_lifetime` を過ぎたIDを削除する。
     *
     * cron はないので、bootstrap.php が毎リクエストで呼ぶ（機能が無効なら何もしない）。
     */
    public static function expireOldIds(): void
    {
        $lifetime = Config::int('id_lifetime');
        if ($lifetime <= 0) {
            return;
        }

        // 比較の左辺は必ずカラムにする（SQLite の型親和性）
        Database::pdo()
            ->prepare('DELETE FROM doorbell_ids WHERE created_at <= :deadline')
            ->execute([':deadline' => time() - $lifetime]);
    }

    /** 秒数を「1時間30分」のような日本語表記にする */
    public static function duration(int $seconds): string
    {
        if ($seconds >= 3600) {
            $minutes = intdiv($seconds % 3600, 60);

            return intdiv($seconds, 3600) . '時間' . ($minutes > 0 ? $minutes . '分' : '');
        }
        if ($seconds >= 60) {
            $rest = $seconds % 60;

            return intdiv($seconds, 60) . '分' . ($rest > 0 ? $rest . '秒' : '');
        }

        return $seconds . '秒';
    }

    // ------------------------------------------------------------------
    // ログイン / 端末管理
    // ------------------------------------------------------------------

    /**
     * ID・パスワード・表示名でログインし、端末レコードを作成/更新する。
     *
     * @return array 端末レコード
     */
    public static function login(
        string $doorbellIdInput,
        string $password,
        string $displayName,
        string $deviceKeyInput,
        string $ip,
    ): array {
        $doorbellId  = self::normalizeId($doorbellIdInput);
        $displayName = self::normalizeDisplayName($displayName);
        $deviceKey   = self::normalizeDeviceKey($deviceKeyInput);

        if ($doorbellId === '') {
            throw new AppError('IDを入力してください。', 'invalid_input');
        }
        if ($password === '') {
            throw new AppError('パスワードを入力してください。', 'invalid_input');
        }
        if ($displayName === '') {
            throw new AppError('表示名を入力してください。', 'invalid_input');
        }

        $pdo  = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM doorbell_ids WHERE doorbell_id = :id');
        $stmt->execute([':id' => $doorbellId]);
        $record = $stmt->fetch();

        // ID の存在有無で検証回数が変わらないよう、常に親機・子機の両方を検証する。
        // 該当する ID がなければ同じコストのダミーハッシュで代用し、応答時間を揃える。
        $dummy      = self::dummyHash();
        $parentHash = $record === false ? $dummy : (string) $record['parent_password_hash'];
        $childHash  = $record === false ? $dummy : (string) $record['child_password_hash'];

        $isParent = password_verify($password, $parentHash);
        $isChild  = password_verify($password, $childHash);

        // ID の誤りとパスワードの誤りを区別すると、有効な ID を総当たりで発見できてしまう
        $role = match (true) {
            $record !== false && $isParent => 'parent',
            $record !== false && $isChild  => 'child',
            default => throw new AppError('IDまたはパスワードが正しくありません。', 'invalid_credentials'),
        };

        return self::registerDevice($doorbellId, $role, $displayName, $deviceKey, $ip);
    }

    /**
     * 端末レコードを作成/更新してセッション鍵を発行する（ログイン後の共通処理）。
     *
     * @return array 端末レコード
     */
    private static function registerDevice(
        string $doorbellId,
        string $role,
        string $displayName,
        string $deviceKey,
        string $ip,
    ): array {
        $pdo    = Database::pdo();
        $now    = time();
        $active = $now - self::activeWindow();

        // 同時接続数の上限チェック（同じ端末からの再ログインは除外する）
        $countStmt = $pdo->prepare(<<<'SQL'
            SELECT COUNT(*) FROM devices
             WHERE doorbell_id = :id AND role = :role AND last_seen_at >= :active AND device_key <> :key
        SQL);
        $countStmt->execute([':id' => $doorbellId, ':role' => $role, ':active' => $active, ':key' => $deviceKey]);
        $inUse = (int) $countStmt->fetchColumn();
        $max   = $role === 'parent' ? Config::int('max_parents_per_id') : Config::int('max_children_per_id');

        if ($inUse >= $max) {
            $label = $role === 'parent' ? '親機' : '子機';
            throw new AppError(
                "このIDの{$label}は既に上限（{$max}台）まで使用されています。",
                'limit_devices',
                409,
            );
        }

        // 親機は IP あたりのID数でも制限する
        if ($role === 'parent') {
            $related = self::idsRelatedToIp($ip);
            if (!in_array($doorbellId, $related, true) && count($related) >= Config::int('max_ids_per_ip')) {
                throw new AppError(
                    '同一IPアドレスから利用できるID数の上限（' . Config::int('max_ids_per_ip') . '件）に達しています。',
                    'limit_ip',
                    409,
                );
            }
        }

        // 端末ごとに1つだけ有効なセッション鍵を発行する。
        // device_key はクライアント由来なので、同じ値を送れば同時接続数の上限を回避できてしまう。
        // ログインのたびに鍵を作り直し、同じ端末の古いセッションを無効化することで上限を実効化する。
        $sessionKey = bin2hex(random_bytes(16));

        $pdo->prepare(<<<'SQL'
            INSERT INTO devices (doorbell_id, device_key, role, display_name, ip, session_key, created_at, last_seen_at)
            VALUES (:id, :key, :role, :name, :ip, :session, :now, :now)
            ON CONFLICT (doorbell_id, device_key) DO UPDATE SET
                role         = :role,
                display_name = :name,
                ip           = :ip,
                session_key  = :session,
                last_seen_at = :now
        SQL)->execute([
            ':id'      => $doorbellId,
            ':key'     => $deviceKey,
            ':role'    => $role,
            ':name'    => $displayName,
            ':ip'      => $ip,
            ':session' => $sessionKey,
            ':now'     => $now,
        ]);

        $deviceStmt = $pdo->prepare('SELECT * FROM devices WHERE doorbell_id = :id AND device_key = :key');
        $deviceStmt->execute([':id' => $doorbellId, ':key' => $deviceKey]);

        /** @var array $device */
        $device = $deviceStmt->fetch();
        $device['device_key'] = $deviceKey;

        return $device;
    }

    // ------------------------------------------------------------------
    // 子機の自動ログイン用URL
    // ------------------------------------------------------------------

    /**
     * 子機URLでログインする（ID・パスワードの入力なし）。
     *
     * トークンを知っていれば誰でも子機になれる。設定 `child_link_login` で有効にした場合のみ使う。
     *
     * @return array 端末レコード
     */
    public static function loginByLink(string $token, string $deviceKeyInput, string $ip): array
    {
        $link = self::childLink($token);
        if ($link === null) {
            throw new AppError('この子機URLは使用できません。', 'invalid_link', 403);
        }

        $device = self::registerDevice(
            (string) $link['doorbell_id'],
            'child',
            (string) $link['display_name'],
            self::normalizeDeviceKey($deviceKeyInput),
            $ip,
        );

        Database::pdo()
            ->prepare('UPDATE child_links SET last_used_at = :now WHERE token = :token')
            ->execute([':now' => time(), ':token' => (string) $link['token']]);

        return $device;
    }

    /** 子機URLを発行する（表示名はリンクに固定される） */
    public static function createChildLink(string $doorbellIdInput, string $displayName): array
    {
        $doorbellId  = self::normalizeId($doorbellIdInput);
        $displayName = self::normalizeDisplayName($displayName);

        if ($displayName === '') {
            throw new AppError('子機の表示名を入力してください。', 'invalid_input');
        }

        $stmt = Database::pdo()->prepare('SELECT 1 FROM doorbell_ids WHERE doorbell_id = :id');
        $stmt->execute([':id' => $doorbellId]);
        if ($stmt->fetchColumn() === false) {
            throw new AppError('指定されたIDは見つかりませんでした。', 'unknown_id', 404);
        }

        $token = bin2hex(random_bytes(16));
        Database::pdo()->prepare(<<<'SQL'
            INSERT INTO child_links (token, doorbell_id, display_name, created_at)
            VALUES (:token, :id, :name, :now)
        SQL)->execute([
            ':token' => $token,
            ':id'    => $doorbellId,
            ':name'  => $displayName,
            ':now'   => time(),
        ]);

        return ['token' => $token, 'doorbell_id' => $doorbellId, 'display_name' => $displayName];
    }

    /** 発行済みの子機URL一覧（管理画面用）。IDを渡すとそのIDのぶんだけ返す */
    public static function listChildLinks(?string $doorbellIdInput = null): array
    {
        $only = $doorbellIdInput === null ? '' : self::normalizeId($doorbellIdInput);
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT * FROM child_links
             WHERE :one = '' OR doorbell_id = :one
             ORDER BY doorbell_id ASC, created_at ASC
        SQL);
        $stmt->execute([':one' => $only]);

        return array_map(static fn (array $row): array => [
            'token'       => (string) $row['token'],
            'doorbellId'  => self::formatId((string) $row['doorbell_id']),
            'displayName' => (string) $row['display_name'],
            'createdAt'   => date('Y-m-d H:i', (int) $row['created_at']),
            'lastUsedAt'  => (int) $row['last_used_at'] > 0 ? date('Y-m-d H:i', (int) $row['last_used_at']) : '—',
            'lockedFor'   => self::deletionLockRemaining((int) $row['created_at']),
        ], $stmt->fetchAll());
    }

    /** 子機URLを失効させる */
    public static function deleteChildLink(string $token): bool
    {
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare('SELECT created_at FROM child_links WHERE token = :token');
        $stmt->execute([':token' => self::normalizeToken($token)]);
        $createdAt = $stmt->fetchColumn();

        if ($createdAt === false) {
            return false;
        }
        self::guardDeletion((int) $createdAt);

        $delete = $pdo->prepare('DELETE FROM child_links WHERE token = :token');
        $delete->execute([':token' => self::normalizeToken($token)]);

        return $delete->rowCount() > 0;
    }

    /** トークンから子機URLのレコードを取得する */
    private static function childLink(string $token): ?array
    {
        $token = self::normalizeToken($token);
        if ($token === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM child_links WHERE token = :token');
        $stmt->execute([':token' => $token]);
        $link = $stmt->fetch();

        return $link === false ? null : $link;
    }

    /** 子機URLのトークン（16進32桁）。形式が違えば空文字を返す */
    public static function normalizeToken(string $token): string
    {
        return preg_match('/\A[0-9a-f]{32}\z/', $token) === 1 ? $token : '';
    }

    /**
     * セッションに保存された端末IDから端末レコードを取得する。
     *
     * $sessionKey が端末の現在の鍵と一致しない場合は無効とみなす。
     * 同じ device_key で後からログインされたセッション（＝同じ端末の別ウィンドウ）を締め出し、
     * 1端末が同時接続数の枠を1つしか使えないようにするため。
     */
    public static function device(int $deviceId, string $sessionKey): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM devices WHERE id = :id');
        $stmt->execute([':id' => $deviceId]);
        $device = $stmt->fetch();

        if ($device === false) {
            return null;
        }
        if ((int) $device['last_seen_at'] < time() - Config::int('session_lifetime')) {
            return null;
        }
        if ($sessionKey === '' || !hash_equals((string) $device['session_key'], $sessionKey)) {
            return null;
        }

        return $device;
    }

    /** ポーリングを受けたことを記録する（親機の稼働判定に使う） */
    public static function touch(int $deviceId, string $ip): void
    {
        Database::pdo()
            ->prepare('UPDATE devices SET last_seen_at = :now, ip = :ip WHERE id = :id')
            ->execute([':now' => time(), ':ip' => $ip, ':id' => $deviceId]);
    }

    /**
     * ログアウトにパスワードの再入力が必要か。
     *
     * 子機は無人の場所に置きっぱなしにするため、通りすがりに触られてログアウトされると
     * 呼び出せない状態のまま気づけない。親機は人がいる場所で使うので対象外。
     */
    public static function logoutNeedsPassword(array $device): bool
    {
        return (string) $device['role'] === 'child' && (bool) Config::get('child_logout_password');
    }

    /** 端末の役割に対応するパスワード（親機用／子機用）を検証する */
    public static function verifyPassword(array $device, string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT parent_password_hash, child_password_hash FROM doorbell_ids WHERE doorbell_id = :id
        SQL);
        $stmt->execute([':id' => (string) $device['doorbell_id']]);
        $record = $stmt->fetch();

        if ($record === false) {
            return false;
        }

        $hash = (string) $device['role'] === 'parent'
            ? (string) $record['parent_password_hash']
            : (string) $record['child_password_hash'];

        return password_verify($password, $hash);
    }

    /** ログアウト（枠をすぐ解放するため last_seen_at を過去にする） */
    public static function logout(int $deviceId): void
    {
        // セッション鍵も破棄し、残っているセッションを再利用できないようにする
        Database::pdo()
            ->prepare("UPDATE devices SET last_seen_at = 0, session_key = '' WHERE id = :id")
            ->execute([':id' => $deviceId]);
    }

    // ------------------------------------------------------------------
    // 呼び出し / 応答
    // ------------------------------------------------------------------

    /**
     * 子機からの呼び出し。応答待ちの呼び出しがあれば回数を加算してまとめる。
     */
    public static function call(array $device): array
    {
        self::expireStaleCalls((string) $device['doorbell_id']);

        $pdo = Database::pdo();
        $now = time();

        $stmt = $pdo->prepare(<<<'SQL'
            SELECT * FROM calls
             WHERE device_id = :device AND status = 'waiting'
             ORDER BY id DESC LIMIT 1
        SQL);
        $stmt->execute([':device' => (int) $device['id']]);
        $pending = $stmt->fetch();

        if ($pending !== false) {
            $pdo->prepare(<<<'SQL'
                UPDATE calls
                   SET call_count = call_count + 1, last_called_at = :now, child_name = :name
                 WHERE id = :id
            SQL)->execute([':now' => $now, ':name' => (string) $device['display_name'], ':id' => (int) $pending['id']]);

            $call = self::callById((int) $pending['id']);
            Webhook::emit((string) $device['doorbell_id'], 'call', $call);

            return $call;
        }

        $pdo->prepare(<<<'SQL'
            INSERT INTO calls (doorbell_id, device_id, child_name, status, call_count, created_at, last_called_at)
            VALUES (:id, :device, :name, 'waiting', 1, :now, :now)
        SQL)->execute([
            ':id'     => (string) $device['doorbell_id'],
            ':device' => (int) $device['id'],
            ':name'   => (string) $device['display_name'],
            ':now'    => $now,
        ]);

        $call = self::callById((int) $pdo->lastInsertId());
        Webhook::emit((string) $device['doorbell_id'], 'call', $call);

        return $call;
    }

    /** 親機からの応答 */
    public static function respond(array $device, int $callId, string $responseKey): array
    {
        return self::respondBy(
            (string) $device['doorbell_id'],
            (string) $device['display_name'],
            $callId,
            $responseKey,
        );
    }

    /**
     * 応答の実体。応答者を端末レコードではなく名前で受け取る。
     *
     * 外部連携（Slack 等）からの応答もここを通す。連携のために devices の行を作ると、
     * 親機の同時接続数の枠を消費し、子機から見た「親機の稼働状態」にも混ざってしまうため。
     */
    public static function respondBy(
        string $doorbellId,
        string $responderName,
        int $callId,
        string $responseKey,
    ): array {
        if (!isset(self::RESPONSES[$responseKey])) {
            throw new AppError('不正な応答です。', 'invalid_response');
        }

        self::expireStaleCalls($doorbellId);

        $now  = time();
        $stmt = Database::pdo()->prepare(<<<'SQL'
            UPDATE calls
               SET status = 'answered',
                   responded_at = :now,
                   responder_name = :name,
                   response_key = :key,
                   response_message = :message
             WHERE id = :id AND doorbell_id = :doorbell AND status = 'waiting'
        SQL);
        $stmt->execute([
            ':now'      => $now,
            ':name'     => $responderName,
            ':key'      => $responseKey,
            ':message'  => self::RESPONSES[$responseKey],
            ':id'       => $callId,
            ':doorbell' => $doorbellId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new AppError('この呼び出しは既に終了しています。', 'call_closed', 409);
        }

        $call = self::callById($callId);
        Webhook::emit($doorbellId, 'answered', $call);

        return $call;
    }

    /** 不在判定秒数を過ぎた応答待ちの呼び出しを「応答なし」に確定する */
    public static function expireStaleCalls(string $doorbellId): void
    {
        $pdo      = Database::pdo();
        $now      = time();
        $deadline = $now - Config::int('absence_timeout');

        // 通知の送信先がある場合だけ、確定する呼び出しを先に控えておく。
        // ポーリングのたびに呼ばれるため、連携がなければ余分なクエリを投げない。
        $expiring = [];
        if (Integration::targets($doorbellId) !== []) {
            $stmt = $pdo->prepare(<<<'SQL'
                SELECT * FROM calls
                 WHERE doorbell_id = :id AND status = 'waiting' AND created_at <= :deadline
            SQL);
            $stmt->execute([':id' => $doorbellId, ':deadline' => $deadline]);
            $expiring = $stmt->fetchAll();
        }

        // 比較の左辺は必ずカラムにする。SQLite では式と文字列パラメータを比較すると
        // 型親和性が働かず、数値とテキストの比較になって常に真になってしまうため。
        $pdo->prepare(<<<'SQL'
            UPDATE calls
               SET status = 'no_answer',
                   responded_at = :now,
                   response_key = 'timeout',
                   response_message = :message
             WHERE doorbell_id = :id
               AND status = 'waiting'
               AND created_at <= :deadline
        SQL)->execute([
            ':now'      => $now,
            ':message'  => self::NO_ANSWER_MESSAGE,
            ':id'       => $doorbellId,
            ':deadline' => $deadline,
        ]);

        foreach ($expiring as $row) {
            Webhook::emit($doorbellId, 'no_answer', self::exportCall([
                ...$row,
                'status'           => 'no_answer',
                'responded_at'     => $now,
                'response_key'     => 'timeout',
                'response_message' => self::NO_ANSWER_MESSAGE,
            ]));
        }
    }

    // ------------------------------------------------------------------
    // 画面状態
    // ------------------------------------------------------------------

    /** 親機画面に表示する状態 */
    public static function parentState(array $device): array
    {
        return self::monitorState(
            (string) $device['doorbell_id'],
            (string) $device['display_name'],
            'parent',
        );
    }

    /**
     * 呼び出しを受ける側（親機・外部連携）に見せる状態。
     *
     * 端末レコードではなく ID と表示名で受け取るのは、連携からも同じ状態を返せるようにするため。
     */
    public static function monitorState(string $doorbellId, string $viewerName, string $role): array
    {
        self::expireStaleCalls($doorbellId);

        // 複数の子機から同時に呼び出される場合があるため、応答待ちを全件返す。
        // 残り時間の少ない順（＝古い順）に並べ、親機側では上から並べて表示する。
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT * FROM calls
             WHERE doorbell_id = :id AND status = 'waiting'
             ORDER BY id ASC
        SQL);
        $stmt->execute([':id' => $doorbellId]);

        return [
            'role'        => $role,
            'displayName' => $viewerName,
            'doorbellId'  => self::formatId($doorbellId),
            'serverTime'  => time(),
            'activeCalls' => array_map(self::exportCall(...), $stmt->fetchAll()),
            'children'    => self::childStatuses($doorbellId),
            'history'     => self::doorbellHistory($doorbellId),
        ];
    }

    /** 子機画面に表示する状態 */
    public static function childState(array $device): array
    {
        $doorbellId = (string) $device['doorbell_id'];
        self::expireStaleCalls($doorbellId);

        $deviceId = (int) $device['id'];
        $pdo      = Database::pdo();

        $stmt = $pdo->prepare('SELECT * FROM calls WHERE device_id = :device ORDER BY id DESC LIMIT 1');
        $stmt->execute([':device' => $deviceId]);
        $latest = $stmt->fetch();

        // 応答待ち、または応答直後（表示期間内）の呼び出しを「現在の呼び出し」として返す
        $current = null;
        if ($latest !== false) {
            $status   = (string) $latest['status'];
            $answered = (int) ($latest['responded_at'] ?? 0);
            $fresh    = $answered > 0 && time() - $answered < Config::int('response_view_timeout');
            if ($status === 'waiting' || $fresh) {
                $current = self::exportCall($latest);
            }
        }

        return [
            'role'         => 'child',
            'displayName'  => (string) $device['display_name'],
            'doorbellId'   => self::formatId($doorbellId),
            'serverTime'   => time(),
            // 連携があるIDは常にオンライン扱いにする。外部（Slack 等）から応答できるので、
            // 親機がポーリングしていなくても呼び出しは届くため
            'parentOnline' => self::countActive($doorbellId, 'parent') > 0
                || Integration::existsFor($doorbellId),
            'currentCall'  => $current,
            'history'      => self::history($deviceId),
        ];
    }

    /** 子機の応答履歴（自分が行った呼び出しのみ） */
    public static function history(int $deviceId): array
    {
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT * FROM calls
             WHERE device_id = :device AND status <> 'waiting'
             ORDER BY COALESCE(responded_at, last_called_at) DESC, id DESC
             LIMIT 200
        SQL);
        $stmt->execute([':device' => $deviceId]);

        return self::groupHistory($stmt->fetchAll());
    }

    /** 親機の応答履歴（そのIDに属する全ての子機の呼び出し） */
    public static function doorbellHistory(string $doorbellId): array
    {
        // 複数の子機が並行して呼び出すため、表示時刻が前後しないよう
        // 「決着した時刻」の新しい順に並べる
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT * FROM calls
             WHERE doorbell_id = :id AND status <> 'waiting'
             ORDER BY COALESCE(responded_at, last_called_at) DESC, id DESC
             LIMIT 200
        SQL);
        $stmt->execute([':id' => $doorbellId]);

        return self::groupHistory($stmt->fetchAll());
    }

    /**
     * 履歴をまとめる。同じ子機から応答がないまま連続して押された分は1件に集約する。
     *
     * @param array $rows 新しい順に並んだ呼び出しレコード
     */
    private static function groupHistory(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $isNoAnswer = (string) $row['status'] === 'no_answer';
            $index      = count($groups) - 1;
            $last       = $index < 0 ? null : $groups[$index];

            // 直前も同じ子機の「応答なし」ならまとめる
            // （新しい順に走査しているため、開始時刻を過去へ伸ばしていく）
            if (
                $isNoAnswer
                && $last !== null
                && $last['status'] === 'no_answer'
                && $last['deviceId'] === (int) $row['device_id']
            ) {
                $groups[$index]['count'] += (int) $row['call_count'];
                $groups[$index]['times'] += 1;
                $groups[$index]['firstAt'] = (int) $row['created_at'];
                continue;
            }

            $groups[] = [
                'deviceId'  => (int) $row['device_id'],
                'childName' => (string) $row['child_name'],
                'status'    => (string) $row['status'],
                'count'     => (int) $row['call_count'],
                'times'     => 1,
                'firstAt'   => (int) $row['created_at'],
                'lastAt'    => (int) ($row['responded_at'] ?? $row['last_called_at']),
                'responder' => $row['responder_name'] !== null ? (string) $row['responder_name'] : null,
                'message'   => $row['response_message'] !== null ? (string) $row['response_message'] : null,
            ];
        }

        return array_slice($groups, 0, Config::int('history_display_limit'));
    }

    // ------------------------------------------------------------------
    // 内部ユーティリティ
    // ------------------------------------------------------------------

    /** 呼び出しレコードをクライアント向けに整形する */
    private static function exportCall(array $row): array
    {
        $createdAt = (int) $row['created_at'];

        return [
            'id'          => (int) $row['id'],
            'childName'   => (string) $row['child_name'],
            'status'      => (string) $row['status'],
            'callCount'   => (int) $row['call_count'],
            'createdAt'   => $createdAt,
            'lastCalledAt' => (int) $row['last_called_at'],
            'expiresAt'   => $createdAt + Config::int('absence_timeout'),
            'respondedAt' => $row['responded_at'] !== null ? (int) $row['responded_at'] : null,
            'responder'   => $row['responder_name'] !== null ? (string) $row['responder_name'] : null,
            'message'     => $row['response_message'] !== null ? (string) $row['response_message'] : null,
        ];
    }

    private static function callById(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM calls WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new AppError('呼び出しが見つかりません。', 'call_missing', 404);
        }

        return self::exportCall($row);
    }

    /**
     * 親機の待ち受け画面に表示する子機の稼働状態。
     *
     * ログアウトした子機（last_seen_at = 0）は一覧から消えるが、通信が途絶えただけの子機は
     * しばらくオフラインとして残す。ネットワーク断に気づけるようにするため。
     */
    public static function childStatuses(string $doorbellId): array
    {
        $now  = time();
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT display_name, last_seen_at FROM devices
             WHERE doorbell_id = :id AND role = 'child' AND last_seen_at >= :since
             ORDER BY id ASC
        SQL);
        $stmt->execute([':id' => $doorbellId, ':since' => $now - Config::int('child_status_window')]);

        $online = $now - self::activeWindow();

        return array_map(static fn (array $row): array => [
            'name'       => (string) $row['display_name'],
            'online'     => (int) $row['last_seen_at'] >= $online,
            'lastSeenAt' => (int) $row['last_seen_at'],
        ], $stmt->fetchAll());
    }

    /** 稼働中とみなす端末の数 */
    private static function countActive(string $doorbellId, string $role): int
    {
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT COUNT(*) FROM devices
             WHERE doorbell_id = :id AND role = :role AND last_seen_at >= :active
        SQL);
        $stmt->execute([':id' => $doorbellId, ':role' => $role, ':active' => time() - self::activeWindow()]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * 指定IPに紐づくIDの一覧。
     * ID発行時に記録したIPと、親機が稼働中のIPの両方で判定する。
     *
     * @return list<string>
     */
    private static function idsRelatedToIp(string $ip): array
    {
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT doorbell_id FROM doorbell_ids WHERE created_ip = :ip
            UNION
            SELECT doorbell_id FROM devices
             WHERE ip = :ip AND role = 'parent' AND last_seen_at >= :active
        SQL);
        $stmt->execute([':ip' => $ip, ':active' => time() - self::activeWindow()]);

        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** 端末が「稼働中」とみなされる時間（秒） */
    public static function activeWindow(): int
    {
        return Config::int('polling_interval') * Config::int('parent_offline_polls');
    }

    /** 古い履歴と長期間アクセスのない端末を削除する */
    public static function cleanup(): void
    {
        $pdo = Database::pdo();

        $pdo->prepare('DELETE FROM calls WHERE created_at < :limit')
            ->execute([':limit' => time() - Config::int('history_retention_days') * 86400]);

        $pdo->prepare('DELETE FROM devices WHERE last_seen_at < :limit')
            ->execute([':limit' => time() - Config::int('session_lifetime')]);

        // 送信を諦めた通知が残ることはないが、連携を消した直後などの取りこぼしに備える
        $pdo->prepare('DELETE FROM webhook_events WHERE created_at < :limit')
            ->execute([':limit' => time() - 86400]);
    }

    /** 入力されたIDを内部表現（数字8桁）に正規化する */
    public static function normalizeId(string $input): string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        return strlen($digits) === 8 ? $digits : '';
    }

    /** 表示用のID（4桁-4桁） */
    public static function formatId(string $doorbellId): string
    {
        return strlen($doorbellId) === 8
            ? substr($doorbellId, 0, 4) . '-' . substr($doorbellId, 4)
            : $doorbellId;
    }

    /** 表示名（親機・子機の名前、外部連携から送られる応答者名）を整える */
    public static function normalizeDisplayName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');

        return mb_substr($name, 0, 32);
    }

    /** ブラウザが保持する端末キー。不正な値ならサーバー側で新規発行する */
    private static function normalizeDeviceKey(string $key): string
    {
        return preg_match('/\A[0-9a-f]{16,64}\z/', $key) === 1 ? $key : bin2hex(random_bytes(16));
    }

    /** パスワードをハッシュ化する（コストは BCRYPT_COST に固定する） */
    private static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    /**
     * ID が存在しないときに検証するダミーハッシュ。
     *
     * 実際に保存されるハッシュと同じコストで組み立てるため、検証にかかる時間が一致する。
     * ソルト部の `.` は bcrypt の文字集合に含まれるので、password_verify は
     * 形式エラーで早期に false を返さず、最後まで計算を行う。
     */
    private static function dummyHash(): string
    {
        return sprintf('$2y$%02d$%s', self::BCRYPT_COST, str_repeat('.', 53));
    }

    private static function randomDoorbellId(): string
    {
        return str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
    }

    private static function randomPassword(int $length = 8): string
    {
        $alphabet = self::PASSWORD_ALPHABET;
        $max      = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
