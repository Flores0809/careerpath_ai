<?php
// CareerPath AI - Student assessment history
// Shows every past RIASEC submission the logged-in student made, and the
// ranked careers they were shown for each one (STUDENT_PROFILE +
// RECOMMENDATION entities from the ERD).

require __DIR__ . '/student_auth.php';
require_once __DIR__ . '/skills_helper.php';
require_once __DIR__ . '/riasec_helper.php';
$currentStudent = require_student_login();

$pdo = get_db();

$stmt = $pdo->prepare(
    "SELECT * FROM student_profiles WHERE student_id = :student_id ORDER BY submitted_at DESC"
);
$stmt->execute(['student_id' => $currentStudent['student_id']]);
$profiles = $stmt->fetchAll();

$recStmt = $pdo->prepare(
    "SELECT r.match_score, r.rank_position, c.career_id, c.career_title, c.description, c.key_subjects
     FROM recommendations r
     JOIN careers c ON c.career_id = r.career_id
     WHERE r.profile_id = :profile_id
     ORDER BY r.rank_position ASC"
);

// Notes/outcomes a counselor recorded for this student (students_lookup.php).
// Only actual notes are shown here — the automatic 'viewed_profile' audit
// entries stay internal to staff, since they're not meant for the student.
$notesStmt = $pdo->prepare(
    "SELECT cl.notes, cl.created_at, u.name AS counselor_name, c.career_title
     FROM counselor_log cl
     JOIN users u ON u.user_id = cl.counselor_id
     LEFT JOIN recommendations r ON r.recommendation_id = cl.recommendation_id
     LEFT JOIN careers c ON c.career_id = r.career_id
     WHERE cl.student_id = :student_id AND cl.action = 'recorded_outcome' AND cl.notes IS NOT NULL
     ORDER BY cl.created_at DESC"
);
$notesStmt->execute(['student_id' => $currentStudent['student_id']]);
$counselorNotes = $notesStmt->fetchAll();

$dreamCareerStmt = $pdo->prepare("SELECT career_id, career_title, career_category, key_subjects, r_score, i_score, a_score, s_score, e_score, c_score FROM careers WHERE career_id = :id");

// Every other active career in the dream career's own field/industry cluster
// — same idea as submit.php: the student picked one job, but the point of
// grouping by field is to show the whole field, not just that one title.
$fieldCareersStmt = $pdo->prepare(
    "SELECT * FROM careers WHERE status = 'active' AND career_category = :category AND career_id != :dream_id"
);

