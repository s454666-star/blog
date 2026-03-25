# Current Task Flow

Observed and cross-checked on 2026-03-25 in `C:\www\blog`.

## First Pitfall: Task Query Can Be Incomplete

- Some visible Task Scheduler entries can return `Access is denied` to `schtasks` or `Get-ScheduledTask` even though their processes are running.
- When that happens, reconstruct the live flow from:
  - `Win32_Process` command lines and parent processes
  - `Get-NetTCPConnection` / `netstat`
  - wrapper `.bat` / `.ps1`
  - runtime state files such as `storage\app\folder-video-server\Caddyfile`
  - recent logs
- As of `2026-03-25`, this fallback is required for tasks visible in the GUI such as `CaddyServer`, `LaravelBlogServer`, and `VideoHTTPServer`.

## Relevant Windows Scheduled Tasks

### `TG Token Scan Dispatch`

- State: enabled, ready
- Schedule:
  - starts daily at `07:00`
  - repeats every `2` hours
  - repeat window `15` hours `1` minute
- Task action:
  - `C:\www\blog\scripts\tg_scan_dispatch.bat`

### `EnsureTgScanGroupMediaHourly`

- State: enabled, ready
- Schedule:
  - started on `2026-03-15 16:24`
  - repeats every hour
- Task action:
  - `cmd.exe /c "C:\www\blog\scripts\ensure_tg_scan_group_media.bat"`

### `Telegram FastAPI Service`

- State observed on `2026-03-25`: task disabled, but the runtime can still exist separately
- Trigger:
  - system startup
- Task action:
  - `C:\Users\User\Pictures\train\start_telegram_service.bat`
- Start in:
  - `C:\Users\User\Pictures\train`
- Service target:
  - `telegram_service:app`
  - port `8000`

### `TG API2`

- State observed on `2026-03-25`: enabled, running
- Trigger:
  - system startup
- Task action:
  - `C:\Users\User\Pictures\train\start_telegram_service2.bat`
- Start in:
  - `C:\Users\User\Pictures\train`
- Service target:
  - `telegram_service2:app`
  - port `8001`

### `Telegram FastAPI Services`

- State observed on `2026-03-25`: enabled, ready
- Schedule:
  - started on `2026-03-15 16:40`
  - repeats every hour
- Task action:
  - `C:\Users\User\Pictures\train\start_telegram_services.bat`
- Purpose:
  - watchdog for ports `8000` and `8001`

### `Blog Get BT`

- State:
  - repo-managed task
- Schedule:
  - daily at `05:00`
  - daily at `17:00`
- Task action:
  - `cmd.exe /c "C:\www\blog\scripts\run_get_bt.bat"`

### `Project Log Cleanup`

- State observed on `2026-03-25`: enabled, ready
- Schedule:
  - every `2` days at `03:00`
- Task action:
  - `cmd.exe /c "C:\www\blog\scripts\clear_project_logs.bat"`
- Purpose:
  - remove untracked `*.log` files under `C:\www\blog` and `C:\Users\User\Pictures\train`

## Relevant Live Processes And Listeners

### Telegram FastAPI runtime

- `TG API2` currently owns the stable listener:
  - `0.0.0.0:8001`
  - parent chain: `cmd.exe /c "C:\Users\User\Pictures\train\start_telegram_service2.bat"` -> `python.exe -m uvicorn telegram_service2:app --port 8001`
- `Telegram FastAPI Service` can be disabled in Task Scheduler while a live runtime still exists:
  - parent chain: `cmd.exe /c "C:\Users\User\Pictures\train\start_telegram_service.bat"` -> `python.exe -m uvicorn telegram_service:app --port 8000`
- Important:
  - do not trust only the task state
  - verify both listener and HTTP response
  - `8000` has shown unstable behavior on `2026-03-25`: `/docs` responded with `200`, but media download also hit `cURL error 7` / connection reset against `http://127.0.0.1:8000/groups/download-message-media`

### Folder video / Caddy runtime

