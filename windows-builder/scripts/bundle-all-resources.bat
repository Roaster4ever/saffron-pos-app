@echo off
setlocal enabledelayedexpansion
title Bukhari POS - Bundle All Resources
color 0A

echo ============================================================
echo    BUKHARI POS - Resource Bundling
echo ============================================================
echo.
echo This script prepares all resources for the Windows build.
echo Run the individual setup scripts first:
echo   1. setup-php.bat      - Extract PHP runtime
echo   2. setup-mariadb.bat  - Extract MariaDB runtime
echo   3. setup-fonts.bat    - Download local fonts
echo   4. setup-icon.bat     - Set up application icon
echo.

set "RESOURCES=%~dp0resources"
set "PHP_DIR=%RESOURCES%\php"
set "MARIADB_DIR=%RESOURCES%\mariadb"
set "FONTS_DIR=%RESOURCES%\fonts"
set "APP_DIR=%RESOURCES%\application"
set "ICONS_DIR=%RESOURCES%\icons"
set "APP_SOURCE=%~dp0..\pos"

echo ============================================================
echo    Checking prerequisites...
echo ============================================================
echo.

set "READY=1"

:: Check PHP
if exist "%PHP_DIR%\php.exe" (
    echo [OK] PHP runtime found
) else (
    echo [MISSING] PHP runtime - run setup-php.bat first
    set "READY=0"
)

:: Check MariaDB
if exist "%MARIADB_DIR%\bin\mariadbd.exe" (
    echo [OK] MariaDB runtime found
) else (
    echo [MISSING] MariaDB runtime - run setup-mariadb.bat first
    set "READY=0"
)

:: Check Fonts
if exist "%FONTS_DIR%\IBMPlexSans-Regular.woff2" (
    echo [OK] Local fonts found
) else (
    echo [WARN] Local fonts not found - run setup-fonts.bat (optional)
)

:: Check Icon
if exist "%ICONS_DIR%\icon.ico" (
    echo [OK] Application icon found
) else (
    echo [WARN] Custom icon not found - will use default (optional)
)

:: Check Application Source
if exist "%APP_SOURCE%\includes\config.php" (
    echo [OK] PHP application source found
) else (
    echo [MISSING] PHP application source not found
    set "READY=0"
)

echo.

if "%READY%"=="0" (
    echo ============================================================
    echo    Cannot bundle resources. Fix missing items above.
    echo ============================================================
    pause
    exit /b 1
)

echo ============================================================
echo    Bundling resources...
echo ============================================================
echo.

:: 1. Copy PHP application
echo [1/4] Copying PHP application...
if not exist "%APP_DIR%" mkdir "%APP_DIR%"
xcopy /E /I /Q /Y "%APP_SOURCE%\*" "%APP_DIR%\" >nul 2>&1
echo   [OK] Application copied to: %APP_DIR%

:: 2. Copy local fonts
if exist "%FONTS_DIR%\*.woff2" (
    echo [2/4] Bundling fonts...
    if not exist "%APP_DIR%\fonts" mkdir "%APP_DIR%\fonts"
    xcopy /Y "%FONTS_DIR%\*.woff2" "%APP_DIR%\fonts\" >nul 2>&1
    echo   [OK] Fonts bundled
) else (
    echo [2/4] Skipping fonts (not downloaded)
)

:: 3. Copy Windows compatibility layer
echo [3/4] Applying Windows compatibility...
copy /Y "%~dp0resources\application\includes\windows_compat.php" "%APP_DIR%\includes\windows_compat.php" >nul 2>&1
echo   [OK] Windows compatibility layer applied

:: 4. Create portable marker
echo [4/4] Creating portable marker...
echo. > "%APP_DIR%\portable.txt"
echo   [OK] Portable mode marker created

echo.
echo ============================================================
echo    ALL RESOURCES BUNDLED SUCCESSFULLY
echo ============================================================
echo.
echo    Application: %APP_DIR%
echo    PHP:         %PHP_DIR%
echo    MariaDB:     %MARIADB_DIR%
echo    Fonts:       %FONTS_DIR%
echo    Icons:       %ICONS_DIR%
echo.
echo    You can now run: build-windows.bat
echo ============================================================
echo.
pause
