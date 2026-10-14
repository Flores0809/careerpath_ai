<?php
// CareerPath AI - Administrator Module: Database Backup
// --------------------------------------------------------------------
// Same paper-alignment caveat as settings.php: "Database Backup" isn't one
// of the paper's 5 named System Administrator use cases — built anyway
// since it's on the group's Gantt chart. Exports application tables as
// plain INSERT statements (no shell_exec/mysqldump dependency, so it works
// on any XAMPP install without extra configuration) and streams it as a
// downloadable .sql file.
//
// Backup options: instead of only ever exporting everything (which gets huge
// over time, mostly from assessment history, notifications and logs), the
// administrator picks which GROUPS of data to include. Each group is just a
// named set of the same tables below.

require __DIR__ . '/auth.php';
$currentUser = require_role(['administrator']);

$pdo = get_db();

$tables = [
    'users', 'students', 'careers', 'career_categories', 'skill_requirements',
    'student_profiles', 'student_career_insights', 'recommendations', 'counselor_log',
    'change_log', 'pending_careers', 'pending_career_skills', 'consultations',
    'notifications', 'system_settings',
];

// Backup groups (key => label, description, tables). Every table in $tables
// belongs to exactly one group.
$backupGroups = [
    'accounts' => ['label' => 'Accounts', 'desc' => 'Staff and student login accounts', 'tables' => ['users', 'students']],
    'careers' => ['label' => 'Careers & settings', 'desc' => 'Career catalog, categories, required skills, pending review queue, system settings', 'tables' => ['careers', 'career_categories', 'skill_requirements', 'pending_careers', 'pending_career_skills', 'system_settings']],
    'assessments' => ['label' => 'Student assessments', 'desc' => 'Assessment results, recommendations, saved AI insights (usually the largest data)', 'tables' => ['student_profiles', 'recommendations', 'student_career_insights']],
    'consultations' => ['label' => 'Consultations & counselor notes', 'desc' => 'Consultation requests and counselor notes/outcomes', 'tables' => ['consultations', 'counselor_log']],
    'logs' => ['label' => 'Notifications & change history', 'desc' => 'Notification history and the audit/change log (can grow large)', 'tables' => ['notifications', 'change_log']],
];

if (isset($_GET['download'])) {
    // Which groups did the administrator pick? Only known group keys are
    // accepted (whitelist), so nothing user-supplied reaches a SQL string.
    $selectedGroups = array_values(array_intersect(array_keys($backupGroups), (array) ($_GET['groups'] ?? [])));
    if (!$selectedGroups) {
        header('Location: backup.php?error=none');
        exit;
    }
    $selectedTables = [];
    foreach ($selectedGroups as $g) {
        $selectedTables = array_merge($selectedTables, $backupGroups[$g]['tables']);
    }
    // Keep the original table order (parents before children) in the output.
    $tables = array_values(array_intersect($tables, $selectedTables));
    $isPartial = count($selectedGroups) < count($backupGroups);

    $filename = 'careerpath_ai_backup_' . ($isPartial ? implode('-', $selectedGroups) . '_' : 'full_') . date('Y-m-d_His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "-- CareerPath AI database backup" . ($isPartial ? " (PARTIAL: " . implode(', ', array_map(fn($g) => $backupGroups[$g]['label'], $selectedGroups)) . ")" : " (full)") . "\n-- Generated " . date('Y-m-d H:i:s') . " via php/backup.php\n";
    echo "-- Tables included: " . implode(', ', $tables) . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        try {
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            continue; // table doesn't exist yet on this install (e.g. pre-migration_10) — skip it
        }
        echo "-- Table: $table (" . count($rows) . " rows)\n";
        echo "DELETE FROM `$table`;\n";
        foreach ($rows as $row) {
            $columns = array_map(fn($c) => "`$c`", array_keys($row));
            $values = array_map(function ($v) use ($pdo) {
                if ($v === null) {
                    return 'NULL';
                }
                return $pdo->quote((string) $v);
            }, array_values($row));
            echo "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

// ---------------------------------------------------------------------
// Restore: upload a .sql file previously downloaded from this page.
// Safety design — this runs administrator-uploaded SQL, so it is NOT a
// general SQL runner. The file is split into statements (respecting quoted
// strings), and EVERY statement must match one of exactly three shapes this
// page's own backup writes, otherwise nothing at all is executed:
//   SET FOREIGN_KEY_CHECKS=0|1;
//   DELETE FROM `known_table`;
//   INSERT INTO `known_table` (`col`, ...) VALUES ('text' | NULL, ...);
// Table names must be in the whitelist above, and INSERT values may only be
// quoted strings or NULL. Everything runs inside one transaction, so a
// failure part-way rolls the whole restore back.
// ---------------------------------------------------------------------

/** Split SQL text into statements on ';' that are outside quotes; drops -- comments. */
function split_sql_statements(string $sql): array
{
    $statements = [];
    $current = '';
    $inString = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inString) {
            $current .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {          // backslash escape: copy next char as-is
                $current .= $sql[++$i];
            } elseif ($ch === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") { // '' escape
                    $current .= $sql[++$i];
                } else {
                    $inString = false;
                }
            }
            continue;
        }
        if ($ch === "'") {
            $inString = true;
            $current .= $ch;
        } elseif ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {   // -- comment to end of line
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
        } elseif ($ch === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
        } else {
            $current .= $ch;
        }
    }
    if ($inString) {
        throw new RuntimeException('The file ends inside an unclosed quote — it looks incomplete or corrupted.');
    }
    if (trim($current) !== '') {
        $statements[] = trim($current);
    }
    return $statements;
}

