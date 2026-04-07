---
name: blog-windows-task-flow
description: Use when inspecting or changing the Windows Task Scheduler, batch files, PowerShell launchers, Laravel artisan commands, local Telegram FastAPI ports `8001-8003` plus legacy `8000`, local Cloudflare tunnel / Caddy webhook routing for `@new_files_star_bot`, or folder-video startup around `C:\www\blog`.
---

# Blog Windows Task Flow

Use this skill when the user asks any of these:

- 目前 `C:\www\blog` 的 Windows 工作排程怎麼接
- 哪個工作排程會叫哪個 `.bat` 或 `.ps1`
- `.bat` 之後怎麼進 Laravel `artisan`
- blog 專案怎麼打本機 Telegram FastAPI API
- `@new_files_star_bot` / `new-files-star.mystar.monster` 為何沒反應
- 本機 Cloudflare tunnel / Caddy / Herd 怎麼把 Telegram webhook 接進 Laravel
- blog 專案怎麼在本機分攤 production `telegram_filestore` queue worker
- 畫面上看得到 Task Scheduler 任務，但 `schtasks` / `Get-ScheduledTask` 查不到或 `Access is denied`
- Caddy / folder video / log cleanup / get-bt 這些 blog 周邊任務現在怎麼跑
- 要新增、修改、重建 blog 相關的 Windows 排程

## Start Here

1. Read `references/current-task-flow.md`.
2. Inspect the current relevant scheduled tasks with `scripts/show-relevant-scheduled-tasks.ps1`.
3. If a task query returns `Access is denied` or `task not found`, do not stop there. Reconstruct the flow from:
   - `Win32_Process` command lines
   - `Get-NetTCPConnection` / `netstat`
   - wrapper `.bat` / `.ps1`
   - `C:\Users\User\.cloudflared\config.yml`
   - runtime state files such as `storage\app\folder-video-server\state.json` or Caddy autosave
   - recent log files under `C:\www\blog\storage\logs`
4. Read the repo file that matches the flow you need to change.
5. If the flow touches Telegram FastAPI, verify `8001`, `8002`, and `8003` before assuming the service is healthy. Treat `8000` as legacy unless the user explicitly asks about it.

## Current Flow Entry Points

- `TG Token Scan Dispatch`
  - Windows / manual wrapper:
    - `C:\www\blog\scripts\tg_scan_dispatch.bat`
  - current FastAPI target:
    - fixed `127.0.0.1:8001`
  - readiness task:
    - `Telegram FastAPI Services`
  - current Laravel chain:
    - `php artisan tg:scan-group-tokens`
    - `php artisan tg:dispatch-token-scan-items --port=8001`
  - current routing in `DispatchTokenScanItemsCommand`:
    - `Messengercode_*` -> `@MessengerCode_bot`
    - `QQfile_bot:*`, `vi_*`, `iv_*`, `ntmjmqbot_*`, `showfilesbot_*` -> `@showfiles12bot`
    - `yzfile_bot:*` -> `@yzfile_bot`
    - `mtfxqbot_*` -> `@mtfxq2bot`
    - `atfileslinksbot_*` -> `@atfileslinksbot`
    - `lddeebot_*` -> `@lddeebot`
    - all other scan tokens -> `@vipfiles2bot`
  - local restart recovery inside `DispatchTokenScanItemsCommand` knows these launchers:
    - `8001` -> `start_telegram_service2.bat`
    - `8002` -> `start_telegram_service3.bat`
    - `8003` -> `start_telegram_service4.bat`
  - dead bots `@Showfiles6bot` and `@newjmqbot` are no longer part of the normal dispatch path

- Group media scan wrapper
  - legacy task name often mentioned in older notes:
    - `EnsureTgScanGroupMediaHourly`
  - wrappers:
    - `C:\www\blog\scripts\ensure_tg_scan_group_media.bat`
    - `C:\www\blog\scripts\ensure_tg_scan_group_media.ps1`
    - `C:\www\blog\scripts\tg_scan_group_media.bat`
  - current base URI:
    - `http://127.0.0.1:8001/`
  - Laravel command:
    - `php artisan tg:scan-group-media`
  - current repo-managed schedule source:
    - `App\Console\Kernel::schedule()` hourly at minute `24`

