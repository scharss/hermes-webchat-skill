<?php
declare(strict_types=1);

/**
 * Hermes Webchat bridge.
 *
 * Pulls jobs from the web chat, runs them against Hermes (/v1/runs) and posts
 * chunks, approvals and attachments back. Single-threaded on purpose.
 */

const VERSION = '1.0.0';

function envv(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function logmsg(string $message, string $level = 'INFO'): void
{
    fwrite(STDOUT, sprintf("[%s] [%s] %s\n", date('c'), $level, $message));
}

final class TokenInvalid extends RuntimeException
{
}

$CONFIG = [
    'base' => rtrim(envv('WEBCHAT_BASE_URL'), '/'),
    'token' => envv('WEBCHAT_TOKEN'),
    'agent' => rtrim(envv('AGENT_API_URL', 'http://hermes:8642'), '/'),
    'agent_key' => envv('AGENT_API_KEY', envv('API_SERVER_KEY')),
    'poll_wait' => max(0, min(25, (int) envv('WEBCHAT_POLL_WAIT', '20'))),
    'heartbeat' => max(30, (int) envv('WEBCHAT_HEARTBEAT_SECONDS', '60')),
    'approval_seconds' => max(30, (int) envv('WEBCHAT_APPROVAL_SECONDS', '280')),
    'media_roots' => array_values(array_filter(array_map('trim', explode(',', envv('MEDIA_ROOTS', '/opt/data/exports'))))),
    'inbox' => envv('INBOX_DIR', '/opt/data/inbox'),
    'max_attachment_mb' => max(1, (int) envv('WEBCHAT_MAX_ATTACHMENT_MB', '25')),
];

if ($CONFIG['base'] === '' || $CONFIG['token'] === '' || $CONFIG['agent_key'] === '') {
    fwrite(STDERR, "Missing WEBCHAT_BASE_URL, WEBCHAT_TOKEN or AGENT_API_KEY.\n");
    exit(1);
}

function webchat_headers(): array
{
    global $CONFIG;
    return ['Accept: application/json', 'Authorization: Bearer ' . $CONFIG['token']];
}

function agent_headers(): array
{
    global $CONFIG;
    return ['Accept: application/json', 'Authorization: Bearer ' . $CONFIG['agent_key']];
}

function http_request(string $method, string $url, array $headers, ?string $body = null, int $timeout = 60): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

    return ['code' => $code, 'raw' => $raw, 'json' => is_array($json) ? $json : null, 'error' => $error];
}

function webchat_json(string $method, string $path, ?array $payload = null, int $timeout = 60): array
{
    global $CONFIG;
    $headers = webchat_headers();
    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    $res = http_request($method, $CONFIG['base'] . $path, $headers, $body, $timeout);
    if ($res['code'] === 401) {
        throw new TokenInvalid('Web chat rejected the token');
    }
    return $res;
}

function agent_json(string $method, string $path, ?array $payload = null, int $timeout = 60): array
{
    global $CONFIG;
    $headers = agent_headers();
    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    return http_request($method, $CONFIG['agent'] . $path, $headers, $body, $timeout);
}

function heartbeat_if_due(bool $force = false): void
{
    static $last = 0;
    global $CONFIG;
    if (!$force && (time() - $last) < $CONFIG['heartbeat']) {
        return;
    }
    $res = webchat_json('POST', '/api/agent/heartbeat.php', []);
    if ($res['code'] !== 200) {
        logmsg('Heartbeat failed: HTTP ' . $res['code'], 'WARN');
    }
    $last = time();
}

function post_chunk(int $jobId, string $text): void
{
    if ($text === '') {
        return;
    }
    $res = webchat_json('POST', '/api/agent/chunk.php', ['job_id' => $jobId, 'text' => $text]);
    if ($res['code'] !== 200) {
        logmsg('Chunk failed: HTTP ' . $res['code'], 'WARN');
    }
}

function post_reply(int $jobId, string $text, array $usage, string $status = 'done', ?string $error = null): void
{
    $res = webchat_json('POST', '/api/agent/reply.php', [
        'job_id' => $jobId,
        'text' => $text,
        'usage' => $usage,
        'status' => $status,
        'error' => $error,
    ]);
    if ($res['code'] !== 200) {
        logmsg('Reply failed: HTTP ' . $res['code'], 'ERROR');
    }
}

function post_event(int $jobId, string $type, string $runId, string $description = ''): ?int
{
    $res = webchat_json('POST', '/api/agent/event.php', [
        'job_id' => $jobId,
        'type' => $type,
        'run_id' => $runId,
        'description' => $description,
    ]);
    return isset($res['json']['approval_id']) ? (int) $res['json']['approval_id'] : null;
}

