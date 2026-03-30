from fastapi import FastAPI
from fastapi.responses import FileResponse
from pydantic import BaseModel
from telethon import TelegramClient, events
from telethon.tl.functions.messages import GetBotCallbackAnswerRequest, DeleteHistoryRequest
from telethon.tl.types import Message, DocumentAttributeFilename, DocumentAttributeVideo
from telethon.errors.rpcerrorlist import MessageIdInvalidError
from telethon.errors import FloodWaitError

import uuid
import re
import asyncio
import time
import traceback
import zipfile
import os
import subprocess
import json
import mimetypes
from typing import Optional, List, Dict, Any, Tuple, Set
from datetime import datetime, date

api_id = 27946982
api_hash = "2324efd7bed05b02a63e3809fa93048c"
SERVICE_HOME = os.environ.get("TELEGRAM_SERVICE_HOME") or os.path.dirname(os.path.abspath(__file__))
SESSION_NAME = os.environ.get("TELEGRAM_SERVICE_SESSION", "session/main_account")

app = FastAPI()
client = TelegramClient(SESSION_NAME, api_id, api_hash)

MESSAGE_STORE: Dict[Tuple[int, int], Dict[str, Any]] = {}
DEBUG_LOGS: List[Dict[str, Any]] = []

INVALID_CALLBACK_MIDS: Dict[str, float] = {}
INVALID_CALLBACK_TTL_SECONDS = 180.0

_PEER_CACHE: Dict[str, Any] = {}

RECENT_USED_CALLBACK_ACTIONS: Dict[str, float] = {}
RECENT_USED_CALLBACK_TTL_SECONDS = 45.0

_BACKFILL_LAST_AT: Dict[str, float] = {}
_BACKFILL_THROTTLE_SECONDS = 0.9
DOWNLOAD_SEEN_KEYS_BY_JOB: Dict[str, Set[str]] = {}
DOWNLOAD_RESERVED_PATHS_BY_JOB: Dict[str, Set[str]] = {}

DOWNLOAD_JOBS: Dict[str, Dict[str, Any]] = {}
DOWNLOAD_SEEN_KEYS: Set[str] = set()
DOWNLOAD_SEMAPHORE = asyncio.Semaphore(2)
BACKGROUND_TELETHON_DOWNLOAD_TIMEOUT_SECONDS = 900
GROUP_TELETHON_DOWNLOAD_TIMEOUT_SECONDS = 180

TDL_EXE_PATH = r"C:\Users\User\Videos\Captures\tdl.exe"
TDL_DOWNLOAD_THREADS = 12
TDL_DOWNLOAD_LIMIT = 32
FFPROBE_EXE_PATH = r"C:\ffmpeg\bin\ffprobe.exe"


def _probe_video_metadata(file_path: str) -> Tuple[Optional[float], Optional[int], Optional[int]]:
    if not os.path.isfile(file_path) or not os.path.isfile(FFPROBE_EXE_PATH):
        return None, None, None

    try:
        proc = subprocess.run(
            [
                FFPROBE_EXE_PATH,
                "-v",
                "error",
                "-select_streams",
                "v:0",
                "-show_entries",
                "stream=width,height,duration",
                "-of",
                "json",
                file_path,
            ],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="ignore",
            timeout=20,
            check=True,
        )
        payload = json.loads(proc.stdout or "{}")
        streams = payload.get("streams") or []
        if not streams:
            return None, None, None

        stream = streams[0] or {}
        width = int(stream.get("width") or 0) or None
        height = int(stream.get("height") or 0) or None
        duration_raw = stream.get("duration")
        duration = float(duration_raw) if duration_raw not in (None, "") else None
        if duration is not None and duration < 0:
            duration = None
        return duration, width, height
    except Exception as e:
        push_log(
            stage="probe_video_metadata",
            result="error",
            extra={
                "file_path": file_path,
                "error": str(e),
                "trace": traceback.format_exc()[:1200],
            },
        )
        return None, None, None

def _clear_debug_logs():
    try:
        DEBUG_LOGS.clear()
    except Exception:
        pass


def _is_disconnected_error(err: Exception) -> bool:
    text = str(err or "").strip().lower()
    if not text:
        return False
    return (
        "cannot send requests while disconnected" in text
        or "disconnected" in text
        or "connection was closed" in text
        or "not connected" in text
    )


async def _ensure_client_connected(force_reconnect: bool = False) -> bool:
    try:
        is_connected = bool(client.is_connected())
    except Exception:
        is_connected = False

    if is_connected and not force_reconnect:
        return True

    if is_connected and force_reconnect:
        try:
            await client.disconnect()
        except Exception:
            pass

    try:
        await client.connect()
    except Exception as e:
        push_log(stage="client_connect", result="connect_error", extra={"error": str(e), "trace": traceback.format_exc()[:1200]})
        return False

    try:
        if client.is_connected():
            push_log(stage="client_connect", result="ok", extra={"force_reconnect": bool(force_reconnect)})
            return True
    except Exception:
        pass

    try:
        await client.start()
        push_log(stage="client_connect", result="start_ok", extra={"force_reconnect": bool(force_reconnect)})
        return bool(client.is_connected())
    except Exception as e:
        push_log(stage="client_connect", result="start_error", extra={"error": str(e), "trace": traceback.format_exc()[:1200]})
        return False

def _now_ms() -> int:
    return int(time.time() * 1000)


def _now_s() -> float:
    return time.time()


def push_log(
    stage: str,
    result: str = "",
    iter: int = -1,
    step: int = -1,
    extra: Optional[Dict[str, Any]] = None,
    max_logs: int = 2000
):
    entry = {
        "ts_ms": _now_ms(),
        "stage": stage,
        "result": result,
        "iter": iter,
        "step": step,
    }
    if extra:
        for k, v in extra.items():
            entry[k] = v

    DEBUG_LOGS.append(entry)
    if len(DEBUG_LOGS) > max_logs:
        overflow = len(DEBUG_LOGS) - max_logs
        if overflow > 0:
            del DEBUG_LOGS[0:overflow]


def clear_all_replies():
    MESSAGE_STORE.clear()


def clear_invalid_callback_cache():
    INVALID_CALLBACK_MIDS.clear()
    RECENT_USED_CALLBACK_ACTIONS.clear()


def _cleanup_cache_by_ttl(cache: Dict[str, float], ttl_seconds: float):
    now = _now_s()
    dead: List[str] = []
    for k, ts in cache.items():
        try:
            if now - float(ts) > float(ttl_seconds):
                dead.append(k)
        except Exception:
            dead.append(k)
    for k in dead:
        try:
            del cache[k]
        except Exception:
            pass


def _cleanup_invalid_callback_cache():
    _cleanup_cache_by_ttl(INVALID_CALLBACK_MIDS, INVALID_CALLBACK_TTL_SECONDS)


def _cleanup_recent_used_callback_cache():
    _cleanup_cache_by_ttl(RECENT_USED_CALLBACK_ACTIONS, RECENT_USED_CALLBACK_TTL_SECONDS)


def _invalid_key(bot_username: str, chat_id: int, message_id: int) -> str:
    return f"{bot_username}|{chat_id}|{message_id}"


def _action_key(bot_username: str, chat_id: int, message_id: int, data_hex: str, marker: Optional[str] = None) -> str:
    base = f"{bot_username}|{chat_id}|{message_id}|{data_hex}"
    mk = str(marker or "").strip()
    if not mk:
        return base
    return f"{base}|{mk}"


def mark_invalid_callback(bot_username: str, chat_id: int, message_id: int):
    _cleanup_invalid_callback_cache()
    k = _invalid_key(bot_username, chat_id, message_id)
    INVALID_CALLBACK_MIDS[k] = _now_s()


def is_invalid_callback(bot_username: str, chat_id: int, message_id: int) -> bool:
    _cleanup_invalid_callback_cache()
    k = _invalid_key(bot_username, chat_id, message_id)
    return k in INVALID_CALLBACK_MIDS


def _mark_callback_message_invalid(message_id: Any):
    try:
        mid = int(message_id or 0)
    except Exception:
        return

    if mid <= 0:
        return

    for msg in MESSAGE_STORE.values():
        try:
            if int(msg.get("message_id", 0) or 0) != mid:
                continue

            bot_username = str(msg.get("bot_username") or msg.get("sender_username") or "").strip()
            chat_id = int(msg.get("chat_id", 0) or 0)

            if bot_username and chat_id:
                mark_invalid_callback(bot_username, chat_id, mid)
            return
        except Exception:
            continue


def mark_recent_used_callback_action(bot_username: str, chat_id: int, message_id: int, data_hex: str, marker: Optional[str] = None):
    _cleanup_recent_used_callback_cache()
    k = _action_key(bot_username, chat_id, message_id, data_hex, marker=marker)
    RECENT_USED_CALLBACK_ACTIONS[k] = _now_s()


def unmark_recent_used_callback_action(bot_username: str, chat_id: int, message_id: int, data_hex: str, marker: Optional[str] = None):
    _cleanup_recent_used_callback_cache()
    k = _action_key(bot_username, chat_id, message_id, data_hex, marker=marker)
    try:
        if k in RECENT_USED_CALLBACK_ACTIONS:
            del RECENT_USED_CALLBACK_ACTIONS[k]
    except Exception:
        pass


def is_recent_used_callback_action(bot_username: str, chat_id: int, message_id: int, data_hex: str, marker: Optional[str] = None) -> bool:
    _cleanup_recent_used_callback_cache()
    k = _action_key(bot_username, chat_id, message_id, data_hex, marker=marker)
    return k in RECENT_USED_CALLBACK_ACTIONS


def get_all_messages_sorted() -> List[Dict[str, Any]]:
    msgs = list(MESSAGE_STORE.values())
    msgs.sort(key=lambda x: (int(x.get("chat_id", 0)), int(x.get("message_id", 0))))
    return msgs


def get_last_messages(limit: int) -> List[Dict[str, Any]]:
    msgs = get_all_messages_sorted()
    return msgs[-limit:]


def _summarize_reply_message(msg: Dict[str, Any]) -> Dict[str, Any]:
    buttons = []
    for btn in get_callback_buttons(msg):
        buttons.append({
            "text": str(btn.get("text") or ""),
        })

    return {
        "bot_username": msg.get("bot_username"),
        "message_id": int(msg.get("message_id", 0) or 0),
        "reply_to_message_id": int(msg.get("reply_to_message_id", 0) or 0),
        "text": str(msg.get("text") or ""),
        "buttons": buttons,
    }


@app.on_event("startup")
async def startup():
    await client.start()
    _patch_client_send_message_for_page_nav()
    push_log(stage="startup", result="ok", extra={})


def _json_sanitize(obj: Any) -> Any:
    if obj is None:
        return None

    if isinstance(obj, (str, int, float, bool)):
        return obj

    if isinstance(obj, (datetime, date)):
        try:
            return obj.isoformat()
        except Exception:
            return str(obj)

    if isinstance(obj, bytes):
        try:
            return obj.hex()
        except Exception:
            return str(obj)

    if isinstance(obj, dict):
        out: Dict[str, Any] = {}
        for k, v in obj.items():
            out[str(k)] = _json_sanitize(v)
        return out

    if isinstance(obj, (list, tuple, set)):
        return [_json_sanitize(x) for x in obj]

    try:
        if hasattr(obj, "to_dict"):
            return _json_sanitize(obj.to_dict())
    except Exception:
        pass

    try:
        return str(obj)
    except Exception:
        return repr(obj)

def _patch_client_send_message_for_page_nav() -> None:
    """
    修正「多按一次 1」：程式內若送出純文字 '1' 給 bot，改為嘗試點擊頁碼按鈕 1，
    避免 bot 回傳「💔抱歉，未找到可解析内容。」也避免頁面狀態被污染。
    """
    if getattr(client, "_page_nav_send_message_patched", False):
        return

    original_send_message = client.send_message

    async def patched_send_message(entity, message, *args, **kwargs):
        try:
            if isinstance(entity, str) and isinstance(message, str):
                bot_username = entity.strip()
                text = message.strip()

                # 只攔截送給 bot 的純文字 '1'
                if text == "1" and bot_username:
                    lower_name = bot_username.lower()
                    if lower_name.endswith("bot") or "bot" in lower_name:
                        try:
                            ok = await _normalize_to_first_page_if_possible(
                                bot_username=bot_username,
                                max_logs=2000,
                                step=-999,
                                normalize_prev_command="",
                                normalize_max_prev_steps=0,
                                next_keywords=["下一頁", "下一页", "下頁", "下页", "Next", "next", "➡", "▶"],
                            )
                            push_log(
                                stage="send_message_patch",
                                result="intercept_1_to_click" if ok else "intercept_1_noop",
                                step=-999,
                                extra={"bot_username": bot_username}
                            )
                        except Exception as e:
                            push_log(
                                stage="send_message_patch",
                                result="intercept_1_error",
                                step=-999,
                                extra={"bot_username": bot_username, "error": str(e)}
                            )

                        # 不把 '1' 真的送出去（避免 bot 回錯誤）
                        return None
        except Exception:
            # 任何 patch 內部錯誤都不影響原始送訊息
            pass

        return await original_send_message(entity, message, *args, **kwargs)

    client.send_message = patched_send_message
    client._page_nav_send_message_patched = True

def _safe_message_payload(message: Message) -> Dict[str, Any]:
    try:
        raw = message.to_dict()
        return _json_sanitize(raw) or {}
    except Exception as e:
        return {"_error": "to_dict_failed", "_error_message": str(e)}


def _infer_file_type(message: Message) -> str:
    try:
        if getattr(message, "photo", None) is not None:
            return "photo"
        if getattr(message, "video", None) is not None:
            return "video"
        if getattr(message, "document", None) is not None:
            return "document"
    except Exception:
        pass
    return "other"


def _extract_file_meta_mtproto(message: Message) -> Optional[Dict[str, Any]]:
    if not getattr(message, "media", None):
        return None

    file_type = _infer_file_type(message)

    file_name = None
    mime_type = None
    file_size = 0

    try:
        if getattr(message, "file", None) is not None:
            file_name = getattr(message.file, "name", None)
            mime_type = getattr(message.file, "mime_type", None)
            size_val = getattr(message.file, "size", None)
            if size_val is not None:
                try:
                    file_size = int(size_val)
                except Exception:
                    file_size = 0
    except Exception:
        pass

    file_id = None
    file_unique_id = None

    mtproto_doc_id = None
    mtproto_access_hash = None
    mtproto_file_reference = None

    try:
        doc = getattr(message, "document", None)
        if doc is not None:
            mtproto_doc_id = getattr(doc, "id", None)
            mtproto_access_hash = getattr(doc, "access_hash", None)
            mtproto_file_reference = getattr(doc, "file_reference", None)

            if mtproto_doc_id is not None:
                file_unique_id = str(mtproto_doc_id)
                if mtproto_access_hash is not None:
                    file_id = f"{mtproto_doc_id}:{mtproto_access_hash}"
                else:
                    file_id = str(mtproto_doc_id)

            if mime_type is None:
                mime_type = getattr(doc, "mime_type", None)

            if file_size == 0:
                size_val = getattr(doc, "size", None)
                if size_val is not None:
                    try:
                        file_size = int(size_val)
                    except Exception:
                        file_size = 0

            if file_name is None:
                try:
                    attrs = getattr(doc, "attributes", None) or []
                    for a in attrs:
                        name_val = getattr(a, "file_name", None)
                        if name_val:
                            file_name = name_val
                            break
                except Exception:
                    pass
    except Exception:
        pass

    try:
        if file_unique_id is None and getattr(message, "photo", None) is not None:
            photo = getattr(message, "photo", None)
            pid = getattr(photo, "id", None)
            access_hash = getattr(photo, "access_hash", None)
            mtproto_doc_id = pid
            mtproto_access_hash = access_hash
            try:
                mtproto_file_reference = getattr(photo, "file_reference", None)
            except Exception:
                mtproto_file_reference = None

            if pid is not None:
                file_unique_id = str(pid)
                if access_hash is not None:
                    file_id = f"{pid}:{access_hash}"
                else:
                    file_id = str(pid)
    except Exception:
        pass

    if file_unique_id is None:
        file_unique_id = str(uuid.uuid4())
    if file_id is None:
        file_id = file_unique_id

    raw_payload = _safe_message_payload(message)

    return {
        "session_id": None,
        "chat_id": int(message.chat_id or 0),
        "message_id": int(message.id or 0),
        "file_id": file_id,
        "file_unique_id": file_unique_id,
        "file_name": file_name,
        "mime_type": mime_type,
        "file_size": int(file_size or 0),
        "file_type": file_type,
        "file_id_scheme": "mtproto",
        "mtproto_doc_id": str(mtproto_doc_id) if mtproto_doc_id is not None else None,
        "mtproto_access_hash": str(mtproto_access_hash) if mtproto_access_hash is not None else None,
        "mtproto_file_reference": mtproto_file_reference.hex() if isinstance(mtproto_file_reference, (bytes, bytearray)) else _json_sanitize(mtproto_file_reference),
        "raw_payload": raw_payload,
    }


async def save_message(message: Message, forced_sender_username: Optional[str] = None, is_edited: bool = False):
    sender = await message.get_sender()

    sender_username = None
    is_bot = False
    if sender:
        sender_username = sender.username
        is_bot = bool(getattr(sender, "bot", False))

    if forced_sender_username:
        sender_username = forced_sender_username

    file_meta = _extract_file_meta_mtproto(message)

    data = {
        "id": str(uuid.uuid4()),
        "message_id": message.id,
        "chat_id": message.chat_id,
        "bot_username": forced_sender_username or sender_username,
        "sender_username": sender_username,
        "is_bot": is_bot,
        "date": message.date.isoformat() if message.date else None,
        "text": message.text,
        "buttons": [],
        "is_edited": is_edited,
        "saved_at_ms": _now_ms(),
        "reply_to_message_id": int(
            getattr(getattr(message, "reply_to", None), "reply_to_msg_id", 0) or 0
        ) if getattr(message, "reply_to", None) else None,
        "file": file_meta,
        "raw_payload": file_meta.get("raw_payload") if file_meta else _safe_message_payload(message),
    }

    if message.buttons:
        for row in message.buttons:
            for btn in row:
                btn_data_hex = None
                if getattr(btn, "data", None):
                    try:
                        btn_data_hex = btn.data.hex()
                    except Exception:
                        btn_data_hex = None

                data["buttons"].append({
                    "text": getattr(btn, "text", ""),
                    "data": btn_data_hex,
                    "url": getattr(btn, "url", None),
                })

    key = (int(message.chat_id or 0), int(message.id or 0))
    MESSAGE_STORE[key] = data


def _normalize_bot_username(value: Any) -> str:
    return str(value or "").strip().lower()


def _message_matches_bot(msg: Optional[Dict[str, Any]], bot_username: str) -> bool:
    if not msg:
        return False

    target = _normalize_bot_username(bot_username)
    if not target:
        return False

    source = _normalize_bot_username(msg.get("bot_username") or msg.get("sender_username"))
    return source == target


@client.on(events.NewMessage)
async def on_new_message(event):
    if not event.is_private:
        return
    if event.out:
        return
    await save_message(event.message)


@client.on(events.MessageEdited)
async def on_message_edited(event):
    if not event.is_private:
        return
    if event.out:
        return
    await save_message(event.message, is_edited=True)


def strip_markdown_stars(s: str) -> str:
    return (s or "").replace("**", "")


def extract_page_info(text: str) -> Optional[Dict[str, int]]:
    """
    從文字抓頁碼資訊：{"current_page": x, "total_pages": y}
    修正重點：
    - 支援全形斜線 "／"
    - 優先匹配真正的 "10/33 頁" 這種頁碼片段
    - 避免把 "100/330"（筆數/進度）誤判為頁碼
    """
    if not text:
        return None

    s = strip_markdown_stars(str(text))
    s = _normalize_emoji_digits(s)

    # 全形斜線統一
    s = s.replace("／", "/")

    # 1) 最優先：帶「頁/页」的分數格式（可有/無「第」）
    m = re.search(r"(?:第\s*)?(\d{1,4})\s*/\s*(\d{1,4})\s*(?:页|頁)\b", s)
    if m:
        cur = int(m.group(1))
        total = int(m.group(2))
        if 1 <= cur <= total <= 9999:
            return {"current_page": cur, "total_pages": total}

    # 2) 英文：Page x/y 或 Page x of y
    lower = s.lower()
    m2 = re.search(r"\bpage\s*(\d{1,4})\s*(?:/|of)\s*(\d{1,4})\b", lower)
    if m2:
        cur = int(m2.group(1))
        total = int(m2.group(2))
        if 1 <= cur <= total <= 9999:
            return {"current_page": cur, "total_pages": total}

    # 3) 括號形式 (10/33) [10/33]，後面可選擇性跟「頁/页/page」
    m3 = re.search(r"[\(\[【]\s*(\d{1,4})\s*/\s*(\d{1,4})\s*[\)\]】]\s*(?:页|頁|page)?", lower)
    if m3:
        cur = int(m3.group(1))
        total = int(m3.group(2))
        if 1 <= cur <= total <= 9999:
            return {"current_page": cur, "total_pages": total}

    # 4) 最後 fallback：純 x/y
    # 為避免 "当前 100/330 个，第 10/33 页" 誤抓到第一個 100/330，
    # 這裡改成「抓最後一個 x/y」
    all_frac = list(re.finditer(r"(\d{1,4})\s*/\s*(\d{1,4})", lower))
    if all_frac:
        last = all_frac[-1]
        cur = int(last.group(1))
        total = int(last.group(2))
        if 1 <= cur <= total <= 9999:
            return {"current_page": cur, "total_pages": total}

    return None

def extract_total_items(text: Optional[str]) -> Optional[int]:
    if not text:
        return None

    s = strip_markdown_stars(text)

    patterns = [
        r"(?:共找到|找到|共計|共计|總共|总共)\s*(\d{1,6})\s*(?:个|個|項|条|條|份|媒体|媒體|文件|檔案|视频|影片|照片|图片|圖片)?",
        r"(?:found|Found|FOUND)\s*(\d{1,6})",
        r"(?:total|Total|TOTAL)\s*[:：]?\s*(\d{1,6})",
    ]
    for pat in patterns:
        m = re.search(pat, s)
        if m:
            try:
                v = int(m.group(1))
                if v > 0:
                    return v
            except Exception:
                pass

    s2 = re.sub(r"第\s*\d{1,3}\s*/\s*\d{1,3}\s*[页頁]", " ", s)
    s2 = re.sub(r"(?:page|Page|PAGE)\s*\d{1,3}\s*/\s*\d{1,3}", " ", s2)

    m = re.search(r"(\d{1,6})\s*/\s*(\d{1,6})", s2)
    if m:
        try:
            total = int(m.group(2))
            if total > 0:
                return total
        except Exception:
            pass

    m2 = re.search(r"(?:共|總共|一共)\s*(\d{1,6})\s*(?:个|個|項|条|條|份)", s)
    if m2:
        try:
            total = int(m2.group(1))
            if total > 0:
                return total
        except Exception:
            pass

    return None


def extract_first_int(text: str) -> Optional[int]:
    if text is None:
        return None
    m = re.search(r"(\d+)", str(text))
    if not m:
        return None
    try:
        return int(m.group(1))
    except Exception:
        return None


def get_callback_buttons(msg: Any) -> List[Dict[str, Any]]:
    """
    允許 msg 為：
    1) dict：{ "buttons": [...] }
    2) list：直接就是 buttons 或 rows
    3) rows：[[{btn},{btn}], [{btn}]]
    回傳扁平化後、且含 data 的 button dict list
    """
    if msg is None:
        return []

    raw: Any = None

    if isinstance(msg, dict):
        raw = msg.get("buttons", []) or []
    elif isinstance(msg, list):
        raw = msg
    else:
        return []

    buttons: List[Dict[str, Any]] = []

    def _push_btn(b: Any):
        if not isinstance(b, dict):
            return
        data_val = b.get("data")
        if data_val:
            buttons.append(b)

    if isinstance(raw, list):
        for item in raw:
            if isinstance(item, dict):
                _push_btn(item)
            elif isinstance(item, list):
                for b in item:
                    _push_btn(b)

    return buttons

def summarize_buttons(buttons: List[Dict[str, Any]]) -> List[str]:
    return [((b.get("text") or "").strip()) for b in buttons]


def callback_fingerprint(msg: Dict[str, Any]) -> str:
    text = (msg.get("text") or "")[:400]
    btns = "|".join(summarize_buttons(get_callback_buttons(msg)))
    chat_id = int(msg.get("chat_id", 0) or 0)
    mid = int(msg.get("message_id", 0) or 0)
    return f"c={chat_id}|m={mid}|t={text}||b={btns}"

def _normalize_button_text(s: Optional[str]) -> str:
    return (str(s or "")).strip().lower()


def _button_text_contains_keywords(text: Optional[str], keywords: List[str]) -> bool:
    normalized_text = _normalize_button_text(text)
    if not normalized_text:
        return False

    for kw in (keywords or []):
        normalized_kw = _normalize_button_text(kw)
        if normalized_kw and normalized_kw in normalized_text:
            return True

    return False

def _extract_first_int_from_text(s: Optional[str]) -> Optional[int]:
    if not s:
        return None
    m = re.search(r"(\d+)", str(s))
    if not m:
        return None
    try:
        return int(m.group(1))
    except Exception:
        return None

def _has_next_by_buttons(buttons: List[Dict[str, Any]], next_keywords: List[str]) -> bool:
    if not buttons:
        return False

    kws = [_normalize_button_text(k) for k in (next_keywords or []) if str(k).strip()]
    for b in buttons:
        t = _normalize_button_text(b.get("text") or "")
        for kw in kws:
            if kw and kw in t:
                return True
    return False

def _max_numeric_page_from_buttons(buttons: List[Dict[str, Any]]) -> Optional[int]:
    if not buttons:
        return None
    nums: List[int] = []
    for b in buttons:
        n = _extract_page_button_number((b.get("text") or "").strip())
        if n is not None:
            nums.append(int(n))
    if not nums:
        return None
    return max(nums)

def _is_last_page_now(pi: Optional[Dict[str, int]], buttons: List[Dict[str, Any]], next_keywords: List[str]) -> bool:
    """
    最後一頁判斷（針對你這種 bot 點「下一頁」流程）：
    1) 若文字有 current/total，且 current >= total -> last
    2) 若沒有 next 按鈕：
       - 若 pi 不存在：為避免最後一頁還去亂點數字鍵，視為 last
       - 若 pi 存在且 current >= total -> last
       - 若 pi 存在但 current < total：保守視為「可能還能翻」，不直接停（避免某些 bot 不提供 next 只給數字）
    3) 若有 next 按鈕：不是 last
    """
    if pi and pi.get("current_page") is not None and pi.get("total_pages") is not None:
        try:
            cur = int(pi["current_page"])
            total = int(pi["total_pages"])
            if total > 0 and cur >= total:
                return True
        except Exception:
            pass

    has_next = _has_next_by_buttons(buttons, next_keywords)
    if has_next:
        return False

    if not pi:
        return True

    if pi.get("current_page") is not None and pi.get("total_pages") is not None:
        try:
            cur = int(pi["current_page"])
            total = int(pi["total_pages"])
            if total > 0 and cur >= total:
                return True
            return False
        except Exception:
            return True

    max_btn_page = _max_numeric_page_from_buttons(buttons)
    if max_btn_page is not None and pi.get("current_page") is not None:
        try:
            return int(pi["current_page"]) >= int(max_btn_page)
        except Exception:
            return True

    return True


def _pagination_confirmed_last_page(state: Dict[str, Any]) -> bool:
    """
    覆蓋你的 last-page 判斷：把「按鈕是否還有 next」也納入。
    這能直接阻止你在最後一頁進到 _safe_click_pagination_button() 去點 45/44/43/42。
    """
    pi = state.get("page_info")
    buttons = state.get("buttons") or []
    next_keywords = state.get("next_keywords") or []
    return _is_last_page_now(pi, buttons, next_keywords)

def _extract_page_button_number(text: str) -> Optional[int]:
    """
    解析一般頁碼按鈕（非 next/prev）上的數字。
    修正重點：支援 🔟 / 10️⃣ / ⑩ 等 emoji 數字
    """
    if not text:
        return None

    t = _normalize_emoji_digits(str(text)).strip()
    t = t.replace("\ufe0f", "")

    # 避免把 "10/33" 這種整段頁碼資訊誤當成按鈕頁碼
    if "/" in t:
        return None

    # 含字母或中文描述的通常不是頁碼按鈕
    if re.search(r"[A-Za-z]", t):
        return None
    if re.search(r"[第页頁]", t):
        return None

    # 直接是數字
    if re.fullmatch(r"\d{1,4}", t):
        return int(t)

    # 常見前綴符號：✅ ✳ ★ 等，抓第一段數字
    m = re.search(r"(\d{1,4})", t)
    if m:
        return int(m.group(1))

    return None

def get_numeric_button_set(buttons: List[Dict[str, Any]]) -> List[int]:
    nums: List[int] = []
    for b in buttons:
        n = _extract_page_button_number((b.get("text") or "").strip())
        if n is not None:
            nums.append(int(n))
    return sorted(set(nums))


def _pagination_action_marker_from_message(callback_msg: Dict[str, Any], buttons: Optional[List[Dict[str, Any]]] = None) -> str:
    if not callback_msg:
        return ""

    if buttons is None:
        try:
            buttons = get_callback_buttons(callback_msg)
        except Exception:
            buttons = []

    pi = None
    try:
        pi = extract_page_info(callback_msg.get("text"))
    except Exception:
        pi = None

    highlighted = None
    try:
        highlighted = detect_current_page_from_buttons(buttons) if buttons else None
    except Exception:
        highlighted = None

    cur = None
    total = None

    if highlighted is not None:
        cur = highlighted

    if cur is None and pi and pi.get("current_page") is not None:
        cur = pi.get("current_page")

    if pi and pi.get("total_pages") is not None:
        total = pi.get("total_pages")

    # 若抓不到頁碼，改用訊息 fingerprint 產生 marker（避免 next-only bot data 固定導致被 recent_used 擋住）
    if cur is None:
        try:
            import hashlib as _hashlib
            fp = callback_fingerprint(callback_msg)
            h = _hashlib.sha1(fp.encode("utf-8", errors="ignore")).hexdigest()[:12]
            return f"fp={h}"
        except Exception:
            return "fp=unknown"

    cur_i = None
    try:
        cur_i = int(cur)
    except Exception:
        cur_i = None

    total_i = None
    if total is not None:
        try:
            total_i = int(total)
        except Exception:
            total_i = None

    if cur_i is not None and total_i is not None:
        return f"p={cur_i}/{total_i}"

    if cur_i is not None:
        return f"p={cur_i}"

    return f"p={cur}"


