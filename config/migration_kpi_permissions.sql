-- ─────────────────────────────────────────────────────────────────────────────
-- Per-account dashboard KPI cards
--
-- One row per card an admin has ticked for a staff/cashier account on the
-- Users page. No row means no card: a counter account starts with nothing and
-- the admin grants cards one person at a time (not one role at a time), which
-- is why this hangs off users.id instead of role_slug.
--
-- Safe to run more than once.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS user_kpi_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kpi_key VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_kpi (user_id, kpi_key),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB;
