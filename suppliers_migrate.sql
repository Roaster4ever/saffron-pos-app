-- Run in phpMyAdmin → pos_db → SQL tab
USE pos_db;

CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id   INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    contact       VARCHAR(100),
    email         VARCHAR(200),
    payment_terms VARCHAR(200),
    credit_limit  DECIMAL(10,2) DEFAULT 0.00,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    order_no      VARCHAR(50) UNIQUE NOT NULL,
    supplier_id   INT,
    status        ENUM('pending','received','cancelled') DEFAULT 'pending',
    total         DECIMAL(10,2) DEFAULT 0.00,
    note          TEXT,
    ordered_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    received_at   TIMESTAMP NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    order_id    INT NOT NULL,
    product_id  INT,
    product_name VARCHAR(200) NOT NULL,
    qty         INT NOT NULL DEFAULT 1,
    cost        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- Sample suppliers
INSERT IGNORE INTO suppliers (name,contact,email,payment_terms,credit_limit) VALUES
('General Traders','0300-1111111','gt@email.com','Net 30',50000.00),
('City Wholesale','0321-2222222','cw@email.com','Net 15',30000.00);