def _pick_next_page_button(callback_msg: Dict[str, Any], next_keywords: List[str]) -> Tuple[Optional[Dict[str, Any]], str]:
    """
    分頁按鈕選擇邏輯（保持原有「頁碼 + 1」行為不變，並額外支援只有「下一頁」按鈕的 bot）：

    1) 若存在可解析的頁碼按鈕：優先按「目前頁碼 + 1」
    2) 否則（或找不到 +1 按鈕）：嘗試按「下一頁/Next/➡️」等 next 按鈕
    """
    if not callback_msg:
        return None, "no_callback_msg"

    buttons = get_callback_buttons(callback_msg)
    if not buttons:
        return None, "no_buttons"

    pi = extract_page_info(callback_msg.get("text"))
    highlighted = None
    try:
        highlighted = detect_current_page_from_buttons(buttons)
    except Exception:
        highlighted = None

    numeric_pages = get_numeric_button_set(buttons)

    # A) 有頁碼按鈕：維持原本「目前頁碼 + 1」優先
    if numeric_pages:
        cur_guess: Optional[int] = None

        if highlighted is not None:
            try:
                cur_guess = int(highlighted)
            except Exception:
                cur_guess = None

        if cur_guess is None and pi and pi.get("current_page") is not None:
            try:
                cur_guess = int(pi.get("current_page"))
            except Exception:
                cur_guess = None

        if cur_guess is None:
            return None, "cannot_detect_current_page"

        # 若文字頁碼資訊可判斷已到最後一頁，直接停
        if pi and pi.get("total_pages") is not None:
            try:
                total_pages = int(pi.get("total_pages"))
                if cur_guess >= total_pages:
                    return None, "already_last_page"
                want = cur_guess + 1
                if want > total_pages:
                    return None, "already_last_page"
            except Exception:
                want = cur_guess + 1
        else:
            want = cur_guess + 1

        btn_plus_one = pick_button_by_page_number(callback_msg, want)
        if btn_plus_one:
            return btn_plus_one, "numeric_plus_one"

        # 找不到 +1 的頁碼按鈕時，再嘗試 next 按鈕（不影響原本可正常按頁碼的 bot）
        # 例如頁碼按鈕是區間滾動，下一頁不一定在可見頁碼集合內

    # B) next 按鈕（下一頁/Next/➡️...）
    effective_kws: List[str] = []
    for kw in (next_keywords or []):
        kw2 = str(kw or "").strip()
        if not kw2:
            continue
        if kw2 not in effective_kws:
            effective_kws.append(kw2)

    best_btn = None
    best_kw_idx = 10 ** 9
    best_btn_idx = 10 ** 9

    for bi, b in enumerate(buttons):
        t = _normalize_button_text(b.get("text") or "")
        if not t:
            continue

        for ki, kw in enumerate(effective_kws):
            kw_norm = _normalize_button_text(kw)
            if not kw_norm:
                continue
            if kw_norm in t:
                if ki < best_kw_idx or (ki == best_kw_idx and bi < best_btn_idx):
                    best_btn = b
                    best_kw_idx = ki
                    best_btn_idx = bi
                break

    if best_btn:
        return best_btn, "next_keyword"

    if numeric_pages:
        return None, "no_next_page_number_button"

    return None, "no_next_button"


def _pagination_confirmed_all_pages_visited(state: Optional[Dict[str, Any]], visited_pages: Any) -> bool:
    if not state:
        return False

    pi = state.get("page_info") or {}
    total = pi.get("total_pages")
    cur = pi.get("current_page")

    if total is None:
        return False

    try:
        total_i = int(total)
    except Exception:
        return False

    if total_i <= 0:
        return False

    visited: Set[int] = set()
    if isinstance(visited_pages, set):
        for x in visited_pages:
            try:
                visited.add(int(x))
            except Exception:
                pass
    elif isinstance(visited_pages, list) or isinstance(visited_pages, tuple):
        for x in visited_pages:
            try:
                visited.add(int(x))
            except Exception:
                pass

    if cur is not None:
        try:
            visited.add(int(cur))
        except Exception:
            pass

    if len(visited) < total_i:
        return False

    for i in range(1, total_i + 1):
        if i not in visited:
            return False

    return True


def _pagination_confirmed_by_last_clicked_target(state: Optional[Dict[str, Any]], last_clicked_page: Optional[int]) -> bool:
    if not state or last_clicked_page is None:
        return False

    pi = state.get("page_info") or {}
    total = pi.get("total_pages")
    if total is None:
        return False

    try:
        total_i = int(total)
        clicked_i = int(last_clicked_page)
        return total_i > 0 and clicked_i >= total_i
    except Exception:
        return False

def detect_current_page_from_buttons(buttons: List[Dict[str, Any]]) -> Optional[int]:
    """
    判斷「目前頁碼」的規則（避免頁碼區間換頁時誤判）：
    1) 優先只認「明確高亮」的頁碼：✳ / ⭐ / 🌟
    2) 如果所有可解析的頁碼只有 1 個，直接回傳（避免卡住）
    3) ✅ / ☑ 這類「可點頁碼」在同時出現多個時，不視為目前頁（避免把 ✅17 當成目前頁）
       （若只有 1 個 ✅/☑ 頁碼，且同時存在其他純數字頁碼作對照，才允許當作目前頁）
    4) 其他情況回傳 None，交由文字 page_info（例如 "第16/25頁"）或外部狀態推斷
    """
    if not buttons:
        return None

    strong: List[Tuple[int, int, int]] = []  # (score, idx, page)
    plain: List[Tuple[int, int]] = []        # (idx, page)
    check: List[Tuple[int, int, int]] = []   # (score, idx, page)
    all_nums: List[int] = []

    for idx, b in enumerate(buttons or []):
        t = (b.get("text") or "").strip()
        n = _extract_strict_current_page_candidate(t)
        if n is None:
            continue

        page = int(n)
        all_nums.append(page)

        s = str(t or "")
        s2 = re.sub(r"\s+", "", s)
        s2 = s2.replace("\ufe0f", "").replace("\ufe0e", "").replace("\u200d", "")

        has_strong = ("✳" in s) or ("⭐" in s) or ("🌟" in s)
        is_plain_digit = bool(re.fullmatch(r"\d{1,4}", s2))
        has_check = ("✅" in s) or ("☑" in s)

        if has_strong:
            score = _button_current_marker_score(s)
            strong.append((score, idx, page))
            continue

        if is_plain_digit:
            plain.append((idx, page))
            continue

        if has_check:
            score = _button_current_marker_score(s)
            check.append((score, idx, page))
            continue

    if not all_nums:
        return None

    unique_all = sorted(list(set(all_nums)))
    if len(unique_all) == 1:
        return unique_all[0]

    if strong:
        strong.sort(key=lambda x: (x[0], -x[1]), reverse=True)
        return strong[0][2]

    unique_plain = sorted(list({p for _, p in plain}))
    if len(unique_plain) == 1:
        return unique_plain[0]

    if len(check) == 1 and len(plain) >= 1:
        return check[0][2]

    return None


def _has_next_like_button(callback_msg: Dict[str, Any], next_keywords: Optional[List[str]] = None) -> bool:
    if not callback_msg:
        return False

    buttons = get_callback_buttons(callback_msg)
    if not buttons:
        return False

    kws = list(next_keywords or [])
    kws.extend([
        "下一頁", "下页", "next", "Next", ">", "»", "›",
        "▶", "►", "⏩", ">>", "»»", "→", "➡", "⏭", "⏭️",
        "更多", "more"
    ])

    seen = set()
    effective_kws: List[str] = []
    for kw in kws:
        kw2 = str(kw or "").strip()
        if not kw2:
            continue
        if kw2 in seen:
            continue
        seen.add(kw2)
        effective_kws.append(kw2)

    for b in buttons:
        t_raw = (b.get("text") or "").strip()
        t = _normalize_button_text(t_raw)
        for kw in effective_kws:
            kw_norm = _normalize_button_text(kw)
            if kw_norm and kw_norm in t:
                return True

    return False



def _is_pagination_callback_message(callback_msg: Dict[str, Any], next_keywords: Optional[List[str]] = None) -> bool:
    if not callback_msg:
        return False
    buttons = get_callback_buttons(callback_msg)
    if not buttons:
        return False

    if extract_page_info(callback_msg.get("text")) is not None:
        return True

    numeric_pages = get_numeric_button_set(buttons)
    if len(numeric_pages) >= 2:
        return True

    if _has_next_like_button(callback_msg, next_keywords=next_keywords):
        return True

    return False


def _is_meaningful_state_message_for_pagination(bot_username: str, msg: Optional[Dict[str, Any]]) -> bool:
    if not msg:
        return False
    if not _message_matches_bot(msg, bot_username):
        return False

    buttons = get_callback_buttons(msg)
    if buttons:
        return True

    if msg.get("file"):
        return True

    t = (msg.get("text") or "").strip()
    if t:
        if _match_bot_verification_success_keyword(t) is not None:
            return False
        if extract_page_info(t) is not None:
            return True
        if extract_total_items(t) is not None:
            return True
        return True

    return False


def _is_bot_completion_message(msg: Optional[Dict[str, Any]]) -> bool:
    if not msg:
        return False

    if get_callback_buttons(msg):
        return False

    text = str(msg.get("text") or "").strip()
    if not text:
        return False

    if extract_page_info(text) is not None:
        return False

    normalized = text.lower()
    completion_keywords = [
        "發送完成",
        "发送完成",
        "已發送完畢",
        "已发送完毕",
        "已發送完成",
        "已发送完成",
        "發送完畢",
        "发送完毕",
        "發送完了",
        "发送完了",
        "全部發送完成",
        "全部发送完成",
        "以下代碼的內容發送完成",
        "以下代码的内容发送完成",
        "所有資源已加載完畢",
        "所有资源已加载完毕",
        "所有資源已加載完成",
        "所有资源已加载完成",
        "完成",
    ]

    for kw in completion_keywords:
        if kw and kw.lower() in normalized:
            return True

    return False


BOT_VERIFICATION_SUCCESS_KEYWORDS = [
    "验证码验证成功",
    "驗證碼驗證成功",
    "captcha verified successfully",
]


def _match_bot_verification_success_keyword(text: str) -> Optional[str]:
    normalized = str(text or "").strip().lower()
    if not normalized:
        return None

    for kw in BOT_VERIFICATION_SUCCESS_KEYWORDS:
        kw2 = str(kw or "").strip()
        if not kw2:
            continue
        if kw2.lower() in normalized:
            return kw2

    return None


def _is_bot_verification_success_message(msg: Optional[Dict[str, Any]]) -> bool:
    if not msg:
        return False

    if get_callback_buttons(msg):
        return False

    text = str(msg.get("text") or "").strip()
    if not text:
        return False

    return _match_bot_verification_success_keyword(text) is not None


BOT_NOT_FOUND_KEYWORDS = [
    "💔抱歉，未找到可解析内容。",
    "抱歉，未找到可解析内容。",
    "抱歉，未找到可解析内容",
    "未找到可解析内容。已加入缓存列表，稍后进行请求。",
    "未找到可解析内容",
    "已加入缓存列表，稍后进行请求。",
]


def _match_bot_not_found_keyword(text: str) -> Optional[str]:
    normalized = str(text or "").strip().lower()
    if not normalized:
        return None

    for kw in BOT_NOT_FOUND_KEYWORDS:
        kw2 = str(kw or "").strip()
        if not kw2:
            continue
        if kw2.lower() in normalized:
            return kw2

    return None


def _is_bot_not_found_message(msg: Optional[Dict[str, Any]]) -> bool:
    if not msg:
        return False

    if get_callback_buttons(msg):
        return False

    text = str(msg.get("text") or "").strip()
    if not text:
        return False

    if extract_page_info(text) is not None:
        return False

    return _match_bot_not_found_keyword(text) is not None


def find_latest_callback_message(bot_username: str, skip_invalid: bool = False) -> Optional[Dict[str, Any]]:
    latest = None
    for msg in MESSAGE_STORE.values():
        if not _message_matches_bot(msg, bot_username):
            continue
        if not get_callback_buttons(msg):
            continue

        if skip_invalid:
            chat_id = int(msg.get("chat_id", 0))
            mid = int(msg.get("message_id", 0))
            if is_invalid_callback(bot_username, chat_id, mid):
                continue

        if latest is None or int(msg.get("message_id", 0)) > int(latest.get("message_id", 0)):
            latest = msg
    return latest


def find_latest_pagination_callback_message(
    bot_username: str,
    next_keywords: Optional[List[str]] = None,
    skip_invalid: bool = False,
    max_age_seconds: int = 0,
    scan_limit: int = 0,
    min_message_id: int = 0
) -> Optional[Dict[str, Any]]:
    """
    修正：加入 max_age_seconds / scan_limit / min_message_id 的篩選能力（預設不影響舊呼叫）。
    這樣 safe_click / 選 state 時不會拿到過舊或不在本輪流程的 callback message，避免按錯上一頁或點不到第 2 頁。
    """
    now_ms = int(time.time() * 1000)
    msgs: List[Dict[str, Any]] = []

    for msg in MESSAGE_STORE.values():
        if not _message_matches_bot(msg, bot_username):
            continue

        mid = int(msg.get("message_id", 0) or 0)
        if int(min_message_id or 0) > 0 and mid < int(min_message_id):
            continue

        if not get_callback_buttons(msg):
            continue

        if skip_invalid:
            chat_id = int(msg.get("chat_id", 0) or 0)
            if is_invalid_callback(bot_username, chat_id, mid):
                continue

        if int(max_age_seconds or 0) > 0:
            saved_at_ms = int(msg.get("saved_at_ms", 0) or 0)
            if saved_at_ms > 0:
                age_s = (now_ms - saved_at_ms) / 1000.0
                if age_s > float(max_age_seconds):
                    continue

        msgs.append(msg)

    if not msgs:
        return None

    msgs.sort(key=lambda m: int(m.get("message_id", 0) or 0), reverse=True)

    limit = int(scan_limit or 0)
    if limit > 0:
        msgs = msgs[:limit]

    for msg in msgs:
        if _is_pagination_callback_message(msg, next_keywords=next_keywords):
            return msg

    return None


def find_latest_bot_message_any(bot_username: str, require_meaningful: bool = False) -> Optional[Dict[str, Any]]:
    latest = None
    for msg in MESSAGE_STORE.values():
        if not _message_matches_bot(msg, bot_username):
            continue

        if require_meaningful:
            if not _is_meaningful_state_message_for_pagination(bot_username, msg):
                continue

        if latest is None or int(msg.get("message_id", 0)) > int(latest.get("message_id", 0)):
            latest = msg
    return latest


def find_latest_bot_message_with_page_info(bot_username: str) -> Optional[Dict[str, Any]]:
    latest = None
    for msg in MESSAGE_STORE.values():
        if not _message_matches_bot(msg, bot_username):
            continue
        pi = extract_page_info(msg.get("text"))
        if not pi:
            continue
        if latest is None or int(msg.get("message_id", 0)) > int(latest.get("message_id", 0)):
            latest = msg
    return latest


def pick_button_by_page_number(callback_msg: Dict[str, Any], want_page: int) -> Optional[Dict[str, Any]]:
    """
    修正：避免用 extract_first_int 太寬鬆，導致把 ⬅️1 當作「頁碼 1」或誤抓到別的含數字按鈕。
    現在用 _extract_page_target_number，可點純數字或兩側帶箭頭的數字，但會排除 Prev/上一頁 這種含文字按鈕。
    """
    best_btn = None
    best_score = -10_000

    for b in get_callback_buttons(callback_msg):
        t = (b.get("text") or "").strip()
        n = _extract_page_target_number(t)
        if n is None:
            continue
        if int(n) != int(want_page):
            continue

        strict_n = _extract_strict_current_page_candidate(t)
        score = 0
        if strict_n is not None:
            score = score + 1000
        score = score + _button_current_marker_score(t)

        if score > best_score:
            best_score = score
            best_btn = b

    return best_btn

def _pagination_state_from_message(msg: Optional[Dict[str, Any]], next_keywords: Optional[List[str]] = None) -> Optional[Dict[str, Any]]:
    if not msg:
        return None

    buttons = get_callback_buttons(msg)
    buttons_text = summarize_buttons(buttons) if buttons else []
    pi = extract_page_info(msg.get("text"))

    effective_next_keywords: List[str] = []
    if next_keywords:
        for kw in next_keywords:
            if kw and kw not in effective_next_keywords:
                effective_next_keywords.append(kw)

    default_next_keywords = [
        "下一頁", "下页", "下一页",
        "Next", "next",
        ">", ">>", "»", "›", "»»",
        "▶", "►", "⏩", "→", "➡", "➡️",
        "⏭", "⏭️",
        "更多", "more", "More",
        "Forward", "forward"
    ]
    for kw in default_next_keywords:
        if kw not in effective_next_keywords:
            effective_next_keywords.append(kw)

    highlighted = detect_current_page_from_buttons(buttons) if buttons else None
    numeric_pages = get_numeric_button_set(buttons) if buttons else []

    has_next = False
    if buttons:
        has_next = _has_next_by_buttons(buttons, effective_next_keywords)
    elif pi and pi.get("current_page") is not None and pi.get("total_pages") is not None:
        try:
            cur = int(pi.get("current_page") or 0)
            total = int(pi.get("total_pages") or 0)
            has_next = total > 0 and cur < total
        except Exception:
            has_next = False

    is_pagination_like = False
    if buttons:
        is_pagination_like = _is_pagination_callback_message(msg, next_keywords=effective_next_keywords)
    elif pi:
        is_pagination_like = True

    return {
        "message_id": int(msg.get("message_id", 0)),
        "chat_id": int(msg.get("chat_id", 0)),
        "text": (msg.get("text") or ""),
        "text_preview": (msg.get("text") or "")[:260],
        "page_info": pi,
        "buttons": buttons,
        "has_buttons": bool(buttons),
        "buttons_text": buttons_text,
        "numeric_pages": numeric_pages,
        "highlighted_page": highlighted,
        "has_next": bool(has_next),
        "is_pagination_like": bool(is_pagination_like),
        "next_keywords": effective_next_keywords
    }

def _truncate_raw_payload_in_file(file_obj: Dict[str, Any], max_bytes: int) -> Dict[str, Any]:
    if max_bytes <= 0:
        return file_obj

    raw = file_obj.get("raw_payload")
    if raw is None:
        return file_obj

    try:
        s = str(raw)
        if len(s.encode("utf-8")) <= max_bytes:
            return file_obj

        prefix = s.encode("utf-8")[:max_bytes]
        try:
            prefix_str = prefix.decode("utf-8", errors="ignore")
        except Exception:
            prefix_str = str(prefix)

        copied = dict(file_obj)
        copied["raw_payload"] = {"_truncated": True, "_max_bytes": max_bytes, "_preview": prefix_str}
        return copied
    except Exception:
        return file_obj

def _normalize_emoji_digits(text: str) -> str:
    """
    將常見的 emoji 數字（例如 🔟、10️⃣、①、⑩ 等）轉為可解析的阿拉伯數字字串
    目的：pagination 按鈕/頁碼在 10 之後常變成 emoji，導致抓不到 current page
    """
    if not text:
        return ""

    s = str(text)

    # 10 emoji
    s = s.replace("🔟", "10")

    # Keycap / variation selector:
    # 例如 "1️⃣" = "1" + FE0F + 20E3；移除後就只剩 "1"
    s = s.replace("\ufe0f", "")
    s = s.replace("\u20e3", "")

    # Circled numbers & 常見裝飾數字（0-20 + 部分黑底圈字）
    circled_map = {
        "⓪": "0",
        "①": "1",
        "②": "2",
        "③": "3",
        "④": "4",
        "⑤": "5",
        "⑥": "6",
        "⑦": "7",
        "⑧": "8",
        "⑨": "9",
        "⑩": "10",
        "⑪": "11",
        "⑫": "12",
        "⑬": "13",
        "⑭": "14",
        "⑮": "15",
        "⑯": "16",
        "⑰": "17",
        "⑱": "18",
        "⑲": "19",
        "⑳": "20",
        "❶": "1",
        "❷": "2",
        "❸": "3",
        "❹": "4",
        "❺": "5",
        "❻": "6",
        "❼": "7",
        "❽": "8",
        "❾": "9",
        "❿": "10",
    }

    for k, v in circled_map.items():
        s = s.replace(k, v)

    return s

def _file_dedup_key(file_obj: Dict[str, Any]) -> str:
    fuid = file_obj.get("file_unique_id")
    if fuid:
        return f"u:{str(fuid)}"
    fid = file_obj.get("file_id")
    if fid:
        return f"i:{str(fid)}"
    return f"m:{file_obj.get('chat_id')}:{file_obj.get('message_id')}"

def collect_files_from_store(
    bot_username: str,
    max_return_files: int,
    max_raw_payload_bytes: int,
    min_message_id: int = 0
) -> Dict[str, Any]:
    msgs = get_all_messages_sorted()
    raw_files: List[Dict[str, Any]] = []

    for m in reversed(msgs):
        if not _message_matches_bot(m, bot_username):
            continue

        mid = int(m.get("message_id") or 0)
        if min_message_id and mid and mid < min_message_id:
            continue

        file_obj = m.get("file")
        if not file_obj:
            continue

        out = _truncate_raw_payload_in_file(dict(file_obj), max_raw_payload_bytes)
        raw_files.append(out)

        if len(raw_files) >= max_return_files:
            break

    seen: Dict[str, Dict[str, Any]] = {}
    for f in raw_files:
        k = _file_dedup_key(f)
        if k not in seen:
            seen[k] = f

    files_unique_new_to_old = list(seen.values())
    files_unique_new_to_old.reverse()
    raw_files.reverse()

    files_unique = files_unique_new_to_old
    files_raw_count = len(raw_files)
    files_unique_count = len(files_unique)
    files_truncated = files_raw_count >= max_return_files

    return {
        "files": files_unique,
        "files_count": files_unique_count,
        "files_unique_count": files_unique_count,
        "files_raw_count": files_raw_count,
        "files_truncated": files_truncated
    }


def _summarize_bot_message(msg: Optional[Dict[str, Any]]) -> Optional[Dict[str, Any]]:
    if not msg:
        return None

    text = str(msg.get("text") or "").strip()
    page_info = extract_page_info(text) if text else None
    total_items = extract_total_items(text) if text else None
    matched_not_found = _match_bot_not_found_keyword(text)

    kind = "other"
    if matched_not_found:
        kind = "not_found"
    elif page_info is not None or bool(get_callback_buttons(msg)):
        kind = "state"
    elif _is_bot_completion_message(msg):
        kind = "completion"

    return {
        "message_id": int(msg.get("message_id", 0) or 0),
        "chat_id": int(msg.get("chat_id", 0) or 0),
        "sender_username": msg.get("sender_username"),
        "kind": kind,
        "text_preview": text[:500],
        "matched_not_found_keyword": matched_not_found,
        "has_buttons": bool(get_callback_buttons(msg)),
        "page_info": page_info,
        "total_items": total_items,
    }


def summarize_latest_bot_message(bot_username: str, min_message_id: int = 0) -> Optional[Dict[str, Any]]:
    latest = None

    for msg in MESSAGE_STORE.values():
        if not _message_matches_bot(msg, bot_username):
            continue

        mid = int(msg.get("message_id", 0) or 0)
        if int(min_message_id or 0) > 0 and mid < int(min_message_id or 0):
            continue

        if latest is None or mid > int(latest.get("message_id", 0) or 0):
            latest = msg

    return _summarize_bot_message(latest)


def _attach_bot_result_snapshot(
    result: Dict[str, Any],
    bot_username: str,
    include_files: bool,
    max_return_files: int,
    max_raw_payload_bytes: int,
    min_message_id: int = 0
) -> Dict[str, Any]:
    files_unique_count = 0

    if include_files:
        files_meta = collect_files_from_store(
            bot_username,
            int(max_return_files),
            int(max_raw_payload_bytes),
            int(min_message_id or 0)
        )
        result["files"] = files_meta
        files_unique_count = int(files_meta.get("files_unique_count", files_meta.get("files_count", 0)) or 0)
        result["files_unique_count"] = files_unique_count

    latest_message = summarize_latest_bot_message(bot_username, min_message_id=int(min_message_id or 0))
    if latest_message is not None:
        result["latest_message"] = latest_message

    result["outcome"] = {
        "has_files": files_unique_count > 0,
        "files_unique_count": files_unique_count,
        "not_found_message_detected": bool(latest_message and latest_message.get("kind") == "not_found"),
        "latest_message_kind": latest_message.get("kind") if latest_message else None,
        "run_completed": str(result.get("status") or "").lower() == "ok",
    }
    result["completed"] = bool(result["outcome"]["run_completed"])

    return result


def _summarize_bot_message(msg: Optional[Dict[str, Any]]) -> Optional[Dict[str, Any]]:
    if not msg:
        return None

    text = str(msg.get("text") or "").strip()
    buttons = get_callback_buttons(msg)
    page_info = extract_page_info(text) if text else None
    total_items = extract_total_items(text) if text else None
    matched_not_found = _match_bot_not_found_keyword(text)

    kind = "other"
    if matched_not_found:
        kind = "not_found"
    elif page_info is not None or bool(buttons):
        kind = "state"
    elif _is_bot_completion_message(msg):
        kind = "completion"

    return {
        "message_id": int(msg.get("message_id", 0) or 0),
        "chat_id": int(msg.get("chat_id", 0) or 0),
        "sender_username": msg.get("sender_username"),
        "kind": kind,
        "text_preview": text[:500],
        "matched_not_found_keyword": matched_not_found,
        "has_buttons": bool(buttons),
        "buttons_text": summarize_buttons(buttons),
        "page_info": page_info,
        "total_items": total_items,
    }

async def _get_peer_for_bot(bot_username: str):
    key = str(bot_username or "").strip()
    if not key:
        return None
    if key in _PEER_CACHE:
        return _PEER_CACHE[key]
    if not await _ensure_client_connected():
        return None
    try:
        peer = await client.get_input_entity(key)
        _PEER_CACHE[key] = peer
        return peer
    except Exception as e:
        if _is_disconnected_error(e):
            _PEER_CACHE.pop(key, None)
            if await _ensure_client_connected(force_reconnect=True):
                try:
                    peer = await client.get_input_entity(key)
                    _PEER_CACHE[key] = peer
                    return peer
                except Exception as retry_error:
                    push_log(stage="peer_resolve", result="retry_error", extra={"bot_username": key, "error": str(retry_error), "trace": traceback.format_exc()[:1200]})
                    return None
        push_log(stage="peer_resolve", result="error", extra={"bot_username": key, "error": str(e), "trace": traceback.format_exc()[:1200]})
        return None


async def backfill_latest_from_bot(
    bot_username: str,
    limit: int = 120,
    timeout_seconds: float = 6.0,
    max_logs: int = 2000,
    step: int = -1,
    force: bool = False,
    min_message_id: int = 0
):
    key = str(bot_username or "").strip()
    if not key:
        return

    now = _now_s()
    last = _BACKFILL_LAST_AT.get(key, 0.0)
    if (not force) and (now - float(last)) < float(_BACKFILL_THROTTLE_SECONDS):
        return
    _BACKFILL_LAST_AT[key] = now

    peer = await _get_peer_for_bot(bot_username)
    min_mid = int(min_message_id or 0)

    async def _do():
        if peer is None:
            async for msg in client.iter_messages(bot_username, limit=limit):
                try:
                    mid = int(getattr(msg, "id", 0) or 0)
                except Exception:
                    mid = 0

                if min_mid > 0 and mid > 0 and mid < min_mid:
                    break

                if not msg.out:
                    await save_message(msg, forced_sender_username=bot_username)
            return

        async for msg in client.iter_messages(peer, limit=limit):
            try:
                mid = int(getattr(msg, "id", 0) or 0)
            except Exception:
                mid = 0

            if min_mid > 0 and mid > 0 and mid < min_mid:
                break

            if not msg.out:
                await save_message(msg, forced_sender_username=bot_username)

    try:
        await asyncio.wait_for(_do(), timeout=float(timeout_seconds))
    except FloodWaitError as e:
        wait_s = int(getattr(e, "seconds", 5) or 5)
        push_log(stage="backfill", result="flood_wait", step=step, extra={"seconds": wait_s}, max_logs=max_logs)
        await asyncio.sleep(min(wait_s + 1, 20))
        try:
            await asyncio.wait_for(_do(), timeout=float(timeout_seconds))
        except Exception as e2:
            push_log(stage="backfill", result="error_after_wait", step=step, extra={"error": str(e2)}, max_logs=max_logs)
    except asyncio.TimeoutError:
        push_log(stage="backfill", result="timeout", step=step, max_logs=max_logs)
    except Exception as e:
        push_log(stage="backfill", result="error", step=step, extra={"error": str(e)}, max_logs=max_logs)


async def click_callback(bot_username: str, chat_id: int, message_id: int, data_hex: str):
    peer = await _get_peer_for_bot(bot_username)
    if peer is None:
        push_log(stage="click_callback", result="no_peer", extra={"bot_username": bot_username, "chat_id": chat_id, "message_id": message_id})
        raise RuntimeError("no peer resolved for bot_username")

    try:
        await client(GetBotCallbackAnswerRequest(peer=peer, msg_id=message_id, data=bytes.fromhex(data_hex)))
    except FloodWaitError as e:
        wait_s = 0
        try:
            wait_s = int(getattr(e, "seconds", 0) or 0)
        except Exception:
            wait_s = 0
        push_log(stage="click_callback", result="flood_wait", extra={"bot_username": bot_username, "chat_id": chat_id, "message_id": message_id, "wait_seconds": wait_s})
        if wait_s > 0:
            await asyncio.sleep(float(wait_s) + 1.0)
        await client(GetBotCallbackAnswerRequest(peer=peer, msg_id=message_id, data=bytes.fromhex(data_hex)))


async def _delete_messages_in_batches(bot_username: str, message_ids: List[int], revoke: bool = True):
    if not message_ids:
        return

    peer = await _get_peer_for_bot(bot_username)
    if peer is None:
        push_log(stage="cleanup_delete", result="no_peer", extra={"bot_username": bot_username})
        return

    batch_size = 90
    i = 0
    while i < len(message_ids):
        batch = message_ids[i:i + batch_size]
        try:
            await client.delete_messages(peer, batch, revoke=revoke)
            push_log(stage="cleanup_delete", result="batch_ok", extra={"count": len(batch), "from": batch[0], "to": batch[-1]})
        except FloodWaitError as e:
            wait_s = int(getattr(e, "seconds", 5) or 5)
            push_log(stage="cleanup_delete", result="flood_wait", extra={"seconds": wait_s, "count": len(batch)})
            await asyncio.sleep(min(wait_s + 1, 20))
        except Exception as e:
            push_log(stage="cleanup_delete", result="batch_error", extra={"error": str(e), "trace": traceback.format_exc()[:800]})
        i = i + batch_size
        await asyncio.sleep(0.2)


async def _find_remaining_message_ids(peer: Any, message_ids: List[int]) -> List[int]:
    if not message_ids:
        return []

    try:
        fetched = await client.get_messages(peer, ids=message_ids)
    except Exception:
        return list(message_ids)

    if fetched is None:
        return []

    fetched_messages = fetched if isinstance(fetched, list) else [fetched]
    remaining: List[int] = []

    for msg in fetched_messages:
        if msg is None:
            continue

        if type(msg).__name__ == "MessageEmpty":
            continue

        try:
            mid = int(getattr(msg, "id", 0) or 0)
        except Exception:
            mid = 0

        if mid > 0:
            remaining.append(mid)

    return sorted(list(set(remaining)))


