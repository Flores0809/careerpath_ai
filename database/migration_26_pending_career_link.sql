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
-- to). For entries already approved BEFORE this migration existed, the
-- backfill below opportunistically fills in the link by matching on exact
-- title — this works for most existing rows since career_title usually
-- isn't edited away from source_title on approval, but any row where it
-- WAS edited stays NULL (no reliable way to know which career it became).
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

-- One-time backfill for rows approved before this column existed — links
-- by exact title match against the live careers table. Safe to re-run
-- (only touches rows that are still NULL).
UPDATE pending_careers pc
JOIN careers c ON c.career_title = pc.source_title
SET pc.approved_career_id = c.career_id
WHERE pc.status = 'approved' AND pc.approved_career_id IS NULL;
