#!/usr/bin/env python3
"""Continuously route Telegram group media by kind using Telegram forwarding.

Configuration and resumable cursors live in a SQLite database so source groups
can be added without editing code or restarting the service.
"""

from __future__ import annotations

import argparse
import json
import os
import sqlite3
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from typing import Any


DEFAULT_DATABASE_PATH = "/var/lib/blog-telegram/media-router.sqlite3"
DEFAULT_BASE_URI = "http://127.0.0.1:8004"
DEFAULT_DEDUPE_SCOPE = "resource_merge_combined"
IMAGE_KINDS = {"photo", "image_document"}
VIDEO_KINDS = {"video", "video_document"}
TERMINAL_ITEM_STATUSES = {"completed", "duplicate", "ignored"}


class RouterBlocked(RuntimeError):
    pass


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


class Api:
    def __init__(self, base_uri: str):
        self.base_uri = base_uri.rstrip("/")

    def request(
        self,
        method: str,
        path: str,
        payload: dict[str, Any] | None = None,
        timeout: float = 180.0,
    ) -> dict[str, Any]:
        data = None
        headers: dict[str, str] = {}
        if payload is not None:
            data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(
            self.base_uri + path,
            data=data,
            headers=headers,
            method=method,
        )
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return json.loads(response.read().decode("utf-8"))

    def get(self, path: str, timeout: float = 180.0) -> dict[str, Any]:
        return self.request("GET", path, timeout=timeout)

    def post(
        self,
        path: str,
        payload: dict[str, Any],
        timeout: float = 7200.0,
    ) -> dict[str, Any]:
        return self.request("POST", path, payload=payload, timeout=timeout)


def connect_database(path: str) -> sqlite3.Connection:
    absolute_path = os.path.abspath(path)
    parent = os.path.dirname(absolute_path)
    if parent:
        os.makedirs(parent, exist_ok=True)
    connection = sqlite3.connect(absolute_path, timeout=30)
    connection.row_factory = sqlite3.Row
    connection.execute("PRAGMA journal_mode=WAL")
    connection.execute("PRAGMA synchronous=FULL")
    connection.execute("PRAGMA foreign_keys=ON")
    connection.executescript(
        """
        CREATE TABLE IF NOT EXISTS router_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            base_uri TEXT NOT NULL,
            video_target_peer_id INTEGER NOT NULL,
            image_target_peer_id INTEGER NOT NULL,
            dedupe_scope TEXT NOT NULL,
            scan_limit INTEGER NOT NULL DEFAULT 40,
            idle_seconds REAL NOT NULL DEFAULT 15,
            enabled INTEGER NOT NULL DEFAULT 1,
            video_target_cursor INTEGER NOT NULL DEFAULT 0,
            image_target_cursor INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS router_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            peer_id INTEGER NOT NULL UNIQUE,
            title TEXT NOT NULL DEFAULT '',
            enabled INTEGER NOT NULL DEFAULT 1,
            last_message_id INTEGER NOT NULL DEFAULT 0,
            initial_scan_completed_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS router_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_peer_id INTEGER NOT NULL,
            source_message_id INTEGER NOT NULL,
            media_kind TEXT,
            content_sha256 TEXT,
            file_unique_id TEXT,
            target_peer_id INTEGER,
            target_message_id INTEGER,
            status TEXT NOT NULL,
            is_duplicate INTEGER NOT NULL DEFAULT 0,
            duplicate_of_item_id INTEGER,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (source_peer_id, source_message_id)
        );

        CREATE INDEX IF NOT EXISTS router_items_status_index
            ON router_items (status, updated_at);

        CREATE TABLE IF NOT EXISTS router_target_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_peer_id INTEGER NOT NULL,
            target_message_id INTEGER NOT NULL,
            media_kind TEXT NOT NULL,
            content_sha256 TEXT,
            canonical_target_message_id INTEGER,
            status TEXT NOT NULL,
            last_error TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (target_peer_id, target_message_id)
        );
        """
    )
    ensure_column(connection, "router_items", "file_unique_id", "TEXT")
    ensure_column(connection, "router_items", "duplicate_of_item_id", "INTEGER")
    connection.execute(
        """
        CREATE UNIQUE INDEX IF NOT EXISTS router_items_completed_file_unique_index
            ON router_items (file_unique_id)
            WHERE file_unique_id IS NOT NULL AND status = 'completed'
        """
    )
    connection.commit()
    return connection


