"""Content-only SHA-256/Base64 features; no names, text or media in logs.

Used by the queue's single worker, never a second download worker. A Telegram
object key is only a cached identity, not a content hash or a filename hash.
"""
from __future__ import annotations

import asyncio
import base64
import hashlib
import json
import os
import shutil
import sqlite3
import time
from pathlib import Path

from telethon.errors import FloodWaitError


def connect(q):
    db = q.open_processed_db()
    for table in ("processed_messages", "processed_archive_items"):
        columns = {r[1] for r in db.execute(f"PRAGMA table_info({table})")}
        if "fingerprint_base64" not in columns:
            db.execute(f"ALTER TABLE {table} ADD COLUMN fingerprint_base64 TEXT")
    db.executescript("""
        CREATE TABLE IF NOT EXISTS file_features (
            fingerprint_base64 TEXT PRIMARY KEY,
            byte_size INTEGER NOT NULL,
            delivered INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS telegram_file_features (
            file_key TEXT PRIMARY KEY,
            fingerprint_base64 TEXT NOT NULL REFERENCES file_features(fingerprint_base64)
        );
        CREATE TABLE IF NOT EXISTS fingerprint_jobs (
            source_peer_id INTEGER NOT NULL, message_id INTEGER NOT NULL,
            source_alias TEXT NOT NULL, status TEXT NOT NULL,
            fingerprint_base64 TEXT, method TEXT, error_class TEXT,
            retry_at REAL NOT NULL DEFAULT 0, updated_at TEXT NOT NULL,
            PRIMARY KEY(source_peer_id, message_id)
        );
        CREATE TABLE IF NOT EXISTS fingerprint_progress (
            source_peer_id INTEGER PRIMARY KEY, source_alias TEXT NOT NULL,
            snapshot_max INTEGER NOT NULL, scanned_id INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        );
    """)
    db.commit()
    return db


def digest_file(path):
    before = path.stat()
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(4 * 1024 * 1024), b""):
            digest.update(block)
    after = path.stat()
    if (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns):
        raise RuntimeError("fingerprint_file_changed")
    return base64.b64encode(digest.digest()).decode("ascii"), after.st_size


def file_key(message):
    doc = getattr(message, "document", None)
    photo = getattr(message, "photo", None)
    if doc is not None and getattr(doc, "id", None):
        return f"document:{doc.id}:{int(doc.size)}"
    if photo is not None and getattr(photo, "id", None):
        return f"photo:{photo.id}:largest"
    raise RuntimeError("fingerprint_no_file_identity")


def register(q, digest, size, delivered=False, key=None):
    db = connect(q)
    try:
        with db:
            row = db.execute("SELECT byte_size,delivered FROM file_features WHERE fingerprint_base64=?", (digest,)).fetchone()
            if row and int(row[0]) != size:
                raise RuntimeError("fingerprint_size_conflict")
            duplicate = bool(row and row[1])
            db.execute("""INSERT INTO file_features VALUES (?,?,?,?)
                ON CONFLICT(fingerprint_base64) DO UPDATE SET delivered=MAX(delivered,excluded.delivered)""",
                (digest, size, int(delivered), q.utc_now()))
            if key:
                prior = db.execute("SELECT fingerprint_base64 FROM telegram_file_features WHERE file_key=?", (key,)).fetchone()
                if prior and prior[0] != digest:
                    raise RuntimeError("fingerprint_identity_conflict")
                db.execute("INSERT OR IGNORE INTO telegram_file_features VALUES (?,?)", (key, digest))
            return duplicate
    finally:
        db.close()


def record_job(q, peer, mid, alias, digest=None, method=None, error=None, skipped=False):
    db = connect(q)
    try:
        with db:
            db.execute("""INSERT INTO fingerprint_jobs VALUES (?,?,?,?,?,?,?,?,?)
                ON CONFLICT(source_peer_id,message_id) DO UPDATE SET
                status=excluded.status,fingerprint_base64=excluded.fingerprint_base64,
                method=excluded.method,error_class=excluded.error_class,
                retry_at=excluded.retry_at,updated_at=excluded.updated_at""",
                (peer, mid, alias, "completed" if digest else ("skipped" if skipped else "failed"), digest, method,
                 error, 0 if skipped else (time.time() + 3600 if error else 0), q.utc_now()))
            if digest:
                db.execute("UPDATE processed_messages SET fingerprint_base64=? WHERE source_peer_id=? AND message_id=?", (digest, peer, mid))
    finally:
        db.close()


def mark_delivered(q, digest):
    db = connect(q)
    try:
        with db:
            db.execute("UPDATE file_features SET delivered=1 WHERE fingerprint_base64=?", (digest,))
    finally:
        db.close()


def child_feature(q, peer, mid, item_key, digest):
    db = connect(q)
    try:
        with db:
            db.execute("UPDATE processed_archive_items SET fingerprint_base64=? WHERE source_peer_id=? AND message_id=? AND item_key=?", (digest, peer, mid, item_key))
    finally:
        db.close()


