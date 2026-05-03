@echo off
setlocal
cd /d C:\www\blog
powershell -NoProfile -ExecutionPolicy Bypass -File C:\www\blog\scripts\start-caddy-static-video.ps1
