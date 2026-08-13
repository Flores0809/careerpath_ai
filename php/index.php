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
<title>CareerPath AI — Meridian Educational Institution Inc.</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; padding: 0; color: #222; background: #faf7f5; }

    .page { position: relative; }
    .page-watermark { position: fixed; top: 62%; left: 50%; transform: translate(-50%, -50%); width: 420px; max-width: 55vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
    .page-content { position: relative; z-index: 1; }

    .hero { background: #4a0c17; background: linear-gradient(135deg, #4a0c17 0%, #6e1423 100%); color: #fff; padding: 60px 20px 70px; text-align: center; }
    .hero .eyebrow { text-transform: uppercase; letter-spacing: 1.5px; font-size: 13px; color: #e9c9ce; margin-bottom: 10px; }
    .hero h1 { font-size: 40px; margin: 0 0 12px; }
    .hero p { font-size: 16px; color: #f0dde1; max-width: 620px; margin: 0 auto; line-height: 1.5; }

    .content { max-width: 960px; margin: -40px auto 60px; padding: 0 20px; }
    .cards { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    @media (max-width: 720px) { .cards { grid-template-columns: 1fr; } }

    .card { background: #fff; border-radius: 12px; box-shadow: 0 8px 24px rgba(74,12,23,0.12); padding: 32px 28px; }
    .card .icon { font-size: 28px; margin-bottom: 10px; }
    .card h2 { color: #6e1423; margin: 0 0 8px; font-size: 20px; }
    .card p.desc { color: #555; font-size: 14px; line-height: 1.5; margin-bottom: 22px; min-height: 42px; }

    .welcome-box { background: #faf0f1; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; font-size: 14px; }
    .welcome-box .role-badge { display: inline-block; background: rgba(110,20,35,0.12); color: #6e1423; padding: 2px 8px; border-radius: 10px; font-size: 11px; text-transform: uppercase; margin-left: 6px; }

    .btn-row { display: flex; flex-direction: column; gap: 10px; }
    .btn { display: block; text-align: center; text-decoration: none; padding: 12px 18px; border-radius: 6px; font-size: 15px; font-weight: bold; }
    .btn-primary { background: #6e1423; color: #fff; }
    .btn-primary:hover { background: #4a0c17; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
    .btn-outline { background: #fff; color: #6e1423; border: 1px solid #6e1423; }
    .btn-outline:hover { background: #faf0f1; }
    .btn-text { text-align: center; font-size: 13px; color: #888; text-decoration: none; margin-top: 4px; }
    .btn-text:hover { color: #611a15; }

    footer { text-align: center; color: #888; font-size: 13px; padding: 0 20px 40px; }
    footer strong { color: #6e1423; }
</style>
</head>
<body>
    <div class="page">
        <img src="assets/img/logo.png" alt="" class="page-watermark">
        <div class="page-content">
        <div class="hero">
            <div class="eyebrow">Meridian Educational Institution Inc.</div>
            <h1>CareerPath AI</h1>
            <p>An AI-powered career guidance system that matches JHS/SHS students to careers using a RIASEC personality assessment and a hybrid recommendation engine.</p>
        </div>

        <div class="content">
            <div class="cards">
            <div class="card">
                <div class="icon">🎓</div>
                <h2>Students</h2>
                <p class="desc">Take the RIASEC assessment and get personalized career recommendations based on your interests and strengths.</p>

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
                <div class="icon">🧑‍💼</div>
                <h2>Staff</h2>
                <p class="desc">Administrators manage counselor and administrator accounts. Counselors review, edit, and approve careers scraped from job postings.</p>

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

    <footer>
        <strong>CareerPath AI</strong> — a capstone project for Meridian Educational Institution Inc. (MEII)
    </footer>
        </div>
    </div>
</body>
</html>
