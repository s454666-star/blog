@echo off
chcp 65001 >nul
title TG 暫存影片截圖
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Users\USER\Documents\project\blog\scripts\run-tg-video-contact-sheets.ps1"
exit /b %ERRORLEVEL%
