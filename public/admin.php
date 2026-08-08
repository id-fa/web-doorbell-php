<?php
/**
 * ID発行管理画面（要パスワード）。
 * ID・親機用パスワード・子機用パスワードを自動発行する。
 */

declare(strict_types=1);

namespace Doorbell;

require __DIR__ . '/../src/bootstrap.php';

Http::startSession();

$ip          = Http::clientIp();
$limits      = (array) Config::get('rate_limit');
$error       = '';
$issued      = null;
$deleted     = '';
$isLoggedIn  = ($_SESSION['admin_authenticated'] ?? false) === true;

try {
    Http::rateLimit('req', $ip, (int) $limits['max_requests']);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Http::requireCsrf();
        $action = (string) ($_POST['action'] ?? '');

        switch ($action) {
            case 'login':
                Http::rateLimit('login', $ip, (int) $limits['max_logins']);
                $password = (string) ($_POST['password'] ?? '');
                if (hash_equals((string) Config::get('admin_password'), $password)) {
                    session_regenerate_id(true);
                    $_SESSION['admin_authenticated'] = true;
                    $isLoggedIn = true;
                    Http::rateLimitRelease('login', $ip);
                } else {
                    $error = 'パスワードが正しくありません。';
                }
                break;

            case 'logout':
                unset($_SESSION['admin_authenticated']);
                $isLoggedIn = false;
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
<link rel="stylesheet" href="assets/style.css?v=3">
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
              <td>
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

      <form method="post" style="margin-top:20px">
        <input type="hidden" name="csrf_token" value="<?= Http::h($csrf) ?>">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn">ログアウト</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
