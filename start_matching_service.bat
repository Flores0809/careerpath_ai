@echo off
REM Double-click this file to start the CareerPath AI matching service.
REM It activates the Python virtual environment and runs app.py for you --
REM no need to open Command Prompt or type any commands.

cd /d "%~dp0matching-service"
call venv\Scripts\activate.bat

if errorlevel 1 (
    echo.
    echo Could not activate the virtual environment.
    echo Make sure you ran: python -m venv venv
    echo and: pip install -r requirements.txt --break-system-packages
    echo inside the matching-service folder first.
    pause
    exit /b 1
)

REM Auto-update: only reinstall requirements when requirements.txt has
REM actually changed since the last successful install (tracked via a
REM snapshot file in venv\). This is what used to be missing here --
REM this file used to only install packages the very first time the venv
REM was created, so every later requirements.txt change (like adding
REM waitress) needed a manual `pip install -r requirements.txt` or the
REM service would crash with "ModuleNotFoundError" on startup. Mirrors
REM the same check already in "!START_HERE - Run Matching Service.bat".
fc /b requirements.txt venv\requirements.snapshot.txt >nul 2>&1
if errorlevel 1 (
    echo Installing/updating required packages...
    pip install -r requirements.txt --quiet --disable-pip-version-check
    if errorlevel 1 (
        echo.
        echo Could not install some required packages -- see any error above.
        pause
        exit /b 1
    )
    copy /y requirements.txt venv\requirements.snapshot.txt >nul
)

echo Keep this window open while using CareerPath AI. Close it to stop the service.
echo.
python app.py

echo.
echo Matching service stopped or crashed. See any error above.
pause