def ensure_column(
    connection: sqlite3.Connection,
    table_name: str,
    column_name: str,
    column_definition: str,
) -> None:
    columns = {
        str(row["name"])
        for row in connection.execute(f"PRAGMA table_info({table_name})").fetchall()
    }
    if column_name not in columns:
        connection.execute(
            f"ALTER TABLE {table_name} ADD COLUMN {column_name} {column_definition}"
        )


def configure_router(
    connection: sqlite3.Connection,
    *,
    base_uri: str,
    video_target_peer_id: int,
    image_target_peer_id: int,
    dedupe_scope: str,
    scan_limit: int,
    idle_seconds: float,
) -> None:
    if video_target_peer_id <= 0 or image_target_peer_id <= 0:
        raise RouterBlocked("both target peer ids must be positive")
    if video_target_peer_id == image_target_peer_id:
        raise RouterBlocked("video and image targets must be different")
    if not dedupe_scope or len(dedupe_scope) > 100:
        raise RouterBlocked("dedupe scope is invalid")
    timestamp = now_iso()
    connection.execute(
        """
        INSERT INTO router_settings (
            id, base_uri, video_target_peer_id, image_target_peer_id,
            dedupe_scope, scan_limit, idle_seconds, enabled,
            video_target_cursor, image_target_cursor, created_at, updated_at
        ) VALUES (1, ?, ?, ?, ?, ?, ?, 1, 0, 0, ?, ?)
        ON CONFLICT(id) DO UPDATE SET
            base_uri = excluded.base_uri,
            video_target_cursor = CASE
                WHEN router_settings.video_target_peer_id <> excluded.video_target_peer_id
                    OR router_settings.dedupe_scope <> excluded.dedupe_scope
                THEN 0 ELSE router_settings.video_target_cursor
            END,
            image_target_cursor = CASE
                WHEN router_settings.image_target_peer_id <> excluded.image_target_peer_id
                    OR router_settings.dedupe_scope <> excluded.dedupe_scope
                THEN 0 ELSE router_settings.image_target_cursor
            END,
            video_target_peer_id = excluded.video_target_peer_id,
            image_target_peer_id = excluded.image_target_peer_id,
            dedupe_scope = excluded.dedupe_scope,
            scan_limit = excluded.scan_limit,
            idle_seconds = excluded.idle_seconds,
            updated_at = excluded.updated_at
        """,
        (
            base_uri.rstrip("/"),
            int(video_target_peer_id),
            int(image_target_peer_id),
            dedupe_scope,
            max(1, min(int(scan_limit), 100)),
            max(1.0, float(idle_seconds)),
            timestamp,
            timestamp,
        ),
    )
    connection.commit()


def add_source(connection: sqlite3.Connection, peer_id: int, title: str) -> None:
    if int(peer_id) <= 0:
        raise RouterBlocked("source peer id must be positive")
    timestamp = now_iso()
    connection.execute(
        """
        INSERT INTO router_sources (
            peer_id, title, enabled, last_message_id, created_at, updated_at
        ) VALUES (?, ?, 1, 0, ?, ?)
        ON CONFLICT(peer_id) DO UPDATE SET
            title = CASE
                WHEN excluded.title <> '' THEN excluded.title
                ELSE router_sources.title
            END,
            enabled = 1,
            updated_at = excluded.updated_at
        """,
        (int(peer_id), str(title or "").strip(), timestamp, timestamp),
    )
    connection.commit()


