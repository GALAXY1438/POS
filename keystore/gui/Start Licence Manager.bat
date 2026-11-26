@echo off
setlocal
cd /d "%~dp0"

set "PHP=..\..\PointShiftPOS\runtime\php\php.exe"
set "INI=..\..\PointShiftPOS\runtime\php\php.ini"

if not exist "%PHP%" (
    echo.
    echo Portable PHP was not found at:
    echo   %PHP%
    echo.
    echo Keep the keystore folder inside the private repo, next to PointShiftPOS.
    pause
    exit /b 1
)

start "" powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep 2; Start-Process 'http://127.0.0.1:8799/'"

echo TechShift Licence Manager
echo ------------------------------------------
echo   Address : http://127.0.0.1:8799/
echo   Records : ..\issued\
echo.
echo This window is the server. Close it to stop the manager.
echo.

"%PHP%" -c "%INI%" -S 127.0.0.1:8799 -t "%CD%"
