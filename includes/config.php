<?php
// includes/config.php — Saffron POS Configuration

// ── Load .env file for local dev ──
function loadDotEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if ($line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!getenv($key)) putenv("{$key}={$value}");
    }
}
loadDotEnv(dirname(__DIR__) . '/.env');

// ── Detect runtime environment ──
function isVercel() {
    return getenv('VERCEL') === '1' || getenv('VERCEL');
}

// ── Base URL (configurable for Vercel vs local) ──
// On Vercel: app lives at root (empty string). On local server: /pos
define('BASE_URL', getenv('POS_BASE_URL') ?: (isVercel() ? '' : '/pos'));

require_once __DIR__ . '/db.php';

// ── Production credentials (override via environment) ──
define('DB_HOST', getenv('POS_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('POS_DB_PORT') ?: '5432');
define('DB_USER', getenv('POS_DB_USER') ?: '');
define('DB_PASS', getenv('POS_DB_PASS') ?: '');
define('DB_NAME', getenv('POS_DB_NAME') ?: 'saffron');
// Also support Vercel/Neon POSTGRES_URL
define('DB_DSN', getenv('POSTGRES_URL') ?: getenv('DATABASE_URL') ?: '');

// Validate credentials exist
if (empty(DB_PASS)) {
    http_response_code(500);
    if (php_sapi_name() === 'cli') {
        die("Database password not configured. Set POS_DB_PASS environment variable.\n");
    } else {
        die('<div style="font-family:sans-serif;padding:40px;background:#fee;color:#c00;border:2px solid #c00;margin:40px;border-radius:8px"><h2>Configuration Error</h2><p>Database password not configured. Set the <code>POS_DB_PASS</code> environment variable.</p></div>');
    }
}

// ── Error handling ──
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (isVercel() || getenv('POS_DB_SESSIONS')) {
    ini_set('error_log', 'php://stderr');
} else {
    ini_set('error_log', '/var/log/pos_errors.log');
}

set_exception_handler(function ($ex) {
    error_log('POS EXCEPTION: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    if (php_sapi_name() === 'cli') {
        echo "Error: " . $ex->getMessage() . "\n";
    } else {
        http_response_code(500);
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/pages/checkout.php') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'An unexpected error occurred.']);
        } else {
            echo '<div style="font-family:sans-serif;padding:40px;background:#fee;color:#c00;border:2px solid #c00;margin:40px;border-radius:8px"><h2>Something went wrong</h2><p>Please try again. If the problem persists, contact support.</p></div>';
        }
    }
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("POS ERROR [$errno]: $errstr in $errfile:$errline");
    return false;
});

// ── Database connection ──
// Support Neon/Vercel POSTGRES_URL (full connection string)
if (DB_DSN && strpos(DB_DSN, 'postgresql') === 0) {
    // Parse Neon connection string: postgresql://user:pass@host/db?sslmode=require
    $parsed = parse_url(DB_DSN);
    $conn = new Db(
        $parsed['host'],
        $parsed['user'],
        $parsed['pass'],
        ltrim($parsed['path'], '/'),
        $parsed['port'] ?? 5432
    );
} elseif (DB_DSN && strpos(DB_DSN, 'mysql') === 0) {
    $parsed = parse_url(DB_DSN);
    $conn = new Db(
        $parsed['host'],
        $parsed['user'],
        $parsed['pass'],
        ltrim($parsed['path'], '/'),
        $parsed['port'] ?? 3306
    );
} else {
    $conn = new Db(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
}
if ($conn->connect_error) {
    error_log('POS DB CONNECTION FAILED: ' . $conn->connect_error);
    if (php_sapi_name() === 'cli') {
        die("Database connection failed.\n");
    } else {
        http_response_code(500);
        die('<div style="font-family:sans-serif;padding:40px;background:#fee;color:#c00;border:2px solid #c00;margin:40px;border-radius:8px"><h2>Database Connection Failed</h2><p>Make sure MariaDB is running and the database is configured correctly.</p></div>');
    }
}
$conn->set_charset('utf8mb4');

// ── DB-backed sessions for Vercel ──
function initSession() {
    if (session_status() !== PHP_SESSION_NONE) return;
    if (isVercel() || getenv('POS_DB_SESSIONS')) {
        global $conn;
        session_set_save_handler(
            function($id) use ($conn) {
                $now = time();
                $stmt = $conn->prepare("SELECT data FROM app_sessions WHERE id=? AND expires > ?");
                $stmt->bind_param("si", $id, $now);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                return $r ? $r['data'] : '';
            },
            function($id, $data) use ($conn) {
                $ttl = time() + 28800;
                $stmt = $conn->prepare("REPLACE INTO app_sessions (id, data, expires) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $id, $data, $ttl);
                $stmt->execute();
                return true;
            },
            function($id) use ($conn) {
                $stmt = $conn->prepare("DELETE FROM app_sessions WHERE id=?");
                $stmt->bind_param("s", $id);
                $stmt->execute();
                return true;
            },
            function($maxLifetime) use ($conn) {
                $cutoff = time() - $maxLifetime;
                $stmt = $conn->prepare("DELETE FROM app_sessions WHERE expires < ?");
                $stmt->bind_param("i", $cutoff);
                $stmt->execute();
                return true;
            },
            function($id) use ($conn) {
                $stmt = $conn->prepare("SELECT 1 FROM app_sessions WHERE id=?");
                $stmt->bind_param("s", $id);
                $stmt->execute();
                return $stmt->get_result()->num_rows > 0;
            },
            function($name) { return 'saffron_' . $name; }
        );
    }
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_start();
}

initSession();

// ── Load settings from database ──
function loadSettings($conn) {
    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value, setting_type FROM settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $val = $row['setting_value'];
            switch ($row['setting_type']) {
                case 'integer': $val = (int)$val; break;
                case 'decimal': $val = (float)$val; break;
                case 'boolean': $val = (bool)$val; break;
                case 'json':    $val = json_decode($val, true); break;
            }
            $settings[$row['setting_key']] = $val;
        }
    }
    return $settings;
}

$SETTINGS = loadSettings($conn);

define('SHOP_NAME',   $SETTINGS['shop_name']   ?? 'Saffron Sanitary');
define('SHOP_ADDRESS',$SETTINGS['shop_address'] ?? '');
define('SHOP_PHONE',  $SETTINGS['shop_phone']   ?? '');
define('CURRENCY',    $SETTINGS['currency']     ?? 'Rs: ');
define('TAX_RATE',    (float)($SETTINGS['default_tax_rate'] ?? 18));
define('INVOICE_FOOTER', $SETTINGS['invoice_footer'] ?? 'Thank you for your business!');
define('QUOTATION_FOOTER', $SETTINGS['quotation_footer'] ?? 'This quotation is valid for 15 days.');

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function money($n) { return CURRENCY . number_format((float)$n, 2); }
function moneyRaw($n) { return number_format((float)$n, 2); }

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}
function csrf_verify() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals(csrf_token(), $token);
}

