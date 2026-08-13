-- CareerPath AI — Migration 9: Counselor final-outcome recording
-- --------------------------------------------------------------------
-- The paper's System Flowchart (Figure 10) ends the counselor/admin track
-- with "Counselor Reviews, Records & Submits Final Outcome" — something
-- more than the automatic 'viewed_profile' audit entries counselor_log
-- already writes on every student lookup. This adds a free-text notes
-- column so a counselor can record an actual outcome/decision
-- (e.g. "Discussed results with student; leaning toward Civil Engineering;
-- follow-up session scheduled for next week") tied to a specific student
-- (and optionally a specific recommendation) via php/students_lookup.php.
--
-- Run this once via phpMyAdmin against an existing database.

USE careerpath_ai;

ALTER TABLE counselor_log
    ADD COLUMN notes TEXT NULL AFTER action;
