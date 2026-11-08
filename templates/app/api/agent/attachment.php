<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

agent_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        json_out(400, ['error' => 'No file received.']);
    }
    $jobId = (int) ($_POST['job_id'] ?? 0);
    $job = job_get($jobId);
    if ($job === null) {
        json_out(404, ['error' => 'Job not found.']);
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_out(400, ['error' => 'Upload failed with code ' . (int) $file['error']]);
    }

    $config = app_config();
    if ((int) $file['size'] > $config['max_upload_mb'] * 1024 * 1024) {
        json_out(413, ['error' => 'File too large.']);
    }

    $name = safe_name((string) $file['name']);
    $mime = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file['tmp_name']);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }

    $install = $config['install_id'] !== '' ? $config['install_id'] : 'local';
    $dir = storage_dir('attachments/' . $install);
    $stored = $dir . '/' . bin2hex(random_bytes(8)) . '_' . $name;
    if (!move_uploaded_file($file['tmp_name'], $stored)) {
        json_out(500, ['error' => 'Cannot store the file.']);
    }

    $relative = 'storage/attachments/' . $install . '/' . basename($stored);
    $id = attachment_add([
        'job_id' => $jobId,
        'user_id' => (int) $job['user_id'],
        'name' => $name,
        'mime' => $mime,
        'size' => (int) $file['size'],
        'sha256' => hash_file('sha256', $stored),
        'path' => $relative,
        'direction' => 'agent',
    ]);

    json_out(200, [
        'ok' => true,
        'id' => $id,
        'name' => $name,
        'url' => 'api/file.php?id=' . $id,
    ]);
}

$id = (int) ($_GET['id'] ?? 0);
$attachment = attachment_get($id);
if ($attachment === null) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$absolute = realpath(APP_ROOT . '/' . $attachment['path']);
$storage = realpath(APP_STORAGE);
if ($absolute === false || $storage === false || !str_starts_with($absolute, $storage) || !is_file($absolute)) {
    http_response_code(404);
    echo 'File missing';
    exit;
}

header('Content-Type: ' . $attachment['mime']);
header('Content-Length: ' . filesize($absolute));
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $attachment['name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($absolute);
