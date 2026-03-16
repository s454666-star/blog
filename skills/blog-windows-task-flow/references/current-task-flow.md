# Current Task Flow

Observed and cross-checked on 2026-03-16 in `C:\www\blog`.

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

- State observed: task disabled, but process was still running
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

- State: enabled, ready
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

- State: enabled, ready
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
  - `php artisan tg:dispatch-token-scan-items --done-action=delete --fallback-newjmqbot --port=<selected-port>`

### Laravel to TG API

- `ScanGroupTokensCommand` calls local FastAPI `groups` endpoints for configured `base_uri` values:
  - `http://127.0.0.1:8000/`
  - `http://127.0.0.1:8001/`
- It scans Telegram group messages, extracts tokens, and inserts new rows into `token_scan_items`.
- `DispatchTokenScanItemsCommand` posts to:
  - `POST /bots/send-and-run-all-pages`
  - `GET /bots/download-jobs/{jobId}`
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

## Flow 3: Telegram FastAPI Services Outside Repo

The blog repo does not contain the FastAPI source. The currently observed runtime layout is:

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

## Important Note About Laravel Internal Scheduling

`C:\www\blog\app\Console\Kernel.php` currently schedules:

- `command('command:get-bt')->dailyAt('00:00')`

But the actual Artisan signature in `CrawlerBtCommand` is:

- `get-bt`

That means the repo's Laravel scheduler definition does not match the command name as observed on 2026-03-16. For Windows Task Scheduler, call `php artisan get-bt`.
