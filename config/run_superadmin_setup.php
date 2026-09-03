<?php
/**
 * One-time Super Admin Setup
 * Run once: http://localhost/smart/config/run_superadmin_setup.php
 * DELETE this file after running!
 */

require_once 'db.php';

$db = getDB();
$results = [];

try {
    // 1. Fix koro account → tenant admin (owner_id = id)
    $stmt = $db->prepare("UPDATE users SET owner_id = id WHERE email = 'koro@pos.com' AND role = 'admin'");
    $stmt->execute();
    $results[] = ['ok', "koro@pos.com → owner_id set to self (tenant admin). Login: /auth/login.php"];

    // 2. Create/update Super Admin with owner_id = NULL
    $hash = '$2y$10$WAdtAHPKjQiC91IvooWNWuMZg/0d8fxqVWswO8398Niavqu.2lgAq';
    $stmt = $db->prepare("
        INSERT INTO users (name, email, password, role, status, store_id, owner_id)
        VALUES ('Super Admin', 'admin@pos.com', ?, 'admin', 'active', NULL, NULL)
        ON DUPLICATE KEY UPDATE
            name     = 'Super Admin',
            password = ?,
            owner_id = NULL,
            status   = 'active'
    ");
    $stmt->execute([$hash, $hash]);
    $results[] = ['ok', "Super Admin created/updated — admin@pos.com / Admin@123"];

    // 3. Verify
    $stmt = $db->query("SELECT id, name, email, role, status, owner_id FROM users WHERE role='admin' ORDER BY id");
    $admins = $stmt->fetchAll();

} catch (Exception $e) {
    $results[] = ['error', "Error: " . $e->getMessage()];
    $admins = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Super Admin Setup</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; padding: 2rem; }
        .card { background: #1e293b; border-radius: 12px; padding: 2rem; max-width: 720px; margin: 0 auto; }
        h1 { color: #818cf8; margin-top: 0; }
        .ok    { color: #34d399; }
        .error { color: #f87171; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        th { background: #334155; padding: 10px; text-align: left; font-size: .85rem; }
        td { padding: 9px 10px; border-bottom: 1px solid #334155; font-size: .88rem; }
        .sa { color: #a78bfa; font-weight: 700; }
        .ta { color: #60a5fa; }
        .creds { background: #064e3b; border: 1px solid #065f46; border-radius: 8px; padding: 1.25rem; margin: 1.5rem 0; }
        .creds h3 { margin: 0 0 .75rem; color: #34d399; }
        .creds p  { margin: .3rem 0; font-size: .95rem; }
        .creds strong { color: #6ee7b7; }
        .warn { background: #7f1d1d; border: 1px solid #991b1b; border-radius: 8px; padding: 1rem; margin-top: 1.5rem; color: #fca5a5; font-size: .9rem; }
        a.btn { display: inline-block; background: #4f46e5; color: #fff; text-decoration: none; padding: .6rem 1.4rem; border-radius: 8px; font-weight: 600; margin-top: 1rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>⚡ Super Admin Setup</h1>

    <?php foreach ($results as [$type, $msg]): ?>
        <p class="<?php echo $type; ?>">
            <?php echo $type === 'ok' ? '✅' : '❌'; ?> <?php echo htmlspecialchars($msg); ?>
        </p>
    <?php endforeach; ?>

    <div class="creds">
        <h3>🔐 Super Admin Credentials</h3>
        <p>Portal: <strong>http://localhost/smart/admin/login.php</strong></p>
        <p>Email: <strong>admin@pos.com</strong></p>
        <p>Password: <strong>Admin@123</strong></p>
    </div>

    <div class="creds" style="background:#1e3a5f;border-color:#1e40af;">
        <h3 style="color:#60a5fa;">👤 Tenant / Business User Login</h3>
        <p>Portal: <strong>http://localhost/smart/auth/login.php</strong></p>
        <p>Use your registered business email & password</p>
    </div>

    <?php if (!empty($admins)): ?>
    <table>
        <thead>
            <tr><th>#</th><th>Name</th><th>Email</th><th>Status</th><th>owner_id</th><th>Type</th></tr>
        </thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
            <?php $isSA = $a['owner_id'] === null; ?>
            <tr>
                <td><?php echo $a['id']; ?></td>
                <td><?php echo htmlspecialchars($a['name']); ?></td>
                <td><?php echo htmlspecialchars($a['email']); ?></td>
                <td><?php echo $a['status']; ?></td>
                <td><?php echo $a['owner_id'] ?? '<em style="color:#64748b">NULL</em>'; ?></td>
                <td class="<?php echo $isSA ? 'sa' : 'ta'; ?>">
                    <?php echo $isSA ? '⚡ SUPER ADMIN' : '👤 Tenant Admin'; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="warn">
        ⚠️ <strong>Security:</strong> এই file টি run করার পর অবশ্যই delete করুন!<br>
        <code>c:/xampp/htdocs/smart/config/run_superadmin_setup.php</code>
    </div>

    <a class="btn" href="../admin/login.php">Admin Login পেজে যান →</a>
</div>
</body>
</html>
