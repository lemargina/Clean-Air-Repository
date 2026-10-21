@echo off
setlocal
cd /d "%~dp0"
PowerShell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start.ps1"
if errorlevel 1 (
    echo.
    echo The application could not be started. See the error above.
    pause
    exit /b 1
)
endlocal
