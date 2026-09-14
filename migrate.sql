-- Run this in phpMyAdmin → pos_db → SQL tab
-- NOTE: The mfg/exp columns and inventory_log table were already created previously.
-- Only run the NEW migrations below:

USE pos_db;

-- Add user_id to sales (track which cashier made each sale)
-- Safe to run multiple times: ignores if column already exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='user_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE sales ADD COLUMN user_id INT NULL AFTER customer_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add type and reason columns to inventory_log for stock adjustments
SET @col_exists2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='inventory_log' AND COLUMN_NAME='type');
SET @sql2 = IF(@col_exists2 = 0, 'ALTER TABLE inventory_log ADD COLUMN type ENUM(\'stock_in\',\'adjustment\',\'initial\') DEFAULT \'stock_in\' AFTER qty_added', 'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET @col_exists3 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='inventory_log' AND COLUMN_NAME='reason');
SET @sql3 = IF(@col_exists3 = 0, 'ALTER TABLE inventory_log ADD COLUMN reason VARCHAR(255) NULL AFTER note', 'SELECT 1');
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

-- Session tracking table
CREATE TABLE IF NOT EXISTS user_sessions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    username    VARCHAR(100) NOT NULL,
    sign_in     DATETIME NOT NULL,
    sign_out    DATETIME NULL,
    duration    VARCHAR(20) NULL,
    ip_address  VARCHAR(45) NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Suppliers
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id   INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    contact       VARCHAR(100),
    email         VARCHAR(200),
    payment_terms VARCHAR(100),
    credit_limit  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Orders
CREATE TABLE IF NOT EXISTS orders (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    order_no      VARCHAR(50) UNIQUE NOT NULL,
    supplier_id   INT,
    total         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('pending','received','cancelled') DEFAULT 'pending',
    note          TEXT,
    ordered_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    received_at   DATETIME NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL
);

-- Order Items
CREATE TABLE IF NOT EXISTS order_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    order_id      INT NOT NULL,
    product_id    INT,
    product_name  VARCHAR(200) NOT NULL,
    qty           INT NOT NULL DEFAULT 1,
    cost          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- GST/Tax columns for products
SET @col_taxable = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='taxable');
SET @sql_taxable = IF(@col_taxable = 0, 'ALTER TABLE products ADD COLUMN taxable TINYINT(1) NOT NULL DEFAULT 1 AFTER low_stock_alert', 'SELECT 1');
PREPARE stmt_t FROM @sql_taxable; EXECUTE stmt_t; DEALLOCATE PREPARE stmt_t;

SET @col_gst = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='gst_rate');
SET @sql_gst = IF(@col_gst = 0, 'ALTER TABLE products ADD COLUMN gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00 AFTER taxable', 'SELECT 1');
PREPARE stmt_g FROM @sql_gst; EXECUTE stmt_g; DEALLOCATE PREPARE stmt_g;

-- GST/Tax columns for sale_items
SET @col_sitax = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sale_items' AND COLUMN_NAME='tax_amount');
SET @sql_sitax = IF(@col_sitax = 0, 'ALTER TABLE sale_items ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total', 'SELECT 1');
PREPARE stmt_st FROM @sql_sitax; EXECUTE stmt_st; DEALLOCATE PREPARE stmt_st;

SET @col_sigr = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sale_items' AND COLUMN_NAME='gst_rate');
SET @sql_sigr = IF(@col_sigr = 0, 'ALTER TABLE sale_items ADD COLUMN gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER tax_amount', 'SELECT 1');
PREPARE stmt_sg FROM @sql_sigr; EXECUTE stmt_sg; DEALLOCATE PREPARE stmt_sg;