async def download_document(q, peer, message, alias, config, state, staging):
    """Retain incomplete staging on errors; only our exact job owns this path."""
    staging.mkdir(parents=True, exist_ok=True)
    download = staging / "download"
    download.mkdir(exist_ok=True)
    export = staging / "item.json"
    expected = int(message.document.size)
    complete = staging / "verified.json"
    if complete.exists():
        files = [p for p in download.rglob("*") if p.is_file() and p.stat().st_size == expected]
        if len(files) == 1:
            return files[0]
    await q.run_tdl([str(config["tdl_path"]), "chat", "export", "-n", str(config["tdl_namespace"]),
        "-c", str(q.tdl_peer_id(peer)), "-T", "id", "-i", f"{message.id},{message.id}", "-o", str(export)], state, alias)
    exported = json.loads(export.read_text(encoding="utf-8"))
    if int(exported.get("id", 0)) != q.tdl_peer_id(peer) or [int(m.get("id", 0)) for m in exported.get("messages", [])] != [message.id]:
        raise RuntimeError("single_message_export_verification_failed")
    await q.run_tdl([str(config["tdl_path"]), "download", "-n", str(config["tdl_namespace"]),
        "-f", str(export), "-d", str(download), "--skip-same", "-t", str(config.get("tdl_threads", 8)), "-l", "1"], state, alias)
    files = [p for p in download.rglob("*") if p.is_file() and p.stat().st_size == expected]
    if len(files) != 1:
        raise RuntimeError("download_size_verification_failed")
    q.atomic_json(complete, {"verified": True})
    return files[0]


def job_staging(q, config, peer, mid):
    root = Path(config.get("fingerprint_work_dir") or (q.APP_DIR / "fingerprint-work"))
    token = hashlib.sha256(f"{peer}:{mid}".encode("ascii")).hexdigest()
    return root / token


def cleanup(q, config, peer, mid):
    path = job_staging(q, config, peer, mid)
    root = Path(config.get("fingerprint_work_dir") or (q.APP_DIR / "fingerprint-work")).resolve()
    if path.resolve().parent != root or path.is_symlink():
        raise RuntimeError("fingerprint_cleanup_scope")
    if path.exists():
        shutil.rmtree(path)


async def prepare(q, client, source, message, alias, config, state, historical=False, force_path=False):
    peer, mid = q.marked_peer_id(source), int(message.id)
    key = file_key(message)
    db = connect(q)
    try:
        known = db.execute("""SELECT f.fingerprint_base64,f.byte_size,f.delivered
            FROM telegram_file_features t JOIN file_features f USING(fingerprint_base64)
            WHERE t.file_key=?""", (key,)).fetchone()
    finally:
        db.close()
    if known and (historical or known[2]) and not force_path:
        if historical:
            mark_delivered(q, known[0])
        record_job(q, peer, mid, alias, known[0], "telegram_identity")
        cleanup(q, config, peer, mid)
        return {"digest": known[0], "duplicate": bool(known[2]), "path": None}

    path = None
    kind = q.message_kind(message)
    if kind == "video":
        token = hashlib.sha256(f"{peer}:{mid}".encode("ascii")).hexdigest()[:20]
        candidates = [p for p in Path(config["download_dir"]).glob(f"tg_{token}.*")
                      if p.is_file() and p.stat().st_size == int(message.document.size)]
        if len(candidates) == 1:
            path = candidates[0]
    method = "existing_local" if path else "temporary_download"
    if path is None:
        staging = job_staging(q, config, peer, mid)
        if getattr(message, "document", None) is not None:
            path = await download_document(q, peer, message, alias, config, state, staging)
        else:
            staging.mkdir(parents=True, exist_ok=True)
            state["active_source"] = alias
            q.save_state(state, "fingerprint_downloading")
            path = staging / "photo.bin"
            await client.download_media(message, file=str(path))
            if not path.is_file() or not path.stat().st_size:
                raise RuntimeError("fingerprint_photo_download_failed")
    digest, size = await asyncio.to_thread(digest_file, path)
    duplicate = register(q, digest, size, historical, key)
    record_job(q, peer, mid, alias, digest, method)
    q.safe_log("fingerprint_written", source=alias, message_id=mid, status=method)
    return {"digest": digest, "duplicate": duplicate, "path": path}


def enabled(config, alias):
    return bool(config.get("file_fingerprints_enabled")) and alias in config.get("fingerprint_source_aliases", [])


