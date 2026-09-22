-- CareerPath AI — Migration 27: allow 'delete_account' in change_log.action
-- --------------------------------------------------------------------
-- change_log.action is a strict ENUM('insert','update','delete'). The new
-- account-deletion-with-24-hour-undo feature (php/change_log_helper.php's
-- delete_account_with_log()) writes a new action value, 'delete_account',
-- to distinguish a full account+related-data snapshot from a plain single-
-- row 'delete'. Without this migration, that INSERT fails outright on a
-- strict-mode MySQL server (Railway's default) — run this BEFORE deploying
-- the new code, or every delete attempt will error out.
--
-- Run this against whichever database is already selected in HeidiSQL
-- (the same one fix_duplicate_careers.sql was run against) — no USE
-- statement here on purpose, so it doesn't fail if the DB name differs
-- from your local setup.

ALTER TABLE change_log
    MODIFY COLUMN action ENUM('insert','update','delete','delete_account') NOT NULL;
