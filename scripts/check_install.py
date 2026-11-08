#!/usr/bin/env python3
"""Check a Hermes Webchat installation.

Usage:
    python check_install.py --dir ./app
    python check_install.py --url https://yourdomain.com/chat
    python check_install.py --dir ./app --url https://yourdomain.com/chat

Exits 1 when errors are found.
"""

from __future__ import annotations

import argparse
import os
import re
import sys
import urllib.request
from pathlib import Path

OK, WARN, ERROR = "OK", "WARN", "ERROR"
results: list[tuple[str, str]] = []

REQUIRED_FILES = [
    "index.php",
    "login.php",
    "logout.php",
    "install.php",
    "schema.sql",
    "inc/bootstrap.php",
    "inc/agent.php",
    "inc/store.php",
    "api/chat.php",
    "api/job.php",
    "api/upload.php",
    "api/file.php",
    "api/approval.php",
    "api/agent/jobs.php",
    "api/agent/reply.php",
    "admin/index.php",
]

REQUIRED_ENV = [
    "WEBCHAT_APP_NAME",
    "DB_HOST",
    "DB_NAME",
    "DB_USER",
]

STORAGE_DIRS = ["sessions", "uploads", "attachments"]


def add(level: str, message: str) -> None:
    results.append((level, message))


def read_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def check_dir(root: Path) -> None:
    if not root.is_dir():
        add(ERROR, f"Folder not found: {root}")
        return

    for relative in REQUIRED_FILES:
        if (root / relative).is_file():
            add(OK, f"file {relative}")
        else:
            add(ERROR, f"missing file {relative}")

    env_file = root / ".env"
    if env_file.is_file():
        add(OK, ".env present")
        values = read_env(env_file)
        for key in REQUIRED_ENV:
            if values.get(key):
                add(OK, f".env has {key}")
            else:
                add(ERROR, f".env missing {key}")
        if values.get("DB_PASS", "") == "":
            add(WARN, "DB_PASS is empty")
        if values.get("WEBCHAT_PUBLIC_URL", "") == "":
            add(WARN, "WEBCHAT_PUBLIC_URL is empty (required by the bridge)")
    else:
        add(ERROR, ".env not found: run install.php")

    for folder in STORAGE_DIRS:
        path = root / "storage" / folder
        if path.is_dir():
            if os.access(path, os.W_OK):
                add(OK, f"storage/{folder} writable")
            else:
                add(ERROR, f"storage/{folder} is not writable")
        else:
            add(ERROR, f"missing storage/{folder}")

    if (root / "storage" / ".htaccess").is_file():
        add(OK, "storage protected by .htaccess")
    else:
        add(WARN, "storage/.htaccess missing (direct file access risk)")

    if (root / "schema.sql").is_file():
        sql = (root / "schema.sql").read_text(encoding="utf-8", errors="replace")
        tables = re.findall(r"CREATE TABLE IF NOT EXISTS\s+(\w+)", sql)
        add(OK, f"schema with {len(tables)} tables: {', '.join(tables)}")


def fetch(url: str) -> tuple[int, str]:
    request = urllib.request.Request(url, headers={"User-Agent": "webchat-check"})
    with urllib.request.urlopen(request, timeout=20) as response:
        return response.status, response.read().decode("utf-8", errors="replace")


def check_url(base: str) -> None:
    base = base.rstrip("/")
    for path, label in (("/login.php", "login"), ("/install.php", "installer")):
        try:
            status, body = fetch(base + path)
            if status == 200:
                add(OK, f"{label} reachable ({path})")
                if path == "/install.php" and "already installed" not in body.lower():
                    add(WARN, "install.php still shows the wizard: the app is not installed")
            else:
                add(ERROR, f"{label} returned HTTP {status}")
        except Exception as exc:
            add(ERROR, f"{label} unreachable: {exc}")

    try:
        status, _ = fetch(base + "/api/health.php")
        if status in (200, 401):
            add(OK, "API responds (session protection active)")
        else:
            add(WARN, f"api/health.php returned HTTP {status}")
    except Exception as exc:
        add(ERROR, f"API unreachable: {exc}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Check a Hermes Webchat install")
    parser.add_argument("--dir", help="Application folder")
    parser.add_argument("--url", help="Public base URL")
    args = parser.parse_args()

    if not args.dir and not args.url:
        parser.error("Provide --dir and/or --url")

    if args.dir:
        print(f"\n== Local check: {args.dir} ==")
        check_dir(Path(args.dir).resolve())
    if args.url:
        print(f"\n== Remote check: {args.url} ==")
        check_url(args.url)

    errors = 0
    for level, message in results:
        print(f"[{level}] {message}")
        if level == ERROR:
            errors += 1

    print(f"\nSummary: {len(results)} checks, {errors} errors")
    sys.exit(1 if errors else 0)


if __name__ == "__main__":
    main()
