-- CareerPath AI — Migration 26: link an approved pending entry to its live career
-- --------------------------------------------------------------------
-- Adds pending_careers.approved_career_id — set at approval time
-- (php/careers.php's 'approve' action) to the resulting live
-- careers.career_id, whether that's a brand-new row or an existing career
-- it was merged into via AI-assisted duplicate resolution. Lets pages like
-- the staff dashboard's "Recent Review Activity" list link an approved
-- entry straight to that career in Manage Careers, instead of just
-- showing static text with no way to jump to it.
--
-- Nullable: NULL for anything still pending or rejected (nothing to link
-- to), and for anything approved before this migration existed (no way to
-- retroactively know which career it became without re-matching by title,
-- which isn't reliable since titles can be edited on approval).
--
-- Real FOREIGN KEY, matching this table's existing convention for
-- reviewed_by -> users.user_id. ON DELETE SET NULL: if a career is ever
-- hard-deleted, this just reverts to NULL (no link) rather than blocking
-- the delete or leaving a dangling ID.
--
-- Undo: ALTER TABLE pending_careers DROP FOREIGN KEY <constraint_name>, DROP COLUMN approved_career_id;
--       (run SHOW CREATE TABLE pending_careers; to find the auto-generated constraint name first)

USE careerpath_ai;

ALTER TABLE pending_careers
    ADD COLUMN approved_career_id INT NULL AFTER status,
    ADD FOREIGN KEY (approved_career_id) REFERENCES careers(career_id) ON DELETE SET NULL;
