@echo off
setlocal enabledelayedexpansion
title Saffron POS - Windows Installer Builder
color 0B
mode con: cols=80 lines=50

echo ============================================================
echo    SAFFRON POS - Windows Installer Builder
echo    One-click build: downloads everything, produces .exe
echo ============================================================
echo.

:: ── Configuration ──
set "APP_NAME=Saffron POS"
set "APP_VERSION=2.0.0"
set "BUILD_DIR=%~dp0dist"
set "RUNTIME_DIR=%~dp0runtime"
set "RESOURCES_DIR=%~dp0resources"
set "SCRIPTS_DIR=%~dp0scripts"
set "SOURCE_DIR=%~dp0.."
set "PHP_VERSION=8.2.12"
set "MARIADB_VERSION=11.4.5"
set "NGINX_VERSION=1.26.2"
set "INNO_SETUP_URL=https://files.jrsoftware.org/innosetup/innosetup-6.3.3.exe"
set "ERRORS=0"

:: ── Step 1: Check Windows ──
echo [1/8] Checking environment...
echo.
if "%OS%" neq "Windows_NT" (
    echo   [FAIL] This script must run on Windows.
    pause
    exit /b 1
)
echo   [OK] Windows detected.
if "%PROCESSOR_ARCHITECTURE%" neq "AMD64" (
    echo   [WARN] 64-bit Windows recommended.
) else (
    echo   [OK] 64-bit architecture.
)

:: ── Step 2: Check/Create directories ──
echo.
echo [2/8] Preparing directories...
echo.
if not exist "%BUILD_DIR%" mkdir "%BUILD_DIR%"
if not exist "%RUNTIME_DIR%\php" mkdir "%RUNTIME_DIR%\php"
if not exist "%RUNTIME_DIR%\mariadb" mkdir "%RUNTIME_DIR%\mariadb"
if not exist "%RUNTIME_DIR%\nginx" mkdir "%RUNTIME_DIR%\nginx"
echo   [OK] Directories ready.

:: ── Step 3: Download PHP ──
echo.
echo [3/8] Downloading PHP %PHP_VERSION%...
echo.
if exist "%RUNTIME_DIR%\php\php.exe" (
    echo   [OK] PHP already downloaded. Skipping.
) else (
    echo   Downloading PHP...
    set "PHP_ZIP=%TEMP%\php-%PHP_VERSION%-Win32-vs16-x64.zip"
    set "PHP_URL=https://windows.php.net/downloads/releases/php-%PHP_VERSION%-Win32-vs16-x64.zip"

    powershell -Command ^
        "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; " ^
        "Write-Host '  Connecting to windows.php.net...'; " ^
        "Invoke-WebRequest -Uri '!PHP_URL!' -OutFile '!PHP_ZIP!' -UseBasicParsing; " ^
        "Write-Host '  Download complete.'"

    if not exist "!PHP_ZIP!" (
        echo   [FAIL] PHP download failed.
        echo   Please download manually from: https://windows.php.net/download/
        echo   Extract to: %RUNTIME_DIR%\php\
        set /a ERRORS+=1
    ) else (
        echo   Extracting PHP...
        powershell -Command "Expand-Archive -Path '!PHP_ZIP!' -DestinationPath '%RUNTIME_DIR%\php_tmp' -Force"
        :: Move contents from subdirectory to runtime root
        for /d %%D in ("%RUNTIME_DIR%\php_tmp\php-*") do (
            xcopy /E /I /Q /Y "%%D\*" "%RUNTIME_DIR%\php\" >nul 2>&1
        )
        rmdir /S /Q "%RUNTIME_DIR%\php_tmp" >nul 2>&1
        del /Q "!PHP_ZIP!" >nul 2>&1
        echo   [OK] PHP extracted.
    )
)

