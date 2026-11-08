<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/agent.php';

$user = require_user_json();

json_out(200, [
    'ok' => true,
    'csrf' => csrf_token(),
    'user' => [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
    ],
    'quota' => remaining_quota($user),
    'agent' => agent_status(),
]);
