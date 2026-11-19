/**
 * POS System - Main JavaScript
 * Common functionality for all pages
 */

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    initSidebar();
    initSidebarNav();
    initAlerts();
    initFullscreen();
    initResponsiveTables();
});

/**
 * Label every table cell with its column header, so a phone can show one row
 * as one card instead of a 10-column sheet that has to be dragged sideways.
 *
 * The header text is already on the page once, so rather than editing the
 * markup of the fifteen pages that own a table, the label is read off the
 * <thead> and written onto each cell as data-label. The stylesheet then draws
 * "Label   value" lines (see the MOBILE RESPONSIVE PASS block in
 * assets/css/style.css). Nothing here is width-dependent: the same attributes
 * sit unused on a desktop, where the table renders normally.
 *
 * Tables that really are matrices - the permission grid - opt out with
 * .table-matrix, because stacking a page-by-role grid of ticks destroys the
 * one thing it shows.
 *
 * Rows that JavaScript writes later (the stock history modal, the returns
 * detail lines) are caught by the observer, which watches for added nodes
 * only: setting data-label is an attribute change, so labelling can never
 * re-trigger itself.
 */
function initResponsiveTables() {
    function labelTables(root) {
        (root || document).querySelectorAll('table.table:not(.table-matrix)').forEach(function(table) {
            const heads = [].slice.call(table.querySelectorAll('thead th'))
                .map(function(th) { return th.textContent.trim(); });
            if (!heads.length) return;

            table.querySelectorAll('tbody tr').forEach(function(row) {
                const cells = [].slice.call(row.children);
                cells.forEach(function(cell, i) {
                    if (cell.tagName !== 'TD') return;
                    if (cell.colSpan > 1) return;           // the "nothing found" line
                    if (!cell.hasAttribute('data-label') && heads[i]) {
                        cell.setAttribute('data-label', heads[i]);
                    }
                    // A cell of two or more buttons (the Actions column on nearly
                    // every list) needs to be allowed to wrap, or six icon buttons
                    // hang off the right edge of a 320px card.
                    if (!cell.classList.contains('cell-actions') &&
                        cell.querySelectorAll('button, .btn, a.btn').length > 1) {
                        cell.classList.add('cell-actions');
                    }
                    // An address or a comment is not readable in the sliver left
                    // beside its label, so it drops onto its own line.
                    if (!cell.classList.contains('cell-wide') &&
                        cell.textContent.trim().length > 40) {
                        cell.classList.add('cell-wide');
                    }
                });
            });
        });
    }

    labelTables(document);

    if (!('MutationObserver' in window)) return;

    let queued = false;
    new MutationObserver(function() {
        if (queued) return;
        queued = true;
        (window.requestAnimationFrame || setTimeout)(function() {
            queued = false;
            labelTables(document);
        });
    }).observe(document.body, { childList: true, subtree: true });
}

/**
 * Initialize sidebar toggle for mobile
 *
 * The class is "open", not "active" - assets/css/style.css slides the panel in
 * with `.sidebar.open { transform: translateX(0) }`, and there is no `.active`
 * rule for the sidebar anywhere. Toggling a class the stylesheet does not know
 * would have looked correct in the source and done nothing on the phone.
 */
function initSidebar() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        // Close sidebar when clicking outside
        document.addEventListener('click', function(e) {
            if (sidebar.classList.contains('open') && 
                !sidebar.contains(e.target) && 
                !menuToggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }
}

/**
 * Collapsible sidebar groups.
 *
 * The server already opens the group that owns the page you are on, so this is
 * only about remembering what you additionally chose to open: the states are
 * written to localStorage and replayed on the next page load, which is what
 * keeps a menu from collapsing back every time you navigate. A group with no
 * stored state keeps the server's answer.
 *
 * One delegated listener covers both carets and, for a group whose own page is
 * not permitted, the whole parent row - anything carrying data-nav-toggle.
 * Carets are siblings of the parent link rather than children of it, so a
 * toggle never needs to cancel a navigation.
 */
function initSidebarNav() {
    const KEY = 'pos.admin.navOpen';
    const groups = [].slice.call(document.querySelectorAll('.nav-group[data-nav]'));
    if (!groups.length) return;

    let saved = {};
    try {
        saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {};
    } catch (e) {
        saved = {};
    }

    function sync(group) {
        const open = group.classList.contains('open');
        group.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function persist() {
        const state = {};
        groups.forEach(function (group) {
            state[group.getAttribute('data-nav')] = group.classList.contains('open') ? 1 : 0;
        });
        try {
            localStorage.setItem(KEY, JSON.stringify(state));
        } catch (e) {}
    }

    groups.forEach(function (group) {
        const key = group.getAttribute('data-nav');
        if (Object.prototype.hasOwnProperty.call(saved, key)) {
            group.classList.toggle('open', !!saved[key]);
        }
        sync(group);
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest ? e.target.closest('[data-nav-toggle]') : null;
        if (!btn) return;
        e.preventDefault();
        const group = btn.closest('.nav-group');
        if (!group) return;
        group.classList.toggle('open');
        sync(group);
        persist();
    });
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
 *
 * No symbol. The shop asked for amounts to be shown as plain numbers, and the
 * PHP side (config/db.php) was changed to match - but this JavaScript copy had
 * its own default of'' hardcoded in the parameter list, so anything rendered
 * client side still carried one. Two definitions of"the same" formatter is
 * exactly how the two drifted apart in the first place.
 *
 * @param {number} amount
 * @param {string} symbol pass one to show a symbol; empty by default
 * @returns {string}
 */
function formatCurrency(amount, symbol = '') {
    const n = parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return symbol ? symbol + ' ' + n : n;
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
