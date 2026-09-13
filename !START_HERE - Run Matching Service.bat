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

echo Starting matching service on http://localhost:5000 ...
echo Keep this window open while using CareerPath AI. Close it to stop the service.
echo.
python app.py

echo.
echo Matching service stopped or crashed. See any error above.
pause
