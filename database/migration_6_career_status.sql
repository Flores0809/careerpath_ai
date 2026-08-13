-- CareerPath AI — Migration 6: Career status widening (active/pending/inactive)
--
-- Widens careers.status from ENUM('active','pending') to include 'inactive'.
-- Lets a counselor/administrator hide a live career from the matching engine
-- (php/careers_manage.php's "Deactivate" action) without deleting the row —
-- deleting would cascade-delete any student recommendations that reference
-- it (recommendations.career_id ON DELETE CASCADE), destroying assessment
-- history. Existing rows are untouched; this only widens the allowed values.
--
-- Run this via phpMyAdmin: select the `careerpath_ai` database, go to the
-- SQL tab, paste this whole file, and click Go.

USE careerpath_ai;

ALTER TABLE careers
    MODIFY COLUMN status ENUM('active', 'pending', 'inactive') DEFAULT 'active';
