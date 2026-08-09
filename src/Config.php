<?php
/**
 * 設定ファイルの読み込みと既定値の管理。
 */

declare(strict_types=1);

namespace Doorbell;

final class Config
{
    /** 設定ファイルが存在しない/項目が欠けている場合に使う既定値 */
    private const DEFAULTS = [
        'polling_interval'       => 10,
        'admin_password'         => '1234',
        'admin_session_lifetime' => 1800,
        'max_ids_per_instance'   => 100,
        'max_children_per_id'    => 1,
        'max_parents_per_id'     => 1,
        'max_ids_per_ip'         => 5,
        'ringtone_repeat'        => 3,
        'absence_timeout'        => 60,
        'db_path'                => null,
        'response_view_timeout'  => 60,
        'parent_offline_polls'   => 5,
        'offline_stop_seconds'   => 600,
        'child_status_window'    => 3600,
        'session_lifetime'       => 86400,
        'history_retention_days' => 30,
        'history_display_limit'  => 20,
        'child_logout_password'  => true,
        'child_link_login'       => false,
        'integration_api'        => false,
        'webhook_allowed_hosts'  => ['hooks.slack.com'],
        'webhook_timeout'        => 5,
        'webhook_max_attempts'   => 5,
        'mask_secrets'           => false,
        'deletion_grace_seconds' => 0,
        'id_lifetime'            => 0,
        'trust_proxy'            => false,
        'rate_limit'             => [
            'window'       => 60,
            'max_requests' => 300,
            'max_logins'   => 10,
            'max_calls'    => 20,
        ],
    ];

    private static ?array $values = null;

    /** 設定を読み込む（初回のみファイルアクセス） */
    public static function load(): array
    {
        if (self::$values !== null) {
            return self::$values;
        }

        $file   = self::envOverride('DOORBELL_CONFIG_PATH') ?: \dirname(__DIR__) . '/config/config.php';
        $loaded = [];
        if (is_file($file)) {
            /** @var mixed $loaded */
            $loaded = require $file;
            if (!is_array($loaded)) {
                throw new \RuntimeException('config.php は設定の配列を return する必要があります。');
            }
        }

        $values = array_merge(self::DEFAULTS, $loaded);
        $values['rate_limit'] = array_merge(self::DEFAULTS['rate_limit'], $loaded['rate_limit'] ?? []);
        $values['db_path'] ??= \dirname(__DIR__) . '/data/doorbell.sqlite';

        // テスト実行時に本番データを壊さないよう、DBの場所を環境変数で差し替えられるようにする
        $values['db_path'] = self::envOverride('DOORBELL_DB_PATH') ?: $values['db_path'];

        // 極端な値で動作不能にならないよう最低限の丸め込みを行う
        $values['polling_interval']       = max(1, (int) $values['polling_interval']);
        $values['admin_session_lifetime'] = max(60, (int) $values['admin_session_lifetime']);
        $values['max_ids_per_instance']   = max(1, (int) $values['max_ids_per_instance']);
        $values['max_children_per_id']    = max(1, (int) $values['max_children_per_id']);
        $values['max_parents_per_id']     = max(1, (int) $values['max_parents_per_id']);
        $values['max_ids_per_ip']         = max(1, (int) $values['max_ids_per_ip']);
        $values['ringtone_repeat']        = max(1, (int) $values['ringtone_repeat']);
        $values['absence_timeout']        = max(5, (int) $values['absence_timeout']);
        $values['response_view_timeout']  = max(5, (int) $values['response_view_timeout']);
        $values['parent_offline_polls']   = max(2, (int) $values['parent_offline_polls']);
        $values['offline_stop_seconds']   = max(60, (int) $values['offline_stop_seconds']);
        $values['child_status_window']    = max(60, (int) $values['child_status_window']);
        $values['child_logout_password']  = (bool) $values['child_logout_password'];
        $values['child_link_login']       = (bool) $values['child_link_login'];
        $values['integration_api']        = (bool) $values['integration_api'];
        $values['mask_secrets']           = (bool) $values['mask_secrets'];
        $values['deletion_grace_seconds'] = max(0, (int) $values['deletion_grace_seconds']);
        $values['id_lifetime']            = max(0, (int) $values['id_lifetime']);
        $values['webhook_timeout']        = min(30, max(1, (int) $values['webhook_timeout']));
        $values['webhook_max_attempts']   = min(20, max(1, (int) $values['webhook_max_attempts']));

        // 送信先ホストの許可リスト。小文字に揃え、空の項目は落とす
        $hosts = is_array($values['webhook_allowed_hosts']) ? $values['webhook_allowed_hosts'] : [];
        $values['webhook_allowed_hosts'] = array_values(array_filter(
            array_map(static fn (mixed $host): string => strtolower(trim((string) $host)), $hosts),
            static fn (string $host): bool => $host !== '',
        ));

        self::$values = $values;
        return self::$values;
    }

    /**
     * テスト用の環境変数による上書き（設定ファイルの場所・DBの場所）。
     *
     * Web サーバー経由（php-fpm / mod_php / CGI）では受け付けない。
     * それらの SAPI では環境変数がリクエスト由来の値で汚染されうるため。
     */
    private static function envOverride(string $name): string
    {
        if (!\in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
            return '';
        }

        $value = getenv($name);

        return is_string($value) ? $value : '';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::load()[$key] ?? $default;
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /**
     * クライアント（ブラウザ）へ公開してよい設定値のみを返す。
     * パスワード等の秘匿値をここに含めてはいけない。
     */
    public static function publicValues(): array
    {
        return [
            'pollingInterval'     => self::int('polling_interval'),
            'ringtoneRepeat'      => self::int('ringtone_repeat'),
            'absenceTimeout'      => self::int('absence_timeout'),
            'responseViewTimeout' => self::int('response_view_timeout'),
            'offlineStopSeconds'  => self::int('offline_stop_seconds'),
            'childLogoutPassword' => (bool) self::get('child_logout_password'),
        ];
    }
}
