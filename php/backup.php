<?php
// CareerPath AI - Administrator Module: Database Backup
// --------------------------------------------------------------------
// Same paper-alignment caveat as settings.php: "Database Backup" isn't one
// of the paper's 5 named System Administrator use cases — built anyway
// since it's on the group's Gantt chart. Exports every application table as
// plain INSERT statements (no shell_exec/mysqldump dependency, so it works
// on any XAMPP install without extra configuration) and streams it as a
// downloadable .sql file.

require __DIR__ . '/auth.php';
$currentUser = require_role(['administrator']);

$pdo = get_db();

$tables = [
    'users', 'students', 'careers', 'skill_requirements', 'student_profiles',
    'recommendations', 'counselor_log', 'pending_careers', 'consultations',
    'notifications', 'system_settings',
];

if (isset($_GET['download'])) {
    $filename = 'careerpath_ai_backup_' . date('Y-m-d_His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "-- CareerPath AI database backup\n-- Generated " . date('Y-m-d H:i:s') . " via php/backup.php\n\n";
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
<title>CareerPath AI — Database Backup</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    h1, .panel { max-width: 640px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .panel { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; }
    table { width: 100%; border-collapse: collapse; margin: 14px 0 20px; font-size: 13px; }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; }
    .missing { color: #aaa; font-style: italic; }
    .btn { display: inline-block; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: bold; background: #6e1423; color: #fff; }
    .btn:hover { background: #4a0c17; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
    .note { font-size: 12px; color: #888; margin-top: 14px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>Database Backup</h1>

    <div class="panel">
        <p>Exports every application table as SQL <code>INSERT</code> statements — restore it by running the file against a fresh <code>careerpath_ai</code> database (after importing <code>database/schema.sql</code> for the table structure).</p>
        <table>
            <tr><th>Table</th><th>Rows</th></tr>
            <?php foreach ($tableCounts as $table => $count): ?>
                <tr>
                    <td><?= htmlspecialchars($table) ?></td>
                    <td><?= $count === null ? '<span class="missing">not on this install</span>' : $count ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <a class="btn" href="backup.php?download=1">Download backup (.sql)</a>
        <p class="note">This exports data only, not the table structure — keep <code>database/schema.sql</code> alongside any backup you archive.</p>
    </div>
</body>
</html>
