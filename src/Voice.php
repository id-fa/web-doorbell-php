<?php
/**
 * 親機と子機のボイスチャット（WebRTC）のシグナリング。テスト版機能。
 *
 * このクラスが担うのは「SDP と ICE を相手に届けること」と「通話の生死を管理すること」だけで、
 * 音声・映像そのものはブラウザ同士が直接やり取りする（サーバーは中継しない）。
 *
 * 呼び出し（calls）のライフサイクルには手を入れない。通話は calls と並走する別の
 * セッションとして持ち、接続が成立した時点で通常の応答と同じ形
 * （status = 'answered' / response_key = 'talk'）に着地させる。
 *
 * 成立するまで呼び出しを 'waiting' のまま残すのが要点。接続に失敗しても呼び出しは
 * 生きているので、親機は通常の応答ボタンに戻って選び直せる。
 * 先に 'answered' にしてしまうと、繋がらなかったときに来訪者が置き去りになる。
 *
 * cron はないので、期限切れの判定はすべて expireSessions() がポーリング時に評価する。
 */

declare(strict_types=1);

namespace Doorbell;

final class Voice
{
    /**
     * SDP / ICE 1件あたりの上限（バイト）。
     *
     * この値は相手のブラウザの setRemoteDescription() / addIceCandidate() に渡る。
     * 通常の SDP は 4KB 程度なので、それを大きく超える入力は受け付けない。
     */
    private const MAX_PAYLOAD_BYTES = 16384;

    /** 通話機能そのものが有効か */
    public static function enabled(): bool
    {
        return (bool) Config::get('voice_call');
    }

    // ------------------------------------------------------------------
    // 通話の開始・進行・終了
    // ------------------------------------------------------------------

    /**
     * 通話を開始する（親機のみ）。offer を子機宛てに積む。
     *
     * @param array $sdp RTCSessionDescription を配列にしたもの
     */
    public static function start(array $device, int $callId, array $sdp): array
    {
        self::guardEnabled();

        $doorbellId = (string) $device['doorbell_id'];
        $parentId   = (int) $device['id'];
        self::expireSessions($doorbellId);

        $pdo  = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM calls WHERE id = :id AND doorbell_id = :doorbell');
        $stmt->execute([':id' => $callId, ':doorbell' => $doorbellId]);
        $call = $stmt->fetch();

        if ($call === false) {
            throw new AppError('呼び出しが見つかりません。', 'call_missing', 404);
        }

        if ((string) $call['status'] !== 'waiting') {
            throw new AppError('この呼び出しは既に終了しています。', 'call_closed', 409);
        }

        // 同じIDで通話を二重に始めさせない。親機・子機とも1台ずつの想定で、
        // 2本目を許すと同じ端末のマイクを取り合うことになる
        $stmt = $pdo->prepare("SELECT 1 FROM voice_sessions WHERE doorbell_id = :id AND status <> 'ended' LIMIT 1");
        $stmt->execute([':id' => $doorbellId]);
        if ($stmt->fetchColumn() !== false) {
            throw new AppError('既に通話中です。', 'voice_busy', 409);
        }

        $offer = self::validateDescription($sdp, 'offer');
        $now   = time();

        $pdo->prepare(<<<'SQL'
            INSERT INTO voice_sessions (
                call_id, doorbell_id, parent_device_id, child_device_id,
                parent_name, child_name, status, created_at, parent_seen_at, child_seen_at
            ) VALUES (
                :call, :doorbell, :parent, :child,
                :parent_name, :child_name, 'offering', :now, :now, :now
            )
        SQL)->execute([
            ':call'        => $callId,
            ':doorbell'    => $doorbellId,
            ':parent'      => $parentId,
            ':child'       => (int) $call['device_id'],
            ':parent_name' => (string) $device['display_name'],
            ':child_name'  => (string) $call['child_name'],
            ':now'         => $now,
        ]);

        $sessionId = (int) $pdo->lastInsertId();
        self::push($sessionId, (int) $call['device_id'], 'offer', $offer);

        return self::exportSession(self::rowById($sessionId), $parentId);
    }

