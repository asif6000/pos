-- Subscriptions / packages (UddoktaPay billing).
-- Import this on cPanel if the DB user lacks CREATE; on XAMPP the tables are
-- created automatically on first use by subEnsureTables() in config/subscription.php.

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `user_id` INT NULL,
  `plan_slug` VARCHAR(40) NOT NULL,
  `billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `status` ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  `started_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_owner` (`owner_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `subscription_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `user_id` INT NULL,
  `plan_slug` VARCHAR(40) NOT NULL,
  `billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `amount` DECIMAL(10,2) NOT NULL,
  `action` ENUM('new','renew','upgrade','downgrade') NOT NULL DEFAULT 'new',
  `gateway` VARCHAR(30) NOT NULL DEFAULT 'uddoktapay',
  `gateway_invoice_id` VARCHAR(120) NULL,
  `gateway_txn_id` VARCHAR(120) NULL,
  `status` ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_url` TEXT NULL,
  `payer_name` VARCHAR(120) NULL,
  `payer_email` VARCHAR(160) NULL,
  `verify_payload` TEXT NULL,
  `paid_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_owner` (`owner_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_invoice` (`gateway_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
