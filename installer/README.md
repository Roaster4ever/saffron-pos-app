# Saffron POS — Windows Installer

Professional Windows installer for Saffron POS. Bundles PHP, MariaDB, and Nginx as a self-contained desktop application.

## Features

- **One-click install** — installs everything needed to run Saffron POS
- **Custom directory** — choose where to install
- **Setup wizard** — web-based configuration on first run
- **Desktop shortcut** — launch Saffron POS from desktop
- **Start Menu** — full Start Menu with Start/Stop/Setup/Uninstall
- **Complete uninstall** — removes all files, services, and optionally the database
- **Portable stack** — PHP + MariaDB + Nginx bundled (no system dependencies)

## Requirements (for building)

- **Windows 10/11** (64-bit)
- **Inno Setup 6.3+** — [Download](https://jrsoftware.org/isinfo.php)
- **Runtime files** — PHP, MariaDB, Nginx (see below)

## Building the Installer

### Step 1: Download Runtimes

Download these and extract to `installer\runtime\`:

#### PHP (8.2+ TS x64)
1. Download from https://windows.php.net/download/
2. Get the **"VS16 x64 Thread Safe"** ZIP
3. Extract to `installer\runtime\php\`
4. Verify: `installer\runtime\php\php.exe` exists

#### MariaDB (11.x x64)
1. Download from https://mariadb.org/download/
2. Get the **ZIP or MSI** package
3. Extract to `installer\runtime\mariadb\`
4. Verify: `installer\runtime\mariadb\bin\mariadbd.exe` exists

#### Nginx (latest stable)
1. Download from https://nginx.org/en/download.html
2. Get the **Windows** ZIP
3. Extract to `installer\runtime\nginx\`
4. Verify: `installer\runtime\nginx\nginx.exe` exists

### Step 2: Create Resources

Create these files in `installer\resources\`:

- `icon.ico` — Application icon (256x256)
- `wizard-image.bmp` — Installer sidebar image (164x314)
- `wizard-small.bmp` — Installer header image (55x58)
- `saffron.exe` — Launch wrapper (can be a simple batch-to-exe or Electron shell)

### Step 3: Build

1. Open `saffron-pos.iss` in Inno Setup
2. Click **Build > Compile**
3. Output: `dist\SaffronPOS-2.0.0-Setup.exe`

## Directory Structure

```
installer/
├── saffron-pos.iss          # Inno Setup script (main build file)
├── scripts/
│   ├── start.bat            # Start all services
│   ├── stop.bat             # Stop all services
│   ├── restart.bat          # Restart services
│   ├── setup-wizard.bat     # Run first-time setup
│   ├── setup-db.php         # Database initialization (CLI)
│   └── uninstall.bat        # Manual uninstall helper
├── resources/
│   ├── nginx.conf.template  # Nginx configuration
│   ├── icon.ico             # Application icon
│   ├── wizard-image.bmp     # Installer image
│   ├── wizard-small.bmp     # Installer header image
│   └── saffron.exe          # Launcher executable
└── runtime/                 # (Download before building)
    ├── php/                 # PHP runtime
    ├── mariadb/             # MariaDB runtime
    └── nginx/               # Nginx runtime
```

## What the Installer Does

1. **Installs files** to the chosen directory (default: `C:\Program Files\SaffronPOS`)
2. **Creates shortcuts** on Desktop and Start Menu
3. **Initializes database** on first install (creates `saffron_pos` database + all tables)
4. **Seeds defaults** — admin user, units, expense categories, settings
5. **Opens setup wizard** in browser for shop configuration

## What the Uninstaller Does

1. **Stops all services** (Nginx, MariaDB, PHP)
2. **Removes all installed files**
3. **Optionally drops the database**
4. **Removes shortcuts** from Desktop and Start Menu
5. **Removes registry entries**

## First-Time Setup

After installation, the setup wizard opens automatically:

1. **Configure Shop** — name, address, phone, email, currency
2. **Set Admin Password** — change from default `admin123`
3. **Configure Tax** — set default GST rate
4. **Start Selling** — redirect to POS page

## Manual Service Management

From Start Menu or command line:

```batch
# Start all services
scripts\start.bat

# Stop all services
scripts\stop.bat

# Restart
scripts\restart.bat

# Access POS
http://localhost:8080
```

## Default Credentials

- **URL:** http://localhost:8080
- **Username:** admin
- **Password:** admin123

⚠️ **Change the admin password immediately after first login!**

## Troubleshooting

### Port 8080 already in use
Edit `config\nginx.conf` and change `listen 8080` to another port (e.g., `8090`).

### MariaDB won't start
Delete `mariadb\data` folder and run `scripts\setup-wizard.bat` to reinitialize.

### PHP errors
Ensure `php\php.ini` has:
```
extension=mysqli
extension=pdo_mysql
extension=json
extension=mbstring
```
