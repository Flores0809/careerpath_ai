<?php
// CareerPath AI - Student self-registration
// Students create their own account so their assessment history (past

require __DIR__ . '/student_auth.php';
require_once __DIR__ . '/password_rules.php';
require_once __DIR__ . '/name_match.php';

if (current_student()) {
    header('Location: student_dashboard.php');
    exit;
}

$error = null;

// Locked list of MEII grade/section names — see grade_levels.php.
require_once __DIR__ . '/grade_levels.php';

$pdo = get_db();
try {
    $requiredAccessCode = (string) ($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'student_access_code'")->fetchColumn() ?: '');
} catch (Exception $e) {
    $requiredAccessCode = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $studentNumber = trim($_POST['student_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gradeLevel = trim($_POST['grade_level'] ?? '');
    $ageRaw = trim($_POST['age'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $accessCode = trim($_POST['access_code'] ?? '');

    if ($name === '' || $email === '' || $password === '' || $studentNumber === '' || $gradeLevel === '' || $ageRaw === '') {
        $error = 'Name, LRN, email, grade level, age, and password are all required.';
    } elseif (!ctype_digit($studentNumber) || strlen($studentNumber) !== 12) {
        $error = 'LRN must be exactly 12 digits.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!ctype_digit($ageRaw) || (int) $ageRaw < 10 || (int) $ageRaw > 25) {
        $error = 'Enter a valid age (10-25).';
    } elseif (!in_array($gradeLevel, $gradeLevelOptions, true)) {
        $error = 'Select a valid grade level from the list.';
    } elseif (($pwErr = password_strength_error($password)) !== null) {
        $error = $pwErr;
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($requiredAccessCode !== '' && !hash_equals($requiredAccessCode, $accessCode)) {
        $error = 'That access code is incorrect. Ask your guidance counselor or class adviser for the current MEII student access code.';
    } else {
        $age = (int) $ageRaw;
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Class-list check: when staff have uploaded the school's LRN list,
        // an LRN on it is activated automatically; anything else waits as
        // 'pending' for staff approval. With no list uploaded (or the table
        // not yet migrated), everyone is active, as before.
        $accountStatus = 'active';
        try {
            $rosterCount = (int) $pdo->query("SELECT COUNT(*) FROM lrn_roster")->fetchColumn();
            if ($rosterCount > 0) {
                $rosterStmt = $pdo->prepare("SELECT lrn, name FROM lrn_roster WHERE lrn = :lrn");
                $rosterStmt->execute(['lrn' => $studentNumber]);
                $rosterRow = $rosterStmt->fetch();
                if (!$rosterRow) {
                    $accountStatus = 'pending'; // LRN not on the class list
                } elseif (($rosterRow['name'] ?? '') !== '' && !names_match($name, $rosterRow['name'])) {
                    $accountStatus = 'pending'; // LRN is listed but the name differs
                }
            }
        } catch (PDOException $e) {
            $accountStatus = 'active';
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO students (name, student_number, email, password_hash, grade_level, age, status)
                 VALUES (:name, :student_number, :email, :hash, :grade_level, :age, :status)"
            );
            $stmt->execute([
                'status' => $accountStatus,
                'name' => $name,
                'student_number' => $studentNumber,
                'email' => $email,
                'hash' => $hash,
                'grade_level' => $gradeLevel,
                'age' => $age,
            ]);
            $newStudentId = (int) $pdo->lastInsertId();

            if ($accountStatus === 'pending') {
                header('Location: student_login.php?pending=1');
                exit;
            }

            login_student([
                'student_id' => $newStudentId,
                'name' => $name,
                'email' => $email,
                'grade_level' => $gradeLevel,
            ]);

            header('Location: student_dashboard.php?welcome=1');
            exit;
        } catch (PDOException $e) {
            error_log('student_register.php: account creation failed — ' . $e->getMessage());
            $sqlErrorCode = (int) ($e->errorInfo[1] ?? 0);
            if ($sqlErrorCode === 1062 && str_contains($e->getMessage(), 'student_number')) {
                $error = 'That LRN is already registered to another account.';
            } elseif ($sqlErrorCode === 1062 && str_contains($e->getMessage(), 'email')) {
                $error = 'That email is already registered to another account.';
            } else {
                $error = 'Could not create the account due to a system error. Please try again, or let your guidance counselor know if this keeps happening.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — Student Sign Up</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 440px; margin: 60px auto; padding: 0 20px; color: #222; }
    h1 { color: #6e1423; font-size: 24px; }
    .intro { color: #555; font-size: 15.5px; margin-bottom: 24px; }
    label { display: block; font-size: 14.5px; font-weight: bold; margin: 14px 0 4px; }
    input[type=text], input[type=email], input[type=password] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    button { margin-top: 20px; width: 100%; padding: 10px; background: #6e1423; color: #fff; border: none; border-radius: 6px; font-size: 16.5px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 15.5px; }
    .switch { margin-top: 18px; font-size: 14.5px; text-align: center; }
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
            <p style="font-size:13.5px;color:#888;margin-top:2px;">Ask your guidance counselor or class adviser for this if you don't have it.</p>
        <?php endif; ?>

        <label>Full name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>

        <label>LRN</label>
        <input type="text" name="student_number" value="<?= htmlspecialchars($_POST['student_number'] ?? '') ?>" placeholder="Your 12-digit Learner Reference Number" pattern="\d{12}" maxlength="12" inputmode="numeric" title="12 digits, numbers only" required>

        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>

        <label>Grade level</label>
        <select name="grade_level" required style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;">
            <option value="">— Select grade level —</option>
            <?php foreach ($gradeLevelOptions as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>" <?= ($_POST['grade_level'] ?? '') === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Age</label>
        <input type="number" name="age" min="10" max="25" value="<?= htmlspecialchars($_POST['age'] ?? '') ?>" required style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;">

        <label>Password (8+ chars, capital letter, number, special character)</label>
        <input type="password" name="password" required minlength="8" pattern="(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])(?!.*\s).{8,}" title="At least 8 characters with a capital letter, a number and a special character. No spaces.">

        <label>Confirm password</label>
        <input type="password" name="confirm" required minlength="8" pattern="(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])(?!.*\s).{8,}" title="At least 8 characters with a capital letter, a number and a special character. No spaces.">

        <button type="submit">Create account</button>
    </form>

    <p class="switch">Already have an account? <a href="student_login.php">Log in</a></p>
    <p class="switch"><a href="index.php">&larr; Back to home</a></p>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
