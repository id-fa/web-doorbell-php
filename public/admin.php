<?php
/**
 * ID発行管理画面（要パスワード）。
 *
 * 画面は 2 つある。
 * - 一覧: 発行済みIDの一覧と新規発行
 * - 詳細（`admin.php?id=1234-5678`）: そのIDの子機URL・外部連携・削除
 *
 * 表示するIDはクエリ `id` で決まる。子機URLや連携を発行した直後は、
 * 発行したIDの詳細画面に留まる（トークンをその場で控えられるようにするため）。
 */

declare(strict_types=1);

namespace Doorbell;

require __DIR__ . '/../src/bootstrap.php';

Http::startSession();

/**
 * 管理画面のログイン状態。
 * 一定時間操作がなければ失効させる（画面を開いたまま放置された端末を保護するため）。
 */
function adminAuthenticated(): bool
{
    if (($_SESSION['admin_authenticated'] ?? false) !== true) {
        return false;
    }

    $lastActive = (int) ($_SESSION['admin_last_active'] ?? 0);
    if (time() - $lastActive >= Config::int('admin_session_lifetime')) {
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_last_active']);
        return false;
    }

    $_SESSION['admin_last_active'] = time();
    return true;
}

/** 詳細画面のURL（フォームの送信先にも使う） */
function detailUrl(string $rawId): string
{
    return 'admin.php?id=' . rawurlencode($rawId);
}

/** 失効ボタンの状態（発行直後は押せない） */
function lockNote(?int $remaining): string
{
    return $remaining !== null && $remaining > 0 ? 'あと' . Doorbell::duration($remaining) : '';
}

$ip          = Http::clientIp();
$limits      = (array) Config::get('rate_limit');
$issued      = null;
$deleted     = '';
$issuedLink  = null;
$issuedHook  = null;
$linkEnabled = (bool) Config::get('child_link_login');
$hookEnabled = Integration::enabled();
$mask        = (bool) Config::get('mask_secrets');

$hadSession = isset($_SESSION['admin_authenticated']);
$isLoggedIn = adminAuthenticated(); // 失効していれば、ここでセッションから取り除かれる
$error      = $hadSession && !$isLoggedIn
    ? 'ログインの有効期限が切れました。もう一度ログインしてください。'
    : '';

// 表示対象のID（空なら一覧を出す）
$viewId = Doorbell::normalizeId((string) ($_GET['id'] ?? ''));

try {
    Http::rateLimit('req', $ip, (int) $limits['max_requests']);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Http::requireCsrf();
        $action = (string) ($_POST['action'] ?? '');

        // ログイン以外はすべてログインが要る
        if (!in_array($action, ['', 'login', 'logout'], true) && !$isLoggedIn) {
            throw new AppError('ログインしてください。', 'unauthenticated', 401);
        }

        switch ($action) {
            case 'login':
                // メイン画面のログインとは別のバケットにする。
                // 共有すると、子機のログイン成功でこの試行回数を巻き戻せてしまうため
                Http::rateLimit('login_admin', $ip, (int) $limits['max_logins']);
                $password = (string) ($_POST['password'] ?? '');
                if (hash_equals((string) Config::get('admin_password'), $password)) {
                    session_regenerate_id(true);
                    $_SESSION['admin_authenticated'] = true;
                    $_SESSION['admin_last_active']   = time();
                    $isLoggedIn = true;
                    $error      = '';
                    Http::rateLimitRelease('login_admin', $ip);
                } else {
                    $error = 'パスワードが正しくありません。';
                }
                break;

            case 'logout':
                unset($_SESSION['admin_authenticated'], $_SESSION['admin_last_active']);
                $isLoggedIn = false;
                $error      = '';
                $viewId     = '';
                break;

            case 'issue':
                $issued = Doorbell::issueId($ip);
                $viewId = ''; // 発行直後のパスワードは一覧画面で見せる
                break;

            case 'delete':
                $target  = (string) ($_POST['doorbell_id'] ?? '');
                $deleted = Doorbell::deleteId($target) ? Doorbell::formatId(Doorbell::normalizeId($target)) : '';
                if ($deleted === '') {
                    $error = '指定されたIDは見つかりませんでした。';
                } else {
                    $viewId = ''; // 消したIDの詳細は開けないので一覧へ戻す
                }
                break;

            case 'child_link':
                if (!$linkEnabled) {
                    throw new AppError('子機URLの発行は無効です。', 'link_disabled', 403);
                }
                $issuedLink = Doorbell::createChildLink(
                    (string) ($_POST['doorbell_id'] ?? ''),
                    (string) ($_POST['display_name'] ?? ''),
                );
                $viewId = $issuedLink['doorbell_id'];
                break;

            case 'child_link_delete':
                if (!Doorbell::deleteChildLink((string) ($_POST['token'] ?? ''))) {
                    $error = '指定された子機URLは見つかりませんでした。';
                }
                break;

            case 'integration':
                if (!$hookEnabled) {
                    throw new AppError('外部連携の発行は無効です。', 'integration_disabled', 403);
                }
                $issuedHook = Integration::create(
                    (string) ($_POST['doorbell_id'] ?? ''),
                    (string) ($_POST['label'] ?? ''),
                    (string) ($_POST['webhook_url'] ?? ''),
                );
                $viewId = $issuedHook['doorbell_id'];
                break;

            case 'integration_delete':
                if (!Integration::delete((int) ($_POST['integration_id'] ?? 0))) {
                    $error = '指定された連携は見つかりませんでした。';
                }
                break;
        }
    }
} catch (AppError $e) {
    $error = $e->getMessage();
} catch (\Throwable $e) {
    error_log('doorbell admin error: ' . $e);
    $error = 'サーバーエラーが発生しました。';
}

