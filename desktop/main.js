const { app, BrowserWindow, ipcMain, Tray, Menu, shell } = require('electron');
const { spawn, exec } = require('child_process');
const path = require('path');
const fs = require('fs');
const net = require('net');

const isDev = !app.isPackaged;
const RESOURCES = isDev ? __dirname : process.resourcesPath;
const PHP_DIR = path.join(RESOURCES, 'php');
const MARIADB_DIR = path.join(RESOURCES, 'mariadb');
const NGINX_DIR = path.join(RESOURCES, 'nginx');
const APP_DIR = path.join(RESOURCES, 'app');
const LOG_DIR = path.join(app.getPath('userData'), 'logs');
const DATA_DIR = path.join(app.getPath('userData'), 'data');
const PORT = 8080;

let mainWindow = null;
let tray = null;
let mariadbProc = null;
let phpProc = null;
let nginxProc = null;
let isQuitting = false;

[LOG_DIR, DATA_DIR].forEach(d => {
  if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
});

function portInUse(port) {
  return new Promise(r => {
    const s = net.createServer();
    s.once('error', () => r(true));
    s.once('listening', () => { s.close(); r(false); });
    s.listen(port, '127.0.0.1');
  });
}

function killProc(p) {
  return new Promise(r => {
    if (!p || p.killed) return r();
    try { p.kill(); } catch(e) {}
    setTimeout(r, 2000);
  });
}

// ── MariaDB ──
async function startMariaDB() {
  const bin = [
    path.join(MARIADB_DIR, 'bin', 'mariadbd.exe'),
    path.join(MARIADB_DIR, 'bin', 'mysqld.exe')
  ].find(p => fs.existsSync(p));
  if (!bin) { console.log('[DB] binary not found'); return false; }

  const dataDir = path.join(DATA_DIR, 'mariadb');
  if (!fs.existsSync(dataDir)) {
    fs.mkdirSync(dataDir, { recursive: true });
    const init = [
      path.join(MARIADB_DIR, 'bin', 'mariadb-install-db.exe'),
      path.join(MARIADB_DIR, 'bin', 'mysql_install_db.exe')
    ].find(p => fs.existsSync(p));
    if (init) {
      await new Promise(r => exec('"' + init + '" --datadir="' + dataDir + '" --password=""', r));
    }
  }

  mariadbProc = spawn(bin, ['--datadir=' + dataDir, '--port=3306', '--console'], {
    stdio: 'ignore', detached: true, windowsHide: true
  });
  mariadbProc.on('error', e => console.log('[DB]', e.message));
  mariadbProc.unref();

  for (let i = 0; i < 20; i++) {
    await new Promise(r => setTimeout(r, 500));
    if (!(await portInUse(3306))) return true;
  }
  return true;
}

// ── PHP ──
async function startPHP() {
  const phpIni = path.join(PHP_DIR, 'php.ini');
  const phpIniDev = path.join(PHP_DIR, 'php.ini-development');
  if (!fs.existsSync(phpIni) && fs.existsSync(phpIniDev)) {
    fs.copyFileSync(phpIniDev, phpIni);
    let c = fs.readFileSync(phpIni, 'utf8');
    c = c.replace(/;extension=mysqli/mi, 'extension=mysqli')
         .replace(/;extension=pdo_mysql/mi, 'extension=pdo_mysql')
         .replace(/;extension=json/mi, 'extension=json')
         .replace(/;extension=mbstring/mi, 'extension=mbstring')
         .replace(/upload_max_filesize = .*/m, 'upload_max_filesize = 20M')
         .replace(/post_max_size = .*/m, 'post_max_size = 25M')
         .replace(/memory_limit = .*/m, 'memory_limit = 256M');
    fs.writeFileSync(phpIni, c);
  }

  const envFile = path.join(APP_DIR, '.env');
  if (!fs.existsSync(envFile)) {
    fs.writeFileSync(envFile, [
      'DB_HOST=127.0.0.1', 'DB_PORT=3306', 'DB_NAME=saffron_pos',
      'DB_USER=root', 'DB_PASS=', 'POS_BASE_URL=', 'POS_PORTABLE=1'
    ].join('\n'));
  }

  const cgi = path.join(PHP_DIR, 'php-cgi.exe');
  const php = path.join(PHP_DIR, 'php.exe');
  if (fs.existsSync(cgi)) {
    phpProc = spawn(cgi, ['-b', '127.0.0.1:9000', '-c', phpIni], {
      cwd: PHP_DIR, stdio: 'ignore', detached: true, windowsHide: true
    });
  } else if (fs.existsSync(php)) {
    phpProc = spawn(php, ['-S', '127.0.0.1:9000', '-t', APP_DIR, '-c', phpIni], {
      cwd: PHP_DIR, stdio: 'ignore', detached: true, windowsHide: true
    });
  } else {
    console.log('[PHP] binary not found');
    return false;
  }
  phpProc.on('error', e => console.log('[PHP]', e.message));
  phpProc.unref();
  await new Promise(r => setTimeout(r, 2000));
  return true;
}

