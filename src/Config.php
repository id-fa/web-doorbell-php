<?php
/**
 * 設定ファイルの読み込みと既定値の管理。
 */

declare(strict_types=1);

namespace Doorbell;

final class Config
{
    /** 応答ボタンの数の上限（親機のカード表示が3列で組まれているため） */
    public const MAX_RESPONSES = 3;

    /**
     * responses に使えないキー。
     *
     * 'timeout' は不在確定、'talk' は通話成立に使う組み込みの応答で、どちらも
     * 設定の応答ボタンとは別系統で書き込まれる。同じキーを設定側で定義できると
     * 履歴の意味が二重になるため受け付けない。
     */
    private const RESERVED_RESPONSE_KEYS = ['timeout', 'talk'];

    /** 設定ファイルが存在しない/項目が欠けている場合に使う既定値 */
    private const DEFAULTS = [
        'polling_interval'       => 10,
        'responses'              => [
            'in1'  => ['message' => '1分以内に応対します', 'label' => '1分以内'],
            'in5'  => ['message' => '5分以内に応対します', 'label' => '5分以内'],
            'away' => ['message' => '担当者が離席中のため応対できません', 'label' => '離席中'],
        ],
        'admin_password'         => '1234',
        'admin_session_lifetime' => 1800,
        'admin_script'           => '',
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
        'mask_client_ip'         => false,
        'deletion_grace_seconds' => 0,
        'id_lifetime'            => 0,
        'trust_proxy'            => false,
        // 通話（テスト版機能）。詳細は voice_ja.md を参照
        'voice_call'             => false,
        'voice_call_label'       => '通話する',
        'voice_call_message'     => 'ここから通話します',
        'voice_video'            => true,
        'voice_ice_servers'      => [],
        'voice_poll_interval'    => 1,
        'voice_connect_timeout'  => 20,
        'voice_max_seconds'      => 300,
        'voice_stale_seconds'    => 15,
        'rate_limit'             => [
            'window'       => 60,
            'max_requests' => 300,
            'max_logins'   => 10,
            'max_calls'    => 20,
            // 通話は 1 秒間隔でポーリングするため、通常のリクエストとは別枠にする。
            // 同じ枠にすると通話中に max_requests を食い潰して画面全体が止まる
            'max_voice'    => 900,
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
        $values['responses']              = self::normalizeResponses($values['responses']);
        $values['admin_script']           = self::normalizeAdminScript($values['admin_script']);
        $values['mask_secrets']           = (bool) $values['mask_secrets'];
        $values['mask_client_ip']         = (bool) $values['mask_client_ip'];
        $values['deletion_grace_seconds'] = max(0, (int) $values['deletion_grace_seconds']);
        $values['id_lifetime']            = max(0, (int) $values['id_lifetime']);
        $values['webhook_timeout']        = min(30, max(1, (int) $values['webhook_timeout']));
        $values['webhook_max_attempts']   = min(20, max(1, (int) $values['webhook_max_attempts']));
        $values['voice_call']             = (bool) $values['voice_call'];
        $values['voice_video']            = (bool) $values['voice_video'];
        $values['voice_call_label']       = self::cleanText($values['voice_call_label'], 12, self::DEFAULTS['voice_call_label']);
        $values['voice_call_message']     = self::cleanText($values['voice_call_message'], 60, self::DEFAULTS['voice_call_message']);
        $values['voice_poll_interval']    = min(10, max(1, (int) $values['voice_poll_interval']));
        $values['voice_connect_timeout']  = max(5, (int) $values['voice_connect_timeout']);
        $values['voice_max_seconds']      = max(30, (int) $values['voice_max_seconds']);
        $values['voice_stale_seconds']    = max(5, (int) $values['voice_stale_seconds']);
        $values['voice_ice_servers']      = self::normalizeIceServers($values['voice_ice_servers']);

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
     * 応答ボタンの定義を整える。
     *
     * 受け付ける書き方は 2 通り。
     *   'key' => ['message' => '子機に出す文言', 'label' => '短縮ラベル']
     *   'key' => '子機に出す文言'   // ラベルは文言と同じになる
     *
     * 設定が壊れていると親機が応答できなくなるため、使えない項目は落とし、
     * 1件も残らなければ既定値に戻す。
     */
    private static function normalizeResponses(mixed $raw): array
    {
        $clean = static fn (string $text, int $limit): string => mb_substr(
            trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $text) ?? ''),
            0,
            $limit,
        );

        $result = [];
        foreach (is_array($raw) ? $raw : [] as $key => $entry) {
            $key = (string) $key;

            // 組み込みの応答と同じキーは受け付けない
            if (preg_match('/\A[A-Za-z0-9_]{1,16}\z/', $key) !== 1 || in_array($key, self::RESERVED_RESPONSE_KEYS, true)) {
                continue;
            }

            $message = $clean(is_array($entry) ? (string) ($entry['message'] ?? '') : (string) $entry, 60);
            if ($message === '') {
                continue;
            }

            $label = $clean(is_array($entry) ? (string) ($entry['label'] ?? '') : '', 12);
            $result[$key] = ['message' => $message, 'label' => $label === '' ? mb_substr($message, 0, 12) : $label];

            if (count($result) >= self::MAX_RESPONSES) {
                break;
            }
        }

        if (count(is_array($raw) ? $raw : []) > self::MAX_RESPONSES) {
            error_log('doorbell config: responses は ' . self::MAX_RESPONSES . ' 件までです。先頭の分だけを使います。');
        }

        return $result === [] ? self::DEFAULTS['responses'] : $result;
    }

