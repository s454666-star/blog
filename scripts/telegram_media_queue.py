from __future__ import annotations

import argparse
import asyncio
import getpass
import hashlib
import json
import mimetypes
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from telethon import TelegramClient, utils
from telethon.errors import FloodWaitError, SessionPasswordNeededError
from telethon.errors.rpcerrorlist import ChatForwardsRestrictedError, MessageIdInvalidError
from telethon.tl.types import (
    DocumentAttributeAnimated,
    DocumentAttributeFilename,
    DocumentAttributeVideo,
    MessageEmpty,
)


APP_DIR = Path(__file__).resolve().parent
CONFIG_PATH = APP_DIR / "config.json"
STATE_PATH = APP_DIR / "state.json"
LOG_PATH = APP_DIR / "monitor.jsonl"
SESSION_PATH = APP_DIR / "s4546666.session"
PROCESSED_DB_PATH = APP_DIR / "processed.sqlite3"

VIDEO_FILE_EXTENSIONS = frozenset(
    {
        ".3g2",
        ".3gp",
        ".asf",
        ".avi",
        ".divx",
        ".dv",
        ".f4v",
        ".flv",
        ".h264",
        ".hevc",
        ".m2ts",
        ".m2v",
        ".m4v",
        ".mkv",
        ".mov",
        ".mp4",
        ".mpe",
        ".mpeg",
        ".mpg",
        ".mpv",
        ".mts",
        ".ogm",
        ".ogv",
        ".qt",
        ".rm",
        ".rmvb",
        ".ts",
        ".vob",
        ".webm",
        ".wmv",
    }
)

IMAGE_FILE_EXTENSIONS = frozenset(
    {".avif", ".bmp", ".gif", ".heic", ".heif", ".jfif", ".jpeg", ".jpg", ".png", ".tif", ".tiff", ".webp"}
)
ARCHIVE_FILE_EXTENSIONS = frozenset({".zip"})
PASSWORD_PATTERN = re.compile(
    r"(?:密碼|密码|password|pass(?:word)?|pwd|解壓碼|解压码)\s*[:：=]?\s*([^\s]{1,128})",
    flags=re.IGNORECASE,
)
BARE_PASSWORD_PATTERN = re.compile(r"\A\s*([@A-Za-z0-9._-]{4,64})\s*\Z")


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _read_json_dict(path: Path) -> dict[str, Any] | None:
    try:
        raw = path.read_bytes()
        if not raw or b"\x00" in raw:
            return None
        payload = json.loads(raw.decode("utf-8"))
        if not isinstance(payload, dict):
            return None
        return payload
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        return None


def _write_json_fsync(path: Path, payload: dict[str, Any]) -> None:
    with path.open("w", encoding="utf-8", newline="\n") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())


def atomic_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    previous = path.with_suffix(path.suffix + ".prev")
    previous_temporary = previous.with_suffix(previous.suffix + ".tmp")
    current = _read_json_dict(path) if path.exists() else None
    if current is not None:
        _write_json_fsync(previous_temporary, current)
        os.replace(previous_temporary, previous)
    _write_json_fsync(temporary, payload)
    os.replace(temporary, path)


def open_processed_db() -> sqlite3.Connection:
    connection = sqlite3.connect(PROCESSED_DB_PATH, timeout=30)
    connection.row_factory = sqlite3.Row
    connection.execute("PRAGMA journal_mode=WAL")
    connection.execute("PRAGMA synchronous=FULL")
    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS processed_messages (
            source_peer_id INTEGER NOT NULL,
            message_id INTEGER NOT NULL,
            source_alias TEXT NOT NULL,
            kind TEXT NOT NULL,
            status TEXT NOT NULL CHECK (status IN ('processing', 'completed', 'failed')),
            attempts INTEGER NOT NULL DEFAULT 0,
            media_items INTEGER NOT NULL DEFAULT 0,
            error_class TEXT,
            updated_at TEXT NOT NULL,
            completed_at TEXT,
            PRIMARY KEY (source_peer_id, message_id)
        )
        """
    )
    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS processed_archive_items (
            source_peer_id INTEGER NOT NULL,
            message_id INTEGER NOT NULL,
            item_key TEXT NOT NULL,
            kind TEXT NOT NULL CHECK (kind IN ('video', 'image')),
            status TEXT NOT NULL CHECK (status IN ('completed')),
            target_message_id INTEGER,
            anonymous_name TEXT,
            expected_size INTEGER NOT NULL,
            completed_at TEXT NOT NULL,
            PRIMARY KEY (source_peer_id, message_id, item_key),
            FOREIGN KEY (source_peer_id, message_id)
                REFERENCES processed_messages(source_peer_id, message_id)
        )
        """
    )
    connection.commit()
    return connection


def processed_message_complete(source_peer_id: int, message_id: int) -> bool:
    connection = open_processed_db()
    try:
        row = connection.execute(
            "SELECT status FROM processed_messages WHERE source_peer_id = ? AND message_id = ?",
            (source_peer_id, message_id),
        ).fetchone()
    finally:
        connection.close()
    return row is not None and str(row["status"]) == "completed"


def begin_processed_message(source_peer_id: int, message_id: int, source_alias: str, kind: str) -> None:
    now = utc_now()
    connection = open_processed_db()
    try:
        with connection:
            connection.execute(
            """
            INSERT INTO processed_messages (
                source_peer_id, message_id, source_alias, kind, status, attempts, updated_at
            ) VALUES (?, ?, ?, ?, 'processing', 1, ?)
            ON CONFLICT(source_peer_id, message_id) DO UPDATE SET
                source_alias = excluded.source_alias,
                kind = excluded.kind,
                status = 'processing',
                attempts = processed_messages.attempts + 1,
                error_class = NULL,
                updated_at = excluded.updated_at
            WHERE processed_messages.status <> 'completed'
            """,
                (source_peer_id, message_id, source_alias, kind, now),
            )
    finally:
        connection.close()


def finish_processed_message(source_peer_id: int, message_id: int, media_items: int) -> None:
    now = utc_now()
    connection = open_processed_db()
    try:
        with connection:
            connection.execute(
            """
            UPDATE processed_messages
            SET status = 'completed', media_items = ?, error_class = NULL,
                updated_at = ?, completed_at = ?
            WHERE source_peer_id = ? AND message_id = ?
            """,
                (media_items, now, now, source_peer_id, message_id),
            )
    finally:
        connection.close()


