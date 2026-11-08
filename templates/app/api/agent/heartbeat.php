<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

agent_auth();

json_out(200, ['ok' => true, 'time' => date('c')]);