    /** 表示に使う短い文字列を整える（制御文字を落とし、長さを切り、空なら既定値に戻す） */
    private static function cleanText(mixed $raw, int $limit, string $fallback): string
    {
        $text = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $raw) ?? ''), 0, $limit);

        return $text === '' ? $fallback : $text;
    }

    /**
     * 通話の ICE サーバー設定を整える。
     *
     * この値はブラウザにそのまま渡るため、誰でも読める。TURN の固定の認証情報を
     * 書くべきではない（voice_ja.md に明記）。
     * 受け付ける書き方は 2 通り。
     *   'stun:stun.example.net:3478'
     *   ['urls' => 'turn:turn.example.net:3478', 'username' => '...', 'credential' => '...']
     *
     * @return list<array{urls: string, username?: string, credential?: string}>
     */
    private static function normalizeIceServers(mixed $raw): array
    {
        $result = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            $urls = is_array($entry) ? (string) ($entry['urls'] ?? '') : (string) $entry;
            $urls = trim($urls);

            // ブラウザに渡す前に形式を絞る。想定外のスキームは黙って落とす
            if (preg_match('#\A(stun|stuns|turn|turns):[A-Za-z0-9._\-\[\]:]+(\?transport=(udp|tcp))?\z#', $urls) !== 1) {
                if ($urls !== '') {
                    error_log('doorbell config: voice_ice_servers に使えない指定があります: ' . $urls);
                }
                continue;
            }

            $server = ['urls' => $urls];
            if (is_array($entry) && ($entry['username'] ?? '') !== '') {
                $server['username']   = (string) $entry['username'];
                $server['credential'] = (string) ($entry['credential'] ?? '');
            }

            $result[] = $server;
        }

        return $result;
    }

    /**
     * ID発行画面のファイル名を整える。
     *
     * この値は画面内のリンクとフォームの送信先にそのまま出るため、
     * ディレクトリを含む指定は名前だけに落とし、ファイル名として使える文字に限る。
     * 使えない指定は空（＝実行中のファイル名から自動判定）に戻す。
     * 設定ミスで画面内のリンクが全滅するのを避けるため。
     */
    private static function normalizeAdminScript(mixed $raw): string
    {
        $name = basename(trim((string) $raw));
        if ($name === '') {
            return '';
        }

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\.php\z/', $name) !== 1) {
            error_log('doorbell config: admin_script は英数字と . _ - からなる .php のファイル名で指定してください。自動判定に戻します。');
            return '';
        }

        return $name;
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
            'voiceCall'           => (bool) self::get('voice_call'),
            'voiceCallLabel'      => (string) self::get('voice_call_label'),
            'voiceVideo'          => (bool) self::get('voice_video'),
            // 誰でも読める値なので、TURN の固定の認証情報を書かせない前提の設定にしてある
            'voiceIceServers'     => (array) self::get('voice_ice_servers'),
            'voicePollInterval'   => self::int('voice_poll_interval'),
            'voiceConnectTimeout' => self::int('voice_connect_timeout'),
            'voiceMaxSeconds'     => self::int('voice_max_seconds'),
            'voiceStaleSeconds'   => self::int('voice_stale_seconds'),
        ];
    }
}
