-- CareerPath AI — Migration 4: Student accounts + assessment history
--
-- Adds the STUDENT / STUDENT_PROFILE / RECOMMENDATION entities from the
-- paper's ERD (Chapter III, Figure 11). Students self-register at
-- php/student_register.php and log in at php/student_login.php — separate
-- from the staff accounts in `users` (administrators/counselors).
--
-- Non-destructive: only CREATEs new tables, doesn't touch anything existing.
-- Run this via phpMyAdmin: select the `careerpath_ai` database, go to the
-- SQL tab, paste this whole file, and click Go.

USE careerpath_ai;

-- STUDENT entity
CREATE TABLE IF NOT EXISTS students (
    student_id     INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    email          VARCHAR(150) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    grade_level    VARCHAR(50) NULL,   -- e.g. "Grade 10", "Grade 12 - STEM"
    status         ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- STUDENT_PROFILE entity — one row per RIASEC assessment a student submits.
-- One-to-many from STUDENT, per the ERD (a student can retake the assessment).
CREATE TABLE IF NOT EXISTS student_profiles (
    profile_id     INT AUTO_INCREMENT PRIMARY KEY,
    student_id     INT NOT NULL,
    r_score        DECIMAL(5,4) NOT NULL, -- normalized 0-1, same scale submit.php already computes
    i_score        DECIMAL(5,4) NOT NULL,
    a_score        DECIMAL(5,4) NOT NULL,
    s_score        DECIMAL(5,4) NOT NULL,
    e_score        DECIMAL(5,4) NOT NULL,
    c_score        DECIMAL(5,4) NOT NULL,
    submitted_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);

-- RECOMMENDATION entity — the multi-associative bridge table connecting
-- STUDENT and CAREER (per the ERD), one row per career shown to the student
-- for a given profile submission, storing match_score.
CREATE TABLE IF NOT EXISTS recommendations (
    recommendation_id  INT AUTO_INCREMENT PRIMARY KEY,
    profile_id          INT NOT NULL,
    student_id          INT NOT NULL,
    career_id           INT NOT NULL,
    match_score          DECIMAL(5,2) NOT NULL, -- percentage, e.g. 87.50
    rank_position         INT NOT NULL,          -- 1 = top match for that submission
    ai_output_text       TEXT NULL,             -- reserved for a future per-recommendation AI blurb
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profile_id) REFERENCES student_profiles(profile_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (career_id) REFERENCES careers(career_id) ON DELETE CASCADE
);
