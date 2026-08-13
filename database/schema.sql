-- CareerPath AI — Barebone MVP Schema
-- Matches the ERD in Chapter III.
-- Career RIASEC values are stored on a 0-100 scale for readability; the
-- matching microservice normalizes them to 0-1, mean-centers each profile,
-- then runs cosine similarity (see matching-service/app.py's
-- profile_similarity() and README's "How the matching actually works").

CREATE DATABASE IF NOT EXISTS careerpath_ai;
USE careerpath_ai;

DROP TABLE IF EXISTS skill_requirements;
DROP TABLE IF EXISTS careers;

-- Accounts & roles. administrator manages counselor/administrator accounts
-- via php/users.php; counselor reviews/approves careers via php/careers.php.
-- After importing this file, visit php/setup_admin.php once to create the
-- first administrator account (see README "Accounts & roles").
CREATE TABLE IF NOT EXISTS users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    email          VARCHAR(150) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('administrator', 'counselor') NOT NULL,
    status         ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_by     INT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE careers (
    career_id           INT AUTO_INCREMENT PRIMARY KEY,
    career_title         VARCHAR(150) NOT NULL,
    -- Industry/job cluster grouping (e.g. "Hospitality & Culinary Arts",
    -- "Engineering, Architecture & Construction") — lets the assessment's
    -- dream-career picker group similar jobs together and keep clearly
    -- different fields (e.g. Chef vs. Architect) visibly apart. Set from
    -- php/careers_manage.php or php/careers.php. Nullable so ungrouped
    -- careers don't break anything; they just won't appear under a cluster
    -- until a counselor/administrator categorizes them.
    career_category      VARCHAR(100) NULL,
    description          TEXT,
    daily_task           TEXT,
    educational_pathway  VARCHAR(255),
    -- JHS/SHS subjects a student should focus on if aiming for this career
    -- (e.g. "TVL - Cookery, Home Economics, Food Science/Chemistry") —
    -- shown alongside the RIASEC match % and trait-gap breakdown on the
    -- results page so a recommendation comes with something concrete to
    -- act on academically, not just a percentage. Same editable pattern as
    -- career_category: set from php/careers_manage.php or php/careers.php,
    -- nullable until a counselor/administrator fills it in.
    key_subjects         VARCHAR(255) NULL,
    r_score              INT NOT NULL, -- Realistic
    i_score              INT NOT NULL, -- Investigative
    a_score              INT NOT NULL, -- Artistic
    s_score              INT NOT NULL, -- Social
    e_score              INT NOT NULL, -- Enterprising
    c_score              INT NOT NULL, -- Conventional
    source               VARCHAR(50) DEFAULT 'seed',
    -- 'inactive' hides a career from the matching engine (app.py only pulls
    -- status='active') without deleting it, so recommendation history that
    -- references it stays intact. Set/cleared from php/careers_manage.php.
    status               ENUM('active','pending','inactive') DEFAULT 'active',
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SKILL_REQUIREMENTS entity — the skills-verification mechanism named in
-- Specific Objective 2 / Research Gap #4: lets a counselor define what a
-- career actually requires (php/careers_manage.php), so a student's
-- self-reported skills can be checked against it on the results page.
CREATE TABLE skill_requirements (
    skill_req_id       INT AUTO_INCREMENT PRIMARY KEY,
    career_id          INT NOT NULL,
    skill_name         VARCHAR(150) NOT NULL,
    proficiency_level  ENUM('basic','intermediate','advanced') NOT NULL DEFAULT 'basic',
    is_required        BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (career_id) REFERENCES careers(career_id) ON DELETE CASCADE
);

-- Student accounts (STUDENT entity in the ERD). Students self-register at
-- php/student_register.php and log in at php/student_login.php — a
-- separate login domain from staff accounts (`users` table above).
CREATE TABLE IF NOT EXISTS students (
    student_id     INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    email          VARCHAR(150) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    grade_level    VARCHAR(50) NULL,
    status         ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- STUDENT_PROFILE entity — one row per RIASEC assessment a student submits.
CREATE TABLE IF NOT EXISTS student_profiles (
    profile_id        INT AUTO_INCREMENT PRIMARY KEY,
    student_id        INT NOT NULL,
    r_score           DECIMAL(5,4) NOT NULL,
    i_score           DECIMAL(5,4) NOT NULL,
    a_score           DECIMAL(5,4) NOT NULL,
    s_score           DECIMAL(5,4) NOT NULL,
    e_score           DECIMAL(5,4) NOT NULL,
    c_score           DECIMAL(5,4) NOT NULL,
    -- Free-text, comma-separated skills the student says they have, captured
    -- alongside the RIASEC assessment (php/assessment.php). Compared against
    -- skill_requirements per recommended career on the results page.
    skills            TEXT NULL,
    -- Optional academic average (0-100 scale), ties into SCCT's self-efficacy framing.
    academic_average  DECIMAL(5,2) NULL,
    -- The career the student picked as their "dream career" before this
    -- submission (php/assessment.php's cluster -> job picker). NULL for
    -- submissions made before this field existed — those just show the
    -- flat recommendation list, same as always.
    dream_career_id   INT NULL,
    submitted_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (dream_career_id) REFERENCES careers(career_id) ON DELETE SET NULL
);

-- RECOMMENDATION entity — bridge table connecting STUDENT and CAREER,
-- one row per career shown to the student for a given profile submission.
CREATE TABLE IF NOT EXISTS recommendations (
    recommendation_id  INT AUTO_INCREMENT PRIMARY KEY,
    profile_id          INT NOT NULL,
    student_id          INT NOT NULL,
    career_id           INT NOT NULL,
    match_score          DECIMAL(5,2) NOT NULL,
    rank_position         INT NOT NULL,
    ai_output_text       TEXT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profile_id) REFERENCES student_profiles(profile_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (career_id) REFERENCES careers(career_id) ON DELETE CASCADE
);

-- COUNSELOR_LOG entity — tracks counselor/administrator interactions with a
-- student's profile (written by php/students_lookup.php on each view, or
-- with a filled-in `notes` value for an actual recorded outcome — see
-- "Counselor Reviews, Records & Submits Final Outcome" in the Flowchart).
CREATE TABLE IF NOT EXISTS counselor_log (
    log_id            INT AUTO_INCREMENT PRIMARY KEY,
    counselor_id      INT NOT NULL,
    student_id        INT NOT NULL,
    recommendation_id INT NULL,
    action            VARCHAR(50) NOT NULL DEFAULT 'viewed_profile',
    notes             TEXT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (counselor_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (recommendation_id) REFERENCES recommendations(recommendation_id) ON DELETE SET NULL
);

-- CHANGE_LOG — general before/after snapshot table backing the "undo a
-- mistake" feature (php/change_history.php), distinct from counselor_log
-- above (which only tracks student-profile view/note events, not field
-- values). Every risky account/career/category/skill edit writes an
-- old_values/new_values JSON snapshot here first; administrators can review
-- and, for revertible entries, restore the old values with one click.
CREATE TABLE IF NOT EXISTS change_log (
    log_id        INT AUTO_INCREMENT PRIMARY KEY,
    table_name    VARCHAR(50) NOT NULL,
    record_id     INT NOT NULL,
    record_label  VARCHAR(255) NULL,
    action        ENUM('insert','update','delete') NOT NULL,
    old_values    JSON NULL,
    new_values    JSON NULL,
    changed_by    INT NULL,
    changed_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reverted_at   TIMESTAMP NULL,
    reverted_by   INT NULL,
    FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (reverted_by) REFERENCES users(user_id) ON DELETE SET NULL
);
CREATE INDEX idx_change_log_table_record ON change_log (table_name, record_id);
CREATE INDEX idx_change_log_changed_at ON change_log (changed_at);

-- Pending Careers (DS3 in the paper's DFD): staging table for crawler output.
-- Nothing here is visible to students until an admin approves it into `careers`.
DROP TABLE IF EXISTS pending_careers;
CREATE TABLE pending_careers (
    pending_id           INT AUTO_INCREMENT PRIMARY KEY,
    source_title         VARCHAR(255),
    career_category      VARCHAR(100) NULL, -- set on approval (php/careers.php), copied into careers.career_category
    key_subjects         VARCHAR(255) NULL, -- set on approval (php/careers.php), copied into careers.key_subjects
    source_url           VARCHAR(500) NOT NULL UNIQUE,
    employer              VARCHAR(255),
    location              VARCHAR(255),
    education_level       VARCHAR(150),
    employment_type       VARCHAR(100),
    salary                VARCHAR(100),
    description           TEXT,
    qualifications        TEXT,
    -- AI-enriched versions (Gemini 2.5 Flash-Lite via matching-service/app.py's /enrich endpoint).
    -- Kept alongside the raw scraped fields above rather than overwriting them,
    -- so the admin can compare before approving. NULL until "Enrich with AI" is used.
    ai_description         TEXT NULL,
    ai_daily_task           TEXT NULL,
    ai_educational_pathway  VARCHAR(255) NULL,
    ai_r_score              INT NULL,
    ai_i_score              INT NULL,
    ai_a_score              INT NULL,
    ai_s_score              INT NULL,
    ai_e_score              INT NULL,
    ai_c_score              INT NULL,
    ai_enriched_at           TIMESTAMP NULL,
    search_keyword         VARCHAR(100),        -- keyword used to find this posting (e.g. "nurse")
    data_source            VARCHAR(50) NOT NULL DEFAULT 'philjobnet', -- 'philjobnet', 'onet', 'adzuna', 'remoteok'
    country                VARCHAR(100) NULL,   -- 'Philippines', 'United States', 'International (O*NET)', etc.
    suggested_r_score     INT DEFAULT 0,        -- rule-based heuristic guess, admin adjusts on approval
    suggested_i_score     INT DEFAULT 0,
    suggested_a_score     INT DEFAULT 0,
    suggested_s_score     INT DEFAULT 0,
    suggested_e_score     INT DEFAULT 0,
    suggested_c_score     INT DEFAULT 0,
    status                ENUM('pending','approved','rejected') DEFAULT 'pending',
    scraped_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at           TIMESTAMP NULL,
    reviewed_by           INT NULL,        -- FK to users.user_id; which account approved/rejected this
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL
);
-- CONSULTATIONS — Consultation Request & Appointment Scheduling between
-- students and counselors. Not a named ERD entity in the capstone paper —
-- see README for the paper-alignment note — built because it's on the
-- group's Gantt chart under Software Development.
CREATE TABLE IF NOT EXISTS consultations (
    consultation_id  INT AUTO_INCREMENT PRIMARY KEY,
    student_id       INT NOT NULL,
    counselor_id     INT NULL,
    reason           TEXT NULL,
    preferred_date   DATE NULL,
    preferred_time   TIME NULL,
    status           ENUM('pending','scheduled','completed','cancelled') NOT NULL DEFAULT 'pending',
    scheduled_date   DATE NULL,
    scheduled_time   TIME NULL,
    counselor_notes  TEXT NULL,
    requested_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (counselor_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- NOTIFICATIONS — the Notification Module from the Gantt chart. Same
-- paper-alignment caveat as consultations above.
CREATE TABLE IF NOT EXISTS notifications (
    notification_id  INT AUTO_INCREMENT PRIMARY KEY,
    audience         ENUM('student','staff') NOT NULL,
    student_id       INT NULL,
    user_id          INT NULL,
    message          VARCHAR(255) NOT NULL,
    link             VARCHAR(255) NULL,
    is_read          BOOLEAN NOT NULL DEFAULT FALSE,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- SYSTEM_SETTINGS — backs the Administrator Module's "System Settings" item.
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key    VARCHAR(100) PRIMARY KEY,
    setting_value  VARCHAR(255) NOT NULL,
    description    VARCHAR(255) NULL,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('recommendation_count', '5', 'How many careers the matching engine returns per assessment (Top-N).'),
    ('site_name', 'CareerPath AI', 'Display name shown in page titles and nav bars.');

-- CAREER_CATEGORIES — lookup table for the industry/job clusters used to
-- group careers (careers.career_category / pending_careers.career_category).
-- Managed on php/career_categories.php: administrators/counselors can add,
-- rename, describe, or remove categories there instead of free-typing a
-- string on each career's edit form. Not a foreign key on purpose — see
-- migration_13_category_management.sql for why; consistency (rename
-- cascades, delete blocked while in use) is enforced in that page instead.
CREATE TABLE IF NOT EXISTS career_categories (
    category_id  INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL UNIQUE,
    description  TEXT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO career_categories (name, description) VALUES
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

-- NOTE: if you already have a live `careerpath_ai` database (i.e. you're not
-- setting this up fresh), don't re-run this whole file — it will DROP and
-- reset your existing careers/pending_careers data. Instead, run, in order:
--   database/migration_2_ai_enrichment.sql   (adds ai_* columns)
--   database/migration_3_users_auth.sql      (adds users table + reviewed_by)
--   database/migration_4_student_accounts.sql (adds students/student_profiles/recommendations)
--   database/migration_5_counselor_log.sql     (adds counselor_log)
--   database/migration_6_career_status.sql     (widens careers.status to add 'inactive')
--   database/migration_7_international_sources.sql (adds data_source/country to pending_careers)
--   database/migration_8_skills_verification.sql    (adds proficiency_level, student skills/academic_average)
--   database/migration_9_counselor_outcomes.sql      (adds notes column to counselor_log)
--   database/migration_10_consultations_notifications_settings.sql (adds consultations/notifications/system_settings)
--   database/migration_11_career_categories.sql (adds career_category, dream_career_id)
--   database/migration_12_expand_careers.sql (adds 28 more careers so every industry cluster has real options)
--   database/migration_13_category_management.sql (adds career_categories lookup table: names + descriptions)
--   database/migration_14_change_log.sql (adds change_log table: before/after snapshots + undo)
--   database/migration_15_key_subjects.sql (adds key_subjects: recommended JHS/SHS subjects per career)

-- Seed data: RIASEC codes are approximate, based on commonly published
-- Holland Code profiles for these occupations (O*NET-style), used here
-- only as sample data to prove the matching engine end-to-end.
INSERT INTO careers (career_title, career_category, description, daily_task, educational_pathway, r_score, i_score, a_score, s_score, e_score, c_score) VALUES
('Software Developer', 'Technology & IT', 'Designs, builds, and maintains software applications and systems.', 'Writing code, debugging, attending stand-ups, reviewing pull requests.', 'BS Computer Science / Information Technology', 30, 85, 40, 20, 30, 55),
('Registered Nurse', 'Healthcare & Medical', 'Provides direct patient care and supports treatment plans in clinical settings.', 'Monitoring patients, administering medication, coordinating with doctors.', 'BS Nursing + Licensure Exam', 40, 55, 15, 90, 25, 40),
('Accountant', 'Business & Management', 'Prepares and examines financial records to ensure accuracy and compliance.', 'Reconciling accounts, preparing reports, filing tax documents.', 'BS Accountancy / Accounting Information Systems', 15, 40, 10, 25, 35, 90),
('Graphic Artist', 'Arts, Design & Media', 'Creates visual concepts for branding, marketing, and digital media.', 'Sketching layouts, editing designs, presenting concepts to clients.', 'BS/BA Fine Arts, Multimedia Arts, or related', 20, 25, 95, 30, 35, 20),
('Mechanical/Automotive Technician', 'Skilled Trades & Technical', 'Diagnoses and repairs mechanical systems in vehicles or equipment.', 'Inspecting engines, replacing parts, running diagnostics.', 'TVET / Vocational Certificate, Mechanical Engineering', 90, 45, 15, 20, 20, 35),
('Sales & Marketing Manager', 'Business & Management', 'Leads sales strategy and manages client and market relationships.', 'Client meetings, campaign planning, sales forecasting.', 'BS Business Administration / Marketing', 20, 25, 30, 45, 90, 45),
('Electrician', 'Skilled Trades & Technical', 'Installs and maintains electrical systems and wiring.', 'Reading blueprints, wiring installations, safety testing.', 'TVET / Vocational Certificate, Electrical Engineering', 90, 40, 10, 20, 25, 40),
('Psychologist / Guidance Counselor', 'Social Services & Education', 'Supports emotional, social, and academic development through counseling.', 'One-on-one sessions, assessments, case documentation.', 'BS Psychology + Licensure / Graduate Studies', 15, 45, 25, 95, 30, 35),
('Chef / Culinary Professional', 'Hospitality & Culinary Arts', 'Plans menus and prepares food in a professional kitchen setting.', 'Meal prep, recipe development, kitchen management.', 'BS Culinary Arts / Hotel & Restaurant Management', 65, 30, 55, 35, 40, 30),
('Civil Engineer', 'Engineering, Architecture & Construction', 'Designs and oversees construction of infrastructure projects.', 'Site inspections, structural calculations, project coordination.', 'BS Civil Engineering + Licensure Exam', 75, 80, 20, 25, 35, 50),
('Entrepreneur / Business Owner', 'Business & Management', 'Starts and manages a business venture, taking on financial risk.', 'Strategic planning, pitching to investors, operations management.', 'BS Entrepreneurship / Business Administration', 25, 35, 40, 40, 95, 40),
('Web/UI Designer', 'Technology & IT', 'Designs user interfaces and experiences for websites and apps.', 'Wireframing, prototyping, usability testing.', 'BS Information Technology / Multimedia Arts', 15, 40, 80, 30, 40, 35),
('Data Analyst', 'Technology & IT', 'Interprets data to help organizations make informed decisions.', 'Cleaning datasets, building dashboards, presenting insights.', 'BS Statistics / Computer Science / Information Systems', 15, 90, 20, 20, 30, 65),
('Teacher (Secondary Education)', 'Social Services & Education', 'Delivers instruction and supports student learning and development.', 'Lesson planning, classroom teaching, grading, student mentoring.', 'BS Secondary Education + LET', 20, 45, 40, 90, 35, 45),
('Police Officer / Law Enforcement', 'Public Safety & Law Enforcement', 'Maintains public safety and enforces laws within a community.', 'Patrolling, responding to incidents, report writing.', 'BS Criminology + Licensure Exam', 80, 30, 15, 55, 40, 45),
('Human Resources Officer', 'Business & Management', 'Manages recruitment, employee relations, and workplace policies.', 'Interviewing candidates, processing benefits, resolving disputes.', 'BS Psychology / Human Resource Management', 15, 30, 25, 75, 55, 60),
('Architect', 'Engineering, Architecture & Construction', 'Plans and designs buildings and physical structures.', 'Drafting blueprints, client consultations, site visits.', 'BS Architecture + Licensure Exam', 55, 65, 75, 25, 35, 40),
('Agriculturist / Agricultural Technician', 'Agriculture & Environmental Science', 'Manages crop production and farm resources for productivity.', 'Soil testing, crop monitoring, equipment operation.', 'BS Agriculture / Agricultural Technology', 85, 60, 15, 30, 30, 35),

-- Migration 12 additions — more depth per industry cluster so the
-- assessment's dream-career picker offers real choices, not a single
-- career per field.
('Medical Technologist', 'Healthcare & Medical', 'Performs laboratory tests on patient samples to help diagnose and monitor diseases.', 'Running lab tests, analyzing specimens, maintaining lab equipment, recording results.', 'BS Medical Technology / Medical Laboratory Science + Licensure Exam', 20, 85, 15, 40, 20, 60),
('Physical Therapist', 'Healthcare & Medical', 'Helps patients recover movement and manage pain through therapeutic exercise and treatment.', 'Assessing patients, designing therapy plans, guiding exercises, tracking recovery progress.', 'BS Physical Therapy + Licensure Exam', 55, 50, 15, 85, 25, 35),
('Pharmacist', 'Healthcare & Medical', 'Dispenses medications and advises patients and healthcare providers on safe, effective drug use.', 'Filling prescriptions, checking drug interactions, counseling patients, managing inventory.', 'BS Pharmacy + Licensure Exam', 15, 75, 15, 45, 25, 70),
('Midwife', 'Healthcare & Medical', 'Provides care to women during pregnancy, childbirth, and postpartum recovery.', 'Prenatal checkups, assisting deliveries, postpartum care, health education.', 'BS Midwifery + Licensure Exam', 35, 50, 15, 90, 25, 35),
('Photographer / Videographer', 'Arts, Design & Media', 'Captures and edits photos or videos for events, media, and creative projects.', 'Shooting photos/video, editing footage, managing equipment, client consultations.', 'BA Multimedia Arts / Fine Arts, or portfolio-based TVET training', 30, 20, 90, 30, 40, 25),
('Fashion Designer', 'Arts, Design & Media', 'Designs clothing and accessories, from concept sketches to finished garments.', 'Sketching designs, selecting fabrics, pattern-making, overseeing production.', 'BS Fashion Design / Multimedia Arts', 25, 20, 95, 25, 45, 25),
('Interior Designer', 'Arts, Design & Media', 'Plans and designs functional, aesthetically pleasing interior spaces.', 'Space planning, material selection, client presentations, coordinating contractors.', 'BS Interior Design', 30, 35, 85, 35, 40, 40),
('Film/Video Editor', 'Arts, Design & Media', 'Assembles and edits raw footage into a polished final video product.', 'Cutting footage, adding effects/sound, color grading, collaborating with directors.', 'BA Multimedia Arts / Film', 20, 35, 85, 25, 25, 40),
('Hotel & Restaurant Manager', 'Hospitality & Culinary Arts', 'Oversees daily operations of a hotel or restaurant, ensuring quality service and profitability.', 'Staff supervision, budgeting, customer service, coordinating operations.', 'BS Hotel & Restaurant Management / Tourism Management', 25, 25, 30, 55, 80, 50),
('Baker / Pastry Chef', 'Hospitality & Culinary Arts', 'Prepares baked goods and desserts for bakeries, restaurants, or hotels.', 'Mixing and baking recipes, decorating pastries, managing inventory, food safety.', 'BS Culinary Arts / TVET Baking & Pastry Certificate', 60, 25, 65, 30, 30, 35),
('Tour Guide / Travel Consultant', 'Hospitality & Culinary Arts', 'Plans itineraries and guides travelers, sharing knowledge of destinations and culture.', 'Leading tours, booking arrangements, answering traveler questions, handling logistics.', 'BS Tourism Management', 30, 40, 35, 70, 60, 30),
('Flight Attendant', 'Hospitality & Culinary Arts', 'Ensures passenger safety and comfort aboard commercial flights.', 'Safety briefings, assisting passengers, serving meals, handling emergencies.', 'BS Tourism Management / Hospitality Management + airline training', 30, 25, 25, 80, 45, 40),
('Firefighter', 'Public Safety & Law Enforcement', 'Responds to fires and emergencies to protect life and property.', 'Fire suppression, rescue operations, equipment maintenance, safety inspections.', 'BS Fire and Safety Technology / TVET training', 85, 30, 15, 60, 35, 35),
('Criminologist / Forensic Investigator', 'Public Safety & Law Enforcement', 'Analyzes crime scenes and evidence to support criminal investigations.', 'Evidence collection, lab analysis, report writing, courtroom testimony.', 'BS Criminology + Licensure Exam', 45, 75, 20, 40, 30, 55),
('Correctional Officer', 'Public Safety & Law Enforcement', 'Supervises and maintains order among inmates in correctional facilities.', 'Monitoring inmates, enforcing rules, security checks, incident reporting.', 'BS Criminology + Licensure Exam', 70, 25, 10, 55, 35, 45),
('Veterinarian', 'Agriculture & Environmental Science', 'Diagnoses and treats illnesses and injuries in animals.', 'Examining animals, performing surgery, prescribing treatment, client education.', 'Doctor of Veterinary Medicine + Licensure Exam', 55, 80, 15, 65, 25, 40),
('Environmental Scientist', 'Agriculture & Environmental Science', 'Studies environmental conditions and develops solutions to protect natural resources.', 'Field sampling, data analysis, writing reports, recommending conservation measures.', 'BS Environmental Science / Biology', 45, 85, 20, 35, 25, 45),
('Fisheries Technician', 'Agriculture & Environmental Science', 'Manages and monitors fish production and aquatic resources.', 'Monitoring water quality, fish stock management, equipment maintenance, record-keeping.', 'BS Fisheries / Agricultural Technology', 80, 50, 15, 30, 25, 40),
('Network/Systems Administrator', 'Technology & IT', 'Maintains an organization''s computer networks and systems infrastructure.', 'Configuring servers, monitoring network performance, troubleshooting, security updates.', 'BS Information Technology / Computer Science', 45, 70, 15, 30, 25, 65),
('Cybersecurity Analyst', 'Technology & IT', 'Protects computer systems and networks from digital threats and breaches.', 'Monitoring for threats, running security audits, responding to incidents, patching vulnerabilities.', 'BS Information Technology / Computer Science (Cybersecurity track)', 30, 85, 15, 25, 30, 60),
('Bank Teller / Financial Services Officer', 'Business & Management', 'Handles customer transactions and financial services at a bank branch.', 'Processing deposits/withdrawals, opening accounts, resolving customer concerns, balancing cash.', 'BS Accountancy / Business Administration / Finance', 15, 30, 15, 60, 45, 80),
('Real Estate Broker', 'Business & Management', 'Facilitates buying, selling, and leasing of properties for clients.', 'Property listings, client meetings, negotiations, closing transactions.', 'BS Real Estate Management + Licensure Exam', 20, 25, 25, 55, 85, 40),
('Welder', 'Skilled Trades & Technical', 'Joins metal parts using welding equipment for construction and manufacturing.', 'Reading blueprints, operating welding equipment, inspecting welds, maintaining tools.', 'TVET / Vocational Certificate, Welding NC I/II', 90, 30, 15, 15, 15, 35),
('Plumber', 'Skilled Trades & Technical', 'Installs and repairs piping systems for water, gas, and drainage.', 'Installing pipes, fixing leaks, reading blueprints, inspecting systems.', 'TVET / Vocational Certificate, Plumbing NC I/II', 90, 30, 10, 25, 20, 35),
('Social Worker', 'Social Services & Education', 'Supports individuals and families facing social, emotional, or economic challenges.', 'Case assessment, counseling, connecting clients to resources, documentation.', 'BS Social Work + Licensure Exam', 20, 40, 25, 95, 35, 40),
('Early Childhood Educator', 'Social Services & Education', 'Teaches and nurtures young children''s early learning and development.', 'Lesson planning, classroom activities, monitoring development, parent communication.', 'BS Early Childhood Education', 20, 35, 55, 90, 30, 40),
('Electrical Engineer', 'Engineering, Architecture & Construction', 'Designs and oversees electrical systems for buildings, power, and infrastructure.', 'Circuit design, system testing, project oversight, compliance checks.', 'BS Electrical Engineering + Licensure Exam', 65, 80, 20, 25, 35, 55),
('Industrial Engineer', 'Engineering, Architecture & Construction', 'Optimizes processes, systems, and resources for efficient production and operations.', 'Process analysis, workflow design, quality control, efficiency reporting.', 'BS Industrial Engineering + Licensure Exam', 45, 75, 20, 35, 45, 65);

-- Recommended JHS/SHS subjects per seed career (migration_15_key_subjects.sql).
UPDATE careers SET key_subjects = CASE career_title
    WHEN 'Software Developer' THEN 'Computer Programming, Empowerment Technologies, Mathematics'
    WHEN 'Registered Nurse' THEN 'Biology, Chemistry, General Science, English'
    WHEN 'Accountant' THEN 'Mathematics, Business Math, ABM core subjects, English'
    WHEN 'Graphic Artist' THEN 'Arts, Media and Information Literacy, Computer/ICT electives'
    WHEN 'Mechanical/Automotive Technician' THEN 'TVL - Automotive Servicing, Physics, Mathematics'
    WHEN 'Sales & Marketing Manager' THEN 'Business Math, Principles of Marketing, English, ABM core subjects'
    WHEN 'Electrician' THEN 'TVL - Electrical Installation and Maintenance, Physics, Mathematics'
    WHEN 'Psychologist / Guidance Counselor' THEN 'Personal Development, Psychology electives, English, Social Science'
    WHEN 'Chef / Culinary Professional' THEN 'TVL - Cookery, Home Economics, Food Science/Chemistry'
    WHEN 'Civil Engineer' THEN 'Mathematics, Physics, Earth Science'
    WHEN 'Entrepreneur / Business Owner' THEN 'Entrepreneurship, Business Math, ABM core subjects'
    WHEN 'Web/UI Designer' THEN 'Computer/ICT electives, Media and Information Literacy, Empowerment Technologies'
    WHEN 'Data Analyst' THEN 'Mathematics, Statistics and Probability, Computer/ICT electives'
    WHEN 'Teacher (Secondary Education)' THEN 'English, Communication subjects, chosen specialization subject (Math/Science/etc.)'
    WHEN 'Police Officer / Law Enforcement' THEN 'Physical Education, Values Education, Social Science'
    WHEN 'Human Resources Officer' THEN 'Business Math, Organization and Management, English, Psychology electives'
    WHEN 'Architect' THEN 'Mathematics, Physics, Arts/Design electives'
    WHEN 'Agriculturist / Agricultural Technician' THEN 'TVL - Agri-Fishery Arts, Biology, Earth Science'
    WHEN 'Medical Technologist' THEN 'Biology, Chemistry, General Science'
    WHEN 'Physical Therapist' THEN 'Biology, Physical Education, General Science'
    WHEN 'Pharmacist' THEN 'Chemistry, Biology, Mathematics'
    WHEN 'Midwife' THEN 'Biology, General Science, Health/Home Economics'
    WHEN 'Photographer / Videographer' THEN 'Media and Information Literacy, Arts, Computer/ICT electives'
    WHEN 'Fashion Designer' THEN 'Arts, TVL - Dressmaking/Tailoring, Media and Information Literacy'
    WHEN 'Interior Designer' THEN 'Arts, Mathematics, TVL - Design electives'
    WHEN 'Film/Video Editor' THEN 'Media and Information Literacy, Computer/ICT electives, Arts'
    WHEN 'Hotel & Restaurant Manager' THEN 'TVL - Hotel and Restaurant Services, Business Math, English'
    WHEN 'Baker / Pastry Chef' THEN 'TVL - Bread and Pastry Production, Home Economics, Food Science/Chemistry'
    WHEN 'Tour Guide / Travel Consultant' THEN 'TVL - Tourism, English, Social Science/Geography'
    WHEN 'Flight Attendant' THEN 'English, Physical Education, TVL - Tourism'
    WHEN 'Firefighter' THEN 'Physical Education, Earth/Physical Science, TVL - safety-related electives'
    WHEN 'Criminologist / Forensic Investigator' THEN 'Social Science, Chemistry, Biology, English'
    WHEN 'Correctional Officer' THEN 'Values Education, Physical Education, Social Science'
    WHEN 'Veterinarian' THEN 'Biology, Chemistry, General Science'
    WHEN 'Environmental Scientist' THEN 'Biology, Earth Science, Chemistry'
    WHEN 'Fisheries Technician' THEN 'TVL - Agri-Fishery Arts, Biology, Earth Science'
    WHEN 'Network/Systems Administrator' THEN 'Computer/ICT electives, Mathematics, Empowerment Technologies'
    WHEN 'Cybersecurity Analyst' THEN 'Computer/ICT electives, Mathematics, Empowerment Technologies'
    WHEN 'Bank Teller / Financial Services Officer' THEN 'Mathematics, Business Math, ABM core subjects'
    WHEN 'Real Estate Broker' THEN 'Business Math, English, ABM core subjects'
    WHEN 'Welder' THEN 'TVL - Welding, Physics, Mathematics'
    WHEN 'Plumber' THEN 'TVL - Plumbing, Physics, Mathematics'
    WHEN 'Social Worker' THEN 'Social Science, Personal Development, English'
    WHEN 'Early Childhood Educator' THEN 'Child Development, English, Personal Development'
    WHEN 'Electrical Engineer' THEN 'Mathematics, Physics, Computer/ICT electives'
    WHEN 'Industrial Engineer' THEN 'Mathematics, Physics, Statistics and Probability'
    ELSE key_subjects
END;
