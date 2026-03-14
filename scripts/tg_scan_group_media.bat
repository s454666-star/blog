@echo off
setlocal

set "APP_DIR=C:\www\blog"
set "PHP_EXE=C:\php\php.exe"
set "BASE_URI=http://127.0.0.1:8000/"
set "NEXT_LIMIT=1000"
set "EMPTY_EXIT=2"

if not "%~1"=="" set "BASE_URI=%~1"

pushd "%APP_DIR%"
if errorlevel 1 exit /b 1

echo [%date% %time%] Start tg scan group media. base_uri=%BASE_URI%

:loop
"%PHP_EXE%" artisan tg:scan-group-media --base-uri=%BASE_URI% --next-limit=%NEXT_LIMIT% --exit-code-when-empty=%EMPTY_EXIT%
set "EXIT_CODE=%ERRORLEVEL%"

if "%EXIT_CODE%"=="0" (
    echo [%date% %time%] Processed one item. Continue.
    goto loop
)

if "%EXIT_CODE%"=="%EMPTY_EXIT%" (
    echo [%date% %time%] No more media to download. Stop.
    set "EXIT_CODE=0"
    goto finish
)

echo [%date% %time%] tg:scan-group-media failed. exit_code=%EXIT_CODE%

:finish
echo [%date% %time%] Finished tg scan group media. exit_code=%EXIT_CODE%

popd
exit /b %EXIT_CODE%
