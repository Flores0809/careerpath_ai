<?php
// CareerPath AI - Student Lookup (counselors & administrators)
//
// Lets staff search for a student by ID, name, or email, and view that
// student's full assessment history (RIASEC scores + careers recommended
// per submission) — the "counselor connected to student accounts" feature.
// Every profile view is logged to counselor_log (the COUNSELOR_LOG entity
// from the paper's ERD) for later auditing of who looked at what.

require __DIR__ . '/auth.php';
require_once __DIR__ . '/skills_helper.php';
require_once __DIR__ . '/notifications_helper.php';
$currentUser = require_role(['administrator', 'counselor']);

$pdo = get_db();
$noteMessage = null;

// Same mean-centered cosine similarity used by submit.php/student_history.php
// — needed here so a student's "dream career" fit still shows to staff even
// when the Flask matching service was unavailable at submission time (that
// case only skips the flat `recommendations` table rows, not the dream
// career, which is computed live from student_profiles.dream_career_id).
function cosine_similarity_riasec_lookup(array $a, array $b): float
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

// Counselor final-outcome recording — the paper's System Flowchart (Figure
// 10) ends the counselor/admin track with "Counselor Reviews, Records &
// Submits Final Outcome," which is more than the automatic 'viewed_profile'
// audit entries below. This lets a counselor attach an actual note/decision
// to a student, optionally tied to a specific recommendation.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_outcome') {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $recommendationId = (int) ($_POST['recommendation_id'] ?? 0) ?: null;
    $notes = trim($_POST['notes'] ?? '');

    if ($studentId && $notes !== '') {
        $stmt = $pdo->prepare(
            "INSERT INTO counselor_log (counselor_id, student_id, recommendation_id, action, notes)
             VALUES (:counselor_id, :student_id, :recommendation_id, 'recorded_outcome', :notes)"
        );
        $stmt->execute([
            'counselor_id' => $currentUser['user_id'],
            'student_id' => $studentId,
            'recommendation_id' => $recommendationId,
            'notes' => $notes,
        ]);

        // Also surface this as a real, mark-as-readable notification (tagged
        // 'counselor_note') instead of the student only finding out via the
        // "Notes from Your Counselor" box on their History page — that box
        // has no read state, so it used to nag forever on the dashboard.
        $noteCareerTitle = null;
        if ($recommendationId) {
            $careerStmt = $pdo->prepare(
                "SELECT c.career_title FROM recommendations r
                 JOIN careers c ON c.career_id = r.career_id
                 WHERE r.recommendation_id = :id"
            );
            $careerStmt->execute(['id' => $recommendationId]);
            $noteCareerTitle = $careerStmt->fetchColumn() ?: null;
        }
        $noteNotificationMessage = $noteCareerTitle
            ? "Your counselor left a note about \"$noteCareerTitle\"."
            : 'Your counselor left you a note.';
        notify_student($pdo, $studentId, $noteNotificationMessage, 'student_history.php', 'counselor_note');

        $noteMessage = ['type' => 'success', 'text' => 'Outcome recorded.'];
        $_GET['view'] = $studentId; // re-show this student's profile after redirect-less POST
    } else {
        $noteMessage = ['type' => 'error', 'text' => 'Please enter a note before saving.'];
        $_GET['view'] = $studentId;
    }
}

// The roster always loads in full (no query required) so staff can browse
// every student account at a glance; the search box below is now a live,
// client-side filter over this already-loaded list rather than a page
// reload, since the full roster is small enough to render at once.
// Distinct grade levels currently in use, for the grade-level filter
// dropdown next to the search box — built from real data rather than a
// hardcoded list, so it always matches whatever values students/counselors
// have actually entered (free text on student_register.php/student_profile.php).
$gradeLevelOptions = $pdo->query(
    "SELECT DISTINCT grade_level FROM students WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level"
)->fetchAll(PDO::FETCH_COLUMN);

