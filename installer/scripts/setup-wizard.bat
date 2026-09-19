@echo off
setlocal
title Saffron POS - Setup Wizard
color 0B

set "BASE=%~dp0"
set "PHP=%BASE%php"
set "MARIADB=%BASE%mariadb"
set "APP=%BASE%app"

echo.
echo  ========================================
echo   Saffron POS - Setup Wizard
echo  ========================================
echo.

:: ── Make sure MariaDB is running ──
tasklist /FI "IMAGENAME eq mariadbd.exe" 2>nul | find /I "mariadbd.exe" >nul
if %errorlevel% neq 0 (
    echo  Starting database...
    if not exist "%MARIADB%\data" (
        echo  Initializing database...
        "%MARIADB%\bin\mariadb-install-db.exe" --datadir="%MARIADB%\data" --password="" >nul 2>&1
    )
    start "" /B "%MARIADB%\bin\mariadbd.exe" --datadir="%MARIADB%\data" --port=3306 --console >nul 2>&1
    timeout /t 3 /nobreak >nul
    echo  Database started.
) else (
    echo  Database is running.
)

:: ── Make sure PHP is running ──
tasklist /FI "IMAGENAME eq php-cgi.exe" 2>nul | find /I "php-cgi.exe" >nul
if %errorlevel% neq 0 (
    tasklist /FI "IMAGENAME eq php.exe" 2>nul | find /I "php.exe" >nul
    if %errorlevel% neq 0 (
        echo  Starting PHP...
        if exist "%PHP%\php-cgi.exe" (
            start "" /B "%PHP%\php-cgi.exe" -b 127.0.0.1:9000 -c "%PHP%\php.ini" >nul 2>&1
        ) else (
            start "" /B "%PHP%\php.exe" -S 127.0.0.1:9000 -t "%APP%" >nul 2>&1
        )
        timeout /t 2 /nobreak >nul
        echo  PHP started.
    )
)

:: ── Make sure Nginx is running ──
tasklist /FI "IMAGENAME eq nginx.exe" 2>nul | find /I "nginx.exe" >nul
if %errorlevel% neq 0 (
    echo  Starting web server...
    start "" /B "%BASE%nginx\nginx.exe" -p "%BASE%nginx" -c "%BASE%nginx\conf\nginx.conf" >nul 2>&1
    timeout /t 1 /nobreak >nul
    echo  Web server started.
)

:: ── Initialize database tables ──
echo.
echo  Setting up database...
"%PHP%\php.exe" "%APP%\setup_db.php" 2>nul
if %errorlevel% neq 0 (
    echo  [NOTE] If database setup had issues, you can configure manually.
)

:: ── Open setup page ──
echo.
echo  Opening setup wizard in your browser...
start http://localhost:8080/setup.php

echo.
echo  ========================================
echo   Follow the instructions in your browser
echo   to configure your shop details.
echo  ========================================
echo.
pause