- Live listeners observed on `2026-03-25`:
  - `127.0.0.1:2019`
  - `0.0.0.0:8090`
  - `0.0.0.0:9005`
  - `0.0.0.0:450`
- Current repo-managed folder-video bootstrap files:
  - `C:\www\blog\scripts\start-folder-video-api.bat`
  - `C:\www\blog\scripts\start-folder-video-api.ps1`
  - `C:\www\blog\scripts\register-folder-video-api-caddy-startup-task.ps1`
- Current generated runtime config:
  - `C:\www\blog\storage\app\folder-video-server\Caddyfile`
  - reverse proxies `:8090` to `https://127.0.0.1:443` with host `blog.test`
- Important:
  - GUI may show `CaddyServer` / `VideoHTTPServer`, but when direct task query is blocked, the better source of truth is listener + wrapper + Caddy state

### Laravel local server runtime

- A PHP process was listening on `127.0.0.1:8081` on `2026-03-25`.
- The exact visible task name in GUI may be `LaravelBlogServer`, but if task query is denied, confirm it from the listener and related wrapper/config chain instead of assuming the task is missing.

## Flow 1: Token Scan Dispatch

### Scheduler to batch

- Task Scheduler runs `C:\www\blog\scripts\tg_scan_dispatch.bat`.
- The batch file keeps a lock directory in `storage\logs\tg_scan_dispatch.lock`.
- It alternates target ports by reading and writing `storage\logs\tg_scan_dispatch_next_port.txt`.

### Batch to FastAPI

- The batch chooses one of these pairs:
  - port `8000` -> task `Telegram FastAPI Service`
  - port `8001` -> task `TG API2`
- If the selected port is not listening, the batch starts the matching Windows task and waits up to `30` seconds.

### Batch to Laravel

- After FastAPI is ready, the batch runs:
  - `php artisan tg:scan-group-tokens`
  - `php artisan tg:dispatch-token-scan-items --done-action=delete --port=<selected-port>`

### Laravel to TG API

- `ScanGroupTokensCommand` calls local FastAPI `groups` endpoints for configured `base_uri` values:
  - `http://127.0.0.1:8000/`
  - `http://127.0.0.1:8001/`
- It scans Telegram group messages, extracts tokens, and inserts new rows into `token_scan_items`.
- `DispatchTokenScanItemsCommand` posts to:
  - `POST /bots/send`
  - `POST /bots/run-all-pages-by-bot` for `@vipfiles2bot` and `@MessengerCode_bot`
  - `POST /bots/click-matching-button` for `@QQfile_bot` and `@yzfile_bot`
- Default API host is `http://127.0.0.1`, with the port supplied by the batch file.

## Flow 2: Group Media Download

### Scheduler to batch and PowerShell

- Task Scheduler runs `C:\www\blog\scripts\ensure_tg_scan_group_media.bat`.
- That batch runs `C:\www\blog\scripts\ensure_tg_scan_group_media.ps1`.
- The PowerShell script checks whether `tg_scan_group_media.bat` or `artisan tg:scan-group-media` is already running.
- If not running, it starts `cmd.exe /c C:\www\blog\scripts\tg_scan_group_media.bat`.

### Batch to Laravel

- `tg_scan_group_media.bat` runs:
  - `php artisan tg:scan-group-media --base-uri=<target> --next-limit=1000 --exit-code-when-empty=2`
- Default base URI is `http://127.0.0.1:8000/`.
- The batch loops until:
  - one media item is processed and another loop begins
  - no more media is available
  - a flood-wait delay is required
  - the command fails

### Laravel to TG API

- `ScanGroupMediaCommand` reads group pages from:
  - `GET /groups`
  - `GET /groups/{peerId}/{startMessageId}?next_limit=<n>&include_raw=false`
- For media downloads it calls:
  - `POST /groups/download-message-media`
- For text-only messages it may extract tokens and queue them into `token_scan_items`.

### Current runtime note