// 詳細画面に出す情報（IDが見つからなければ一覧に落とす）
$detail = $isLoggedIn && $viewId !== '' ? Doorbell::idSummary($viewId) : null;
if ($isLoggedIn && $viewId !== '' && $detail === null && $error === '') {
    $error = '指定されたIDは見つかりませんでした。';
}

$csrf     = Http::csrfToken();
$formUrl  = $detail === null ? 'admin.php' : detailUrl($detail['raw_id']);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $detail === null ? 'ID発行管理' : 'ID ' . Http::h($detail['doorbell_id']) ?> - ドアベル</title>
<link rel="stylesheet" href="assets/style.css?v=6">
</head>
<body>
<div id="app" class="admin">
  <div class="card">

    <?php if (!$isLoggedIn): ?>
      <h1 class="title">ID発行管理</h1>
      <?php if ($error !== ''): ?>
        <p class="error"><?= Http::h($error) ?></p>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
        <input type="hidden" name="action" value="login">
        <label class="field">
          <span class="field-label">管理パスワード</span>
          <input type="password" name="password" maxlength="128" autocomplete="current-password" required autofocus>
        </label>
        <button type="submit" class="btn btn-primary">ログイン</button>
      </form>

    <?php elseif ($detail === null): ?>
      <?php /* ---------------- 一覧画面 ---------------- */ ?>
      <h1 class="title">ID発行管理</h1>

      <?php if ($error !== ''): ?>
        <p class="error"><?= Http::h($error) ?></p>
      <?php endif; ?>

      <?php if ($issued !== null): ?>
        <div class="credentials">
          <p class="meta">発行しました。パスワードは再表示できません。必ず控えてください。</p>
          <dl>
            <dt>ID</dt>
            <dd><?= Http::h(Doorbell::formatId($issued['doorbell_id'])) ?></dd>
            <dt>親機用パスワード</dt>
            <dd><?= Http::h($issued['parent_password']) ?></dd>
            <dt>子機用パスワード</dt>
            <dd><?= Http::h($issued['child_password']) ?></dd>
          </dl>
          <p class="meta">
            <a href="<?= Http::h(detailUrl($issued['doorbell_id'])) ?>">このIDの詳細設定を開く</a>
          </p>
        </div>
      <?php endif; ?>

      <?php if ($deleted !== ''): ?>
        <p class="meta">ID <?= Http::h($deleted) ?> を削除しました。</p>
      <?php endif; ?>

      <?php $total = Doorbell::countIds(); $max = Config::int('max_ids_per_instance'); ?>
      <p class="meta">発行済み: <?= $total ?> / <?= $max ?> 件</p>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
        <input type="hidden" name="action" value="issue">
        <button type="submit" class="btn btn-primary" <?= $total >= $max ? 'disabled' : '' ?>>新しいIDを発行する</button>
      </form>

      <h2 class="section-title">発行済みID</h2>
      <?php $rows = Doorbell::listIds(); ?>
      <?php if ($rows === []): ?>
        <p class="meta">まだIDはありません。</p>
      <?php else: ?>
        <p class="meta">IDを選ぶと、子機URL・外部連携・削除を行う詳細設定を開きます。</p>
        <table>
          <thead>
            <tr>
              <th>ID</th><th>発行日時</th><th>親機</th><th>子機</th><th>子機URL</th><th>連携</th>
              <?php if (Config::int('id_lifetime') > 0): ?><th>自動失効</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><a href="<?= Http::h(detailUrl($row['raw_id'])) ?>"><?= Http::h($row['doorbell_id']) ?></a></td>
              <td><?= Http::h($row['created_at']) ?></td>
              <td><?= $row['parents'] > 0 ? '稼働中' : '—' ?></td>
              <td><?= $row['children'] > 0 ? '稼働中' : '—' ?></td>
              <td><?= $row['links'] > 0 ? $row['links'] . ' 件' : '—' ?></td>
              <td><?= $row['integrations'] > 0 ? $row['integrations'] . ' 件' : '—' ?></td>
              <?php if (Config::int('id_lifetime') > 0): ?>
                <td><?= $row['expires_in'] === null ? '—' : 'あと' . Http::h(Doorbell::duration($row['expires_in'])) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <p class="warn-text">※ パスワードはハッシュ化して保存されるため、発行時以外は確認できません。</p>

      <?php if (Config::int('id_lifetime') > 0): ?>
        <p class="warn-text">
          ※ 発行から <?= Http::h(Doorbell::duration(Config::int('id_lifetime'))) ?> が経過したIDは、
          関連する端末・履歴・子機URL・外部連携とともに自動的に削除されます。
        </p>
      <?php endif; ?>
      <?php if (Config::int('deletion_grace_seconds') > 0): ?>
        <p class="warn-text">
          ※ 発行から <?= Http::h(Doorbell::duration(Config::int('deletion_grace_seconds'))) ?> のあいだは
          削除・失効できません。
        </p>
      <?php endif; ?>

      <form method="post" style="margin-top:20px">
        <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn">ログアウト</button>
      </form>

    <?php else: ?>
      <?php /* ---------------- 詳細画面 ---------------- */ ?>
      <p class="meta"><a href="admin.php">← 発行済みIDの一覧へ</a></p>
      <h1 class="title">ID <?= Http::h($detail['doorbell_id']) ?></h1>

      <?php if ($error !== ''): ?>
        <p class="error"><?= Http::h($error) ?></p>
      <?php endif; ?>

      <dl class="summary">
        <dt>発行日時</dt><dd><?= Http::h($detail['created_at']) ?></dd>
        <dt>発行元IP</dt><dd><?= Http::h($detail['created_ip']) ?></dd>
        <dt>親機</dt><dd><?= $detail['parents'] > 0 ? '稼働中' : '—' ?></dd>
        <dt>子機</dt><dd><?= $detail['children'] > 0 ? '稼働中' : '—' ?></dd>
        <?php if ($detail['expires_in'] !== null): ?>
          <dt>自動失効まで</dt><dd>あと<?= Http::h(Doorbell::duration($detail['expires_in'])) ?></dd>
        <?php endif; ?>
      </dl>

      <?php /* ---- 子機URL ---- */ ?>
      <h2 class="section-title">子機URL</h2>
      <?php $links = Doorbell::listChildLinks($detail['raw_id']); ?>
      <?php if (!$linkEnabled): ?>
        <p class="meta">
          この機能は無効（<code>child_link_login</code> が false）です。
          <?= $links === [] ? '' : '以下のURLではログインできません。' ?>
        </p>
      <?php else: ?>
        <p class="meta">
          開くだけで子機になるURLを発行します。端末のホームURLに設定して使ってください。
        </p>
        <form method="post" action="<?= Http::h($formUrl) ?>" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
          <input type="hidden" name="action" value="child_link">
          <input type="hidden" name="doorbell_id" value="<?= Http::h($detail['raw_id']) ?>">
          <label class="field">
            <span class="field-label">子機の表示名</span>
            <input type="text" name="display_name" maxlength="32" placeholder="玄関 / 受付 など" required>
          </label>
          <button type="submit" class="btn btn-primary">子機URLを発行する</button>
        </form>
      <?php endif; ?>

      <?php if ($issuedLink !== null): ?>
        <?php $linkUrl = Http::baseUrl() . 'index.php?child=' . $issuedLink['token']; ?>
        <div class="credentials">
          <p class="meta">
            子機URLを発行しました（表示名 <?= Http::h($issuedLink['display_name']) ?>）。
            <?= $mask ? '<strong>この画面を離れると伏せ字になります。</strong>' : '' ?>
          </p>
          <p class="link-url" id="issued-link"><?= Http::h($linkUrl) ?></p>
          <button type="button" class="btn btn-quiet" data-copy="issued-link">URLをコピー</button>
          <p class="warn-text">
            ※ このURLを知っている人は誰でもこの子機として呼び出せます。取り扱いに注意してください。
          </p>
        </div>
      <?php endif; ?>

      <?php if ($links === []): ?>
        <p class="meta">まだ子機URLはありません。</p>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>表示名</th><th>発行日時</th><th>最終利用</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($links as $link): ?>
            <?php
              $shown = $mask ? Http::mask($link['token']) : $link['token'];
              $url   = Http::baseUrl() . 'index.php?child=' . $shown;
              $note  = lockNote($link['lockedFor']);
            ?>
            <tr>
              <td><?= Http::h($link['displayName']) ?></td>
              <td><?= Http::h($link['createdAt']) ?></td>
              <td><?= Http::h($link['lastUsedAt']) ?></td>
              <td class="row-actions">
                <?php if (!$mask): ?>
                  <button type="button" class="btn btn-quiet" data-copy="url-<?= Http::h($link['token']) ?>">コピー</button>
                <?php endif; ?>
                <form method="post" action="<?= Http::h($formUrl) ?>"
                      onsubmit="return confirm('この子機URLを失効させます。よろしいですか？');">
                  <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
                  <input type="hidden" name="action" value="child_link_delete">
                  <input type="hidden" name="token" value="<?= Http::h($link['token']) ?>">
                  <button type="submit" class="btn btn-quiet" <?= $note === '' ? '' : 'disabled' ?>>
                    失効<?= $note === '' ? '' : '（' . Http::h($note) . '）' ?>
                  </button>
                </form>
              </td>
            </tr>
            <tr>
              <td colspan="4" class="link-url" id="url-<?= Http::h($link['token']) ?>"><?= Http::h($url) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="warn-text">
          ※ 子機URLはそれ自体がログイン情報です。URLを知っている人は誰でもこの子機として呼び出せます。
          <?= $mask ? '発行した直後以外は伏せ字で表示します。' : '' ?>
        </p>
      <?php endif; ?>

      <?php /* ---- 外部連携 ---- */ ?>
      <h2 class="section-title">外部連携</h2>
      <?php $hooks = Integration::listAll($detail['raw_id']); ?>
      <?php if (!$hookEnabled): ?>
        <p class="meta">
          この機能は無効（<code>integration_api</code> が false）です。
          <?= $hooks === [] ? '' : '以下の連携は動作しません。' ?>
        </p>
      <?php else: ?>
        <p class="meta">
          呼び出しを外部サービスへ通知し、外部から応答を返すための連携です。
          通知先URLに Slack の Incoming Webhook をそのまま指定できます（空にすると受信専用）。
        </p>
        <form method="post" action="<?= Http::h($formUrl) ?>" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
          <input type="hidden" name="action" value="integration">
          <input type="hidden" name="doorbell_id" value="<?= Http::h($detail['raw_id']) ?>">
          <label class="field">
            <span class="field-label">連携の名前</span>
            <input type="text" name="label" maxlength="32" placeholder="Slack #受付 など" required>
          </label>
          <label class="field">
            <span class="field-label">通知先URL（任意）</span>
            <input type="url" name="webhook_url" maxlength="512" placeholder="https://hooks.slack.com/services/…">
          </label>
          <button type="submit" class="btn btn-primary">連携を発行する</button>
        </form>
      <?php endif; ?>

      <?php if ($issuedHook !== null): ?>
        <div class="credentials">
          <p class="meta">
            連携を発行しました（名前 <?= Http::h($issuedHook['label']) ?>）。
            <strong>トークンと署名シークレットは再表示できません。</strong>必ず控えてください。
          </p>
          <dl>
            <dt>エンドポイント</dt>
            <dd class="link-url" id="hook-endpoint"><?= Http::h(Http::baseUrl() . 'integration.php') ?></dd>
            <dt>連携トークン</dt>
            <dd class="link-url" id="hook-token"><?= Http::h($issuedHook['token']) ?></dd>
            <dt>署名シークレット</dt>
            <dd class="link-url" id="hook-secret"><?= Http::h($issuedHook['secret']) ?></dd>
          </dl>
          <button type="button" class="btn btn-quiet" data-copy="hook-token">トークンをコピー</button>
          <button type="button" class="btn btn-quiet" data-copy="hook-secret">シークレットをコピー</button>
          <p class="warn-text">
            ※ トークンを知っている相手は、このIDの呼び出し状況の取得と応答ができます。
          </p>
        </div>
      <?php endif; ?>

      <?php if ($hooks === []): ?>
        <p class="meta">まだ連携はありません。</p>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>名前</th><th>通知先</th><th>発行日時</th><th>最終利用</th><th>送信待ち</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($hooks as $hook): ?>
            <?php $note = lockNote($hook['lockedFor']); ?>
            <tr>
              <td><?= Http::h($hook['label']) ?></td>
              <td class="link-url">
                <?php if ($hook['webhookUrl'] === ''): ?>
                  —（受信のみ）
                <?php else: ?>
                  <?= Http::h($mask ? Http::maskUrl($hook['webhookUrl']) : $hook['webhookUrl']) ?>
                <?php endif; ?>
              </td>
              <td><?= Http::h($hook['createdAt']) ?></td>
              <td><?= Http::h($hook['lastUsedAt']) ?></td>
              <td><?= $hook['pending'] > 0 ? $hook['pending'] . ' 件' : '—' ?></td>
              <td class="row-actions">
                <form method="post" action="<?= Http::h($formUrl) ?>"
                      onsubmit="return confirm('この連携を失効させます。よろしいですか？');">
                  <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
                  <input type="hidden" name="action" value="integration_delete">
                  <input type="hidden" name="integration_id" value="<?= (int) $hook['id'] ?>">
                  <button type="submit" class="btn btn-quiet" <?= $note === '' ? '' : 'disabled' ?>>
                    失効<?= $note === '' ? '' : '（' . Http::h($note) . '）' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="warn-text">
          ※ 連携トークンはハッシュ化して保存されるため、発行時以外は確認できません。
          紛失した場合は失効させて発行し直してください。
        </p>
      <?php endif; ?>

      <?php /* ---- 削除 ---- */ ?>
      <h2 class="section-title">このIDを削除する</h2>
      <p class="meta">
        端末・応答履歴・子機URL・外部連携もすべて削除されます。元に戻せません。
      </p>
      <?php $deleteNote = lockNote($detail['locked_for']); ?>
      <form method="post" action="admin.php"
            onsubmit="return confirm('ID <?= Http::h($detail['doorbell_id']) ?> を削除します。よろしいですか？');">
        <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="doorbell_id" value="<?= Http::h($detail['raw_id']) ?>">
        <button type="submit" class="btn" <?= $deleteNote === '' ? '' : 'disabled' ?>>
          このIDを削除<?= $deleteNote === '' ? '' : '（' . Http::h($deleteNote) . '）' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<script src="assets/admin.js?v=3"></script>
</body>
</html>
