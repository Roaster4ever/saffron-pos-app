-- POS Suite Database
CREATE DATABASE IF NOT EXISTS pos_db;
USE pos_db;

-- Categories
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Products
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    name VARCHAR(200) NOT NULL,
    barcode VARCHAR(100),
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    low_stock_alert INT DEFAULT 5,
    taxable TINYINT(1) NOT NULL DEFAULT 1,
    gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Customers (legacy, kept for data integrity)
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(200),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sales
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT,
    user_id INT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    change_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash','card','mobile') DEFAULT 'cash',
    status ENUM('completed','refunded') DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

-- Sale Items
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(200) NOT NULL,
    qty INT NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- Users
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','cashier') DEFAULT 'cashier',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Expenses
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Inventory log
CREATE TABLE IF NOT EXISTS inventory_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT NOT NULL,
    qty_added   INT NOT NULL DEFAULT 0,
    type        ENUM('stock_in','adjustment','initial') DEFAULT 'stock_in',
    reason      VARCHAR(255) NULL,
    note        VARCHAR(255),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- User sessions
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

-- Insert sample data
INSERT INTO categories (name) VALUES ('Beverages'),('Snacks'),('Electronics'),('Clothing'),('Food');

INSERT INTO products (category_id,name,barcode,price,cost,stock) VALUES
(1,'Coca Cola 500ml','111001',1.50,0.80,100),
(1,'Mineral Water','111002',0.75,0.30,200),
(1,'Orange Juice','111003',2.00,1.00,80),
(2,'Chips (Lays)','222001',1.25,0.60,150),
(2,'Chocolate Bar','222002',1.75,0.90,120),
(3,'USB Cable','333001',5.99,2.50,50),
(3,'Phone Charger','333002',12.99,6.00,30),
(4,'T-Shirt','444001',15.99,8.00,60),
(5,'Bread Loaf','555001',2.50,1.20,40),
(5,'Butter 200g','555002',3.25,1.80,35);

INSERT INTO customers (name,phone,email) VALUES
('Walk-in Customer','',''),
('Ahmed Khan','0300-1234567','ahmed@email.com'),
('Sara Ali','0321-9876543','sara@email.com');

-- Default admin user (set during first-run setup)
INSERT INTO users (name,username,password,role) VALUES
('Administrator','admin','$2y$12$sf3prhmofN7IWyZaaPkDeOP1CgVrFzjpHDfpazGXD9C6SnipDkB/q','admin'),
('Cashier One','cashier','$2y$12$sf3prhmofN7IWyZaaPkDeOP1CgVrFzjpHDfpazGXD9C6SnipDkB/q','cashier');

-- Login rate limiting (DB-based for serverless)
CREATE TABLE IF NOT EXISTS login_attempts (
    ip_address VARCHAR(45) PRIMARY KEY,
    attempt_count INT NOT NULL DEFAULT 1,
    first_attempt INT NOT NULL
);

-- Session storage (DB-based for serverless)
CREATE TABLE IF NOT EXISTS app_sessions (
    id VARCHAR(128) PRIMARY KEY,
    data MEDIUMTEXT,
    expires INT NOT NULL,
    INDEX idx_expires (expires)
);
