#!/usr/bin/env python3
"""Safely resume one large QQ decoder job without involving the normal worker."""

from __future__ import annotations

import argparse
import fcntl
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any


PAGE_RE = re.compile(r"第\s*(\d+)\s*/\s*(\d+)\s*[页頁]")
BOT_RE = re.compile(r"^[A-Za-z0-9_]+$")


def log(message: str) -> None:
    print(message, flush=True)


def http_json(
    base_uri: str,
    method: str,
    path: str,
    payload: dict[str, Any] | None = None,
    timeout: float = 30.0,
) -> Any:
    body = None
    headers: dict[str, str] = {}
    if payload is not None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        headers["Content-Type"] = "application/json"

    request = urllib.request.Request(
        base_uri.rstrip("/") + path,
        data=body,
        headers=headers,
        method=method,
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        detail = error.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {error.code} {method} {path}: {detail[:1000]}") from error


def php_tinker(expression: str) -> str:
    result = subprocess.run(
        ["php", "artisan", "tinker", f"--execute={expression}"],
        cwd="/var/www/html/blog",
        check=False,
        capture_output=True,
        text=True,
        timeout=60,
    )
    if result.returncode != 0:
        raise RuntimeError(
            f"artisan tinker failed ({result.returncode}): "
            f"{(result.stderr or result.stdout)[-1500:]}"
        )
    return result.stdout.strip()


def fetch_record(record_id: int) -> dict[str, Any]:
    output = php_tinker(
        "echo json_encode(DB::table('telegram_resource_codes')"
        f"->where('id',{record_id})->first());"
    )
    record = json.loads(output or "null")
    if not isinstance(record, dict):
        raise RuntimeError(f"record {record_id} not found")
    return record


def checkpoint(
    record_id: int,
    bot_username: str,
    page: int,
    total_pages: int,
    forwarded_count: int,
    total_count: int,
    completed: bool = False,
) -> None:
    if not BOT_RE.fullmatch(bot_username):
        raise RuntimeError("unsafe bot username")

    next_page = "null" if completed else str(page + 1)
    completed_at = "now()" if completed else "null"
    status = 2 if completed else 1
    processing_started_at = "null" if completed else "DB::raw('processing_started_at')"
    expression = (
        "DB::table('telegram_resource_codes')"
        f"->where('id',{record_id})->update(["
        f"'status'=>{status},"
        "'processing_account'=>1,"
        f"'forwarded_message_count'=>{forwarded_count},"
        f"'decoder_sent_count'=>{forwarded_count},"
        f"'decoder_total_count'=>{total_count},"
        f"'last_completed_page'=>{page},"
        f"'resume_from_page'=>{next_page},"
        f"'decoder_total_pages'=>{total_pages},"
        f"'resume_bot_username'=>'{bot_username}',"
        "'paused_at'=>null,"
        f"'processing_started_at'=>{processing_started_at},"
        f"'completed_at'=>{completed_at},"
        "'updated_at'=>now()]);"
    )
    php_tinker(expression)


def write_state(state_path: Path, state: dict[str, Any]) -> None:
    state_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = state_path.with_suffix(state_path.suffix + ".tmp")
    temporary.write_text(
        json.dumps(state, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    os.replace(temporary, state_path)


def expected_page_media_count(page: int, total_count: int, page_size: int) -> int:
    remaining = total_count - ((page - 1) * page_size)
    return max(0, min(page_size, remaining))


def page_from_item(item: dict[str, Any]) -> tuple[int, int] | None:
    text = str(item.get("message") or item.get("text") or "")
    match = PAGE_RE.search(text)
    if not match:
        return None
    return int(match.group(1)), int(match.group(2))


def wait_for_page(
    base_uri: str,
    source_peer_id: int,
    previous_control_id: int,
    wanted_page: int,
    total_pages: int,
    expected_media_count: int,
    timeout_seconds: float,
) -> tuple[int, list[int], float]:
    deadline = time.monotonic() + timeout_seconds
    query = urllib.parse.urlencode(
        {
            "limit": 40,
            "min_id": previous_control_id,
            "reverse": "true",
            "include_raw": "true",
        }
    )

    while time.monotonic() < deadline:
        response = http_json(
            base_uri,
            "GET",
            f"/groups/{source_peer_id}?{query}",
            timeout=20,
        )
        items = list(response.get("items") or [])
        controls = [
            item
            for item in items
            if int(item.get("id") or 0) > previous_control_id
            and item.get("media") is None
            and page_from_item(item) == (wanted_page, total_pages)
        ]
        if controls:
            control_id = max(int(item["id"]) for item in controls)
            media_ids = sorted(
                int(item["id"])
                for item in items
                if previous_control_id < int(item.get("id") or 0) < control_id
                and item.get("media") is not None
            )
            if len(media_ids) == expected_media_count:
                return control_id, media_ids, time.monotonic()
            if len(media_ids) > expected_media_count:
                raise RuntimeError(
                    f"page {wanted_page}: expected {expected_media_count} media, "
                    f"found {len(media_ids)}"
                )

        time.sleep(1.5)

    raise TimeoutError(f"page {wanted_page}: did not receive a complete page")


def dispatch_next_page(
    base_uri: str,
    bot_username: str,
    control_message_id: int,
) -> dict[str, Any]:
    return http_json(
        base_uri,
        "POST",
        "/bots/dispatch-next-page-callback",
        {
            "bot_username": bot_username,
            "message_id": control_message_id,
            "response_timeout_seconds": 0.75,
        },
        timeout=10,
    )


def verify_anonymous_targets(
    base_uri: str,
    target_peer_id: int,
    target_message_ids: list[int],
) -> None:
    for message_id in target_message_ids:
        response = http_json(
            base_uri,
            "GET",
            f"/groups/{target_peer_id}/{message_id}"
            "?include_next=false&include_raw=true",
            timeout=20,
        )
        items = list(response.get("items") or [])
        if len(items) != 1:
            raise RuntimeError(f"target message {message_id}: expected one item")
        item = items[0]
        if item.get("media") is None:
            raise RuntimeError(f"target message {message_id}: media missing")
        if item.get("from_id") is not None or item.get("fwd_from") is not None:
            raise RuntimeError(f"target message {message_id}: not anonymous")
        if str(item.get("message") or ""):
            raise RuntimeError(f"target message {message_id}: caption was not removed")


def process_page(
    args: argparse.Namespace,
    page: int,
    media_ids: list[int],
    previous_control_id: int,
    control_id: int,
    forwarded_count: int,
    state_path: Path,
) -> int:
    write_state(
        state_path,
        {
            "stage": "about_to_forward",
            "page": page,
            "previous_control_message_id": previous_control_id,
            "control_message_id": control_id,
            "source_message_ids": media_ids,
            "forwarded_count_before": forwarded_count,
            "updated_at_epoch": time.time(),
        },
    )

    result = http_json(
        args.base_uri,
        "POST",
        "/resource-codes/forward-batch",
        {
            "source_peer_id": args.source_peer_id,
            "message_ids": media_ids,
            "target_peer_id": args.target_peer_id,
            "drop_media_captions": True,
        },
        timeout=120,
    )
    if result.get("status") != "ok" or int(result.get("forwarded_count") or 0) != len(media_ids):
        raise RuntimeError(f"page {page}: forward failed: {result}")

    target_ids = [int(message_id) for message_id in result.get("target_message_ids") or []]
    write_state(
        state_path,
        {
            "stage": "forwarded_pending_verify_delete",
            "page": page,
            "previous_control_message_id": previous_control_id,
            "control_message_id": control_id,
            "source_message_ids": media_ids,
            "target_message_ids": target_ids,
            "forwarded_count_before": forwarded_count,
            "updated_at_epoch": time.time(),
        },
    )
    verify_anonymous_targets(args.base_uri, args.target_peer_id, target_ids)

    delete_result = http_json(
        args.base_uri,
        "POST",
        "/bots/delete-messages",
        {
            "chat_peer": args.bot_username,
            "message_ids": media_ids + [previous_control_id],
        },
        timeout=60,
    )
    if delete_result.get("status") != "ok" or delete_result.get("remaining_message_ids"):
        raise RuntimeError(f"page {page}: source delete failed: {delete_result}")

    new_forwarded_count = forwarded_count + len(media_ids)
    checkpoint(
        args.record_id,
        args.bot_username,
        page,
        args.total_pages,
        new_forwarded_count,
        args.total_count,
        completed=False,
    )
    write_state(
        state_path,
        {
            "stage": "page_completed",
            "page": page,
            "control_message_id": control_id,
            "target_message_ids": target_ids,
            "forwarded_count": new_forwarded_count,
            "updated_at_epoch": time.time(),
        },
    )
    log(
        f"PROGRESS page={page}/{args.total_pages} "
        f"forwarded={new_forwarded_count} batch={len(media_ids)} control={control_id}"
    )
    return new_forwarded_count


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--record-id", type=int, required=True)
    parser.add_argument("--bot-username", required=True)
    parser.add_argument("--source-peer-id", type=int, required=True)
    parser.add_argument("--target-peer-id", type=int, required=True)
    parser.add_argument("--base-uri", required=True)
    parser.add_argument("--start-page", type=int, required=True)
    parser.add_argument("--total-pages", type=int, required=True)
    parser.add_argument("--total-count", type=int, required=True)
    parser.add_argument("--page-size", type=int, default=10)
    parser.add_argument("--previous-control-message-id", type=int, required=True)
    parser.add_argument("--forwarded-count", type=int, required=True)
    parser.add_argument("--minimum-page-seconds", type=float, default=11.0)
    parser.add_argument("--page-timeout-seconds", type=float, default=24.0)
    parser.add_argument("--callback-attempts", type=int, default=3)
    parser.add_argument("--state-path", required=True)
    parser.add_argument("--lock-path", required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if not BOT_RE.fullmatch(args.bot_username):
        raise RuntimeError("invalid bot username")
    if args.start_page <= 1 or args.start_page > args.total_pages:
        raise RuntimeError("invalid start page")

    lock_path = Path(args.lock_path)
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    lock_handle = lock_path.open("a+", encoding="utf-8")
    try:
        fcntl.flock(lock_handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        raise RuntimeError(f"another runner holds {lock_path}")

    record = fetch_record(args.record_id)
    expected_previous_page = args.start_page - 1
    if int(record.get("last_completed_page") or 0) != expected_previous_page:
        raise RuntimeError(
            f"checkpoint mismatch: DB page={record.get('last_completed_page')}, "
            f"expected={expected_previous_page}"
        )
    if int(record.get("forwarded_message_count") or 0) != args.forwarded_count:
        raise RuntimeError(
            f"count mismatch: DB={record.get('forwarded_message_count')}, "
            f"expected={args.forwarded_count}"
        )
    if str(record.get("resume_bot_username") or "") != args.bot_username:
        raise RuntimeError(
            f"bot mismatch: DB={record.get('resume_bot_username')}, "
            f"expected={args.bot_username}"
        )

    state_path = Path(args.state_path)
    current_control_id = args.previous_control_message_id
    forwarded_count = args.forwarded_count

    for page in range(args.start_page, args.total_pages + 1):
        expected_media = expected_page_media_count(page, args.total_count, args.page_size)
        if expected_media <= 0:
            raise RuntimeError(f"page {page}: invalid expected media count")

        page_result: tuple[int, list[int], float] | None = None
        for attempt in range(1, args.callback_attempts + 1):
            dispatch = dispatch_next_page(args.base_uri, args.bot_username, current_control_id)
            if dispatch.get("status") == "flood_wait":
                wait_seconds = max(1, int(dispatch.get("wait_seconds") or 1))
                log(f"FLOOD_WAIT page={page} seconds={wait_seconds}")
                time.sleep(wait_seconds + 1)
                continue
            if dispatch.get("status") != "ok":
                raise RuntimeError(f"page {page}: dispatch failed: {dispatch}")

            try:
                page_result = wait_for_page(
                    args.base_uri,
                    args.source_peer_id,
                    current_control_id,
                    page,
                    args.total_pages,
                    expected_media,
                    args.page_timeout_seconds,
                )
                break
            except TimeoutError:
                log(f"RETRY page={page} callback_attempt={attempt}")
                if attempt >= args.callback_attempts:
                    raise
                time.sleep(3)

        if page_result is None:
            raise RuntimeError(f"page {page}: callback attempts exhausted")

        new_control_id, media_ids, page_arrived_at = page_result
        forwarded_count = process_page(
            args,
            page,
            media_ids,
            current_control_id,
            new_control_id,
            forwarded_count,
            state_path,
        )
        current_control_id = new_control_id

        elapsed = time.monotonic() - page_arrived_at
        if page < args.total_pages and elapsed < args.minimum_page_seconds:
            time.sleep(args.minimum_page_seconds - elapsed)

    if forwarded_count != args.total_count:
        raise RuntimeError(
            f"final count mismatch: forwarded={forwarded_count}, expected={args.total_count}"
        )

    delete_result = http_json(
        args.base_uri,
        "POST",
        "/bots/delete-messages",
        {"chat_peer": args.bot_username, "message_ids": [current_control_id]},
        timeout=60,
    )
    if delete_result.get("status") != "ok" or delete_result.get("remaining_message_ids"):
        raise RuntimeError(f"final control delete failed: {delete_result}")

    checkpoint(
        args.record_id,
        args.bot_username,
        args.total_pages,
        args.total_pages,
        forwarded_count,
        args.total_count,
        completed=True,
    )
    write_state(
        state_path,
        {
            "stage": "completed",
            "page": args.total_pages,
            "forwarded_count": forwarded_count,
            "updated_at_epoch": time.time(),
        },
    )
    log(f"COMPLETE page={args.total_pages}/{args.total_pages} forwarded={forwarded_count}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        log(f"FATAL {type(error).__name__}: {error}")
        raise
