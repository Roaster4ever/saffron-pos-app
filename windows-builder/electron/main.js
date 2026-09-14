// Saffron POS — Electron Main Process
// Manages MariaDB, PHP, and the application window.

const { app, BrowserWindow, dialog, shell, ipcMain } = require('electron');
const path = require('path');
const fs = require('fs');
const { spawn, execSync } = require('child_process');
const net = require('net');
const http = require('http');

// ── App identity ──
const APP_NAME = 'Saffron POS';
const APP_VERSION = '1.0.0';
const DATA_DIR_NAME = 'SaffronPOS';
const DB_NAME = 'pos_db';
const DB_USER = 'bukhari_app';
const DB_PASS = 'Bukh@r1P0s_2026!xK9';

// ── State ──
let mainWindow = null;
let mariadbProcess = null;
let phpProcess = null;
let actualPort = 0;
let isQuitting = false;

// ── Paths ──
const isDev = !app.isPackaged;

function getInstallDir() {
  if (isDev) return path.join(__dirname, '..');
  return path.dirname(process.execPath);
}

function getDataDir() {
  // User data goes in %LOCALAPPDATA%\SaffronPOS
  // Portable mode: same directory as executable
  const portableMarker = path.join(getInstallDir(), 'portable.txt');
  if (fs.existsSync(portableMarker)) {
    return path.join(getInstallDir(), 'data');
  }
  return path.join(app.getPath('userData'), 'data');
}

function getPhpDir() {
  if (isDev) return path.join(__dirname, '..', 'resources', 'php');
  return path.join(process.resourcesPath, 'php');
}

function getMariaDbDir() {
  if (isDev) return path.join(__dirname, '..', 'resources', 'mariadb');
  return path.join(process.resourcesPath, 'mariadb');
}

function getAppDir() {
  if (isDev) return path.join(__dirname, '..', 'resources', 'application');
  return path.join(process.resourcesPath, 'application');
}

function getLogDir() {
  const dataDir = getDataDir();
  const logDir = path.join(dataDir, 'logs');
  if (!fs.existsSync(logDir)) fs.mkdirSync(logDir, { recursive: true });
  return logDir;
}

function log(level, message, detail = null) {
  const timestamp = new Date().toISOString();
  const logFile = path.join(getLogDir(), 'app.log');
  const line = `[${timestamp}] [${level}] ${message}${detail ? '\n  ' + detail : ''}\n`;
  try { fs.appendFileSync(logFile, line); } catch {}
  if (level === 'ERROR') console.error(line.trim());
}

// ── Port management ──
function findAvailablePort(preferred = 8080) {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.listen(preferred, '127.0.0.1', () => {
      const port = server.address().port;
      server.close(() => resolve(port));
    });
    server.on('error', () => {
      // Try next port
      if (preferred < 65535) {
        findAvailablePort(preferred + 1).then(resolve).catch(reject);
      } else {
        reject(new Error('No available port found'));
      }
    });
  });
}

function waitForPort(port, timeoutMs = 30000) {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    function tryConnect() {
      const sock = net.createConnection(port, '127.0.0.1');
      sock.on('connect', () => { sock.destroy(); resolve(); });
      sock.on('error', () => {
        sock.destroy();
        if (Date.now() - start > timeoutMs) {
          reject(new Error(`Port ${port} not ready after ${timeoutMs}ms`));
        } else {
          setTimeout(tryConnect, 500);
        }
      });
    }
    tryConnect();
  });
}

function waitForHttp(url, timeoutMs = 30000) {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    function tryRequest() {
      http.get(url, (res) => {
        res.resume();
        resolve(res.statusCode);
      }).on('error', () => {
        if (Date.now() - start > timeoutMs) {
          reject(new Error(`HTTP ${url} not ready after ${timeoutMs}ms`));
        } else {
          setTimeout(tryRequest, 500);
        }
      });
    }
    tryRequest();
  });
}