    /**
     * 通話の状態と、自分宛てに届いているシグナルを取り出す。
     *
     * セッションIDを省略すると、自分が当事者になっている進行中の通話を探す。
     * 子機はこれで着信に気づく（親機が始めるまでセッションIDを知らないため）。
     *
     * @return array{session: ?array, signals: list<array{kind: string, payload: array}>}
     */
    public static function poll(array $device, int $sessionId = 0): array
    {
        self::guardEnabled();

        $doorbellId = (string) $device['doorbell_id'];
        $deviceId   = (int) $device['id'];
        self::expireSessions($doorbellId);

        $row = $sessionId > 0
            ? self::sessionFor($sessionId, $device)
            : self::activeRowFor($doorbellId, $deviceId);

        if ($row === null) {
            return ['session' => null, 'signals' => []];
        }

        $pdo = Database::pdo();

        // 相手にこちらが生きていることを伝える。これが途絶えると expireSessions() が切断する
        if ((string) $row['status'] !== 'ended') {
            $column = (int) $row['parent_device_id'] === $deviceId ? 'parent_seen_at' : 'child_seen_at';
            $pdo->prepare("UPDATE voice_sessions SET {$column} = :now WHERE id = :id")
                ->execute([':now' => time(), ':id' => (int) $row['id']]);
        }

        $stmt = $pdo->prepare(<<<'SQL'
            SELECT * FROM voice_signals
             WHERE session_id = :session AND to_device_id = :device
             ORDER BY id ASC
        SQL);
        $stmt->execute([':session' => (int) $row['id'], ':device' => $deviceId]);
        $signals = $stmt->fetchAll();

        // 配送は1回きり。取り出した分だけを消す
        // （この間に届いた新しい分を巻き込まないよう id で区切る）
        if ($signals !== []) {
            $pdo->prepare(<<<'SQL'
                DELETE FROM voice_signals
                 WHERE session_id = :session AND to_device_id = :device AND id <= :last
            SQL)->execute([
                ':session' => (int) $row['id'],
                ':device'  => $deviceId,
                ':last'    => (int) $signals[array_key_last($signals)]['id'],
            ]);
        }

        return [
            'session' => self::exportSession($row, $deviceId),
            'signals' => array_map(static fn (array $signal): array => [
                'kind'    => (string) $signal['kind'],
                'payload' => (array) json_decode((string) $signal['payload'], true),
            ], $signals),
        ];
    }

    /** SDP（answer）や ICE candidate を相手に渡す */
    public static function signal(array $device, int $sessionId, string $kind, array $payload): void
    {
        self::guardEnabled();

        $row      = self::sessionFor($sessionId, $device);
        $deviceId = (int) $device['id'];
        $isParent = (int) $row['parent_device_id'] === $deviceId;

        if ((string) $row['status'] === 'ended') {
            throw new AppError('この通話は終了しています。', 'voice_ended', 409);
        }

        if ($kind === 'answer') {
            // offer を出すのは常に親機なので、answer を返せるのは子機だけ
            if ($isParent) {
                throw new AppError('不正な操作です。', 'forbidden', 403);
            }

            $body = self::validateDescription($payload, 'answer');
        } elseif ($kind === 'ice') {
            $body = self::validatePayload($payload);
        } else {
            throw new AppError('不正な操作です。', 'invalid_input');
        }

        $peerId = $isParent ? (int) $row['child_device_id'] : (int) $row['parent_device_id'];
        self::push($sessionId, $peerId, $kind, $body);

        if ($kind === 'answer' && (string) $row['status'] === 'offering') {
            Database::pdo()
                ->prepare("UPDATE voice_sessions SET status = 'answered' WHERE id = :id AND status = 'offering'")
                ->execute([':id' => $sessionId]);
        }
    }

    /**
     * 接続が成立したことを記録する（親機のみ）。
     *
     * ここで初めて呼び出しを決着させる。ICE が通らずに失敗した場合はここまで来ないので、
     * 呼び出しは応答待ちのまま残り、親機は通常の応答ボタンに戻れる。
     */
    public static function connected(array $device, int $sessionId): array
    {
        self::guardEnabled();

        $row      = self::sessionFor($sessionId, $device);
        $deviceId = (int) $device['id'];

        if ((int) $row['parent_device_id'] !== $deviceId) {
            throw new AppError('不正な操作です。', 'forbidden', 403);
        }

        if ((string) $row['status'] === 'ended') {
            throw new AppError('この通話は終了しています。', 'voice_ended', 409);
        }

        // 接続が一度切れて繋ぎ直した場合など、二度呼ばれても害がないようにする
        if ((string) $row['status'] === 'connected') {
            return self::exportSession($row, $deviceId);
        }

        Database::pdo()->prepare(<<<'SQL'
            UPDATE voice_sessions
               SET status = 'connected', connected_at = :now
             WHERE id = :id AND status <> 'ended'
        SQL)->execute([':now' => time(), ':id' => $sessionId]);

        try {
            Doorbell::answerByVoice(
                (string) $row['doorbell_id'],
                (string) $row['parent_name'],
                (int) $row['call_id'],
            );
        } catch (AppError $e) {
            // 通話の確立中に呼び出しが不在確定した／別経路で応答された場合。
            // 話す相手がいないので通話も畳む
            self::finish($sessionId, 'call_closed');
            throw $e;
        }

        return self::exportSession(self::rowById($sessionId), $deviceId);
    }

