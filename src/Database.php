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
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
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

        return self::$pdo;
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
                created_at   INTEGER NOT NULL,
                last_seen_at INTEGER NOT NULL,
                UNIQUE (doorbell_id, device_key),
                FOREIGN KEY (doorbell_id) REFERENCES doorbell_ids (doorbell_id) ON DELETE CASCADE
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_active ON devices (doorbell_id, role, last_seen_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_ip ON devices (ip, role, last_seen_at)');

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

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rate_limits (
                bucket       TEXT    PRIMARY KEY,
                hits         INTEGER NOT NULL,
                window_start INTEGER NOT NULL
            )
        SQL);
    }
}
