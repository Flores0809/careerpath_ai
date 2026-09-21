-- ============================================================================
-- fix_duplicate_careers.sql
-- ----------------------------------------------------------------------------
-- One-time cleanup for the Railway production database.
--
-- Root cause: careerpath_ai_full_import.sql (built by concatenating
-- schema.sql + all migration files' DATA statements) preserved INSERT
-- statements from migrations that originally added careers incrementally
-- (e.g. "28 new careers" in migration_12) even though schema.sql's own seed
-- data already bakes in the full, final career catalog -- the same class of
-- bug as the duplicate ADD COLUMN / CREATE INDEX issues found earlier in that
-- same file, just for career rows instead of schema statements. Net effect:
-- many careers exist twice in the `careers` table under two different
-- career_id values, which shows up as literal duplicate cards in student
-- results (e.g. "Tour Guide / Travel Consultant" appearing twice with an
-- identical match score).
--
-- This script is safe to run on a live database with real student data:
-- it repoints every foreign-key reference from the duplicate row to the
-- single canonical (lowest-id) row for that career BEFORE deleting the
-- duplicate rows, so nothing gets orphaned or throws a foreign-key error.
--
-- Grouping key: (career_title, career_category, career_scope). This is safe
-- because career_scope genuinely distinguishes the intentional local vs.
-- international variants of the same job title (migration_19) -- those are
-- NOT touched by this script, only true duplicates sharing all three fields.
--
-- Run this once via HeidiSQL against the Railway database, then re-check
-- careers_manage.php -- no career title should appear twice with the same
-- category anymore.
-- ============================================================================

START TRANSACTION;

-- One row per distinct (title, category, scope): the lowest career_id in
-- each group is the canonical row every duplicate should be merged into.
CREATE TEMPORARY TABLE canonical_careers AS
SELECT career_title, career_category, career_scope, MIN(career_id) AS keep_id
FROM careers
GROUP BY career_title, career_category, career_scope;

-- Map every duplicate (non-canonical) career_id to the keep_id it should be
-- replaced with. Empty result = no duplicates exist, rest of script is a no-op.
CREATE TEMPORARY TABLE career_dedupe_map AS
SELECT c.career_id AS dup_id, k.keep_id AS keep_id
FROM careers c
JOIN canonical_careers k
  ON c.career_title = k.career_title
  AND c.career_category <=> k.career_category
  AND c.career_scope <=> k.career_scope
WHERE c.career_id <> k.keep_id;

SELECT COUNT(*) AS duplicate_rows_found FROM career_dedupe_map;

-- Repoint every table that references careers.career_id -----------------

UPDATE skill_requirements sr
JOIN career_dedupe_map m ON sr.career_id = m.dup_id
SET sr.career_id = m.keep_id;

UPDATE student_profiles sp
JOIN career_dedupe_map m ON sp.dream_career_id = m.dup_id
SET sp.dream_career_id = m.keep_id;

UPDATE recommendations r
JOIN career_dedupe_map m ON r.career_id = m.dup_id
SET r.career_id = m.keep_id;

UPDATE pending_careers pc
JOIN career_dedupe_map m ON pc.approved_career_id = m.dup_id
SET pc.approved_career_id = m.keep_id;

-- student_career_insights has a composite PRIMARY KEY (student_id,
-- career_id) -- if a student already has an insight row for BOTH the
-- duplicate and the canonical career, repointing would collide on that
-- primary key. Drop the duplicate-side row first in that specific case,
-- then repoint everything else normally.
DELETE sci_dup FROM student_career_insights sci_dup
JOIN career_dedupe_map m ON sci_dup.career_id = m.dup_id
JOIN student_career_insights sci_keep
  ON sci_keep.student_id = sci_dup.student_id
  AND sci_keep.career_id = m.keep_id;

UPDATE student_career_insights sci
JOIN career_dedupe_map m ON sci.career_id = m.dup_id
SET sci.career_id = m.keep_id;

-- Now safe to remove the duplicate career rows themselves ----------------

DELETE c FROM careers c
JOIN career_dedupe_map m ON c.career_id = m.dup_id;

-- Verification: should return zero rows after the fix.
SELECT career_title, career_category, career_scope, COUNT(*) AS row_count
FROM careers
GROUP BY career_title, career_category, career_scope
HAVING COUNT(*) > 1;

DROP TEMPORARY TABLE canonical_careers;
DROP TEMPORARY TABLE career_dedupe_map;

COMMIT;
