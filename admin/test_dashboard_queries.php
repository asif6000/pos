<?php
require_once '../config/db.php';
$db = getDB();

try {
    $db->exec('ALTER TABLE products ADD COLUMN comment TEXT NULL');
    echo "Added comment column to products.\n";
} catch (Exception $e) {
    echo "Products comment: " . $e->getMessage() . "\n";
}

// Now test dashboard queries
$store_id = 1;
$owner_id = 1;
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-t 23:59:59');

$queries = [
    "SELECT COALESCE(SUM(s.total), 0) as total, COUNT(*) as count FROM sales s JOIN users u ON s.user_id = u.id WHERE u.store_id = ?",
    "SELECT COALESCE(SUM(si.quantity), 0) * 5.00 as commission FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN users u ON s.user_id = u.id WHERE u.store_id = ?",
    "SELECT st.id as store_id, st.name as store_name, COUNT(s.id) as sale_count, COALESCE(SUM(s.total), 0) as total FROM stores st LEFT JOIN users u ON u.store_id = st.id LEFT JOIN sales s ON s.user_id = u.id WHERE st.id = ?",
    "SELECT COUNT(*) as count FROM products p JOIN store_stocks ss ON p.id = ss.product_id JOIN stores st ON ss.store_id = st.id WHERE st.owner_id = ? AND p.status = 'active'",
    "SELECT COUNT(*) as count FROM categories WHERE status = 'active' AND owner_id = ?",
    "SELECT COUNT(*) as count FROM customers WHERE owner_id = ?",
    "SELECT COUNT(*) as count FROM stores WHERE status = 'active' AND owner_id = ?",
    "SELECT COUNT(*) as count FROM staff WHERE owner_id = ? AND status = 'active'",
    "SELECT COUNT(*) as count FROM store_stocks ss JOIN products p ON ss.product_id = p.id WHERE ss.store_id = ? AND ss.quantity <= p.min_stock AND p.status = 'active'",
    "SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id ORDER BY s.created_at DESC LIMIT 10",
    "SELECT p.name, SUM(si.quantity) as total_qty, SUM(si.total_price) as total_revenue FROM sale_items si JOIN products p ON si.product_id = p.id JOIN sales s ON si.sale_id = s.id JOIN users u ON s.user_id = u.id WHERE u.store_id = ?",
    "SELECT s.payment_method, COUNT(s.id) as count, COALESCE(SUM(s.total), 0) as total FROM sales s JOIN users u ON s.user_id = u.id WHERE u.store_id = ?",
    "SELECT COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out FROM cashbook_entries ce WHERE ce.owner_id = ?",
    "SELECT s.name, s.salary, s.salary_type, COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = s.id AND sp.payment_date BETWEEN ? AND ?), 0) as paid_month, COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = s.id), 0) as total_paid FROM staff s WHERE s.owner_id = ? AND s.status = 'active' ORDER BY s.created_at DESC LIMIT 5",
];

foreach ($queries as $i => $sql) {
    try {
        $stmt = $db->prepare($sql);
        // just pass owner_id/store_id for testing
        // for the staff_payments query which has 3 params, we will try/catch and see.
        if (substr_count($sql, '?') == 3) {
            $stmt->execute([$monthStart, $monthEnd, $owner_id]);
        } else {
            $stmt->execute([1]);
        }
        echo "Query " . ($i+1) . " OK.\n";
    } catch (Exception $e) {
        echo "Query " . ($i+1) . " FAILED: " . $e->getMessage() . "\n";
    }
}
