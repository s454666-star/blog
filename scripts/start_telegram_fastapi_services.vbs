Option Explicit

Dim shell
Dim command

command = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File ""C:\www\blog\scripts\start_telegram_fastapi_services.ps1"""

Set shell = CreateObject("WScript.Shell")
shell.Run command, 0, False
