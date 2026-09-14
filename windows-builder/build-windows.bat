@echo off
setlocal enabledelayedexpansion
title Saffron POS - Windows Build
color 0A

echo ============================================================
echo    BUKHARI POS - Windows Build Script
echo    Version: 1.0.0
echo ============================================================
echo.

:: ── Configuration ──
set "APP_NAME=Saffron POS"
set "APP_VERSION=1.0.0"
set "REQUIRED_NODE_MAJOR=18"
set "REQUIRED_NODE_MINOR=0"
set "BUILD_DIR=%~dp0dist"
set "RESOURCES_DIR=%~dp0resources"
set "APP_SOURCE=%~dp0..\pos"

:: ── Preflight Checks ──
echo [1/8] Running preflight checks...
echo.

set "ERRORS=0"

:: Check 1: Windows
echo   Checking operating system...
if "%OS%" neq "Windows_NT" (
    echo   [FAIL] This script must run on Windows.
    set /a ERRORS+=1
) else (
    echo   [OK] Windows detected.
)

:: Check 2: Architecture
echo   Checking system architecture...
if "%PROCESSOR_ARCHITECTURE%" neq "AMD64" (
    echo   [WARN] 64-bit Windows recommended. Current: %PROCESSOR_ARCHITECTURE%
) else (
    echo   [OK] 64-bit architecture detected.
)

:: Check 3: Node.js
echo   Checking Node.js...
where node >nul 2>&1
if %errorlevel% neq 0 (
    echo   [FAIL] Node.js is not installed or not in PATH.
    echo          Install Node.js LTS from https://nodejs.org/
    set /a ERRORS+=1
) else (
    for /f "tokens=1 delims=." %%a in ('node -v') do set "NODE_MAJOR=%%a"
    set "NODE_MAJOR=!NODE_MAJOR:v=!"
    if !NODE_MAJOR! lss %REQUIRED_NODE_MAJOR% (
        echo   [FAIL] Node.js version !NODE_MAJOR! is too old. Need ^>= %REQUIRED_NODE_MAJOR%.
        set /a ERRORS+=1
    ) else (
        echo   [OK] Node.js v!NODE_MAJOR! detected.
    )
)

:: Check 4: npm
echo   Checking npm...
where npm >nul 2>&1
if %errorlevel% neq 0 (
    echo   [FAIL] npm is not installed or not in PATH.
    set /a ERRORS+=1
) else (
    for /f "tokens=*" %%a in ('npm -v 2^>nul') do set "NPM_VER=%%a"
    echo   [OK] npm v!NPM_VER! detected.
)

:: Check 5: Project files
echo   Checking project files...
set "REQUIRED_FILES=package.json electron\main.js electron\preload.js scripts\installer.nsh"
for %%f in (%REQUIRED_FILES%) do (
    if not exist "%~dp0%%f" (
        echo   [FAIL] Missing: %%f
        set /a ERRORS+=1
    )
)
if %ERRORS%==0 echo   [OK] All required files present.

:: Check 6: PHP runtime
echo   Checking bundled PHP runtime...
if exist "%RESOURCES_DIR%\php\php.exe" (
    echo   [OK] PHP runtime found.
) else (
    echo   [FAIL] PHP runtime not found at: resources\php\php.exe
    echo          Download PHP from https://windows.php.net/download/
    echo          Extract to: resources\php\
    set /a ERRORS+=1
)

:: Check 7: MariaDB runtime
echo   Checking bundled MariaDB runtime...
if exist "%RESOURCES_DIR%\mariadb\bin\mariadbd.exe" (
    echo   [OK] MariaDB runtime found.
) else (
    echo   [FAIL] MariaDB runtime not found at: resources\mariadb\bin\mariadbd.exe
    echo          Download MariaDB from https://mariadb.org/download/
    echo          Extract to: resources\mariadb\
    set /a ERRORS+=1
)

:: Check 8: Application source
echo   Checking application source...
if exist "%APP_SOURCE%\includes\config.php" (
    echo   [OK] PHP application source found.
) else (
    echo   [FAIL] PHP application not found at: ..\pos\includes\config.php
    echo          Ensure the windows-builder folder is inside the POS project.
    set /a ERRORS+=1
)

