<?php
/**
 * Saffron POS — Database Setup Script (CLI)
 * Run this on first install to initialize the database.
 */

$host = '127.0.0.1';
$port = 3306;
$user = 'root';
$pass = '';
$dbName = 'saffron_pos';

echo "=== Saffron POS Database Setup ===\n\n";

// Connect without database
$conn = new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "[ERROR] Cannot connect to MariaDB: " . $conn->connect_error . "\n";
    echo "Make sure MariaDB is running.\n";
    exit(1);
}

echo "[OK] Connected to MariaDB.\n";

// Create database
$conn->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db($dbName);
echo "[OK] Database '$dbName' ready.\n";

// Check if tables already exist
$result = $conn->query("SHOW TABLES LIKE 'users'");
if ($result && $result->num_rows > 0) {
    echo "[OK] Tables already exist. Skipping creation.\n";
    echo "\nDatabase is ready!\n";
    $conn->close();
    exit(0);
}

// Create all tables
echo "Creating tables...\n";

$tables = [
    // Users
    "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(100) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('admin','cashier') DEFAULT 'cashier',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Settings
    "CREATE TABLE IF NOT EXISTS `settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` TEXT,
        `setting_type` VARCHAR(20) DEFAULT 'string',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Categories
    "CREATE TABLE IF NOT EXISTS `categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Brands
    "CREATE TABLE IF NOT EXISTS `brands` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Units
    "CREATE TABLE IF NOT EXISTS `units` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL,
        `short_name` VARCHAR(20) NOT NULL,
        `allows_decimal` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Products
    "CREATE TABLE IF NOT EXISTS `products` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `category_id` INT,
        `brand_id` INT,
        `unit_id` INT,
        `name` VARCHAR(200) NOT NULL,
        `sku` VARCHAR(50),
        `barcode` VARCHAR(50),
        `model` VARCHAR(100),
        `price` DECIMAL(12,2) DEFAULT 0,
        `cost` DECIMAL(12,2) DEFAULT 0,
        `min_price` DECIMAL(12,2) DEFAULT 0,
        `wholesale_price` DECIMAL(12,2) DEFAULT 0,
        `contractor_price` DECIMAL(12,2) DEFAULT 0,
        `stock` DECIMAL(10,3) DEFAULT 0,
        `reserved_stock` DECIMAL(10,3) DEFAULT 0,
        `low_stock_alert` DECIMAL(10,3) DEFAULT 5,
        `taxable` TINYINT(1) DEFAULT 1,
        `gst_rate` DECIMAL(5,2) DEFAULT 18,
        `tax_mode` VARCHAR(20) DEFAULT 'default',
        `selling_mode` VARCHAR(20) DEFAULT 'fixed',
        `standard_lengths` VARCHAR(500),
        `default_qty` DECIMAL(10,3),
        `description` TEXT,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_sku` (`sku`),
        INDEX `idx_barcode` (`barcode`),
        INDEX `idx_category` (`category_id`),
        INDEX `idx_brand` (`brand_id`)
    ) ENGINE=InnoDB",

    // Customers v2
    "CREATE TABLE IF NOT EXISTS `customers_v2` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(200) NOT NULL,
        `type` VARCHAR(50) DEFAULT 'individual',
        `phone` VARCHAR(30),
        `whatsapp` VARCHAR(30),
        `email` VARCHAR(100),
        `address` TEXT,
        `city` VARCHAR(100),
        `credit_limit` DECIMAL(12,2) DEFAULT 0,
        `opening_balance` DECIMAL(12,2) DEFAULT 0,
        `notes` TEXT,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Sales
    "CREATE TABLE IF NOT EXISTS `sales` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_no` VARCHAR(30) NOT NULL UNIQUE,
        `customer_v2_id` INT,
        `project_id` INT,
        `quotation_id` INT,
        `user_id` INT,
        `subtotal` DECIMAL(12,2) DEFAULT 0,
        `discount` DECIMAL(12,2) DEFAULT 0,
        `tax` DECIMAL(12,2) DEFAULT 0,
        `total` DECIMAL(12,2) DEFAULT 0,
        `paid` DECIMAL(12,2) DEFAULT 0,
        `change_amount` DECIMAL(12,2) DEFAULT 0,
        `payment_method` VARCHAR(30) DEFAULT 'cash',
        `outstanding` DECIMAL(12,2) DEFAULT 0,
        `status` VARCHAR(20) DEFAULT 'completed',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_invoice` (`invoice_no`),
        INDEX `idx_customer` (`customer_v2_id`),
        INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB",

    // Sale Items
    "CREATE TABLE IF NOT EXISTS `sale_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `sale_id` INT NOT NULL,
        `product_id` INT,
        `product_name` VARCHAR(200) NOT NULL,
        `qty` DECIMAL(10,3) NOT NULL,
        `unit` VARCHAR(20) DEFAULT 'pc',
        `price` DECIMAL(12,2) NOT NULL,
        `total` DECIMAL(12,2) NOT NULL,
        `tax_amount` DECIMAL(12,2) DEFAULT 0,
        `gst_rate` DECIMAL(5,2) DEFAULT 0,
        INDEX `idx_sale` (`sale_id`),
        INDEX `idx_product` (`product_id`)
    ) ENGINE=InnoDB",

    // Customer Ledger
    "CREATE TABLE IF NOT EXISTS `customer_ledger` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `customer_id` INT NOT NULL,
        `type` VARCHAR(30) NOT NULL,
        `reference_type` VARCHAR(30),
        `reference_id` INT,
        `amount` DECIMAL(12,2) NOT NULL,
        `balance_after` DECIMAL(12,2) NOT NULL,
        `note` TEXT,
        `user_id` INT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_customer` (`customer_id`)
    ) ENGINE=InnoDB",

    // Customer Payments
    "CREATE TABLE IF NOT EXISTS `customer_payments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `customer_id` INT NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `payment_method` VARCHAR(30) DEFAULT 'cash',
        `reference_no` VARCHAR(50),
        `note` TEXT,
        `user_id` INT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_customer` (`customer_id`)
    ) ENGINE=InnoDB",

    // Inventory Log
    "CREATE TABLE IF NOT EXISTS `inventory_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_id` INT NOT NULL,
        `qty_added` DECIMAL(10,3) NOT NULL,
        `type` VARCHAR(30) NOT NULL,
        `reason` VARCHAR(100),
        `note` TEXT,
        `supplier` VARCHAR(100),
        `unit_cost` DECIMAL(12,2),
        `reference` VARCHAR(50),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_product` (`product_id`)
    ) ENGINE=InnoDB",

    // Expenses
    "CREATE TABLE IF NOT EXISTS `expenses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(200) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `category_id` INT,
        `note` TEXT,
        `user_id` INT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Expense Categories
    "CREATE TABLE IF NOT EXISTS `expense_categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Quotations
    "CREATE TABLE IF NOT EXISTS `quotations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `quotation_no` VARCHAR(30) NOT NULL UNIQUE,
        `customer_id` INT,
        `project_id` INT,
        `user_id` INT,
        `subtotal` DECIMAL(12,2) DEFAULT 0,
        `discount` DECIMAL(12,2) DEFAULT 0,
        `tax` DECIMAL(12,2) DEFAULT 0,
        `total` DECIMAL(12,2) DEFAULT 0,
        `status` VARCHAR(20) DEFAULT 'draft',
        `valid_until` DATE,
        `notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Quotation Items
    "CREATE TABLE IF NOT EXISTS `quotation_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `quotation_id` INT NOT NULL,
        `product_id` INT,
        `product_name` VARCHAR(200) NOT NULL,
        `qty` DECIMAL(10,3) NOT NULL,
        `unit` VARCHAR(20) DEFAULT 'pc',
        `price` DECIMAL(12,2) NOT NULL,
        `discount` DECIMAL(12,2) DEFAULT 0,
        `tax_amount` DECIMAL(12,2) DEFAULT 0,
        `gst_rate` DECIMAL(5,2) DEFAULT 0,
        `total` DECIMAL(12,2) NOT NULL,
        INDEX `idx_quotation` (`quotation_id`)
    ) ENGINE=InnoDB",

    // Orders
    "CREATE TABLE IF NOT EXISTS `orders` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_no` VARCHAR(30) NOT NULL UNIQUE,
        `customer_id` INT,
        `project_id` INT,
        `user_id` INT,
        `subtotal` DECIMAL(12,2) DEFAULT 0,
        `discount` DECIMAL(12,2) DEFAULT 0,
        `tax` DECIMAL(12,2) DEFAULT 0,
        `total` DECIMAL(12,2) DEFAULT 0,
        `status` VARCHAR(20) DEFAULT 'pending',
        `expected_date` DATE,
        `notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Order Items
    "CREATE TABLE IF NOT EXISTS `order_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `product_id` INT,
        `product_name` VARCHAR(200) NOT NULL,
        `qty` DECIMAL(10,3) NOT NULL,
        `unit` VARCHAR(20) DEFAULT 'pc',
        `price` DECIMAL(12,2) NOT NULL,
        `total` DECIMAL(12,2) NOT NULL,
        `tax_amount` DECIMAL(12,2) DEFAULT 0,
        `gst_rate` DECIMAL(5,2) DEFAULT 0,
        INDEX `idx_order` (`order_id`)
    ) ENGINE=InnoDB",

    // Deliveries
    "CREATE TABLE IF NOT EXISTS `deliveries` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `delivery_no` VARCHAR(30) NOT NULL UNIQUE,
        `order_id` INT,
        `customer_id` INT,
        `user_id` INT,
        `status` VARCHAR(20) DEFAULT 'pending',
        `delivery_date` DATE,
        `notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // Delivery Items
    "CREATE TABLE IF NOT EXISTS `delivery_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `delivery_id` INT NOT NULL,
        `product_id` INT,
        `product_name` VARCHAR(200) NOT NULL,
        `qty` DECIMAL(10,3) NOT NULL,
        `unit` VARCHAR(20) DEFAULT 'pc',
        `price` DECIMAL(12,2) NOT NULL,
        `total` DECIMAL(12,2) NOT NULL,
        INDEX `idx_delivery` (`delivery_id`)
    ) ENGINE=InnoDB",

    // Projects
    "CREATE TABLE IF NOT EXISTS `projects` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(200) NOT NULL,
        `customer_id` INT,
        `location` VARCHAR(200),
        `description` TEXT,
        `status` VARCHAR(20) DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",

    // POS Drafts
    "CREATE TABLE IF NOT EXISTS `pos_drafts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `draft_no` VARCHAR(30) NOT NULL,
        `user_id` INT NOT NULL,
        `customer_id` INT,
        `customer_name` VARCHAR(200),
        `subtotal` DECIMAL(12,2) DEFAULT 0,
        `discount` DECIMAL(12,2) DEFAULT 0,
        `tax` DECIMAL(12,2) DEFAULT 0,
        `total` DECIMAL(12,2) DEFAULT 0,
        `payment_method` VARCHAR(30) DEFAULT 'cash',
        `item_count` INT DEFAULT 0,
        `items_json` LONGTEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_user` (`user_id`)
    ) ENGINE=InnoDB",

    // User Sessions
    "CREATE TABLE IF NOT EXISTS `user_sessions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `username` VARCHAR(50),
        `sign_in` DATETIME NOT NULL,
        `sign_out` DATETIME,
        `duration` VARCHAR(20),
        `ip_address` VARCHAR(45),
        INDEX `idx_user` (`user_id`)
    ) ENGINE=InnoDB",

    // Login Attempts
    "CREATE TABLE IF NOT EXISTS `login_attempts` (
        `ip_address` VARCHAR(45) NOT NULL PRIMARY KEY,
        `attempt_count` INT DEFAULT 0,
        `first_attempt` INT NOT NULL
    ) ENGINE=InnoDB",

    // Audit Log
    "CREATE TABLE IF NOT EXISTS `audit_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT,
        `action` VARCHAR(50) NOT NULL,
        `entity_type` VARCHAR(50),
        `entity_id` INT,
        `details` JSON,
        `ip_address` VARCHAR(45),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_action` (`action`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB",

    // App Sessions (for DB-backed sessions on Vercel)
    "CREATE TABLE IF NOT EXISTS `app_sessions` (
        `id` VARCHAR(128) NOT NULL PRIMARY KEY,
        `data` LONGTEXT NOT NULL,
        `expires` INT(11) NOT NULL,
        INDEX `idx_expires` (`expires`)
    ) ENGINE=InnoDB",

    // Suppliers
    "CREATE TABLE IF NOT EXISTS `suppliers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(200) NOT NULL,
        `phone` VARCHAR(30),
        `email` VARCHAR(100),
        `address` TEXT,
        `city` VARCHAR(100),
        `notes` TEXT,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",
];