// ── MariaDB ──
function startMariaDB() {
  return new Promise((resolve, reject) => {
    const dataDir = getDataDir();
    const mariadbDir = getMariaDbDir();
    const dbDataDir = path.join(dataDir, 'database');

    // Ensure data directory exists
    if (!fs.existsSync(dbDataDir)) fs.mkdirSync(dbDataDir, { recursive: true });

    const mariadbd = path.join(mariadbDir, 'bin', 'mariadbd.exe');
    const mariadbInstall = path.join(mariadbDir, 'bin', 'mariadb-install-db.exe');

    // Check if data directory needs initialization
    const needInit = !fs.existsSync(path.join(dbDataDir, 'ibdata1'));

    if (needInit) {
      log('INFO', 'Initializing MariaDB data directory...');
      try {
        // Initialize system tables
        const initArgs = [
          '--datadir=' + dbDataDir,
          '--service=' + APP_NAME,
          '--password='
        ];
        execSync(`"${mariadbInstall}" ${initArgs.join(' ')}`, {
          timeout: 60000,
          stdio: 'pipe'
        });
        log('INFO', 'MariaDB data directory initialized.');
      } catch (err) {
        log('ERROR', 'MariaDB initialization failed', err.message);
        // Try alternative: mysqld --initialize-insecure
        try {
          execSync(`"${mariadbd}" --initialize-insecure --datadir="${dbDataDir}"`, {
            timeout: 60000,
            stdio: 'pipe'
          });
          log('INFO', 'MariaDB initialized with --initialize-insecure.');
        } catch (err2) {
          reject(new Error(`Failed to initialize MariaDB: ${err2.message}`));
          return;
        }
      }
    }

    // Start MariaDB
    const args = [
      '--datadir=' + dbDataDir,
      '--port=0',               // Let OS assign port (we don't need external access)
      '--bind-address=127.0.0.1',
      '--skip-networking=0',
      '--innodb-buffer-pool-size=64M',
      '--max-allowed-packet=16M',
      '--character-set-server=utf8mb4',
      '--collation-server=utf8mb4_unicode_ci',
      '--slow-query-log=0',
      '--log-error=' + path.join(dataDir, 'logs', 'mariadb.log'),
      '--pid-file=' + path.join(dataDir, 'mariadb.pid'),
      '--socket=' + path.join(dataDir, 'mariadb.sock')
    ];

    log('INFO', 'Starting MariaDB...');
    mariadbProcess = spawn(mariadbd, args, {
      stdio: ['ignore', 'pipe', 'pipe'],
      windowsHide: true
    });

    mariadbProcess.stdout.on('data', (data) => {
      const msg = data.toString();
      if (msg.includes('ready for connections')) {
        log('INFO', 'MariaDB ready for connections.');
        resolve();
      }
    });

    mariadbProcess.stderr.on('data', (data) => {
      log('DEBUG', 'MariaDB stderr: ' + data.toString().trim());
    });

    mariadbProcess.on('error', (err) => {
      log('ERROR', 'MariaDB process error', err.message);
      reject(new Error(`Failed to start MariaDB: ${err.message}`));
    });

    mariadbProcess.on('exit', (code, signal) => {
      if (!isQuitting) {
        log('ERROR', `MariaDB exited unexpectedly (code=${code}, signal=${signal})`);
      }
      mariadbProcess = null;
    });

    // Timeout if MariaDB doesn't signal ready within 30 seconds
    setTimeout(() => {
      if (mariadbProcess) {
        log('ERROR', 'MariaDB startup timeout');
        reject(new Error('MariaDB did not start within 30 seconds'));
      }
    }, 30000);
  });
}

// ── Wait for MariaDB socket readiness ──
function waitForMariaDB(timeoutMs = 30000) {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    const mariadbDir = getMariaDbDir();
    const mariadbCli = path.join(mariadbDir, 'bin', 'mariadb.exe');

    function tryConnect() {
      try {
        execSync(`"${mariadbCli}" -u root -S "${path.join(getDataDir(), 'mariadb.sock')}" -e "SELECT 1"`, {
          timeout: 5000,
          stdio: 'pipe'
        });
        resolve();
      } catch {
        if (Date.now() - start > timeoutMs) {
          reject(new Error('MariaDB not ready after timeout'));
        } else {
          setTimeout(tryConnect, 1000);
        }
      }
    }
    tryConnect();
  });
}

