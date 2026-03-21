@echo off
setlocal EnableExtensions

set "APP_DIR=C:\www\blog"
set "PHP_EXE=C:\php\php.exe"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\filestore_upload_next.log"
set "LOCK_DIR=%LOG_DIR%\filestore_upload_next.lock"
set "ARTISAN_ARGS=filestore:upload-next"

if not "%~1"=="" (
    set "ARTISAN_ARGS=%ARTISAN_ARGS% %*"
)

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

if not exist "%PHP_EXE%" (
    echo [%date% %time%] Failed: cannot find PHP at %PHP_EXE%. >>"%LOG_FILE%"
    exit /b 1
)

mkdir "%LOCK_DIR%" 2>nul
if errorlevel 1 (
    echo [%date% %time%] Skip: previous filestore upload run is still active. >>"%LOG_FILE%"
    exit /b 0
)

pushd "%APP_DIR%"
if errorlevel 1 (
    echo [%date% %time%] Failed: cannot enter %APP_DIR%. >>"%LOG_FILE%"
    rmdir "%LOCK_DIR%" 2>nul
    exit /b 1
)

echo [%date% %time%] Start php artisan %ARTISAN_ARGS% >>"%LOG_FILE%"
"%PHP_EXE%" artisan %ARTISAN_ARGS% >>"%LOG_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"
echo [%date% %time%] Finished php artisan %ARTISAN_ARGS% exit_code=%EXIT_CODE% >>"%LOG_FILE%"

popd
rmdir "%LOCK_DIR%" 2>nul
exit /b %EXIT_CODE%
