<?php
/**
 * POS System - Exchange Migration
 * Run this script to add exchange functionality to returns
 */

require_once __DIR__ . '/db.php';

$db = getDB();

try {
    // Check if exchange_balance column exists
    $stmt = $db->query("SHOW COLUMNS FROM returns LIKE 'exchange_balance'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE returns ADD COLUMN exchange_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount");
        echo "Added exchange_balance column to returns table.\n";
    } else {
        echo "exchange_balance column already exists.\n";
    }

    // Check if exchange_items table exists
    $stmt = $db->query("SHOW TABLES LIKE 'exchange_items'");
    if (!$stmt->fetch()) {
        $db->exec("
            CREATE TABLE exchange_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                return_id INT NOT NULL,
                product_id INT NOT NULL,
                product_name VARCHAR(200) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
                total_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
                INDEX idx_return (return_id),
                INDEX idx_product (product_id)
            ) ENGINE=InnoDB
        ");
        echo "Created exchange_items table.\n";
    } else {
        echo "exchange_items table already exists.\n";
    }

    // Add 'exchange' type to stock_history ENUM
    $stmt = $db->query("SHOW COLUMNS FROM stock_history LIKE 'type'");
    $col = $stmt->fetch();
    if ($col && strpos($col['Type'], 'exchange') === false) {
        $db->exec("ALTER TABLE stock_history MODIFY COLUMN type ENUM('purchase', 'sale', 'adjustment', 'return', 'transfer', 'exchange') NOT NULL");
        echo "Added 'exchange' type to stock_history ENUM.\n";
    } else {
        echo "'exchange' type already exists in stock_history.\n";
    }

    echo "\nMigration completed successfully!";
} catch (PDOException $e) {
    echo "Migration error: " . $e->getMessage();
}
