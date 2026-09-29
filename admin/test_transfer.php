<?php
require_once '../config/db.php';
$db = getDB();

try {
    $productId = 13;
    $fromStore = 2; // Source has 20
    $toStore = 1; 
    $qty = 1;
    
    echo "Testing transfer for Product ID: $productId ($qty piece from Store $fromStore to Store $toStore)\n";
    
    $db->beginTransaction();
    
    // Logic from stock.php
    $store_id = $fromStore; // If Store Admin for Store 2
    $toStoreId = $toStore;
    $transferQty = $qty;
    $currentUser = ['id' => 1];

    // 1. Verify Source Stock
    $stmt = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ? FOR UPDATE");
    $stmt->execute([$fromStore, $productId]);
    $sourceStock = $stmt->fetchColumn();
    echo "Source Balance: $sourceStock\n";

    if ($sourceStock === false || $sourceStock < $transferQty) {
        throw new Exception("Insufficient stock in source store");
    }

    // 2. Deduct
    $stmt = $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?");
    $stmt->execute([$transferQty, $fromStore, $productId]);
    echo "Deducted\n";

    // 3. Add
    $stmt = $db->prepare("SELECT 1 FROM store_stocks WHERE store_id = ? AND product_id = ?");
    $stmt->execute([$toStoreId, $productId]);
    if (!$stmt->fetch()) {
        $stmt = $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)");
        $stmt->execute([$toStoreId, $productId]);
    }
    $stmt = $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?");
    $stmt->execute([$transferQty, $toStoreId, $productId]);
    echo "Added\n";

    // 4. History
    $stmt = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, note, user_id) VALUES (?, ?, 'transfer', ?, ?)");
    $stmt->execute([$productId, -$transferQty, "Transfer OUT to Store $toStoreId", $currentUser['id']]);
    $stmt->execute([$productId, $transferQty, "Transfer IN from Store $fromStore", $currentUser['id']]);
    echo "History Recorded\n";

    $db->commit();
    echo "SUCCESS!\n";

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "FAILED: " . $e->getMessage() . "\n";
    // echo $e->getTraceAsString();
}
