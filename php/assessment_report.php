<?php
// CareerPath AI - Downloadable/printable assessment report
// --------------------------------------------------------------------
// "Generate downloadable assessment reports and career recommendation
// summaries (PDF/Print)" from the Gantt chart. Built as a print-friendly
// HTML page (browser's own "Print to PDF" covers the PDF half) rather than
// adding a server-side PDF library — consistent with the project's
// lightweight stack. Reachable by the owning student (their own profile)
// or any counselor/administrator (any student's profile, for consultation
// prep and printed hand-outs).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/skills_helper.php';

session_start();
$isStaff = isset($_SESSION['user_id']);
$isStudent = isset($_SESSION['student_id']);

if (!$isStaff && !$isStudent) {
    header('Location: index.php');
    exit;
}

$pdo = get_db();
$profileId = (int) ($_GET['profile_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT sp.*, s.name AS student_name, s.email AS student_email, s.grade_level
     FROM student_profiles sp
     JOIN students s ON s.student_id = sp.student_id
     WHERE sp.profile_id = :id"
);
$stmt->execute(['id' => $profileId]);
$profile = $stmt->fetch();

if (!$profile) {
    http_response_code(404);
    echo '<p style="font-family:Arial,sans-serif;max-width:600px;margin:60px auto;">Report not found.</p>';
    exit;
}

// A student may only ever print their OWN report; staff may print anyone's.
if (!$isStaff && (int) $profile['student_id'] !== (int) $_SESSION['student_id']) {
    http_response_code(403);
    echo '<p style="font-family:Arial,sans-serif;max-width:600px;margin:60px auto;">You can only view your own assessment reports.</p>';
    exit;
}

$recStmt = $pdo->prepare(
    "SELECT r.match_score, r.rank_position, c.career_id, c.career_title, c.description, c.daily_task, c.educational_pathway
     FROM recommendations r
     JOIN careers c ON c.career_id = r.career_id
     WHERE r.profile_id = :id
     ORDER BY r.rank_position ASC"
);
$recStmt->execute(['id' => $profileId]);
$recommendations = $recStmt->fetchAll();

$riasecLabels = ['r_score' => 'Realistic (R)', 'i_score' => 'Investigative (I)', 'a_score' => 'Artistic (A)', 's_score' => 'Social (S)', 'e_score' => 'Enterprising (E)', 'c_score' => 'Conventional (C)'];
$backLink = $isStaff ? 'students_lookup.php' : 'student_history.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Assessment Report</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; color: #222; }
    .no-print { margin-bottom: 20px; }
    .btn { display: inline-block; padding: 9px 18px; border-radius: 6px; font-size: 14px; font-weight: bold; background: #6e1423; color: #fff; border: none; cursor: pointer; text-decoration: none; margin-right: 10px; }
    header.report-header { border-bottom: 3px solid #6e1423; padding-bottom: 14px; margin-bottom: 20px; }
    header.report-header h1 { color: #6e1423; margin: 0 0 4px; }
    header.report-header .sub { color: #666; font-size: 13px; }
    .student-block { background: #faf0f1; border-radius: 8px; padding: 14px 20px; margin-bottom: 22px; font-size: 14px; }
    .student-block div { margin-bottom: 3px; }
    h2.section-title { color: #6e1423; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 6px; margin-top: 28px; }
    table.riasec-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
    table.riasec-table td { padding: 6px 8px; border-bottom: 1px solid #eee; }
    .career-block { border: 1px solid #ddd; border-radius: 8px; padding: 14px 20px; margin-top: 14px; page-break-inside: avoid; }
    .career-block h3 { margin: 0 0 6px; color: #6e1423; }
    .career-block .match { float: right; background: #6e1423; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 13px; }
    .skill-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 11px; }
    .skill-have { background: #d1e7dd; color: #0f5132; }
    .skill-need { background: #fff3cd; color: #856404; }
    footer.report-footer { margin-top: 40px; font-size: 11px; color: #999; border-top: 1px solid #eee; padding-top: 10px; }
    @media print {
        .no-print { display: none; }
        body { margin: 0; }
    }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <div class="no-print">
        <button class="btn" onclick="window.print()">Print / Save as PDF</button>
        <a class="btn" href="<?= $backLink ?>" style="background:#fff;color:#6e1423;border:1px solid #6e1423;">&larr; Back</a>
    </div>

    <header class="report-header">
        <h1>CareerPath AI — Assessment Report</h1>
        <div class="sub">Meridian Educational Institution Inc. · Generated <?= date('M j, Y') ?></div>
    </header>

    <div class="student-block">
        <div><strong>Student:</strong> <?= htmlspecialchars($profile['student_name']) ?></div>
        <div><strong>Email:</strong> <?= htmlspecialchars($profile['student_email']) ?></div>
        <?php if ($profile['grade_level']): ?><div><strong>Grade level:</strong> <?= htmlspecialchars($profile['grade_level']) ?></div><?php endif; ?>
        <div><strong>Assessment date:</strong> <?= date('M j, Y', strtotime($profile['submitted_at'])) ?></div>
        <?php if (!empty($profile['academic_average'])): ?>
            <div><strong>Academic average:</strong> <?= number_format($profile['academic_average'], 2) ?></div>
        <?php endif; ?>
    </div>

    <h2 class="section-title">RIASEC Profile</h2>
    <table class="riasec-table">
        <?php foreach ($riasecLabels as $col => $label): ?>
            <tr>
                <td style="width:200px;"><?= $label ?></td>
                <td><strong><?= number_format($profile[$col] * 100, 0) ?>%</strong></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2 class="section-title">Career Recommendations</h2>
    <?php if (!$recommendations): ?>
        <p><em>No recommendations were saved for this assessment.</em></p>
    <?php else: ?>
        <?php foreach ($recommendations as $rec): ?>
            <?php $skillMatch = compute_skill_match($pdo, (int) $rec['career_id'], $profile['skills'] ?? null); ?>
            <div class="career-block">
                <span class="match"><?= number_format($rec['match_score'], 0) ?>% match</span>
                <h3>#<?= $rec['rank_position'] ?> — <?= htmlspecialchars($rec['career_title']) ?></h3>
                <p><?= htmlspecialchars($rec['description']) ?></p>
                <p><strong>Typical tasks:</strong> <?= htmlspecialchars($rec['daily_task']) ?></p>
                <p><strong>Educational pathway:</strong> <?= htmlspecialchars($rec['educational_pathway']) ?></p>
                <?php if ($skillMatch['match_percent'] !== null): ?>
                    <p><strong><?= $skillMatch['match_percent'] ?>% skills match</strong> —
                        <?php foreach ($skillMatch['matched'] as $s): ?>
                            <span class="skill-tag skill-have">✓ <?= htmlspecialchars($s['skill_name']) ?></span>
                        <?php endforeach; ?>
                        <?php foreach ($skillMatch['missing'] as $s): ?>
                            <span class="skill-tag skill-need">to develop: <?= htmlspecialchars($s['skill_name']) ?></span>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <footer class="report-footer">
        Generated by CareerPath AI — an AI-based career guidance system for students of Meridian Educational Institution Inc.
        This report reflects a single point-in-time assessment and is meant to support, not replace, guidance counseling.
    </footer>
</body>
</html>
