<?php
// CareerPath AI - Administrator-only account management
//
// Administrators create and manage counselor (and other administrator)
// accounts here. Counselors cannot reach this page — require_role() below
// enforces that with a 403, not just a hidden link.

require __DIR__ . '/auth.php';
require_once __DIR__ . '/change_log_helper.php';

$currentUser = require_role(['administrator']);
$pdo = get_db();
$message = null;

function count_active_admins(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'administrator' AND status = 'active'"
    )->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';
        $role = $_POST['role'] ?? 'counselor';

        if (!in_array($role, ['administrator', 'counselor'], true)) {
            $role = 'counselor';
        }

        if ($name === '' || $email === '' || $password === '') {
            $message = ['type' => 'error', 'text' => 'Name, email, and password are all required.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = ['type' => 'error', 'text' => 'Enter a valid email address.'];
        } elseif (strlen($password) < 8) {
            $message = ['type' => 'error', 'text' => 'Password must be at least 8 characters.'];
        } elseif ($password !== $confirm) {
            $message = ['type' => 'error', 'text' => 'Passwords do not match.'];
        } else {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, email, password_hash, role, status, created_by)
                     VALUES (:name, :email, :hash, :role, 'active', :created_by)"
                );
                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                    'created_by' => $currentUser['user_id'],
                ]);
                $newUserId = (int) $pdo->lastInsertId();
                $newRowStmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
                $newRowStmt->execute(['id' => $newUserId]);
                log_change($pdo, 'users', $newUserId, $name, 'insert', null, $newRowStmt->fetch(), $currentUser['user_id']);
                $message = ['type' => 'success', 'text' => "Created $role account for \"$name\"."];
            } catch (PDOException $e) {
                $message = ['type' => 'error', 'text' => 'Could not create account — that email may already be in use.'];
            }
        }
    } elseif ($action === 'update' && !empty($_POST['user_id'])) {
        $targetId = (int) $_POST['user_id'];
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'counselor';

        if (!in_array($role, ['administrator', 'counselor'], true)) {
            $role = 'counselor';
        }

        if ($name === '' || $email === '') {
            $message = ['type' => 'error', 'text' => 'Name and email cannot be empty.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = ['type' => 'error', 'text' => 'Enter a valid email address.'];
        } else {
            // Guard: don't let the last active administrator demote themselves —
            // that would lock everyone out of user management.
            $stmt = $pdo->prepare("SELECT role, status FROM users WHERE user_id = :id");
            $stmt->execute(['id' => $targetId]);
            $target = $stmt->fetch();

            $isSelfDemotion = $targetId === $currentUser['user_id']
                && $target && $target['role'] === 'administrator'
                && $role !== 'administrator';

            if ($isSelfDemotion && count_active_admins($pdo) <= 1) {
                $message = ['type' => 'error', 'text' => "You're the only active administrator — can't remove your own admin role."];
            } else {
                $oldRowStmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
                $oldRowStmt->execute(['id' => $targetId]);
                $oldRow = $oldRowStmt->fetch();

                try {
                    $stmt = $pdo->prepare(
                        "UPDATE users SET name = :name, email = :email, role = :role WHERE user_id = :id"
                    );
                    $stmt->execute(['name' => $name, 'email' => $email, 'role' => $role, 'id' => $targetId]);

                    if ($oldRow) {
                        $newRowStmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
                        $newRowStmt->execute(['id' => $targetId]);
                        log_change($pdo, 'users', $targetId, $name, 'update', $oldRow, $newRowStmt->fetch(), $currentUser['user_id']);
                    }

                    $message = ['type' => 'success', 'text' => 'Account updated.'];
                } catch (PDOException $e) {
                    $message = ['type' => 'error', 'text' => 'Could not update account — that email may already be in use.'];
                }
            }
        }
    } elseif ($action === 'toggle_status' && !empty($_POST['user_id'])) {
        $targetId = (int) $_POST['user_id'];

        if ($targetId === $currentUser['user_id']) {
            $message = ['type' => 'error', 'text' => "You can't disable your own account while logged in as it."];
        } else {
            $stmt = $pdo->prepare("SELECT role, status FROM users WHERE user_id = :id");
            $stmt->execute(['id' => $targetId]);
            $target = $stmt->fetch();

            if (!$target) {
                $message = ['type' => 'error', 'text' => 'Account not found.'];
            } elseif ($target['role'] === 'administrator' && $target['status'] === 'active' && count_active_admins($pdo) <= 1) {
                $message = ['type' => 'error', 'text' => 'Cannot disable the last active administrator account.'];
            } else {
                $newStatus = $target['status'] === 'active' ? 'disabled' : 'active';
                $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE user_id = :id");
                $stmt->execute(['status' => $newStatus, 'id' => $targetId]);
                $nameStmt = $pdo->prepare("SELECT name FROM users WHERE user_id = :id");
                $nameStmt->execute(['id' => $targetId]);
                log_change($pdo, 'users', $targetId, $nameStmt->fetchColumn() ?: null, 'update', ['status' => $target['status']], ['status' => $newStatus], $currentUser['user_id']);
                $message = ['type' => 'success', 'text' => "Account $newStatus."];
            }
        }
    } elseif ($action === 'reset_password' && !empty($_POST['user_id'])) {
        $targetId = (int) $_POST['user_id'];
        $password = $_POST['new_password'] ?? '';
        $confirm = $_POST['new_password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $message = ['type' => 'error', 'text' => 'New password must be at least 8 characters.'];
        } elseif ($password !== $confirm) {
            $message = ['type' => 'error', 'text' => 'New passwords do not match.'];
        } else {
            $oldStmt = $pdo->prepare("SELECT name, password_hash FROM users WHERE user_id = :id");
            $oldStmt->execute(['id' => $targetId]);
            $target = $oldStmt->fetch();

            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id");
            $stmt->execute(['hash' => $newHash, 'id' => $targetId]);

            if ($target) {
                // Only the password_hash field is logged (not a full-row
                // snapshot) — reverting restores the old hash so the
                // previous password works again, without touching name/
                // email/role. Hashes aren't reversible to plaintext, so
                // this is safe to store, but change_history.php masks the
                // raw value in the UI since showing a hash string is just
                // noise, not something a reviewer needs to read.
                log_change($pdo, 'users', $targetId, $target['name'], 'update', ['password_hash' => $target['password_hash']], ['password_hash' => $newHash], $currentUser['user_id']);
            }
            $message = ['type' => 'success', 'text' => 'Password reset.'];
        }
    } elseif ($action === 'toggle_student_status' && !empty($_POST['student_id'])) {
        // Students self-register (see php/student_register.php) — administrators
        // don't create or edit their accounts, only moderate (disable/re-enable).
        $targetId = (int) $_POST['student_id'];
        $stmt = $pdo->prepare("SELECT name, status FROM students WHERE student_id = :id");
        $stmt->execute(['id' => $targetId]);
        $target = $stmt->fetch();

        if (!$target) {
            $message = ['type' => 'error', 'text' => 'Student account not found.'];
        } else {
            $newStatus = $target['status'] === 'active' ? 'disabled' : 'active';
            $stmt = $pdo->prepare("UPDATE students SET status = :status WHERE student_id = :id");
            $stmt->execute(['status' => $newStatus, 'id' => $targetId]);
            log_change($pdo, 'students', $targetId, $target['name'], 'update', ['status' => $target['status']], ['status' => $newStatus], $currentUser['user_id']);
            $message = ['type' => 'success', 'text' => "Student account $newStatus."];
        }
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY role, name")->fetchAll();
$administrators = array_values(array_filter($users, fn($u) => $u['role'] === 'administrator'));
$counselors = array_values(array_filter($users, fn($u) => $u['role'] === 'counselor'));

$students = $pdo->query(
    "SELECT s.*, COUNT(sp.profile_id) AS submission_count
     FROM students s
     LEFT JOIN student_profiles sp ON sp.student_id = s.student_id
     GROUP BY s.student_id
     ORDER BY s.name"
)->fetchAll();

$welcome = isset($_GET['welcome']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Manage Accounts</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    h1 { color: #6e1423; }
    h2 { color: #6e1423; font-size: 18px; margin-top: 34px; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 14px; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
    th { color: #555; font-size: 12px; text-transform: uppercase; }
    .role-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; text-transform: uppercase; }
    .role-administrator { background: #e7d9f7; color: #4b2e83; }
    .role-counselor { background: #f0dde1; color: #6e1423; }
    .status-active { color: #0f5132; }
    .status-disabled { color: #b02a37; }
    form.inline { display: inline; }
    input[type=text], input[type=email], input[type=password], select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; }
    .card { border: 1px solid #ddd; border-radius: 8px; padding: 18px 22px; margin-bottom: 22px; }
    label { display: block; font-size: 13px; font-weight: bold; margin: 10px 0 4px; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    button { padding: 6px 14px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .btn-primary { background: #6e1423; color: #fff; }
    .btn-danger { background: #b02a37; color: #fff; }
    .btn-secondary { background: #6c757d; color: #fff; }
    .actions-cell button { margin: 2px 2px 2px 0; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    details summary { cursor: pointer; color: #6e1423; font-size: 13px; }
    .empty { color: #666; font-style: italic; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>Manage Accounts</h1>

    <?php if ($welcome): ?>
        <div class="flash-success">Welcome! Your administrator account is set up. Create counselor accounts below.</div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="card" style="max-width:560px;margin-left:auto;margin-right:auto;">
        <h2 style="margin-top:0;">Create a new account</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="grid2">
                <div>
                    <label>Full name</label>
                    <input type="text" name="name" required style="width:100%;box-sizing:border-box;">
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email" required style="width:100%;box-sizing:border-box;">
                </div>
                <div>
                    <label>Password (min. 8 characters)</label>
                    <input type="password" name="password" required minlength="8" style="width:100%;box-sizing:border-box;">
                </div>
                <div>
                    <label>Confirm password</label>
                    <input type="password" name="confirm" required minlength="8" style="width:100%;box-sizing:border-box;">
                </div>
                <div>
                    <label>Role</label>
                    <select name="role" style="width:100%;box-sizing:border-box;">
                        <option value="counselor">Counselor — reviews & approves careers</option>
                        <option value="administrator">Administrator — manages accounts</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-primary" style="margin-top:16px;">Create account</button>
        </form>
    </div>

    <?php
        // Renders one staff row (administrator or counselor) — same edit/reset/toggle actions for both.
        function render_staff_row(array $u, array $currentUser): void
        {
    ?>
            <tr>
                <td><?= htmlspecialchars($u['name']) ?><?= $u['user_id'] == $currentUser['user_id'] ? ' <em>(you)</em>' : '' ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="role-tag role-<?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                <td class="status-<?= htmlspecialchars($u['status']) ?>"><?= htmlspecialchars($u['status']) ?></td>
                <td class="actions-cell">
                    <details>
                        <summary>Edit</summary>
                        <form method="POST" style="margin-top:8px;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                            <label>Name</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($u['name']) ?>" required>
                            <label>Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" required>
                            <label>Role</label>
                            <select name="role">
                                <option value="counselor" <?= $u['role'] === 'counselor' ? 'selected' : '' ?>>Counselor</option>
                                <option value="administrator" <?= $u['role'] === 'administrator' ? 'selected' : '' ?>>Administrator</option>
                            </select>
                            <button type="submit" class="btn-primary" style="margin-top:8px;">Save</button>
                        </form>
                    </details>
                    <details>
                        <summary>Reset password</summary>
                        <form method="POST" style="margin-top:8px;">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                            <label>New password</label>
                            <input type="password" name="new_password" minlength="8" required>
                            <label>Confirm</label>
                            <input type="password" name="new_password_confirm" minlength="8" required>
                            <button type="submit" class="btn-secondary" style="margin-top:8px;">Reset password</button>
                        </form>
                    </details>
                    <form method="POST" class="inline" onsubmit="return confirm('<?= $u['status'] === 'active' ? 'Disable' : 'Re-enable' ?> this account?');">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                        <button type="submit" class="<?= $u['status'] === 'active' ? 'btn-danger' : 'btn-secondary' ?>">
                            <?= $u['status'] === 'active' ? 'Disable' : 'Re-enable' ?>
                        </button>
                    </form>
                </td>
            </tr>
    <?php
        }
    ?>

    <h2>Administrators</h2>
    <table>
        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        <?php if (!$administrators): ?>
            <tr><td colspan="5" class="empty">No administrator accounts.</td></tr>
        <?php endif; ?>
        <?php foreach ($administrators as $u): render_staff_row($u, $currentUser); endforeach; ?>
    </table>

    <h2>Counselors</h2>
    <table>
        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        <?php if (!$counselors): ?>
            <tr><td colspan="5" class="empty">No counselor accounts yet — create one above.</td></tr>
        <?php endif; ?>
        <?php foreach ($counselors as $u): render_staff_row($u, $currentUser); endforeach; ?>
    </table>

    <h2>Students</h2>
    <p style="font-size:13px;color:#666;margin-top:-6px;">Students create their own accounts at <code>student_register.php</code> — administrators can only view accounts and disable/re-enable them here (e.g. for misuse), not edit their details or reset their passwords.</p>
    <table>
        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Grade level</th>
            <th>Assessments taken</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        <?php if (!$students): ?>
            <tr><td colspan="6" class="empty">No student accounts yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($students as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['name']) ?></td>
                <td><?= htmlspecialchars($s['email']) ?></td>
                <td><?= htmlspecialchars($s['grade_level'] ?? '—') ?></td>
                <td><?= (int) $s['submission_count'] ?></td>
                <td class="status-<?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></td>
                <td class="actions-cell">
                    <a href="students_lookup.php?view=<?= (int) $s['student_id'] ?>&from=users" class="btn-secondary" style="display:inline-block;text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;margin:2px 2px 2px 0;">View History</a>
                    <form method="POST" class="inline" onsubmit="return confirm('<?= $s['status'] === 'active' ? 'Disable' : 'Re-enable' ?> this student account?');">
                        <input type="hidden" name="action" value="toggle_student_status">
                        <input type="hidden" name="student_id" value="<?= (int) $s['student_id'] ?>">
                        <button type="submit" class="<?= $s['status'] === 'active' ? 'btn-danger' : 'btn-secondary' ?>">
                            <?= $s['status'] === 'active' ? 'Disable' : 'Re-enable' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
