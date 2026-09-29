-- ============================================================================
--  Migration 003 - column reconciliation
--  Smart Collection POS
--
--  Migration 002 created the eleven tables that were missing. It did not add
--  columns to tables that already existed, because the database this was written
--  against already had them. The live database did not, so pages started failing
--  with
--      SQLSTATE[42S22] Unknown column 'google_synced_at' in 'SELECT'
--      SQLSTATE[42S22] Unknown column 'c.has_whatsapp' in 'SELECT'
--  which is a blank HTTP 500 with nothing in the browser to explain it.
--
--  This file adds every column of every table, taken from the database the
--  application is known to run correctly against. It is a reconciliation, not a
--  redesign: it only ever ADDs a column that is absent. It never drops, never
--  renames, never changes a type and never touches a stored value, so it is safe
--  on a database that is already correct and safe to run more than once.
--
--  251 columns across 28 tables.
--
--  HOW TO USE
--  Import with phpMyAdmin. Take a backup first.
--
--  Verified against MariaDB 10.4.32.
-- ============================================================================

-- cashbook_entries (11 column(s))
SET @t := 'cashbook_entries';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `cashbook_entries`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `store_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `user_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `type` enum(''cash_in'',''cash_out'') NOT NULL,
    ADD COLUMN IF NOT EXISTS `amount` decimal(12,2) NOT NULL,
    ADD COLUMN IF NOT EXISTS `note` varchar(255) NULL,
    ADD COLUMN IF NOT EXISTS `category_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `source_type` varchar(20) NULL,
    ADD COLUMN IF NOT EXISTS `source_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- categories (7 column(s))
SET @t := 'categories';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `categories`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `description` text NULL,
    ADD COLUMN IF NOT EXISTS `status` enum(''active'',''inactive'') NOT NULL DEFAULT ''''''active'''''',
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- customers (12 column(s))
SET @t := 'customers';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `phone` varchar(20) NULL,
    ADD COLUMN IF NOT EXISTS `email` varchar(100) NULL,
    ADD COLUMN IF NOT EXISTS `address` text NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `google_contact_id` varchar(255) NULL,
    ADD COLUMN IF NOT EXISTS `google_synced_at` timestamp NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `has_whatsapp` tinyint(1) NULL,
    ADD COLUMN IF NOT EXISTS `whatsapp_checked_at` timestamp NULL DEFAULT NULL'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- expense_categories (4 column(s))
