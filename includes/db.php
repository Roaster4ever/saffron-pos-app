<?php
// includes/db.php — PDO wrapper providing mysqli-compatible API
// Supports both MySQL and PostgreSQL (Neon/Vercel)

if (!defined('MYSQLI_ASSOC')) define('MYSQLI_ASSOC', 1);

class Db {
    public $pdo;
    public $connect_error = '';
    public $error = '';
    public $insert_id = 0;
    private $driver;

    public function __construct($host, $user, $pass, $name, $port = 3306) {
        $this->driver = extension_loaded('pdo_pgsql') ? 'pgsql' : 'mysql';
        try {
            if ($this->driver === 'pgsql') {
                $dsn = "pgsql:host={$host};port={$port};dbname={$name};options='--client_encoding=utf8';sslmode=require";
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } else {
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // 1002 = MYSQL_ATTR_INIT_COMMAND (avoids deprecation on PHP 8.5)
                    1002 => "SET NAMES utf8mb4",
                ]);
            }
        } catch (PDOException $e) {
            $this->connect_error = $e->getMessage();
        }
    }

    public function set_charset($charset) { /* handled in DSN */ }

    public function prepare($sql) {
        $sql = $this->adaptSql($sql);
        try {
            $stmt = $this->pdo->prepare($sql);
            return new DbStmt($this, $stmt);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function query($sql) {
        $sql = $this->adaptSql($sql);
        try {
            $stmt = $this->pdo->query($sql);
            return new DbResult($this, $stmt);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function begin_transaction() { return $this->pdo->beginTransaction(); }
    public function commit() { return $this->pdo->commit(); }
    public function rollback() { return $this->pdo->rollBack(); }

    public function real_escape_string($s) { return $this->pdo ? $this->pdo->quote($s) : addslashes($s); }

    // Adapt MySQL-specific SQL to PostgreSQL
    public function adaptSql($sql) {
        if ($this->driver === 'pgsql') {
            // REPLACE INTO → INSERT ... ON CONFLICT ... DO UPDATE
            // Pattern: REPLACE INTO table (cols) VALUES (vals)
            if (preg_match('/^REPLACE\s+INTO\s+(\S+)\s*\((.+?)\)\s*VALUES\s*(.+)/i', $sql, $m)) {
                $table = $m[1];
                $cols = $m[2];
                $vals = $m[3];
                // Extract column names for ON CONFLICT
                $colArr = array_map('trim', explode(',', $cols));
                $updates = [];
                foreach ($colArr as $c) {
                    $c = trim($c, ' `');
                    if (strtolower($c) !== 'id') {
                        $updates[] = "{$c} = EXCLUDED.{$c}";
                    }
                }
                $conflict = !empty($updates) ? " ON CONFLICT (" . $colArr[0] . ") DO UPDATE SET " . implode(', ', $updates) : "";
                return "INSERT INTO {$table} ({$cols}) VALUES {$vals}{$conflict}";
            }

            // ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            // → ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
            if (preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(\w+)\s*=\s*VALUES\(\1\)/i', $sql)) {
                $sql = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(\w+)\s*=\s*VALUES\(\1\)/i',
                    'ON CONFLICT DO UPDATE SET $1 = EXCLUDED.$1', $sql);
            }

            // SHOW TABLES → information_schema
            if (preg_match('/SHOW\s+TABLES\s+LIKE\s+[\'"](\w+)[\'"]/i', $sql, $m)) {
                return "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '{$m[1]}'";
            }
            if (preg_match('/SHOW\s+TABLES/i', $sql)) {
                return "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name";
            }

            // SHOW CREATE TABLE → simplified
            if (preg_match('/SHOW\s+CREATE\s+TABLE\s+`?(\w+)`?/i', $sql, $m)) {
                return "SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = '{$m[1]}' ORDER BY ordinal_position";
            }

            // MySQL functions → PostgreSQL
            $sql = preg_replace('/DATE_FORMAT\((\w+\.\w+),\s*\'%Y-%m\'\)/i', "TO_CHAR($1, 'YYYY-MM')", $sql);
            $sql = preg_replace('/DATE_FORMAT\((\w+),\s*\'%Y-%m\'\)/i', "TO_CHAR($1, 'YYYY-MM')", $sql);
            $sql = preg_replace('/DATE_FORMAT\((\w+\.\w+),\s*\'%Y-%m-%d\'\)/i', "TO_CHAR($1, 'YYYY-MM-DD')", $sql);
            $sql = preg_replace('/DATE_FORMAT\((\w+),\s*\'%Y-%m-%d\'\)/i', "TO_CHAR($1, 'YYYY-MM-DD')", $sql);
            $sql = preg_replace('/DATE_SUB\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i', "(CURRENT_DATE - INTERVAL '$1 days')", $sql);
            $sql = preg_replace('/CURDATE\(\)/i', 'CURRENT_DATE', $sql);
            $sql = preg_replace('/NOW\(\)/i', 'NOW()', $sql);
            $sql = preg_replace('/IFNULL\((.+?),\s*(.+?)\)/i', 'COALESCE($1, $2)', $sql);

            // TIMESTAMPDIFF(SECOND, a, b) → EXTRACT(EPOCH FROM (b - a))
            $sql = preg_replace('/TIMESTAMPDIFF\s*\(\s*SECOND\s*,\s*(\S+)\s*,\s*(\S+)\s*\)/i',
                "EXTRACT(EPOCH FROM ($2 - $1))", $sql);

            // CONCAT → || (PostgreSQL)
            $sql = preg_replace('/CONCAT\((.+?)\)/i', '($1)', $sql);
            // Replace comma separators inside CONCAT/|| with ||
            // Simple approach: CONCAT(a, b, c) → (a || b || c)
            // This is handled by the general replace above — comma → || in concat context
            // Actually let's just convert CONCAT(a, b) → (a || b) more carefully
            if (preg_match_all('/CONCAT\((.+?)\)/i', $sql, $m)) {
                foreach ($m[0] as $i => $full) {
                    $inner = $m[1][$i];
                    $parts = array_map('trim', explode(',', $inner));
                    $replacement = '(' . implode(' || ', $parts) . ')';
                    $sql = str_replace($full, $replacement, $sql);
                }
            }

            // MOD(a, b) → (a % b)
            $sql = preg_replace('/MOD\((.+?),\s*(.+?)\)/i', '($1 % $2)', $sql);

            // GROUP_CONCAT(expr SEPARATOR ',') → STRING_AGG(expr::text, ',')
            // GROUP_CONCAT(expr) → STRING_AGG(expr::text, ',')
            $sql = preg_replace('/GROUP_CONCAT\((\w+\.\w+)\s+SEPARATOR\s+[\'"](.+?)[\'"]\)/i',
                "STRING_AGG(CAST($1 AS TEXT), '$2')", $sql);
            $sql = preg_replace('/GROUP_CONCAT\((\w+\.\w+)\)/i',
                "STRING_AGG(CAST($1 AS TEXT), ',')", $sql);
            $sql = preg_replace('/GROUP_CONCAT\((\w+)\s+SEPARATOR\s+[\'"](.+?)[\'"]\)/i',
                "STRING_AGG(CAST($1 AS TEXT), '$2')", $sql);
            $sql = preg_replace('/GROUP_CONCAT\((\w+)\)/i',
                "STRING_AGG(CAST($1 AS TEXT), ',')", $sql);

            // information_schema WHERE table_schema='pos_db' → 'public'
            $sql = preg_replace("/table_schema\s*=\s*'pos_db'/i", "table_schema = 'public'", $sql);

            // LIMIT n → LIMIT n (same syntax, but MySQL also supports LIMIT n, m)
            // MySQL LIMIT offset, count → PostgreSQL LIMIT count OFFSET offset
            $sql = preg_replace('/LIMIT\s+(\d+)\s*,\s*(\d+)/i', 'LIMIT $2 OFFSET $1', $sql);

            // MySQL backtick → PostgreSQL double-quote
            $sql = preg_replace('/`(\w+)`/', '"$1"', $sql);
        }
        return $sql;
    }

    public function is_pgsql() { return $this->driver === 'pgsql'; }
}

class DbStmt {
    private $db;
    private $stmt;
    public $num_rows = 0;
    public $types;
    public $params;

    public function __construct($db, $stmt) {
        $this->db = $db;
        $this->stmt = $stmt;
    }

    public function bind_param($types, ...$params) {
        $this->types = $types;
        $this->params = $params;
        return true;
    }

    public function execute($params = null) {
        try {
            if ($params !== null) {
                $result = $this->stmt->execute($params);
            } elseif (isset($this->types) && isset($this->params)) {
                $result = $this->stmt->execute($this->params);
            } else {
                $result = $this->stmt->execute();
            }
            $this->db->insert_id = (int)$this->db->pdo->lastInsertId();
            return $result;
        } catch (PDOException $e) {
            $this->db->error = $e->getMessage();
            return false;
        }
    }

    public function get_result() {
        return new DbResult($this->db, $this->stmt);
    }
}

class DbResult {
    private $db;
    private $stmt;
    private $fetched = false;
    private $allRows = null;

    public $num_rows = 0;

    public function __construct($db, $stmt) {
        $this->db = $db;
        $this->stmt = $stmt;
        if ($stmt) {
            // rowCount() is unreliable for SELECT on some drivers
            // Only use it for INSERT/UPDATE/DELETE
            $this->num_rows = $stmt->rowCount();
            if ($this->num_rows == 0 && $stmt->columnCount() > 0) {
                // It's a SELECT — eagerly fetch to get count
                $this->allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $this->num_rows = count($this->allRows);
            }
        }
    }

    public function fetch_assoc() {
        if ($this->allRows !== null) {
            return array_shift($this->allRows);
        }
        return $this->stmt ? $this->stmt->fetch(PDO::FETCH_ASSOC) : null;
    }

    public function fetch_row() {
        $row = $this->fetch_assoc();
        return $row ? array_values($row) : null;
    }

    public function fetch_all($mode = MYSQLI_ASSOC) {
        if ($this->allRows !== null) {
            $rows = $this->allRows;
            $this->allRows = null;
            return $rows;
        }
        return $this->stmt ? $this->stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
