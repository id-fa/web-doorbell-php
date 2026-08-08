<?php
/**
 * ロジックの検証（不在判定・履歴のまとめ・同時コール・子機の稼働状態・各種上限）。
 *
 * 使い方: php tests/logic_test.php
 *
 * 一時ファイルの SQLite を使うため、data/ の実データには影響しない。
 */

declare(strict_types=1);

// bootstrap より前に DB の場所を差し替える
$testDb = sys_get_temp_dir() . '/doorbell-test-' . getmypid() . '.sqlite';
putenv('DOORBELL_DB_PATH=' . $testDb);
register_shutdown_function(static function () use ($testDb): void {
    foreach ([$testDb, $testDb . '-wal', $testDb . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
});

require dirname(__DIR__) . '/src/bootstrap.php';

use Doorbell\AppError;
use Doorbell\Config;
use Doorbell\Database;
use Doorbell\Doorbell;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  OK   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label} {$detail}\n";
    }
}

$pdo = Database::pdo();

echo "== 不在判定と履歴のまとめ ==\n";
$id     = Doorbell::issueId('192.0.2.10');
$parent = Doorbell::login($id['doorbell_id'], $id['parent_password'], '受付', str_repeat('a', 16), '192.0.2.10');
$child  = Doorbell::login($id['doorbell_id'], $id['child_password'], '玄関', str_repeat('b', 16), '192.0.2.20');

// 応答なしの呼び出しを3回（連打ぶんを含む）作り、created_at を過去にずらす
$timeout = Config::int('absence_timeout');
for ($i = 3; $i >= 1; $i--) {
    Doorbell::call($child);
    if ($i === 2) {
        Doorbell::call($child); // 応答待ち中の連打はまとめられる
    }
    $pdo->exec('UPDATE calls SET created_at = created_at - ' . ($timeout + 10) . " WHERE status = 'waiting'");
    Doorbell::expireStaleCalls($id['doorbell_id']);
}

$state = Doorbell::childState($child);
check('応答待ちが不在判定で終了する', $state['currentCall'] !== null && $state['currentCall']['status'] === 'no_answer');
check('不在メッセージが設定される', ($state['currentCall']['message'] ?? '') === Doorbell::NO_ANSWER_MESSAGE);
check('連続する応答なしが1件にまとまる', count($state['history']) === 1, json_encode($state['history'], JSON_UNESCAPED_UNICODE));
check('まとめた呼び出し回数が4回', ($state['history'][0]['count'] ?? 0) === 4, json_encode($state['history'][0] ?? null, JSON_UNESCAPED_UNICODE));

// 応答ありを挟むと履歴が分かれる
Doorbell::call($child);
$ps = Doorbell::parentState($parent);
Doorbell::respond($parent, $ps['activeCalls'][0]['id'], 'away');
$state = Doorbell::childState($child);
check('応答ありで履歴が分かれる', count($state['history']) === 2);
check('応答ありの内容が記録される', ($state['history'][0]['message'] ?? '') === Doorbell::RESPONSES['away']);

echo "== 複数子機からの同時コール ==\n";
// 上限（既定1台）に関係なく検証したいので、2台目の子機は直接登録する
$now = time();
$pdo->prepare(<<<'SQL'
    INSERT INTO devices (doorbell_id, device_key, role, display_name, ip, created_at, last_seen_at)
    VALUES (:id, :key, 'child', :name, :ip, :now, :now)
SQL)->execute([
    ':id'   => $id['doorbell_id'],
    ':key'  => str_repeat('e', 16),
    ':name' => '裏口',
    ':ip'   => '192.0.2.21',
    ':now'  => $now,
]);
$stmt = $pdo->prepare('SELECT * FROM devices WHERE doorbell_id = :id AND device_key = :key');
$stmt->execute([':id' => $id['doorbell_id'], ':key' => str_repeat('e', 16)]);
$child2 = $stmt->fetch();

Doorbell::call($child);
Doorbell::call($child2);
Doorbell::call($child2); // 2台目だけ連打
$ps = Doorbell::parentState($parent);
check('応答待ちの呼び出しが全件返る', count($ps['activeCalls']) === 2, json_encode($ps['activeCalls'], JSON_UNESCAPED_UNICODE));
check('古い呼び出しが先頭に並ぶ', ($ps['activeCalls'][0]['childName'] ?? '') === '玄関');
check('残り時間が呼び出しごとに個別に返る', isset($ps['activeCalls'][0]['expiresAt'], $ps['activeCalls'][1]['expiresAt']));
check('子機ごとに連打がまとめられる', ($ps['activeCalls'][1]['callCount'] ?? 0) === 2);

