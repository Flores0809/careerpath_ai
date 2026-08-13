<?php
// CareerPath AI - Student Dashboard
//
// The real "home base" for a logged-in student — replaces landing directly
// on the intake form after login. Summarizes their latest RIASEC profile,
// top match, and assessment history at a glance, with quick actions to
// take a new assessment or view full history.

require __DIR__ . '/student_auth.php';
$currentStudent = require_student_login();

$pdo = get_db();

$stmt = $pdo->prepare("SELECT created_at FROM students WHERE student_id = :id");
$stmt->execute(['id' => $currentStudent['student_id']]);
$memberSince = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_profiles WHERE student_id = :id");
$stmt->execute(['id' => $currentStudent['student_id']]);
$assessmentCount = (int) $stmt->fetchColumn();

// Counselor-recorded notes/outcomes for this student (students_lookup.php) —
// shown in full on student_history.php; just a count + link here.
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM counselor_log WHERE student_id = :id AND action = 'recorded_outcome' AND notes IS NOT NULL"
);
$stmt->execute(['id' => $currentStudent['student_id']]);
$counselorNoteCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM student_profiles WHERE student_id = :id ORDER BY submitted_at DESC LIMIT 1"
);
$stmt->execute(['id' => $currentStudent['student_id']]);
$latestProfile = $stmt->fetch();

$topRecommendations = [];
if ($latestProfile) {
    $stmt = $pdo->prepare(
        "SELECT r.match_score, c.career_id, c.career_title
         FROM recommendations r
         JOIN careers c ON c.career_id = r.career_id
         WHERE r.profile_id = :profile_id
         ORDER BY r.rank_position ASC
         LIMIT 3"
    );
    $stmt->execute(['profile_id' => $latestProfile['profile_id']]);
    $topRecommendations = $stmt->fetchAll();
}

$stmt = $pdo->prepare(
    "SELECT profile_id, submitted_at FROM student_profiles WHERE student_id = :id ORDER BY submitted_at DESC LIMIT 5"
);
$stmt->execute(['id' => $currentStudent['student_id']]);
$recentSubmissions = $stmt->fetchAll();

$topCareerStmt = $pdo->prepare(
    "SELECT c.career_id, c.career_title, r.match_score
     FROM recommendations r
     JOIN careers c ON c.career_id = r.career_id
     WHERE r.profile_id = :profile_id
     ORDER BY r.rank_position ASC
     LIMIT 1"
);

