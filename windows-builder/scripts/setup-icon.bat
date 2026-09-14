@echo off
setlocal enabledelayedexpansion
title Bukhari POS - Icon Generation
color 0B

echo ============================================================
echo    BUKHARI POS - Application Icon Setup
echo ============================================================
echo.
echo The application icon (icon.ico) must be placed at:
echo   resources\icons\icon.ico
echo.
echo Requirements:
echo   - Windows ICO format
echo   - Contains: 16x16, 32x32, 48x48, 64x64, 128x128, 256x256
echo   - Used for: installer, desktop shortcut, taskbar, window
echo.
echo If you have a PNG icon, convert it:
echo   1. Use https://convertio.co/png-ico/
echo   2. Or use IcoFX (free): https://icofx.ro/
echo   3. Or use ImageMagick: magick convert icon.png -define icon:auto-resize=16,32,48,64,128,256 icon.ico
echo.

set "ICON_DIR=%~dp0resources\icons"
if not exist "%ICON_DIR%" mkdir "%ICON_DIR%"

if exist "%ICON_DIR%\icon.ico" (
    echo [OK] Icon found: %ICON_DIR%\icon.ico
    for %%A in ("%ICON_DIR%\icon.ico") do (
        set "SIZE=%%~zA"
        echo    Size: !SIZE! bytes
    )
) else (
    echo [WARN] No icon.ico found.
    echo.
    echo To create a temporary placeholder icon:
    echo.
    echo Option A: Use an online converter (recommended)
    echo   1. Create or find a 256x256 PNG image
    echo   2. Convert to ICO at https://convertio.co/png-ico/
    echo   3. Save as: resources\icons\icon.ico
    echo.
    echo Option B: Use ImageMagick (if installed)
    echo   magick convert -size 256x256 xc:"#e8ff3a" -gravity center -pointsize 120 -fill "#0f0f0f" -annotate 0 "B" icon.png
    echo   magick convert icon.png -define icon:auto-resize=16,32,48,64,128,256 "%ICON_DIR%\icon.ico"
    echo   del icon.png
    echo.
    echo The build will use a default Electron icon if no custom icon is provided.
)

echo.
pause
