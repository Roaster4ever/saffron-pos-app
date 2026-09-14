@echo off
setlocal enabledelayedexpansion
title Bukhari POS - MariaDB Runtime Setup
color 0B

echo ============================================================
echo    BUKHARI POS - MariaDB Runtime Setup for Windows
echo ============================================================
echo.
echo This script helps you prepare the MariaDB runtime for bundling.
echo.
echo REQUIRED: Download MariaDB 11.x "WinX64" (MSI or ZIP)
echo from: https://mariadb.org/download/
echo.
echo Choose "WinX64" ZIP archive (not MSI installer).
echo Recommended: mariadb-11.x-winx64.zip
echo.

set "MARIADB_ZIP=%~dp0mariadb-winx64.zip"
set "MARIADB_DIR=%~dp0resources\mariadb"

if exist "%MARIADB_ZIP%" (
    echo Found MariaDB zip: %MARIADB_ZIP%
    echo.
    echo Extracting...
    if not exist "%MARIADB_DIR%" mkdir "%MARIADB_DIR%"
    powershell -Command "Expand-Archive -Path '%MARIADB_ZIP%' -DestinationPath '%MARIADB_DIR%' -Force"
    if %errorlevel% neq 0 (
        echo [FAIL] Extraction failed.
        pause
        exit /b 1
    )
    
    :: Check if files are in a subdirectory
    for /d %%D in ("%MARIADB_DIR%\mariadb-*") do (
        echo Moving files from subdirectory...
        xcopy /E /I /Q /Y "%%D\*" "%MARIADB_DIR%\" >nul 2>&1
        rmdir /s /q "%%D" 2>nul
    )
    
    echo [OK] MariaDB extracted to: %MARIADB_DIR%
) else (
    echo MariaDB zip not found at: %MARIADB_ZIP%
    echo.
    echo Please download MariaDB from https://mariadb.org/download/
    echo Select "WinX64" and download the ZIP archive.
    echo.
    echo Place the zip file here as: mariadb-winx64.zip
    echo Then run this script again.
    pause
    exit /b 1
)

:: Verify key executables
set "MISSING=0"
if not exist "%MARIADB_DIR%\bin\mariadbd.exe" (
    echo [WARN] mariadbd.exe not found
    set /a MISSING+=1
)
if not exist "%MARIADB_DIR%\bin\mariadb.exe" (
    echo [WARN] mariadb.exe client not found
    set /a MISSING+=1
)
if not exist "%MARIADB_DIR%\bin\mariadb-dump.exe" (
    echo [WARN] mariadb-dump.exe not found (needed for backup)
    set /a MISSING+=1
)

if %MISSING% gtr 0 (
    echo.
    echo [WARN] Some tools are missing. Check the extracted structure.
    echo The MariaDB ZIP should have bin\, share\, data\ directories.
)

:: Create required directories
echo.
echo Creating data directories...
mkdir "%MARIADB_DIR%\data" 2>nul
mkdir "%MARIADB_DIR%\tmp" 2>nul

echo.
echo ============================================================
echo    MARIADB RUNTIME READY
echo ============================================================
echo    Location: %MARIADB_DIR%
if exist "%MARIADB_DIR%\bin\mariadbd.exe" echo    Server: mariadbd.exe
if exist "%MARIADB_DIR%\bin\mariadb.exe" echo    Client: mariadb.exe
if exist "%MARIADB_DIR%\bin\mariadb-dump.exe" echo    Dump: mariadb-dump.exe
echo ============================================================

echo.
pause
