<?php
// CareerPath AI - Barebone submit handler
// 1. Scores the RIASEC intake form (sum per type, normalize 0-1)
// 2. Sends the vector to the Python/Scikit-learn matching microservice
// 3. Saves the submission + returned recommendations to student_profiles/
//    recommendations (STUDENT_PROFILE / RECOMMENDATION entities in the ERD)
//    so the student can review it later on student_history.php
// 4. Displays the ranked career recommendations returned

require __DIR__ . '/student_auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/skills_helper.php';
require_once __DIR__ . '/notifications_helper.php';

$currentStudent = require_student_login();

$types = ['R', 'I', 'A', 'S', 'E', 'C'];
$maxPerType = 4 * 4; // 4 questions x max score of 4

$riasec = [];
foreach ($types as $type) {
    $answers = $_POST[$type] ?? [];
    $sum = array_sum(array_map('intval', $answers));
    $riasec[$type] = round($sum / $maxPerType, 4); // normalize to 0-1
}

// Skills verification mechanism (Specific Objective 2 / Research Gap #4) —
// captured alongside RIASEC so recommendations can show a skills gap, not
// just a personality match. Skills stay optional; academic average is now
// required (server-side, not just the form's `required` attribute, since
// that can be bypassed) so every recommendation factors in academic standing.
$studentSkillsRaw = trim($_POST['skills'] ?? '');
$academicAverageRaw = trim($_POST['academic_average'] ?? '');
if ($academicAverageRaw === '' || !is_numeric($academicAverageRaw)) {
    http_response_code(400);
    die('Academic average is required. <a href="assessment.php">Go back</a>');
}
$academicAverage = max(0, min(100, (float) $academicAverageRaw));

// Dream career (php/assessment.php's cluster -> job picker, required there).
// Same server-side enforcement pattern as academic average above.
$dreamCareerId = (int) ($_POST['dream_career_id'] ?? 0);
if ($dreamCareerId <= 0) {
    http_response_code(400);
    die('Please choose a dream career. <a href="assessment.php">Go back</a>');
}

$pdo = get_db();

$riasecTypeNames = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];

// Compute the dream career's own match score directly in PHP (same
// mean-centered cosine similarity math as the matching microservice —
// see matching-service/app.py's profile_similarity() for why plain cosine
// similarity on raw 0-1 RIASEC vectors was a poor discriminator: every
// vector lives in the same all-positive octant of 6D space, so even a
// mismatched career scored 90%+ purely from baseline overlap) rather than
// round-tripping through Flask a second time — this also means the
// dream-career section still works even if the matching service is down.
//
// Mean-centering each profile before comparing scores the *shape* of the
// profile (which traits are relatively high/low vs each other) instead of
// raw magnitude — mathematically this is the Pearson correlation between
// the two profiles. Returned on a 0-100 scale ([-1,1] rescaled), never
// negative, so it always reads as a normal percentage to a student.
function cosine_similarity_riasec(array $a, array $b): float
{
    $keys = ['R', 'I', 'A', 'S', 'E', 'C'];
    $meanA = array_sum($a) / count($keys);
    $meanB = array_sum($b) / count($keys);

    $dot = 0.0; $normA = 0.0; $normB = 0.0;
    foreach ($keys as $k) {
        $ca = $a[$k] - $meanA;
        $cb = $b[$k] - $meanB;
        $dot += $ca * $cb;
        $normA += $ca ** 2;
        $normB += $cb ** 2;
    }
    if ($normA == 0 || $normB == 0) {
        return 50.0; // one profile is perfectly flat (no variance) — no shape to correlate against
    }
    $r = $dot / (sqrt($normA) * sqrt($normB));
    return ($r + 1) / 2 * 100;
}

