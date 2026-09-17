-- CareerPath AI — Migration 23: free-text skill proficiency, not a fixed 3-level enum
-- --------------------------------------------------------------------
-- skill_requirements.proficiency_level and pending_career_skills.proficiency_level
-- were originally ENUM('basic','intermediate','advanced') (migration_8 and
-- migration_20). In practice, forcing every required skill into one of
-- exactly three rigid buckets was more trouble than it was worth — most
-- skills don't cleanly fit "basic/intermediate/advanced," and it's more
-- useful to counselors and students to see a short, concrete description
-- (e.g. "Comfortable with basic HTML/CSS" or "Can follow food-safety
-- protocols under supervision") than a single vague word.
--
-- This converts both columns from ENUM to a plain VARCHAR(150) — same width
-- as skill_name — so a counselor (or the AI enrichment call) can write
-- whatever phrase actually describes the skill level needed, instead of
-- picking from a fixed dropdown. No data is lost: existing 'basic' /
-- 'intermediate' / 'advanced' values remain exactly as they are, just in a
-- column that no longer restricts what can go there going forward.
--
-- Undo: ALTER TABLE skill_requirements MODIFY COLUMN proficiency_level
-- ENUM('basic','intermediate','advanced') NOT NULL DEFAULT 'basic'; (same
-- for pending_career_skills) — note this would truncate/error on any
-- free-text value entered after this migration ran, since it no longer fits
-- the old enum.

USE careerpath_ai;

ALTER TABLE skill_requirements
    MODIFY COLUMN proficiency_level VARCHAR(150) NOT NULL DEFAULT '';

ALTER TABLE pending_career_skills
    MODIFY COLUMN proficiency_level VARCHAR(150) NOT NULL DEFAULT '';
