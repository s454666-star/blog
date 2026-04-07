@echo off
setlocal

set "APP_DIR=C:\www\blog"
set "PHP_EXE=C:\php\php.exe"
set "BASE_URI=http://127.0.0.1:8001/"
set "NEXT_LIMIT=1000"
set "EMPTY_EXIT=2"
set "RUN_LOG=%TEMP%\tg_scan_group_media_last.log"
set "PERSIST_LOG=C:\www\blog\storage\logs\tg_scan_group_media_runtime.log"

if not "%~1"=="" set "BASE_URI=%~1"

pushd "%APP_DIR%"
if errorlevel 1 exit /b 1

call :log_line "[%date% %time%] Start tg scan group media. base_uri=%BASE_URI%"

:loop
"%PHP_EXE%" artisan tg:scan-group-media --base-uri=%BASE_URI% --next-limit=%NEXT_LIMIT% --exit-code-when-empty=%EMPTY_EXIT% > "%RUN_LOG%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"
type "%RUN_LOG%"
type "%RUN_LOG%" >> "%PERSIST_LOG%"

if "%EXIT_CODE%"=="0" (
    call :log_line "[%date% %time%] Processed one item. Continue."
    goto loop
)

if "%EXIT_CODE%"=="%EMPTY_EXIT%" (
    call :log_line "[%date% %time%] No more media to download. Stop."
    set "EXIT_CODE=0"
    goto finish
)

set "FLOOD_WAIT_SECONDS="
for /f "tokens=2 delims==" %%A in ('findstr /b /c:"FLOOD_WAIT_SECONDS=" "%RUN_LOG%"') do set "FLOOD_WAIT_SECONDS=%%A"
if defined FLOOD_WAIT_SECONDS (
    call :log_line "[%date% %time%] Flood wait detected. Need wait %FLOOD_WAIT_SECONDS% seconds before download can continue."
    timeout /t %FLOOD_WAIT_SECONDS% /nobreak >nul
    call :log_line "[%date% %time%] Flood wait finished. Retry."
    goto loop
)

call :log_line "[%date% %time%] tg:scan-group-media failed. exit_code=%EXIT_CODE%"
pause

:finish
call :log_line "[%date% %time%] Finished tg scan group media. exit_code=%EXIT_CODE%"

popd
exit /b %EXIT_CODE%

:log_line
echo %~1
>> "%PERSIST_LOG%" echo %~1
goto :eof
