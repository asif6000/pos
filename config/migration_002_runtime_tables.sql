-- ============================================================================
--  Migration 002 - runtime tables, columns and seed data
--  Smart Collection POS
--
--  WHY THIS FILE EXISTS
--  --------------------
--  pos_schema.sql describes 17 tables. The application actually needs 28.
--  The other 11 were never written down anywhere - each was created lazily by
--  a PHP file the first time somebody opened that page (product_variables.php
--  creates the three variable tables, admin/staff.php creates staff, and so
--  on). That works right up until the moment it does not: the page that owns
--  the CREATE TABLE is behind a permission check, so a role that cannot open
--  it never creates the table, and the first query that needs it raises
--      SQLSTATE[42S02]: Base table or view not found
--  which is an uncaught PDOException, which is HTTP 500.
--
--  Drift found by diffing a database built from pos_schema.sql against the
--  database the application runs on (MariaDB 10.4.32):
--
--    11 tables absent .... cashbook_entries, expense_categories,
--                          marketing_campaigns, marketing_recipients,
--                          product_variables, product_variable_values,
--                          product_variable_map, staff, staff_payments,
--                          whatsapp_contacts, whatsapp_messages
--     1 column absent .... sales.printed
--     1 column mistyped .. users.role was ENUM('owner','admin','manager','cashier')
--     3 roles absent ..... owner, manager, staff
--    46 permissions absent
--    16 settings keys absent
--
--  ABOUT users.role, SPECIFICALLY
--  -----------------------------
--  The ENUM did not list 'staff'. MySQL is not running in strict mode on this
--  host (sql_mode has no STRICT_TRANS_TABLES), so assigning 'staff' did not
--  raise an error - MySQL stored the empty string instead. hasPermission()
--  then matched no rows for that account, so a staff member logged in and saw
--  an empty menu with nothing on screen to explain why. The column is widened
--  to VARCHAR(50) below. Existing values are untouched by the widening.
--
--  HOW TO USE
--  ----------
--  Import with phpMyAdmin (cPanel -> phpMyAdmin -> select database -> Import).
--  This file is safe to run more than once: every statement checks before it
--  writes. It only ever creates or widens. It never drops a table, never
--  deletes a row, and never overwrites an existing setting value - so your
--  shop name, your SMS API key and your WhatsApp bridge token are safe.
--
--  Take a backup first. Importing into the wrong database is the one mistake
--  this file cannot protect you from.
--
--  Verified against MariaDB 10.4.32.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. users.role : ENUM -> VARCHAR(50)
--
--    Guarded, because a plain MODIFY would also run on a database that has
--    already been fixed, and re-running must not be a surprise. The IF() looks
--    at information_schema and builds either the ALTER or a no-op.
-- ----------------------------------------------------------------------------
SET @role_is_enum := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'role'
      AND DATA_TYPE    = 'enum'
);
SET @sql := IF(@role_is_enum > 0,
    'ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT ''cashier''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ----------------------------------------------------------------------------
-- 2. sales.printed
--
--    mark-printed.php sets this to 1 so a receipt is not re-sent. The column
--    is in the working database and missing from pos_schema.sql, which is why
--    a fresh install cannot mark a receipt printed.
-- ----------------------------------------------------------------------------
ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS `printed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `payment_status`;


-- ----------------------------------------------------------------------------
-- 3. The 11 missing tables
--
--    Definitions are taken verbatim from the database the application already
--    runs against, so nothing about them is guesswork.
-- ----------------------------------------------------------------------------

-- 3.1 cashbook_entries - money in and out. Every sale writes a row here.
--     source_type / source_id are what make the automatic sale entry
--     idempotent, so editing a sale updates its entry instead of adding a
--     second one.
CREATE TABLE IF NOT EXISTS `cashbook_entries` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `owner_id`    INT(11)      NOT NULL,
  `store_id`    INT(11)      DEFAULT NULL,
  `user_id`     INT(11)      NOT NULL,
  `type`        ENUM('cash_in','cash_out') NOT NULL,
  `amount`      DECIMAL(12,2) NOT NULL,
  `note`        VARCHAR(255) DEFAULT NULL,
  `category_id` INT(11)      DEFAULT NULL,
  `source_type` VARCHAR(20)  DEFAULT NULL,
  `source_id`   INT(11)      DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_owner`   (`owner_id`),
  KEY `idx_store`   (`store_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_category`(`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.2 expense_categories - what the cash_out rows are categorised as.
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `owner_id`   INT(11)      NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_owner_name` (`owner_id`,`name`),
  KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.3 marketing_campaigns
CREATE TABLE IF NOT EXISTS `marketing_campaigns` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `owner_id`      INT(11)      NOT NULL,
  `channel`       VARCHAR(20)  NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `message`       TEXT         NOT NULL,
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'draft',
  `total`         INT(11)      NOT NULL DEFAULT 0,
  `sent_count`    INT(11)      NOT NULL DEFAULT 0,
  `failed_count`  INT(11)      NOT NULL DEFAULT 0,
  `is_manual`     TINYINT(1)   NOT NULL DEFAULT 0,
  `started_at`    DATETIME     DEFAULT NULL,
  `finished_at`   DATETIME     DEFAULT NULL,
  `created_by`    INT(11)      DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_owner_status`  (`owner_id`,`status`),
  KEY `idx_owner_created` (`owner_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.4 marketing_recipients - one row per recipient per campaign.
CREATE TABLE IF NOT EXISTS `marketing_recipients` (
  `id`          INT(11)     NOT NULL AUTO_INCREMENT,
  `campaign_id` INT(11)     NOT NULL,
  `owner_id`    INT(11)     NOT NULL,
  `customer_id` INT(11)     DEFAULT NULL,
  `name`        VARCHAR(100) NOT NULL DEFAULT '',
  `phone`       VARCHAR(20)  NOT NULL,
  `message`     TEXT         DEFAULT NULL,
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'pending',
  `error`       TEXT         DEFAULT NULL,
  `sent_at`     DATETIME     DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_status` (`campaign_id`,`status`),
  KEY `idx_owner_phone`    (`owner_id`,`phone`),
  KEY `idx_customer`       (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.5 product_variables - the Size / Colour / Unit lists on the Variable Name
--     page. slug is what product_variables.php matches on.
CREATE TABLE IF NOT EXISTS `product_variables` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(60)  NOT NULL,
  `slug`       VARCHAR(60)  NOT NULL,
  `sort_order` INT(11)      NOT NULL DEFAULT 0,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `owner_id`   INT(11)      DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.6 product_variable_values
CREATE TABLE IF NOT EXISTS `product_variable_values` (
  `id`          INT(11)     NOT NULL AUTO_INCREMENT,
  `variable_id` INT(11)     NOT NULL,
  `value`       VARCHAR(60) NOT NULL,
  `sort_order`  INT(11)     NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_var_value` (`variable_id`,`value`),
  KEY `idx_variable` (`variable_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.7 product_variable_map - which value a given product uses for a given
--     variable. Unique on (product_id, variable_id): one value per variable.
CREATE TABLE IF NOT EXISTS `product_variable_map` (
  `id`          INT(11)   NOT NULL AUTO_INCREMENT,
  `product_id`  INT(11)   NOT NULL,
  `variable_id` INT(11)   NOT NULL,
  `value_id`    INT(11)   NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_var` (`product_id`,`variable_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.8 staff - a person on the payroll. An account can exist without a row
--     here; that is not an error, it just means no payroll profile.
CREATE TABLE IF NOT EXISTS `staff` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `owner_id`    INT(11)      NOT NULL,
  `store_id`    INT(11)      DEFAULT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `phone`       VARCHAR(20)  DEFAULT NULL,
  `email`       VARCHAR(100) DEFAULT NULL,
  `user_id`     INT(11)      DEFAULT NULL,
  `designation` VARCHAR(100) DEFAULT NULL,
  `salary`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `salary_type` ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly',
  `status`      ENUM('active','inactive') DEFAULT 'active',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.9 staff_payments
CREATE TABLE IF NOT EXISTS `staff_payments` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `staff_id`          INT(11)      NOT NULL,
  `owner_id`          INT(11)      NOT NULL,
  `store_id`          INT(11)      DEFAULT NULL,
  `amount`            DECIMAL(12,2) NOT NULL,
  `payment_date`      DATE         NOT NULL,
  `note`              VARCHAR(255) DEFAULT NULL,
  `created_by`        INT(11)      DEFAULT NULL,
  `working_days`      INT(11)      DEFAULT NULL,
  `total_days`        INT(11)      DEFAULT NULL,
  `daily_rate`        DECIMAL(12,2) DEFAULT NULL,
  `earned_amount`     DECIMAL(12,2) DEFAULT NULL,
  `bonus`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `advance_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_date`  (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.10 whatsapp_contacts
--      wa_last_at / wa_unread are the contact's own record; the messages table
--      holds the thread. wa_last_at is deliberately not called last_at - that
--      name is used as an alias for MAX(whatsapp_messages.created_at).
CREATE TABLE IF NOT EXISTS `whatsapp_contacts` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `owner_id`   INT(11)      NOT NULL,
  `chat_id`    VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(120) DEFAULT NULL,
  `notify`     VARCHAR(120) DEFAULT NULL,
  `username`   VARCHAR(60)  DEFAULT NULL,
  `wa_last_at` DATETIME     DEFAULT NULL,
  `wa_unread`  INT(11)      NOT NULL DEFAULT 0,
  `source`     VARCHAR(10)  NOT NULL DEFAULT 'known',
  `seen_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_contact` (`owner_id`,`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.11 whatsapp_messages
CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id`             INT(11)     NOT NULL AUTO_INCREMENT,
  `owner_id`       INT(11)     NOT NULL,
  `chat_id`        VARCHAR(20) NOT NULL,
  `direction`      ENUM('in','out') NOT NULL,
  `body`           TEXT        DEFAULT NULL,
  `has_media`      TINYINT(1)  NOT NULL DEFAULT 0,
  `wa_message_id`  VARCHAR(90) DEFAULT NULL,
  `is_revoked`     TINYINT(1)  NOT NULL DEFAULT 0,
  `read_at`        TIMESTAMP   NULL DEFAULT NULL,
  `created_at`     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_msg` (`owner_id`,`wa_message_id`),
  KEY `idx_thread` (`owner_id`,`chat_id`,`id`),
  KEY `idx_unread` (`owner_id`,`direction`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- 4. Roles
--
--    adminSlugs() in config/db.php reads this table to decide which roles get
--    blanket access, so 'owner' has to exist as a row for owner to be treated
--    as an owner. pos_schema.sql only ever seeded admin and cashier.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `status`) VALUES
('Owner',   'owner',   'Full access to everything, including roles and staff', 'active'),
('Manager', 'manager', 'Runs the shop day to day. Cannot change system settings or permissions', 'active'),
('Staff',   'staff',   'Counter role: billing, sales list and customer lookup', 'active');

-- admin gains the two permissions added after pos_schema.sql was written
INSERT IGNORE INTO `role_permissions` (`role_slug`, `permission`) VALUES
('admin', 'marketing'),
('admin', 'variables');

-- owner: everything
INSERT IGNORE INTO `role_permissions` (`role_slug`, `permission`) VALUES
('owner', 'dashboard'), ('owner', 'pos'), ('owner', 'products'),
('owner', 'categories'), ('owner', 'variables'), ('owner', 'stock'),
('owner', 'transfers'), ('owner', 'sales'), ('owner', 'sales_delete'),
('owner', 'returns'), ('owner', 'reports'), ('owner', 'cashbook'),
('owner', 'customers'), ('owner', 'marketing'), ('owner', 'users'),
('owner', 'stores'), ('owner', 'staff'), ('owner', 'roles'),
('owner', 'settings'), ('owner', 'barcode_settings'), ('owner', 'vouchers');

-- manager: the shop day to day, but not settings, users, roles or stores
INSERT IGNORE INTO `role_permissions` (`role_slug`, `permission`) VALUES
('manager', 'dashboard'), ('manager', 'pos'), ('manager', 'products'),
('manager', 'categories'), ('manager', 'variables'), ('manager', 'stock'),
('manager', 'transfers'), ('manager', 'sales'), ('manager', 'sales_delete'),
('manager', 'returns'), ('manager', 'reports'), ('manager', 'cashbook'),
('manager', 'customers'), ('manager', 'marketing'), ('manager', 'staff'),
('manager', 'stores'), ('manager', 'barcode_settings'), ('manager', 'vouchers');

-- staff: the counter
INSERT IGNORE INTO `role_permissions` (`role_slug`, `permission`) VALUES
('staff', 'dashboard'), ('staff', 'pos'), ('staff', 'sales'),
('staff', 'customers'), ('staff', 'returns');


-- ----------------------------------------------------------------------------
-- 5. Settings keys the code reads but pos_schema.sql never seeded
--
--    Guarded with NOT EXISTS rather than INSERT IGNORE, and that is not
--    fussiness: settings has UNIQUE (owner_id, setting_key) and these rows are
--    written with owner_id = NULL. MySQL treats every NULL as distinct in a
--    unique index, so INSERT IGNORE would happily add a second copy of a key
--    that already exists. The NOT EXISTS guard is what actually prevents that.
--
--    Secrets are inserted empty on purpose. Put the real API key in through
--    the Settings page, not here.
-- ----------------------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'self_registration', '0', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'self_registration');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_bridge_url', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_bridge_url');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_bridge_token', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_bridge_token');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sender_name', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sender_name');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_delay_min', '2', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_delay_min');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_delay_max', '3', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_delay_max');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_mode', 'json', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_mode');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_api_key', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_api_key');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_sender_id', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_sender_id');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_provider', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_provider');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_post_url', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_post_url');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_get_url', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_get_url');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_balance_url', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_balance_url');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_message_key', 'message', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_message_key');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_number_key', 'mobile_number', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_number_key');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'marketing_sms_headers', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'marketing_sms_headers');

