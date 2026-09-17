-- CareerPath AI — Migration 25: Student age
-- --------------------------------------------------------------------
-- Adds students.age — requested by the client so age is captured at
-- sign-up alongside LRN and grade level. Kept as a plain nullable integer
-- rather than a computed birthdate field: this system only ever needs the
-- student's current age at a point in time (JHS/SHS range, roughly 11-19),
-- not a birthdate for other purposes, so a simpler field avoids the extra
-- complexity of date math for no real benefit here.
--
-- Nullable at the DB level so existing accounts (created before this
-- column existed) don't break — the app enforces "required" going forward
-- at the registration form level (php/student_register.php), same pattern
-- used for grade_level and student_number (LRN).
--
-- Undo: ALTER TABLE students DROP COLUMN age;

USE careerpath_ai;

ALTER TABLE students
    ADD COLUMN age TINYINT UNSIGNED NULL AFTER grade_level;
