-- ============================================================
-- SAFFRON POS — Phase 1: Data Foundation Migration
-- Safe migration: adds new tables, alters existing ones
-- Does NOT drop any existing tables or data
-- ============================================================

USE pos_db;

-- ============================================================
-- 1. BRANDS
-- ============================================================
CREATE TABLE IF NOT EXISTS brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 2. UNITS
-- ============================================================
CREATE TABLE IF NOT EXISTS units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    short_name VARCHAR(20) NOT NULL,
    allows_decimal TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 3. PRICE LISTS
-- ============================================================
CREATE TABLE IF NOT EXISTS price_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 4. PRODUCT PRICES (per product, per price list)
-- ============================================================
CREATE TABLE IF NOT EXISTS product_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    price_list_id INT NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_pricelist (product_id, price_list_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 5. PRODUCT SPECIFICATION DEFINITIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS spec_definitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 6. PRODUCT SPECIFICATION VALUES
-- ============================================================
CREATE TABLE IF NOT EXISTS product_specs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    spec_def_id INT NOT NULL,
    value VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_product_spec (product_id, spec_def_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (spec_def_id) REFERENCES spec_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 7. CUSTOMERS (upgraded)
-- ============================================================
CREATE TABLE IF NOT EXISTS customers_v2 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    type ENUM('individual','contractor','builder','architect','dealer','company') NOT NULL DEFAULT 'individual',
    phone VARCHAR(20),
    whatsapp VARCHAR(20),
    email VARCHAR(200),
    address TEXT,
    city VARCHAR(100),
    credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 8. CUSTOMER LEDGER
-- ============================================================
CREATE TABLE IF NOT EXISTS customer_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    type ENUM('invoice','payment','credit','adjustment','refund','opening') NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note TEXT,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ledger_customer (customer_id),
    INDEX idx_ledger_type (type),
    INDEX idx_ledger_date (created_at),
    FOREIGN KEY (customer_id) REFERENCES customers_v2(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 9. PROJECTS
-- ============================================================
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT,
    name VARCHAR(200) NOT NULL,
    location VARCHAR(255),
    description TEXT,
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers_v2(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 10. QUOTATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS quotations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quotation_no VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT,
    project_id INT,
    status ENUM('draft','sent','approved','rejected','converted','cancelled') NOT NULL DEFAULT 'draft',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    terms TEXT,
    valid_until DATE,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers_v2(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 11. QUOTATION ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS quotation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quotation_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(200) NOT NULL,
    qty DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit VARCHAR(20) DEFAULT 'pc',
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 12. STOCK RESERVATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS stock_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    reference_type VARCHAR(50) NOT NULL,
    reference_id INT NOT NULL,
    customer_id INT,
    project_id INT,
    status ENUM('active','fulfilled','cancelled') NOT NULL DEFAULT 'active',
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reservation_product (product_id),
    INDEX idx_reservation_status (status),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers_v2(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 13. DELIVERIES
-- ============================================================
CREATE TABLE IF NOT EXISTS deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_no VARCHAR(50) UNIQUE NOT NULL,
    sale_id INT,
    quotation_id INT,
    customer_id INT,
    project_id INT,
    delivery_address TEXT,
    delivery_date DATE,
    status ENUM('pending','partially_delivered','delivered','cancelled') NOT NULL DEFAULT 'pending',
    notes TEXT,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers_v2(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 14. DELIVERY ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS delivery_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(200) NOT NULL,
    qty_ordered DECIMAL(10,2) NOT NULL DEFAULT 0,
    qty_delivered DECIMAL(10,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 15. AUDIT LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT,
    details JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_date (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 16. SETTINGS (key-value store for app configuration)
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string','integer','decimal','boolean','json') NOT NULL DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 17. EXPENSE CATEGORIES
-- ============================================================
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 18. CUSTOMER PAYMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS customer_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash','card','mobile','bank_transfer','cheque') NOT NULL DEFAULT 'cash',
    reference_no VARCHAR(100),
    note TEXT,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cpayment_customer (customer_id),
    FOREIGN KEY (customer_id) REFERENCES customers_v2(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================
-- ALTER EXISTING TABLES
-- ============================================================

-- PRODUCTS: Add new columns (safe defaults, no data loss)
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='brand_id');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN brand_id INT NULL AFTER category_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='sku');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN sku VARCHAR(100) NULL AFTER barcode', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='unit_id');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN unit_id INT NULL AFTER gst_rate', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='model');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN model VARCHAR(100) NULL AFTER sku', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='description');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN description TEXT NULL AFTER image', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='min_price');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN min_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cost', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='wholesale_price');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER min_price', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='contractor_price');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN contractor_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER wholesale_price', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='reserved_stock');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN reserved_stock INT NOT NULL DEFAULT 0 AFTER stock', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='is_active');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER description', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='updated_at');
SET @sql = IF(@col = 0, 'ALTER TABLE products ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add FK for brand_id
SET @fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='brand_id' AND REFERENCED_TABLE_NAME='brands');
SET @sql = IF(@fk = 0, 'ALTER TABLE products ADD FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add FK for unit_id
SET @fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='products' AND COLUMN_NAME='unit_id' AND REFERENCED_TABLE_NAME='units');
SET @sql = IF(@fk = 0, 'ALTER TABLE products ADD FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- SALES: Add customer, project, credit columns
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='customer_v2_id');
SET @sql = IF(@col = 0, 'ALTER TABLE sales ADD COLUMN customer_v2_id INT NULL AFTER user_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='project_id');
SET @sql = IF(@col = 0, 'ALTER TABLE sales ADD COLUMN project_id INT NULL AFTER customer_v2_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='quotation_id');
SET @sql = IF(@col = 0, 'ALTER TABLE sales ADD COLUMN quotation_id INT NULL AFTER project_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='delivery_address');
SET @sql = IF(@col = 0, 'ALTER TABLE sales ADD COLUMN delivery_address TEXT NULL AFTER quotation_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='payment_method');
-- payment_method already exists as ENUM. Extend it to include 'credit'.
SET @col2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='outstanding');
SET @sql = IF(@col2 = 0, "ALTER TABLE sales ADD COLUMN outstanding DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER change_amount", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Extend payment_method ENUM to include 'credit' and 'bank_transfer' and 'cheque'
ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash','card','mobile','credit','bank_transfer','cheque') DEFAULT 'cash';

-- SALE ITEMS: add unit column
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sale_items' AND COLUMN_NAME='unit');
SET @sql = IF(@col = 0, "ALTER TABLE sale_items ADD COLUMN unit VARCHAR(20) DEFAULT 'pc' AFTER product_name", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sale_items' AND COLUMN_NAME='discount');
SET @sql = IF(@col = 0, "ALTER TABLE sale_items ADD COLUMN discount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER gst_rate", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add FKs for sales
SET @fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='customer_v2_id' AND REFERENCED_TABLE_NAME='customers_v2');
SET @sql = IF(@fk = 0, 'ALTER TABLE sales ADD FOREIGN KEY (customer_v2_id) REFERENCES customers_v2(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='project_id' AND REFERENCED_TABLE_NAME='projects');
SET @sql = IF(@fk = 0, 'ALTER TABLE sales ADD FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='sales' AND COLUMN_NAME='quotation_id' AND REFERENCED_TABLE_NAME='quotations');
SET @sql = IF(@fk = 0, 'ALTER TABLE sales ADD FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- EXPENSES: add category and user columns
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='expenses' AND COLUMN_NAME='category_id');
SET @sql = IF(@col = 0, 'ALTER TABLE expenses ADD COLUMN category_id INT NULL AFTER title', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='expenses' AND COLUMN_NAME='user_id');
SET @sql = IF(@col = 0, 'ALTER TABLE expenses ADD COLUMN user_id INT NULL AFTER note', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='expenses' AND COLUMN_NAME='category_id' AND REFERENCED_TABLE_NAME='expense_categories');
SET @sql = IF(@fk = 0, 'ALTER TABLE expenses ADD FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='pos_db' AND TABLE_NAME='expenses' AND COLUMN_NAME='user_id' AND REFERENCED_TABLE_NAME='users');
SET @sql = IF(@fk = 0, 'ALTER TABLE expenses ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_products_sku ON products(sku);
CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode);
CREATE INDEX IF NOT EXISTS idx_products_brand ON products(brand_id);
CREATE INDEX IF NOT EXISTS idx_products_category ON products(category_id);
CREATE INDEX IF NOT EXISTS idx_products_active ON products(is_active);
CREATE INDEX IF NOT EXISTS idx_sales_customer_v2 ON sales(customer_v2_id);
CREATE INDEX IF NOT EXISTS idx_sales_project ON sales(project_id);
CREATE INDEX IF NOT EXISTS idx_sales_outstanding ON sales(outstanding);
CREATE INDEX IF NOT EXISTS idx_sale_items_product ON sale_items(product_id);
CREATE INDEX IF NOT EXISTS idx_quotations_customer ON quotations(customer_id);
CREATE INDEX IF NOT EXISTS idx_quotations_status ON quotations(status);
CREATE INDEX IF NOT EXISTS idx_quotations_no ON quotations(quotation_no);
CREATE INDEX IF NOT EXISTS idx_inventory_log_product ON inventory_log(product_id);
CREATE INDEX IF NOT EXISTS idx_inventory_log_date ON inventory_log(created_at);
CREATE INDEX IF NOT EXISTS idx_inventory_log_type ON inventory_log(type);
