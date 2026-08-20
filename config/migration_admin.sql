-- SQL Migration: Create Koro Admin User
-- Run this in phpMyAdmin or MySQL CLI
-- Default password: admin123

-- Insert Koro admin user
-- Password hash is for 'admin123'
INSERT IGNORE INTO users (name, email, password, role, status) VALUES
('Koro', 'koro@pos.com', '$2y$10$n6hnA4uPhMIxkwDv0FZPpO/NK.CZo/si6Aos3MtIj9DvVuupKLBae', 'admin', 'active');

-- Get the user ID and set owner_id to self
UPDATE users SET owner_id = id WHERE email = 'koro@pos.com' AND owner_id IS NULL;

-- Create store for Koro
INSERT IGNORE INTO stores (name, status, owner_id)
SELECT 'Koro Store', 'active', id FROM users WHERE email = 'koro@pos.com'
ON DUPLICATE KEY UPDATE name = name;

-- Update user with store_id
UPDATE users u
JOIN stores s ON s.owner_id = u.id
SET u.store_id = s.id
WHERE u.email = 'koro@pos.com' AND u.store_id IS NULL;
