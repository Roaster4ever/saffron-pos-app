# Saffron POS — Desktop App Builder

## How to Build (One Click)

1. Copy the `installer/` folder to your Windows PC
2. Double-click **`build.bat`**
3. Wait — it downloads Node.js, PHP, MariaDB, Nginx automatically
4. Get: `dist/SaffronPOS-2.0.0-Setup.exe`

That's it. The .exe is a **native Windows desktop application**.

## What It Produces

A native desktop app that:
- Opens in its own window (no browser)
- Custom titlebar with minimize/maximize/close
- System tray icon (minimize to tray)
- Starts database + web server automatically
- No browser chrome, no address bar
- Feels like a real desktop application

## What build.bat Downloads

| Component | Version | Purpose |
|-----------|---------|---------|
| Node.js | 20.18.1 | Build toolchain |
| PHP | 8.2.12 | Application runtime |
| MariaDB | 11.4.5 | Database server |
| Nginx | 1.26.2 | Web server (internal) |

**Downloads are cached** — second build is instant.

## Architecture

```
SaffronPOS-Setup.exe (Electron app)
├── main.js          # Main process: starts services, manages window
├── preload.js       # Security bridge
├── titlebar.html    # Custom window chrome
├── php/             # PHP runtime (bundled)
├── mariadb/         # Database server (bundled)
├── nginx/           # Web server (bundled)
└── app/             # Saffron POS PHP application
```

The Electron app starts MariaDB + PHP + Nginx in the background,
opens a frameless window with custom titlebar, and loads the POS app.

## After Install

- Desktop shortcut: "Saffron POS"
- Start Menu: "Saffron POS"
- Double-click to launch — everything starts automatically
- Minimize to system tray
- Right-click tray icon for options

## Requirements

- Windows 10/11 (64-bit)
- Internet connection (first build only)
- ~800MB disk space
