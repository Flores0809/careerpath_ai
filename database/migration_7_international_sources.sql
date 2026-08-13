-- CareerPath AI — Migration 7: International crawler sources
--
-- The panel raised a concern that the system only reflected PH job
-- requirements. Adds two columns to pending_careers so entries can be
-- tagged by where they came from:
--   data_source — which crawler/API produced this entry ('philjobnet',
--                 'onet', 'adzuna', 'remoteok')
--   country     — human-readable origin ('Philippines', 'United States',
--                 'International (O*NET)', 'Remote / International', etc.)
--
-- Existing rows are all from the PhilJobNet crawler, so they're backfilled
-- accordingly. Non-destructive otherwise — only ADDs columns.
--
-- Run this via phpMyAdmin: select the `careerpath_ai` database, go to the
-- SQL tab, paste this whole file, and click Go.

USE careerpath_ai;

ALTER TABLE pending_careers
    ADD COLUMN data_source VARCHAR(50) NOT NULL DEFAULT 'philjobnet' AFTER search_keyword,
    ADD COLUMN country VARCHAR(100) NULL AFTER data_source;

UPDATE pending_careers SET country = 'Philippines' WHERE country IS NULL;