async def backfill_batch(q, client, source, image_target, alias, config, state):
    if not enabled(config, alias):
        return False
    peer = q.marked_peer_id(source)
    db = connect(q)
    try:
        high = db.execute("SELECT COALESCE(MAX(message_id),0) FROM processed_messages WHERE source_peer_id=? AND status='completed'", (peer,)).fetchone()[0]
        with db:
            db.execute("""INSERT INTO fingerprint_progress VALUES (?,?,?,0,?)
                ON CONFLICT(source_peer_id) DO UPDATE SET snapshot_max=MAX(snapshot_max,excluded.snapshot_max),updated_at=excluded.updated_at""",
                (peer, alias, high, q.utc_now()))
        rows = db.execute("""SELECT p.message_id,j.status FROM processed_messages p
            LEFT JOIN fingerprint_jobs j ON j.source_peer_id=p.source_peer_id AND j.message_id=p.message_id
            WHERE p.source_peer_id=? AND p.status='completed'
            AND COALESCE(j.status,'') <> 'skipped'
            AND (p.fingerprint_base64 IS NULL OR j.status='failed')
            AND (j.retry_at IS NULL OR j.retry_at<=?) ORDER BY p.message_id LIMIT ?""",
            (peer, time.time(), max(1, int(config.get("fingerprint_batch_size", 3))))).fetchall()
    finally:
        db.close()
    started = time.monotonic()
    for row in rows:
        mid = row[0]
        state["active_source"] = alias
        q.save_state(state, "fingerprint_backfill")
        try:
            async with asyncio.timeout(max(0.01, float(config.get("message_timeout_seconds", 1200)))):
                message = await client.get_messages(source, ids=mid)
                if not message or q.message_kind(message) is None:
                    raise RuntimeError("fingerprint_source_unavailable")
                result = await prepare(q, client, source, message, alias, config, state, historical=True,
                                       force_path=row[1] == "failed")
                # Historical archive children are indexed only from an available ZIP.
                # Their delivery records stay intact and no output is sent again.
                if q.message_kind(message) == "archive" and result["path"]:
                    await q.process_archive(client, source, image_target, message, alias, config, state,
                                            prepared_path=result["path"], fingerprint_only=True)
                cleanup(q, config, peer, mid)
        except FloodWaitError:
            raise
        except TimeoutError:
            record_job(q, peer, mid, alias, error="fingerprint_timeout", skipped=True)
            q.safe_log("fingerprint_failed", source=alias, message_id=mid,
                       error_class="fingerprint_timeout", status="skipped")
        except Exception as error:
            code = str(error) if isinstance(error, RuntimeError) and q.re.fullmatch(r"[a-z0-9_]{3,120}", str(error)) else type(error).__name__
            record_job(q, peer, mid, alias, error=code)
            q.safe_log("fingerprint_failed", source=alias, message_id=mid, error_class=code, status="retry_pending")
        db = connect(q)
        try:
            with db:
                db.execute("UPDATE fingerprint_progress SET scanned_id=MAX(scanned_id,?),updated_at=? WHERE source_peer_id=?", (mid, q.utc_now(), peer))
        finally:
            db.close()
        if time.monotonic() - started >= 60:
            break
    return bool(rows)


def report(q, config):
    db = connect(q)
    out = []
    try:
        for src in config["sources"]:
            if not enabled(config, src["alias"]):
                continue
            peer = int(src["peer_id"])
            counts = db.execute("""SELECT COUNT(*),
                COUNT(CASE WHEN p.fingerprint_base64 IS NOT NULL AND COALESCE(j.status,'completed') <> 'failed' THEN 1 END),
                MAX(p.message_id), MAX(CASE WHEN p.fingerprint_base64 IS NOT NULL THEN p.message_id END),
                MIN(CASE WHEN COALESCE(j.status,'') <> 'skipped'
                    AND (p.fingerprint_base64 IS NULL OR j.status='failed') THEN p.message_id END),
                COUNT(CASE WHEN j.status='skipped' THEN 1 END)
                FROM processed_messages p LEFT JOIN fingerprint_jobs j
                ON j.source_peer_id=p.source_peer_id AND j.message_id=p.message_id
                WHERE p.source_peer_id=? AND p.status='completed'""", (peer,)).fetchone()
            progress = db.execute("SELECT scanned_id FROM fingerprint_progress WHERE source_peer_id=?", (peer,)).fetchone()
            failed = db.execute("SELECT COUNT(*) FROM fingerprint_jobs WHERE source_peer_id=? AND status='failed'", (peer,)).fetchone()[0]
            # Coverage means all *completed media rows* through this boundary,
            # not every integer ID or every group message.
            through = db.execute("""SELECT COALESCE(MAX(message_id),0) FROM processed_messages
                WHERE source_peer_id=? AND status='completed' AND fingerprint_base64 IS NOT NULL
                AND (? IS NULL OR message_id < ?)""", (peer, counts[4], counts[4])).fetchone()[0]
            out.append({"source": src["alias"], "total_completed_media": counts[0], "written": counts[1],
                "skipped": counts[5], "pending": counts[0]-counts[1]-counts[5], "covered_through_id": through,
                "highest_written_id": counts[3] or 0, "first_missing_id": counts[4],
                "backfill_scanned_id": progress[0] if progress else 0, "failed": failed})
    finally:
        db.close()
    return out