def fail_processed_message(source_peer_id: int, message_id: int, error_class: str) -> None:
    connection = open_processed_db()
    try:
        with connection:
            connection.execute(
            """
            UPDATE processed_messages
            SET status = 'failed', error_class = ?, updated_at = ?
            WHERE source_peer_id = ? AND message_id = ? AND status <> 'completed'
            """,
                (error_class[:120], utc_now(), source_peer_id, message_id),
            )
    finally:
        connection.close()


def archive_item_row(source_peer_id: int, message_id: int, item_key: str) -> sqlite3.Row | None:
    connection = open_processed_db()
    try:
        return connection.execute(
            """
            SELECT kind, target_message_id, anonymous_name, expected_size
            FROM processed_archive_items
            WHERE source_peer_id = ? AND message_id = ? AND item_key = ? AND status = 'completed'
            """,
            (source_peer_id, message_id, item_key),
        ).fetchone()
    finally:
        connection.close()


def finish_archive_item(
    source_peer_id: int,
    message_id: int,
    item_key: str,
    kind: str,
    expected_size: int,
    target_message_id: int | None = None,
    anonymous_name: str | None = None,
) -> None:
    connection = open_processed_db()
    try:
        with connection:
            connection.execute(
            """
            INSERT INTO processed_archive_items (
                source_peer_id, message_id, item_key, kind, status,
                target_message_id, anonymous_name, expected_size, completed_at
            ) VALUES (?, ?, ?, ?, 'completed', ?, ?, ?, ?)
            ON CONFLICT(source_peer_id, message_id, item_key) DO NOTHING
            """,
            (
                source_peer_id,
                message_id,
                item_key,
                kind,
                target_message_id,
                anonymous_name,
                expected_size,
                utc_now(),
                ),
            )
    finally:
        connection.close()


def safe_log(event: str, **fields: Any) -> None:
    allowed = {
        "source",
        "status",
        "kind",
        "count",
        "message_id",
        "target_message_id",
        "wait_seconds",
        "attempt",
        "return_code",
        "error_class",
        "pid",
    }
    row = {"at": utc_now(), "event": event}
    row.update({key: value for key, value in fields.items() if key in allowed})
    with LOG_PATH.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(row, ensure_ascii=False, separators=(",", ":")) + "\n")
        handle.flush()
        os.fsync(handle.fileno())


def load_config() -> dict[str, Any]:
    return json.loads(CONFIG_PATH.read_text(encoding="utf-8"))


def empty_source_state() -> dict[str, Any]:
    return {"last_scanned_id": 0, "videos": 0, "images": 0, "pending": {}}


def ensure_configured_sources(state: dict[str, Any]) -> dict[str, Any]:
    sources = state.setdefault("sources", {})
    for item in load_config().get("sources", []):
        alias = str(item.get("alias") or "")
        if not alias:
            continue
        source_state = sources.setdefault(alias, {})
        for key, value in empty_source_state().items():
            source_state.setdefault(key, value)
    return state


def default_state() -> dict[str, Any]:
    configured_sources = {
        str(item.get("alias")): empty_source_state()
        for item in load_config().get("sources", [])
        if item.get("alias")
    }
    return {
        "status": "new",
        "active_source": None,
        "cycle": 0,
        "sources": configured_sources,
    }


def recover_state_from_log() -> dict[str, Any]:
    state = default_state()
    successful: set[tuple[str, str, int]] = set()
    last_source: str | None = None
    if LOG_PATH.exists():
        for raw_line in LOG_PATH.read_bytes().splitlines():
            line = raw_line.replace(b"\x00", b"").strip()
            if not line.startswith(b"{") or not line.endswith(b"}"):
                continue
            try:
                row = json.loads(line.decode("utf-8"))
            except (UnicodeDecodeError, json.JSONDecodeError):
                continue
            if not isinstance(row, dict):
                continue
            if row.get("event") == "cycle_complete":
                state["cycle"] = max(int(state.get("cycle") or 0), int(row.get("count") or 0))
                continue
            if row.get("event") not in {"source_deleted", "salvaged_source_deleted"}:
                continue
            source = str(row.get("source") or "")
            kind = str(row.get("kind") or "")
            message_id = int(row.get("message_id") or 0)
            if source not in state["sources"] or kind not in {"video", "image"} or message_id <= 0:
                continue
            key = (source, kind, message_id)
            if key in successful:
                continue
            successful.add(key)
            source_state = state["sources"][source]
            source_state["last_scanned_id"] = max(int(source_state["last_scanned_id"]), message_id)
            source_state["videos" if kind == "video" else "images"] += 1
            last_source = source
    state["status"] = "state_recovered_from_log"
    state["active_source"] = last_source
    state["recovered_at"] = utc_now()
    return state


def augment_state_from_destination(state: dict[str, Any]) -> dict[str, Any]:
    try:
        config = load_config()
        target_dir = Path(config["download_dir"])
        if not target_dir.is_dir():
            return state
        anonymous_tokens = {
            path.stem.removeprefix("tg_")
            for path in target_dir.rglob("tg_*.*")
            if path.is_file() and re.fullmatch(r"tg_[0-9a-f]{20}\.[A-Za-z0-9]+", path.name)
        }
        if not anonymous_tokens:
            return state
        scan_max = max(1, int(config.get("state_recovery_scan_max_message_id", 100_000)))
        last_source: str | None = None
        for item in config.get("sources", []):
            source = str(item.get("alias") or "")
            if source not in state["sources"]:
                continue
            peer_id = int(item.get("peer_id") or 0)
            found_count = 0
            found_max = 0
            for message_id in range(1, scan_max + 1):
                token = hashlib.sha256(f"{peer_id}:{message_id}".encode("ascii")).hexdigest()[:20]
                if token in anonymous_tokens:
                    found_count += 1
                    found_max = message_id
            if found_count <= 0:
                continue
            source_state = state["sources"][source]
            # Revisit the highest moved video after recovery. If shutdown
            # happened between move and source deletion, the existing file is
            # reused and only the verified delete remains.
            source_state["last_scanned_id"] = max(
                int(source_state.get("last_scanned_id") or 0),
                max(0, found_max - 1),
            )
            source_state["videos"] = max(
                int(source_state.get("videos") or 0),
                max(0, found_count - 1),
            )
            source_state["destination_video_count"] = found_count
            source_state["destination_max_message_id"] = found_max
            last_source = source
        if last_source:
            state["active_source"] = last_source
            state["status"] = "state_recovered_from_log_and_destination"
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        pass
    return state


