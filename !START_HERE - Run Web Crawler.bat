@echo off
REM Double-click this file to run the CareerPath AI web crawler scripts
REM directly, with live output in this window. Sets up its own Python
REM virtual environment automatically the first time you run it.
REM
REM Note: you don't NEED this file for PhilJobNet/RemoteOK anymore -- the
REM "Run Web Crawler" button on Career Review Queue (careers.php) can start
REM those in the background as long as the matching service is running.
REM Use this .bat instead if you want to watch the crawl live in a terminal,
REM or to run O*NET/Adzuna with their API keys set for this session only.

cd /d "%~dp0crawler"

if not exist venv (
    echo First-time setup: creating a Python virtual environment for the crawler...
    python -m venv venv
    if errorlevel 1 (
        echo.
        echo Could not create the virtual environment. Make sure Python is installed
        echo and available as "python" in your PATH.
        pause
        exit /b 1
    )
    call venv\Scripts\activate.bat
    echo Installing required packages...
    pip install -r requirements.txt
) else (
    call venv\Scripts\activate.bat
)

:menu
echo.
echo ============================================
echo   CareerPath AI - Web Crawler
echo ============================================
echo   1. PhilJobNet (Philippines - no setup needed)
echo   2. RemoteOK (International - no setup needed)
echo   3. O*NET (International - needs ONET_USERNAME / ONET_PASSWORD)
echo   4. Adzuna (International - needs ADZUNA_APP_ID / ADZUNA_APP_KEY)
echo   5. Exit
echo ============================================
set /p choice="Choose an option (1-5): "

if "%choice%"=="1" (
    python crawler.py
    goto end
)
if "%choice%"=="2" (
    python remoteok_client.py
    goto end
)
if "%choice%"=="3" (
    if "%ONET_USERNAME%"=="" (
        echo.
        echo ONET_USERNAME / ONET_PASSWORD are not set for this session.
        set /p ONET_USERNAME="Enter your O*NET username: "
        set /p ONET_PASSWORD="Enter your O*NET password: "
    )
    python onet_client.py
    goto end
)
if "%choice%"=="4" (
    if "%ADZUNA_APP_ID%"=="" (
        echo.
        echo ADZUNA_APP_ID / ADZUNA_APP_KEY are not set for this session.
        set /p ADZUNA_APP_ID="Enter your Adzuna app_id: "
        set /p ADZUNA_APP_KEY="Enter your Adzuna app_key: "
    )
    python adzuna_client.py
    goto end
)
if "%choice%"=="5" goto end

echo Invalid choice, try again.
goto menu

:end
echo.
echo Done. Open php/careers.php (Career Review Queue) in your browser to
echo review and approve any new entries.
pause
