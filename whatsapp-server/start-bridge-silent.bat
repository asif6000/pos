@echo off
rem ---------------------------------------------------------------------------
rem  Starts the WhatsApp bridge in the background, with no window.
rem
rem  Called by the Startup-folder shim so the bridge comes back after a
rem  restart without anyone having to start it by hand.
rem
rem  The working directory matters. .env sets SESSION_DIR=session, which is a
rem  relative path, so starting the process from any other folder makes it lose
rem  the saved WhatsApp login and ask for a fresh QR scan every time.
rem
rem  Kept deliberately plain: an earlier version polled /health through a
rem  for-loop, which failed outright under some shells and left the bridge
rem  down. If a bridge is already listening on 3001 the new process exits by
rem  itself, so re-running this is harmless.
rem ---------------------------------------------------------------------------
cd /d "%~dp0"
start "pos-whatsapp-bridge" /min "%ProgramFiles%\nodejs\node.exe" "%~dp0server.js"
exit /b 0