def load_state() -> dict[str, Any]:
    candidates = [
        STATE_PATH,
        STATE_PATH.with_suffix(STATE_PATH.suffix + ".tmp"),
        STATE_PATH.with_suffix(STATE_PATH.suffix + ".prev"),
    ]
    valid: list[tuple[int, Path, dict[str, Any]]] = []
    for candidate in candidates:
        payload = _read_json_dict(candidate) if candidate.exists() else None
        if payload is not None and isinstance(payload.get("sources"), dict):
            valid.append((candidate.stat().st_mtime_ns, candidate, payload))
    if valid:
        _, selected, payload = max(valid, key=lambda item: item[0])
        if selected != STATE_PATH:
            payload["status"] = "state_recovered_from_previous"
            payload["recovered_at"] = utc_now()
            safe_log("state_recovered", status="state_recovered_from_previous")
        return ensure_configured_sources(payload)
    payload = augment_state_from_destination(recover_state_from_log())
    safe_log("state_recovered", status="state_recovered_from_log")
    return payload


def save_state(state: dict[str, Any], status: str | None = None) -> None:
    if status is not None:
        state["status"] = status
    state["updated_at"] = utc_now()
    atomic_json(STATE_PATH, state)


def message_kind(message: Any) -> str | None:
    if getattr(message, "photo", None) is not None:
        return "image"
    document = getattr(message, "document", None)
    if document is None:
        return None
    mime = str(getattr(document, "mime_type", "") or "").lower()
    attributes = list(getattr(document, "attributes", None) or [])
    animated = any(isinstance(item, DocumentAttributeAnimated) for item in attributes)
    video = any(isinstance(item, DocumentAttributeVideo) for item in attributes)
    filename_extension = next(
        (
            Path(str(getattr(item, "file_name", "") or "")).suffix.lower()
            for item in attributes
            if isinstance(item, DocumentAttributeFilename)
        ),
        "",
    )
    if video and not animated:
        return "video"
    if not animated and (mime.startswith("video/") or filename_extension in VIDEO_FILE_EXTENSIONS):
        return "video"
    if mime.startswith("image/"):
        return "image"
    if filename_extension in ARCHIVE_FILE_EXTENSIONS or mime in {
        "application/zip",
        "application/x-zip",
        "application/x-zip-compressed",
    }:
        return "archive"
    return None


def marked_peer_id(entity: Any) -> int:
    return int(utils.get_peer_id(entity))


def tdl_peer_id(peer_id: int) -> int:
    # tdl resolves the bare Telegram ID for channels, users, and legacy chats.
    # Telethon marks channels as -100<ID> and legacy chats as -<ID>.
    if peer_id <= -1_000_000_000_000:
        return abs(peer_id) - 1_000_000_000_000
    if peer_id < 0:
        return abs(peer_id)
    return peer_id


def anonymous_video_name(peer_id: int, message_id: int, mime: str, downloaded: Path) -> str:
    token = hashlib.sha256(f"{peer_id}:{message_id}".encode("ascii")).hexdigest()[:20]
    mime_extension = mimetypes.guess_extension(mime or "")
    extension = (
        mime_extension
        if mime.startswith("video/") and mime_extension
        else downloaded.suffix or mime_extension or ".bin"
    )
    if len(extension) > 12 or not re.fullmatch(r"\.[A-Za-z0-9]+", extension):
        extension = ".bin"
    return f"tg_{token}{extension.lower()}"


def classify_tdl_error(raw: str) -> tuple[str, int | None]:
    flood_patterns = (
        r"FLOOD_WAIT[_ ](\d+)",
        r"(?:wait|retry after)\D{0,16}(\d+)\s*(?:seconds?|s)\b",
    )
    for pattern in flood_patterns:
        match = re.search(pattern, raw, flags=re.IGNORECASE)
        if match:
            return "flood_wait", max(1, int(match.group(1)))
    lowered = raw.lower()
    if "timeout" in lowered or "deadline exceeded" in lowered:
        return "timeout", None
    if "unauthorized" in lowered or "auth" in lowered and "key" in lowered:
        return "authorization", None
    return "tdl_failed", None


async def run_tdl(command: list[str], state: dict[str, Any], source_alias: str) -> None:
    creationflags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    process = await asyncio.create_subprocess_exec(
        *command,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
        creationflags=creationflags,
    )
    state["worker_pid"] = os.getpid()
    state["tdl_pid"] = process.pid
    save_state(state, "downloading")
    safe_log("download_started", source=source_alias, status="downloading", pid=process.pid)
    communication = asyncio.create_task(process.communicate())
    while not communication.done():
        done, _ = await asyncio.wait({communication}, timeout=30)
        if not done:
            safe_log("download_heartbeat", source=source_alias, status="downloading", pid=process.pid)
    stdout, stderr = await communication
    state.pop("tdl_pid", None)
    save_state(state, "running")
    if process.returncode == 0:
        safe_log("download_command_finished", source=source_alias, status="running", return_code=0)
        return
    raw = (stdout + b"\n" + stderr).decode("utf-8", errors="ignore")
    error_class, wait_seconds = classify_tdl_error(raw)
    safe_log(
        "download_command_failed",
        source=source_alias,
        status=error_class,
        return_code=process.returncode,
        error_class=error_class,
        wait_seconds=wait_seconds,
    )
    if wait_seconds:
        raise FloodWaitError(request=None, capture=wait_seconds)
    raise RuntimeError(error_class)


