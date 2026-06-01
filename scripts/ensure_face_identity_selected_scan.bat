@echo off
setlocal

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\www\blog\scripts\ensure_face_identity_selected_scan.ps1"
exit /b %ERRORLEVEL%