async def _cleanup_chat_after_run(
    bot_username: str,
    min_mid: int = 0,
    scope: str = "run",
    limit: int = 300,
    max_mid: int = 0,
    min_message_id: Optional[int] = None,
    max_message_id: Optional[int] = None,
    preserve_file_messages: bool = False,
    max_logs: int = 2000
):
    scope = (scope or "run").strip().lower()
    if scope not in ["run", "all"]:
        scope = "run"

    lower_bound = int(min_message_id if min_message_id is not None else (min_mid or 0))
    upper_bound = int(max_message_id if max_message_id is not None else (max_mid or 0))
    if upper_bound > 0 and upper_bound < lower_bound:
        upper_bound = lower_bound

    peer = await _get_peer_for_bot(bot_username)
    if peer is None:
        push_log(stage="cleanup", result="no_peer", extra={"bot_username": bot_username, "scope": scope}, max_logs=max_logs)
        return

    if scope == "all":
        try:
            await client(DeleteHistoryRequest(peer=peer, max_id=0, just_clear=False, revoke=True))
            push_log(stage="cleanup", result="delete_history_ok", extra={"scope": "all"}, max_logs=max_logs)
        except FloodWaitError as e:
            wait_s = int(getattr(e, "seconds", 5) or 5)
            push_log(stage="cleanup", result="delete_history_flood_wait", extra={"seconds": wait_s}, max_logs=max_logs)
            await asyncio.sleep(min(wait_s + 1, 20))
            try:
                await client(DeleteHistoryRequest(peer=peer, max_id=0, just_clear=False, revoke=True))
                push_log(stage="cleanup", result="delete_history_ok_after_wait", extra={"scope": "all"}, max_logs=max_logs)
            except Exception as e2:
                push_log(stage="cleanup", result="delete_history_error", extra={"error": str(e2), "trace": traceback.format_exc()[:800]}, max_logs=max_logs)
        except Exception as e:
            push_log(stage="cleanup", result="delete_history_error", extra={"error": str(e), "trace": traceback.format_exc()[:800]}, max_logs=max_logs)
        return

    push_log(
        stage="cleanup_snapshot",
        result="before_range_delete",
        extra={
            "bot_username": bot_username,
            "scope": scope,
            "min_mid": lower_bound,
            "max_mid": upper_bound,
            "store_recent": _recent_store_snapshot(bot_username, limit=8, min_message_id=max(lower_bound - 3, 0)),
            "live_recent": await _recent_live_snapshot(bot_username, limit=8),
        },
        max_logs=max_logs,
    )

    ids: List[int] = []
    preserved_file_ids: List[int] = []
    try:
        async for msg in client.iter_messages(peer, limit=max(int(limit or 300), 50)):
            try:
                mid = int(msg.id or 0)
            except Exception:
                continue
            if mid < lower_bound:
                continue
            if upper_bound > 0 and mid > upper_bound:
                continue
            if preserve_file_messages and (
                bool(getattr(msg, "media", None))
                or _store_message_has_file(bot_username, mid)
            ):
                preserved_file_ids.append(mid)
                continue
            ids.append(mid)
    except Exception as e:
        push_log(stage="cleanup", result="iter_messages_error", extra={"error": str(e), "trace": traceback.format_exc()[:800]}, max_logs=max_logs)
        return

    if not ids:
        push_log(stage="cleanup", result="no_messages_to_delete", extra={"scope": "run", "min_mid": lower_bound, "max_mid": upper_bound, "preserved_file_ids": preserved_file_ids[:120]}, max_logs=max_logs)
        return

    ids_sorted = sorted(ids)
    push_log(stage="cleanup", result="collected", extra={"scope": "run", "count": len(ids_sorted), "from": ids_sorted[0], "to": ids_sorted[-1], "min_mid": lower_bound, "max_mid": upper_bound, "preserved_file_ids": preserved_file_ids[:120]}, max_logs=max_logs)
    await _delete_messages_in_batches(bot_username, ids_sorted, revoke=True)
    push_log(
        stage="cleanup_snapshot",
        result="after_range_delete",
        extra={
            "bot_username": bot_username,
            "scope": scope,
            "deleted_message_ids": ids_sorted[:120],
            "preserved_file_ids": preserved_file_ids[:120],
            "min_mid": lower_bound,
            "max_mid": upper_bound,
            "store_recent": _recent_store_snapshot(bot_username, limit=8, min_message_id=max(lower_bound - 3, 0)),
            "live_recent": await _recent_live_snapshot(bot_username, limit=8),
        },
        max_logs=max_logs,
    )


async def _cleanup_not_found_messages(
    bot_username: str,
    trigger_msg: Optional[Dict[str, Any]],
    min_message_id: int = 0,
    sent_message_id: int = 0
):
    msg = trigger_msg or {}
    ids: List[int] = []
    run_floor_mid = int(min_message_id or 0)
    sent_mid = int(sent_message_id or 0)

    try:
        trigger_mid = int(msg.get("message_id", 0) or 0)
    except Exception:
        trigger_mid = 0

    try:
        reply_mid = int(msg.get("reply_to_message_id", 0) or 0)
    except Exception:
        reply_mid = 0

    if trigger_mid > 0 and (run_floor_mid <= 0 or trigger_mid >= run_floor_mid):
        ids.append(trigger_mid)
    if sent_mid > 0:
        ids.append(sent_mid)
    elif reply_mid > 0 and (run_floor_mid <= 0 or reply_mid >= run_floor_mid):
        ids.append(reply_mid)
    elif run_floor_mid > 0:
        ids.append(run_floor_mid)

    deduped: List[int] = []
    seen: Set[int] = set()
    for mid in ids:
        if mid <= 0 or mid in seen:
            continue
        seen.add(mid)
        deduped.append(mid)

    if not deduped:
        push_log(stage="cleanup_not_found", result="skip_no_ids", extra={"bot_username": bot_username})
        return

    push_log(
        stage="cleanup_not_found",
        result="delete_targeted",
        extra={
            "bot_username": bot_username,
            "message_ids": deduped,
            "sent_message_id": sent_mid,
            "trigger_message_id": trigger_mid,
            "candidate_reply_to_message_id": reply_mid,
            "fallback_min_message_id": run_floor_mid,
            "trigger_message": _message_debug_summary(msg),
            "store_recent": _recent_store_snapshot(bot_username, limit=8, min_message_id=max(run_floor_mid - 3, 0)),
            "live_recent": await _recent_live_snapshot(bot_username, limit=8),
        }
    )
    await _delete_messages_in_batches(bot_username, deduped, revoke=True)
    push_log(
        stage="cleanup_not_found",
        result="delete_targeted_done",
        extra={
            "bot_username": bot_username,
            "message_ids": deduped,
            "sent_message_id": sent_mid,
            "store_recent": _recent_store_snapshot(bot_username, limit=8, min_message_id=max(run_floor_mid - 3, 0)),
            "live_recent": await _recent_live_snapshot(bot_username, limit=8),
        }
    )

def _get_debug_logs(limit: int = 200) -> List[Dict[str, Any]]:
    try:
        return DEBUG_LOGS[-max(int(limit or 200), 1):]
    except Exception:
        return []


def _message_debug_summary(msg: Optional[Dict[str, Any]]) -> Optional[Dict[str, Any]]:
    if not msg:
        return None

    text = str(msg.get("text") or "").replace("\r", " ").replace("\n", " ").strip()
    buttons = get_callback_buttons(msg)

    return {
        "message_id": int(msg.get("message_id", 0) or 0),
        "reply_to_message_id": int(msg.get("reply_to_message_id", 0) or 0),
        "chat_id": int(msg.get("chat_id", 0) or 0),
        "bot_username": msg.get("bot_username"),
        "sender_username": msg.get("sender_username"),
        "has_file": bool(msg.get("file")),
        "has_buttons": bool(buttons),
        "buttons_text": summarize_buttons(buttons) if buttons else "",
        "text_preview": text[:180],
        "date": msg.get("date"),
    }


def _recent_store_snapshot(bot_username: str, limit: int = 8, min_message_id: int = 0) -> List[Dict[str, Any]]:
    items: List[Dict[str, Any]] = []
    for msg in get_all_messages_sorted():
        if not _message_matches_bot(msg, bot_username):
            continue
        mid = int(msg.get("message_id", 0) or 0)
        if int(min_message_id or 0) > 0 and mid < int(min_message_id or 0):
            continue
        summary = _message_debug_summary(msg)
        if summary:
            items.append(summary)

    if limit > 0:
        items = items[-limit:]
    return items


def _store_message_has_file(bot_username: str, message_id: int) -> bool:
    target_mid = int(message_id or 0)
    if target_mid <= 0:
        return False

    for msg in MESSAGE_STORE.values():
        try:
            if not _message_matches_bot(msg, bot_username):
                continue
            if int(msg.get("message_id", 0) or 0) != target_mid:
                continue
            return bool(msg.get("file"))
        except Exception:
            continue

    return False


async def _recent_live_snapshot(bot_username: str, limit: int = 8) -> List[Dict[str, Any]]:
    peer = await _get_peer_for_bot(bot_username)
    if peer is None:
        return []

    out: List[Dict[str, Any]] = []
    try:
        async for msg in client.iter_messages(peer, limit=max(int(limit or 8), 1)):
            try:
                text = str(getattr(msg, "text", "") or "").replace("\r", " ").replace("\n", " ").strip()
                out.append({
                    "message_id": int(getattr(msg, "id", 0) or 0),
                    "reply_to_message_id": int(getattr(getattr(msg, "reply_to", None), "reply_to_msg_id", 0) or 0),
                    "has_file": bool(getattr(msg, "media", None)),
                    "text_preview": text[:180],
                    "date": getattr(msg, "date", None).isoformat() if getattr(msg, "date", None) else None,
                })
            except Exception:
                continue
    except Exception as e:
        return [{"_error": str(e)}]

    out.reverse()
    return out

def _sanitize_for_path(s: str, max_len: int = 80) -> str:
    s = (s or "").strip()
    if not s:
        return "download"
    s = re.sub(r"\s+", " ", s).strip()
    s = s.replace("/", "_").replace("\\", "_").replace(":", "_")
    s = s.replace("*", "_").replace("?", "_").replace('"', "_")
    s = s.replace("<", "_").replace(">", "_").replace("|", "_")
    s = re.sub(r"[^\w\u4e00-\u9fff\u3040-\u30ff\uac00-\ud7af\.\-\s_]+", "_", s)
    s = s.strip(" ._")
    if not s:
        s = "download"
    if len(s) > max_len:
        s = s[:max_len].rstrip(" ._")
    return s or "download"


def _ensure_download_folder_for_text(text: str) -> str:
    base_dir = SERVICE_HOME

    downloads_root = os.path.join(base_dir, "downloads")
    try:
        os.makedirs(downloads_root, exist_ok=True)
    except Exception:
        downloads_root = base_dir

    folder_name = _sanitize_for_path(text, max_len=80)
    folder_path = os.path.join(downloads_root, folder_name)
    try:
        os.makedirs(folder_path, exist_ok=True)
    except Exception:
        tmp = os.path.join(downloads_root, "download")
        os.makedirs(tmp, exist_ok=True)
        folder_path = tmp
    return folder_path


def _media_download_subdir(file_type: Optional[str], mime_type: Optional[str]) -> str:
    ft = str(file_type or "").strip().lower()
    mt = str(mime_type or "").strip().lower()

    if ft in ["photo", "image"] or mt.startswith("image/"):
        return "images"
    if ft == "video" or mt.startswith("video/"):
        return "videos"
    return "files"


def _ensure_download_folder_for_media(text: str, file_type: Optional[str], mime_type: Optional[str]) -> str:
    base_folder = _ensure_download_folder_for_text(text)
    target_folder = os.path.join(base_folder, _media_download_subdir(file_type, mime_type))
    os.makedirs(target_folder, exist_ok=True)
    return target_folder


def _run_tdl_download_url(
    url: str,
    folder_path: str,
    reserved_path: str
) -> Dict[str, Any]:
    exe_path = str(TDL_EXE_PATH or "").strip()
    if not exe_path or not os.path.isfile(exe_path):
        return {
            "ok": False,
            "reason": "tdl_missing",
            "exe_path": exe_path,
        }

    os.makedirs(folder_path, exist_ok=True)
    before_entries: Set[str] = set()
    try:
        before_entries = set(os.listdir(folder_path))
    except Exception:
        before_entries = set()

    cmd = [
        exe_path,
        "dl",
        "-u",
        url,
        "-d",
        folder_path,
        "-t",
        str(TDL_DOWNLOAD_THREADS),
        "-l",
        str(TDL_DOWNLOAD_LIMIT),
        "--skip-same",
    ]

    try:
        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=300,
            check=False,
        )
    except Exception as e:
        return {
            "ok": False,
            "reason": "tdl_exec_error",
            "error": str(e),
        }

    stdout = str(proc.stdout or "")
    stderr = str(proc.stderr or "")
    after_paths: List[str] = []

    try:
        for name in os.listdir(folder_path):
            if name in before_entries:
                continue
            full_path = os.path.join(folder_path, name)
            if os.path.isfile(full_path):
                after_paths.append(full_path)
    except Exception:
        after_paths = []

    if proc.returncode != 0:
        return {
            "ok": False,
            "reason": "tdl_failed",
            "returncode": int(proc.returncode),
            "stdout": stdout[-2000:],
            "stderr": stderr[-2000:],
        }

    if not after_paths:
        if os.path.isfile(reserved_path):
            return {
                "ok": True,
                "saved_path": reserved_path,
                "saved_name": os.path.basename(reserved_path),
                "stdout": stdout[-1000:],
                "stderr": stderr[-1000:],
            }
        return {
            "ok": False,
            "reason": "tdl_no_new_file",
            "stdout": stdout[-2000:],
            "stderr": stderr[-2000:],
        }

    after_paths.sort(key=lambda path: os.path.getmtime(path), reverse=True)
    downloaded_path = after_paths[0]

    final_path = reserved_path
    try:
        if os.path.normcase(downloaded_path) != os.path.normcase(final_path):
            if os.path.isfile(final_path):
                os.remove(final_path)
            os.replace(downloaded_path, final_path)
            for extra_path in after_paths[1:]:
                try:
                    if os.path.isfile(extra_path):
                        os.remove(extra_path)
                except Exception:
                    pass
        else:
            final_path = downloaded_path
    except Exception as e:
        return {
            "ok": False,
            "reason": "tdl_rename_failed",
            "downloaded_path": downloaded_path,
            "target_path": final_path,
            "error": str(e),
            "stdout": stdout[-2000:],
            "stderr": stderr[-2000:],
        }

    return {
        "ok": True,
        "saved_path": final_path,
        "saved_name": os.path.basename(final_path),
        "stdout": stdout[-1000:],
        "stderr": stderr[-1000:],
    }


def _run_tdl_download(
    peer_id: int,
    message_id: int,
    folder_path: str,
    reserved_path: str
) -> Dict[str, Any]:
    url = f"https://t.me/c/{int(peer_id)}/{int(message_id)}"
    return _run_tdl_download_url(url, folder_path, reserved_path)


def _run_tdl_download_for_bot_message(
    bot_username: str,
    message_id: int,
    folder_path: str,
    reserved_path: str
) -> Dict[str, Any]:
    username = str(bot_username or "").strip().lstrip("@")
    if not username:
        return {
            "ok": False,
            "reason": "bot_username_missing",
        }

    url = f"https://t.me/{username}/{int(message_id)}"
    return _run_tdl_download_url(url, folder_path, reserved_path)


def _guess_ext_from_name_or_mime(file_name: Optional[str], mime_type: Optional[str]) -> str:
    fn = (file_name or "").strip().lower()
    if "." in fn:
        ext = fn.rsplit(".", 1)[-1]
        if 1 <= len(ext) <= 6 and re.fullmatch(r"[a-z0-9]+", ext):
            return "." + ext

    mt = (mime_type or "").strip().lower()
    mapping = {
        "video/mp4": ".mp4",
        "video/quicktime": ".mov",
        "video/x-matroska": ".mkv",
        "video/webm": ".webm",
        "image/jpeg": ".jpg",
        "image/png": ".png",
        "image/webp": ".webp",
        "image/gif": ".gif",
        "application/pdf": ".pdf",
        "text/plain": ".txt",
    }
    return mapping.get(mt, "")


def _safe_download_ext(file_name: Optional[str], mime_type: Optional[str]) -> str:
    ext = _guess_ext_from_name_or_mime(file_name, mime_type)
    if ext and re.fullmatch(r"\.[A-Za-z0-9]{1,10}", ext):
        return ext
    return ""


def _reserve_download_file_path(
    folder_path: str,
    file_obj: Dict[str, Any],
    base_name: str,
    index: int,
    job_id: str
) -> str:
    original_name = str(file_obj.get("file_name") or "").strip()
    ext = _safe_download_ext(original_name, file_obj.get("mime_type"))

    if original_name:
        stem = original_name
        if "." in original_name:
            stem = original_name.rsplit(".", 1)[0]
        safe_stem = _sanitize_for_path(stem, max_len=120)
        if not safe_stem:
            safe_stem = "download"
    else:
        safe_stem = f"{_sanitize_for_path(base_name, max_len=90)}_{index}"

    reserved = DOWNLOAD_RESERVED_PATHS_BY_JOB.get(job_id)
    if reserved is None:
        reserved = set()
        DOWNLOAD_RESERVED_PATHS_BY_JOB[job_id] = reserved

    suffix = 0
    while True:
        if suffix <= 0:
            candidate_name = f"{safe_stem}{ext}"
        else:
            candidate_name = f"{safe_stem} ({suffix}){ext}"

        candidate_path = os.path.join(folder_path, candidate_name)
        normalized = os.path.normcase(candidate_path)
        if normalized in reserved or os.path.exists(candidate_path):
            suffix = suffix + 1
            continue

        reserved.add(normalized)
        return candidate_path


def _cleanup_partial_download_path(file_path: Optional[str]) -> None:
    try:
        if file_path and os.path.isfile(file_path):
            os.remove(file_path)
    except Exception:
        pass


async def _download_media_with_telethon_timeout(
    message: Message,
    file_path: str,
    timeout_seconds: int
) -> Dict[str, Any]:
    started_at = time.time()

    try:
        await asyncio.wait_for(
            client.download_media(message, file=file_path),
            timeout=float(timeout_seconds or 0),
        )
        return {
            "ok": True,
            "saved_path": file_path,
            "saved_name": os.path.basename(file_path),
            "elapsed_seconds": round(time.time() - started_at, 3),
        }
    except asyncio.TimeoutError:
        _cleanup_partial_download_path(file_path)
        return {
            "ok": False,
            "reason": "telethon_download_timeout",
            "timeout_seconds": int(timeout_seconds or 0),
            "saved_path": file_path,
            "saved_name": os.path.basename(file_path),
            "elapsed_seconds": round(time.time() - started_at, 3),
        }
    except Exception as e:
        _cleanup_partial_download_path(file_path)
        return {
            "ok": False,
            "reason": "telethon_download_error",
            "error": str(e),
            "saved_path": file_path,
            "saved_name": os.path.basename(file_path),
            "elapsed_seconds": round(time.time() - started_at, 3),
        }


async def _download_one_file_by_message_id(
    bot_username: str,
    peer: Any,
    file_obj: Dict[str, Any],
    folder_path: str,
    base_name: str,
    index: int,
    job_id: str,
    slow_seconds: float
):
    async with DOWNLOAD_SEMAPHORE:
        try:
            mid = int(file_obj.get("message_id", 0) or 0)
            if mid <= 0:
                raise RuntimeError("invalid message_id")

            unique_key = _file_dedup_key(file_obj)

            seen = DOWNLOAD_SEEN_KEYS_BY_JOB.get(job_id)
            if seen is None:
                seen = set()
                DOWNLOAD_SEEN_KEYS_BY_JOB[job_id] = seen

            if unique_key in seen:
                return
            seen.add(unique_key)

            one = await client.get_messages(peer, ids=mid)
            if one is None:
                raise RuntimeError("message not found")

            file_path = _reserve_download_file_path(
                folder_path=folder_path,
                file_obj=file_obj,
                base_name=base_name,
                index=index,
                job_id=job_id
            )

            downloader = "telethon"
            telethon_result: Dict[str, Any] = {"ok": False, "reason": "not_attempted"}
            tdl_result = await asyncio.to_thread(
                _run_tdl_download_for_bot_message,
                bot_username,
                mid,
                folder_path,
                file_path,
            )

            if bool(tdl_result.get("ok")):
                downloader = "tdl"
                file_path = str(tdl_result.get("saved_path") or file_path)
            else:
                telethon_result = await _download_media_with_telethon_timeout(
                    one,
                    file_path,
                    BACKGROUND_TELETHON_DOWNLOAD_TIMEOUT_SECONDS,
                )
                if bool(telethon_result.get("ok")):
                    file_path = str(telethon_result.get("saved_path") or file_path)
                else:
                    reason = str(telethon_result.get("reason") or "telethon_download_failed")
                    error = str(telethon_result.get("error") or "").strip()
                    if error:
                        raise RuntimeError(f"{reason}: {error}")
                    raise RuntimeError(reason)

            job = DOWNLOAD_JOBS.get(job_id) or {}
            job["done"] = int(job.get("done", 0)) + 1
            job["last_saved_path"] = file_path
            job["last_downloader"] = downloader
            DOWNLOAD_JOBS[job_id] = job

            push_log(
                stage="download_local",
                result="ok",
                extra={
                    "job_id": job_id,
                    "mid": mid,
                    "path": file_path,
                    "downloader": downloader,
                    "tdl": _json_sanitize(tdl_result),
                    "telethon": _json_sanitize(telethon_result),
                }
            )

        except Exception as e:
            job = DOWNLOAD_JOBS.get(job_id) or {}
            job["failed"] = int(job.get("failed", 0)) + 1
            job["last_error"] = str(e)
            DOWNLOAD_JOBS[job_id] = job
            push_log(stage="download_local", result="error", extra={"job_id": job_id, "error": str(e), "trace": traceback.format_exc()[:800]})
        finally:
            if slow_seconds and slow_seconds > 0:
                await asyncio.sleep(float(slow_seconds))


async def _download_group_message_media_to_local(
    peer_id: int,
    message_id: int,
    folder_label: Optional[str] = None
) -> Dict[str, Any]:
    ent = await _resolve_any_entity_by_id(peer_id)
    if ent is None:
        return {
            "status": "fail",
            "reason": "entity_not_found",
            "peer_id": int(peer_id),
            "message_id": int(message_id),
        }

    group_title = getattr(ent, "title", None) or getattr(ent, "first_name", None) or getattr(ent, "username", None)

    msg = await client.get_messages(ent, ids=int(message_id))
    if msg is None:
        return {
            "status": "fail",
            "reason": "message_not_found",
            "peer_id": int(peer_id),
            "message_id": int(message_id),
            "group_title": group_title,
        }

    file_obj = _extract_file_meta_mtproto(msg)
    if not file_obj:
        return {
            "status": "ok",
            "downloaded": False,
            "reason": "no_media",
            "peer_id": int(peer_id),
            "message_id": int(message_id),
            "group_title": group_title,
        }

    label = str(folder_label or "").strip()
    if not label:
        title_for_path = str(group_title or f"group_{int(peer_id)}").strip()
        label = f"group_{int(peer_id)}_{title_for_path}"

    folder_path = _ensure_download_folder_for_media(
        label,
        file_obj.get("file_type"),
        file_obj.get("mime_type"),
    )
    job_id = f"group-download-{int(peer_id)}-{int(message_id)}-{uuid.uuid4().hex}"
    file_path = _reserve_download_file_path(
        folder_path=folder_path,
        file_obj=file_obj,
        base_name=str(file_obj.get("file_name") or f"message_{int(message_id)}"),
        index=1,
        job_id=job_id,
    )

    downloader = "telethon"
    telethon_result: Dict[str, Any] = {"ok": False, "reason": "not_attempted"}
    tdl_result = await asyncio.to_thread(
        _run_tdl_download,
        int(peer_id),
        int(message_id),
        folder_path,
        file_path,
    )

    if bool(tdl_result.get("ok")):
        downloader = "tdl"
        file_path = str(tdl_result.get("saved_path") or file_path)
    else:
        telethon_result = await _download_media_with_telethon_timeout(
            msg,
            file_path,
            GROUP_TELETHON_DOWNLOAD_TIMEOUT_SECONDS,
        )
        if bool(telethon_result.get("ok")):
            file_path = str(telethon_result.get("saved_path") or file_path)
        else:
            return {
                "status": "fail",
                "downloaded": False,
                "reason": str(telethon_result.get("reason") or "telethon_download_failed"),
                "error": str(telethon_result.get("error") or ""),
                "peer_id": int(peer_id),
                "message_id": int(message_id),
                "group_title": group_title,
                "folder_path": folder_path,
                "saved_path": telethon_result.get("saved_path"),
                "saved_name": telethon_result.get("saved_name"),
                "downloader": downloader,
                "tdl": _json_sanitize(tdl_result),
                "telethon": _json_sanitize(telethon_result),
                "file": {
                    "file_name": file_obj.get("file_name"),
                    "mime_type": file_obj.get("mime_type"),
                    "file_size": int(file_obj.get("file_size", 0) or 0),
                    "file_type": file_obj.get("file_type"),
                    "file_unique_id": file_obj.get("file_unique_id"),
                },
            }

    return {
        "status": "ok",
        "downloaded": True,
        "peer_id": int(peer_id),
        "message_id": int(message_id),
        "group_title": group_title,
        "folder_path": folder_path,
        "saved_path": file_path,
        "saved_name": os.path.basename(file_path),
        "downloader": downloader,
        "tdl": _json_sanitize(tdl_result),
        "telethon": _json_sanitize(telethon_result),
        "file": {
            "file_name": file_obj.get("file_name"),
            "mime_type": file_obj.get("mime_type"),
            "file_size": int(file_obj.get("file_size", 0) or 0),
            "file_type": file_obj.get("file_type"),
            "file_unique_id": file_obj.get("file_unique_id"),
        },
    }



def _zip_folder_to_file(folder_path: str, zip_path: str) -> None:
    try:
        if not folder_path or not zip_path:
            return
        if not os.path.isdir(folder_path):
            return

        base_dir = os.path.dirname(os.path.abspath(zip_path))
        if base_dir:
            os.makedirs(base_dir, exist_ok=True)

        with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
            for root, _, files in os.walk(folder_path):
                for fn in files:
                    fp = os.path.join(root, fn)
                    if not os.path.isfile(fp):
                        continue
                    arc = os.path.relpath(fp, folder_path)
                    zf.write(fp, arcname=arc)
    except Exception as e:
        push_log(stage="zip_folder", result="error", extra={"error": str(e), "folder_path": folder_path, "zip_path": zip_path, "trace": traceback.format_exc()[:800]})



async def _background_download_files(
    bot_username: str,
    files: List[Dict[str, Any]],
    folder_path: str,
    base_name: str,
    job_id: str,
    slow_seconds: float = 0.8,
    create_zip: bool = False,
    task_batch_size: int = 80
):
    peer = await _get_peer_for_bot(bot_username)
    if peer is None:
        job = DOWNLOAD_JOBS.get(job_id) or {}
        job["status"] = "fail"
        job["last_error"] = "no peer"
        DOWNLOAD_JOBS[job_id] = job
        try:
            if job_id in DOWNLOAD_SEEN_KEYS_BY_JOB:
                del DOWNLOAD_SEEN_KEYS_BY_JOB[job_id]
        except Exception:
            pass
        return

    job = DOWNLOAD_JOBS.get(job_id) or {}
    job["status"] = "running"
    job["folder_path"] = folder_path
    job["total"] = int(job.get("total", 0) or 0) if job.get("total") is not None else 0
    if job["total"] <= 0:
        job["total"] = len(files)
    DOWNLOAD_JOBS[job_id] = job

    tasks: List[asyncio.Task] = []
    idx = 0

    try:
        for f in files:
            idx = idx + 1
            tasks.append(asyncio.create_task(_download_one_file_by_message_id(
                bot_username=bot_username,
                peer=peer,
                file_obj=f,
                folder_path=folder_path,
                base_name=base_name,
                index=idx,
                job_id=job_id,
                slow_seconds=slow_seconds
            )))

            if int(task_batch_size or 0) > 0 and len(tasks) >= int(task_batch_size):
                await asyncio.gather(*tasks)
                tasks.clear()

        if tasks:
            await asyncio.gather(*tasks)
            tasks.clear()

        job = DOWNLOAD_JOBS.get(job_id) or {}
        job["status"] = "done"
        DOWNLOAD_JOBS[job_id] = job

        if create_zip:
            try:
                zip_path = folder_path.rstrip("/\\") + ".zip"
                _zip_folder_to_file(folder_path, zip_path)
                job = DOWNLOAD_JOBS.get(job_id) or {}
                job["zip_path"] = zip_path
                job["zip_file"] = os.path.basename(zip_path)
                job["zip_ready"] = bool(os.path.isfile(zip_path))
                DOWNLOAD_JOBS[job_id] = job
            except Exception as e:
                job = DOWNLOAD_JOBS.get(job_id) or {}
                job["zip_ready"] = False
                job["zip_error"] = str(e)
                DOWNLOAD_JOBS[job_id] = job

    except Exception as e:
        job = DOWNLOAD_JOBS.get(job_id) or {}
        job["status"] = "fail"
        job["last_error"] = str(e)
        DOWNLOAD_JOBS[job_id] = job
    finally:
        try:
            if job_id in DOWNLOAD_SEEN_KEYS_BY_JOB:
                del DOWNLOAD_SEEN_KEYS_BY_JOB[job_id]
        except Exception:
            pass
        try:
            if job_id in DOWNLOAD_RESERVED_PATHS_BY_JOB:
                del DOWNLOAD_RESERVED_PATHS_BY_JOB[job_id]
        except Exception:
            pass



class SendBotMessageRequest(BaseModel):
    bot_username: str
    text: str
    clear_previous_replies: bool = True


class UploadBotVideoRequest(BaseModel):
    bot_username: str
    file_path: str
    caption: Optional[str] = None
    clear_previous_replies: bool = False
    supports_streaming: bool = True


class GetBotFilesRequest(BaseModel):
    bot_username: str
    min_message_id: int = 0
    max_return_files: int = 1000
    max_raw_payload_bytes: int = 0
    backfill_limit: int = 360
    backfill_timeout_seconds: float = 6.0
    force_backfill: bool = True


class ForwardBotMessagesRequest(BaseModel):
    source_chat_id: int
    message_ids: List[int]
    target_bot_username: str
    bridge_control_text: Optional[str] = None


class DeleteMessagesRequest(BaseModel):
    chat_peer: str
    message_ids: List[int]


@app.post("/bots/send")
async def send_message_to_bot(payload: SendBotMessageRequest):
    if payload.clear_previous_replies:
        clear_all_replies()
        clear_invalid_callback_cache()

    sent = await client.send_message(payload.bot_username, payload.text)
    try:
        sent_message_id = int(getattr(sent, "id", 0) or 0)
    except Exception:
        sent_message_id = 0
    await backfill_latest_from_bot(payload.bot_username, limit=160, timeout_seconds=6.0, force=True)
    return {"status": "ok", "sent_message_id": sent_message_id}


@app.post("/bots/files")
async def get_bot_files(payload: GetBotFilesRequest):
    bot_username = str(payload.bot_username or "").strip()
    if not bot_username:
        return {
            "status": "error",
            "reason": "bot_username_required",
        }

    connected = await _ensure_client_connected()
    if not connected:
        return {
            "status": "error",
            "reason": "client_not_connected",
            "bot_username": bot_username,
        }

    try:
        await backfill_latest_from_bot(
            bot_username,
            limit=max(int(payload.backfill_limit or 0), 1),
            timeout_seconds=float(payload.backfill_timeout_seconds or 6.0),
            force=bool(payload.force_backfill),
            min_message_id=max(int(payload.min_message_id or 0), 0),
        )

        files_meta = collect_files_from_store(
            bot_username,
            max(int(payload.max_return_files or 0), 1),
            max(int(payload.max_raw_payload_bytes or 0), 0),
            min_message_id=max(int(payload.min_message_id or 0), 0),
        )

        files = list(files_meta.get("files") or [])
        files_total_bytes = 0
        source_chat_id = 0

        for file_obj in files:
            try:
                files_total_bytes = files_total_bytes + int(file_obj.get("file_size", 0) or 0)
            except Exception:
                pass

            if source_chat_id > 0:
                continue

            try:
                candidate_chat_id = int(file_obj.get("chat_id", 0) or 0)
            except Exception:
                candidate_chat_id = 0

            if candidate_chat_id > 0:
                source_chat_id = candidate_chat_id

        return {
            "status": "ok",
            "bot_username": bot_username,
            "min_message_id": max(int(payload.min_message_id or 0), 0),
            "source_chat_id": source_chat_id,
            "files_total_bytes": files_total_bytes,
            **files_meta,
        }
    except Exception as e:
        push_log(
            stage="bots_files",
            result="files_error",
            extra={
                "bot_username": bot_username,
                "min_message_id": max(int(payload.min_message_id or 0), 0),
                "error": str(e),
                "trace": traceback.format_exc()[:1200],
            },
        )
        return {
            "status": "error",
            "reason": "files_fetch_failed",
            "error": str(e),
            "bot_username": bot_username,
        }


