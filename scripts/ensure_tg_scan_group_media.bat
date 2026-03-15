@echo off
setlocal

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\www\blog\scripts\ensure_tg_scan_group_media.ps1"
exit /b %ERRORLEVEL%
