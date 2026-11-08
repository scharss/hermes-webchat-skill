<?php
declare(strict_types=1);

function conversation_list(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, title, updated_at FROM conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function conversation_get(string $id, int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function conversation_create(int $userId, string $title = 'New conversation'): array
{
    $id = bin2hex(random_bytes(16));
    $stmt = db()->prepare('INSERT INTO conversations (id, user_id, title) VALUES (?, ?, ?)');
    $stmt->execute([$id, $userId, mb_substr($title, 0, 120)]);
    return conversation_get($id, $userId);
}

function conversation_touch(string $id): void
{
    $stmt = db()->prepare('UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$id]);
}

function conversation_title(string $text): string
{
    $title = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($title === '') {
        return 'New conversation';
    }
    return mb_substr($title, 0, 60);
}

function messages_for(string $conversationId): array
{
    $stmt = db()->prepare(
        'SELECT id, role, content, created_at FROM messages WHERE conversation_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$conversationId]);
    return $stmt->fetchAll();
}

function message_add(string $conversationId, string $role, string $content): int
{
    $stmt = db()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?)');
    $stmt->execute([$conversationId, $role, $content]);
    conversation_touch($conversationId);
    return (int) db()->lastInsertId();
}

function storage_dir(string $sub): string
{
    $dir = APP_STORAGE . '/' . trim($sub, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return $dir;
}

function safe_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'file';
    $name = trim($name, '._- ');
    return $name !== '' ? mb_substr($name, 0, 120) : 'file';
}

function human_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return $bytes . ' B';
}

function attachment_add(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO attachments (job_id, user_id, name, mime, size, sha256, path, direction)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['job_id'] ?? null,
        (int) $data['user_id'],
        $data['name'],
        $data['mime'],
        (int) $data['size'],
        $data['sha256'],
        $data['path'],
        $data['direction'] ?? 'user',
    ]);
    return (int) db()->lastInsertId();
}

function attachment_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM attachments WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function attachments_for_job(int $jobId): array
{
    $stmt = db()->prepare('SELECT * FROM attachments WHERE job_id = ? ORDER BY id ASC');
    $stmt->execute([$jobId]);
    return $stmt->fetchAll();
}

function uploads_for_user(array $ids, int $userId): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM attachments WHERE id IN ($placeholders) AND user_id = ? AND job_id IS NULL ORDER BY id ASC"
    );
    $stmt->execute([...$ids, $userId]);
    return $stmt->fetchAll();
}
