@echo off
setlocal enabledelayedexpansion
title Bukhari POS - Font Bundling
color 0B

echo ============================================================
echo    BUKHARI POS - Local Font Bundling
echo ============================================================
echo.
echo This script downloads IBM Plex fonts for offline use.
echo It removes the Google Fonts CDN dependency.
echo.

set "FONTS_DIR=%~dp0resources\fonts"

if not exist "%FONTS_DIR%" mkdir "%FONTS_DIR%"

:: Download IBM Plex Sans (woff2)
echo Downloading IBM Plex Sans...
powershell -Command "try { Invoke-WebRequest -Uri 'https://fonts.gstatic.com/s/ibmplexsans/v19/zYXgKVElMYYaJe8bpLHnCwDKhdHeFaxOedc.woff2' -OutFile '%FONTS_DIR%\IBMPlexSans-Regular.woff2' -ErrorAction Stop; Write-Host '[OK] Regular' } catch { Write-Host '[FAIL] Regular' }"
powershell -Command "try { Invoke-WebRequest -Uri 'https://fonts.gstatic.com/s/ibmplexsans/v19/zYX9KVElMYYaJe8bpLHnCwDKjSL9AIFsdP3pBms.woff2' -OutFile '%FONTS_DIR%\IBMPlexSans-Medium.woff2' -ErrorAction Stop; Write-Host '[OK] Medium' } catch { Write-Host '[FAIL] Medium' }"
powershell -Command "try { Invoke-WebRequest -Uri 'https://fonts.gstatic.com/s/ibmplexsans/v19/zYX9KVElMYYaJe8bpLHnCwDKjWr8AIFsdP3pBms.woff2' -OutFile '%FONTS_DIR%\IBMPlexSans-SemiBold.woff2' -ErrorAction Stop; Write-Host '[OK] SemiBold' } catch { Write-Host '[FAIL] SemiBold' }"
powershell -Command "try { Invoke-WebRequest -Uri 'https://fonts.gstatic.com/s/ibmplexsans/v19/zYX9KVElMYYaJe8bpLHnCwDKjQ76AIFsdP3pBms.woff2' -OutFile '%FONTS_DIR%\IBMPlexSans-Bold.woff2' -ErrorAction Stop; Write-Host '[OK] Bold' } catch { Write-Host '[FAIL] Bold' }"

:: Download IBM Plex Mono (woff2)
echo Downloading IBM Plex Mono...
powershell -Command "try { Invoke-WebRequest -Uri 'https://fonts.gstatic.com/s/ibmplexmono/v19/-F63fjptAgt5VM-kVkqdyU8n5iQ.woff2' -OutFile '%FONTS_DIR%\IBMPlexMono-Regular.woff2' -ErrorAction Stop; Write-Host '[OK] Mono Regular' } catch { Write-Host '[FAIL] Mono Regular' }"
powershell -Command "try { Invoke-WebRequest -Uri 'https://fonts.gstatic.com/s/ibmplexmono/v19/-F6qfjptAgt5VM-kVkqdyU8n3oQIwl1FgsAXHNlYzg.woff2' -OutFile '%FONTS_DIR%\IBMPlexMono-SemiBold.woff2' -ErrorAction Stop; Write-Host '[OK] Mono SemiBold' } catch { Write-Host '[FAIL] Mono SemiBold' }"

echo.
echo ============================================================
echo    Font files downloaded to: %FONTS_DIR%
echo ============================================================
echo.

:: List downloaded files
dir "%FONTS_DIR%\*.woff2" /b 2>nul

echo.
echo Next: Run bundle-all-resources.bat to include fonts in the build.
echo.
pause
