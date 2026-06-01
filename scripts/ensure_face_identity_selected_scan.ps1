$ErrorActionPreference = 'Stop'

$appDir = 'C:\www\blog'
$trainDir = 'C:\Users\User\Pictures\train'
$pythonPath = 'C:\Users\User\AppData\Local\Microsoft\WindowsApps\PythonSoftwareFoundation.Python.3.11_qbz5n2kfra8p0\python.exe'
$scanScript = Join-Path $trainDir 'face_identity_scan.py'
$selectedFolderName = [string]([char]0x7cbe) + [string]([char]0x9078)
$targetRoot = Join-Path '\\10.0.0.2\30T-A\FC2-2026(new)' $selectedFolderName
$logDir = Join-Path $trainDir 'logs'
$watchdogLog = Join-Path $logDir 'face_identity_selected_watchdog.log'
$statePath = Join-Path $logDir 'face_identity_selected_watchdog_state.json'
$pidPath = Join-Path $logDir 'face_identity_selected_watchdog.pid'

function Write-Log {
    param(
        [string] $Message
    )

    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -Path $watchdogLog -Value "[${timestamp}] $Message"
}

function Write-State {
    param(
        [object] $Payload
    )

    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }

    $Payload | ConvertTo-Json -Depth 8 | Set-Content -Path $statePath -Encoding UTF8
}

function Get-RunningFaceIdentityScanner {
    Get-CimInstance Win32_Process | Where-Object {
        $_.ProcessId -ne $PID -and
        ($_.CommandLine -like '*face_identity_scan.py*')
    }
}

function Get-PendingSummary {
    $env:FACE_IDENTITY_TARGET_ROOT = $targetRoot
    $env:BLOG_ROOT = $appDir

    $script = @'
from __future__ import annotations

from datetime import datetime
from pathlib import Path
import hashlib
import json
import os

import mysql.connector

target_root = Path(os.environ["FACE_IDENTITY_TARGET_ROOT"])
blog_root = Path(os.environ["BLOG_ROOT"])
video_extensions = {".mp4", ".mkv", ".avi", ".mov", ".wmv", ".ts", ".m4v"}


def parse_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def sha1_path(path: Path) -> str:
    normalized = str(path).replace("\\", "/").lower().strip()
    return hashlib.sha1(normalized.encode("utf-8")).hexdigest()


def file_modified_at(path: Path) -> datetime:
    return datetime.fromtimestamp(path.stat().st_mtime).replace(microsecond=0)


files: list[dict[str, object]] = []
for file_path in sorted(target_root.rglob("*")):
    if not file_path.is_file() or file_path.suffix.lower() not in video_extensions:
        continue
    absolute_path = file_path.resolve()
    stat = absolute_path.stat()
    files.append({
        "path": str(absolute_path),
        "sha1": sha1_path(absolute_path),
        "size": int(stat.st_size),
        "mtime": file_modified_at(absolute_path),
    })

env = parse_env(blog_root / ".env")
connection = mysql.connector.connect(
    host=env.get("DB_HOST"),
    port=int(env.get("DB_PORT", "3306")),
    user=env.get("DB_USERNAME"),
    password=env.get("DB_PASSWORD", ""),
    database=env.get("DB_DATABASE"),
    charset="utf8mb4",
)
cursor = connection.cursor(dictionary=True)
cursor.execute(
    """
    SELECT path_sha1, scan_status, file_size_bytes, file_modified_at
    FROM face_identity_videos
    """
)
rows = {row["path_sha1"]: row for row in cursor.fetchall()}
cursor.close()
connection.close()

missing: list[str] = []
changed: list[str] = []
incomplete: list[str] = []
status_counts: dict[str, int] = {}
matched = 0

for item in files:
    row = rows.get(item["sha1"])
    if row is None:
        missing.append(str(item["path"]))
        continue

    matched += 1
    status = str(row.get("scan_status") or "")
    status_counts[status] = status_counts.get(status, 0) + 1

    if status not in {"complete", "no_face"}:
        incomplete.append(str(item["path"]))

    db_size = int(row.get("file_size_bytes") or 0)
    if db_size != item["size"] or row.get("file_modified_at") != item["mtime"]:
        changed.append(str(item["path"]))

print(json.dumps({
    "target_root": str(target_root),
    "video_files_on_disk": len(files),
    "db_rows_matching_current_files": matched,
    "missing_db_rows": len(missing),
    "changed_files_need_rescan": len(changed),
    "incomplete_rows_need_retry": len(incomplete),
    "needs_scan": len(missing) + len(changed) + len(incomplete),
    "matched_status_counts": status_counts,
    "sample_missing_files": missing[:20],
    "sample_changed_files": changed[:20],
    "sample_incomplete_files": incomplete[:20],
}, ensure_ascii=False))
'@

    $output = $script | & $pythonPath -
    if ($LASTEXITCODE -ne 0) {
        throw "pending summary failed with exit code $LASTEXITCODE"
    }

    return $output | ConvertFrom-Json
}