- `Telegram FastAPI Services`
  - current verified watchdog task on this host
  - launcher chain:
    - `C:\Users\User\Pictures\train\start_telegram_services.vbs`
    - `C:\Users\User\Pictures\train\start_telegram_services.bat`
    - `C:\Users\User\Pictures\train\start_telegram_services.ps1`
  - current `start_telegram_services.ps1` launches:
    - `start_telegram_service2.ps1` -> `8001`
    - `start_telegram_service3.ps1` -> `8002`
    - `start_telegram_service4.ps1` -> `8003`
  - current observed repetition on `2026-04-07`:
    - every `5` minutes
  - current observed listeners on `2026-04-07`:
    - `8001`, `8002`, `8003`
  - important correction:
    - the watchdog no longer backfills `8000`
    - `C:\Users\User\.cloudflared\config.yml` still contains `tg-api1.mystar.monster -> 127.0.0.1:8000`, but that does not mean Windows tasks should restart `8000`

- Local backup-restore bot / `@new_files_star_bot`
  - this bot is local Windows + Cloudflare tunnel, not AWS
  - current public hostname:
    - `https://new-files-star.mystar.monster`
  - tunnel source of truth:
    - `C:\Users\User\.cloudflared\config.yml`
  - current ingress:
    - `new-files-star.mystar.monster -> http://127.0.0.1:450`
  - local HTTP entry:
    - Caddy on `127.0.0.1:450`
  - Laravel route wiring:
    - `POST /api/telegram/filestore/webhook/new-files-star`
    - `POST /api/telegram/filestore/webhook/backup-restore`
  - controller:
    - `App\Http\Controllers\TelegramFilestoreBotController::newFilesStarWebhook`
  - config:
    - `config/telegram.php` `backup_restore_*`
  - webhook ensure command:
    - `php artisan telegram:ensure-backup-restore-webhook`
  - internal Laravel schedule:
    - `telegram:ensure-backup-restore-webhook` daily at `04:10`
    - `filestore:restore-to-bot --all --pending-session-limit=500 --base-uri=http://127.0.0.1:8001 ...` daily at `01:00`
  - current restore behavior after commit `4e9cec8`:
    - `RestoreFilestoreToBotCommand` uses local webhook bridge capture for the configured backup-restore bot when `telegram_filestore_bridge_contexts` exists
    - it no longer needs to delete the bot webhook to call `getUpdates`
  - practical recovery rule:
    - if `getWebhookInfo` shows an empty URL and `@new_files_star_bot` is unresponsive, first look for a long-running old `php artisan filestore:restore-to-bot ... new_files_star_bot` process, stop it, then rerun `telegram:ensure-backup-restore-webhook`

- `telegram_filestore` local production offload
  - current working Windows tasks:
    - `Blog Telegram Filestore Local Workers Logon`
    - `Blog Telegram Filestore Local Workers Watchdog`
  - local hidden PowerShell runners on Windows
  - generated secret env:
    - `C:\www\blog\storage\app\telegram-filestore-local-workers\worker.env`
  - current worker prefix:
    - `ltf`
  - current autoscale floor / cap:
    - `16` / `200`

- folder video LAN API / Caddy
  - wrapper:
    - `C:\www\blog\scripts\start-folder-video-api.bat`
  - launcher:
    - `C:\www\blog\scripts\start-folder-video-api.ps1`
  - current live listeners seen recently:
    - Caddy `2019`, `450`, `8090`, `9005`
  - `450` is shared with the local blog / webhook ingress behind Cloudflare tunnel

## Operating Rules

- Prefer wrapper `.bat` files under `C:\www\blog\scripts` or `C:\Users\User\Pictures\train` so Task Scheduler actions stay simple.
- Use absolute Windows paths in tasks and scripts.
- Keep the handoff chain explicit: Task Scheduler -> `.bat` -> `.ps1` if needed -> `php artisan` -> Laravel command/controller -> API.
- For Telegram flows, verify both task state and actual listener port before treating the service as healthy.
- `@new_files_star_bot` is not the AWS bot stack. Inspect local tunnel / Caddy / Laravel webhook first.
- For local backup-restore bot issues, prove all of these before changing tokens:
  - `C:\Users\User\.cloudflared\config.yml`
  - `127.0.0.1:450`
  - `routes/api.php`
  - `TelegramFilestoreBotController::newFilesStarWebhook`
  - Telegram `getWebhookInfo`
