@echo off
setlocal EnableExtensions

set "APP_DIR=C:\www\blog"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\esun_dashboard_token_rotation_task.log"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

cd /d "%APP_DIR%" || exit /b 1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%APP_DIR%\scripts\rotate_esun_dashboard_token.ps1" >> "%LOG_FILE%" 2>&1
exit /b %ERRORLEVEL%
