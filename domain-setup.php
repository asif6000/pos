<?php
/**
 * Customer Domain Setup Instructions
 * This page provides instructions for customers to set up their custom domain
 */
require_once 'config/db.php';

// Get SaaS configuration
$db = getDB();
$settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('hosting_provider', 'saas_domain', 'ns1', 'ns2', 'ns3', 'ns4')");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$shopName = $settings['shop_name'] ?? 'Smart Collection';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Setup Instructions - <?php echo htmlspecialchars($shopName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .content {
            padding: 2rem;
        }
        
        .section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            border-radius: 8px;
            background: #f8fafc;
        }
        
        .section-title {
            font-size: 1.3rem;
            color: #4f46e5;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nameservers {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        
        .ns-item {
            padding: 0.75rem;
            margin: 0.5rem 0;
            background: #f1f5f9;
            border-radius: 4px;
            font-family: monospace;
            font-size: 1.1rem;
            font-weight: bold;
        }
        
        .steps {
            counter-reset: step-counter;
        }
        
        .step {
            counter-increment: step-counter;
            margin-bottom: 1.5rem;
            padding-left: 3rem;
            position: relative;
        }
        
        .step:before {
            content: counter(step-counter);
            position: absolute;
            left: 0;
            top: 0;
            background: #4f46e5;
            color: white;
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin: 1rem 0;
        }
        
        .alert-info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            color: #1e40af;
        }
        
        .alert-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        
        .footer {
            text-align: center;
            padding: 1.5rem;
            background: #f1f5f9;
            color: #64748b;
            font-size: 0.9rem;
        }
        
        @media (max-width: 600px) {
            .container {
                margin: 10px;
            }
            
            .header {
                padding: 1.5rem 1rem;
            }
            
            .content {
                padding: 1.5rem 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-cloud"></i> Domain Setup Guide</h1>
            <p>Connect your custom domain to <?php echo htmlspecialchars($shopName); ?></p>
        </div>
        
        <div class="content">
            <div class="section">
                <h2 class="section-title"><i class="fas fa-info-circle"></i> What You'll Need</h2>
                <p>To connect your custom domain to our POS system, you'll need:</p>
                <ul style="margin-top: 1rem; padding-left: 1.5rem;">
                    <li>Access to your domain registrar's control panel</li>
                    <li>Your domain name (e.g., myshop.com)</li>
                    <li>The nameservers provided below</li>
                </ul>
            </div>
            
            <div class="section">
                <h2 class="section-title"><i class="fas fa-server"></i> Required Nameservers</h2>
                <p>Update your domain's nameservers to point to our hosting:</p>
                
                <div class="nameservers">
                    <?php if (!empty($settings['ns1'])): ?>
                        <div class="ns-item">NS1: <?php echo htmlspecialchars($settings['ns1']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['ns2'])): ?>
                        <div class="ns-item">NS2: <?php echo htmlspecialchars($settings['ns2']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['ns3'])): ?>
                        <div class="ns-item">NS3: <?php echo htmlspecialchars($settings['ns3']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($settings['ns4'])): ?>
                        <div class="ns-item">NS4: <?php echo htmlspecialchars($settings['ns4']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (empty($settings['ns1']) && empty($settings['ns2'])): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Nameservers not configured yet. Please contact support.
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-lightbulb"></i>
                    <strong>Note:</strong> You only need to update the nameservers. Keep all other DNS settings as they are.
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title"><i class="fas fa-list-ol"></i> Setup Steps</h2>
                <div class="steps">
                    <div class="step">
                        <strong>Log into your domain registrar</strong>
                        <p>Access your domain management panel (e.g., GoDaddy, Namecheap, Hostinger)</p>
                    </div>
                    
                    <div class="step">
                        <strong>Find DNS/Nameserver settings</strong>
                        <p>Look for "Nameservers", "DNS Management", or "Advanced DNS" section</p>
                    </div>
                    
                    <div class="step">
                        <strong>Update nameservers</strong>
                        <p>Replace existing nameservers with the ones provided above</p>
                    </div>
                    
                    <div class="step">
                        <strong>Save changes</strong>
                        <p>Click save/apply to confirm the changes</p>
                    </div>
                    
                    <div class="step">
                        <strong>Wait for propagation</strong>
                        <p>Changes may take 24-48 hours to fully propagate worldwide</p>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title"><i class="fas fa-question-circle"></i> After Setup</h2>
                <p>Once DNS propagation is complete:</p>
                <ul style="margin-top: 1rem; padding-left: 1.5rem;">
                    <li>Your domain will point to our POS system</li>
                    <li>You can access your store management at your custom domain</li>
                    <li>All existing functionality will work normally</li>
                    <li>Your customers will see your branded domain</li>
                </ul>
            </div>
            
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Important:</strong> Do not change any other DNS records (A, CNAME, MX, etc.) unless specifically instructed. Only update the nameservers.
            </div>
        </div>
        
        <div class="footer">
            <p>Need help? Contact our support team</p>
            <p>© <?php echo date('Y'); ?> <?php echo htmlspecialchars($shopName); ?> - All rights reserved</p>
        </div>
    </div>
</body>
</html>