if (-not (Test-Path -LiteralPath $pythonPath)) {
    Write-Log "python_missing path=$pythonPath"
    exit 1
}

if (-not (Test-Path -LiteralPath $scanScript)) {
    Write-Log "scanner_missing path=$scanScript"
    exit 1
}

if (-not (Test-Path -LiteralPath $targetRoot)) {
    Write-Log "target_missing path=$targetRoot"
    exit 1
}

$running = @(Get-RunningFaceIdentityScanner)
if ($running.Count -gt 0) {
    $pids = ($running | Select-Object -ExpandProperty ProcessId) -join ','
    Write-Log "already_running pids=$pids"
    Write-State ([ordered]@{
        checked_at = (Get-Date).ToString('s')
        target_root = $targetRoot
        status = 'already_running'
        running_pids = @($running | Select-Object -ExpandProperty ProcessId)
    })
    exit 0
}

try {
    $summary = Get-PendingSummary
} catch {
    Write-Log "preflight_failed error=$($_.Exception.Message)"
    Write-State ([ordered]@{
        checked_at = (Get-Date).ToString('s')
        target_root = $targetRoot
        status = 'preflight_failed'
        error = $_.Exception.Message
    })
    exit 1
}

$needsScan = [int] $summary.needs_scan
Write-State ([ordered]@{
    checked_at = (Get-Date).ToString('s')
    target_root = $targetRoot
    status = if ($needsScan -gt 0) { 'pending' } else { 'complete' }
    summary = $summary
})

if ($needsScan -le 0) {
    Write-Log "complete files=$($summary.video_files_on_disk) matched=$($summary.db_rows_matching_current_files)"
    exit 0
}

$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$outLog = Join-Path $logDir "face_identity_selected_watchdog_${stamp}.out.log"
$errLog = Join-Path $logDir "face_identity_selected_watchdog_${stamp}.err.log"

$env:PYTHONUTF8 = '1'
$env:PYTHONIOENCODING = 'utf-8'

$process = Start-Process `
    -FilePath $pythonPath `
    -ArgumentList @($scanScript, '--path', $targetRoot) `
    -WorkingDirectory $trainDir `
    -RedirectStandardOutput $outLog `
    -RedirectStandardError $errLog `
    -WindowStyle Hidden `
    -PassThru

Set-Content -Path $pidPath -Value $process.Id -Encoding ASCII
Write-Log "started pid=$($process.Id) needs_scan=$needsScan missing=$($summary.missing_db_rows) changed=$($summary.changed_files_need_rescan) incomplete=$($summary.incomplete_rows_need_retry) out=$outLog err=$errLog"
Write-State ([ordered]@{
    checked_at = (Get-Date).ToString('s')
    target_root = $targetRoot
    status = 'started'
    started_pid = $process.Id
    out_log = $outLog
    err_log = $errLog
    summary = $summary
})

exit 0