// ── Database initialization ──
function initDatabase() {
  const mariadbDir = getMariaDbDir();
  const mariadbCli = path.join(mariadbDir, 'bin', 'mariadb.exe');
  const socket = path.join(getDataDir(), 'mariadb.sock');

  log('INFO', 'Initializing database...');

  try {
    // Create database if not exists
    execSync(`"${mariadbCli}" -u root -S "${socket}" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"`, {
      timeout: 10000, stdio: 'pipe'
    });

    // Create application user
    execSync(`"${mariadbCli}" -u root -S "${socket}" -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}'; GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES"`, {
      timeout: 10000, stdio: 'pipe'
    });

    // Check if tables exist
    const result = execSync(`"${mariadbCli}" -u root -S "${socket}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'"`, {
      timeout: 10000, encoding: 'utf8'
    }).trim();

    if (parseInt(result) === 0) {
      log('INFO', 'No tables found. Applying schema...');
      applySchema(socket, mariadbCli);
    } else {
      log('INFO', `Database has ${result} tables. Checking for migrations...`);
      applyMigrations(socket, mariadbCli);
    }

    // Set app_initialized if not already set
    try {
      const initCheck = execSync(`"${mariadbCli}" -u root -S "${socket}" -N -e "SELECT setting_value FROM \`${DB_NAME}\`.settings WHERE setting_key='app_initialized'"`, {
        timeout: 5000, encoding: 'utf8'
      }).trim();
      if (!initCheck || initCheck === '0' || initCheck === '') {
        log('INFO', 'App not initialized yet. Setup wizard will run on first launch.');
      }
    } catch {}

    log('INFO', 'Database initialization complete.');
  } catch (err) {
    log('ERROR', 'Database initialization failed', err.message);
    throw err;
  }
}

function applySchema(socket, mariadbCli) {
  const appDir = getAppDir();
  const schemaFiles = [
    'database.sql',
    'migration_phase1.sql',
    'migrate.sql',
    'seed_sanitary.sql'
  ];

  for (const file of schemaFiles) {
    const filePath = path.join(appDir, file);
    if (fs.existsSync(filePath)) {
      log('INFO', `Applying ${file}...`);
      try {
        execSync(`"${mariadbCli}" -u root -S "${socket}" "${DB_NAME}" < "${filePath}"`, {
          timeout: 120000,
          stdio: 'pipe'
        });
        log('INFO', `${file} applied successfully.`);
      } catch (err) {
        log('ERROR', `Failed to apply ${file}`, err.message);
        // Continue — some files may be optional
      }
    }
  }
}

function applyMigrations(socket, mariadbCli) {
  const migrationDir = path.join(getAppDir(), 'migrations');
  if (!fs.existsSync(migrationDir)) return;

  // Get current migration version
  let currentVersion = 0;
  try {
    const v = execSync(`"${mariadbCli}" -u root -S "${socket}" -N -e "SELECT setting_value FROM \`${DB_NAME}\`.settings WHERE setting_key='schema_version'"`, {
      timeout: 5000, encoding: 'utf8'
    }).trim();
    currentVersion = parseInt(v) || 0;
  } catch {}

  // Apply pending migrations
  const migrations = fs.readdirSync(migrationDir)
    .filter(f => f.endsWith('.sql'))
    .sort();

  for (const migration of migrations) {
    const version = parseInt(migration.split('_')[0]) || 0;
    if (version > currentVersion) {
      log('INFO', `Applying migration: ${migration}`);
      try {
        // Create safety backup
        const backupDir = path.join(getDataDir(), 'backups');
        if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir, { recursive: true });
        const safetyBackup = path.join(backupDir, `pre-migration-${Date.now()}.sql`);

        const mariadbDump = path.join(getMariaDbDir(), 'bin', 'mariadb-dump.exe');
        execSync(`"${mariadbDump}" -u root -S "${socket}" "${DB_NAME}" > "${safetyBackup}"`, {
          timeout: 60000, stdio: 'pipe'
        });

        // Apply migration
        const migrationPath = path.join(migrationDir, migration);
        execSync(`"${mariadbCli}" -u root -S "${socket}" "${DB_NAME}" < "${migrationPath}"`, {
          timeout: 120000, stdio: 'pipe'
        });

        // Update version
        execSync(`"${mariadbCli}" -u root -S "${socket}" -e "INSERT INTO \`${DB_NAME}\`.settings (setting_key, setting_value, setting_type) VALUES ('schema_version', '${version}', 'integer') ON DUPLICATE KEY UPDATE setting_value='${version}'"`, {
          timeout: 5000, stdio: 'pipe'
        });

        log('INFO', `Migration ${migration} applied.`);
      } catch (err) {
        log('ERROR', `Migration ${migration} failed`, err.message);
        dialog.showErrorBox('Migration Failed', `Saffron POS could not complete a database migration.\n\nMigration: ${migration}\nError: ${err.message}\n\nA safety backup was created before the migration attempt.`);
        app.quit();
        return;
      }
    }
  }
}

