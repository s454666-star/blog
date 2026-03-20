@echo off
setlocal EnableExtensions

set "SCRIPT_DIR=%~dp0"
set "POWERSHELL_EXE=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
set "RUNNER_PS1=%SCRIPT_DIR%run_extract_failures_then_move_duplicates.ps1"

chcp 65001 >nul

if not exist "%RUNNER_PS1%" (
    echo Missing script: %RUNNER_PS1%
    pause
    exit /b 1
)

"%POWERSHELL_EXE%" -NoProfile -ExecutionPolicy Bypass -File "%RUNNER_PS1%" %*
set "EXIT_CODE=%ERRORLEVEL%"

echo.
if "%EXIT_CODE%"=="0" (
    echo Finished successfully.
) else (
    echo Failed. exit_code=%EXIT_CODE%
)

pause
exit /b %EXIT_CODE%