async def resolve_exact_dialogs(client: TelegramClient, config: dict[str, Any]) -> dict[str, Any]:
    requested = {
        item["alias"]: {"title": item["title"], "peer_id": int(item["peer_id"])}
        for item in config["sources"]
    }
    requested["image_target"] = {
        "title": config["image_target_title"],
        "peer_id": int(config["image_target_peer_id"]),
    }
    matches: dict[str, list[Any]] = {alias: [] for alias in requested}
    async for dialog in client.iter_dialogs():
        title = str(getattr(dialog, "name", "") or "")
        peer_id = marked_peer_id(dialog.entity)
        for alias, expected in requested.items():
            # Source display names can drift. Their marked peer IDs are the
            # durable routing boundary; keep the image target on title + ID.
            title_matches = alias != "image_target" or title == expected["title"]
            if title_matches and peer_id == expected["peer_id"]:
                matches[alias].append(dialog.entity)
    invalid = [alias for alias, entities in matches.items() if len(entities) != 1]
    if invalid:
        safe_log("dialog_resolution_blocked", status="blocked", count=len(invalid), error_class="dialog_match_count")
        raise RuntimeError("dialog_match_count")
    safe_log("dialog_resolution_ok", status="running", count=len(matches))
    return {alias: entities[0] for alias, entities in matches.items()}


async def source_message_deleted(client: TelegramClient, source: Any, message_id: int) -> bool:
    probe = await client.get_messages(source, ids=message_id)
    return probe is None or isinstance(probe, MessageEmpty)


async def delete_and_verify(client: TelegramClient, source: Any, message_id: int) -> None:
    await client.delete_messages(source, [message_id], revoke=True)
    await asyncio.sleep(1)
    if not await source_message_deleted(client, source, message_id):
        raise RuntimeError("source_delete_not_verified")


async def download_video(
    client: TelegramClient,
    source: Any,
    message: Any,
    source_alias: str,
    config: dict[str, Any],
    state: dict[str, Any],
) -> dict[str, Any]:
    peer_id = marked_peer_id(source)
    message_id = int(message.id)
    document = message.document
    expected_size = int(getattr(document, "size", 0) or 0)
    if expected_size <= 0:
        raise RuntimeError("invalid_expected_size")
    target_dir = Path(config["download_dir"])
    target_dir.mkdir(parents=True, exist_ok=True)
    pending = state["sources"][source_alias].setdefault("pending", {}).get(str(message_id))
    if pending and pending.get("kind") == "video":
        destination = target_dir / str(pending.get("anonymous_name") or "")
        if destination.is_file() and destination.stat().st_size == expected_size:
            return pending
        raise RuntimeError("pending_video_verification_failed")

    token = hashlib.sha256(f"{peer_id}:{message_id}".encode("ascii")).hexdigest()[:20]
    existing_candidates = [path for path in target_dir.glob(f"tg_{token}.*") if path.is_file()]
    existing_exact = [path for path in existing_candidates if path.stat().st_size == expected_size]
    if len(existing_exact) == 1:
        result = {
            "kind": "video",
            "anonymous_name": existing_exact[0].name,
            "expected_size": expected_size,
        }
        state["sources"][source_alias]["pending"][str(message_id)] = result
        save_state(state, "running")
        safe_log(
            "existing_video_reused",
            source=source_alias,
            status="running",
            kind="video",
            message_id=message_id,
        )
        return result
    if existing_candidates:
        raise RuntimeError("existing_destination_verification_failed")

    tdl = str(config["tdl_path"])
    namespace = str(config["tdl_namespace"])
    with tempfile.TemporaryDirectory(prefix="tdl-item-", dir=str(APP_DIR)) as temp_name:
        staging = Path(temp_name)
        export_path = staging / "item.json"
        download_path = staging / "download"
        download_path.mkdir()
        export_command = [
            tdl,
            "chat",
            "export",
            "-n",
            namespace,
            "-c",
            str(tdl_peer_id(peer_id)),
            "-T",
            "id",
            "-i",
            f"{message_id},{message_id}",
            "-o",
            str(export_path),
        ]
        await run_tdl(export_command, state, source_alias)
        exported = json.loads(export_path.read_text(encoding="utf-8"))
        exported_ids = [int(item.get("id") or 0) for item in exported.get("messages", [])]
        if int(exported.get("id") or 0) != tdl_peer_id(peer_id) or exported_ids != [message_id]:
            raise RuntimeError("single_message_export_verification_failed")
        download_command = [
            tdl,
            "download",
            "-n",
            namespace,
            "-f",
            str(export_path),
            "-d",
            str(download_path),
            "--skip-same",
            "-t",
            str(config.get("tdl_threads", 8)),
            "-l",
            "1",
        ]
        await run_tdl(download_command, state, source_alias)
        downloaded_files = [path for path in download_path.rglob("*") if path.is_file()]
        exact_files = [path for path in downloaded_files if path.stat().st_size == expected_size]
        if len(exact_files) != 1:
            raise RuntimeError("download_size_verification_failed")
        downloaded = exact_files[0]
        anonymous_name = anonymous_video_name(
            peer_id,
            message_id,
            str(getattr(document, "mime_type", "") or ""),
            downloaded,
        )
        destination = target_dir / anonymous_name
        if destination.exists():
            if destination.stat().st_size != expected_size:
                raise RuntimeError("destination_collision")
            downloaded.unlink()
        else:
            shutil.move(str(downloaded), str(destination))
        if not destination.is_file() or destination.stat().st_size != expected_size:
            raise RuntimeError("destination_verification_failed")

    result = {"kind": "video", "anonymous_name": anonymous_name, "expected_size": expected_size}
    state["sources"][source_alias]["pending"][str(message_id)] = result
    save_state(state, "running")
    safe_log("video_verified", source=source_alias, status="running", kind="video", message_id=message_id)
    return result


def archive_password_tokens(text: str) -> list[str]:
    values: list[str] = []
    for match in PASSWORD_PATTERN.finditer(text or ""):
        token = match.group(1).strip().strip("'\".,;，。；")
        if token and token not in values:
            values.append(token)
    return values


def archive_bare_password_token(text: str) -> str | None:
    match = BARE_PASSWORD_PATTERN.fullmatch(text or "")
    return match.group(1) if match else None


async def archive_password_candidates(
    client: TelegramClient,
    source: Any,
    message: Any,
    config: dict[str, Any],
) -> list[str]:
    values = archive_password_tokens(str(getattr(message, "message", "") or ""))
    lookup_count = max(0, min(5_000, int(config.get("archive_password_lookup_messages", 2_000))))
    if lookup_count:
        first_id = max(1, int(message.id) - lookup_count)
        last_id = int(message.id) + lookup_count
        nearby_messages = [
            nearby
            async for nearby in client.iter_messages(
                source,
                min_id=max(0, first_id - 1),
                max_id=last_id + 1,
                reverse=True,
            )
        ]
        for nearby in nearby_messages:
            for token in archive_password_tokens(str(getattr(nearby, "message", "") or "")):
                if token not in values:
                    values.append(token)
        for nearby in nearby_messages:
            token = archive_bare_password_token(str(getattr(nearby, "message", "") or ""))
            if token and token not in values:
                values.append(token)
    for token in config.get("archive_password_fallbacks", []):
        value = str(token)
        if value and value not in values:
            values.append(value)
    return values


