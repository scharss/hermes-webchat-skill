# Hermes Webchat Skill

Build a complete **web chat for a Hermes Agent**: PHP/MySQL interface,
pull-mode bridge, conversations, streaming, attachments, approvals, users and
quotas. Designed for AI agents (Hermes skills) and for people who prefer a
step-by-step deployment.

The web never connects to the agent. The **bridge** runs next to Hermes, polls
the chat and executes the work:

```text
User -> Web chat (PHP/MySQL) <- long-poll <- bridge -> Hermes /v1/runs
                     ^                                  |
                     +------ chunks, approvals, files --+
```

## Features

- Login with users, roles and optional token quotas.
- Conversations and persistent message history.
- Job queue with atomic claim and lease (MySQL 8 `SKIP LOCKED`).
- Streaming answers over polling (no WebSockets).
- File uploads from users and attachments from the agent.
- Approval cards relayed to Hermes (approve / deny / expire).
- Admin panel: users, quotas, rotate token, connection status.
- Heartbeat so the UI can show "agent connected".
- Web installer (database + administrator).
- Security defaults: CSRF, token hashing, path confinement, upload limits.

## Repository layout

```text
.
├─ README.md
├─ SKILL.md
├─ templates/
│  ├─ app/                      PHP + MySQL application
│  ├─ bridge/                   bridge.php + Dockerfile + .env.example
│  └─ docker-compose.bridge.yml
├─ scripts/
│  └─ check_install.py
└─ references/
   ├─ architecture.md
   ├─ api.md
   ├─ security.md
   └─ checklist.md
```

`SKILL.md` is the entry point for AI agents.

## Requirements

| Component | Version |
|---|---|
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo`, `curl` |
| MySQL | 8.0+ (uses `FOR UPDATE SKIP LOCKED`) |
| Web server | Apache with `.htaccess` (Hestia) or Nginx equivalent |
| Agent | Hermes with the API server enabled (`API_SERVER_KEY`) |
| Bridge | PHP 8.2 CLI in Docker (or bare metal) |

## Quick start

### 1. Copy the web application

Copy `templates/app/` to the web root:

```text
public_html/chat/
  index.php  login.php  install.php  admin/  api/  inc/  assets/
  schema.sql  .htaccess  .user.ini  .env.example
  storage/sessions/  storage/uploads/  storage/attachments/
```

Make `storage/` writable by PHP and serve the site over HTTPS.

### 2. Install

Create an empty MySQL database and user, then open:

```text
https://yourdomain.com/chat/install.php
```

1. Database: host, port, name, user, password.
2. Administrator: app name, username, email, password.

The installer writes `.env`, runs `schema.sql` and creates the admin user. It
refuses to run again once `.env` exists.

Validate:

```bash
python scripts/check_install.py --dir public_html/chat
```

### 3. Generate the agent token

Sign in, open **Admin** and press **Rotate token**. Copy the `apch_...` value;
it is shown only once.

### 4. Configure the bridge

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

### 5. Test

1. Send a message; the bridge streams the answer.
2. Attach a file; the bridge downloads it to `INBOX_DIR`.
3. Ask for a file; if the reply contains `MEDIA:/path/file.pdf`, it appears in
   the chat.
4. Trigger an approval and resolve it.

## Scripts

### `check_install.py`

```bash
python scripts/check_install.py --dir ./app
python scripts/check_install.py --url https://yourdomain.com/chat
```

Checks required files, `.env` keys, storage permissions, `.htaccess`, schema
tables, and (for URLs) login, installer state and API protection.

## How it works

1. The user sends a message; `api/chat.php` stores it and creates a job.
2. The bridge polls `api/agent/jobs.php` and claims a job atomically.
3. The bridge calls `POST /v1/runs` and reads `/v1/runs/{id}/events`.
4. Deltas go to `api/agent/chunk.php`; the UI polls `api/job.php`.
5. Approvals are stored and the bridge waits for the user's decision.
6. The bridge posts the final answer with `api/agent/reply.php`.

More: `references/architecture.md`, `references/api.md`.

## Security

Minimum for production:

- HTTPS.
- Strong admin password.
- Rotate the token if it leaks.
- Keep `storage/` and `inc/` blocked (included `.htaccess`).
- Back up the database and `.env` together.

Details: `references/security.md`.

## Limits

- The bridge is single-threaded: one long job delays the next message.
- Streaming uses polling every ~800 ms, not WebSockets.
- No email verification or password reset; the admin creates users.
- Rate limiting is basic; add it at the web server for public deployments.
- The bridge understands generic SSE event envelopes (`delta`, `text`,
  `approval`, `completed`). Adjust `handle_event()` for other Hermes versions.

## Verified

Tested end to end with MySQL 8, PHP 8.3/Apache, a mock Hermes agent and the real
bridge:

- installer, login, admin token rotation;
- chat, job queue, streaming;
- user upload and agent download;
- approval approved and approval expired;
- agent attachment with `MEDIA:`;
- protected paths, invalid token, CSRF;
- UI rendering and "agent connected" status.

## Developer

**Sharss Azael** — [github.com/scharss](https://github.com/scharss/)

## Support

[![Donate via PayPal](https://img.shields.io/badge/Donate-PayPal-00457C?style=for-the-badge&logo=paypal&logoColor=white)](https://paypal.me/mercaplace)
