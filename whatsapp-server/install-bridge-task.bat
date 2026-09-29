@echo off
REM ============================================================
REM  Install / remove the WhatsApp bridge as a Windows task that
REM  starts automatically when you log in.
REM
REM  Right-click this file > Run as administrator.
REM ============================================================

setlocal
set "TASK=POS WhatsApp Bridge"

REM start-agent.bat, not start-bridge.bat.
REM
REM start-bridge.bat runs server.js - the old arrangement where the POS dials the
REM bridge over HTTP. In agent mode the POS never dials anything, so a task that
REM started it would leave a bridge on port 3001 that nothing talks to, while the
REM agent that does the work stayed down. Worse, both use the one WhatsApp session
REM in auth/, so they fight over it and break the link. The symptom is the same
REM "bridge is off" the shop reports, and the fix is invisible from the symptom.
REM
REM /silent is the same restart loop with no window, so the task comes back after
REM a reboot and after a crash. See start-agent.bat for why that matters.
set "BAT=%~dp0start-agent.bat /silent"

net session >nul 2>&1
if errorlevel 1 (
    echo.
    echo   Administrator rights are required.
    echo   Right-click this file and choose "Run as administrator".
    echo.
    pause
    exit /b 1
)

if /i "%~1"=="/uninstall" goto uninstall

echo.
echo   Installing auto-start task "%TASK%"...
echo.

REM At logon, run the agent with no window and everything into agent-uptime.log.
schtasks /create /tn "%TASK%" /tr "\"%~dp0start-agent.bat\" /silent" /sc onlogon /rl highest /f >nul

if errorlevel 1 (
    echo   ERROR: could not create the task.
    pause
    exit /b 1
)

echo   Done. The agent will now start automatically when you log in.
echo.
echo   To start it right now without logging off, double-click:
echo     %~dp0start-agent.bat
echo.
echo   To remove auto-start later, run this file as:
echo     install-bridge-task.bat /uninstall
echo.
pause
exit /b 0

:uninstall
echo.
echo   Removing task "%TASK%"...
schtasks /delete /tn "%TASK%" /f >nul 2>&1
if errorlevel 1 (
    echo   Task not found - nothing to remove.
) else (
    echo   Removed. The bridge will no longer start automatically.
)
echo.
pause
exit /b 0