def validate_zip_headers(archive_path: Path) -> None:
    try:
        with zipfile.ZipFile(archive_path) as archive:
            for item in archive.infolist():
                normalized = item.filename.replace("\\", "/")
                parts = [part for part in normalized.split("/") if part not in {"", "."}]
                if normalized.startswith("/") or ".." in parts or (parts and ":" in parts[0]):
                    raise RuntimeError("archive_unsafe_path")
                unix_mode = (item.external_attr >> 16) & 0o170000
                if unix_mode == 0o120000:
                    raise RuntimeError("archive_symlink_not_allowed")
    except zipfile.BadZipFile as error:
        raise RuntimeError("archive_invalid_zip") from error


async def extract_zip_once(archive_path: Path, output_dir: Path, seven_zip: str, password: str | None) -> bool:
    command = [seven_zip, "x", str(archive_path), f"-o{output_dir}", "-y", "-bd", "-bb0"]
    if password is not None:
        command.append(f"-p{password}")
    creationflags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    process = await asyncio.create_subprocess_exec(
        *command,
        stdin=asyncio.subprocess.DEVNULL,
        stdout=asyncio.subprocess.DEVNULL,
        stderr=asyncio.subprocess.DEVNULL,
        creationflags=creationflags,
    )
    await process.communicate()
    return process.returncode == 0


def extracted_file_kind(path: Path) -> str | None:
    extension = path.suffix.lower()
    if extension in VIDEO_FILE_EXTENSIONS:
        return "video"
    if extension in IMAGE_FILE_EXTENSIONS:
        return "image"
    guessed = str(mimetypes.guess_type(path.name)[0] or "").lower()
    if guessed.startswith("video/"):
        return "video"
    if guessed.startswith("image/"):
        return "image"
    return None


async def process_archive(
    client: TelegramClient,
    source: Any,
    image_target: Any,
    message: Any,
    source_alias: str,
    config: dict[str, Any],
    state: dict[str, Any],
) -> dict[str, int]:
    peer_id = marked_peer_id(source)
    message_id = int(message.id)
    document = message.document
    expected_size = int(getattr(document, "size", 0) or 0)
    if expected_size <= 0:
        raise RuntimeError("invalid_expected_size")
    work_root = Path(str(config.get("archive_work_dir") or (Path(config["download_dir"]) / ".archive-work")))
    work_root.mkdir(parents=True, exist_ok=True)
    seven_zip = str(config.get("seven_zip_path") or "")
    if not Path(seven_zip).is_file():
        raise RuntimeError("seven_zip_missing")

    with tempfile.TemporaryDirectory(prefix="tg-archive-", dir=str(work_root)) as temp_name:
        staging = Path(temp_name)
        export_path = staging / "item.json"
        download_path = staging / "download"
        download_path.mkdir()
        export_command = [
            str(config["tdl_path"]),
            "chat",
            "export",
            "-n",
            str(config["tdl_namespace"]),
            "-c",
            str(tdl_peer_id(peer_id)),
            "-T",
            "id",
            "-i",
            f"{message_id},{message_id}",
            "-o",
            str(export_path),
        ]
        await run_tdl(export_command, state, source_alias)
        exported = json.loads(export_path.read_text(encoding="utf-8"))
        exported_ids = [int(item.get("id") or 0) for item in exported.get("messages", [])]
        if int(exported.get("id") or 0) != tdl_peer_id(peer_id) or exported_ids != [message_id]:
            raise RuntimeError("single_message_export_verification_failed")
        download_command = [
            str(config["tdl_path"]),
            "download",
            "-n",
            str(config["tdl_namespace"]),
            "-f",
            str(export_path),
            "-d",
            str(download_path),
            "--skip-same",
            "-t",
            str(config.get("tdl_threads", 8)),
            "-l",
            "1",
        ]
        await run_tdl(download_command, state, source_alias)
        downloaded_files = [path for path in download_path.rglob("*") if path.is_file()]
        exact_files = [path for path in downloaded_files if path.stat().st_size == expected_size]
        if len(exact_files) != 1:
            raise RuntimeError("download_size_verification_failed")
        archive_path = exact_files[0]
        validate_zip_headers(archive_path)

        extracted_root: Path | None = None
        password_mode = "none"
        attempt_root = staging / "extract-none"
        attempt_root.mkdir()
        if await extract_zip_once(archive_path, attempt_root, seven_zip, None):
            extracted_root = attempt_root
        else:
            for index, password in enumerate(await archive_password_candidates(client, source, message, config), start=1):
                attempt_root = staging / f"extract-password-{index}"
                attempt_root.mkdir()
                if await extract_zip_once(archive_path, attempt_root, seven_zip, password):
                    extracted_root = attempt_root
                    password_mode = "password"
                    break
        if extracted_root is None:
            raise RuntimeError("archive_password_or_extract_failed")

        destination_dir = Path(config["download_dir"])
        destination_dir.mkdir(parents=True, exist_ok=True)
        files = sorted((path for path in extracted_root.rglob("*") if path.is_file()), key=lambda path: str(path.relative_to(extracted_root)))
        counts = {"video": 0, "image": 0}
        for ordinal, path in enumerate(files, start=1):
            resolved = path.resolve()
            if extracted_root.resolve() not in resolved.parents:
                raise RuntimeError("archive_extraction_escaped_root")
            kind = extracted_file_kind(path)
            if kind is None:
                continue
            expected_item_size = path.stat().st_size
            relative_token = hashlib.sha256(str(path.relative_to(extracted_root)).encode("utf-8")).hexdigest()[:20]
            item_key = hashlib.sha256(
                f"{peer_id}:{message_id}:{ordinal}:{relative_token}:{expected_item_size}".encode("ascii")
            ).hexdigest()[:32]
            existing = archive_item_row(peer_id, message_id, item_key)
            if existing is not None:
                if kind == "video":
                    destination = destination_dir / str(existing["anonymous_name"] or "")
                    if not destination.is_file() or destination.stat().st_size != int(existing["expected_size"]):
                        raise RuntimeError("archive_item_ledger_verification_failed")
                else:
                    target_id = int(existing["target_message_id"] or 0)
                    target_probe = await client.get_messages(image_target, ids=target_id)
                    if target_probe is None or message_kind(target_probe) != "image":
                        raise RuntimeError("archive_item_ledger_verification_failed")
                counts[kind] += 1
                continue
            if kind == "video":
                extension = path.suffix.lower()
                if extension not in VIDEO_FILE_EXTENSIONS:
                    extension = ".bin"
                anonymous_name = f"tg_{hashlib.sha256(f'{peer_id}:{message_id}:{item_key}'.encode('ascii')).hexdigest()[:20]}{extension}"
                destination = destination_dir / anonymous_name
                if destination.exists():
                    if destination.stat().st_size != expected_item_size:
                        raise RuntimeError("destination_collision")
                else:
                    shutil.move(str(path), str(destination))
                if not destination.is_file() or destination.stat().st_size != expected_item_size:
                    raise RuntimeError("destination_verification_failed")
                finish_archive_item(
                    peer_id,
                    message_id,
                    item_key,
                    "video",
                    expected_item_size,
                    anonymous_name=anonymous_name,
                )
            else:
                sent = await client.send_file(image_target, str(path), caption=None)
                if isinstance(sent, list):
                    sent = sent[0] if len(sent) == 1 else None
                target_message_id = int(getattr(sent, "id", 0) or 0)
                target_probe = await client.get_messages(image_target, ids=target_message_id)
                if target_message_id <= 0 or target_probe is None or message_kind(target_probe) != "image":
                    raise RuntimeError("archive_image_forward_not_verified")
                finish_archive_item(
                    peer_id,
                    message_id,
                    item_key,
                    "image",
                    expected_item_size,
                    target_message_id=target_message_id,
                )
            counts[kind] += 1
        if counts["video"] + counts["image"] <= 0:
            raise RuntimeError("archive_no_supported_media")
        safe_log(
            "archive_processed",
            source=source_alias,
            status=password_mode,
            kind="archive",
            message_id=message_id,
            count=counts["video"] + counts["image"],
        )
        return counts


