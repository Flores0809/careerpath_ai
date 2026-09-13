-- Migration 18: notification categories + counselor notes as real notifications
-- --------------------------------------------------------------------
-- Counselor notes (counselor_log.notes) previously had NO read/unread
-- state at all — php/student_dashboard.php just counted every note a
-- student had ever received, forever, so the "You have N notes" banner
-- never went away even after the student read them.
--
-- This adds a `category` tag to the existing notifications table (so a
-- counselor note can be told apart from a consultation/assessment alert in
-- the Notifications list) and backfills one notification per existing
-- counselor note, so nothing already recorded is silently lost. New notes
-- going forward are inserted by php/students_lookup.php at the same time
-- they're written to counselor_log.

ALTER TABLE notifications
    ADD COLUMN category VARCHAR(30) NULL AFTER link;

-- Backfill: one unread notification per existing counselor note, so
-- students see their full outstanding-notes backlog once, then can mark
-- them read same as any other notification.
INSERT INTO notifications (audience, student_id, message, link, category, is_read, created_at)
SELECT
    'student',
    cl.student_id,
    'Your counselor left you a note.',
    'student_history.php',
    'counselor_note',
    0,
    cl.created_at
FROM counselor_log cl
WHERE cl.action = 'recorded_outcome' AND cl.notes IS NOT NULL AND cl.notes != '';
