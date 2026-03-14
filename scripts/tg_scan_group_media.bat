@echo off
setlocal

set "APP_DIR=C:\www\blog"
set "PHP_EXE=C:\php\php.exe"
set "BASE_URI=http://127.0.0.1:8000/"

if not "%~1"=="" set "BASE_URI=%~1"

pushd "%APP_DIR%"
if errorlevel 1 exit /b 1

echo [%date% %time%] Start tg scan group media. base_uri=%BASE_URI%
"%PHP_EXE%" artisan tg:scan-group-media --until-empty --base-uri=%BASE_URI%
set "EXIT_CODE=%ERRORLEVEL%"
echo [%date% %time%] Finished tg scan group media. exit_code=%EXIT_CODE%

popd
exit /b %EXIT_CODE%