@app.post("/bots/forward-messages")
async def forward_messages_to_bot(payload: ForwardBotMessagesRequest):
    source_chat_id = int(payload.source_chat_id or 0)
    target_bot_username = str(payload.target_bot_username or "").strip()
    raw_message_ids = list(payload.message_ids or [])
    bridge_control_text = str(payload.bridge_control_text or "").strip()

    if source_chat_id <= 0:
        return {
            "status": "error",
            "reason": "invalid_source_chat_id",
        }

    if not target_bot_username:
        return {
            "status": "error",
            "reason": "target_bot_username_required",
        }

    message_ids: List[int] = []
    seen_ids = set()

    for raw_mid in raw_message_ids:
        try:
            mid = int(raw_mid or 0)
        except Exception:
            continue

        if mid <= 0 or mid in seen_ids:
            continue

        seen_ids.add(mid)
        message_ids.append(mid)

    if not message_ids:
        return {
            "status": "error",
            "reason": "message_ids_required",
        }

    connected = await _ensure_client_connected()
    if not connected:
        return {
            "status": "error",
            "reason": "client_not_connected",
            "source_chat_id": source_chat_id,
            "target_bot_username": target_bot_username,
        }

    try:
        source_entity = await client.get_input_entity(source_chat_id)
        target_entity = await client.get_input_entity(target_bot_username)
        fetched = await client.get_messages(source_entity, ids=message_ids)

        if isinstance(fetched, list):
            fetched_messages = fetched
        elif fetched is None:
            fetched_messages = []
        else:
            fetched_messages = [fetched]

        messages_by_id: Dict[int, Message] = {}
        for msg in fetched_messages:
            try:
                mid = int(getattr(msg, "id", 0) or 0)
            except Exception:
                mid = 0

            if mid > 0:
                messages_by_id[mid] = msg

        ordered_messages = [messages_by_id[mid] for mid in message_ids if mid in messages_by_id]
        missing_message_ids = [mid for mid in message_ids if mid not in messages_by_id]
        unforwardable_message_ids: List[int] = []

        for msg in ordered_messages:
            try:
                mid = int(getattr(msg, "id", 0) or 0)
            except Exception:
                mid = 0

            if mid <= 0:
                continue

            if not getattr(msg, "media", None) or bool(getattr(msg, "noforwards", False)):
                unforwardable_message_ids.append(mid)

        if not ordered_messages:
            return {
                "status": "error",
                "reason": "source_messages_not_found",
                "source_chat_id": source_chat_id,
                "target_bot_username": target_bot_username,
                "requested_message_ids": message_ids,
                "missing_message_ids": missing_message_ids,
            }

        forwardable_messages = [
            msg for msg in ordered_messages
            if int(getattr(msg, "id", 0) or 0) not in set(unforwardable_message_ids)
        ]

        if not forwardable_messages:
            return {
                "status": "error",
                "reason": "no_forwardable_messages",
                "source_chat_id": source_chat_id,
                "target_bot_username": target_bot_username,
                "requested_message_ids": message_ids,
                "missing_message_ids": missing_message_ids,
                "unforwardable_message_ids": unforwardable_message_ids,
            }

        bridge_control_message_id = 0
        if bridge_control_text:
            control_message = await client.send_message(target_entity, bridge_control_text, parse_mode=None)
            try:
                bridge_control_message_id = int(getattr(control_message, "id", 0) or 0)
            except Exception:
                bridge_control_message_id = 0

        forwarded_messages = []
        for offset in range(0, len(forwardable_messages), 100):
            forwarded_batch = await client.forward_messages(
                target_entity,
                forwardable_messages[offset:offset + 100]
            )
            if isinstance(forwarded_batch, list):
                forwarded_messages.extend(forwarded_batch)
            elif forwarded_batch is not None:
                forwarded_messages.append(forwarded_batch)

            if offset + 100 < len(forwardable_messages):
                await asyncio.sleep(0.2)

        forwarded_message_ids: List[int] = []
        for msg in forwarded_messages:
            try:
                mid = int(getattr(msg, "id", 0) or 0)
            except Exception:
                mid = 0

            if mid > 0:
                forwarded_message_ids.append(mid)

        return {
            "status": "ok",
            "source_chat_id": source_chat_id,
            "target_bot_username": target_bot_username,
            "requested_message_ids": message_ids,
            "missing_message_ids": missing_message_ids,
            "unforwardable_message_ids": unforwardable_message_ids,
            "forwarded_message_ids": forwarded_message_ids,
            "bridge_control_message_id": bridge_control_message_id,
            "forwarded_count": len(forwarded_message_ids),
        }
    except Exception as e:
        push_log(
            stage="bots_forward_messages",
            result="forward_error",
            extra={
                "source_chat_id": source_chat_id,
                "target_bot_username": target_bot_username,
                "message_ids": message_ids[:120],
                "error": str(e),
                "trace": traceback.format_exc()[:1200],
            },
        )
        return {
            "status": "error",
            "reason": "forward_failed",
            "error": str(e),
            "source_chat_id": source_chat_id,
            "target_bot_username": target_bot_username,
            "requested_message_ids": message_ids,
            "unforwardable_message_ids": [],
        }


@app.post("/bots/delete-messages")
async def delete_messages_from_chat(payload: DeleteMessagesRequest):
    chat_peer = str(payload.chat_peer or "").strip()
    raw_message_ids = list(payload.message_ids or [])

    if not chat_peer:
        return {
            "status": "error",
            "reason": "chat_peer_required",
        }

    message_ids: List[int] = []
    seen_ids = set()

    for raw_mid in raw_message_ids:
        try:
            mid = int(raw_mid or 0)
        except Exception:
            continue

        if mid <= 0 or mid in seen_ids:
            continue

        seen_ids.add(mid)
        message_ids.append(mid)

    if not message_ids:
        return {
            "status": "error",
            "reason": "message_ids_required",
            "chat_peer": chat_peer,
        }

    connected = await _ensure_client_connected()
    if not connected:
        return {
            "status": "error",
            "reason": "client_not_connected",
            "chat_peer": chat_peer,
            "message_ids": message_ids,
        }

    try:
        try:
            resolved_peer: Any = await client.get_input_entity(int(chat_peer))
        except Exception:
            resolved_peer = await client.get_input_entity(chat_peer)

        remaining_message_ids = list(message_ids)
        for _ in range(3):
            if not remaining_message_ids:
                break

            await client.delete_messages(resolved_peer, remaining_message_ids, revoke=True)
            await asyncio.sleep(0.6)
            remaining_message_ids = await _find_remaining_message_ids(
                resolved_peer,
                remaining_message_ids,
            )

        deleted_message_ids = [
            mid for mid in message_ids
            if mid not in set(remaining_message_ids)
        ]

        if remaining_message_ids:
            push_log(
                stage="bots_delete_messages",
                result="delete_incomplete",
                extra={
                    "chat_peer": chat_peer,
                    "deleted_message_ids": deleted_message_ids[:120],
                    "undeleted_message_ids": remaining_message_ids[:120],
                },
            )

            return {
                "status": "error",
                "reason": "delete_incomplete",
                "chat_peer": chat_peer,
                "message_ids": message_ids,
                "deleted_message_ids": deleted_message_ids,
                "deleted_count": len(deleted_message_ids),
                "undeleted_message_ids": remaining_message_ids,
            }

        return {
            "status": "ok",
            "chat_peer": chat_peer,
            "deleted_message_ids": deleted_message_ids,
            "deleted_count": len(deleted_message_ids),
            "undeleted_message_ids": [],
        }
    except Exception as e:
        push_log(
            stage="bots_delete_messages",
            result="delete_error",
            extra={
                "chat_peer": chat_peer,
                "message_ids": message_ids[:120],
                "error": str(e),
                "trace": traceback.format_exc()[:1200],
            },
        )
        return {
            "status": "error",
            "reason": "delete_failed",
            "error": str(e),
            "chat_peer": chat_peer,
            "message_ids": message_ids,
        }


@app.post("/bots/upload-video")
async def upload_video_to_bot(payload: UploadBotVideoRequest):
    if payload.clear_previous_replies:
        clear_all_replies()
        clear_invalid_callback_cache()

    file_path = os.path.abspath(os.path.expanduser((payload.file_path or "").strip()))
    if not file_path or not os.path.isfile(file_path):
        return {
            "status": "error",
            "reason": "file_not_found",
            "file_path": file_path,
        }

    connected = await _ensure_client_connected()
    if not connected:
        return {
            "status": "error",
            "reason": "client_not_connected",
            "file_path": file_path,
        }

    try:
        duration, width, height = _probe_video_metadata(file_path)
        mime_type = mimetypes.guess_type(file_path)[0] or "video/mp4"
        attributes = [
            DocumentAttributeFilename(os.path.basename(file_path)),
            DocumentAttributeVideo(
                duration=duration or 0,
                w=width or 0,
                h=height or 0,
                supports_streaming=bool(payload.supports_streaming),
            ),
        ]

        sent = await client.send_file(
            payload.bot_username,
            file_path,
            caption=payload.caption,
            mime_type=mime_type,
            attributes=attributes,
            allow_cache=False,
            file_size=os.path.getsize(file_path),
            force_document=False,
            supports_streaming=bool(payload.supports_streaming),
            video_note=False,
        )

        try:
            sent_message_id = int(getattr(sent, "id", 0) or 0)
        except Exception:
            sent_message_id = 0

        sent_as_video = bool(getattr(sent, "video", None))

        return {
            "status": "ok",
            "sent_message_id": sent_message_id,
            "sent_as_video": sent_as_video,
            "file_path": file_path,
            "file_name": os.path.basename(file_path),
        }
    except Exception as e:
        push_log(
            stage="bots_upload_video",
            result="send_error",
            extra={
                "bot_username": payload.bot_username,
                "file_path": file_path,
                "error": str(e),
                "trace": traceback.format_exc()[:1200],
            },
        )
        return {
            "status": "error",
            "reason": "send_failed",
            "error": str(e),
            "file_path": file_path,
        }


@app.post("/bots/clear-replies")
async def clear_replies():
    clear_all_replies()
    clear_invalid_callback_cache()
    return {"status": "ok"}


@app.get("/bots/replies")
async def get_bot_replies(
    limit: int = 20,
    bot_username: str = "",
    min_message_id: int = 0,
    summary_only: bool = False,
):
    msgs = get_all_messages_sorted()
    normalized_bot = str(bot_username or "").strip()
    lower_bound = int(min_message_id or 0)
    max_items = max(int(limit or 20), 1)

    if normalized_bot:
        msgs = [msg for msg in msgs if _message_matches_bot(msg, normalized_bot)]

    if lower_bound > 0:
        msgs = [msg for msg in msgs if int(msg.get("message_id", 0) or 0) >= lower_bound]

    msgs = msgs[-max_items:]

    if summary_only:
        return [_summarize_reply_message(msg) for msg in msgs]

    return msgs


@app.get("/bots/debug-logs")
async def get_debug_logs(limit: int = 200):
    return DEBUG_LOGS[-limit:]


@app.post("/bots/clear-debug-logs")
async def clear_debug_logs():
    DEBUG_LOGS.clear()
    return {"status": "ok"}

@app.get("/bots/download-jobs")
async def get_download_jobs(limit: int = 30):
    items = list(DOWNLOAD_JOBS.items())
    items.sort(key=lambda x: float(x[1].get("created_at_s", 0.0)), reverse=True)
    out = []
    for k, v in items[: max(int(limit or 30), 1)]:
        obj = dict(v)
        obj["job_id"] = k
        out.append(obj)
    return out


@app.get("/bots/download-jobs/{job_id}")
async def get_download_job(job_id: str):
    job = DOWNLOAD_JOBS.get(str(job_id))
    if not job:
        return {"status": "error", "message": "job_not_found", "job_id": str(job_id)}

    obj = dict(job)
    obj["job_id"] = str(job_id)
    return obj



class DownloadAllMediaRequest(BaseModel):
    bot_username: str
    max_messages: int = 0
    include_out: bool = False
    slow_seconds: float = 0.15
    create_zip: bool = False


@app.post("/bots/download-all-media")
async def download_all_media_from_chat(payload: DownloadAllMediaRequest):
    bot_username = str(payload.bot_username or "").strip()
    if not bot_username:
        return {"status": "error", "message": "bot_username_required"}

    if not await _ensure_client_connected():
        return {"status": "error", "message": "client_disconnected"}

    peer = await _get_peer_for_bot(bot_username)
    if peer is None:
        return {"status": "error", "message": "bot_not_found"}

    job_id = str(uuid.uuid4())
    folder_title = f"{bot_username}_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    folder_path = _ensure_download_folder_for_text(folder_title)

    job = {
        "created_at_s": _now_s(),
        "status": "collecting",
        "bot_username": bot_username,
        "folder_path": folder_path,
        "done": 0,
        "failed": 0,
        "total": 0,
        "zip_ready": False,
    }
    DOWNLOAD_JOBS[job_id] = job

    hard_cap = 50000
    limit = int(payload.max_messages or 0)
    if limit <= 0:
        limit = hard_cap
    if limit > hard_cap:
        limit = hard_cap

    files: List[Dict[str, Any]] = []
    scanned = 0

    for attempt in (1, 2):
        try:
            async for msg in client.iter_messages(peer, limit=limit):
                scanned = scanned + 1

                try:
                    if (not bool(payload.include_out)) and bool(getattr(msg, "out", False)):
                        continue
                    if getattr(msg, "media", None) is None:
                        continue
                except Exception:
                    continue

                fm = _extract_file_meta_mtproto(msg)
                if not fm:
                    continue

                files.append(fm)

                if len(files) % 100 == 0:
                    job = DOWNLOAD_JOBS.get(job_id) or {}
                    job["status"] = "collecting"
                    job["total"] = len(files)
                    job["scanned_messages"] = scanned
                    DOWNLOAD_JOBS[job_id] = job

            break

        except FloodWaitError as e:
            wait_s = int(getattr(e, "seconds", 5) or 5)
            job = DOWNLOAD_JOBS.get(job_id) or {}
            job["status"] = "fail"
            job["last_error"] = f"FloodWaitError: {wait_s}"
            DOWNLOAD_JOBS[job_id] = job
            return {"status": "error", "job_id": job_id, "message": "flood_wait", "seconds": wait_s}

        except Exception as e:
            if attempt == 1 and _is_disconnected_error(e):
                push_log(stage="download_collect", result="retry_after_reconnect", extra={"bot_username": bot_username, "error": str(e)})
                _PEER_CACHE.pop(bot_username, None)
                files = []
                scanned = 0
                if await _ensure_client_connected(force_reconnect=True):
                    peer = await _get_peer_for_bot(bot_username)
                    if peer is not None:
                        continue

            job = DOWNLOAD_JOBS.get(job_id) or {}
            job["status"] = "fail"
            job["last_error"] = str(e)
            DOWNLOAD_JOBS[job_id] = job
            return {"status": "error", "job_id": job_id, "message": "collect_failed"}

    files.reverse()

    job = DOWNLOAD_JOBS.get(job_id) or {}
    job["status"] = "queued"
    job["total"] = len(files)
    job["scanned_messages"] = scanned
    DOWNLOAD_JOBS[job_id] = job

    if not files:
        job = DOWNLOAD_JOBS.get(job_id) or {}
        job["status"] = "done"
        DOWNLOAD_JOBS[job_id] = job
        return {"status": "ok", "job_id": job_id, "total": 0, "folder_path": folder_path}

    asyncio.create_task(_background_download_files(
        bot_username=bot_username,
        files=files,
        folder_path=folder_path,
        base_name=bot_username,
        job_id=job_id,
        slow_seconds=float(payload.slow_seconds or 0.15),
        create_zip=False
    ))

    return {"status": "ok", "job_id": job_id, "total": len(files), "folder_path": folder_path}


@app.get("/bots/download-all-media-archive/{job_id}")
async def download_all_media_archive(job_id: str):
    job = DOWNLOAD_JOBS.get(job_id)
    if not job:
        return {"status": "error", "message": "job_not_found"}

    if str(job.get("status")) != "done":
        return {"status": "not_ready", "job": job}

    zip_path = job.get("zip_path")
    if not zip_path:
        return {"status": "not_ready", "job": job}

    if not os.path.isfile(zip_path):
        return {"status": "not_ready", "job": job}

    return FileResponse(
        path=zip_path,
        filename=os.path.basename(zip_path),
        media_type="application/zip"
    )



class SendAndRunAllPagesRequest(BaseModel):
    bot_username: str
    text: str
    clear_previous_replies: bool = True
    download_after_done: bool = True
    wait_download_completion: bool = False
    skip_download_if_total_bytes_exceeds: int = 0
    delay_seconds: int = 0
    max_steps: int = 80

    next_text_keywords: List[str] = [
        "下一頁", "下页", "Next", "next", ">", "»", "›",
        "▶", "►", "⏩", ">>", "»»", "→", "➡", "⏭", "⏭️",
        "更多", "more"
    ]

    wait_first_callback_timeout_seconds: int = 25
    wait_each_page_timeout_seconds: int = 25
    debug: bool = True
    debug_max_logs: int = 2000

    include_files_in_response: bool = True
    max_return_files: int = 500
    max_raw_payload_bytes: int = 0
    stop_when_total_items_exceeds: int = 0

    bootstrap_click_get_all: bool = True
    bootstrap_get_all_keywords: List[str] = ["獲取全部", "获取全部", "Get all", "全部获取", "全部獲取"]
    wait_after_bootstrap_timeout_seconds: int = 25

    allow_ok_when_no_buttons: bool = True

    text_next_fallback_enabled: bool = True
    text_next_command: str = "下一頁"
    stop_when_no_new_files_rounds: int = 4

    stop_when_reached_total_items: bool = True
    max_invalid_callback_rounds: int = 2

    normalize_to_first_page_when_no_buttons: bool = True
    normalize_prev_command: str = "上一頁"
    normalize_max_prev_steps: int = 6

    stop_need_confirm_pagination_done: bool = True
    stop_need_last_page_or_all_pages: bool = True

    cleanup_after_done: bool = True
    cleanup_scope: str = "run"
    cleanup_limit: int = 500

    callback_message_max_age_seconds: int = 25
    callback_candidate_scan_limit: int = 30

    initial_wait_for_controls_seconds: int = 6

    observe_when_no_controls_seconds: int = 10
    observe_when_no_controls_poll_seconds: float = 0.5
    observe_send_get_all_when_no_controls: bool = True
    observe_get_all_command: str = "獲取全部"
    observe_send_next_when_no_controls: bool = False


async def wait_for_first_bot_message(
    bot_username: str,
    timeout_seconds: int,
    max_logs: int,
    prefer_callback: bool = True,
    only_after_message_id: int = 0,
    require_meaningful: bool = True
) -> Optional[Dict[str, Any]]:
    start = time.time()
    iter_i = 0

    while True:
        if (time.time() - start) >= timeout_seconds:
            break

        await backfill_latest_from_bot(bot_username, limit=240, timeout_seconds=6.0, max_logs=max_logs, step=-1)

        if prefer_callback:
            msg_cb = find_latest_callback_message(bot_username, skip_invalid=True)
            if msg_cb and int(msg_cb.get("message_id", 0)) > int(only_after_message_id):
                if (not require_meaningful) or _is_meaningful_state_message_for_pagination(bot_username, msg_cb):
                    push_log(stage="wait_first_bot_message", result="found_callback", iter=iter_i, extra={
                        "message_id": msg_cb.get("message_id"),
                        "chat_id": msg_cb.get("chat_id"),
                        "fingerprint": callback_fingerprint(msg_cb),
                        "buttons_text": summarize_buttons(get_callback_buttons(msg_cb)),
                        "text_preview": (msg_cb.get("text") or "")[:200]
                    }, max_logs=max_logs)
                    return msg_cb

        msg_page = find_latest_bot_message_with_page_info(bot_username)
        if msg_page and int(msg_page.get("message_id", 0)) > int(only_after_message_id):
            return msg_page

        msg_any = find_latest_bot_message_any(bot_username, require_meaningful=require_meaningful)
        if msg_any and int(msg_any.get("message_id", 0)) > int(only_after_message_id):
            return msg_any

        if iter_i % 6 == 0:
            push_log(stage="wait_first_bot_message", result="waiting", iter=iter_i, max_logs=max_logs)

        iter_i = iter_i + 1
        await asyncio.sleep(0.35)

    push_log(stage="wait_first_bot_message", result="timeout", max_logs=max_logs)
    return None

def _pagination_confirmed_last_page(state: Optional[Dict[str, Any]]) -> bool:
    """
    修正重點：
    只用 page_info 的 current/total 來「確認」最後一頁。
    page_info 缺失時不要亂猜最後一頁，避免卡在 16/47 這種狀況提早停。
    """
    if not state:
        return False

    pi = state.get("page_info") or {}
    cur = pi.get("current_page")
    total = pi.get("total_pages")
    if cur is None or total is None:
        return False

    try:
        cur_i = int(cur)
        total_i = int(total)
        if total_i <= 0:
            return False
        return cur_i >= total_i
    except Exception:
        return False



def _should_stop_by_total_items(
    enabled: bool,
    assumed_total_items: Optional[int],
    files_meta: Optional[Dict[str, Any]] = None,
    state: Optional[Dict[str, Any]] = None,
    need_confirm_done: bool = False,
    need_last_page: bool = False,
    files_unique_count: Optional[int] = None
) -> bool:
    if not enabled:
        return False
    if assumed_total_items is None:
        return False
    try:
        total_items_val = int(assumed_total_items)
    except Exception:
        return False
    if total_items_val <= 0:
        return False

    files_meta = files_meta or {}
    raw_count = int(files_meta.get("files_raw_count") or 0)
    if files_unique_count is None:
        try:
            files_unique_count = int(files_meta.get("files_unique_count") or 0)
        except Exception:
            files_unique_count = 0
    unique_count = int(files_unique_count or 0)
    count_for_total = raw_count if raw_count > 0 else unique_count

    if count_for_total < int(total_items_val):
        return False

    if need_confirm_done or need_last_page:
        return _pagination_confirmed_last_page(state)

    return True


async def _wait_for_files_or_state_change(
    bot_username: str,
    prev_state_msg_id: int = 0,
    min_message_id: int = 0,
    timeout_seconds: int = 25,
    poll_interval: float = 0.35,
    max_logs: int = 120,
    step: int = 0,
    prev_state: Optional[Dict[str, Any]] = None,
    prev_files_unique_count: Optional[int] = None,
    next_keywords: Optional[List[str]] = None,
    max_return_files: int = 0,
    max_raw_payload_bytes: int = 0
) -> Tuple[bool, str]:
    start = time.time()

    baseline_unique = 0
    if prev_files_unique_count is not None:
        try:
            baseline_unique = int(prev_files_unique_count or 0)
        except Exception:
            baseline_unique = 0
    else:
        try:
            meta0 = collect_files_from_store(
                bot_username,
                max_return_files=int(max_return_files or 0),
                max_raw_payload_bytes=int(max_raw_payload_bytes or 0),
                min_message_id=min_message_id
            )
            baseline_unique = int(meta0.get("files_unique_count", meta0.get("files_count", 0)) or 0)
        except Exception:
            baseline_unique = 0

    prev_msg = prev_state if isinstance(prev_state, dict) else None
    if prev_msg is None:
        try:
            for m in MESSAGE_STORE.values():
                if not _message_matches_bot(m, bot_username):
                    continue
                if int(m.get("message_id", 0) or 0) == int(prev_state_msg_id or 0):
                    prev_msg = m
                    break
        except Exception:
            prev_msg = None

    prev_fp = callback_fingerprint(prev_msg) if prev_msg else ""
    prev_text_preview = ((prev_msg.get("text") or "")[:260]) if prev_msg else ""
    prev_pi = None
    try:
        prev_pi = extract_page_info(prev_msg.get("text")) if prev_msg else None
    except Exception:
        prev_pi = None

    watch_next_keywords = [
        "下一頁", "下一页", "下頁", "下页",
        "Next", "next",
        ">", ">>", "»", "›", "»»",
        "▶", "►", "⏩", "→", "➡", "➡️",
        "⏭", "⏭️",
        "更多", "more", "More",
        "Forward", "forward"
    ]

    if next_keywords:
        for kw in next_keywords:
            if kw and kw not in watch_next_keywords:
                watch_next_keywords.append(kw)

    while True:
        if time.time() - start >= float(timeout_seconds or 0):
            return False, "timeout"

        await backfill_latest_from_bot(
            bot_username,
            limit=300,
            timeout_seconds=6.0,
            max_logs=max_logs,
            step=step,
            min_message_id=int(min_message_id or 0)
        )

        try:
            files_meta = collect_files_from_store(
                bot_username,
                max_return_files=int(max_return_files or 0),
                max_raw_payload_bytes=int(max_raw_payload_bytes or 0),
                min_message_id=min_message_id
            )
            files_unique_count = int(files_meta.get("files_unique_count", files_meta.get("files_count", 0)) or 0)
        except Exception:
            files_unique_count = baseline_unique

        if files_unique_count > baseline_unique:
            return True, "files_increased"

        latest_any = find_latest_bot_message_any(bot_username, require_meaningful=False)
        if latest_any:
            latest_any_mid = int(latest_any.get("message_id", 0) or 0)
            state_floor_mid = max(int(prev_state_msg_id or 0), int(min_message_id or 0))
            if latest_any_mid >= state_floor_mid and _is_bot_verification_success_message(latest_any):
                return True, "verification_success"

        new_cb = find_latest_pagination_callback_message(
            bot_username,
            next_keywords=watch_next_keywords,
            skip_invalid=True,
            min_message_id=int(min_message_id or 0)
        )

        if new_cb:
            mid = int(new_cb.get("message_id") or 0)

            if mid and mid != int(prev_state_msg_id or 0):
                return True, "state_changed"

            # 很多 bot 是「編輯同一則訊息」來更新頁碼（message_id 不變）
            if mid and mid == int(prev_state_msg_id or 0):
                fp2 = ""
                try:
                    fp2 = callback_fingerprint(new_cb)
                except Exception:
                    fp2 = ""

                if prev_fp and fp2 and fp2 != prev_fp:
                    return True, "state_edited"

                tp2 = (new_cb.get("text") or "")[:260]
                if prev_text_preview and tp2 and tp2 != prev_text_preview:
                    return True, "state_edited"

                pi2 = None
                try:
                    pi2 = extract_page_info(new_cb.get("text"))
                except Exception:
                    pi2 = None

                if prev_pi and pi2:
                    try:
                        if int(prev_pi.get("current_page")) != int(pi2.get("current_page")):
                            return True, "state_edited"
                    except Exception:
                        pass

        await asyncio.sleep(float(poll_interval or 0.35))


def _pagination_confirmed_last_page(state: Optional[Dict[str, Any]]) -> bool:
    if not state:
        return False

    pi = state.get("page_info") or {}
    cur = pi.get("current_page")
    total = pi.get("total_pages")

    if cur is not None and total is not None:
        try:
            total_i = int(total)
            cur_i = int(cur)
            if total_i > 0 and cur_i >= total_i:
                return True
            return False
        except Exception:
            return False

    buttons_text = state.get("buttons_text") or []
    if not buttons_text and state.get("buttons"):
        try:
            buttons_text = summarize_buttons(state.get("buttons") or [])
        except Exception:
            buttons_text = []

    kws = list(state.get("next_keywords") or [])
    kws.extend([
        "下一頁", "下页", "next", "Next", ">", "»", "›",
        "▶", "►", "⏩", ">>", "»»", "→", "➡", "⏭", "⏭️",
        "更多", "more"
    ])

    seen = set()
    effective: List[str] = []
    for kw in kws:
        kw2 = str(kw or "").strip()
        if not kw2:
            continue
        if kw2 in seen:
            continue
        seen.add(kw2)
        effective.append(kw2)

    for t in buttons_text:
        tn = _normalize_button_text(t)
        for kw in effective:
            if _normalize_button_text(kw) in tn:
                return False

    return False

def _extract_page_target_number(text: str) -> Optional[int]:
    """
    解析要跳的頁碼（通常是按鈕文字的純頁碼）
    修正重點：支援 🔟 / 10️⃣ / ⑩ 等 emoji 數字
    """
    if not text:
        return None

    s = _normalize_emoji_digits(str(text)).strip()
    s = s.replace("\ufe0f", "")

    # 有些按鈕可能是 "11 " 或 " 11"
    if re.fullmatch(r"\d{1,4}", s):
        return int(s)

    # 可能前面帶符號，例如 "✅11" / "✳️10"
    m = re.search(r"\b(\d{1,4})\b", s)
    if m:
        return int(m.group(1))

    # 若有全形數字或其他符號，最後再嘗試抓連續數字
    m2 = re.search(r"(\d{1,4})", s)
    if m2:
        return int(m2.group(1))

    return None

def _extract_strict_current_page_candidate(text: str) -> Optional[int]:
    """
    用更嚴格的方式判斷「目前頁碼」候選（通常是被標記的那顆按鈕）。
    修正重點：支援 🔟 / 10️⃣ / ⑩ 等 emoji 數字
    """
    if not text:
        return None

    s = _normalize_emoji_digits(str(text)).strip()
    s = s.replace("\ufe0f", "")

    # 這些符號常被用來標記目前頁（依你原本邏輯保留）
    if not any(mark in s for mark in ["✳", "★", "【", "】", "[", "]", "⬛", "■", "●"]):
        # 沒有明顯標記就不當「嚴格 current」
        return None

    m = re.search(r"(\d{1,4})", s)
    if not m:
        return None

    try:
        return int(m.group(1))
    except Exception:
        return None

def _button_current_marker_score(t: str) -> int:
    s = (t or "").strip()
    score = 0

    if "✳" in s or "⭐" in s or "🌟" in s:
        score = score + 100
    if "✅" in s:
        score = score + 40
    if "☑" in s:
        score = score + 35
    if "▶" in s or "►" in s:
        score = score + 20

    return score

