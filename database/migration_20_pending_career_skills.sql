-- CareerPath AI — Migration 20: AI-suggested required skills for pending
-- careers, plus automatic (no-button) enrichment
-- --------------------------------------------------------------------
-- Previously, "required skills" (skill_requirements) could only be added
-- AFTER a career was already approved, on careers_manage.php — there was no
-- way to stage suggested skills for a *pending* posting before approval.
--
-- This adds pending_career_skills, the pending-side mirror of
-- skill_requirements, so the Gemini enrichment call (matching-service/
-- app.py's /enrich endpoint) can suggest 3-6 required skills alongside the
-- description/daily_task/educational_pathway/RIASEC it already generates.
-- A counselor reviews/edits these suggestions on php/careers.php before
-- approving; on approval they're copied into skill_requirements for the
-- new career_id (same pattern already used for key_subjects).
--
-- This migration also coincides with a workflow change: enrichment is now
-- triggered automatically by the crawler scripts right after a new posting
-- is staged (crawler/*.py -> matching-service /enrich), instead of a
-- counselor having to click "Enrich with AI" per entry. No schema change is
-- needed for that part — it just means ai_enriched_at is now typically
-- already set by the time a counselor opens Career Review. If the matching
-- service wasn't running (or the Gemini call failed) at crawl time, the
-- entry simply stays unenriched and the counselor fills it in by hand, same
-- graceful fallback the system already had.

USE careerpath_ai;

CREATE TABLE IF NOT EXISTS pending_career_skills (
    pending_skill_id   INT AUTO_INCREMENT PRIMARY KEY,
    pending_id         INT NOT NULL,
    skill_name         VARCHAR(150) NOT NULL,
    proficiency_level  ENUM('basic','intermediate','advanced') NOT NULL DEFAULT 'basic',
    is_required        BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (pending_id) REFERENCES pending_careers(pending_id) ON DELETE CASCADE
);
