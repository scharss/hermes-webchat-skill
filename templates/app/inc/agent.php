<?php
declare(strict_types=1);

function agent_bearer(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $match)) {
        return trim($match[1]);
    }
    return '';
}

function agent_auth(): array
{
    $token = agent_bearer();
    if ($token === '') {
        json_out(401, ['error' => 'Missing agent token']);
    }

    $hash = hash('sha256', $token);
    $stmt = db()->prepare(
        'SELECT * FROM agent_tokens WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        json_out(401, ['error' => 'Invalid agent token']);
    }

    $stmt = db()->prepare('UPDATE agent_tokens SET last_seen_at = NOW() WHERE id = ?');
    $stmt->execute([(int) $row['id']]);

    return $row;
}

function agent_status(): array
{
    $stmt = db()->query(
        'SELECT id, label, last_seen_at FROM agent_tokens
         WHERE revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 1'
    );
    $token = $stmt->fetch();
    $connected = false;
    if ($token && $token['last_seen_at']) {
        $connected = (time() - strtotime($token['last_seen_at'])) <= 120;
    }

    $pending = (int) db()->query("SELECT COUNT(*) FROM jobs WHERE status = 'queued'")->fetchColumn();

    return [
        'connected' => $connected,
        'label' => $token['label'] ?? '',
        'last_seen_at' => $token['last_seen_at'] ?? null,
        'pending' => $pending,
    ];
}

function agent_token_create(string $label = 'Hermes'): array
{
    $plain = 'apch_' . bin2hex(random_bytes(24));
    $stmt = db()->prepare(
        'INSERT INTO agent_tokens (label, token_hash, token_prefix) VALUES (?, ?, ?)'
    );
    $stmt->execute([$label, hash('sha256', $plain), substr($plain, 0, 12)]);
    return ['token' => $plain, 'id' => (int) db()->lastInsertId()];
}

function agent_token_revoke_all(): void
{
    db()->exec('UPDATE agent_tokens SET revoked_at = NOW() WHERE revoked_at IS NULL');
}

function job_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function job_claim(): ?array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query(
            "SELECT id FROM jobs
             WHERE status = 'queued' OR (status = 'running' AND lease_until < NOW())
             ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->commit();
            return null;
        }

        $pdo->prepare(
            "UPDATE jobs
             SET status = 'running',
                 started_at = COALESCE(started_at, NOW()),
                 lease_until = DATE_ADD(NOW(), INTERVAL 180 SECOND)
             WHERE id = ?"
        )->execute([(int) $row['id']]);

        $pdo->commit();
        return job_get((int) $row['id']);
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function job_payload(array $job): array
{
    $payload = json_decode((string) $job['request_json'], true);
    if (!is_array($payload)) {
        $payload = ['messages' => []];
    }

    $uploads = [];
    foreach (attachments_for_job((int) $job['id']) as $file) {
        $uploads[] = [
            'id' => (int) $file['id'],
            'name' => $file['name'],
            'mime' => $file['mime'],
            'size' => (int) $file['size'],
            'url' => 'api/agent/attachment.php?id=' . $file['id'],
        ];
    }
    $payload['uploads'] = $uploads;

    return $payload;
}

function job_chunk(int $jobId, string $text): void
{
    $stmt = db()->prepare('INSERT INTO job_chunks (job_id, content) VALUES (?, ?)');
    $stmt->execute([$jobId, $text]);

    // Keep the lease alive while the agent is still producing output.
    db()->prepare('UPDATE jobs SET lease_until = DATE_ADD(NOW(), INTERVAL 180 SECOND) WHERE id = ?')
        ->execute([$jobId]);
}

function job_reply(int $jobId, string $text, array $usage, string $status = 'done', ?string $error = null): void
{
    $job = job_get($jobId);
    if ($job === null) {
        return;
    }

    if ($text !== '') {
        message_add($job['conversation_id'], 'assistant', $text);
    }

    $total = (int) ($usage['total_tokens'] ?? 0);
    $stmt = db()->prepare(
        'UPDATE jobs SET status = ?, response = ?, error = ?, lease_until = NULL, finished_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$status === 'error' ? 'error' : 'done', $text, $error, $jobId]);

    // Any approval still pending when the run ends is expired.
    db()->prepare(
        "UPDATE approvals SET status = 'expired', resolved_at = NOW() WHERE job_id = ? AND status = 'pending'"
    )->execute([$jobId]);

    if ($total > 0) {
        db()->prepare('UPDATE users SET used_tokens = used_tokens + ? WHERE id = ?')
            ->execute([$total, (int) $job['user_id']]);
    }
}

function approval_create(int $jobId, string $runId, string $description): int
{
    $stmt = db()->prepare(
        "SELECT id FROM approvals WHERE job_id = ? AND run_id = ? AND status = 'pending' LIMIT 1"
    );
    $stmt->execute([$jobId, $runId]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $stmt = db()->prepare('INSERT INTO approvals (job_id, run_id, description) VALUES (?, ?, ?)');
    $stmt->execute([$jobId, $runId, mb_substr($description, 0, 500)]);
    return (int) db()->lastInsertId();
}

function approval_pending(int $jobId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM approvals WHERE job_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function approval_status(int $jobId, string $runId): ?string
{
    $stmt = db()->prepare(
        'SELECT status FROM approvals WHERE job_id = ? AND run_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$jobId, $runId]);
    $status = $stmt->fetchColumn();
    return $status === false ? null : (string) $status;
}

function approval_resolve(int $id, string $status, int $userId): bool
{
    if (!in_array($status, ['approved', 'denied'], true)) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT a.id, j.user_id FROM approvals a JOIN jobs j ON j.id = a.job_id
         WHERE a.id = ? AND a.status = ? LIMIT 1'
    );
    $stmt->execute([$id, 'pending']);
    $row = $stmt->fetch();
    if (!$row || (int) $row['user_id'] !== $userId) {
        return false;
    }

    $stmt = db()->prepare('UPDATE approvals SET status = ?, resolved_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $id]);
    return true;
}
