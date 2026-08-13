<?php
// CareerPath AI - Student Profile Management module
// (paired with the Student Dashboard in the paper's own task wording:
// "Develop the Student Dashboard and Profile Management module")

require __DIR__ . '/student_auth.php';
$currentStudent = require_student_login();

$pdo = get_db();
$message = null;

$stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = :id");
$stmt->execute(['id' => $currentStudent['student_id']]);
$student = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $gradeLevel = trim($_POST['grade_level'] ?? '');

        if ($name === '' || $email === '') {
            $message = ['type' => 'error', 'text' => 'Name and email are required.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = ['type' => 'error', 'text' => 'Please enter a valid email address.'];
        } else {
            $dupStmt = $pdo->prepare("SELECT student_id FROM students WHERE email = :email AND student_id != :id");
            $dupStmt->execute(['email' => $email, 'id' => $currentStudent['student_id']]);
            if ($dupStmt->fetch()) {
                $message = ['type' => 'error', 'text' => 'That email is already used by another account.'];
            } else {
                $update = $pdo->prepare(
                    "UPDATE students SET name = :name, email = :email, grade_level = :grade_level WHERE student_id = :id"
                );
                $update->execute([
                    'name' => $name,
                    'email' => $email,
                    'grade_level' => $gradeLevel !== '' ? $gradeLevel : null,
                    'id' => $currentStudent['student_id'],
                ]);
                // Keep the session (and nav greeting) in sync immediately.
                $_SESSION['student_name'] = $name;
                $_SESSION['student_email'] = $email;
                $_SESSION['student_grade_level'] = $gradeLevel !== '' ? $gradeLevel : null;
                $message = ['type' => 'success', 'text' => 'Profile updated.'];

                $stmt->execute(['id' => $currentStudent['student_id']]);
                $student = $stmt->fetch();
            }
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $student['password_hash'])) {
            $message = ['type' => 'error', 'text' => 'Current password is incorrect.'];
        } elseif (strlen($new) < 8) {
            $message = ['type' => 'error', 'text' => 'New password must be at least 8 characters.'];
        } elseif ($new !== $confirm) {
            $message = ['type' => 'error', 'text' => 'New password and confirmation do not match.'];
        } else {
            $update = $pdo->prepare("UPDATE students SET password_hash = :hash WHERE student_id = :id");
            $update->execute([
                'hash' => password_hash($new, PASSWORD_DEFAULT),
                'id' => $currentStudent['student_id'],
            ]);
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
    .panel { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; margin-bottom: 20px; }
    .panel h2 { margin: 0 0 14px; color: #6e1423; font-size: 16px; }
    label { display: block; font-size: 13px; font-weight: bold; margin: 10px 0 4px; }
    input[type=text], input[type=email], input[type=password] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    button { margin-top: 14px; padding: 9px 20px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; background: #6e1423; color: #fff; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .member-since { font-size: 13px; color: #888; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1>My Profile</h1>

    <?php if ($message): ?>
        <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Account Details</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_profile">
            <label>Full name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($student['name']) ?>" required>
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($student['email']) ?>" required>
            <label>Grade level</label>
            <input type="text" name="grade_level" value="<?= htmlspecialchars($student['grade_level'] ?? '') ?>" placeholder="e.g. Grade 12">
            <p class="member-since">Member since <?= date('M j, Y', strtotime($student['created_at'])) ?></p>
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
</body>
</html>
