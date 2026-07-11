#!/usr/bin/env python3
"""High-throughput byte-range server plus background Folder Video preview worker."""

from __future__ import annotations

import argparse
import email.utils
import json
import mimetypes
import os
from pathlib import Path
import re
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote, urlsplit
import uuid


BUFFER_SIZE = 1024 * 1024
RANGE_PATTERN = re.compile(r"^bytes=(\d*)-(\d*)$")


def is_within(path: Path, root: Path) -> bool:
    try:
        path.resolve().relative_to(root.resolve())
        return True
    except (OSError, ValueError):
        return False


class RangeRequestHandler(BaseHTTPRequestHandler):
    server_version = "FolderVideoRange/1.0"

    def do_HEAD(self) -> None:  # noqa: N802
        self._serve_file(False)

    def do_GET(self) -> None:  # noqa: N802
        self._serve_file(True)

    def log_message(self, fmt: str, *args: object) -> None:
        return

    def _serve_file(self, send_body: bool) -> None:
        raw_path = unquote(urlsplit(self.path).path)
        preview_prefix = "/folder-video-preview-cache/"
        hls_prefix = "/folder-video-tv-hls-cache/"
        if raw_path.startswith(hls_prefix):
            root: Path = self.server.hls_root  # type: ignore[attr-defined]
            relative = raw_path[len(hls_prefix) :].replace("/", os.sep)
        elif raw_path.startswith(preview_prefix):
            root: Path = self.server.preview_root  # type: ignore[attr-defined]
            relative = raw_path[len(preview_prefix) :].replace("/", os.sep)
        else:
            root = self.server.media_root  # type: ignore[attr-defined]
            relative = raw_path.lstrip("/").replace("/", os.sep)
        if not relative or "\x00" in relative:
            self.send_error(404)
            return

        candidate = (root / relative).resolve()
        if not is_within(candidate, root) or not candidate.is_file():
            self.send_error(404)
            return

        try:
            stat = candidate.stat()
            start, end, partial = self._requested_range(stat.st_size)
        except ValueError:
            self.send_response(416)
            self.send_header("Content-Range", f"bytes */{candidate.stat().st_size}")
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        except OSError:
            self.send_error(404)
            return

        length = end - start + 1
        content_type = {
            ".m3u8": "application/vnd.apple.mpegurl",
            ".ts": "video/mp2t",
        }.get(candidate.suffix.lower()) or mimetypes.guess_type(candidate.name)[0] or "application/octet-stream"
        self.send_response(206 if partial else 200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(length))
        self.send_header("Accept-Ranges", "bytes")
        if candidate.suffix.lower() == ".m3u8":
            self.send_header("Cache-Control", "no-store, no-cache, must-revalidate")
        elif candidate.suffix.lower() == ".ts":
            self.send_header("Cache-Control", "private, max-age=31536000, immutable")
        else:
            self.send_header("Cache-Control", "private, max-age=600")
        self.send_header("Last-Modified", email.utils.formatdate(stat.st_mtime, usegmt=True))
        self.send_header("X-Content-Type-Options", "nosniff")
        if partial:
            self.send_header("Content-Range", f"bytes {start}-{end}/{stat.st_size}")
        self.end_headers()

        if not send_body:
            return

        remaining = length
        try:
            with candidate.open("rb", buffering=BUFFER_SIZE) as source:
                source.seek(start)
                while remaining > 0:
                    chunk = source.read(min(BUFFER_SIZE, remaining))
                    if not chunk:
                        break
                    self.wfile.write(chunk)
                    remaining -= len(chunk)
        except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError):
            return

    def _requested_range(self, size: int) -> tuple[int, int, bool]:
        header = self.headers.get("Range", "").strip()
        if not header:
            return 0, max(0, size - 1), False

        match = RANGE_PATTERN.match(header)
        if not match or size <= 0:
            raise ValueError("Unsupported range")

        start_text, end_text = match.groups()
        if start_text == "":
            suffix = int(end_text or "0")
            if suffix <= 0:
                raise ValueError("Invalid suffix")
            start = max(0, size - suffix)
            end = size - 1
        else:
            start = int(start_text)
            end = int(end_text) if end_text else size - 1

        if start < 0 or start >= size or end < start:
            raise ValueError("Out of range")
        return start, min(end, size - 1), True