:: ── Step 4: Download MariaDB ──
echo.
echo [4/8] Downloading MariaDB %MARIADB_VERSION%...
echo.
if exist "%RUNTIME_DIR%\mariadb\bin\mariadbd.exe" (
    echo   [OK] MariaDB already downloaded. Skipping.
) else (
    echo   Downloading MariaDB...
    set "MDB_ZIP=%TEMP%\mariadb-%MARIADB_VERSION%-winx64.zip"
    set "MDB_URL=https://archive.mariadb.org/mariadb-%MARIADB_VERSION%/winx64-packages/mariadb-%MARIADB_VERSION%-winx64.zip"

    powershell -Command ^
        "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; " ^
        "Write-Host '  Connecting to archive.mariadb.org...'; " ^
        "try { Invoke-WebRequest -Uri '!MDB_URL!' -OutFile '!MDB_ZIP!' -UseBasicParsing } catch { " ^
        "  Write-Host '  Primary URL failed, trying mirror...'; " ^
        "  $alt = 'https://downloads.mariadb.org/interstitial/mariadb-%MARIADB_VERSION%/winx64-packages/mariadb-%MARIADB_VERSION%-winx64.zip'; " ^
        "  Invoke-WebRequest -Uri $alt -OutFile '!MDB_ZIP!' -UseBasicParsing " ^
        "}; Write-Host '  Download complete.'"

    if not exist "!MDB_ZIP!" (
        echo   [FAIL] MariaDB download failed.
        echo   Please download manually from: https://mariadb.org/download/
        echo   Extract to: %RUNTIME_DIR%\mariadb\
        set /a ERRORS+=1
    ) else (
        echo   Extracting MariaDB...
        powershell -Command "Expand-Archive -Path '!MDB_ZIP!' -DestinationPath '%RUNTIME_DIR%\mdb_tmp' -Force"
        for /d %%D in ("%RUNTIME_DIR%\mdb_tmp\mariadb-*") do (
            xcopy /E /I /Q /Y "%%D\*" "%RUNTIME_DIR%\mariadb\" >nul 2>&1
        )
        rmdir /S /Q "%RUNTIME_DIR%\mdb_tmp" >nul 2>&1
        del /Q "!MDB_ZIP!" >nul 2>&1
        echo   [OK] MariaDB extracted.
    )
)

:: ── Step 5: Download Nginx ──
echo.
echo [5/8] Downloading Nginx %NGINX_VERSION%...
echo.
if exist "%RUNTIME_DIR%\nginx\nginx.exe" (
    echo   [OK] Nginx already downloaded. Skipping.
) else (
    echo   Downloading Nginx...
    set "NGX_ZIP=%TEMP%\nginx-%NGINX_VERSION%.zip"
    set "NGX_URL=https://nginx.org/download/nginx-%NGINX_VERSION%.zip"

    powershell -Command ^
        "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; " ^
        "Write-Host '  Connecting to nginx.org...'; " ^
        "Invoke-WebRequest -Uri '!NGX_URL!' -OutFile '!NGX_ZIP!' -UseBasicParsing; " ^
        "Write-Host '  Download complete.'"

    if not exist "!NGX_ZIP!" (
        echo   [FAIL] Nginx download failed.
        echo   Please download manually from: https://nginx.org/en/download.html
        echo   Extract to: %RUNTIME_DIR%\nginx\
        set /a ERRORS+=1
    ) else (
        echo   Extracting Nginx...
        powershell -Command "Expand-Archive -Path '!NGX_ZIP!' -DestinationPath '%RUNTIME_DIR%\ngx_tmp' -Force"
        for /d %%D in ("%RUNTIME_DIR%\ngx_tmp\nginx-*") do (
            xcopy /E /I /Q /Y "%%D\*" "%RUNTIME_DIR%\nginx\" >nul 2>&1
        )
        rmdir /S /Q "%RUNTIME_DIR%\ngx_tmp" >nul 2>&1
        del /Q "!NGX_ZIP!" >nul 2>&1
        echo   [OK] Nginx extracted.
    )
)

:: ── Step 6: Check/Install Inno Setup ──
echo.
echo [6/8] Checking Inno Setup...
echo.
set "ISCC="
:: Check common install locations
if exist "C:\Program Files (x86)\Inno Setup 6\ISCC.exe" (
    set "ISCC=C:\Program Files (x86)\Inno Setup 6\ISCC.exe"
)
if exist "C:\Program Files\Inno Setup 6\ISCC.exe" (
    set "ISCC=C:\Program Files\Inno Setup 6\ISCC.exe"
)
:: Check PATH
where iscc >nul 2>&1
if %errorlevel%==0 (
    for /f "tokens=*" %%I in ('where iscc') do set "ISCC=%%I"
)

