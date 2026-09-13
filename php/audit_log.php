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

$staff = $pdo->query("SELECT user_id, name, role FROM users ORDER BY role, name")->fetchAll();
$adminStaff = array_values(array_filter($staff, fn($u) => $u['role'] === 'administrator'));
$counselorStaff = array_values(array_filter($staff, fn($u) => $u['role'] === 'counselor'));

$students = $pdo->query("SELECT student_id, name FROM students ORDER BY name LIMIT 500")->fetchAll();
$selectedStudentName = '';
if ($studentFilter) {
    foreach ($students as $s) {
        if ((int) $s['student_id'] === $studentFilter) {
            $selectedStudentName = $s['name'];
            break;
        }
    }
}
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
    .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; align-items: flex-start; }
    .filter-bar select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; }

    /* Student filter — live-search combobox instead of a giant <select> */
    .student-filter { position: relative; }
    .student-filter-input-wrap { position: relative; }
    .student-filter input[type=text] { padding: 6px 28px 6px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; font-size: 14px; width: 220px; }
    .student-filter input[type=text]:focus { outline: none; border-color: #6e1423; box-shadow: 0 0 0 2px rgba(110,20,35,0.12); }
    .student-filter .clear-btn { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #888; cursor: pointer; font-size: 15px; padding: 2px 4px; line-height: 1; }
    .student-filter .clear-btn:hover { color: #b02a37; }
    .student-suggestions { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fff; border: 1px solid #ddd; border-radius: 6px; box-shadow: 0 8px 20px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; z-index: 30; display: none; }
    .student-suggestions.open { display: block; }
    .student-suggestions div { padding: 7px 12px; font-size: 13px; cursor: pointer; }
    .student-suggestions div:hover, .student-suggestions div.active { background: #faf0f1; color: #6e1423; }
    .student-suggestions .no-match { color: #888; font-style: italic; cursor: default; }
    .student-suggestions .no-match:hover { background: none; color: #888; }
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

    <form method="GET" class="filter-bar" id="audit-filter-form">
        <select name="counselor_id" onchange="this.form.submit()">
            <option value="0">All staff</option>
            <?php if ($adminStaff): ?>
                <optgroup label="Administrators">
                    <?php foreach ($adminStaff as $c): ?>
                        <option value="<?= $c['user_id'] ?>" <?= $counselorFilter === (int) $c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
            <?php if ($counselorStaff): ?>
                <optgroup label="Counselors">
                    <?php foreach ($counselorStaff as $c): ?>
                        <option value="<?= $c['user_id'] ?>" <?= $counselorFilter === (int) $c['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
        </select>

        <select name="action" onchange="this.form.submit()">
            <option value="">All actions</option>
            <option value="viewed_profile" <?= $actionFilter === 'viewed_profile' ? 'selected' : '' ?>>Viewed profile</option>
            <option value="recorded_outcome" <?= $actionFilter === 'recorded_outcome' ? 'selected' : '' ?>>Recorded outcome</option>
        </select>

        <div class="student-filter">
            <div class="student-filter-input-wrap">
                <input type="text" id="student-search-input" placeholder="Search students..." autocomplete="off" value="<?= htmlspecialchars($selectedStudentName) ?>">
                <?php if ($studentFilter): ?>
                    <button type="button" class="clear-btn" id="student-clear-btn" title="Clear student filter">&times;</button>
                <?php endif; ?>
            </div>
            <input type="hidden" name="student_id" id="student-id-hidden" value="<?= $studentFilter ?: 0 ?>">
            <div class="student-suggestions" id="student-suggestions"></div>
        </div>
    </form>

    <script>
    (function () {
        var students = <?= json_encode(array_map(fn($s) => ['id' => (int) $s['student_id'], 'name' => $s['name']], $students)) ?>;
        var input = document.getElementById('student-search-input');
        var hiddenId = document.getElementById('student-id-hidden');
        var suggestions = document.getElementById('student-suggestions');
        var form = document.getElementById('audit-filter-form');
        var clearBtn = document.getElementById('student-clear-btn');

        function closeSuggestions() {
            suggestions.classList.remove('open');
            suggestions.innerHTML = '';
        }

        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            hiddenId.value = 0; // typing invalidates any previously selected student until one is chosen again
            if (q === '') { closeSuggestions(); return; }

            var matches = students.filter(function (s) { return s.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 20);
            suggestions.innerHTML = '';
            if (!matches.length) {
                var none = document.createElement('div');
                none.className = 'no-match';
                none.textContent = 'No students match "' + input.value.trim() + '"';
                suggestions.appendChild(none);
            } else {
                matches.forEach(function (s) {
                    var opt = document.createElement('div');
                    opt.textContent = s.name;
                    opt.addEventListener('click', function () {
                        input.value = s.name;
                        hiddenId.value = s.id;
                        closeSuggestions();
                        form.submit();
                    });
                    suggestions.appendChild(opt);
                });
            }
            suggestions.classList.add('open');
        });

        input.addEventListener('focus', function () {
            if (input.value.trim() !== '' && suggestions.innerHTML !== '') suggestions.classList.add('open');
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.student-filter')) closeSuggestions();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                input.value = '';
                hiddenId.value = 0;
                form.submit();
            });
        }
    })();
    </script>

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
