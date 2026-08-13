<?php
// CareerPath AI - Student assessment history
// Shows every past RIASEC submission the logged-in student made, and the
// ranked careers they were shown for each one (STUDENT_PROFILE +
// RECOMMENDATION entities from the ERD).

require __DIR__ . '/student_auth.php';
require_once __DIR__ . '/skills_helper.php';
$currentStudent = require_student_login();

$pdo = get_db();

$stmt = $pdo->prepare(
    "SELECT * FROM student_profiles WHERE student_id = :student_id ORDER BY submitted_at DESC"
);
$stmt->execute(['student_id' => $currentStudent['student_id']]);
$profiles = $stmt->fetchAll();

$recStmt = $pdo->prepare(
    "SELECT r.match_score, r.rank_position, c.career_id, c.career_title, c.description
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
<title>CareerPath AI — My History</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .empty, body > .notes-panel, body > .submission, body > a.back { max-width: 760px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .empty { color: #666; font-style: italic; }
    .submission { border: 1px solid #ddd; border-radius: 8px; padding: 18px 22px; margin-bottom: 22px; }
    .submission-date { font-size: 13px; color: #666; margin-bottom: 10px; }
    .profile { background: #faf0f1; border-radius: 8px; padding: 10px 16px; margin-bottom: 14px; }
    .profile span { display: inline-block; margin-right: 14px; font-size: 13px; }
    .career-row { display: flex; justify-content: space-between; align-items: baseline; padding: 8px 0; border-top: 1px solid #eee; }
    .career-row:first-of-type { border-top: none; }
    .career-row .title { font-weight: bold; }
    .career-row .title a { color: #6e1423; text-decoration: none; }
    .career-row .title a:hover { text-decoration: underline; }
    .career-row .match { color: #6e1423; font-weight: bold; white-space: nowrap; margin-left: 12px; }
    a.back { display: inline-block; margin-top: 10px; color: #6e1423; }
    .skill-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 11px; }
    .skill-have { background: #d1e7dd; color: #0f5132; }
    .skill-need { background: #fff3cd; color: #856404; }
    .skill-pct { font-size: 12px; color: #666; margin-top: 4px; }
    .notes-panel { background: #faf0f1; border: 1px solid #f0dde1; border-radius: 8px; padding: 16px 20px; margin-bottom: 26px; }
    .notes-panel h2 { margin: 0 0 12px; color: #6e1423; font-size: 17px; }
    .note-item { border-top: 1px solid #f0dde1; padding: 10px 0; font-size: 14px; }
    .note-item:first-of-type { border-top: none; }
    .note-item .note-meta { font-size: 12px; color: #666; margin-bottom: 3px; }
    .note-item .note-career { color: #6e1423; font-weight: bold; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }

    .dream-row { background: #faf0f1; border: 1px solid #6e1423; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; }
    .dream-row .dream-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6e1423; font-weight: bold; margin-bottom: 4px; }
    .field-careers-row { border: 1px solid #eee; border-radius: 8px; padding: 4px 14px 2px; margin-bottom: 12px; }
    .field-careers-row .career-row { padding: 6px 0; }
    .subjects-line { font-size: 12px; color: #666; margin-top: 3px; }
    .explore-others-row { margin-top: 4px; }
    .explore-others-row summary { cursor: pointer; font-weight: bold; color: #6e1423; font-size: 13.5px; padding: 8px 0; }
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

            // Dream career (migration 11 — only set on submissions made after
            // php/assessment.php's cluster -> job picker was added; older
            // submissions have dream_career_id = NULL and just show the flat
            // list below, same as always).
            $dreamCareer = null;
            $fieldCareers = [];
            if (!empty($profile['dream_career_id'])) {
                $dreamCareerStmt->execute(['id' => $profile['dream_career_id']]);
                $dreamCareerRow = $dreamCareerStmt->fetch();
                if ($dreamCareerRow) {
                    $studentVec = ['R' => (float) $profile['r_score'], 'I' => (float) $profile['i_score'], 'A' => (float) $profile['a_score'], 'S' => (float) $profile['s_score'], 'E' => (float) $profile['e_score'], 'C' => (float) $profile['c_score']];
                    $careerVec = ['R' => $dreamCareerRow['r_score'] / 100, 'I' => $dreamCareerRow['i_score'] / 100, 'A' => $dreamCareerRow['a_score'] / 100, 'S' => $dreamCareerRow['s_score'] / 100, 'E' => $dreamCareerRow['e_score'] / 100, 'C' => $dreamCareerRow['c_score'] / 100];
                    $dreamCareer = [
                        'career_id' => (int) $dreamCareerRow['career_id'],
                        'career_title' => $dreamCareerRow['career_title'],
                        'career_category' => $dreamCareerRow['career_category'],
                        'key_subjects' => $dreamCareerRow['key_subjects'] ?? null,
                        'match_score' => cosine_similarity_riasec_history($studentVec, $careerVec),
                    ];

                    if ($dreamCareerRow['career_category']) {
                        $fieldCareersStmt->execute(['category' => $dreamCareerRow['career_category'], 'dream_id' => $dreamCareer['career_id']]);
                        foreach ($fieldCareersStmt->fetchAll() as $row) {
                            $rowVec = ['R' => $row['r_score'] / 100, 'I' => $row['i_score'] / 100, 'A' => $row['a_score'] / 100, 'S' => $row['s_score'] / 100, 'E' => $row['e_score'] / 100, 'C' => $row['c_score'] / 100];
                            $fieldCareers[] = [
                                'career_id' => (int) $row['career_id'],
                                'career_title' => $row['career_title'],
                                'key_subjects' => $row['key_subjects'] ?? null,
                                'match_score' => cosine_similarity_riasec_history($studentVec, $rowVec),
                            ];
                        }
                        usort($fieldCareers, function ($a, $b) { return $b['match_score'] <=> $a['match_score']; });
                    }
                }
            }
            $shownCareerIds = array_map(function ($c) { return $c['career_id']; }, $fieldCareers);
            if ($dreamCareer) {
                $shownCareerIds[] = $dreamCareer['career_id'];
            }
            $otherRecommendations = array_filter($recommendations, function ($rec) use ($shownCareerIds) {
                return !in_array((int) $rec['career_id'], $shownCareerIds, true);
            });
        ?>
        <div class="submission">
            <div class="submission-date">
                Submitted <?= htmlspecialchars($profile['submitted_at']) ?>
                · <a href="assessment_report.php?profile_id=<?= (int) $profile['profile_id'] ?>" target="_blank">Print / Save as PDF</a>
            </div>

            <div class="profile">
                <span><strong>R:</strong> <?= number_format($profile['r_score'] * 100, 0) ?>%</span>
                <span><strong>I:</strong> <?= number_format($profile['i_score'] * 100, 0) ?>%</span>
                <span><strong>A:</strong> <?= number_format($profile['a_score'] * 100, 0) ?>%</span>
                <span><strong>S:</strong> <?= number_format($profile['s_score'] * 100, 0) ?>%</span>
                <span><strong>E:</strong> <?= number_format($profile['e_score'] * 100, 0) ?>%</span>
                <span><strong>C:</strong> <?= number_format($profile['c_score'] * 100, 0) ?>%</span>
                <?php if (!empty($profile['academic_average'])): ?>
                    <span><strong>Academic average:</strong> <?= number_format($profile['academic_average'], 2) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($dreamCareer): ?>
                <div class="dream-row">
                    <div class="dream-label">🎯 Dream Career<?= $dreamCareer['career_category'] ? ' · ' . htmlspecialchars($dreamCareer['career_category']) : '' ?></div>
                    <div style="display:flex;justify-content:space-between;align-items:baseline;">
                        <span class="title"><a href="career_profile.php?id=<?= $dreamCareer['career_id'] ?>"><?= htmlspecialchars($dreamCareer['career_title']) ?></a></span>
                        <span class="match"><?= number_format($dreamCareer['match_score'], 0) ?>% match</span>
                    </div>
                    <?php if (!empty($dreamCareer['key_subjects'])): ?>
                        <div class="subjects-line">📚 Subjects to focus on: <?= htmlspecialchars($dreamCareer['key_subjects']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($fieldCareers): ?>
                <div class="field-careers-row">
                    <div class="dream-label" style="color:#888;">📂 More in <?= htmlspecialchars($dreamCareer['career_category']) ?> (<?= count($fieldCareers) ?>)</div>
                    <?php foreach ($fieldCareers as $fc): ?>
                        <div class="career-row" style="flex-direction:column;align-items:stretch;">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;">
                                <span class="title"><a href="career_profile.php?id=<?= $fc['career_id'] ?>"><?= htmlspecialchars($fc['career_title']) ?></a></span>
                                <span class="match"><?= number_format($fc['match_score'], 0) ?>% match</span>
                            </div>
                            <?php if (!empty($fc['key_subjects'])): ?>
                                <div class="subjects-line">📚 <?= htmlspecialchars($fc['key_subjects']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$otherRecommendations): ?>
                <?php if (!$dreamCareer): ?>
                    <p class="empty">No recommendations were saved for this submission.</p>
                <?php endif; ?>
            <?php else: ?>
                <?php
                    $othersMarkup = function () use ($otherRecommendations, $pdo, $profile) {
                        foreach ($otherRecommendations as $rec) {
                            $skillMatch = compute_skill_match($pdo, (int) $rec['career_id'], $profile['skills'] ?? null);
                            ?>
                            <div class="career-row" style="flex-direction:column;align-items:stretch;">
                                <div style="display:flex;justify-content:space-between;align-items:baseline;">
                                    <span class="title"><a href="career_profile.php?id=<?= (int) $rec['career_id'] ?>"><?= htmlspecialchars($rec['career_title']) ?></a></span>
                                    <span class="match"><?= number_format($rec['match_score'], 0) ?>% match</span>
                                </div>
                                <?php if ($skillMatch['match_percent'] !== null): ?>
                                    <div class="skill-pct">
                                        <?= $skillMatch['match_percent'] ?>% skills match —
                                        <?php foreach ($skillMatch['matched'] as $s): ?>
                                            <span class="skill-tag skill-have">✓ <?= htmlspecialchars($s['skill_name']) ?></span>
                                        <?php endforeach; ?>
                                        <?php foreach ($skillMatch['missing'] as $s): ?>
                                            <span class="skill-tag skill-need"><?= htmlspecialchars($s['skill_name']) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php
                        }
                    };
                ?>
                <?php if ($dreamCareer): ?>
                    <details class="explore-others-row">
                        <summary>🔎 Explore other careers from this submission (<?= count($otherRecommendations) ?>)</summary>
                        <?php $othersMarkup(); ?>
                    </details>
                <?php else: ?>
                    <?php $othersMarkup(); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <a class="back" href="assessment.php">&larr; Take the assessment again</a>
</body>
</html>