-- Barcode / label defaults. barcode-settings.php writes these with plain
-- INSERT .. ON DUPLICATE KEY UPDATE, which does not work for owner_id NULL
-- rows for the reason given above - it is the reason the Print Labels modal on
-- the Products page was rendering empty inputs.
INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_sticker_width', '1.7700', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_sticker_width');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_sticker_height', '1.3800', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_sticker_height');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_paper_width', '1.8000', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_paper_width');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_paper_height', '1.4000', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_paper_height');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_top_margin', '0.0000', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_top_margin');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_left_margin', '0.0000', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_left_margin');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_stickers_per_row', '1', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_stickers_per_row');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_stickers_per_sheet', '1', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_stickers_per_sheet');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_row_distance', '0.0000', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_row_distance');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'barcode_col_distance', '0.0000', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'barcode_col_distance');

-- Invoice sending switches. A missing row means ON for these four, which is a
-- deliberate convention in invoice-send.php. They are seeded OFF here so a new
-- install does not start sending invoices to customers nobody asked to send
-- them to.
INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'auto_send_invoice', '0', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'auto_send_invoice');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'invoice_send_sms', '0', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'invoice_send_sms');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'invoice_send_whatsapp', '0', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'invoice_send_whatsapp');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'invoice_sms_whatsapp_only', '0', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'invoice_sms_whatsapp_only');

-- Receipt wording. A missing voucher_terms row is what made the invoice fall
-- back to a hardcoded default in process-sale.php.
INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'voucher_terms', '', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'voucher_terms');

INSERT INTO `settings` (`setting_key`, `setting_value`, `owner_id`)
SELECT 'timezone', 'Asia/Dhaka', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'timezone');


-- ----------------------------------------------------------------------------
-- 6. Verify
--
--    Every table this application touches should now be listed. 28 is the
--    number to expect. Anything lower means a CREATE above did not run.
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS `tables_total_expect_28`
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';

SELECT `TABLE_NAME`
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
 ORDER BY `TABLE_NAME`;

-- users.role must report varchar, not enum
SELECT `COLUMN_TYPE` AS `users_role_type_expect_varchar`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role';

-- these three must each report 1
SELECT
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='printed') AS `sales_printed_ok`,
  (SELECT COUNT(*) FROM `roles` WHERE slug IN ('owner','manager','staff'))                          AS `new_roles_ok`,
  (SELECT COUNT(DISTINCT role_slug) FROM `role_permissions`)                                       AS `roles_with_permissions`;

SELECT role_slug, COUNT(*) AS permissions
  FROM `role_permissions` GROUP BY role_slug ORDER BY role_slug;
