-- CareerPath AI — Migration 3: Accounts & roles
-- Safe to run on an existing database (only ADDs a table and a column,
-- doesn't drop/reset anything you already have).
--
-- Introduces two account roles:
--   administrator — manages counselor (and other administrator) accounts
--                   via php/users.php. Can also review careers.
--   counselor     — reviews, edits, approves/rejects careers via
--                   php/careers.php. Cannot manage accounts.
--
-- Run this via phpMyAdmin: select the `careerpath_ai` database, go to the
-- SQL tab, paste this whole file, and click Go.
--
-- After running this, visit php/setup_admin.php ONCE in your browser to
-- create the first administrator account (see README "Accounts & roles").

USE careerpath_ai;

CREATE TABLE IF NOT EXISTS users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    email          VARCHAR(150) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('administrator', 'counselor') NOT NULL,
    status         ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_by     INT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Tracks which account approved/rejected a given crawled career entry
-- (lightweight version of the paper's COUNSELOR_LOG concept).
ALTER TABLE pending_careers
    ADD COLUMN reviewed_by INT NULL AFTER reviewed_at,
    ADD CONSTRAINT fk_pending_careers_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL;
