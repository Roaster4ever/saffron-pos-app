@echo off
setlocal enabledelayedexpansion
title Saffron POS - Desktop App Builder
color 0B
mode con: cols=80 lines=50

echo ============================================================
echo    SAFFRON POS - Desktop Application Builder
echo    Produces: SaffronPOS-Setup.exe (native desktop app)
echo ============================================================
echo.

:: ── Configuration ──
set "APP_NAME=Saffron POS"
set "APP_VERSION=2.0.0"
set "ROOT_DIR=%~dp0.."
set "DESKTOP_DIR=%~dp0"
set "BUILD_DIR=%~dp0dist"
set "PHP_VERSION=8.2.12"
set "MARIADB_VERSION=11.4.5"
set "NGINX_VERSION=1.26.2"
set "NODE_VERSION=20.18.1"
set "ERRORS=0"

:: ── Step 1: Check Environment ──
echo [1/9] Checking environment...
echo.
if "%OS%" neq "Windows_NT" (
    echo   [FAIL] This script must run on Windows.
    pause & exit /b 1
)
echo   [OK] Windows detected.
if "%PROCESSOR_ARCHITECTURE%" neq "AMD64" (
    echo   [WARN] 64-bit recommended.
) else (
    echo   [OK] 64-bit architecture.
)

:: ── Step 2: Download Node.js ──
echo.
echo [2/9] Checking Node.js...
echo.
set "NODE_DIR=%~dp0node_runtime"
set "NODE_EXE=%NODE_DIR%\node.exe"
set "NPM_CMD=%NODE_DIR%\npm.cmd"

where node >nul 2>&1
if %errorlevel%==0 (
    echo   [OK] Node.js found in PATH.
    set "NODE_EXE="
    set "NPM_CMD="
) else (
    if exist "%NODE_EXE%" (
        echo   [OK] Node.js already downloaded.
    ) else (
        echo   Downloading Node.js %NODE_VERSION%...
        set "NODE_ZIP=%TEMP%\node-v%NODE_VERSION%-win-x64.zip"
        set "NODE_URL=https://nodejs.org/dist/v%NODE_VERSION%/node-v%NODE_VERSION%-win-x64.zip"

        powershell -Command ^
            "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; " ^
            "Write-Host '  Downloading from nodejs.org...'; " ^
            "Invoke-WebRequest -Uri '!NODE_URL!' -OutFile '!NODE_ZIP!' -UseBasicParsing; " ^
            "Write-Host '  Done.'"

        if not exist "!NODE_ZIP!" (
            echo   [FAIL] Node.js download failed.
            set /a ERRORS+=1
        ) else (
            echo   Extracting...
            powershell -Command "Expand-Archive -Path '!NODE_ZIP!' -DestinationPath '%NODE_DIR%\tmp' -Force"
            for /d %%D in ("%NODE_DIR%\tmp\node-*") do (
                xcopy /E /I /Q /Y "%%D\*" "%NODE_DIR%\" >nul 2>&1
            )
            rmdir /S /Q "%NODE_DIR%\tmp" >nul 2>&1
            del /Q "!NODE_ZIP!" >nul 2>&1
            echo   [OK] Node.js extracted.
        )
    )
)

:: Set PATH for node/npm
if defined NODE_EXE (
    set "PATH=%NODE_DIR%;!PATH!"
)

:: ── Step 3: Download PHP ──
echo.
echo [3/9] Downloading PHP %PHP_VERSION%...
echo.
set "PHP_DIR=%DESKTOP_DIR%\php"
if exist "%PHP_DIR%\php.exe" (
    echo   [OK] PHP already downloaded.
) else (
    set "PHP_ZIP=%TEMP%\php-%PHP_VERSION%-Win32-vs16-x64.zip"
    powershell -Command ^
        "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; " ^
        "Write-Host '  Downloading PHP...'; " ^
        "Invoke-WebRequest -Uri 'https://windows.php.net/downloads/releases/php-%PHP_VERSION%-Win32-vs16-x64.zip' -OutFile '!PHP_ZIP!' -UseBasicParsing"
    if not exist "!PHP_ZIP!" (
        echo   [FAIL] PHP download failed. Get it from https://windows.php.net/download/
        set /a ERRORS+=1
    ) else (
        powershell -Command "Expand-Archive -Path '!PHP_ZIP!' -DestinationPath '%DESKTOP_DIR%\php_tmp' -Force"
        for /d %%D in ("%DESKTOP_DIR%\php_tmp\php-*") do xcopy /E /I /Q /Y "%%D\*" "%PHP_DIR%\" >nul 2>&1
        rmdir /S /Q "%DESKTOP_DIR%\php_tmp" >nul 2>&1
        del /Q "!PHP_ZIP!" >nul 2>&1
        echo   [OK] PHP extracted.
    )
)

:: ── Step 4: Download MariaDB ──
echo.
echo [4/9] Downloading MariaDB %MARIADB_VERSION%...
echo.
set "MDB_DIR=%DESKTOP_DIR%\mariadb"
if exist "%MDB_DIR%\bin\mariadbd.exe" (
    echo   [OK] MariaDB already downloaded.
) else (
    set "MDB_ZIP=%TEMP%\mariadb-%MARIADB_VERSION%-winx64.zip"
    powershell -Command ^
        "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; " ^
        "Write-Host '  Downloading MariaDB...'; " ^
        "Invoke-WebRequest -Uri 'https://archive.mariadb.org/mariadb-%MARIADB_VERSION%/winx64-packages/mariadb-%MARIADB_VERSION%-winx64.zip' -OutFile '!MDB_ZIP!' -UseBasicParsing"
    if not exist "!MDB_ZIP!" (
        echo   [FAIL] MariaDB download failed.
        set /a ERRORS+=1
    ) else (
        powershell -Command "Expand-Archive -Path '!MDB_ZIP!' -DestinationPath '%DESKTOP_DIR%\mdb_tmp' -Force"
        for /d %%D in ("%DESKTOP_DIR%\mdb_tmp\mariadb-*") do xcopy /E /I /Q /Y "%%D\*" "%MDB_DIR%\" >nul 2>&1
        rmdir /S /Q "%DESKTOP_DIR%\mdb_tmp" >nul 2>&1
        del /Q "!MDB_ZIP!" >nul 2>&1
        echo   [OK] MariaDB extracted.
    )
)

