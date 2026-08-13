<?php
// CareerPath AI - Change History / Undo (administrator only)
// --------------------------------------------------------------------
// Reads change_log (migration_14_change_log.sql) — the before/after
// snapshot table that php/users.php, php/careers_manage.php, and
// php/career_categories.php write to on every risky mutation. This is
// where an administrator reviews what changed and, for entries flagged
// revertible, restores the old values with one click (see
// php/change_log_helper.php's revert_change() for the actual logic and
// its safety guards).
//
// Administrator-only: unlike audit_log.php (which is just a read-only
// event list open to both roles), reverting a change is a real write —
// including account status/role changes users.php itself restricts to
// administrators — so this page matches that same restriction.
//
// NOT everything in the app writes here. Approving/rejecting a pending
// career (php/careers.php) touches two tables and, if reverted carelessly,
// could cascade-delete a student's saved recommendation history — so
// that's deliberately left out of the auto-revert system for now (still
// visible via the career's own audit trail / status, just not "undo-able"
// with one click). See change_log_helper.php's CHANGE_LOG_PK_COLUMNS for
// exactly which tables are covered.

require __DIR__ . '/auth.php';
require_once __DIR__ . '/change_log_helper.php';
$currentUser = require_role(['administrator']);

$pdo = get_db();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revert') {
    $logId = (int) ($_POST['log_id'] ?? 0);
    $result = revert_change($pdo, $logId, (int) $currentUser['user_id']);
    $message = ['type' => $result['ok'] ? 'success' : 'error', 'text' => $result['message']];
}

$tableFilter = $_GET['table'] ?? '';
$validTables = array_keys(CHANGE_LOG_PK_COLUMNS);
if (!in_array($tableFilter, $validTables, true)) {
    $tableFilter = '';
}
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['reverted', 'active', ''], true)) {
    $statusFilter = '';
}