def transcode_preview(
    ffmpeg: str,
    source: Path,
    destination: Path,
    seconds: int,
    height: int,
) -> bool:
    destination.parent.mkdir(parents=True, exist_ok=True)
    temporary = destination.with_name(destination.name + f".tmp.{uuid.uuid4().hex}.mp4")
    common = [
        ffmpeg,
        "-hide_banner",
        "-loglevel",
        "error",
        "-y",
        "-ss",
        "0",
        "-i",
        str(source),
        "-t",
        str(seconds),
        "-vf",
        f"scale=-2:{height}",
        "-an",
    ]
    commands = [
        common
        + [
            "-c:v",
            "h264_nvenc",
            "-preset",
            "p2",
            "-tune",
            "ll",
            "-b:v",
            "900k",
            "-maxrate",
            "1400k",
            "-bufsize",
            "2800k",
            "-pix_fmt",
            "yuv420p",
            "-movflags",
            "+faststart",
            str(temporary),
        ],
        common
        + [
            "-c:v",
            "libx264",
            "-preset",
            "veryfast",
            "-tune",
            "fastdecode",
            "-crf",
            "32",
            "-pix_fmt",
            "yuv420p",
            "-movflags",
            "+faststart",
            str(temporary),
        ],
    ]

    creation_flags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    try:
        for command in commands:
            temporary.unlink(missing_ok=True)
            result = subprocess.run(
                command,
                stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                timeout=240,
                check=False,
                creationflags=creation_flags,
            )
            if result.returncode == 0 and temporary.is_file() and temporary.stat().st_size > 0:
                os.replace(temporary, destination)
                return True
    except (OSError, subprocess.TimeoutExpired):
        pass
    finally:
        temporary.unlink(missing_ok=True)
    return False


def transcode_animated_preview(ffmpeg: str, source: Path, destination: Path) -> bool:
    destination.parent.mkdir(parents=True, exist_ok=True)
    temporary = destination.with_name(destination.stem + f".tmp.{uuid.uuid4().hex}.webp")
    command = [
        ffmpeg, "-hide_banner", "-loglevel", "error", "-y", "-t", "3", "-i", str(source),
        "-vf", "fps=5,scale=320:180:force_original_aspect_ratio=increase,crop=320:180",
        "-an", "-c:v", "libwebp_anim", "-lossless", "0", "-quality", "48",
        "-compression_level", "3", "-loop", "0", str(temporary),
    ]
    creation_flags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    try:
        result = subprocess.run(
            command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL, timeout=180, check=False, creationflags=creation_flags,
        )
        if result.returncode == 0 and temporary.is_file() and temporary.stat().st_size > 0:
            os.replace(temporary, destination)
            return True
    except (OSError, subprocess.TimeoutExpired):
        pass
    finally:
        temporary.unlink(missing_ok=True)
    return False


def cleanup_hls_output(hls_path: Path) -> None:
    for stale in hls_path.glob("segment_*.ts*"):
        stale.unlink(missing_ok=True)
    for stale_name in (
        "index.m3u8", "index.m3u8.publish", "index.working.m3u8",
        "index.working.m3u8.tmp", ".complete",
    ):
        (hls_path / stale_name).unlink(missing_ok=True)


def publish_hls_playlist(hls_path: Path) -> bool:
    working = hls_path / "index.working.m3u8"
    public = hls_path / "index.m3u8"
    temporary = hls_path / "index.m3u8.publish"
    try:
        first = working.read_bytes()
        if not first.startswith(b"#EXTM3U") or b"#EXTINF:" not in first:
            return False
        time.sleep(0.01)
        second = working.read_bytes()
        if first != second:
            return False
        if b"#EXT-X-START:" not in second:
            newline = b"\r\n" if b"\r\n" in second[:32] else b"\n"
            marker = b"#EXT-X-START:TIME-OFFSET=0,PRECISE=YES" + newline
            first_line_end = second.find(newline)
            if first_line_end >= 0:
                first_line_end += len(newline)
                second = second[:first_line_end] + marker + second[first_line_end:]
        temporary.write_bytes(second)
        os.replace(temporary, public)
        return True
    except OSError:
        temporary.unlink(missing_ok=True)
        return False


