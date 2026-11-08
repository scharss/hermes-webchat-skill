<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/store.php';
require __DIR__ . '/inc/agent.php';

$user = require_login();
$config = app_config();
$conversations = conversation_list((int) $user['id']);
$remaining = remaining_quota($user);

$initialConversation = $conversations[0]['id'] ?? '';
$version = static fn (string $file): int => (int) @filemtime(__DIR__ . '/' . $file);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#FFC107">
<title><?= h($config['app_name']) ?></title>
<link rel="stylesheet" href="assets/styles.css?v=<?= $version('assets/styles.css') ?>">
</head>
<body
  data-initial="<?= h($initialConversation) ?>"
  data-max-chars="<?= (int) $config['max_message_chars'] ?>"
  data-max-upload-mb="<?= (int) $config['max_upload_mb'] ?>"
  data-user-id="<?= (int) $user['id'] ?>"
  data-csrf="<?= h(csrf_token()) ?>"
>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="mark">H</div>
      <div>
        <strong><?= h($config['app_name']) ?></strong>
        <span>Web chat</span>
      </div>
    </div>

    <button class="new-chat" id="newChat" type="button">+ New conversation</button>

    <nav class="conversations" id="conversations">
      <?php foreach ($conversations as $conversation): ?>
        <button class="conv" type="button" data-id="<?= h($conversation['id']) ?>">
          <?= h($conversation['title']) ?>
        </button>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
      <div class="agent" id="agentStatus"><span class="dot"></span> Checking agent...</div>
      <div class="quota">
        <span>Balance</span>
        <strong><?= $remaining === null ? 'Unlimited' : h(format_tokens($remaining)) ?></strong>
      </div>
      <div class="user-row">
        <span class="avatar"><?= h(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></span>
        <span class="username"><?= h($user['username']) ?></span>
        <?php if ($user['role'] === 'admin'): ?>
          <a class="icon-link" href="admin/" title="Admin">&#9881;</a>
        <?php endif; ?>
        <a class="icon-link" href="logout.php" title="Sign out">&#9099;</a>
      </div>
    </div>
  </aside>

  <main class="chat">
    <header class="chat-head">
      <button class="menu" id="menuBtn" type="button" aria-label="Menu">&#9776;</button>
      <h1 id="chatTitle">New conversation</h1>
    </header>

    <div class="messages" id="messages"></div>

    <div class="composer">
      <div class="pending" id="pending"></div>
      <div class="composer-box">
        <button class="attach" id="attachBtn" type="button" title="Attach file">&#128206;</button>
        <input type="file" id="fileInput" multiple hidden>
        <textarea id="input" rows="1" maxlength="<?= (int) $config['max_message_chars'] ?>" placeholder="Write your message..."></textarea>
        <button class="send" id="sendBtn" type="button" title="Send">&#10148;</button>
      </div>
      <div class="hint">Enter to send · Shift+Enter for a new line</div>
    </div>
  </main>
</div>

<script src="assets/app.js?v=<?= $version('assets/app.js') ?>"></script>
</body>
</html>
