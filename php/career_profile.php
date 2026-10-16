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
require_once __DIR__ . '/riasec_helper.php';
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

// Employment opportunities: the real job postings the crawler collected for this
// career (linked at approval time via pending_careers.approved_career_id, or — for careers approved before that link existed — matched by identical title). Shown
// so students can see actual hiring examples — employers, locations, pay — and
// how recent they are. Wrapped in try/catch so older databases without the link
// column simply show no panel.
$postings = [];
try {
    $postStmt = $pdo->prepare(
        "SELECT employer, location, employment_type, salary, education_level, source_url, data_source, scraped_at
         FROM pending_careers
         WHERE status = 'approved'
           AND (approved_career_id = :id OR (approved_career_id IS NULL AND LOWER(TRIM(source_title)) = LOWER(TRIM(:title))))
         ORDER BY scraped_at DESC LIMIT 50"
    );
    $postStmt->execute(['id' => $careerId, 'title' => $career['career_title']]);
    $postings = $postStmt->fetchAll();
} catch (Exception $e) {
    $postings = [];
}
$postSummary = null;
if ($postings) {
    $uniq = function (string $col, int $max) use ($postings) {
        $seen = [];
        foreach ($postings as $p) {
            $v = trim((string) ($p[$col] ?? ''));
            if ($v !== '' && !in_array(mb_strtolower($v), array_map('mb_strtolower', $seen), true)) {
                $seen[] = $v;
            }
            if (count($seen) >= $max) break;
        }
        return $seen;
    };
    $postSummary = [
        'count' => count($postings),
        'employers' => $uniq('employer', 5),
        'locations' => $uniq('location', 5),
        'types' => $uniq('employment_type', 4),
        'salaries' => $uniq('salary', 3),
        'latest' => career_updated_label($postings[0]['scraped_at'] ?? null),
        'links' => array_slice(array_filter($postings, fn($p) => preg_match('#^https?://#i', (string) $p['source_url'])), 0, 3),
    ];
}

$riasecNames = ['r_score' => 'Realistic', 'i_score' => 'Investigative', 'a_score' => 'Artistic', 's_score' => 'Social', 'e_score' => 'Enterprising', 'c_score' => 'Conventional'];

// AI notes are generated ONLY at submission time (see submit.php), in one call,
// and cached on student_profiles: one note for the student's best match and,
// if they picked one, one for their optional dream career. This page never
// calls the AI -- it only shows the cached note that belongs to THIS career
// (best-match note if this career was their rank-1 result, dream note if it
// was their dream career), so opening the page again costs no tokens.
$aiCareerCommentary = null;
$aiSkillsAreSuggested = false;

