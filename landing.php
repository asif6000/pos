<?php
/**
 * POS System - Landing Page
 * Shows pricing and registration options
 */

require_once 'config/db.php';
startSecureSession();

// Redirect if already logged in
if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user['role'] === 'admin') {
        redirect('admin/dashboard.php');
    } else {
        redirect('cashier/pos.php');
    }
}
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
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Hind Siliguri', 'Inter', sans-serif;
            background-color: #0a1628;
            color: #ffffff;
            overflow-x: hidden;
        }

        /* ─── NAVBAR ─── */
        .navbar {
            background: #0a1628;
            padding: 18px 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .navbar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .navbar-logo img {
            height: 42px;
        }

        .navbar-logo-text {
            display: flex;
            flex-direction: column;
        }

        .navbar-logo-text .pos-badge {
            font-size: 0.65rem;
            font-weight: 700;
            color: #3b82f6;
            letter-spacing: 2px;
            text-transform: uppercase;
            border: 1px solid #3b82f6;
            padding: 1px 6px;
            border-radius: 4px;
            display: inline-block;
            margin-top: 2px;
            width: fit-content;
        }

        .navbar-links {
            display: flex;
            gap: 36px;
            list-style: none;
        }

        .navbar-links a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: color 0.2s;
            position: relative;
        }

        .navbar-links a:hover, .navbar-links a.active {
            color: #ffffff;
        }

        .navbar-links a.active::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0; right: 0;
            height: 2px;
            background: #3b82f6;
            border-radius: 2px;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .navbar-phone {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #cbd5e1;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .navbar-phone i {
            color: #3b82f6;
            font-size: 1rem;
        }

        .btn-request-demo {
            background: #3b82f6;
            color: white;
            padding: 10px 22px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .btn-request-demo:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .btn-nav-login {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 9px 18px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.2);
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.2s;
        }

        .btn-nav-login:hover {
            color: #ffffff;
            border-color: rgba(255,255,255,0.5);
            background: rgba(255,255,255,0.07);
        }

        .btn-nav-signup {
            background: #3b82f6;
            color: white;
            padding: 9px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.2s;
        }

        .btn-nav-signup:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(59,130,246,0.4);
        }

        /* ─── HERO ─── */
        .hero {
            background: linear-gradient(135deg, #0a1628 0%, #0d1f3d 50%, #0a1628 100%);
            padding: 80px 60px 120px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 40px;
            position: relative;
            overflow: hidden;
            min-height: 620px;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.12) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -80px; left: 40%;
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.07) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-left {
            max-width: 520px;
            position: relative;
            z-index: 2;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(59, 130, 246, 0.12);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            padding: 7px 16px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 28px;
            letter-spacing: 0.3px;
        }

        .hero-badge i { font-size: 0.75rem; }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 900;
            line-height: 1.15;
            margin-bottom: 22px;
            letter-spacing: -0.5px;
        }

        .hero-title .highlight {
            color: #3b82f6;
        }

        .hero-desc {
            color: #94a3b8;
            font-size: 1.05rem;
            line-height: 1.7;
            margin-bottom: 38px;
        }

        .hero-buttons {
            display: flex;
            gap: 16px;
            margin-bottom: 50px;
            flex-wrap: wrap;
        }

        .btn-hero-primary {
            background: #3b82f6;
            color: white;
            padding: 14px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }

        .btn-hero-primary:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59,130,246,0.35);
        }

        .btn-hero-outline {
            background: transparent;
            color: white;
            padding: 14px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 2px solid rgba(255,255,255,0.25);
            transition: all 0.3s;
        }

        .btn-hero-outline:hover {
            border-color: rgba(255,255,255,0.6);
            background: rgba(255,255,255,0.05);
        }

        .hero-features {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .hero-feature-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .hero-feature-item .icon-wrap {
            width: 38px; height: 38px;
            border-radius: 10px;
            background: rgba(59, 130, 246, 0.12);
            border: 1px solid rgba(59, 130, 246, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3b82f6;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .hero-feature-item .feature-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: #e2e8f0;
        }

        .hero-feature-item .feature-desc {
            font-size: 0.75rem;
            color: #64748b;
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
            min-height: 380px;
        }

        /* CSS-only POS hardware mockup */
        .pos-mockup {
            position: relative;
            display: flex;
            align-items: flex-end;
            gap: 15px;
        }

        .pos-monitor-wrap {
            position: relative;
        }

        .pos-monitor {
            width: 340px;
            height: 230px;
            background: #111827;
            border-radius: 12px;
            border: 2px solid #1f2937;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }

        .pos-monitor-header {
            background: #1f2937;
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

        .pos-monitor-header .logo-small img {
            height: 18px;
        }

        .pos-monitor-header .search-bar {
            background: #374151;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 0.7rem;
            color: #9ca3af;
            width: 120px;
        }

        .pos-monitor-body {
            display: flex;
            height: calc(100% - 38px);
        }

        .pos-sidebar {
            width: 70px;
            background: #0f172a;
            padding: 8px 0;
        }

        .pos-sidebar-item {
            padding: 6px 10px;
            font-size: 0.6rem;
            color: #6b7280;
            cursor: pointer;
        }

        .pos-sidebar-item.active {
            background: #1e40af;
            color: white;
        }

        .pos-content {
            flex: 1;
            background: #111827;
            padding: 8px;
        }

        .pos-category-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 8px;
        }

        .pos-tab {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.6rem;
            background: #1f2937;
            color: #9ca3af;
        }

        .pos-tab.active { background: #3b82f6; color: white; }

        .pos-products {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
        }

        .pos-product {
            background: #1f2937;
            border-radius: 4px;
            padding: 4px;
            text-align: center;
        }

        .pos-product-icon {
            width: 100%;
            height: 30px;
            background: #374151;
            border-radius: 3px;
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #60a5fa;
            font-size: 0.7rem;
        }

        .pos-product-name {
            font-size: 0.5rem;
            color: #9ca3af;
        }

        .pos-product-price {
            font-size: 0.55rem;
            color: #60a5fa;
            font-weight: 700;
        }

        .pos-cart {
            width: 90px;
            background: #0f172a;
            padding: 6px;
            border-left: 1px solid #1f2937;
        }

        .pos-cart-title {
            font-size: 0.6rem;
            color: #9ca3af;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .pos-cart-item {
            background: #1f2937;
            border-radius: 3px;
            padding: 3px 5px;
            margin-bottom: 3px;
            display: flex;
            justify-content: space-between;
            font-size: 0.55rem;
            color: #d1d5db;
        }

        .pos-cart-total {
            background: #1e40af;
            border-radius: 4px;
            padding: 5px;
            text-align: center;
            font-size: 0.65rem;
            font-weight: 700;
            color: white;
            margin-top: 6px;
        }

        .pos-monitor-stand {
            width: 40px;
            height: 18px;
            background: #1f2937;
            margin: 0 auto;
            clip-path: polygon(25% 0%, 75% 0%, 90% 100%, 10% 100%);
        }

        .pos-base {
            width: 100px;
            height: 8px;
            background: #1f2937;
            margin: 0 auto;
            border-radius: 4px;
        }

        .pos-printer {
            width: 100px;
            background: #1f2937;
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .printer-top {
            width: 100%;
            height: 40px;
            background: #111827;
            border-radius: 5px;
        }

        .printer-slot {
            width: 80%;
            height: 3px;
            background: #374151;
            border-radius: 2px;
            margin: 0 auto;
        }

        .printer-receipt {
            width: 55px;
            background: white;
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
            width: 40px;
            height: 55px;
            background: #1f2937;
            border-radius: 5px 5px 10px 10px;
            position: relative;
            box-shadow: 0 5px 15px rgba(0,0,0,0.4);
        }

        .scanner-lens {
            position: absolute;
            top: 8px; left: 50%;
            transform: translateX(-50%);
            width: 25px; height: 20px;
            background: #111827;
            border-radius: 4px;
            border: 1px solid #374151;
        }

        .scanner-red {
            position: absolute;
            bottom: 6px; left: 50%;
            transform: translateX(-50%);
            width: 20px; height: 3px;
            background: #ef4444;
            border-radius: 2px;
            box-shadow: 0 0 8px rgba(239,68,68,0.8);
        }

        .scanner-base {
            width: 20px;
            height: 40px;
            background: #1f2937;
            border-radius: 10px;
        }

        .scanner-foot {
            width: 45px;
            height: 8px;
            background: #111827;
            border-radius: 4px;
        }

        /* ─── STATS BAR ─── */
        .stats-bar {
            background: #f8fafc;
            margin: -50px 60px 0;
            border-radius: 16px;
            padding: 40px 50px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            position: relative;
            z-index: 10;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .stat-icon {
            width: 56px; height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .stat-icon.blue { background: #eff6ff; color: #3b82f6; }
        .stat-icon.green { background: #f0fdf4; color: #22c55e; }
        .stat-icon.orange { background: #fff7ed; color: #f97316; }
        .stat-icon.purple { background: #faf5ff; color: #a855f7; }

        .stat-info .stat-number {
            font-size: 1.7rem;
            font-weight: 800;
            color: #111827;
            line-height: 1;
        }

        .stat-info .stat-label {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 4px;
        }

        /* ─── FEATURES ─── */
        .features-section {
            background: #f8fafc;
            padding: 100px 60px 80px;
        }

        .features-inner {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 60px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .features-left-col {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .features-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eff6ff;
            color: #3b82f6;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 22px;
            width: fit-content;
        }

        .features-title {
            font-size: 2.2rem;
            font-weight: 800;
            color: #111827;
            line-height: 1.25;
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
            background: #3b82f6;
            color: white;
            padding: 13px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all 0.2s;
            width: fit-content;
        }

        .btn-view-all:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }

        .feature-card {
            background: white;
            border-radius: 14px;
            padding: 26px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: default;
        }

        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .feature-card-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 16px;
        }

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
            line-height: 1.55;
            margin-bottom: 14px;
        }

        .feature-card .learn-more {
            font-size: 0.82rem;
            font-weight: 700;
            color: #3b82f6;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: gap 0.2s;
        }

        .feature-card .learn-more:hover { gap: 10px; }

        /* ─── TRUSTED BY ─── */
        .trusted-section {
            background: linear-gradient(180deg, #0d1f3d 0%, #0a1628 100%);
            padding: 70px 60px;
            text-align: center;
        }

        .trusted-label {
            color: #94a3b8;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 40px;
            letter-spacing: 0.5px;
        }

        .trusted-logos {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 50px;
            flex-wrap: wrap;
        }

        .trusted-logo-item {
            color: #475569;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 8px;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .trusted-logo-item:hover { opacity: 1; }
        .trusted-logo-item i { font-size: 1.1rem; }

        /* ─── FOOTER ─── */
        footer {
            background: #060e1e;
            padding: 24px 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        footer p {
            color: #475569;
            font-size: 0.85rem;
        }

        footer a {
            color: #475569;
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.2s;
        }

        footer a:hover { color: #94a3b8; }

        /* ─── RESPONSIVE ─── */
        @media (max-width: 1024px) {
            .navbar { padding: 16px 30px; }
            .navbar-links { display: none; }
            .hero { padding: 60px 30px 100px; flex-direction: column; }
            .hero-title { font-size: 2.5rem; }
            .hero-features { grid-template-columns: repeat(2, 1fr); }
            .hero-right { width: 100%; }
            .stats-bar { margin: -40px 30px 0; padding: 30px; grid-template-columns: repeat(2, 1fr); }
            .features-section { padding: 80px 30px 60px; }
            .features-inner { grid-template-columns: 1fr; }
            .features-grid { grid-template-columns: repeat(2, 1fr); }
            footer { flex-direction: column; gap: 12px; text-align: center; padding: 24px 30px; }
        }

        @media (max-width: 600px) {
            .hero-title { font-size: 2rem; }
            .hero-features { grid-template-columns: 1fr 1fr; }
            .stats-bar { grid-template-columns: 1fr; margin: -30px 20px 0; }
            .features-grid { grid-template-columns: 1fr; }
            .trusted-logos { gap: 25px; }
        }
    </style>
</head>

<body>

    <!-- ─── NAVBAR ─── -->
    <nav class="navbar">
        <div class="navbar-logo">
            <img src="assets/img/ava_logo.png" alt="AVA IT Solution">
            <div class="navbar-logo-text">
                <span class="pos-badge">POS SYSTEM</span>
            </div>
        </div>

        <ul class="navbar-links">
            <li><a href="#" class="active">Home</a></li>
            <li><a href="#features">Features</a></li>
            <li><a href="#">Why AVA</a></li>
            <li><a href="#">Pricing</a></li>
            <li><a href="#">Demo</a></li>
            <li><a href="#">Contact</a></li>
        </ul>

        <div class="navbar-right">
            <div class="navbar-phone">
                <i class="fas fa-phone-alt"></i>
                +880 1234-567890
            </div>
            <a href="auth/login.php" class="btn-nav-login">
                <i class="fas fa-sign-in-alt"></i> Login
            </a>
            <a href="auth/register.php" class="btn-nav-signup">
                <i class="fas fa-user-plus"></i> Sign Up
            </a>
        </div>
    </nav>

    <!-- ─── HERO ─── -->
    <section class="hero">
        <div class="hero-left">
            <div class="hero-badge">
                <i class="fas fa-bolt"></i>
                Smart POS Solution for Modern Business
            </div>
            <h1 class="hero-title">
                Smart POS System<br>for <span class="highlight">Smart Business</span>
            </h1>
            <p class="hero-desc">
                AVA IT SOLUTION POS System helps you manage sales, inventory,<br>
                customers and reports in one powerful platform.<br>
                Easy to use. Powerful to grow.
            </p>
            <div class="hero-buttons">
                <a href="auth/register.php" class="btn-hero-primary">
                    Request Demo <i class="fas fa-arrow-right"></i>
                </a>
                <a href="#features" class="btn-hero-outline">
                    Explore Features <i class="fas fa-th"></i>
                </a>
            </div>
            <div class="hero-features">
                <div class="hero-feature-item">
                    <div class="icon-wrap"><i class="fas fa-check-circle"></i></div>
                    <span class="feature-label">Easy to Use</span>
                    <span class="feature-desc">User friendly interface</span>
                </div>
                <div class="hero-feature-item">
                    <div class="icon-wrap"><i class="fas fa-sync-alt"></i></div>
                    <span class="feature-label">Real-time Sync</span>
                    <span class="feature-desc">All data real-time synchronization</span>
                </div>
                <div class="hero-feature-item">
                    <div class="icon-wrap"><i class="fas fa-shield-alt"></i></div>
                    <span class="feature-label">Secure & Reliable</span>
                    <span class="feature-desc">Your data is 100% safe with us</span>
                </div>
                <div class="hero-feature-item">
                    <div class="icon-wrap"><i class="fas fa-headset"></i></div>
                    <span class="feature-label">24/7 Support</span>
                    <span class="feature-desc">We're here to help anytime</span>
                </div>
            </div>
        </div>

        <!-- POS Hardware Mockup (CSS) -->
        <div class="hero-right">
            <div class="pos-mockup">
                <!-- Monitor -->
                <div class="pos-monitor-wrap">
                    <div class="pos-monitor">
                        <div class="pos-monitor-header">
                            <div class="logo-small">
                                <img src="assets/img/ava_logo.png" alt="AVA">
                            </div>
                            <div class="search-bar">Search product...</div>
                            <div style="font-size:0.6rem;color:#9ca3af;">Admin ▾</div>
                        </div>
                        <div class="pos-monitor-body">
                            <div class="pos-sidebar">
                                <div class="pos-sidebar-item active">POS</div>
                                <div class="pos-sidebar-item">Products</div>
                                <div class="pos-sidebar-item">Category</div>
                                <div class="pos-sidebar-item">Sales</div>
                                <div class="pos-sidebar-item">Customers</div>
                                <div class="pos-sidebar-item">Reports</div>
                                <div class="pos-sidebar-item">Users</div>
                                <div class="pos-sidebar-item">Settings</div>
                            </div>
                            <div class="pos-content">
                                <div class="pos-category-tabs">
                                    <div class="pos-tab active">All</div>
                                    <div class="pos-tab">Fashion</div>
                                    <div class="pos-tab">Grocery</div>
                                    <div class="pos-tab">Others</div>
                                </div>
                                <div class="pos-products">
                                    <div class="pos-product">
                                        <div class="pos-product-icon"><i class="fas fa-tshirt"></i></div>
                                        <div class="pos-product-name">T-Shirt</div>
                                        <div class="pos-product-price">৳450</div>
                                    </div>
                                    <div class="pos-product">
                                        <div class="pos-product-icon"><i class="fas fa-tshirt"></i></div>
                                        <div class="pos-product-name">Shirt</div>
                                        <div class="pos-product-price">৳650</div>
                                    </div>
                                    <div class="pos-product">
                                        <div class="pos-product-icon"><i class="fas fa-socks"></i></div>
                                        <div class="pos-product-name">Jeans</div>
                                        <div class="pos-product-price">৳1200</div>
                                    </div>
                                    <div class="pos-product">
                                        <div class="pos-product-icon"><i class="fas fa-shoe-prints"></i></div>
                                        <div class="pos-product-name">Shoes</div>
                                        <div class="pos-product-price">৳1600</div>
                                    </div>
                                    <div class="pos-product">
                                        <div class="pos-product-icon"><i class="fas fa-clock"></i></div>
                                        <div class="pos-product-name">Watch</div>
                                        <div class="pos-product-price">৳2000</div>
                                    </div>
                                    <div class="pos-product">
                                        <div class="pos-product-icon"><i class="fas fa-hat-cowboy"></i></div>
                                        <div class="pos-product-name">Cap</div>
                                        <div class="pos-product-price">৳300</div>
                                    </div>
                                    <div class="pos-product">
                                        <div class="pos-product-icon"><i class="fas fa-glasses"></i></div>
                                        <div class="pos-product-name">Sunglass</div>
                                        <div class="pos-product-price">৳700</div>
                                    </div>
                                    <div class="pos-product">
                                        <div class="pos-product-icon"><i class="fas fa-tag"></i></div>
                                        <div class="pos-product-name">Belt</div>
                                        <div class="pos-product-price">৳500</div>
                                    </div>
                                </div>
                            </div>
                            <div class="pos-cart">
                                <div class="pos-cart-title">Cart (3)</div>
                                <div class="pos-cart-item"><span>T-Shirt</span><span>x1</span></div>
                                <div class="pos-cart-item"><span>Jeans</span><span>x1</span></div>
                                <div class="pos-cart-item"><span>Watch</span><span>x1</span></div>
                                <div style="font-size:0.55rem;color:#6b7280;margin:5px 0 2px;">Subtotal: ৳4150</div>
                                <div style="font-size:0.55rem;color:#6b7280;margin-bottom:4px;">Discount: ৳150</div>
                                <div class="pos-cart-total">Checkout ৳4000</div>
                            </div>
                        </div>
                    </div>
                    <div class="pos-monitor-stand"></div>
                    <div class="pos-base"></div>
                </div>

                <!-- Printer -->
                <div class="pos-printer">
                    <div class="printer-top" style="display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-print" style="color:#374151;font-size:1rem;"></i>
                    </div>
                    <div class="printer-slot"></div>
                    <div class="printer-receipt">
                        <div class="receipt-line medium"></div>
                        <div class="receipt-line short"></div>
                        <div class="receipt-line medium"></div>
                        <div class="receipt-line short"></div>
                    </div>
                    <div style="font-size:0.55rem;color:#6b7280;text-align:center;margin-top:4px;font-weight:700;">AVA</div>
                </div>

                <!-- Scanner -->
                <div class="pos-scanner">
                    <div class="scanner-head">
                        <div class="scanner-lens"></div>
                        <div class="scanner-red"></div>
                    </div>
                    <div class="scanner-base"></div>
                    <div class="scanner-foot"></div>
                    <div style="font-size:0.5rem;color:#6b7280;margin-top:2px;font-weight:700;">AVA</div>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── STATS BAR ─── -->
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-icon blue"><i class="fas fa-shopping-cart"></i></div>
            <div class="stat-info">
                <div class="stat-number">10K+</div>
                <div class="stat-label">Businesses Trust Us</div>
            </div>
        </div>
        <div class="stat-item">
            <div class="stat-icon green"><i class="fas fa-box"></i></div>
            <div class="stat-info">
                <div class="stat-number">50K+</div>
                <div class="stat-label">Products Managed</div>
            </div>
        </div>
        <div class="stat-item">
            <div class="stat-icon orange"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-number">1M+</div>
                <div class="stat-label">Transactions Processed</div>
            </div>
        </div>
        <div class="stat-item">
            <div class="stat-icon purple"><i class="fas fa-headset"></i></div>
            <div class="stat-info">
                <div class="stat-number">24/7</div>
                <div class="stat-label">Customer Support</div>
            </div>
        </div>
    </div>

    <!-- ─── FEATURES ─── -->
    <section class="features-section" id="features">
        <div class="features-inner">
            <div class="features-left-col">
                <div class="features-badge">
                    <i class="fas fa-bolt"></i> Powerful Features
                </div>
                <h2 class="features-title">
                    Everything You Need<br>in One <span class="highlight">POS System</span>
                </h2>
                <p class="features-desc">
                    Manage your business smarter and faster with our all-in-one POS solution.
                </p>
                <a href="auth/register.php" class="btn-view-all">
                    View All Features <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-card-icon blue"><i class="fas fa-cash-register"></i></div>
                    <h4>Sales Management</h4>
                    <p>Fast billing, multiple payment methods and invoice printing.</p>
                    <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="feature-card">
                    <div class="feature-card-icon green"><i class="fas fa-boxes"></i></div>
                    <h4>Inventory Management</h4>
                    <p>Track stock in real-time, low stock alerts and inventory reports.</p>
                    <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="feature-card">
                    <div class="feature-card-icon orange"><i class="fas fa-users"></i></div>
                    <h4>Customer Management</h4>
                    <p>Manage customer data, purchase history and loyalty points.</p>
                    <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="feature-card">
                    <div class="feature-card-icon purple"><i class="fas fa-chart-bar"></i></div>
                    <h4>Reports & Analytics</h4>
                    <p>Powerful reports to analyze your business growth and profit.</p>
                    <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="feature-card">
                    <div class="feature-card-icon pink"><i class="fas fa-barcode"></i></div>
                    <h4>Barcode & Label</h4>
                    <p>Generate barcodes and print labels for your products.</p>
                    <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="feature-card">
                    <div class="feature-card-icon teal"><i class="fas fa-store"></i></div>
                    <h4>Multi Branch Support</h4>
                    <p>Manage multiple branches from one central dashboard.</p>
                    <a href="auth/register.php" class="learn-more">Learn more <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── TRUSTED BY ─── -->
    <section class="trusted-section">
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

    <!-- ─── FOOTER ─── -->
    <footer>
        <p>&copy; 2026 AVA IT Solution. All rights reserved. 🇧🇩</p>
        <div style="display:flex;gap:20px;">
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Contact</a>
        </div>
    </footer>

</body>
</html>