// 片方だけ応答しても、もう片方は応答待ちのまま残る
Doorbell::respond($parent, $ps['activeCalls'][1]['id'], 'in1');
$ps = Doorbell::parentState($parent);
check('片方に応答しても他方は残る', count($ps['activeCalls']) === 1 && $ps['activeCalls'][0]['childName'] === '玄関');
Doorbell::respond($parent, $ps['activeCalls'][0]['id'], 'in5');
check('全てに応答すると待ち受けに戻る', Doorbell::parentState($parent)['activeCalls'] === []);

echo "== 親機の応答履歴 ==\n";
$pdo->exec('DELETE FROM calls');

// 応答なしの履歴を任意の時刻で作る（responded_at は不在判定の 60 秒後）
$mk = static function (array $device, int $ago) use ($pdo, $id): void {
    $pdo->prepare(<<<'SQL'
        INSERT INTO calls (doorbell_id, device_id, child_name, status, call_count, created_at, last_called_at,
                           responded_at, response_key, response_message)
        VALUES (:db, :dev, :name, 'no_answer', 1, :ca, :ca, :ra, 'timeout', :msg)
    SQL)->execute([
        ':db'   => $id['doorbell_id'],
        ':dev'  => $device['id'],
        ':name' => $device['display_name'],
        ':ca'   => time() - $ago,
        ':ra'   => time() - $ago + 60,
        ':msg'  => Doorbell::NO_ANSWER_MESSAGE,
    ]);
};
// 古い順に: 玄関(応答なし) → 玄関(応答なし) → 裏口(応答なし) → 玄関(応答あり)
$mk($child, 500);
$mk($child, 400);
$mk($child2, 300);
Doorbell::call($child);
$ps = Doorbell::parentState($parent);
Doorbell::respond($parent, $ps['activeCalls'][0]['id'], 'in5');

$ph = Doorbell::parentState($parent)['history'];
check('親機の履歴に全子機の呼び出しが載る', count($ph) === 3, json_encode($ph, JSON_UNESCAPED_UNICODE));
check(
    '親機の履歴は新しい順',
    ($ph[0]['lastAt'] ?? 0) >= ($ph[1]['lastAt'] ?? 0) && ($ph[1]['lastAt'] ?? 0) >= ($ph[2]['lastAt'] ?? 0),
);
check(
    '履歴に呼び出し元の子機名と応答者が入る',
    ($ph[0]['childName'] ?? '') === '玄関' && ($ph[0]['responder'] ?? '') === '受付' && $ph[0]['status'] === 'answered',
    json_encode($ph[0] ?? null, JSON_UNESCAPED_UNICODE),
);
check(
    '別の子機の応答なしは分けて表示する',
    ($ph[1]['childName'] ?? '') === '裏口' && ($ph[1]['count'] ?? 0) === 1,
    json_encode($ph[1] ?? null, JSON_UNESCAPED_UNICODE),
);
check(
    '同じ子機の連続した応答なしはまとめる',
    ($ph[2]['childName'] ?? '') === '玄関' && ($ph[2]['count'] ?? 0) === 2 && ($ph[2]['times'] ?? 0) === 2,
    json_encode($ph[2] ?? null, JSON_UNESCAPED_UNICODE),
);

// 子機側の履歴には自分の呼び出しだけが出る
$ch      = Doorbell::childState($child2)['history'];
$onlyOwn = $ch !== [];
foreach ($ch as $g) {
    if ($g['childName'] !== '裏口') {
        $onlyOwn = false;
    }
}
check('子機の履歴は自分の呼び出しのみ', $onlyOwn, json_encode($ch, JSON_UNESCAPED_UNICODE));

echo "== 親機から見た子機の稼働状態 ==\n";
$names = static fn (array $list): array => array_map(
    static fn ($c) => $c['name'] . ':' . ($c['online'] ? 'on' : 'off'),
    $list,
);

$cs = Doorbell::parentState($parent)['children'];
check('稼働中の子機が一覧に出る', $names($cs) === ['玄関:on', '裏口:on'], json_encode($names($cs), JSON_UNESCAPED_UNICODE));

// ポーリングが途絶えた子機はオフラインとして残る（ネットワーク断に気づけるようにするため）
$pdo->prepare('UPDATE devices SET last_seen_at = :t WHERE id = :id')
    ->execute([':t' => time() - Doorbell::activeWindow() - 1, ':id' => $child2['id']]);
$cs = Doorbell::parentState($parent)['children'];
check('通信が途絶えた子機はオフライン表示で残る', $names($cs) === ['玄関:on', '裏口:off'], json_encode($names($cs), JSON_UNESCAPED_UNICODE));

// 明示的にログアウトした子機は一覧から消える
Doorbell::logout((int) $child2['id']);
$cs = Doorbell::parentState($parent)['children'];
check('ログアウトした子機は一覧から消える', $names($cs) === ['玄関:on'], json_encode($names($cs), JSON_UNESCAPED_UNICODE));

