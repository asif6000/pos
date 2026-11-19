-- E-commerce (My Online Shop) - a shop's public storefront settings, the
-- per-product decision to be listed online and which branch fulfils it, and the
-- orders customers place with their lines.
--
-- Import this on cPanel if the DB user lacks CREATE. On XAMPP the tables are
-- created automatically on first use by ecommerceEnsureTables() in
-- config/ecommerce.php, so this file only matters where DDL is denied.
-- Safe to run more than once.
--
-- An order is NOT a sale when it arrives. ecom_orders.sale_id stays NULL until the
-- shopkeeper accepts, and accepting writes the real sales + sale_items rows and
-- deducts store_stocks - so an abandoned or mistyped order never touches stock.

CREATE TABLE IF NOT EXISTS `ecom_shops` (
  `owner_id` INT NOT NULL,
  `slug` VARCHAR(60) NOT NULL,
  `name` VARCHAR(150) NULL,
  `phone` VARCHAR(20) NULL,
  `address` VARCHAR(255) NULL,
  `area` VARCHAR(120) NULL,
  `delivery_charge` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `free_over` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `min_order` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `is_open` TINYINT(1) NOT NULL DEFAULT 1,
  `cover` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`owner_id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The shop's own front picture, chosen on the Online Shop page and shown on the
-- shop's card in the Marketplace app. Added here as well as above because this file
-- is run on installs whose ecom_shops table already exists, where CREATE IF NOT
-- EXISTS changes nothing. Safe to run more than once.
SET @has_cover := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ecom_shops'
      AND COLUMN_NAME  = 'cover'
);
SET @sql := IF(@has_cover = 0,
    'ALTER TABLE `ecom_shops` ADD COLUMN `cover` VARCHAR(255) NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `ecom_listings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `store_id` INT NULL,
  `visible` TINYINT(1) NOT NULL DEFAULT 0,
  `price_override` DECIMAL(12,2) NULL,
  `note` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_owner_product` (`owner_id`, `product_id`),
  INDEX `idx_owner_visible` (`owner_id`, `visible`),
  INDEX `idx_store` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ecom_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_no` VARCHAR(30) NOT NULL,
  `owner_id` INT NOT NULL,
  `store_id` INT NULL,
  `customer_name` VARCHAR(150) NULL,
  `customer_phone` VARCHAR(20) NULL,
  `address` VARCHAR(255) NULL,
  `items_total` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `delivery_charge` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` ENUM('pending','accepted','rejected','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `note` VARCHAR(500) NULL,
  `sale_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_order_no` (`order_no`),
  INDEX `idx_owner_status` (`owner_id`, `status`),
  INDEX `idx_phone` (`customer_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ecom_order_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `product_name` VARCHAR(200) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
  INDEX `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
