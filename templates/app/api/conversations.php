<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/store.php';

$user = require_user_json();
$config = app_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    $title = is_array($body) ? trim((string) ($body['title'] ?? '')) : '';
    if ($title === '') {
        $title = 'New conversation';
    }
    $conversation = conversation_create((int) $user['id'], $title);
    json_out(200, ['ok' => true, 'conversation' => $conversation]);
}

$id = trim((string) ($_GET['id'] ?? ''));
if ($id !== '') {
    $conversation = conversation_get($id, (int) $user['id']);
    if ($conversation === null) {
        json_out(404, ['error' => 'Conversation not found.']);
    }
    $messages = [];
    foreach (messages_for($id) as $row) {
        $messages[] = [
            'id' => (int) $row['id'],
            'role' => $row['role'],
            'content' => $row['content'],
        ];
    }
    json_out(200, ['ok' => true, 'conversation' => $conversation, 'messages' => $messages]);
}

json_out(200, ['ok' => true, 'conversations' => conversation_list((int) $user['id'])]);
