@echo off
setlocal
cd /d C:\www\blog
powershell -NoProfile -ExecutionPolicy Bypass -File C:\www\blog\scripts\manage_telegram_filestore_local_workers.ps1 -Action watchdog -WorkerCount 100
