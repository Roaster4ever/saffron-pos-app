-- ============================================================
-- SAFFRON POS — Complete Database Schema (PostgreSQL)
-- Compatible with Neon / Vercel Postgres
-- ============================================================

-- 1. CATEGORIES
CREATE TABLE IF NOT EXISTS categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. BRANDS
CREATE TABLE IF NOT EXISTS brands (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. UNITS
CREATE TABLE IF NOT EXISTS units (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    short_name VARCHAR(20) NOT NULL,
    allows_decimal BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. PRODUCTS
CREATE TABLE IF NOT EXISTS products (
    id SERIAL PRIMARY KEY,
    category_id INT REFERENCES categories(id) ON DELETE SET NULL,
    brand_id INT REFERENCES brands(id) ON DELETE SET NULL,
    name VARCHAR(200) NOT NULL,
    sku VARCHAR(100) UNIQUE,
    barcode VARCHAR(100),
    model VARCHAR(100),
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    min_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    contractor_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stock DECIMAL(12,3) NOT NULL DEFAULT 0,
    reserved_stock DECIMAL(12,3) NOT NULL DEFAULT 0,
    low_stock_alert DECIMAL(12,3) DEFAULT 5,
    taxable BOOLEAN NOT NULL DEFAULT TRUE,
    gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
    tax_mode VARCHAR(20) DEFAULT 'default' CHECK (tax_mode IN ('default','non_taxable','custom')),
    unit_id INT REFERENCES units(id) ON DELETE SET NULL,
    selling_mode VARCHAR(10) DEFAULT 'fixed' CHECK (selling_mode IN ('fixed','measured')),
    standard_lengths TEXT NULL,
    default_qty DECIMAL(10,2) NULL,
    image VARCHAR(255),
    description TEXT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. PRICE LISTS
CREATE TABLE IF NOT EXISTS price_lists (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. PRODUCT PRICES
CREATE TABLE IF NOT EXISTS product_prices (
    id SERIAL PRIMARY KEY,
    product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    price_list_id INT NOT NULL REFERENCES price_lists(id) ON DELETE CASCADE,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (product_id, price_list_id)
);

-- 7. SPEC DEFINITIONS
CREATE TABLE IF NOT EXISTS spec_definitions (
    id SERIAL PRIMARY KEY,
    category_id INT REFERENCES categories(id) ON DELETE SET NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. PRODUCT SPECS
CREATE TABLE IF NOT EXISTS product_specs (
    id SERIAL PRIMARY KEY,
    product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    spec_def_id INT NOT NULL REFERENCES spec_definitions(id) ON DELETE CASCADE,
    value VARCHAR(255) NOT NULL,
    UNIQUE (product_id, spec_def_id)
);

-- 9. CUSTOMERS (Legacy)
CREATE TABLE IF NOT EXISTS customers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(200),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 10. CUSTOMERS V2
CREATE TABLE IF NOT EXISTS customers_v2 (
    id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'individual' CHECK (type IN ('individual','contractor','builder','architect','dealer','company')),
    phone VARCHAR(20),
    whatsapp VARCHAR(20),
    email VARCHAR(200),
    address TEXT,
    city VARCHAR(100),
    credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 11. CUSTOMER LEDGER
CREATE TABLE IF NOT EXISTS customer_ledger (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL REFERENCES customers_v2(id) ON DELETE CASCADE,
    type VARCHAR(20) NOT NULL CHECK (type IN ('invoice','payment','credit','adjustment','refund','opening')),
    reference_type VARCHAR(50) NULL,
    reference_id INT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note TEXT,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ledger_customer ON customer_ledger(customer_id);
CREATE INDEX IF NOT EXISTS idx_ledger_type ON customer_ledger(type);
CREATE INDEX IF NOT EXISTS idx_ledger_date ON customer_ledger(created_at);

-- 12. CUSTOMER PAYMENTS
CREATE TABLE IF NOT EXISTS customer_payments (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL REFERENCES customers_v2(id) ON DELETE CASCADE,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' CHECK (payment_method IN ('cash','card','mobile','bank_transfer','cheque')),
    reference VARCHAR(100) NULL,
    note TEXT,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cpayment_customer ON customer_payments(customer_id);

-- 13. USERS
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(10) DEFAULT 'cashier' CHECK (role IN ('admin','cashier')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 14. SALES
CREATE TABLE IF NOT EXISTS sales (
    id SERIAL PRIMARY KEY,
    invoice_no VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT,
    customer_v2_id INT,
    project_id INT,
    quotation_id INT,
    user_id INT,
    delivery_address TEXT,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    change_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    outstanding DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(20) DEFAULT 'cash' CHECK (payment_method IN ('cash','card','mobile','credit','bank_transfer','cheque')),
    status VARCHAR(10) DEFAULT 'completed' CHECK (status IN ('completed','refunded')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 15. SALE ITEMS
CREATE TABLE IF NOT EXISTS sale_items (
    id SERIAL PRIMARY KEY,
    sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
    product_id INT REFERENCES products(id) ON DELETE SET NULL,
    product_name VARCHAR(200) NOT NULL,
    qty DECIMAL(12,3) NOT NULL DEFAULT 1,
    unit VARCHAR(20) DEFAULT 'pc',
    price DECIMAL(10,2) NOT NULL,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00
);

-- 16. PROJECTS
CREATE TABLE IF NOT EXISTS projects (
    id SERIAL PRIMARY KEY,
    customer_id INT REFERENCES customers_v2(id) ON DELETE SET NULL,
    name VARCHAR(200) NOT NULL,
    location VARCHAR(255),
    description TEXT,
    status VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','completed','cancelled')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 17. QUOTATIONS
CREATE TABLE IF NOT EXISTS quotations (
    id SERIAL PRIMARY KEY,
    quotation_no VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT REFERENCES customers_v2(id) ON DELETE SET NULL,
    project_id INT REFERENCES projects(id) ON DELETE SET NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','sent','approved','rejected','converted','cancelled')),
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    terms TEXT,
    valid_until DATE,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 18. QUOTATION ITEMS
CREATE TABLE IF NOT EXISTS quotation_items (
    id SERIAL PRIMARY KEY,
    quotation_id INT NOT NULL REFERENCES quotations(id) ON DELETE CASCADE,
    product_id INT REFERENCES products(id) ON DELETE SET NULL,
    product_name VARCHAR(200) NOT NULL,
    qty DECIMAL(12,3) NOT NULL DEFAULT 1,
    unit VARCHAR(20) DEFAULT 'pc',
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00
);

-- 19. STOCK RESERVATIONS
CREATE TABLE IF NOT EXISTS stock_reservations (
    id SERIAL PRIMARY KEY,
    product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    qty DECIMAL(12,3) NOT NULL,
    reference_type VARCHAR(50) NOT NULL,
    reference_id INT NOT NULL,
    customer_id INT REFERENCES customers_v2(id) ON DELETE SET NULL,
    project_id INT REFERENCES projects(id) ON DELETE SET NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','fulfilled','cancelled')),
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_reservation_product ON stock_reservations(product_id);
CREATE INDEX IF NOT EXISTS idx_reservation_status ON stock_reservations(status);

-- 20. DELIVERIES
CREATE TABLE IF NOT EXISTS deliveries (
    id SERIAL PRIMARY KEY,
    delivery_no VARCHAR(50) UNIQUE NOT NULL,
    sale_id INT REFERENCES sales(id) ON DELETE SET NULL,
    quotation_id INT REFERENCES quotations(id) ON DELETE SET NULL,
    customer_id INT REFERENCES customers_v2(id) ON DELETE SET NULL,
    project_id INT REFERENCES projects(id) ON DELETE SET NULL,
    delivery_address TEXT,
    delivery_date DATE,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','partially_delivered','delivered','cancelled')),
    notes TEXT,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 21. DELIVERY ITEMS
CREATE TABLE IF NOT EXISTS delivery_items (
    id SERIAL PRIMARY KEY,
    delivery_id INT NOT NULL REFERENCES deliveries(id) ON DELETE CASCADE,
    product_id INT REFERENCES products(id) ON DELETE SET NULL,
    product_name VARCHAR(200) NOT NULL,
    qty_ordered DECIMAL(12,3) NOT NULL DEFAULT 0,
    qty_delivered DECIMAL(12,3) NOT NULL DEFAULT 0
);

-- 22. EXPENSE CATEGORIES
CREATE TABLE IF NOT EXISTS expense_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 23. EXPENSES
CREATE TABLE IF NOT EXISTS expenses (
    id SERIAL PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    category_id INT REFERENCES expense_categories(id) ON DELETE SET NULL,
    note TEXT,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    expense_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 24. SUPPLIERS
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    contact VARCHAR(100),
    email VARCHAR(200),
    payment_terms VARCHAR(100),
    credit_limit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 25. ORDERS
CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    order_no VARCHAR(50) UNIQUE NOT NULL,
    supplier_id INT REFERENCES suppliers(supplier_id) ON DELETE SET NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(10) DEFAULT 'pending' CHECK (status IN ('pending','received','cancelled')),
    note TEXT,
    ordered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    received_at TIMESTAMP NULL
);

-- 26. ORDER ITEMS
CREATE TABLE IF NOT EXISTS order_items (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id INT REFERENCES products(id) ON DELETE SET NULL,
    product_name VARCHAR(200) NOT NULL,
    qty DECIMAL(12,3) NOT NULL DEFAULT 1,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00
);

-- 27. USER SESSIONS
CREATE TABLE IF NOT EXISTS user_sessions (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id),
    username VARCHAR(100) NOT NULL,
    sign_in TIMESTAMP NOT NULL,
    sign_out TIMESTAMP NULL,
    duration VARCHAR(20) NULL,
    ip_address VARCHAR(45) NULL
);

-- 28. INVENTORY LOG
CREATE TABLE IF NOT EXISTS inventory_log (
    id SERIAL PRIMARY KEY,
    product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    qty_added DECIMAL(12,3) NOT NULL DEFAULT 0,
    type VARCHAR(20) DEFAULT 'stock_in' CHECK (type IN ('stock_in','adjustment','initial','sale','refund','stock_out')),
    reason VARCHAR(255) NULL,
    supplier VARCHAR(255) NULL,
    unit_cost DECIMAL(10,2) NULL,
    reference VARCHAR(255) NULL,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_inventory_log_product ON inventory_log(product_id);
CREATE INDEX IF NOT EXISTS idx_inventory_log_date ON inventory_log(created_at);
CREATE INDEX IF NOT EXISTS idx_inventory_log_type ON inventory_log(type);

-- 29. AUDIT LOG
CREATE TABLE IF NOT EXISTS audit_log (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT,
    details JSONB,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_date ON audit_log(created_at);

-- 30. SETTINGS
CREATE TABLE IF NOT EXISTS settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type VARCHAR(10) NOT NULL DEFAULT 'string' CHECK (setting_type IN ('string','integer','decimal','boolean','json')),
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 31. APP SESSIONS
CREATE TABLE IF NOT EXISTS app_sessions (
    id VARCHAR(128) PRIMARY KEY,
    data TEXT,
    expires INT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_expires ON app_sessions(expires);

-- 32. LOGIN ATTEMPTS
CREATE TABLE IF NOT EXISTS login_attempts (
    ip_address VARCHAR(45) PRIMARY KEY,
    attempt_count INT NOT NULL DEFAULT 1,
    first_attempt INT NOT NULL
);

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO users (name, username, password, role) VALUES
('Administrator', 'admin', '$2y$12$sf3prhmofN7IWyZaaPkDeOP1CgVrFzjpHDfpazGXD9C6SnipDkB/q', 'admin'),
('Cashier One', 'cashier', '$2y$12$sf3prhmofN7IWyZaaPkDeOP1CgVrFzjpHDfpazGXD9C6SnipDkB/q', 'cashier');

INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('shop_name', 'Saffron Sanitary', 'string', 'Shop name displayed on invoices and reports'),
('shop_address', 'Main Market, Lahore', 'string', 'Shop address'),
('shop_phone', '0321-1234567', 'string', 'Shop phone number'),
('shop_whatsapp', '0321-1234567', 'string', 'WhatsApp number'),
('shop_email', 'info@saffronpos.com', 'string', 'Email address'),
('currency', 'Rs: ', 'string', 'Currency prefix'),
('default_tax_rate', '18', 'decimal', 'Default GST rate percentage'),
('invoice_footer', 'Thank you for your business!', 'string', 'Footer text on invoices'),
('quotation_footer', 'This quotation is valid for 15 days.', 'string', 'Footer text on quotations'),
('low_stock_default', '5', 'integer', 'Default low stock alert threshold'),
('quotation_validity_days', '15', 'integer', 'Default validity period for quotations');
