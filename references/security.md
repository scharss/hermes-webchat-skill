# Security

## Model

- The web is the only public surface.
- The agent authenticates with a bearer token (`apch_...`).
- Users authenticate with a session cookie and a CSRF token.
- The bridge holds the agent API key locally; it never reaches the browser.

## Tokens

- Store only the SHA-256 hash (`agent_tokens.token_hash`).
- Show the plain token once, at rotation.
- One token per bridge; rotate immediately if leaked.
- Revoking sets `revoked_at`; old tokens stop working at once.

## Sessions

- Cookies: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS is on.
- `session_regenerate_id(true)` after login.
- Session files live under `storage/sessions`, outside the web root access.
- Expire idle sessions at the web server if the deployment requires it.

## CSRF

- Every POST from the browser requires `X-CSRF-Token`.
- The token is generated per session and refreshed on login.
- Agent endpoints do not use CSRF; they use the bearer token.

## XSS

- The UI builds messages with `textContent`, never `innerHTML` for user data.
- The only `innerHTML` uses are static templates and escaped labels.
- Keep it that way: if you add Markdown, sanitize it first.

## SQL injection

- All queries use PDO prepared statements.
- Never interpolate user input into SQL. The only dynamic `IN (...)` builds
  placeholders from integers.

## Path traversal

- Uploaded files are stored with a random prefix and a sanitized name.
- Downloads resolve `realpath()` and verify the result stays inside
  `storage/`.
- `api/agent/attachment.php` applies the same check.
- `MEDIA_ROOTS` limits which local files the bridge may upload.

## Uploads

- Limit size with `WEBCHAT_MAX_UPLOAD_MB` and `.user.ini`.
- Reject executables: `php`, `phtml`, `phar`, `cgi`, `pl`, `exe`, `bat`, `cmd`,
  `sh`.
- Serve downloads as `Content-Disposition: attachment` with `nosniff`.
- `storage/` is blocked by `.htaccess`; keep that rule if you migrate to Nginx.

## Approvals

- An approval belongs to one job and one user.
- Only the job owner (or an admin) can resolve it.
- The bridge forwards the decision; it cannot approve on its own.
- Timeouts return `expired`, which the bridge treats as denied.

## Rate limiting and quotas

- Quotas are optional per user (`users.quota`, `NULL` = unlimited).
- `api/chat.php` refuses when the remaining quota is zero.
- Token usage is added from the agent's `usage.total_tokens`.
- For public deployments, add rate limiting at the web server (per IP and per
  session). The app does not include a full limiter.

## HTTPS and headers

- Serve only over HTTPS; the session cookie uses `Secure` automatically.
- The included `.htaccess` sets `X-Content-Type-Options`, `X-Frame-Options`
  and `Referrer-Policy`.
- Block `inc/`, `storage/` and dotfiles.

## Operational security

- Keep `.env` at `0640` and outside version control.
- Back up the database and `.env` together; the token hashes live in the DB.
- Do not run two bridges with the same token unless you want concurrency; the
  claim is atomic, but each job is processed once.
- Review the admin user list periodically; deactivate unused accounts.

## Incident response

1. Rotate the agent token in Admin.
2. Update `WEBCHAT_TOKEN` in the bridge and restart it.
3. Change the admin password.
4. Check `jobs`, `attachments` and `approvals` for unexpected activity.
5. Restore from backup if files were modified.
