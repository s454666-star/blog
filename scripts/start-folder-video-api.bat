@echo off
setlocal
cd /d C:\www\blog
powershell -ExecutionPolicy Bypass -File C:\www\blog\scripts\start-folder-video-api.ps1
