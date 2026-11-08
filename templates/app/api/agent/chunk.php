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
if ($jobId <= 0 || $text === '') {
    json_out(400, ['error' => 'job_id and text are required.']);
}

$job = job_get($jobId);
if ($job === null) {
    json_out(404, ['error' => 'Job not found.']);
}

job_chunk($jobId, $text);

json_out(200, ['ok' => true]);
