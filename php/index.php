<?php
// CareerPath AI - Front landing page
//
// The public entry point for the whole system. Routes visitors down one of
// two paths — Student or Staff — since they're two completely separate
// login systems (see php/student_auth.php vs php/auth.php). If someone's
// already logged in as either, this page shows shortcuts instead of a
// login prompt.

require __DIR__ . '/student_auth.php';
require __DIR__ . '/auth.php';

$currentStudent = current_student();
$currentStaff = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — Meridian Educational Institution Inc.</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; padding: 0; color: #222; background: #e9e6e4; }

    .page { position: relative; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 40px 20px; }

    /* Split-panel shell: colored brand panel on the left, page content on
       the right — same two-pane login-screen layout the client referenced,
       adapted to this page's actual job (routing to Student/Staff), not a
       login form of its own. */
    .auth-shell { width: 100%; max-width: 1080px; min-height: 600px; background: #fff; border-radius: 22px; box-shadow: 0 20px 50px rgba(0,0,0,0.18); overflow: hidden; display: flex; }
    @media (max-width: 800px) { .auth-shell { flex-direction: column; min-height: 0; } }

    .auth-left { flex: 0 0 42%; background: linear-gradient(135deg, #4a0c17 0%, #6e1423 100%); color: #fff; padding: 56px 40px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
    .auth-logo { width: 92px; height: auto; margin-bottom: 18px; }
    .auth-left h1 { font-size: 28px; margin: 0 0 4px; letter-spacing: 0.5px; }
    .auth-divider { width: 60px; height: 2px; background: rgba(255,255,255,0.45); border: none; margin: 16px 0; }
    .auth-tagline { font-size: 15px; color: #f0dde1; line-height: 1.6; max-width: 300px; margin: 0; }

    .auth-right { flex: 1; padding: 56px 44px; display: flex; flex-direction: column; justify-content: center; }
    .auth-right h2 { color: #6e1423; font-size: 26px; margin: 0 0 6px; text-align: center; }
    .auth-right .welcome-sub { text-align: center; color: #888; font-size: 14.5px; margin: 0 0 30px; }

    .cards { display: flex; flex-direction: column; gap: 18px; }

    .card { background: #f5f5f5; border-radius: 12px; padding: 24px 26px; }
    .card h3 { color: #6e1423; margin: 0 0 4px; font-size: 18px; text-align: center; }
    .card p.tagline { color: #6e1423; font-size: 14.5px; text-align: center; margin: 0 0 16px; }

    .welcome-box { background: #faf0f1; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; font-size: 15px; }
    .welcome-box .role-badge { display: inline-block; background: rgba(110,20,35,0.12); color: #6e1423; padding: 2px 8px; border-radius: 10px; font-size: 12px; text-transform: uppercase; margin-left: 6px; }

    .btn-row { display: flex; flex-direction: column; gap: 10px; }
    .btn { display: block; text-align: center; text-decoration: none; padding: 12px 18px; border-radius: 6px; font-size: 16px; font-weight: bold; }
    .btn-primary { background: #6e1423; color: #fff; }
    .btn-primary:hover { background: #4a0c17; }
    .btn-outline { background: #fff; color: #6e1423; border: 1px solid #6e1423; }
    .btn-outline:hover { background: #faf0f1; }
    .btn-text { text-align: center; font-size: 14px; color: #888; text-decoration: none; margin-top: 4px; }
    .btn-text:hover { color: #611a15; }

    footer.page-footer { text-align: center; color: #888; font-size: 13.5px; margin-top: 18px; }
    footer.page-footer strong { color: #6e1423; }
</style>
</head>
<body>
    <div class="page">
        <div>
        <div class="auth-shell">
            <div class="auth-left">
                <img src="assets/img/logo-hex.png" alt="" class="auth-logo">
                <h1>CareerPath AI</h1>
                <hr class="auth-divider">
                <p class="auth-tagline">JH/SH AI-assisted career guidance system</p>
            </div>

            <div class="auth-right">
                <h2>Welcome</h2>
                <p class="welcome-sub">Choose how you'd like to continue</p>

                <div class="cards">
                <div class="card">
                    <h3>Students</h3>
                    <p class="tagline">Start Your Personalized Career Journey</p>

                    <?php if ($currentStudent): ?>
                        <div class="welcome-box">
                            Welcome, <strong><?= htmlspecialchars($currentStudent['name']) ?></strong>!
                        </div>
                        <div class="btn-row">
                            <a class="btn btn-primary" href="student_dashboard.php">Go to My Dashboard</a>
                            <a class="btn btn-outline" href="assessment.php">Take the Assessment</a>
                            <a class="btn-text" href="student_logout.php">Log out</a>
                        </div>
                    <?php else: ?>
                        <div class="btn-row">
                            <a class="btn btn-primary" href="student_login.php">Student Log In</a>
                            <a class="btn btn-outline" href="student_register.php">Create an Account</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>Staff</h3>
                    <p class="tagline">Manage Accounts and Approve Careers</p>

                    <?php if ($currentStaff): ?>
                        <div class="welcome-box">
                            Welcome, <strong><?= htmlspecialchars($currentStaff['name']) ?></strong>!
                            <span class="role-badge"><?= htmlspecialchars($currentStaff['role']) ?></span>
                        </div>
                        <div class="btn-row">
                            <a class="btn btn-primary" href="dashboard.php">Go to Dashboard</a>
                            <a class="btn btn-outline" href="careers.php">Career Review</a>
                            <a class="btn-text" href="logout.php">Log out</a>
                        </div>
                    <?php else: ?>
                        <div class="btn-row">
                            <a class="btn btn-primary" href="login.php">Staff Log In</a>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </div>
        </div>

        <footer class="page-footer">
            <strong>CareerPath AI</strong> — a capstone project for Meridian Educational Institution Inc. (MEII)
        </footer>
        </div>
    </div>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
