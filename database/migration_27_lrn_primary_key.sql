-- Migration 27: LRN (students.student_number) becomes required and unique.
-- student_id stays the internal primary key (foreign keys depend on it);
-- the UI no longer shows it. LRN is what Student Lookup displays/searches.
--
-- STEP 1 - must return 0 rows. Fix any listed row (give it a real 12-digit
-- LRN) or delete the test account first.
SELECT student_id, name, email FROM students
WHERE student_number IS NULL OR student_number NOT REGEXP '^[0-9]{12}$';

-- STEP 2 - back up first. If the unique index already exists, run only the
-- MODIFY line.
ALTER TABLE students
    MODIFY student_number CHAR(12) NOT NULL,
    ADD UNIQUE KEY student_number (student_number);

-- Undo:
-- ALTER TABLE students DROP INDEX student_number,
--     MODIFY student_number VARCHAR(50) NULL, ADD UNIQUE KEY student_number (student_number);
