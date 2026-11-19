-- Supplier Purchase & Due module - tables
-- Run this in phpMyAdmin with the shop database SELECTED (click the database name in the
-- left list first). Importing while information_schema is the active database creates the
-- tables in the wrong place and USE cannot undo it inside the import.
-- Every statement is IF NOT EXISTS, so running the file twice is harmless.
-- Paste the statements one block at a time if the import reports a partial success -
-- phpMyAdmin shows a green banner even when it stopped early.
-- Generated from config/purchases.php purchaseSchema(); do not edit by hand.

-- ── purchases ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchases (
          id INT AUTO_INCREMENT PRIMARY KEY,
          invoice_no VARCHAR(20) NOT NULL,
          owner_id INT NOT NULL,
          store_id INT NOT NULL,
          user_id INT NOT NULL,
          supplier_id INT NOT NULL,
          purchase_date DATE NOT NULL,
          subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          credit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          due_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          payment_method VARCHAR(20) DEFAULT NULL,
          status ENUM('paid','partial','due') NOT NULL DEFAULT 'due',
          stock_applied TINYINT(1) NOT NULL DEFAULT 0,
          note VARCHAR(255) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY idx_pur_invoice (invoice_no),
          KEY idx_pur_owner (owner_id),
          KEY idx_pur_store (store_id),
          KEY idx_pur_supplier (supplier_id),
          KEY idx_pur_date (purchase_date),
          KEY idx_pur_status (status),
          CONSTRAINT fk_pur_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
          CONSTRAINT fk_pur_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE RESTRICT
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── purchase_items ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          purchase_id INT NOT NULL,
          product_id INT NOT NULL,
          product_name VARCHAR(200) NOT NULL,
          qty INT NOT NULL,
          unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          returned_qty INT NOT NULL DEFAULT 0,
          KEY idx_pi_purchase (purchase_id),
          KEY idx_pi_product (product_id),
          CONSTRAINT fk_pi_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
          CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── supplier_payments ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS supplier_payments (
          id INT AUTO_INCREMENT PRIMARY KEY,
          owner_id INT NOT NULL,
          store_id INT DEFAULT NULL,
          user_id INT NOT NULL,
          supplier_id INT NOT NULL,
          purchase_id INT DEFAULT NULL,
          amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
          payment_date DATE NOT NULL,
          reference VARCHAR(60) DEFAULT NULL,
          note VARCHAR(255) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_sp_owner (owner_id),
          KEY idx_sp_supplier (supplier_id),
          KEY idx_sp_purchase (purchase_id),
          KEY idx_sp_date (payment_date),
          CONSTRAINT fk_sp_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── purchase_returns ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_returns (
          id INT AUTO_INCREMENT PRIMARY KEY,
          invoice_no VARCHAR(24) NOT NULL,
          owner_id INT NOT NULL,
          store_id INT NOT NULL,
          user_id INT NOT NULL,
          supplier_id INT NOT NULL,
          purchase_id INT NOT NULL,
          return_date DATE NOT NULL,
          total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          refund_method VARCHAR(20) DEFAULT NULL,
          note VARCHAR(255) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY idx_pr_invoice (invoice_no),
          KEY idx_pr_owner (owner_id),
          KEY idx_pr_supplier (supplier_id),
          KEY idx_pr_purchase (purchase_id),
          KEY idx_pr_date (return_date),
          CONSTRAINT fk_pr_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── purchase_return_items ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_return_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          return_id INT NOT NULL,
          purchase_item_id INT NOT NULL,
          product_id INT NOT NULL,
          qty INT NOT NULL,
          unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          KEY idx_pri_return (return_id),
          KEY idx_pri_product (product_id),
          CONSTRAINT fk_pri_return FOREIGN KEY (return_id) REFERENCES purchase_returns (id) ON DELETE CASCADE,
          CONSTRAINT fk_pri_item FOREIGN KEY (purchase_item_id) REFERENCES purchase_items (id) ON DELETE RESTRICT
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── purchase_audit_log ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_audit_log (
          id INT AUTO_INCREMENT PRIMARY KEY,
          owner_id INT DEFAULT NULL,
          user_id INT DEFAULT NULL,
          user_name VARCHAR(150) DEFAULT NULL,
          action VARCHAR(40) NOT NULL,
          entity VARCHAR(30) NOT NULL,
          entity_id INT DEFAULT NULL,
          invoice_no VARCHAR(24) DEFAULT NULL,
          amount DECIMAL(12,2) DEFAULT NULL,
          meta TEXT DEFAULT NULL,
          ip VARCHAR(45) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_pal_owner (owner_id),
          KEY idx_pal_entity (entity, entity_id),
          KEY idx_pal_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── payment methods: nothing to run ───────────────────────────────────────
-- Each shop edits its own list on Settings -> Supplier Payment Methods. It is
-- stored in the existing per-shop settings table
-- (setting_key = 'purchase_payment_methods', a CSV of cash, bkash, nagad,
-- rocket, card, bank), so a shop can never trim another shop's choices, and
-- with no row saved the purchase form offers all six.
