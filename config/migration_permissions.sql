-- Role Permissions Migration
-- Run this once to add permission system

-- Role Permissions Table: stores which pages/features each role can access
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_slug VARCHAR(100) NOT NULL,
    permission VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_perm (role_slug, permission),
    INDEX idx_role_slug (role_slug),
    INDEX idx_permission (permission)
) ENGINE=InnoDB;

-- Default admin permissions (full access)
INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES
('admin', 'dashboard'),
('admin', 'pos'),
('admin', 'products'),
('admin', 'categories'),
('admin', 'stock'),
('admin', 'sales'),
('admin', 'sales_delete'),
('admin', 'returns'),
('admin', 'reports'),
('admin', 'customers'),
('admin', 'users'),
('admin', 'stores'),
('admin', 'roles'),
('admin', 'settings'),
('admin', 'transfers'),
('admin', 'vouchers'),
('admin', 'barcode_settings');

-- Default cashier permissions (limited access)
INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES
('cashier', 'pos'),
('cashier', 'sales'),
('cashier', 'customers');
