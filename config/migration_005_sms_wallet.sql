-- SMS Wallet - per-owner credit ledger, transaction history, delivery log and
-- package-payment ledger.
--
-- Import this on cPanel if the DB user lacks CREATE. On XAMPP the tables are
-- created automatically on first use by smsWalletEnsureTables() in
-- config/sms_wallet.php, so this file only matters where DDL is denied.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `sms_wallets` (
  `owner_id` INT NOT NULL,
  `balance` INT NOT NULL DEFAULT 0,
  `total_purchased` INT NOT NULL DEFAULT 0,
  `total_used` INT NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sms_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `type` ENUM('purchase','invoice','marketing','adjustment','refund') NOT NULL,
  `direction` ENUM('credit','debit') NOT NULL,
  `quantity` INT NOT NULL,
  `balance_before` INT NOT NULL,
  `balance_after` INT NOT NULL,
  `reference_type` VARCHAR(40) NULL,
  `reference_id` VARCHAR(60) NULL,
  `note` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_owner` (`owner_id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sms_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `store_id` INT NULL,
  `user_id` INT NULL,
  `recipient` VARCHAR(20) NOT NULL,
  `msg_type` ENUM('invoice','marketing','manual') NOT NULL DEFAULT 'manual',
  `message` TEXT NULL,
  `segments` INT NOT NULL DEFAULT 1,
  `provider_ref` VARCHAR(120) NULL,
  `status` ENUM('sent','failed','manual') NOT NULL DEFAULT 'sent',
  `error` VARCHAR(500) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  INDEX `idx_owner` (`owner_id`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sms_package_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `user_id` INT NULL,
  `package_slug` VARCHAR(40) NOT NULL,
  `sms_count` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
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
