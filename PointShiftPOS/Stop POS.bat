@echo off
setlocal EnableExtensions
title TechShift POS - Stop
cd /d "%~dp0"

set "PGBIN=%CD%\runtime\pgsql\bin"
set "PGDATA=%CD%\data"
if defined TECHSHIFT_DATA_ROOT set "PGDATA=%TECHSHIFT_DATA_ROOT%\data"
set "APPPORT=8787"
set "PGPORT=55433"
set "PING=%SystemRoot%\System32\ping.exe"

echo Stopping the TechShift POS web server...
powershell -NoProfile -Command "$c = Get-NetTCPConnection -LocalPort %APPPORT% -State Listen -ErrorAction SilentlyContinue; if ($c) { $c | Select-Object -ExpandProperty OwningProcess -Unique | ForEach-Object { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue } }" >nul 2>&1

echo Stopping the database...
"%PGBIN%\pg_ctl.exe" -D "%PGDATA%" stop -m fast >nul 2>&1

echo.
echo TechShift POS has been stopped.
"%PING%" -n 4 127.0.0.1 >nul
exit /b 0
