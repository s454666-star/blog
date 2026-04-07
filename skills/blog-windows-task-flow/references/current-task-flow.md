# Current Task Flow

Observed and cross-checked on `2026-04-07` in `C:\www\blog` and the local Windows host.

## First Pitfall

- Some GUI-visible tasks still return `Access is denied` or `task not found` to `schtasks` / `Get-ScheduledTask`.
- When that happens, rebuild the flow from:
  - `Win32_Process` command lines
  - `Get-NetTCPConnection` / `netstat`
  - wrapper `.bat` / `.ps1`
  - `C:\Users\User\.cloudflared\config.yml`
  - Caddy files under `storage\app\folder-video-server`
  - recent logs under `storage\logs`
- This is still required for `CaddyServer`, `LaravelBlogServer`, `VideoHTTPServer`, and legacy Telegram task names.
- As of `2026-04-07`, `schtasks` returned `The system cannot find the file specified.` for:
  - `EnsureTgScanGroupMediaHourly`
  - `Telegram FastAPI Service`
  - `TG API2`
  - `Blog Get BT`
  - `Project Log Cleanup`
  - `Blog Folder Video API Caddy`

## Relevant Scheduled and Runtime Entry Points

### Telegram FastAPI Watchdog

- Verified task on `2026-04-07`:
  - `\Telegram FastAPI Services`
- Verified action chain:
  - `wscript.exe "C:\Users\User\Pictures\train\start_telegram_services.vbs"`
  - `start_telegram_services.vbs`
  - `start_telegram_services.bat`
  - `start_telegram_services.ps1`
- Current observed repetition:
  - every `5` minutes
- Current verified `start_telegram_services.ps1` behavior:
  - starts `start_telegram_service2.ps1`
  - starts `start_telegram_service3.ps1`
  - starts `start_telegram_service4.ps1`
- Current observed listeners:
  - `8001`
  - `8002`
  - `8003`
- Important correction:
  - the watchdog no longer launches `8000`
  - `C:\Users\User\.cloudflared\config.yml` still maps `tg-api1.mystar.monster -> 127.0.0.1:8000`, but that is not proof that Windows tasks should backfill `8000`

### TG Token Scan Dispatch

- Wrapper:
  - `C:\www\blog\scripts\tg_scan_dispatch.bat`
- Lock file:
  - `C:\www\blog\storage\logs\tg_scan_dispatch.lock`
- Current target selection:
  - fixed `127.0.0.1:8001`
  - readiness task: `Telegram FastAPI Services`
  - selection reason in batch: `fixed_8001`
- Current Laravel chain:
  - `php artisan tg:scan-group-tokens`
  - `php artisan tg:dispatch-token-scan-items --done-action=delete --port=8001`
- Current Laravel routing:
  - `Messengercode_*` -> `@MessengerCode_bot`
  - `QQfile_bot:*`, `vi_*`, `iv_*`, `ntmjmqbot_*`, `showfilesbot_*` -> `@showfiles12bot`
  - `yzfile_bot:*` -> `@yzfile_bot`
  - `mtfxqbot_*` -> `@mtfxq2bot`
  - `atfileslinksbot_*` -> `@atfileslinksbot`
  - `lddeebot_*` -> `@lddeebot`
  - everything else -> `@vipfiles2bot`
- Local restart recovery inside `DispatchTokenScanItemsCommand` currently knows these launchers:
  - `8001` -> `C:\Users\User\Pictures\train\start_telegram_service2.bat`
  - `8002` -> `C:\Users\User\Pictures\train\start_telegram_service3.bat`
  - `8003` -> `C:\Users\User\Pictures\train\start_telegram_service4.bat`

### EnsureTgScanGroupMediaHourly

- Legacy task name:
  - `EnsureTgScanGroupMediaHourly`
- Current runnable wrapper chain:
  - `ensure_tg_scan_group_media.bat`
  - `ensure_tg_scan_group_media.ps1`
  - `tg_scan_group_media.bat`
- Current main command:
  - `php artisan tg:scan-group-media --base-uri=http://127.0.0.1:8001/ --next-limit=1000 --exit-code-when-empty=2`
- Guard behavior:
  - the PowerShell wrapper only starts a new batch if neither `tg_scan_group_media.bat` nor `artisan tg:scan-group-media` is already running
- Current repo-managed schedule source:
  - `App\Console\Kernel::schedule()` hourly at minute `24`

### Local Backup-Restore Bot Webhook

- Bot:
  - `@new_files_star_bot`
- This flow is local Windows, not AWS.
- Public hostname:
  - `https://new-files-star.mystar.monster`
- Cloudflare Tunnel source of truth:
  - `C:\Users\User\.cloudflared\config.yml`
- Current ingress:
  - `new-files-star.mystar.monster -> http://127.0.0.1:450`
- Local HTTP entry:
  - Caddy listening on `127.0.0.1:450`
- Current Laravel routes:
  - `POST /api/telegram/filestore/webhook/new-files-star`
  - `POST /api/telegram/filestore/webhook/backup-restore`
- Current controller:
  - `App\Http\Controllers\TelegramFilestoreBotController::newFilesStarWebhook`
- Current config source:
  - `C:\www\blog\config\telegram.php`
- Current webhook maintenance command:
  - `php artisan telegram:ensure-backup-restore-webhook`
- Current internal Laravel schedule in `App\Console\Kernel`:
  - `filestore:restore-to-bot --all --pending-session-limit=500 --base-uri=http://127.0.0.1:8001 ...` daily at `01:00`
  - `telegram:ensure-backup-restore-webhook` daily at `04:10`
