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
$type = (string) ($body['type'] ?? 'progress');
$runId = (string) ($body['run_id'] ?? '');
$description = (string) ($body['description'] ?? '');

if ($jobId <= 0) {
    json_out(400, ['error' => 'job_id is required.']);
}

$job = job_get($jobId);
if ($job === null) {
    json_out(404, ['error' => 'Job not found.']);
}

if ($type === 'approval' && $runId !== '') {
    $id = approval_create($jobId, $runId, $description !== '' ? $description : 'Approval required');
    json_out(200, ['ok' => true, 'approval_id' => $id]);
}

json_out(200, ['ok' => true]);
