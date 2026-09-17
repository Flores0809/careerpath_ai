<?php
// CareerPath AI - Staff (Administrator/Counselor) self-service Profile page.
// Mirrors php/student_profile.php's pattern for the students table, but for
// the users table. Any logged-in staff member (either role) can view their
// own account and change their own name/email/password here — this does
// NOT touch role or status, which stay administrator-only via users.php.

require __DIR__ . '/auth.php';
require_once __DIR__ . '/change_log_helper.php';
$currentUser = require_login();

$pdo = get_db();
$message = null;

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
$stmt->execute(['id' => $currentUser['user_id']]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '' || $email === '') {
            $message = ['type' => 'error', 'text' => 'Name and email are required.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = ['type' => 'error', 'text' => 'Please enter a valid email address.'];
        } else {
            $dupStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :id");
            $dupStmt->execute(['email' => $email, 'id' => $currentUser['user_id']]);
            if ($dupStmt->fetch()) {
                $message = ['type' => 'error', 'text' => 'That email is already used by another account.'];
            } else {
                $oldValues = ['name' => $user['name'], 'email' => $user['email']];

                $update = $pdo->prepare("UPDATE users SET name = :name, email = :email WHERE user_id = :id");
                $update->execute(['name' => $name, 'email' => $email, 'id' => $currentUser['user_id']]);

                $newValues = ['name' => $name, 'email' => $email];
                // changed_by is the staff member's own user_id (valid here,
                // unlike students self-edits) — "(self-edit)" still added to
                // the label so Change History reads clearly at a glance.
                log_change($pdo, 'users', $currentUser['user_id'], $name . ' (self-edit)', 'update', $oldValues, $newValues, $currentUser['user_id']);

                // Keep the session (and nav greeting) in sync immediately.
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $message = ['type' => 'success', 'text' => 'Profile updated.'];

                $stmt->execute(['id' => $currentUser['user_id']]);
                $user = $stmt->fetch();
            }
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password_hash'])) {
            $message = ['type' => 'error', 'text' => 'Current password is incorrect.'];
        } elseif (strlen($new) < 8) {
            $message = ['type' => 'error', 'text' => 'New password must be at least 8 characters.'];
        } elseif ($new !== $confirm) {
            $message = ['type' => 'error', 'text' => 'New password and confirmation do not match.'];
        } else {
            $oldHash = $user['password_hash'];
            $newHash = password_hash($new, PASSWORD_DEFAULT);

            $update = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id");
            $update->execute(['hash' => $newHash, 'id' => $currentUser['user_id']]);

            log_change($pdo, 'users', $currentUser['user_id'], $user['name'] . ' (self-edit)', 'update', ['password_hash' => $oldHash], ['password_hash' => $newHash], $currentUser['user_id']);

            $message = ['type' => 'success', 'text' => 'Password changed.'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — My Profile</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .panel, body > .flash-success, body > .flash-error { max-width: 640px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .panel { background: #f5f5f5; border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; margin-bottom: 20px; }
    .panel h2 { margin: 0 0 14px; color: #6e1423; font-size: 17.5px; }
    label { display: block; font-size: 14.5px; font-weight: bold; margin: 10px 0 4px; }
    input[type=text], input[type=email], input[type=password] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    button { margin-top: 14px; padding: 9px 20px; border: none; border-radius: 6px; font-size: 15.5px; cursor: pointer; background: #6e1423; color: #fff; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .member-since { font-size: 14.5px; color: #888; }
    .role-note { font-size: 14.5px; color: #888; margin: 0 0 14px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>My Profile</h1>

    <?php if ($message): ?>
        <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Account Details</h2>
        <p class="role-note">Role: <strong><?= htmlspecialchars(ucfirst($user['role'])) ?></strong> — only an administrator can change your role or account status (Admin &rsaquo; Manage Accounts).</p>
        <form method="POST">
            <input type="hidden" name="action" value="update_profile">
            <label>Full name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
            <p class="member-since">Member since <?= date('M j, Y', strtotime($user['created_at'])) ?></p>
            <button type="submit">Save changes</button>
        </form>
    </div>

    <div class="panel">
        <h2>Change Password</h2>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <label>Current password</label>
            <input type="password" name="current_password" required>
            <label>New password</label>
            <input type="password" name="new_password" required minlength="8">
            <label>Confirm new password</label>
            <input type="password" name="confirm_password" required minlength="8">
            <button type="submit">Change password</button>
        </form>
    </div>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