$where = [];
$params = [];
if ($tableFilter) {
    $where[] = 'cl.table_name = :table_name';
    $params['table_name'] = $tableFilter;
}
if ($statusFilter === 'reverted') {
    $where[] = 'cl.reverted_at IS NOT NULL';
} elseif ($statusFilter === 'active') {
    $where[] = 'cl.reverted_at IS NULL';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare(
    "SELECT cl.*, u.name AS changed_by_name, ru.name AS reverted_by_name
     FROM change_log cl
     LEFT JOIN users u ON u.user_id = cl.changed_by
     LEFT JOIN users ru ON ru.user_id = cl.reverted_by
     $whereSql
     ORDER BY cl.changed_at DESC
     LIMIT 200"
);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$totalCount = (int) $pdo->query("SELECT COUNT(*) FROM change_log")->fetchColumn();

$tableLabels = [
    'users' => 'Staff Accounts',
    'students' => 'Student Accounts',
    'careers' => 'Careers',
    'career_categories' => 'Categories',
    'skill_requirements' => 'Skill Requirements',
];

// Fields never worth showing raw in a diff — masked instead of decoded.
$maskedFields = ['password_hash'];

function render_change_value($val)
{
    if ($val === null) return '<em style="color:#aaa;">—</em>';
    if ($val === '') return '<em style="color:#aaa;">(empty)</em>';
    $str = (string) $val;
    if (strlen($str) > 160) {
        $str = substr($str, 0, 160) . '…';
    }
    return htmlspecialchars($str);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Change History</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; padding: 40px 20px; color: #222; background: #faf7f5; }
    .wrap { max-width: 1200px; margin: 0 auto; }
    h1 { color: #6e1423; margin-bottom: 4px; }
    .subtitle { color: #666; margin-top: 0; margin-bottom: 24px; font-size: 14px; }

    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }

    .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
    .filter-bar select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; }

    .entry { background: #fff; border: 1px solid #eee; border-radius: 8px; padding: 14px 18px; margin-bottom: 10px; box-shadow: 0 2px 8px rgba(74,12,23,0.05); }
    .entry summary { cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 10px; font-size: 14px; flex-wrap: wrap; }
    .entry summary::-webkit-details-marker { display: none; }
    .entry-main { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .entry-label { font-weight: bold; color: #6e1423; }
    .entry-meta { color: #888; font-size: 12.5px; }
    .action-tag { font-size: 11px; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; }
    .action-insert { background: #d1e7dd; color: #0f5132; }
    .action-update { background: #fff3cd; color: #856404; }
    .action-delete { background: #fdecea; color: #611a15; }
    .table-tag { font-size: 11px; padding: 2px 8px; border-radius: 10px; background: #f0dde1; color: #6e1423; }
    .reverted-tag { font-size: 11px; padding: 2px 8px; border-radius: 10px; background: #eee; color: #666; }

    .diff-table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 13px; }
    .diff-table th, .diff-table td { text-align: left; padding: 6px 10px; border-bottom: 1px solid #f2f2f2; }
    .diff-table th { color: #888; font-size: 11px; text-transform: uppercase; }
    .diff-old { color: #b02a37; }
    .diff-new { color: #0f5132; }
    .diff-same { color: #666; }

    .revert-form { margin-top: 12px; }
    button.btn { padding: 7px 16px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; }
    .btn-revert { background: #6e1423; color: #fff; }
    .empty { color: #888; font-style: italic; font-size: 14px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <div class="wrap">
        <?php require __DIR__ . '/nav.php'; ?>

        <h1>Change History</h1>
        <p class="subtitle">Every account, career, category, and skill-requirement change made in the system (<?= $totalCount ?> total) — review what changed and revert it if it was a mistake. Showing most recent 200 matching the filters below.</p>

        <?php if ($message): ?>
            <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
        <?php endif; ?>

        <form method="GET" class="filter-bar">
            <select name="table" onchange="this.form.submit()">
                <option value="">All record types</option>
                <?php foreach ($tableLabels as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $tableFilter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" onchange="this.form.submit()">
                <option value="">All changes</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Not yet reverted</option>
                <option value="reverted" <?= $statusFilter === 'reverted' ? 'selected' : '' ?>>Already reverted</option>
            </select>
        </form>

        <?php if (!$entries): ?>
            <p class="empty">No matching changes.</p>
        <?php endif; ?>

        <?php foreach ($entries as $e): ?>
            <?php
                $oldValues = $e['old_values'] ? json_decode($e['old_values'], true) : null;
                $newValues = $e['new_values'] ? json_decode($e['new_values'], true) : null;
                $allFields = array_unique(array_merge(array_keys($oldValues ?? []), array_keys($newValues ?? [])));
            ?>
            <details class="entry">
                <summary>
                    <span class="entry-main">
                        <span class="table-tag"><?= htmlspecialchars($tableLabels[$e['table_name']] ?? $e['table_name']) ?></span>
                        <span class="action-tag action-<?= $e['action'] ?>"><?= htmlspecialchars($e['action']) ?></span>
                        <span class="entry-label"><?= htmlspecialchars($e['record_label'] ?? ('#' . $e['record_id'])) ?></span>
                        <?php if ($e['reverted_at']): ?>
                            <span class="reverted-tag">↩ reverted by <?= htmlspecialchars($e['reverted_by_name'] ?? '—') ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="entry-meta">
                        <?= htmlspecialchars($e['changed_by_name'] ?? 'system') ?> ·
                        <?= date('M j, Y g:i A', strtotime($e['changed_at'])) ?>
                    </span>
                </summary>

                <?php if ($allFields): ?>
                    <table class="diff-table">
                        <tr><th>Field</th><th>Before</th><th>After</th></tr>
                        <?php foreach ($allFields as $field): ?>
                            <?php
                                $isMasked = in_array($field, $maskedFields, true);
                                $oldVal = $oldValues[$field] ?? null;
                                $newVal = $newValues[$field] ?? null;
                                $changed = $oldVal !== $newVal;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($field) ?></td>
                                <td class="<?= $changed ? 'diff-old' : 'diff-same' ?>"><?= $isMasked ? '<em style="color:#aaa;">(hidden)</em>' : render_change_value($oldVal) ?></td>
                                <td class="<?= $changed ? 'diff-new' : 'diff-same' ?>"><?= $isMasked ? '<em style="color:#aaa;">(hidden)</em>' : render_change_value($newVal) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p class="empty" style="margin-top:10px;">No field-level detail recorded for this entry.</p>
                <?php endif; ?>

                <?php if (!$e['reverted_at']): ?>
                    <form method="POST" class="revert-form" onsubmit="return confirm('Revert this change? The values shown in \'Before\' above will be restored.');">
                        <input type="hidden" name="action" value="revert">
                        <input type="hidden" name="log_id" value="<?= (int) $e['log_id'] ?>">
                        <button type="submit" class="btn btn-revert">↩ Revert this change</button>
                    </form>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    </div>
</body>
</html>
