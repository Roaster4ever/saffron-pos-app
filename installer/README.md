# Saffron POS — Windows Installer Builder

## How to Build (One Click)

1. Copy this entire `installer/` folder to your Windows PC
2. Double-click **`build.bat`**
3. Wait — it downloads PHP, MariaDB, Nginx and Inno Setup automatically
4. Get `dist/SaffronPOS-2.0.0-Setup.exe`

That's it. The .exe is the complete installer you can distribute.

## What build.bat Does

```
[1/8] Check environment (Windows, 64-bit)
[2/8] Create directories
[3/8] Download PHP 8.2.12 (if not cached)
[4/8] Download MariaDB 11.4.5 (if not cached)
[5/8] Download Nginx 1.26.2 (if not cached)
[6/8] Install Inno Setup 6.3 (if not present)
[7/8] Package application source files
[8/8] Compile Windows installer → dist/SaffronPOS-2.0.0-Setup.exe
```

**Downloads are cached** — second build is instant.

## What the Installer Does

When someone runs `SaffronPOS-2.0.0-Setup.exe`:

1. **Choose install directory** (default: `C:\Program Files\SaffronPOS`)
2. **Install all files** — PHP, MariaDB, Nginx, application source
3. **Create shortcuts** — Desktop + Start Menu
4. **Initialize database** — creates all 24 tables, seeds admin user
5. **Open setup wizard** — configure shop name, admin password, tax rate

## What Gets Installed

```
C:\Program Files\SaffronPOS\
├── php\           # PHP runtime
├── mariadb\       # MariaDB database server
├── nginx\         # Web server
├── app\           # Saffron POS application
├── logs\          # Server logs
├── nginx\conf\    # Nginx configuration
├── start-saffron.bat   # Desktop launcher
├── start-server.bat    # Start all services
├── stop-server.bat     # Stop all services
└── setup-wizard.bat    # First-time setup
```

## After Install

- **URL:** http://localhost:8080
- **Default login:** admin / admin123
- **Change password** immediately after first login

## Uninstall

Use Windows "Add or Remove Programs" or run the Uninstaller from Start Menu.
Optionally removes the database during uninstall.

## Requirements

- Windows 10/11 (64-bit)
- Internet connection (for first build only)
- ~500MB disk space (runtimes + app)
