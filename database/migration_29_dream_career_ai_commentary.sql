-- CareerPath AI - Migration 29: AI note for the optional dream career
-- The student's dream career (optional) now gets its own short AI insight,
-- generated in the same Gemini call as the best-match insight. The old general
-- "summary of your scores" (ai_summary) is no longer generated or shown; the
-- column is kept so older rows are not lost.
-- php/riasec_helper.php (ensure_dream_commentary_column) also adds this column
-- on demand, so running this file is optional but recommended.

ALTER TABLE student_profiles
    ADD COLUMN ai_dream_commentary TEXT NULL AFTER ai_career_commentary;