:: ── Step 5: Download Nginx ──
echo.
echo [5/9] Downloading Nginx %NGINX_VERSION%...
echo.
set "NGX_DIR=%DESKTOP_DIR%\nginx"
if exist "%NGX_DIR%\nginx.exe" (
    echo   [OK] Nginx already downloaded.
) else (
    set "NGX_ZIP=%TEMP%\nginx-%NGINX_VERSION%.zip"
    powershell -Command ^
        "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; " ^
        "Write-Host '  Downloading Nginx...'; " ^
        "Invoke-WebRequest -Uri 'https://nginx.org/download/nginx-%NGINX_VERSION%.zip' -OutFile '!NGX_ZIP!' -UseBasicParsing"
    if not exist "!NGX_ZIP!" (
        echo   [FAIL] Nginx download failed.
        set /a ERRORS+=1
    ) else (
        powershell -Command "Expand-Archive -Path '!NGX_ZIP!' -DestinationPath '%DESKTOP_DIR%\ngx_tmp' -Force"
        for /d %%D in ("%DESKTOP_DIR%\ngx_tmp\nginx-*") do xcopy /E /I /Q /Y "%%D\*" "%NGX_DIR%\" >nul 2>&1
        rmdir /S /Q "%DESKTOP_DIR%\ngx_tmp" >nul 2>&1
        del /Q "!NGX_ZIP!" >nul 2>&1
        echo   [OK] Nginx extracted.
    )
)

:: ── Step 6: Copy Application Source ──
echo.
echo [6/9] Packaging application...
echo.
set "APP_DIR=%DESKTOP_DIR%\app"
if exist "%APP_DIR%" rmdir /S /Q "%APP_DIR%"
mkdir "%APP_DIR%"

echo   Copying PHP source...
xcopy /E /I /Q /Y "%ROOT_DIR%\css" "%APP_DIR%\css\" >nul 2>&1
xcopy /E /I /Q /Y "%ROOT_DIR%\includes" "%APP_DIR%\includes\" >nul 2>&1
xcopy /E /I /Q /Y "%ROOT_DIR%\js" "%APP_DIR%\js\" >nul 2>&1
xcopy /E /I /Q /Y "%ROOT_DIR%\pages" "%APP_DIR%\pages\" >nul 2>&1
xcopy /E /I /Q /Y "%ROOT_DIR%\api" "%APP_DIR%\api\" >nul 2>&1

for %%F in (index.php login.php logout.php setup.php setup_db.php 404.php 500.php favicon.ico composer.json .env.example) do (
    if exist "%ROOT_DIR%\%%F" copy /Y "%ROOT_DIR%\%%F" "%APP_DIR%\" >nul 2>&1
)
for %%F in (schema.sql seed_sanitary.sql) do (
    if exist "%ROOT_DIR%\%%F" copy /Y "%ROOT_DIR%\%%F" "%APP_DIR%\" >nul 2>&1
)

echo   [OK] Application packaged.

:: ── Step 7: Install npm dependencies ──
echo.
echo [7/9] Installing Electron dependencies...
echo.
if defined NPM_CMD (
    call "!NPM_CMD!" install --production
) else (
    call npm install --production
)
if %errorlevel% neq 0 (
    echo   [FAIL] npm install failed.
    set /a ERRORS+=1
) else (
    echo   [OK] Dependencies installed.
)

:: ── Step 8: Build Desktop App ──
echo.
echo [8/9] Building desktop application...
echo.
if defined NPM_CMD (
    call "!NPM_CMD!" run build
) else (
    call npm run build
)
if %errorlevel% neq 0 (
    echo   [FAIL] Build failed.
    set /a ERRORS+=1
) else (
    echo   [OK] Build complete.
)

:: ── Step 9: Verify Output ──
echo.
echo [9/9] Verifying output...
echo.
if exist "%BUILD_DIR%\SaffronPOS-%APP_VERSION%-Setup.exe" (
    for %%A in ("%BUILD_DIR%\SaffronPOS-%APP_VERSION%-Setup.exe") do (
        set "SIZE=%%~zA"
        set /a "SIZE_MB=!SIZE! / 1048576"
        echo   [OK] Installer found: !SIZE_MB! MB
    )
) else (
    echo   [WARN] Installer not at expected path. Checking dist...
    dir "%BUILD_DIR%\*.exe" /b 2>nul
)

:done
echo.
echo ============================================================
if %ERRORS% gtr 0 (
    echo    BUILD COMPLETED WITH %ERRORS% ERROR(S)
) else (
    echo    BUILD SUCCESSFUL!
    echo.
    echo    Desktop App: %BUILD_DIR%\SaffronPOS-%APP_VERSION%-Setup.exe
    echo.
    echo    This is a native Windows desktop application.
    echo    No browser needed. Just install and run.
)
echo ============================================================
echo.
pause