// Shared: turn a `careers` row into the display-ready array (with computed
// match score + top contributing dimensions) used by render_career_card().
// Reused for the dream career itself and for every other career in the same
// field/industry cluster, so "your dream career" and "everything else in
// that field" are computed identically.
function build_career_match_data(array $careerRow, array $studentVectorAssoc): array
{
    $careerVector = [
        'R' => $careerRow['r_score'] / 100, 'I' => $careerRow['i_score'] / 100, 'A' => $careerRow['a_score'] / 100,
        'S' => $careerRow['s_score'] / 100, 'E' => $careerRow['e_score'] / 100, 'C' => $careerRow['c_score'] / 100,
    ];
    $score = cosine_similarity_riasec($studentVectorAssoc, $careerVector); // already 0-100

    // Rank "why this match" dimensions by their contribution to the
    // *centered* similarity actually used for match_score above, so the
    // explanation matches what's really driving the number — not just
    // whichever two raw scores happen to both be numerically large.
    $keys = ['R', 'I', 'A', 'S', 'E', 'C'];
    $studentMean = array_sum($studentVectorAssoc) / count($keys);
    $careerMean = array_sum($careerVector) / count($keys);
    $contributions = [];
    foreach ($keys as $k) {
        $contributions[$k] = ($studentVectorAssoc[$k] - $studentMean) * ($careerVector[$k] - $careerMean);
    }
    arsort($contributions);
    $topDimensions = [];
    $i = 0;
    foreach ($contributions as $k => $v) {
        if ($i >= 2) break;
        $topDimensions[] = ['type' => $k, 'student_pct' => round($studentVectorAssoc[$k] * 100, 1), 'career_pct' => round($careerVector[$k] * 100, 1)];
        $i++;
    }

    return [
        'career_id' => (int) $careerRow['career_id'],
        'career_title' => $careerRow['career_title'],
        'career_category' => $careerRow['career_category'],
        'description' => $careerRow['description'],
        'daily_task' => $careerRow['daily_task'],
        'educational_pathway' => $careerRow['educational_pathway'],
        'key_subjects' => $careerRow['key_subjects'] ?? null,
        'match_score' => round($score, 2),
        'top_dimensions' => $topDimensions,
        'career_riasec' => [
            'R' => (int) $careerRow['r_score'], 'I' => (int) $careerRow['i_score'], 'A' => (int) $careerRow['a_score'],
            'S' => (int) $careerRow['s_score'], 'E' => (int) $careerRow['e_score'], 'C' => (int) $careerRow['c_score'],
        ],
    ];
}

$studentVectorAssoc = ['R' => $riasec['R'], 'I' => $riasec['I'], 'A' => $riasec['A'], 'S' => $riasec['S'], 'E' => $riasec['E'], 'C' => $riasec['C']];
// 0-100 version, for the "traits to strengthen" gap comparison in render_career_card().
$studentRiasecPct = array_map(fn($v) => round($v * 100, 1), $studentVectorAssoc);

$dreamStmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id AND status = 'active'");
$dreamStmt->execute(['id' => $dreamCareerId]);
$dreamCareer = $dreamStmt->fetch();

$dreamCareerData = null;
if ($dreamCareer) {
    $dreamCareerData = build_career_match_data($dreamCareer, $studentVectorAssoc);
}

// The student picked one specific job, but the point of the field/industry
// grouping is the whole field — so also show every other active career in
// that same category, ranked by fit, instead of just the single job title.
$fieldCareers = [];
if ($dreamCareerData && $dreamCareerData['career_category']) {
    $fieldStmt = $pdo->prepare(
        "SELECT * FROM careers WHERE status = 'active' AND career_category = :category AND career_id != :dream_id"
    );
    $fieldStmt->execute(['category' => $dreamCareerData['career_category'], 'dream_id' => $dreamCareerData['career_id']]);
    foreach ($fieldStmt->fetchAll() as $row) {
        $fieldCareers[] = build_career_match_data($row, $studentVectorAssoc);
    }
    usort($fieldCareers, function ($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });
}

// Call the Python matching microservice. Top-N is configurable from the
// Administrator Module's System Settings page (php/settings.php); falls
// back to 5 if the setting row is missing for any reason.
$topN = 5;
try {
    $settingStmt = get_db()->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'recommendation_count'");
    $settingStmt->execute();
    $settingValue = $settingStmt->fetchColumn();
    if ($settingValue !== false && (int) $settingValue > 0) {
        $topN = (int) $settingValue;
    }
} catch (Exception $e) {
    // system_settings table missing (pre-migration_10 install) — keep default.
}
$payload = json_encode(['riasec' => $riasec, 'top_n' => $topN]);

