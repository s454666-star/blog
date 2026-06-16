@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0watchdog_cloudflared_redis.ps1"
exit /b %ERRORLEVEL%