def stop_process(process: subprocess.Popen[bytes]) -> None:
    try:
        process.terminate()
        process.wait(timeout=3)
    except (OSError, subprocess.TimeoutExpired):
        try:
            process.kill()
            process.wait(timeout=3)
        except (OSError, subprocess.TimeoutExpired):
            pass


def transcode_hls(
    ffmpeg: str,
    source: Path,
    hls_path: Path,
    segment_seconds: int,
    cancel_check,
) -> bool:
    hls_path.mkdir(parents=True, exist_ok=True)
    cleanup_hls_output(hls_path)

    common = [
        ffmpeg, "-hide_banner", "-loglevel", "error", "-y",
        "-probesize", "1M", "-analyzeduration", "1000000", "-i", str(source),
        "-vf", "scale=-2:720,fps=30", "-c:a", "aac", "-b:a", "128k", "-ac", "2",
    ]
    hls = [
        "-force_key_frames", f"expr:gte(t,n_forced*{segment_seconds})",
        "-f", "hls", "-hls_time", str(segment_seconds), "-hls_list_size", "0",
        "-hls_playlist_type", "event", "-hls_flags", "independent_segments",
        "-hls_segment_filename", str(hls_path / "segment_%05d.ts"), str(hls_path / "index.working.m3u8"),
    ]
    commands = [
        common + [
            "-c:v", "h264_nvenc", "-preset", "p1", "-tune", "ll",
            "-b:v", "2800k", "-maxrate", "3500k", "-bufsize", "7000k",
            "-g", "60", "-bf", "0", "-pix_fmt", "yuv420p",
        ] + hls,
        common + [
            "-c:v", "libx264", "-preset", "veryfast", "-tune", "fastdecode",
            "-b:v", "2800k", "-maxrate", "3500k", "-bufsize", "7000k",
            "-g", "60", "-bf", "0", "-pix_fmt", "yuv420p",
        ] + hls,
    ]
    creation_flags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    for command in commands:
        process = None
        try:
            process = subprocess.Popen(
                command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL, creationflags=creation_flags,
            )
            deadline = time.monotonic() + 14400
            while process.poll() is None:
                publish_hls_playlist(hls_path)
                if cancel_check():
                    stop_process(process)
                    cleanup_hls_output(hls_path)
                    return False
                if time.monotonic() >= deadline:
                    stop_process(process)
                    break
                time.sleep(0.2)
            publish_hls_playlist(hls_path)
            if process.returncode == 0 and (hls_path / "index.m3u8").is_file():
                (hls_path / "index.working.m3u8").unlink(missing_ok=True)
                (hls_path / ".complete").write_text("ok", encoding="ascii")
                return True
        except OSError:
            if process is not None and process.poll() is None:
                stop_process(process)
            pass
    cleanup_hls_output(hls_path)
    return False


def preview_worker(
    stop_event: threading.Event,
    media_root: Path,
    queue_root: Path,
    preview_root: Path,
    ffmpeg: str,
    seconds: int,
    height: int,
) -> None:
    queue_root.mkdir(parents=True, exist_ok=True)
    preview_root.mkdir(parents=True, exist_ok=True)
    for stale in queue_root.glob("*.working"):
        try:
            stale.rename(stale.with_suffix(".json"))
        except OSError:
            pass

    while not stop_event.is_set():
        queued = sorted(queue_root.glob("*.json"), key=lambda path: path.stat().st_mtime)
        if not queued:
            stop_event.wait(0.25)
            continue

        request_path = queued[0]
        working_path = request_path.with_suffix(".working")
        try:
            os.replace(request_path, working_path)
            payload = json.loads(working_path.read_text(encoding="utf-8"))
            source = Path(str(payload.get("source_path", ""))).resolve()
            destination = Path(str(payload.get("preview_path", ""))).resolve()
            if not is_within(source, media_root) or not source.is_file():
                continue
            if not is_within(destination, preview_root):
                continue
            if destination.is_file() and destination.stat().st_size > 0:
                continue
            if payload.get("kind") == "animated_webp":
                transcode_animated_preview(ffmpeg, source, destination)
            else:
                transcode_preview(ffmpeg, source, destination, seconds, height)
        except (OSError, ValueError, json.JSONDecodeError):
            pass
        finally:
            working_path.unlink(missing_ok=True)