// 表示継続時間を過ぎた子機も消える
$pdo->prepare('UPDATE devices SET last_seen_at = :t WHERE id = :id')
    ->execute([':t' => time() - Config::int('child_status_window') - 1, ':id' => $child['id']]);
check('古すぎる子機は一覧から消える', Doorbell::parentState($parent)['children'] === []);
$pdo->prepare('UPDATE devices SET last_seen_at = :t WHERE id = :id')->execute([':t' => time(), ':id' => $child['id']]);

echo "== 親機の稼働判定 ==\n";
check('親機が稼働中と判定される', Doorbell::childState($child)['parentOnline'] === true);
$pdo->prepare("UPDATE devices SET last_seen_at = :t WHERE role = 'parent'")
    ->execute([':t' => time() - Doorbell::activeWindow() - 1]);
check('ポーリング停止で親機オフライン', Doorbell::childState($child)['parentOnline'] === false);

echo "== 上限チェック ==\n";
// 1IPあたりの最大ID数
$pdo->exec('DELETE FROM doorbell_ids');
$max = Config::int('max_ids_per_ip');
for ($i = 0; $i < $max; $i++) {
    Doorbell::issueId('198.51.100.5');
}
try {
    Doorbell::issueId('198.51.100.5');
    check('1IPあたりのID数上限', false, '例外が発生しなかった');
} catch (AppError $e) {
    check('1IPあたりのID数上限', $e->errorCode === 'limit_ip', $e->getMessage());
}
check('別IPからは発行できる', Doorbell::issueId('198.51.100.6') !== []);

// 親機の同時接続上限
$fresh = Doorbell::issueId('203.0.113.1');
Doorbell::login($fresh['doorbell_id'], $fresh['parent_password'], '親1', str_repeat('c', 16), '203.0.113.1');
try {
    Doorbell::login($fresh['doorbell_id'], $fresh['parent_password'], '親2', str_repeat('d', 16), '203.0.113.2');
    check('親機の同時接続上限', false, '例外が発生しなかった');
} catch (AppError $e) {
    check('親機の同時接続上限', $e->errorCode === 'limit_devices', $e->getMessage());
}
// 同じ端末キーなら再ログインできる
$again = Doorbell::login($fresh['doorbell_id'], $fresh['parent_password'], '親1', str_repeat('c', 16), '203.0.113.1');
check('同一端末の再ログインは許可される', (string) $again['role'] === 'parent');

// サーバー全体の最大ID数
$pdo->exec('DELETE FROM doorbell_ids');
$total = Config::int('max_ids_per_instance');
$stmt  = $pdo->prepare('INSERT INTO doorbell_ids VALUES (:id, :p, :c, :ip, :now)');
for ($i = 0; $i < $total; $i++) {
    $stmt->execute([
        ':id'  => str_pad((string) $i, 8, '0', STR_PAD_LEFT),
        ':p'   => 'x',
        ':c'   => 'y',
        ':ip'  => '203.0.113.' . ($i % 200),
        ':now' => time(),
    ]);
}
try {
    Doorbell::issueId('192.0.2.250');
    check('サーバー全体のID数上限', false, '例外が発生しなかった');
} catch (AppError $e) {
    check('サーバー全体のID数上限', $e->errorCode === 'limit_instance', $e->getMessage());
}

echo "== ログインの失敗応答 ==\n";
// 直前の上限テストでID数が上限に達しているため、発行できるように空にする
$pdo->exec('DELETE FROM doorbell_ids');

// ID の誤りとパスワードの誤りを区別すると、有効なIDを総当たりで発見できてしまう
$creds = Doorbell::issueId('198.51.100.9');
$codes = [];
foreach ([['00000000', 'x'], [$creds['doorbell_id'], 'wrongpass']] as [$tryId, $tryPw]) {
    try {
        Doorbell::login($tryId, $tryPw, 'x', str_repeat('f', 16), '198.51.100.9');
        $codes[] = 'ログインできてしまった';
    } catch (AppError $e) {
        $codes[] = $e->errorCode;
    }
}
check('IDの誤りとパスワードの誤りで応答が変わらない', $codes[0] === $codes[1], json_encode($codes));
check('失敗時のエラー種別が invalid_credentials', $codes[0] === 'invalid_credentials', json_encode($codes));

echo "== 端末のセッション鍵 ==\n";
$key   = str_repeat('9', 16);
$first = Doorbell::login($creds['doorbell_id'], $creds['child_password'], '端末A', $key, '198.51.100.9');
check('ログインでセッション鍵が発行される', ($first['session_key'] ?? '') !== '');
check('発行直後のセッション鍵で認証できる', Doorbell::device((int) $first['id'], (string) $first['session_key']) !== null);

