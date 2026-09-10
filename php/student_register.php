<?php
// CareerPath AI - Student self-registration
// Students create their own account so their assessment history (past
// RIASEC submissions + recommendations) can be saved and revisited.

require __DIR__ . '/student_auth.php';

if (current_student()) {
    header('Location: student_dashboard.php');
    exit;
}

$error = null;

// MEII doesn't issue students their own institutional email addresses (unlike
// staff), so this can't be gated by an email-domain check the way many school
// systems do. Instead, self-registration requires a shared access code that
// an administrator sets/rotates on settings.php and shares only with MEII
// students (e.g. announced in class, printed on ID handouts) — this stops a
// random visitor who finds the site from self-enrolling and spamming
// consultations, without requiring students to have a school email.
$pdo = get_db();
try {
    $requiredAccessCode = (string) ($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'student_access_code'")->fetchColumn() ?: '');
} catch (Exception $e) {
    $requiredAccessCode = ''; // system_settings table missing (pre-migration_10 install) — no code required
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gradeLevel = trim($_POST['grade_level'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $accessCode = trim($_POST['access_code'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Name, email, and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($requiredAccessCode !== '' && !hash_equals($requiredAccessCode, $accessCode)) {
        $error = 'That access code is incorrect. Ask your guidance counselor or class adviser for the current MEII student access code.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO students (name, email, password_hash, grade_level, status)
                 VALUES (:name, :email, :hash, :grade_level, 'active')"
            );
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'hash' => $hash,
                'grade_level' => $gradeLevel !== '' ? $gradeLevel : null,
            ]);
            $newStudentId = (int) $pdo->lastInsertId();

            login_student([
                'student_id' => $newStudentId,
                'name' => $name,
                'email' => $email,
                'grade_level' => $gradeLevel,
            ]);

            header('Location: student_dashboard.php?welcome=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Could not create the account (that email may already be in use).';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Student Sign Up</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 440px; margin: 60px auto; padding: 0 20px; color: #222; }
    h1 { color: #6e1423; font-size: 22px; }
    .intro { color: #555; font-size: 14px; margin-bottom: 24px; }
    label { display: block; font-size: 13px; font-weight: bold; margin: 14px 0 4px; }
    input[type=text], input[type=email], input[type=password] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    button { margin-top: 20px; width: 100%; padding: 10px; background: #6e1423; color: #fff; border: none; border-radius: 6px; font-size: 15px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
    .switch { margin-top: 18px; font-size: 13px; text-align: center; }
    .switch a { color: #6e1423; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <h1>Create your CareerPath AI account</h1>
    <p class="intro">Sign up to take the RIASEC assessment and keep a history of your results and recommendations.</p>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php if ($requiredAccessCode !== ''): ?>
            <label>MEII student access code</label>
            <input type="text" name="access_code" value="" required autocomplete="off">
            <p style="font-size:12px;color:#888;margin-top:2px;">Ask your guidance counselor or class adviser for this if you don't have it.</p>
        <?php endif; ?>

        <label>Full name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>

        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>

        <label>Grade level (optional)</label>
        <input type="text" name="grade_level" value="<?= htmlspecialchars($_POST['grade_level'] ?? '') ?>" placeholder="e.g. Grade 10, Grade 12 - STEM">

        <label>Password (min. 8 characters)</label>
        <input type="password" name="password" required minlength="8">

        <label>Confirm password</label>
        <input type="password" name="confirm" required minlength="8">

        <button type="submit">Create account</button>
    </form>

    <p class="switch">Already have an account? <a href="student_login.php">Log in</a></p>
    <p class="switch"><a href="index.php">&larr; Back to home</a></p>
</body>
</html>