$cachedCommentaryStmt = $pdo->prepare(
    "SELECT p.*, r.career_id AS best_career_id
     FROM student_profiles p
     LEFT JOIN recommendations r ON r.profile_id = p.profile_id AND r.rank_position = 1
     WHERE p.student_id = :student_id
       AND (r.career_id = :career_id OR p.dream_career_id = :career_id2)
     ORDER BY p.submitted_at DESC"
);
$cachedCommentaryStmt->execute([
    'student_id' => $currentStudent['student_id'],
    'career_id' => $careerId,
    'career_id2' => $careerId,
]);
foreach ($cachedCommentaryStmt->fetchAll() as $cachedRow) {
    if ((int) ($cachedRow['best_career_id'] ?? 0) === $careerId && !empty($cachedRow['ai_career_commentary'])) {
        $aiCareerCommentary = $cachedRow['ai_career_commentary'];
        $aiSkillsAreSuggested = !empty($cachedRow['ai_skills_are_suggested']);
        break;
    }
    if ((int) ($cachedRow['dream_career_id'] ?? 0) === $careerId && !empty($cachedRow['ai_dream_commentary'])) {
        $aiCareerCommentary = $cachedRow['ai_dream_commentary'];
        $skillCountStmt = $pdo->prepare("SELECT COUNT(*) FROM skill_requirements WHERE career_id = :id");
        $skillCountStmt->execute(['id' => $careerId]);
        $aiSkillsAreSuggested = ((int) $skillCountStmt->fetchColumn() === 0);
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — <?= htmlspecialchars($career['career_title']) ?></title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .source-note, body > .badge-row, body > .panel, body > a.back { max-width: 800px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; margin-bottom: 4px; }
    .source-note { font-size: 13.5px; color: #888; margin-bottom: 22px; }
    .panel { background: #f5f5f5; border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; margin-bottom: 20px; }
    .panel h2 { margin: 0 0 12px; color: #6e1423; font-size: 17.5px; }
    .panel p { line-height: 1.5; }

    .riasec-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .riasec-bar-row .letter { width: 18px; font-weight: bold; color: #6e1423; font-size: 14.5px; }
    .riasec-bar-row .name { width: 100px; font-size: 13.5px; color: #666; }
    .riasec-bar-track { flex: 1; background: #f5e6e8; border-radius: 6px; height: 14px; overflow: hidden; }
    .riasec-bar-fill { background: linear-gradient(90deg, #6e1423, #b3465c); height: 100%; border-radius: 6px; }
    .riasec-bar-pct { width: 38px; text-align: right; font-size: 13.5px; color: #888; }

    .skill-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-top: 1px solid #eee; font-size: 15.5px; }
    .skill-row:first-of-type { border-top: none; }
    .skill-row .req-badge { font-size: 12.5px; padding: 2px 8px; border-radius: 10px; margin-left: 8px; }
    .req-yes { background: #f8d7da; color: #842029; }
    .req-no { background: #e2e3e5; color: #41464b; }
    .prof-badge { font-size: 12.5px; color: #666; }
    .have-badge { font-size: 13.5px; font-weight: bold; }
    .have-yes { color: #0f5132; }
    .have-no { color: #856404; }

    .skill-summary { font-size: 14.5px; color: #666; margin-bottom: 6px; }
    .skill-summary.skills-good { background: #e8f5ec; border: 1px solid #b9dfc6; color: #0f5132; border-radius: 8px; padding: 10px 14px; font-weight: bold; line-height: 1.5; margin-bottom: 10px; }
    .empty { color: #888; font-style: italic; font-size: 15.5px; }

    .ai-insights-panel { background: #f3edfb; border-color: #e2d4f5; }
    .ai-insights-panel h2 { color: #6f42c1; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .ai-suggestion-tag { background: #fff3cd; color: #856404; border: 1px solid #ffe69c; border-radius: 10px; padding: 2px 9px; font-size: 12px; font-weight: bold; cursor: help; }

    a.back { display: inline-block; margin-top: 6px; color: #6e1423; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
.badge-row { margin-top: 0; margin-bottom: 18px; display: flex; flex-wrap: wrap; gap: 6px; }
    .badge-row .src-badge, .badge-row .upd-badge { margin: 0; }
    .src-badge, .upd-badge { display: inline-block; border-radius: 12px; padding: 3px 10px; font-size: 12.5px; font-weight: bold; margin: 2px 6px 2px 0; }
    .src-badge { background: #f0dde1; color: #6e1423; }
    .upd-badge { background: #e7f1ff; color: #0a4a9e; border: 1px solid #b6d4fe; }
.employment-panel { background: #eef7f0; border-color: #cfe6d5; }
    .employment-panel h2 { color: #0f5132; }
    .emp-note { font-size: 13.5px; color: #555; }
    .employment-panel a { color: #0f5132; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1><?= htmlspecialchars($career['career_title']) ?></h1>
    <p class="source-note">Career profile — reviewed by a counselor/administrator before publishing.</p>
    <p class="badge-row"><?= career_source_badges($career) ?></p>

    <?php if ($aiCareerCommentary): ?>
        <div class="panel ai-insights-panel">
            <h2>
                ✨ AI Insights — why this could fit you
                <?php if ($aiSkillsAreSuggested): ?>
                    <span class="ai-suggestion-tag" title="This career doesn't have a counselor-verified skills list yet, so any skills mentioned here are general AI suggestions, not a verified requirement.">AI-suggested skills</span>
                <?php endif; ?>
            </h2>
            <p><?= nl2br(htmlspecialchars($aiCareerCommentary)) ?></p>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Description</h2>
        <p><?= nl2br(htmlspecialchars($career['description'] ?: 'No description on file yet.')) ?></p>
    </div>

    <div class="panel">
        <h2>Typical Tasks</h2>
        <p><?= nl2br(htmlspecialchars($career['daily_task'] ?: 'No task details on file yet.')) ?></p>
    </div>

    <div class="panel">
        <h2>Programs You Can Enroll In</h2>
        <p><?= htmlspecialchars($career['educational_pathway'] ?: 'No programs on file yet.') ?></p>
    </div>

    <?php if (!empty($career['key_subjects'])): ?>
        <div class="panel">
            <h2>📚 Subjects to Focus On</h2>
            <p><?= htmlspecialchars($career['key_subjects']) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($postSummary): ?>
        <div class="panel employment-panel">
            <h2>💼 Employment Opportunities</h2>
            <p class="emp-note">Based on <?= (int) $postSummary['count'] ?> real job posting<?= $postSummary['count'] === 1 ? '' : 's' ?> collected for this career<?= $postSummary['latest'] ? ' (most recent collected ' . htmlspecialchars($postSummary['latest']) . ')' : '' ?>. Openings change often, so use these as examples of what employers are looking for.</p>
            <?php if ($postSummary['employers']): ?><p><strong>Example employers:</strong> <?= htmlspecialchars(implode(' · ', $postSummary['employers'])) ?></p><?php endif; ?>
            <?php if ($postSummary['locations']): ?><p><strong>Where hiring:</strong> <?= htmlspecialchars(implode(' · ', $postSummary['locations'])) ?></p><?php endif; ?>
            <?php if ($postSummary['types']): ?><p><strong>Employment type:</strong> <?= htmlspecialchars(implode(' · ', $postSummary['types'])) ?></p><?php endif; ?>
            <?php if ($postSummary['salaries']): ?><p><strong>Posted pay examples:</strong> <?= htmlspecialchars(implode(' · ', $postSummary['salaries'])) ?></p><?php endif; ?>
            <?php if ($postSummary['links']): ?>
                <p><strong>Original postings:</strong>
                <?php foreach ($postSummary['links'] as $i => $lp): ?>
                    <a href="<?= htmlspecialchars($lp['source_url']) ?>" target="_blank" rel="noopener noreferrer">Posting <?= $i + 1 ?> (<?= htmlspecialchars(career_source_label($lp['data_source'])) ?>)</a><?= $i < count($postSummary['links']) - 1 ? ' · ' : '' ?>
                <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>RIASEC Profile</h2>
        <?php foreach ($riasecNames as $col => $name): ?>
            <?php $pct = round($career[$col]); ?>
            <div class="riasec-bar-row">
                <div class="letter"><?= strtoupper($col[0]) ?></div>
                <div class="name"><?= $name ?></div>
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
            <?php $skillMsg = skill_match_message($skillMatch); ?>
            <?php if ($skillMsg['text'] !== ''): ?>
                <div class="skill-summary skills-good"><?= htmlspecialchars($skillMsg['text']) ?></div>
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
                        <?php $profText = trim($skill['proficiency_level'] ?? ''); ?>
                        <?php if ($profText !== ''): ?>
                            <span class="prof-badge">· <?= htmlspecialchars($profText) ?></span>
                        <?php endif; ?>
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
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
