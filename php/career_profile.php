<?php
// CareerPath AI - Student-facing Career Profile page
//
// A student clicks a recommended career (from submit.php or
// student_history.php) to land here. Shows the full career profile: the
// AI/crawler-sourced description, typical tasks, educational pathway, RIASEC
// profile, and the complete required-skills list (SKILL_REQUIREMENTS entity)
// compared against the student's own most recently submitted skills — a
// standing reference page independent of any single AI recommendation call,
// per the "safe to still have an existing one available" requirement.

require __DIR__ . '/student_auth.php';
require_once __DIR__ . '/skills_helper.php';
$currentStudent = require_student_login();

$pdo = get_db();

$careerId = (int) ($_GET['id'] ?? 0);
if ($careerId <= 0) {
    header('Location: student_dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id");
$stmt->execute(['id' => $careerId]);
$career = $stmt->fetch();

if (!$career) {
    http_response_code(404);
    require __DIR__ . '/student_nav.php';
    echo '<p style="font-family:Arial,sans-serif;max-width:760px;margin:40px auto;">Career not found. <a href="student_dashboard.php">Back to dashboard</a></p>';
    exit;
}

// Required skills for this career, full list (not just matched/missing tags).
$skillStmt = $pdo->prepare(
    "SELECT skill_name, proficiency_level, is_required
     FROM skill_requirements
     WHERE career_id = :id
     ORDER BY is_required DESC, skill_name"
);
$skillStmt->execute(['id' => $careerId]);
$requiredSkills = $skillStmt->fetchAll();

// Compare against the student's most recently submitted skills (if any),
// so this page is useful even when reached outside of a specific
// recommendation's context.
$latestSkillsStmt = $pdo->prepare(
    "SELECT skills FROM student_profiles WHERE student_id = :id ORDER BY submitted_at DESC LIMIT 1"
);
$latestSkillsStmt->execute(['id' => $currentStudent['student_id']]);
$latestSkillsRaw = $latestSkillsStmt->fetchColumn();
$skillMatch = compute_skill_match($pdo, $careerId, $latestSkillsRaw ?: null);

$riasecLabels = ['r_score' => 'R', 'i_score' => 'I', 'a_score' => 'A', 's_score' => 'S', 'e_score' => 'E', 'c_score' => 'C'];
$riasecNames = ['r_score' => 'Realistic', 'i_score' => 'Investigative', 'a_score' => 'Artistic', 's_score' => 'Social', 'e_score' => 'Enterprising', 'c_score' => 'Conventional'];

$proficiencyLabels = ['basic' => 'Basic', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — <?= htmlspecialchars($career['career_title']) ?></title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .source-note, body > .panel, body > a.back { max-width: 800px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; margin-bottom: 4px; }
    .source-note { font-size: 12px; color: #888; margin-bottom: 22px; }
    .panel { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; margin-bottom: 20px; }
    .panel h2 { margin: 0 0 12px; color: #6e1423; font-size: 16px; }
    .panel p { line-height: 1.5; }

    .riasec-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .riasec-bar-row .letter { width: 18px; font-weight: bold; color: #6e1423; font-size: 13px; }
    .riasec-bar-row .name { width: 100px; font-size: 12px; color: #666; }
    .riasec-bar-track { flex: 1; background: #f5e6e8; border-radius: 6px; height: 14px; overflow: hidden; }
    .riasec-bar-fill { background: linear-gradient(90deg, #6e1423, #b3465c); height: 100%; border-radius: 6px; }
    .riasec-bar-pct { width: 38px; text-align: right; font-size: 12px; color: #888; }

    .skill-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-top: 1px solid #eee; font-size: 14px; }
    .skill-row:first-of-type { border-top: none; }
    .skill-row .req-badge { font-size: 11px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; }
    .req-yes { background: #f8d7da; color: #842029; }
    .req-no { background: #e2e3e5; color: #41464b; }
    .prof-badge { font-size: 11px; color: #666; }
    .have-badge { font-size: 12px; font-weight: bold; }
    .have-yes { color: #0f5132; }
    .have-no { color: #856404; }

    .skill-summary { font-size: 13px; color: #666; margin-bottom: 6px; }
    .skill-summary .pct { font-weight: bold; color: #6e1423; }
    .empty { color: #888; font-style: italic; font-size: 14px; }

    a.back { display: inline-block; margin-top: 6px; color: #6e1423; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1><?= htmlspecialchars($career['career_title']) ?></h1>
    <p class="source-note">Career profile — sourced from <?= htmlspecialchars($career['source'] ?? 'seed') ?> data, reviewed by a counselor/administrator before publishing.</p>

    <div class="panel">
        <h2>Description</h2>
        <p><?= nl2br(htmlspecialchars($career['description'] ?: 'No description on file yet.')) ?></p>
    </div>

    <div class="panel">
        <h2>Typical Tasks</h2>
        <p><?= nl2br(htmlspecialchars($career['daily_task'] ?: 'No task details on file yet.')) ?></p>
    </div>

    <div class="panel">
        <h2>Educational Pathway</h2>
        <p><?= htmlspecialchars($career['educational_pathway'] ?: 'No pathway on file yet.') ?></p>
    </div>

    <?php if (!empty($career['key_subjects'])): ?>
        <div class="panel">
            <h2>📚 Subjects to Focus On</h2>
            <p><?= htmlspecialchars($career['key_subjects']) ?></p>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>RIASEC Profile</h2>
        <?php foreach ($riasecLabels as $col => $letter): ?>
            <?php $pct = round($career[$col]); ?>
            <div class="riasec-bar-row">
                <div class="letter"><?= $letter ?></div>
                <div class="name"><?= $riasecNames[$col] ?></div>
                <div class="riasec-bar-track"><div class="riasec-bar-fill" style="width: <?= $pct ?>%;"></div></div>
                <div class="riasec-bar-pct"><?= $pct ?>%</div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <h2>Required Skills</h2>
        <?php if (!$requiredSkills): ?>
            <p class="empty">No required-skills list has been set up for this career yet.</p>
        <?php else: ?>
            <?php if ($skillMatch['match_percent'] !== null): ?>
                <div class="skill-summary">
                    Based on your latest assessment's skills, you match
                    <span class="pct"><?= $skillMatch['match_percent'] ?>%</span> of what this career requires.
                </div>
            <?php else: ?>
                <div class="skill-summary empty">Take the assessment with your skills listed to see your match here.</div>
            <?php endif; ?>

            <?php
                $matchedNames = array_column($skillMatch['matched'], 'skill_name');
            ?>
            <?php foreach ($requiredSkills as $skill): ?>
                <?php $haveIt = in_array($skill['skill_name'], $matchedNames, true); ?>
                <div class="skill-row">
                    <span>
                        <?= htmlspecialchars($skill['skill_name']) ?>
                        <span class="req-badge <?= $skill['is_required'] ? 'req-yes' : 'req-no' ?>">
                            <?= $skill['is_required'] ? 'Required' : 'Preferred' ?>
                        </span>
                        <span class="prof-badge">· <?= $proficiencyLabels[$skill['proficiency_level']] ?? ucfirst($skill['proficiency_level']) ?></span>
                    </span>
                    <?php if ($latestSkillsRaw): ?>
                        <span class="have-badge <?= $haveIt ? 'have-yes' : 'have-no' ?>">
                            <?= $haveIt ? '✓ You have this' : 'To develop' ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <a class="back" href="javascript:history.back()">&larr; Back</a>
</body>
</html>
