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

// How long a deleted account can still be restored from Change History.
// Past this, revert_change() refuses even though the change_log row (and
// its full snapshot) is kept forever — this is an app-level grace period,
// not a data-retention limit.
const ACCOUNT_DELETE_UNDO_WINDOW_SECONDS = 24 * 60 * 60;

// FK-safe order to re-INSERT a deleted student's related rows in during a
// revert (see revert_change()'s 'delete_account' branch) — student_profiles
// must exist before recommendations (profile_id FK), which is the only
// real ordering dependency among these; the rest just have to come after
// student_profiles/recommendations for consistency. Not used for a deleted
// staff (users) account's related rows (just counselor_log + notifications,
// neither depends on the other), but harmless to list here since anything
// not present in a given snapshot is simply skipped.
const DELETE_ACCOUNT_RELATED_RESTORE_ORDER = [
    'student_profiles',
    'recommendations',
    'student_career_insights',
    'counselor_log',
    'consultations',
    'notifications',
];

/**
 * Delete a staff (users) or student (students) account, first snapshotting
 * it AND every row in other tables that ON DELETE CASCADE would wipe out
 * alongside it, into one change_log entry (action 'delete_account') — so a
 * revert within the 24-hour window (see revert_change()) can restore not
 * just the account row but its full assessment history / notes too, not
 * just an empty shell account.
 *
 * Deliberately its own function rather than reusing plain log_change() +
 * a DELETE — the snapshot has to happen BEFORE the delete (so the cascaded
 * rows still exist to read), and the whole thing needs to be one
 * transaction so a crash partway through can't delete without logging.
 */
