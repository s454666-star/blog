@echo off
setlocal EnableExtensions

set "APP_DIR=C:\www\blog"
set "PHP_EXE=C:\php\php.exe"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\tw_futures_hourly_prices.log"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

cd /d "%APP_DIR%" || exit /b 1
"%PHP_EXE%" artisan tw-stock:fetch-taiex-futures-hourly --interval=60 >> "%LOG_FILE%" 2>&1
exit /b %ERRORLEVEL%
