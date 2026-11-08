# Deployment checklist

## Before deploying

- [ ] PHP 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo`, `curl`.
- [ ] MySQL 8.0+ (needs `FOR UPDATE SKIP LOCKED`).
- [ ] HTTPS certificate ready.
- [ ] Hermes API server enabled (`API_SERVER_KEY`).
- [ ] Docker available where the agent runs.

## Application

- [ ] `templates/app/` copied to the web root.
- [ ] `storage/`, `storage/sessions`, `storage/uploads`,
      `storage/attachments` writable.
- [ ] `.htaccess` uploaded (Apache) or equivalent rules (Nginx).
- [ ] `.user.ini` uploaded or PHP limits set in the panel.
- [ ] `schema.sql` present.
- [ ] `install.php` reachable.

## Installation

- [ ] Database created (empty).
- [ ] Installer step 1 connects.
- [ ] Installer step 2 creates the admin and writes `.env`.
- [ ] `install.php` now says "already installed".
- [ ] `check_install.py --dir` reports no errors.
- [ ] Login works.

## Admin

- [ ] Agent token generated and copied.
- [ ] Admin password is strong.
- [ ] Unused users deactivated.
- [ ] Quotas set where needed.

## Bridge

- [ ] `bridge/.env` created from `.env.example`.
- [ ] `WEBCHAT_BASE_URL` is the public HTTPS URL.
- [ ] `WEBCHAT_TOKEN` is the `apch_...` value.
- [ ] `AGENT_API_URL` points to Hermes (usually `http://hermes:8642`).
- [ ] `AGENT_API_KEY` matches `API_SERVER_KEY`.
- [ ] `MEDIA_ROOTS` and `INBOX_DIR` exist and are writable.
- [ ] Service added to the agent's compose file.
- [ ] Container starts and logs "Hermes Webchat bridge".

## Tests

- [ ] Admin shows **Connected**.
- [ ] Send "Hello" and receive an answer.
- [ ] Reload the page and the conversation persists.
- [ ] Open a second conversation; both appear in the sidebar.
- [ ] Attach a PDF; the agent receives the path.
- [ ] Ask for a file; the attachment appears in the chat.
- [ ] Trigger an approval and resolve it.
- [ ] Stop the bridge; the UI shows **Agent offline** within 2 minutes.
- [ ] Restart the bridge; status returns.

## Security

- [ ] `inc/` and `storage/` return 404 from the browser.
- [ ] `.env` is not downloadable.
- [ ] Session cookie is `HttpOnly`.
- [ ] Uploads over the limit are rejected.
- [ ] Executable extensions are rejected.
- [ ] Rotating the token disconnects the old bridge.

## Operations

- [ ] Database backup scheduled.
- [ ] `.env` backed up securely.
- [ ] Log rotation for the bridge container.
- [ ] Monitor disk space for `storage/`.
- [ ] Document how to rotate the token.

## Optional

- [ ] PWA added with `pwa-skill`.
- [ ] Brand colors and logo replaced.
- [ ] Rate limiting at the web server.
- [ ] Second bridge for concurrency (separate token).
