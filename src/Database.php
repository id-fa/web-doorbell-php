<?php
/**
 * SQLite (PDO) 接続とスキーマ初期化。
 */

declare(strict_types=1);

namespace Doorbell;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = (string) Config::get('db_path');
        $dir  = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('データベースディレクトリを作成できません: ' . $dir);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // 同時ポーリングでの書き込み競合に備えて WAL + タイムアウトを設定
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        self::migrate($pdo);
        self::restrictPermissions($path);

        return self::$pdo;
    }

    /**
     * DB ファイルを所有者以外から読めないようにする。
     *
     * 共有ホスティングでは同居する他ユーザーからファイルを読まれる可能性があるため。
     * -wal / -shm は SQLite が本体と同じパーミッションで作り直すので、本体だけを見ればよい。
     * 権限が既に正しいときは何もしない（毎リクエストの chmod を避ける）。
     *
     * Windows では chmod が成功を返しても実際には変化せず、毎回無駄に呼ぶことになるため何もしない。
     */
    private static function restrictPermissions(string $path): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $perms = @fileperms($path);
        if ($perms !== false && ($perms & 0o077) !== 0) {
            @chmod($path, 0o600);
            foreach ([$path . '-wal', $path . '-shm'] as $sidecar) {
                if (is_file($sidecar)) {
                    @chmod($sidecar, 0o600);
                }
            }
        }
    }

    /** テーブルを作成する（存在すれば何もしない） */
    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS doorbell_ids (
                doorbell_id          TEXT    PRIMARY KEY,
                parent_password_hash TEXT    NOT NULL,
                child_password_hash  TEXT    NOT NULL,
                created_ip           TEXT    NOT NULL,
                created_at           INTEGER NOT NULL
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS devices (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                doorbell_id  TEXT    NOT NULL,
                device_key   TEXT    NOT NULL,
                role         TEXT    NOT NULL CHECK (role IN ('parent', 'child')),
                display_name TEXT    NOT NULL,
                ip           TEXT    NOT NULL,
                session_key  TEXT    NOT NULL DEFAULT '',
                created_at   INTEGER NOT NULL,
                last_seen_at INTEGER NOT NULL,
                UNIQUE (doorbell_id, device_key),
                FOREIGN KEY (doorbell_id) REFERENCES doorbell_ids (doorbell_id) ON DELETE CASCADE
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_active ON devices (doorbell_id, role, last_seen_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_ip ON devices (ip, role, last_seen_at)');

        // 既存の DB には CREATE TABLE IF NOT EXISTS で列が追加されないため、個別に足す。
        // 既存の端末は session_key が空のままになり、次回ログインまで未認証として扱われる。
        $columns = $pdo->query('PRAGMA table_info(devices)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('session_key', $columns, true)) {
            $pdo->exec("ALTER TABLE devices ADD COLUMN session_key TEXT NOT NULL DEFAULT ''");
        }

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS calls (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                doorbell_id      TEXT    NOT NULL,
                device_id        INTEGER NOT NULL,
                child_name       TEXT    NOT NULL,
                status           TEXT    NOT NULL CHECK (status IN ('waiting', 'answered', 'no_answer')),
                call_count       INTEGER NOT NULL DEFAULT 1,
                created_at       INTEGER NOT NULL,
                last_called_at   INTEGER NOT NULL,
                responded_at     INTEGER,
                responder_name   TEXT,
                response_key     TEXT,
                response_message TEXT,
                FOREIGN KEY (doorbell_id) REFERENCES doorbell_ids (doorbell_id) ON DELETE CASCADE
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_calls_pending ON calls (doorbell_id, status, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_calls_device ON calls (device_id, id)');

        // 通話（voice_call が有効なときだけ使う）。呼び出しと並走するセッションとして持つ。
        // calls 側の status には手を入れず、通話が成立した時点で通常の応答と同じ形
        // （answered / response_key = 'talk'）に着地させる。
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS voice_sessions (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                call_id          INTEGER NOT NULL,
                doorbell_id      TEXT    NOT NULL,
                parent_device_id INTEGER NOT NULL,
                child_device_id  INTEGER NOT NULL,
                parent_name      TEXT    NOT NULL,
                child_name       TEXT    NOT NULL,
                status           TEXT    NOT NULL CHECK (status IN ('offering', 'answered', 'connected', 'ended')),
                end_reason       TEXT    NOT NULL DEFAULT '',
                created_at       INTEGER NOT NULL,
                connected_at     INTEGER NOT NULL DEFAULT 0,
                ended_at         INTEGER NOT NULL DEFAULT 0,
                parent_seen_at   INTEGER NOT NULL,
                child_seen_at    INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (call_id) REFERENCES calls (id) ON DELETE CASCADE
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_voice_active ON voice_sessions (doorbell_id, status, id)');

        // SDP / ICE の受け渡し。相手が受け取った時点で削除するので、残っているのは未配送のものだけ。
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS voice_signals (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id   INTEGER NOT NULL,
                to_device_id INTEGER NOT NULL,
                kind         TEXT    NOT NULL CHECK (kind IN ('offer', 'answer', 'ice')),
                payload      TEXT    NOT NULL,
                created_at   INTEGER NOT NULL,
                FOREIGN KEY (session_id) REFERENCES voice_sessions (id) ON DELETE CASCADE
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_voice_signals_inbox ON voice_signals (to_device_id, id)');

        // 子機の自動ログイン用URL（child_link_login が有効なときだけ使う）。
        // トークンはそれ自体が認証情報なので、URLを知っている端末は誰でも子機になれる。
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS child_links (
                token        TEXT    PRIMARY KEY,
                doorbell_id  TEXT    NOT NULL,
                display_name TEXT    NOT NULL,
                created_at   INTEGER NOT NULL,
                last_used_at INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (doorbell_id) REFERENCES doorbell_ids (doorbell_id) ON DELETE CASCADE
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_child_links_id ON child_links (doorbell_id, created_at)');

        // 外部連携（Slack 等）。トークンはそれ自体がログイン情報なのでハッシュで保存する。
        // webhook_url が空の連携は「受信専用」（API から状態取得・応答のみ行う）。
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS integrations (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                doorbell_id  TEXT    NOT NULL,
                label        TEXT    NOT NULL,
                token_hash   TEXT    NOT NULL UNIQUE,
                webhook_url  TEXT    NOT NULL DEFAULT '',
                secret       TEXT    NOT NULL,
                created_at   INTEGER NOT NULL,
                last_used_at INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (doorbell_id) REFERENCES doorbell_ids (doorbell_id) ON DELETE CASCADE
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_integrations_id ON integrations (doorbell_id, created_at)');

        // 通知の送信待ち行列。cron がないため、次に誰かがアクセスした時点で配送する。
        // 送信に成功した行は削除するので、残っているのは未送信のものだけ。
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_events (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                integration_id  INTEGER NOT NULL,
                event           TEXT    NOT NULL,
                payload         TEXT    NOT NULL,
                attempts        INTEGER NOT NULL DEFAULT 0,
                next_attempt_at INTEGER NOT NULL,
                created_at      INTEGER NOT NULL,
                FOREIGN KEY (integration_id) REFERENCES integrations (id) ON DELETE CASCADE
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_webhook_events_due ON webhook_events (next_attempt_at, id)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rate_limits (
                bucket       TEXT    PRIMARY KEY,
                hits         INTEGER NOT NULL,
                window_start INTEGER NOT NULL
            )
        SQL);
    }
}
