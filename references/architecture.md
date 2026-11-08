# Architecture

## Components

| Component | Runs on | Responsibility |
|---|---|---|
| Web chat | PHP hosting (Hestia) | Users, sessions, conversations, jobs, files, admin |
| MySQL | Same host | Persistent state |
| Bridge | Next to Hermes (Docker) | Polls jobs, calls Hermes, streams results |
| Hermes | Agent host | Runs the model and tools |

## Why pull

The web never opens a connection to the agent. The bridge authenticates to the
web with a token and asks for work. That means:

- the agent can live behind NAT or on another VPS;
- the web only needs PHP and MySQL;
- revoking the token disconnects the agent immediately.

## Message sequence

```text
User            Web chat              Bridge                Hermes
 |  message  ->   |                     |                     |
 |                |  store msg + job    |                     |
 |                |  <- GET jobs -------|                     |
 |                |  --- job ---------->|                     |
 |                |                     |  POST /v1/runs ---> |
 |                |                     |  <- events (SSE) -- |
 |                |  <- chunk ----------|                     |
 |  <- poll job --|                     |                     |
 |                |  <- reply ----------|                     |
 |  <- answer ----|                     |                     |
```

## Tables

| Table | Purpose |
|---|---|
| `users` | Accounts, role, quota, used tokens |
| `conversations` | One row per chat, owner and title |
| `messages` | User and assistant history |
| `jobs` | One run request, status and lease |
| `job_chunks` | Streaming pieces, ordered by id |
| `attachments` | Files from user (`user`) or agent (`agent`) |
| `approvals` | Pending/resolved permission requests |
| `agent_tokens` | SHA-256 hashes of bridge tokens |
| `settings` | Optional key/value settings |

## Job lifecycle

```text
queued -> running -> done
                  \-> error
```

- `queued`: created by `api/chat.php`.
- `running`: claimed by the bridge with a 180 s lease.
- If the lease expires, the job can be claimed again (crash recovery).
- `done` / `error`: final state, sets `finished_at`.

`job_claim()` uses a transaction with `FOR UPDATE SKIP LOCKED` so two bridges
never take the same job.

## Streaming

1. The bridge reads the SSE stream from Hermes.
2. Each text delta is posted to `api/agent/chunk.php`.
3. The browser polls `api/job.php?job_id=N&offset=X`.
4. `offset` is the last chunk id, so only new text is returned.
5. On completion, the bridge posts the full answer with `api/agent/reply.php`.

If the agent produced no chunks, `api/job.php` returns the final response once.

## Approvals

1. Hermes emits an approval event during the run.
2. The bridge posts `type=approval` to `api/agent/event.php`.
3. The web stores a row in `approvals` and shows a card.
4. The user presses Approve/Deny; `api/approval.php` updates the row.
5. The bridge polls `api/agent/approval.php?job_id&run_id` every 2 s.
6. When resolved, the bridge forwards the decision to
   `POST /v1/runs/{run_id}/approval`.
7. If nobody answers before `WEBCHAT_APPROVAL_SECONDS`, it returns `expired`.

## Attachments

### User to agent

1. `api/upload.php` stores the file and an `attachments` row with `job_id NULL`.
2. `api/chat.php` links those rows to the new job.
3. The bridge downloads each file to `INBOX_DIR/job-<id>/`.
4. The bridge appends the local paths to the input text.

### Agent to user

1. The final text contains `MEDIA:/absolute/path/file.pdf` lines.
2. The bridge validates the path against `MEDIA_ROOTS`.
3. Uploads it to `api/agent/attachment.php` with `direction=agent`.
4. `api/job.php` returns the attachment with a download URL.

## Heartbeat

- Every authenticated agent request updates `agent_tokens.last_seen_at`.
- The bridge also calls `api/agent/heartbeat.php` every 60 s.
- Admin shows **Connected** when the last heartbeat is under 120 s old.

## Scaling notes

- One bridge handles one job at a time. For concurrency, run several bridges
  with different tokens; the claim is atomic.
- The UI polls; for large teams, increase the polling interval.
- MySQL is the only shared state; the web servers can be replicated.

## Failure modes

| Failure | Behaviour |
|---|---|
| Bridge crashes mid-job | Lease expires; another bridge retries |
| Web unreachable | Bridge logs a warning every 30 s and keeps trying |
| Token revoked | Bridge waits 60 s and retries; admin sees "not connected" |
| Hermes down | Job returns an error message to the user |
| Approval timeout | Status `expired`; the run is denied |
