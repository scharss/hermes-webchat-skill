<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/store.php';

$user = require_user_json();

$id = (int) ($_GET['id'] ?? 0);
$attachment = attachment_get($id);
if ($attachment === null) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

if ((int) $attachment['user_id'] !== (int) $user['id'] && $user['role'] !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$absolute = realpath(APP_ROOT . '/' . $attachment['path']);
$storage = realpath(APP_STORAGE);
if ($absolute === false || $storage === false || !str_starts_with($absolute, $storage) || !is_file($absolute)) {
    http_response_code(404);
    echo 'File missing';
    exit;
}

$name = $attachment['name'];
$ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

header('Content-Type: ' . $attachment['mime']);
header('Content-Length: ' . filesize($absolute));
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');

readfile($absolute);