// ── PHP Server ──
function startPHP() {
  return new Promise((resolve, reject) => {
    const phpDir = getPhpDir();
    const appDir = getAppDir();
    const phpExe = path.join(phpDir, 'php.exe');
    const dataDir = getDataDir();

    // Find available port
    findAvailablePort(8080).then(port => {
      actualPort = port;
      log('INFO', `Starting PHP on port ${port}...`);

      // Create a custom php.ini for the desktop app
      const phpIni = path.join(dataDir, 'php.ini');
      createPhpIni(phpIni, port);

      const args = [
        '-S', `127.0.0.1:${port}`,
        '-t', appDir,
        '-c', phpIni,
        '-d', 'display_errors=Off',
        '-d', 'log_errors=On',
        '-d', 'error_log=' + path.join(dataDir, 'logs', 'php_errors.log'),
        '-d', 'expose_php=Off'
      ];

      phpProcess = spawn(phpExe, args, {
        stdio: ['ignore', 'pipe', 'pipe'],
        windowsHide: true
      });

      phpProcess.stdout.on('data', (data) => {
        log('DEBUG', 'PHP: ' + data.toString().trim());
      });

      phpProcess.stderr.on('data', (data) => {
        const msg = data.toString();
        log('DEBUG', 'PHP stderr: ' + msg.trim());
        // PHP built-in server outputs "Listening on http://..." to stderr
        if (msg.includes('Listening on')) {
          log('INFO', 'PHP server listening.');
          // Wait a moment for server to be fully ready
          setTimeout(() => resolve(port), 500);
        }
      });

      phpProcess.on('error', (err) => {
        log('ERROR', 'PHP process error', err.message);
        reject(new Error(`Failed to start PHP: ${err.message}`));
      });

      phpProcess.on('exit', (code) => {
        if (!isQuitting) {
          log('ERROR', `PHP exited unexpectedly (code=${code})`);
        }
        phpProcess = null;
      });

      // Timeout
      setTimeout(() => {
        if (phpProcess) {
          log('INFO', 'PHP timeout (no output), assuming ready...');
          resolve(port);
        }
      }, 5000);
    }).catch(reject);
  });
}

