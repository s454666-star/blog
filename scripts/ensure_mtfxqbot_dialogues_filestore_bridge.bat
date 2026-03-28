@echo off
setlocal

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\www\blog\scripts\ensure_mtfxqbot_dialogues_filestore_bridge.ps1"
exit /b %ERRORLEVEL%
