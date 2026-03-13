@echo off
setlocal

set "APP_DIR=C:\www\blog"
set "PHP_EXE=C:\php\php.exe"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\tg_scan_dispatch.log"
set "LOCK_DIR=%LOG_DIR%\tg_scan_dispatch.lock"
set "FASTAPI_HOST=127.0.0.1"
set "FASTAPI_PORT=8000"
set "FASTAPI_TASK=Telegram FastAPI Service"
set "WAIT_SECONDS=30"
set "SCAN_EXIT=0"
set "DISPATCH_EXIT=0"
set "PORT_CHECK_EXIT=0"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

mkdir "%LOCK_DIR%" 2>nul
if errorlevel 1 (
    echo [%date% %time%] Skip: previous run is still active.>>"%LOG_FILE%"
    exit /b 0
)

pushd "%APP_DIR%"
if errorlevel 1 (
    echo [%date% %time%] Failed: cannot enter %APP_DIR%.>>"%LOG_FILE%"
    set "SCAN_EXIT=1"
    goto cleanup
)

echo [%date% %time%] Start tg scan dispatch.>>"%LOG_FILE%"

call :ensure_fastapi_port
if errorlevel 1 (
    set "PORT_CHECK_EXIT=%ERRORLEVEL%"
    goto cleanup
)

"%PHP_EXE%" artisan tg:scan-group-tokens >>"%LOG_FILE%" 2>&1
set "SCAN_EXIT=%ERRORLEVEL%"

"%PHP_EXE%" artisan tg:dispatch-token-scan-items --done-action=delete --port=8000 >>"%LOG_FILE%" 2>&1
set "DISPATCH_EXIT=%ERRORLEVEL%"

echo [%date% %time%] Finished tg scan dispatch. scan_exit=%SCAN_EXIT% dispatch_exit=%DISPATCH_EXIT%>>"%LOG_FILE%"

:cleanup
popd 2>nul
rmdir "%LOCK_DIR%" 2>nul

if not "%PORT_CHECK_EXIT%"=="0" exit /b %PORT_CHECK_EXIT%
if not "%SCAN_EXIT%"=="0" exit /b %SCAN_EXIT%
if not "%DISPATCH_EXIT%"=="0" exit /b %DISPATCH_EXIT%
exit /b 0

:ensure_fastapi_port
call :is_port_open
if "%ERRORLEVEL%"=="0" (
    echo [%date% %time%] FastAPI service detected on %FASTAPI_HOST%:%FASTAPI_PORT%.>>"%LOG_FILE%"
    exit /b 0
)

echo [%date% %time%] FastAPI service is not listening on %FASTAPI_HOST%:%FASTAPI_PORT%. Starting scheduled task "%FASTAPI_TASK%".>>"%LOG_FILE%"
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$taskName = '%FASTAPI_TASK%';" ^
    "try {" ^
    "  $task = Get-ScheduledTask -TaskName $taskName -ErrorAction Stop;" ^
    "  if ($task.State -ne 'Running') { Start-ScheduledTask -TaskName $taskName }" ^
    "} catch {" ^
    "  Write-Error $_; exit 1" ^
    "}"
if errorlevel 1 (
    echo [%date% %time%] Failed: cannot start scheduled task "%FASTAPI_TASK%".>>"%LOG_FILE%"
    exit /b 1
)

set /a WAIT_COUNT=0
:wait_for_fastapi
call :is_port_open
if "%ERRORLEVEL%"=="0" (
    echo [%date% %time%] FastAPI service is ready on %FASTAPI_HOST%:%FASTAPI_PORT%.>>"%LOG_FILE%"
    exit /b 0
)

if %WAIT_COUNT% geq %WAIT_SECONDS% (
    echo [%date% %time%] Failed: %FASTAPI_HOST%:%FASTAPI_PORT% did not become ready within %WAIT_SECONDS% seconds.>>"%LOG_FILE%"
    exit /b 1
)

if %WAIT_COUNT% equ 0 (
    echo [%date% %time%] Waiting for FastAPI service to open %FASTAPI_HOST%:%FASTAPI_PORT%...>>"%LOG_FILE%"
)

timeout /t 1 /nobreak >nul
set /a WAIT_COUNT+=1
goto wait_for_fastapi

:is_port_open
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$client = New-Object System.Net.Sockets.TcpClient;" ^
    "try {" ^
    "  $iar = $client.BeginConnect('%FASTAPI_HOST%', %FASTAPI_PORT%, $null, $null);" ^
    "  if (-not $iar.AsyncWaitHandle.WaitOne(1000, $false)) { throw 'timeout' }" ^
    "  $client.EndConnect($iar);" ^
    "  exit 0" ^
    "} catch {" ^
    "  exit 1" ^
    "} finally {" ^
    "  $client.Dispose()" ^
    "}"
exit /b %ERRORLEVEL%
