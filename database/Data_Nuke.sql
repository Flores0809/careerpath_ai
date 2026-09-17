-- CareerPath AI — Data_Nuke: full data reset, keeping STAFF accounts and configuration
-- --------------------------------------------------------------------
-- Wipes every career, every student-generated record, AND every student
-- login account — but leaves staff login accounts (users: admin/counselor)
-- and site configuration (career_categories, system_settings) untouched.
-- Students will need to sign up again (with the access code) after this runs.
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
--   students                student login accounts (name, email, LRN, password)
--
-- KEPT — NOT touched by this script:
--   users                   staff/administrator/counselor login accounts
--   career_categories       the Category / Industry Cluster list
--   system_settings         site name, access code, recommendation count, etc.
--
-- EACH TRUNCATE BELOW IS GUARDED to skip tables that don't exist yet on this
-- database (e.g. a feature's migration file was never actually run here,
-- even if schema.sql already describes it) instead of throwing a hard error
-- and stopping the rest of the script partway through — which is exactly
-- what happened the first time this ran here (student_career_insights was
-- missing, so the script stopped before ever reaching TRUNCATE careers).
--
-- This is a raw SQL wipe, NOT an action taken through the app's own
-- Change History feature — it is NOT revertible with the "Revert" button on
-- change_history.php once run (that table itself is one of the things being
-- cleared). Back up the database first if there's any chance you'll want
-- this data later:
--   mysqldump -u root careerpath_ai > backup_before_nuke.sql
--
-- After running this, the career catalog is completely empty. You do NOT
-- need to run database/schema.sql afterward — just run the web crawler
-- scripts (crawler/*.py) to repopulate pending_careers from scratch, then
-- review/approve on careers.php as usual. (schema.sql is only needed if you
-- specifically want the original ~46-career seed catalog back instead.)
--
-- How to run this:
--   Option A (phpMyAdmin): open the "careerpath_ai" database, click the
--     "SQL" tab, paste this file's contents, and click Go.
--   Option B (command line): from a terminal with mysql on PATH —
--     mysql -u root careerpath_ai < Data_Nuke.sql
--     (default XAMPP MySQL root has no password, matching php/config.php)

USE careerpath_ai;

-- TRUNCATE requires foreign-key checks off while any of these tables still
-- reference each other (e.g. skill_requirements -> careers) — safe here
-- because every table below is being cleared together in the same batch.
SET FOREIGN_KEY_CHECKS = 0;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pending_career_skills');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE pending_career_skills', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'skill_requirements');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE skill_requirements', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'student_career_insights');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE student_career_insights', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'recommendations');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE recommendations', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'counselor_log');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE counselor_log', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'student_profiles');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE student_profiles', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'change_log');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE change_log', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'notifications');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE notifications', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'consultations');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE consultations', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pending_careers');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE pending_careers', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'careers');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE careers', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Student login accounts — wiped along with their data (see header). Every
-- table above that has a student_id foreign key into `students` is already
-- cleared before this runs, and FK checks are off for the whole batch, so
-- order doesn't matter either way.
SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'students');
SET @sql := IF(@t > 0, 'TRUNCATE TABLE students', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- users (staff/admin/counselor accounts), career_categories, and
-- system_settings are deliberately left out of this script — nothing above
-- touches them.
