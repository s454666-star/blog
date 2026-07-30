@echo off
setlocal EnableExtensions

set "SCRIPT_DIR=%~dp0"
for %%I in ("%SCRIPT_DIR%..") do set "APP_DIR=%%~fI"
set "WORKER=%APP_DIR%\scripts\taiex-futures-realtime\worker.mjs"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\taiex_futures_realtime_worker.log"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

where node.exe >nul 2>nul
if errorlevel 1 (
    echo [%date% %time%] Failed: node.exe is not available.>>"%LOG_FILE%"
    exit /b 1
)

if not exist "%WORKER%" (
    echo [%date% %time%] Failed: worker not found at %WORKER%.>>"%LOG_FILE%"
    exit /b 1
)

cd /d "%APP_DIR%"
echo [%date% %time%] Starting TradingView realtime Redis worker.>>"%LOG_FILE%"
node.exe "%WORKER%" >>"%LOG_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"
echo [%date% %time%] Worker stopped with exit_code=%EXIT_CODE%.>>"%LOG_FILE%"
exit /b %EXIT_CODE%
