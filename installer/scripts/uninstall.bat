@echo off
setlocal
title Saffron POS - Uninstall
color 0C

echo ============================================================
echo    Saffron POS - Uninstall
echo ============================================================
echo.
echo    This will:
echo    1. Stop all running services
echo    2. Remove the application
echo    3. Optionally remove the database
echo.

set /p "confirm=Are you sure you want to uninstall Saffron POS? (Y/N): "
if /i not "%confirm%"=="Y" (
    echo Uninstall cancelled.
    exit /b 0
)

echo.
echo Stopping services...
taskkill /F /IM nginx.exe >nul 2>&1
taskkill /F /IM php-cgi.exe >nul 2>&1
taskkill /F /IM mariadbd.exe >nul 2>&1
echo   [OK] Services stopped.

echo.
set /p "dropdb=Do you want to remove the database too? (Y/N): "
if /i "%dropdb%"=="Y" (
    echo Removing database...
    "%~dp0..\mariadb\bin\mariadb.exe" -u root -e "DROP DATABASE IF EXISTS saffron_pos" 2>nul
    echo   [OK] Database removed.
)

echo.
echo ============================================================
echo    Uninstall complete.
echo    Run the installer again to reinstall.
echo ============================================================
echo.
timeout /t 5 /nobreak >nul
