CREATE DATABASE IF NOT EXISTS membership_app
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE membership_app;

CREATE TABLE IF NOT EXISTS users (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                    VARCHAR(120) NOT NULL,
    email                   VARCHAR(190) NOT NULL,
    password_hash           VARCHAR(255) NOT NULL,
    stripe_customer_id      VARCHAR(64)  DEFAULT NULL,
    stripe_subscription_id  VARCHAR(64)  DEFAULT NULL,
    membership_plan         ENUM('free','silver','gold') NOT NULL DEFAULT 'free',
    membership_status       ENUM('inactive','active','past_due','canceled') NOT NULL DEFAULT 'inactive',
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_customer (stripe_customer_id),
    KEY idx_users_subscription (stripe_subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dummy test user. Login: test@example.com / password
INSERT INTO users (name, email, password_hash, membership_plan, membership_status)
VALUES (
    'Test Member',
    'test@example.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'free',
    'inactive'
)
ON DUPLICATE KEY UPDATE name = VALUES(name);
