<?php
require_once __DIR__ . '/config/db.php';
ensureCashbookSourceColumns();
$db = getDB();
$cols = $db->query("SHOW COLUMNS FROM cashbook_entries")->fetchAll();
echo "Columns:\n";
foreach ($cols as $c) { echo "  " . $c["Field"] . " (" . $c["Type"] . ")\n"; }