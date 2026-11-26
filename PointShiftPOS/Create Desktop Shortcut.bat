@echo off
setlocal EnableExtensions
cd /d "%~dp0"

powershell -NoProfile -Command "$s = (New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Desktop') + '\TechShift POS.lnk'); $s.TargetPath = '%CD%\Start POS.bat'; $s.WorkingDirectory = '%CD%'; $s.IconLocation = '%SystemRoot%\System32\imageres.dll,174'; $s.Description = 'TechShift POS'; $s.Save()"

if errorlevel 1 (
    echo Could not create the shortcut.
    pause
    exit /b 1
)

echo A "TechShift POS" shortcut has been placed on your Desktop.
timeout /t 3 >nul
exit /b 0