$riasecLabels = ['r_score' => 'R', 'i_score' => 'I', 'a_score' => 'A', 's_score' => 'S', 'e_score' => 'E', 'c_score' => 'C'];
$riasecNames = ['r_score' => 'Realistic', 'i_score' => 'Investigative', 'a_score' => 'Artistic', 's_score' => 'Social', 'e_score' => 'Enterprising', 'c_score' => 'Conventional'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — My Dashboard</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; padding: 40px 20px; color: #222; background: #faf7f5; }
    .wrap { max-width: 1280px; margin: 0 auto; position: relative; }
    .wrap-content { position: relative; z-index: 1; }
    .dashboard-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 65%; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
    h1 { color: #6e1423; margin-bottom: 4px; }
    .subtitle { color: #666; margin-top: 0; margin-bottom: 28px; }

    .stat-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-bottom: 24px; }
    @media (max-width: 720px) { .stat-row { grid-template-columns: 1fr; } }
    .stat-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(74,12,23,0.08); padding: 20px 22px; }
    .stat-card .label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 6px; }
    .stat-card .value { font-size: 26px; font-weight: bold; color: #6e1423; }
    .stat-card .value.small { font-size: 17px; }
    .stat-card .sub { font-size: 13px; color: #888; margin-top: 4px; }
    a.stat-card-link { text-decoration: none; display: block; transition: box-shadow 0.15s; }
    a.stat-card-link:hover { box-shadow: 0 6px 20px rgba(74,12,23,0.16); }

    .panels { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 18px; margin-bottom: 28px; }
    @media (max-width: 720px) { .panels { grid-template-columns: 1fr; } }
    .panel { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(74,12,23,0.08); padding: 24px 26px; }
    .panel h2 { margin: 0 0 16px; color: #6e1423; font-size: 16px; }

    .riasec-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .riasec-bar-row .letter { width: 18px; font-weight: bold; color: #6e1423; font-size: 13px; }
    .riasec-bar-row .name { width: 100px; font-size: 12px; color: #666; }
    .riasec-bar-track { flex: 1; background: #f5e6e8; border-radius: 6px; height: 14px; overflow: hidden; }
    .riasec-bar-fill { background: linear-gradient(90deg, #6e1423, #b3465c); height: 100%; border-radius: 6px; }
    .riasec-bar-pct { width: 38px; text-align: right; font-size: 12px; color: #888; }

    .activity-item { display: flex; justify-content: space-between; align-items: baseline; padding: 10px 0; border-top: 1px solid #eee; font-size: 14px; }
    .activity-item:first-of-type { border-top: none; }
    .activity-item .date { color: #666; }
    .activity-item .top-career { color: #6e1423; font-weight: bold; }
    .activity-item .top-career a, .stat-card .value.small a { color: #6e1423; text-decoration: none; }
    .activity-item .top-career a:hover, .stat-card .value.small a:hover { text-decoration: underline; }

    .empty { color: #888; font-style: italic; font-size: 14px; }

    .welcome-banner { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .notes-banner { background: #fff3cd; border: 1px solid #ffe08a; color: #856404; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    .notes-banner a { color: #856404; font-weight: bold; text-decoration: underline; }
</style>
</head>
<body>
    <div class="wrap">
        <img src="assets/img/logo.png" alt="" class="dashboard-watermark">
        <div class="wrap-content">
        <?php require __DIR__ . '/student_nav.php'; ?>

        <?php if (isset($_GET['welcome'])): ?>
            <div class="welcome-banner">Account created! Take your first assessment below to get personalized career recommendations.</div>
        <?php endif; ?>

        <?php if ($counselorNoteCount > 0): ?>
            <div class="notes-banner">
                <span>📝 You have <?= $counselorNoteCount ?> note<?= $counselorNoteCount === 1 ? '' : 's' ?> from your guidance counselor.</span>
                <a href="student_history.php">View notes &rarr;</a>
            </div>
        <?php endif; ?>

        <h1>Welcome, <?= htmlspecialchars($currentStudent['name']) ?></h1>
        <p class="subtitle">Here's a snapshot of your career guidance journey so far.</p>

        <div class="stat-row">
            <a class="stat-card stat-card-link" href="student_history.php">
                <div class="label">Assessments Taken</div>
                <div class="value"><?= $assessmentCount ?></div>
                <div class="sub"><?= $assessmentCount === 1 ? 'submission on record' : 'submissions on record' ?></div>
            </a>
            <?php if ($topRecommendations): ?>
                <a class="stat-card stat-card-link" href="career_profile.php?id=<?= (int) $topRecommendations[0]['career_id'] ?>">
                    <div class="label">Top Match (Latest)</div>
                    <div class="value small"><?= htmlspecialchars($topRecommendations[0]['career_title']) ?></div>
                    <div class="sub"><?= number_format($topRecommendations[0]['match_score'], 0) ?>% match</div>
                </a>
            <?php else: ?>
                <a class="stat-card stat-card-link" href="assessment.php">
                    <div class="label">Top Match (Latest)</div>
                    <div class="value small">—</div>
                    <div class="sub">Take the assessment to find out</div>
                </a>
            <?php endif; ?>
            <a class="stat-card stat-card-link" href="student_profile.php">
                <div class="label">Member Since</div>
                <div class="value small"><?= $memberSince ? date('M j, Y', strtotime($memberSince)) : '—' ?></div>
                <div class="sub">CareerPath AI account</div>
            </a>
        </div>

        <div class="panels">
            <div class="panel">
                <h2>Latest RIASEC Snapshot</h2>
                <?php if ($latestProfile): ?>
                    <?php foreach ($riasecLabels as $col => $letter): ?>
                        <?php $pct = round($latestProfile[$col] * 100); ?>
                        <div class="riasec-bar-row">
                            <div class="letter"><?= $letter ?></div>
                            <div class="name"><?= $riasecNames[$col] ?></div>
                            <div class="riasec-bar-track"><div class="riasec-bar-fill" style="width: <?= $pct ?>%;"></div></div>
                            <div class="riasec-bar-pct"><?= $pct ?>%</div>
                        </div>
                    <?php endforeach; ?>
                    <p class="sub" style="margin-top:14px;">From your submission on <?= date('M j, Y', strtotime($latestProfile['submitted_at'])) ?>.</p>
                <?php else: ?>
                    <p class="empty">No assessments yet. Take your first one to see your RIASEC profile here.</p>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2>Recent Activity</h2>
                <?php if (!$recentSubmissions): ?>
                    <p class="empty">Nothing yet — your assessment history will show up here.</p>
                <?php else: ?>
                    <?php foreach ($recentSubmissions as $submission): ?>
                        <?php
                            $topCareerStmt->execute(['profile_id' => $submission['profile_id']]);
                            $top = $topCareerStmt->fetch();
                        ?>
                        <div class="activity-item">
                            <span class="date"><?= date('M j, Y', strtotime($submission['submitted_at'])) ?></span>
                            <span class="top-career"><?= $top ? '<a href="career_profile.php?id=' . (int) $top['career_id'] . '">' . htmlspecialchars($top['career_title']) . '</a>' : 'No result saved' ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>
</body>
</html>
