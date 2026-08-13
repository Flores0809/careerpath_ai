-- CareerPath AI — Migration 14: Change log + undo
-- --------------------------------------------------------------------
-- counselor_log (the paper's COUNSELOR_LOG entity) only ever recorded
-- "viewed_profile" / "recorded_outcome" events — it never captured the old
-- value of a field before an edit, so there was nothing to restore from if
-- a counselor/admin made a mistake (wrong category deleted, wrong account
-- disabled, a field edited to the wrong value, etc.).
--
-- change_log is a separate, general-purpose before/after snapshot table.
-- Every risky admin/counselor mutation (account edits, account status
-- toggles, career edits, category add/rename/delete, skill requirements)
-- now writes an old_values/new_values JSON snapshot here before it happens.
-- php/change_history.php lets an administrator review that history and, for
-- entries flagged revertible, restore the old values with one click.
--
-- Deliberately NOT used for every single action in the app (e.g. approving/
-- rejecting a pending career touches two tables and can cascade-delete
-- student recommendation history if reverted carelessly — those stay
-- logged elsewhere or not auto-revertible; see php/change_history.php's
-- comments for the exact list).

USE careerpath_ai;

CREATE TABLE IF NOT EXISTS change_log (
    log_id        INT AUTO_INCREMENT PRIMARY KEY,
    table_name    VARCHAR(50) NOT NULL,   -- e.g. 'users', 'students', 'careers', 'career_categories', 'skill_requirements'
    record_id     INT NOT NULL,           -- primary key value of the affected row in table_name
    record_label  VARCHAR(255) NULL,      -- human-readable snapshot (name/title) so history stays readable after the row changes again or is gone
    action        ENUM('insert','update','delete') NOT NULL,
    old_values    JSON NULL,              -- full row snapshot before the change (NULL for insert)
    new_values    JSON NULL,              -- full row snapshot after the change (NULL for delete)
    changed_by    INT NULL,               -- FK to users.user_id; who made the change
    changed_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reverted_at   TIMESTAMP NULL,         -- set once this specific change has been undone
    reverted_by   INT NULL,               -- FK to users.user_id; who clicked "Revert"
    FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (reverted_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE INDEX idx_change_log_table_record ON change_log (table_name, record_id);
CREATE INDEX idx_change_log_changed_at ON change_log (changed_at);
