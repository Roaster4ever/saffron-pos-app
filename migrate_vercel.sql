-- Migration: Add Vercel-compatible tables
CREATE TABLE IF NOT EXISTS login_attempts (
    ip_address VARCHAR(45) PRIMARY KEY,
    attempt_count INT NOT NULL DEFAULT 1,
    first_attempt INT NOT NULL
);

CREATE TABLE IF NOT EXISTS app_sessions (
    id VARCHAR(128) PRIMARY KEY,
    data MEDIUMTEXT,
    expires INT NOT NULL,
    INDEX idx_expires (expires)
);
