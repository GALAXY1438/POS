@echo off
setlocal EnableExtensions
title TechShift POS - Build & Release
cd /d "%~dp0"

rem Builds the Windows installer and publishes it to GitHub Releases
rem (GALAXY1438/POS). Installed clients then offer the update
rem automatically — no shop visit needed.
rem
rem Usage:  Release.bat 2.0.1 "What changed in this version"

set "VERSION=%~1"
set "NOTES=%~2"
if "%VERSION%"=="" (
    echo Usage: Release.bat VERSION "Release notes"
    echo Example: Release.bat 2.0.1 "Fixed receipt totals"
    pause
    exit /b 1
)

where node >nul 2>&1
if errorlevel 1 (
    echo ERROR: Node.js is not installed or not on PATH.
    pause
    exit /b 1
)

if not defined GH_TOKEN (
    echo A GitHub token with "Contents: Read and write" access to
    echo GALAXY1438/POS is required to publish the update.
    echo.
    set /p GH_TOKEN=Token: 
    if not defined GH_TOKEN exit /b 1
)

echo.
echo Building TechShift POS %VERSION% ...
powershell -NoProfile -Command "$p='package.json'; $j=Get-Content $p -Raw | ConvertFrom-Json; $j.version='%VERSION%'; if ('%NOTES%' -ne '') { $j.build | Add-Member -NotePropertyName releaseInfo -NotePropertyValue ([pscustomobject]@{ releaseNotes = '%NOTES%' }) -Force }; $j | ConvertTo-Json -Depth 20 | Set-Content $p -Encoding UTF8"

if not exist node_modules (
    echo Installing build tools, please wait...
    call npm install
    if errorlevel 1 exit /b 1
)

call npm run release
if errorlevel 1 (
    echo.
    echo ERROR: the build or upload failed. Check the output above.
    pause
    exit /b 1
)

echo.
echo Done. TechShift POS %VERSION% was published to GitHub Releases.
echo Open https://github.com/GALAXY1438/POS/releases and publish
echo the draft release so clients can download it.
pause