def _find_best_callback_candidate(
    bot_username: str,
    next_keywords: List[str],
    max_age_seconds: int,
    scan_limit: int,
    min_message_id: int = 0
) -> Optional[Dict[str, Any]]:
    now_ms = _now_ms()
    msgs = get_all_messages_sorted()
    candidates: List[Dict[str, Any]] = []

    for m in reversed(msgs):
        if not _message_matches_bot(m, bot_username):
            continue
        buttons = get_callback_buttons(m)
        if not buttons:
            continue

        mid = int(m.get("message_id", 0))
        if int(min_message_id or 0) > 0 and mid < int(min_message_id):
            continue

        chat_id = int(m.get("chat_id", 0))
        if is_invalid_callback(bot_username, chat_id, mid):
            continue

        saved_at_ms = int(m.get("saved_at_ms", 0) or 0)
        if saved_at_ms > 0 and int(max_age_seconds or 0) > 0:
            age_s = (now_ms - saved_at_ms) / 1000.0
            if age_s > float(max_age_seconds):
                continue

        candidates.append(m)
        if len(candidates) >= int(scan_limit or 20):
            break

    if not candidates:
        return None

    best = None
    best_score = -10_000_000
    newest_mid = 0
    for m in candidates:
        try:
            newest_mid = max(newest_mid, int(m.get("message_id", 0)))
        except Exception:
            pass

    for m in candidates:
        st = _pagination_state_from_message(m, next_keywords=next_keywords) or {}
        score = 0

        mid = int(m.get("message_id", 0))
        score = score + (mid // 3)

        if bool(st.get("is_pagination_like")):
            score = score + 100
        if bool(st.get("has_next")):
            score = score + 60

        nums = st.get("numeric_set") or []
        if nums:
            score = score + min(len(nums), 10) * 6

        pi = st.get("page_info") or {}
        cur = pi.get("current_page")
        total = pi.get("total_pages")
        if cur is not None and total is not None:
            try:
                if int(cur) < int(total):
                    score = score + 40
                else:
                    score = score - 80
            except Exception:
                pass

        if newest_mid > 0:
            gap = newest_mid - mid
            if gap > 0:
                score = score - min(gap, 800) * 3

        if score > best_score:
            best_score = score
            best = m

    return best


def _choose_best_state_message(
    bot_username: str,
    next_keywords: List[str],
    max_age_seconds: int,
    scan_limit: int,
    min_message_id: int = 0
) -> Optional[Dict[str, Any]]:
    candidates: List[Dict[str, Any]] = []

    msg_page = find_latest_bot_message_with_page_info(bot_username)
    if msg_page:
        if int(min_message_id or 0) <= 0 or int(msg_page.get("message_id", 0)) >= int(min_message_id):
            candidates.append(msg_page)

    msg_pag_cb = find_latest_pagination_callback_message(
        bot_username,
        next_keywords=next_keywords,
        skip_invalid=True,
        max_age_seconds=int(max_age_seconds or 0),
        scan_limit=int(scan_limit or 0),
        min_message_id=int(min_message_id or 0)
    )
    if msg_pag_cb:
        candidates.append(msg_pag_cb)

    msg_best_cb = _find_best_callback_candidate(
        bot_username=bot_username,
        next_keywords=next_keywords,
        max_age_seconds=int(max_age_seconds or 0),
        scan_limit=int(scan_limit or 20),
        min_message_id=int(min_message_id or 0)
    )
    if msg_best_cb:
        candidates.append(msg_best_cb)

    msg_any_cb = find_latest_callback_message(bot_username, skip_invalid=True)
    if msg_any_cb:
        if int(min_message_id or 0) <= 0 or int(msg_any_cb.get("message_id", 0)) >= int(min_message_id):
            candidates.append(msg_any_cb)

    msg_any = find_latest_bot_message_any(bot_username, require_meaningful=True)
    if msg_any:
        if int(min_message_id or 0) <= 0 or int(msg_any.get("message_id", 0)) >= int(min_message_id):
            candidates.append(msg_any)

    if not candidates:
        return None

    best = None
    best_score = -10_000_000
    for m in candidates:
        st = _pagination_state_from_message(m, next_keywords=next_keywords) or {}
        mid = int(m.get("message_id", 0) or 0)

        score = 0
        score = score + mid * 10

        if bool(st.get("is_pagination_like")):
            score = score + 50000
        if bool(st.get("page_info")):
            score = score + 30000
        if bool(st.get("has_buttons")):
            score = score + 20000
        if bool(st.get("total_items")):
            score = score + 5000
        numeric_pages = st.get("numeric_pages") or []
        if numeric_pages:
            score = score + min(len(numeric_pages), 10) * 500

        if best is None or score > best_score:
            best = m
            best_score = score

    return best

async def _safe_click_pagination_button(
    bot_username: str,
    next_keywords: List[str],
    max_logs: int,
    step: int,
    max_retries: int = 2,
    wait_each_page_timeout_seconds: int = 25,
    max_return_files: int = 1000,
    max_raw_payload_bytes: int = 0,
    callback_message_max_age_seconds: int = 30,
    callback_candidate_scan_limit: int = 20,
    min_message_id: int = 0,
    msg: Optional[Dict[str, Any]] = None,
    btn: Optional[Dict[str, Any]] = None
) -> Tuple[bool, str, Optional[Dict[str, Any]]]:
    files_meta_before = collect_files_from_store(
        bot_username,
        int(max_return_files),
        int(max_raw_payload_bytes),
        min_message_id=int(min_message_id or 0)
    )
    files_unique_before = int(files_meta_before.get("files_unique_count", files_meta_before.get("files_count", 0)))

    effective_next_keywords: List[str] = []
    if next_keywords:
        for kw in next_keywords:
            if kw and kw not in effective_next_keywords:
                effective_next_keywords.append(kw)

    default_next_keywords = [
        "下一頁", "下页", "下一页",
        "Next", "next",
        ">", ">>", "»", "›", "»»",
        "▶", "►", "⏩", "→", "➡", "➡️",
        "⏭", "⏭️",
        "更多", "more", "More",
        "Forward", "forward"
    ]
    for kw in default_next_keywords:
        if kw not in effective_next_keywords:
            effective_next_keywords.append(kw)

    if msg is not None and btn is not None:
        callback_msg = msg
        chat_id = int(callback_msg.get("chat_id", 0) or 0)
        mid = int(callback_msg.get("message_id", 0) or 0)

        if is_invalid_callback(bot_username, chat_id, mid):
            return False, "invalid_callback", callback_msg

        data_hex = str(btn.get("data") or "")
        clicked_text = (btn.get("text") or "").strip()
        marker = _pagination_action_marker_from_message(callback_msg, buttons=get_callback_buttons(callback_msg))

        if data_hex and is_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker):
            await asyncio.sleep(0.6)
            return False, "recent_used", callback_msg

        try:
            await click_callback(bot_username, chat_id, mid, data_hex)
            if data_hex:
                mark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)

            changed, reason = await _wait_for_files_or_state_change(
                bot_username=bot_username,
                prev_state_msg_id=mid,
                prev_state=callback_msg,
                prev_files_unique_count=files_unique_before,
                min_message_id=int(min_message_id or 0),
                timeout_seconds=int(wait_each_page_timeout_seconds or 25),
                poll_interval=0.35,
                max_logs=max_logs,
                step=step,
                next_keywords=effective_next_keywords,
                max_return_files=int(max_return_files or 0),
                max_raw_payload_bytes=int(max_raw_payload_bytes or 0)
            )

            if not changed:
                if data_hex:
                    unmark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)
                return False, "clicked_but_no_change", callback_msg

            files_meta_after = collect_files_from_store(
                bot_username,
                int(max_return_files),
                int(max_raw_payload_bytes),
                min_message_id=int(min_message_id or 0)
            )
            files_unique_after = int(files_meta_after.get("files_unique_count", files_meta_after.get("files_count", 0)))
            if files_unique_after < files_unique_before:
                pass

            return True, clicked_text or "clicked", callback_msg

        except FloodWaitError as e:
            if data_hex:
                unmark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)
            wait_s = 0
            try:
                wait_s = int(getattr(e, "seconds", 0) or 0)
            except Exception:
                wait_s = 0
            push_log(stage="safe_click", result="flood_wait", step=step, extra={"bot_username": bot_username, "wait_seconds": wait_s}, max_logs=max_logs)
            if wait_s > 0:
                await asyncio.sleep(float(wait_s) + 1.0)
            else:
                await asyncio.sleep(1.2)
            return False, "flood_wait", callback_msg

        except MessageIdInvalidError:
            mark_invalid_callback(bot_username, chat_id, mid)
            if data_hex:
                mark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)
            await asyncio.sleep(0.9)
            return False, "invalid_callback", callback_msg

        except Exception:
            if data_hex:
                mark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)
            await asyncio.sleep(0.9)
            return False, "exception", callback_msg

    for attempt in range(1, int(max_retries or 1) + 1):
        await backfill_latest_from_bot(
            bot_username,
            limit=420,
            timeout_seconds=6.0,
            max_logs=max_logs,
            step=step,
            force=True,
            min_message_id=int(min_message_id or 0)
        )

        callback_msg = (
            find_latest_pagination_callback_message(
                bot_username,
                next_keywords=effective_next_keywords,
                skip_invalid=True,
                max_age_seconds=int(callback_message_max_age_seconds or 0),
                scan_limit=int(callback_candidate_scan_limit or 0),
                min_message_id=int(min_message_id or 0)
            )
            or _find_best_callback_candidate(
                bot_username=bot_username,
                next_keywords=effective_next_keywords,
                max_age_seconds=int(callback_message_max_age_seconds or 0),
                scan_limit=int(callback_candidate_scan_limit or 20),
                min_message_id=int(min_message_id or 0)
            )
            or find_latest_callback_message(bot_username, skip_invalid=True)
        )

        if not callback_msg:
            await asyncio.sleep(0.6)
            continue

        if not _is_pagination_controls_message(callback_msg):
            await asyncio.sleep(0.6)
            continue

        chat_id = int(callback_msg.get("chat_id", 0))
        mid = int(callback_msg.get("message_id", 0))

        if is_invalid_callback(bot_username, chat_id, mid):
            await asyncio.sleep(0.6)
            continue

        last_state = _pagination_state_from_message(callback_msg, next_keywords=effective_next_keywords)
        if _pagination_confirmed_last_page(last_state):
            return False, "already_last_page", callback_msg

        btn, pick_reason = _pick_next_page_button(callback_msg, effective_next_keywords)
        if not btn:
            if last_state and (not bool(last_state.get("has_next"))) and bool(last_state.get("is_pagination_like")):
                return False, "already_last_page", callback_msg
            await asyncio.sleep(0.6)
            continue

        data_hex = str(btn.get("data") or "")
        clicked_text = (btn.get("text") or "").strip()
        marker = _pagination_action_marker_from_message(callback_msg, buttons=get_callback_buttons(callback_msg))

        if data_hex and is_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker):
            await asyncio.sleep(0.6)
            continue

        try:
            await click_callback(bot_username, chat_id, mid, data_hex)
            if data_hex:
                mark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)

            changed, reason = await _wait_for_files_or_state_change(
                bot_username=bot_username,
                prev_state_msg_id=mid,
                prev_state=callback_msg,
                prev_files_unique_count=files_unique_before,
                min_message_id=int(min_message_id or 0),
                timeout_seconds=int(wait_each_page_timeout_seconds or 25),
                poll_interval=0.35,
                max_logs=max_logs,
                step=step,
                next_keywords=effective_next_keywords,
                max_return_files=int(max_return_files or 0),
                max_raw_payload_bytes=int(max_raw_payload_bytes or 0)
            )

            if not changed:
                if data_hex:
                    unmark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)

                # next-only bot 常見「同一顆下一頁按鈕」且偶爾回覆慢，這裡不要立刻判定失敗，改為重試
                if pick_reason == "next_keyword":
                    await asyncio.sleep(1.1)
                    continue

                return False, "clicked_but_no_change", callback_msg

            # 若檔案數量沒有增加，但狀態有變動，仍視為成功（避免某些頁沒有新檔案）
            files_meta_after = collect_files_from_store(
                bot_username,
                int(max_return_files),
                int(max_raw_payload_bytes),
                min_message_id=int(min_message_id or 0)
            )
            files_unique_after = int(files_meta_after.get("files_unique_count", files_meta_after.get("files_count", 0)))
            if files_unique_after < files_unique_before and pick_reason == "next_keyword":
                pass

            return True, clicked_text, callback_msg

        except FloodWaitError as e:
            if data_hex:
                unmark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)
            wait_s = 0
            try:
                wait_s = int(getattr(e, "seconds", 0) or 0)
            except Exception:
                wait_s = 0
            push_log(stage="safe_click", result="flood_wait", step=step, extra={"bot_username": bot_username, "wait_seconds": wait_s}, max_logs=max_logs)
            if wait_s > 0:
                await asyncio.sleep(float(wait_s) + 1.0)
            else:
                await asyncio.sleep(1.2)
            continue

        except MessageIdInvalidError:
            mark_invalid_callback(bot_username, chat_id, mid)
            if data_hex:
                mark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)
            await asyncio.sleep(0.9)
            continue

        except Exception:
            if data_hex:
                mark_recent_used_callback_action(bot_username, chat_id, mid, data_hex, marker=marker)
            await asyncio.sleep(0.9)
            continue

    return False, "no_click", None


async def _try_click_get_all_anytime(
    bot_username: str,
    get_all_keywords: List[str],
    max_logs: int,
    step: int,
    min_message_id: int = 0,
    keywords: Optional[List[str]] = None
) -> Tuple[bool, Optional[str]]:
    """
    修正：同時支援呼叫端傳入 keywords=... 或 get_all_keywords=...
    避免出現 unexpected keyword argument: 'keywords'
    """
    if (not get_all_keywords) and keywords:
        get_all_keywords = keywords

    if not get_all_keywords:
        get_all_keywords = ["获取全部", "獲取全部", "Get all", "ALL"]

    await backfill_latest_from_bot(
        bot_username,
        limit=320,
        timeout_seconds=6.0,
        max_logs=max_logs,
        step=step,
        force=True
    )

    candidates: List[Dict[str, Any]] = []
    for msg in MESSAGE_STORE.values():
        if str(msg.get("bot_username", "")).lower() != str(bot_username).lower():
            continue
        if int(msg.get("message_id", 0)) < int(min_message_id or 0):
            continue
        if msg.get("invalid_callback"):
            continue
        btns = get_callback_buttons(msg)
        if not btns:
            continue

        text = str(msg.get("text", "") or "")
        candidates.append({
            "msg": msg,
            "text": text,
            "buttons": btns
        })

    if not candidates:
        return False, None

    def contains_any_keyword(s: str, kws: List[str]) -> bool:
        ss = (s or "").lower()
        for k in kws:
            kk = str(k or "").strip().lower()
            if not kk:
                continue
            if kk in ss:
                return True
        return False

    # 由新到舊找「獲取全部」按鈕
    candidates.sort(key=lambda x: int(x["msg"].get("message_id", 0)), reverse=True)

    for cand in candidates:
        msg = cand["msg"]
        buttons = cand["buttons"]

        for row in buttons:
            for b in row:
                btn_text = str(b.get("text", "") or "")
                if contains_any_keyword(btn_text, get_all_keywords):
                    ok, _, _ = await _safe_click_pagination_button(
                        bot_username=bot_username,
                        msg=msg,
                        btn=b,
                        max_logs=max_logs,
                        step=step,
                        next_keywords=["下一頁", "下一页", "下頁", "下页", "Next", "next", "➡", "▶"],
                        min_message_id=min_message_id
                    )
                    if ok:
                        return True, btn_text
                    _mark_callback_message_invalid(msg.get("message_id"))

        # 有些 bot 按鈕文字不含關鍵字，但訊息本文含「✅ 共找到」「全部类型」等
        if contains_any_keyword(cand["text"], get_all_keywords):
            for row in buttons:
                for b in row:
                    btn_text = str(b.get("text", "") or "")
                    if btn_text:
                        ok, _, _ = await _safe_click_pagination_button(
                            bot_username=bot_username,
                            msg=msg,
                            btn=b,
                            max_logs=max_logs,
                            step=step,
                            next_keywords=["下一頁", "下一页", "下頁", "下页", "Next", "next", "➡", "▶"],
                            min_message_id=min_message_id
                        )
                        if ok:
                            return True, btn_text
                        _mark_callback_message_invalid(msg.get("message_id"))

    return False, None


async def _try_click_get_all_anytime(
    bot_username: str,
    get_all_keywords: List[str],
    max_logs: int,
    step: int,
    min_message_id: int = 0,
    keywords: Optional[List[str]] = None
) -> Tuple[bool, Optional[str]]:
    if (not get_all_keywords) and keywords:
        get_all_keywords = keywords

    if not get_all_keywords:
        get_all_keywords = ["获取全部", "獲取全部", "Get all", "ALL"]

    await backfill_latest_from_bot(
        bot_username,
        limit=320,
        timeout_seconds=6.0,
        max_logs=max_logs,
        step=step,
        force=True
    )

    candidates: List[Dict[str, Any]] = []
    for msg in MESSAGE_STORE.values():
        msg_bot_username = str(msg.get("bot_username") or msg.get("sender_username") or "").lower()
        if msg_bot_username != str(bot_username).lower():
            continue
        if int(msg.get("message_id", 0)) < int(min_message_id or 0):
            continue
        if msg.get("invalid_callback"):
            continue

        btns = get_callback_buttons(msg)
        if not btns:
            continue

        candidates.append({
            "msg": msg,
            "text": str(msg.get("text", "") or ""),
            "buttons": btns,
        })

    if not candidates:
        return False, None

    def contains_any_keyword(s: str, kws: List[str]) -> bool:
        ss = (s or "").lower()
        for k in kws:
            kk = str(k or "").strip().lower()
            if not kk:
                continue
            if kk in ss:
                return True
        return False

    next_keywords = ["下一頁", "下一页", "下頁", "下页", "Next", "next", "➡", "▶"]
    candidates.sort(key=lambda x: int(x["msg"].get("message_id", 0)), reverse=True)

    for cand in candidates:
        msg = cand["msg"]
        buttons = cand["buttons"]

        for b in buttons:
            btn_text = str(b.get("text", "") or "")
            if contains_any_keyword(btn_text, get_all_keywords):
                ok, _, _ = await _safe_click_pagination_button(
                    bot_username=bot_username,
                    msg=msg,
                    btn=b,
                    max_logs=max_logs,
                    step=step,
                    next_keywords=next_keywords,
                    min_message_id=min_message_id
                )
                if ok:
                    return True, btn_text
                _mark_callback_message_invalid(msg.get("message_id"))

        if contains_any_keyword(cand["text"], get_all_keywords):
            for b in buttons:
                btn_text = str(b.get("text", "") or "")
                if not btn_text:
                    continue
                ok, _, _ = await _safe_click_pagination_button(
                    bot_username=bot_username,
                    msg=msg,
                    btn=b,
                    max_logs=max_logs,
                    step=step,
                    next_keywords=next_keywords,
                    min_message_id=min_message_id
                )
                if ok:
                    return True, btn_text
                _mark_callback_message_invalid(msg.get("message_id"))

    return False, None

def _is_pagination_controls_message(callback_msg: Dict[str, Any]) -> bool:
    """
    判斷這個 callback message 是否真的是「分頁控制」：
    - 文字本身有可解析的 page_info（例如 1/47）
    或
    - 按鈕上有明確的「目前頁碼」高亮（例如 ✳️1、✅2）
    或
    - 按鈕上存在「下一頁/Next/➡️」等 next-like 控制鍵（支援只有下一頁按鈕的新 bot）

    這能避免把「分類選單」那種 1/2/3 按鈕誤判成分頁，導致一直按錯頁或觸發機器人回覆錯誤。
    """
    if not callback_msg:
        return False

    buttons = get_callback_buttons(callback_msg)
    if not buttons:
        return False

    pi = extract_page_info(callback_msg.get("text"))
    if pi and pi.get("current_page") is not None:
        return True

    cur = detect_current_page_from_buttons(buttons)
    if cur is not None:
        return True

    if _has_next_like_button(callback_msg, next_keywords=[
        "下一頁", "下页", "下一页",
        "Next", "next",
        ">", ">>", "»", "›", "»»",
        "▶", "►", "⏩", "→", "➡", "➡️",
        "⏭", "⏭️",
        "更多", "more", "More",
        "Forward", "forward"
    ]):
        return True

    return False


def _message_store_max_mid_for_bot(bot_username: str) -> int:
    mx = 0
    for msg in MESSAGE_STORE.values():
        if not _message_matches_bot(msg, bot_username):
            continue
        try:
            mid = int(msg.get("message_id", 0))
            if mid > mx:
                mx = mid
        except Exception:
            pass
    return mx


async def _wait_after_bootstrap(
    bot_username: str,
    seconds: int,
    max_logs: int,
    step: int,
    next_keywords: List[str],
    max_return_files: int,
    max_raw_payload_bytes: int
):
    if int(seconds or 0) <= 0:
        return

    files_meta_before = collect_files_from_store(bot_username, int(max_return_files), int(max_raw_payload_bytes))
    files_unique_before = int(files_meta_before.get("files_unique_count", files_meta_before.get("files_count", 0)))

    prev_state_msg = (
        find_latest_pagination_callback_message(bot_username, next_keywords=next_keywords, skip_invalid=True)
        or find_latest_callback_message(bot_username, skip_invalid=True)
        or find_latest_bot_message_with_page_info(bot_username)
        or find_latest_bot_message_any(bot_username, require_meaningful=True)
    )
    prev_state = _pagination_state_from_message(prev_state_msg, next_keywords=next_keywords) if prev_state_msg else None

    changed = await _wait_for_files_or_state_change(
        bot_username=bot_username,
        prev_state=prev_state,
        prev_files_unique_count=files_unique_before,
        timeout_seconds=int(seconds),
        max_logs=max_logs,
        step=step,
        next_keywords=next_keywords,
        max_return_files=int(max_return_files),
        max_raw_payload_bytes=int(max_raw_payload_bytes)
    )
    if changed:
        return

    await asyncio.sleep(0.5)


def _should_attempt_normalize_first_page(first_msg: Dict[str, Any]) -> bool:
    if not first_msg:
        return False

    first_buttons = get_callback_buttons(first_msg)
    first_pi = extract_page_info(first_msg.get("text"))
    first_is_pagelike = _is_pagination_callback_message(first_msg) if first_buttons else bool(first_pi)

    if first_is_pagelike:
        return True

    if first_buttons:
        numeric_pages = get_numeric_button_set(first_buttons)
        if len(numeric_pages) >= 2:
            return True

    if first_pi:
        return True

    return False


async def _normalize_to_first_page_if_possible(
    bot_username: str,
    max_logs: int,
    step: int,
    normalize_prev_command: str,
    normalize_max_prev_steps: int,
    next_keywords: List[str]
) -> bool:
    """
    修正：
    - 只有在「明確知道目前頁 > 1」才會嘗試回到第 1 頁，避免一直亂送上一頁或亂送 1。
    - 優先用 callback 點「頁碼 1」按鈕，避免送文字 1 造成 bot 回 💔未找到可解析内容。
    """
    try:
        await backfill_latest_from_bot(bot_username, limit=260, timeout_seconds=6.0, max_logs=max_logs, step=step, force=True)
        msg = (
            find_latest_pagination_callback_message(
                bot_username,
                next_keywords=next_keywords,
                skip_invalid=True,
                max_age_seconds=60,
                scan_limit=80,
                min_message_id=0
            )
            or find_latest_bot_message_with_page_info(bot_username)
        )
        if not msg:
            return False

        pi = extract_page_info(msg.get("text"))
        current_page = None
        if pi and pi.get("current_page") is not None:
            try:
                current_page = int(pi.get("current_page"))
            except Exception:
                current_page = None

        buttons = get_callback_buttons(msg)
        if current_page is None and buttons:
            try:
                current_page = detect_current_page_from_buttons(buttons)
            except Exception:
                current_page = None

        if current_page is None or int(current_page) <= 1:
            return True

        if buttons:
            btn1 = pick_button_by_page_number(msg, 1)
            if btn1:
                data_hex = str(btn1.get("data") or "")
                if data_hex and is_recent_used_callback_action(bot_username, int(msg.get("chat_id", 0)), int(msg.get("message_id", 0)), data_hex):
                    return True
                try:
                    await click_callback(bot_username, int(msg.get("chat_id", 0)), int(msg.get("message_id", 0)), data_hex)
                    if data_hex:
                        mark_recent_used_callback_action(bot_username, int(msg.get("chat_id", 0)), int(msg.get("message_id", 0)), data_hex)
                    await asyncio.sleep(0.8)
                    return True
                except Exception:
                    pass

        for _ in range(0, int(normalize_max_prev_steps or 0)):
            await client.send_message(bot_username, str(normalize_prev_command or "上一頁"))
            await asyncio.sleep(0.9)
            await backfill_latest_from_bot(bot_username, limit=220, timeout_seconds=6.0, max_logs=max_logs, step=step)

            msg2 = find_latest_bot_message_with_page_info(bot_username) or find_latest_pagination_callback_message(
                bot_username,
                next_keywords=next_keywords,
                skip_invalid=True,
                max_age_seconds=60,
                scan_limit=80,
                min_message_id=0
            )
            if msg2:
                pi2 = extract_page_info(msg2.get("text"))
                if pi2 and pi2.get("current_page") is not None:
                    try:
                        if int(pi2.get("current_page")) == 1:
                            return True
                    except Exception:
                        pass

        return False
    except Exception:
        return False

async def _observe_no_controls_and_collect_files(
    bot_username: str,
    observe_seconds: int,
    poll_seconds: float,
    max_logs: int,
    step: int,
    max_return_files: int,
    max_raw_payload_bytes: int,
    try_send_get_all: bool,
    get_all_command: str,
    try_send_next: bool,
    next_command: str,
    target_total_items: Optional[int] = None,
    min_message_id: int = 0
) -> Dict[str, Any]:
    start = time.time()
    sent_get_all = False
    sent_next = False

    meta0 = collect_files_from_store(
        bot_username,
        int(max_return_files),
        int(max_raw_payload_bytes),
        min_message_id=int(min_message_id or 0)
    )
    best_count = int(meta0.get("files_unique_count", meta0.get("files_count", 0)))

    while True:
        elapsed = time.time() - start
        if elapsed >= float(observe_seconds or 0):
            break

        await backfill_latest_from_bot(
            bot_username,
            limit=360,
            timeout_seconds=6.0,
            max_logs=max_logs,
            step=step,
            min_message_id=int(min_message_id or 0)
        )

        msg_control = (
            find_latest_pagination_callback_message(bot_username, next_keywords=["下一頁", "下页", "Next", "next", ">", "»", "›"], skip_invalid=True, min_message_id=int(min_message_id or 0))
            or find_latest_callback_message(bot_username, skip_invalid=True)
            or find_latest_bot_message_with_page_info(bot_username)
        )
        if msg_control:
            break

        meta = collect_files_from_store(
            bot_username,
            int(max_return_files),
            int(max_raw_payload_bytes),
            min_message_id=int(min_message_id or 0)
        )
        cur_count = int(meta.get("files_unique_count", meta.get("files_count", 0)))
        if cur_count > best_count:
            best_count = cur_count

        if target_total_items is not None:
            try:
                if int(target_total_items) > 0 and best_count >= int(target_total_items):
                    break
            except Exception:
                pass

        if try_send_get_all and (not sent_get_all) and elapsed >= 1.0 and int(best_count) <= 0:
            try:
                await client.send_message(bot_username, get_all_command)
                push_log(stage="observe_no_controls", result="sent_get_all", step=step, extra={"best_count": int(best_count), "command": get_all_command}, max_logs=max_logs)
                sent_get_all = True
            except Exception:
                pass

        if try_send_next and (not sent_next) and elapsed >= 2.0:
            try:
                await client.send_message(bot_username, next_command)
                sent_next = True
            except Exception:
                pass

        await asyncio.sleep(float(poll_seconds or 0.5))

    return collect_files_from_store(
        bot_username,
        int(max_return_files),
        int(max_raw_payload_bytes),
        min_message_id=int(min_message_id or 0)
    )


async def _continue_pagination_from_current_state(
    bot_username: str,
    next_keywords: List[str],
    max_steps: int,
    wait_each_page_timeout_seconds: int,
    max_logs: int,
    max_return_files: int,
    max_raw_payload_bytes: int,
    stop_when_no_new_files_rounds: int,
    stop_when_reached_total_items: bool,
    stop_need_confirm_pagination_done: bool,
    stop_need_last_page_or_all_pages: bool,
    callback_message_max_age_seconds: int,
    callback_candidate_scan_limit: int,
    observe_when_no_controls_poll_seconds: float,
    stop_when_total_items_exceeds: int,
    min_message_id: int,
    timeline: List[Dict[str, Any]],
    visited_pages: Set[int],
    initial_assumed_total_items: Optional[int] = None,
    start_step: int = 0
) -> Dict[str, Any]:
    steps = int(start_step or 0)
    no_new_files_rounds = 0
    last_files_unique_count = 0
    assumed_total_items: Optional[int] = None
    if initial_assumed_total_items is not None:
        try:
            assumed_total_items = int(initial_assumed_total_items)
        except Exception:
            assumed_total_items = None
    did_any_pagination_click = False
    last_clicked_page: Optional[int] = None
    last_clicked_desc = ""
    total_items_exceeded_limit = False
    total_items_exceeded_limit_value = 0

    def _result(status: str, reason: str) -> Dict[str, Any]:
        return {
            "status": status,
            "reason": reason,
            "steps": steps,
            "did_any_pagination_click": did_any_pagination_click,
            "last_clicked_page": last_clicked_page,
            "last_clicked_desc": last_clicked_desc,
            "total_items_exceeded_limit": bool(total_items_exceeded_limit),
            "total_items_exceeded_limit_value": int(total_items_exceeded_limit_value or 0),
        }

    if int(stop_when_total_items_exceeds or 0) > 0 and assumed_total_items is not None:
        try:
            parsed_assumed_total_items = int(assumed_total_items)
        except Exception:
            parsed_assumed_total_items = 0
        if parsed_assumed_total_items > int(stop_when_total_items_exceeds or 0):
            total_items_exceeded_limit = True
            total_items_exceeded_limit_value = parsed_assumed_total_items
            timeline.append({
                "step": steps,
                "status": "done",
                "reason": "reported total_items exceeded limit before pagination",
                "total_items": parsed_assumed_total_items,
                "limit": int(stop_when_total_items_exceeds or 0),
            })
            return _result("ok", "reported total_items exceeded limit before pagination")

    while steps < int(max_steps or 0):
        steps = steps + 1
        await backfill_latest_from_bot(
            bot_username,
            limit=460,
            timeout_seconds=6.0,
            max_logs=max_logs,
            step=steps,
            force=True,
            min_message_id=int(min_message_id or 0)
        )

        chosen_now = (
            find_latest_pagination_callback_message(
                bot_username,
                next_keywords=next_keywords,
                skip_invalid=True,
                max_age_seconds=int(callback_message_max_age_seconds or 0),
                scan_limit=int(callback_candidate_scan_limit or 0),
                min_message_id=int(min_message_id or 0)
            )
            or find_latest_callback_message(bot_username, skip_invalid=True)
            or _choose_best_state_message(
                bot_username=bot_username,
                next_keywords=next_keywords,
                max_age_seconds=int(callback_message_max_age_seconds or 0),
                scan_limit=int(callback_candidate_scan_limit or 0),
                min_message_id=int(min_message_id or 0)
            )
        )

        if not chosen_now:
            latest_any = find_latest_bot_message_any(bot_username, require_meaningful=False)
            if _is_bot_not_found_message(latest_any):
                return _result("ok", "not found message detected; stop")

            latest_any = find_latest_bot_message_any(bot_username, require_meaningful=True)
            if _is_bot_completion_message(latest_any):
                return _result("ok", "completion message detected; stop")

            timeline.append({"step": steps, "status": "observe", "reason": "no state message; sleep"})
            await asyncio.sleep(float(observe_when_no_controls_poll_seconds or 0.5))
            continue

        state = _pagination_state_from_message(chosen_now, next_keywords=next_keywords) or {}
        pi_now = state.get("page_info")
        total_items_now = state.get("total_items")
        has_buttons_now = bool(state.get("has_buttons")) or bool(state.get("buttons"))

        if pi_now and not has_buttons_now:
            alt_chosen_now = (
                find_latest_pagination_callback_message(
                    bot_username,
                    next_keywords=next_keywords,
                    skip_invalid=True,
                    max_age_seconds=int(callback_message_max_age_seconds or 0),
                    scan_limit=int(callback_candidate_scan_limit or 0),
                    min_message_id=int(min_message_id or 0)
                )
                or find_latest_callback_message(bot_username, skip_invalid=True)
            )
            if alt_chosen_now:
                alt_state = _pagination_state_from_message(alt_chosen_now, next_keywords=next_keywords) or {}
                alt_pi_now = alt_state.get("page_info")
                alt_has_buttons = bool(alt_state.get("has_buttons")) or bool(alt_state.get("buttons"))
                if alt_has_buttons and (bool(alt_state.get("is_pagination_like")) or bool(alt_pi_now)):
                    chosen_now = alt_chosen_now
                    state = alt_state
                    pi_now = state.get("page_info")
                    total_items_now = state.get("total_items")
                    has_buttons_now = alt_has_buttons

        buttons_now = get_callback_buttons(chosen_now)
        latest_any = find_latest_bot_message_any(bot_username, require_meaningful=True)
        if latest_any:
            latest_any_mid = int(latest_any.get("message_id", 0) or 0)
            chosen_now_mid = int(chosen_now.get("message_id", 0) or 0)
            if latest_any_mid >= chosen_now_mid and _is_bot_not_found_message(latest_any):
                return _result("ok", "not found message detected; stop")
            if latest_any_mid > chosen_now_mid and _is_bot_completion_message(latest_any):
                return _result("ok", "completion message detected; stop")

        if assumed_total_items is None and total_items_now is not None:
            try:
                assumed_total_items = int(total_items_now)
            except Exception:
                assumed_total_items = None
        if int(stop_when_total_items_exceeds or 0) > 0 and total_items_now is not None:
            try:
                parsed_total_items_now = int(total_items_now)
            except Exception:
                parsed_total_items_now = 0
            if parsed_total_items_now > int(stop_when_total_items_exceeds or 0):
                total_items_exceeded_limit = True
                total_items_exceeded_limit_value = parsed_total_items_now
                timeline.append({
                    "step": steps,
                    "status": "done",
                    "reason": "reported total_items exceeded limit before pagination",
                    "total_items": parsed_total_items_now,
                    "limit": int(stop_when_total_items_exceeds or 0),
                })
                return _result("ok", "reported total_items exceeded limit before pagination")

        if pi_now and pi_now.get("current_page") is not None:
            try:
                visited_pages.add(int(pi_now.get("current_page")))
            except Exception:
                pass

        files_meta_now = collect_files_from_store(bot_username, int(max_return_files), int(max_raw_payload_bytes))
        files_unique_now = int(files_meta_now.get("files_unique_count", files_meta_now.get("files_count", 0)))

        timeline.append({
            "step": steps,
            "status": "state",
            "message_id": chosen_now.get("message_id"),
            "chat_id": chosen_now.get("chat_id"),
            "page_info": pi_now,
            "total_items": total_items_now,
            "files_unique_count": files_unique_now,
            "has_buttons": bool(has_buttons_now),
            "buttons_text": summarize_buttons(buttons_now)
        })

        if files_unique_now <= last_files_unique_count:
            no_new_files_rounds = no_new_files_rounds + 1
        else:
            no_new_files_rounds = 0
            last_files_unique_count = files_unique_now

        if stop_when_reached_total_items and assumed_total_items is not None:
            if int(files_unique_now) >= int(assumed_total_items):
                if stop_need_confirm_pagination_done:
                    if _pagination_confirmed_last_page(state):
                        return _result("ok", "reached total items + last page confirmed; stop")
                    if stop_need_last_page_or_all_pages:
                        if _pagination_confirmed_all_pages_visited(state, visited_pages):
                            return _result("ok", "reached total items + all pages visited; stop")
                        if _pagination_confirmed_by_last_clicked_target(state, last_clicked_page):
                            return _result("ok", "reached total items after final page click; stop")
                timeline.append({"step": steps, "status": "note", "reason": "reached total items but continue clicking pages"})

        threshold_no_new = int(stop_when_no_new_files_rounds or 0)
        if threshold_no_new > 0 and int(no_new_files_rounds) >= threshold_no_new:
            if stop_need_confirm_pagination_done:
                if _pagination_confirmed_last_page(state):
                    return _result("ok", "last page confirmed; stop")
                if stop_need_last_page_or_all_pages:
                    if _pagination_confirmed_all_pages_visited(state, visited_pages):
                        return _result("ok", "all pages visited; stop")
            timeline.append({"step": steps, "status": "note", "reason": "no new files rounds reached but continue clicking pages"})

        if stop_need_confirm_pagination_done and _pagination_confirmed_last_page(state):
            return _result("ok", "last page confirmed; stop")

        ok_click, clicked_text, _used_msg = await _safe_click_pagination_button(
            bot_username=bot_username,
            next_keywords=next_keywords,
            max_logs=int(max_logs or 0),
            step=steps,
            max_retries=2,
            wait_each_page_timeout_seconds=int(wait_each_page_timeout_seconds or 0),
            max_return_files=int(max_return_files or 0),
            max_raw_payload_bytes=int(max_raw_payload_bytes or 0),
            callback_message_max_age_seconds=int(callback_message_max_age_seconds or 0),
            callback_candidate_scan_limit=int(callback_candidate_scan_limit or 0),
            min_message_id=int(min_message_id or 0)
        )

        if not ok_click:
            if clicked_text == "clicked_but_no_change":
                timeline.append({"step": steps, "status": "note", "reason": "clicked_but_no_change; retry"})
                await asyncio.sleep(0.35)
                continue

            try:
                if clicked_text == "no_click" and bool(state.get("has_next")):
                    timeline.append({"step": steps, "status": "note", "reason": "no_click but has_next; retry"})
                    await asyncio.sleep(0.35)
                    continue
            except Exception:
                pass

            return _result("ok", str(clicked_text or "pagination stopped"))

        did_any_pagination_click = True
        if clicked_text:
            last_clicked_desc = str(clicked_text)
            clicked_page_int = extract_first_int(clicked_text)
            if clicked_page_int is not None:
                try:
                    last_clicked_page = int(clicked_page_int)
                except Exception:
                    pass
            timeline.append({"step": steps, "status": "clicked", "clicked": clicked_text})

        await asyncio.sleep(0.2)

    return _result("ok", "max_steps reached")