async def forward_image(
    client: TelegramClient,
    source: Any,
    target: Any,
    message: Any,
    source_alias: str,
    state: dict[str, Any],
) -> dict[str, Any]:
    message_id = int(message.id)
    pending = state["sources"][source_alias].setdefault("pending", {}).get(str(message_id))
    if pending and pending.get("kind") == "image":
        target_message_id = int(pending.get("target_message_id") or 0)
        target_probe = await client.get_messages(target, ids=target_message_id)
        if target_probe is not None and message_kind(target_probe) == "image":
            return pending
        raise RuntimeError("pending_image_verification_failed")

    try:
        sent = await client.forward_messages(target, message_id, source)
    except (ChatForwardsRestrictedError, MessageIdInvalidError):
        extension = ".jpg" if getattr(message, "photo", None) is not None else mimetypes.guess_extension(
            str(getattr(getattr(message, "document", None), "mime_type", "") or "")
        ) or ".bin"
        if not re.fullmatch(r"\.[A-Za-z0-9]{1,10}", extension):
            extension = ".bin"
        expected_size = int(getattr(getattr(message, "document", None), "size", 0) or 0)
        if expected_size <= 0:
            photo_sizes = list(getattr(getattr(message, "photo", None), "sizes", None) or [])
            expected_size = max(
                [
                    max(
                        [int(getattr(size, "size", 0) or 0)]
                        + [int(value) for value in (getattr(size, "sizes", None) or [])]
                    )
                    for size in photo_sizes
                ]
                + [0]
            )
        with tempfile.TemporaryDirectory(prefix="tg-image-", dir=str(APP_DIR)) as temp_name:
            local_path = Path(temp_name) / f"image{extension.lower()}"
            downloaded_path = await client.download_media(message, file=str(local_path))
            downloaded = Path(str(downloaded_path or local_path))
            if not downloaded.is_file() or downloaded.stat().st_size <= 0:
                raise RuntimeError("protected_image_download_failed")
            if expected_size > 0 and downloaded.stat().st_size != expected_size:
                raise RuntimeError("protected_image_size_verification_failed")
            sent = await client.send_file(target, str(downloaded), caption=None, force_document=False)
    if isinstance(sent, list):
        sent = sent[0] if len(sent) == 1 else None
    target_message_id = int(getattr(sent, "id", 0) or 0)
    if target_message_id <= 0:
        raise RuntimeError("image_forward_result_missing")
    target_probe = await client.get_messages(target, ids=target_message_id)
    if target_probe is None or message_kind(target_probe) != "image":
        raise RuntimeError("image_forward_not_verified")
    result = {"kind": "image", "target_message_id": target_message_id}
    state["sources"][source_alias]["pending"][str(message_id)] = result
    save_state(state, "running")
    safe_log(
        "image_forward_verified",
        source=source_alias,
        status="running",
        kind="image",
        message_id=message_id,
        target_message_id=target_message_id,
    )
    return result


