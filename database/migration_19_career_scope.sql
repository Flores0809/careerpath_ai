-- Migration 19: local vs. international career scope
-- --------------------------------------------------------------------
-- Groupmate feedback: for a student's DREAM career specifically, show a
-- local (Philippines) version and an international version side by side
-- on the results page (php/submit.php), when both exist in the catalog.
--
-- Approved careers previously had no stored local/international flag at
-- all — that info only ever lived on the scraped pending_careers row
-- (data_source/country), and was dropped the moment something got
-- approved into the `careers` table. This adds it back as a real column.
--
-- Limitation: this can only be set accurately GOING FORWARD (php/careers.php
-- now sets it from the pending entry's data_source at approval time).
-- Careers already approved before this migration have no way to recover
-- which source they came from, so they all default to 'local' here —
-- correct for the original seed catalog (written for Philippine JHS/SHS
-- students), but any already-approved career that actually came from an
-- international crawl (O*NET/Adzuna/RemoteOK) will need to be corrected
-- manually via php/careers_manage.php if that matters for that entry.

ALTER TABLE careers
    ADD COLUMN career_scope ENUM('local','international') NOT NULL DEFAULT 'local' AFTER source;