def hls_worker(
    stop_event: threading.Event,
    source_roots: list[Path],
    queue_root: Path,
    hls_root: Path,
    ffmpeg: str,
    segment_seconds: int,
) -> None:
    queue_root.mkdir(parents=True, exist_ok=True)
    hls_root.mkdir(parents=True, exist_ok=True)
    for stale in queue_root.glob("*.working"):
        stale.rename(stale.with_suffix(".json"))
    while not stop_event.is_set():
        queued = sorted(queue_root.glob("*.json"), key=lambda path: path.stat().st_mtime_ns, reverse=True)
        if not queued:
            stop_event.wait(0.25)
            continue
        request_path = queued[0]
        for obsolete in queued[1:]:
            obsolete.unlink(missing_ok=True)
        working_path = request_path.with_suffix(".working")
        try:
            request_mtime = request_path.stat().st_mtime_ns
            os.replace(request_path, working_path)
            payload = json.loads(working_path.read_text(encoding="utf-8"))
            source = Path(str(payload.get("source_path", ""))).resolve()
            hls_path = Path(str(payload.get("hls_path", ""))).resolve()
            if not any(is_within(source, root) for root in source_roots) or not source.is_file():
                continue
            if not is_within(hls_path, hls_root):
                continue
            if (hls_path / ".complete").is_file():
                continue
            def has_newer_request() -> bool:
                if stop_event.is_set():
                    return True
                try:
                    return any(path.stat().st_mtime_ns > request_mtime for path in queue_root.glob("*.json"))
                except OSError:
                    return False

            transcode_hls(ffmpeg, source, hls_path, segment_seconds, has_newer_request)
        except (OSError, ValueError, json.JSONDecodeError):
            pass
        finally:
            working_path.unlink(missing_ok=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8092)
    parser.add_argument("--root", required=True)
    parser.add_argument("--preview-queue", required=True)
    parser.add_argument("--preview-root", required=True)
    parser.add_argument("--hls-queue", required=True)
    parser.add_argument("--hls-root", required=True)
    parser.add_argument("--hls-segment-seconds", type=int, default=2)
    parser.add_argument("--hls-source-root", action="append", default=[])
    parser.add_argument("--ffmpeg", default="ffmpeg")
    parser.add_argument("--preview-seconds", type=int, default=18)
    parser.add_argument("--preview-height", type=int, default=360)
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    media_root = Path(args.root).resolve()
    queue_root = Path(args.preview_queue).resolve()
    preview_root = Path(args.preview_root).resolve()
    hls_queue_root = Path(args.hls_queue).resolve()
    hls_root = Path(args.hls_root).resolve()
    hls_source_roots = [Path(path).resolve() for path in args.hls_source_root]
    if not hls_source_roots:
        hls_source_roots = [media_root]
    stop_event = threading.Event()
    for worker_index in range(2):
        worker = threading.Thread(
            target=preview_worker,
            args=(
                stop_event,
                media_root,
                queue_root,
                preview_root,
                args.ffmpeg,
                max(4, min(args.preview_seconds, 120)),
                max(144, min(args.preview_height, 720)),
            ),
            daemon=True,
            name=f"folder-video-preview-worker-{worker_index + 1}",
        )
        worker.start()
    hls_thread = threading.Thread(
        target=hls_worker,
        args=(
            stop_event,
            hls_source_roots,
            hls_queue_root,
            hls_root,
            args.ffmpeg,
            max(1, min(args.hls_segment_seconds, 10)),
        ),
        daemon=True,
        name="folder-video-hls-worker",
    )
    hls_thread.start()

    server = ThreadingHTTPServer((args.host, args.port), RangeRequestHandler)
    server.daemon_threads = True
    server.media_root = media_root  # type: ignore[attr-defined]
    server.preview_root = preview_root  # type: ignore[attr-defined]
    server.hls_root = hls_root  # type: ignore[attr-defined]
    try:
        server.serve_forever(poll_interval=0.25)
    finally:
        stop_event.set()
        server.server_close()


if __name__ == "__main__":
    main()