function delete_account_with_log(PDO $pdo, string $table, int $recordId, ?string $label, int $changedBy): void
{
    if (!in_array($table, ['users', 'students'], true)) {
        throw new Exception("delete_account_with_log() only supports 'users' or 'students'.");
    }
    $pkCol = CHANGE_LOG_PK_COLUMNS[$table];

    $accountStmt = $pdo->prepare("SELECT * FROM $table WHERE $pkCol = :id");
    $accountStmt->execute(['id' => $recordId]);
    $accountRow = $accountStmt->fetch();
    if (!$accountRow) {
        throw new Exception('Account not found.');
    }

    $related = [];
    if ($table === 'students') {
        // Every table with an ON DELETE CASCADE FK to students.student_id
        // (see schema.sql) has to be captured here, or that data is
        // silently wiped by MySQL's own cascade the moment the DELETE
        // below runs — with nothing in the snapshot to restore it from,
        // even inside the 24-hour window. Cross-checked against schema.sql
        // directly rather than assumed: student_profiles, recommendations,
        // student_career_insights, counselor_log, consultations, and
        // notifications are the full set as of migration_27.
        // Order matters on the way back in during revert: student_profiles
        // before recommendations (profile_id FK).
        foreach ([
            'student_profiles' => 'student_id',
            'recommendations' => 'student_id',
            'student_career_insights' => 'student_id',
            'counselor_log' => 'student_id',
            'consultations' => 'student_id',
            'notifications' => 'student_id',
        ] as $relTable => $col) {
            $s = $pdo->prepare("SELECT * FROM $relTable WHERE $col = :id");
            $s->execute(['id' => $recordId]);
            $rows = $s->fetchAll();
            if ($rows) {
                $related[$relTable] = $rows;
            }
        }
    } else { // users
        // Same reasoning as above, for users.user_id's CASCADE FKs:
        // counselor_log.counselor_id and notifications.user_id. (users.
        // created_by, change_log.changed_by/reverted_by, pending_careers.
        // reviewed_by, and consultations.counselor_id are all ON DELETE
        // SET NULL, not CASCADE — those rows survive the delete as-is, so
        // there's nothing to snapshot/restore for them.)
        foreach ([
            'counselor_log' => 'counselor_id',
            'notifications' => 'user_id',
        ] as $relTable => $col) {
            $s = $pdo->prepare("SELECT * FROM $relTable WHERE $col = :id");
            $s->execute(['id' => $recordId]);
            $rows = $s->fetchAll();
            if ($rows) {
                $related[$relTable] = $rows;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM $table WHERE $pkCol = :id")->execute(['id' => $recordId]);
        log_change($pdo, $table, $recordId, $label, 'delete_account', ['account' => $accountRow, 'related' => $related], null, $changedBy);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Restore a change_log entry's old state. Returns ['ok' => bool, 'message' => string].
 *
 * - update        -> UPDATE the row back to old_values.
 * - delete        -> re-INSERT the row from old_values (explicit PK, so anything
 *                     that still points at that ID stays intact).
 * - delete_account -> re-INSERT the account row AND every related row captured
 *                     by delete_account_with_log(), in FK-safe order. Only
 *                     allowed within ACCOUNT_DELETE_UNDO_WINDOW_SECONDS of the
 *                     original deletion — past that, refused outright (see
 *                     the check at the top of this function).
 * - insert        -> DELETE the row that was created. Guarded: if other data now
 *                     depends on it (e.g. a student has since taken the assessment
 *                     and recommendations reference this career), the delete is
 *                     blocked rather than silently cascading and losing that
 *                     student's history — this is the "no gaps / no lost data"
 *                     guard the undo feature exists for.
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
    if ($entry['action'] === 'delete_account') {
        $deadline = strtotime($entry['changed_at']) + ACCOUNT_DELETE_UNDO_WINDOW_SECONDS;
        if (time() > $deadline) {
            return ['ok' => false, 'message' => 'This deletion can no longer be undone — the 24-hour grace period has expired.'];
        }
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
        } elseif ($entry['action'] === 'delete_account') {
            if ($oldValues === null || !isset($oldValues['account'])) {
                throw new Exception('No prior values recorded for this deletion.');
            }
            $accountRow = $oldValues['account'];
            $cols = array_keys($accountRow);
            $colSql = implode(', ', $cols);
            $placeholderSql = implode(', ', array_map(fn($c) => ":$c", $cols));
            $pdo->prepare("INSERT INTO $table ($colSql) VALUES ($placeholderSql)")->execute($accountRow);

            // Related rows back in, in a fixed FK-safe order (student_profiles
            // before recommendations, which references profile_id) — each row
            // carries its own original PK, so anything else still pointing at
            // these IDs stays intact.
            //
            // Deliberately NOT just `foreach ($oldValues['related'] as $relTable
            // => $rows)` in whatever order the JSON happens to decode to: this
            // broke in production (recommendations attempted before its own
            // student_profiles row existed, throwing FK error 1452) because
            // old_values is stored in a MySQL JSON column, and MySQL's JSON
            // storage does not reliably preserve object key order through a
            // round-trip the way json_encode()/json_decode() alone would in
            // plain PHP — so DELETE_ACCOUNT_RELATED_RESTORE_ORDER below is used
            // as the authoritative sequence instead of trusting the JSON's own
            // key order.
            $relatedData = $oldValues['related'] ?? [];
            $orderedTables = array_unique(array_merge(DELETE_ACCOUNT_RELATED_RESTORE_ORDER, array_keys($relatedData)));
            foreach ($orderedTables as $relTable) {
                if (empty($relatedData[$relTable])) {
                    continue;
                }
                foreach ($relatedData[$relTable] as $row) {
                    $relCols = array_keys($row);
                    $relColSql = implode(', ', $relCols);
                    $relPlaceholderSql = implode(', ', array_map(fn($c) => ":$c", $relCols));
                    $pdo->prepare("INSERT INTO $relTable ($relColSql) VALUES ($relPlaceholderSql)")->execute($row);
                }
            }
        }

        $pdo->prepare("UPDATE change_log SET reverted_at = NOW(), reverted_by = :by WHERE log_id = :id")
            ->execute(['by' => $revertedBy, 'id' => $logId]);

        // The revert is itself a change worth tracking (and, in principle,
        // re-revertible) — log it the same way as the original action.
        // delete_account's snapshot is nested (account + related rows), not
        // a flat column map — logging just the account portion here keeps
        // this follow-up entry's diff readable on change_history.php rather
        // than dumping the whole nested blob into it.
        $revertLogOld = $entry['action'] === 'delete_account' ? null : $newValues;
        $revertLogNew = $entry['action'] === 'delete_account' ? ($oldValues['account'] ?? null) : $oldValues;
        log_change(
            $pdo,
            $table,
            $recordId,
            $entry['record_label'] ? $entry['record_label'] . ' (reverted)' : null,
            in_array($entry['action'], ['delete', 'delete_account'], true) ? 'insert' : ($entry['action'] === 'insert' ? 'delete' : 'update'),
            $revertLogOld,
            $revertLogNew,
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