    /** 通話を終了する（どちらの端末からでも） */
    public static function end(array $device, int $sessionId, string $reason): void
    {
        self::guardEnabled();

        $row = self::sessionFor($sessionId, $device);
        self::finish((int) $row['id'], $reason);
    }

    // ------------------------------------------------------------------
    // 画面状態
    // ------------------------------------------------------------------

    /**
     * 画面に出すための通話の要約。parentState() / childState() から呼ばれる。
     *
     * $deviceId が 0（外部連携）のときは常に null。連携には端末レコードがなく、
     * 通話の当事者になれないため。
     */
    public static function summaryFor(string $doorbellId, int $deviceId): ?array
    {
        if (!self::enabled() || $deviceId <= 0) {
            return null;
        }

        self::expireSessions($doorbellId);
        $row = self::activeRowFor($doorbellId, $deviceId);

        return $row === null ? null : self::exportSession($row, $deviceId);
    }

    // ------------------------------------------------------------------
    // 期限切れの判定（cron がないため、ポーリングのたびに評価する）
    // ------------------------------------------------------------------

    /**
     * 期限を過ぎた通話を終了させる。
     *
     * SQLite では比較の左辺を式にすると型親和性が働かず、数値と文字列の比較になって
     * 常に真になる。しきい値は必ず PHP 側で計算し、左辺はカラムのままにすること。
     */
    public static function expireSessions(string $doorbellId): void
    {
        if (!self::enabled()) {
            return;
        }

        $pdo     = Database::pdo();
        $now     = time();
        $changed = 0;

        // 接続が成立しないまま時間切れになったもの（ICE が通らなかった場合など）
        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE voice_sessions
               SET status = 'ended', ended_at = :now, end_reason = 'timeout'
             WHERE doorbell_id = :id
               AND status IN ('offering', 'answered')
               AND created_at <= :deadline
        SQL);
        $stmt->execute([
            ':now'      => $now,
            ':id'       => $doorbellId,
            ':deadline' => $now - Config::int('voice_connect_timeout'),
        ]);
        $changed += $stmt->rowCount();

