<?php
// CareerPath AI - Login page (administrators & counselors)

require __DIR__ . '/auth.php';

$next = $_GET['next'] ?? ($_POST['next'] ?? 'dashboard.php');
if ($next === '') {
    $next = 'dashboard.php';
}

// Already logged in? Skip straight to where they were headed.
if (current_user()) {
    header("Location: $next");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'Incorrect email or password.';
    } elseif ($user['status'] !== 'active') {
        $error = 'This account has been disabled. Contact your administrator.';
    } else {
        login_user($user);
        header("Location: $next");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Login</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 400px; margin: 80px auto; padding: 0 20px; color: #222; }
    h1 { color: #6e1423; font-size: 22px; }
    label { display: block; font-size: 13px; font-weight: bold; margin: 14px 0 4px; }
    input[type=email], input[type=password] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    button { margin-top: 20px; width: 100%; padding: 10px; background: #6e1423; color: #fff; border: none; border-radius: 6px; font-size: 15px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <h1>CareerPath AI — Staff Login</h1>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>

        <label>Password</label>
        <input type="password" name="password" required>

        <button type="submit">Log in</button>
    </form>

    <p style="margin-top:18px;font-size:13px;text-align:center;"><a href="index.php" style="color:#6e1423;">&larr; Back to home</a></p>
</body>
</html>
