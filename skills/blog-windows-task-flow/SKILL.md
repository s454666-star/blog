---
name: blog-windows-task-flow
description: Use when inspecting or changing the Windows Task Scheduler, batch files, PowerShell launchers, Laravel artisan commands, local Telegram FastAPI integration, or folder-video / Caddy startup around C:\www\blog, especially when task query access is denied and the live flow must be reconstructed from processes, ports, wrapper scripts, and runtime state.
---

# Blog Windows Task Flow

Use this skill when the user asks any of these:

- 目前 `C:\www\blog` 的 Windows 工作排程怎麼接
- 哪個工作排程會叫哪個 `.bat` 或 `.ps1`
- `.bat` 之後怎麼進 Laravel `artisan`
- blog 專案怎麼打本機 Telegram FastAPI API
- 畫面上看得到 Task Scheduler 任務，但 `schtasks` / `Get-ScheduledTask` 查不到或 `Access is denied`
- Caddy / folder video / log cleanup / get-bt 這些 blog 周邊任務現在怎麼跑
- 要新增、修改、重建 blog 相關的 Windows 排程

## Start Here

1. Read `references/current-task-flow.md`.
2. Inspect the current relevant scheduled tasks with `scripts/show-relevant-scheduled-tasks.ps1`.
3. If a task query returns `Access is denied`, do not stop there. Reconstruct the flow from:
   - `Win32_Process` command lines
   - `Get-NetTCPConnection` / `netstat`
   - wrapper `.bat` / `.ps1`
   - runtime state files such as `storage\app\folder-video-server\state.json` or Caddy autosave
   - recent log files under `C:\www\blog\storage\logs`
4. Read the repo file that matches the flow you need to change.
5. If the flow touches Telegram FastAPI startup, also verify ports `8000` and `8001` are actually listening and responding before assuming the task is healthy.

## Current Flow Entry Points

- `TG Token Scan Dispatch`
  - Windows Task Scheduler
  - `C:\www\blog\scripts\tg_scan_dispatch.bat`
  - `php artisan tg:scan-group-tokens`
  - `php artisan tg:dispatch-token-scan-items --port=8000/8001`
  - local Telegram FastAPI on `127.0.0.1:8000` or `127.0.0.1:8001`

- `EnsureTgScanGroupMediaHourly`
  - Windows Task Scheduler
  - `C:\www\blog\scripts\ensure_tg_scan_group_media.bat`
  - `C:\www\blog\scripts\ensure_tg_scan_group_media.ps1`
  - `C:\www\blog\scripts\tg_scan_group_media.bat`
  - `php artisan tg:scan-group-media`
  - local Telegram FastAPI `groups/*` endpoints

- `Telegram FastAPI Service`, `TG API2`, `Telegram FastAPI Services`
  - Windows Task Scheduler
  - `C:\Users\User\Pictures\train\start_telegram_service*.bat`
  - `python -m uvicorn telegram_service:app --port 8000`
  - `python -m uvicorn telegram_service2:app --port 8001`
  - observe both scheduled-task state and live listener state; they can drift

- `get-bt`
  - manual run or Windows Task Scheduler
  - `C:\www\blog\scripts\run_get_bt.bat`
  - `php artisan get-bt`
  - `App\Console\Commands\CrawlerBtCommand`
  - `App\Http\Controllers\GetBtDataController`
  - remote source `https://sukebei.nyaa.si`

- `Project Log Cleanup`
  - Windows Task Scheduler
  - `C:\www\blog\scripts\clear_project_logs.bat`
  - `C:\www\blog\scripts\clear_project_logs.ps1`
  - deletes untracked `*.log` under `C:\www\blog` and `C:\Users\User\Pictures\train`

- folder video LAN API / Caddy
  - wrapper `C:\www\blog\scripts\start-folder-video-api.bat`
  - launcher `C:\www\blog\scripts\start-folder-video-api.ps1`
  - managed state under `C:\www\blog\storage\app\folder-video-server`
  - live reverse proxy currently observed on port `8090`
  - if the visible Task Scheduler entry is inaccessible, infer from listeners, Caddy state, and wrapper scripts

## Operating Rules

- Prefer wrapper `.bat` files under `C:\www\blog\scripts` so Task Scheduler actions stay simple.
- Use absolute Windows paths in tasks and scripts.
- Keep the handoff chain explicit: Task Scheduler -> `.bat` -> `.ps1` if needed -> `php artisan` -> Laravel command/controller -> API.
- For Telegram flows, verify both task state and actual listener port before treating the service as healthy.
- If Task Scheduler query is blocked by permissions, treat that as a normal branch of the workflow, not a blocker.
- When a task is inaccessible, prove the flow by correlating process parent/child chains, open ports, batch targets, and recent logs.
- `get-bt` Windows tasks should call `php artisan get-bt`, not `command:get-bt`.
- If a task must run at both `05:00` and `17:00`, prefer one scheduled task with two triggers so monitoring and maintenance stay in one place.

## Files To Read By Flow

- Telegram task orchestration:
  - `references/current-task-flow.md`
  - `C:\www\blog\scripts\tg_scan_dispatch.bat`
  - `C:\www\blog\app\Console\Commands\ScanGroupTokensCommand.php`
  - `C:\www\blog\app\Console\Commands\DispatchTokenScanItemsCommand.php`

- Telegram group media download:
  - `references/current-task-flow.md`
  - `C:\www\blog\scripts\ensure_tg_scan_group_media.bat`
  - `C:\www\blog\scripts\ensure_tg_scan_group_media.ps1`
  - `C:\www\blog\scripts\tg_scan_group_media.bat`
  - `C:\www\blog\app\Console\Commands\ScanGroupMediaCommand.php`

- BT crawler:
  - `references/current-task-flow.md`
  - `C:\www\blog\scripts\run_get_bt.bat`
  - `C:\www\blog\app\Console\Commands\CrawlerBtCommand.php`
  - `C:\www\blog\app\Http\Controllers\GetBtDataController.php`
  - `C:\www\blog\app\Console\Kernel.php`

- Log cleanup:
  - `references/current-task-flow.md`
  - `C:\www\blog\scripts\clear_project_logs.bat`
  - `C:\www\blog\scripts\clear_project_logs.ps1`

- Folder video / Caddy:
  - `references/current-task-flow.md`
  - `C:\www\blog\scripts\start-folder-video-api.bat`
  - `C:\www\blog\scripts\start-folder-video-api.ps1`
  - `C:\www\blog\scripts\stop-folder-video-api.ps1`
  - `C:\www\blog\scripts\register-folder-video-api-caddy-startup-task.ps1`
  - `C:\www\blog\storage\app\folder-video-server\state.json`
  - `C:\www\blog\storage\app\folder-video-server\Caddyfile`
  - `C:\Users\User\AppData\Roaming\Caddy\autosave.json`

## Validation

- Query the relevant scheduled task after any change.
- Confirm the target script path exists.
- If the task launches Laravel, run the `.bat` manually once when practical.
- If the flow depends on Telegram FastAPI, confirm the port and the scheduled task agree on the same target.
- If task query is denied, verify the live flow with `Get-CimInstance Win32_Process`, `Get-NetTCPConnection`, `netstat`, and the wrapper/log/state files instead.
