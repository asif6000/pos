-- POS System Database Schema
-- For Small & Medium Businesses in Bangladesh
-- Version 1.0.0

-- 1. Stores Table
CREATE TABLE IF NOT EXISTS stores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address TEXT,
    phone VARCHAR(20),
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- 2. Categories Table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_status (status),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- 3. Products Table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    barcode VARCHAR(50) UNIQUE,
    category_id INT,
    buy_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    sell_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    min_stock INT NOT NULL DEFAULT 10,
    unit VARCHAR(20) DEFAULT 'piece',
    description TEXT,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_name (name),
    INDEX idx_barcode (barcode),
    INDEX idx_category (category_id),
    INDEX idx_status (status),
    INDEX idx_stock (stock),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- 4. Roles Table
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 5. Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'cashier',
    store_id INT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_store (store_id),
    INDEX idx_status (status),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- 6. Store Stocks Table
CREATE TABLE IF NOT EXISTS store_stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY idx_store_product (store_id, product_id)
) ENGINE=InnoDB;

-- 7. Transfers Table
CREATE TABLE IF NOT EXISTS transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(50) NOT NULL UNIQUE,
    from_store_id INT NOT NULL,
    to_store_id INT NOT NULL,
    status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    note TEXT,
    created_by INT NOT NULL,
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (from_store_id) REFERENCES stores(id) ON DELETE RESTRICT,
    FOREIGN KEY (to_store_id) REFERENCES stores(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- 8. Transfer Items Table
CREATE TABLE IF NOT EXISTS transfer_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transfer_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    FOREIGN KEY (transfer_id) REFERENCES transfers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 9. Customers Table
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_phone (phone),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- 10. Sales Table
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT,
    user_id INT NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    discount_percent DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
    vat_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    vat_percent DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    change_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash', 'bkash', 'nagad', 'rocket', 'card', 'bank') NOT NULL DEFAULT 'cash',
    payment_status ENUM('paid', 'partial', 'unpaid') NOT NULL DEFAULT 'paid',
    note TEXT,
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_invoice (invoice_number),
    INDEX idx_customer (customer_id),
    INDEX idx_user (user_id),
    INDEX idx_payment_method (payment_method),
    INDEX idx_created_at (created_at),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- 11. Sale Items Table
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    total_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_sale (sale_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB;

-- 12. Settings Table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT,
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_owner_key (owner_id, setting_key),
    INDEX idx_key (setting_key),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- 13. Stock History Table
CREATE TABLE IF NOT EXISTS stock_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity_change INT NOT NULL,
    type ENUM('purchase', 'sale', 'adjustment', 'return', 'transfer', 'sale_delete') NOT NULL,
    reference_id INT,
    note TEXT,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_product (product_id),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- 14. Returns Table
CREATE TABLE IF NOT EXISTS returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(50) NOT NULL UNIQUE,
    sale_id INT NOT NULL,
    user_id INT NOT NULL,
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    refund_method ENUM('cash', 'bkash', 'nagad', 'rocket', 'card', 'bank', 'store_credit') NOT NULL DEFAULT 'cash',
    reason TEXT,
    status ENUM('pending', 'approved', 'completed', 'rejected') NOT NULL DEFAULT 'completed',
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_return_number (return_number),
    INDEX idx_sale (sale_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- 16. Return Items Table
CREATE TABLE IF NOT EXISTS return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    total_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_return (return_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB;

-- 17. Role Permissions Table
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_slug VARCHAR(100) NOT NULL,
    permission VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_perm (role_slug, permission),
    INDEX idx_role_slug (role_slug),
    INDEX idx_permission (permission)
) ENGINE=InnoDB;

-- 18. System Settings Table (global, not per-owner)
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('permissions_version', '1');
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('plan_modules_version', '1');

-- Data Inserts
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('shop_name', 'My POS Shop'),
('shop_address', 'Dhaka, Bangladesh'),
('shop_phone', '+880 1XXX-XXXXXX'),
('shop_email', 'shop@example.com'),
('currency', 'BDT'),
('currency_symbol', '৳'),
('vat_percent', '0'),
('low_stock_threshold', '10'),
('invoice_prefix', 'INV'),
('receipt_footer', 'Thank you for shopping with us!');

INSERT IGNORE INTO categories (name, description, status) VALUES
('General', 'General products', 'active'),
('Electronics', 'Electronic items', 'active'),
('Grocery', 'Grocery items', 'active'),
('Clothing', 'Clothing items', 'active'),
('Beverages', 'Drinks and beverages', 'active');

INSERT IGNORE INTO products (name, barcode, category_id, buy_price, sell_price, stock, min_stock, unit) VALUES
('Coca Cola 500ml', '5449000000996', 5, 35.00, 45.00, 100, 20, 'piece'),
('Pran Mango Juice 1L', '8901764012345', 5, 80.00, 100.00, 50, 10, 'piece'),
('Rice (Miniket) 5kg', '8801234567890', 3, 400.00, 450.00, 30, 5, 'bag'),
('Sugar 1kg', '8801234567891', 3, 80.00, 95.00, 40, 10, 'pack'),
('Salt (Iodized) 1kg', '8801234567892', 3, 25.00, 35.00, 60, 15, 'pack');

INSERT IGNORE INTO customers (name, phone, address) VALUES
('Walk-in Customer', 'N/A', 'N/A');

-- Default Roles
INSERT IGNORE INTO roles (name, slug, description, status) VALUES
('Admin', 'admin', 'Full system access', 'active'),
('Cashier', 'cashier', 'POS and sales access', 'active');

-- Default Role Permissions
INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES
('admin', 'dashboard'), ('admin', 'pos'), ('admin', 'products'),
('admin', 'categories'), ('admin', 'stock'), ('admin', 'transfers'),
('admin', 'sales'), ('admin', 'sales_delete'), ('admin', 'returns'),
('admin', 'reports'), ('admin', 'customers'), ('admin', 'users'),
('admin', 'stores'), ('admin', 'roles'), ('admin', 'settings'),
('admin', 'barcode_settings'), ('admin', 'vouchers'),
('cashier', 'pos'), ('cashier', 'sales'), ('cashier', 'customers');
