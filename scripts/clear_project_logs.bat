@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "SCRIPT_DIR=%~dp0"
set "POWERSHELL_EXE=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
set "LOG_DIR=C:\www\blog\storage\logs"
set "RUN_LOG=%LOG_DIR%\project_log_cleanup_task.txt"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

echo([%date% %time%] Start cleanup>>"%RUN_LOG%"
"%POWERSHELL_EXE%" -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%clear_project_logs.ps1" >>"%RUN_LOG%" 2>&1
set "EXIT_CODE=!ERRORLEVEL!"
echo([%date% %time%] Finished cleanup exit_code=!EXIT_CODE!>>"%RUN_LOG%"

exit /b !EXIT_CODE!