function createPhpIni(iniPath, port) {
  const phpDir = getPhpDir();
  const dataDir = getDataDir();

  const content = `
; Saffron POS — PHP Configuration (auto-generated)
; Do not edit manually.

engine = On
short_open_tag = Off
precision = 14
output_buffering = 4096
zlib.output_compression = Off
serialize_precision = -1

; Error handling
error_reporting = E_ALL
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = ${path.join(dataDir, 'logs', 'php_errors.log').replace(/\\/g, '/')}
ignore_repeated_errors = Off
ignore_repeated_source = Off
report_memleaks = On

; Resource limits
max_execution_time = 60
max_input_time = 60
memory_limit = 128M

; File uploads
file_uploads = On
upload_max_filesize = 10M
post_max_size = 20M

; Security
expose_php = Off
allow_url_fopen = On
allow_url_include = Off

; Session
session.save_handler = files
session.save_path = "${path.join(dataDir, 'sessions').replace(/\\/g, '/')}"
session.use_strict_mode = 1
session.use_only_cookies = 1
session.use_cookies = 1
session.name = PHPSESSID
session.cookie_httponly = 1
session.cookie_samesite = Lax
session.gc_maxlifetime = 28800
session.cookie_lifetime = 0

; Extensions
extension_dir = "${path.join(phpDir, 'ext').replace(/\\/g, '/')}"
extension=mysqli
extension=mbstring
extension=openssl
extension=zlib
extension=fileinfo
extension=session
extension=json
extension=pdo
extension=pdo_mysql

; Date
date.timezone = Asia/Karachi

; MySQLi
mysqli.default_socket = ${path.join(dataDir, 'mariadb.sock').replace(/\\/g, '/')}
`.trim();

  fs.writeFileSync(iniPath, content, 'utf8');
}

// ── Cleanup ──
function stopMariaDB() {
  if (!mariadbProcess) return;
  log('INFO', 'Stopping MariaDB...');
  try {
    const mariadbDir = getMariaDbDir();
    const mariadbCli = path.join(mariadbDir, 'bin', 'mariadb.exe');
    const socket = path.join(getDataDir(), 'mariadb.sock');

    // Try graceful shutdown first
    execSync(`"${mariadbCli}" -u root -S "${socket}" -e "SHUTDOWN"`, {
      timeout: 10000,
      stdio: 'pipe'
    });
  } catch {
    // Force kill if graceful shutdown fails
    if (mariadbProcess && !mariadbProcess.killed) {
      mariadbProcess.kill('SIGTERM');
    }
  }
  mariadbProcess = null;
}

function stopPHP() {
  if (!phpProcess) return;
  log('INFO', 'Stopping PHP...');
  try {
    if (!phpProcess.killed) {
      phpProcess.kill('SIGTERM');
    }
  } catch {}
  phpProcess = null;
}

function cleanup() {
  isQuitting = true;
  stopPHP();
  stopMariaDB();

  // Clean up stale PID files
  const dataDir = getDataDir();
  try {
    const pidFile = path.join(dataDir, 'mariadb.pid');
    if (fs.existsSync(pidFile)) fs.unlinkSync(pidFile);
  } catch {}
}

// ── Loading Window ──
let loadingWindow = null;

function createLoadingWindow() {
  loadingWindow = new BrowserWindow({
    width: 480,
    height: 360,
    frame: false,
    transparent: true,
    resizable: false,
    skipTaskbar: true,
    alwaysOnTop: true,
    center: true,
    title: APP_NAME,
    icon: path.join(__dirname, '..', 'resources', 'icons', 'icon.ico'),
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false
    }
  });

  loadingWindow.loadFile(path.join(__dirname, 'loading.html'));
  loadingWindow.on('closed', () => { loadingWindow = null; });
  return loadingWindow;
}

function updateLoadingStatus(message, progress, isError = false) {
  if (loadingWindow && !loadingWindow.isDestroyed()) {
    loadingWindow.webContents.send('status-update', { message, progress, isError });
    // Fallback: also use webContents.executeJavaScript
    loadingWindow.webContents.executeJavaScript(`
      if (typeof updateStatus === 'function') {
        updateStatus(${JSON.stringify(message)}, ${progress || 0}, ${isError});
      }
    `).catch(() => {});
  }
}

function destroyLoadingWindow() {
  if (loadingWindow && !loadingWindow.isDestroyed()) {
    loadingWindow.close();
    loadingWindow = null;
  }
}

// ── Main Application Window ──
let appWindow = null;

