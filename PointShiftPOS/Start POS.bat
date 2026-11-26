@echo off
setlocal EnableExtensions
title TechShift POS
cd /d "%~dp0"

rem ---------- configuration ----------
set "ROOT=%CD%"
set "PHP=%ROOT%\runtime\php\php.exe"
set "PHPCFG=%ROOT%\runtime\php\php.ini"
set "PGBIN=%ROOT%\runtime\pgsql\bin"
set "PGDATA=%ROOT%\data"
set "LOGS=%ROOT%\logs"
set "BACKUPS=%ROOT%\backups"
set "SETUP=%ROOT%\setup"

rem ---------- desktop app: keep data outside the program folder ----------
rem The desktop launcher sets TECHSHIFT_DATA_ROOT so database, logs and
rem backups survive app updates. The portable ZIP does not set it.
if defined TECHSHIFT_DATA_ROOT (
    set "PGDATA=%TECHSHIFT_DATA_ROOT%\data"
    set "LOGS=%TECHSHIFT_DATA_ROOT%\logs"
    set "BACKUPS=%TECHSHIFT_DATA_ROOT%\backups"
)
set "SETUP_MARKER=%SETUP%\.db_ready"
if defined TECHSHIFT_DATA_ROOT set "SETUP_MARKER=%TECHSHIFT_DATA_ROOT%\.db_ready"

set "APPPORT=8787"
set "PGPORT=55433"
set "APPURL=http://localhost:%APPPORT%/pos/"
set "PING=%SystemRoot%\System32\ping.exe"

set "POS_DB_HOST=127.0.0.1"
set "POS_DB_PORT=%PGPORT%"
set "POS_DB_NAME=pos"
set "POS_DB_USER=admin"
set "POS_DB_PASS=admin"
set "POS_BASE_URL=http://localhost:%APPPORT%/pos"

if not exist "%LOGS%" mkdir "%LOGS%"
if not exist "%BACKUPS%" mkdir "%BACKUPS%"

rem ---------- already running? ----------
powershell -NoProfile -Command "if (Get-NetTCPConnection -LocalPort %APPPORT% -State Listen -ErrorAction SilentlyContinue) { exit 0 } else { exit 1 }"
if not errorlevel 1 (
    "%PGBIN%\pg_isready.exe" -h 127.0.0.1 -p %PGPORT% >nul 2>&1
    if not errorlevel 1 (
        echo TechShift POS is already running. Opening your browser...
        if not defined TS_NO_BROWSER start "" "%APPURL%"
        "%PING%" -n 3 127.0.0.1 >nul
        exit /b 0
    )
)

rem ---------- first run: create the database ----------
if not exist "%PGDATA%\PG_VERSION" (
    echo.
    echo ==============================================
    echo   TechShift POS - first-time setup
    echo ==============================================
    echo Creating the database, please wait...
    "%PGBIN%\initdb.exe" -D "%PGDATA%" -U postgres --auth=trust --encoding=UTF8 --locale=C > "%LOGS%\initdb.log" 2>&1
    if errorlevel 1 (
        echo.
        echo ERROR: could not create the database.
        echo See "%LOGS%\initdb.log" for details.
        pause
        exit /b 1
    )
)

rem ---------- start PostgreSQL ----------
"%PGBIN%\pg_isready.exe" -h 127.0.0.1 -p %PGPORT% >nul 2>&1
if errorlevel 1 (
    "%PGBIN%\pg_ctl.exe" -D "%PGDATA%" -l "%LOGS%\postgres.log" -o "-p %PGPORT% -c listen_addresses=127.0.0.1" start >> "%LOGS%\pg_ctl.log" 2>&1
)

set "PGREADY="
for /l %%i in (1,1,30) do (
    "%PGBIN%\pg_isready.exe" -h 127.0.0.1 -p %PGPORT% >nul 2>&1
    if not errorlevel 1 (
        set "PGREADY=1"
        goto :pg_ready
    )
    "%PING%" -n 2 127.0.0.1 >nul
)
:pg_ready
if not defined PGREADY (
    echo.
    echo ERROR: the database did not start.
    echo See "%LOGS%\postgres.log" and "%LOGS%\pg_ctl.log" for details.
    pause
    exit /b 1
)

