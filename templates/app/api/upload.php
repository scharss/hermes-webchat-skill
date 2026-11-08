<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/store.php';

$user = require_user_json();
require_csrf();
$config = app_config();

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_out(400, ['error' => 'No file received.']);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_out(400, ['error' => 'Upload failed with code ' . (int) $file['error']]);
}

$maxBytes = $config['max_upload_mb'] * 1024 * 1024;
if ((int) $file['size'] > $maxBytes) {
    json_out(413, ['error' => 'File exceeds ' . $config['max_upload_mb'] . ' MB.']);
}

$name = safe_name((string) $file['name']);
$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (in_array($extension, ['php', 'phtml', 'phar', 'cgi', 'pl', 'exe', 'bat', 'cmd', 'sh'], true)) {
    json_out(400, ['error' => 'File type not allowed.']);
}

$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($file['tmp_name']);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }
}

$install = $config['install_id'] !== '' ? $config['install_id'] : 'local';
$dir = storage_dir('uploads/' . $install);
$stored = $dir . '/' . bin2hex(random_bytes(8)) . '_' . $name;

if (!move_uploaded_file($file['tmp_name'], $stored)) {
    json_out(500, ['error' => 'Cannot store the file.']);
}

$relative = 'storage/uploads/' . $install . '/' . basename($stored);
$id = attachment_add([
    'job_id' => null,
    'user_id' => (int) $user['id'],
    'name' => $name,
    'mime' => $mime,
    'size' => (int) $file['size'],
    'sha256' => hash_file('sha256', $stored),
    'path' => $relative,
    'direction' => 'user',
]);

json_out(200, [
    'ok' => true,
    'id' => $id,
    'name' => $name,
    'size' => (int) $file['size'],
    'mime' => $mime,
]);
