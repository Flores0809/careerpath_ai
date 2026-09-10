-- Migration 16: Student registration access code
-- --------------------------------------------------------------------
-- MEII does not issue students their own institutional email addresses
-- (unlike staff), so php/student_register.php cannot be gated by an email
-- domain check. Instead, registration now requires a shared access code
-- (set/changed by an administrator on php/settings.php, e.g. printed on
-- student ID handouts or announced by guidance counselors) so a random
-- visitor who finds the site can't self-enroll and spam consultations.
-- This does not verify individual identity (no roster/LRN check) — see
-- README for the fuller discussion of this trade-off and future work.

INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
    ('student_access_code', 'MEII2026', 'Code students must enter to self-register. Share this only with MEII students (e.g. announce it in class or print it on ID handouts); change it here if it leaks.');