- Current restore behavior after commit `4e9cec8`:
  - `RestoreFilestoreToBotCommand` uses local webhook bridge capture for the configured backup-restore bot when `telegram_filestore_bridge_contexts` exists
  - it no longer needs to call `deleteWebhook` just to use `getUpdates`
- Important recovery rule:
  - if `getWebhookInfo` shows `url=""`, first check for an old long-running `php artisan filestore:restore-to-bot ... --target-bot-username=new_files_star_bot`
  - stop that old process before rerunning `telegram:ensure-backup-restore-webhook`

### Folder Video / Caddy

- Current observed listeners:
  - Caddy `2019`, `450`, `8090`, `9005`
- `450` is the local blog / webhook HTTP entry used by both:
  - `blog.mystar.monster`
  - `new-files-star.mystar.monster`
- `C:\Users\User\AppData\Roaming\Caddy\autosave.json` still shows a separate static-file server on `:9005` rooted at `D:\video`.

### Local `telegram_filestore` Worker Offload

- Working task pair on this host:
  - `Blog Telegram Filestore Local Workers Logon`
  - `Blog Telegram Filestore Local Workers Watchdog`
- Current wrapper parameters previously verified and still expected:
  - `-MinWorkerCount 16`
  - `-MaxWorkerCount 200`
  - `-ScaleDownHoldSeconds 300`
- Important host-specific rules still in force:
  - pass explicit `-d curl.cainfo=...` and `-d openssl.cafile=...` to PHP
  - avoid PowerShell `*>>` for worker logs
  - read queue metrics through a temporary `.php` file, not stdin-piped PHP source
  - use .NET file writes for worker state files

## Flow 1: Telegram FastAPI Runtime Layout

- Runtime base:
  - `C:\Users\User\Pictures\train`
- Python:
  - `C:\Users\User\Pictures\train\venv\Scripts\python.exe`
- Shared route logic:
  - `C:\www\blog\python\telegram_service_shared.py`
- Current live wrappers:
  - `telegram_service2.py` -> `8001` -> `session/main_account2`
  - `telegram_service3.py` -> `8002` -> `session/main_account3`
  - `telegram_service4.py` -> `8003` -> `session/main_account4`
- Legacy wrapper still present but not watchdog-backed:
  - `telegram_service.py` -> `8000` -> `session/main_account`

## Flow 2: Token Scan Dispatch

- `tg_scan_dispatch.bat` no longer rotates between `8000` and `8001`.
- It now:
  - hard-codes `8001`
  - starts `Telegram FastAPI Services` if `8001` is down
  - runs `tg:scan-group-tokens`
  - runs `tg:dispatch-token-scan-items --port=8001`
- `DispatchTokenScanItemsCommand` default port is `8001`.

## Flow 3: Group Media Download

- `tg_scan_group_media.bat` now defaults to:
  - `BASE_URI=http://127.0.0.1:8001/`
- `ScanGroupTokensCommand` and `ScanGroupMediaCommand` both currently use `8001` in their built-in target maps.

## Flow 4: Local Backup-Restore Bot

- `config/telegram.php` defaults:
  - `backup_restore_bot_username = new_files_star_bot`
  - `backup_restore_webhook_url = https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star`
- `routes/api.php` exposes both:
  - `/telegram/filestore/webhook/new-files-star`
  - `/telegram/filestore/webhook/backup-restore`
- `TelegramFilestoreBotController::newFilesStarWebhook` handles both routes.
- `EnsureBackupRestoreWebhookCommand` is the direct fix command when Telegram lost the webhook.
- `laravel.log` key signal:
  - `telegram_filestore_webhook_event` with `bot=new_files_star_bot`

## Flow 5: Folder Video Through Caddy

- Wrapper:
  - `C:\www\blog\scripts\start-folder-video-api.bat`
- Launcher:
  - `C:\www\blog\scripts\start-folder-video-api.ps1`
- Current generated config:
  - `C:\www\blog\storage\app\folder-video-server\Caddyfile`
- Current reality:
  - live HTTP ingress on `450` may still be managed by older GUI-visible tasks like `CaddyServer` / `LaravelBlogServer` / `VideoHTTPServer`
  - when task query is blocked, trust listeners plus Caddy config over Task Scheduler output

## Flow 6: `get-bt`

- Legacy task name sometimes referenced:
  - `Blog Get BT`
- Wrapper:
  - `C:\www\blog\scripts\run_get_bt.bat`
- Actual command:
  - `php artisan get-bt`
- Current schedule in `App\Console\Kernel`:
  - `0 5,17 * * *`

## Flow 7: Project Log Cleanup

- Legacy task / script path:
  - `Project Log Cleanup`
  - `clear_project_logs.bat`
  - `clear_project_logs.ps1`
- Current behavior:
  - delete `*.log` recursively under `C:\www\blog\storage\logs`
  - if delete fails because the log is locked, truncate it to zero bytes instead
- Current repo state:
  - the internal Laravel scheduler entry is commented out in `App\Console\Kernel`

## Laravel Scheduling

- `C:\www\blog\app\Console\Kernel.php` is currently the right source of truth for:
  - `get-bt`
  - `filestore:restore-to-bot`
  - `telegram:ensure-backup-restore-webhook`
  - `filestore:cleanup-stale-sessions`
  - `ensure_tg_scan_group_media.bat`
- Older notes that claimed `command:get-bt` was still scheduled are stale for this host.
