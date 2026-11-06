-- Adds two-factor sign-in to an existing database. Import once, after selecting
-- the database in phpMyAdmin, and before uploading the new code.
ALTER TABLE users
    ADD COLUMN totp_secret    VARCHAR(64)     NULL,
    ADD COLUMN totp_last_step BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS recovery_codes (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id   INT UNSIGNED NOT NULL,
    code_hash CHAR(64)     NOT NULL,
    UNIQUE KEY uq_recovery_code (user_id, code_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
