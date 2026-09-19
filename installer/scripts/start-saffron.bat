@echo off
setlocal
title Saffron POS
color 0A

set "BASE=%~dp0"
set "PHP=%BASE%php"
set "MARIADB=%BASE%mariadb"
set "NGINX=%BASE%nginx"
set "LOGS=%BASE%logs"
set "APP=%BASE%app"

if not exist "%LOGS%" mkdir "%LOGS%"

:: Check if already running
tasklist /FI "IMAGENAME eq nginx.exe" 2>nul | find /I "nginx.exe" >nul
if %errorlevel%==0 (
    echo  Saffron POS is already running.
    start http://localhost:8080
    exit /b 0
)

echo.
echo  Starting Saffron POS...
echo.

:: ── MariaDB ──
echo  [1/3] Database...
if not exist "%MARIADB%\data" (
    echo        Initializing...
    "%MARIADB%\bin\mariadb-install-db.exe" --datadir="%MARIADB%\data" --password="" >nul 2>&1
)
start "" /B "%MARIADB%\bin\mariadbd.exe" --datadir="%MARIADB%\data" --port=3306 --console >"%LOGS%\mariadb.log" 2>&1
timeout /t 3 /nobreak >nul
echo        OK

:: ── PHP ──
echo  [2/3] PHP engine...
if exist "%PHP%\php-cgi.exe" (
    start "" /B "%PHP%\php-cgi.exe" -b 127.0.0.1:9000 -c "%PHP%\php.ini" >"%LOGS%\php.log" 2>&1
) else (
    start "" /B "%PHP%\php.exe" -S 127.0.0.1:9000 -t "%APP%" >"%LOGS%\php.log" 2>&1
)
timeout /t 2 /nobreak >nul
echo        OK

:: ── Nginx ──
echo  [3/3] Web server...
start "" /B "%NGINX%\nginx.exe" -p "%NGINX%" -c "%BASE%nginx\conf\nginx.conf" >"%LOGS%\nginx.log" 2>&1
timeout /t 1 /nobreak >nul
echo        OK

echo.
echo  Opening Saffron POS...
echo.

:: Wait a moment then open browser
timeout /t 2 /nobreak >nul
start http://localhost:8080

:: Keep window open
:loop
timeout /t 10 /nobreak >nul
:: Check if still running
tasklist /FI "IMAGENAME eq nginx.exe" 2>nul | find /I "nginx.exe" >nul
if %errorlevel% neq 0 (
    echo.
    echo  Server stopped. Close this window or restart.
    pause
    exit /b 0
)
goto loop
