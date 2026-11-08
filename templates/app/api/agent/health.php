<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

agent_auth();

$config = app_config();

json_out(200, [
    'ok' => true,
    'app' => $config['app_name'],
    'pending' => (int) db()->query("SELECT COUNT(*) FROM jobs WHERE status = 'queued'")->fetchColumn(),
    'server_time' => date('c'),
]);
