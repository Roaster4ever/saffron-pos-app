@echo off
setlocal
title Saffron POS - Starting Server...
color 0A

set "BASE=%~dp0"
set "PHP=%BASE%php"
set "MARIADB=%BASE%mariadb"
set "NGINX=%BASE%nginx"
set "LOGS=%BASE%logs"
set "APP=%BASE%app"

if not exist "%LOGS%" mkdir "%LOGS%"

echo.
echo  Starting Saffron POS...
echo.

:: ── MariaDB ──
echo  [1/3] Database...
if not exist "%MARIADB%\data" (
    echo        Initializing database...
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
echo  ========================================
echo   Saffron POS is ready!
echo   http://localhost:8080
echo  ========================================
echo.

:: Open browser
start http://localhost:8080

:: Keep running
:loop
timeout /t 5 /nobreak >nul
goto loop
