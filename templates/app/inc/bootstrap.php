<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_STORAGE', APP_ROOT . '/storage');

require __DIR__ . '/env.php';
require __DIR__ . '/db.php';

function base_path(): string
{
    $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = str_replace('\\', '/', APP_ROOT);
    if ($documentRoot !== '' && str_starts_with($appRoot, $documentRoot)) {
        return substr($appRoot, strlen($documentRoot));
    }
    return '';
}

function url(string $path): string
{
    return base_path() . '/' . ltrim($path, '/');
}

$envFile = APP_ROOT . '/.env';
if (!is_file($envFile)) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (!str_ends_with($script, '/install.php')) {
        header('Location: ' . url('install.php'));
        exit;
    }
} else {
    env_load($envFile);
}

$savePath = APP_STORAGE . '/sessions';
if (!is_dir($savePath)) {
    @mkdir($savePath, 0770, true);
}
if (is_dir($savePath) && is_writable($savePath)) {
    session_save_path($savePath);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('webchat');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function json_out(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function app_config(): array
{
    return [
        'app_name' => envv('WEBCHAT_APP_NAME', 'Hermes Webchat'),
        'public_url' => envv('WEBCHAT_PUBLIC_URL'),
        'session_hours' => max(1, (int) envv('WEBCHAT_SESSION_HOURS', '8')),
        'max_upload_mb' => max(1, (int) envv('WEBCHAT_MAX_UPLOAD_MB', '25')),
        'max_message_chars' => max(500, (int) envv('WEBCHAT_MAX_MESSAGE_CHARS', '8000')),
        'default_quota' => envv('WEBCHAT_DEFAULT_QUOTA'),
        'approval_seconds' => max(30, (int) envv('WEBCHAT_APPROVAL_SECONDS', '280')),
        'install_id' => envv('WEBCHAT_INSTALL_ID'),
    ];
}

function db_user_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $user = db_user_by_id((int) $_SESSION['user_id']);
    } catch (Throwable $e) {
        return null;
    }
    if (!$user || (int) $user['active'] !== 1) {
        return null;
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect(url('login.php'));
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    return $user;
}

function require_user_json(): array
{
    $user = current_user();
    if ($user === null) {
        json_out(401, ['error' => 'Session expired. Sign in again.']);
    }
    return $user;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        json_out(403, ['error' => 'Session refreshed. Reload the page.', 'code' => 'csrf']);
    }
}

function remaining_quota(array $user): ?int
{
    if ($user['quota'] === null || $user['quota'] === '') {
        return null;
    }
    return max(0, (int) $user['quota'] - (int) $user['used_tokens']);
}

function format_tokens(int $value): string
{
    if ($value >= 1000000) {
        return number_format($value / 1000000, 1, ',', '.') . 'M';
    }
    if ($value >= 1000) {
        return number_format($value / 1000, 1, ',', '.') . 'k';
    }
    return (string) $value;
}
