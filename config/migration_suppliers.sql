-- Suppliers Migration
-- Standalone supplier directory (no product link yet).
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    company VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(120) NULL,
    address TEXT NULL,
    note TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_status (status),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB;

-- Make the 'suppliers' permission available to assignable roles.
-- Admin/owner bypass the table entirely, so this only matters for other roles.
INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES ('admin', 'suppliers');
