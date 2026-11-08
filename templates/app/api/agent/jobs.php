<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

agent_auth();

$wait = min(25, max(0, (int) ($_GET['wait'] ?? 0)));
$deadline = microtime(true) + $wait;

do {
    $job = job_claim();
    if ($job !== null) {
        $payload = job_payload($job);
        json_out(200, [
            'ok' => true,
            'job' => [
                'id' => (int) $job['id'],
                'conversation_id' => $job['conversation_id'],
                'messages' => $payload['messages'] ?? [],
                'uploads' => $payload['uploads'] ?? [],
                'created_at' => $job['created_at'],
            ],
        ]);
    }

    if (microtime(true) >= $deadline) {
        break;
    }
    usleep(900000);
} while (true);

json_out(200, ['ok' => true, 'job' => null]);
