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
            notify_student($pdo, (int) $studentId, "Your consultation has been scheduled for $when.", 'request_consultation.php');
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
            notify_student($pdo, (int) $studentId, 'Your consultation request was cancelled by staff.', 'request_consultation.php');
        }
        $message = ['type' => 'success', 'text' => 'Request cancelled.'];
    }
}

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending', 'scheduled', 'completed', 'cancelled', 'all'], true)) {
    $statusFilter = 'pending';
}

$sql = "SELECT c.*, s.name AS student_name, s.email AS student_email, u.name AS counselor_name
        FROM consultations c
        JOIN students s ON s.student_id = c.student_id
        LEFT JOIN users u ON u.user_id = c.counselor_id";
if ($statusFilter !== 'all') {
    $sql .= " WHERE c.status = :status";
}
$sql .= " ORDER BY c.requested_at DESC";
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
<title>CareerPath AI — Consultations</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    h1, .counts, .flash-success, .card { max-width: 900px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .counts { margin-bottom: 18px; font-size: 14px; color: #555; }
    .counts a { margin-right: 16px; color: #555; text-decoration: none; }
    .counts a.active, .counts a:hover { color: #6e1423; font-weight: bold; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px 22px; margin-bottom: 16px; }
    .card .top { display: flex; justify-content: space-between; align-items: baseline; }
    .card h3 { margin: 0; color: #6e1423; font-size: 16px; }
    .status-badge { font-size: 11px; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-scheduled { background: #d1e7dd; color: #0f5132; }
    .status-completed { background: #f0dde1; color: #6e1423; }
    .status-cancelled { background: #e2e3e5; color: #41464b; }
    .meta { font-size: 13px; color: #666; margin: 6px 0; }
    label { display: block; font-size: 12px; font-weight: bold; margin: 8px 0 3px; }
    input[type=date], input[type=time], textarea { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; }
    textarea { width: 100%; min-height: 50px; box-sizing: border-box; }
    .schedule-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    button { padding: 7px 16px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; margin-right: 8px; margin-top: 10px; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .schedule-btn { background: #6e1423; color: #fff; }
    .complete-btn { background: #0f5132; color: #fff; }
    .cancel-btn { background: #b02a37; color: #fff; }
    .empty { color: #666; font-style: italic; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>Consultations</h1>

    <?php if ($message): ?>
        <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="counts">
        <?php foreach (['pending' => 'Pending', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'] as $key => $label): ?>
            <a href="?status=<?= $key ?>" class="<?= $statusFilter === $key ? 'active' : '' ?>"><?= $label ?> (<?= $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0) ?>)</a>
        <?php endforeach; ?>
    </div>

    <?php if (!$consultations): ?>
        <p class="empty">No requests<?= $statusFilter !== 'all' ? ' with status "' . $statusFilter . '"' : '' ?>.</p>
    <?php endif; ?>

    <?php foreach ($consultations as $c): ?>
        <div class="card">
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
                    <button class="schedule-btn" type="submit">Schedule</button>
                </form>
            <?php elseif ($c['status'] === 'scheduled'): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="consultation_id" value="<?= (int) $c['consultation_id'] ?>">
                    <button class="complete-btn" type="submit">Mark completed</button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this consultation?');">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="consultation_id" value="<?= (int) $c['consultation_id'] ?>">
                    <button class="cancel-btn" type="submit">Cancel</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>