- If the current repo code is deployed but the webhook still disappears, suspect an old pre-fix restore process rather than a new code regression.
- For local `telegram_filestore` offload, the key dependency is production MySQL plus shared production cache. `blog.mystar.monster` or Cloudflare Tunnel HTTP access is not enough by itself.
- On this Windows host, `php.exe` needed explicit `-d curl.cainfo=...` and `-d openssl.cafile=...` flags. Setting only `SSL_CERT_FILE` / `CURL_CA_BUNDLE` did not fix Telegram HTTPS for PHP CLI.
- For local `telegram_filestore` worker logs, avoid PowerShell `*>>` redirection. It produced UTF-16 / NUL-filled `ltfNNN.log` content in this environment. The working pattern here is `cmd.exe /d /c ... >> logfile 2>&1`.
- If Task Scheduler query is blocked by permissions, treat that as a normal branch of the workflow, not a blocker.
- When a task is inaccessible, prove the flow by correlating process parent/child chains, open ports, batch targets, cloudflared ingress, and recent logs.
- `get-bt` Windows tasks should call `php artisan get-bt`, not `command:get-bt`.
- On this host, `schtasks` currently returns `not found` for several older names such as `EnsureTgScanGroupMediaHourly`, `Telegram FastAPI Service`, `TG API2`, `Blog Get BT`, and `Project Log Cleanup`. Treat those names as hints, not proof.

## Files To Read By Flow

- Telegram task orchestration:
  - `references/current-task-flow.md`
  - `C:\www\blog\scripts\tg_scan_dispatch.bat`
  - `C:\www\blog\app\Console\Commands\ScanGroupTokensCommand.php`
  - `C:\www\blog\app\Console\Commands\DispatchTokenScanItemsCommand.php`
  - `C:\www\blog\app\Services\DialogueFilestoreDispatchService.php`
  - for shared FastAPI route details, also read:
    - `C:\Users\User\.codex\skills\telegram-fastapi-windows\references\api-implementation-map.md`

- Telegram group media download:
  - `references/current-task-flow.md`
  - `C:\www\blog\scripts\ensure_tg_scan_group_media.bat`
  - `C:\www\blog\scripts\ensure_tg_scan_group_media.ps1`
  - `C:\www\blog\scripts\tg_scan_group_media.bat`
  - `C:\www\blog\app\Console\Commands\ScanGroupMediaCommand.php`

- Local backup-restore bot / webhook:
  - `references/current-task-flow.md`
  - `C:\Users\User\.cloudflared\config.yml`
  - `C:\www\blog\config\telegram.php`
  - `C:\www\blog\routes\api.php`
  - `C:\www\blog\app\Http\Controllers\TelegramFilestoreBotController.php`
  - `C:\www\blog\app\Console\Commands\EnsureBackupRestoreWebhookCommand.php`
  - `C:\www\blog\app\Console\Commands\RestoreFilestoreToBotCommand.php`
  - `C:\www\blog\app\Console\Kernel.php`
  - `C:\Users\User\Pictures\train\start_telegram_services.ps1`

- Local `telegram_filestore` production offload:
  - `references/current-task-flow.md`
  - `C:\www\blog\scripts\sync_telegram_worker_env_from_aws.ps1`
  - `C:\www\blog\scripts\run_telegram_filestore_local_worker.ps1`
  - `C:\www\blog\scripts\manage_telegram_filestore_local_workers.ps1`
  - `C:\www\blog\scripts\start_telegram_filestore_local_workers.bat`
  - `C:\www\blog\scripts\watchdog_telegram_filestore_local_workers.bat`
  - `C:\www\blog\scripts\restart_telegram_filestore_local_workers.bat`
  - `C:\www\blog\scripts\register_telegram_filestore_local_workers_startup_task.ps1`

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
- If the flow depends on Telegram FastAPI, confirm the port and the watchdog agree on the same target.
- If task query is denied, verify the live flow with `Get-CimInstance Win32_Process`, `Get-NetTCPConnection`, `netstat`, wrapper scripts, cloudflared ingress, and recent logs instead.
- For local backup-restore bot issues, verify both:
  - Telegram `getWebhookInfo` points to `https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star`
  - `http://127.0.0.1:450/api/telegram/filestore/webhook/new-files-star` answers when called locally with `Host: new-files-star.mystar.monster`
