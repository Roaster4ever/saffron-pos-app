@echo off
setlocal

taskkill /F /IM nginx.exe >nul 2>&1
taskkill /F /IM php-cgi.exe >nul 2>&1
taskkill /F /IM php.exe >nul 2>&1
taskkill /F /IM mariadbd.exe >nul 2>&1

echo.
echo  Saffron POS stopped.
echo.
timeout /t 2 /nobreak >nul
