-- CareerPath AI — Migration 21: AI-generated commentary on student results
-- --------------------------------------------------------------------
-- Adds student-facing AI commentary on a submission's own RIASEC result and
-- chosen dream career, generated once by the matching service right after
-- php/submit.php inserts the student_profiles row, and cached here rather
-- than regenerated on every page view (submit.php, student_history.php,
-- career_profile.php just read these columns back).
--
-- Two distinct pieces, both best-effort/non-fatal — if the matching service
-- or Gemini isn't reachable at submission time, these simply stay NULL and
-- every page that would show them falls back to verified-data-only, same
-- graceful-fallback convention already used for career enrichment (see
-- crawler/enrichment_helper.py, migration_20).
--
--   ai_summary            Personalized paraphrase of THIS submission's own
--                         RIASEC scores (already computed deterministically
--                         by the assessment) — explains what the numbers
--                         mean in plain language, doesn't introduce new
--                         claims.
--
--   ai_career_commentary  Why the student's dream_career_id fits them, plus
--                         skills to focus on — grounded in that career's
--                         counselor-approved description/key_subjects/
--                         skill_requirements where those exist.
--
--   ai_skills_are_suggested  A flag set by application code (PHP calling
--                         the matching service, which itself decides this
--                         in Python before ever calling Gemini) — never
--                         something the model asserts about itself. True
--                         only when dream_career_id had no counselor-
--                         verified skill_requirements for the AI to
--                         reference, meaning the skills mentioned in
--                         ai_career_commentary were generated rather than
--                         sourced from reviewed data. Pages show a small
--                         "AI suggestion" label whenever this is true, so
--                         the distinction between verified and AI-invented
--                         content stays visible to the student.
--
-- To undo: this is purely additive (four nullable/defaulted columns on an
-- existing table, no new foreign keys, no behavior change to existing
-- columns) — safe to just stop calling the new /student_commentary
-- endpoint from submit.php, or drop the four columns outright, with no
-- knock-on effect to matching, recommendations, or approval.

USE careerpath_ai;

ALTER TABLE student_profiles
    ADD COLUMN ai_summary TEXT NULL,
    ADD COLUMN ai_career_commentary TEXT NULL,
    ADD COLUMN ai_skills_are_suggested TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN ai_commentary_generated_at TIMESTAMP NULL;
