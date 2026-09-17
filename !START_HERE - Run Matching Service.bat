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

REM Only reinstall requirements if requirements.txt changed since the last
REM successful install (compared against a saved snapshot in venv\). This
REM avoids pip re-checking PyPI -- which looks like "downloading every
REM time" -- on every single launch when nothing actually changed.
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

echo Starting matching service on http://localhost:5000 ...
echo Keep this window open while using CareerPath AI. Close it to stop the service.
echo.
python app.py

echo.
echo Matching service stopped or crashed. See any error above.
pause