:: Check 9: Icons
echo   Checking application icons...
if exist "%RESOURCES_DIR%\icons\icon.ico" (
    echo   [OK] Application icon found.
) else (
    echo   [WARN] No icon.ico found. Build will use default Electron icon.
)

echo.

if %ERRORS% gtr 0 (
    echo ============================================================
    echo    BUILD CANNOT START
    echo    %ERRORS% prerequisite(s) missing.
    echo    Fix the issues above and run build-windows.bat again.
    echo ============================================================
    pause
    exit /b 1
)

echo ============================================================
echo    All preflight checks passed.
echo ============================================================
echo.

:: ── Prepare Resources ──
echo [2/8] Preparing application resources...

:: Copy PHP application to resources
echo   Copying PHP application...
if not exist "%RESOURCES_DIR%\application" mkdir "%RESOURCES_DIR%\application"
xcopy /E /I /Q /Y "%APP_SOURCE%\*" "%RESOURCES_DIR%\application\" >nul 2>&1

:: Add Windows compatibility layer
copy /Y "%~dp0resources\application\includes\windows_compat.php" "%RESOURCES_DIR%\application\includes\windows_compat.php" >nul 2>&1

:: Create portable marker
echo. > "%RESOURCES_DIR%\application\portable.txt"

echo   [OK] Application resources prepared.
echo.

:: ── Install Dependencies ──
echo [3/8] Installing npm dependencies...
call npm install
if %errorlevel% neq 0 (
    echo   [FAIL] npm install failed.
    pause
    exit /b 1
)
echo   [OK] Dependencies installed.
echo.

:: ── Verify Dependencies ──
echo [4/8] Verifying dependencies...
if not exist "node_modules\electron" (
    echo   [FAIL] Electron not found in node_modules.
    pause
    exit /b 1
)
if not exist "node_modules\electron-builder" (
    echo   [FAIL] electron-builder not found in node_modules.
    pause
    exit /b 1
)
echo   [OK] All dependencies verified.
echo.

:: ── Copy Application Source ──
echo [5/8] Copying application to build resources...
if not exist "%RESOURCES_DIR%\application\pages" mkdir "%RESOURCES_DIR%\application\pages"
xcopy /E /I /Q /Y "%APP_SOURCE%\*" "%RESOURCES_DIR%\application\" >nul 2>&1
echo   [OK] Application source copied.
echo.

:: ── Build Electron ──
echo [6/8] Building Electron application...
echo   This may take several minutes...
call npx electron-builder --win
if %errorlevel% neq 0 (
    echo   [FAIL] Electron build failed.
    echo   Check the error output above.
    pause
    exit /b 1
)
echo   [OK] Electron build complete.
echo.

:: ── Verify Output ──
echo [7/8] Verifying build output...
if exist "%BUILD_DIR%\Saffron POS Setup %APP_VERSION% x64.exe" (
    echo   [OK] Installer found: dist\Saffron POS Setup %APP_VERSION% x64.exe
    for %%A in ("%BUILD_DIR%\Saffron POS Setup %APP_VERSION% x64.exe") do (
        set "SIZE=%%~zA"
        set /a "SIZE_MB=!SIZE! / 1048576"
        echo   Size: !SIZE_MB! MB
    )
) else (
    echo   [WARN] Installer not found at expected path. Checking dist folder...
    dir "%BUILD_DIR%\*.exe" /b 2>nul
)
echo.

:: ── Done ──
echo ============================================================
echo    BUILD SUCCESSFUL
echo ============================================================
echo.
echo    Installer:
echo    %BUILD_DIR%\Saffron POS Setup %APP_VERSION% x64.exe
echo.
echo    Size:
if exist "%BUILD_DIR%\Saffron POS Setup %APP_VERSION% x64.exe" (
    for %%A in ("%BUILD_DIR%\Saffron POS Setup %APP_VERSION% x64.exe") do (
        set "SIZE=%%~zA"
        set /a "SIZE_MB=!SIZE! / 1048576"
        echo    !SIZE_MB! MB
    )
)
echo.
echo ============================================================
echo.
pause
