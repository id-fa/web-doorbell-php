<?php
/**
 * ID発行管理画面（要パスワード）。
 * ID・親機用パスワード・子機用パスワードを自動発行する。
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

$ip          = Http::clientIp();
$limits      = (array) Config::get('rate_limit');
$issued      = null;
$deleted     = '';
$issuedLink  = null;
$linkEnabled = (bool) Config::get('child_link_login');

$hadSession = isset($_SESSION['admin_authenticated']);
$isLoggedIn = adminAuthenticated(); // 失効していれば、ここでセッションから取り除かれる
$error      = $hadSession && !$isLoggedIn
    ? 'ログインの有効期限が切れました。もう一度ログインしてください。'
    : '';

try {
    Http::rateLimit('req', $ip, (int) $limits['max_requests']);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Http::requireCsrf();
        $action = (string) ($_POST['action'] ?? '');

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
                break;

            case 'issue':
                if (!$isLoggedIn) {
                    throw new AppError('ログインしてください。', 'unauthenticated', 401);
                }
                $issued = Doorbell::issueId($ip);
                break;

            case 'delete':
                if (!$isLoggedIn) {
                    throw new AppError('ログインしてください。', 'unauthenticated', 401);
                }
                $target = (string) ($_POST['doorbell_id'] ?? '');
                $deleted = Doorbell::deleteId($target) ? Doorbell::formatId(Doorbell::normalizeId($target)) : '';
                if ($deleted === '') {
                    $error = '指定されたIDは見つかりませんでした。';
                }
                break;

            case 'child_link':
                if (!$isLoggedIn) {
                    throw new AppError('ログインしてください。', 'unauthenticated', 401);
                }
                if (!$linkEnabled) {
                    throw new AppError('子機URLの発行は無効です。', 'link_disabled', 403);
                }
                $issuedLink = Doorbell::createChildLink(
                    (string) ($_POST['doorbell_id'] ?? ''),
                    (string) ($_POST['display_name'] ?? ''),
                );
                break;

            case 'child_link_delete':
                if (!$isLoggedIn) {
                    throw new AppError('ログインしてください。', 'unauthenticated', 401);
                }
                if (!Doorbell::deleteChildLink((string) ($_POST['token'] ?? ''))) {
                    $error = '指定された子機URLは見つかりませんでした。';
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

$csrf = Http::csrfToken();

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
<title>ID発行管理 - ドアベル</title>
<link rel="stylesheet" href="assets/style.css?v=4">
</head>
<body>
<div id="app" class="admin">
  <div class="card">
    <h1 class="title">ID発行管理</h1>

    <?php if ($error !== ''): ?>
      <p class="error"><?= Http::h($error) ?></p>
    <?php endif; ?>

    <?php if (!$isLoggedIn): ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
        <input type="hidden" name="action" value="login">
        <label class="field">
          <span class="field-label">管理パスワード</span>
          <input type="password" name="password" maxlength="128" autocomplete="current-password" required autofocus>
        </label>
        <button type="submit" class="btn btn-primary">ログイン</button>
      </form>
    <?php else: ?>

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
        </div>
      <?php endif; ?>

      <?php if ($issuedLink !== null): ?>
        <?php $linkUrl = Http::baseUrl() . 'index.php?child=' . $issuedLink['token']; ?>
        <div class="credentials">
          <p class="meta">
            子機URLを発行しました（ID <?= Http::h(Doorbell::formatId($issuedLink['doorbell_id'])) ?> /
            表示名 <?= Http::h($issuedLink['display_name']) ?>）。
            このURLをブラウザのホームURLに設定すると、ログイン操作なしで子機になります。
          </p>
          <p class="link-url" id="issued-link"><?= Http::h($linkUrl) ?></p>
          <button type="button" class="btn btn-quiet" data-copy="issued-link">URLをコピー</button>
          <p class="warn-text">
            ※ このURLを知っている人は誰でもこの子機として呼び出せます。取り扱いに注意してください。
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
        <table>
          <thead>
            <tr>
              <th>ID</th><th>発行日時</th><th>発行元IP</th><th>親機</th><th>子機</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= Http::h($row['doorbell_id']) ?></td>
              <td><?= Http::h($row['created_at']) ?></td>
              <td><?= Http::h($row['created_ip']) ?></td>
              <td><?= $row['parents'] > 0 ? '稼働中' : '—' ?></td>
              <td><?= $row['children'] > 0 ? '稼働中' : '—' ?></td>
              <td class="row-actions">
                <?php if ($linkEnabled): ?>
                  <button type="button" class="btn btn-quiet" data-link-id="<?= Http::h($row['doorbell_id']) ?>">子機URL</button>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('ID <?= Http::h($row['doorbell_id']) ?> を削除します。よろしいですか？');">
                  <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="doorbell_id" value="<?= Http::h($row['doorbell_id']) ?>">
                  <button type="submit" class="btn btn-quiet">削除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <p class="warn-text">※ パスワードはハッシュ化して保存されるため、発行時以外は確認できません。</p>

      <?php $links = Doorbell::listChildLinks(); ?>
      <?php if ($linkEnabled || $links !== []): ?>
        <h2 class="section-title">子機URL</h2>
        <?php if (!$linkEnabled): ?>
          <p class="meta">
            現在この機能は無効（<code>child_link_login</code> が false）です。以下のURLではログインできません。
          </p>
        <?php endif; ?>
        <?php if ($links === []): ?>
          <p class="meta">まだ子機URLはありません。発行済みIDの「子機URL」から作成できます。</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th><th>表示名</th><th>発行日時</th><th>最終利用</th><th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($links as $link): ?>
              <?php $url = Http::baseUrl() . 'index.php?child=' . $link['token']; ?>
              <tr>
                <td><?= Http::h($link['doorbellId']) ?></td>
                <td><?= Http::h($link['displayName']) ?></td>
                <td><?= Http::h($link['createdAt']) ?></td>
                <td><?= Http::h($link['lastUsedAt']) ?></td>
                <td class="row-actions">
                  <button type="button" class="btn btn-quiet" data-copy="url-<?= Http::h($link['token']) ?>">コピー</button>
                  <form method="post" onsubmit="return confirm('この子機URLを失効させます。よろしいですか？');">
                    <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
                    <input type="hidden" name="action" value="child_link_delete">
                    <input type="hidden" name="token" value="<?= Http::h($link['token']) ?>">
                    <button type="submit" class="btn btn-quiet">失効</button>
                  </form>
                </td>
              </tr>
              <tr>
                <td colspan="5" class="link-url" id="url-<?= Http::h($link['token']) ?>"><?= Http::h($url) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="warn-text">
            ※ 子機URLはそれ自体がログイン情報です。URLを知っている人は誰でもこの子機として呼び出せます。
          </p>
        <?php endif; ?>
      <?php endif; ?>

      <form method="post" style="margin-top:20px">
        <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn">ログアウト</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($isLoggedIn && $linkEnabled): ?>
<dialog id="link-dialog" class="dialog">
  <form method="post">
    <h2 class="dialog-title">子機URLの発行</h2>
    <p class="meta">ID <strong id="link-target"></strong> の子機URLを発行します。</p>
    <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
    <input type="hidden" name="action" value="child_link">
    <input type="hidden" name="doorbell_id" id="link-doorbell-id" value="">
    <label class="field">
      <span class="field-label">子機の表示名</span>
      <input type="text" name="display_name" id="link-name" maxlength="32" placeholder="玄関 / 受付 など" required>
    </label>
    <div class="dialog-actions">
      <button type="button" class="btn" id="link-cancel">キャンセル</button>
      <button type="submit" class="btn btn-primary">発行する</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<script src="assets/admin.js?v=1"></script>
</body>
</html>
