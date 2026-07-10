@echo off
setlocal
set "SCRIPT_DIR=%~dp0"
cd /d "%SCRIPT_DIR%.."
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%start-folder-video-api.ps1"