if defined ISCC (
    echo   [OK] Inno Setup found: !ISCC!
) else (
    echo   Inno Setup not found. Installing...
    set "IS_SETUP=%TEMP%\innosetup-setup.exe"

    powershell -Command ^
        "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; " ^
        "Write-Host '  Downloading Inno Setup...'; " ^
        "Invoke-WebRequest -Uri '%INNO_SETUP_URL%' -OutFile '!IS_SETUP!' -UseBasicParsing; " ^
        "Write-Host '  Download complete.'"

    if exist "!IS_SETUP!" (
        echo   Installing Inno Setup (silent)...
        "!IS_SETUP!" /VERYSILENT /NORESTART /SUPPRESSMSGBOXES /SP- /DIR="C:\Inno Setup 6"
        if exist "C:\Program Files (x86)\Inno Setup 6\ISCC.exe" (
            set "ISCC=C:\Program Files (x86)\Inno Setup 6\ISCC.exe"
        )
        if exist "C:\Program Files\Inno Setup 6\ISCC.exe" (
            set "ISCC=C:\Program Files\Inno Setup 6\ISCC.exe"
        )
        del /Q "!IS_SETUP!" >nul 2>&1
        echo   [OK] Inno Setup installed.
    ) else (
        echo   [FAIL] Inno Setup download failed.
        echo   Please install manually from: https://jrsoftware.org/isdl.php
        set /a ERRORS+=1
    )
)

:: ── Step 7: Build the application source ──
echo.
echo [7/8] Preparing application source...
echo.

:: Create app directory for bundling
set "APP_BUILD=%~dp0app_build"
if exist "%APP_BUILD%" rmdir /S /Q "%APP_BUILD%"
mkdir "%APP_BUILD%"

:: Copy PHP source (exclude installer, .git, node_modules, dist)
echo   Copying application files...
xcopy /E /I /Q /Y "%SOURCE_DIR%\css" "%APP_BUILD%\css\" >nul 2>&1
xcopy /E /I /Q /Y "%SOURCE_DIR%\includes" "%APP_BUILD%\includes\" >nul 2>&1
xcopy /E /I /Q /Y "%SOURCE_DIR%\js" "%APP_BUILD%\js\" >nul 2>&1
xcopy /E /I /Q /Y "%SOURCE_DIR%\pages" "%APP_BUILD%\pages\" >nul 2>&1
xcopy /E /I /Q /Y "%SOURCE_DIR%\api" "%APP_BUILD%\api\" >nul 2>&1

:: Copy individual files
for %%F in (index.php login.php logout.php setup.php setup_db.php 404.php 500.php favicon.ico composer.json .env.example) do (
    if exist "%SOURCE_DIR%\%%F" copy /Y "%SOURCE_DIR%\%%F" "%APP_BUILD%\" >nul 2>&1
)

:: Copy schema files for reference
for %%F in (schema.sql seed_sanitary.sql) do (
    if exist "%SOURCE_DIR%\%%F" copy /Y "%SOURCE_DIR%\%%F" "%APP_BUILD%\" >nul 2>&1
)

:: Remove any .git or installer references
if exist "%APP_BUILD%\.git" rmdir /S /Q "%APP_BUILD%\.git" >nul 2>&1
if exist "%APP_BUILD%\installer" rmdir /S /Q "%APP_BUILD%\installer" >nul 2>&1

echo   [OK] Application source prepared.

:: ── Step 8: Compile Installer ──
echo.
echo [8/8] Compiling Windows installer...
echo.

if not defined ISCC (
    echo   [FAIL] Cannot compile: Inno Setup not available.
    echo   Please install Inno Setup and run this script again.
    set /a ERRORS+=1
    goto :done
)

echo   Running Inno Setup compiler...
"!ISCC!" /F "%~dp0saffron-pos.iss" /O"%BUILD_DIR%" /DAPP_VERSION="%APP_VERSION%"
if %errorlevel% neq 0 (
    echo   [FAIL] Compilation failed. Check the output above.
    set /a ERRORS+=1
) else (
    echo   [OK] Compilation successful.
)

:done
echo.
echo ============================================================
if %ERRORS% gtr 0 (
    echo    BUILD COMPLETED WITH %ERRORS% ERROR(S)
    echo    Check the messages above for details.
) else (
    echo    BUILD SUCCESSFUL!
    echo.
    echo    Installer: %BUILD_DIR%\SaffronPOS-%APP_VERSION%-Setup.exe
    if exist "%BUILD_DIR%\SaffronPOS-%APP_VERSION%-Setup.exe" (
        for %%A in ("%BUILD_DIR%\SaffronPOS-%APP_VERSION%-Setup.exe") do (
            set "SIZE=%%~zA"
            set /a "SIZE_MB=!SIZE! / 1048576"
            echo    Size: !SIZE_MB! MB
        )
    )
)
echo ============================================================
echo.

:: Cleanup temp build dir
if exist "%APP_BUILD%" rmdir /S /Q "%APP_BUILD%" >nul 2>&1

pause