function generateInvoice($conn) {
    $prefix = 'INV-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT invoice_no FROM sales WHERE invoice_no LIKE ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    if ($last) { $lastNum = (int)substr($last['invoice_no'], -5); return $prefix . str_pad($lastNum + 1, 5, '0', STR_PAD_LEFT); }
    return $prefix . '00001';
}

function generateQuotationNo($conn) {
    $prefix = 'QUO-' . date('Ym') . '-';
    $stmt = $conn->prepare("SELECT quotation_no FROM quotations WHERE quotation_no LIKE ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    if ($last) { $lastNum = (int)substr($last['quotation_no'], -4); return $prefix . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT); }
    return $prefix . '0001';
}

function generateOrderNo($conn) {
    $prefix = 'ORD-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT order_no FROM orders WHERE order_no LIKE ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    if ($last) { $lastNum = (int)substr($last['order_no'], -4); return $prefix . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT); }
    return $prefix . '0001';
}

function generateDeliveryNo($conn) {
    $prefix = 'DEL-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT delivery_no FROM deliveries WHERE delivery_no LIKE ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    if ($last) { $lastNum = (int)substr($last['delivery_no'], -4); return $prefix . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT); }
    return $prefix . '0001';
}

function calculateTax($subtotal, $taxable, $gstRate) {
    if (!$taxable || $gstRate <= 0) return 0.00;
    return round($subtotal * $gstRate / 100, 2);
}

function getCustomerBalance($conn, $customerId) {
    $stmt = $conn->prepare("SELECT COALESCE(balance_after, 0) FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? (float)$result['balance_after'] : 0.00;
}

function updateCustomerBalance($conn, $customerId, $type, $amount, $referenceType = null, $referenceId = null, $note = null) {
    $currentBalance = getCustomerBalance($conn, $customerId);
    switch ($type) {
        case 'invoice':  $newBalance = $currentBalance + $amount; break;
        case 'payment':  $newBalance = $currentBalance - $amount; break;
        case 'refund':   $newBalance = $currentBalance - $amount; break;
        case 'credit':   $newBalance = $currentBalance + $amount; break;
        case 'adjustment': $newBalance = $currentBalance + $amount; break;
        default: $newBalance = $currentBalance;
    }
    $userId = $_SESSION['user_id'] ?? null;
    $stmt = $conn->prepare("INSERT INTO customer_ledger (customer_id, type, reference_type, reference_id, amount, balance_after, note, user_id) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param("issiddsi", $customerId, $type, $referenceType, $referenceId, $amount, $newBalance, $note, $userId);
    $stmt->execute();
    return $newBalance;
}

function logInventoryMovement($conn, $productId, $qty, $type, $reason = null, $note = null) {
    $stmt = $conn->prepare("INSERT INTO inventory_log (product_id, qty_added, type, reason, note) VALUES (?,?,?,?,?)");
    $stmt->bind_param("iisss", $productId, $qty, $type, $reason, $note);
    $stmt->execute();
}

function auditLog($conn, $action, $entityType, $entityId = null, $details = null, $ip = null) {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$ip) $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $jsonDetails = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("ississ", $userId, $action, $entityType, $entityId, $jsonDetails, $ip);
    $stmt->execute();
}

function getSetting($key, $default = null) {
    global $SETTINGS;
    return $SETTINGS[$key] ?? $default;
}

function setSetting($conn, $key, $value) {
    global $SETTINGS;
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
    $SETTINGS[$key] = $value;
}

function availableStock($product) {
    return max(0, (int)$product['stock'] - (int)($product['reserved_stock'] ?? 0));
}

function getBrandName($conn, $brandId) {
    static $cache = [];
    if (!$brandId) return '—';
    if (isset($cache[$brandId])) return $cache[$brandId];
    $stmt = $conn->prepare("SELECT name FROM brands WHERE id = ?");
    $stmt->bind_param("i", $brandId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $cache[$brandId] = $r ? $r['name'] : '—';
    return $cache[$brandId];
}

function getUnitShort($conn, $unitId) {
    static $cache = [];
    if (!$unitId) return 'pc';
    if (isset($cache[$unitId])) return $cache[$unitId];
    $stmt = $conn->prepare("SELECT short_name FROM units WHERE id = ?");
    $stmt->bind_param("i", $unitId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $cache[$unitId] = $r ? $r['short_name'] : 'pc';
    return $cache[$unitId];
}

function resolveTax($product) {
    $mode = $product['tax_mode'] ?? 'default';
    switch ($mode) {
        case 'non_taxable': return ['taxable' => false, 'rate' => 0.0];
        case 'custom': return ['taxable' => true, 'rate' => max(0.0, floatval($product['gst_rate'] ?? 0))];
        default: return ['taxable' => true, 'rate' => getSetting('default_tax_rate', 18)];
    }
}

function resolveTaxById($conn, $productId) {
    $stmt = $conn->prepare("SELECT tax_mode, gst_rate, taxable FROM products WHERE id=?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    if (!$product) return ['taxable' => false, 'rate' => 0.0];
    return resolveTax($product);
}

function getEffectiveTaxRate($conn, $productId) {
    $tax = resolveTaxById($conn, $productId);
    return $tax['taxable'] ? $tax['rate'] : 0.0;
}

function parseCsvFile($handle, $maxRows = 5000) {
    if (!$handle) return ['headers' => [], 'rows' => [], 'error' => 'Cannot read file'];
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);
    $headers = fgetcsv($handle);
    if (!$headers || empty($headers)) return ['headers' => [], 'rows' => [], 'error' => 'CSV file is empty or has no headers'];
    $headers = array_map(function($h) { return strtolower(trim($h)); }, $headers);
    $rows = []; $rowNum = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if ($rowNum > $maxRows + 1) return ['headers' => $headers, 'rows' => $rows, 'error' => "File exceeds maximum of {$maxRows} data rows"];
        if (count($row) < count($headers)) $row = array_merge($row, array_fill(0, count($headers) - count($row), ''));
        $rows[] = array_combine($headers, $row);
    }
    fclose($handle);
    return ['headers' => $headers, 'rows' => $rows, 'error' => null];
}

function generateCsv($headers, $rows) {
    $output = fopen('php://temp', 'r+');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $h) $line[] = $row[$h] ?? '';
        fputcsv($output, $line);
    }
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    return $csv;
}

function sendCsvDownload($filename, $csvContent) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $csvContent;
    exit;
}

