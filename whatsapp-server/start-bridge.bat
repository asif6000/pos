@echo off
REM ============================================================
REM  POS Marketing - WhatsApp bridge launcher
REM
REM  Double-click this file to start the bridge. It runs in this
REM  window, so LEAVE THE WINDOW OPEN while you send campaigns.
REM  Right-click the file > Run as administrator to install it as
REM  a Windows task that starts automatically when you log in.
REM ============================================================

setlocal
cd /d "%~dp0"

title POS WhatsApp Bridge

REM --- Node installed? -------------------------------------------------
where node >nul 2>&1
if errorlevel 1 (
    echo.
    echo   ERROR: Node.js is not installed or not in PATH.
    echo   Download it from https://nodejs.org  ^(LTS version^)
    echo   then run this file again.
    echo.
    pause
    exit /b 1
)

REM --- .env present? ---------------------------------------------------
if not exist ".env" (
    echo.
    echo   First run: copying .env.example to .env
    copy /y ".env.example" ".env" >nul
    echo   Done. Open .env and set API_TOKEN to any long random string.
    echo.
    pause
    exit /b 1
)

REM --- Dependencies installed? -----------------------------------------
if not exist "node_modules\whatsapp-web.js" (
    echo.
    echo   First run: installing packages. This takes 2-5 minutes...
    echo.
    call npm.cmd install
    if errorlevel 1 (
        echo.
        echo   ERROR: npm install failed. Check your internet connection.
        echo.
        pause
        exit /b 1
    )
    echo.
    echo   Packages installed. Run this file again to start the bridge.
    pause
    exit /b 1
)

REM --- Already running? ------------------------------------------------
netstat -ano | findstr /R /C:"127.0.0.1:3001 .*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo.
    echo   The bridge is ALREADY running. Nothing to do.
    echo   You can close this window.
    echo.
    timeout /t 4 >nul
    exit /b 0
)

echo.
echo   Starting WhatsApp bridge...
echo   ------------------------------------------------------------
echo   KEEP THIS WINDOW OPEN while sending campaigns.
echo   To stop, press Ctrl+C or close this window.
echo   ------------------------------------------------------------
echo.

node server.js

echo.
echo   The bridge has stopped. Campaigns cannot send until it is
echo   started again - double-click this file.
echo.
pause
