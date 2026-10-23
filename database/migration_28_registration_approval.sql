-- Migration 28: registration approval + LRN class list (roster).
--
-- * students.status gains 'pending': a new account whose LRN is not on the
--   uploaded class list waits for staff approval before it can log in.
-- * lrn_roster holds the school's list of valid LRNs (uploaded as CSV/Excel
--   from php/student_approvals.php). While the list is empty, registration
--   works as before (accounts are active immediately).
USE careerpath_ai;

ALTER TABLE students
    MODIFY status ENUM('active', 'disabled', 'pending') NOT NULL DEFAULT 'active';

CREATE TABLE IF NOT EXISTS lrn_roster (
    lrn         CHAR(12) NOT NULL PRIMARY KEY,
    name        VARCHAR(150) NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Undo:
-- DROP TABLE lrn_roster;
-- UPDATE students SET status = 'disabled' WHERE status = 'pending';
-- ALTER TABLE students MODIFY status ENUM('active','disabled') NOT NULL DEFAULT 'active';
