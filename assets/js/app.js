/**
 * POS System - Main JavaScript
 * Common functionality for all pages
 */

// ─── Permission-change polling ────────────────────────────────────────────────
// On every admin page we store the version numbers the server returned at
// page-load time.  Every 30 s we poll /admin/api/check-permissions.php.
// If either version counter increments we show a toast and auto-reload.
// This gives users near-realtime feedback when the SaaS admin changes a plan.
(function initPermissionPolling() {
    // Version tokens are injected by the PHP header (see header.php).
    // If they're missing (e.g. non-admin pages) we skip polling silently.
    const initPV  = window.__POS_PERM_VERSION__  || null;
    const initPMV = window.__POS_PLAN_VERSION__  || null;
    if (!initPV && !initPMV) return;

    let lastPV  = initPV;
    let lastPMV = initPMV;
    let reloadScheduled = false;

    function scheduleReload(reason) {
        if (reloadScheduled) return;
        reloadScheduled = true;
        showToast(reason + ' Reloading in 3 s…', 'warning');
        setTimeout(function () { window.location.reload(); }, 3000);
    }

    function poll() {
        fetch('/smart/admin/api/check-permissions.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data || !data.authenticated) {
                // Session expired — redirect to login
                if (!reloadScheduled) {
                    reloadScheduled = true;
                    window.location.href = '/smart/auth/login.php';
                }
                return;
            }
            const newPV  = String(data.permissions_version  || '0');
            const newPMV = String(data.plan_modules_version || '0');

            if (lastPV !== null && newPV !== lastPV) {
                lastPV = newPV;
                scheduleReload('Your role permissions have been updated.');
            } else if (lastPMV !== null && newPMV !== lastPMV) {
                lastPMV = newPMV;
                scheduleReload('Your subscription plan has been updated.');
            }
        })
        .catch(function () { /* network error — retry next interval */ });
    }

    // Poll every 30 seconds (non-blocking, lightweight)
    setInterval(poll, 30000);
})();

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    initSidebar();
    initAlerts();
    initFullscreen();
});

/**
 * Initialize sidebar toggle for mobile
 */
function initSidebar() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    if (menuToggle && sidebar) {
        // Create overlay element
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            document.body.appendChild(overlay);
        }

        function openSidebar() {
            sidebar.classList.add('open');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        overlay.addEventListener('click', function() {
            closeSidebar();
        });

        // Close sidebar when clicking outside
        document.addEventListener('click', function(e) {
            if (sidebar.classList.contains('open') &&
                !sidebar.contains(e.target) &&
                !menuToggle.contains(e.target)) {
                closeSidebar();
            }
        });

        // Close sidebar on window resize to desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });
    }
}

/**
 * Auto-dismiss alerts after 5 seconds
 */
function initAlerts() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.3s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
}

/**
 * Fullscreen toggle
 */
function initFullscreen() {
    const btn = document.getElementById('fullscreenBtn');
    if (btn) {
        btn.addEventListener('click', function() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen();
                this.innerHTML = '<i class="fas fa-compress"></i>';
            } else {
                document.exitFullscreen();
                this.innerHTML = '<i class="fas fa-expand"></i>';
            }
        });
    }
}

/**
 * Format number as currency
 * @param {number} amount 
 * @param {string} symbol 
 * @returns {string}
 */
function formatCurrency(amount, symbol = '৳') {
    return symbol + ' ' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * Show loading overlay
 */
function showLoading() {
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.id = 'loadingOverlay';
    overlay.innerHTML = '<div class="spinner"></div>';
    document.body.appendChild(overlay);
}

/**
 * Hide loading overlay
 */
function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.remove();
    }
}

/**
 * Show toast notification
 * @param {string} message 
 * @param {string} type - success, danger, warning, info
 */
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 250px; box-shadow: var(--shadow-lg);';
    
    const icon = type === 'success' ? 'check-circle' : 
                 type === 'danger' ? 'exclamation-circle' : 
                 type === 'warning' ? 'exclamation-triangle' : 'info-circle';
    
    toast.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.transition = 'opacity 0.3s ease';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

/**
 * Confirm dialog
 * @param {string} message 
 * @returns {Promise<boolean>}
 */
function confirmDialog(message) {
    return new Promise((resolve) => {
        if (confirm(message)) {
            resolve(true);
        } else {
            resolve(false);
        }
    });
}

/**
 * Debounce function
 * @param {Function} func 
 * @param {number} wait 
 * @returns {Function}
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Format date
 * @param {string} dateString 
 * @returns {string}
 */
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
}

/**
 * Print element
 * @param {string} elementId 
 */
function printElement(elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Print</title>
            <style>
                body { font-family: Arial, sans-serif; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
            </style>
        </head>
        <body>${element.innerHTML}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
    printWindow.close();
}

// Export functions for global use
window.POS = {
    formatCurrency,
    showLoading,
    hideLoading,
    showToast,
    confirmDialog,
    debounce,
    formatDate,
    printElement
};
