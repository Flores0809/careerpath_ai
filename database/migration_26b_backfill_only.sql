-- CareerPath AI — Migration 26b: backfill-only re-run
-- --------------------------------------------------------------------
-- If you already ran migration_26_pending_career_link.sql BEFORE the
-- backfill UPDATE was added to it, running the full file again will error
-- on "Duplicate column" (the ALTER TABLE part). Run just this instead —
-- it's the same backfill UPDATE on its own, safe to run any number of
-- times (only touches rows still missing a link).
--
-- What it does: for every pending_careers row already marked 'approved'
-- that doesn't yet have an approved_career_id, look for a career in the
-- live `careers` table whose title matches source_title EXACTLY, and link
-- them. Rows where the title was edited during approval won't match —
-- there's no reliable way to backfill those automatically.

USE careerpath_ai;

UPDATE pending_careers pc
JOIN careers c ON c.career_title = pc.source_title
SET pc.approved_career_id = c.career_id
WHERE pc.status = 'approved' AND pc.approved_career_id IS NULL;

-- Run this after, to see which approved entries still have no link (those
-- are the ones whose title was edited on approval — check them manually
-- in Manage Careers if you want them clickable too):
-- SELECT pending_id, source_title, reviewed_at FROM pending_careers
-- WHERE status = 'approved' AND approved_career_id IS NULL;