$students = $pdo->query(
    "SELECT s.*, COUNT(sp.profile_id) AS submission_count
     FROM students s
     LEFT JOIN student_profiles sp ON sp.student_id = s.student_id
     GROUP BY s.student_id
     ORDER BY s.name"
)->fetchAll();

$viewedStudent = null;
$viewedProfiles = [];
$viewError = null;

if (!empty($_GET['view'])) {
    $viewId = (int) $_GET['view'];

    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = :id");
    $stmt->execute(['id' => $viewId]);
    $viewedStudent = $stmt->fetch();

    if (!$viewedStudent) {
        $viewError = 'That student account could not be found.';
    } else {
        // Log this view — COUNSELOR_LOG entity from the ERD (Chapter III, Figure 11).
        $logStmt = $pdo->prepare(
            "INSERT INTO counselor_log (counselor_id, student_id, action) VALUES (:counselor_id, :student_id, 'viewed_profile')"
        );
        $logStmt->execute(['counselor_id' => $currentUser['user_id'], 'student_id' => $viewId]);

        $profileStmt = $pdo->prepare(
            "SELECT * FROM student_profiles WHERE student_id = :id ORDER BY submitted_at DESC"
        );
        $profileStmt->execute(['id' => $viewId]);
        $viewedProfiles = $profileStmt->fetchAll();

        $recStmt = $pdo->prepare(
            "SELECT r.recommendation_id, r.match_score, c.career_id, c.career_title
             FROM recommendations r
             JOIN careers c ON c.career_id = r.career_id
             WHERE r.profile_id = :profile_id
             ORDER BY r.rank_position ASC"
        );
        $dreamCareerStmt = $pdo->prepare("SELECT career_id, career_title, career_category, key_subjects, r_score, i_score, a_score, s_score, e_score, c_score FROM careers WHERE career_id = :id");
        $fieldCareersStmt = $pdo->prepare(
            "SELECT * FROM careers WHERE status = 'active' AND career_category = :category AND career_id != :dream_id"
        );
        $allRecommendations = []; // flat list across all profiles, for the "attach note to" dropdown
        foreach ($viewedProfiles as &$profile) {
            $recStmt->execute(['profile_id' => $profile['profile_id']]);
            $profile['recommendations'] = $recStmt->fetchAll();
            foreach ($profile['recommendations'] as &$rec) {
                $rec['skill_match'] = compute_skill_match($pdo, (int) $rec['career_id'], $profile['skills'] ?? null);
                $allRecommendations[] = $rec;
            }
            unset($rec);

            // Dream career (migration 11) — computed live from
            // student_profiles.dream_career_id, same as student_history.php,
            // so it still shows here even on a submission where the Flask
            // matching service was down and no `recommendations` rows exist.
            $profile['dream_career'] = null;
            $profile['field_careers'] = [];
            if (!empty($profile['dream_career_id'])) {
                $dreamCareerStmt->execute(['id' => $profile['dream_career_id']]);
                $dreamCareerRow = $dreamCareerStmt->fetch();
                if ($dreamCareerRow) {
                    $studentVec = ['R' => (float) $profile['r_score'], 'I' => (float) $profile['i_score'], 'A' => (float) $profile['a_score'], 'S' => (float) $profile['s_score'], 'E' => (float) $profile['e_score'], 'C' => (float) $profile['c_score']];
                    $careerVec = ['R' => $dreamCareerRow['r_score'] / 100, 'I' => $dreamCareerRow['i_score'] / 100, 'A' => $dreamCareerRow['a_score'] / 100, 'S' => $dreamCareerRow['s_score'] / 100, 'E' => $dreamCareerRow['e_score'] / 100, 'C' => $dreamCareerRow['c_score'] / 100];
                    $profile['dream_career'] = [
                        'career_id' => (int) $dreamCareerRow['career_id'],
                        'career_title' => $dreamCareerRow['career_title'],
                        'career_category' => $dreamCareerRow['career_category'],
                        'key_subjects' => $dreamCareerRow['key_subjects'] ?? null,
                        'match_score' => cosine_similarity_riasec_lookup($studentVec, $careerVec),
                    ];

                    if ($dreamCareerRow['career_category']) {
                        $fieldCareersStmt->execute(['category' => $dreamCareerRow['career_category'], 'dream_id' => $profile['dream_career']['career_id']]);
                        foreach ($fieldCareersStmt->fetchAll() as $row) {
                            $rowVec = ['R' => $row['r_score'] / 100, 'I' => $row['i_score'] / 100, 'A' => $row['a_score'] / 100, 'S' => $row['s_score'] / 100, 'E' => $row['e_score'] / 100, 'C' => $row['c_score'] / 100];
                            $profile['field_careers'][] = [
                                'career_id' => (int) $row['career_id'],
                                'career_title' => $row['career_title'],
                                'key_subjects' => $row['key_subjects'] ?? null,
                                'match_score' => cosine_similarity_riasec_lookup($studentVec, $rowVec),
                            ];
                        }
                        usort($profile['field_careers'], function ($a, $b) { return $b['match_score'] <=> $a['match_score']; });
                    }
                }
            }
        }
        unset($profile);

        // Counselor-recorded outcomes/notes for this student (task: final
        // outcome recording), plus the raw view-audit trail, newest first.
        $logStmt = $pdo->prepare(
            "SELECT cl.*, u.name AS counselor_name
             FROM counselor_log cl
             JOIN users u ON u.user_id = cl.counselor_id
             WHERE cl.student_id = :id
             ORDER BY cl.created_at DESC
             LIMIT 50"
        );
        $logStmt->execute(['id' => $viewId]);
        $counselorLogEntries = $logStmt->fetchAll();
        $recordedNoteCount = count(array_filter(
            $counselorLogEntries,
            fn($e) => $e['action'] === 'recorded_outcome' && !empty($e['notes'])
        ));
    }
}