function createAppWindow(port) {
  const url = `http://127.0.0.1:${port}/`;

  appWindow = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 900,
    minHeight: 600,
    title: APP_NAME,
    icon: path.join(__dirname, '..', 'resources', 'icons', 'icon.ico'),
    show: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false,
      webSecurity: true
    }
  });

  // Prevent new windows
  appWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));

  // Handle external links
  appWindow.webContents.setWindowOpenHandler(({ url: linkUrl }) => {
    if (linkUrl.startsWith('http')) shell.openExternal(linkUrl);
    return { action: 'deny' };
  });

  // Show window when ready, destroy loading screen
  appWindow.once('ready-to-show', () => {
    destroyLoadingWindow();
    appWindow.show();
    appWindow.focus();
  });

  // Window title
  appWindow.on('page-title-updated', (e) => {
    e.preventDefault();
    appWindow.setTitle(APP_NAME);
  });

  // Remember window size
  appWindow.on('close', () => {
    if (appWindow) {
      const bounds = appWindow.getBounds();
      try {
        const stateFile = path.join(getDataDir(), 'window-state.json');
        fs.writeFileSync(stateFile, JSON.stringify(bounds));
      } catch {}
    }
  });

  // Restore window size
  try {
    const stateFile = path.join(getDataDir(), 'window-state.json');
    if (fs.existsSync(stateFile)) {
      const state = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
      if (state.width && state.height) {
        appWindow.setBounds(state);
      }
    }
  } catch {}

  // Load the POS
  appWindow.loadURL(url);
  mainWindow = appWindow; // Alias for cleanup compatibility
}

// ── Startup ──
async function startApplication() {
  log('INFO', `${APP_NAME} v${APP_VERSION} starting...`);
  log('INFO', `Install dir: ${getInstallDir()}`);
  log('INFO', `Data dir: ${getDataDir()}`);

  try {
    // 0. Show loading screen
    createLoadingWindow();

    // 1. Ensure directories
    updateLoadingStatus('Preparing data directories...', 10);
    const dataDir = getDataDir();
    const dirs = ['database', 'backups', 'logs', 'sessions'];
    for (const d of dirs) {
      const dirPath = path.join(dataDir, d);
      if (!fs.existsSync(dirPath)) fs.mkdirSync(dirPath, { recursive: true });
    }

    // 2. Start MariaDB
    updateLoadingStatus('Starting database server...', 25);
    await startMariaDB();

    // 3. Wait for MariaDB to be actually ready
    updateLoadingStatus('Waiting for database...', 40);
    await waitForMariaDB();

    // 4. Initialize database
    updateLoadingStatus('Initializing database...', 55);
    initDatabase();

    // 5. Start PHP
    updateLoadingStatus('Starting PHP server...', 70);
    const port = await startPHP();

    // 6. Wait for HTTP
    updateLoadingStatus('Verifying application server...', 85);
    await waitForHttp(`http://127.0.0.1:${port}/`, 15000);

    // 7. All ready
    updateLoadingStatus('Loading Saffron POS...', 95);
    log('INFO', `All services ready on port ${port}`);

    // Small delay for loading screen to show final state
    await new Promise(r => setTimeout(r, 300));

    // 8. Create main window
    createAppWindow(port);

  } catch (err) {
    log('ERROR', 'Application startup failed', err.message);
    updateLoadingStatus('Startup failed: ' + err.message, 100, true);
    // Wait briefly so user can see the error
    await new Promise(r => setTimeout(r, 2000));
    destroyLoadingWindow();
    dialog.showErrorBox(
      'Saffron POS could not start',
      `The local services could not be started.\n\nError: ${err.message}\n\nA diagnostic log was saved to:\n${path.join(getDataDir(), 'logs', 'app.log')}`
    );
    app.quit();
  }
}

// ── App lifecycle ──
app.whenReady().then(startApplication);

app.on('window-all-closed', () => {
  cleanup();
  app.quit();
});

app.on('before-quit', () => {
  cleanup();
});

app.on('activate', () => {
  if (BrowserWindow.getAllWindows().length === 0) {
    // macOS: recreate window
  }
});

// ── IPC handlers ──
ipcMain.handle('get-app-info', () => ({
  name: APP_NAME,
  version: APP_VERSION,
  dataDir: getDataDir(),
  installDir: getInstallDir()
}));
