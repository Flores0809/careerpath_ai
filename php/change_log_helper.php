<?php
// CareerPath AI - Change log / undo helpers
// --------------------------------------------------------------------

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

const DELETE_ACCOUNT_RELATED_RESTORE_ORDER = [
    'student_profiles',
    'recommendations',
    'student_career_insights',
    'counselor_log',
    'consultations',
    'notifications',
];

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
