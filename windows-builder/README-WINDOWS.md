# Saffron POS — Windows Desktop Build Guide

## Overview

This guide explains how to build the Windows desktop installer for Saffron POS.

The Windows version wraps the existing PHP application with Electron, bundling PHP and MariaDB runtimes so end users don't need to install anything manually.

## Architecture

```
Electron (Node.js)
    ↓
Local PHP server (127.0.0.1:<port>)
    ↓
Existing Saffron POS PHP application
    ↓
Local MariaDB (portable, user-data directory)
```

## Prerequisites (Windows Machine)

You need a Windows 10/11 machine with:

1. **Node.js 18+ LTS** — https://nodejs.org/
2. **Git for Windows** (optional but recommended) — https://git-scm.com/
3. **PHP 8.3+ Windows (NTS, x64)** — https://windows.php.net/download/
4. **MariaDB 11.x Windows (x64 ZIP)** — https://mariadb.org/download/

## Quick Start (Step by Step)

### Step 1: Copy the windows-builder folder

Copy the entire `windows-builder/` folder to your Windows machine.

### Step 2: Prepare PHP runtime

Download PHP 8.3+ Windows (VS16 x64 Non Thread Safe) ZIP from https://windows.php.net/download/

Place it in `windows-builder/` as `php-8.3-nts-Win32-vs16-x64.zip`

Run: `scripts\setup-php.bat`

This extracts PHP to `resources\php\`.

### Step 3: Prepare MariaDB runtime

Download MariaDB 11.x WinX64 ZIP from https://mariadb.org/download/

Place it in `windows-builder/` as `mariadb-winx64.zip`

Run: `scripts\setup-mariadb.bat`

This extracts MariaDB to `resources\mariadb\`.

### Step 4: Download local fonts

Run: `scripts\setup-fonts.bat`

This downloads IBM Plex fonts to `resources\fonts\` for offline use.

### Step 5: Set up application icon (optional)

Create a `resources\icons\icon.ico` file (16x16 through 256x256).

Run: `scripts\setup-icon.bat` for instructions.

### Step 6: Bundle all resources

Run: `scripts\bundle-all-resources.bat`

This copies the PHP application and fonts into the build resources.

### Step 7: Build the installer

Run: `build-windows.bat`

This installs npm dependencies and builds the NSIS installer.

The installer will be at: `dist\Saffron POS Setup 1.0.0 x64.exe`

## File Structure

```
windows-builder/
├── build-windows.bat           Main build script
├── package.json                Electron + electron-builder config
├── electron/
│   ├── main.js                 Electron main process (PHP/MariaDB lifecycle)
│   ├── preload.js              Secure bridge between Electron and PHP
│   └── loading.html            Startup loading screen
├── scripts/
│   ├── installer.nsh           NSIS installer customization
│   ├── setup-php.bat           PHP runtime extraction
│   ├── setup-mariadb.bat       MariaDB runtime extraction
│   ├── setup-fonts.bat         Font downloading
│   ├── setup-icon.bat          Icon instructions
│   ├── bundle-all-resources.bat Resource bundling
│   └── prepare-resources-linux.sh Linux-side resource prep
├── resources/
│   ├── php/                    Bundled PHP runtime (after setup)
│   ├── mariadb/                Bundled MariaDB runtime (after setup)
│   ├── fonts/                  Local fonts (after setup)
│   ├── icons/                  Application icons
│   │   └── icon.ico            Main application icon
│   └── application/            PHP application files (after bundling)
│       ├── includes/
│       │   ├── config.php
│       │   ├── auth.php
│       │   ├── header.php
│       │   └── windows_compat.php
│       ├── pages/
│       ├── css/
│       ├── js/
│       └── *.sql               Schema files
└── dist/                       Build output
    └── Saffron POS Setup 1.0.0 x64.exe
```

## How It Works

### Startup Sequence

1. Electron launches and shows a loading screen
2. Creates data directory at `%LOCALAPPDATA%\SaffronPOS\`
3. Starts MariaDB with data in `%LOCALAPPDATA%\SaffronPOS\database\`
4. Waits for MariaDB to be ready (checks actual DB connection)
5. Initializes database (creates tables if first run, applies migrations if upgrading)
6. Starts PHP built-in server on 127.0.0.1:<available port>
7. Waits for PHP HTTP server to respond
8. Creates the main application window pointing to `http://127.0.0.1:<port>/`
9. Destroys loading screen, shows main window

### Data Storage

| Data | Location |
|------|----------|
| Database | `%LOCALAPPDATA%\SaffronPOS\database\` |
| Backups | `%LOCALAPPDATA%\SaffronPOS\backups\` |
| Logs | `%LOCALAPPDATA%\SaffronPOS\logs\` |
| Sessions | `%LOCALAPPDATA%\SaffronPOS\sessions\` |
| Window state | `%LOCALAPPDATA%\SaffronPOS\window-state.json` |
| PHP config | `%LOCALAPPDATA%\SaffronPOS\php.ini` |

Application binaries are in the installation directory (user-selected during install).

### First Run

1. Installer runs and installs to chosen directory
2. User launches Saffron POS
3. Application detects no database → initializes MariaDB
4. PHP app detects `app_initialized` is not set → redirects to setup wizard
5. User creates admin account and shop details
6. Application is ready

### Upgrades

1. New installer detects existing installation
2. Asks if user wants to upgrade
3. Installs new binaries to same location
4. Application detects existing database → applies migrations
5. All data is preserved

### Uninstall

1. User goes to Windows Settings → Apps → Saffron POS → Uninstall
2. Installer removes application binaries and shortcuts
3. Asks if user wants to keep business data (default: YES)
4. If user chooses to delete data, shows strong warning + confirmation
5. Business data in `%LOCALAPPDATA%\SaffronPOS\` is preserved unless explicitly deleted

## Troubleshooting

### Build fails with "electron not found"

Run `npm install` manually before `build-windows.bat`.

### PHP doesn't start

Check that `resources\php\php.exe` exists and has the required extensions:
- php_mysqli.dll
- php_mbstring.dll
- php_openssl.dll
- php_zlib.dll
- php_fileinfo.dll

### MariaDB doesn't start

Check that `resources\mariadb\bin\mariadbd.exe` exists.

Check the log at `%LOCALAPPDATA%\SaffronPOS\logs\mariadb.log`.

### Application shows blank page

Check that PHP is responding: open a browser and go to `http://127.0.0.1:<port>/`

Check the PHP error log at `%LOCALAPPDATA%\SaffronPOS\logs\php_errors.log`.

## Linux Development

To prepare resources from Linux (Omarchy):

```bash
cd windows-builder
./scripts/prepare-resources-linux.sh
```

This copies the PHP application and downloads fonts. You still need to place PHP and MariaDB Windows binaries before building.

**Note:** The NSIS installer cannot be built on Linux. You must build on a Windows machine.

## Environment Variables

The desktop app uses these environment variables:

| Variable | Purpose |
|----------|---------|
| `BUKHARI_DESKTOP=1` | Signals PHP that it's running as desktop app |
| `MARIADB_BIN_DIR` | Path to MariaDB bin directory |
| `BUKHARI_DATA_DIR` | Path to user data directory |