// ── Nginx ──
async function startNginx() {
  const nginxExe = path.join(NGINX_DIR, 'nginx.exe');
  if (!fs.existsSync(nginxExe)) { console.log('[Nginx] not found'); return false; }

  const confPath = path.join(NGINX_DIR, 'conf', 'nginx.conf');
  const a = APP_DIR.replace(/\\/g, '/').replace(/^([A-Z]):/i, '/$1');
  const l = LOG_DIR.replace(/\\/g, '/').replace(/^([A-Z]):/i, '/$1');

  const conf = [
    'worker_processes 1;',
    'events { worker_connections 1024; }',
    'http {',
    '  include mime.types;',
    '  default_type application/octet-stream;',
    '  sendfile on;',
    '  keepalive_timeout 65;',
    '  access_log ' + l + '/access.log;',
    '  error_log ' + l + '/error.log;',
    '  gzip on;',
    '  gzip_types text/css application/javascript application/json text/plain;',
    '  server {',
    '    listen ' + PORT + ';',
    '    server_name localhost;',
    '    root ' + a + ';',
    '    index index.php index.html;',
    '    add_header X-Content-Type-Options "nosniff" always;',
    '    add_header X-Frame-Options "SAMEORIGIN" always;',
    '    location ~ /\\. { deny all; }',
    '    location / { try_files $uri /index.php?$args; }',
    '    location ~ \\.php$ {',
    '      try_files $uri =404;',
    '      fastcgi_pass 127.0.0.1:9000;',
    '      fastcgi_index index.php;',
    '      fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;',
    '      include fastcgi_params;',
    '    }',
    '    location ~* \\.(css|js|ico|png|jpg|gif|svg|woff2|ttf|eot)$ {',
    '      expires 7d;',
    '      add_header Cache-Control "public, immutable";',
    '    }',
    '  }',
    '}',
  ].join('\n');

  fs.writeFileSync(confPath, conf);

  nginxProc = spawn(nginxExe, ['-p', NGINX_DIR, '-c', confPath], {
    cwd: NGINX_DIR, stdio: 'ignore', detached: true, windowsHide: true
  });
  nginxProc.on('error', e => console.log('[Nginx]', e.message));
  nginxProc.unref();

  for (let i = 0; i < 10; i++) {
    await new Promise(r => setTimeout(r, 500));
    if (await portInUse(PORT) === false) return true;
  }
  return true;
}

// ── Init Database ──
function initDatabase() {
  return new Promise(resolve => {
    const php = path.join(PHP_DIR, 'php.exe');
    const setup = path.join(APP_DIR, 'setup_db.php');
    if (!fs.existsSync(php) || !fs.existsSync(setup)) return resolve(false);
    exec('"' + php + '" "' + setup + '"', { cwd: APP_DIR }, (err, out) => {
      console.log('[DB]', out);
      resolve(!err);
    });
  });
}

// ── Stop All ──
async function stopAll() {
  await killProc(nginxProc);
  await killProc(phpProc);
  await killProc(mariadbProc);
}

// ── Create Window ──
function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1400, height: 900, minWidth: 1024, minHeight: 700,
    frame: false, titleBarStyle: 'hidden',
    backgroundColor: '#0a0a14',
    icon: path.join(__dirname, 'icon.ico'),
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      nodeIntegration: false, contextIsolation: true, webSecurity: true
    },
    show: false
  });

  const titlebar = path.join(__dirname, 'titlebar.html');
  if (fs.existsSync(titlebar)) {
    mainWindow.loadFile(titlebar);
  } else {
    mainWindow.loadURL('http://localhost:' + PORT);
  }

  mainWindow.once('ready-to-show', () => mainWindow.show());
  mainWindow.on('close', e => {
    if (!isQuitting) { e.preventDefault(); mainWindow.hide(); }
  });
  mainWindow.on('closed', () => { mainWindow = null; });
}

// ── System Tray ──
function createTray() {
  const iconPath = path.join(__dirname, 'icon.ico');
  if (!fs.existsSync(iconPath)) return;
  tray = new Tray(iconPath);
  const menu = Menu.buildFromTemplate([
    { label: 'Open Saffron POS', click: () => { if (mainWindow) { mainWindow.show(); mainWindow.focus(); } } },
    { type: 'separator' },
    { label: 'Restart Server', click: async () => {
      await stopAll();
      await startMariaDB(); await startPHP(); await startNginx();
      if (mainWindow) mainWindow.reload();
    }},
    { type: 'separator' },
    { label: 'Open Data Folder', click: () => shell.openPath(DATA_DIR) },
    { type: 'separator' },
    { label: 'Quit', click: () => { isQuitting = true; app.quit(); } }
  ]);
  tray.setToolTip('Saffron POS');
  tray.setContextMenu(menu);
  tray.on('double-click', () => { if (mainWindow) { mainWindow.show(); mainWindow.focus(); } });
}

// ── IPC ──
ipcMain.on('window-minimize', () => { if (mainWindow) mainWindow.minimize(); });
ipcMain.on('window-maximize', () => {
  if (mainWindow) mainWindow.isMaximized() ? mainWindow.unmaximize() : mainWindow.maximize();
});
ipcMain.on('window-close', () => { if (mainWindow) mainWindow.hide(); });

// ── App Lifecycle ──
app.whenReady().then(async () => {
  console.log('[Saffron POS] Starting...');

  const dbOk = await startMariaDB();
  console.log('[Saffron POS] MariaDB:', dbOk ? 'OK' : 'FAILED');
  if (dbOk) await initDatabase();

  const phpOk = await startPHP();
  console.log('[Saffron POS] PHP:', phpOk ? 'OK' : 'FAILED');

  const ngxOk = await startNginx();
  console.log('[Saffron POS] Nginx:', ngxOk ? 'OK' : 'FAILED');

  for (let i = 0; i < 30; i++) {
    if (!(await portInUse(PORT))) break;
    await new Promise(r => setTimeout(r, 1000));
  }

  createWindow();
  createTray();
});

app.on('window-all-closed', () => {});
app.on('before-quit', async () => {
  isQuitting = true;
  await stopAll();
});
app.on('activate', () => {
  if (mainWindow === null) createWindow();
  else { mainWindow.show(); mainWindow.focus(); }
});
