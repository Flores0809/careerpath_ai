<?php
// CareerPath AI - Staff Dashboard (administrators & counselors)
//
// The real "home base" after staff login — replaces landing directly on
// careers.php. Gives an at-a-glance view of the career review pipeline and
// (for administrators) account counts, plus a recent review activity feed
// built from the reviewed_by/reviewed_at columns on pending_careers.

require __DIR__ . '/auth.php';
$currentUser = require_role(['administrator', 'counselor']);

$pdo = get_db();

$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM pending_careers WHERE status = 'pending'")->fetchColumn();
$approvedCount = (int) $pdo->query("SELECT COUNT(*) FROM pending_careers WHERE status = 'approved'")->fetchColumn();
$rejectedCount = (int) $pdo->query("SELECT COUNT(*) FROM pending_careers WHERE status = 'rejected'")->fetchColumn();
$activeCareerCount = (int) $pdo->query("SELECT COUNT(*) FROM careers WHERE status = 'active'")->fetchColumn();

$studentCount = null;
$counselorCount = null;
$administratorCount = null;
if ($currentUser['role'] === 'administrator') {
    $studentCount = (int) $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $counselorCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'counselor' AND status = 'active'")->fetchColumn();
    $administratorCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'administrator' AND status = 'active'")->fetchColumn();
}

// Analytics panel — the "analytics" half of "Develop the Guidance Counselor
// Dashboard (... recommendation results, consultation history, and
// analytics)" from the Gantt chart. Open to both roles, same as the rest of
// this dashboard.
$avgRiasec = $pdo->query(
    "SELECT AVG(r_score) r, AVG(i_score) i, AVG(a_score) a, AVG(s_score) s, AVG(e_score) e, AVG(c_score) c
     FROM student_profiles"
)->fetch();

$topCareers = $pdo->query(
    "SELECT c.career_title, COUNT(*) AS times_recommended, AVG(r.match_score) AS avg_match
     FROM recommendations r
     JOIN careers c ON c.career_id = r.career_id
     GROUP BY r.career_id
     ORDER BY times_recommended DESC
     LIMIT 5"
)->fetchAll();

$assessmentsThisWeek = (int) $pdo->query(
    "SELECT COUNT(*) FROM student_profiles WHERE submitted_at >= NOW() - INTERVAL 7 DAY"
)->fetchColumn();
$totalAssessments = (int) $pdo->query("SELECT COUNT(*) FROM student_profiles")->fetchColumn();

