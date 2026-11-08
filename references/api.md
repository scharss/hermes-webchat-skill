# HTTP API

All responses are JSON unless noted. Errors use `{ "error": "..." }` with the
matching HTTP status.

## Web endpoints (session cookie)

CSRF is required on POST: send the token from `csrf_token()` in the header
`X-CSRF-Token`.

### `GET /api/health.php`

Returns CSRF, current user, quota and agent status.

```json
{ "ok": true, "csrf": "...", "user": { "id": 1, "username": "admin", "role": "admin" },
  "quota": null, "agent": { "connected": true, "pending": 0 } }
```

### `GET /api/conversations.php`

Lists the user's conversations.

### `GET /api/conversations.php?id=<id>`

Returns one conversation and its messages.

### `POST /api/conversations.php`

Body: `{ "title": "New conversation" }`. Creates an empty conversation.

### `POST /api/chat.php`

Body:

```json
{ "conversation_id": "optional", "message": "Hello", "upload_ids": [1, 2] }
```

Creates the user message and a queued job. Returns:

```json
{ "ok": true, "job_id": 42, "conversation_id": "abc...", "title": "Hello" }
```

### `GET /api/job.php?job_id=<id>&offset=<last_chunk_id>`

Polling endpoint:

```json
{ "ok": true, "status": "running", "delta": "partial text", "offset": 12,
  "approval": { "id": 3, "description": "Run a command" },
  "attachments": [ { "id": 7, "name": "report.pdf", "size": 1200, "url": "api/file.php?id=7" } ] }
```

`delta` only contains text after `offset`. On `done`, if the agent produced no
chunks, `delta` contains the final response once.

### `POST /api/upload.php`

Multipart with `file`. Returns `{ "id": 5, "name": "...", "size": 123 }`.
Limits: `WEBCHAT_MAX_UPLOAD_MB`; executable extensions are rejected.

### `GET /api/file.php?id=<id>`

Downloads an attachment. Only its owner or an admin can access it.

### `POST /api/approval.php`

Body: `{ "approval_id": 3, "decision": "approved" | "denied" }`.

## Agent endpoints (Bearer token)

Header: `Authorization: Bearer apch_...`.

### `GET /api/agent/jobs.php?wait=20`

Long-polls up to 25 seconds and claims one job.

```json
{ "ok": true, "job": { "id": 42, "conversation_id": "abc",
  "messages": [ { "role": "user", "content": "Hi" } ],
  "uploads": [ { "id": 5, "name": "a.pdf", "url": "api/agent/attachment.php?id=5" } ] } }
```

When there is no work: `{ "ok": true, "job": null }`.

### `POST /api/agent/chunk.php`

Body: `{ "job_id": 42, "text": "partial" }`. Appends streaming text.

### `POST /api/agent/reply.php`

Body:

```json
{ "job_id": 42, "text": "final", "usage": { "total_tokens": 900 }, "status": "done" }
```

`status` may be `done` or `error` (with `error`).

### `POST /api/agent/event.php`

Body: `{ "job_id": 42, "type": "approval", "run_id": "run_x", "description": "..." }`.
Returns `{ "approval_id": 3 }`.

### `GET /api/agent/approval.php?job_id=42&run_id=run_x`

Returns `{ "status": "pending" | "approved" | "denied" | "expired" | null }`.

### `POST /api/agent/attachment.php`

Multipart: `job_id` and `file`. Uploads an agent attachment.

### `GET /api/agent/attachment.php?id=<id>`

Downloads a user upload for the bridge.

### `POST /api/agent/heartbeat.php`

Updates `last_seen_at`.

### `GET /api/agent/health.php`

```json
{ "ok": true, "app": "Hermes Webchat", "pending": 0, "server_time": "..." }
```

## Status codes

| Code | Meaning |
|---|---|
| 200 | Success |
| 400 | Invalid input |
| 401 | Missing or invalid token / session |
| 402 | Quota exhausted |
| 403 | CSRF failed or forbidden |
| 404 | Not found |
| 413 | File too large |
| 500 | Server error |