def set_source_enabled(
    connection: sqlite3.Connection,
    peer_id: int,
    enabled: bool,
) -> None:
    cursor = connection.execute(
        "UPDATE router_sources SET enabled = ?, updated_at = ? WHERE peer_id = ?",
        (1 if enabled else 0, now_iso(), int(peer_id)),
    )
    connection.commit()
    if cursor.rowcount != 1:
        raise RouterBlocked(f"source peer {peer_id} is not configured")


def row_to_dict(row: sqlite3.Row | None) -> dict[str, Any] | None:
    return dict(row) if row is not None else None


def router_status(connection: sqlite3.Connection, database_path: str) -> dict[str, Any]:
    settings = row_to_dict(
        connection.execute("SELECT * FROM router_settings WHERE id = 1").fetchone()
    )
    sources = [
        dict(row)
        for row in connection.execute(
            "SELECT * FROM router_sources ORDER BY id"
        ).fetchall()
    ]
    item_counts = {
        str(row["status"]): int(row["count"])
        for row in connection.execute(
            "SELECT status, COUNT(*) AS count FROM router_items GROUP BY status"
        ).fetchall()
    }
    target_counts = {
        str(row["status"]): int(row["count"])
        for row in connection.execute(
            "SELECT status, COUNT(*) AS count FROM router_target_items GROUP BY status"
        ).fetchall()
    }
    return {
        "database_path": os.path.abspath(database_path),
        "settings": settings,
        "sources": sources,
        "item_counts": item_counts,
        "target_item_counts": target_counts,
    }


