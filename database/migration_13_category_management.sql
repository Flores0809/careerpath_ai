-- CareerPath AI — Migration 13: Career category management
-- --------------------------------------------------------------------
-- Migration 11 added a free-text career_category column, guided only by a
-- suggestion list hardcoded in PHP (careers.php / careers_manage.php). That
-- meant counselors/admins could only ever add categories by typing a new
-- string on a career's edit form — no way to see the full list, rename a
-- category, add a description of what it covers, or remove one that's no
-- longer needed.
--
-- This migration adds a real career_categories lookup table (name +
-- description) and backfills it from every category value already in use,
-- so nothing currently on careers/pending_careers becomes orphaned. The
-- careers.career_category / pending_careers.career_category columns stay as
-- plain VARCHAR (not a foreign key) — php/career_categories.php enforces
-- consistency at the application layer (rename cascades, delete is blocked
-- while a category is still in use), which keeps this migration low-risk on
-- existing data.

USE careerpath_ai;

CREATE TABLE IF NOT EXISTS career_categories (
    category_id  INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL UNIQUE,
    description  TEXT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO career_categories (name, description) VALUES
('Technology & IT', 'Careers building, running, and securing software, networks, and digital systems.'),
('Healthcare & Medical', 'Careers diagnosing, treating, and caring for patients in hospitals, clinics, and labs.'),
('Business & Management', 'Careers running organizations — finance, banking, real estate, and operations.'),
('Arts, Design & Media', 'Creative careers in visual arts, design, photography, film, and fashion.'),
('Skilled Trades & Technical', 'Hands-on trade careers such as welding, plumbing, and other vocational/technical work.'),
('Social Services & Education', 'Careers supporting people''s wellbeing and learning — social work, teaching, community services.'),
('Hospitality & Culinary Arts', 'Careers in food service, hotels, travel, and other guest-facing hospitality roles.'),
('Engineering, Architecture & Construction', 'Careers designing and building structures, systems, and infrastructure.'),
('Public Safety & Law Enforcement', 'Careers protecting communities — police, fire, corrections, forensic investigation.'),
('Agriculture & Environmental Science', 'Careers working with land, animals, water, and the natural environment.');

-- Backfill: any category value already sitting on a career/pending_career
-- row that isn't in the seed list above (e.g. a counselor free-typed
-- something new before this migration) still gets a row here, just without
-- a description yet — visible on php/career_categories.php with an "add a
-- description" prompt rather than silently disappearing from the picker.
INSERT IGNORE INTO career_categories (name)
SELECT DISTINCT career_category FROM careers WHERE career_category IS NOT NULL;

INSERT IGNORE INTO career_categories (name)
SELECT DISTINCT career_category FROM pending_careers WHERE career_category IS NOT NULL;
