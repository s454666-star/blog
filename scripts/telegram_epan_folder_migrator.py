#!/usr/bin/env python3
"""Move protected ePan folder media into a Telegram group through one account API."""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from typing import Any


DEFAULT_MANIFEST = {
    "name": "original_backup",
    "source_bot": "yuanchaungbot",
    "source_peer_id": 8766016058,
    "target_peer_id": 3995547485,
    "video_target_peer_id": 3995547485,
    "image_target_peer_id": 4367037987,
    "dedupe_scope": "epan_originals_combined",
    "expected_total": 6441,
    "expected_media": 6372,
    "expected_images": 583,
    "expected_videos": 5789,
    "expected_text": 69,
    "folders": [
        {"name": "无水印 一套 备用", "count": 50},
        {"name": "6000—-6500", "count": 494},
        {"name": "5500————6000", "count": 493},
        {"name": "5000————-5500", "count": 478},
        {"name": "4500——-5000", "count": 483},
        {"name": "4000—-4500", "count": 494},
        {"name": "3500——4000", "count": 493},
        {"name": "3000-3500", "count": 491},
        {"name": "2500——3000", "count": 486},
        {"name": "2000——2500", "count": 483},
        {"name": "1500--2000", "count": 496},
        {"name": "1000--1500", "count": 496},
        {"name": "500—1000", "count": 502},
        {"name": "已经被流出很多 喜欢得话 自己收藏把 原画质 无水印", "count": 502},
    ],
}

FOLDER_COUNTER_KEYS = (
    "processed_total",
    "copied_media",
    "copied_images",
    "copied_videos",
    "deleted_text",
    "source_media_processed",
    "source_images",
    "source_videos",
    "duplicate_media",
    "duplicate_images",
    "duplicate_videos",
)


class MigrationBlocked(RuntimeError):
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
        timeout: float = 120.0,
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

    def get(self, path: str, timeout: float = 120.0) -> dict[str, Any]:
        return self.request("GET", path, timeout=timeout)

    def post(
        self,
        path: str,
        payload: dict[str, Any],
        timeout: float = 120.0,
    ) -> dict[str, Any]:
        return self.request("POST", path, payload=payload, timeout=timeout)


