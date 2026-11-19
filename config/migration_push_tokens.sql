-- Push Tokens Migration
-- Stores Expo push tokens so the backend can notify owner/manager phones
-- when a sale is rung up on the staff app.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS push_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(50) NULL,
    token VARCHAR(255) NOT NULL,
    platform VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_token (token),
    INDEX idx_owner (owner_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB;