/** Returns ['type' => 'set'|'delete'|'insert', 'table' => ?string] or null if the statement isn't an allowed shape. */
function classify_backup_statement(string $stmt, array $allowedTables): ?array
{
    if (preg_match('/^SET FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $stmt)) {
        return ['type' => 'set', 'table' => null];
    }
    if (preg_match('/^DELETE FROM `(\w+)`$/', $stmt, $m) && in_array($m[1], $allowedTables, true)) {
        return ['type' => 'delete', 'table' => $m[1]];
    }
    if (preg_match('/^INSERT INTO `(\w+)` \((`\w+`(?:, `\w+`)*)\) VALUES \((.*)\)$/s', $stmt, $m) && in_array($m[1], $allowedTables, true)) {
        // Blank out every quoted string; what's left must be only NULLs and commas.
        $rest = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", 'S', $m[3]);
        if (preg_match('/^\s*(?:S|NULL)(?:\s*,\s*(?:S|NULL))*\s*$/', $rest)) {
            return ['type' => 'insert', 'table' => $m[1]];
        }
    }
    return null;
}

$restoreMessage = null;
$restoreSummary = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    if (trim($_POST['confirm_phrase'] ?? '') !== 'RESTORE') {
        $restoreMessage = ['type' => 'error', 'text' => 'Type RESTORE in the confirmation box to continue.'];
    } elseif (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $restoreMessage = ['type' => 'error', 'text' => ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE)
            ? 'That file is larger than this server allows (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Back up fewer groups, or raise the limit in php.ini.'
            : 'Please choose a backup .sql file to upload.'];
    } elseif (strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION)) !== 'sql') {
        $restoreMessage = ['type' => 'error', 'text' => 'Only .sql backup files downloaded from this page can be restored.'];
    } else {
        try {
            $sqlText = file_get_contents($_FILES['backup_file']['tmp_name']);
            $statements = split_sql_statements($sqlText);
            if (!$statements) {
                throw new RuntimeException('The file contains no statements.');
            }
            // Validate EVERYTHING before executing anything.
            $plan = [];
            foreach ($statements as $n => $stmtText) {
                $info = classify_backup_statement($stmtText, $tables);
                if ($info === null) {
                    throw new RuntimeException('Statement #' . ($n + 1) . ' is not something this backup tool writes, so the restore was stopped before changing anything: ' . mb_substr($stmtText, 0, 80) . '…');
                }
                $plan[] = $info;
            }

            $summary = [];
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->beginTransaction();
            try {
                foreach ($statements as $n => $stmtText) {
                    $pdo->exec($stmtText);
                    $info = $plan[$n];
                    if ($info['type'] === 'delete') {
                        $summary[$info['table']] = $summary[$info['table']] ?? 0;
                    } elseif ($info['type'] === 'insert') {
                        $summary[$info['table']] = ($summary[$info['table']] ?? 0) + 1;
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new RuntimeException('Restore failed and was rolled back — nothing was changed. Details: ' . $e->getMessage());
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
            $restoreSummary = $summary;
            $restoreMessage = ['type' => 'success', 'text' => 'Restore complete. The tables below were replaced with the contents of the uploaded backup.'];
        } catch (Exception $e) {
            $restoreMessage = ['type' => 'error', 'text' => $e->getMessage()];
        }
    }
}

$tableCounts = [];
foreach ($tables as $table) {
    try {
        $tableCounts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    } catch (Exception $e) {
        $tableCounts[$table] = null; // not present on this install
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — Database Backup</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    h1, .panel { max-width: 640px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .panel { background: #f5f5f5; border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; }
    table { width: 100%; border-collapse: collapse; margin: 14px 0 20px; font-size: 14.5px; }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; }
    .missing { color: #aaa; font-style: italic; }
    .btn { display: inline-block; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 15.5px; font-weight: bold; background: #6e1423; color: #fff; }
    .btn:hover { background: #4a0c17; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
    .note { font-size: 13.5px; color: #888; margin-top: 14px; }
    .btn { border: none; cursor: pointer; font-family: inherit; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; }
    .btn-danger { background: #b02a37; margin-top: 14px; }
    .btn-danger:hover { background: #8a1f2b; }
    .restore-warn { background: #fff3cd; border: 1px solid #ffe69c; color: #664d03; border-radius: 8px; padding: 10px 14px; font-size: 14px; }
    .form-label { display: block; font-weight: bold; font-size: 14px; margin: 12px 0 4px; }
    .group-actions { font-size: 14px; margin-bottom: 10px; }
    .group-actions a { color: #6e1423; }
    .group-box { display: flex; gap: 12px; align-items: flex-start; background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; cursor: pointer; }
    .group-box input { margin-top: 4px; width: 18px; height: 18px; }
    .group-rows { margin-left: 8px; font-size: 13px; color: #6e1423; }
    .group-desc { font-size: 13.5px; color: #555; }
    .group-tables { font-size: 12.5px; color: #999; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>Database Backup</h1>

    <div class="panel">
        <p>Choose which data to include, then download. Exports are SQL <code>INSERT</code> statements — restore one by running the file against your <code>careerpath_ai</code> database (after importing <code>database/schema.sql</code> for the table structure).</p>

        <?php if (($_GET['error'] ?? '') === 'none'): ?>
            <div class="flash-error">Select at least one group of data to back up.</div>
        <?php endif; ?>

        <form method="GET" action="backup.php">
            <input type="hidden" name="download" value="1">
            <div class="group-actions">
                <a href="#" id="select-all">Select all</a> · <a href="#" id="select-none">Select none</a>
            </div>
            <?php foreach ($backupGroups as $key => $group): ?>
                <?php
                    $groupRows = 0;
                    foreach ($group['tables'] as $t) { $groupRows += (int) ($tableCounts[$t] ?? 0); }
                ?>
                <label class="group-box">
                    <input type="checkbox" name="groups[]" value="<?= htmlspecialchars($key) ?>" checked>
                    <span class="group-text">
                        <strong><?= htmlspecialchars($group['label']) ?></strong>
                        <span class="group-rows"><?= number_format($groupRows) ?> rows</span><br>
                        <span class="group-desc"><?= htmlspecialchars($group['desc']) ?></span><br>
                        <span class="group-tables"><?= htmlspecialchars(implode(', ', $group['tables'])) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
            <button class="btn" type="submit">Download selected backup (.sql)</button>
        </form>

        <p class="note">This exports data only, not the table structure — keep <code>database/schema.sql</code> alongside any backup you archive. A partial backup only contains the groups you chose, and restoring it replaces only those tables — it won't touch the others.</p>
    </div>

    <div class="panel" style="margin-top:20px;">
        <h2 style="margin-top:0;color:#6e1423;font-size:18px;">Restore from a backup</h2>
        <?php if ($restoreMessage): ?>
            <div class="<?= $restoreMessage['type'] === 'success' ? 'flash-success' : 'flash-error' ?>"><?= htmlspecialchars($restoreMessage['text']) ?></div>
        <?php endif; ?>
        <?php if ($restoreSummary !== null): ?>
            <div class="cp-table-scroll"><table>
                <tr><th>Table restored</th><th>Rows loaded</th></tr>
                <?php foreach ($restoreSummary as $t => $n): ?>
                    <tr><td><?= htmlspecialchars($t) ?></td><td><?= (int) $n ?></td></tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
        <p>Upload a <code>.sql</code> file that was downloaded from this page. Only the tables inside that file are replaced — a partial backup leaves every other table untouched.</p>
        <p class="restore-warn"><strong>Warning:</strong> restoring <em>replaces</em> the current contents of those tables with what is in the file, and can't be undone. Download a fresh backup of the current data first if you might need it. If you restore the Accounts group, the current accounts (including yours) are replaced by the ones in the file.</p>
        <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Replace the current data with this backup? This cannot be undone.');">
            <input type="hidden" name="action" value="restore">
            <label class="form-label">Backup file (.sql)</label>
            <input type="file" name="backup_file" accept=".sql" required>
            <label class="form-label">Type <strong>RESTORE</strong> to confirm</label>
            <input type="text" name="confirm_phrase" autocomplete="off" required placeholder="RESTORE" style="padding:8px;border:1px solid #ccc;border-radius:4px;">
            <div><button class="btn btn-danger" type="submit">Restore backup</button></div>
        </form>
        <p class="note">Maximum upload size on this server: <?= htmlspecialchars(ini_get('upload_max_filesize')) ?>.</p>
    </div>
    <script>
        document.getElementById('select-all').addEventListener('click', function (e) { e.preventDefault(); document.querySelectorAll('input[name="groups[]"]').forEach(function (c) { c.checked = true; }); });
        document.getElementById('select-none').addEventListener('click', function (e) { e.preventDefault(); document.querySelectorAll('input[name="groups[]"]').forEach(function (c) { c.checked = false; }); });
    </script>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
