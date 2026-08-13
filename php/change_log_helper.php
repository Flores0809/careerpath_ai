<?php
// CareerPath AI - Change log / undo helpers
// --------------------------------------------------------------------
// counselor_log only ever recorded event names ("viewed_profile",
// "recorded_outcome") — never a field's value before it was overwritten.
// change_log fixes that for the handful of admin/counselor actions that can
// silently do real damage if clicked by mistake (wrong account disabled,
// wrong category deleted, a field edited to the wrong value): every one of
// those call sites now calls log_change() with a full row snapshot before
// and after, and php/change_history.php can call revert_change() to
// restore the old values.
//
// Deliberately NOT wired into every mutation in the app — CHANGE_LOG_PK_COLUMNS
// below is the exact list of tables covered, and why some things (like
// approving a pending career) are handled differently: see revert_change()'s
// careers-specific guard further down.

require_once __DIR__ . '/db.php';

// Primary-key column per loggable table — needed by revert_change() to
// build a targeted UPDATE/DELETE/INSERT for exactly one row.
const CHANGE_LOG_PK_COLUMNS = [
    'users' => 'user_id',
    'students' => 'student_id',
    'careers' => 'career_id',
    'career_categories' => 'category_id',
    'skill_requirements' => 'skill_req_id',
];

/**
 * Record a before/after snapshot of a row-level change.
 * $oldValues is null for an insert; $newValues is null for a delete.
 */
function log_change(PDO $pdo, string $table, int $recordId, ?string $label, string $action, ?array $oldValues, ?array $newValues, ?int $changedBy): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO change_log (table_name, record_id, record_label, action, old_values, new_values, changed_by)
         VALUES (:table_name, :record_id, :record_label, :action, :old_values, :new_values, :changed_by)"
    );
    $stmt->execute([
        'table_name' => $table,
        'record_id' => $recordId,
        'record_label' => $label,
        'action' => $action,
        'old_values' => $oldValues !== null ? json_encode($oldValues) : null,
        'new_values' => $newValues !== null ? json_encode($newValues) : null,
        'changed_by' => $changedBy,
    ]);
}

/**
 * Restore a change_log entry's old state. Returns ['ok' => bool, 'message' => string].
 *
 * - update  -> UPDATE the row back to old_values.
 * - delete  -> re-INSERT the row from old_values (explicit PK, so anything
 *              that still points at that ID stays intact).
 * - insert  -> DELETE the row that was created. Guarded: if other data now
 *              depends on it (e.g. a student has since taken the assessment
 *              and recommendations reference this career), the delete is
 *              blocked rather than silently cascading and losing that
 *              student's history — this is the "no gaps / no lost data"
 *              guard the undo feature exists for.
 */
function revert_change(PDO $pdo, int $logId, int $revertedBy): array
{
    $stmt = $pdo->prepare("SELECT * FROM change_log WHERE log_id = :id");
    $stmt->execute(['id' => $logId]);
    $entry = $stmt->fetch();

    if (!$entry) {
        return ['ok' => false, 'message' => 'Log entry not found.'];
    }
    if ($entry['reverted_at']) {
        return ['ok' => false, 'message' => 'This change was already reverted.'];
    }

    $table = $entry['table_name'];
    $pk = CHANGE_LOG_PK_COLUMNS[$table] ?? null;
    if (!$pk) {
        return ['ok' => false, 'message' => "Don't know how to revert changes to \"$table\"."];
    }

    $recordId = (int) $entry['record_id'];
    $oldValues = $entry['old_values'] ? json_decode($entry['old_values'], true) : null;
    $newValues = $entry['new_values'] ? json_decode($entry['new_values'], true) : null;

    // Extra safety net specific to careers: undoing the *creation* of a
    // career (i.e. un-approving it) would need to delete the row, but
    // careers.career_id is referenced by recommendations and
    // student_profiles.dream_career_id with ON DELETE CASCADE / SET NULL —
    // a silent delete here could wipe a student's saved match history.
    // Block it and point the admin at the safe alternative (Deactivate).
    if ($entry['action'] === 'insert' && $table === 'careers') {
        $refStmt = $pdo->prepare("SELECT COUNT(*) FROM recommendations WHERE career_id = :id");
        $refStmt->execute(['id' => $recordId]);
        $refCount = (int) $refStmt->fetchColumn();
        $dreamStmt = $pdo->prepare("SELECT COUNT(*) FROM student_profiles WHERE dream_career_id = :id");
        $dreamStmt->execute(['id' => $recordId]);
        $dreamCount = (int) $dreamStmt->fetchColumn();
        if ($refCount > 0 || $dreamCount > 0) {
            return ['ok' => false, 'message' => "Can't undo — this career is already referenced in student recommendation history ($refCount recommendation(s), $dreamCount dream-career pick(s)). Use \"Deactivate\" on Manage Careers instead, which hides it without deleting that history."];
        }
    }

    try {
        $pdo->beginTransaction();

        if ($entry['action'] === 'update') {
            if ($oldValues === null) {
                throw new Exception('No prior values recorded for this change.');
            }
            $setSql = implode(', ', array_map(fn($col) => "$col = :$col", array_keys($oldValues)));
            $pdo->prepare("UPDATE $table SET $setSql WHERE $pk = :pk_value")
                ->execute(array_merge($oldValues, ['pk_value' => $recordId]));
        } elseif ($entry['action'] === 'delete') {
            if ($oldValues === null) {
                throw new Exception('No prior values recorded for this change.');
            }
            $cols = array_keys($oldValues);
            $colSql = implode(', ', $cols);
            $placeholderSql = implode(', ', array_map(fn($c) => ":$c", $cols));
            $pdo->prepare("INSERT INTO $table ($colSql) VALUES ($placeholderSql)")->execute($oldValues);
        } elseif ($entry['action'] === 'insert') {
            $pdo->prepare("DELETE FROM $table WHERE $pk = :pk_value")->execute(['pk_value' => $recordId]);
        }

        $pdo->prepare("UPDATE change_log SET reverted_at = NOW(), reverted_by = :by WHERE log_id = :id")
            ->execute(['by' => $revertedBy, 'id' => $logId]);

        // The revert is itself a change worth tracking (and, in principle,
        // re-revertible) — log it the same way as the original action.
        log_change(
            $pdo,
            $table,
            $recordId,
            $entry['record_label'] ? $entry['record_label'] . ' (reverted)' : null,
            $entry['action'] === 'delete' ? 'insert' : ($entry['action'] === 'insert' ? 'delete' : 'update'),
            $newValues,
            $oldValues,
            $revertedBy
        );

        $pdo->commit();
        return ['ok' => true, 'message' => 'Change reverted.'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $isFk = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1451;
        $reason = $isFk
            ? 'other data now depends on this record, so it can\'t be safely restored/removed automatically'
            : $e->getMessage();
        return ['ok' => false, 'message' => "Could not revert this change — $reason."];
    }
}
