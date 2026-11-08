<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/store.php';
require __DIR__ . '/../inc/agent.php';

$user = require_user_json();
require_csrf();

$config = app_config();
$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    json_out(400, ['error' => 'Invalid JSON body.']);
}

$message = trim((string) ($body['message'] ?? ''));
$conversationId = trim((string) ($body['conversation_id'] ?? ''));
$uploadIds = is_array($body['upload_ids'] ?? null) ? $body['upload_ids'] : [];

if ($message === '') {
    json_out(400, ['error' => 'Message is empty.']);
}
if (mb_strlen($message) > $config['max_message_chars']) {
    json_out(400, ['error' => 'Message is too long.']);
}

$remaining = remaining_quota($user);
if ($remaining !== null && $remaining <= 0) {
    json_out(402, ['error' => 'Your token balance is empty. Contact the administrator.']);
}

if ($conversationId !== '') {
    $conversation = conversation_get($conversationId, (int) $user['id']);
    if ($conversation === null) {
        json_out(404, ['error' => 'Conversation not found.']);
    }
} else {
    $conversation = conversation_create((int) $user['id'], conversation_title($message));
}

$conversationId = $conversation['id'];
message_add($conversationId, 'user', $message);

$history = [];
foreach (messages_for($conversationId) as $row) {
    if (in_array($row['role'], ['user', 'assistant'], true)) {
        $history[] = ['role' => $row['role'], 'content' => $row['content']];
    }
}
$history = array_slice($history, -24);

$request = json_encode([
    'messages' => $history,
    'conversation_id' => $conversationId,
], JSON_UNESCAPED_UNICODE);

$stmt = db()->prepare(
    'INSERT INTO jobs (user_id, conversation_id, request_json) VALUES (?, ?, ?)'
);
$stmt->execute([(int) $user['id'], $conversationId, $request]);
$jobId = (int) db()->lastInsertId();

$uploads = uploads_for_user($uploadIds, (int) $user['id']);
if ($uploads) {
    $ids = array_map(static fn (array $file): int => (int) $file['id'], $uploads);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE attachments SET job_id = ? WHERE id IN ($placeholders)")
        ->execute([$jobId, ...$ids]);
}

$title = $conversation['title'];
if ($title === 'New conversation') {
    $title = conversation_title($message);
    db()->prepare('UPDATE conversations SET title = ? WHERE id = ?')->execute([$title, $conversationId]);
}

json_out(200, [
    'ok' => true,
    'job_id' => $jobId,
    'conversation_id' => $conversationId,
    'title' => $title,
]);