        // 通話時間の上限
        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE voice_sessions
               SET status = 'ended', ended_at = :now, end_reason = 'max_time'
             WHERE doorbell_id = :id
               AND status = 'connected'
               AND connected_at <= :deadline
        SQL);
        $stmt->execute([
            ':now'      => $now,
            ':id'       => $doorbellId,
            ':deadline' => $now - Config::int('voice_max_seconds'),
        ]);
        $changed += $stmt->rowCount();

        // 相手のポーリングが途絶えた（タブを閉じた・端末がスリープした・回線が切れた）。
        // 「通話中」の表示のまま音声だけ届かない状態を残さないための判定
        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE voice_sessions
               SET status = 'ended', ended_at = :now, end_reason = 'lost'
             WHERE doorbell_id = :id
               AND status <> 'ended'
               AND (parent_seen_at <= :deadline OR child_seen_at <= :deadline)
        SQL);
        $stmt->execute([
            ':now'      => $now,
            ':id'       => $doorbellId,
            ':deadline' => $now - Config::int('voice_stale_seconds'),
        ]);
        $changed += $stmt->rowCount();

        if ($changed > 0) {
            $pdo->prepare(<<<'SQL'
                DELETE FROM voice_signals
                 WHERE session_id IN (
                     SELECT id FROM voice_sessions WHERE doorbell_id = :id AND status = 'ended'
                 )
            SQL)->execute([':id' => $doorbellId]);
        }
    }

    // ------------------------------------------------------------------
    // 内部ユーティリティ
    // ------------------------------------------------------------------

    private static function guardEnabled(): void
    {
        if (!self::enabled()) {
            throw new AppError('通話機能は無効です。', 'voice_disabled', 403);
        }
    }

    /**
     * セッションを取り出し、この端末が当事者であることを確かめる。
     *
     * SDP は相手のブラウザの setRemoteDescription() にそのまま渡るため、
     * 第三者がセッションIDを推測して割り込めてはいけない。通話に関わる操作は
     * すべてここを通すこと。
     */
    private static function sessionFor(int $sessionId, array $device): array
    {
        $row      = self::rowById($sessionId);
        $deviceId = (int) $device['id'];

        if ((int) $row['parent_device_id'] !== $deviceId && (int) $row['child_device_id'] !== $deviceId) {
            throw new AppError('不正な操作です。', 'forbidden', 403);
        }

        return $row;
    }

    private static function rowById(int $sessionId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM voice_sessions WHERE id = :id');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new AppError('通話が見つかりません。', 'voice_missing', 404);
        }

        return $row;
    }

    /** この端末が当事者になっている進行中の通話 */
    private static function activeRowFor(string $doorbellId, int $deviceId): ?array
    {
        $stmt = Database::pdo()->prepare(<<<'SQL'
            SELECT * FROM voice_sessions
             WHERE doorbell_id = :id
               AND status <> 'ended'
               AND (parent_device_id = :device OR child_device_id = :device)
             ORDER BY id DESC LIMIT 1
        SQL);
        $stmt->execute([':id' => $doorbellId, ':device' => $deviceId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private static function finish(int $sessionId, string $reason): void
    {
        $pdo    = Database::pdo();
        $reason = mb_substr(preg_replace('/[^a-z_]+/', '', strtolower($reason)) ?? '', 0, 16);

        $pdo->prepare(<<<'SQL'
            UPDATE voice_sessions
               SET status = 'ended', ended_at = :now, end_reason = :reason
             WHERE id = :id AND status <> 'ended'
        SQL)->execute([
            ':now'    => time(),
            ':reason' => $reason === '' ? 'hangup' : $reason,
            ':id'     => $sessionId,
        ]);

        $pdo->prepare('DELETE FROM voice_signals WHERE session_id = :id')->execute([':id' => $sessionId]);
    }

    private static function push(int $sessionId, int $toDeviceId, string $kind, array $payload): void
    {
        Database::pdo()->prepare(<<<'SQL'
            INSERT INTO voice_signals (session_id, to_device_id, kind, payload, created_at)
            VALUES (:session, :device, :kind, :payload, :now)
        SQL)->execute([
            ':session' => $sessionId,
            ':device'  => $toDeviceId,
            ':kind'    => $kind,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':now'     => time(),
        ]);
    }

    /** offer / answer の形を確かめる */
    private static function validateDescription(array $raw, string $type): array
    {
        $sdp = (string) ($raw['sdp'] ?? '');

        if ((string) ($raw['type'] ?? '') !== $type || $sdp === '') {
            throw new AppError('通話の情報が不正です。', 'invalid_input');
        }

        return self::validatePayload(['type' => $type, 'sdp' => $sdp]);
    }

    /** 相手のブラウザに渡る値なので、大きさを必ず制限する */
    private static function validatePayload(array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new AppError('通話の情報が大きすぎます。', 'invalid_input');
        }

        return $payload;
    }

    /** クライアント向けに通話の状態を整形する */
    private static function exportSession(array $row, int $viewerId): array
    {
        $status      = (string) $row['status'];
        $connectedAt = (int) $row['connected_at'];
        $isParent    = (int) $row['parent_device_id'] === $viewerId;

        return [
            'id'          => (int) $row['id'],
            'callId'      => (int) $row['call_id'],
            'status'      => $status,
            'role'        => $isParent ? 'parent' : 'child',
            'peerName'    => $isParent ? (string) $row['child_name'] : (string) $row['parent_name'],
            'endReason'   => (string) $row['end_reason'],
            'createdAt'   => (int) $row['created_at'],
            'connectedAt' => $connectedAt,
            // 通話中なら通話時間の上限、確立待ちなら接続を諦める時刻
            'expiresAt'   => $connectedAt > 0
                ? $connectedAt + Config::int('voice_max_seconds')
                : (int) $row['created_at'] + Config::int('voice_connect_timeout'),
        ];
    }
}
