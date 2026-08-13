<?php
// CareerPath AI - Consultation Request & Appointment Scheduling (student side)
// --------------------------------------------------------------------
// Not a named entity/use case in the capstone paper's ERD or Use Case
// Diagram (see README for the alignment note) — built anyway since it's on
// the group's Gantt chart under Software Development. A student submits a
// request with an optional reason + preferred date/time; a counselor or
// administrator picks it up on consultations.php and schedules it.

require __DIR__ . '/student_auth.php';
require_once __DIR__ . '/notifications_helper.php';
$currentStudent = require_student_login();

$pdo = get_db();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    $reason = trim($_POST['reason'] ?? '');
    $preferredDate = trim($_POST['preferred_date'] ?? '') ?: null;
    $preferredTime = trim($_POST['preferred_time'] ?? '') ?: null;

    $insert = $pdo->prepare(
        "INSERT INTO consultations (student_id, reason, preferred_date, preferred_time)
         VALUES (:student_id, :reason, :preferred_date, :preferred_time)"
    );
    $insert->execute([
        'student_id' => $currentStudent['student_id'],
        'reason' => $reason !== '' ? $reason : null,
        'preferred_date' => $preferredDate,
        'preferred_time' => $preferredTime,
    ]);

    notify_staff($pdo, null, htmlspecialchars($currentStudent['name']) . ' requested a consultation.', 'consultations.php');

    $message = ['type' => 'success', 'text' => 'Your consultation request has been sent. A counselor will reach out to confirm a schedule.'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $id = (int) ($_POST['consultation_id'] ?? 0);
    $update = $pdo->prepare(
        "UPDATE consultations SET status = 'cancelled' WHERE consultation_id = :id AND student_id = :sid AND status IN ('pending','scheduled')"
    );
    $update->execute(['id' => $id, 'sid' => $currentStudent['student_id']]);
    $message = ['type' => 'success', 'text' => 'Request cancelled.'];
}

$stmt = $pdo->prepare(
    "SELECT c.*, u.name AS counselor_name
     FROM consultations c
     LEFT JOIN users u ON u.user_id = c.counselor_id
     WHERE c.student_id = :sid
     ORDER BY c.requested_at DESC"
);
$stmt->execute(['sid' => $currentStudent['student_id']]);
$myConsultations = $stmt->fetchAll();

$statusLabels = ['pending' => 'Pending', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Consultations</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .panel, body > .flash-success, body > .flash-error { max-width: 720px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .panel { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; margin-bottom: 20px; }
    .panel h2 { margin: 0 0 14px; color: #6e1423; font-size: 16px; }
    label { display: block; font-size: 13px; font-weight: bold; margin: 10px 0 4px; }
    textarea, input[type=date], input[type=time] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    textarea { min-height: 70px; }
    button { margin-top: 14px; padding: 9px 20px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; background: #6e1423; color: #fff; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .empty { color: #666; font-style: italic; }
    .item { border-top: 1px solid #eee; padding: 12px 0; }
    .item:first-of-type { border-top: none; }
    .item .top { display: flex; justify-content: space-between; align-items: baseline; }
    .status-badge { font-size: 11px; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-scheduled { background: #d1e7dd; color: #0f5132; }
    .status-completed { background: #f0dde1; color: #6e1423; }
    .status-cancelled { background: #e2e3e5; color: #41464b; }
    .item .meta { font-size: 13px; color: #666; margin-top: 4px; }
    .cancel-btn { background: none; border: none; color: #b02a37; font-size: 12px; text-decoration: underline; cursor: pointer; padding: 0; margin-top: 6px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1>Consultations</h1>

    <?php if ($message): ?>
        <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Request a Consultation</h2>
        <form method="POST">
            <input type="hidden" name="action" value="request">
            <label>What would you like to talk about? (optional)</label>
            <textarea name="reason" placeholder="e.g. I'd like to discuss my top career recommendation."></textarea>
            <label>Preferred date (optional)</label>
            <input type="date" name="preferred_date">
            <label>Preferred time (optional)</label>
            <input type="time" name="preferred_time">
            <button type="submit">Send request</button>
        </form>
    </div>

    <div class="panel">
        <h2>My Requests</h2>
        <?php if (!$myConsultations): ?>
            <p class="empty">You haven't requested a consultation yet.</p>
        <?php else: ?>
            <?php foreach ($myConsultations as $c): ?>
                <div class="item">
                    <div class="top">
                        <span class="status-badge status-<?= $c['status'] ?>"><?= $statusLabels[$c['status']] ?></span>
                        <span class="meta">Requested <?= date('M j, Y', strtotime($c['requested_at'])) ?></span>
                    </div>
                    <?php if ($c['reason']): ?><p><?= nl2br(htmlspecialchars($c['reason'])) ?></p><?php endif; ?>
                    <?php if ($c['status'] === 'scheduled'): ?>
                        <div class="meta">
                            Scheduled for <?= $c['scheduled_date'] ? date('M j, Y', strtotime($c['scheduled_date'])) : '—' ?>
                            <?= $c['scheduled_time'] ? date('g:i A', strtotime($c['scheduled_time'])) : '' ?>
                            with <?= htmlspecialchars($c['counselor_name'] ?? 'a counselor') ?>
                        </div>
                    <?php elseif ($c['preferred_date']): ?>
                        <div class="meta">Preferred: <?= date('M j, Y', strtotime($c['preferred_date'])) ?> <?= $c['preferred_time'] ? date('g:i A', strtotime($c['preferred_time'])) : '' ?></div>
                    <?php endif; ?>
                    <?php if ($c['counselor_notes']): ?>
                        <div class="meta"><strong>Counselor note:</strong> <?= nl2br(htmlspecialchars($c['counselor_notes'])) ?></div>
                    <?php endif; ?>
                    <?php if (in_array($c['status'], ['pending', 'scheduled'], true)): ?>
                        <form method="POST" onsubmit="return confirm('Cancel this request?');">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="consultation_id" value="<?= (int) $c['consultation_id'] ?>">
                            <button class="cancel-btn" type="submit">Cancel</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
