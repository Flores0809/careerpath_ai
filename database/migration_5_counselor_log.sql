-- CareerPath AI — Migration 5: Counselor activity log
--
-- Adds the COUNSELOR_LOG entity from the paper's ERD (Chapter III, Figure 11):
-- "COUNSELOR_LOG maintains one-to-many relationships pointing from the
-- COUNSELOR, STUDENT, and RECOMMENDATION entities to document explicit
-- system interactions indexed by specific counselors."
--
-- In this build, a log row is written every time a counselor or
-- administrator opens a student's profile on php/students_lookup.php.
-- recommendation_id is nullable since a "viewed profile" action isn't
-- always tied to one specific recommendation.
--
-- Non-destructive: only CREATEs a new table. Run this via phpMyAdmin:
-- select the `careerpath_ai` database, go to the SQL tab, paste this whole
-- file, and click Go.

USE careerpath_ai;

CREATE TABLE IF NOT EXISTS counselor_log (
    log_id            INT AUTO_INCREMENT PRIMARY KEY,
    counselor_id      INT NOT NULL,
    student_id        INT NOT NULL,
    recommendation_id INT NULL,
    action            VARCHAR(50) NOT NULL DEFAULT 'viewed_profile',
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (counselor_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (recommendation_id) REFERENCES recommendations(recommendation_id) ON DELETE SET NULL
);