try {
    $pendingConsultations = (int) $pdo->query("SELECT COUNT(*) FROM consultations WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {
    $pendingConsultations = 0; // consultations table not present yet (pre-migration_10 install)
}

$recentActivity = $pdo->query(
    "SELECT pc.source_title, pc.status, pc.reviewed_at, u.name AS reviewer_name
     FROM pending_careers pc
     LEFT JOIN users u ON u.user_id = pc.reviewed_by
     WHERE pc.status IN ('approved', 'rejected')
     ORDER BY pc.reviewed_at DESC
     LIMIT 8"
)->fetchAll();

$welcome = isset($_GET['welcome']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Dashboard</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; padding: 40px 20px; color: #222; background: #faf7f5; }
    .wrap { max-width: 1280px; margin: 0 auto; position: relative; }
    .wrap-content { position: relative; z-index: 1; }
    .dashboard-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 65%; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
    h1 { color: #6e1423; margin-bottom: 4px; }
    .subtitle { color: #666; margin-top: 0; margin-bottom: 28px; }

    .welcome-banner { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }

    .stat-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 18px; }
    @media (max-width: 900px) { .stat-row { grid-template-columns: repeat(2, 1fr); } }
    .stat-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(74,12,23,0.08); padding: 18px 20px; }
    .stat-card .label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 6px; }
    .stat-card .value { font-size: 26px; font-weight: bold; color: #6e1423; }
    .stat-card.pending .value { color: #b8860b; }
    .stat-card.rejected .value { color: #b02a37; }
    .stat-card.approved .value { color: #0f5132; }
    a.stat-card-link { text-decoration: none; display: block; transition: box-shadow 0.15s; }
    a.stat-card-link:hover { box-shadow: 0 6px 20px rgba(74,12,23,0.16); }

    .panels { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 28px; }
    @media (max-width: 900px) { .panels { grid-template-columns: 1fr; } }
    .panel { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(74,12,23,0.08); padding: 24px 26px; }
    .panel h2 { margin: 0 0 16px; color: #6e1423; font-size: 16px; }
    .riasec-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .riasec-letter { width: 16px; font-weight: bold; color: #6e1423; font-size: 13px; }
    .riasec-track { flex: 1; background: #f5e6e8; border-radius: 6px; height: 12px; overflow: hidden; }
    .riasec-fill { background: linear-gradient(90deg, #6e1423, #b3465c); height: 100%; border-radius: 6px; }
    .riasec-pct { width: 36px; text-align: right; font-size: 12px; color: #888; }

    .activity-item { display: flex; justify-content: space-between; align-items: baseline; padding: 10px 0; border-top: 1px solid #eee; font-size: 14px; gap: 10px; }
    .activity-item:first-of-type { border-top: none; }
    .activity-item .title { font-weight: bold; color: #222; flex: 1; }
    .activity-item .meta { color: #888; font-size: 12px; white-space: nowrap; }
    .status-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; text-transform: uppercase; margin-right: 8px; }
    .status-approved { background: #d1e7dd; color: #0f5132; }
    .status-rejected { background: #fdecea; color: #611a15; }

    .empty { color: #888; font-style: italic; font-size: 14px; }
</style>
</head>
<body>
    <div class="wrap">
        <img src="assets/img/logo.png" alt="" class="dashboard-watermark">
        <div class="wrap-content">
        <?php require __DIR__ . '/nav.php'; ?>

        <?php if ($welcome): ?>
            <div class="welcome-banner">Welcome! Your administrator account is set up. Create counselor accounts from Manage Accounts whenever you're ready.</div>
        <?php endif; ?>

        <h1>Welcome, <?= htmlspecialchars($currentUser['name']) ?></h1>
        <p class="subtitle">Here's what's happening in the career review pipeline.</p>

        <div class="stat-row">
            <a class="stat-card stat-card-link pending" href="careers.php">
                <div class="label">Pending Review</div>
                <div class="value"><?= $pendingCount ?></div>
            </a>
            <a class="stat-card stat-card-link approved" href="#recent-review-activity">
                <div class="label">Approved</div>
                <div class="value"><?= $approvedCount ?></div>
            </a>
            <a class="stat-card stat-card-link rejected" href="#recent-review-activity">
                <div class="label">Rejected</div>
                <div class="value"><?= $rejectedCount ?></div>
            </a>
            <a class="stat-card stat-card-link" href="careers_manage.php">
                <div class="label">Active Careers</div>
                <div class="value"><?= $activeCareerCount ?></div>
            </a>
            <a class="stat-card stat-card-link" href="consultations.php">
                <div class="label">Pending Consultations</div>
                <div class="value"><?= $pendingConsultations ?></div>
            </a>
        </div>

        <?php if ($currentUser['role'] === 'administrator'): ?>
            <div class="stat-row">
                <a class="stat-card stat-card-link" href="users.php">
                    <div class="label">Students</div>
                    <div class="value"><?= $studentCount ?></div>
                </a>
                <a class="stat-card stat-card-link" href="users.php">
                    <div class="label">Counselors (active)</div>
                    <div class="value"><?= $counselorCount ?></div>
                </a>
                <a class="stat-card stat-card-link" href="users.php">
                    <div class="label">Administrators (active)</div>
                    <div class="value"><?= $administratorCount ?></div>
                </a>
            </div>
        <?php endif; ?>

        <div class="panels">
            <div class="panel">
                <h2>Student Analytics</h2>
                <p class="empty" style="margin-top:0;"><?= $totalAssessments ?> assessments total · <?= $assessmentsThisWeek ?> in the last 7 days</p>

                <?php if ($totalAssessments > 0): ?>
                    <p style="font-size:13px;color:#666;margin:14px 0 6px;"><strong>Average RIASEC profile (all students)</strong></p>
                    <?php
                        $avgLabels = ['r' => 'R', 'i' => 'I', 'a' => 'A', 's' => 'S', 'e' => 'E', 'c' => 'C'];
                    ?>
                    <?php foreach ($avgLabels as $col => $letter): ?>
                        <?php $pct = round(((float) $avgRiasec[$col]) * 100); ?>
                        <div class="riasec-bar-row">
                            <div class="riasec-letter"><?= $letter ?></div>
                            <div class="riasec-track"><div class="riasec-fill" style="width: <?= $pct ?>%;"></div></div>
                            <div class="riasec-pct"><?= $pct ?>%</div>
                        </div>
                    <?php endforeach; ?>

                    <p style="font-size:13px;color:#666;margin:18px 0 6px;"><strong>Most recommended careers</strong></p>
                    <?php if (!$topCareers): ?>
                        <p class="empty">No recommendations saved yet.</p>
                    <?php else: ?>
                        <?php foreach ($topCareers as $tc): ?>
                            <div class="activity-item">
                                <span class="title"><?= htmlspecialchars($tc['career_title']) ?></span>
                                <span class="meta"><?= $tc['times_recommended'] ?>x · avg <?= number_format($tc['avg_match'], 0) ?>% match</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="empty">No assessments have been submitted yet.</p>
                <?php endif; ?>
            </div>

            <div class="panel" id="recent-review-activity">
                <h2>Recent Review Activity</h2>
                <?php if (!$recentActivity): ?>
                    <p class="empty">No careers have been approved or rejected yet.</p>
                <?php else: ?>
                    <?php foreach ($recentActivity as $item): ?>
                        <div class="activity-item">
                            <span class="title">
                                <span class="status-tag status-<?= htmlspecialchars($item['status']) ?>"><?= htmlspecialchars($item['status']) ?></span>
                                <?= htmlspecialchars($item['source_title'] ?? '(untitled)') ?>
                            </span>
                            <span class="meta">
                                <?= $item['reviewer_name'] ? htmlspecialchars($item['reviewer_name']) . ' · ' : '' ?><?= date('M j, Y', strtotime($item['reviewed_at'])) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>
</body>
</html>
