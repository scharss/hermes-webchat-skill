<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/agent.php';

$admin = require_admin();
$config = app_config();
$flash = $_SESSION['admin_flash'] ?? '';
$flashType = $_SESSION['admin_flash_type'] ?? 'ok';
unset($_SESSION['admin_flash'], $_SESSION['admin_flash_type']);
$newToken = $_SESSION['new_token'] ?? '';
unset($_SESSION['new_token']);

function admin_flash(string $message, string $type = 'ok'): never
{
    $_SESSION['admin_flash'] = $message;
    $_SESSION['admin_flash_type'] = $type;
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''))) {
        admin_flash('The form expired. Reload the page.', 'error');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $quotaRaw = trim((string) ($_POST['quota'] ?? ''));
        $quota = $quotaRaw === '' ? null : max(0, (int) $quotaRaw);

        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            admin_flash('Check username, email and password (min. 8).', 'error');
        }

        try {
            db()->prepare(
                'INSERT INTO users (username, email, password_hash, role, quota) VALUES (?, ?, ?, ?, ?)'
            )->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $quota]);
            admin_flash('User created.');
        } catch (Throwable $error) {
            admin_flash('Could not create the user: ' . $error->getMessage(), 'error');
        }
    }

    if ($action === 'toggle_user') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if ($id !== (int) $admin['id']) {
            db()->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$id]);
            admin_flash('User updated.');
        } else {
            admin_flash('You cannot deactivate your own account.', 'error');
        }
    }

    if ($action === 'update_quota') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $quotaRaw = trim((string) ($_POST['quota'] ?? ''));
        $quota = $quotaRaw === '' ? null : max(0, (int) $quotaRaw);
        db()->prepare('UPDATE users SET quota = ? WHERE id = ?')->execute([$quota, $id]);
        admin_flash('Quota updated.');
    }

    if ($action === 'rotate_token') {
        agent_token_revoke_all();
        $created = agent_token_create('Hermes');
        $_SESSION['new_token'] = $created['token'];
        admin_flash('New token generated. Copy it now: it will not be shown again.');
    }

    admin_flash('Unknown action.', 'error');
}