@app.post("/bots/send-and-run-all-pages")
async def send_and_run_all_pages(payload: SendAndRunAllPagesRequest):
    if payload.clear_previous_replies:
        clear_all_replies()
        clear_invalid_callback_cache()
        if payload.debug:
            DEBUG_LOGS.clear()

    push_log(stage="run_start", result="begin", extra=_json_sanitize(payload.dict()), max_logs=payload.debug_max_logs)

    timeline: List[Dict[str, Any]] = []
    steps = 0
    visited_pages: Set[int] = set()
    did_any_pagination_click = False
    did_bootstrap_click = False
    last_clicked_page: Optional[int] = None
    last_clicked_desc: str = ""
    cleanup_min_mid = 0
    cleanup_max_mid = 0
    background_cleanup_enabled = True
    skip_cleanup_due_to_files = False
    skip_cleanup_due_to_size_limit = False
    skip_cleanup_files_count = 0
    skip_cleanup_total_bytes = 0
    total_items_exceeded_limit = False
    total_items_exceeded_limit_value = 0
    total_items_exceeded_limit_limit = max(int(payload.stop_when_total_items_exceeds or 0), 0)
    download_job_id = uuid.uuid4().hex
    folder_path = ""
    DOWNLOAD_JOBS[download_job_id] = {
        "status": "pending",
        "created_at_s": _now_s(),
        "bot_username": payload.bot_username,
        "folder_path": folder_path,
        "base_name": payload.text,
        "total": 0,
        "done": 0,
        "failed": 0,
        "last_saved_path": None,
        "last_error": None,
    }

    def _ensure_download_job_folder() -> str:
        nonlocal folder_path
        if folder_path:
            return folder_path
        folder_path = _ensure_download_folder_for_text(payload.text)
        job = DOWNLOAD_JOBS.get(download_job_id) or {}
        job["folder_path"] = folder_path
        DOWNLOAD_JOBS[download_job_id] = job
        return folder_path

    def _attach_files(resp: Dict[str, Any]) -> Dict[str, Any]:
        if not payload.include_files_in_response:
            return resp
        meta = collect_files_from_store(payload.bot_username, int(payload.max_return_files), int(payload.max_raw_payload_bytes))
        files_total_bytes = 0
        for one in meta.get("files") or []:
            try:
                files_total_bytes += int(one.get("file_size", 0) or 0)
            except Exception:
                pass
        resp["files"] = meta["files"]
        resp["files_count"] = meta["files_count"]
        resp["files_unique_count"] = meta.get("files_unique_count", meta["files_count"])
        resp["files_raw_count"] = meta.get("files_raw_count", meta["files_count"])
        resp["files_truncated"] = meta["files_truncated"]
        resp["files_total_bytes"] = int(files_total_bytes)
        resp["page_state"] = {
            "visited_pages": sorted(list(visited_pages)),
            "did_any_pagination_click": bool(did_any_pagination_click),
            "did_bootstrap_click": bool(did_bootstrap_click),
            "last_clicked_page": last_clicked_page,
            "last_clicked_desc": last_clicked_desc
        }
        resp["download"] = {"job_id": download_job_id, "folder_path": folder_path or None, "base_name": payload.text}
        return resp

    def _freeze_cleanup_window() -> None:
        nonlocal cleanup_max_mid
        lower_bound = int(cleanup_min_mid or 0)
        if lower_bound <= 0:
            cleanup_max_mid = 0
            return

        cleanup_max_mid = max(lower_bound, int(_message_store_max_mid_for_bot(payload.bot_username) or 0))

    async def _maybe_cleanup():
        if not payload.cleanup_after_done or not background_cleanup_enabled:
            return
        files_meta_for_cleanup = collect_files_from_store(
            payload.bot_username,
            int(payload.max_return_files or 0),
            int(payload.max_raw_payload_bytes or 0),
            min_message_id=int(cleanup_min_mid or 0),
        )
        current_run_files_count = int(files_meta_for_cleanup.get("files_unique_count", files_meta_for_cleanup.get("files_count", 0)) or 0)
        if (not payload.wait_download_completion) and (current_run_files_count > 0 or skip_cleanup_due_to_files):
            push_log(
                stage="cleanup",
                result="preserve_files_skip",
                extra={
                    "bot_username": payload.bot_username,
                    "files_unique_count": max(int(skip_cleanup_files_count or 0), current_run_files_count),
                    "cleanup_min_mid": int(cleanup_min_mid or 0),
                    "cleanup_max_mid": int(cleanup_max_mid or 0),
                },
                max_logs=int(payload.debug_max_logs or 0),
            )
            return
        try:
            if int(cleanup_min_mid or 0) <= 0:
                return
            await _cleanup_chat_after_run(
                payload.bot_username,
                int(cleanup_min_mid or 0),
                str(payload.cleanup_scope or "run"),
                int(payload.cleanup_limit or 500),
                max_mid=int(cleanup_max_mid or 0),
                preserve_file_messages=not bool(payload.wait_download_completion),
                max_logs=int(payload.debug_max_logs or 0),
            )
        except Exception as e:
            push_log(stage="cleanup", result="exception", extra={"error": str(e), "trace": traceback.format_exc()[:900]})

    async def _prepare_download_job_and_maybe_cleanup() -> Dict[str, Any]:
        nonlocal skip_cleanup_due_to_size_limit, skip_cleanup_total_bytes
        files_meta = collect_files_from_store(
            payload.bot_username,
            int(payload.max_return_files or 0),
            int(payload.max_raw_payload_bytes or 0),
            min_message_id=int(cleanup_min_mid or 0),
        )
        files = files_meta.get("files") or []
        total_bytes = 0
        for one in files:
            try:
                total_bytes += int(one.get("file_size", 0) or 0)
            except Exception:
                pass
        job = DOWNLOAD_JOBS.get(download_job_id) or {}
        job["total"] = len(files)
        job["total_bytes"] = int(total_bytes)
        job["status"] = "queued" if files else "done"
        DOWNLOAD_JOBS[download_job_id] = job

        if not bool(payload.download_after_done):
            job["status"] = "disabled"
            job["reason"] = "download_disabled"
            DOWNLOAD_JOBS[download_job_id] = job
            await _maybe_cleanup()
            return {
                "mode": "disabled",
                "files": [],
                "files_count": len(files),
                "total_bytes": int(total_bytes),
            }

        download_limit_bytes = int(payload.skip_download_if_total_bytes_exceeds or 0)
        if files and download_limit_bytes > 0 and int(total_bytes) > download_limit_bytes:
            skip_cleanup_due_to_size_limit = True
            skip_cleanup_total_bytes = int(total_bytes)
            job["status"] = "skipped_size_limit"
            job["reason"] = "total_bytes_exceeded"
            job["download_skipped"] = True
            job["download_skipped_limit_bytes"] = download_limit_bytes
            DOWNLOAD_JOBS[download_job_id] = job
            push_log(
                stage="download_local",
                result="skipped_size_limit",
                extra={
                    "job_id": download_job_id,
                    "files_total_bytes": int(total_bytes),
                    "download_limit_bytes": download_limit_bytes,
                    "files_count": len(files),
                },
                max_logs=int(payload.debug_max_logs or 0),
            )
            await _maybe_cleanup()
            return {
                "mode": "skipped_size_limit",
                "files": [],
                "files_count": len(files),
                "total_bytes": int(total_bytes),
            }

        if not files:
            await _maybe_cleanup()
            return {
                "mode": "done",
                "files": [],
                "files_count": 0,
                "total_bytes": int(total_bytes),
            }

        return {
            "mode": "queued",
            "files": files,
            "files_count": len(files),
            "total_bytes": int(total_bytes),
        }

    async def _run_prepared_download_and_cleanup(prepared: Dict[str, Any]) -> None:
        files = prepared.get("files") or []
        if not files:
            await _maybe_cleanup()
            return

        try:
            actual_folder_path = _ensure_download_job_folder()
            await _background_download_files(
                payload.bot_username,
                files,
                actual_folder_path,
                payload.text,
                download_job_id,
                slow_seconds=0.8,
            )
        finally:
            await _maybe_cleanup()

    def _mark_total_items_limit_exceeded(total_items_value: Optional[int]) -> None:
        nonlocal total_items_exceeded_limit, total_items_exceeded_limit_value
        if int(total_items_exceeded_limit_limit or 0) <= 0:
            return
        try:
            parsed_value = int(total_items_value or 0)
        except Exception:
            parsed_value = 0
        if parsed_value <= int(total_items_exceeded_limit_limit or 0):
            return
        total_items_exceeded_limit = True
        total_items_exceeded_limit_value = parsed_value

    def _maybe_mark_total_items_from_get_all_buttons(buttons: List[Dict[str, Any]]) -> None:
        if int(total_items_exceeded_limit_limit or 0) <= 0:
            return
        for button in buttons or []:
            text = str(button.get("text") or "").strip()
            if not text:
                continue
            lowered = text.lower()
            if not any(str(keyword or "").strip().lower() in lowered for keyword in (payload.bootstrap_get_all_keywords or [])):
                continue
            _mark_total_items_limit_exceeded(extract_first_int(text))
            if total_items_exceeded_limit:
                return

    async def _return_ok(reason: str) -> Dict[str, Any]:
        nonlocal skip_cleanup_due_to_files, skip_cleanup_due_to_size_limit, skip_cleanup_files_count, skip_cleanup_total_bytes
        timeline.append({"step": steps, "status": "done", "reason": reason})
        _freeze_cleanup_window()
        resp = _attach_files({"status": "ok", "reason": reason, "steps": steps, "timeline": timeline})
        resp["sent_message_id"] = int(cleanup_min_mid or 0)
        resp = _attach_bot_result_snapshot(
            resp,
            bot_username=payload.bot_username,
            include_files=bool(payload.include_files_in_response),
            max_return_files=int(payload.max_return_files or 0),
            max_raw_payload_bytes=int(payload.max_raw_payload_bytes or 0),
            min_message_id=int(cleanup_min_mid or 0)
        )
        skip_cleanup_files_count = int(resp.get("files_unique_count", 0) or 0)
        skip_cleanup_total_bytes = int(resp.get("files_total_bytes", 0) or 0)
        skip_cleanup_due_to_files = skip_cleanup_files_count > 0
        prepared_download = await _prepare_download_job_and_maybe_cleanup()
        download_resp = resp.get("download") if isinstance(resp.get("download"), dict) else {}
        download_resp["status"] = str(prepared_download.get("mode") or "")
        download_resp["total"] = int(prepared_download.get("files_count", 0) or 0)
        download_resp["files_total_bytes"] = int(prepared_download.get("total_bytes", 0) or 0)
        if str(prepared_download.get("mode") or "") == "queued":
            download_resp["folder_path"] = _ensure_download_job_folder()
        resp["download"] = download_resp
        if str(prepared_download.get("mode") or "") == "queued":
            if payload.wait_download_completion:
                await _run_prepared_download_and_cleanup(prepared_download)
            else:
                asyncio.create_task(_run_prepared_download_and_cleanup(prepared_download))
        if skip_cleanup_due_to_size_limit:
            download_resp["skipped"] = True
            download_resp["reason"] = "total_bytes_exceeded"
            download_resp["limit_bytes"] = int(payload.skip_download_if_total_bytes_exceeds or 0)
            download_resp["files_total_bytes"] = int(skip_cleanup_total_bytes or 0)
            resp["download"] = download_resp
            resp["download_skipped"] = True
            resp["download_skipped_reason"] = "total_bytes_exceeded"
            resp["download_skipped_limit_bytes"] = int(payload.skip_download_if_total_bytes_exceeds or 0)
        resp["total_items_exceeded_limit"] = bool(total_items_exceeded_limit)
        resp["total_items_exceeded_limit_value"] = int(total_items_exceeded_limit_value or 0)
        resp["total_items_exceeded_limit_limit"] = int(total_items_exceeded_limit_limit or 0)
        return resp

    async def _return_fail(reason: str, error: Optional[str] = None) -> Dict[str, Any]:
        resp: Dict[str, Any] = {"status": "fail", "reason": reason, "steps": steps, "timeline": timeline}
        if error:
            resp["error"] = error
        _freeze_cleanup_window()
        resp = _attach_files(resp)
        resp["sent_message_id"] = int(cleanup_min_mid or 0)
        resp = _attach_bot_result_snapshot(
            resp,
            bot_username=payload.bot_username,
            include_files=bool(payload.include_files_in_response),
            max_return_files=int(payload.max_return_files or 0),
            max_raw_payload_bytes=int(payload.max_raw_payload_bytes or 0),
            min_message_id=int(cleanup_min_mid or 0)
        )
        prepared_download = await _prepare_download_job_and_maybe_cleanup()
        download_resp = resp.get("download") if isinstance(resp.get("download"), dict) else {}
        download_resp["status"] = str(prepared_download.get("mode") or "")
        download_resp["total"] = int(prepared_download.get("files_count", 0) or 0)
        download_resp["files_total_bytes"] = int(prepared_download.get("total_bytes", 0) or 0)
        if str(prepared_download.get("mode") or "") == "queued":
            download_resp["folder_path"] = _ensure_download_job_folder()
        resp["download"] = download_resp
        if str(prepared_download.get("mode") or "") == "queued":
            if payload.wait_download_completion:
                await _run_prepared_download_and_cleanup(prepared_download)
            else:
                asyncio.create_task(_run_prepared_download_and_cleanup(prepared_download))
        if skip_cleanup_due_to_size_limit:
            download_resp["skipped"] = True
            download_resp["reason"] = "total_bytes_exceeded"
            download_resp["limit_bytes"] = int(payload.skip_download_if_total_bytes_exceeds or 0)
            download_resp["files_total_bytes"] = int(skip_cleanup_total_bytes or 0)
            resp["download"] = download_resp
            resp["download_skipped"] = True
            resp["download_skipped_reason"] = "total_bytes_exceeded"
            resp["download_skipped_limit_bytes"] = int(payload.skip_download_if_total_bytes_exceeds or 0)
        resp["total_items_exceeded_limit"] = bool(total_items_exceeded_limit)
        resp["total_items_exceeded_limit_value"] = int(total_items_exceeded_limit_value or 0)
        resp["total_items_exceeded_limit_limit"] = int(total_items_exceeded_limit_limit or 0)
        return resp

    async def _sleep_after_pagination_click() -> None:
        try:
            delay_s = float(payload.delay_seconds or 0)
        except Exception:
            delay_s = 0.0
        if delay_s > 0:
            await asyncio.sleep(delay_s)

    try:
        await backfill_latest_from_bot(payload.bot_username, limit=120, timeout_seconds=6.0, max_logs=payload.debug_max_logs, step=0, force=True)
        before_mid_pre_send = _message_store_max_mid_for_bot(payload.bot_username)

        sent = await client.send_message(payload.bot_username, payload.text)
        try:
            cleanup_min_mid = int(getattr(sent, "id", 0) or 0)
        except Exception:
            cleanup_min_mid = 0
        if int(cleanup_min_mid or 0) <= 0:
            cleanup_min_mid = int(before_mid_pre_send or 0)
        push_log(
            stage="run_send",
            result="sent",
            step=0,
            extra={
                "bot_username": payload.bot_username,
                "text": str(payload.text or "")[:180],
                "before_mid_pre_send": int(before_mid_pre_send or 0),
                "sent_message_id": int(getattr(sent, "id", 0) or 0),
                "cleanup_min_mid": int(cleanup_min_mid or 0),
                "store_recent": _recent_store_snapshot(payload.bot_username, limit=6, min_message_id=max(int(cleanup_min_mid or 0) - 3, 0)),
            },
            max_logs=payload.debug_max_logs,
        )

        first_any = await wait_for_first_bot_message(
            bot_username=payload.bot_username,
            timeout_seconds=int(payload.wait_first_callback_timeout_seconds),
            max_logs=payload.debug_max_logs,
            prefer_callback=True,
            only_after_message_id=int(cleanup_min_mid or 0) - 1,
            require_meaningful=True
        )
        if not first_any:
            return await _return_fail("timeout waiting for first bot message after sending")
        push_log(
            stage="run_first_message",
            result="received",
            step=0,
            extra={
                "bot_username": payload.bot_username,
                "first_message": _message_debug_summary(first_any),
                "store_recent": _recent_store_snapshot(payload.bot_username, limit=8, min_message_id=max(int(cleanup_min_mid or 0) - 3, 0)),
            },
            max_logs=payload.debug_max_logs,
        )

        if _is_bot_not_found_message(first_any):
            background_cleanup_enabled = False
            await _cleanup_not_found_messages(
                payload.bot_username,
                first_any,
                min_message_id=int(cleanup_min_mid or 0),
                sent_message_id=int(cleanup_min_mid or 0)
            )
            timeline.append({
                "step": 0,
                "status": "done",
                "reason": "not found message detected; stop",
                "message_id": first_any.get("message_id"),
                "text_preview": (first_any.get("text") or "")[:200]
            })
            return await _return_ok("not found message detected; stop")

        await backfill_latest_from_bot(payload.bot_username, limit=360, timeout_seconds=6.0, max_logs=payload.debug_max_logs, step=0, force=True)

        chosen_first = _choose_best_state_message(
            bot_username=payload.bot_username,
            next_keywords=payload.next_text_keywords,
            max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
            scan_limit=int(payload.callback_candidate_scan_limit or 20),
            min_message_id=int(cleanup_min_mid or 0)
        ) or first_any

        first_buttons = get_callback_buttons(chosen_first)
        first_pi = extract_page_info(chosen_first.get("text"))
        first_total_items = extract_total_items(chosen_first.get("text"))
        first_is_pagelike = _is_pagination_callback_message(chosen_first, next_keywords=payload.next_text_keywords) if first_buttons else bool(first_pi)

        if first_pi and first_pi.get("current_page"):
            try:
                visited_pages.add(int(first_pi.get("current_page")))
            except Exception:
                pass

        timeline.append({
            "step": 0,
            "status": "first_message_chosen",
            "message_id": chosen_first.get("message_id"),
            "chat_id": chosen_first.get("chat_id"),
            "has_buttons": bool(first_buttons),
            "is_pagination_like": bool(first_is_pagelike),
            "page_info": first_pi,
            "total_items": first_total_items,
            "buttons_text": summarize_buttons(first_buttons),
            "text_preview": (chosen_first.get("text") or "")[:200]
        })

        if _is_bot_not_found_message(chosen_first):
            background_cleanup_enabled = False
            await _cleanup_not_found_messages(
                payload.bot_username,
                chosen_first,
                min_message_id=int(cleanup_min_mid or 0),
                sent_message_id=int(cleanup_min_mid or 0)
            )
            return await _return_ok("not found message detected; stop")

        _mark_total_items_limit_exceeded(first_total_items)
        _maybe_mark_total_items_from_get_all_buttons(first_buttons)
        if total_items_exceeded_limit:
            timeline.append({
                "step": 0,
                "status": "done",
                "reason": "reported total_items exceeded limit before pagination",
                "total_items": first_total_items,
                "limit": int(total_items_exceeded_limit_limit or 0),
            })
            return await _return_ok("reported total_items exceeded limit before pagination")

        clicked_get_all = False
        seed_total_items: Optional[int] = first_total_items
        if payload.bootstrap_click_get_all and not did_bootstrap_click:
            ok_get_all, clicked_text = await _try_click_get_all_anytime(
                bot_username=payload.bot_username,
                get_all_keywords=payload.bootstrap_get_all_keywords,
                max_logs=payload.debug_max_logs,
                step=0,
                min_message_id=int(cleanup_min_mid or 0)
            )
            if ok_get_all:
                clicked_get_all = True
                did_bootstrap_click = True
                if seed_total_items is None:
                    try:
                        inferred_total = extract_first_int(clicked_text or "")
                        if inferred_total is not None and int(inferred_total) > 0:
                            seed_total_items = int(inferred_total)
                    except Exception:
                        pass
                timeline.append({"step": 0, "status": "bootstrap_clicked_anytime", "clicked_text": clicked_text})
                _mark_total_items_limit_exceeded(seed_total_items)
                if total_items_exceeded_limit:
                    timeline.append({
                        "step": 0,
                        "status": "done",
                        "reason": "reported total_items exceeded limit before pagination",
                        "total_items": int(seed_total_items or 0),
                        "limit": int(total_items_exceeded_limit_limit or 0),
                    })
                    return await _return_ok("reported total_items exceeded limit before pagination")
                await asyncio.sleep(0.8)
                await backfill_latest_from_bot(payload.bot_username, limit=420, timeout_seconds=6.0, max_logs=payload.debug_max_logs, step=0, force=True)
                await _wait_after_bootstrap(payload.bot_username, int(payload.wait_after_bootstrap_timeout_seconds), payload.debug_max_logs, 0, payload.next_text_keywords, int(payload.max_return_files), int(payload.max_raw_payload_bytes))

        if payload.normalize_to_first_page_when_no_buttons:
            should_norm = _should_attempt_normalize_first_page(chosen_first)
            if should_norm and not did_bootstrap_click:
                await _normalize_to_first_page_if_possible(payload.bot_username, payload.debug_max_logs, 0, payload.normalize_prev_command, payload.normalize_max_prev_steps, payload.next_text_keywords)
                await asyncio.sleep(0.7)
                await backfill_latest_from_bot(payload.bot_username, limit=320, timeout_seconds=6.0, max_logs=payload.debug_max_logs, step=0, force=True)

        if (not clicked_get_all) and (first_pi is None) and (not first_is_pagelike):
            if int(payload.initial_wait_for_controls_seconds or 0) > 0:
                later = await wait_for_first_bot_message(
                    bot_username=payload.bot_username,
                    timeout_seconds=int(payload.initial_wait_for_controls_seconds),
                    max_logs=payload.debug_max_logs,
                    prefer_callback=True,
                    only_after_message_id=int(chosen_first.get("message_id", 0) or 0),
                    require_meaningful=True
                )
                if later:
                    await backfill_latest_from_bot(payload.bot_username, limit=360, timeout_seconds=6.0, max_logs=payload.debug_max_logs, step=0, force=True)
                    chosen_first2 = _choose_best_state_message(
                        bot_username=payload.bot_username,
                        next_keywords=payload.next_text_keywords,
                        max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                        scan_limit=int(payload.callback_candidate_scan_limit or 20),
                        min_message_id=int(cleanup_min_mid or 0)
                    ) or later

                    first_buttons = get_callback_buttons(chosen_first2)
                    first_pi = extract_page_info(chosen_first2.get("text"))
                    first_total_items = extract_total_items(chosen_first2.get("text"))
                    first_is_pagelike = _is_pagination_callback_message(chosen_first2, next_keywords=payload.next_text_keywords) if first_buttons else bool(first_pi)

                    timeline.append({
                        "step": 0,
                        "status": "first_message_rechecked",
                        "message_id": chosen_first2.get("message_id"),
                        "chat_id": chosen_first2.get("chat_id"),
                        "has_buttons": bool(first_buttons),
                        "is_pagination_like": bool(first_is_pagelike),
                        "page_info": first_pi,
                        "total_items": first_total_items,
                        "buttons_text": summarize_buttons(first_buttons),
                        "text_preview": (chosen_first2.get("text") or "")[:200]
                    })
                    chosen_first = chosen_first2
                    _mark_total_items_limit_exceeded(first_total_items)
                    _maybe_mark_total_items_from_get_all_buttons(first_buttons)
                    if total_items_exceeded_limit:
                        timeline.append({
                            "step": 0,
                            "status": "done",
                            "reason": "reported total_items exceeded limit before pagination",
                            "total_items": first_total_items,
                            "limit": int(total_items_exceeded_limit_limit or 0),
                        })
                        return await _return_ok("reported total_items exceeded limit before pagination")

            if (first_pi is None) and (not first_is_pagelike) and (not clicked_get_all):
                meta_observed = await _observe_no_controls_and_collect_files(
                    bot_username=payload.bot_username,
                    observe_seconds=int(payload.observe_when_no_controls_seconds or 0),
                    poll_seconds=float(payload.observe_when_no_controls_poll_seconds or 0.5),
                    max_logs=payload.debug_max_logs,
                    step=0,
                    max_return_files=int(payload.max_return_files),
                    max_raw_payload_bytes=int(payload.max_raw_payload_bytes),
                    try_send_get_all=bool(payload.observe_send_get_all_when_no_controls),
                    get_all_command=str(payload.observe_get_all_command or "獲取全部"),
                    try_send_next=bool(payload.observe_send_next_when_no_controls),
                    next_command=str(payload.text_next_command or "下一頁"),
                    target_total_items=first_total_items
                )
                files_unique_count_observed = int(meta_observed.get("files_unique_count", meta_observed.get("files_count", 0)))
                if files_unique_count_observed > 0:
                    await asyncio.sleep(1.2)
                    await backfill_latest_from_bot(
                        payload.bot_username,
                        limit=360,
                        timeout_seconds=6.0,
                        max_logs=payload.debug_max_logs,
                        step=0,
                        force=True
                    )
                    late_state = _choose_best_state_message(
                        bot_username=payload.bot_username,
                        next_keywords=payload.next_text_keywords,
                        max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                        scan_limit=int(payload.callback_candidate_scan_limit or 20),
                        min_message_id=int(cleanup_min_mid or 0)
                    )
                    if late_state:
                        late_buttons = get_callback_buttons(late_state)
                        late_pi = extract_page_info(late_state.get("text"))
                        late_is_pagelike = _is_pagination_callback_message(late_state, next_keywords=payload.next_text_keywords) if late_buttons else bool(late_pi)
                        if late_is_pagelike or late_pi or late_buttons:
                            chosen_first = late_state
                            first_buttons = late_buttons
                            first_pi = late_pi
                            first_total_items = extract_total_items(late_state.get("text"))
                            first_is_pagelike = late_is_pagelike
                            if first_pi and first_pi.get("current_page"):
                                try:
                                    visited_pages.add(int(first_pi.get("current_page")))
                                except Exception:
                                    pass
                            timeline.append({
                                "step": 0,
                                "status": "late_state_detected_after_file_observe",
                                "message_id": late_state.get("message_id"),
                                "chat_id": late_state.get("chat_id"),
                                "has_buttons": bool(late_buttons),
                                "is_pagination_like": bool(late_is_pagelike),
                                "page_info": late_pi,
                                "total_items": first_total_items,
                                "buttons_text": summarize_buttons(late_buttons),
                                "text_preview": (late_state.get("text") or "")[:200]
                            })
                            _mark_total_items_limit_exceeded(first_total_items)
                            _maybe_mark_total_items_from_get_all_buttons(late_buttons)
                            if total_items_exceeded_limit:
                                timeline.append({
                                    "step": 0,
                                    "status": "done",
                                    "reason": "reported total_items exceeded limit before pagination",
                                    "total_items": first_total_items,
                                    "limit": int(total_items_exceeded_limit_limit or 0),
                                })
                                return await _return_ok("reported total_items exceeded limit before pagination")
                            loop_result = await _continue_pagination_from_current_state(
                                bot_username=payload.bot_username,
                                next_keywords=payload.next_text_keywords,
                                max_steps=int(payload.max_steps or 0),
                                wait_each_page_timeout_seconds=int(payload.wait_each_page_timeout_seconds),
                                max_logs=int(payload.debug_max_logs or 0),
                                max_return_files=int(payload.max_return_files or 0),
                                max_raw_payload_bytes=int(payload.max_raw_payload_bytes or 0),
                                stop_when_no_new_files_rounds=int(payload.stop_when_no_new_files_rounds or 0),
                                stop_when_reached_total_items=bool(payload.stop_when_reached_total_items),
                                stop_need_confirm_pagination_done=bool(payload.stop_need_confirm_pagination_done),
                                stop_need_last_page_or_all_pages=bool(payload.stop_need_last_page_or_all_pages),
                                callback_message_max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                                callback_candidate_scan_limit=int(payload.callback_candidate_scan_limit or 0),
                                observe_when_no_controls_poll_seconds=float(payload.observe_when_no_controls_poll_seconds or 0.5),
                                stop_when_total_items_exceeds=int(payload.stop_when_total_items_exceeds or 0),
                                min_message_id=int(cleanup_min_mid or 0),
                                timeline=timeline,
                                visited_pages=visited_pages,
                                initial_assumed_total_items=seed_total_items,
                            )
                            if bool(loop_result.get("total_items_exceeded_limit")):
                                total_items_exceeded_limit = True
                                total_items_exceeded_limit_value = int(loop_result.get("total_items_exceeded_limit_value") or 0)
                            if loop_result["status"] == "ok":
                                return await _return_ok(loop_result["reason"])
                            return await _return_fail(loop_result["reason"], loop_result.get("error"))
                if first_total_items is not None:
                    try:
                        if int(first_total_items) > 0 and files_unique_count_observed >= int(first_total_items):
                            return await _return_ok("no page_info and no buttons; observed files collected to total_items")
                    except Exception:
                        pass
                if files_unique_count_observed > 0:
                    return await _return_ok("no page_info and no buttons; observed files collected")
                return await _return_ok("no page_info and no get_all; return current files")

        loop_result = await _continue_pagination_from_current_state(
            bot_username=payload.bot_username,
            next_keywords=payload.next_text_keywords,
            max_steps=int(payload.max_steps or 0),
            wait_each_page_timeout_seconds=int(payload.wait_each_page_timeout_seconds),
            max_logs=int(payload.debug_max_logs or 0),
            max_return_files=int(payload.max_return_files or 0),
            max_raw_payload_bytes=int(payload.max_raw_payload_bytes or 0),
            stop_when_no_new_files_rounds=int(payload.stop_when_no_new_files_rounds or 0),
            stop_when_reached_total_items=bool(payload.stop_when_reached_total_items),
            stop_need_confirm_pagination_done=bool(payload.stop_need_confirm_pagination_done),
            stop_need_last_page_or_all_pages=bool(payload.stop_need_last_page_or_all_pages),
            callback_message_max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
            callback_candidate_scan_limit=int(payload.callback_candidate_scan_limit or 0),
            observe_when_no_controls_poll_seconds=float(payload.observe_when_no_controls_poll_seconds or 0.5),
            stop_when_total_items_exceeds=int(payload.stop_when_total_items_exceeds or 0),
            min_message_id=int(cleanup_min_mid or 0),
            timeline=timeline,
            visited_pages=visited_pages,
            initial_assumed_total_items=seed_total_items,
            start_step=steps
        )
        if bool(loop_result.get("total_items_exceeded_limit")):
            total_items_exceeded_limit = True
            total_items_exceeded_limit_value = int(loop_result.get("total_items_exceeded_limit_value") or 0)
        steps = int(loop_result.get("steps", steps) or steps)
        did_any_pagination_click = bool(loop_result.get("did_any_pagination_click")) or did_any_pagination_click
        if loop_result.get("last_clicked_desc"):
            last_clicked_desc = str(loop_result.get("last_clicked_desc"))
        if loop_result.get("last_clicked_page") is not None:
            try:
                last_clicked_page = int(loop_result.get("last_clicked_page"))
            except Exception:
                pass

        if str(loop_result.get("status") or "ok").lower() == "ok":
            return await _return_ok(str(loop_result.get("reason") or "pagination done"))

        if payload.allow_ok_when_no_buttons:
            return await _return_ok(str(loop_result.get("reason") or "pagination stopped"))

        return await _return_fail(
            str(loop_result.get("reason") or "pagination stopped"),
            str(loop_result.get("error") or "") or None
        )

        no_new_files_rounds = 0
        last_files_unique_count = 0
        assumed_total_items: Optional[int] = None
        invalid_callback_rounds = 0

        while steps < int(payload.max_steps or 0):
            steps = steps + 1
            await backfill_latest_from_bot(payload.bot_username, limit=460, timeout_seconds=6.0, max_logs=payload.debug_max_logs, step=steps)

            if payload.bootstrap_click_get_all and not did_bootstrap_click:
                ok_get_all, clicked_text = await _try_click_get_all_anytime(
                    bot_username=payload.bot_username,
                    get_all_keywords=payload.bootstrap_get_all_keywords,
                    max_logs=payload.debug_max_logs,
                    step=steps,
                    min_message_id=int(cleanup_min_mid or 0)
                )
                if ok_get_all:
                    did_bootstrap_click = True
                    timeline.append({"step": steps, "status": "bootstrap_clicked_anytime", "clicked_text": clicked_text})
                    await asyncio.sleep(0.8)
                    await backfill_latest_from_bot(payload.bot_username, limit=420, timeout_seconds=6.0, max_logs=payload.debug_max_logs, step=steps, force=True)
                    await _wait_after_bootstrap(payload.bot_username, int(payload.wait_after_bootstrap_timeout_seconds), payload.debug_max_logs, steps, payload.next_text_keywords, int(payload.max_return_files), int(payload.max_raw_payload_bytes))

            msg_for_state = (
                find_latest_pagination_callback_message(
                    payload.bot_username,
                    next_keywords=payload.next_text_keywords,
                    skip_invalid=True,
                    max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                    scan_limit=int(payload.callback_candidate_scan_limit or 20),
                    min_message_id=int(cleanup_min_mid or 0)
                )
                or find_latest_callback_message(payload.bot_username, skip_invalid=True)
                or _choose_best_state_message(
                    bot_username=payload.bot_username,
                    next_keywords=payload.next_text_keywords,
                    max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                    scan_limit=int(payload.callback_candidate_scan_limit or 20),
                    min_message_id=int(cleanup_min_mid or 0)
                )
            )
            if not msg_for_state:
                latest_any = find_latest_bot_message_any(payload.bot_username, require_meaningful=False)
                if _is_bot_not_found_message(latest_any):
                    background_cleanup_enabled = False
                    await _cleanup_not_found_messages(
                        payload.bot_username,
                        latest_any,
                        min_message_id=int(cleanup_min_mid or 0),
                        sent_message_id=int(cleanup_min_mid or 0)
                    )
                    timeline.append({
                        "step": steps,
                        "status": "done",
                        "reason": "not found message detected; stop",
                        "message_id": latest_any.get("message_id"),
                        "text_preview": (latest_any.get("text") or "")[:200]
                    })
                    return await _return_ok("not found message detected; stop")
                if payload.allow_ok_when_no_buttons:
                    return await _return_ok("no bot message found; return collected files")
                return await _return_fail("no bot message found")

            state = _pagination_state_from_message(msg_for_state, next_keywords=payload.next_text_keywords) or {}
            pi = state.get("page_info")
            total_items = state.get("total_items")
            has_buttons = bool(state.get("has_buttons"))
            is_pagelike = bool(state.get("is_pagination_like")) or bool(pi)

            if pi and not has_buttons:
                alt_msg_for_state = (
                    find_latest_pagination_callback_message(
                        payload.bot_username,
                        next_keywords=payload.next_text_keywords,
                        skip_invalid=True,
                        max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                        scan_limit=int(payload.callback_candidate_scan_limit or 0),
                        min_message_id=int(cleanup_min_mid or 0)
                    )
                    or find_latest_callback_message(payload.bot_username, skip_invalid=True)
                )
                if alt_msg_for_state:
                    alt_state = _pagination_state_from_message(alt_msg_for_state, next_keywords=payload.next_text_keywords) or {}
                    alt_pi = alt_state.get("page_info")
                    if bool(alt_state.get("has_buttons")) and (bool(alt_state.get("is_pagination_like")) or bool(alt_pi)):
                        msg_for_state = alt_msg_for_state
                        state = alt_state
                        pi = state.get("page_info")
                        total_items = state.get("total_items")
                        has_buttons = bool(state.get("has_buttons"))
                        is_pagelike = bool(state.get("is_pagination_like")) or bool(pi)

            if (pi is None) and (not is_pagelike):
                meta_observed = await _observe_no_controls_and_collect_files(
                    bot_username=payload.bot_username,
                    observe_seconds=int(payload.observe_when_no_controls_seconds or 0),
                    poll_seconds=float(payload.observe_when_no_controls_poll_seconds or 0.5),
                    max_logs=payload.debug_max_logs,
                    step=steps,
                    max_return_files=int(payload.max_return_files),
                    max_raw_payload_bytes=int(payload.max_raw_payload_bytes),
                    try_send_get_all=bool(payload.observe_send_get_all_when_no_controls),
                    get_all_command=str(payload.observe_get_all_command or "獲取全部"),
                    try_send_next=bool(payload.observe_send_next_when_no_controls),
                    next_command=str(payload.text_next_command or "下一頁"),
                    target_total_items=total_items
                )
                files_unique_count_observed = int(meta_observed.get("files_unique_count", meta_observed.get("files_count", 0)))
                if total_items is not None:
                    try:
                        if int(total_items) > 0 and files_unique_count_observed >= int(total_items):
                            return await _return_ok("not pagination-like; observed files collected to total_items")
                    except Exception:
                        pass
                if files_unique_count_observed > 0:
                    return await _return_ok("not pagination-like; observed files collected")
                return await _return_ok("not pagination-like; return current files")

            if pi and pi.get("current_page") is not None:
                try:
                    visited_pages.add(int(pi.get("current_page")))
                except Exception:
                    pass

            if total_items is not None:
                try:
                    assumed_total_items = int(total_items)
                except Exception:
                    pass

            files_meta = collect_files_from_store(payload.bot_username, int(payload.max_return_files), int(payload.max_raw_payload_bytes))
            files_unique_count = int(files_meta.get("files_unique_count", files_meta.get("files_count", 0)))

            if _should_stop_by_total_items(
                enabled=bool(payload.stop_when_reached_total_items),
                assumed_total_items=assumed_total_items,
                files_unique_count=files_unique_count,
                state=state,
                need_confirm_done=bool(payload.stop_need_confirm_pagination_done),
                need_last_page=bool(payload.stop_need_last_page_or_all_pages)
            ):
                return await _return_ok("files_unique_count reached total_items and pagination confirmed; stop")

            if _pagination_confirmed_last_page(state):
                return await _return_ok("last page confirmed; stop")

            if files_unique_count <= last_files_unique_count:
                no_new_files_rounds = no_new_files_rounds + 1
            else:
                no_new_files_rounds = 0
            last_files_unique_count = files_unique_count

            if no_new_files_rounds >= int(payload.stop_when_no_new_files_rounds or 0):
                if _pagination_confirmed_last_page(state):
                    return await _return_ok("no new files for several rounds and last page confirmed; stop")
                if not bool(state.get("has_next")) and bool(state.get("has_buttons")):
                    return await _return_ok("no new files for several rounds and no next; stop")
                return await _return_ok("no new files for several rounds; stop")

            if has_buttons:
                ok, clicked_or_err, _ = await _safe_click_pagination_button(
                    bot_username=payload.bot_username,
                    next_keywords=payload.next_text_keywords,
                    max_logs=payload.debug_max_logs,
                    step=steps,
                    max_retries=4,
                    wait_each_page_timeout_seconds=int(payload.wait_each_page_timeout_seconds),
                    max_return_files=int(payload.max_return_files),
                    max_raw_payload_bytes=int(payload.max_raw_payload_bytes),
                    callback_message_max_age_seconds=int(payload.callback_message_max_age_seconds),
                    callback_candidate_scan_limit=int(payload.callback_candidate_scan_limit),
                    min_message_id=int(cleanup_min_mid or 0)
                )

                if ok:
                    invalid_callback_rounds = 0
                    did_any_pagination_click = True
                    last_clicked_desc = str(clicked_or_err)
                    clicked_page_int = extract_first_int(clicked_or_err)
                    if clicked_page_int is not None:
                        last_clicked_page = int(clicked_page_int)
                    timeline.append({"step": steps, "status": "clicked", "mode": "callback", "desc": clicked_or_err})
                    await _sleep_after_pagination_click()
                else:
                    if str(clicked_or_err) == "clicked_but_no_change":
                        if payload.text_next_fallback_enabled and bool(state.get("has_next")):
                            await client.send_message(payload.bot_username, payload.text_next_command)
                            timeline.append({"step": steps, "status": "clicked", "mode": "text_next_fallback", "desc": payload.text_next_command})
                            await _sleep_after_pagination_click()
                            await asyncio.sleep(0.7)
                            await _wait_for_files_or_state_change(
                                bot_username=payload.bot_username,
                                prev_state=state,
                                prev_files_unique_count=files_unique_count,
                                timeout_seconds=int(payload.wait_each_page_timeout_seconds),
                                max_logs=payload.debug_max_logs,
                                step=steps,
                                next_keywords=payload.next_text_keywords,
                                max_return_files=int(payload.max_return_files),
                                max_raw_payload_bytes=int(payload.max_raw_payload_bytes)
                            )
                            continue

                    if str(clicked_or_err) == "already_last_page":
                        return await _return_ok("already last page; stop")

                    invalid_callback_rounds = invalid_callback_rounds + 1
                    if invalid_callback_rounds >= int(payload.max_invalid_callback_rounds):
                        if payload.allow_ok_when_no_buttons:
                            return await _return_ok("callback invalid repeatedly; stop")
                        return await _return_fail("callback invalid repeatedly", clicked_or_err)

                    if payload.text_next_fallback_enabled and bool(state.get("has_next")):
                        await client.send_message(payload.bot_username, payload.text_next_command)
                        timeline.append({"step": steps, "status": "clicked", "mode": "text_next_fallback", "desc": payload.text_next_command})
                        await _sleep_after_pagination_click()
                        await asyncio.sleep(0.7)
                        await _wait_for_files_or_state_change(
                            bot_username=payload.bot_username,
                            prev_state=state,
                            prev_files_unique_count=files_unique_count,
                            timeout_seconds=int(payload.wait_each_page_timeout_seconds),
                            max_logs=payload.debug_max_logs,
                            step=steps,
                            next_keywords=payload.next_text_keywords,
                            max_return_files=int(payload.max_return_files),
                            max_raw_payload_bytes=int(payload.max_raw_payload_bytes)
                        )
                    else:
                        if payload.allow_ok_when_no_buttons:
                            return await _return_ok("no next available after callback failure; return files")
                        return await _return_fail("no next available after callback failure", clicked_or_err)
            else:
                if pi and _pagination_confirmed_last_page(state):
                    return await _return_ok("last page confirmed; stop")

                if pi and payload.text_next_fallback_enabled and bool(state.get("has_next")):
                    await client.send_message(payload.bot_username, payload.text_next_command)
                    timeline.append({"step": steps, "status": "clicked", "mode": "text_next", "desc": payload.text_next_command})
                    await _sleep_after_pagination_click()
                    await asyncio.sleep(0.7)
                    await _wait_for_files_or_state_change(
                        bot_username=payload.bot_username,
                        prev_state=state,
                        prev_files_unique_count=files_unique_count,
                        timeout_seconds=int(payload.wait_each_page_timeout_seconds),
                        max_logs=payload.debug_max_logs,
                        step=steps,
                        next_keywords=payload.next_text_keywords,
                        max_return_files=int(payload.max_return_files),
                        max_raw_payload_bytes=int(payload.max_raw_payload_bytes)
                    )
                else:
                    meta_observed = await _observe_no_controls_and_collect_files(
                        bot_username=payload.bot_username,
                        observe_seconds=int(payload.observe_when_no_controls_seconds or 0),
                        poll_seconds=float(payload.observe_when_no_controls_poll_seconds or 0.5),
                        max_logs=payload.debug_max_logs,
                        step=steps,
                        max_return_files=int(payload.max_return_files),
                        max_raw_payload_bytes=int(payload.max_raw_payload_bytes),
                        try_send_get_all=bool(payload.observe_send_get_all_when_no_controls),
                        get_all_command=str(payload.observe_get_all_command or "獲取全部"),
                        try_send_next=bool(payload.observe_send_next_when_no_controls),
                        next_command=str(payload.text_next_command or "下一頁"),
                        target_total_items=total_items
                    )
                    files_unique_count_observed = int(meta_observed.get("files_unique_count", meta_observed.get("files_count", 0)))
                    if total_items is not None:
                        try:
                            if int(total_items) > 0 and files_unique_count_observed >= int(total_items):
                                return await _return_ok("no buttons and no next; observed files collected to total_items")
                        except Exception:
                            pass
                    if files_unique_count_observed > 0:
                        return await _return_ok("no buttons and no next; observed files collected")
                    if payload.allow_ok_when_no_buttons:
                        return await _return_ok("no buttons and no next; return files")
                    return await _return_fail("no buttons and no next; cannot paginate")

        if payload.allow_ok_when_no_buttons:
            return await _return_ok("reached max_steps; return files")
        return await _return_fail("reached max_steps")

    except Exception as e:
        push_log(stage="fatal_exception", step=steps, extra={"error": str(e), "trace": traceback.format_exc()}, max_logs=payload.debug_max_logs)
        return await _return_fail("fatal_exception", str(e))


