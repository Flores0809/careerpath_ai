-- Migration 29: record of rejected student registrations.
--
-- Rejecting a pending account removes it from `students` (freeing the email
-- and LRN so the student can register again) and keeps a copy here, shown in
-- Manage Accounts > Approvals > Rejected. An admin can restore or purge it.
USE careerpath_ai;

CREATE TABLE IF NOT EXISTS rejected_registrations (
    rejection_id   INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    student_number CHAR(12) NOT NULL,
    email          VARCHAR(150) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    grade_level    VARCHAR(50) NULL,
    age            TINYINT UNSIGNED NULL,
    registered_at  TIMESTAMP NULL,
    rejected_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    rejected_by    INT NULL,
    reason         VARCHAR(255) NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Undo: DROP TABLE rejected_registrations;