foreach ($tables as $sql) {
    if (!$conn->query($sql)) {
        echo "[ERROR] Failed: " . $conn->error . "\n";
    }
}
echo "[OK] All tables created.\n";

// Seed default settings
$defaults = [
    ['shop_name', 'Saffron POS', 'string'],
    ['shop_address', '', 'string'],
    ['shop_phone', '', 'string'],
    ['shop_whatsapp', '', 'string'],
    ['shop_email', '', 'string'],
    ['currency', 'Rs: ', 'string'],
    ['default_tax_rate', '18', 'decimal'],
    ['invoice_footer', 'Thank you for your business!', 'string'],
    ['quotation_footer', 'This quotation is valid for 15 days.', 'string'],
    ['low_stock_default', '5', 'integer'],
    ['quotation_validity_days', '15', 'integer'],
    ['pos_customer_profiles', '1', 'boolean'],
    ['app_initialized', '0', 'boolean'],
];

$stmt = $conn->prepare("INSERT IGNORE INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)");
foreach ($defaults as [$key, $val, $type]) {
    $stmt->bind_param("sss", $key, $val, $type);
    $stmt->execute();
}
echo "[OK] Default settings inserted.\n";

// Seed default units
$units = [
    ['Piece', 'pc', 0],
    ['Box', 'box', 0],
    ['Set', 'set', 0],
    ['Meter', 'm', 1],
    ['Foot', 'ft', 1],
    ['Kilogram', 'kg', 1],
    ['Roll', 'roll', 0],
    ['Pack', 'pack', 0],
];
$stmt = $conn->prepare("INSERT IGNORE INTO units (name, short_name, allows_decimal) VALUES (?, ?, ?)");
foreach ($units as [$name, $short, $decimal]) {
    $stmt->bind_param("ssi", $name, $short, $decimal);
    $stmt->execute();
}
echo "[OK] Default units inserted.\n";

// Seed default expense categories
$expCats = ['Rent', 'Utilities', 'Salaries', 'Transport', 'Office Supplies', 'Marketing', 'Maintenance', 'Other'];
$stmt = $conn->prepare("INSERT IGNORE INTO expense_categories (name) VALUES (?)");
foreach ($expCats as $cat) {
    $stmt->bind_param("s", $cat);
    $stmt->execute();
}
echo "[OK] Default expense categories inserted.\n";

// Seed default admin user (admin / admin123)
$adminPass = password_hash('admin123', PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT IGNORE INTO users (username, name, password, role) VALUES (?, ?, ?, ?)");
$username = 'admin';
$name = 'Administrator';
$role = 'admin';
$stmt->bind_param("ssss", $username, $name, $adminPass, $role);
$stmt->execute();
echo "[OK] Default admin user created (admin / admin123).\n";

$conn->close();

echo "\n=== Database setup complete! ===\n";
echo "You can now access Saffron POS at http://localhost:8080\n";
echo "Login: admin / admin123\n";
echo "\nIMPORTANT: Change the admin password after first login!\n";
