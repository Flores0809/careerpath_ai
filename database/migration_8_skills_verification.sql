-- CareerPath AI — Migration 8: Skills verification mechanism
-- --------------------------------------------------------------------
-- Adds the pieces needed for the "skills verification mechanism" named in
-- Specific Objective 2 and Research Gap #4 of the capstone paper: comparing
-- a student's own skills against a career's required skills, not just
-- RIASEC personality fit.
--
-- 1. skill_requirements.proficiency_level — the ERD's SKILL_REQUIREMENTS
--    entity already includes this column; it was missing from the original
--    barebone schema. Counselors set this per skill when managing a career
--    (php/careers_manage.php).
-- 2. student_profiles.skills — free-text, comma-separated list of skills the
--    student says they have, captured alongside the RIASEC assessment
--    (php/assessment.php -> php/submit.php). Matches the ERD's
--    STUDENT_PROFILE.skills column.
-- 3. student_profiles.academic_average — matches the ERD's
--    STUDENT_PROFILE.academic_average column (ties into SCCT's
--    self-efficacy framing). Optional field.
--
-- Run this once via phpMyAdmin (or `mysql -u root careerpath_ai < this file`)
-- against an existing database. Safe to re-run: guarded with column-exists
-- checks isn't supported by plain ALTER TABLE in older MySQL, so just run it
-- once — if a column already exists you'll get a harmless "duplicate column"
-- error you can ignore for that one statement.

USE careerpath_ai;

ALTER TABLE skill_requirements
    ADD COLUMN proficiency_level ENUM('basic','intermediate','advanced') NOT NULL DEFAULT 'basic' AFTER skill_name;

ALTER TABLE student_profiles
    ADD COLUMN skills TEXT NULL AFTER c_score,
    ADD COLUMN academic_average DECIMAL(5,2) NULL AFTER skills;
