-- ============================================================
-- Super Admin Setup Migration
-- Run this ONCE in phpMyAdmin → SQL tab
--
-- Super Admin Login:
--   Portal   : http://localhost/smart/admin/login.php
--   Email    : admin@pos.com
--   Password : Admin@123
-- ============================================================

-- Step 1: Fix koro account — set owner_id = id (becomes tenant admin, not super admin)
-- koro will now login via /auth/login.php with their subscription plan
UPDATE users
SET owner_id = id
WHERE email = 'koro@pos.com' AND role = 'admin';

-- Step 2: Create the true Super Admin (owner_id = NULL = platform level)
-- Password: Admin@123
INSERT INTO users (name, email, password, role, status, store_id, owner_id)
VALUES (
    'Super Admin',
    'admin@pos.com',
    '$2y$10$WAdtAHPKjQiC91IvooWNWuMZg/0d8fxqVWswO8398Niavqu.2lgAq',
    'admin',
    'active',
    NULL,
    NULL
)
ON DUPLICATE KEY UPDATE
    name     = 'Super Admin',
    password = '$2y$10$WAdtAHPKjQiC91IvooWNWuMZg/0d8fxqVWswO8398Niavqu.2lgAq',
    owner_id = NULL,
    status   = 'active';

-- Step 3: Verify result
SELECT
    id,
    name,
    email,
    role,
    status,
    owner_id,
    CASE
        WHEN owner_id IS NULL THEN 'SUPER ADMIN - Full Access'
        WHEN owner_id = id    THEN 'Tenant Admin - Plan Gated'
        ELSE                       'Staff / User'
    END AS account_type
FROM users
WHERE role = 'admin'
ORDER BY id;