async def process_message(
    client: TelegramClient,
    source: Any,
    image_target: Any,
    message: Any,
    source_alias: str,
    config: dict[str, Any],
    state: dict[str, Any],
) -> None:
    kind = message_kind(message)
    message_id = int(message.id)
    if kind is None:
        return
    source_state = state["sources"][source_alias]
    peer_id = marked_peer_id(source)
    if processed_message_complete(peer_id, message_id):
        source_state["last_scanned_id"] = max(int(source_state["last_scanned_id"]), message_id)
        save_state(state, "running")
        safe_log("processed_message_skipped", source=source_alias, status="completed", kind=kind, message_id=message_id)
        return
    begin_processed_message(peer_id, message_id, source_alias, kind)
    try:
        counts = {"video": 0, "image": 0}
        if kind == "video":
            await download_video(client, source, message, source_alias, config, state)
            counts["video"] = 1
        elif kind == "image":
            await forward_image(client, source, image_target, message, source_alias, state)
            counts["image"] = 1
        else:
            counts = await process_archive(client, source, image_target, message, source_alias, config, state)
        source_config = next(item for item in config["sources"] if str(item["alias"]) == source_alias)
        delete_source = bool(source_config.get("delete_source", True))
        if delete_source:
            await delete_and_verify(client, source, message_id)
        finish_processed_message(peer_id, message_id, counts["video"] + counts["image"])
        source_state["pending"].pop(str(message_id), None)
        source_state["last_scanned_id"] = max(int(source_state["last_scanned_id"]), message_id)
        source_state["videos"] += counts["video"]
        source_state["images"] += counts["image"]
        save_state(state, "running")
        safe_log(
            "source_deleted" if delete_source else "source_retained_completed",
            source=source_alias,
            status="running",
            kind=kind,
            message_id=message_id,
            count=counts["video"] + counts["image"],
        )
    except Exception as error:
        runtime_code = str(error)
        error_class = (
            runtime_code
            if isinstance(error, RuntimeError) and re.fullmatch(r"[a-z0-9_]{3,120}", runtime_code)
            else type(error).__name__
        )
        fail_processed_message(peer_id, message_id, error_class)
        safe_log(
            "message_failed",
            source=source_alias,
            status="failed",
            kind=kind,
            message_id=message_id,
            error_class=error_class,
        )
        raise


async def process_source(
    client: TelegramClient,
    source: Any,
    image_target: Any,
    source_alias: str,
    config: dict[str, Any],
    state: dict[str, Any],
) -> None:
    source_state = state["sources"][source_alias]
    latest = await client.get_messages(source, limit=1)
    snapshot_max = int(latest[0].id) if latest else int(source_state["last_scanned_id"])
    state["active_source"] = source_alias
    save_state(state, "scanning")
    safe_log("source_scan_started", source=source_alias, status="scanning", message_id=snapshot_max)
    processed = 0
    async for message in client.iter_messages(
        source,
        min_id=int(source_state["last_scanned_id"]),
        max_id=snapshot_max + 1,
        reverse=True,
    ):
        message_id = int(message.id)
        await process_message(client, source, image_target, message, source_alias, config, state)
        if message_kind(message) is None:
            source_state["last_scanned_id"] = max(int(source_state["last_scanned_id"]), message_id)
            if message_id % 100 == 0:
                save_state(state, "scanning")
        processed += 1
    source_state["last_scanned_id"] = max(int(source_state["last_scanned_id"]), snapshot_max)
    save_state(state, "source_complete")
    safe_log("source_scan_complete", source=source_alias, status="source_complete", count=processed)


async def login(config: dict[str, Any]) -> None:
    client = TelegramClient(str(SESSION_PATH.with_suffix("")), int(config["api_id"]), str(config["api_hash"]))
    await client.connect()
    try:
        if await client.is_user_authorized():
            print("Telethon 工作階段已登入。")
            return
        phone = str(config["phone"])
        await client.send_code_request(phone)
        code = input("請輸入 Telegram 一次性驗證碼：").strip()
        try:
            await client.sign_in(phone=phone, code=code)
        except SessionPasswordNeededError:
            password = getpass.getpass("請輸入 Telegram 兩步驗證密碼：")
            await client.sign_in(password=password)
        if not await client.is_user_authorized():
            raise RuntimeError("authorization_not_completed")
        print("Telethon 工作階段登入完成。")
    finally:
        await client.disconnect()


async def run_worker(config: dict[str, Any], once: bool) -> None:
    state = load_state()
    state["worker_pid"] = os.getpid()
    save_state(state, "starting")
    client = TelegramClient(str(SESSION_PATH.with_suffix("")), int(config["api_id"]), str(config["api_hash"]))
    await client.connect()
    if not await client.is_user_authorized():
        save_state(state, "waiting_login")
        safe_log("worker_waiting_login", status="waiting_login")
        await client.disconnect()
        raise SystemExit(10)
    try:
        dialogs = await resolve_exact_dialogs(client, config)
        while True:
            try:
                for item in config["sources"]:
                    alias = str(item["alias"])
                    await process_source(client, dialogs[alias], dialogs["image_target"], alias, config, state)
                state["cycle"] = int(state.get("cycle") or 0) + 1
                state["active_source"] = None
                save_state(state, "idle")
                safe_log("cycle_complete", status="idle", count=state["cycle"])
                if once:
                    return
                await asyncio.sleep(int(config.get("rescan_seconds", 300)))
            except FloodWaitError as error:
                wait_seconds = max(1, int(getattr(error, "seconds", 60) or 60)) + 5
                save_state(state, "flood_wait")
                safe_log("flood_wait", status="flood_wait", wait_seconds=wait_seconds)
                await asyncio.sleep(wait_seconds)
                save_state(state, "running")
            except Exception as error:
                error_class = type(error).__name__
                retry_seconds = max(60, int(config.get("error_retry_seconds", 300)))
                save_state(state, "retry_wait")
                safe_log("retry_wait", status="retry_wait", error_class=error_class, wait_seconds=retry_seconds)
                await asyncio.sleep(retry_seconds)
                save_state(state, "running")
    finally:
        state.pop("tdl_pid", None)
        state.pop("worker_pid", None)
        save_state(state, "stopped")
        await client.disconnect()


async def check_ready(config: dict[str, Any]) -> None:
    client = TelegramClient(str(SESSION_PATH.with_suffix("")), int(config["api_id"]), str(config["api_hash"]))
    await client.connect()
    try:
        authorized = await client.is_user_authorized()
        exact_match_counts: dict[str, int] = {}
        if authorized:
            requested = {
                item["alias"]: {"title": item["title"], "peer_id": int(item["peer_id"])}
                for item in config["sources"]
            }
            requested["image_target"] = {
                "title": config["image_target_title"],
                "peer_id": int(config["image_target_peer_id"]),
            }
            exact_match_counts = {alias: 0 for alias in requested}
            async for dialog in client.iter_dialogs():
                title = str(getattr(dialog, "name", "") or "")
                peer_id = marked_peer_id(dialog.entity)
                for alias, expected in requested.items():
                    title_matches = alias != "image_target" or title == expected["title"]
                    if title_matches and peer_id == expected["peer_id"]:
                        exact_match_counts[alias] += 1
        print(
            json.dumps(
                {"authorized": authorized, "exact_match_counts": exact_match_counts},
                ensure_ascii=False,
                separators=(",", ":"),
            )
        )
    finally:
        await client.disconnect()


