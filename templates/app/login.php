<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

if (current_user() !== null) {
    redirect(url('index.php'));
}

$config = app_config();
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        $error = 'The form expired. Reload the page.';
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($error === '') {
        $stmt = db()->prepare(
            'SELECT * FROM users WHERE (email = ? OR username = ?) AND active = 1 LIMIT 1'
        );
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirect(url('index.php'));
        }
        $error = 'Wrong email or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#FFC107">
<title>Sign in - <?= h($config['app_name']) ?></title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0b0f14; color: #e7eef5; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding: 24px; }
  .card { width: min(400px, 100%); background: #121a23; border: 1px solid #232f3c; border-radius: 16px; padding: 26px; }
  .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
  .mark { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(145deg, #FFC107, #FFB300); color: #101418; font-weight: 800; font-size: 20px; display: grid; place-items: center; }
  h1 { margin: 0; font-size: 18px; }
  .sub { margin: 2px 0 0; color: #8a99a8; font-size: 12px; }
  label { display: grid; gap: 6px; margin: 12px 0; font-size: 13px; color: #b7c4d1; }
  input { width: 100%; padding: 11px 12px; border-radius: 10px; border: 1px solid #232f3c; background: #0f151c; color: #e7eef5; font: inherit; }
  input:focus { outline: none; border-color: #FFC107; box-shadow: 0 0 0 3px rgba(255,193,7,.14); }
  button { width: 100%; margin-top: 10px; padding: 11px 16px; border: 0; border-radius: 10px; background: #FFC107; color: #101418; font: inherit; font-weight: 700; cursor: pointer; }
  button:hover { background: #FFB300; }
  .error { background: rgba(248,81,73,.12); border: 1px solid rgba(248,81,73,.4); color: #ffb3b0; padding: 9px 12px; border-radius: 9px; font-size: 13px; margin-bottom: 8px; }
  .foot { margin-top: 14px; color: #8a99a8; font-size: 12px; text-align: center; }
</style>
</head>
<body>
<main class="card">
  <div class="brand">
    <div class="mark">H</div>
    <div>
      <h1><?= h($config['app_name']) ?></h1>
      <p class="sub">Sign in to continue</p>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <label>Email or username
      <input type="text" name="email" value="<?= h($email) ?>" required autofocus autocomplete="username">
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit">Sign in</button>
  </form>

  <p class="foot">Access restricted to authorized users.</p>
</main>
</body>
</html>
