<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

agent_auth();

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    json_out(400, ['error' => 'Invalid JSON body.']);
}

$jobId = (int) ($body['job_id'] ?? 0);
$text = (string) ($body['text'] ?? '');
$status = (string) ($body['status'] ?? 'done');
$error = isset($body['error']) ? mb_substr((string) $body['error'], 0, 500) : null;
$usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

if ($jobId <= 0) {
    json_out(400, ['error' => 'job_id is required.']);
}

$job = job_get($jobId);
if ($job === null) {
    json_out(404, ['error' => 'Job not found.']);
}

job_reply($jobId, $text, $usage, $status === 'error' ? 'error' : 'done', $error);

json_out(200, ['ok' => true]);