rem ---------- create application database (only once) ----------
if not exist "%SETUP_MARKER%" (
    echo Creating application tables...
    "%PGBIN%\psql.exe" -h 127.0.0.1 -p %PGPORT% -U postgres -d postgres -c "CREATE ROLE admin LOGIN PASSWORD 'admin';" >nul 2>&1
    "%PGBIN%\psql.exe" -h 127.0.0.1 -p %PGPORT% -U postgres -d postgres -c "CREATE DATABASE pos OWNER admin;" >nul 2>&1
    "%PGBIN%\psql.exe" -h 127.0.0.1 -p %PGPORT% -U admin -d pos -v ON_ERROR_STOP=1 -f "%SETUP%\sql\schema.sql" > "%LOGS%\setup.log" 2>&1
    if errorlevel 1 goto :setup_failed
    "%PGBIN%\psql.exe" -h 127.0.0.1 -p %PGPORT% -U admin -d pos -v ON_ERROR_STOP=1 -f "%SETUP%\sql\seed.sql" >> "%LOGS%\setup.log" 2>&1
    if errorlevel 1 goto :setup_failed
    echo ready> "%SETUP_MARKER%"
    echo Database created. Default login: admin / admin
)

rem ---------- apply schema updates (safe to run every start) ----------
if exist "%SETUP%\sql\migrate.sql" (
    "%PGBIN%\psql.exe" -h 127.0.0.1 -p %PGPORT% -U admin -d pos -v ON_ERROR_STOP=1 -f "%SETUP%\sql\migrate.sql" > "%LOGS%\migrate.log" 2>&1
)

rem ---------- daily backup ----------
for /f %%d in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd"') do set "TODAY=%%d"
if not exist "%BACKUPS%\pos_%TODAY%.sql" (
    "%PGBIN%\pg_dump.exe" -h 127.0.0.1 -p %PGPORT% -U admin -d pos -f "%BACKUPS%\pos_%TODAY%.sql" >nul 2>&1
)
powershell -NoProfile -Command "Get-ChildItem '%BACKUPS%\pos_*.sql' -ErrorAction SilentlyContinue | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-30) } | Remove-Item -Force -ErrorAction SilentlyContinue"

rem ---------- start the web server ----------
powershell -NoProfile -Command "if (Get-NetTCPConnection -LocalPort %APPPORT% -State Listen -ErrorAction SilentlyContinue) { exit 0 } else { exit 1 }"
if not errorlevel 1 (
    set "WEBREADY=1"
    goto :web_ready
)

pushd "%ROOT%\www\pos"
rem Desktop mode (TS_NO_BROWSER) starts the server attached to the hidden
rem parent console so no stray console window appears in the shop.
if defined TS_NO_BROWSER (
    start /b "" "%PHP%" -c "%PHPCFG%" -S 127.0.0.1:%APPPORT% -t ".." > "%LOGS%\web_server.log" 2>&1
) else (
    start "TechShift POS Server" /min "%PHP%" -c "%PHPCFG%" -S 127.0.0.1:%APPPORT% -t ".." > "%LOGS%\web_server.log" 2>&1
)
popd

set "WEBREADY="
for /l %%i in (1,1,15) do (
    powershell -NoProfile -Command "if (Get-NetTCPConnection -LocalPort %APPPORT% -State Listen -ErrorAction SilentlyContinue) { exit 0 } else { exit 1 }"
    if not errorlevel 1 (
        set "WEBREADY=1"
        goto :web_ready
    )
    "%PING%" -n 2 127.0.0.1 >nul
)
:web_ready
if not defined WEBREADY (
    echo.
    echo ERROR: the web server did not start.
    pause
    exit /b 1
)

if not defined TS_NO_BROWSER start "" "%APPURL%"
exit /b 0

:setup_failed
echo.
echo ERROR: the database setup failed.
echo See "%LOGS%\setup.log" for details.
pause
exit /b 1