class ClickMatchingButtonRequest(BaseModel):
    bot_username: str
    sent_message_id: int = 0
    clear_previous_replies: bool = False
    delay_seconds: int = 0
    button_keywords: List[str] = []

    debug: bool = True
    debug_max_logs: int = 2000

    include_files_in_response: bool = True
    max_return_files: int = 500
    max_raw_payload_bytes: int = 0
    stop_when_total_items_exceeds: int = 0

    wait_after_click_timeout_seconds: int = 8

    cleanup_after_done: bool = False
    cleanup_scope: str = "run"
    cleanup_limit: int = 500

    callback_message_max_age_seconds: int = 25
    callback_candidate_scan_limit: int = 30


@app.post("/bots/click-matching-button")
async def click_matching_button(payload: ClickMatchingButtonRequest) -> Dict[str, Any]:
    timeline: List[Dict[str, Any]] = []
    steps = 0
    cleanup_min_mid = int(payload.sent_message_id or 0)
    cleanup_max_mid = 0
    cleanup_after_return_enabled = True

    button_keywords: List[str] = []
    for kw in (payload.button_keywords or []):
        normalized = str(kw or "").strip()
        if normalized and normalized not in button_keywords:
            button_keywords.append(normalized)

    def _freeze_cleanup_window() -> None:
        nonlocal cleanup_max_mid
        lower_bound = int(cleanup_min_mid or 0)
        if lower_bound <= 0:
            cleanup_max_mid = 0
            return

        cleanup_max_mid = max(lower_bound, int(_message_store_max_mid_for_bot(payload.bot_username) or 0))

    async def _build_response(
        status: str,
        reason: str,
        button_clicked: bool = False,
        clicked_button_text: str = "",
        clicked_message_id: int = 0,
        click_effect_observed: bool = False,
        click_effect_reason: str = ""
    ) -> Dict[str, Any]:
        _freeze_cleanup_window()
        result: Dict[str, Any] = {
            "status": status,
            "reason": reason,
            "steps": steps,
            "timeline": timeline,
            "button_clicked": bool(button_clicked),
            "clicked_button_text": clicked_button_text,
            "clicked_message_id": int(clicked_message_id or 0),
            "button_keywords": button_keywords,
            "click_effect_observed": bool(click_effect_observed),
            "click_effect_reason": click_effect_reason,
        }

        result = _attach_bot_result_snapshot(
            result,
            bot_username=payload.bot_username,
            include_files=bool(payload.include_files_in_response),
            max_return_files=int(payload.max_return_files or 0),
            max_raw_payload_bytes=int(payload.max_raw_payload_bytes or 0),
            min_message_id=int(cleanup_min_mid or 0)
        )

        if payload.debug:
            result["debug"] = _get_debug_logs(int(payload.debug_max_logs or 0))

        if payload.cleanup_after_done and cleanup_after_return_enabled:
            try:
                await _cleanup_chat_after_run(
                    bot_username=payload.bot_username,
                    scope=payload.cleanup_scope,
                    limit=int(payload.cleanup_limit or 0),
                    min_message_id=int(cleanup_min_mid or 0),
                    max_message_id=int(cleanup_max_mid or 0),
                    preserve_file_messages=True,
                    max_logs=int(payload.debug_max_logs or 0)
                )
            except Exception:
                push_log(stage="cleanup", result="cleanup_error", step=steps, extra={
                    "err": traceback.format_exc()
                }, max_logs=int(payload.debug_max_logs or 0))

        return result

    try:
        if int(payload.delay_seconds or 0) > 0:
            await asyncio.sleep(float(payload.delay_seconds))

        if payload.clear_previous_replies:
            try:
                clear_reply_cache_for_bot(payload.bot_username)
            except Exception:
                pass

        if payload.debug:
            _clear_debug_logs()

        push_log(stage="click_matching_button_start", result="start", step=0, extra={
            "bot": payload.bot_username,
            "button_keywords": button_keywords,
            "cleanup_after_done": bool(payload.cleanup_after_done),
        }, max_logs=int(payload.debug_max_logs or 0))

        if not button_keywords:
            timeline.append({"step": 0, "status": "fail", "reason": "button_keywords required"})
            return await _build_response("fail", "button_keywords required")

        await backfill_latest_from_bot(
            payload.bot_username,
            limit=420,
            timeout_seconds=6.0,
            max_logs=int(payload.debug_max_logs or 0),
            step=0,
            force=True,
            min_message_id=int(cleanup_min_mid or 0)
        )

        candidates: List[Dict[str, Any]] = []
        for msg in MESSAGE_STORE.values():
            if not _message_matches_bot(msg, payload.bot_username):
                continue

            mid = int(msg.get("message_id", 0) or 0)
            if int(cleanup_min_mid or 0) > 0 and mid < int(cleanup_min_mid or 0):
                continue

            chat_id = int(msg.get("chat_id", 0) or 0)
            if is_invalid_callback(payload.bot_username, chat_id, mid):
                continue

            buttons = get_callback_buttons(msg)
            if not buttons:
                continue

            for btn in buttons:
                btn_text = str(btn.get("text", "") or "")
                if _button_text_contains_keywords(btn_text, button_keywords):
                    candidates.append({
                        "message": msg,
                        "button": btn,
                        "button_text": btn_text,
                        "message_id": mid,
                    })
                    break

        candidates.sort(key=lambda item: int(item.get("message_id", 0) or 0), reverse=True)

        if not candidates:
            timeline.append({"step": 0, "status": "fail", "reason": "no matching button found", "button_keywords": button_keywords})
            return await _build_response("fail", "no matching button found")

        chosen = candidates[0]
        chosen_msg = chosen["message"]
        chosen_btn = chosen["button"]
        chosen_text = str(chosen.get("button_text") or "").strip()
        chosen_mid = int(chosen.get("message_id", 0) or 0)
        chosen_chat_id = int(chosen_msg.get("chat_id", 0) or 0)

        if cleanup_min_mid <= 0 or chosen_mid < cleanup_min_mid:
            cleanup_min_mid = chosen_mid

        timeline.append({
            "step": 0,
            "status": "button_candidate",
            "message_id": chosen_mid,
            "chat_id": chosen_chat_id,
            "button_text": chosen_text,
            "buttons_text": summarize_buttons(get_callback_buttons(chosen_msg)),
        })

        data_hex = str(chosen_btn.get("data") or "")
        if not data_hex:
            timeline.append({"step": 0, "status": "fail", "reason": "matching button missing callback data"})
            return await _build_response("fail", "matching button missing callback data")

        marker = _pagination_action_marker_from_message(chosen_msg, buttons=get_callback_buttons(chosen_msg))
        if is_recent_used_callback_action(payload.bot_username, chosen_chat_id, chosen_mid, data_hex, marker=marker):
            timeline.append({"step": 0, "status": "fail", "reason": "recent_used"})
            return await _build_response("fail", "recent_used")

        files_meta_before = collect_files_from_store(
            payload.bot_username,
            int(payload.max_return_files or 0),
            int(payload.max_raw_payload_bytes or 0),
            min_message_id=int(cleanup_min_mid or 0)
        )
        files_unique_before = int(files_meta_before.get("files_unique_count", files_meta_before.get("files_count", 0)) or 0)

        await click_callback(payload.bot_username, chosen_chat_id, chosen_mid, data_hex)
        mark_recent_used_callback_action(payload.bot_username, chosen_chat_id, chosen_mid, data_hex, marker=marker)

        steps = 1
        timeline.append({
            "step": steps,
            "status": "clicked",
            "message_id": chosen_mid,
            "chat_id": chosen_chat_id,
            "button_text": chosen_text,
        })

        click_effect_observed = False
        click_effect_reason = ""
        if int(payload.wait_after_click_timeout_seconds or 0) > 0:
            click_effect_observed, click_effect_reason = await _wait_for_files_or_state_change(
                bot_username=payload.bot_username,
                prev_state_msg_id=chosen_mid,
                prev_state=chosen_msg,
                prev_files_unique_count=files_unique_before,
                min_message_id=int(cleanup_min_mid or 0),
                timeout_seconds=int(payload.wait_after_click_timeout_seconds or 0),
                poll_interval=0.35,
                max_logs=int(payload.debug_max_logs or 0),
                step=steps,
                next_keywords=[],
                max_return_files=int(payload.max_return_files or 0),
                max_raw_payload_bytes=int(payload.max_raw_payload_bytes or 0)
            )

        await backfill_latest_from_bot(
            payload.bot_username,
            limit=420,
            timeout_seconds=6.0,
            max_logs=int(payload.debug_max_logs or 0),
            step=steps,
            force=True,
            min_message_id=int(cleanup_min_mid or 0)
        )

        timeline.append({
            "step": steps,
            "status": "after_click",
            "reason": click_effect_reason or "clicked",
            "effect_observed": bool(click_effect_observed),
        })

        return await _build_response(
            "ok",
            "clicked matching button",
            button_clicked=True,
            clicked_button_text=chosen_text,
            clicked_message_id=chosen_mid,
            click_effect_observed=bool(click_effect_observed),
            click_effect_reason=click_effect_reason,
        )
    except MessageIdInvalidError:
        timeline.append({"step": max(steps, 1), "status": "fail", "reason": "invalid_callback"})
        return await _build_response("fail", "invalid_callback")
    except Exception as e:
        timeline.append({"step": steps, "status": "fail", "reason": "exception", "err": traceback.format_exc()})
        return await _build_response("fail", str(e) or "exception")


@app.get("/bots/health")
async def health():
    return {"status": "ok"}


def _peer_type_name(dialog_obj: Any) -> str:
    try:
        if getattr(dialog_obj, "is_user", False):
            return "user"
        if getattr(dialog_obj, "is_group", False):
            return "group"
        if getattr(dialog_obj, "is_channel", False):
            return "channel"
    except Exception:
        pass
    return "unknown"


async def _resolve_any_entity_by_id(peer_id: int):
    try:
        return await client.get_entity(int(peer_id))
    except Exception:
        return None


async def _resolve_any_input_entity_by_id(peer_id: int):
    try:
        return await client.get_input_entity(int(peer_id))
    except Exception:
        pass

    try:
        async for d in client.iter_dialogs(limit=500):
            try:
                ent = getattr(d, "entity", None)
                ent_id = int(getattr(ent, "id", 0) or 0)
            except Exception:
                ent = None
                ent_id = 0

            if ent is None or ent_id != int(peer_id):
                continue

            migrated_to = getattr(ent, "migrated_to", None)
            if migrated_to is not None:
                try:
                    return await client.get_input_entity(migrated_to)
                except Exception:
                    try:
                        return migrated_to
                    except Exception:
                        pass

            try:
                return await client.get_input_entity(ent)
            except Exception:
                continue
    except Exception:
        pass

    return None


def _message_to_full_dict(m: Any) -> Dict[str, Any]:
    if m is None:
        return {}
    try:
        d = m.to_dict()
        return _json_sanitize(d) or {}
    except Exception as e:
        return {"_error": "message_to_dict_failed", "error": str(e), "id": getattr(m, "id", None)}


@app.get("/groups")
async def list_groups(limit: int = 200, include_channels: bool = True):
    out: List[Dict[str, Any]] = []
    i = 0
    async for d in client.iter_dialogs(limit=max(int(limit or 200), 1)):
        try:
            is_group = bool(getattr(d, "is_group", False))
            is_channel = bool(getattr(d, "is_channel", False))
            if not is_group and not (include_channels and is_channel):
                continue

            ent = getattr(d, "entity", None)
            pid = None
            title = None
            username = None
            try:
                pid = int(getattr(ent, "id", 0) or 0)
            except Exception:
                pid = 0
            title = getattr(d, "name", None) or getattr(ent, "title", None) or getattr(ent, "first_name", None)
            username = getattr(ent, "username", None)

            last_mid = None
            last_date = None
            try:
                if getattr(d, "message", None) is not None:
                    last_mid = int(getattr(d.message, "id", 0) or 0)
                    last_date = getattr(d.message, "date", None)
            except Exception:
                last_mid = None
                last_date = None

            out.append({
                "id": pid,
                "title": title,
                "username": username,
                "type": _peer_type_name(d),
                "unread_count": int(getattr(d, "unread_count", 0) or 0),
                "unread_mentions_count": int(getattr(d, "unread_mentions_count", 0) or 0),
                "last_message_id": last_mid,
                "last_message_date": last_date.isoformat() if last_date else None
            })
            i = i + 1
        except Exception as e:
            push_log(stage="groups", result="row_error", extra={"error": str(e), "trace": traceback.format_exc()[:800]})

    out.sort(key=lambda x: (x.get("unread_count", 0), x.get("last_message_id", 0) or 0), reverse=True)
    return {"items": out, "count": len(out)}


@app.get("/groups/{peer_id}")
async def get_group_messages(
    peer_id: int,
    limit: int = 1000,
    offset_id: int = 0,
    min_id: int = 0,
    max_id: int = 0,
    add_offset: int = 0,
    reverse: bool = False,
    include_raw: bool = True
):
    ent = await _resolve_any_entity_by_id(peer_id)
    if ent is None:
        return {"status": "fail", "reason": "entity_not_found", "peer_id": peer_id}

    dialog_obj = None
    try:
        dialog_obj = await client.get_dialogs(1)
    except Exception:
        dialog_obj = None

    dialog_unread = None
    dialog_last_mid = None
    dialog_last_date = None
    dialog_title = getattr(ent, "title", None) or getattr(ent, "first_name", None) or getattr(ent, "username", None)
    dialog_username = getattr(ent, "username", None)

    try:
        async for d in client.iter_dialogs(limit=300):
            try:
                eid = int(getattr(getattr(d, "entity", None), "id", 0) or 0)
            except Exception:
                eid = 0
            if eid == int(peer_id):
                dialog_obj = d
                break
    except Exception:
        dialog_obj = None

    try:
        if dialog_obj is not None:
            dialog_unread = int(getattr(dialog_obj, "unread_count", 0) or 0)
            if getattr(dialog_obj, "message", None) is not None:
                dialog_last_mid = int(getattr(dialog_obj.message, "id", 0) or 0)
                dialog_last_date = getattr(dialog_obj.message, "date", None)
                if getattr(dialog_obj, "name", None):
                    dialog_title = dialog_obj.name
    except Exception:
        pass

    msgs = await client.get_messages(
        ent,
        limit=max(int(limit or 50), 1),
        offset_id=int(offset_id or 0),
        min_id=int(min_id or 0),
        max_id=int(max_id or 0),
        add_offset=int(add_offset or 0),
        reverse=bool(reverse)
    )

    items: List[Dict[str, Any]] = []
    for m in (msgs or []):
        if include_raw:
            items.append(_message_to_full_dict(m))
        else:
            items.append({
                "id": int(getattr(m, "id", 0) or 0),
                "date": getattr(m, "date", None).isoformat() if getattr(m, "date", None) else None,
                "sender_id": int(getattr(getattr(m, "sender_id", None), "user_id", 0) or (getattr(m, "sender_id", 0) or 0)),
                "text": getattr(m, "message", None) or getattr(m, "text", None),
                "has_media": bool(getattr(m, "media", None) is not None),
            })

    ids_desc = [int(x.get("id", 0) or 0) for x in items if isinstance(x, dict)]
    ids_desc = [x for x in ids_desc if x > 0]
    ids_desc_sorted = sorted(ids_desc, reverse=True)

    unread_count = int(dialog_unread or 0)
    unread_ids_in_page: List[int] = []
    if unread_count > 0 and ids_desc_sorted:
        unread_ids_in_page = ids_desc_sorted[: min(unread_count, len(ids_desc_sorted))]

    paging = {
        "how_to_read_older": "用 offset_id=目前頁面最小 message id（或最舊那筆 id）再呼叫一次即可往更舊讀",
        "how_to_read_newer": "用 min_id=目前頁面最大 message id（或最新那筆 id）再呼叫一次即可往更新讀（需要 reverse=true 比較好理解）",
        "suggested_next": {
            "older_offset_id": min(ids_desc_sorted) if ids_desc_sorted else 0,
            "newer_min_id": max(ids_desc_sorted) if ids_desc_sorted else 0,
            "reverse_for_newer": True
        }
    }

    return {
        "status": "ok",
        "group": {
            "id": int(peer_id),
            "title": dialog_title,
            "username": dialog_username,
            "type": "group_or_channel",
            "unread_count": unread_count,
            "last_message_id": dialog_last_mid,
            "last_message_date": dialog_last_date.isoformat() if dialog_last_date else None,
            "unread_hint": {
                "note": "Telegram 未讀起點精準 message id 不一定可直接取得，這裡用 unread_count 對當前回傳頁面做近似標記",
                "unread_message_ids_in_this_page": unread_ids_in_page
            }
        },
        "query": {
            "limit": int(limit or 50),
            "offset_id": int(offset_id or 0),
            "min_id": int(min_id or 0),
            "max_id": int(max_id or 0),
            "add_offset": int(add_offset or 0),
            "reverse": bool(reverse),
            "include_raw": bool(include_raw)
        },
        "paging": paging,
        "items": items,
        "count": len(items)
    }