async def print_candidates(config: dict[str, Any]) -> None:
    client = TelegramClient(str(SESSION_PATH.with_suffix("")), int(config["api_id"]), str(config["api_hash"]))
    await client.connect()
    try:
        requested = {item["title"]: item["alias"] for item in config["sources"]}
        requested[config["image_target_title"]] = "image_target"
        candidates: dict[str, list[dict[str, Any]]] = {alias: [] for alias in requested.values()}
        async for dialog in client.iter_dialogs():
            alias = requested.get(str(getattr(dialog, "name", "") or ""))
            if not alias:
                continue
            entity = dialog.entity
            admin_rights = getattr(entity, "admin_rights", None)
            default_banned = getattr(entity, "default_banned_rights", None)
            latest = await client.get_messages(entity, limit=1)
            latest_id = int(latest[0].id) if latest else 0
            candidates[alias].append(
                {
                    "peer_id": marked_peer_id(entity),
                    "kind": type(entity).__name__,
                    "creator": bool(getattr(entity, "creator", False)),
                    "admin_delete": bool(getattr(admin_rights, "delete_messages", False)),
                    "send_blocked": bool(getattr(default_banned, "send_messages", False)),
                    "megagroup": bool(getattr(entity, "megagroup", False)),
                    "broadcast": bool(getattr(entity, "broadcast", False)),
                    "latest_message_id": latest_id,
                }
            )
        print(json.dumps(candidates, ensure_ascii=False, separators=(",", ":")))
    finally:
        await client.disconnect()


async def print_inventory(config: dict[str, Any]) -> None:
    client = TelegramClient(str(SESSION_PATH.with_suffix("")), int(config["api_id"]), str(config["api_hash"]))
    await client.connect()
    try:
        dialogs = await resolve_exact_dialogs(client, config)
        output: dict[str, Any] = {}
        max_messages = max(100, min(10_000, int(config.get("inventory_max_messages", 2_000))))
        for item in config["sources"]:
            if bool(item.get("delete_source", True)):
                continue
            alias = str(item["alias"])
            counts = {"video": 0, "image": 0, "archive": 0}
            samples: dict[str, list[int]] = {"video": [], "image": [], "archive": []}
            scanned = 0
            async for message in client.iter_messages(dialogs[alias], limit=max_messages):
                scanned += 1
                kind = message_kind(message)
                if kind not in counts:
                    continue
                counts[kind] += 1
                if len(samples[kind]) < 3:
                    samples[kind].append(int(message.id))
            output[alias] = {
                "scanned_recent_messages": scanned,
                "media_counts_in_sample": counts,
                "sample_message_ids": samples,
            }
        print(json.dumps(output, ensure_ascii=False, separators=(",", ":")))
    finally:
        await client.disconnect()


async def process_exact_messages(config: dict[str, Any], requests: list[str]) -> None:
    parsed: list[tuple[str, int]] = []
    configured_aliases = {str(item["alias"]) for item in config["sources"]}
    for value in requests:
        alias, separator, raw_message_id = value.partition(":")
        if separator != ":" or alias not in configured_aliases or not raw_message_id.isdigit():
            raise RuntimeError("invalid_process_request")
        parsed.append((alias, int(raw_message_id)))
    state = load_state()
    original_cursors = {
        alias: int(state["sources"][alias].get("last_scanned_id") or 0)
        for alias, _ in parsed
    }
    client = TelegramClient(str(SESSION_PATH.with_suffix("")), int(config["api_id"]), str(config["api_hash"]))
    await client.connect()
    if not await client.is_user_authorized():
        await client.disconnect()
        raise RuntimeError("authorization_required")
    try:
        dialogs = await resolve_exact_dialogs(client, config)
        results: list[dict[str, Any]] = []
        for alias, message_id in parsed:
            message = await client.get_messages(dialogs[alias], ids=message_id)
            if message is None or isinstance(message, MessageEmpty):
                raise RuntimeError("requested_message_missing")
            kind = message_kind(message)
            if kind is None:
                raise RuntimeError("requested_message_not_supported_media")
            await process_message(client, dialogs[alias], dialogs["image_target"], message, alias, config, state)
            results.append({"source": alias, "message_id": message_id, "kind": kind, "status": "completed"})
        print(json.dumps(results, ensure_ascii=False, separators=(",", ":")))
    finally:
        for alias, cursor in original_cursors.items():
            state["sources"][alias]["last_scanned_id"] = cursor
        save_state(state, "stopped")
        await client.disconnect()


def print_status() -> None:
    state = load_state()
    summary = {
        "status": state.get("status"),
        "active_source": state.get("active_source"),
        "cycle": state.get("cycle", 0),
        "worker_pid": state.get("worker_pid"),
        "tdl_pid": state.get("tdl_pid"),
        "updated_at": state.get("updated_at"),
        "sources": {
            alias: {
                "last_scanned_id": value.get("last_scanned_id", 0),
                "videos": value.get("videos", 0),
                "images": value.get("images", 0),
                "pending_count": len(value.get("pending", {})),
            }
            for alias, value in state.get("sources", {}).items()
        },
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))


def run_resilient(config: dict[str, Any]) -> None:
    while True:
        try:
            asyncio.run(run_worker(config, False))
        except Exception as error:
            retry_seconds = max(60, int(config.get("error_retry_seconds", 300)))
            safe_log(
                "worker_top_level_retry",
                status="retry_wait",
                error_class=type(error).__name__,
                wait_seconds=retry_seconds,
            )
            time.sleep(retry_seconds)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--login", action="store_true")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--status", action="store_true")
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--candidates", action="store_true")
    parser.add_argument("--inventory", action="store_true")
    parser.add_argument("--process", action="append", default=[])
    args = parser.parse_args()
    if args.status:
        print_status()
        return
    config = load_config()
    if args.check:
        asyncio.run(check_ready(config))
        return
    if args.candidates:
        asyncio.run(print_candidates(config))
        return
    if args.inventory:
        asyncio.run(print_inventory(config))
        return
    if args.process:
        asyncio.run(process_exact_messages(config, args.process))
        return
    if args.login:
        asyncio.run(login(config))
    elif args.once:
        asyncio.run(run_worker(config, True))
    else:
        run_resilient(config)


if __name__ == "__main__":
    main()
