@echo off
REM ============================================================
REM  POS WhatsApp - AGENT MODE supervisor.
REM
REM  agent.js already reconnects on its own, but a process that
REM  dies for any reason - an out-of-memory kill, a Node crash,
REM  a machine restart - stays dead until somebody notices and
REM  starts it again. This wraps it in a restart loop, so the
REM  bridge comes back on its own.
REM
REM  LEAVE THIS WINDOW OPEN while sending campaigns. Close it and
REM  the agent stops, exactly as before.
REM
REM  Pass /silent to run it with no window (Startup-folder use).
REM ============================================================

setlocal
cd /d "%~dp0"

set "NODE=%ProgramFiles%\nodejs\node.exe"
if not exist "%NODE%" set "NODE=node"

set "RESTARTS=0"

REM agent-uptime.log keeps the crash history. Without it a bridge that
REM restarts every few minutes looks identical to one that is simply
REM quiet, which is the hardest kind of fault to notice.
set "LOG=agent-uptime.log"

REM /silent is the same restart loop with no window and everything into the log.
REM
REM It used to run agent.js exactly once and exit, which quietly gave up the one
REM thing this file exists for: a scheduled task that started the agent after a
REM reboot would come back up once, and then stay dead through the next crash,
RAM check or Windows update. The bridge would read as "the shop PC is off" for
REM reasons that had nothing to do with the shop PC's network.
if /i "%~1"=="/silent" (
    set "QUIET=1"
) else (
    title POS WhatsApp Agent
    echo.
    echo   POS WhatsApp agent - agent mode.
    echo   ------------------------------------------------------------
    echo   Keep this window open. If the agent ever stops, this will
    echo   start it again by itself.
    echo   Press Ctrl+C to stop it for good.
    echo   ------------------------------------------------------------
    echo.
)

:supervise
echo [%DATE% %TIME%] starting agent.js >> "%LOG%"
if defined QUIET (
    "%NODE%" agent.js >> "%LOG%" 2>&1
) else (
    "%NODE%" agent.js
)
set "RC=%errorlevel%"

REM Back off further on every restart. A ten-second loop is fine for the one
REM crash, but a process that dies immediately every time would otherwise ask
REM WhatsApp for a fresh pairing code over and over, and that is exactly the
REM pattern that earns a "try again later" block on the shop's phone number.
if %RESTARTS% GEQ 5 (
    set "PAUSE=180"
) else if %RESTARTS% GEQ 3 (
    set "PAUSE=60"
) else (
    set "PAUSE=15"
)
set /a RESTARTS+=1

echo [%DATE% %TIME%] agent.js exited with code %RC% - restart %RESTARTS% in %PAUSE%s >> "%LOG%"
if not defined QUIET (
    echo.
    echo   The agent stopped (code %RC%).
    echo   Restarting in %PAUSE% seconds. Press Ctrl+C now to stop it instead.
    echo.
)

REM Sleep through PowerShell, not `timeout`.
REM
REM `timeout` reads the console's input handle. In the background context this
REM file is built for - the scheduled task install-bridge-task.bat creates - there
REM is often no such handle, and it then fails outright and returns at once,
REM which turns the backoff below into a restart loop measured in seconds.
REM
REM It also blocks the opposite way: when the session is being torn down (a logoff,
REM a Windows restart, the task being stopped) the handle exists but never
REM signals, and the wait never finishes. That is what happened here. The log
REM ended on "agent.js exited with code 1073807364 - restart 1 in 15s" and there
REM was no "starting agent.js" line after it, so the restart this file promises
REM in writing never happened and the bridge stayed down until a human noticed.
REM
REM Start-Sleep depends on neither. It is a timer, not console input.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Sleep -Seconds %PAUSE%"

REM Logged after waking, so the file shows the loop is actually alive rather than
REM only that it intends to be. The gap between this line and the next
REM "starting agent.js" is the pause; nothing at all after an exit line means the
REM supervisor itself died.
echo [%DATE% %TIME%] restart %RESTARTS% firing >> "%LOG%"
goto supervise