$riasecLabels = ['r_score' => 'Realistic (R)', 'i_score' => 'Investigative (I)', 'a_score' => 'Artistic (A)', 's_score' => 'Social (S)', 'e_score' => 'Enterprising (E)', 'c_score' => 'Conventional (C)'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — Student Lookup</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; padding: 40px 20px; color: #222; background: #faf7f5; }
    .wrap { max-width: 1280px; margin: 0 auto; }
    h1 { color: #6e1423; margin-bottom: 4px; }
    .subtitle { color: #666; margin-top: 0; margin-bottom: 24px; }

    .panel { background: #f5f5f5; border-radius: 12px; box-shadow: 0 4px 16px rgba(74,12,23,0.08); padding: 22px 26px; margin-bottom: 20px; }

    /* Search bar — same pill + icon style used on users.php / careers.php */
    .search-bar { position: relative; max-width: 340px; margin: 0 0 4px; }
    .search-bar input { width: 100%; padding: 9px 14px 9px 32px; border: 1px solid #ccc; border-radius: 20px; font-size: 15.5px; box-sizing: border-box; }
    .search-bar input:focus { outline: none; border-color: #6e1423; box-shadow: 0 0 0 2px rgba(110,20,35,0.12); }
    .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14.5px; opacity: 0.55; pointer-events: none; }

    table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 15.5px; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
    th { color: #555; font-size: 13.5px; text-transform: uppercase; }
    .status-active { color: #0f5132; }
    .status-disabled { color: #b02a37; }
    a.view-link { color: #6e1423; font-weight: bold; text-decoration: none; }
    a.view-link:hover { text-decoration: underline; }

    .empty { color: #888; font-style: italic; font-size: 15.5px; }
    .error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }

    .student-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .student-header h2 { margin: 0; color: #6e1423; }
    .student-meta { font-size: 14.5px; color: #666; }
    a.back { color: #6e1423; font-size: 14.5px; text-decoration: none; }
    a.back:hover { text-decoration: underline; }

    .submission { border: 1px solid #eee; border-radius: 8px; padding: 16px 18px; margin-top: 14px; }
    .submission-date { font-size: 14.5px; color: #666; margin-bottom: 10px; }
    .riasec-row span { display: inline-block; margin-right: 14px; font-size: 14.5px; background: #faf0f1; padding: 3px 8px; border-radius: 6px; }
    .career-row { padding: 6px 0; border-top: 1px solid #f2f2f2; font-size: 15.5px; }
    .career-row:first-of-type { border-top: none; }
    .career-row .top-line, .dream-row .top-line { display: flex; justify-content: space-between; }
    .dream-row .top-line a, .field-careers-row a { color: #6e1423; text-decoration: none; font-weight: bold; }
    .dream-row .top-line a:hover, .field-careers-row a:hover { text-decoration: underline; }
    .career-row .match { color: #6e1423; font-weight: bold; }
    .skill-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 12.5px; }
    .skill-have { background: #d1e7dd; color: #0f5132; }
    .skill-need { background: #fff3cd; color: #856404; }
    .skill-pct { font-size: 13.5px; color: #666; margin-top: 4px; }
    .note-entry { border-top: 1px solid #f2f2f2; padding: 10px 0; font-size: 14.5px; }
    .note-entry:first-of-type { border-top: none; }
    .note-entry .note-meta { color: #888; font-size: 13.5px; margin-bottom: 3px; }
    .note-entry.audit-only { color: #aaa; font-style: italic; }
    .note-form textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; min-height: 60px; }
    .note-form select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; margin-bottom: 8px; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 15.5px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 15.5px; }
    .notes-toggle { list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; background: #6e1423; color: #fff; padding: 10px 18px; border-radius: 6px; font-size: 15.5px; font-weight: bold; user-select: none; }
    .notes-toggle::-webkit-details-marker { display: none; }
    .notes-toggle:hover { background: #4a0c17; }
    .notes-count { background: #fff; color: #6e1423; border-radius: 10px; padding: 1px 8px; font-size: 13.5px; }
    .dream-row { background: #faf0f1; border: 1px solid #6e1423; border-radius: 8px; padding: 10px 14px; margin-top: 10px; }
    .dream-row .dream-label { font-size: 12.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #6e1423; font-weight: bold; margin-bottom: 4px; }
    .field-careers-row { border: 1px solid #eee; border-radius: 8px; padding: 4px 14px 2px; margin-top: 10px; }
    .field-careers-row .career-row { padding: 6px 0; }
    .subjects-line { font-size: 13.5px; color: #666; margin-top: 3px; }
    .explore-others-row summary { cursor: pointer; font-weight: bold; color: #6e1423; font-size: 15px; padding: 8px 0; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <div class="wrap">
        <?php require __DIR__ . '/nav.php'; ?>

        <h1>Student Lookup</h1>
        <p class="subtitle">Search for a student by ID, name, or email to view their assessment history.</p>

        <?php if ($viewError): ?>
            <div class="error"><?= htmlspecialchars($viewError) ?></div>
        <?php endif; ?>

        <?php if ($noteMessage): ?>
            <div class="flash-<?= $noteMessage['type'] ?>"><?= htmlspecialchars($noteMessage['text']) ?></div>
        <?php endif; ?>

        <?php if ($viewedStudent): ?>
            <?php
                // Where "Back" goes depends on how this profile was opened —
                // straight from a search here, or drilled into from Manage
                // Accounts (users.php). Without this, staff had no way back
                // except the top nav, even though they came from a list.
                if (($_GET['from'] ?? '') === 'users') {
                    // users.php reads the URL's #hash on load to decide which
                    // tab to show (see its activateTab() script), defaulting
                    // to Administrators when there isn't one -- so a plain
                    // "users.php" link always landed back on Administrators
                    // regardless of which tab the student was actually
                    // opened from. #students pins it back to the right tab.
                    $backHref = 'users.php#students';
                    $backLabel = '&larr; Back to Manage Accounts';
                } else {
                    $backHref = 'students_lookup.php';
                    $backLabel = '&larr; Back to student list';
                }
            ?>
            <div class="panel">
                <p><a class="back" href="<?= htmlspecialchars($backHref) ?>"><?= $backLabel ?></a></p>
                <div class="student-header">
                    <div>
                        <h2><?= htmlspecialchars($viewedStudent['name']) ?></h2>
                        <div class="student-meta">
                            <?= htmlspecialchars($viewedStudent['email']) ?> ·
                            <?= htmlspecialchars($viewedStudent['grade_level'] ?? 'Grade level not set') ?> ·
                            <span class="status-<?= htmlspecialchars($viewedStudent['status']) ?>"><?= htmlspecialchars($viewedStudent['status']) ?></span> ·
                            Student ID #<?= (int) $viewedStudent['student_id'] ?>
                            <?php if (!empty($viewedStudent['student_number'])): ?>
                                · LRN <?= htmlspecialchars($viewedStudent['student_number']) ?>
                            <?php endif; ?>
                            <?php if (!empty($viewedStudent['age'])): ?>
                                · Age <?= (int) $viewedStudent['age'] ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <details <?= $noteMessage ? 'open' : '' ?>>
                    <summary class="notes-toggle">
                        📝 Counselor Notes &amp; Outcomes
                        <?php if ($recordedNoteCount): ?><span class="notes-count"><?= $recordedNoteCount ?></span><?php endif; ?>
                    </summary>

                    <div style="margin-top:18px;">
                        <p class="subtitle" style="margin-bottom:16px;">Record what was discussed or decided during a consultation. The student can see these notes on their own history page. (Every profile view and note is also logged internally — see <a href="audit_log.php" style="color:#6e1423;">Audit Log</a>.)</p>

                        <form method="POST" class="note-form">
                            <input type="hidden" name="action" value="record_outcome">
                            <input type="hidden" name="student_id" value="<?= (int) $viewedStudent['student_id'] ?>">

                            <?php if ($allRecommendations): ?>
                                <label style="display:block;font-size:14.5px;font-weight:bold;margin-bottom:4px;">Relates to a specific recommendation (optional)</label>
                                <select name="recommendation_id">
                                    <option value="">— General note, not tied to one career —</option>
                                    <?php foreach ($allRecommendations as $rec): ?>
                                        <option value="<?= (int) $rec['recommendation_id'] ?>"><?= htmlspecialchars($rec['career_title']) ?> (<?= number_format($rec['match_score'], 0) ?>% match)</option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <textarea name="notes" placeholder="e.g. Discussed results with student; leaning toward Civil Engineering; follow-up session scheduled next week." required></textarea>
                            <div class="actions" style="margin-top:10px;">
                                <button type="submit" class="btn btn-primary" style="padding:8px 18px;border:none;border-radius:6px;background:#6e1423;color:#fff;cursor:pointer;">Save Note</button>
                            </div>
                        </form>
                    </div>
                </details>
            </div>

            <div class="panel">
                <h3 style="color:#6e1423;font-size:16.5px;margin-top:0;">Assessment History</h3>
                <?php if (!$viewedProfiles): ?>
                    <p class="empty">This student hasn't taken the assessment yet.</p>
                <?php else: ?>
                    <?php foreach ($viewedProfiles as $profile): ?>
                        <div class="submission">
                            <div class="submission-date">
                                Submitted <?= htmlspecialchars($profile['submitted_at']) ?>
                                · <a href="assessment_report.php?profile_id=<?= (int) $profile['profile_id'] ?>" target="_blank">Print / Save as PDF</a>
                            </div>
                            <div class="riasec-row">
                                <?php foreach ($riasecLabels as $col => $letter): ?>
                                    <span><strong><?= $letter ?>:</strong> <?= number_format($profile[$col] * 100, 0) ?>%</span>
                                <?php endforeach; ?>
                                <?php if (!empty($profile['academic_average'])): ?>
                                    <span><strong>Academic avg:</strong> <?= number_format($profile['academic_average'], 2) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($profile['skills'])): ?>
                                <p style="font-size:14.5px;color:#666;margin:6px 0 0;"><strong>Self-reported skills:</strong> <?= htmlspecialchars($profile['skills']) ?></p>
                            <?php endif; ?>
                            <?php if ($profile['dream_career']): ?>
                                <div class="dream-row">
                                    <div class="dream-label">🎯 Dream Career<?= $profile['dream_career']['career_category'] ? ' · ' . htmlspecialchars($profile['dream_career']['career_category']) : '' ?></div>
                                    <div class="top-line">
                                        <span><a href="career_profile.php?id=<?= $profile['dream_career']['career_id'] ?>"><?= htmlspecialchars($profile['dream_career']['career_title']) ?></a></span>
                                        <span class="match"><?= number_format($profile['dream_career']['match_score'], 0) ?>% match</span>
                                    </div>
                                    <?php if (!empty($profile['dream_career']['key_subjects'])): ?>
                                        <div class="subjects-line">📚 Subjects to focus on: <?= htmlspecialchars($profile['dream_career']['key_subjects']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($profile['field_careers']): ?>
                                <div class="field-careers-row">
                                    <div class="dream-label" style="color:#888;">📂 More in <?= htmlspecialchars($profile['dream_career']['career_category']) ?> (<?= count($profile['field_careers']) ?>)</div>
                                    <?php foreach ($profile['field_careers'] as $fc): ?>
                                        <div class="career-row">
                                            <div class="top-line">
                                                <span><a href="career_profile.php?id=<?= $fc['career_id'] ?>"><?= htmlspecialchars($fc['career_title']) ?></a></span>
                                                <span class="match"><?= number_format($fc['match_score'], 0) ?>% match</span>
                                            </div>
                                            <?php if (!empty($fc['key_subjects'])): ?>
                                                <div class="subjects-line">📚 <?= htmlspecialchars($fc['key_subjects']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php
                                $shownCareerIds = array_map(fn($c) => $c['career_id'], $profile['field_careers']);
                                if ($profile['dream_career']) {
                                    $shownCareerIds[] = $profile['dream_career']['career_id'];
                                }
                                $otherRecs = array_filter($profile['recommendations'], fn($rec) => !in_array((int) $rec['career_id'], $shownCareerIds, true));
                            ?>
                            <?php if ($otherRecs): ?>
                                <?php
                                    $otherRecsMarkup = function () use ($otherRecs) {
                                        foreach ($otherRecs as $rec) {
                                            ?>
                                            <div class="career-row">
                                                <div class="top-line">
                                                    <span><?= htmlspecialchars($rec['career_title']) ?></span>
                                                    <span class="match"><?= number_format($rec['match_score'], 0) ?>%</span>
                                                </div>
                                                <?php $sm = $rec['skill_match']; ?>
                                                <?php if ($sm['match_percent'] !== null): ?>
                                                    <div class="skill-pct">
                                                        <?= $sm['match_percent'] ?>% skills match —
                                                        <?php foreach ($sm['matched'] as $s): ?>
                                                            <span class="skill-tag skill-have">✓ <?= htmlspecialchars($s['skill_name']) ?></span>
                                                        <?php endforeach; ?>
                                                        <?php foreach ($sm['missing'] as $s): ?>
                                                            <span class="skill-tag skill-need"><?= htmlspecialchars($s['skill_name']) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                        }
                                    };
                                ?>
                                <?php if ($profile['dream_career']): ?>
                                    <details class="explore-others-row" style="margin-top:10px;">
                                        <summary>🔎 Explore other careers from this submission (<?= count($otherRecs) ?>)</summary>
                                        <div style="margin-top:8px;"><?php $otherRecsMarkup(); ?></div>
                                    </details>
                                <?php else: ?>
                                    <div style="margin-top:10px;"><?php $otherRecsMarkup(); ?></div>
                                <?php endif; ?>
                            <?php elseif (!$profile['dream_career']): ?>
                                <p class="empty" style="margin-top:8px;">No recommendations were saved for this submission.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="panel">
                <div class="search-bar" style="display:flex;align-items:center;gap:12px;max-width:none;flex-wrap:wrap;">
                    <div style="position:relative;max-width:340px;flex:1;min-width:220px;">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="student-search" placeholder="Search by student ID, student number, name, or email..." autofocus style="width:100%;padding:9px 14px 9px 32px;border:1px solid #ccc;border-radius:20px;font-size:15.5px;box-sizing:border-box;">
                    </div>
                    <?php if ($gradeLevelOptions): ?>
                        <select id="grade-filter" style="padding:8px 12px;border:1px solid #ccc;border-radius:20px;font-size:14.5px;">
                            <option value="">All grade levels</option>
                            <?php foreach ($gradeLevelOptions as $gl): ?>
                                <option value="<?= htmlspecialchars(strtolower($gl)) ?>"><?= htmlspecialchars($gl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <?php if (!$students): ?>
                    <p class="empty" style="margin-top:16px;">No student accounts yet.</p>
                <?php else: ?>
                    <p class="empty" id="student-count" style="margin-top:16px;margin-bottom:0;"><?= count($students) ?> student account<?= count($students) === 1 ? '' : 's' ?></p>
                    <div class="cp-table-scroll"><table>
                        <tr>
                            <th>ID</th>
                            <th>LRN</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Grade level</th>
                            <th>Age</th>
                            <th>Assessments</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                        <?php foreach ($students as $s): ?>
                            <tr data-search="<?= htmlspecialchars(strtolower($s['student_id'] . ' ' . ($s['student_number'] ?? '') . ' ' . $s['name'] . ' ' . $s['email'])) ?>" data-grade="<?= htmlspecialchars(strtolower($s['grade_level'] ?? '')) ?>">
                                <td>#<?= (int) $s['student_id'] ?></td>
                                <td><?= htmlspecialchars($s['student_number'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($s['name']) ?></td>
                                <td><?= htmlspecialchars($s['email']) ?></td>
                                <td><?= htmlspecialchars($s['grade_level'] ?? '—') ?></td>
                                <td><?= $s['age'] !== null ? (int) $s['age'] : '—' ?></td>
                                <td><?= (int) $s['submission_count'] ?></td>
                                <td class="status-<?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></td>
                                <td><a class="view-link" href="students_lookup.php?view=<?= (int) $s['student_id'] ?>">View →</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="student-no-results" style="display:none;">
                            <td colspan="9" class="empty" style="text-align:center;">No students match your search.</td>
                        </tr>
                    </table></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        var input = document.getElementById('student-search');
        if (!input) return;
        var gradeFilter = document.getElementById('grade-filter');
        var rows = document.querySelectorAll('tr[data-search]');
        var noResultsRow = document.getElementById('student-no-results');
        var countLabel = document.getElementById('student-count');
        function applyFilters() {
            var q = input.value.trim().toLowerCase();
            var grade = gradeFilter ? gradeFilter.value : '';
            var visible = 0;
            rows.forEach(function (row) {
                var matchesText = row.dataset.search.indexOf(q) !== -1;
                var matchesGrade = grade === '' || row.dataset.grade === grade;
                var match = matchesText && matchesGrade;
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (noResultsRow) noResultsRow.style.display = visible === 0 ? '' : 'none';
            if (countLabel) countLabel.textContent = visible + ' student account' + (visible === 1 ? '' : 's') + (q !== '' || grade !== '' ? ' matching your filters' : '');
        }
        input.addEventListener('input', applyFilters);
        if (gradeFilter) gradeFilter.addEventListener('change', applyFilters);
    })();
    </script>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
