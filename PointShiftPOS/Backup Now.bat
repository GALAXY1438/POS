@echo off
setlocal EnableExtensions
title TechShift POS - Backup
cd /d "%~dp0"

set "PGBIN=%CD%\runtime\pgsql\bin"
set "BACKUPS=%CD%\backups"
set "PGPORT=55433"

if not exist "%BACKUPS%" mkdir "%BACKUPS%"

"%PGBIN%\pg_isready.exe" -h 127.0.0.1 -p %PGPORT% >nul 2>&1
if errorlevel 1 (
    echo The database is not running. Start TechShift POS first, then run this backup again.
    pause
    exit /b 1
)

for /f %%d in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set "STAMP=%%d"
set "FILE=%BACKUPS%\backup_%STAMP%.sql"

echo Creating backup...
"%PGBIN%\pg_dump.exe" -h 127.0.0.1 -p %PGPORT% -U admin -d pos -f "%FILE%"
if errorlevel 1 (
    echo Backup failed. See the message above.
    pause
    exit /b 1
)

echo.
echo Backup saved to:
echo   %FILE%
echo.
pause
exit /b 0
