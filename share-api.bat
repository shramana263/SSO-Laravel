@echo off
title SSO Laravel - Public HTTPS Tunnel
echo =======================================================================
echo   SSO LARAVEL - PUBLIC HTTPS TUNNEL (AUTO-RECONNECT)
echo   Forwarding external HTTPS traffic to local Laragon (ssolaravel.local)
echo =======================================================================
echo.
echo Keep this window open. If disconnected, it will automatically reconnect.
echo.

:loop
lt --port 80 --local-host ssolaravel.local
echo.
echo Connection dropped. Reconnecting in 2 seconds...
timeout /t 2 /nobreak >nul
goto loop