// 同じ device_key で入り直すと、前のセッションは無効になる（上限の回避を防ぐため）
$second = Doorbell::login($creds['doorbell_id'], $creds['child_password'], '端末B', $key, '198.51.100.9');
check('再ログインでセッション鍵が変わる', $second['session_key'] !== $first['session_key']);
check('古いセッション鍵は無効になる', Doorbell::device((int) $first['id'], (string) $first['session_key']) === null);
check('新しいセッション鍵は有効', Doorbell::device((int) $second['id'], (string) $second['session_key']) !== null);
check('空のセッション鍵では認証できない', Doorbell::device((int) $second['id'], '') === null);

echo "== ログアウト時のパスワード確認 ==\n";
// 置きっぱなしの子機を勝手にログアウトされないようにする（親機は対象外）
check('子機はログアウトにパスワードが必要', Doorbell::logoutNeedsPassword($second) === true);
check('親機はログアウトにパスワード不要', Doorbell::logoutNeedsPassword($again) === false);
check('子機パスワードで検証が通る', Doorbell::verifyPassword($second, $creds['child_password']) === true);
check('親機パスワードでは通らない', Doorbell::verifyPassword($second, $creds['parent_password']) === false);
check('空のパスワードでは通らない', Doorbell::verifyPassword($second, '') === false);

Doorbell::logout((int) $second['id']);
check('ログアウト後はセッション鍵が使えない', Doorbell::device((int) $second['id'], (string) $second['session_key']) === null);

echo "== 子機URL（自動ログイン） ==\n";
$linkId = Doorbell::issueId('198.51.100.20');
$link   = Doorbell::createChildLink($linkId['doorbell_id'], '  勝手口  ');
check('トークンは16進32桁', Doorbell::normalizeToken($link['token']) === $link['token'], $link['token']);
check('表示名は前後の空白を落として保持する', $link['display_name'] === '勝手口', $link['display_name']);

$linked = Doorbell::loginByLink($link['token'], str_repeat('e', 16), '198.51.100.21');
check('子機URLでログインできる', (string) $linked['role'] === 'child');
check('リンクの表示名が端末に設定される', (string) $linked['display_name'] === '勝手口');
check('セッション鍵が発行される', ($linked['session_key'] ?? '') !== '');
check(
    'リンクで入った子機が親機から見える',
    Doorbell::childStatuses($linkId['doorbell_id']) !== [],
);

// 表示名なしでは発行できない（端末の識別ができなくなるため）
try {
    Doorbell::createChildLink($linkId['doorbell_id'], '   ');
    check('表示名なしの子機URLは発行できない', false, '例外が発生しなかった');
} catch (AppError $e) {
    check('表示名なしの子機URLは発行できない', $e->errorCode === 'invalid_input', $e->getMessage());
}

// 存在しないIDには発行できない
try {
    Doorbell::createChildLink('00000000', '玄関');
    check('存在しないIDには発行できない', false, '例外が発生しなかった');
} catch (AppError $e) {
    check('存在しないIDには発行できない', $e->errorCode === 'unknown_id', $e->getMessage());
}

// 失効させたトークンでは入れない
check('失効させられる', Doorbell::deleteChildLink($link['token']) === true);
try {
    Doorbell::loginByLink($link['token'], str_repeat('e', 16), '198.51.100.21');
    check('失効した子機URLでは入れない', false, '例外が発生しなかった');
} catch (AppError $e) {
    check('失効した子機URLでは入れない', $e->errorCode === 'invalid_link', $e->getMessage());
}

// 形式の違うトークンも同じエラーにまとめる
try {
    Doorbell::loginByLink('not-a-token', str_repeat('e', 16), '198.51.100.21');
    check('不正な形式のトークンは拒否される', false, '例外が発生しなかった');
} catch (AppError $e) {
    check('不正な形式のトークンは拒否される', $e->errorCode === 'invalid_link', $e->getMessage());
}

// ID を削除するとリンクも消える（外部キーの ON DELETE CASCADE）
$link2 = Doorbell::createChildLink($linkId['doorbell_id'], '裏口');
Doorbell::deleteId($linkId['doorbell_id']);
check('ID削除でリンクも消える', Doorbell::deleteChildLink($link2['token']) === false);

echo "== ID の正規化 ==\n";
check('ハイフン付きIDを受け付ける', Doorbell::normalizeId('1234-5678') === '12345678');
check('桁数違いは無効', Doorbell::normalizeId('123') === '');
check('表示は4桁区切り', Doorbell::formatId('12345678') === '1234-5678');

echo "\n結果: {$pass} 件成功 / {$fail} 件失敗\n";
exit($fail === 0 ? 0 : 1);