class Migrator:
    def __init__(self, args: argparse.Namespace):
        self.args = args
        self.manifest = self.load_manifest()
        self.args.source_bot = str(self.manifest["source_bot"])
        self.args.source_peer_id = int(self.manifest["source_peer_id"])
        self.args.target_peer_id = int(self.manifest["target_peer_id"])
        self.video_target_peer_id = int(self.manifest["video_target_peer_id"])
        self.image_target_peer_id = int(self.manifest["image_target_peer_id"])
        self.folders = [
            (str(folder["name"]), int(folder["count"]))
            for folder in self.manifest["folders"]
        ]
        self.expected_total = int(self.manifest["expected_total"])
        self.expected_media = int(self.manifest["expected_media"])
        self.expected_images = int(self.manifest["expected_images"])
        self.expected_videos = int(self.manifest["expected_videos"])
        self.expected_text = int(self.manifest["expected_text"])
        self.dedupe_scope = str(self.manifest["dedupe_scope"])
        self.api = Api(args.base_uri)
        self.state = self.load_or_initialize_state()

    def load_manifest(self) -> dict[str, Any]:
        if self.args.manifest_path:
            with open(self.args.manifest_path, "r", encoding="utf-8") as handle:
                manifest = json.load(handle)
        else:
            manifest = json.loads(json.dumps(DEFAULT_MANIFEST, ensure_ascii=False))

        required = (
            "name",
            "source_bot",
            "source_peer_id",
            "target_peer_id",
            "video_target_peer_id",
            "image_target_peer_id",
            "dedupe_scope",
            "expected_total",
            "expected_media",
            "expected_images",
            "expected_videos",
            "expected_text",
            "folders",
        )
        missing = [key for key in required if key not in manifest]
        if missing:
            raise MigrationBlocked(f"manifest is missing keys: {missing}")
        folders = list(manifest.get("folders") or [])
        if not folders:
            raise MigrationBlocked("manifest has no folders")
        if sum(int(folder["count"]) for folder in folders) != int(manifest["expected_total"]):
            raise MigrationBlocked("manifest folder counts do not match expected_total")
        if int(manifest["expected_images"]) + int(manifest["expected_videos"]) != int(
            manifest["expected_media"]
        ):
            raise MigrationBlocked("manifest media counts do not add up")
        if int(manifest["expected_media"]) + int(manifest["expected_text"]) != int(
            manifest["expected_total"]
        ):
            raise MigrationBlocked("manifest media and text counts do not add up")
        if not re.fullmatch(r"[A-Za-z0-9_.:-]{1,100}", str(manifest["dedupe_scope"])):
            raise MigrationBlocked("manifest dedupe_scope is invalid")
        return manifest

    def log(self, message: str, **fields: Any) -> None:
        row = {"at": now_iso(), "message": message, **fields}
        print(json.dumps(row, ensure_ascii=False, separators=(",", ":")), flush=True)

    def save(self) -> None:
        self.state["updated_at"] = now_iso()
        path = os.path.abspath(self.args.state_path)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        temporary = path + ".tmp"
        with open(temporary, "w", encoding="utf-8", newline="\n") as handle:
            json.dump(self.state, handle, ensure_ascii=False, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, 0o600)
        os.replace(temporary, path)

    def load_or_initialize_state(self) -> dict[str, Any]:
        path = os.path.abspath(self.args.state_path)
        if os.path.isfile(path):
            with open(path, "r", encoding="utf-8") as handle:
                state = json.load(handle)
            expected_identity = {
                "manifest_name": self.manifest["name"],
                "source_bot": self.manifest["source_bot"],
                "source_peer_id": int(self.manifest["source_peer_id"]),
                "target_peer_id": int(self.manifest["target_peer_id"]),
            }
            for key, value in expected_identity.items():
                if state.get(key) != value:
                    raise MigrationBlocked(f"checkpoint {key} does not match requested migration")
            state.setdefault("source_media_processed", int(state.get("copied_media") or 0))
            state.setdefault("source_images", int(state.get("copied_images") or 0))
            state.setdefault("source_videos", int(state.get("copied_videos") or 0))
            state.setdefault("duplicate_media", 0)
            state.setdefault("duplicate_images", 0)
            state.setdefault("duplicate_videos", 0)
            state.setdefault("dedupe_scope", self.dedupe_scope)
            state.setdefault("video_target_peer_id", self.video_target_peer_id)
            state.setdefault("image_target_peer_id", self.image_target_peer_id)
            state.setdefault("video_target_baseline_videos", 0)
            state.setdefault("image_target_baseline_images", 0)
            state.setdefault("last_video_target_message_id", 0)
            state.setdefault("last_image_target_message_id", 0)
            state.setdefault("folder_next_group_clicks", 0)
            state.setdefault("current_page_processed", 0)
            state.setdefault("source_recovery_count", 0)
            if state.get("dedupe_scope") != self.dedupe_scope:
                raise MigrationBlocked("checkpoint dedupe_scope does not match manifest")
            if int(state.get("video_target_peer_id") or 0) != self.video_target_peer_id:
                raise MigrationBlocked("checkpoint video target does not match manifest")
            if int(state.get("image_target_peer_id") or 0) != self.image_target_peer_id:
                raise MigrationBlocked("checkpoint image target does not match manifest")
            self.state = state
            self.save()
            return state

        if self.args.fresh:
            state = {
                "version": 1,
                "status": "running",
                "stage": "start_first_folder",
                "manifest_name": self.manifest["name"],
                "source_bot": self.manifest["source_bot"],
                "source_peer_id": int(self.manifest["source_peer_id"]),
                "target_peer_id": int(self.manifest["target_peer_id"]),
                "video_target_peer_id": self.video_target_peer_id,
                "image_target_peer_id": self.image_target_peer_id,
                "dedupe_scope": self.dedupe_scope,
                "folder_index": 0,
                "folder_name": "",
                "folder_expected": 0,
                "folder_processed": 0,
                "previous_control_id": 0,
                "processed_total": 0,
                "copied_media": 0,
                "copied_images": 0,
                "copied_videos": 0,
                "source_media_processed": 0,
                "source_images": 0,
                "source_videos": 0,
                "duplicate_media": 0,
                "duplicate_images": 0,
                "duplicate_videos": 0,
                "deleted_text": 0,
                "target_baseline_media": 0,
                "target_baseline_images": 0,
                "target_baseline_videos": 0,
                "video_target_baseline_videos": 0,
                "image_target_baseline_images": 0,
                "last_video_target_message_id": 0,
                "last_image_target_message_id": 0,
                "folder_next_group_clicks": 0,
                "current_page_processed": 0,
                "source_recovery_count": 0,
                "started_at": now_iso(),
                "updated_at": now_iso(),
            }
            self.state = state
            self.save()
            return state

        state = {
            "version": 1,
            "status": "running",
            "stage": "process_page",
            "manifest_name": self.manifest["name"],
            "source_bot": self.manifest["source_bot"],
            "source_peer_id": int(self.manifest["source_peer_id"]),
            "target_peer_id": int(self.manifest["target_peer_id"]),
            "video_target_peer_id": self.video_target_peer_id,
            "image_target_peer_id": self.image_target_peer_id,
            "dedupe_scope": self.dedupe_scope,
            "folder_index": 1,
            "folder_name": self.folders[0][0],
            "folder_expected": self.folders[0][1],
            "folder_processed": 1,
            "previous_control_id": self.args.initial_previous_control_id,
            "processed_total": 1,
            "copied_media": 1,
            "copied_images": 0,
            "copied_videos": 1,
            "source_media_processed": 1,
            "source_images": 0,
            "source_videos": 1,
            "duplicate_media": 0,
            "duplicate_images": 0,
            "duplicate_videos": 0,
            "deleted_text": 0,
            "target_baseline_media": 0,
            "target_baseline_images": 0,
            "target_baseline_videos": 0,
            "video_target_baseline_videos": 0,
            "image_target_baseline_images": 0,
            "last_source_message_id": self.args.initial_source_message_id,
            "last_target_message_id": self.args.initial_target_message_id,
            "last_video_target_message_id": self.args.initial_target_message_id,
            "last_image_target_message_id": 0,
            "folder_next_group_clicks": 0,
            "current_page_processed": 0,
            "source_recovery_count": 0,
            "started_at": now_iso(),
            "updated_at": now_iso(),
        }
        self.state = state
        self.save()
        return state

    @staticmethod
    def buttons(message: dict[str, Any]) -> list[dict[str, Any]]:
        result: list[dict[str, Any]] = []
        markup = message.get("reply_markup") or {}
        for row in markup.get("rows") or []:
            result.extend(row.get("buttons") or [])
        return result

    @staticmethod
    def is_regular_message(message: dict[str, Any]) -> bool:
        return message.get("_") == "Message" and int(message.get("id") or 0) > 0

    @staticmethod
    def media_kind(message: dict[str, Any]) -> str | None:
        media = message.get("media") or {}
        if media.get("_") == "MessageMediaPhoto":
            return "image"
        if media.get("_") == "MessageMediaDocument":
            mime_type = str((media.get("document") or {}).get("mime_type") or "").lower()
            if mime_type.startswith("image/"):
                return "image"
            if mime_type.startswith("video/"):
                return "video"
        return None

    def target_peer_for_kind(self, kind: str) -> int:
        if kind == "image":
            return self.image_target_peer_id
        if kind == "video":
            return self.video_target_peer_id
        raise MigrationBlocked(f"unsupported target media kind {kind!r}")

    def messages(
        self,
        peer_id: int,
        *,
        limit: int = 1000,
        offset_id: int = 0,
        min_id: int = 0,
        reverse: bool = False,
    ) -> list[dict[str, Any]]:
        query = urllib.parse.urlencode(
            {
                "limit": limit,
                "offset_id": offset_id,
                "min_id": min_id,
                "reverse": str(reverse).lower(),
                "include_raw": "true",
            }
        )
        response = self.api.get(f"/groups/{peer_id}?{query}", timeout=180.0)
        if response.get("status") != "ok":
            raise MigrationBlocked(f"message lookup failed: {response}")
        return list(response.get("items") or [])

    def message_exists(self, peer_id: int, message_id: int) -> bool:
        response = self.api.get(
            f"/groups/{peer_id}/{message_id}?include_next=false&include_raw=true",
            timeout=120.0,
        )
        return bool(response.get("items"))

    def latest_message_id(self, peer_id: int) -> int:
        items = self.messages(peer_id, limit=1)
        return max((int(item.get("id") or 0) for item in items), default=0)

    def backfill_source(self) -> None:
        response = self.api.post(
            "/bots/files",
            {
                "bot_username": self.args.source_bot,
                "min_message_id": 0,
                "max_return_files": 1,
                "max_raw_payload_bytes": 0,
                "backfill_limit": 1000,
                "backfill_timeout_seconds": 30,
                "force_backfill": True,
            },
            timeout=90.0,
        )
        if response.get("status") != "ok":
            raise MigrationBlocked(f"source backfill failed: {response}")

    def click(self, keyword: str) -> dict[str, Any]:
        self.backfill_source()
        response = self.api.post(
            "/bots/click-matching-button",
            {
                "bot_username": self.args.source_bot,
                "sent_message_id": 0,
                "clear_previous_replies": False,
                "button_keywords": [keyword],
                "debug": False,
                "include_files_in_response": False,
                "wait_after_click_timeout_seconds": 3,
                "cleanup_after_done": False,
                "callback_message_max_age_seconds": 86400,
                "callback_candidate_scan_limit": 100,
            },
            timeout=90.0,
        )
        if response.get("status") != "ok" or not response.get("button_clicked"):
            raise MigrationBlocked(
                f"button {keyword!r} was not clicked: {self.safe_click_response(response)}"
            )
        clicked_text = str(response.get("clicked_button_text") or "").strip()
        if keyword.isdigit() and clicked_text != keyword:
            raise MigrationBlocked(
                f"numeric folder selection mismatch: wanted {keyword}, clicked {clicked_text}"
            )
        self.log(
            "button_clicked",
            keyword=keyword,
            clicked_text=clicked_text,
            message_id=response.get("clicked_message_id"),
        )
        return response

    @staticmethod
    def safe_click_response(response: dict[str, Any]) -> dict[str, Any]:
        safe: dict[str, Any] = {
            "status": response.get("status"),
            "reason": response.get("reason"),
            "steps": response.get("steps"),
            "button_clicked": bool(response.get("button_clicked")),
            "clicked_message_id": int(response.get("clicked_message_id") or 0),
            "button_keywords": list(response.get("button_keywords") or []),
            "completed": bool(response.get("completed")),
        }
        outcome = response.get("outcome")
        if isinstance(outcome, dict):
            safe["outcome"] = {
                "has_files": bool(outcome.get("has_files")),
                "files_unique_count": int(outcome.get("files_unique_count") or 0),
                "latest_message_kind": outcome.get("latest_message_kind"),
                "run_completed": bool(outcome.get("run_completed")),
            }
        latest = response.get("latest_message")
        if isinstance(latest, dict):
            safe["latest_message"] = {
                "message_id": int(latest.get("message_id") or 0),
                "chat_id": int(latest.get("chat_id") or 0),
                "kind": latest.get("kind"),
                "has_buttons": bool(latest.get("has_buttons")),
                "page_info": latest.get("page_info"),
                "total_items": latest.get("total_items"),
            }
        safe_timeline = []
        for event in list(response.get("timeline") or [])[-5:]:
            if not isinstance(event, dict):
                continue
            safe_timeline.append(
                {
                    key: event.get(key)
                    for key in (
                        "step",
                        "status",
                        "reason",
                        "message_id",
                        "chat_id",
                        "effect_observed",
                    )
                    if key in event
                }
            )
        if safe_timeline:
            safe["timeline"] = safe_timeline
        return safe

    def delete_source(self, message_ids: list[int]) -> None:
        ids = sorted({int(message_id) for message_id in message_ids if int(message_id) > 0})
        if not ids:
            return
        response = self.api.post(
            "/bots/delete-messages",
            {"chat_peer": self.args.source_bot, "message_ids": ids},
            timeout=180.0,
        )
        if response.get("status") != "ok" or response.get("remaining_message_ids"):
            raise MigrationBlocked(f"source deletion incomplete: {response}")

    def target_media_after(self, target_peer_id: int, baseline: int) -> list[dict[str, Any]]:
        return [
            item
            for item in self.messages(
                target_peer_id,
                limit=100,
                min_id=baseline,
                reverse=True,
            )
            if self.is_regular_message(item) and self.media_kind(item)
        ]

    def register_source_hash(
        self,
        source_id: int,
        target_peer_id: int,
        target_id: int,
    ) -> str:
        response = self.api.post(
            "/messages/register-media-hash",
            {
                "media_peer_id": self.args.source_peer_id,
                "message_id": source_id,
                "target_peer_id": target_peer_id,
                "target_message_id": target_id,
                "dedupe_scope": self.dedupe_scope,
            },
            timeout=7200.0,
        )
        if response.get("status") != "ok":
            raise MigrationBlocked(f"source hash registration failed: {response}")
        return str(response.get("content_sha256") or "")

    def mark_source_complete(
        self,
        source_id: int,
        target_id: int,
        kind: str,
        *,
        target_peer_id: int,
        duplicate: bool,
        content_sha256: str,
    ) -> None:
        self.state["stage"] = "source_copied"
        self.state["active_source_message_id"] = source_id
        self.state["active_target_message_id"] = target_id
        self.state["active_target_peer_id"] = target_peer_id
        self.state["active_media_kind"] = kind
        self.state["active_duplicate"] = bool(duplicate)
        self.state["active_content_sha256"] = content_sha256
        self.save()
        self.delete_source([source_id])
        self.state["processed_total"] += 1
        self.state["folder_processed"] += 1
        self.state["source_media_processed"] += 1
        if kind == "image":
            self.state["source_images"] += 1
        elif kind == "video":
            self.state["source_videos"] += 1
        if duplicate:
            self.state["duplicate_media"] += 1
            if kind == "image":
                self.state["duplicate_images"] += 1
            elif kind == "video":
                self.state["duplicate_videos"] += 1
        else:
            self.state["copied_media"] += 1
            if kind == "image":
                self.state["copied_images"] += 1
            elif kind == "video":
                self.state["copied_videos"] += 1
        self.state["last_source_message_id"] = source_id
        self.state["last_target_message_id"] = max(
            int(self.state.get("last_target_message_id") or 0),
            target_id,
        )
        if kind == "image":
            self.state["last_image_target_message_id"] = max(
                int(self.state.get("last_image_target_message_id") or 0),
                target_id,
            )
        elif kind == "video":
            self.state["last_video_target_message_id"] = max(
                int(self.state.get("last_video_target_message_id") or 0),
                target_id,
            )
        self.state["current_page_processed"] = int(
            self.state.get("current_page_processed") or 0
        ) + 1
        self.state["last_content_sha256"] = content_sha256
        for key in (
            "active_source_message_id",
            "active_target_message_id",
            "active_target_peer_id",
            "active_media_kind",
            "active_duplicate",
            "active_content_sha256",
            "copy_target_baseline",
            "copy_target_peer_id",
        ):
            self.state.pop(key, None)
        self.state["stage"] = "process_page"
        self.save()
        self.log(
            "media_copied_and_source_deleted",
            source_message_id=source_id,
            target_message_id=target_id,
            target_peer_id=target_peer_id,
            kind=kind,
            duplicate=duplicate,
            copied_media=self.state["copied_media"],
            duplicate_media=self.state["duplicate_media"],
            source_media_processed=self.state["source_media_processed"],
            processed_total=self.state["processed_total"],
            folder_index=self.state["folder_index"],
        )

    def schedule_source_page_recovery(self, source_id: int, kind: str) -> None:
        folder_start_counts = self.state.get("folder_start_counts")
        if not isinstance(folder_start_counts, dict) or any(
            key not in folder_start_counts for key in FOLDER_COUNTER_KEYS
        ):
            raise MigrationBlocked(
                "source page recovery has no complete folder-start counter snapshot"
            )
        recovery_count = int(self.state.get("source_recovery_count") or 0) + 1
        if recovery_count > 10:
            raise MigrationBlocked(
                f"source page recovery exceeded limit for source {source_id}"
            )
        progress_before_recovery = {
            "processed_total": int(self.state.get("processed_total") or 0),
            "folder_processed": int(self.state.get("folder_processed") or 0),
        }
        self.state.update(
            {
                "status": "running",
                "stage": "resume_current_folder",
                **{
                    key: int(folder_start_counts[key])
                    for key in FOLDER_COUNTER_KEYS
                },
                "folder_processed": 0,
                "folder_next_group_clicks": 0,
                "current_page_processed": 0,
                "source_recovery_count": recovery_count,
                "source_recovery_source_message_id": source_id,
                "source_recovery_media_kind": kind,
                "source_recovery_progress_before": progress_before_recovery,
            }
        )
        for key in (
            "active_source_message_id",
            "active_target_message_id",
            "active_target_peer_id",
            "active_media_kind",
            "active_duplicate",
            "active_content_sha256",
            "copy_target_baseline",
            "copy_target_peer_id",
            "replay_next_groups_remaining",
            "replay_current_page_processed",
            "active_page_media",
            "active_page_text_ids",
            "active_page_results",
        ):
            self.state.pop(key, None)
        self.state.pop("blocked_reason", None)
        self.state.pop("blocked_at", None)
        self.save()
        self.log(
            "source_page_recovery_scheduled",
            source_message_id=source_id,
            kind=kind,
            folder_index=self.state.get("folder_index"),
            folder_processed=self.state.get("folder_processed"),
            progress_before_recovery=progress_before_recovery,
            recovery_count=recovery_count,
        )

    def schedule_folder_control_recovery(self, reason: str, control_id: int = 0) -> None:
        folder_start_counts = self.state.get("folder_start_counts")
        if not isinstance(folder_start_counts, dict) or any(
            key not in folder_start_counts for key in FOLDER_COUNTER_KEYS
        ):
            raise MigrationBlocked(
                "folder control recovery has no complete folder-start counter snapshot"
            )
        recovery_count = int(self.state.get("source_recovery_count") or 0) + 1
        if recovery_count > 10:
            raise MigrationBlocked(
                f"folder control recovery exceeded limit for folder {self.state.get('folder_index')}"
            )
        progress_before_recovery = {
            "processed_total": int(self.state.get("processed_total") or 0),
            "folder_processed": int(self.state.get("folder_processed") or 0),
        }
        self.state.update(
            {
                "status": "running",
                "stage": "resume_current_folder",
                **{
                    key: int(folder_start_counts[key])
                    for key in FOLDER_COUNTER_KEYS
                },
                "folder_processed": 0,
                "folder_next_group_clicks": 0,
                "current_page_processed": 0,
                "source_recovery_count": recovery_count,
                "source_recovery_reason": reason,
                "source_recovery_control_id": int(control_id or 0),
                "source_recovery_progress_before": progress_before_recovery,
            }
        )
        for key in (
            "active_source_message_id",
            "active_target_message_id",
            "active_target_peer_id",
            "active_media_kind",
            "active_duplicate",
            "active_content_sha256",
            "copy_target_baseline",
            "copy_target_peer_id",
            "replay_next_groups_remaining",
            "replay_current_page_processed",
            "active_page_media",
            "active_page_text_ids",
            "active_page_results",
            "blocked_reason",
            "blocked_at",
        ):
            self.state.pop(key, None)
        self.save()
        self.log(
            "folder_control_recovery_scheduled",
            reason=reason,
            control_id=int(control_id or 0),
            folder_index=self.state.get("folder_index"),
            progress_before_recovery=progress_before_recovery,
            recovery_count=recovery_count,
        )

    def recover_pending_copy(self) -> None:
        stage = self.state.get("stage")
        if stage not in ("copying_source", "source_copied"):
            return

        source_id = int(self.state.get("active_source_message_id") or 0)
        kind = str(self.state.get("active_media_kind") or "")
        if source_id <= 0 or kind not in ("image", "video"):
            raise MigrationBlocked("invalid active copy checkpoint")

        if stage == "copying_source":
            baseline = int(self.state.get("copy_target_baseline") or 0)
            target_peer_id = int(
                self.state.get("copy_target_peer_id")
                or self.target_peer_for_kind(kind)
            )
            candidates = self.target_media_after(target_peer_id, baseline)
            if len(candidates) > 1:
                raise MigrationBlocked(
                    f"copy recovery is ambiguous after target {baseline}: "
                    f"{[item.get('id') for item in candidates]}"
                )
            if len(candidates) == 1:
                candidate = candidates[0]
                if self.media_kind(candidate) != kind:
                    raise MigrationBlocked("copy recovery target media kind mismatch")
                if str(candidate.get("message") or "") or candidate.get("fwd_from") is not None:
                    raise MigrationBlocked("copy recovery target contains caption or forward attribution")
                target_id = int(candidate.get("id") or 0)
                content_sha256 = self.register_source_hash(
                    source_id,
                    target_peer_id,
                    target_id,
                )
                self.mark_source_complete(
                    source_id,
                    target_id,
                    kind,
                    target_peer_id=target_peer_id,
                    duplicate=False,
                    content_sha256=content_sha256,
                )
                return
            if not self.message_exists(self.args.source_peer_id, source_id):
                self.schedule_source_page_recovery(source_id, kind)
                return
            self.state["stage"] = "process_page"
            for key in (
                "active_source_message_id",
                "active_media_kind",
                "copy_target_baseline",
                "copy_target_peer_id",
            ):
                self.state.pop(key, None)
            self.save()
            return

        target_id = int(self.state.get("active_target_message_id") or 0)
        target_peer_id = int(
            self.state.get("active_target_peer_id")
            or self.target_peer_for_kind(kind)
        )
        if target_id <= 0:
            raise MigrationBlocked("copied source checkpoint has no target id")
        self.mark_source_complete(
            source_id,
            target_id,
            kind,
            target_peer_id=target_peer_id,
            duplicate=bool(self.state.get("active_duplicate")),
            content_sha256=str(self.state.get("active_content_sha256") or ""),
        )

    def copy_media(self, message: dict[str, Any]) -> None:
        source_id = int(message.get("id") or 0)
        kind = self.media_kind(message) or str(
            message.get("media_kind") or message.get("kind") or ""
        )
        if source_id <= 0 or kind not in ("image", "video"):
            raise MigrationBlocked("invalid source media message")

        target_peer_id = self.target_peer_for_kind(kind)
        baseline = self.latest_message_id(target_peer_id)
        self.state.update(
            {
                "stage": "copying_source",
                "active_source_message_id": source_id,
                "active_media_kind": kind,
                "copy_target_baseline": baseline,
                "copy_target_peer_id": target_peer_id,
            }
        )
        self.save()

        retry = 0
        while True:
            retry += 1
            try:
                response = self.api.post(
                    "/messages/copy-protected-media-batch",
                    {
                        "source_peer_id": self.args.source_peer_id,
                        "message_ids": [source_id],
                        "target_peer_id": target_peer_id,
                        "drop_media_captions": True,
                        "dedupe_scope": self.dedupe_scope,
                    },
                    timeout=7200.0,
                )
            except (TimeoutError, urllib.error.URLError, ConnectionError) as error:
                self.log(
                    "copy_request_transport_error",
                    source_message_id=source_id,
                    retry=retry,
                    error=str(error),
                )
                self.recover_pending_copy()
                if self.state.get("stage") == "process_page":
                    if not self.message_exists(self.args.source_peer_id, source_id):
                        return
                    if retry >= 6:
                        raise
                    time.sleep(min(120, 5 * retry))
                    self.state.update(
                        {
                            "stage": "copying_source",
                            "active_source_message_id": source_id,
                            "active_media_kind": kind,
                            "copy_target_baseline": self.latest_message_id(target_peer_id),
                            "copy_target_peer_id": target_peer_id,
                        }
                    )
                    self.save()
                    continue
                return

            if response.get("status") == "ok":
                results = list(response.get("results") or [])
                if len(results) != 1:
                    raise MigrationBlocked(f"copy response count mismatch: {response}")
                result = results[0]
                if int(result.get("source_message_id") or 0) != source_id:
                    raise MigrationBlocked(f"copy response source mismatch: {response}")
                target_id = int(result.get("target_message_id") or 0)
                if target_id <= 0:
                    raise MigrationBlocked(f"copy response target missing: {response}")
                self.mark_source_complete(
                    source_id,
                    target_id,
                    kind,
                    target_peer_id=target_peer_id,
                    duplicate=bool(result.get("duplicate")),
                    content_sha256=str(result.get("content_sha256") or ""),
                )
                return

            self.log(
                "copy_request_application_error",
                source_message_id=source_id,
                retry=retry,
                reason=response.get("reason"),
                error=response.get("error"),
            )
            self.recover_pending_copy()
            if not self.message_exists(self.args.source_peer_id, source_id):
                return
            if retry >= 8:
                raise MigrationBlocked(f"copy failed repeatedly for source {source_id}: {response}")
            time.sleep(min(180, 10 * retry))
            self.state.update(
                {
                    "stage": "copying_source",
                    "active_source_message_id": source_id,
                    "active_media_kind": kind,
                    "copy_target_baseline": self.latest_message_id(target_peer_id),
                    "copy_target_peer_id": target_peer_id,
                }
            )
            self.save()

    def prepare_page_items(self, page_items: list[dict[str, Any]]) -> None:
        media_items = [item for item in page_items if self.media_kind(item)]
        text_ids = [
            int(item.get("id") or 0)
            for item in page_items
            if not self.media_kind(item) and int(item.get("id") or 0) > 0
        ]
        media_entries = [
            {
                "source_message_id": int(item.get("id") or 0),
                "kind": str(self.media_kind(item) or ""),
            }
            for item in media_items
        ]
        if any(
            entry["source_message_id"] <= 0
            or entry["kind"] not in ("image", "video")
            for entry in media_entries
        ):
            raise MigrationBlocked("source page contains an invalid media item")

        self.state.update(
            {
                "stage": "copying_page",
                "active_page_media": media_entries,
                "active_page_text_ids": text_ids,
                "active_page_results": [],
            }
        )
        self.save()

        self.state["stage"] = "page_items_ready"
        self.save()

    def recover_pending_page_copy(self) -> None:
        media_entries = list(self.state.get("active_page_media") or [])
        if not media_entries:
            raise MigrationBlocked("pending page copy has no media checkpoint")
        first = media_entries[0]
        source_id = int(first.get("source_message_id") or 0)
        kind = str(first.get("kind") or "")
        if source_id <= 0 or kind not in ("image", "video"):
            raise MigrationBlocked("pending page copy checkpoint is invalid")
        self.schedule_source_page_recovery(source_id, kind)

    def complete_page_items(self) -> None:
        text_ids = [
            int(message_id)
            for message_id in self.state.get("active_page_text_ids") or []
            if int(message_id or 0) > 0
        ]
        expected_media = list(self.state.get("active_page_media") or [])
        for entry in expected_media:
            source_id = int(entry.get("source_message_id") or 0)
            kind = str(entry.get("kind") or "")
            if source_id <= 0 or kind not in ("image", "video"):
                raise MigrationBlocked("ready page media entry is invalid")
            self.copy_media({"id": source_id, "media_kind": kind})

        for message_id in text_ids:
            self.delete_text_item(message_id)

        for key in (
            "active_page_media",
            "active_page_text_ids",
            "active_page_results",
        ):
            self.state.pop(key, None)
        self.state["stage"] = "process_page"
        self.save()

    def delete_text_item(self, message_id: int) -> None:
        self.state.update(
            {
                "stage": "deleting_text",
                "active_source_message_id": message_id,
            }
        )
        self.save()
        self.delete_source([message_id])
        self.state["processed_total"] += 1
        self.state["folder_processed"] += 1
        self.state["deleted_text"] += 1
        self.state["current_page_processed"] = int(
            self.state.get("current_page_processed") or 0
        ) + 1
        self.state["last_source_message_id"] = message_id
        self.state.pop("active_source_message_id", None)
        self.state["stage"] = "process_page"
        self.save()
        self.log(
            "source_text_deleted",
            source_message_id=message_id,
            deleted_text=self.state["deleted_text"],
            processed_total=self.state["processed_total"],
            folder_index=self.state["folder_index"],
        )

    def recover_deleting_text(self) -> None:
        if self.state.get("stage") != "deleting_text":
            return
        message_id = int(self.state.get("active_source_message_id") or 0)
        if message_id <= 0:
            raise MigrationBlocked("invalid text deletion checkpoint")
        if self.message_exists(self.args.source_peer_id, message_id):
            self.delete_source([message_id])
        self.state["processed_total"] += 1
        self.state["folder_processed"] += 1
        self.state["deleted_text"] += 1
        self.state["last_source_message_id"] = message_id
        self.state.pop("active_source_message_id", None)
        self.state["stage"] = "process_page"
        self.save()

    def current_page(
        self,
        *,
        required_button_keyword: str = "",
    ) -> tuple[list[dict[str, Any]], dict[str, Any]]:
        previous_control_id = int(self.state.get("previous_control_id") or 0)
        deadline = time.time() + 180
        while time.time() < deadline:
            candidates = sorted(
                (
                    item
                    for item in self.messages(self.args.source_peer_id, limit=1000)
                    if self.is_regular_message(item)
                    and int(item.get("id") or 0) > previous_control_id
                ),
                key=lambda item: int(item.get("id") or 0),
            )
            controls = [item for item in candidates if self.buttons(item)]
            if required_button_keyword:
                controls = [
                    item
                    for item in controls
                    if any(
                        required_button_keyword in str(button.get("text") or "")
                        for button in self.buttons(item)
                    )
                ]
            if controls:
                control = controls[-1]
                control_id = int(control.get("id") or 0)
                page_items = [
                    item
                    for item in candidates
                    if int(item.get("id") or 0) < control_id and not self.buttons(item)
                ]
                return page_items, control
            time.sleep(3)
        raise MigrationBlocked("timed out waiting for the next source page control")

    def navigate_to_folder(self, folder_index: int) -> None:
        if folder_index < 1 or folder_index > len(self.folders):
            raise MigrationBlocked(f"invalid folder index {folder_index}")
        page_number = ((folder_index - 1) // 10) + 1
        for _ in range(1, page_number):
            self.click("下一页")
        position = ((folder_index - 1) % 10) + 1
        folder_response = self.click(str(position))
        detail_message_id = int(folder_response.get("clicked_message_id") or 0)
        if detail_message_id <= 0:
            raise MigrationBlocked("folder detail message id missing")

        details = self.api.get(
            f"/groups/{self.args.source_peer_id}/{detail_message_id}"
            "?include_next=false&include_raw=true",
            timeout=120.0,
        )
        detail_items = list(details.get("items") or [])
        if not detail_items:
            raise MigrationBlocked("folder detail message missing after selection")
        detail_text = str(detail_items[0].get("message") or "")
        expected_name, expected_count = self.folders[folder_index - 1]
        count_match = re.search(r"消息数[：:]\s*(\d+)", detail_text)
        if expected_name not in detail_text or not count_match or int(count_match.group(1)) != expected_count:
            raise MigrationBlocked(
                f"folder detail mismatch for index {folder_index}: {detail_text[:300]}"
            )

        view_response = self.click("查看内容")
        if int(view_response.get("clicked_message_id") or 0) != detail_message_id:
            raise MigrationBlocked("view-content callback moved to an unexpected control message")
        folder_start_counts = {
            key: int(self.state.get(key) or 0) for key in FOLDER_COUNTER_KEYS
        }
        self.state.update(
            {
                "stage": "process_page",
                "folder_index": folder_index,
                "folder_name": expected_name,
                "folder_expected": expected_count,
                "folder_processed": 0,
                "folder_next_group_clicks": 0,
                "current_page_processed": 0,
                "folder_start_counts": folder_start_counts,
                "previous_control_id": detail_message_id,
            }
        )
        self.state.pop("replay_next_groups_remaining", None)
        self.state.pop("replay_current_page_processed", None)
        self.save()
        self.current_page()

    def resume_current_folder(self) -> None:
        folder_index = int(self.state.get("folder_index") or 0)
        if folder_index <= 0:
            raise MigrationBlocked("source page recovery has no current folder")

        response = self.api.post(
            "/bots/send",
            {
                "bot_username": self.args.source_bot,
                "text": "/start",
                "clear_previous_replies": True,
            },
            timeout=120.0,
        )
        if response.get("status") != "ok":
            raise MigrationBlocked(f"source recovery /start failed: {response}")
        self.state["source_recovery_start_message_id"] = int(
            response.get("sent_message_id") or 0
        )
        self.save()
        self.click("文件夹列表")
        self.navigate_to_folder(folder_index)
        self.log(
            "source_folder_restart_started",
            folder_index=folder_index,
            folder_name=self.state.get("folder_name"),
            folder_start_counts=self.state.get("folder_start_counts"),
            recovery_count=self.state.get("source_recovery_count"),
        )

    def finish_folder(self, control: dict[str, Any]) -> None:
        expected = int(self.state.get("folder_expected") or 0)
        processed = int(self.state.get("folder_processed") or 0)
        if processed != expected:
            raise MigrationBlocked(
                f"folder {self.state['folder_index']} count mismatch: {processed}/{expected}"
            )

        self.log(
            "folder_completed",
            folder_index=self.state["folder_index"],
            folder_name=self.state["folder_name"],
            processed=processed,
            copied_media=self.state["copied_media"],
            deleted_text=self.state["deleted_text"],
        )
        folder_index = int(self.state["folder_index"])
        if folder_index >= len(self.folders):
            self.state["stage"] = "clear_source_dialog"
            self.save()
            return

        button_texts = [str(button.get("text") or "") for button in self.buttons(control)]
        if not any("文件夹列表" in text for text in button_texts):
            raise MigrationBlocked("folder completion control has no folder-list button")
        self.click("文件夹列表")
        self.navigate_to_folder(folder_index + 1)

    def process_current_page(self) -> None:
        page_items, control = self.current_page()
        if page_items:
            self.prepare_page_items(page_items)
            if self.state.get("stage") == "resume_current_folder":
                return
            self.complete_page_items()

        button_texts = [str(button.get("text") or "") for button in self.buttons(control)]
        next_buttons = [text for text in button_texts if "下一组" in text]
        if next_buttons:
            control_id = int(control.get("id") or 0)
            self.click("下一组")
            self.state["previous_control_id"] = control_id
            self.state["folder_next_group_clicks"] = int(
                self.state.get("folder_next_group_clicks") or 0
            ) + 1
            self.state["current_page_processed"] = 0
            self.state["stage"] = "process_page"
            self.save()
            self.current_page()
            return

        expected = int(self.state.get("folder_expected") or 0)
        processed = int(self.state.get("folder_processed") or 0)
        if expected > 0 and processed != expected:
            self.schedule_folder_control_recovery(
                "folder_control_missing_next_before_expected_count",
                int(control.get("id") or 0),
            )
            return

        self.finish_folder(control)

    def clear_source_dialog(self) -> None:
        for _ in range(20):
            items = self.messages(self.args.source_peer_id, limit=1000)
            ids = [
                int(item.get("id") or 0)
                for item in items
                if self.is_regular_message(item)
            ]
            if not ids:
                break
            for offset in range(0, len(ids), 100):
                self.delete_source(ids[offset : offset + 100])
        remaining = [
            item
            for item in self.messages(self.args.source_peer_id, limit=1000)
            if self.is_regular_message(item)
        ]
        if remaining:
            raise MigrationBlocked(
                f"source dialog still has messages: {[item.get('id') for item in remaining[:20]]}"
            )
        self.state["stage"] = "verify_target"
        self.save()
        self.log("source_dialog_cleared")

    def target_counts(self, peer_id: int) -> dict[str, int]:
        offset_id = 0
        media_count = 0
        image_count = 0
        video_count = 0
        text_message_ids: list[int] = []
        attribution_message_ids: list[int] = []
        seen_ids: set[int] = set()

        while True:
            items = self.messages(
                peer_id,
                limit=1000,
                offset_id=offset_id,
            )
            regular = [item for item in items if self.is_regular_message(item)]
            if not regular:
                break
            new_items = [
                item for item in regular if int(item.get("id") or 0) not in seen_ids
            ]
            if not new_items:
                break
            for item in new_items:
                message_id = int(item.get("id") or 0)
                seen_ids.add(message_id)
                kind = self.media_kind(item)
                if kind:
                    media_count += 1
                    if kind == "image":
                        image_count += 1
                    elif kind == "video":
                        video_count += 1
                    if item.get("fwd_from") is not None or str(item.get("message") or ""):
                        attribution_message_ids.append(message_id)
                else:
                    text_message_ids.append(message_id)
            offset_id = min(int(item.get("id") or 0) for item in new_items)
            if offset_id <= 1:
                break

        return {
            "media": media_count,
            "images": image_count,
            "videos": video_count,
            "text": len(text_message_ids),
            "attribution": len(attribution_message_ids),
        }

    def start_first_folder(self) -> None:
        video_baseline = self.target_counts(self.video_target_peer_id)
        image_baseline = self.target_counts(self.image_target_peer_id)
        if video_baseline != {
            "media": video_baseline["videos"],
            "images": 0,
            "videos": video_baseline["videos"],
            "text": 0,
            "attribution": 0,
        }:
            raise MigrationBlocked(
                f"video target baseline is not clean: {video_baseline}"
            )
        if image_baseline != {
            "media": image_baseline["images"],
            "images": image_baseline["images"],
            "videos": 0,
            "text": 0,
            "attribution": 0,
        }:
            raise MigrationBlocked(
                f"image target baseline is not clean: {image_baseline}"
            )
        self.state.update(
            {
                "target_baseline_media": (
                    video_baseline["media"] + image_baseline["media"]
                ),
                "target_baseline_images": image_baseline["images"],
                "target_baseline_videos": video_baseline["videos"],
                "video_target_baseline_videos": video_baseline["videos"],
                "image_target_baseline_images": image_baseline["images"],
            }
        )
        self.save()

        response = self.api.post(
            "/bots/send",
            {
                "bot_username": self.args.source_bot,
                "text": "/start",
                "clear_previous_replies": True,
            },
            timeout=120.0,
        )
        if response.get("status") != "ok":
            raise MigrationBlocked(f"source /start failed: {response}")
        self.state["start_message_id"] = int(response.get("sent_message_id") or 0)
        self.save()
        self.click("文件夹列表")
        self.navigate_to_folder(1)

    def verify_target(self) -> None:
        actual_video = self.target_counts(self.video_target_peer_id)
        actual_image = self.target_counts(self.image_target_peer_id)
        if (
            actual_video["media"] != actual_video["videos"]
            or actual_video["images"] != 0
            or actual_video["text"] != 0
            or actual_video["attribution"] != 0
        ):
            raise MigrationBlocked(
                f"video target routing or cleanliness mismatch: {actual_video}"
            )
        if (
            actual_image["media"] != actual_image["images"]
            or actual_image["videos"] != 0
            or actual_image["text"] != 0
            or actual_image["attribution"] != 0
        ):
            raise MigrationBlocked(
                f"image target routing or cleanliness mismatch: {actual_image}"
            )
        if int(self.state.get("processed_total") or 0) != self.expected_total:
            raise MigrationBlocked("processed source total does not match manifest")
        if int(self.state.get("source_media_processed") or 0) != self.expected_media:
            raise MigrationBlocked("processed source media total does not match manifest")
        if int(self.state.get("source_images") or 0) != self.expected_images:
            raise MigrationBlocked("processed source image total does not match manifest")
        if int(self.state.get("source_videos") or 0) != self.expected_videos:
            raise MigrationBlocked("processed source video total does not match manifest")
        if int(self.state.get("copied_media") or 0) + int(
            self.state.get("duplicate_media") or 0
        ) != self.expected_media:
            raise MigrationBlocked("unique plus duplicate media total does not match manifest")
        if int(self.state.get("deleted_text") or 0) != self.expected_text:
            raise MigrationBlocked("deleted text total does not match manifest")

        self.state.update(
            {
                "status": "complete",
                "stage": "complete",
                "completed_at": now_iso(),
                "verified_target_media": (
                    actual_video["media"] + actual_image["media"]
                ),
                "verified_target_images": actual_image["images"],
                "verified_target_videos": actual_video["videos"],
                "verified_target_text": (
                    actual_video["text"] + actual_image["text"]
                ),
                "verified_target_attribution": (
                    actual_video["attribution"] + actual_image["attribution"]
                ),
                "verified_video_target": actual_video,
                "verified_image_target": actual_image,
                "verified_unique_media_added": int(self.state.get("copied_media") or 0),
                "verified_duplicate_media_skipped": int(self.state.get("duplicate_media") or 0),
            }
        )
        self.save()
        self.log(
            "migration_complete",
            video_target=actual_video,
            image_target=actual_image,
        )

    def run(self) -> None:
        self.log(
            "migration_runner_started",
            stage=self.state.get("stage"),
            folder_index=self.state.get("folder_index"),
            processed_total=self.state.get("processed_total"),
            copied_media=self.state.get("copied_media"),
        )
        while self.state.get("status") == "running":
            stage = self.state.get("stage")
            if stage == "start_first_folder":
                self.start_first_folder()
            elif stage == "resume_current_folder":
                self.resume_current_folder()
            elif stage == "copying_page":
                self.recover_pending_page_copy()
            elif stage == "page_items_ready":
                self.complete_page_items()
            elif stage in ("copying_source", "source_copied"):
                self.recover_pending_copy()
            elif stage == "deleting_text":
                self.recover_deleting_text()
            elif stage == "process_page":
                self.process_current_page()
            elif stage == "clear_source_dialog":
                self.clear_source_dialog()
            elif stage == "verify_target":
                self.verify_target()
            else:
                raise MigrationBlocked(f"unsupported checkpoint stage {stage!r}")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-uri", default="http://127.0.0.1:8004")
    parser.add_argument("--manifest-path", default="")
    parser.add_argument("--fresh", action="store_true")
    parser.add_argument("--source-bot", default="yuanchaungbot")
    parser.add_argument("--source-peer-id", type=int, default=8766016058)
    parser.add_argument("--target-peer-id", type=int, default=3995547485)
    parser.add_argument(
        "--state-path",
        default="/var/lib/blog-telegram/epan-original-migration.json",
    )
    parser.add_argument("--initial-previous-control-id", type=int, default=354)
    parser.add_argument("--initial-source-message-id", type=int, default=375)
    parser.add_argument("--initial-target-message-id", type=int, default=3)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    migrator: Migrator | None = None
    try:
        migrator = Migrator(args)
        migrator.run()
        return 0
    except MigrationBlocked as error:
        if migrator is not None:
            migrator.state["status"] = "blocked"
            migrator.state["blocked_reason"] = str(error)
            migrator.state["blocked_at"] = now_iso()
            migrator.save()
        print(
            json.dumps(
                {"at": now_iso(), "message": "migration_blocked", "error": str(error)},
                ensure_ascii=False,
                separators=(",", ":"),
            ),
            file=sys.stderr,
            flush=True,
        )
        return 2
    except Exception as error:
        print(
            json.dumps(
                {
                    "at": now_iso(),
                    "message": "migration_crashed",
                    "error": f"{type(error).__name__}: {error}",
                },
                ensure_ascii=False,
                separators=(",", ":"),
            ),
            file=sys.stderr,
            flush=True,
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