function fetch_job(): ?array
{
    global $CONFIG;
    $res = webchat_json('GET', '/api/agent/jobs.php?wait=' . $CONFIG['poll_wait'], null, $CONFIG['poll_wait'] + 15);
    if ($res['code'] !== 200) {
        logmsg('Cannot poll jobs: HTTP ' . $res['code'], 'WARN');
        sleep(5);
        return null;
    }
    $job = $res['json']['job'] ?? null;
    return is_array($job) ? $job : null;
}

function safe_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'file';
    return trim($name, '._- ') !== '' ? $name : 'file';
}

function download_uploads(int $jobId, array $uploads): array
{
    global $CONFIG;
    if (!$uploads) {
        return [];
    }
    $dir = rtrim($CONFIG['inbox'], '/') . '/job-' . $jobId;
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        logmsg('Cannot create inbox ' . $dir, 'ERROR');
        return [];
    }

    $paths = [];
    foreach ($uploads as $upload) {
        $url = (string) ($upload['url'] ?? '');
        if ($url === '') {
            continue;
        }
        $target = $dir . '/' . safe_name((string) ($upload['name'] ?? 'file'));
        $headers = webchat_headers();
        $ch = curl_init($CONFIG['base'] . '/' . ltrim($url, '/'));
        $fp = fopen($target, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok && $code === 200) {
            $paths[] = $target;
        } else {
            @unlink($target);
            logmsg('Upload download failed: HTTP ' . $code, 'WARN');
        }
    }
    return $paths;
}

function resolve_media(string $path): ?string
{
    global $CONFIG;
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        return null;
    }
    foreach ($CONFIG['media_roots'] as $root) {
        $rootReal = realpath($root);
        if ($rootReal !== false && str_starts_with($real, $rootReal)) {
            return $real;
        }
    }
    return null;
}

function upload_media(int $jobId, string $path): ?array
{
    global $CONFIG;
    $real = resolve_media($path);
    if ($real === null) {
        logmsg('Media outside allowed roots: ' . $path, 'WARN');
        return null;
    }
    $size = filesize($real);
    if ($size === false || $size > $CONFIG['max_attachment_mb'] * 1024 * 1024) {
        logmsg('Media too large: ' . $path, 'WARN');
        return null;
    }

    $headers = webchat_headers();
    $post = [
        'job_id' => (string) $jobId,
        'file' => new CURLFile($real, mime_content_type($real) ?: 'application/octet-stream', basename($real)),
    ];
    $ch = curl_init($CONFIG['base'] . '/api/agent/attachment.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;
    if ($code === 200 && is_array($json)) {
        return $json;
    }
    logmsg('Media upload failed: HTTP ' . $code, 'WARN');
    return null;
}

function wait_approval(int $jobId, string $runId, string $description = ''): string
{
    global $CONFIG;
    post_event(
        $jobId,
        'approval',
        $runId,
        $description !== '' ? $description : 'The agent needs permission to continue.'
    );
    $deadline = time() + $CONFIG['approval_seconds'];

    while (time() < $deadline) {
        $res = webchat_json('GET', '/api/agent/approval.php?job_id=' . $jobId . '&run_id=' . rawurlencode($runId));
        $status = $res['json']['status'] ?? null;
        if ($status === 'approved' || $status === 'denied') {
            $res = agent_json('POST', '/v1/runs/' . rawurlencode($runId) . '/approval', ['decision' => $status], 30);
            if ($res['code'] >= 400) {
                logmsg('Approval forward failed: HTTP ' . $res['code'], 'WARN');
            }
            return $status;
        }
        sleep(2);
    }

    return 'expired';
}

function handle_event(array $event, int $jobId, string $runId, string &$text, ?string &$error): void
{
    $type = strtolower((string) ($event['type'] ?? $event['event'] ?? ''));

    if (isset($event['delta']) && is_string($event['delta'])) {
        $text .= $event['delta'];
        post_chunk($jobId, $event['delta']);
        return;
    }
    if (isset($event['text']) && is_string($event['text']) && !str_contains($type, 'completed')) {
        $text .= $event['text'];
        post_chunk($jobId, $event['text']);
        return;
    }
    if (str_contains($type, 'approval')) {
        $description = (string) ($event['description'] ?? $event['message'] ?? 'Approval required');
        wait_approval($jobId, $runId, $description);
        return;
    }
    if (str_contains($type, 'failed') || str_contains($type, 'error')) {
        $error = (string) ($event['error'] ?? $event['message'] ?? 'Agent run failed');
        return;
    }
    if (str_contains($type, 'completed')) {
        if (isset($event['output']) && is_string($event['output']) && $event['output'] !== '') {
            $text = $event['output'];
        }
        if (isset($event['usage']) && is_array($event['usage'])) {
            $GLOBALS['run_usage'] = $event['usage'];
        }
    }
}

function stream_events(int $jobId, string $runId, string &$text, ?string &$error): void
{
    global $CONFIG;
    $url = $CONFIG['agent'] . '/v1/runs/' . rawurlencode($runId) . '/events';
    $headers = array_merge(agent_headers(), ['Accept: text/event-stream']);

    $buffer = '';
    $data = '';
    $eventName = '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 1800,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, &$data, &$eventName, $jobId, $runId, &$text, &$error) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') {
                    if ($data !== '') {
                        $event = json_decode($data, true);
                        if (is_array($event)) {
                            if (empty($event['type']) && empty($event['event']) && $eventName !== '') {
                                $event['type'] = $eventName;
                            }
                            handle_event($event, $jobId, $runId, $text, $error);
                        }
                        $data = '';
                    }
                    $eventName = '';
                    continue;
                }
                if (str_starts_with($line, 'event:')) {
                    $eventName = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $data .= trim(substr($line, 5));
                }
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($data !== '') {
        $event = json_decode($data, true);
        if (is_array($event)) {
            if (empty($event['type']) && empty($event['event']) && $eventName !== '') {
                $event['type'] = $eventName;
            }
            handle_event($event, $jobId, $runId, $text, $error);
        }
    }
    if ($code >= 400) {
        $error = $error ?? ('Event stream failed: HTTP ' . $code);
    }
}