$users = db()->query(
    'SELECT id, username, email, role, quota, used_tokens, active, created_at FROM users ORDER BY id ASC'
)->fetchAll();
$agent = agent_status();
$lastPrefix = db()->query(
    'SELECT token_prefix, last_seen_at FROM agent_tokens WHERE revoked_at IS NULL ORDER BY id DESC LIMIT 1'
)->fetch();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#FFC107">
<title>Admin - <?= h($config['app_name']) ?></title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #0b0f14; color: #e7eef5; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .head { display: flex; align-items: center; gap: 14px; padding: 16px 24px; border-bottom: 1px solid #1a2430; }
  .mark { width: 38px; height: 38px; border-radius: 11px; background: #FFC107; color: #101418; font-weight: 800; display: grid; place-items: center; }
  h1 { font-size: 18px; margin: 0; }
  .head nav { margin-left: auto; display: flex; gap: 14px; font-size: 13px; }
  a { color: #FFC107; text-decoration: none; }
  main { max-width: 1040px; margin: 0 auto; padding: 24px; }
  h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .05em; color: #8a99a8; margin: 26px 0 10px; }
  .card { background: #121a23; border: 1px solid #232f3c; border-radius: 12px; padding: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #1a2430; }
  th { color: #8a99a8; font-weight: 600; }
  .pill { padding: 2px 8px; border-radius: 999px; font-size: 11px; border: 1px solid #232f3c; }
  .ok { color: #7ee787; border-color: rgba(126,231,135,.35); }
  .off { color: #ffb3b0; border-color: rgba(248,81,73,.35); }
  form { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; }
  label { display: grid; gap: 5px; font-size: 12px; color: #b7c4d1; }
  input, select { padding: 8px 10px; border-radius: 8px; border: 1px solid #232f3c; background: #0f151c; color: #e7eef5; font: inherit; }
  button { padding: 8px 14px; border: 0; border-radius: 8px; background: #FFC107; color: #101418; font: inherit; font-weight: 700; cursor: pointer; }
  button.ghost { background: #16202b; color: #e7eef5; border: 1px solid #232f3c; }
  .flash { padding: 10px 12px; border-radius: 9px; margin-bottom: 14px; font-size: 13px; }
  .flash.ok { background: rgba(126,231,135,.1); border: 1px solid rgba(126,231,135,.35); color: #7ee787; }
  .flash.error { background: rgba(248,81,73,.12); border: 1px solid rgba(248,81,73,.4); color: #ffb3b0; }
  code { background: #0f151c; padding: 2px 6px; border-radius: 5px; font-size: 12px; word-break: break-all; }
  .inline { display: inline; }
</style>
</head>
<body>
<header class="head">
  <div class="mark">H</div>
  <h1>Admin - <?= h($config['app_name']) ?></h1>
  <nav>
    <a href="../index.php">Back to chat</a>
    <a href="../logout.php">Sign out</a>
  </nav>
</header>

<main>
  <?php if ($flash !== ''): ?>
    <div class="flash <?= h($flashType) ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <?php if ($newToken !== ''): ?>
    <div class="card" style="margin-bottom:14px">
      <strong>Agent token (shown once)</strong>
      <p><code><?= h($newToken) ?></code></p>
      <p style="color:#8a99a8;font-size:13px">Paste it into the bridge as <code>WEBCHAT_TOKEN</code>.</p>
    </div>
  <?php endif; ?>

  <h2>Agent</h2>
  <div class="card">
    <p style="margin:0 0 10px">
      Status:
      <span class="pill <?= $agent['connected'] ? 'ok' : 'off' ?>">
        <?= $agent['connected'] ? 'Connected' : 'Not connected' ?>
      </span>
      <?php if ($agent['last_seen_at']): ?>
        <span style="color:#8a99a8;font-size:12px">last heartbeat <?= h($agent['last_seen_at']) ?></span>
      <?php endif; ?>
    </p>
    <p style="margin:0 0 10px;color:#8a99a8;font-size:12px">
      Pending jobs: <?= (int) $agent['pending'] ?>
      <?php if ($lastPrefix): ?> · current token <?= h($lastPrefix['token_prefix']) ?>...<?php endif; ?>
    </p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="rotate_token">
      <button type="submit">Rotate token</button>
    </form>
  </div>

  <h2>Users</h2>
  <div class="card">
    <table>
      <thead>
        <tr><th>ID</th><th>User</th><th>Email</th><th>Role</th><th>Quota</th><th>Used</th><th>Active</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $row): ?>
        <tr>
          <td><?= (int) $row['id'] ?></td>
          <td><?= h($row['username']) ?></td>
          <td><?= h($row['email']) ?></td>
          <td><?= h($row['role']) ?></td>
          <td><?= $row['quota'] === null ? 'Unlimited' : h(format_tokens((int) $row['quota'])) ?></td>
          <td><?= h(format_tokens((int) $row['used_tokens'])) ?></td>
          <td><?= (int) $row['active'] === 1 ? 'Yes' : 'No' ?></td>
          <td>
            <form method="post" class="inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="toggle_user">
              <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
              <button class="ghost" type="submit"><?= (int) $row['active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form method="post" class="inline">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_quota">
              <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
              <input name="quota" placeholder="tokens or empty" style="width:130px">
              <button class="ghost" type="submit">Quota</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2>Create user</h2>
  <div class="card">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_user">
      <label>Username <input name="username" required></label>
      <label>Email <input type="email" name="email" required></label>
      <label>Password <input type="password" name="password" minlength="8" required></label>
      <label>Role
        <select name="role">
          <option value="user">user</option>
          <option value="admin">admin</option>
        </select>
      </label>
      <label>Quota (empty = unlimited) <input name="quota" placeholder="1000000"></label>
      <button type="submit">Create</button>
    </form>
  </div>
</main>
</body>
</html>