$ch = curl_init(MATCHING_SERVICE_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$result = null;
$errorMessage = null;

if ($curlError) {
    $errorMessage = "Could not reach the matching engine: $curlError. Is the Flask service running (python app.py) on port 5000?";
} else {
    $result = json_decode($response, true);
    if ($httpCode !== 200 || !$result || isset($result['error'])) {
        $errorMessage = $result['error'] ?? "Matching engine returned an unexpected response (HTTP $httpCode).";
    }
}

// Save this submission + its recommendations so the student can look back
// at it later on student_history.php. Saved whenever we have either a
// dream-career fit (computed locally, doesn't need the matching service) or
// a successful matching-service result — so a Flask hiccup doesn't wipe out
// the dream-career half of the feature.
$hasMatchResults = !$errorMessage && $result && !empty($result['recommendations']);
if ($dreamCareerData || $hasMatchResults) {
    try {
        $pdo->beginTransaction();

        $insertProfile = $pdo->prepare(
            "INSERT INTO student_profiles (student_id, r_score, i_score, a_score, s_score, e_score, c_score, skills, academic_average, dream_career_id)
             VALUES (:student_id, :r, :i, :a, :s, :e, :c, :skills, :academic_average, :dream_career_id)"
        );
        $insertProfile->execute([
            'student_id' => $currentStudent['student_id'],
            'r' => $riasec['R'], 'i' => $riasec['I'], 'a' => $riasec['A'],
            's' => $riasec['S'], 'e' => $riasec['E'], 'c' => $riasec['C'],
            'skills' => $studentSkillsRaw !== '' ? $studentSkillsRaw : null,
            'academic_average' => $academicAverage,
            'dream_career_id' => $dreamCareerData ? $dreamCareerData['career_id'] : null,
        ]);
        $profileId = (int) $pdo->lastInsertId();

        if ($hasMatchResults) {
            $insertRecommendation = $pdo->prepare(
                "INSERT INTO recommendations (profile_id, student_id, career_id, match_score, rank_position)
                 VALUES (:profile_id, :student_id, :career_id, :match_score, :rank_position)"
            );
            foreach ($result['recommendations'] as $rank => $career) {
                if (empty($career['career_id'])) {
                    continue; // matching service didn't return one; skip rather than break the transaction
                }
                $insertRecommendation->execute([
                    'profile_id' => $profileId,
                    'student_id' => $currentStudent['student_id'],
                    'career_id' => $career['career_id'],
                    'match_score' => $career['match_score'],
                    'rank_position' => $rank + 1,
                ]);
            }
        }

        $pdo->commit();

        // Notification Module (Gantt chart item, not a named ERD entity —
        // see README) — assessment-completion notice. Best-effort: never
        // block the results page over a notification insert failing.
        try {
            notify_student($pdo, (int) $currentStudent['student_id'], 'Your new RIASEC assessment results are ready.', 'student_history.php');
        } catch (Exception $e) {
            // ignore
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Saving history is a nice-to-have, not required for the student to
        // see their results right now — don't block the page on this.
    }
}

// Independent connection for the skills-verification display below — kept
// separate from the save block above so a history-save hiccup never blocks
// the (unrelated) skills gap comparison from showing.
try {
    $pdo = $pdo ?? get_db();
} catch (Exception $e) {
    $pdo = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Your Recommendations</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .profile, body > .error, body > .career, body > a.back { max-width: 760px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .profile { background: #faf0f1; border-radius: 8px; padding: 14px 20px; margin-bottom: 26px; }
    .profile span { display: inline-block; margin-right: 16px; font-size: 14px; }
    .career { border: 1px solid #ddd; border-radius: 8px; padding: 16px 20px; margin-bottom: 16px; }
    .career h3 { margin: 0 0 6px 0; color: #6e1423; }
    .career h3 a { color: #6e1423; text-decoration: none; }
    .career h3 a:hover { text-decoration: underline; }
    .match { float: right; background: #6e1423; color: #fff; padding: 4px 10px; border-radius: 12px; font-size: 13px; }
    .error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 14px 20px; border-radius: 8px; }
    a.back { display: inline-block; margin-top: 20px; color: #6e1423; }
    .skills-box { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 13px; }
    .skills-box .pct { font-weight: bold; color: #6e1423; }
    .skill-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 12px; }
    .skill-have { background: #d1e7dd; color: #0f5132; }
    .skill-need { background: #fff3cd; color: #856404; }
    .how-it-works { margin-bottom: 22px; }
    .how-it-works .heading { font-weight: bold; color: #6e1423; padding: 0 0 10px; font-size: 15px; }
    .how-it-works .content { background: #faf0f1; border-radius: 8px; padding: 14px 20px; font-size: 13.5px; line-height: 1.6; color: #444; }
    .how-it-works .content p:first-child { margin-top: 0; }
    .how-it-works .content p:last-child { margin-bottom: 0; }
    .why-match { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 13px; color: #444; }
    .why-match .dim { display: inline-block; background: #f0dde1; color: #6e1423; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 12px; font-weight: bold; }
    .growth-box { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 13px; color: #444; }
    .growth-box .growth-dim { display: inline-block; background: #fff3cd; color: #856404; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 12px; font-weight: bold; }
    .subjects-box { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 13px; color: #444; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }

    .dream-career-section { max-width: 760px; margin: 0 auto 30px; }
    .dream-career-section h2 { font-size: 16px; color: #6e1423; margin: 0 0 10px; }
    .career.dream-career-card { border: 2px solid #6e1423; box-shadow: 0 6px 18px rgba(110,20,35,0.14); }
    .dream-note { font-size: 12px; color: #888; margin: -4px 0 12px; }

    .field-careers-section { max-width: 760px; margin: 0 auto 30px; }
    .field-careers-section h2 { font-size: 16px; color: #6e1423; margin: 0 0 10px; }

    .explore-others { max-width: 760px; margin: 0 auto 20px; }
    .explore-others summary { cursor: pointer; font-weight: bold; color: #6e1423; padding: 12px 0; font-size: 15px; }
    .explore-others .explore-note { font-size: 12.5px; color: #888; margin: -6px 0 16px; }
    .inline-note { font-size: 13px; color: #888; font-style: italic; max-width: 760px; margin: 0 auto 20px; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1>Your Career Recommendations</h1>

    <div class="how-it-works">
        <div class="heading">🔍 How were these recommendations calculated? (no black-box AI — see the math)</div>
        <div class="content">
            <p>Your 24 assessment answers were summed per RIASEC type (Realistic, Investigative, Artistic, Social, Enterprising, Conventional) and converted into a percentage score for each — that's the profile shown below.</p>
            <p>Each career in our database also has its own RIASEC profile, reviewed and approved by a guidance counselor or administrator. A rule-based algorithm (cosine similarity, via Scikit-learn) then compares the <em>shape</em> of your profile to every career's profile — which traits are relatively higher or lower than your own average, not just the raw scores — so a career only scores high if your actual strengths line up with what it needs, not just because most of your answers were positive.</p>
            <p>This is a transparent, rule-based calculation, not an opaque AI decision — every recommendation below includes a "Why this match?" breakdown showing exactly which of your RIASEC traits contributed most.</p>
        </div>
    </div>

    <div class="profile">
        <?php foreach ($riasec as $type => $score): ?>
            <span><strong><?= $type ?>:</strong> <?= number_format($score * 100, 0) ?>%</span>
        <?php endforeach; ?>
        <?php if ($academicAverage !== null): ?>
            <span><strong>Academic average:</strong> <?= number_format($academicAverage, 2) ?></span>
        <?php endif; ?>
    </div>

    <?php
        // Shared card renderer for the dream-career highlight, same-field
        // careers, and the "explore other careers" list, so all three stay
        // visually/structurally identical.
        function render_career_card($career, $pdo, $studentSkillsRaw, $riasecNames, $studentRiasecPct, $extraClass = '')
        {
            $skillMatch = ($pdo && !empty($career['career_id']))
                ? compute_skill_match($pdo, (int) $career['career_id'], $studentSkillsRaw)
                : null;

            // Traits to strengthen: RIASEC dimensions where this career's
            // profile sits notably higher than the student's own — i.e. not
            // what already matches (that's "Why this match?" above), but
            // what's genuinely underdeveloped relative to what the career
            // actually needs. Only flagged above a threshold so a student
            // isn't told to "develop" a trait over a trivial few-point gap.
            $growthDimensions = [];
            if (!empty($career['career_riasec'])) {
                $gaps = [];
                foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $k) {
                    $gaps[$k] = ($career['career_riasec'][$k] ?? 0) - ($studentRiasecPct[$k] ?? 0);
                }
                arsort($gaps);
                $i = 0;
                foreach ($gaps as $k => $gap) {
                    if ($i >= 2 || $gap < 15) break;
                    $growthDimensions[] = ['type' => $k, 'gap' => $gap, 'career_pct' => $career['career_riasec'][$k], 'student_pct' => $studentRiasecPct[$k] ?? 0];
                    $i++;
                }
            }
            ?>
            <div class="career <?= htmlspecialchars($extraClass) ?>">
                <span class="match"><?= $career['match_score'] ?>% match</span>
                <h3>
                    <?php if (!empty($career['career_id'])): ?>
                        <a href="career_profile.php?id=<?= (int) $career['career_id'] ?>"><?= htmlspecialchars($career['career_title']) ?></a>
                    <?php else: ?>
                        <?= htmlspecialchars($career['career_title']) ?>
                    <?php endif; ?>
                </h3>
                <p><?= htmlspecialchars($career['description']) ?></p>
                <p><strong>Typical tasks:</strong> <?= htmlspecialchars($career['daily_task']) ?></p>
                <p><strong>Educational pathway:</strong> <?= htmlspecialchars($career['educational_pathway']) ?></p>

                <?php if (!empty($career['top_dimensions'])): ?>
                    <div class="why-match">
                        <strong>Why this match?</strong> Your strongest overlap with this career:
                        <div style="margin-top:6px;">
                            <?php foreach ($career['top_dimensions'] as $dim): ?>
                                <span class="dim"><?= $riasecNames[$dim['type']] ?> — Your Score: <?= (int) round($dim['student_pct']) ?>% · Career: <?= (int) round($dim['career_pct']) ?>%</span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($growthDimensions)): ?>
                    <div class="growth-box">
                        <strong>Traits to strengthen</strong> — this career leans more on these than your current profile does:
                        <div style="margin-top:6px;">
                            <?php foreach ($growthDimensions as $dim): ?>
                                <span class="dim growth-dim"><?= $riasecNames[$dim['type']] ?> — Career: <?= (int) round($dim['career_pct']) ?>% · Your Score: <?= (int) round($dim['student_pct']) ?>%</span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($career['key_subjects'])): ?>
                    <div class="subjects-box">
                        <strong>📚 Subjects to focus on:</strong> <?= htmlspecialchars($career['key_subjects']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($skillMatch && $skillMatch['match_percent'] !== null): ?>
                    <div class="skills-box">
                        <span class="pct"><?= $skillMatch['match_percent'] ?>%</span> of this career's required skills match what you listed.
                        <div style="margin-top:6px;">
                            <?php foreach ($skillMatch['matched'] as $s): ?>
                                <span class="skill-tag skill-have">✓ <?= htmlspecialchars($s['skill_name']) ?></span>
                            <?php endforeach; ?>
                            <?php foreach ($skillMatch['missing'] as $s): ?>
                                <span class="skill-tag skill-need">to develop: <?= htmlspecialchars($s['skill_name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($skillMatch): ?>
                    <div class="skills-box" style="color:#888;font-style:italic;">No required-skills list has been set up for this career yet.</div>
                <?php endif; ?>
            </div>
            <?php
        }
    ?>

    <?php if ($dreamCareerData): ?>
        <div class="dream-career-section">
            <h2>🎯 Your Dream Career<?= $dreamCareerData['career_category'] ? ' · ' . htmlspecialchars($dreamCareerData['career_category']) : '' ?></h2>
            <p class="dream-note">This is the career you picked before the assessment — here's how your RIASEC profile actually fits it.</p>
            <?php render_career_card($dreamCareerData, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct, 'dream-career-card'); ?>
        </div>
    <?php endif; ?>

    <?php if ($fieldCareers): ?>
        <div class="field-careers-section">
            <h2>📂 More careers in <?= htmlspecialchars($dreamCareerData['career_category']) ?> (<?= count($fieldCareers) ?>)</h2>
            <p class="dream-note">Other careers in the same field, ranked by how well your RIASEC profile fits each one.</p>
            <?php foreach ($fieldCareers as $career): ?>
                <?php render_career_card($career, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($dreamCareerData && $errorMessage && !$hasMatchResults): ?>
        <p class="inline-note">Other career suggestions aren't available right now (<?= htmlspecialchars($errorMessage) ?>) — your dream career match above was calculated independently, so it's unaffected.</p>
    <?php elseif ($errorMessage && !$hasMatchResults): ?>
        <div class="error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php elseif ($hasMatchResults): ?>
        <?php
            $shownCareerIds = array_map(function ($c) { return $c['career_id']; }, $fieldCareers);
            if ($dreamCareerData) {
                $shownCareerIds[] = $dreamCareerData['career_id'];
            }
            $otherCareers = array_filter($result['recommendations'], function ($c) use ($shownCareerIds) {
                return !in_array((int) ($c['career_id'] ?? 0), $shownCareerIds, true);
            });
        ?>
        <?php if ($otherCareers): ?>
            <details class="explore-others">
                <summary>🔎 Explore other careers outside <?= $dreamCareerData ? 'your field' : 'your pick' ?> that might suit you (<?= count($otherCareers) ?>)</summary>
                <p class="explore-note">These are ranked purely by RIASEC fit and fall outside your chosen field — some may be in completely different industries, worth a look if you're still exploring.</p>
                <?php foreach ($otherCareers as $career): ?>
                    <?php render_career_card($career, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct); ?>
                <?php endforeach; ?>
            </details>
        <?php endif; ?>
    <?php endif; ?>

    <a class="back" href="assessment.php">&larr; Take the assessment again</a>
</body>
</html>