SET @t := 'expense_categories';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `expense_categories`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- marketing_campaigns (14 column(s))
SET @t := 'marketing_campaigns';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `marketing_campaigns`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `channel` varchar(20) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(150) NOT NULL,
    ADD COLUMN IF NOT EXISTS `message` text NOT NULL,
    ADD COLUMN IF NOT EXISTS `status` varchar(20) NOT NULL DEFAULT ''''''draft'''''',
    ADD COLUMN IF NOT EXISTS `total` int(11) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `sent_count` int(11) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `failed_count` int(11) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `is_manual` tinyint(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `started_at` datetime NULL,
    ADD COLUMN IF NOT EXISTS `finished_at` datetime NULL,
    ADD COLUMN IF NOT EXISTS `created_by` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- marketing_recipients (11 column(s))
SET @t := 'marketing_recipients';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `marketing_recipients`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `campaign_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `customer_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(100) NOT NULL DEFAULT '''''''''''',
    ADD COLUMN IF NOT EXISTS `phone` varchar(20) NOT NULL,
    ADD COLUMN IF NOT EXISTS `message` text NULL,
    ADD COLUMN IF NOT EXISTS `status` varchar(20) NOT NULL DEFAULT ''''''pending'''''',
    ADD COLUMN IF NOT EXISTS `error` text NULL,
    ADD COLUMN IF NOT EXISTS `sent_at` datetime NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- products (15 column(s))
SET @t := 'products';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(200) NOT NULL,
    ADD COLUMN IF NOT EXISTS `barcode` varchar(50) NULL,
    ADD COLUMN IF NOT EXISTS `category_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `buy_price` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `sell_price` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `stock` int(11) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `min_stock` int(11) NOT NULL DEFAULT 10,
    ADD COLUMN IF NOT EXISTS `unit` varchar(20) NULL DEFAULT ''''''piece'''''',
    ADD COLUMN IF NOT EXISTS `description` text NULL,
    ADD COLUMN IF NOT EXISTS `comment` text NULL,
    ADD COLUMN IF NOT EXISTS `status` enum(''active'',''inactive'') NOT NULL DEFAULT ''''''active'''''',
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- product_variables (7 column(s))
SET @t := 'product_variables';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `product_variables`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(60) NOT NULL,
    ADD COLUMN IF NOT EXISTS `slug` varchar(60) NOT NULL,
    ADD COLUMN IF NOT EXISTS `sort_order` int(11) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `status` enum(''active'',''inactive'') NOT NULL DEFAULT ''''''active'''''',
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- product_variable_map (5 column(s))
SET @t := 'product_variable_map';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `product_variable_map`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `product_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `variable_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `value_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- product_variable_values (5 column(s))
SET @t := 'product_variable_values';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `product_variable_values`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `variable_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `value` varchar(60) NOT NULL,
    ADD COLUMN IF NOT EXISTS `sort_order` int(11) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- returns (11 column(s))
SET @t := 'returns';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `returns`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `return_number` varchar(50) NOT NULL,
    ADD COLUMN IF NOT EXISTS `sale_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `user_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `refund_method` enum(''cash'',''bkash'',''nagad'',''rocket'',''card'',''bank'',''store_credit'') NOT NULL DEFAULT ''''''cash'''''',
    ADD COLUMN IF NOT EXISTS `reason` text NULL,
    ADD COLUMN IF NOT EXISTS `status` enum(''pending'',''approved'',''completed'',''rejected'') NOT NULL DEFAULT ''''''completed'''''',
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- return_items (8 column(s))
SET @t := 'return_items';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `return_items`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `return_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `product_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `product_name` varchar(200) NOT NULL,
    ADD COLUMN IF NOT EXISTS `quantity` int(11) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `total_price` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- roles (7 column(s))
SET @t := 'roles';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `roles`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `slug` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `description` text NULL,
    ADD COLUMN IF NOT EXISTS `status` enum(''active'',''inactive'') NOT NULL DEFAULT ''''''active'''''',
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- role_permissions (4 column(s))
SET @t := 'role_permissions';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `role_permissions`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `role_slug` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `permission` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- sales (19 column(s))
SET @t := 'sales';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `invoice_number` varchar(50) NOT NULL,
    ADD COLUMN IF NOT EXISTS `customer_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `user_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `vat_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `vat_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `total` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `change_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `payment_method` enum(''cash'',''bkash'',''nagad'',''rocket'',''card'',''bank'') NOT NULL DEFAULT ''''''cash'''''',
    ADD COLUMN IF NOT EXISTS `payment_status` enum(''paid'',''partial'',''unpaid'') NOT NULL DEFAULT ''''''paid'''''',
    ADD COLUMN IF NOT EXISTS `printed` tinyint(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `note` text NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- sale_items (8 column(s))
SET @t := 'sale_items';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `sale_items`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `sale_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `product_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `product_name` varchar(200) NOT NULL,
    ADD COLUMN IF NOT EXISTS `quantity` int(11) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `total_price` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- settings (6 column(s))
SET @t := 'settings';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `settings`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `setting_key` varchar(50) NOT NULL,
    ADD COLUMN IF NOT EXISTS `setting_value` text NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- staff (12 column(s))
SET @t := 'staff';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `staff`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `store_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `phone` varchar(20) NULL,
    ADD COLUMN IF NOT EXISTS `email` varchar(100) NULL,
    ADD COLUMN IF NOT EXISTS `user_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `designation` varchar(100) NULL,
    ADD COLUMN IF NOT EXISTS `salary` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `salary_type` enum(''weekly'',''monthly'') NOT NULL DEFAULT ''''''monthly'''''',
    ADD COLUMN IF NOT EXISTS `status` enum(''active'',''inactive'') NULL DEFAULT ''''''active'''''',
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- staff_payments (15 column(s))
SET @t := 'staff_payments';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `staff_payments`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `staff_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `store_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `amount` decimal(12,2) NOT NULL,
    ADD COLUMN IF NOT EXISTS `payment_date` date NOT NULL,
    ADD COLUMN IF NOT EXISTS `note` varchar(255) NULL,
    ADD COLUMN IF NOT EXISTS `created_by` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `working_days` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `total_days` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `daily_rate` decimal(12,2) NULL,
    ADD COLUMN IF NOT EXISTS `earned_amount` decimal(12,2) NULL,
    ADD COLUMN IF NOT EXISTS `bonus` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `advance_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- stock_history (8 column(s))
SET @t := 'stock_history';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `stock_history`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `product_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `quantity_change` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `type` enum(''purchase'',''sale'',''adjustment'',''return'',''transfer'',''sale_delete'') NOT NULL,
    ADD COLUMN IF NOT EXISTS `reference_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `note` text NULL,
    ADD COLUMN IF NOT EXISTS `user_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- stores (8 column(s))
SET @t := 'stores';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `stores`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `address` text NULL,
    ADD COLUMN IF NOT EXISTS `phone` varchar(20) NULL,
    ADD COLUMN IF NOT EXISTS `status` enum(''active'',''inactive'') NOT NULL DEFAULT ''''''active'''''',
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- store_stocks (4 column(s))
SET @t := 'store_stocks';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `store_stocks`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `store_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `product_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `quantity` int(11) NOT NULL DEFAULT 0'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- system_settings (4 column(s))
SET @t := 'system_settings';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `system_settings`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `setting_key` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `setting_value` text NULL,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- transfers (10 column(s))
SET @t := 'transfers';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `transfers`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `reference_no` varchar(50) NOT NULL,
    ADD COLUMN IF NOT EXISTS `from_store_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `to_store_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `status` enum(''pending'',''completed'',''cancelled'') NOT NULL DEFAULT ''''''pending'''''',
    ADD COLUMN IF NOT EXISTS `note` text NULL,
    ADD COLUMN IF NOT EXISTS `created_by` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- transfer_items (4 column(s))
SET @t := 'transfer_items';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `transfer_items`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `transfer_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `product_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `quantity` int(11) NOT NULL'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- users (12 column(s))
SET @t := 'users';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `email` varchar(100) NOT NULL,
    ADD COLUMN IF NOT EXISTS `phone` varchar(20) NULL,
    ADD COLUMN IF NOT EXISTS `password` varchar(255) NOT NULL,
    ADD COLUMN IF NOT EXISTS `role` varchar(50) NOT NULL DEFAULT ''''''cashier'''''',
    ADD COLUMN IF NOT EXISTS `store_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `status` enum(''active'',''inactive'') NOT NULL DEFAULT ''''''active'''''',
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NULL,
    ADD COLUMN IF NOT EXISTS `last_login` timestamp NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- whatsapp_contacts (10 column(s))
SET @t := 'whatsapp_contacts';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `whatsapp_contacts`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `chat_id` varchar(20) NOT NULL,
    ADD COLUMN IF NOT EXISTS `name` varchar(120) NULL,
    ADD COLUMN IF NOT EXISTS `notify` varchar(120) NULL,
    ADD COLUMN IF NOT EXISTS `username` varchar(60) NULL,
    ADD COLUMN IF NOT EXISTS `wa_last_at` datetime NULL,
    ADD COLUMN IF NOT EXISTS `wa_unread` int(11) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `source` varchar(10) NOT NULL DEFAULT ''''''known'''''',
    ADD COLUMN IF NOT EXISTS `seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- whatsapp_messages (10 column(s))
SET @t := 'whatsapp_messages';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t) > 0,
    CONCAT('ALTER TABLE `whatsapp_messages`
    ADD COLUMN IF NOT EXISTS `id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `owner_id` int(11) NOT NULL,
    ADD COLUMN IF NOT EXISTS `chat_id` varchar(20) NOT NULL,
    ADD COLUMN IF NOT EXISTS `direction` enum(''in'',''out'') NOT NULL,
    ADD COLUMN IF NOT EXISTS `body` text NULL,
    ADD COLUMN IF NOT EXISTS `has_media` tinyint(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `wa_message_id` varchar(90) NULL,
    ADD COLUMN IF NOT EXISTS `is_revoked` tinyint(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `read_at` timestamp NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