// Mean-centered cosine similarity (Pearson correlation between the two
// profiles' shapes), rescaled to 0-100 — same formula as submit.php's
// cosine_similarity_riasec() and matching-service/app.py's
// profile_similarity(). Plain cosine similarity on raw 0-1 RIASEC vectors
// scored almost everything 90%+ since every vector lives in the same
// all-positive octant of 6D space; centering each profile before comparing
// scores its shape (relatively high/low traits) instead of raw magnitude.
function cosine_similarity_riasec_history(array $a, array $b): float
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
        return 50.0;
    }
    $r = $dot / (sqrt($normA) * sqrt($normB));
    return ($r + 1) / 2 * 100;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — My History</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .empty, body > .notes-panel, body > .submission, body > a.back { max-width: 760px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .empty { color: #666; font-style: italic; }
    .submission { background: #f5f5f5; border: 1px solid #ddd; border-radius: 8px; padding: 18px 22px; margin-bottom: 22px; }
    .submission-date { font-size: 14.5px; color: #666; margin-bottom: 10px; }
    .profile { background: #faf0f1; border-radius: 8px; padding: 10px 16px; margin-bottom: 14px; white-space: nowrap; overflow-x: auto; }
    .profile span { display: inline-block; margin-right: 12px; font-size: 14px; }
    /* On mobile the 6 RIASEC stats + academic average don't fit on one line,
       so the nowrap+scroll above just clipped the last item at the screen
       edge (e.g. "Investigative (I): 82% A|") with no visual hint that more
       was scrollable off-screen -- read as a cut-off/broken page rather than
       a swipeable row. These are short, self-contained "Label: NN%" pairs
       (unlike a wide data table), so wrapping them onto a couple of lines
       reads fine and needs no swipe gesture at all. */
    @media (max-width: 600px) {
        .profile { white-space: normal; overflow-x: visible; }
        .profile span { margin-bottom: 6px; }
    }
    .scoring-card { background: #faf0f1; border-radius: 8px; padding: 14px 18px; margin-bottom: 14px; }
    .scoring-title { font-weight: bold; color: #6e1423; font-size: 15.5px; margin-bottom: 6px; }
    .scoring-explain { font-size: 13.5px; color: #555; line-height: 1.5; margin: 0 0 12px; }
    .scoring-row { display: flex; align-items: center; gap: 10px; margin-bottom: 7px; }
    .scoring-label { flex: 0 0 150px; font-size: 13.5px; color: #333; }
    .scoring-track { flex: 1; background: #eddadd; border-radius: 6px; height: 12px; overflow: hidden; }
    .scoring-fill { display: block; height: 100%; border-radius: 6px; background: linear-gradient(90deg, #6e1423, #b3465c); }
    .scoring-pts { flex: 0 0 90px; text-align: right; font-size: 13px; color: #6e1423; white-space: nowrap; }
    .scoring-academic { margin-top: 10px; padding-top: 10px; border-top: 1px solid #e3c9cd; font-size: 13.5px; color: #555; }
    @media (max-width: 480px) { .scoring-label { flex-basis: 96px; font-size: 12px; } .scoring-pts { flex-basis: 74px; font-size: 12px; } }
    .career-row { display: flex; justify-content: space-between; align-items: baseline; padding: 8px 0; border-top: 1px solid #eee; }
    .career-row:first-of-type { border-top: none; }
    .career-row .title { font-weight: bold; }
    .career-row .title a { color: #6e1423; text-decoration: none; }
    .career-row .title a:hover { text-decoration: underline; }
    .career-row .match { color: #6e1423; font-weight: bold; white-space: nowrap; margin-left: 12px; }
    a.back { display: inline-block; margin-top: 10px; color: #6e1423; }
    .skill-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 12.5px; }
    .skill-have { background: #d1e7dd; color: #0f5132; }
    .skill-need { background: #fff3cd; color: #856404; }
    .skill-needed { background: #f0dde1; color: #6e1423; }
    .skill-pct { font-size: 13.5px; color: #666; margin-top: 4px; }
    .skill-pct .skills-good { display: block; background: #e8f5ec; border: 1px solid #b9dfc6; color: #0f5132; border-radius: 8px; padding: 8px 12px; font-weight: bold; line-height: 1.5; }
    .notes-panel { background: #faf0f1; border: 1px solid #f0dde1; border-radius: 8px; padding: 16px 20px; margin-bottom: 26px; }
    .notes-panel h2 { margin: 0 0 12px; color: #6e1423; font-size: 18.5px; }
    .note-item { border-top: 1px solid #f0dde1; padding: 10px 0; font-size: 15.5px; }
    .note-item:first-of-type { border-top: none; }
    .note-item .note-meta { font-size: 13.5px; color: #666; margin-bottom: 3px; }
    .note-item .note-career { color: #6e1423; font-weight: bold; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }

    .dream-row { background: #faf0f1; border: 1px solid #6e1423; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; }
    .dream-row .dream-label { font-size: 12.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #6e1423; font-weight: bold; margin-bottom: 4px; }
    .compare-verdict { background: #faf0f1; border: 1px solid #e3c9cd; border-radius: 8px; padding: 10px 14px; margin-bottom: 10px; font-size: 14px; line-height: 1.5; }
    .compare-grid { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 12px; }
    .compare-grid > .dream-row { flex: 1 1 0; min-width: 0; margin-bottom: 0; }
    @media (max-width: 700px) { .compare-grid { flex-direction: column; align-items: stretch; } }
    .field-careers-row { border: 1px solid #eee; border-radius: 8px; padding: 4px 14px 2px; margin-bottom: 12px; }
    .field-careers-row .career-row { padding: 6px 0; }
    .subjects-line { font-size: 13.5px; color: #666; margin-top: 3px; }
    .ai-insights-box { background: #f3edfb; border: 1px solid #e2d4f5; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; font-size: 14px; color: #444; }
    .ai-insights-box p { margin: 0; line-height: 1.6; }
    .ai-insights-box-inline { margin: 10px 0 0; }
    .ai-insights-label { font-weight: bold; color: #6f42c1; font-size: 13px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .ai-suggestion-tag { background: #fff3cd; color: #856404; border: 1px solid #ffe69c; border-radius: 10px; padding: 2px 9px; font-size: 11.5px; font-weight: bold; cursor: help; }
    .explore-others-row { margin-top: 4px; }
    .explore-others-row summary { cursor: pointer; font-weight: bold; color: #6e1423; font-size: 15px; padding: 8px 0; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1>My Assessment History</h1>

    <?php if ($counselorNotes): ?>
        <div class="notes-panel">
            <h2>Notes from Your Counselor</h2>
            <?php foreach ($counselorNotes as $note): ?>
                <div class="note-item">
                    <div class="note-meta">
                        <?= htmlspecialchars($note['counselor_name']) ?> ·
                        <?= htmlspecialchars($note['created_at']) ?>
                        <?php if ($note['career_title']): ?>
                            · about <span class="note-career"><?= htmlspecialchars($note['career_title']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div><?= nl2br(htmlspecialchars($note['notes'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$profiles): ?>
        <p class="empty">You haven't taken the assessment yet. <a href="assessment.php">Take it now</a>.</p>
    <?php endif; ?>

    <?php foreach ($profiles as $profile): ?>
        <?php
            $recStmt->execute(['profile_id' => $profile['profile_id']]);
            $recommendations = $recStmt->fetchAll();

            // Optional dream career: scored live (same math as the results page)
            // and shown beside the system's best match (rank 1) as a comparison.
            // Submissions made without one just show the ranked list as always.
            $studentVec = ['R' => (float) $profile['r_score'], 'I' => (float) $profile['i_score'], 'A' => (float) $profile['a_score'], 'S' => (float) $profile['s_score'], 'E' => (float) $profile['e_score'], 'C' => (float) $profile['c_score']];
            $dreamData = load_dream_career($pdo, $profile['dream_career_id'] ?? 0, $studentVec);
            $dreamCareer = null;
            $bestRec = $recommendations[0] ?? null;
            if ($dreamData && $bestRec) {
                $dreamCareer = [
                    'career_id' => (int) $dreamData['row']['career_id'],
                    'career_title' => $dreamData['row']['career_title'],
                    'career_category' => $dreamData['row']['career_category'],
                    'key_subjects' => $dreamData['row']['key_subjects'] ?? null,
                    'match_score' => $dreamData['match_score'],
                ];
            }
            // With a comparison, the best match is shown in the comparison block,
            // so the list below only carries the remaining careers.
            $otherRecommendations = $dreamCareer ? array_slice($recommendations, 1) : $recommendations;
        ?>
        <div class="submission">
            <div class="submission-date">
                Submitted <?= htmlspecialchars($profile['submitted_at']) ?>
                · <a href="assessment_report.php?profile_id=<?= (int) $profile['profile_id'] ?>" target="_blank">Print / Save as PDF</a>
            </div>

            <div class="scoring-card">
                <div class="scoring-title">How your RIASEC profile was scored</div>
                <p class="scoring-explain">Each of the six RIASEC types has 10 statements, and you rated each from 1 (Strongly Disagree) to 4 (Strongly Agree). Your score for a type is the total of your 10 ratings divided by the highest possible total (40), shown as a percentage. These scores are then compared with every career's own RIASEC profile to produce the match percentages below.</p>
                <?php foreach (['R' => ['Realistic', 'r_score'], 'I' => ['Investigative', 'i_score'], 'A' => ['Artistic', 'a_score'], 'S' => ['Social', 's_score'], 'E' => ['Enterprising', 'e_score'], 'C' => ['Conventional', 'c_score']] as $letter => [$typeName, $col]): ?>
                    <?php $typePct = (int) round($profile[$col] * 100); $typePoints = (int) round($profile[$col] * 40); ?>
                    <div class="scoring-row">
                        <span class="scoring-label"><?= $typeName ?> (<?= $letter ?>)</span>
                        <div class="scoring-track"><span class="scoring-fill" style="width: <?= $typePct ?>%;"></span></div>
                        <span class="scoring-pts"><?= $typePoints ?>/40 · <strong><?= $typePct ?>%</strong></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!empty($profile['academic_average'])): ?>
                    <div class="scoring-academic">Academic average you entered: <strong><?= number_format($profile['academic_average'], 2) ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($profile['skills'])): ?>
                <div class="profile" style="margin-top:-8px;">
                    <span><strong>Skills listed:</strong> <?= htmlspecialchars(implode(', ', array_map('trim', explode(',', $profile['skills'])))) ?></span>
                </div>
            <?php endif; ?>


            <?php if ($dreamCareer): ?>
                <?php $sameCareer = (int) $dreamCareer['career_id'] === (int) $bestRec['career_id']; ?>
                <div class="compare-verdict">
                    <strong>Your results vs. your chosen industry.</strong>
                    <?= htmlspecialchars(dream_comparison_text($bestRec['career_title'], (float) $bestRec['match_score'], $dreamCareer['career_title'], (float) $dreamCareer['match_score'], (int) $dreamData['rank'], (int) $dreamData['total'], $sameCareer)) ?>
                </div>
                <div class="compare-grid">
                    <div class="dream-row">
                        <div class="dream-label">🎯 What best suits you</div>
                        <div style="display:flex;justify-content:space-between;align-items:baseline;">
                            <span class="title"><a href="career_profile.php?id=<?= (int) $bestRec['career_id'] ?>"><?= htmlspecialchars($bestRec['career_title']) ?></a></span>
                            <span class="match"><?= number_format($bestRec['match_score'], 0) ?>% match</span>
                        </div>
                        <?php if (!empty($bestRec['key_subjects'])): ?>
                            <div class="subjects-line">📚 Subjects to focus on: <?= htmlspecialchars($bestRec['key_subjects']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($profile['ai_career_commentary'])): ?>
                            <div class="ai-insights-box ai-insights-box-inline">
                                <div class="ai-insights-label">
                                    ✨ AI Insights
                                    <?php if (!empty($profile['ai_skills_are_suggested'])): ?>
                                        <span class="ai-suggestion-tag" title="This career doesn't have a counselor-verified skills list yet, so any skills mentioned here are general AI suggestions, not a verified requirement.">AI-suggested skills</span>
                                    <?php endif; ?>
                                </div>
                                <p><?= htmlspecialchars($profile['ai_career_commentary']) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="dream-row">
                        <div class="dream-label">💭 Your chosen industry<?= $dreamCareer['career_category'] ? ' · ' . htmlspecialchars($dreamCareer['career_category']) : '' ?></div>
                        <div style="display:flex;justify-content:space-between;align-items:baseline;">
                            <span class="title"><a href="career_profile.php?id=<?= $dreamCareer['career_id'] ?>"><?= htmlspecialchars($dreamCareer['career_title']) ?></a></span>
                            <span class="match"><?= number_format($dreamCareer['match_score'], 0) ?>% match</span>
                        </div>
                        <?php if (!empty($dreamCareer['key_subjects'])): ?>
                            <div class="subjects-line">📚 Subjects to focus on: <?= htmlspecialchars($dreamCareer['key_subjects']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($profile['ai_dream_commentary']) && !$sameCareer): ?>
                            <div class="ai-insights-box ai-insights-box-inline">
                                <div class="ai-insights-label">✨ AI Insights</div>
                                <p><?= htmlspecialchars($profile['ai_dream_commentary']) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$otherRecommendations): ?>
                <?php if (!$dreamCareer): ?>
                    <p class="empty">No recommendations were saved for this submission.</p>
                <?php endif; ?>
            <?php else: ?>
                <?php
                    $othersMarkup = function () use ($otherRecommendations, $pdo, $profile, $dreamCareer) {
                        $isFirstRow = true;
                        foreach ($otherRecommendations as $rec) {
                            $skillMatch = compute_skill_match($pdo, (int) $rec['career_id'], $profile['skills'] ?? null);
                            ?>
                            <div class="career-row" style="flex-direction:column;align-items:stretch;">
                                <div style="display:flex;justify-content:space-between;align-items:baseline;">
                                    <span class="title"><a href="career_profile.php?id=<?= (int) $rec['career_id'] ?>"><?= htmlspecialchars($rec['career_title']) ?></a></span>
                                    <span class="match"><?= number_format($rec['match_score'], 0) ?>% match</span>
                                </div>
                                <?php if ($isFirstRow && !$dreamCareer && !empty($profile['ai_career_commentary'])): ?>
                                    <div class="ai-insights-box ai-insights-box-inline">
                                        <div class="ai-insights-label">
                                            ✨ AI Insights — your best match
                                            <?php if (!empty($profile['ai_skills_are_suggested'])): ?>
                                                <span class="ai-suggestion-tag" title="This career doesn't have a counselor-verified skills list yet, so any skills mentioned here are general AI suggestions, not a verified requirement.">AI-suggested skills</span>
                                            <?php endif; ?>
                                        </div>
                                        <p><?= htmlspecialchars($profile['ai_career_commentary']) ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($skillMatch['required_count'] > 0): ?>
                                    <?php $skillMsg = skill_match_message($skillMatch); ?>
                                    <div class="skill-pct">
                                        <?php if ($skillMsg['text'] !== ''): ?><span class="skills-good"><?= htmlspecialchars($skillMsg['text']) ?></span><?php endif; ?>
                                        <div style="margin-top:8px;"><strong><?= htmlspecialchars($skillMsg['label']) ?></strong></div>
                                        <div style="margin-top:6px;">
                                        <?php foreach ($skillMatch['matched'] as $s): ?>
                                            <span class="skill-tag skill-have">✓ <?= htmlspecialchars($s['skill_name']) ?></span>
                                        <?php endforeach; ?>
                                        <?php foreach ($skillMatch['missing'] as $s): ?>
                                            <span class="skill-tag skill-needed"><?= htmlspecialchars($s['skill_name']) ?></span>
                                        <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php
                            $isFirstRow = false;
                        }
                    };
                ?>
                <?php if ($dreamCareer): ?>
                    <div class="dream-label" style="color:#888;margin:6px 0;">More careers that fit you (<?= count($otherRecommendations) ?>)</div>
                <?php endif; ?>
                <?php $othersMarkup(); ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <a class="back" href="assessment.php">&larr; Take the assessment again</a>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
