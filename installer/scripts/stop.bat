@echo off
setlocal
title Saffron POS - Stop Server
color 0C

echo ============================================================
echo    Saffron POS - Stopping Services
echo ============================================================
echo.

:: ── Stop Nginx ──
echo [1/4] Stopping Nginx...
taskkill /F /IM nginx.exe >nul 2>&1
if %errorlevel%==0 (
    echo   [OK] Nginx stopped.
) else (
    echo   [OK] Nginx was not running.
)

:: ── Stop PHP-FPM ──
echo [2/4] Stopping PHP-FPM...
taskkill /F /IM php-fpm.exe >nul 2>&1
if %errorlevel%==0 (
    echo   [OK] PHP-FPM stopped.
) else (
    echo   [OK] PHP-FPM was not running.
)

:: ── Stop PHP built-in server (fallback) ──
echo [3/4] Stopping PHP server...
taskkill /F /IM php.exe >nul 2>&1
if %errorlevel%==0 (
    echo   [OK] PHP server stopped.
) else (
    echo   [OK] PHP server was not running.
)

:: ── Stop MariaDB ──
echo [4/4] Stopping MariaDB...
taskkill /F /IM mariadbd.exe >nul 2>&1
if %errorlevel%==0 (
    echo   [OK] MariaDB stopped.
) else (
    echo   [OK] MariaDB was not running.
)

echo.
echo ============================================================
echo    All services stopped.
echo ============================================================
echo.
timeout /t 3 /nobreak >nul
