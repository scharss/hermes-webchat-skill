<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/agent.php';

$user = require_user_json();
require_csrf();

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    json_out(400, ['error' => 'Invalid JSON body.']);
}

$approvalId = (int) ($body['approval_id'] ?? 0);
$decision = (string) ($body['decision'] ?? '');

if ($approvalId <= 0 || !in_array($decision, ['approved', 'denied'], true)) {
    json_out(400, ['error' => 'Invalid approval request.']);
}

if (!approval_resolve($approvalId, $decision, (int) $user['id'])) {
    json_out(404, ['error' => 'Approval not found or already resolved.']);
}

json_out(200, ['ok' => true, 'decision' => $decision]);
