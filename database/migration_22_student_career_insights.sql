-- CareerPath AI — Migration 22: on-demand AI insights for any career
-- --------------------------------------------------------------------
-- migration_21 gave student_profiles two AI-generated fields, but
-- ai_career_commentary only ever covers ONE career per submission — the
-- student's dream career pick, generated automatically right when they
-- submit the assessment.
--
-- This adds student_career_insights: a cache of AI commentary keyed by
-- (student_id, career_id), generated lazily by php/career_profile.php the
-- FIRST time a student opens any career that isn't already covered by their
-- dream-career commentary — every career they explore gets its own AI
-- insight, not just the one they picked upfront, without generating (and
-- paying the Gemini cost for) commentary on every recommended career
-- whether the student looks at it or not.
--
-- Lookup order on career_profile.php, per career:
--   1. If this career is the student's dream career for some submission
--      and that submission already has ai_career_commentary, reuse it —
--      no extra API call, and keeps the text identical to what the results
--      page/history already showed them.
--   2. Else check this table for an existing (student_id, career_id) row.
--   3. Else generate it now via matching-service's /student_commentary
--      (using the student's most recent RIASEC profile), insert it here,
--      and display it immediately.
--
-- Same graceful-fallback rule as everywhere else in this system: if the
-- matching service/Gemini isn't reachable, career_profile.php just shows
-- the verified career details with no AI Insights panel, exactly like it
-- always has.
--
-- To undo: purely additive, one new table with no changes to any existing
-- column — safe to just stop calling /student_commentary from
-- career_profile.php, or drop this table outright, with no effect on
-- matching, recommendations, or approval.

USE careerpath_ai;

CREATE TABLE IF NOT EXISTS student_career_insights (
    student_id              INT NOT NULL,
    career_id                INT NOT NULL,
    ai_career_commentary     TEXT NOT NULL,
    ai_skills_are_suggested  TINYINT(1) NOT NULL DEFAULT 0,
    generated_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, career_id),
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (career_id) REFERENCES careers(career_id) ON DELETE CASCADE
);