function csvEscape($value) {
    $s = (string)$value;
    if (in_array(substr($s, 0, 1), ['=', '+', '-', '@', "\t", "\r", "\n"])) $s = "'" . $s;
    return $s;
}

// ── Pure PHP Backup (no exec needed) ──
function generateSqlDump($conn) {
    $isPg = $conn->is_pgsql();
    $tables = [];
    $result = $conn->query($isPg
        ? "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name"
        : "SHOW TABLES"
    );
    while ($row = $result->fetch_row()) $tables[] = $row[0];
    $output = "-- Saffron POS Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    foreach ($tables as $table) {
        $output .= "DROP TABLE IF EXISTS \"{$table}\" CASCADE;\n\n";
        if ($isPg) {
            // PostgreSQL: get CREATE TABLE from pg_dump-like output
            $cols = $conn->query("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = '{$table}' ORDER BY ordinal_position");
            $output .= "CREATE TABLE \"{$table}\" (\n";
            $colDefs = [];
            while ($col = $cols->fetch_assoc()) {
                $null = $col['is_nullable'] === 'YES' ? '' : ' NOT NULL';
                $def = $col['column_default'] ? " DEFAULT {$col['column_default']}" : '';
                $colDefs[] = "    \"{$col['column_name']}\" {$col['data_type']}{$null}{$def}";
            }
            $output .= implode(",\n", $colDefs) . "\n);\n\n";
        } else {
            $create = $conn->query("SHOW CREATE TABLE `{$table}`");
            if ($create && $row = $create->fetch_row()) $output .= "{$row[1]};\n\n";
        }
        $data = $conn->query("SELECT * FROM \"{$table}\"");
        if ($data && $data->num_rows > 0) {
            while ($row = $data->fetch_assoc()) {
                $vals = array_map(function($v) use ($conn) {
                    if ($v === null) return 'NULL';
                    if ($conn->is_pgsql()) return $conn->pdo->quote($v);
                    return "'" . $conn->real_escape_string($v) . "'";
                }, array_values($row));
                $cols = array_map(function($c) { return "\"{$c}\""; }, array_keys($row));
                $output .= "INSERT INTO \"{$table}\" (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $output .= "\n";
        }
    }
    return $output;
}

function executeSqlRestore($conn, $sqlContent) {
    $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
    $errors = [];
    foreach ($statements as $stmt) {
        if (empty($stmt) || substr($stmt, 0, 2) === '--') continue;
        if (!$conn->query($stmt)) $errors[] = $conn->error;
    }
    return ['success' => empty($errors), 'errors' => $errors];
}
