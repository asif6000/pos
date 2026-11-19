<?php
/**
 * Subscriptions / packages.
 *
 * A shop owner buys a plan (Starter / Business / Pro) for a month or a year
 * through UddoktaPay (see config/uddoktapay.php). This file owns the two tables
 * that record that - one current `subscriptions` row per owner, and a
 * `subscription_payments` ledger of every checkout attempt - plus the helpers the
 * landing page, the checkout and the admin banner all share.
 *
 * Money rule: the amount charged always comes from config/plans.php on the
 * server. Nothing here trusts an amount that arrived from a browser.
 */

if (!function_exists('subPlans')) {

    /** @return array<string,array> plan slug => definition */
    function subPlans()
    {
        static $plans = null;
        if ($plans === null) {
            $plans = require __DIR__ . '/plans.php';
        }
        return $plans;
    }

    /** @return array|null the plan definition, or null for an unknown slug */
    function subPlan($slug)
    {
        $plans = subPlans();
        return isset($plans[$slug]) ? $plans[$slug] : null;
    }

    /** Server-side price for a plan + cycle. Unknown combo returns 0. */
    function subPlanPrice($slug, $cycle)
    {
        $plan = subPlan($slug);
        if (!$plan) { return 0; }
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        return (int)$plan[$cycle];
    }

    /** How many months a cycle buys. */
    function subCycleMonths($cycle)
    {
        return $cycle === 'yearly' ? 12 : 1;
    }

    /** Trial length in days for a plan (0 when it is not a trial plan). */
    function subPlanTrialDays($slug)
    {
        $plan = subPlan($slug);
        return $plan && isset($plan['trial_days']) ? (int)$plan['trial_days'] : 0;
    }

    /**
     * Auto-grant the free trial to a freshly registered owner.
     *
     * Only ever creates a row when the owner has no subscription yet, so calling
     * this again (or on an account that already paid) can never wipe or shorten a
     * real subscription. Returns the subscription row, or null if it did nothing.
     */
    function subGrantTrial($db, $ownerId, $userId, $slug = 'free')
    {
        $days = subPlanTrialDays($slug);
        if ($days <= 0) { return null; }
        if (subGetCurrent($db, $ownerId)) { return null; }   // never overwrite an existing plan

        $now = new DateTime();
        $exp = (clone $now)->modify('+' . $days . ' day');
        $db->prepare(
            "INSERT INTO subscriptions
                (owner_id, user_id, plan_slug, billing_cycle, status, started_at, expires_at, amount)
             VALUES (?, ?, ?, 'monthly', 'active', ?, ?, 0)"
        )->execute([(int)$ownerId, (int)$userId, $slug, $now->format('Y-m-d H:i:s'), $exp->format('Y-m-d H:i:s')]);
        return subGetCurrent($db, $ownerId);
    }

    /**
     * Create the subscription tables on first use (XAMPP) and assert they exist.
     * On a cPanel DB user without CREATE, appSafeDdl swallows the failure and the
     * migration file config/migration_subscriptions.sql is the way to make them.
     */
    function subEnsureTables($db)
    {
        appSafeDdl($db, "CREATE TABLE IF NOT EXISTS subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            user_id INT NULL,
            plan_slug VARCHAR(40) NOT NULL,
            billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
            status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_owner (owner_id),
            INDEX idx_status (status),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB");

        appSafeDdl($db, "CREATE TABLE IF NOT EXISTS subscription_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            user_id INT NULL,
            plan_slug VARCHAR(40) NOT NULL,
            billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
            amount DECIMAL(10,2) NOT NULL,
            action ENUM('new','renew','upgrade','downgrade') NOT NULL DEFAULT 'new',
            gateway VARCHAR(30) NOT NULL DEFAULT 'uddoktapay',
            gateway_invoice_id VARCHAR(120) NULL,
            gateway_txn_id VARCHAR(120) NULL,
            status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
            payment_url TEXT NULL,
            payer_name VARCHAR(120) NULL,
            payer_email VARCHAR(160) NULL,
            verify_payload TEXT NULL,
            paid_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_owner (owner_id),
            INDEX idx_status (status),
            INDEX idx_invoice (gateway_invoice_id)
        ) ENGINE=InnoDB");

        appRequireTables(['subscriptions', 'subscription_payments'], $db);
    }

    /** The owner's current subscription row, or null if they never bought one. */
    function subGetCurrent($db, $ownerId)
    {
        $st = $db->prepare("SELECT * FROM subscriptions WHERE owner_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([(int)$ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Human-usable status of an owner's subscription.
     * state: none | active | expiring (<=7 days) | expired.
     */
    function subStatus($db, $ownerId)
    {
        $cur = subGetCurrent($db, $ownerId);
        if (!$cur) {
            return ['state' => 'none', 'plan' => null, 'plan_name' => null,
                    'cycle' => null, 'expires_at' => null, 'days_left' => null];
        }
        $expiresTs = strtotime($cur['expires_at']);
        $daysLeft  = (int)floor(($expiresTs - time()) / 86400);
        if ($expiresTs <= time()) {
            $state = 'expired';
        } elseif ($daysLeft <= 7) {
            $state = 'expiring';
        } else {
            $state = 'active';
        }
        $plan = subPlan($cur['plan_slug']);
        return [
            'state'      => $state,
            'plan'       => $cur['plan_slug'],
            'plan_name'  => $plan ? $plan['name'] : ucfirst($cur['plan_slug']),
            'cycle'      => $cur['billing_cycle'],
            'expires_at' => $cur['expires_at'],
            'days_left'  => $daysLeft,
        ];
    }

    /**
     * What the button for a given plan should say / do relative to the owner's
     * current plan. Used by the landing pricing cards.
     * action: new | renew | upgrade | downgrade | current
     */
    function subPlanAction($db, $ownerId, $slug)
    {
        $status = subStatus($db, $ownerId);
        if ($status['state'] === 'none' || $status['state'] === 'expired') {
            return 'new';
        }
        if ($status['plan'] === $slug) {
            return 'renew';
        }
        $order = ['starter' => 1, 'business' => 2, 'pro' => 3];
        $curRank = isset($order[$status['plan']]) ? $order[$status['plan']] : 0;
        $newRank = isset($order[$slug]) ? $order[$slug] : 0;
        return $newRank > $curRank ? 'upgrade' : 'downgrade';
    }

    /**
     * Record a checkout attempt as a pending payment and return its row.
     * The amount is looked up server-side from the plan, never passed in.
     */
    function subCreatePendingPayment($db, $ownerId, $userId, $slug, $cycle, $action, $name, $email)
    {
        $amount = subPlanPrice($slug, $cycle);
        $cycle  = $cycle === 'yearly' ? 'yearly' : 'monthly';
        $st = $db->prepare(
            "INSERT INTO subscription_payments
                (owner_id, user_id, plan_slug, billing_cycle, amount, action, gateway, status, payer_name, payer_email)
             VALUES (?, ?, ?, ?, ?, ?, 'uddoktapay', 'pending', ?, ?)"
        );
        $st->execute([(int)$ownerId, (int)$userId, $slug, $cycle, $amount, $action, $name, $email]);
        $id = (int)$db->lastInsertId();
        return subFindPayment($db, $id);
    }

    /** Fetch a payment row by our internal id. */
    function subFindPayment($db, $id)
    {
        $st = $db->prepare("SELECT * FROM subscription_payments WHERE id = ? LIMIT 1");
        $st->execute([(int)$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Store the gateway payment_url on a pending row. */
    function subSetPaymentUrl($db, $id, $url)
    {
        $db->prepare("UPDATE subscription_payments SET payment_url = ? WHERE id = ?")
           ->execute([$url, (int)$id]);
    }

    /** Update a payment's status + gateway references + raw verify payload. */
    function subMarkPayment($db, $id, $status, $invoiceId = null, $txnId = null, $payload = null, $paidAt = null)
    {
        $db->prepare(
            "UPDATE subscription_payments
                SET status = ?, gateway_invoice_id = COALESCE(?, gateway_invoice_id),
                    gateway_txn_id = COALESCE(?, gateway_txn_id),
                    verify_payload = COALESCE(?, verify_payload),
                    paid_at = COALESCE(?, paid_at)
              WHERE id = ?"
        )->execute([$status, $invoiceId, $txnId, $payload, $paidAt, (int)$id]);
    }

    /**
     * Credit a verified payment: create or extend the owner's subscription.
     *
     * Renewing the SAME plan while it is still active extends from the current
     * expiry (so no paid time is lost). Anything else - a new plan, an upgrade, a
     * downgrade, or a renewal after expiry - starts a fresh term from now.
     *
     * @return array the resulting subscription row
     */
    function subApplyPaid($db, array $pay)
    {
        $ownerId = (int)$pay['owner_id'];
        $slug    = $pay['plan_slug'];
        $cycle   = $pay['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly';
        $months  = subCycleMonths($cycle);
        $amount  = (float)$pay['amount'];

        $cur = subGetCurrent($db, $ownerId);
        $now = new DateTime();

        if ($cur && $cur['plan_slug'] === $slug && strtotime($cur['expires_at']) > time()) {
            $base = new DateTime($cur['expires_at']);   // extend - keep paid time
            $startedAt = $cur['started_at'];
        } else {
            $base = clone $now;                          // fresh term
            $startedAt = $now->format('Y-m-d H:i:s');
        }
        $base->modify('+' . $months . ' month');
        $expiresAt = $base->format('Y-m-d H:i:s');

        if ($cur) {
            $db->prepare(
                "UPDATE subscriptions
                    SET user_id = ?, plan_slug = ?, billing_cycle = ?, status = 'active',
                        started_at = ?, expires_at = ?, amount = ?
                  WHERE id = ?"
            )->execute([(int)$pay['user_id'], $slug, $cycle, $startedAt, $expiresAt, $amount, (int)$cur['id']]);
            return subGetCurrent($db, $ownerId);
        }

        $db->prepare(
            "INSERT INTO subscriptions
                (owner_id, user_id, plan_slug, billing_cycle, status, started_at, expires_at, amount)
             VALUES (?, ?, ?, ?, 'active', ?, ?, ?)"
        )->execute([$ownerId, (int)$pay['user_id'], $slug, $cycle, $startedAt, $expiresAt, $amount]);
        return subGetCurrent($db, $ownerId);
    }

    /**
     * Absolute URL of a file that sits in the same directory as the running
     * script. Built from the live request so the same code produces
     * http://localhost/admin/... on XAMPP and https://the-shop/... on cPanel
     * (where the project is the document root) without any hardcoded host.
     */
    function subSelfUrl($file)
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
                 || (($_SERVER['SERVER_PORT'] ?? '') == 443)
                 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/';
        $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return $scheme . '://' . $host . $dir . '/' . ltrim($file, '/');
    }
}
