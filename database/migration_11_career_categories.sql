-- CareerPath AI — Migration 11: Career categories + dream career selection
-- --------------------------------------------------------------------
-- Adds an "industry/job cluster" grouping to careers, and lets a student
-- pick a dream career (from that grouping) before taking the RIASEC
-- assessment. The results page then highlights how well the student's
-- RIASEC profile matches their own stated dream career specifically,
-- instead of only ever showing an undifferentiated top-N list where
-- unrelated fields (e.g. Chef vs. Architect) can land side-by-side at
-- similar match percentages with no context for the student.
--
-- 1. careers.career_category / pending_careers.career_category — a short
--    label grouping careers into industry/job clusters (e.g.
--    "Hospitality & Culinary Arts", "Engineering, Architecture &
--    Construction"). Set per career on php/careers_manage.php (existing
--    careers) and php/careers.php (approving a pending career). Nullable
--    so existing rows don't break; php/careers_manage.php flags
--    uncategorized careers so counselors can backfill them over time.
-- 2. student_profiles.dream_career_id — the career the student picked as
--    their "dream career" before this assessment submission (nullable —
--    only new submissions from php/assessment.php's updated form set
--    this; older submissions stay NULL and just show the flat
--    recommendation list as before). ON DELETE SET NULL so deactivating
--    or removing a career later doesn't break old submission history.
--
-- Run this once via phpMyAdmin (or `mysql -u root careerpath_ai < this file`)
-- against an existing database. Safe to re-run individual ALTERs isn't
-- guaranteed on older MySQL — if a column already exists you'll get a
-- harmless "duplicate column" error you can ignore for that one statement.

USE careerpath_ai;

ALTER TABLE careers
    ADD COLUMN career_category VARCHAR(100) NULL AFTER career_title;

ALTER TABLE pending_careers
    ADD COLUMN career_category VARCHAR(100) NULL AFTER source_title;

ALTER TABLE student_profiles
    ADD COLUMN dream_career_id INT NULL AFTER academic_average,
    ADD FOREIGN KEY (dream_career_id) REFERENCES careers(career_id) ON DELETE SET NULL;

-- Backfill industry/job cluster categories for the 18 seed careers from
-- database/schema.sql, so the dream-career picker has real groupings to
-- show immediately rather than an all-"uncategorized" list.
UPDATE careers SET career_category = 'Technology & IT' WHERE career_title IN ('Software Developer', 'Web/UI Designer', 'Data Analyst');
UPDATE careers SET career_category = 'Healthcare & Medical' WHERE career_title IN ('Registered Nurse');
UPDATE careers SET career_category = 'Business & Management' WHERE career_title IN ('Accountant', 'Sales & Marketing Manager', 'Entrepreneur / Business Owner', 'Human Resources Officer');
UPDATE careers SET career_category = 'Arts, Design & Media' WHERE career_title IN ('Graphic Artist');
UPDATE careers SET career_category = 'Skilled Trades & Technical' WHERE career_title IN ('Mechanical/Automotive Technician', 'Electrician');
UPDATE careers SET career_category = 'Social Services & Education' WHERE career_title IN ('Psychologist / Guidance Counselor', 'Teacher (Secondary Education)');
UPDATE careers SET career_category = 'Hospitality & Culinary Arts' WHERE career_title IN ('Chef / Culinary Professional');
UPDATE careers SET career_category = 'Engineering, Architecture & Construction' WHERE career_title IN ('Civil Engineer', 'Architect');
UPDATE careers SET career_category = 'Public Safety & Law Enforcement' WHERE career_title IN ('Police Officer / Law Enforcement');
UPDATE careers SET career_category = 'Agriculture & Environmental Science' WHERE career_title IN ('Agriculturist / Agricultural Technician');
