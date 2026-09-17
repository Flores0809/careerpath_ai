-- CareerPath AI — Full data reset, keeping login accounts and configuration
-- --------------------------------------------------------------------
-- Wipes every career and every student-generated record, but leaves login
-- accounts (users, students) and site configuration (career_categories,
-- system_settings) untouched — a "fresh start, nobody has to re-register"
-- reset.
--
-- WIPED (TRUNCATEd — all rows deleted, AUTO_INCREMENT IDs reset to 1):
--   pending_career_skills   AI-suggested skills staged on pending postings
--   skill_requirements      required skills on approved careers
--   student_career_insights on-demand AI career insights cache
--   recommendations         per-submission ranked career matches
--   counselor_log           counselor view/outcome history on students
--   student_profiles        every past RIASEC assessment submission
--   change_log              admin/counselor edit-and-undo audit trail
--   notifications           student + staff notification bell history
--   consultations           student consultation requests
--   pending_careers         crawler staging queue (not-yet-reviewed postings)
--   careers                 the live, approved career catalog
--
-- KEPT — NOT touched by this script:
--   users                   staff/administrator/counselor login accounts
--   students                student login accounts
--   career_categories       the Category / Industry Cluster list
--   system_settings         site name, access code, recommendation count, etc.
--
-- This is a raw SQL wipe, NOT an action taken through the app's own
-- Change History feature — it is NOT revertible with the "Revert" button on
-- change_history.php once run (that table itself is one of the things being
-- cleared). Back up the database first if there's any chance you'll want
-- this data later:
--   mysqldump -u root careerpath_ai > backup_before_reset.sql
--
-- How to run this:
--   Option A (phpMyAdmin): open the "careerpath_ai" database, click the
--     "SQL" tab, paste this file's contents, and click Go.
--   Option B (command line): from a terminal with mysql on PATH —
--     mysql -u root careerpath_ai < reset_all_except_accounts.sql
--     (default XAMPP MySQL root has no password, matching php/config.php)

USE careerpath_ai;

-- TRUNCATE requires foreign-key checks off while any of these tables still
-- reference each other (e.g. skill_requirements -> careers) — safe here
-- because every table below is being cleared together in the same batch.
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE pending_career_skills;
TRUNCATE TABLE skill_requirements;
TRUNCATE TABLE student_career_insights;
TRUNCATE TABLE recommendations;
TRUNCATE TABLE counselor_log;
TRUNCATE TABLE student_profiles;
TRUNCATE TABLE change_log;
TRUNCATE TABLE notifications;
TRUNCATE TABLE consultations;
TRUNCATE TABLE pending_careers;
TRUNCATE TABLE careers;

SET FOREIGN_KEY_CHECKS = 1;

-- users, students, career_categories, and system_settings are deliberately
-- left out of this script — nothing above touches them.
