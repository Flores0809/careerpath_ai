<?php
// CareerPath AI - Consultation Request & Appointment Scheduling (staff side)
// Counselor/administrator view: pick up a pending request, schedule a date
// and time, add a note, and mark it completed or cancelled later.

require __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications_helper.php';
$currentUser = require_role(['administrator', 'counselor']);

$pdo = get_db();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['consultation_id'] ?? 0);

    if ($action === 'schedule' && $id) {
        $scheduledDate = trim($_POST['scheduled_date'] ?? '') ?: null;
        $scheduledTime = trim($_POST['scheduled_time'] ?? '') ?: null;
        $notes = trim($_POST['counselor_notes'] ?? '');

        $update = $pdo->prepare(
            "UPDATE consultations
             SET counselor_id = :counselor_id, status = 'scheduled',
                 scheduled_date = :scheduled_date, scheduled_time = :scheduled_time, counselor_notes = :notes
             WHERE consultation_id = :id"
        );
        $update->execute([
            'counselor_id' => $currentUser['user_id'],
            'scheduled_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
            'notes' => $notes !== '' ? $notes : null,
            'id' => $id,
        ]);

        $studentStmt = $pdo->prepare("SELECT student_id FROM consultations WHERE consultation_id = :id");
        $studentStmt->execute(['id' => $id]);
        $studentId = $studentStmt->fetchColumn();
        if ($studentId) {
            $when = $scheduledDate ? date('M j, Y', strtotime($scheduledDate)) : 'a date to be confirmed';
            notify_student($pdo, (int) $studentId, "Your consultation has been scheduled for $when.", 'request_consultation.php', 'consultation');
        }
        $message = ['type' => 'success', 'text' => 'Consultation scheduled.'];
    } elseif ($action === 'complete' && $id) {
        $update = $pdo->prepare("UPDATE consultations SET status = 'completed' WHERE consultation_id = :id");
        $update->execute(['id' => $id]);
        $message = ['type' => 'success', 'text' => 'Marked as completed.'];
    } elseif ($action === 'cancel' && $id) {
        $update = $pdo->prepare("UPDATE consultations SET status = 'cancelled' WHERE consultation_id = :id");
        $update->execute(['id' => $id]);

        $studentStmt = $pdo->prepare("SELECT student_id FROM consultations WHERE consultation_id = :id");
        $studentStmt->execute(['id' => $id]);
        $studentId = $studentStmt->fetchColumn();
        if ($studentId) {
            notify_student($pdo, (int) $studentId, 'Your consultation request was cancelled by staff.', 'request_consultation.php', 'consultation');
        }
        $message = ['type' => 'success', 'text' => 'Request cancelled.'];
    }
}

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending', 'scheduled', 'completed', 'cancelled', 'all'], true)) {
    $statusFilter = 'pending';
}

$sortFilter = $_GET['sort'] ?? 'default';
if (!in_array($sortFilter, ['default', 'requested_asc', 'preferred'], true)) {
    $sortFilter = 'default';
}
$sortLabels = [
    'default' => 'Default - newest request first',
    'requested_asc' => 'Request date - oldest first',
    'preferred' => "Student's preferred date/time",
];
$orderBySql = [
    'default' => 'c.requested_at DESC',
    // Oldest-first — so a student who requested first isn't buried under
    // everyone who requested after them; "default" alone made that easy to miss.
    'requested_asc' => 'c.requested_at ASC',
    // Chronological by the slot the student actually asked for, so a
    // counselor building their day can work straight down the list.
    'preferred' => '(c.preferred_date IS NULL), c.preferred_date ASC, (c.preferred_time IS NULL), c.preferred_time ASC',
][$sortFilter];

$sql = "SELECT c.*, s.name AS student_name, s.email AS student_email, u.name AS counselor_name
        FROM consultations c
        JOIN students s ON s.student_id = c.student_id
        LEFT JOIN users u ON u.user_id = c.counselor_id";
if ($statusFilter !== 'all') {
    $sql .= " WHERE c.status = :status";
}
$sql .= " ORDER BY $orderBySql";
$stmt = $pdo->prepare($sql);
if ($statusFilter !== 'all') {
    $stmt->execute(['status' => $statusFilter]);
} else {
    $stmt->execute();
}
$consultations = $stmt->fetchAll();

