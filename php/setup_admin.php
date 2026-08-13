<?php
// CareerPath AI - One-time first-administrator bootstrap.
//
// Run this ONCE, in your browser, right after importing the database.
// It refuses to run again once any administrator account exists — so it
// can't be used to plant a rogue admin account later. After creating the
// first administrator, use users.php (logged in as that admin) to create
// counselor accounts and any additional administrators.

require __DIR__ . '/auth.php';

$pdo = get_db();
$existingAdminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'administrator'")->fetchColumn();

if ($existingAdminCount > 0) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"><title>Setup already complete</title></head>
    <body style="font-family:Arial,sans-serif;max-width:600px;margin:60px auto;color:#222;">
        <h1>Setup already complete</h1>
        <p>An administrator account already exists, so this page is locked.</p>
        <p>Need another administrator or a counselor account? Log in as an
        existing administrator and create one from the Manage Users page.</p>
        <p><a href="login.php">Go to login</a></p>
    </body>
    </html>
    <?php
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, role, status)
                 VALUES (:name, :email, :hash, 'administrator', 'active')"
            );
            $stmt->execute(['name' => $name, 'email' => $email, 'hash' => $hash]);
            $newUserId = (int) $pdo->lastInsertId();

            login_user([
                'user_id' => $newUserId,
                'name' => $name,
                'email' => $email,
                'role' => 'administrator',
            ]);

            header('Location: dashboard.php?welcome=1');
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
<title>CareerPath AI — Create First Administrator</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 480px; margin: 60px auto; padding: 0 20px; color: #222; }
    h1 { color: #6e1423; font-size: 22px; }
    .intro { color: #555; font-size: 14px; margin-bottom: 24px; }
    label { display: block; font-size: 13px; font-weight: bold; margin: 14px 0 4px; }
    input[type=text], input[type=email], input[type=password] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    button { margin-top: 20px; width: 100%; padding: 10px; background: #6e1423; color: #fff; border: none; border-radius: 6px; font-size: 15px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <h1>Create the first administrator account</h1>
    <p class="intro">This page only works once — it locks itself as soon as one administrator account exists.</p>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <label>Full name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>

        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>

        <label>Password (min. 8 characters)</label>
        <input type="password" name="password" required minlength="8">

        <label>Confirm password</label>
        <input type="password" name="confirm" required minlength="8">

        <button type="submit">Create administrator account</button>
    </form>
</body>
</html>
