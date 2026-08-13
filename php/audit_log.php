<?php
// CareerPath AI - Administrator Module: full Audit Log viewer
// --------------------------------------------------------------------
// counselor_log itself IS a named ERD entity (COUNSELOR_LOG, Chapter III),
// so this page is on firmer paper-alignment ground than settings.php/
// backup.php — it's just a cross-student, cross-counselor view of the same
// table students_lookup.php already writes to and shows per-student.
// Open to both roles (counselors can already see their own actions on
// students_lookup.php; this just adds the "audit" cross-section view).

require __DIR__ . '/auth.php';
$currentUser = require_role(['administrator', 'counselor']);

$pdo = get_db();

$studentFilter = (int) ($_GET['student_id'] ?? 0);
$counselorFilter = (int) ($_GET['counselor_id'] ?? 0);
$actionFilter = $_GET['action'] ?? '';
if (!in_array($actionFilter, ['viewed_profile', 'recorded_outcome', ''], true)) {
    $actionFilter = '';
}

$where = [];
$params = [];
if ($studentFilter) {
    $where[] = 'cl.student_id = :student_id';
    $params['student_id'] = $studentFilter;
}
if ($counselorFilter) {
    $where[] = 'cl.counselor_id = :counselor_id';
    $params['counselor_id'] = $counselorFilter;
}
if ($actionFilter) {
    $where[] = 'cl.action = :action';
    $params['action'] = $actionFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare(
    "SELECT cl.*, u.name AS counselor_name, s.name AS student_name, c.career_title
     FROM counselor_log cl
     JOIN users u ON u.user_id = cl.counselor_id
     JOIN students s ON s.student_id = cl.student_id
     LEFT JOIN recommendations r ON r.recommendation_id = cl.recommendation_id
     LEFT JOIN careers c ON c.career_id = r.career_id
     $whereSql
     ORDER BY cl.created_at DESC
     LIMIT 200"
);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$counselors = $pdo->query("SELECT user_id, name FROM users WHERE role IN ('administrator','counselor') ORDER BY name")->fetchAll();
$students = $pdo->query("SELECT student_id, name FROM students ORDER BY name LIMIT 500")->fetchAll();
$totalCount = (int) $pdo->query("SELECT COUNT(*) FROM counselor_log")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Audit Log</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    h1 { color: #6e1423; }
    .sub { color: #666; font-size: 14px; margin-bottom: 20px; }
    .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
    .filter-bar select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
    th { color: #6e1423; text-transform: uppercase; font-size: 11px; }
    .action-tag { font-size: 11px; padding: 2px 8px; border-radius: 10px; }
    .action-viewed_profile { background: #e2e3e5; color: #41464b; }
    .action-recorded_outcome { background: #d1e7dd; color: #0f5132; }
    .empty { color: #666; font-style: italic; }
    .notes { font-size: 12px; color: #555; max-width: 260px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>Audit Log</h1>
    <p class="sub">All counselor/administrator actions on student profiles (<?= $totalCount ?> total). Showing most recent 200 matching the filters below.</p>

    <form method="GET" class="filter-bar">
        <select name="counselor_id" onchange="this.form.submit()">
            <option value="0">All staff</option>
            <?php foreach ($counselors as $c): ?>
                <option value="<?= $c['user_id'] ?>" <?= $counselorFilter === (int) $c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="student_id" onchange="this.form.submit()">
            <option value="0">All students</option>
            <?php foreach ($students as $s): ?>
                <option value="<?= $s['student_id'] ?>" <?= $studentFilter === (int) $s['student_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="action" onchange="this.form.submit()">
            <option value="">All actions</option>
            <option value="viewed_profile" <?= $actionFilter === 'viewed_profile' ? 'selected' : '' ?>>Viewed profile</option>
            <option value="recorded_outcome" <?= $actionFilter === 'recorded_outcome' ? 'selected' : '' ?>>Recorded outcome</option>
        </select>
    </form>

    <?php if (!$entries): ?>
        <p class="empty">No matching log entries.</p>
    <?php else: ?>
        <table>
            <tr><th>Date</th><th>Staff</th><th>Student</th><th>Action</th><th>Career</th><th>Notes</th></tr>
            <?php foreach ($entries as $e): ?>
                <tr>
                    <td><?= date('M j, Y g:i A', strtotime($e['created_at'])) ?></td>
                    <td><?= htmlspecialchars($e['counselor_name']) ?></td>
                    <td><?= htmlspecialchars($e['student_name']) ?></td>
                    <td><span class="action-tag action-<?= $e['action'] ?>"><?= htmlspecialchars(str_replace('_', ' ', $e['action'])) ?></span></td>
                    <td><?= $e['career_title'] ? htmlspecialchars($e['career_title']) : '—' ?></td>
                    <td class="notes"><?= $e['notes'] ? nl2br(htmlspecialchars($e['notes'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>
</html>
