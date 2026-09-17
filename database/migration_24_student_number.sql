-- CareerPath AI — Migration 24: LRN (Learner Reference Number)
-- --------------------------------------------------------------------
-- Adds students.student_number — the student's DepEd-issued 12-digit LRN
-- (Learner Reference Number), shown in the UI as "LRN" — separate from
-- students.student_id (the internal auto-increment primary key used
-- everywhere else in the code). Requested so counselors/administrators can
-- look a student up by their actual LRN, not an arbitrary database row
-- number nobody has memorized. Column name kept as student_number since
-- renaming it would mean touching every query/form that references it for
-- a label-only change — the UI text is what students/staff actually see.
--
-- Nullable + UNIQUE: nullable so existing accounts (created before this
-- column existed) don't break, UNIQUE so it still behaves like a real ID
-- once populated (MySQL allows multiple NULLs under a UNIQUE constraint,
-- so that's compatible with "existing rows have none yet").
--
-- Undo: ALTER TABLE students DROP COLUMN student_number;

USE careerpath_ai;

ALTER TABLE students
    ADD COLUMN student_number VARCHAR(50) NULL UNIQUE AFTER name;