class Router:
    def __init__(
        self,
        connection: sqlite3.Connection,
        database_path: str,
        api: Api | None = None,
    ):
        self.connection = connection
        self.database_path = os.path.abspath(database_path)
        self._api = api

    def log(self, message: str, **fields: Any) -> None:
        print(
            json.dumps(
                {"at": now_iso(), "message": message, **fields},
                ensure_ascii=False,
                separators=(",", ":"),
            ),
            flush=True,
        )

    def settings(self) -> sqlite3.Row:
        row = self.connection.execute(
            "SELECT * FROM router_settings WHERE id = 1"
        ).fetchone()
        if row is None:
            raise RouterBlocked("router is not configured")
        return row

    def api(self, settings: sqlite3.Row) -> Api:
        if self._api is not None:
            return self._api
        return Api(str(settings["base_uri"]))

    @staticmethod
    def routed_kind(raw_kind: Any) -> str | None:
        kind = str(raw_kind or "").strip()
        if kind in IMAGE_KINDS:
            return "image"
        if kind in VIDEO_KINDS:
            return "video"
        return None

    @staticmethod
    def normalized_file_unique_id(raw_file_unique_id: Any) -> str | None:
        value = str(raw_file_unique_id or "").strip()
        if not value:
            return None
        return value[:255]

    def find_completed_file_duplicate(
        self,
        file_unique_id: str | None,
    ) -> sqlite3.Row | None:
        normalized = self.normalized_file_unique_id(file_unique_id)
        if normalized is None:
            return None
        return self.connection.execute(
            """
            SELECT *
            FROM router_items
            WHERE file_unique_id = ?
              AND status = 'completed'
              AND target_peer_id IS NOT NULL
              AND target_message_id IS NOT NULL
            ORDER BY id
            LIMIT 1
            """,
            (normalized,),
        ).fetchone()

    def group_messages(
        self,
        api: Api,
        peer_id: int,
        min_id: int,
        limit: int,
    ) -> list[dict[str, Any]]:
        query = urllib.parse.urlencode(
            {
                "limit": max(1, min(int(limit), 100)),
                "min_id": max(0, int(min_id)),
                "reverse": "true",
                "include_raw": "false",
                "include_text": "false",
            }
        )
        response = api.get(f"/groups/{int(peer_id)}?{query}")
        if response.get("status") != "ok":
            raise RouterBlocked(
                f"cannot read peer {peer_id}: {response.get('reason') or response}"
            )
        return sorted(
            [item for item in list(response.get("items") or []) if isinstance(item, dict)],
            key=lambda item: int(item.get("id") or 0),
        )

    def record_target_item(
        self,
        *,
        target_peer_id: int,
        target_message_id: int,
        media_kind: str,
        content_sha256: str,
        canonical_target_message_id: int,
        status: str,
        last_error: str | None = None,
    ) -> None:
        timestamp = now_iso()
        self.connection.execute(
            """
            INSERT INTO router_target_items (
                target_peer_id, target_message_id, media_kind,
                content_sha256, canonical_target_message_id,
                status, last_error, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(target_peer_id, target_message_id) DO UPDATE SET
                media_kind = excluded.media_kind,
                content_sha256 = excluded.content_sha256,
                canonical_target_message_id = excluded.canonical_target_message_id,
                status = excluded.status,
                last_error = excluded.last_error,
                updated_at = excluded.updated_at
            """,
            (
                int(target_peer_id),
                int(target_message_id),
                media_kind,
                content_sha256 or None,
                int(canonical_target_message_id or 0) or None,
                status,
                last_error,
                timestamp,
                timestamp,
            ),
        )

    def update_target_cursor(self, cursor_column: str, message_id: int) -> None:
        if cursor_column not in {"video_target_cursor", "image_target_cursor"}:
            raise RouterBlocked("invalid target cursor column")
        self.connection.execute(
            f"UPDATE router_settings SET {cursor_column} = ?, updated_at = ? WHERE id = 1",
            (int(message_id), now_iso()),
        )
        self.connection.commit()

    def index_target(
        self,
        settings: sqlite3.Row,
        *,
        kind: str,
        peer_id: int,
        cursor_column: str,
    ) -> int:
        api = self.api(settings)
        cursor = int(settings[cursor_column] or 0)
        messages = self.group_messages(
            api,
            peer_id,
            cursor,
            int(settings["scan_limit"]),
        )
        processed = 0
        for message in messages:
            message_id = int(message.get("id") or 0)
            if message_id <= cursor:
                continue
            routed_kind = self.routed_kind(message.get("media_kind"))
            if routed_kind is None:
                self.update_target_cursor(cursor_column, message_id)
                cursor = message_id
                processed += 1
                continue
            if routed_kind != kind:
                self.record_target_item(
                    target_peer_id=peer_id,
                    target_message_id=message_id,
                    media_kind=routed_kind,
                    content_sha256="",
                    canonical_target_message_id=0,
                    status="wrong_kind",
                )
                self.update_target_cursor(cursor_column, message_id)
                cursor = message_id
                self.log(
                    "target_wrong_media_kind",
                    target_peer_id=peer_id,
                    target_message_id=message_id,
                    expected_kind=kind,
                    actual_kind=routed_kind,
                )
                processed += 1
                continue

            managed_item = self.connection.execute(
                """
                SELECT content_sha256
                FROM router_items
                WHERE target_peer_id = ?
                  AND target_message_id = ?
                  AND status IN ('completed', 'duplicate')
                ORDER BY id
                LIMIT 1
                """,
                (int(peer_id), int(message_id)),
            ).fetchone()
            if managed_item is not None:
                self.record_target_item(
                    target_peer_id=peer_id,
                    target_message_id=message_id,
                    media_kind=routed_kind,
                    content_sha256=str(managed_item["content_sha256"] or ""),
                    canonical_target_message_id=message_id,
                    status="managed",
                )
                self.update_target_cursor(cursor_column, message_id)
                cursor = message_id
                processed += 1
                continue

            self.record_target_item(
                target_peer_id=peer_id,
                target_message_id=message_id,
                media_kind=routed_kind,
                content_sha256="",
                canonical_target_message_id=message_id,
                status="indexed_forward_only",
            )
            self.update_target_cursor(cursor_column, message_id)
            cursor = message_id
            processed += 1
        return processed

    def begin_source_item(
        self,
        source_peer_id: int,
        source_message_id: int,
        media_kind: str | None,
        file_unique_id: str | None = None,
    ) -> sqlite3.Row:
        timestamp = now_iso()
        self.connection.execute(
            """
            INSERT INTO router_items (
                source_peer_id, source_message_id, media_kind, file_unique_id,
                status, attempts, created_at, updated_at
            ) VALUES (?, ?, ?, ?, 'processing', 1, ?, ?)
            ON CONFLICT(source_peer_id, source_message_id) DO UPDATE SET
                media_kind = excluded.media_kind,
                file_unique_id = COALESCE(excluded.file_unique_id, router_items.file_unique_id),
                status = CASE
                    WHEN router_items.status IN ('completed', 'duplicate', 'ignored')
                        THEN router_items.status
                    ELSE 'processing'
                END,
                attempts = CASE
                    WHEN router_items.status IN ('completed', 'duplicate', 'ignored')
                        THEN router_items.attempts
                    ELSE router_items.attempts + 1
                END,
                last_error = NULL,
                updated_at = excluded.updated_at
            """,
            (
                int(source_peer_id),
                int(source_message_id),
                media_kind,
                self.normalized_file_unique_id(file_unique_id),
                timestamp,
                timestamp,
            ),
        )
        self.connection.commit()
        row = self.connection.execute(
            """
            SELECT * FROM router_items
            WHERE source_peer_id = ? AND source_message_id = ?
            """,
            (int(source_peer_id), int(source_message_id)),
        ).fetchone()
        if row is None:
            raise RouterBlocked("failed to create source item")
        return row

    def complete_source_item(
        self,
        *,
        source_peer_id: int,
        source_message_id: int,
        status: str,
        media_kind: str | None,
        target_peer_id: int | None = None,
        target_message_id: int | None = None,
        content_sha256: str | None = None,
        file_unique_id: str | None = None,
        is_duplicate: bool = False,
        duplicate_of_item_id: int | None = None,
    ) -> None:
        timestamp = now_iso()
        with self.connection:
            self.connection.execute(
                """
                UPDATE router_items
                SET status = ?, media_kind = ?, target_peer_id = ?,
                    target_message_id = ?, content_sha256 = ?,
                    file_unique_id = COALESCE(?, file_unique_id),
                    is_duplicate = ?, duplicate_of_item_id = ?,
                    last_error = NULL, updated_at = ?
                WHERE source_peer_id = ? AND source_message_id = ?
                """,
                (
                    status,
                    media_kind,
                    target_peer_id,
                    target_message_id,
                    content_sha256,
                    self.normalized_file_unique_id(file_unique_id),
                    1 if is_duplicate else 0,
                    int(duplicate_of_item_id or 0) or None,
                    timestamp,
                    int(source_peer_id),
                    int(source_message_id),
                ),
            )
            self.connection.execute(
                """
                UPDATE router_sources
                SET last_message_id = ?, updated_at = ?
                WHERE peer_id = ?
                """,
                (int(source_message_id), timestamp, int(source_peer_id)),
            )

    def fail_source_item(
        self,
        source_peer_id: int,
        source_message_id: int,
        error: str,
    ) -> None:
        self.connection.execute(
            """
            UPDATE router_items
            SET status = 'failed', last_error = ?, updated_at = ?
            WHERE source_peer_id = ? AND source_message_id = ?
            """,
            (str(error)[:2000], now_iso(), int(source_peer_id), int(source_message_id)),
        )
        self.connection.commit()

    def process_source(self, settings: sqlite3.Row, source: sqlite3.Row) -> int:
        api = self.api(settings)
        peer_id = int(source["peer_id"])
        cursor = int(source["last_message_id"] or 0)
        messages = self.group_messages(
            api,
            peer_id,
            cursor,
            int(settings["scan_limit"]),
        )
        processed = 0
        for message in messages:
            message_id = int(message.get("id") or 0)
            if message_id <= cursor:
                continue
            media_kind = self.routed_kind(message.get("media_kind"))
            file_unique_id = self.normalized_file_unique_id(message.get("file_unique_id"))
            item = self.begin_source_item(peer_id, message_id, media_kind, file_unique_id)
            if str(item["status"]) in TERMINAL_ITEM_STATUSES:
                self.complete_source_item(
                    source_peer_id=peer_id,
                    source_message_id=message_id,
                    status=str(item["status"]),
                    media_kind=media_kind,
                    target_peer_id=item["target_peer_id"],
                    target_message_id=item["target_message_id"],
                    content_sha256=item["content_sha256"],
                    file_unique_id=item["file_unique_id"],
                    is_duplicate=bool(item["is_duplicate"]),
                    duplicate_of_item_id=item["duplicate_of_item_id"],
                )
                cursor = message_id
                processed += 1
                continue
            if media_kind is None:
                self.complete_source_item(
                    source_peer_id=peer_id,
                    source_message_id=message_id,
                    status="ignored",
                    media_kind=None,
                )
                cursor = message_id
                processed += 1
                continue

            duplicate = self.find_completed_file_duplicate(file_unique_id)
            if duplicate is not None:
                self.complete_source_item(
                    source_peer_id=peer_id,
                    source_message_id=message_id,
                    status="duplicate",
                    media_kind=media_kind,
                    target_peer_id=int(duplicate["target_peer_id"] or 0),
                    target_message_id=int(duplicate["target_message_id"] or 0),
                    content_sha256=None,
                    file_unique_id=file_unique_id,
                    is_duplicate=True,
                    duplicate_of_item_id=int(duplicate["id"] or 0),
                )
                self.log(
                    "source_media_duplicate_skipped",
                    source_peer_id=peer_id,
                    source_message_id=message_id,
                    media_kind=media_kind,
                    duplicate_of_item_id=int(duplicate["id"] or 0),
                    target_peer_id=int(duplicate["target_peer_id"] or 0),
                    target_message_id=int(duplicate["target_message_id"] or 0),
                )
                cursor = message_id
                processed += 1
                continue

            target_peer_id = int(
                settings[
                    "image_target_peer_id" if media_kind == "image" else "video_target_peer_id"
                ]
            )
            try:
                response = api.post(
                    "/resource-codes/forward-batch",
                    {
                        "source_peer_id": peer_id,
                        "message_ids": [message_id],
                        "target_peer_id": target_peer_id,
                        "drop_media_captions": True,
                    },
                )
            except (TimeoutError, urllib.error.URLError, ConnectionError) as error:
                self.fail_source_item(peer_id, message_id, f"transport_error: {error}")
                self.log(
                    "source_forward_transport_error",
                    source_peer_id=peer_id,
                    source_message_id=message_id,
                    error=str(error),
                )
                break

            if response.get("status") != "ok":
                reason = str(response.get("reason") or "forward_failed")
                self.fail_source_item(
                    peer_id,
                    message_id,
                    f"{reason}: {response.get('error') or ''}".strip(),
                )
                self.log(
                    "source_forward_failed",
                    source_peer_id=peer_id,
                    source_message_id=message_id,
                    reason=reason,
                    wait_seconds=int(response.get("wait_seconds") or 0),
                )
                wait_seconds = int(response.get("wait_seconds") or 0)
                if reason == "flood_wait" and wait_seconds > 0:
                    time.sleep(wait_seconds)
                break

            target_message_ids = [
                int(target_message_id or 0)
                for target_message_id in list(response.get("target_message_ids") or [])
                if int(target_message_id or 0) > 0
            ]
            if len(target_message_ids) != 1:
                self.fail_source_item(peer_id, message_id, "forward_result_count_mismatch")
                break
            target_message_id = target_message_ids[0]
            if target_message_id <= 0:
                self.fail_source_item(peer_id, message_id, "forward_target_message_missing")
                break
            self.complete_source_item(
                source_peer_id=peer_id,
                source_message_id=message_id,
                status="completed",
                media_kind=media_kind,
                target_peer_id=target_peer_id,
                target_message_id=target_message_id,
                content_sha256=None,
                file_unique_id=file_unique_id,
                is_duplicate=False,
            )
            self.log(
                "source_media_routed",
                source_peer_id=peer_id,
                source_message_id=message_id,
                media_kind=media_kind,
                target_peer_id=target_peer_id,
                target_message_id=target_message_id,
                duplicate=False,
            )
            cursor = message_id
            processed += 1

        if messages and len(messages) < int(settings["scan_limit"]):
            self.connection.execute(
                """
                UPDATE router_sources
                SET initial_scan_completed_at = COALESCE(initial_scan_completed_at, ?),
                    updated_at = ?
                WHERE peer_id = ?
                """,
                (now_iso(), now_iso(), peer_id),
            )
            self.connection.commit()
        return processed

    def run_once(self) -> int:
        settings = self.settings()
        if not bool(settings["enabled"]):
            return 0
        processed = 0
        sources = self.connection.execute(
            "SELECT * FROM router_sources WHERE enabled = 1 ORDER BY id"
        ).fetchall()
        for source in sources:
            processed += self.process_source(settings, source)
        return processed

    def run(self) -> None:
        self.log("media_router_started", database_path=self.database_path)
        while True:
            settings = self.settings()
            try:
                processed = self.run_once()
                delay = 0.5 if processed > 0 else float(settings["idle_seconds"])
            except RouterBlocked as error:
                self.log("media_router_blocked", error=str(error))
                delay = max(10.0, float(settings["idle_seconds"]))
            except Exception as error:
                self.log(
                    "media_router_error",
                    error=f"{type(error).__name__}: {error}",
                )
                delay = max(10.0, float(settings["idle_seconds"]))
            time.sleep(delay)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--database-path", default=DEFAULT_DATABASE_PATH)
    parser.add_argument("--init", action="store_true")
    parser.add_argument("--configure", action="store_true")
    parser.add_argument("--base-uri", default=DEFAULT_BASE_URI)
    parser.add_argument("--video-target-peer-id", type=int, default=0)
    parser.add_argument("--image-target-peer-id", type=int, default=0)
    parser.add_argument("--dedupe-scope", default=DEFAULT_DEDUPE_SCOPE)
    parser.add_argument("--scan-limit", type=int, default=40)
    parser.add_argument("--idle-seconds", type=float, default=15.0)
    parser.add_argument("--add-source", type=int, default=0)
    parser.add_argument("--title", default="")
    parser.add_argument("--enable-source", type=int, default=0)
    parser.add_argument("--disable-source", type=int, default=0)
    parser.add_argument("--status", action="store_true")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--run", action="store_true")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    connection = connect_database(args.database_path)
    try:
        if args.configure:
            configure_router(
                connection,
                base_uri=args.base_uri,
                video_target_peer_id=args.video_target_peer_id,
                image_target_peer_id=args.image_target_peer_id,
                dedupe_scope=args.dedupe_scope,
                scan_limit=args.scan_limit,
                idle_seconds=args.idle_seconds,
            )
        if args.add_source:
            add_source(connection, args.add_source, args.title)
        if args.enable_source:
            set_source_enabled(connection, args.enable_source, True)
        if args.disable_source:
            set_source_enabled(connection, args.disable_source, False)
        if args.status:
            print(
                json.dumps(
                    router_status(connection, args.database_path),
                    ensure_ascii=False,
                    indent=2,
                )
            )
            return 0
        if args.once:
            processed = Router(connection, args.database_path).run_once()
            print(json.dumps({"status": "ok", "processed": processed}))
            return 0
        if args.run or not any(
            (
                args.init,
                args.configure,
                args.add_source,
                args.enable_source,
                args.disable_source,
                args.status,
            )
        ):
            Router(connection, args.database_path).run()
        return 0
    except RouterBlocked as error:
        print(
            json.dumps(
                {"status": "blocked", "error": str(error)},
                ensure_ascii=False,
            ),
            file=sys.stderr,
        )
        return 2
    finally:
        connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
