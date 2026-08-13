<?php
// CareerPath AI - Student notifications page (Notification Module)

require __DIR__ . '/student_auth.php';
require_once __DIR__ . '/notifications_helper.php';
$currentStudent = require_student_login();

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    $id = (int) ($_POST['notification_id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = :id AND audience = 'student' AND student_id = :sid");
        $stmt->execute(['id' => $id, 'sid' => $currentStudent['student_id']]);
    }
    header('Location: student_notifications.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE audience = 'student' AND student_id = :sid");
    $stmt->execute(['sid' => $currentStudent['student_id']]);
    header('Location: student_notifications.php');
    exit;
}

$notifications = student_notifications($pdo, (int) $currentStudent['student_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Notifications</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .top-row, body > .item, body > .empty { max-width: 700px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .empty { color: #666; font-style: italic; }
    .top-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .item { border: 1px solid #ddd; border-radius: 8px; padding: 14px 18px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; gap: 14px; }
    .item.unread { background: #faf0f1; border-color: #e9c9ce; }
    .item .msg a { color: #6e1423; text-decoration: none; }
    .item .msg a:hover { text-decoration: underline; }
    .item .meta { font-size: 12px; color: #888; margin-top: 4px; }
    .item form { margin: 0; }
    button.link-btn { background: none; border: none; color: #6e1423; cursor: pointer; font-size: 12px; text-decoration: underline; padding: 0; }
    .btn { display: inline-block; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: bold; background: #6e1423; color: #fff; border: none; cursor: pointer; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <div class="top-row">
        <h1>Notifications</h1>
        <?php if ($notifications): ?>
            <form method="POST"><input type="hidden" name="action" value="mark_all_read"><button class="btn" type="submit">Mark all as read</button></form>
        <?php endif; ?>
    </div>

    <?php if (!$notifications): ?>
        <p class="empty">Nothing here yet.</p>
    <?php else: ?>
        <?php foreach ($notifications as $n): ?>
            <div class="item <?= $n['is_read'] ? '' : 'unread' ?>">
                <div class="msg">
                    <?php if ($n['link']): ?>
                        <a href="<?= htmlspecialchars($n['link']) ?>"><?= htmlspecialchars($n['message']) ?></a>
                    <?php else: ?>
                        <?= htmlspecialchars($n['message']) ?>
                    <?php endif; ?>
                    <div class="meta"><?= htmlspecialchars($n['created_at']) ?></div>
                </div>
                <?php if (!$n['is_read']): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>">
                        <button class="link-btn" type="submit">Mark read</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