function extract_media(string &$text): array
{
    $paths = [];
    $text = preg_replace_callback('/^MEDIA:\s*(.+)$/mi', function (array $match) use (&$paths): string {
        $paths[] = trim($match[1]);
        return '';
    }, $text) ?? $text;
    return $paths;
}

function run_job(array $job): void
{
    $jobId = (int) ($job['id'] ?? 0);
    $messages = is_array($job['messages'] ?? null) ? $job['messages'] : [];
    $uploads = is_array($job['uploads'] ?? null) ? $job['uploads'] : [];
    if ($jobId <= 0 || !$messages) {
        return;
    }

    $last = end($messages);
    $input = is_array($last) ? (string) ($last['content'] ?? '') : '';

    $files = download_uploads($jobId, $uploads);
    if ($files) {
        $input .= "\n\nAttached files (local paths):\n- " . implode("\n- ", $files);
    }

    $history = array_slice($messages, 0, -1);
    $history = array_values(array_filter($history, static fn ($m): bool => is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant'], true)));

    $payload = [
        'input' => $input,
        'conversation_history' => $history,
        'session_id' => 'webchat-' . substr((string) ($job['conversation_id'] ?? 'job' . $jobId), 0, 40),
    ];

    logmsg('Job ' . $jobId . ' -> Hermes');
    $res = agent_json('POST', '/v1/runs', $payload, 60);
    if ($res['code'] < 200 || $res['code'] >= 300 || !is_array($res['json'])) {
        $detail = (string) ($res['json']['error'] ?? $res['error'] ?? ('HTTP ' . $res['code']));
        logmsg('Run failed: ' . substr($detail, 0, 200), 'ERROR');
        post_reply($jobId, '', [], 'error', $detail);
        return;
    }

    $runId = (string) ($res['json']['id'] ?? $res['json']['run_id'] ?? '');
    if ($runId === '') {
        post_reply($jobId, '', [], 'error', 'The agent did not return a run id');
        return;
    }

    $text = '';
    $error = null;
    $GLOBALS['run_usage'] = [];

    stream_events($jobId, $runId, $text, $error);

    $media = extract_media($text);
    foreach ($media as $path) {
        if (upload_media($jobId, $path) === null) {
            $text .= "\n\n(Attachment not sent: " . basename($path) . ")";
        }
    }

    if ($error !== null && trim($text) === '') {
        post_reply($jobId, '', [], 'error', $error);
        return;
    }

    post_reply($jobId, trim($text), is_array($GLOBALS['run_usage']) ? $GLOBALS['run_usage'] : []);
    logmsg('Job ' . $jobId . ' done (' . strlen($text) . ' chars)');
}

logmsg('Hermes Webchat bridge ' . VERSION);
logmsg('Web chat: ' . $CONFIG['base']);
logmsg('Agent:    ' . $CONFIG['agent']);

heartbeat_if_due(true);

while (true) {
    try {
        heartbeat_if_due();
        $job = fetch_job();
        if ($job !== null) {
            heartbeat_if_due(true);
            run_job($job);
            heartbeat_if_due(true);
        }
    } catch (TokenInvalid $error) {
        logmsg('Invalid token. Waiting 60s. Rotate it in Admin and update .env.', 'ERROR');
        sleep(60);
    } catch (Throwable $error) {
        logmsg('Unexpected error: ' . $error->getMessage(), 'ERROR');
        sleep(5);
    }
}
