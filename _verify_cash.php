<?php
require __DIR__ . '/config/db.php';
$db = getDB();
$rows = $db->query("SELECT id, owner_id, store_id, user_id, type, amount, note, source_type, source_id, created_at FROM cashbook_entries WHERE source_type IS NOT NULL ORDER BY id DESC LIMIT 5")->fetchAll();
echo json_encode($rows, JSON_PRETTY_PRINT);