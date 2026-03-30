@echo off
setlocal
cd /d C:\www\blog
powershell -NoProfile -ExecutionPolicy Bypass -File C:\www\blog\scripts\manage_telegram_filestore_local_workers.ps1 -Action restart -MinWorkerCount 50 -MaxWorkerCount 200 -ScaleDownHoldSeconds 300
