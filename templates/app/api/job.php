<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/store.php';
require __DIR__ . '/../inc/agent.php';

$user = require_user_json();

$jobId = (int) ($_GET['job_id'] ?? 0);
$offset = (int) ($_GET['offset'] ?? 0);
if ($jobId <= 0) {
    json_out(400, ['error' => 'Missing job_id.']);
}

$job = job_get($jobId);
if ($job === null || (int) $job['user_id'] !== (int) $user['id']) {
    json_out(404, ['error' => 'Job not found.']);
}

$stmt = db()->prepare(
    'SELECT id, content FROM job_chunks WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT 200'
);
$stmt->execute([$jobId, $offset]);
$chunks = $stmt->fetchAll();

$delta = '';
foreach ($chunks as $chunk) {
    $delta .= $chunk['content'];
    $offset = (int) $chunk['id'];
}

if ($delta === '' && $offset === 0 && $job['status'] === 'done' && $job['response']) {
    $delta = (string) $job['response'];
}

$approval = approval_pending($jobId);
$attachments = [];
foreach (attachments_for_job($jobId) as $file) {
    if ($file['direction'] !== 'agent') {
        continue;
    }
    $attachments[] = [
        'id' => (int) $file['id'],
        'name' => $file['name'],
        'mime' => $file['mime'],
        'size' => (int) $file['size'],
        'url' => 'api/file.php?id=' . (int) $file['id'],
    ];
}

json_out(200, [
    'ok' => true,
    'job_id' => $jobId,
    'conversation_id' => $job['conversation_id'],
    'status' => $job['status'],
    'delta' => $delta,
    'offset' => $offset,
    'error' => $job['error'],
    'approval' => $approval ? [
        'id' => (int) $approval['id'],
        'description' => $approval['description'],
    ] : null,
    'attachments' => $attachments,
]);
