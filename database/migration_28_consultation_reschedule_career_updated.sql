-- CareerPath AI — Migration 28: consultation reschedule marker + career "last updated" date
-- --------------------------------------------------------------------
-- Added in response to the final oral defense panel comments:
--   * Fabellon: appointment statuses should show Rescheduled, and students
--     can reschedule without filing a new request  -> consultations.rescheduled_at
--   * Fabellon: show the source AND the last-updated date of career
--     information so students can judge how current it is -> careers.updated_at
--
-- Non-destructive: only ADDs two columns. Existing rows are untouched, except
-- that every existing career gets "today" as its updated_at (MySQL fills the
-- default when the column is added); from now on it refreshes automatically
-- whenever a career row is edited, enriched, or re-approved.
--
-- Run via phpMyAdmin/HeidiSQL on the careerpath_ai (or railway) database.
-- Undo:
--   ALTER TABLE consultations DROP COLUMN rescheduled_at;
--   ALTER TABLE careers DROP COLUMN updated_at;

ALTER TABLE consultations
    ADD COLUMN rescheduled_at DATETIME NULL AFTER scheduled_time;

ALTER TABLE careers
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
