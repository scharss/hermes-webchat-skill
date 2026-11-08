---
name: hermes-webchat-skill
description: >-
  Build a complete web chat for a Hermes Agent: PHP/MySQL interface, pull-mode
  bridge, conversations, streaming, attachments, approvals, users and quotas.
version: 1.0.0
platforms: [linux, macos, windows]
---

# Hermes Webchat

## When to use this skill

When the user wants a **web chat** for a Hermes Agent instead of (or next to)
Telegram: multiple users, login, conversations, attachments, quotas and
approvals, hosted on cheap PHP hosting.

It is a pull architecture: the web never connects to the agent. The **bridge**
runs next to Hermes, polls the web and executes the work.

```text
User -> Web chat (PHP/MySQL) <- long-poll <- bridge -> Hermes /v1/runs
                     ^                                  |
                     +------ chunks, approvals, files --+
```

## What it includes

- Login with users, roles and optional token quotas.
- Conversations and message history.
- Queue of jobs with atomic claim and lease (MySQL 8 `SKIP LOCKED`).
- Streaming answers (polling, no WebSockets).
- File uploads and agent attachments.
- Approval cards (approve/deny) relayed to Hermes.
- Admin panel: users, quotas, rotate agent token, connection status.
- Heartbeat so the UI can show "agent connected".
- Installer wizard.

## Requirements

| Component | Version |
|---|---|
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo`, `curl` |
| MySQL | 8.0+ (needs `SKIP LOCKED`) |
| Web server | Apache with `.htaccess` (Hestia) or Nginx equivalent |
| Agent | Hermes with the API server enabled (`API_SERVER_KEY`) |
| Bridge | PHP 8.2 CLI in Docker (or bare metal) |

## Step 1 - Copy the application

Copy `templates/app/` to the web root, for example:

```text
public_html/chat/
  index.php  login.php  install.php  admin/  api/  inc/  assets/
  schema.sql  .htaccess  .user.ini  .env.example
  storage/sessions/  storage/uploads/  storage/attachments/
```

Requirements:

- `storage/` writable by PHP.
- `schema.sql` present (used by the installer).
- HTTPS in production.

## Step 2 - Create the database and install

Create an empty MySQL database and user. Then open:

```text
https://yourdomain.com/chat/install.php
```

1. Database: host, port, name, user, password.
2. Administrator: app name, username, email, password.

The installer writes `.env`, runs `schema.sql` and creates the admin user.
`install.php` refuses to run again if `.env` exists.

Verify:

```bash
python scripts/check_install.py --dir public_html/chat
```

## Step 3 - Generate the agent token

Sign in and open **Admin**. Press **Rotate token** and copy the `apch_...`
value. It is shown only once.

## Step 4 - Configure the bridge

Copy `templates/bridge/` next to the agent's `docker-compose.yml`:

```bash
cp templates/bridge/.env.example bridge/.env
```

Fill:

```dotenv
WEBCHAT_BASE_URL=https://yourdomain.com/chat
WEBCHAT_TOKEN=apch_...
AGENT_API_URL=http://hermes:8642
AGENT_API_KEY=<API_SERVER_KEY>
MEDIA_ROOTS=/opt/data/exports,/opt/data/media
INBOX_DIR=/opt/data/inbox
```

Add the service from `templates/docker-compose.bridge.yml` and start it:

```bash
docker compose up -d --build webchat-bridge
docker compose logs -f webchat-bridge
```

Expected:

```text
[Hermes Webchat bridge 1.0.0]
Web chat: https://yourdomain.com/chat
Agent:    http://hermes:8642
```

And in **Admin**, the agent must show **Connected**.

## Step 5 - Test

1. Send "Hello" from the web. The bridge claims the job and streams the reply.
2. Attach a PDF; the bridge downloads it to `INBOX_DIR` and passes the path.
3. Ask the agent to generate a file; when the reply contains
   `MEDIA:/path/file.pdf`, the bridge uploads it and it appears in the chat.
4. Trigger an approval and press **Approve** or **Deny**.

## Layout

```text
app/
  index.php  login.php  logout.php  install.php  schema.sql
  inc/       bootstrap, db, env, store, agent
  api/       chat, job, conversations, upload, file, approval, health
  api/agent/ jobs, chunk, reply, event, approval, attachment, heartbeat, health
  admin/     index.php
  assets/    app.js, styles.css
bridge/
  bridge.php  Dockerfile  .env.example
```

## How it works

1. The user sends a message; `api/chat.php` stores it and creates a `job`.
2. The bridge polls `api/agent/jobs.php` and atomically claims a job.
3. The bridge calls `POST /v1/runs` and reads `/v1/runs/{id}/events`.
4. Text deltas go to `api/agent/chunk.php`; the UI polls `api/job.php`.
5. Approvals are stored and the bridge waits for the user's decision.
6. The bridge posts the final answer with `api/agent/reply.php`.

Details in `references/architecture.md` and `references/api.md`.

## Security

Read `references/security.md`. Minimum:

- HTTPS.
- Strong admin password.
- Rotate the token if it leaks.
- Keep `storage/` and `inc/` blocked (the included `.htaccess` does it).
- Back up the database and `.env`.

## Common problems

| Symptom | Cause |
|---|---|
| "Agent not connected" | Bridge stopped, wrong URL or revoked token |
| Job stuck in `queued` | Bridge cannot reach the web (DNS, firewall, HTTPS) |
| `401` in the bridge | Token rotated or from another install |
| Replies never arrive | Agent API key missing or `/v1/runs` unavailable |
| Upload fails | `upload_max_filesize`, `post_max_size` or permissions |
| Messages repeat | Two bridges running the same token |
| Approval never resolves | `WEBCHAT_APPROVAL_SECONDS` too short or bridge blocked |

## Optional: PWA

The chat UI is installable. Use the `pwa-skill` to add `manifest.json`,
`sw.js`, icons and splash screens. Serve `sw.js` from the app root.

## Limits

- The bridge is single-threaded: one long job delays the next message.
- Streaming uses polling every ~800 ms, not WebSockets.
- There is no email verification or password reset; the admin creates users.
- Rate limiting is basic; add it at the web server for public deployments.
- The bridge understands generic event envelopes (`delta`, `text`, `approval`,
  `completed`). If your Hermes version uses different event names, adjust
  `handle_event()` in `bridge.php`.

## Reference implementation

`apichatbusiness` (Business Copilot) is a production example of this
architecture, with PWA, branding and more edge cases. Use it to compare.
