@echo off
setlocal
title Saffron POS - Start Server
color 0A

set "APP_DIR=%~dp0.."
set "PHP_DIR=%APP_DIR%\php"
set "NGINX_DIR=%APP_DIR%\nginx"
set "MARIADB_DIR=%APP_DIR%\mariadb"
set "LOG_DIR=%APP_DIR%\logs"
set "APP_SRC=%APP_DIR%\app"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

echo ============================================================
echo    Saffron POS - Starting Services
echo ============================================================
echo.

:: ── Start MariaDB ──
echo [1/4] Starting MariaDB...
if not exist "%MARIADB_DIR%\data" (
    echo   Initializing database...
    "%MARIADB_DIR%\bin\mariadb-install-db.exe" --datadir="%MARIADB_DIR%\data" --password="" >nul 2>&1
)
start "" /B "%MARIADB_DIR%\bin\mariadbd.exe" --datadir="%MARIADB_DIR%\data" --port=3306 --console >"%LOG_DIR%\mariadb.log" 2>&1
timeout /t 3 /nobreak >nul
echo   [OK] MariaDB started on port 3306.

:: ── Start PHP-FPM (if available) or PHP built-in server ──
echo [2/4] Starting PHP...
if exist "%PHP_DIR%\php-fpm.exe" (
    start "" /B "%PHP_DIR%\php-fpm.exe" --php-ini="%PHP_DIR%\php.ini" --fpm-config="%APP_DIR%\config\php-fpm.conf" >"%LOG_DIR%\php-fpm.log" 2>&1
    timeout /t 2 /nobreak >nul
    echo   [OK] PHP-FPM started on port 9000.
) else (
    echo   PHP-FPM not found, using built-in server...
    start "" /B "%PHP_DIR%\php.exe" -S 127.0.0.1:9000 -t "%APP_SRC%" >"%LOG_DIR%\php-server.log" 2>&1
    timeout /t 2 /nobreak >nul
    echo   [OK] PHP built-in server started on port 9000.
)

:: ── Start Nginx ──
echo [3/4] Starting Nginx...
start "" /B "%NGINX_DIR%\nginx.exe" -p "%NGINX_DIR%" -c "%APP_DIR%\config\nginx.conf" >"%LOG_DIR%\nginx.log" 2>&1
timeout /t 1 /nobreak >nul
echo   [OK] Nginx started on port 8080.

:: ── Done ──
echo [4/4] All services ready.
echo.
echo ============================================================
echo    Saffron POS is running!
echo    Open: http://localhost:8080
echo ============================================================
echo.
echo    Default login: admin / admin123
echo    Press Ctrl+C in this window to stop all services.
echo ============================================================
echo.

:: Keep window open and wait for Ctrl+C
:loop
timeout /t 5 /nobreak >nul
goto loop
