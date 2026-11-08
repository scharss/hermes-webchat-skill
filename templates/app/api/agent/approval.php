<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

agent_auth();

$jobId = (int) ($_GET['job_id'] ?? 0);
$runId = trim((string) ($_GET['run_id'] ?? ''));

if ($jobId <= 0 || $runId === '') {
    json_out(400, ['error' => 'job_id and run_id are required.']);
}

json_out(200, [
    'ok' => true,
    'status' => approval_status($jobId, $runId),
]);