@app.get("/groups/{peer_id}/{message_id}")
async def get_group_message_by_id(
    peer_id: int,
    message_id: int,
    include_next: bool = True,
    next_limit: int = 1000,
    include_raw: bool = True
):
    ent = await _resolve_any_entity_by_id(peer_id)
    if ent is None:
        return {
            "status": "fail",
            "reason": "entity_not_found",
            "peer_id": int(peer_id)
        }

    items: List[Dict[str, Any]] = []

    try:
        if include_next:
            msgs = await client.get_messages(
                ent,
                min_id=int(message_id),
                limit=int(next_limit),
                reverse=True
            )

            for m in (msgs or []):
                if include_raw:
                    items.append(_message_to_full_dict(m))
                else:
                    items.append({
                        "id": int(getattr(m, "id", 0) or 0),
                        "date": m.date.isoformat() if getattr(m, "date", None) else None,
                        "sender_id": int(
                            getattr(getattr(m, "sender_id", None), "user_id", 0)
                            or (getattr(m, "sender_id", 0) or 0)
                        ),
                        "text": getattr(m, "message", None) or getattr(m, "text", None),
                        "has_media": bool(getattr(m, "media", None) is not None),
                        "is_reply": bool(getattr(m, "reply_to", None) is not None),
                        "reply_to_message_id": int(
                            getattr(getattr(m, "reply_to", None), "reply_to_msg_id", 0) or 0
                        ) if getattr(m, "reply_to", None) else None
                    })

        else:
            msg = await client.get_messages(ent, ids=int(message_id))
            if msg is None:
                return {
                    "status": "ok",
                    "found": False,
                    "peer_id": int(peer_id),
                    "message_id": int(message_id),
                    "items": []
                }

            if include_raw:
                items.append(_message_to_full_dict(msg))
            else:
                items.append({
                    "id": int(getattr(msg, "id", 0) or 0),
                    "date": msg.date.isoformat() if getattr(msg, "date", None) else None,
                    "sender_id": int(
                        getattr(getattr(msg, "sender_id", None), "user_id", 0)
                        or (getattr(msg, "sender_id", 0) or 0)
                    ),
                    "text": getattr(msg, "message", None) or getattr(msg, "text", None),
                    "has_media": bool(getattr(msg, "media", None) is not None),
                    "is_reply": bool(getattr(msg, "reply_to", None) is not None),
                    "reply_to_message_id": int(
                        getattr(getattr(msg, "reply_to", None), "reply_to_msg_id", 0) or 0
                    ) if getattr(msg, "reply_to", None) else None
                })

    except Exception as e:
        return {
            "status": "fail",
            "reason": "get_messages_error",
            "peer_id": int(peer_id),
            "message_id": int(message_id),
            "error": str(e)
        }

    return {
        "status": "ok",
        "peer_id": int(peer_id),
        "start_message_id": int(message_id),
        "include_next": bool(include_next),
        "next_limit": int(next_limit),
        "count": len(items),
        "items": items
    }


class GroupMessageDownloadRequest(BaseModel):
    peer_id: int
    message_id: int
    folder_label: Optional[str] = None


class GroupSendMessageRequest(BaseModel):
    peer_id: int
    text: str


class BotMessageDownloadRequest(BaseModel):
    bot_username: str
    message_id: int
    folder_label: Optional[str] = None


@app.post("/groups/send-message")
async def send_group_message(payload: GroupSendMessageRequest):
    peer_id = int(payload.peer_id or 0)
    text = str(payload.text or "").strip()

    if peer_id <= 0:
        return {
            "status": "fail",
            "reason": "peer_id_required",
        }

    if text == "":
        return {
            "status": "fail",
            "reason": "text_required",
            "peer_id": peer_id,
        }

    connected = await _ensure_client_connected()
    if not connected:
        return {
            "status": "fail",
            "reason": "client_not_connected",
            "peer_id": peer_id,
        }

    input_ent = await _resolve_any_input_entity_by_id(peer_id)
    if input_ent is None:
        return {
            "status": "fail",
            "reason": "entity_not_found",
            "peer_id": peer_id,
        }

    try:
        sent = await client.send_message(input_ent, text, parse_mode=None, link_preview=False)

        result = {
            "status": "ok",
            "peer_id": peer_id,
            "message_id": int(getattr(sent, "id", 0) or 0),
            "text": text,
        }
        push_log(stage="group_send_message", result="ok", extra=_json_sanitize(result))
        return result
    except Exception as e:
        push_log(
            stage="group_send_message",
            result="error",
            extra={
                "peer_id": peer_id,
                "text": text[:200],
                "error": str(e),
                "trace": traceback.format_exc()[:800],
            },
        )
        return {
            "status": "fail",
            "reason": "send_error",
            "peer_id": peer_id,
            "error": str(e),
        }


@app.post("/groups/download-message-media")
async def download_group_message_media(payload: GroupMessageDownloadRequest):
    try:
        result = await _download_group_message_media_to_local(
            peer_id=int(payload.peer_id),
            message_id=int(payload.message_id),
            folder_label=payload.folder_label,
        )
        push_log(
            stage="group_download",
            result="ok" if bool(result.get("downloaded")) else str(result.get("reason") or result.get("status") or "ok"),
            extra=_json_sanitize(result),
        )
        return result
    except Exception as e:
        push_log(
            stage="group_download",
            result="error",
            extra={
                "peer_id": int(payload.peer_id),
                "message_id": int(payload.message_id),
                "error": str(e),
                "trace": traceback.format_exc()[:800],
            },
        )
        return {
            "status": "fail",
            "reason": "download_error",
            "peer_id": int(payload.peer_id),
            "message_id": int(payload.message_id),
            "error": str(e),
        }


@app.post("/bots/download-message-media")
async def download_bot_message_media(payload: BotMessageDownloadRequest):
    bot_username = str(payload.bot_username or "").strip()
    message_id = int(payload.message_id or 0)

    if not bot_username:
        return {
            "status": "fail",
            "reason": "bot_username_required",
        }

    if message_id <= 0:
        return {
            "status": "fail",
            "reason": "message_id_required",
            "bot_username": bot_username,
        }

    try:
        connected = await _ensure_client_connected()
        if not connected:
            return {
                "status": "fail",
                "reason": "client_not_connected",
                "bot_username": bot_username,
                "message_id": message_id,
            }

        peer = await _get_peer_for_bot(bot_username)
        if peer is None:
            return {
                "status": "fail",
                "reason": "bot_not_found",
                "bot_username": bot_username,
                "message_id": message_id,
            }

        one = await client.get_messages(peer, ids=message_id)
        if one is None:
            return {
                "status": "fail",
                "reason": "message_not_found",
                "bot_username": bot_username,
                "message_id": message_id,
            }

        msg = one if isinstance(one, Message) else None
        if msg is None:
            return {
                "status": "fail",
                "reason": "message_not_found",
                "bot_username": bot_username,
                "message_id": message_id,
            }

        file_meta = _extract_file_meta_mtproto(msg)
        if not file_meta:
            return {
                "status": "fail",
                "reason": "message_has_no_file",
                "bot_username": bot_username,
                "message_id": message_id,
            }

        label = str(payload.folder_label or bot_username).strip() or bot_username
        folder_path = _ensure_download_folder_for_text(label)
        reserved_path = _reserve_download_file_path(
            folder_path=folder_path,
            file_obj=file_meta,
            base_name=label,
            index=1,
            job_id=f"single-download-{bot_username}-{message_id}",
        )

        tdl_result = await asyncio.to_thread(
            _run_tdl_download_for_bot_message,
            bot_username,
            message_id,
            folder_path,
            reserved_path,
        )

        telethon_result: Dict[str, Any] = {"ok": False, "reason": "not_attempted"}
        downloader = "tdl"
        saved_path = str(tdl_result.get("saved_path") or "").strip()

        if not bool(tdl_result.get("ok")):
            downloader = "telethon"
            telethon_result = await _download_media_with_telethon_timeout(
                msg,
                reserved_path,
                BACKGROUND_TELETHON_DOWNLOAD_TIMEOUT_SECONDS,
            )
            if bool(telethon_result.get("ok")):
                saved_path = str(telethon_result.get("saved_path") or "").strip()
            else:
                saved_path = ""

        if saved_path == "":
            return {
                "status": "fail",
                "reason": "download_error",
                "bot_username": bot_username,
                "message_id": message_id,
                "error": str(
                    telethon_result.get("error")
                    or telethon_result.get("reason")
                    or tdl_result.get("error")
                    or tdl_result.get("reason")
                    or "download_failed"
                ),
            }

        return {
            "status": "ok",
            "downloaded": True,
            "bot_username": bot_username,
            "message_id": message_id,
            "saved_path": saved_path,
            "saved_name": os.path.basename(saved_path),
            "folder_path": folder_path,
            "downloader": downloader,
            "tdl": _json_sanitize(tdl_result),
            "telethon": _json_sanitize(telethon_result),
            "file": file_meta,
        }
    except Exception as e:
        push_log(
            stage="bot_download",
            result="error",
            extra={
                "bot_username": bot_username,
                "message_id": message_id,
                "error": str(e),
                "trace": traceback.format_exc()[:800],
            },
        )
        return {
            "status": "fail",
            "reason": "download_error",
            "bot_username": bot_username,
            "message_id": message_id,
            "error": str(e),
        }


@app.get("/bots/dialogs")
async def list_bot_dialogs(limit: int = 300):
    items: List[Dict[str, Any]] = []

    async for d in client.iter_dialogs(limit=max(int(limit or 300), 1)):
        try:
            if not getattr(d, "is_user", False):
                continue

            ent = getattr(d, "entity", None)
            if ent is None:
                continue

            if not bool(getattr(ent, "bot", False)):
                continue

            peer_id = int(getattr(ent, "id", 0) or 0)
            username = getattr(ent, "username", None)
            first_name = getattr(ent, "first_name", None)
            last_name = getattr(ent, "last_name", None)

            title = None
            if first_name or last_name:
                title = f"{first_name or ''} {last_name or ''}".strip()
            if not title:
                title = username

            last_mid = None
            last_date = None
            unread_count = 0

            try:
                unread_count = int(getattr(d, "unread_count", 0) or 0)
                if getattr(d, "message", None) is not None:
                    last_mid = int(getattr(d.message, "id", 0) or 0)
                    last_date = getattr(d.message, "date", None)
            except Exception:
                pass

            items.append({
                "id": peer_id,
                "username": username,
                "title": title,
                "unread_count": unread_count,
                "last_message_id": last_mid,
                "last_message_date": last_date.isoformat() if last_date else None
            })

        except Exception as e:
            push_log(
                stage="bots_dialogs",
                result="row_error",
                extra={"error": str(e), "trace": traceback.format_exc()[:800]}
            )

    items.sort(
        key=lambda x: (
            x.get("unread_count", 0),
            x.get("last_message_id", 0) or 0
        ),
        reverse=True
    )

    return {
        "status": "ok",
        "count": len(items),
        "items": items
    }
# ===== 新增：只輸入 bot 名稱，自動一直按「下一頁」直到結束 =====
# 放在你的檔案中（建議放在 SendAndRunAllPagesRequest 定義之後、/bots/send-and-run-all-pages 之後都可）

class RunAllPagesByBotOnlyRequest(BaseModel):
    bot_username: str
    sent_message_id: int = 0
    clear_previous_replies: bool = False

    delay_seconds: int = 0
    max_steps: int = 120
    next_text_keywords: List[str] = [
        "下一頁", "下页", "下一页",
        "Next", "next",
        ">", ">>", "»", "›", "»»",
        "▶", "►", "⏩", "→", "➡", "➡️",
        "⏭", "⏭️",
        "更多", "more", "More",
        "Forward", "forward"
    ]
    wait_each_page_timeout_seconds: int = 25

    debug: bool = True
    debug_max_logs: int = 2000

    include_files_in_response: bool = True
    max_return_files: int = 500
    max_raw_payload_bytes: int = 0
    stop_when_total_items_exceeds: int = 0

    stop_when_no_new_files_rounds: int = 4
    stop_when_reached_total_items: bool = True

    stop_need_confirm_pagination_done: bool = True
    stop_need_last_page_or_all_pages: bool = True

    cleanup_after_done: bool = False
    cleanup_scope: str = "run"
    cleanup_limit: int = 500

    callback_message_max_age_seconds: int = 25
    callback_candidate_scan_limit: int = 30

    observe_when_no_controls_poll_seconds: float = 0.5

    # 預設改為不在「沒控制鍵」時送「獲取全部」
    observe_send_get_all_when_no_controls: bool = False
    observe_get_all_command: str = "獲取全部"
    observe_send_next_when_no_controls: bool = False

@app.post("/bots/run-all-pages-by-bot")
async def run_all_pages_by_bot(payload: RunAllPagesByBotOnlyRequest) -> Dict[str, Any]:
    import traceback
    from typing import Any, Dict, List, Optional, Set

    timeline: List[Dict[str, Any]] = []
    steps = 0
    visited_pages: Set[int] = set()
    cleanup_min_mid = int(payload.sent_message_id or 0)
    cleanup_max_mid = 0
    cleanup_after_return_enabled = True
    total_items_exceeded_limit = False
    total_items_exceeded_limit_value = 0
    total_items_exceeded_limit_limit = max(int(payload.stop_when_total_items_exceeds or 0), 0)

    def _current_run_min_message_id() -> int:
        return max(int(cleanup_min_mid or 0), int(payload.sent_message_id or 0))

    def _filter_current_run_message(msg: Optional[Dict[str, Any]]) -> Optional[Dict[str, Any]]:
        if not msg:
            return None
        min_mid = int(_current_run_min_message_id() or 0)
        if min_mid > 0 and int(msg.get("message_id", 0) or 0) < min_mid:
            return None
        return msg

    def _find_latest_any_current_run(require_meaningful: bool = False) -> Optional[Dict[str, Any]]:
        return _filter_current_run_message(
            find_latest_bot_message_any(payload.bot_username, require_meaningful=require_meaningful)
        )

    def _freeze_cleanup_window() -> None:
        nonlocal cleanup_max_mid
        lower_bound = int(cleanup_min_mid or 0)
        if lower_bound <= 0:
            cleanup_max_mid = 0
            return

        cleanup_max_mid = max(lower_bound, int(_message_store_max_mid_for_bot(payload.bot_username) or 0))

    def _mark_total_items_limit_exceeded(total_items_value: Optional[int]) -> None:
        nonlocal total_items_exceeded_limit, total_items_exceeded_limit_value
        if int(total_items_exceeded_limit_limit or 0) <= 0:
            return
        try:
            parsed_value = int(total_items_value or 0)
        except Exception:
            parsed_value = 0
        if parsed_value <= int(total_items_exceeded_limit_limit or 0):
            return
        total_items_exceeded_limit = True
        total_items_exceeded_limit_value = parsed_value

    async def _return_ok(reason: str) -> Dict[str, Any]:
        _freeze_cleanup_window()
        result: Dict[str, Any] = {
            "status": "ok",
            "reason": reason,
            "steps": steps,
            "timeline": timeline
        }

        result = _attach_bot_result_snapshot(
            result,
            bot_username=payload.bot_username,
            include_files=bool(payload.include_files_in_response),
            max_return_files=int(payload.max_return_files or 0),
            max_raw_payload_bytes=int(payload.max_raw_payload_bytes or 0),
            min_message_id=int(cleanup_min_mid or 0)
        )

        if payload.debug:
            result["debug"] = _get_debug_logs(int(payload.debug_max_logs or 0))
        result["total_items_exceeded_limit"] = bool(total_items_exceeded_limit)
        result["total_items_exceeded_limit_value"] = int(total_items_exceeded_limit_value or 0)
        result["total_items_exceeded_limit_limit"] = int(total_items_exceeded_limit_limit or 0)

        files_unique_count = int(result.get("files_unique_count", 0) or 0)
        if payload.cleanup_after_done and cleanup_after_return_enabled:
            try:
                await _cleanup_chat_after_run(
                    bot_username=payload.bot_username,
                    scope=payload.cleanup_scope,
                    limit=int(payload.cleanup_limit or 0),
                    min_message_id=int(cleanup_min_mid or 0),
                    max_message_id=int(cleanup_max_mid or 0),
                    preserve_file_messages=True,
                    max_logs=int(payload.debug_max_logs or 0)
                )
            except Exception:
                push_log(stage="cleanup", result="cleanup_error", step=steps, extra={
                    "err": traceback.format_exc()
                }, max_logs=int(payload.debug_max_logs or 0))
        elif payload.cleanup_after_done and files_unique_count > 0:
            push_log(stage="cleanup", result="preserve_files_only", step=steps, extra={
                "bot_username": payload.bot_username,
                "files_unique_count": files_unique_count,
                "cleanup_min_mid": int(cleanup_min_mid or 0),
                "cleanup_max_mid": int(cleanup_max_mid or 0),
            }, max_logs=int(payload.debug_max_logs or 0))

        return result

    async def _return_fail(reason: str) -> Dict[str, Any]:
        _freeze_cleanup_window()
        result: Dict[str, Any] = {
            "status": "fail",
            "reason": reason,
            "steps": steps,
            "timeline": timeline
        }

        result = _attach_bot_result_snapshot(
            result,
            bot_username=payload.bot_username,
            include_files=bool(payload.include_files_in_response),
            max_return_files=int(payload.max_return_files or 0),
            max_raw_payload_bytes=int(payload.max_raw_payload_bytes or 0),
            min_message_id=int(cleanup_min_mid or 0)
        )

        if payload.debug:
            result["debug"] = _get_debug_logs(int(payload.debug_max_logs or 0))
        result["total_items_exceeded_limit"] = bool(total_items_exceeded_limit)
        result["total_items_exceeded_limit_value"] = int(total_items_exceeded_limit_value or 0)
        result["total_items_exceeded_limit_limit"] = int(total_items_exceeded_limit_limit or 0)

        if payload.cleanup_after_done and cleanup_after_return_enabled:
            try:
                await _cleanup_chat_after_run(
                    bot_username=payload.bot_username,
                    scope=payload.cleanup_scope,
                    limit=int(payload.cleanup_limit or 0),
                    min_message_id=int(cleanup_min_mid or 0),
                    max_message_id=int(cleanup_max_mid or 0),
                    preserve_file_messages=True,
                    max_logs=int(payload.debug_max_logs or 0)
                )
            except Exception:
                push_log(stage="cleanup", result="cleanup_error", step=steps, extra={
                    "err": traceback.format_exc()
                }, max_logs=int(payload.debug_max_logs or 0))

        return result

    try:
        if int(payload.delay_seconds or 0) > 0:
            await asyncio.sleep(float(payload.delay_seconds))

        if payload.clear_previous_replies:
            try:
                clear_reply_cache_for_bot(payload.bot_username)
            except Exception:
                pass

        if payload.debug:
            _clear_debug_logs()

        push_log(stage="run_botonly_start", result="start", step=0, extra={
            "bot": payload.bot_username,
            "max_steps": int(payload.max_steps or 0),
            "cleanup_after_done": bool(payload.cleanup_after_done)
        }, max_logs=int(payload.debug_max_logs or 0))

        await backfill_latest_from_bot(payload.bot_username, limit=420, timeout_seconds=6.0, max_logs=int(payload.debug_max_logs or 0), step=0, force=True)

        chosen_first = _choose_best_state_message(
            bot_username=payload.bot_username,
            next_keywords=payload.next_text_keywords,
            max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
            scan_limit=int(payload.callback_candidate_scan_limit or 0),
            min_message_id=int(_current_run_min_message_id() or 0)
        )

        if not chosen_first:
            latest_any = _find_latest_any_current_run(require_meaningful=False)
            if _is_bot_not_found_message(latest_any):
                cleanup_after_return_enabled = False
                await _cleanup_not_found_messages(
                    payload.bot_username,
                    latest_any,
                    min_message_id=int(cleanup_min_mid or 0)
                )
                timeline.append({
                    "step": 0,
                    "status": "done",
                    "reason": "not found message detected; stop",
                    "message_id": latest_any.get("message_id"),
                    "text_preview": (latest_any.get("text") or "")[:200]
                })
                return await _return_ok("not found message detected; stop")
            timeline.append({"step": 0, "status": "fail", "reason": "no callback state message found"})
            return await _return_fail("no callback state message found")

        if chosen_first.get("message_id"):
            try:
                chosen_first_mid = int(chosen_first.get("message_id", 0))
                if chosen_first_mid > 0 and (cleanup_min_mid <= 0 or chosen_first_mid < cleanup_min_mid):
                    cleanup_min_mid = chosen_first_mid
            except Exception:
                pass

        first_buttons = get_callback_buttons(chosen_first)
        first_pi = extract_page_info(chosen_first.get("text"))
        first_total_items = extract_total_items(chosen_first.get("text"))

        if first_pi and first_pi.get("current_page") is not None:
            try:
                visited_pages.add(int(first_pi.get("current_page")))
            except Exception:
                pass

        timeline.append({
            "step": 0,
            "status": "first_state",
            "message_id": chosen_first.get("message_id"),
            "chat_id": chosen_first.get("chat_id"),
            "page_info": first_pi,
            "total_items": first_total_items,
            "buttons_text": summarize_buttons(first_buttons),
            "text_preview": (chosen_first.get("text") or "")[:200]
        })

        if _is_bot_not_found_message(chosen_first):
            cleanup_after_return_enabled = False
            await _cleanup_not_found_messages(
                payload.bot_username,
                chosen_first,
                min_message_id=int(cleanup_min_mid or 0)
            )
            return await _return_ok("not found message detected; stop")

        _mark_total_items_limit_exceeded(first_total_items)
        if total_items_exceeded_limit:
            timeline.append({
                "step": 0,
                "status": "done",
                "reason": "reported total_items exceeded limit before pagination",
                "total_items": first_total_items,
                "limit": int(total_items_exceeded_limit_limit or 0),
            })
            return await _return_ok("reported total_items exceeded limit before pagination")

        no_new_files_rounds = 0
        last_files_unique_count = 0
        assumed_total_items: Optional[int] = None

        while steps < int(payload.max_steps or 0):
            steps = steps + 1
            await backfill_latest_from_bot(payload.bot_username, limit=460, timeout_seconds=6.0, max_logs=int(payload.debug_max_logs or 0), step=steps, force=True)

            chosen_now = (
                find_latest_pagination_callback_message(
                    payload.bot_username,
                    next_keywords=payload.next_text_keywords,
                    skip_invalid=True,
                    max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                    scan_limit=int(payload.callback_candidate_scan_limit or 0),
                    min_message_id=int(_current_run_min_message_id() or 0)
                )
                or _filter_current_run_message(find_latest_callback_message(payload.bot_username, skip_invalid=True))
                or _choose_best_state_message(
                    bot_username=payload.bot_username,
                    next_keywords=payload.next_text_keywords,
                    max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                    scan_limit=int(payload.callback_candidate_scan_limit or 0),
                    min_message_id=int(_current_run_min_message_id() or 0)
                )
            )

            if not chosen_now:
                latest_any = _find_latest_any_current_run(require_meaningful=False)
                if _is_bot_not_found_message(latest_any):
                    cleanup_after_return_enabled = False
                    await _cleanup_not_found_messages(
                        payload.bot_username,
                        latest_any,
                        min_message_id=int(cleanup_min_mid or 0),
                        sent_message_id=int(cleanup_min_mid or 0)
                    )
                    timeline.append({
                        "step": steps,
                        "status": "done",
                        "reason": "not found message detected; stop",
                        "message_id": latest_any.get("message_id"),
                        "text_preview": (latest_any.get("text") or "")[:200]
                    })
                    return await _return_ok("not found message detected; stop")
                latest_any = _find_latest_any_current_run(require_meaningful=True)
                if _is_bot_completion_message(latest_any):
                    timeline.append({
                        "step": steps,
                        "status": "done",
                        "reason": "completion message detected; stop",
                        "message_id": latest_any.get("message_id"),
                        "text_preview": (latest_any.get("text") or "")[:200]
                    })
                    return await _return_ok("completion message detected; stop")
                timeline.append({"step": steps, "status": "observe", "reason": "no state message; sleep"})
                await asyncio.sleep(float(payload.observe_when_no_controls_poll_seconds or 0.5))
                continue

            buttons_now = get_callback_buttons(chosen_now)
            state = _pagination_state_from_message(chosen_now, next_keywords=payload.next_text_keywords) or {}
            pi_now = state.get("page_info")
            total_items_now = state.get("total_items")

            if pi_now and not bool(state.get("has_buttons")):
                alt_chosen_now = (
                    find_latest_pagination_callback_message(
                        payload.bot_username,
                        next_keywords=payload.next_text_keywords,
                        skip_invalid=True,
                        max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                        scan_limit=int(payload.callback_candidate_scan_limit or 0),
                        min_message_id=int(cleanup_min_mid or 0)
                    )
                    or _filter_current_run_message(find_latest_callback_message(payload.bot_username, skip_invalid=True))
                )
                if alt_chosen_now:
                    alt_state = _pagination_state_from_message(alt_chosen_now, next_keywords=payload.next_text_keywords) or {}
                    alt_pi_now = alt_state.get("page_info")
                    if bool(alt_state.get("has_buttons")) and (bool(alt_state.get("is_pagination_like")) or bool(alt_pi_now)):
                        chosen_now = alt_chosen_now
                        state = alt_state
                        pi_now = state.get("page_info")
                        total_items_now = state.get("total_items")

            latest_any = _find_latest_any_current_run(require_meaningful=True)
            if latest_any:
                latest_any_mid = int(latest_any.get("message_id", 0) or 0)
                chosen_now_mid = int(chosen_now.get("message_id", 0) or 0)
                if latest_any_mid >= chosen_now_mid and _is_bot_not_found_message(latest_any):
                    cleanup_after_return_enabled = False
                    await _cleanup_not_found_messages(
                        payload.bot_username,
                        latest_any,
                        min_message_id=int(cleanup_min_mid or 0)
                    )
                    timeline.append({
                        "step": steps,
                        "status": "done",
                        "reason": "not found message detected; stop",
                        "message_id": latest_any.get("message_id"),
                        "text_preview": (latest_any.get("text") or "")[:200]
                    })
                    return await _return_ok("not found message detected; stop")
                if latest_any_mid > chosen_now_mid and _is_bot_completion_message(latest_any):
                    timeline.append({
                        "step": steps,
                        "status": "done",
                        "reason": "completion message detected; stop",
                        "message_id": latest_any.get("message_id"),
                        "text_preview": (latest_any.get("text") or "")[:200]
                    })
                    return await _return_ok("completion message detected; stop")

            if assumed_total_items is None and total_items_now is not None:
                try:
                    assumed_total_items = int(total_items_now)
                except Exception:
                    assumed_total_items = None
            _mark_total_items_limit_exceeded(total_items_now)
            if total_items_exceeded_limit:
                timeline.append({
                    "step": steps,
                    "status": "done",
                    "reason": "reported total_items exceeded limit before pagination",
                    "total_items": total_items_now,
                    "limit": int(total_items_exceeded_limit_limit or 0),
                })
                return await _return_ok("reported total_items exceeded limit before pagination")

            if pi_now and pi_now.get("current_page") is not None:
                try:
                    visited_pages.add(int(pi_now.get("current_page")))
                except Exception:
                    pass

            files_meta_now = collect_files_from_store(
                payload.bot_username,
                int(payload.max_return_files),
                int(payload.max_raw_payload_bytes),
                min_message_id=int(_current_run_min_message_id() or 0)
            )
            files_unique_now = int(files_meta_now.get("files_unique_count", files_meta_now.get("files_count", 0)))

            timeline.append({
                "step": steps,
                "status": "state",
                "message_id": chosen_now.get("message_id"),
                "chat_id": chosen_now.get("chat_id"),
                "page_info": pi_now,
                "total_items": total_items_now,
                "files_unique_count": files_unique_now,
                "buttons_text": summarize_buttons(buttons_now)
            })

            if files_unique_now <= last_files_unique_count:
                no_new_files_rounds = no_new_files_rounds + 1
            else:
                no_new_files_rounds = 0
                last_files_unique_count = files_unique_now

            if payload.stop_when_reached_total_items and assumed_total_items is not None:
                if int(files_unique_now) >= int(assumed_total_items):
                    # 即使已達到 total_items，也要優先把頁碼按到最後一頁 / 全部頁面跑完（避免提早停在中間頁）
                    if payload.stop_need_confirm_pagination_done:
                        if _pagination_confirmed_last_page(state):
                            timeline.append({"step": steps, "status": "done", "reason": "reached total items + last page confirmed; stop"})
                            return await _return_ok("reached total items + last page confirmed; stop")
                        if payload.stop_need_last_page_or_all_pages:
                            if _pagination_confirmed_all_pages_visited(state, visited_pages):
                                timeline.append({"step": steps, "status": "done", "reason": "reached total items + all pages visited; stop"})
                                return await _return_ok("reached total items + all pages visited; stop")
                    timeline.append({"step": steps, "status": "note", "reason": "reached total items but continue clicking pages"})

            threshold_no_new = int(payload.stop_when_no_new_files_rounds or 0)
            if threshold_no_new > 0 and int(no_new_files_rounds) >= threshold_no_new:
                if payload.stop_need_confirm_pagination_done:
                    if _pagination_confirmed_last_page(state):
                        timeline.append({"step": steps, "status": "done", "reason": "last page confirmed; stop"})
                        return await _return_ok("last page confirmed; stop")
                    if payload.stop_need_last_page_or_all_pages:
                        if _pagination_confirmed_all_pages_visited(state, visited_pages):
                            timeline.append({"step": steps, "status": "done", "reason": "all pages visited; stop"})
                            return await _return_ok("all pages visited; stop")
                # 沒有新增檔案也不要直接停，繼續按頁碼直到最後一頁 / 全部頁碼都按過（避免卡在中間頁）
                timeline.append({"step": steps, "status": "note", "reason": "no new files rounds reached but continue clicking pages"})

            if payload.stop_need_confirm_pagination_done:
                if _pagination_confirmed_last_page(state):
                    timeline.append({"step": steps, "status": "done", "reason": "last page confirmed; stop"})
                    return await _return_ok("last page confirmed; stop")

            ok_click, clicked_text, used_msg = await _safe_click_pagination_button(
                bot_username=payload.bot_username,
                next_keywords=payload.next_text_keywords,
                max_logs=int(payload.debug_max_logs or 0),
                step=steps,
                max_retries=2,
                wait_each_page_timeout_seconds=int(payload.wait_each_page_timeout_seconds or 0),
                max_return_files=int(payload.max_return_files or 0),
                max_raw_payload_bytes=int(payload.max_raw_payload_bytes or 0),
                callback_message_max_age_seconds=int(payload.callback_message_max_age_seconds or 0),
                callback_candidate_scan_limit=int(payload.callback_candidate_scan_limit or 0),
                min_message_id=int(_current_run_min_message_id() or 0)
            )

            if used_msg and used_msg.get("message_id"):
                try:
                    if cleanup_min_mid <= 0:
                        cleanup_min_mid = int(used_msg.get("message_id", 0))
                except Exception:
                    pass

            if not ok_click:
                if clicked_text == "clicked_but_no_change":
                    timeline.append({"step": steps, "status": "note", "reason": "clicked_but_no_change; retry"})
                    await asyncio.sleep(1.0)
                    continue

                # next-only bot 可能會短暫抓不到分頁控制訊息（例如訊息文字格式變化或回覆延遲），但仍有 next 按鈕時不要立刻停
                try:
                    if clicked_text == "no_click" and bool(state.get("has_next")):
                        timeline.append({"step": steps, "status": "note", "reason": "no_click but has_next; retry"})
                        await asyncio.sleep(1.0)
                        continue
                except Exception:
                    pass

                timeline.append({"step": steps, "status": "done", "reason": clicked_text})
                return await _return_ok(clicked_text)

            if clicked_text:
                timeline.append({"step": steps, "status": "clicked", "clicked": clicked_text})

            await asyncio.sleep(0.2)

        timeline.append({"step": steps, "status": "done", "reason": "max_steps reached"})
        return await _return_ok("max_steps reached")

    except Exception:
        timeline.append({"step": steps, "status": "fail", "reason": "exception", "err": traceback.format_exc()})
        return await _return_fail("exception")
