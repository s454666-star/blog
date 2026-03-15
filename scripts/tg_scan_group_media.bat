@echo off
setlocal

set "APP_DIR=C:\www\blog"
set "PHP_EXE=C:\php\php.exe"
set "BASE_URI=http://127.0.0.1:8000/"
set "NEXT_LIMIT=1000"
set "EMPTY_EXIT=2"
set "RUN_LOG=%TEMP%\tg_scan_group_media_last.log"

if not "%~1"=="" set "BASE_URI=%~1"

pushd "%APP_DIR%"
if errorlevel 1 exit /b 1

echo [%date% %time%] Start tg scan group media. base_uri=%BASE_URI%

:loop
"%PHP_EXE%" artisan tg:scan-group-media --base-uri=%BASE_URI% --next-limit=%NEXT_LIMIT% --exit-code-when-empty=%EMPTY_EXIT% > "%RUN_LOG%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"
type "%RUN_LOG%"

if "%EXIT_CODE%"=="0" (
    echo [%date% %time%] Processed one item. Continue.
    goto loop
)

if "%EXIT_CODE%"=="%EMPTY_EXIT%" (
    echo [%date% %time%] No more media to download. Stop.
    set "EXIT_CODE=0"
    goto finish
)

set "FLOOD_WAIT_SECONDS="
for /f "tokens=2 delims==" %%A in ('findstr /b /c:"FLOOD_WAIT_SECONDS=" "%RUN_LOG%"') do set "FLOOD_WAIT_SECONDS=%%A"
if defined FLOOD_WAIT_SECONDS (
    echo [%date% %time%] Flood wait detected. Need wait %FLOOD_WAIT_SECONDS% seconds before download can continue.
)

echo [%date% %time%] tg:scan-group-media failed. exit_code=%EXIT_CODE%

:finish
echo [%date% %time%] Finished tg scan group media. exit_code=%EXIT_CODE%

popd
exit /b %EXIT_CODE%