$counts = $pdo->query("SELECT status, COUNT(*) AS n FROM consultations GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$statusLabels = ['pending' => 'Pending', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — Consultations</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    h1, .flash-success, .card { max-width: 1100px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .card { background: #f5f5f5; border: 1px solid #ddd; border-radius: 8px; padding: 16px 22px; margin-bottom: 16px; }

    /* Status tabs — same pattern as careers.php / users.php */
    .tabs { max-width: 1100px; margin: 24px auto 18px; display: flex; gap: 4px; border-bottom: 2px solid #eee; flex-wrap: wrap; }
    .tab-btn { background: none; border: none; padding: 10px 18px; font-size: 15.5px; font-weight: bold; color: #888; cursor: pointer; text-decoration: none; display: inline-block; border-bottom: 3px solid transparent; margin-bottom: -2px; font-family: inherit; }
    /* On mobile, 5 tabs (Pending/Scheduled/Completed/Cancelled/All) don't fit
       one row, so flex-wrap used to break them into a ragged 2-then-2-then-1
       grid with no consistent column alignment between rows -- reported as
       looking "goloh" (messed up). A single horizontally-scrollable row
       (same idea as the .cp-table-scroll wrapper used for data tables)
       keeps every tab the same height in one clean line instead. */
    @media (max-width: 700px) {
        .tabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .tab-btn { flex: 0 0 auto; white-space: nowrap; }
    }
    .tab-btn:hover { color: #6e1423; }
    .tab-btn.active { color: #6e1423; border-bottom-color: #6e1423; }
    .tab-count { display: inline-block; background: #eee; color: #555; border-radius: 10px; padding: 1px 8px; font-size: 12.5px; margin-left: 5px; }
    .tab-btn.active .tab-count { background: #f0dde1; color: #6e1423; }
    .card .top { display: flex; justify-content: space-between; align-items: baseline; }
    .card h3 { margin: 0; color: #6e1423; font-size: 17.5px; }
    .status-badge { font-size: 12.5px; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-scheduled { background: #d1e7dd; color: #0f5132; }
    .status-completed { background: #f0dde1; color: #6e1423; }
    .status-cancelled { background: #e2e3e5; color: #41464b; }
    .meta { font-size: 14.5px; color: #666; margin: 6px 0; }
    label { display: block; font-size: 13.5px; font-weight: bold; margin: 8px 0 3px; }
    input[type=date], input[type=time], textarea { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; }
    textarea { width: 100%; min-height: 50px; box-sizing: border-box; }
    .schedule-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    button { padding: 7px 16px; border: none; border-radius: 6px; font-size: 14.5px; cursor: pointer; margin-right: 8px; margin-top: 10px; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    /* Shared button naming used across Manage Accounts, Career Review, etc. */
    .btn-primary { background: #6e1423; color: #fff; }
    .btn-success { background: #0f5132; color: #fff; }
    .btn-danger { background: #b02a37; color: #fff; }
    .empty { color: #666; font-style: italic; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }

    .toolbar { max-width: 1100px; margin: 0 auto 18px; display: flex; gap: 14px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
    .sort-control { font-size: 14.5px; color: #555; display: flex; align-items: center; gap: 6px; }
    .sort-control select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; font-size: 14.5px; }

    /* Search bar — same pill + icon style used elsewhere in the app */
    .search-bar { position: relative; max-width: 300px; flex: 1 1 240px; }
    .search-bar input { width: 100%; padding: 9px 14px 9px 32px; border: 1px solid #ccc; border-radius: 20px; font-size: 15.5px; box-sizing: border-box; }
    .search-bar input:focus { outline: none; border-color: #6e1423; box-shadow: 0 0 0 2px rgba(110,20,35,0.12); }
    .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14.5px; opacity: 0.55; pointer-events: none; }
    .no-results { max-width: 1100px; margin: 20px auto; text-align: center; color: #888; font-style: italic; display: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>Consultations</h1>

    <?php if ($message): ?>
        <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="tabs">
        <?php foreach (['pending' => 'Pending', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'] as $key => $label): ?>
            <a class="tab-btn <?= $statusFilter === $key ? 'active' : '' ?>" href="?status=<?= $key ?>&sort=<?= urlencode($sortFilter) ?>"><?= $label ?> <span class="tab-count"><?= $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </div>

    <div class="toolbar">
        <form class="sort-control" method="GET">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <label for="sort-select" style="margin:0;font-weight:bold;">Sort by:</label>
            <select name="sort" id="sort-select" onchange="this.form.submit()">
                <?php foreach ($sortLabels as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $sortFilter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="search-bar">
            <span class="search-icon">🔍</span>
            <input type="text" id="consultation-search" placeholder="Search by student name or email...">
        </div>
    </div>

    <?php if (!$consultations): ?>
        <p class="empty">No requests<?= $statusFilter !== 'all' ? ' with status "' . $statusFilter . '"' : '' ?>.</p>
    <?php endif; ?>

    <div id="no-search-results" class="no-results">No requests match your search.</div>

    <?php foreach ($consultations as $c): ?>
        <div class="card" data-search="<?= htmlspecialchars(strtolower($c['student_name'] . ' ' . $c['student_email'])) ?>">
            <div class="top">
                <h3><?= htmlspecialchars($c['student_name']) ?></h3>
                <span class="status-badge status-<?= $c['status'] ?>"><?= $statusLabels[$c['status']] ?></span>
            </div>
            <div class="meta"><?= htmlspecialchars($c['student_email']) ?> · Requested <?= date('M j, Y g:i A', strtotime($c['requested_at'])) ?></div>
            <?php if ($c['reason']): ?><p><?= nl2br(htmlspecialchars($c['reason'])) ?></p><?php endif; ?>
            <?php if ($c['preferred_date']): ?>
                <div class="meta">Student's preferred time: <?= date('M j, Y', strtotime($c['preferred_date'])) ?> <?= $c['preferred_time'] ? date('g:i A', strtotime($c['preferred_time'])) : '' ?></div>
            <?php endif; ?>
            <?php if ($c['status'] === 'scheduled' || $c['status'] === 'completed'): ?>
                <div class="meta">
                    Scheduled: <?= $c['scheduled_date'] ? date('M j, Y', strtotime($c['scheduled_date'])) : '—' ?>
                    <?= $c['scheduled_time'] ? date('g:i A', strtotime($c['scheduled_time'])) : '' ?>
                    with <?= htmlspecialchars($c['counselor_name'] ?? '—') ?>
                </div>
                <?php if ($c['counselor_notes']): ?><div class="meta"><strong>Note:</strong> <?= nl2br(htmlspecialchars($c['counselor_notes'])) ?></div><?php endif; ?>
            <?php endif; ?>

            <?php if ($c['status'] === 'pending'): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="schedule">
                    <input type="hidden" name="consultation_id" value="<?= (int) $c['consultation_id'] ?>">
                    <div class="schedule-row">
                        <div><label>Date</label><input type="date" name="scheduled_date" value="<?= htmlspecialchars($c['preferred_date'] ?? '') ?>"></div>
                        <div><label>Time</label><input type="time" name="scheduled_time" value="<?= htmlspecialchars($c['preferred_time'] ?? '') ?>"></div>
                    </div>
                    <label>Note (optional)</label>
                    <textarea name="counselor_notes" placeholder="e.g. Meet at the Guidance Office"></textarea>
                    <button class="btn-primary" type="submit">Schedule</button>
                </form>
            <?php elseif ($c['status'] === 'scheduled'): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="consultation_id" value="<?= (int) $c['consultation_id'] ?>">
                    <button class="btn-success" type="submit">Mark completed</button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this consultation?');">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="consultation_id" value="<?= (int) $c['consultation_id'] ?>">
                    <button class="btn-danger" type="submit">Cancel</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <script>
    (function () {
        var input = document.getElementById('consultation-search');
        var noResults = document.getElementById('no-search-results');
        var cards = document.querySelectorAll('[data-search]');
        if (!input) return;
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            var visible = 0;
            cards.forEach(function (card) {
                var match = card.dataset.search.indexOf(q) !== -1;
                card.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            noResults.style.display = (cards.length && visible === 0 && q !== '') ? 'block' : 'none';
        });
    })();
    </script>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
