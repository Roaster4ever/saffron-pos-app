<?php
// includes/windows_compat.php — Windows Desktop Compatibility Layer
// Provides Windows-compatible backup/restore commands when running as desktop app.
// On Linux: all functions fall back to standard Linux behavior.
// On Windows desktop app: uses bundled MariaDB tools.

/**
 * Check if running as Electron desktop app.
 * The desktop app sets BUKHARI_DESKTOP=1 environment variable.
 */
function isDesktopApp() {
    return getenv('BUKHARI_DESKTOP') === '1';
}

/**
 * Get the path to the bundled MariaDB tools directory.
 */
function getMariaDbBinDir() {
    $dir = getenv('MARIADB_BIN_DIR');
    if ($dir && is_dir($dir)) return $dir;
    return null;
}

/**
 * Get the path to the application data directory.
 */
function getDataDir() {
    $dir = getenv('BUKHARI_DATA_DIR');
    if ($dir) return $dir;
    $localAppData = getenv('LOCALAPPDATA');
    if ($localAppData) return $localAppData . DIRECTORY_SEPARATOR . 'SaffronPOS';
    return null;
}

/**
 * Build a backup command.
 * Linux: mariadb-dump -u root pos_db | gzip > backup.sql.gz
 * Windows desktop: uses bundled mariadb-dump.exe and gzip.exe
 */
function buildBackupCommand($outputFile) {
    if (!isDesktopApp()) {
        return "mariadb-dump -u root pos_db 2>/dev/null | gzip > " . escapeshellarg($outputFile);
    }

    $binDir = getMariaDbBinDir();
    if (!$binDir) throw new Exception('MariaDB bin directory not found');

    $dump = escapeshellarg($binDir . DIRECTORY_SEPARATOR . 'mariadb-dump.exe');
    $gzip = escapeshellarg($binDir . DIRECTORY_SEPARATOR . 'gzip.exe');
    $socket = escapeshellarg(getDataDir() . DIRECTORY_SEPARATOR . 'mariadb.sock');

    return "$dump -u root -S $socket pos_db 2>nul | $gzip > " . escapeshellarg($outputFile);
}

/**
 * Build a restore command.
 * Linux: gunzip -c restore.sql.gz | mariadb -u root pos_db
 * Windows desktop: uses bundled tools
 */
function buildRestoreCommand($inputFile, $isGzip = true) {
    if (!isDesktopApp()) {
        if ($isGzip) {
            return "gunzip -c " . escapeshellarg($inputFile) . " | mariadb -u root pos_db 2>&1";
        }
        return "mariadb -u root pos_db < " . escapeshellarg($inputFile) . " 2>&1";
    }

    $binDir = getMariaDbBinDir();
    if (!$binDir) throw new Exception('MariaDB bin directory not found');

    $mariadb = escapeshellarg($binDir . DIRECTORY_SEPARATOR . 'mariadb.exe');
    $gzip = escapeshellarg($binDir . DIRECTORY_SEPARATOR . 'gzip.exe');
    $socket = escapeshellarg(getDataDir() . DIRECTORY_SEPARATOR . 'mariadb.sock');

    if ($isGzip) {
        return "$gzip -dc " . escapeshellarg($inputFile) . " | $mariadb -u root -S $socket pos_db 2>&1";
    }
    return "$mariadb -u root -S $socket pos_db < " . escapeshellarg($inputFile) . " 2>&1";
}

/**
 * Execute a backup command.
 */
function executeBackupCommand($outputFile) {
    $cmd = buildBackupCommand($outputFile);
    exec($cmd, $output, $returnCode);
    return ['success' => $returnCode === 0, 'output' => $output, 'code' => $returnCode];
}

/**
 * Execute a restore command.
 */
function executeRestoreCommand($inputFile, $isGzip = true) {
    $cmd = buildRestoreCommand($inputFile, $isGzip);
    exec($cmd, $output, $returnCode);
    return ['success' => $returnCode === 0, 'output' => $output, 'code' => $returnCode];
}
