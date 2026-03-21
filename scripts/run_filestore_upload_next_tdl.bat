@echo off
setlocal EnableExtensions

call "%~dp0run_filestore_upload_next.bat" --method=tdl %*
exit /b %ERRORLEVEL%
