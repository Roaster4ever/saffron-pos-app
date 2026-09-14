@echo off
setlocal enabledelayedexpansion
title Bukhari POS - PHP Runtime Setup
color 0B

echo ============================================================
echo    BUKHARI POS - PHP Runtime Setup for Windows
echo ============================================================
echo.
echo This script helps you prepare the PHP runtime for bundling.
echo.
echo REQUIRED: Download PHP 8.3+ Windows (VS16 x64 Non Thread Safe)
echo from: https://windows.php.net/download/
echo.
echo Recommended: php-8.3.x-nts-Win32-vs16-x64.zip
echo.

set "PHP_ZIP=%~dp0php-8.3-nts-Win32-vs16-x64.zip"
set "PHP_DIR=%~dp0resources\php"

if exist "%PHP_ZIP%" (
    echo Found PHP zip: %PHP_ZIP%
    echo.
    echo Extracting...
    if not exist "%PHP_DIR%" mkdir "%PHP_DIR%"
    powershell -Command "Expand-Archive -Path '%PHP_ZIP%' -DestinationPath '%PHP_DIR%' -Force"
    if %errorlevel% neq 0 (
        echo [FAIL] Extraction failed.
        pause
        exit /b 1
    )
    
    :: Check if files are in a subdirectory (common with ZIP extraction)
    if exist "%PHP_DIR%\php-8*" (
        echo Moving files from subdirectory...
        xcopy /E /I /Q /Y "%PHP_DIR%\php-8*\*" "%PHP_DIR%\" >nul 2>&1
        for /d %%D in ("%PHP_DIR%\php-8*") do rmdir /s /q "%%D" 2>nul
    )
    
    echo [OK] PHP extracted to: %PHP_DIR%
) else (
    echo PHP zip not found at: %PHP_ZIP%
    echo.
    echo Please download PHP from https://windows.php.net/download/
    echo and place the zip file here as: php-8.3-nts-Win32-vs16-x64.zip
    echo.
    echo Then run this script again.
    pause
    exit /b 1
)

:: Verify
if exist "%PHP_DIR%\php.exe" (
    echo.
    echo ============================================================
    echo    PHP RUNTIME READY
    echo ============================================================
    echo    Location: %PHP_DIR%
    echo    Executable: php.exe
    echo ============================================================
) else (
    echo [FAIL] php.exe not found after extraction.
    echo Check the extracted directory structure.
)

echo.
pause
