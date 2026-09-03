<?php
/**
 * POS System - Checkout Page
 * Shows selected plan and payment gateway options before registration.
 * Owner pays (bKash / Nagad / bank), confirms, then proceeds to register.
 */

require_once '../config/db.php';
startSecureSession();

// Must come in from a plan selection
$planId = isset($_GET['plan']) ? (int)$_GET['plan'] : 0;

$plan = null;
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM subscription_plans WHERE id = ? AND status = 'active'");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
} catch (Exception $e) {
    $plan = null;
}

if (!$plan) {
    redirect('../landing.php#pricing');
}

$error = '';
$success = '';

// Load payment gateway settings (global)
$gateway = [];
try {
    $gq = getDB()->query("SELECT setting_key, setting_value FROM system_settings");
    while ($gr = $gq->fetch()) {
        $gateway[$gr['setting_key']] = $gr['setting_value'];
    }
} catch (Exception $e) {
    $gateway = [];
}
$bkashNumber = $gateway['bkash_number'] ?? '';
$nagadNumber = $gateway['nagad_number'] ?? '';
$paymentNote = $gateway['payment_note'] ?? '';

// Payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = sanitize($_POST['payment_method'] ?? '');
    $sender_number  = sanitize($_POST['sender_number'] ?? '');
    $transaction_id = sanitize($_POST['transaction_id'] ?? '');

    $allowed = ['bkash', 'nagad'];
    if (!in_array($payment_method, $allowed, true)) {
        $error = 'Please select a valid payment method.';
    } elseif (empty($transaction_id)) {
        $error = 'Please enter your transaction ID.';
    } else {
        try {
            $db = getDB();
            $db->exec("CREATE TABLE IF NOT EXISTS plan_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                plan_id INT NOT NULL,
                plan_name VARCHAR(100) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                payment_method VARCHAR(20) NOT NULL,
                sender_number VARCHAR(50) NULL,
                transaction_id VARCHAR(100) NOT NULL,
                owner_id INT NULL,
                type ENUM('new','renew','upgrade','downgrade') DEFAULT 'new',
                status ENUM('pending','verified','rejected') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB");

            $stmt = $db->prepare(
                "INSERT INTO plan_payments (plan_id, plan_name, amount, payment_method, sender_number, transaction_id, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([
                $plan['id'],
                $plan['name'],
                $plan['price'],
                $payment_method,
                $sender_number ?: null,
                $transaction_id,
            ]);

            // Carry the selection to registration in the session
            $_SESSION['checkout_plan'] = [
                'plan_id'          => $plan['id'],
                'payment_method'   => $payment_method,
                'transaction_id'   => $transaction_id,
                'payment_status'   => 'pending',
            ];

            setFlash('success', 'Payment submitted successfully! Please create your account to activate the plan. Our team will verify your payment.');
            redirect('register.php?plan=' . $plan['id'] . '&paid=1');
        } catch (Exception $e) {
            $error = 'An error occurred while processing your payment. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - POS System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/hind-siliguri.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            background-color: #fce7f3;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', 'Hind Siliguri', sans-serif;
            margin: 0;
            padding: 20px;
        }
        .checkout-wrapper {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            width: 900px;
            max-width: 100%;
            overflow: hidden;
        }
        .checkout-header {
            background: #f43f5e;
            color: #fff;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .checkout-header h1 { margin: 0; font-size: 1.4rem; }
        .checkout-header p { margin: 2px 0 0; opacity: .9; font-size: .85rem; }
        .checkout-body { padding: 2rem; display: flex; gap: 2rem; flex-wrap: wrap; }
        .plan-summary {
            flex: 1;
            min-width: 260px;
            background: #fff1f2;
            border: 1px solid #ffd7da;
            border-radius: 10px;
            padding: 1.5rem;
            align-self: flex-start;
        }
        .plan-summary h3 { margin: 0 0 .2rem; color: #111827; }
        .plan-price { font-size: 2rem; font-weight: 700; color: #f43f5e; margin: .6rem 0 0; }
        .plan-meta { color: #6b7280; font-size: .85rem; margin-top: .3rem; }
        .plan-summary ul { margin: 1rem 0 0; padding-left: 1.2rem; color: #374151; font-size: .9rem; }
        .plan-summary ul li { margin-bottom: .35rem; }
        .payment-box { flex: 1.4; min-width: 300px; }
        .payment-box h3 { margin: 0 0 1rem; color: #111827; }
        .pay-options { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
        .pay-option {
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: .15s;
            background: #fff;
        }
        .pay-option:hover { border-color: #f43f5e; }
        .pay-option.active { border-color: #f43f5e; background: #fff1f2; }
        .pay-option i { font-size: 1.6rem; display: block; margin-bottom: .4rem; }
        .pay-option span { font-weight: 600; font-size: .9rem; }
        .field { margin-top: 1.2rem; }
        .field label { display: block; font-size: .85rem; font-weight: 600; color: #374151; margin-bottom: .35rem; }
        .field input {
            width: 100%;
            padding: .7rem .9rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: .95rem;
        }
        .field input:focus { outline: none; border-color: #f43f5e; }
        .gateway-info {
            margin-top: 1rem;
            background: #f9fafb;
            border: 1px dashed #d1d5db;
            border-radius: 8px;
            padding: .9rem 1rem;
            font-size: .85rem;
            color: #374151;
        }
        .gateway-info strong { color: #f43f5e; }
        .btn-primary {
            width: 100%;
            background: #f43f5e;
            color: #fff;
            border: none;
            border-radius: 30px;
            padding: 13px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 1.3rem;
            transition: background .2s;
        }
        .btn-primary:hover { background: #e11d48; }
        .alert { border-radius: 8px; padding: 12px; margin-bottom: 1rem; font-size: .9rem; display: flex; align-items: center; gap: 8px; }
        .alert-danger { background: #fee2e2; color: #ef4444; }
        .alert-success { background: #dcfce7; color: #16a34a; }
        .back-link { display: inline-block; margin-top: 1rem; color: #6b7280; text-decoration: none; font-size: .85rem; }
        .back-link:hover { color: #f43f5e; }
        @media (max-width: 640px) { .checkout-body { flex-direction: column; } }
    </style>
</head>

<body>
    <div class="checkout-wrapper">
        <div class="checkout-header">
            <i class="fas fa-credit-card" style="font-size: 2rem;"></i>
            <div>
                <h1>Complete Your Payment</h1>
                <p>Select a payment method and confirm your transaction to continue.</p>
            </div>
        </div>

        <div class="checkout-body">
            <div class="plan-summary">
                <h3><?php echo sanitize($plan['name']); ?> Plan</h3>
                <div class="plan-price"><?php echo formatCurrency($plan['price']); ?></div>
                <div class="plan-meta">per <?php echo (int)$plan['duration_days']; ?> days</div>
                <?php
                $featureList = array_filter(array_map('trim', explode("\n", $plan['features'] ?? '')));
                if (!empty($featureList)): ?>
                    <ul>
                        <?php foreach ($featureList as $feat): ?>
                            <li><?php echo sanitize($feat); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="payment-box">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><span><?php echo $error; ?></span></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo $success; ?></span></div>
                <?php endif; ?>

                <form method="POST" id="paymentForm">
                    <h3>Payment Method</h3>
                    <div class="pay-options">
                        <label class="pay-option active" data-method="bkash">
                            <input type="radio" name="payment_method" value="bkash" checked style="display:none;">
                            <i class="fab fa-cc-visa" style="color:#e2136e;"></i>
                            <span>bKash</span>
                        </label>
                        <label class="pay-option" data-method="nagad">
                            <input type="radio" name="payment_method" value="nagad" style="display:none;">
                            <i class="fas fa-mobile-alt" style="color:#e2122c;"></i>
                            <span>Nagad</span>
                        </label>
                    </div>

                    <div class="gateway-info" id="gatewayInfo">
                        Send <strong><?php echo formatCurrency($plan['price']); ?></strong> to:
                        <br><strong>bKash: <?php echo $bkashNumber ? htmlspecialchars($bkashNumber) : '01XXXXXXXXX'; ?></strong> (Personal)
                    </div>

                    <div class="field">
                        <label for="sender_number">Sender Number (optional)</label>
                        <input type="text" id="sender_number" name="sender_number"
                            placeholder="e.g. 01XXXXXXXXX">
                    </div>
                    <div class="field">
                        <label for="transaction_id">Transaction ID (TrxID) *</label>
                        <input type="text" id="transaction_id" name="transaction_id" required
                            placeholder="e.g. 9HG2X4K7LQ">
                    </div>

                    <button type="submit" class="btn-primary">Confirm Payment &amp; Continue</button>
                </form>

                <a class="back-link" href="../landing.php#pricing">&#8592; Back to plans</a>
            </div>
        </div>
    </div>

    <script>
        <?php
        $bkashJs = $bkashNumber ? htmlspecialchars($bkashNumber, ENT_QUOTES) : '01XXXXXXXXX';
        $nagadJs = $nagadNumber ? htmlspecialchars($nagadNumber, ENT_QUOTES) : '01XXXXXXXXX';
        ?>
        const gateways = {
            bkash: { icon: 'fab fa-cc-visa', color: '#e2136e', name: 'bKash', detail: '<?php echo $bkashJs; ?> (Personal)' },
            nagad: { icon: 'fas fa-mobile-alt', color: '#e2122c', name: 'Nagad', detail: '<?php echo $nagadJs; ?> (Personal)' }
        };
        const info = document.getElementById('gatewayInfo');
        const paymentNote = '<?php echo $paymentNote ? htmlspecialchars($paymentNote, ENT_QUOTES) : ''; ?>';
        document.querySelectorAll('.pay-option').forEach(opt => {
            opt.addEventListener('click', function () {
                document.querySelectorAll('.pay-option').forEach(o => o.classList.remove('active'));
                this.classList.add('active');
                const m = gateways[this.dataset.method];
                this.querySelector('i').style.color = m.color;
                let html = 'Send <strong><?php echo formatCurrency($plan['price']); ?></strong> to:<br><strong>' +
                    m.name + ': ' + m.detail + '</strong>';
                if (paymentNote) { html += '<br><span style="font-size:.8rem;color:#6b7280;">' + paymentNote + '</span>'; }
                info.innerHTML = html;
            });
        });
    </script>
</body>

</html>
