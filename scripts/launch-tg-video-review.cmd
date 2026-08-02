@echo off
chcp 65001 >nul
title TG 暫存影片接觸表掃描
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Users\USER\Documents\project\blog\scripts\run-tg-video-review.ps1"
exit /b %ERRORLEVEL%
