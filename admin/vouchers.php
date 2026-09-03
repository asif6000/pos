<?php
require_once '../config/db.php';
startSecureSession();

requirePermission('vouchers');

redirect('dashboard.php');
exit;
