@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "APP_DIR=C:\www\blog"
set "PHP_EXE=C:\php\php.exe"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\laravel_scheduler_task.log"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

if not exist "%PHP_EXE%" (
    echo [%date% %time%] Failed: cannot find PHP at %PHP_EXE%.>>"%LOG_FILE%"
    exit /b 1
)

pushd "%APP_DIR%"
if errorlevel 1 (
    echo [%date% %time%] Failed: cannot enter %APP_DIR%.>>"%LOG_FILE%"
    exit /b 1
)

echo [%date% %time%] Start php artisan schedule:run --whisper>>"%LOG_FILE%"
"%PHP_EXE%" artisan schedule:run --whisper >>"%LOG_FILE%" 2>&1
set "EXIT_CODE=!ERRORLEVEL!"
echo [%date% %time%] Finished php artisan schedule:run --whisper exit_code=!EXIT_CODE!>>"%LOG_FILE%"

popd
exit /b !EXIT_CODE!
