-- Fix for missing column in products table
ALTER TABLE products ADD COLUMN comment TEXT NULL AFTER description;

-- Fix for missing staff tables required by the dashboard
CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    store_id INT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NULL,
    designation VARCHAR(100) NULL,
    salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    salary_type ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly',
    status ENUM('active','inactive') DEFAULT 'active',
    email VARCHAR(100) NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    owner_id INT NOT NULL,
    store_id INT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    note VARCHAR(255) NULL,
    created_by INT NULL,
    working_days INT NULL,
    total_days INT NULL,
    daily_rate DECIMAL(12,2) NULL,
    earned_amount DECIMAL(12,2) NULL,
    bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
    advance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_staff (staff_id),
    INDEX idx_date (payment_date)
) ENGINE=InnoDB;

-- Fix for missing cashbook tables required by the dashboard
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_name (owner_id, name),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cashbook_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    store_id INT NULL,
    user_id INT NOT NULL,
    type ENUM('cash_in','cash_out') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    note VARCHAR(255) NULL,
    category_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_id),
    INDEX idx_store (store_id),
    INDEX idx_created (created_at),
    INDEX idx_category (category_id)
) ENGINE=InnoDB;