- `ensure_tg_scan_group_media.log` shows the hourly wrapper is doing its job: it avoids duplicate launches when an older scan is still running.
- `tg_scan_group_media_runtime.log` on `2026-03-25` shows the command was actively downloading media from `http://127.0.0.1:8000/`, but later hit a connection reset on `/groups/download-message-media`.
- For this flow, "port open" is not enough. Confirm an actual media endpoint call can complete.

## Flow 3: Telegram FastAPI Services Outside Repo

The currently observed runtime layout is:

- Base directory:
  - `C:\Users\User\Pictures\train`
- Python:
  - `C:\Users\User\Pictures\train\venv\Scripts\python.exe`
- Service 1 batch:
  - `start_telegram_service.bat`
  - runs `python -m uvicorn telegram_service:app --host 0.0.0.0 --port 8000`
- Service 2 batch:
  - `start_telegram_service2.bat`
  - runs `python -m uvicorn telegram_service2:app --host 0.0.0.0 --port 8001`
- Watchdog batch:
  - `start_telegram_services.bat`
  - starts service 1 or service 2 if their ports are not listening
- Note:
  - the same FastAPI source files also exist in `C:\www\blog\python\telegram_service.py` and `C:\www\blog\python\telegram_service2.py`, but the live scheduled tasks currently launch the copies under `C:\Users\User\Pictures\train`

## Flow 4: `get-bt`

### Windows task or manual run

- Windows task:
  - `Blog Get BT`
- Recommended wrapper:
  - `C:\www\blog\scripts\run_get_bt.bat`
- Command inside the wrapper:
  - `php artisan get-bt`

### Laravel chain

- Artisan signature:
  - `get-bt`
- Command class:
  - `App\Console\Commands\CrawlerBtCommand`
- Controller entry:
  - `App\Http\Controllers\GetBtDataController::fetchData()`
- External source:
  - `https://sukebei.nyaa.si/?f=0&c=0_0&q=%22%2B%2B%2B+FC%22&p=<page>`

### What `get-bt` does

- Requests pages `1` to `3`
- Parses HTML for `/view/` detail links
- Passes each detail page URL to `GetBtDataDetailController::fetchDetail()`

## Flow 5: Project Log Cleanup

### Scheduler to batch and PowerShell

- Task Scheduler runs `C:\www\blog\scripts\clear_project_logs.bat`.
- The batch runs `C:\www\blog\scripts\clear_project_logs.ps1`.

### Cleanup behavior

- The PowerShell script scans:
  - `C:\www\blog`
  - `C:\Users\User\Pictures\train`
- It deletes `*.log` files except those tracked by git in each repo.
- This task can affect investigation data, so check it before assuming missing logs mean a flow never ran.

## Flow 6: Folder Video LAN API Through Caddy

### Wrapper to PowerShell

- `C:\www\blog\scripts\start-folder-video-api.bat`
  - `cd /d C:\www\blog`
  - `powershell -ExecutionPolicy Bypass -File C:\www\blog\scripts\start-folder-video-api.ps1`

### PowerShell to live Caddy

- `start-folder-video-api.ps1`:
  - ensures a local Caddy binary under `storage\bin\caddy`
  - writes runtime config to `storage\app\folder-video-server\Caddyfile`
  - stops legacy `artisan serve` on the target port
  - starts `caddy.exe run --config <Caddyfile>`
- Default port:
  - `8090`
- Reverse proxy target:
  - `https://127.0.0.1:443`
  - host header `blog.test`

### Registration helper

- `register-folder-video-api-caddy-startup-task.ps1` registers a startup task named:
  - `Blog Folder Video API Caddy`
- If GUI instead shows older or manually created tasks such as `CaddyServer` / `VideoHTTPServer`, compare them against this wrapper and the live listeners before changing anything.

## Important Note About Laravel Internal Scheduling

`C:\www\blog\app\Console\Kernel.php` currently schedules:

- `command('command:get-bt')->dailyAt('00:00')`

But the actual Artisan signature in `CrawlerBtCommand` is:

- `get-bt`

That means the repo's Laravel scheduler definition does not match the command name as observed on 2026-03-16. For Windows Task Scheduler, call `php artisan get-bt`.
