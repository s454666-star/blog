@echo off
setlocal

set "APP_DIR=C:\www\blog"
set "PHP_EXE=C:\php\php.exe"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\tg_scan_dispatch.log"
set "LOCK_DIR=%LOG_DIR%\tg_scan_dispatch.lock"
set "SCAN_EXIT=0"
set "DISPATCH_EXIT=0"

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

"%PHP_EXE%" artisan tg:scan-group-tokens >>"%LOG_FILE%" 2>&1
set "SCAN_EXIT=%ERRORLEVEL%"

"%PHP_EXE%" artisan tg:dispatch-token-scan-items --port=8000 >>"%LOG_FILE%" 2>&1
set "DISPATCH_EXIT=%ERRORLEVEL%"

echo [%date% %time%] Finished tg scan dispatch. scan_exit=%SCAN_EXIT% dispatch_exit=%DISPATCH_EXIT%>>"%LOG_FILE%"

:cleanup
popd 2>nul
rmdir "%LOCK_DIR%" 2>nul

if not "%SCAN_EXIT%"=="0" exit /b %SCAN_EXIT%
if not "%DISPATCH_EXIT%"=="0" exit /b %DISPATCH_EXIT%
exit /b 0
