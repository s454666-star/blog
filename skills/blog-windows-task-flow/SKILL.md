---
name: blog-windows-task-flow
description: Use when inspecting or changing the Windows Task Scheduler, batch files, PowerShell launchers, Laravel artisan commands, or local Telegram FastAPI integration for C:\www\blog.
---

# Blog Windows Task Flow

Use this skill when the user asks any of these:

- 目前 `C:\www\blog` 的 Windows 工作排程怎麼接
- 哪個工作排程會叫哪個 `.bat` 或 `.ps1`
- `.bat` 之後怎麼進 Laravel `artisan`
- blog 專案怎麼打本機 Telegram FastAPI API
- 要新增、修改、重建 blog 相關的 Windows 排程

## Start Here

1. Read `references/current-task-flow.md`.
2. Inspect the current relevant scheduled tasks with `scripts/show-relevant-scheduled-tasks.ps1`.
3. Read the repo file that matches the flow you need to change.
4. If the flow touches Telegram FastAPI startup, also verify ports `8000` and `8001` are actually listening before assuming the task is healthy.

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

- `get-bt`
  - manual run or Windows Task Scheduler
  - `C:\www\blog\scripts\run_get_bt.bat`
  - `php artisan get-bt`
  - `App\Console\Commands\CrawlerBtCommand`
  - `App\Http\Controllers\GetBtDataController`
  - remote source `https://sukebei.nyaa.si`

## Operating Rules

- Prefer wrapper `.bat` files under `C:\www\blog\scripts` so Task Scheduler actions stay simple.
- Use absolute Windows paths in tasks and scripts.
- Keep the handoff chain explicit: Task Scheduler -> `.bat` -> `.ps1` if needed -> `php artisan` -> Laravel command/controller -> API.
- For Telegram flows, verify both task state and actual listener port before treating the service as healthy.
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

## Validation

- Query the relevant scheduled task after any change.
- Confirm the target script path exists.
- If the task launches Laravel, run the `.bat` manually once when practical.
- If the flow depends on Telegram FastAPI, confirm the port and the scheduled task agree on the same target.
