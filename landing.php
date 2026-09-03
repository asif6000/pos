<?php
/**
 * POS System - Landing Page (Modern Redesign)
 * Shows pricing, features, live stats from admin panel
 */

require_once 'config/db.php';
startSecureSession();

if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user['role'] === 'admin') {
        redirect('admin/dashboard.php');
    } else {
        redirect('cashier/pos.php');
    }
}

$db = getDB();

// ── Live Stats from Admin Panel ──
$stats = ['businesses' => 0, 'products' => 0, 'transactions' => 0, 'users' => 0];
try {
    $stats['businesses'] = (int)$db->query("SELECT COUNT(*) FROM stores WHERE status='active'")->fetchColumn();
    $stats['products'] = (int)$db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
    $stats['transactions'] = (int)$db->query("SELECT COUNT(*) FROM sales")->fetchColumn();
    $stats['users'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
} catch (Exception $e) { /* fallback to 0 */ }

// ── Subscription Plans ──
$plans = [];
try {
    $plans = $db->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price ASC, id ASC")->fetchAll();
} catch (Exception $e) { $plans = []; }

// ── Super Admin Contact & WhatsApp ──
$shopPhone = '01331707900';
$whatsappNumber = '01331707900'; // default
$whatsappMessage = 'Hello! I need support for AVA POS System.';
try {
    $ph = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='shop_phone'")->fetchColumn();
    if ($ph) $shopPhone = $ph;
    $wa = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='whatsapp_number'")->fetchColumn();
    if ($wa) $whatsappNumber = preg_replace('/[^0-9]/', '', $wa);
    $wm = $db->query("SELECT setting_value FROM system_settings WHERE setting_key='whatsapp_message'")->fetchColumn();
    if ($wm) $whatsappMessage = $wm;
} catch (Exception $e) {}
$whatsappUrl = 'https://wa.me/' . $whatsappNumber . '?text=' . urlencode($whatsappMessage);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AVA IT Solution POS System - Smart POS for Smart Business</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/hind-siliguri.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #0056b3;
            --primary-dark: #004494;
            --primary-light: #e6f0fa;
            --accent: #007bff;
            --accent-light: #cce5ff;
            --bg-dark: #ffffff;
            --bg-card: #f8fafc;
            --glass: rgba(255, 255, 255, 0.9);
            --glass-border: rgba(0, 0, 0, 0.1);
            --text: #1e293b;
            --text-muted: #475569;
            --text-dim: #64748b;
            --success: #22c55e;
            --warning: #f59e0b;
            --radius: 16px;
            --radius-sm: 10px;
            --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Hind Siliguri', 'Inter', -apple-system, sans-serif;
            background: #ffffff;
            color: var(--text);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* ─── SCROLLBAR ─── */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }

        /* ─── ANIMATIONS ─── */
        @keyframes fadeUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeLeft { from { opacity: 0; transform: translateX(-30px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes fadeRight { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }
        @keyframes pulse-glow { 0%, 100% { box-shadow: 0 0 20px rgba(59,130,246,0.3); } 50% { box-shadow: 0 0 40px rgba(59,130,246,0.6); } }
        @keyframes gradient-shift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        @keyframes slide-down { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes bounce-in { 0% { transform: scale(0.3); opacity: 0; } 50% { transform: scale(1.05); } 70% { transform: scale(0.9); } 100% { transform: scale(1); opacity: 1; } }
        @keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }

        .animate-on-scroll { opacity: 0; transform: translateY(30px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .animate-on-scroll.visible { opacity: 1; transform: translateY(0); }

        /* ─── NAVBAR ─── */
        .navbar {
            background: rgba(255, 255, 255, 1);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 16px 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid var(--glass-border);
            transition: var(--transition);
        }

        .navbar.scrolled {
            background: rgba(255, 255, 255, 1);
            box-shadow: 0 4px 30px rgba(0,0,0,0.3);
            padding: 12px 60px;
        }

        .navbar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .navbar-logo img { height: 40px; transition: var(--transition); }
        .navbar-logo:hover img { transform: scale(1.05); }

        .navbar-logo-text {
            display: flex;
            flex-direction: column;
        }

        .pos-badge {
            font-size: 0.6rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: 2px;
            text-transform: uppercase;
            border: 1px solid var(--primary);
            padding: 2px 8px;
            border-radius: 4px;
            width: fit-content;
        }

        .navbar-links {
            display: flex;
            gap: 8px;
            list-style: none;
        }

        .navbar-links a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 8px;
            transition: var(--transition);
            position: relative;
        }

        .navbar-links a:hover, .navbar-links a.active {
            color: var(--primary);
            background: rgba(0,86,179,0.08);
        }

        .navbar-links a.active::after {
            content: '';
            position: absolute;
            bottom: 2px;
            left: 50%; right: 50%;
            height: 2px;
            background: var(--primary);
            border-radius: 2px;
            transform: translateX(-50%);
            width: 20px;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .navbar-phone {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .navbar-phone i { color: var(--primary); }

        .btn-ghost {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--glass-border);
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: var(--transition);
            background: transparent;
            cursor: pointer;
        }

        .btn-ghost:hover {
            color: var(--text);
            border-color: rgba(0,0,0,0.15);
            background: var(--glass);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            background-size: 200% 200%;
            animation: gradient-shift 3s ease infinite;
            color: #ffffff;
            padding: 9px 22px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(59,130,246,0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59,130,246,0.45);
        }

        /* Mobile Menu Toggle */
        .mobile-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text);
            font-size: 1.3rem;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: var(--transition);
        }

        .mobile-toggle:hover { background: var(--glass); }

        /* ─── HERO ─── */
        .hero {
            background: #ffffff;
            padding: 80px 60px 60px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 50px;
            position: relative;
            overflow: hidden;
            min-height: 100vh;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -150px; right: -100px;
            width: 800px; height: 800px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.06) 0%, transparent 70%);
            animation: float 10s ease-in-out infinite;
            pointer-events: none;
            filter: blur(40px);
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -200px; left: 10%;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.04) 0%, transparent 70%);
            animation: float 12s ease-in-out infinite reverse;
            pointer-events: none;
            filter: blur(50px);
        }

        .hero-grid {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(0,0,0,0.015) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,0,0,0.015) 1px, transparent 1px);
            background-size: 80px 80px;
            pointer-events: none;
        }

        /* Floating orbs */
        .hero-orb {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
            filter: blur(60px);
        }
        .hero-orb-1 {
            top: 10%; left: 5%;
            width: 300px; height: 300px;
            background: rgba(59,130,246,0.07);
            animation: float 8s ease-in-out infinite;
        }
        .hero-orb-2 {
            top: 20%; right: 5%;
            width: 250px; height: 250px;
            background: rgba(99,102,241,0.05);
            animation: float 10s ease-in-out infinite reverse;
        }
        .hero-orb-3 {
            bottom: 15%; left: 40%;
            width: 200px; height: 200px;
            background: rgba(14,165,233,0.05);
            animation: float 12s ease-in-out infinite;
        }

        /* Hero center text */
        .hero-center {
            text-align: center;
            position: relative;
            z-index: 2;
            max-width: 700px;
            animation: fadeUp 0.8s ease forwards;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            padding: 10px 22px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 700;
            margin-bottom: 28px;
            animation: bounce-in 0.6s ease 0.3s both;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.08);
        }

        .badge-dot {
            width: 8px; height: 8px;
            background: #22c55e;
            border-radius: 50%;
            animation: pulse-glow 2s ease-in-out infinite;
            box-shadow: 0 0 6px rgba(34,197,94,0.5);
        }

        .hero-badge i { font-size: 0.8rem; color: #3b82f6; }

        .hero-title {
            font-size: 3.8rem;
            font-weight: 900;
            line-height: 1.15;
            margin-bottom: 24px;
            letter-spacing: -1.5px;
            color: #0f172a;
        }

        .hero-title .gradient-text {
            background: linear-gradient(135deg, #0056b3, #3b82f6, #0ea5e9);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: gradient-shift 5s ease infinite;
        }

        .hero-desc {
            color: #475569;
            font-size: 1.1rem;
            line-height: 1.8;
            margin-bottom: 36px;
        }

        .hero-buttons {
            display: flex;
            gap: 18px;
            margin-bottom: 36px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn-hero-primary {
            background: #0056b3;
            color: #ffffff;
            padding: 16px 36px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            cursor: pointer;
            box-shadow: 0 10px 25px rgba(0, 86, 179, 0.25);
        }

        .btn-hero-primary:hover {
            transform: translateY(-4px);
            background: #004494;
            box-shadow: 0 15px 35px rgba(0, 86, 179, 0.35);
        }

        .btn-hero-outline {
            background: #ffffff;
            color: #0f172a;
            padding: 16px 36px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0,0,0,0.02);
        }

        .btn-hero-outline:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.05);
        }

        .btn-hero-outline i { color: #0056b3; }

        /* Trust badges */
        .hero-trust {
            display: flex;
            gap: 28px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .hero-trust-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #64748b;
        }

        .hero-trust-item i {
            color: #22c55e;
            font-size: 0.8rem;
        }

        /* Dashboard Mockup */
        .hero-mockup-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 800px;
            animation: fadeUp 0.8s ease 0.3s both;
        }

        .hero-dashboard-mockup {
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.1), 0 0 0 1px rgba(0,0,0,0.04);
            transition: transform 0.5s ease;
        }

        .hero-dashboard-mockup:hover {
            transform: translateY(-6px);
        }

        .mockup-browser-bar {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .mockup-dots {
            display: flex;
            gap: 6px;
        }

        .mockup-dots span {
            width: 10px; height: 10px;
            border-radius: 50%;
        }

        .mockup-url {
            background: #f1f5f9;
            padding: 4px 14px;
            border-radius: 6px;
            font-size: 0.7rem;
            color: #64748b;
            display: flex;
            align-items: center;
        }

        .mockup-dashboard-body {
            display: flex;
            min-height: 260px;
        }

        .mockup-sidebar-mini {
            width: 50px;
            background: #0f172a;
            padding: 12px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .ms-item {
            width: 34px; height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
            font-size: 0.7rem;
            cursor: pointer;
            transition: 0.2s;
        }

        .ms-item.active {
            background: rgba(59,130,246,0.2);
            color: #60a5fa;
        }

        .ms-item:hover { color: #94a3b8; }

        .mockup-main-content {
            flex: 1;
            background: #f8fafc;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .mockup-stat-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .mc-stat {
            background: #ffffff;
            border-radius: 10px;
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }

        .mc-stat-icon {
            width: 30px; height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            flex-shrink: 0;
        }

        .mc-stat-num {
            font-size: 0.75rem;
            font-weight: 800;
            color: #0f172a;
        }

        .mc-stat-label {
            font-size: 0.55rem;
            color: #64748b;
        }

        .mockup-chart-area {
            background: #ffffff;
            border-radius: 10px;
            padding: 14px;
            flex: 1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }

        .mc-chart-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .mc-chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 100px;
        }

        @keyframes bar-grow {
            from { height: 0%; opacity: 0; }
            to { opacity: 1; }
        }

        .mc-bar {
            flex: 1;
            background: linear-gradient(180deg, #3b82f6, #0056b3);
            border-radius: 4px 4px 0 0;
            animation: bar-grow 1s ease forwards;
            animation-delay: var(--delay, 0s);
            opacity: 0;
        }

        /* Floating notification cards */
        .floating-card {
            position: absolute;
            background: #ffffff;
            border-radius: 12px;
            padding: 12px 16px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border: 1px solid #f1f5f9;
            z-index: 5;
        }

        .fc-1 {
            top: 20%; right: -40px;
            animation: float 4s ease-in-out infinite;
        }

        .fc-2 {
            bottom: 25%; left: -40px;
            animation: float 5s ease-in-out infinite reverse;
        }

        .hero-feature-item .feature-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: #1e293b;
        }

        .hero-feature-item .feature-desc {
            font-size: 0.72rem;
            color: var(--text-dim);
            line-height: 1.4;
        }

        /* Hero right - POS mockup */
        .hero-right {
            flex: 1;
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
            align-items: flex-end;
            min-height: 400px;
            animation: fadeRight 0.8s ease 0.2s both;
        }

        .pos-mockup {
            position: relative;
            display: flex;
            align-items: flex-end;
            gap: 18px;
            animation: float 6s ease-in-out infinite;
        }

        .pos-monitor-wrap { position: relative; }

        .pos-monitor {
            width: 360px;
            height: 245px;
            background: #111827;
            border-radius: 14px;
            border: 2px solid #1f2937;
            overflow: hidden;
            box-shadow: 0 25px 70px rgba(0,0,0,0.6), 0 0 40px rgba(59,130,246,0.1);
        }

        .pos-monitor-header {
            background: linear-gradient(90deg, #1e293b, #0f172a);
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .pos-monitor-header .logo-small {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pos-monitor-header .logo-small img { height: 18px; }

        .pos-monitor-header .search-bar {
            background: rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 0.7rem;
            color: #475569;
            width: 120px;
        }

        .pos-monitor-body { display: flex; height: calc(100% - 38px); }

        .pos-sidebar {
            width: 72px;
            background: #ffffff;
            padding: 8px 0;
        }

        .pos-sidebar-item {
            padding: 6px 10px;
            font-size: 0.55rem;
            color: #4b5563;
            cursor: pointer;
            transition: 0.2s;
        }

        .pos-sidebar-item.active {
            background: rgba(59,130,246,0.2);
            color: var(--primary-light);
            border-left: 2px solid var(--primary);
        }

        .pos-content { flex: 1; background: #111827; padding: 8px; }

        .pos-category-tabs { display: flex; gap: 4px; margin-bottom: 8px; }

        .pos-tab {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.6rem;
            background: rgba(0,0,0,0.04);
            color: #6b7280;
            border: 1px solid transparent;
        }

        .pos-tab.active {
            background: rgba(59,130,246,0.15);
            color: var(--primary-light);
            border-color: rgba(59,130,246,0.3);
        }

        .pos-products { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; }

        .pos-product {
            background: rgba(0,0,0,0.02);
            border: 1px solid rgba(0,0,0,0.04);
            border-radius: 6px;
            padding: 5px;
            text-align: center;
            transition: 0.2s;
        }

        .pos-product:hover { border-color: rgba(59,130,246,0.3); }

        .pos-product-icon {
            width: 100%;
            height: 30px;
            background: rgba(0,0,0,0.03);
            border-radius: 4px;
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-light);
            font-size: 0.65rem;
        }

        .pos-product-name { font-size: 0.5rem; color: #475569; }
        .pos-product-price { font-size: 0.55rem; color: var(--primary-light); font-weight: 700; }

        .pos-cart {
            width: 95px;
            background: #ffffff;
            padding: 6px;
            border-left: 1px solid #1f2937;
        }

        .pos-cart-title {
            font-size: 0.6rem;
            color: #475569;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .pos-cart-item {
            background: rgba(0,0,0,0.02);
            border-radius: 4px;
            padding: 3px 5px;
            margin-bottom: 3px;
            display: flex;
            justify-content: space-between;
            font-size: 0.5rem;
            color: #334155;
        }

        .pos-cart-total {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 5px;
            padding: 5px;
            text-align: center;
            font-size: 0.6rem;
            font-weight: 700;
            color: #ffffff;
            margin-top: 6px;
        }

        .pos-monitor-stand {
            width: 40px; height: 18px;
            background: #1f2937;
            margin: 0 auto;
            clip-path: polygon(25% 0%, 75% 0%, 90% 100%, 10% 100%);
        }

        .pos-base {
            width: 100px; height: 8px;
            background: linear-gradient(90deg, #1f2937, #374151, #1f2937);
            margin: 0 auto;
            border-radius: 4px;
        }

        .pos-printer {
            width: 100px;
            background: linear-gradient(180deg, #1f2937, #111827);
            border-radius: 10px;
            padding: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .printer-top {
            width: 100%; height: 40px;
            background: rgba(0,0,0,0.03);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .printer-slot {
            width: 80%; height: 3px;
            background: rgba(0,0,0,0.08);
            border-radius: 2px;
            margin: 0 auto;
        }

        .printer-receipt {
            width: 55px;
            background: #ffffff;
            margin: 0 auto;
            border-radius: 0 0 3px 3px;
            padding: 4px;
        }

        .receipt-line {
            height: 2px;
            background: #d1d5db;
            border-radius: 1px;
            margin-bottom: 2px;
        }

        .receipt-line.short { width: 60%; }
        .receipt-line.medium { width: 80%; }

        .pos-scanner {
            width: 60px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .scanner-head {
            width: 40px; height: 55px;
            background: linear-gradient(180deg, #1f2937, #111827);
            border-radius: 6px 6px 10px 10px;
            position: relative;
            box-shadow: 0 5px 15px rgba(0,0,0,0.5);
        }

        .scanner-lens {
            position: absolute;
            top: 8px; left: 50%;
            transform: translateX(-50%);
            width: 25px; height: 20px;
            background: #ffffff;
            border-radius: 5px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .scanner-red {
            position: absolute;
            bottom: 6px; left: 50%;
            transform: translateX(-50%);
            width: 20px; height: 3px;
            background: #ef4444;
            border-radius: 2px;
            box-shadow: 0 0 10px rgba(239,68,68,0.8);
            animation: pulse-glow 2s ease-in-out infinite;
        }

        .scanner-base {
            width: 20px; height: 40px;
            background: linear-gradient(180deg, #1f2937, #111827);
            border-radius: 10px;
        }

        .scanner-foot {
            width: 45px; height: 8px;
            background: #ffffff;
            border-radius: 4px;
        }

        /* ─── STATS BAR ─── */
        .stats-bar {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            margin: -55px 60px 0;
            border-radius: 20px;
            padding: 40px 50px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.15);
            position: relative;
            z-index: 10;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 18px;
            transition: var(--transition);
        }

        .stat-item:hover { transform: translateY(-3px); }

        .stat-icon {
            width: 58px; height: 58px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
            transition: var(--transition);
        }

        .stat-icon.blue { background: #eff6ff; color: #3b82f6; }
        .stat-icon.green { background: #f0fdf4; color: #22c55e; }
        .stat-icon.orange { background: #fff7ed; color: #f97316; }
        .stat-icon.purple { background: #faf5ff; color: #a855f7; }

        .stat-item:hover .stat-icon { transform: scale(1.1); }

        .stat-info .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            color: #111827;
            line-height: 1;
        }

        .stat-info .stat-label {
            font-size: 0.82rem;
            color: #6b7280;
            margin-top: 4px;
        }

        /* ─── FEATURES ─── */
        .features-section {
            background: #ffffff;
            padding: 110px 60px 90px;
        }

        .features-inner {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 60px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .features-left-col { display: flex; flex-direction: column; }

        .features-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eff6ff;
            color: #3b82f6;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            margin-bottom: 22px;
            width: fit-content;
        }

        .features-title {
            font-size: 2.3rem;
            font-weight: 800;
            color: #111827;
            line-height: 1.2;
            margin-bottom: 18px;
        }

        .features-title .highlight { color: #3b82f6; }

        .features-desc {
            color: #6b7280;
            font-size: 0.95rem;
            line-height: 1.7;
            margin-bottom: 30px;
        }

        .btn-view-all {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: var(--text);
            padding: 14px 28px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            transition: var(--transition);
            width: fit-content;
            box-shadow: 0 4px 15px rgba(59,130,246,0.3);
        }

        .btn-view-all:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(59,130,246,0.45);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }

        .feature-card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            border: 1px solid #f1f5f9;
            transition: var(--transition);
            cursor: default;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            transform: scaleX(0);
            transition: var(--transition);
        }

        .feature-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.1);
            border-color: transparent;
        }

        .feature-card:hover::before { transform: scaleX(1); }

        .feature-card-icon {
            width: 50px; height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 18px;
            transition: var(--transition);
        }

        .feature-card:hover .feature-card-icon { transform: scale(1.1) rotate(-5deg); }

        .feature-card-icon.blue { background: #eff6ff; color: #3b82f6; }
        .feature-card-icon.green { background: #f0fdf4; color: #22c55e; }
        .feature-card-icon.orange { background: #fff7ed; color: #f97316; }
        .feature-card-icon.purple { background: #faf5ff; color: #a855f7; }
        .feature-card-icon.pink { background: #fdf2f8; color: #ec4899; }
        .feature-card-icon.teal { background: #f0fdfa; color: #14b8a6; }

        .feature-card h4 {
            font-size: 0.95rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }

        .feature-card p {
            font-size: 0.82rem;
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .feature-card .learn-more {
            font-size: 0.82rem;
            font-weight: 700;
            color: #3b82f6;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
        }

        .feature-card .learn-more:hover { gap: 10px; }

        /* ─── TRUSTED BY ─── */
        .trusted-section {
            background: #ffffff;
            padding: 70px 60px;
            text-align: center;
        }

        .trusted-label {
            color: var(--text-dim);
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 40px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .trusted-logos {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 50px;
            flex-wrap: wrap;
        }

        .trusted-logo-item {
            color: var(--text-dim);
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0.5;
            transition: var(--transition);
        }

        .trusted-logo-item:hover { opacity: 1; color: var(--text); transform: translateY(-2px); }
        .trusted-logo-item i { font-size: 1.2rem; color: var(--primary); }

        /* ─── PRICING ─── */
        .pricing-section {
            background: #ffffff;
            padding: 100px 60px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .pricing-section::before {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 800px; height: 800px;
            background: radial-gradient(circle, rgba(59,130,246,0.06) 0%, transparent 70%);
            pointer-events: none;
        }

        .pricing-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(59,130,246,0.12);
            border: 1px solid rgba(59,130,246,0.25);
            color: #2563eb;
            padding: 8px 20px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            margin-bottom: 24px;
            letter-spacing: 0.5px;
        }

        .pricing-heading {
            font-size: 2.5rem;
            font-weight: 900;
            color: var(--text);
            margin-bottom: 14px;
            letter-spacing: -0.5px;
        }

        .pricing-heading .gradient-text {
            background: linear-gradient(135deg, var(--primary), var(--accent-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .pricing-sub {
            color: var(--text-muted);
            font-size: 1.05rem;
            margin-bottom: 55px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 28px;
            max-width: 1100px;
            margin: 0 auto;
            align-items: stretch;
        }

        .price-card {
            background: var(--bg-card);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 36px 30px;
            text-align: left;
            position: relative;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .price-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
            border-color: rgba(59,130,246,0.3);
        }

        .price-card.popular {
            border: 2px solid var(--primary);
            background: linear-gradient(180deg, rgba(59,130,246,0.08) 0%, var(--bg-card) 100%);
            box-shadow: 0 25px 50px rgba(59,130,246,0.2);
        }

        .price-card.popular::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 20px;
            padding: 2px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .price-badge-tag {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: var(--text);
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            padding: 6px 20px;
            border-radius: 999px;
            text-transform: uppercase;
            box-shadow: 0 4px 15px rgba(59,130,246,0.4);
        }

        .price-name {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 8px;
        }

        .price-amount {
            font-size: 2.8rem;
            font-weight: 900;
            color: var(--text);
            line-height: 1;
            margin: 12px 0 6px;
        }

        .price-amount span {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dim);
        }

        .price-meta {
            font-size: 0.82rem;
            color: var(--text-dim);
            margin-bottom: 20px;
        }

        .price-period {
            display: inline-block;
            background: rgba(59,130,246,0.12);
            color: #2563eb;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 4px 14px;
            border-radius: 999px;
            margin-bottom: 20px;
        }

        .price-desc {
            font-size: 0.88rem;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 22px;
            flex: 1;
        }

        .price-features {
            list-style: none;
            padding: 0;
            margin: 0 0 28px;
            flex: 1;
        }

        .price-features li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 0.86rem;
            color: var(--text-muted);
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.03);
        }

        .price-features li:last-child { border-bottom: none; }

        .price-features li i {
            color: var(--success);
            font-size: 0.8rem;
            margin-top: 3px;
            flex-shrink: 0;
        }

        .price-btn {
            display: block;
            text-align: center;
            background: transparent;
            color: var(--primary);
            border: 2px solid rgba(59,130,246,0.4);
            padding: 14px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .price-btn:hover {
            background: rgba(59,130,246,0.1);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .price-card.popular .price-btn {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #ffffff;
            border-color: transparent;
            box-shadow: 0 4px 15px rgba(59,130,246,0.35);
        }

        .price-card.popular .price-btn:hover {
            box-shadow: 0 8px 25px rgba(59,130,246,0.5);
            transform: translateY(-2px);
        }

        /* ─── CTA SECTION ─── */
        .cta-section {
            padding: 100px 60px;
            text-align: center;
            position: relative;
        }

        .cta-box {
            max-width: 700px;
            margin: 0 auto;
            background: linear-gradient(135deg, rgba(59,130,246,0.1), rgba(139,92,246,0.1));
            border: 1px solid rgba(59,130,246,0.2);
            border-radius: 24px;
            padding: 60px 50px;
            position: relative;
            overflow: hidden;
        }

        .cta-box::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: conic-gradient(from 0deg, transparent 0%, rgba(59,130,246,0.05) 25%, transparent 50%);
            animation: gradient-shift 8s linear infinite;
            pointer-events: none;
        }

        .cta-box h2 {
            font-size: 2.2rem;
            font-weight: 900;
            margin-bottom: 16px;
            position: relative;
        }

        .cta-box p {
            color: var(--text-muted);
            font-size: 1.05rem;
            line-height: 1.7;
            margin-bottom: 32px;
            position: relative;
        }

        .cta-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
            position: relative;
        }

        /* ─── FOOTER ─── */
        footer {
            background: #ffffff;
            padding: 60px 60px 30px;
            border-top: 1px solid rgba(0,0,0,0.03);
        }

        .footer-top {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 50px;
            margin-bottom: 40px;
        }

        .footer-brand p {
            color: var(--text-dim);
            font-size: 0.88rem;
            line-height: 1.7;
            margin-top: 16px;
            max-width: 320px;
        }

        .footer-col h4 {
            color: var(--text);
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .footer-col a {
            display: block;
            color: var(--text-dim);
            text-decoration: none;
            font-size: 0.85rem;
            padding: 5px 0;
            transition: var(--transition);
        }

        .footer-col a:hover { color: var(--primary-light); padding-left: 5px; }

        .footer-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 24px;
            border-top: 1px solid rgba(0,0,0,0.04);
        }

        .footer-bottom p {
            color: var(--text-dim);
            font-size: 0.82rem;
        }

        .footer-bottom a {
            color: var(--text-dim);
            text-decoration: none;
            font-size: 0.82rem;
            transition: var(--transition);
        }

        .footer-bottom a:hover { color: var(--primary-light); }

        .footer-social {
            display: flex;
            gap: 12px;
        }

        .footer-social a {
            width: 36px; height: 36px;
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dim);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .footer-social a:hover {
            background: rgba(59,130,246,0.15);
            border-color: rgba(59,130,246,0.3);
            color: var(--primary-light);
            transform: translateY(-2px);
        }

        /* ─── RESPONSIVE ─── */
        @media (max-width: 1024px) {
            .navbar { padding: 14px 30px; }
            .navbar-links { display: none; }
            .navbar-phone { display: none; }
            .mobile-toggle { display: block; }
            .hero { padding: 60px 30px 80px; flex-direction: column; text-align: center; min-height: auto; }
            .hero-left { max-width: 100%; }
            .hero-title { font-size: 2.6rem; }
            .hero-buttons { justify-content: center; }
            .hero-features { grid-template-columns: repeat(2, 1fr); max-width: 400px; margin: 0 auto; }
            .hero-right { width: 100%; margin-top: 30px; }
            .stats-bar { margin: -40px 30px 0; padding: 30px; grid-template-columns: repeat(2, 1fr); }
            .features-section { padding: 80px 30px 60px; }
            .features-inner { grid-template-columns: 1fr; text-align: center; }
            .features-badge { margin: 0 auto 22px; }
            .features-desc { margin: 0 auto 30px; }
            .btn-view-all { margin: 0 auto; }
            .features-grid { grid-template-columns: repeat(2, 1fr); }
            .pricing-section { padding: 80px 30px; }
            .footer-top { grid-template-columns: 1fr 1fr; }
            .cta-section { padding: 80px 30px; }
        }

        /* Mobile menu overlay */
        .mobile-menu {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.98);
            backdrop-filter: blur(20px);
            z-index: 999;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 20px;
            animation: fadeUp 0.3s ease;
        }

        .mobile-menu.open { display: flex; }

        .mobile-menu a {
            color: var(--text);
            text-decoration: none;
            font-size: 1.2rem;
            font-weight: 600;
            padding: 12px 24px;
            border-radius: 12px;
            transition: var(--transition);
        }

        .mobile-menu a:hover { background: var(--glass); color: var(--primary-light); }

        .mobile-close {
            position: absolute;
            top: 20px; right: 30px;
            background: none;
            border: none;
            color: var(--text);
            font-size: 1.5rem;
            cursor: pointer;
        }

        @media (max-width: 600px) {
            .hero-title { font-size: 2rem; }
            .hero-features { grid-template-columns: 1fr 1fr; }
            .stats-bar { grid-template-columns: 1fr; margin: -30px 20px 0; }
            .features-grid { grid-template-columns: 1fr; }
            .pricing-grid { grid-template-columns: 1fr; max-width: 350px; margin: 0 auto; }
            .footer-top { grid-template-columns: 1fr; }
            .footer-bottom { flex-direction: column; gap: 12px; text-align: center; }
            .cta-box { padding: 40px 24px; }
            .cta-buttons { flex-direction: column; align-items: center; }
        }

        /* ── Tutorial / Demo Section ── */
        .tutorial-section { scroll-margin-top: 80px; position: relative; overflow: hidden; }
        .demo-stage { position: relative; }

        /* Animated glow orbs behind demo */
        .demo-orb { position: absolute; border-radius: 50%; filter: blur(60px); opacity: .5; will-change: transform; z-index: 0; }
        .demo-orb.o1 { width: 320px; height: 320px; background: #6366f1; top: -80px; left: -60px; animation: orbFloat 9s ease-in-out infinite; }
        .demo-orb.o2 { width: 280px; height: 280px; background: #a855f7; bottom: -80px; right: -50px; animation: orbFloat 11s ease-in-out infinite reverse; }
        .demo-orb.o3 { width: 200px; height: 200px; background: #22d3ee; top: 40%; left: 60%; opacity: .3; animation: orbFloat 13s ease-in-out infinite; }
        @keyframes orbFloat {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(20px, -20px) scale(1.1); }
        }

        .demo-video-container {
            position: relative;
            z-index: 1;
            background: linear-gradient(160deg, #1e293b, #0f172a 60%, #1e1b4b);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 30px 70px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(255,255,255,.06);
            border: 1px solid #334155;
            transform-style: preserve-3d;
            will-change: transform;
        }
        .demo-particles { position: absolute; inset: 0; overflow: hidden; pointer-events: none; z-index: 2; }
        .demo-particle {
            position: absolute;
            bottom: -10px;
            width: 6px; height: 6px;
            border-radius: 50%;
            background: radial-gradient(circle, #a78bfa, transparent);
            opacity: 0;
            animation: particleUp linear infinite;
        }
        @keyframes particleUp {
            0% { transform: translateY(0) scale(1); opacity: 0; }
            10% { opacity: .8; }
            100% { transform: translateY(-560px) scale(.3); opacity: 0; }
        }
        .demo-confetti { position: absolute; top: -12px; width: 8px; height: 14px; opacity: 0; animation: confettiFall 2.4s linear infinite; z-index: 3; pointer-events: none; }
        @keyframes confettiFall {
            0% { transform: translateY(0) rotate(0); opacity: 1; }
            100% { transform: translateY(500px) rotate(720deg); opacity: 0; }
        }

        .demo-browser-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 13px 18px;
            background: rgba(15, 23, 42, 0.92);
            border-bottom: 1px solid #1e293b;
            position: relative; z-index: 4;
        }
        .demo-screen { display: flex; background: radial-gradient(circle at 20% 0%, #f1f5f9, #e7ecf3); min-height: 420px; position: relative; z-index: 3; }
        .demo-sidebar {
            width: 64px;
            background: rgba(255,255,255,.9);
            backdrop-filter: blur(6px);
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 16px 0;
            gap: 10px;
        }
        .demo-sb-item {
            width: 40px; height: 40px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #94a3b8;
            font-size: 0.95rem;
            background: #f8fafc;
            transition: all .3s;
        }
        .demo-sb-item.active { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; box-shadow: 0 8px 18px rgba(99,102,241,.35); }
        .demo-main { flex: 1; padding: 26px; display: flex; flex-direction: column; }
        .demo-step-indicator {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            margin: 0 0 22px;
            padding: 0 4px;
        }
        .demo-indicator-line { position: absolute; top: 50%; left: 6%; right: 6%; height: 2px; background: #e2e8f0; z-index: 0; transform: translateY(-50%); }
        .demo-indicator-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #6366f1, #a855f7); border-radius: 999px; transition: width .6s cubic-bezier(.65,0,.35,1); }
        .demo-step-dot {
            position: relative;
            z-index: 1;
            width: 34px; height: 34px;
            border-radius: 50%;
            background: #ffffff;
            border: 2px solid #cbd5e1;
            color: #94a3b8;
            font-size: 0.7rem;
            font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: all .3s cubic-bezier(.65,0,.35,1);
            box-shadow: 0 2px 8px rgba(15,23,42,.06);
        }
        .demo-step-dot:hover { transform: translateY(-2px); border-color: #a5b4fc; color: #6366f1; }
        .demo-step-dot.active {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 0 0 5px rgba(99,102,241,.15), 0 10px 20px rgba(99,102,241,.35);
            transform: scale(1.12);
        }
        .demo-step-dot.done { background: #22c55e; border-color: transparent; color: #fff; }
        .demo-steps-wrapper { position: relative; flex: 1; perspective: 1200px; }
        .demo-step {
            position: absolute; inset: 0;
            opacity: 0;
            transform: translateX(60px) scale(.96) rotateY(-4deg);
            transform-origin: left center;
            transition: opacity .55s ease, transform .6s cubic-bezier(.22,1,.36,1);
            pointer-events: none;
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(4px);
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            padding: 22px;
            box-shadow: 0 16px 40px rgba(15,23,42,.08);
        }
        .demo-step.active { opacity: 1; transform: translateX(0) scale(1) rotateY(0); pointer-events: auto; position: relative; }
        .demo-step.leave-left { opacity: 0; transform: translateX(-60px) scale(.96) rotateY(4deg); }
        .demo-step-label { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; font-weight: 700; color: #0f172a; margin-bottom: 16px; }
        .demo-step-label i { font-size: 1rem; }
        .demo-product-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .demo-prod {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            position: relative;
            text-align: center;
            transition: all .3s;
        }
        .demo-prod-selected { border-color: #6366f1; background: #eef2ff; box-shadow: 0 10px 24px rgba(99,102,241,.18); transform: translateY(-2px); }
        .dp-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-size: 1.05rem; animation: iconPulse 2.4s ease-in-out infinite; }
        @keyframes iconPulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.08); } }
        .dp-name { font-size: 0.78rem; font-weight: 700; color: #0f172a; }
        .dp-price { font-size: 0.72rem; color: #64748b; }
        .dp-check {
            position: absolute; top: 8px; right: 8px;
            width: 22px; height: 22px; border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff;
            font-size: 0.6rem;
            display: flex; align-items: center; justify-content: center;
            animation: checkPop .5s cubic-bezier(.34,1.56,.64,1);
        }
        @keyframes checkPop { 0% { transform: scale(0); } 100% { transform: scale(1); } }
        .demo-cart-view { display: flex; flex-direction: column; gap: 9px; }
        .dc-item { display: flex; justify-content: space-between; font-size: 0.85rem; color: #334155; animation: rowIn .5s both; }
        .dc-item:nth-child(2) { animation-delay: .1s; }
        @keyframes rowIn { from { opacity: 0; transform: translateX(-16px); } to { opacity: 1; transform: none; } }
        .dc-divider { height: 1px; background: #e2e8f0; }
        .dc-total { display: flex; justify-content: space-between; font-size: 0.8rem; color: #64748b; }
        .dc-discount { display: flex; justify-content: space-between; font-size: 0.8rem; color: #10b981; }
        .dc-grand { display: flex; justify-content: space-between; font-size: 1rem; font-weight: 800; color: #0f172a; }
        .demo-payment { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .dp-method {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 8px;
            text-align: center;
            font-size: 0.78rem;
            color: #475569;
            background: #f8fafc;
            transition: all .3s;
        }
        .dp-method-active { border-color: #8b5cf6; background: linear-gradient(135deg,#f5f3ff,#ede9fe); color: #7c3aed; font-weight: 700; transform: translateY(-2px); box-shadow: 0 8px 18px rgba(139,92,246,.2); }
        .demo-pay-input, .demo-change {
            margin-top: 14px;
            font-size: 0.8rem;
            color: #334155;
            display: flex;
            justify-content: space-between;
        }
        .demo-typing-amount { font-weight: 800; color: #7c3aed; border-right: 2px solid #7c3aed; padding-right: 4px; animation: blink 1s step-end infinite; }
        @keyframes blink { 50% { border-color: transparent; } }
        .demo-change { background: #f0fdf4; color: #16a34a; padding: 10px; border-radius: 10px; border: 1px solid #bbf7d0; font-weight: 700; animation: changeSlide .6s both; }
        @keyframes changeSlide { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        .demo-receipt { background: #fff; border: 1px dashed #cbd5e1; border-radius: 4px; padding: 16px; max-width: 240px; margin: 0 auto; position: relative; }
        .demo-receipt::after, .demo-receipt::before {
            content: ''; position: absolute; width: 18px; height: 18px; border-radius: 50%;
            background: #f1f5f9; left: -1px; top: 30%;
        }
        .demo-receipt::after { left: auto; right: -1px; }
        .dr-line { height: 1px; background: #cbd5e1; margin: 8px 0; }
        .dr-item { display: flex; justify-content: space-between; font-size: 0.72rem; color: #334155; }
        .demo-success-check {
            width: 68px; height: 68px;
            border-radius: 50%;
            background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #16a34a;
            font-size: 2.2rem;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            animation: successPop .6s cubic-bezier(.34,1.56,.64,1);
            box-shadow: 0 0 0 0 rgba(34,197,94,.5);
        }
        @keyframes successPop { 0% { transform: scale(.3); opacity: 0; } 60% { transform: scale(1.12); } 100% { transform: scale(1); opacity: 1; } }

        /* Controls bar */
        .demo-controls {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 16px 4px 0;
            position: relative; z-index: 4;
        }
        .demo-progress-bar { flex: 1; height: 6px; background: rgba(226,232,240,.6); border-radius: 999px; overflow: hidden; }
        .demo-progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #6366f1, #a855f7, #22d3ee); background-size: 200% 100%; border-radius: 999px; transition: width .3s linear; animation: gradShift 3s linear infinite; }
        @keyframes gradShift { to { background-position: -200% 0; } }
        .demo-step-caption { font-size: 0.78rem; color: #64748b; font-weight: 600; white-space: nowrap; }
        .demo-play-btn {
            width: 42px; height: 42px; border-radius: 50%;
            border: none; cursor: pointer;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff; font-size: 0.85rem;
            display: flex; align-items: center; justify-content: center;
            transition: all .3s; box-shadow: 0 8px 20px rgba(99,102,241,.35);
        }
        .demo-play-btn:hover { transform: scale(1.1); }
        .demo-arrow {
            width: 34px; height: 34px; border-radius: 50%;
            border: 1px solid #e2e8f0; background: #fff; color: #64748b;
            cursor: pointer; font-size: 0.8rem;
            display: flex; align-items: center; justify-content: center;
            transition: all .25s;
        }
        .demo-arrow:hover { border-color: #a5b4fc; color: #6366f1; transform: translateX(-2px); }
        .demo-arrow.next:hover { transform: translateX(2px); }
        .demo-nav-group { display: flex; gap: 8px; align-items: center; }

        .demo-rec-dot { width: 9px; height: 9px; border-radius: 50%; background: #ef4444; animation: demo-blip 1s infinite; box-shadow: 0 0 10px rgba(239,68,68,.7); }
        @keyframes demo-blip { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.3; transform: scale(.85); } }

        /* Add Product mockups */
        .demo-admin-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .demo-admin-title { font-size: 0.9rem; font-weight: 800; color: #0f172a; }
        .demo-admin-sub { font-size: 0.62rem; color: #94a3b8; }
        .demo-add-button {
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            color: #fff; border: none;
            padding: 8px 14px; border-radius: 10px;
            font-size: 0.72rem; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px;
            font-family: inherit;
            box-shadow: 0 8px 18px rgba(59,130,246,.3);
            animation: addBtnPulse 2s ease-in-out infinite;
        }
        @keyframes addBtnPulse { 0%,100% { box-shadow: 0 8px 18px rgba(59,130,246,.3); } 50% { box-shadow: 0 8px 28px rgba(59,130,246,.55); } }
        .demo-table { width: 100%; border-collapse: collapse; font-size: 0.72rem; }
        .demo-table th { text-align: left; color: #94a3b8; font-weight: 700; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; font-size: 0.6rem; text-transform: uppercase; letter-spacing: .04em; }
        .demo-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; }
        .demo-table tr.new-row td { background: #f8f7ff; animation: rowIn .5s both; }
        .demo-td-icon { width: 26px; height: 26px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.62rem; }
        .demo-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.58rem; font-weight: 700; background: #f1f5f9; color: #475569; }
        .demo-badge.stock { background: #dcfce7; color: #16a34a; }
        .demo-form { display: flex; flex-direction: column; gap: 10px; max-width: 460px; margin: 0 auto; }
        .demo-form-row { display: grid; grid-template-columns: 108px 1fr; align-items: center; gap: 10px; font-size: 0.75rem; color: #475569; font-weight: 600; }
        .demo-input {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px;
            font-size: 0.75rem; color: #0f172a; font-family: inherit; width: 100%;
            transition: all .25s;
        }
        .demo-input.focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.15); }
        .demo-select {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px;
            font-size: 0.75rem; color: #0f172a; font-family: inherit; width: 100%; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%2364748b' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
        }
        .demo-form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; }
        .demo-save-btn {
            background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; border: none;
            padding: 9px 18px; border-radius: 10px; font-size: 0.75rem; font-weight: 700;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-family: inherit;
            box-shadow: 0 8px 18px rgba(34,197,94,.3);
            animation: saveBtnPulse 2s ease-in-out infinite;
        }
        @keyframes saveBtnPulse { 0%,100% { box-shadow: 0 8px 18px rgba(34,197,94,.3); } 50% { box-shadow: 0 8px 26px rgba(34,197,94,.55); } }
        .demo-cancel-btn { background: #fff; color: #64748b; border: 1px solid #e2e8f0; padding: 9px 16px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; cursor: pointer; }
        .demo-added-card {
            display: flex; align-items: center; gap: 12px; background: #f0fdf4; border: 1px solid #bbf7d0;
            border-radius: 14px; padding: 14px 16px; margin-top: 18px;
            max-width: 340px; margin-left: auto; margin-right: auto;
            animation: changeSlide .6s both; box-shadow: 0 10px 24px rgba(34,197,94,.15);
        }
        .demo-added-card .ac-icon { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg,#22c55e,#16a34a); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .demo-added-card .ac-name { font-size: 0.82rem; font-weight: 800; color: #0f172a; }
        .demo-added-card .ac-sub { font-size: 0.68rem; color: #64748b; margin-top: 2px; }

        @media (max-width: 700px) {
            .demo-screen { min-height: 380px; }
            .demo-sidebar { width: 48px; }
            .demo-main { padding: 18px; }
            .demo-product-grid { grid-template-columns: 1fr 1fr; }
            .demo-step-caption { display: none; }
            .demo-step-indicator { padding: 0; }
        }
    </style>
</head>

<body>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
    <button class="mobile-close" id="mobileClose"><i class="fas fa-times"></i></button>
    <a href="#" onclick="closeMobile()">Home</a>
    <a href="#features" onclick="closeMobile()">Features</a>
    <a href="#pricing" onclick="closeMobile()">Pricing</a>
    <a href="auth/login.php">Login</a>
    <a href="auth/register.php" class="btn-primary" style="text-align:center;">Sign Up</a>
</div>

<!-- ─── NAVBAR ─── -->
<nav class="navbar" id="navbar">
    <a href="#" class="navbar-logo">
        <img src="assets/img/ava_logo.png" alt="AVA IT Solution">
        <div class="navbar-logo-text">
            <span class="pos-badge">POS SYSTEM</span>
        </div>
    </a>

    <ul class="navbar-links">
        <li><a href="#" class="active">Home</a></li>
        <li><a href="#solutions">Solutions</a></li>
        <li><a href="#tutorial">Tutorial</a></li>
        <li><a href="#how-to-use">How it Works</a></li>
        <li><a href="#features">Features</a></li>
        <li><a href="#pricing">Pricing</a></li>
        <li><a href="#contact">Contact</a></li>
    </ul>

    <div class="navbar-right">
        <div class="navbar-phone">
            <i class="fas fa-phone-alt"></i>
            <?php echo $shopPhone; ?>
        </div>
        <a href="auth/login.php" class="btn-ghost">
            <i class="fas fa-sign-in-alt"></i> Login
        </a>
        <a href="auth/register.php" class="btn-primary">
            <i class="fas fa-user-plus"></i> Sign Up
        </a>
        <button class="mobile-toggle" id="mobileToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>
</nav>

<!-- ─── HERO ─── -->
<section class="hero" id="hero-section">
    <div class="hero-grid"></div>
    
    <!-- Animated floating orbs -->
    <div class="hero-orb hero-orb-1"></div>
    <div class="hero-orb hero-orb-2"></div>
    <div class="hero-orb hero-orb-3"></div>
    
    <div class="hero-center">
        <div class="hero-badge">
            <span class="badge-dot"></span>
            <i class="fas fa-rocket"></i>
            #1 POS Solution in Bangladesh
        </div>
        <h1 class="hero-title">
            আপনার ব্যবসা হোক<br><span class="gradient-text" id="heroTyping">Smart & Digital</span>
        </h1>
        <p class="hero-desc">
            AVA POS System দিয়ে আপনার দোকানের বিক্রয়, স্টক, কাস্টমার এবং রিপোর্ট — সব এক জায়গায় ম্যানেজ করুন।<br>
            সহজ। দ্রুত। নির্ভরযোগ্য।
        </p>
        <div class="hero-buttons">
            <a href="auth/register.php" class="btn-hero-primary">
                <i class="fas fa-bolt"></i> ফ্রি তে শুরু করুন <i class="fas fa-arrow-right"></i>
            </a>
            <a href="#how-to-use" class="btn-hero-outline">
                <i class="fas fa-play-circle"></i> কিভাবে কাজ করে দেখুন
            </a>
        </div>
        
        <!-- Hero trust badges -->
        <div class="hero-trust">
            <div class="hero-trust-item">
                <i class="fas fa-check-circle"></i> সম্পূর্ণ ফ্রি ট্রায়াল
            </div>
            <div class="hero-trust-item">
                <i class="fas fa-shield-alt"></i> ১০০% নিরাপদ ডেটা
            </div>
            <div class="hero-trust-item">
                <i class="fas fa-headset"></i> ২৪/৭ সাপোর্ট
            </div>
        </div>
    </div>
    
    <!-- Floating dashboard mockup -->
    <div class="hero-mockup-container">
        <div class="hero-dashboard-mockup">
            <div class="mockup-browser-bar">
                <div class="mockup-dots">
                    <span style="background:#ff5f57;"></span>
                    <span style="background:#febc2e;"></span>
                    <span style="background:#28c840;"></span>
                </div>
                <div class="mockup-url">
                    <i class="fas fa-lock" style="color:#28c840;font-size:0.55rem;margin-right:4px;"></i>
                    ava-pos.com/dashboard
                </div>
                <div style="width:50px;"></div>
            </div>
            <div class="mockup-dashboard-body">
                <div class="mockup-sidebar-mini">
                    <div class="ms-item active"><i class="fas fa-th-large"></i></div>
                    <div class="ms-item"><i class="fas fa-cash-register"></i></div>
                    <div class="ms-item"><i class="fas fa-boxes"></i></div>
                    <div class="ms-item"><i class="fas fa-users"></i></div>
                    <div class="ms-item"><i class="fas fa-chart-bar"></i></div>
                    <div class="ms-item"><i class="fas fa-cog"></i></div>
                </div>
                <div class="mockup-main-content">
                    <div class="mockup-stat-cards">
                        <div class="mc-stat" style="border-left:3px solid #3b82f6;">
                            <div class="mc-stat-icon" style="background:#eff6ff;color:#3b82f6;"><i class="fas fa-shopping-cart"></i></div>
                            <div><div class="mc-stat-num">৳12,450</div><div class="mc-stat-label">Today Sales</div></div>
                        </div>
                        <div class="mc-stat" style="border-left:3px solid #22c55e;">
                            <div class="mc-stat-icon" style="background:#f0fdf4;color:#22c55e;"><i class="fas fa-box"></i></div>
                            <div><div class="mc-stat-num">248</div><div class="mc-stat-label">Products</div></div>
                        </div>
                        <div class="mc-stat" style="border-left:3px solid #f59e0b;">
                            <div class="mc-stat-icon" style="background:#fffbeb;color:#f59e0b;"><i class="fas fa-users"></i></div>
                            <div><div class="mc-stat-num">56</div><div class="mc-stat-label">Customers</div></div>
                        </div>
                    </div>
                    <div class="mockup-chart-area">
                        <div class="mc-chart-header">
                            <span style="font-weight:700;color:#0f172a;font-size:0.6rem;">Sales Overview</span>
                            <span style="color:#64748b;font-size:0.5rem;">Last 7 days</span>
                        </div>
                        <div class="mc-chart-bars">
                            <div class="mc-bar" style="height:40%;animation-delay:0s;"></div>
                            <div class="mc-bar" style="height:65%;animation-delay:0.1s;"></div>
                            <div class="mc-bar" style="height:45%;animation-delay:0.2s;"></div>
                            <div class="mc-bar" style="height:80%;animation-delay:0.3s;"></div>
                            <div class="mc-bar" style="height:55%;animation-delay:0.4s;"></div>
                            <div class="mc-bar" style="height:90%;animation-delay:0.5s;"></div>
                            <div class="mc-bar" style="height:70%;animation-delay:0.6s;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Floating notification cards -->
        <div class="floating-card fc-1">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:28px;height:28px;border-radius:8px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;color:#22c55e;font-size:0.7rem;"><i class="fas fa-check"></i></div>
                <div>
                    <div style="font-size:0.65rem;font-weight:700;color:#0f172a;">New Sale ৳2,500</div>
                    <div style="font-size:0.55rem;color:#64748b;">Just now</div>
                </div>
            </div>
        </div>
        
        <div class="floating-card fc-2">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:28px;height:28px;border-radius:8px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:#3b82f6;font-size:0.7rem;"><i class="fas fa-chart-line"></i></div>
                <div>
                    <div style="font-size:0.65rem;font-weight:700;color:#0f172a;">Revenue +23%</div>
                    <div style="font-size:0.55rem;color:#64748b;">This week</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ─── STATS BAR ─── -->
<div class="stats-bar animate-on-scroll">
    <div class="stat-item">
        <div class="stat-icon blue"><i class="fas fa-store"></i></div>
        <div class="stat-info">
            <div class="stat-number" data-count="<?php echo $stats['businesses']; ?>">0</div>
            <div class="stat-label">Active Businesses</div>
        </div>
    </div>
    <div class="stat-item">
        <div class="stat-icon green"><i class="fas fa-box"></i></div>
        <div class="stat-info">
            <div class="stat-number" data-count="<?php echo $stats['products']; ?>">0</div>
            <div class="stat-label">Products Managed</div>
        </div>
    </div>
    <div class="stat-item">
        <div class="stat-icon orange"><i class="fas fa-chart-line"></i></div>
        <div class="stat-info">
            <div class="stat-number" data-count="<?php echo $stats['transactions']; ?>">0</div>
            <div class="stat-label">Transactions</div>
        </div>
    </div>
    <div class="stat-item">
        <div class="stat-icon purple"><i class="fas fa-users"></i></div>
        <div class="stat-info">
            <div class="stat-number" data-count="<?php echo $stats['users']; ?>">0</div>
            <div class="stat-label">Active Users</div>
        </div>
    </div>
</div>



<!-- ─── PROBLEM SOLVING ─── -->
<section class="problems-section" id="solutions" style="padding: 100px 60px; background: #ffffff; border-top: 1px solid #f1f5f9;">
    <div style="text-align: center; margin-bottom: 60px;" class="animate-on-scroll">
        <div class="features-badge" style="background: #fef2f2; color: #ef4444; padding: 8px 18px; border-radius: 999px; font-weight: 700; font-size: 0.85rem; display: inline-block; margin-bottom: 16px;">
            <i class="fas fa-shield-alt"></i> Why Choose Us?
        </div>
        <h2 style="font-size: 2.5rem; font-weight: 900; color: #0f172a;">Problems We <span style="color: #ef4444;">Solve</span> For You</h2>
        <p style="color: #64748b; font-size: 1.05rem; max-width: 600px; margin: 16px auto 0;">Running a business is hard. Our software eliminates the headaches so you can focus on growth.</p>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; max-width: 1100px; margin: 0 auto;">
        
        <div style="background: #ffffff; border: 1px solid #f1f5f9; border-left: 4px solid #ef4444; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);" class="animate-on-scroll">
            <h3 style="font-size: 1.2rem; font-weight: 800; color: #0f172a; margin-bottom: 10px;"><i class="fas fa-times-circle" style="color: #ef4444; margin-right: 8px;"></i> Manual Calculations Error</h3>
            <p style="color: #64748b; font-size: 0.9rem; line-height: 1.6;"><strong>Solved:</strong> Automated, error-free billing with precise tax and discount calculations instantly.</p>
        </div>
        
        <div style="background: #ffffff; border: 1px solid #f1f5f9; border-left: 4px solid #f59e0b; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);" class="animate-on-scroll">
            <h3 style="font-size: 1.2rem; font-weight: 800; color: #0f172a; margin-bottom: 10px;"><i class="fas fa-box-open" style="color: #f59e0b; margin-right: 8px;"></i> Out of Stock Issues</h3>
            <p style="color: #64748b; font-size: 0.9rem; line-height: 1.6;"><strong>Solved:</strong> Real-time inventory tracking with low stock alerts so you never run out of top sellers.</p>
        </div>
        
        <div style="background: #ffffff; border: 1px solid #f1f5f9; border-left: 4px solid #3b82f6; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);" class="animate-on-scroll">
            <h3 style="font-size: 1.2rem; font-weight: 800; color: #0f172a; margin-bottom: 10px;"><i class="fas fa-chart-line" style="color: #3b82f6; margin-right: 8px;"></i> Unclear Business Profit</h3>
            <p style="color: #64748b; font-size: 0.9rem; line-height: 1.6;"><strong>Solved:</strong> Detailed daily, monthly, and yearly reports showing exact profit margins and sales trends.</p>
        </div>
        
        <div style="background: #ffffff; border: 1px solid #f1f5f9; border-left: 4px solid #8b5cf6; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);" class="animate-on-scroll">
            <h3 style="font-size: 1.2rem; font-weight: 800; color: #0f172a; margin-bottom: 10px;"><i class="fas fa-users-slash" style="color: #8b5cf6; margin-right: 8px;"></i> Losing Loyal Customers</h3>
            <p style="color: #64748b; font-size: 0.9rem; line-height: 1.6;"><strong>Solved:</strong> Customer management system with purchase history to offer targeted discounts and retain customers.</p>
        </div>

    </div>
</section>


<!-- ─── TUTORIAL / GUIDE ─── -->
<section class="tutorial-section" id="tutorial" style="padding: 100px 60px; background: #f8fafc; border-top: 1px solid #f1f5f9;">
    <div style="text-align: center; margin-bottom: 60px;" class="animate-on-scroll">
        <div class="features-badge" style="background: #e0e7ff; color: #4f46e5; padding: 8px 18px; border-radius: 999px; font-weight: 700; font-size: 0.85rem; display: inline-block; margin-bottom: 16px;">
            <i class="fas fa-video"></i> Live Demo
        </div>
        <h2 style="font-size: 2.5rem; font-weight: 900; color: #0f172a;">দেখুন কত <span style="color: #4f46e5;">সহজ</span></h2>
        <p style="color: #64748b; font-size: 1.05rem; max-width: 600px; margin: 16px auto 0;">কোনো টেকনিক্যাল জ্ঞানের প্রয়োজন নেই। প্রথম দিন থেকেই যে কেউ ব্যবহার করতে পারবে।</p>
    </div>
    
    <div style="max-width: 900px; margin: 0 auto;" class="animate-on-scroll">
        <div class="demo-stage">
            <div class="demo-orb o1"></div>
            <div class="demo-orb o2"></div>
            <div class="demo-orb o3"></div>
            <!-- Animated Video Simulation -->
            <div class="demo-video-container" id="demoVideoContainer">
                <div class="demo-particles" id="demoParticles"></div>
                <div class="demo-browser-bar">
                    <div style="display:flex;gap:6px;">
                        <span style="width:10px;height:10px;border-radius:50%;background:#ff5f57;"></span>
                        <span style="width:10px;height:10px;border-radius:50%;background:#febc2e;"></span>
                        <span style="width:10px;height:10px;border-radius:50%;background:#28c840;"></span>
                    </div>
                    <div id="demoUrlBar" style="background:#f1f5f9;padding:4px 14px;border-radius:6px;font-size:0.7rem;color:#64748b;display:flex;align-items:center;">
                        <i class="fas fa-lock" style="color:#28c840;font-size:0.55rem;margin-right:4px;"></i>
                        ava-pos.com/cashier/pos
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <div class="demo-rec-dot"></div>
                        <span style="font-size:0.65rem;color:#ef4444;font-weight:700;">LIVE DEMO</span>
                    </div>
                </div>
                
                <!-- Animated POS Screen -->
                <div class="demo-screen">
                    <div class="demo-sidebar">
                        <div class="demo-sb-item"><i class="fas fa-th-large"></i></div>
                        <div class="demo-sb-item active"><i class="fas fa-cash-register"></i></div>
                        <div class="demo-sb-item"><i class="fas fa-boxes"></i></div>
                        <div class="demo-sb-item"><i class="fas fa-users"></i></div>
                        <div class="demo-sb-item"><i class="fas fa-chart-bar"></i></div>
                    </div>
                    <div class="demo-main">
                        <!-- Step indicator -->
                        <div class="demo-step-indicator">
                            <div class="demo-indicator-line"><div class="demo-indicator-fill" id="demoIndicatorFill"></div></div>
                            <div class="demo-step-dot active" data-step="0">1</div>
                            <div class="demo-step-dot" data-step="1">2</div>
                            <div class="demo-step-dot" data-step="2">3</div>
                            <div class="demo-step-dot" data-step="3">4</div>
                            <div class="demo-step-dot" data-step="4">5</div>
                            <div class="demo-step-dot" data-step="5">6</div>
                            <div class="demo-step-dot" data-step="6">7</div>
                            <div class="demo-step-dot" data-step="7">8</div>
                        </div>
                        
                        <!-- Animated steps -->
                        <div class="demo-steps-wrapper">
                        <!-- Step 1: Add Product - list view -->
                        <div class="demo-step demo-step-1">
                            <div class="demo-step-label"><i class="fas fa-box-open" style="color:#3b82f6;"></i> Step 1: অ্যাডমিন প্যানেলে প্রোডাক্ট পেজে যান</div>
                            <div class="demo-admin-head">
                                <div>
                                    <div class="demo-admin-title"><i class="fas fa-boxes" style="color:#3b82f6;margin-right:6px;"></i> Products</div>
                                    <div class="demo-admin-sub">Total 248 products • Updated just now</div>
                                </div>
                                <button class="demo-add-button"><i class="fas fa-plus"></i> Add Product</button>
                            </div>
                            <table class="demo-table">
                                <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th></tr></thead>
                                <tbody>
                                    <tr><td><span class="demo-td-icon" style="background:#eff6ff;color:#3b82f6;"><i class="fas fa-tshirt"></i></span> T-Shirt</td><td><span class="demo-badge">Clothing</span></td><td>৳450</td><td><span class="demo-badge stock">120 in stock</span></td></tr>
                                    <tr><td><span class="demo-td-icon" style="background:#fef3c7;color:#f59e0b;"><i class="fas fa-socks"></i></span> Jeans</td><td><span class="demo-badge">Clothing</span></td><td>৳1,200</td><td><span class="demo-badge stock">48 in stock</span></td></tr>
                                    <tr><td><span class="demo-td-icon" style="background:#fce7f3;color:#ec4899;"><i class="fas fa-shoe-prints"></i></span> Sneakers</td><td><span class="demo-badge">Footwear</span></td><td>৳2,800</td><td><span class="demo-badge stock">35 in stock</span></td></tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Step 2: Product form -->
                        <div class="demo-step demo-step-2">
                            <div class="demo-step-label"><i class="fas fa-plus-circle" style="color:#22c55e;"></i> Step 2: প্রোডাক্টের তথ্য পূরণ করুন</div>
                            <div class="demo-form">
                                <div class="demo-form-row"><span>Product Name</span><input class="demo-input focus" value="Polo Shirt" readonly></div>
                                <div class="demo-form-row"><span>Category</span><select class="demo-select"><option>Clothing</option><option selected>Fashion</option></select></div>
                                <div class="demo-form-row"><span>Buying Price</span><input class="demo-input" value="৳600" readonly></div>
                                <div class="demo-form-row"><span>Selling Price</span><input class="demo-input" value="৳850" readonly></div>
                                <div class="demo-form-row"><span>Stock Quantity</span><input class="demo-input" value="50" readonly></div>
                                <div class="demo-form-actions">
                                    <button class="demo-cancel-btn">Cancel</button>
                                    <button class="demo-save-btn"><i class="fas fa-check"></i> Add Product</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 3: Product added -->
                        <div class="demo-step demo-step-3">
                            <div class="demo-success-check" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);">
                                <i class="fas fa-check"></i>
                            </div>
                            <div style="text-align:center;font-size:1.1rem;font-weight:800;color:#0f172a;margin-bottom:6px;">প্রোডাক্ট যোগ হয়েছে!</div>
                            <div style="text-align:center;font-size:0.85rem;color:#64748b;margin-bottom:6px;">Product added successfully</div>
                            <div class="demo-added-card">
                                <div class="ac-icon"><i class="fas fa-tshirt"></i></div>
                                <div>
                                    <div class="ac-name">Polo Shirt</div>
                                    <div class="ac-sub">৳850 • Profit ৳250 • 50 in stock</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 4: Product grid -->
                        <div class="demo-step demo-step-4">
                            <div class="demo-step-label"><i class="fas fa-hand-pointer" style="color:#3b82f6;"></i> Step 4: প্রোডাক্ট সিলেক্ট করুন</div>
                            <div class="demo-product-grid">
                                <div class="demo-prod"><div class="dp-icon" style="background:#eff6ff;color:#3b82f6;"><i class="fas fa-tshirt"></i></div><div class="dp-name">T-Shirt</div><div class="dp-price">৳450</div></div>
                                <div class="demo-prod demo-prod-selected"><div class="dp-icon" style="background:#f0fdf4;color:#22c55e;"><i class="fas fa-tshirt"></i></div><div class="dp-name">Polo Shirt</div><div class="dp-price">৳850</div><div class="dp-check"><i class="fas fa-check"></i></div></div>
                                <div class="demo-prod"><div class="dp-icon" style="background:#fef3c7;color:#f59e0b;"><i class="fas fa-socks"></i></div><div class="dp-name">Jeans</div><div class="dp-price">৳1,200</div></div>
                                <div class="demo-prod demo-prod-selected"><div class="dp-icon" style="background:#fce7f3;color:#ec4899;"><i class="fas fa-shoe-prints"></i></div><div class="dp-name">Sneakers</div><div class="dp-price">৳2,800</div><div class="dp-check"><i class="fas fa-check"></i></div></div>
                            </div>
                        </div>
                        
                        <!-- Step 2: Cart (renamed to step 5) -->
                        <div class="demo-step demo-step-5">
                            <div class="demo-step-label"><i class="fas fa-shopping-cart" style="color:#22c55e;"></i> Step 5: কার্ট চেক করুন</div>
                            <div class="demo-cart-view">
                                <div class="dc-item"><span>Polo Shirt</span><span>x1</span><span>৳850</span></div>
                                <div class="dc-item"><span>Sneakers</span><span>x1</span><span>৳2,800</span></div>
                                <div class="dc-divider"></div>
                                <div class="dc-total"><span>Subtotal</span><span>৳3,650</span></div>
                                <div class="dc-discount"><span>Discount (5%)</span><span>-৳183</span></div>
                                <div class="dc-divider"></div>
                                <div class="dc-grand"><span>Total</span><span>৳3,467</span></div>
                            </div>
                        </div>
                        
                        <!-- Step 3: Payment (renamed to step 6) -->
                        <div class="demo-step demo-step-6">
                            <div class="demo-step-label"><i class="fas fa-credit-card" style="color:#8b5cf6;"></i> Step 6: পেমেন্ট নিন</div>
                            <div class="demo-payment">
                                <div class="dp-method dp-method-active"><i class="fas fa-money-bill-wave"></i> Cash</div>
                                <div class="dp-method"><i class="fas fa-mobile-alt"></i> bKash</div>
                                <div class="dp-method"><i class="fas fa-credit-card"></i> Card</div>
                            </div>
                            <div class="demo-pay-input">
                                <span>Amount Received:</span>
                                <span class="demo-typing-amount">৳4,000</span>
                            </div>
                            <div class="demo-change">Change: ৳533</div>
                        </div>
                        
                        <!-- Step 4: Receipt -->
                        <div class="demo-step demo-step-7">
                            <div class="demo-step-label"><i class="fas fa-receipt" style="color:#f59e0b;"></i> Step 7: রিসিপ্ট প্রিন্ট</div>
                            <div class="demo-receipt">
                                <div style="text-align:center;font-weight:800;font-size:0.8rem;color:#0f172a;margin-bottom:6px;">AVA IT Solution</div>
                                <div style="text-align:center;font-size:0.6rem;color:#64748b;margin-bottom:8px;">Invoice #0042</div>
                                <div class="dr-line"></div>
                                <div class="dr-item"><span>Polo Shirt</span><span>৳850</span></div>
                                <div class="dr-item"><span>Sneakers</span><span>৳2,800</span></div>
                                <div class="dr-line"></div>
                                <div class="dr-item" style="font-weight:700;"><span>Total</span><span>৳3,467</span></div>
                                <div style="text-align:center;margin-top:8px;font-size:0.6rem;color:#64748b;">Thank you for shopping!</div>
                            </div>
                        </div>
                        
                        <!-- Step 8: Success -->
                        <div class="demo-step demo-step-8">
                            <div class="demo-success-check">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div style="font-size:1.1rem;font-weight:800;color:#0f172a;margin-bottom:6px;">Sale Complete!</div>
                            <div style="font-size:0.85rem;color:#64748b;">Transaction saved successfully</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Controls bar -->
        <div class="demo-controls">
            <div class="demo-nav-group">
                <button class="demo-arrow prev" id="demoPrev" aria-label="Previous step"><i class="fas fa-chevron-left"></i></button>
            </div>
            <div class="demo-progress-bar">
                <div class="demo-progress-fill" id="demoProgressFill"></div>
            </div>
            <div style="display:flex;gap:14px;align-items:center;">
                <span class="demo-step-caption" id="demoStepCaption">Step 1 of 8 • অ্যাডমিন প্যানেলে প্রোডাক্ট পেজ</span>
                <button class="demo-play-btn" id="demoPlay" aria-label="Play/Pause"><i class="fas fa-pause"></i></button>
                <button class="demo-arrow next" id="demoNext" aria-label="Next step"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
    </div>
</section>

<!-- ─── HOW TO USE ─── -->
<section class="how-to-use" id="how-to-use" style="padding: 100px 60px; background: #ffffff;">
    <div style="text-align: center; margin-bottom: 60px;" class="animate-on-scroll">
        <div class="features-badge" style="background: #eff6ff; color: #3b82f6; padding: 8px 18px; border-radius: 999px; font-weight: 700; font-size: 0.85rem; display: inline-block; margin-bottom: 16px;">
            <i class="fas fa-play-circle"></i> Simple Workflow
        </div>
        <h2 style="font-size: 2.5rem; font-weight: 900; color: #0f172a;">How to Use <span style="color: #3b82f6;">AVA POS</span></h2>
        <p style="color: #64748b; font-size: 1.05rem; max-width: 600px; margin: 16px auto 0;">Start managing your business in three simple steps. Our system is designed to be intuitive and fast.</p>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px; max-width: 1100px; margin: 0 auto;">
        <!-- Step 1 -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 40px 30px; text-align: center; position: relative;" class="animate-on-scroll">
            <div style="width: 64px; height: 64px; background: #eff6ff; color: #3b82f6; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; margin: 0 auto 24px;">
                <i class="fas fa-box-open"></i>
            </div>
            <div style="position: absolute; top: -15px; left: 50%; transform: translateX(-50%); background: #3b82f6; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">1</div>
            <h3 style="font-size: 1.3rem; font-weight: 800; color: #0f172a; margin-bottom: 12px;">Add Products</h3>
            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6;">Easily add your inventory items, set prices, and categorize them. Use barcode scanning for faster entry.</p>
        </div>
        
        <!-- Step 2 -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 40px 30px; text-align: center; position: relative;" class="animate-on-scroll">
            <div style="width: 64px; height: 64px; background: #eff6ff; color: #3b82f6; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; margin: 0 auto 24px;">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <div style="position: absolute; top: -15px; left: 50%; transform: translateX(-50%); background: #3b82f6; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">2</div>
            <h3 style="font-size: 1.3rem; font-weight: 800; color: #0f172a; margin-bottom: 12px;">Make Sales</h3>
            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6;">Process transactions quickly at the counter. Select items, apply discounts, and accept multiple payment methods.</p>
        </div>
        
        <!-- Step 3 -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 40px 30px; text-align: center; position: relative;" class="animate-on-scroll">
            <div style="width: 64px; height: 64px; background: #eff6ff; color: #3b82f6; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; margin: 0 auto 24px;">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div style="position: absolute; top: -15px; left: 50%; transform: translateX(-50%); background: #3b82f6; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">3</div>
            <h3 style="font-size: 1.3rem; font-weight: 800; color: #0f172a; margin-bottom: 12px;">View Reports</h3>
            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6;">Monitor your business performance with real-time analytics, daily summaries, and profit margin tracking.</p>
        </div>
    </div>
</section>

<!-- ─── FEATURES ─── -->
<section class="features-section" id="features">
    <div class="features-inner">
        <div class="features-left-col animate-on-scroll">
            <div class="features-badge">
                <i class="fas fa-bolt"></i> Powerful Features
            </div>
            <h2 class="features-title">
                Everything You Need<br>in One <span class="highlight">POS System</span>
            </h2>
            <p class="features-desc">
                Manage your business smarter and faster with our all-in-one POS solution.
                From billing to inventory, we've got you covered.
            </p>
            <a href="auth/register.php" class="btn-view-all">
                Start Free Trial <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <div class="features-grid">
            <div class="feature-card animate-on-scroll">
                <div class="feature-card-icon blue"><i class="fas fa-cash-register"></i></div>
                <h4>Sales Management</h4>
                <p>Fast billing, multiple payment methods and invoice printing.</p>
                <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="feature-card animate-on-scroll">
                <div class="feature-card-icon green"><i class="fas fa-boxes"></i></div>
                <h4>Inventory Management</h4>
                <p>Track stock in real-time, low stock alerts and inventory reports.</p>
                <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="feature-card animate-on-scroll">
                <div class="feature-card-icon orange"><i class="fas fa-users"></i></div>
                <h4>Customer Management</h4>
                <p>Manage customer data, purchase history and loyalty points.</p>
                <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="feature-card animate-on-scroll">
                <div class="feature-card-icon purple"><i class="fas fa-chart-bar"></i></div>
                <h4>Reports & Analytics</h4>
                <p>Powerful reports to analyze your business growth and profit.</p>
                <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="feature-card animate-on-scroll">
                <div class="feature-card-icon pink"><i class="fas fa-barcode"></i></div>
                <h4>Barcode & Label</h4>
                <p>Generate barcodes and print labels for your products.</p>
                <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="feature-card animate-on-scroll">
                <div class="feature-card-icon teal"><i class="fas fa-store"></i></div>
                <h4>Multi Branch Support</h4>
                <p>Manage multiple branches from one central dashboard.</p>
                <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>
</section>

<!-- ─── TRUSTED BY ─── -->
<section class="trusted-section animate-on-scroll">
    <p class="trusted-label">Trusted by Businesses Across Bangladesh</p>
    <div class="trusted-logos">
        <div class="trusted-logo-item"><i class="fas fa-store"></i> Fashion</div>
        <div class="trusted-logo-item"><i class="fas fa-shopping-basket"></i> Mart</div>
        <div class="trusted-logo-item"><i class="fas fa-store-alt"></i> Mega</div>
        <div class="trusted-logo-item"><i class="fas fa-building"></i> ShopHub</div>
        <div class="trusted-logo-item"><i class="fas fa-shopping-bag"></i> Daily Bazar</div>
        <div class="trusted-logo-item"><i class="fas fa-tags"></i> Trendz</div>
    </div>
</section>

<!-- ─── PRICING ─── -->
<section class="pricing-section" id="pricing">
    <div class="pricing-badge animate-on-scroll">
        <i class="fas fa-tag"></i> Pricing Plans
    </div>
    <h2 class="pricing-heading animate-on-scroll">
        Choose Your <span class="gradient-text">Perfect Plan</span>
    </h2>
    <p class="pricing-sub animate-on-scroll">
        Simple, transparent pricing for businesses of every size. Start free, upgrade when ready.
    </p>

    <?php if ($flash = getFlash()): ?>
        <div style="max-width:600px;margin:0 auto 1.5rem;background:<?php echo $flash['type']==='warning'?'#fef3c7':'#d1fae5'; ?>;border:1px solid <?php echo $flash['type']==='warning'?'#fcd34d':'#6ee7b7'; ?>;color:<?php echo $flash['type']==='warning'?'#92400e':'#065f46'; ?>;padding:14px 18px;border-radius:12px;display:flex;align-items:center;gap:10px;font-size:.95rem;font-weight:500;">
            <i class="fas fa-<?php echo $flash['type']==='warning'?'exclamation-triangle':'check-circle'; ?>"></i>
            <?php echo $flash['message']; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($plans)): ?>
        <p style="color:#64748b;font-size:1rem;">No plans available right now. Please check back soon.</p>
    <?php else: ?>
        <?php $popularIdx = (int)floor(count($plans) / 2); ?>
        <div class="pricing-grid">
            <?php foreach ($plans as $idx => $plan): ?>
                <?php
                $popular = $idx === $popularIdx;
                $perDay = $plan['duration_days'] > 0 ? round((float)$plan['price'] / (int)$plan['duration_days'], 2) : 0;
                $featureList = array_filter(array_map('trim', explode("\n", $plan['features'] ?? '')));
                ?>
                <div class="price-card<?php echo $popular ? ' popular' : ''; ?> animate-on-scroll">
                    <?php if ($popular): ?>
                        <span class="price-badge-tag">Most Popular</span>
                    <?php endif; ?>
                    <div class="price-name"><?php echo sanitize($plan['name']); ?></div>
                    <div class="price-amount">
                        <?php echo formatCurrency($plan['price']); ?>
                    </div>
                    <div class="price-meta">
                        per <?php echo $plan['duration_days']; ?> days
                        &middot; <?php echo formatCurrency($perDay); ?>/day
                    </div>
                    <span class="price-period">
                        <?php echo $plan['duration_days'] >= 360 ? 'Billed yearly' : 'Billed ' . $plan['duration_days'] . ' days'; ?>
                    </span>
                    <?php if (!empty($featureList)): ?>
                        <ul class="price-features">
                            <?php foreach ($featureList as $feat): ?>
                                <li><i class="fas fa-check-circle"></i> <?php echo sanitize($feat); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="price-desc">
                            <?php echo $plan['description'] ? sanitize($plan['description']) : 'Get started with the ' . sanitize($plan['name']) . ' plan and grow your business.'; ?>
                        </p>
                    <?php endif; ?>
                    <a href="auth/checkout.php?plan=<?php echo $plan['id']; ?>" class="price-btn">
                        <?php echo $popular ? 'Get Started Now' : 'Choose Plan'; ?> <i class="fas fa-arrow-right" style="margin-left:6px;"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ─── CTA ─── -->
<section class="cta-section" id="contact">
    <div class="cta-box animate-on-scroll">
        <h2>Ready to Transform Your Business?</h2>
        <p>Join thousands of businesses across Bangladesh using our Smart POS System. Start your free trial today.</p>
        <div class="cta-buttons">
            <a href="auth/register.php" class="btn-hero-primary">
                Start Free Trial <i class="fas fa-arrow-right"></i>
            </a>
            <a href="auth/login.php" class="btn-hero-outline">
                <i class="fas fa-sign-in-alt"></i> Login to Dashboard
            </a>
        </div>
    </div>
</section>

<!-- ─── FOOTER ─── -->
<footer>
    <div class="footer-top">
        <div class="footer-brand">
            <a href="#" class="navbar-logo" style="margin-bottom:8px;">
                <img src="assets/img/ava_logo.png" alt="AVA IT Solution" style="height:36px;">
                <div class="navbar-logo-text"><span class="pos-badge">POS SYSTEM</span></div>
            </a>
            <p>Smart POS System for modern businesses in Bangladesh. Manage sales, inventory, customers and grow your business with AVA IT Solution.</p>
        </div>
        <div class="footer-col">
            <h4>Product</h4>
            <a href="#features">Features</a>
            <a href="#pricing">Pricing</a>
            <a href="auth/register.php">Register</a>
            <a href="auth/login.php">Login</a>
        </div>
        <div class="footer-col">
            <h4>Support</h4>
            <a href="<?php echo htmlspecialchars($whatsappUrl); ?>" target="_blank" rel="noopener">
                <i class="fab fa-whatsapp" style="color:#25D366;margin-right:6px;"></i>WhatsApp Support
            </a>
            <a href="#contact">Contact Us</a>
            <a href="#">Documentation</a>
            <a href="#">Help Center</a>
        </div>
        <div class="footer-col">
            <h4>Company</h4>
            <a href="#">About Us</a>
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Careers</a>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; 2026 AVA IT Solution. All rights reserved.</p>
        <div class="footer-social">
            <a href="<?php echo htmlspecialchars($whatsappUrl); ?>" target="_blank" rel="noopener" title="WhatsApp" style="background:rgba(37,211,102,0.12);border-color:rgba(37,211,102,0.25);color:#25D366;">
                <i class="fab fa-whatsapp"></i>
            </a>
            <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
            <a href="#" title="GitHub"><i class="fab fa-github"></i></a>
            <a href="#" title="YouTube"><i class="fab fa-youtube"></i></a>
        </div>
    </div>
</footer>

<script>
// ── Sticky navbar ──
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 50);
});

// ── Mobile menu ──
const mobileToggle = document.getElementById('mobileToggle');
const mobileMenu = document.getElementById('mobileMenu');
const mobileClose = document.getElementById('mobileClose');
mobileToggle.addEventListener('click', () => mobileMenu.classList.add('open'));
mobileClose.addEventListener('click', () => mobileMenu.classList.remove('open'));
function closeMobile() { mobileMenu.classList.remove('open'); }

// ── Scroll animations ──
const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry, i) => {
        if (entry.isIntersecting) {
            setTimeout(() => entry.target.classList.add('visible'), i * 80);
            observer.unobserve(entry.target);
        }
    });
}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

document.querySelectorAll('.animate-on-scroll').forEach(el => observer.observe(el));

// ── Counter animation ──
function animateCounter(el) {
    const target = parseInt(el.dataset.count) || 0;
    if (target === 0) { el.textContent = '0'; return; }
    const duration = 2000;
    const step = target / (duration / 16);
    let current = 0;
    const timer = setInterval(() => {
        current += step;
        if (current >= target) {
            current = target;
            clearInterval(timer);
        }
        el.textContent = target >= 1000 ? Math.floor(current).toLocaleString() + '+' : Math.floor(current);
    }, 16);
}

const counterObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            animateCounter(entry.target);
            counterObserver.unobserve(entry.target);
        }
    });
}, { threshold: 0.5 });

document.querySelectorAll('[data-count]').forEach(el => counterObserver.observe(el));

// ── Smooth scroll for anchor links ──
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// ── Active nav highlight ──
const sections = document.querySelectorAll('section[id]');
window.addEventListener('scroll', () => {
    const scrollPos = window.scrollY + 100;
    sections.forEach(section => {
        const top = section.offsetTop;
        const height = section.offsetHeight;
        const id = section.getAttribute('id');
        const link = document.querySelector(`.navbar-links a[href="#${id}"]`);
        if (link) {
            link.classList.toggle('active', scrollPos >= top && scrollPos < top + height);
        }
    });
});

// ── Tutorial demo (advanced auto-play + controls) ──
const demoSteps = document.querySelectorAll('.demo-step');
const demoDots = document.querySelectorAll('.demo-step-dot');
const demoFill = document.getElementById('demoProgressFill');
const demoCaption = document.getElementById('demoStepCaption');
const demoIndicatorFill = document.getElementById('demoIndicatorFill');
const demoPlayBtn = document.getElementById('demoPlay');
const demoNextBtn = document.getElementById('demoNext');
const demoPrevBtn = document.getElementById('demoPrev');
const demoVideoContainer = document.getElementById('demoVideoContainer');
const demoParticles = document.getElementById('demoParticles');

const demoCaptions = [
    'Step 1 of 8 • অ্যাডমিন প্যানেলে প্রোডাক্ট পেজ',
    'Step 2 of 8 • প্রোডাক্টের তথ্য পূরণ করুন',
    'Step 3 of 8 • প্রোডাক্ট যোগ হয়েছে',
    'Step 4 of 8 • প্রোডাক্ট সিলেক্ট করুন',
    'Step 5 of 8 • কার্ট চেক করুন',
    'Step 6 of 8 • পেমেন্ট নিন',
    'Step 7 of 8 • রিসিপ্ট প্রিন্ট',
    'Step 8 of 8 • সেল কমপ্লিট'
];

let currentStep = 0;
let demoStarted = false;
let playing = true;
let demoInterval = null;
const STEP_DURATION = 3200;

// Generate floating particles
if (demoParticles) {
    const PARTICLES = 14;
    for (let i = 0; i < PARTICLES; i++) {
        const p = document.createElement('span');
        p.className = 'demo-particle';
        p.style.left = (Math.random() * 100) + '%';
        p.style.animationDuration = (6 + Math.random() * 6) + 's';
        p.style.animationDelay = (Math.random() * 8) + 's';
        p.style.width = p.style.height = (3 + Math.random() * 5) + 'px';
        demoParticles.appendChild(p);
    }
}

// Spawn confetti on the final success step
function spawnConfetti() {
    if (!demoVideoContainer) return;
    const colors = ['#6366f1', '#a855f7', '#22d3ee', '#22c55e', '#f59e0b', '#ef4444'];
    for (let i = 0; i < 40; i++) {
        const c = document.createElement('span');
        c.className = 'demo-confetti';
        c.style.left = (Math.random() * 100) + '%';
        c.style.background = colors[Math.floor(Math.random() * colors.length)];
        c.style.animationDuration = (1.8 + Math.random() * 1.4) + 's';
        c.style.animationDelay = (Math.random() * 0.6) + 's';
        demoVideoContainer.appendChild(c);
        setTimeout(() => c.remove(), 4000);
    }
}

function setProgressFill(duration) {
    if (!demoFill) return;
    demoFill.style.transition = 'none';
    demoFill.style.width = '0%';
    requestAnimationFrame(() => {
        demoFill.style.transition = 'width ' + (duration / 1000) + 's linear';
        demoFill.style.width = '100%';
    });
}

function showDemoStep(idx, fromLeft) {
    const total = demoSteps.length;
    if (total === 0) return;
    const prev = currentStep;
    currentStep = ((idx % total) + total) % total;

    demoSteps.forEach((s, i) => {
        s.classList.remove('active', 'leave-left');
        if (i === prev) {
            s.classList.add(fromLeft ? 'leave-left' : 'leave-left');
        }
        if (i === currentStep) s.classList.add('active');
    });

    demoDots.forEach((d, i) => {
        const idxN = parseInt(d.dataset.step, 10);
        d.classList.remove('active', 'done');
        if (idxN === currentStep) d.classList.add('active');
        else if (idxN < currentStep) d.classList.add('done');
    });

    if (demoCaption) demoCaption.textContent = demoCaptions[currentStep] || '';
    if (demoIndicatorFill) {
        demoIndicatorFill.style.width = (currentStep / (total - 1) * 100) + '%';
    }

    // Sidebar: Products icon during add-product steps, POS icon during sale steps
    const sbItems = document.querySelectorAll('.demo-sb-item');
    sbItems.forEach(b => b.classList.remove('active'));
    const activeSb = currentStep < 3 ? 2 : 1;
    if (sbItems[activeSb]) sbItems[activeSb].classList.add('active');

    // URL bar reflects the current screen
    const urlBar = document.getElementById('demoUrlBar');
    if (urlBar) {
        urlBar.lastChild.textContent = currentStep < 3 ? '  ava-pos.com/admin/products' : '  ava-pos.com/cashier/pos';
    }

    if (currentStep === total - 1) spawnConfetti();
}

function advanceDemo() {
    showDemoStep(currentStep + 1, true);
    setProgressFill(STEP_DURATION);
}

function togglePlay() {
    playing = !playing;
    if (demoPlayBtn) {
        demoPlayBtn.innerHTML = playing ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>';
    }
    if (playing) {
        setProgressFill(STEP_DURATION);
        demoInterval = setInterval(advanceDemo, STEP_DURATION);
    } else {
        clearInterval(demoInterval);
        if (demoFill) demoFill.style.transition = 'none';
    }
}

function startDemo() {
    if (demoStarted) return;
    demoStarted = true;
    showDemoStep(0, false);
    setProgressFill(STEP_DURATION);
    demoInterval = setInterval(advanceDemo, STEP_DURATION);
}

// Controls
if (demoNextBtn) demoNextBtn.addEventListener('click', (e) => { e.stopPropagation(); if (!playing) clearInterval(demoInterval); else { clearInterval(demoInterval); } showDemoStep(currentStep + 1, true); setProgressFill(STEP_DURATION); demoInterval = setInterval(advanceDemo, STEP_DURATION); });
if (demoPrevBtn) demoPrevBtn.addEventListener('click', (e) => { e.stopPropagation(); clearInterval(demoInterval); showDemoStep(currentStep - 1, false); setProgressFill(STEP_DURATION); demoInterval = setInterval(advanceDemo, STEP_DURATION); });
if (demoPlayBtn) demoPlayBtn.addEventListener('click', togglePlay);
demoDots.forEach(d => {
    d.addEventListener('click', () => {
        const target = parseInt(d.dataset.step, 10);
        clearInterval(demoInterval);
        showDemoStep(target, target >= currentStep);
        setProgressFill(STEP_DURATION);
        demoInterval = setInterval(advanceDemo, STEP_DURATION);
        if (!playing) {
            playing = true;
            if (demoPlayBtn) demoPlayBtn.innerHTML = '<i class="fas fa-pause"></i>';
        }
    });
});

// Subtle 3D tilt on the demo window
if (demoVideoContainer && window.matchMedia('(hover:hover)').matches) {
    demoVideoContainer.addEventListener('mousemove', (e) => {
        const rect = demoVideoContainer.getBoundingClientRect();
        const px = (e.clientX - rect.left) / rect.width - 0.5;
        const py = (e.clientY - rect.top) / rect.height - 0.5;
        demoVideoContainer.style.transform = 'perspective(1200px) rotateY(' + (px * 6) + 'deg) rotateX(' + (-py * 6) + 'deg)';
    });
    demoVideoContainer.addEventListener('mouseleave', () => {
        demoVideoContainer.style.transition = 'transform .5s ease';
        demoVideoContainer.style.transform = 'perspective(1200px) rotateY(0) rotateX(0)';
        setTimeout(() => { demoVideoContainer.style.transition = ''; }, 500);
    });
}

const demoObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) startDemo();
    });
}, { threshold: 0.25 });
if (demoVideoContainer) demoObserver.observe(demoVideoContainer);
</script>

<!-- ─── WHATSAPP FLOATING BUTTON ─── -->
<a href="<?php echo htmlspecialchars($whatsappUrl); ?>"
   target="_blank"
   rel="noopener noreferrer"
   id="waBtn"
   title="Chat on WhatsApp"
   aria-label="WhatsApp Support">

    <!-- Pulse ring -->
    <span class="wa-pulse"></span>

    <!-- Icon -->
    <span class="wa-icon">
        <svg viewBox="0 0 32 32" width="28" height="28" fill="currentColor">
            <path d="M16 0C7.163 0 0 7.163 0 16c0 2.827.739 5.476 2.027 7.779L0 32l8.458-2.009A15.93 15.93 0 0016 32c8.837 0 16-7.163 16-16S24.837 0 16 0zm0 29.333a13.27 13.27 0 01-6.771-1.849l-.486-.288-5.022 1.193 1.264-4.886-.318-.503A13.267 13.267 0 012.667 16C2.667 8.636 8.636 2.667 16 2.667S29.333 8.636 29.333 16 23.364 29.333 16 29.333zm7.274-9.91c-.399-.2-2.359-1.164-2.724-1.296-.365-.133-.631-.2-.897.2-.266.398-1.031 1.296-1.264 1.562-.232.266-.465.299-.864.1-.399-.2-1.684-.621-3.207-1.98-1.185-1.058-1.985-2.365-2.218-2.764-.232-.399-.025-.615.175-.813.18-.18.399-.465.598-.698.2-.232.266-.399.399-.664.133-.266.066-.499-.033-.698-.1-.2-.897-2.163-1.23-2.963-.324-.779-.653-.674-.897-.686l-.765-.013c-.266 0-.698.1-1.063.499-.366.398-1.396 1.364-1.396 3.327s1.43 3.86 1.629 4.126c.2.266 2.813 4.294 6.817 6.024.953.412 1.697.658 2.277.843.956.305 1.826.262 2.515.159.767-.114 2.359-.964 2.692-1.895.332-.931.332-1.729.232-1.895-.099-.166-.365-.266-.764-.465z"/>
        </svg>
    </span>

    <!-- Tooltip -->
    <span class="wa-tooltip">
        <i class="fas fa-headset" style="margin-right:6px;"></i>
        Support এ যোগাযোগ করুন
    </span>
</a>

<style>
/* WhatsApp floating button */
#waBtn {
    position: fixed;
    bottom: 28px;
    right: 28px;
    z-index: 9999;
    width: 60px;
    height: 60px;
    background: #25D366;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    text-decoration: none;
    box-shadow: 0 6px 24px rgba(37,211,102,0.45);
    transition: transform .25s cubic-bezier(.4,0,.2,1), box-shadow .25s;
    cursor: pointer;
}

#waBtn:hover {
    transform: scale(1.12);
    box-shadow: 0 10px 35px rgba(37,211,102,0.6);
}

/* Pulse ring */
.wa-pulse {
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    border: 3px solid rgba(37,211,102,0.5);
    animation: wa-ring 2.4s ease-out infinite;
    pointer-events: none;
}

@keyframes wa-ring {
    0%   { transform: scale(1);   opacity: .9; }
    70%  { transform: scale(1.4); opacity: 0; }
    100% { transform: scale(1.4); opacity: 0; }
}

/* Icon container */
.wa-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 2;
}

/* Tooltip */
.wa-tooltip {
    position: absolute;
    right: 70px;
    background: #1a1a2e;
    color: #fff;
    font-size: .82rem;
    font-weight: 600;
    white-space: nowrap;
    padding: .5rem 1rem;
    border-radius: 8px;
    pointer-events: none;
    opacity: 0;
    transform: translateX(10px);
    transition: opacity .2s, transform .2s;
    box-shadow: 0 4px 20px rgba(0,0,0,.3);
    font-family: 'Hind Siliguri', 'Inter', sans-serif;
}

.wa-tooltip::after {
    content: '';
    position: absolute;
    top: 50%;
    right: -6px;
    transform: translateY(-50%);
    border: 6px solid transparent;
    border-left-color: #1a1a2e;
    border-right: none;
}

#waBtn:hover .wa-tooltip {
    opacity: 1;
    transform: translateX(0);
}

/* Mobile: smaller */
@media (max-width: 600px) {
    #waBtn { width: 52px; height: 52px; bottom: 20px; right: 20px; }
    .wa-tooltip { display: none; }
}
</style>

</body>
</html>
