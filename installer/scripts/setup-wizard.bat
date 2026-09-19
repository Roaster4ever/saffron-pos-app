@echo off
setlocal
title Saffron POS - Setup Wizard
color 0B

set "APP_DIR=%~dp0.."
set "PHP_DIR=%APP_DIR%\php"
set "MARIADB_DIR=%APP_DIR%\mariadb"

echo ============================================================
echo    Saffron POS - Setup Wizard
echo ============================================================
echo.
echo    This wizard will:
echo    1. Initialize the database (if needed)
echo    2. Open the web-based setup page
echo    3. Let you configure shop name, admin password, etc.
echo.

:: ── Check if MariaDB is running ──
echo Checking if MariaDB is running...
tasklist /FI "IMAGENAME eq mariadbd.exe" 2>nul | find /I "mariadbd.exe" >nul
if %errorlevel% neq 0 (
    echo   MariaDB is not running. Starting...
    if not exist "%MARIADB_DIR%\data" (
        echo   Initializing database...
        "%MARIADB_DIR%\bin\mariadb-install-db.exe" --datadir="%MARIADB_DIR%\data" --service=MariaDB --password="" >nul 2>&1
    )
    start "" /B "%MARIADB_DIR%\bin\mariadbd.exe" --datadir="%MARIADB_DIR%\data" --port=3306 --console >nul 2>&1
    timeout /t 3 /nobreak >nul
    echo   [OK] MariaDB started.
) else (
    echo   [OK] MariaDB is already running.
)

:: ── Initialize database if needed ──
echo.
echo Initializing database...
"%PHP_DIR%\php.exe" "%APP_DIR%\scripts\setup-db.php"
if %errorlevel% neq 0 (
    echo   [WARN] Database setup encountered issues. You can configure manually.
)

:: ── Start Nginx if not running ──
tasklist /FI "IMAGENAME eq nginx.exe" 2>nul | find /I "nginx.exe" >nul
if %errorlevel% neq 0 (
    echo Starting web server...
    start "" /B "%APP_DIR%\nginx\nginx.exe" -p "%APP_DIR%\nginx" -c "%APP_DIR%\config\nginx.conf" >nul 2>&1
    timeout /t 2 /nobreak >nul
)

:: ── Open setup page in browser ──
echo.
echo Opening setup wizard in your browser...
start http://localhost:8080/setup.php

echo.
echo ============================================================
echo    Follow the instructions in your browser to complete
echo    the setup. You can close this window when done.
echo ============================================================
echo.
pause
