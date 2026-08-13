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
$currentUser = require_role(['administrator', 'counselor']);

$pdo = get_db();
$noteMessage = null;

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
        $noteMessage = ['type' => 'success', 'text' => 'Outcome recorded.'];
        $_GET['view'] = $studentId; // re-show this student's profile after redirect-less POST
    } else {
        $noteMessage = ['type' => 'error', 'text' => 'Please enter a note before saving.'];
        $_GET['view'] = $studentId;
    }
}

$search = trim($_GET['q'] ?? '');
$students = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $idMatch = ctype_digit($search) ? (int) $search : 0;

    $stmt = $pdo->prepare(
        "SELECT s.*, COUNT(sp.profile_id) AS submission_count
         FROM students s
         LEFT JOIN student_profiles sp ON sp.student_id = s.student_id
         WHERE s.student_id = :id_match OR s.name LIKE :like OR s.email LIKE :like
         GROUP BY s.student_id
         ORDER BY s.name
         LIMIT 25"
    );
    $stmt->execute(['id_match' => $idMatch, 'like' => $like]);
    $students = $stmt->fetchAll();
}

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
        $allRecommendations = []; // flat list across all profiles, for the "attach note to" dropdown
        foreach ($viewedProfiles as &$profile) {
            $recStmt->execute(['profile_id' => $profile['profile_id']]);
            $profile['recommendations'] = $recStmt->fetchAll();
            foreach ($profile['recommendations'] as &$rec) {
                $rec['skill_match'] = compute_skill_match($pdo, (int) $rec['career_id'], $profile['skills'] ?? null);
                $allRecommendations[] = $rec;
            }
            unset($rec);
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

$riasecLabels = ['r_score' => 'R', 'i_score' => 'I', 'a_score' => 'A', 's_score' => 'S', 'e_score' => 'E', 'c_score' => 'C'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Student Lookup</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; padding: 40px 20px; color: #222; background: #faf7f5; }
    .wrap { max-width: 1280px; margin: 0 auto; }
    h1 { color: #6e1423; margin-bottom: 4px; }
    .subtitle { color: #666; margin-top: 0; margin-bottom: 24px; }

    .panel { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(74,12,23,0.08); padding: 22px 26px; margin-bottom: 20px; }

    .search-form { display: flex; gap: 10px; }
    .search-form input[type=text] { flex: 1; padding: 10px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
    .search-form button { padding: 10px 20px; background: #6e1423; color: #fff; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .search-form button:hover { background: #4a0c17; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.15); }

    table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 14px; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
    th { color: #555; font-size: 12px; text-transform: uppercase; }
    .status-active { color: #0f5132; }
    .status-disabled { color: #b02a37; }
    a.view-link { color: #6e1423; font-weight: bold; text-decoration: none; }
    a.view-link:hover { text-decoration: underline; }

    .empty { color: #888; font-style: italic; font-size: 14px; }
    .error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }

    .student-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .student-header h2 { margin: 0; color: #6e1423; }
    .student-meta { font-size: 13px; color: #666; }
    a.back { color: #6e1423; font-size: 13px; text-decoration: none; }
    a.back:hover { text-decoration: underline; }

    .submission { border: 1px solid #eee; border-radius: 8px; padding: 16px 18px; margin-top: 14px; }
    .submission-date { font-size: 13px; color: #666; margin-bottom: 10px; }
    .riasec-row span { display: inline-block; margin-right: 14px; font-size: 13px; background: #faf0f1; padding: 3px 8px; border-radius: 6px; }
    .career-row { padding: 6px 0; border-top: 1px solid #f2f2f2; font-size: 14px; }
    .career-row:first-of-type { border-top: none; }
    .career-row .top-line { display: flex; justify-content: space-between; }
    .career-row .match { color: #6e1423; font-weight: bold; }
    .skill-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 11px; }
    .skill-have { background: #d1e7dd; color: #0f5132; }
    .skill-need { background: #fff3cd; color: #856404; }
    .skill-pct { font-size: 12px; color: #666; margin-top: 4px; }
    .note-entry { border-top: 1px solid #f2f2f2; padding: 10px 0; font-size: 13px; }
    .note-entry:first-of-type { border-top: none; }
    .note-entry .note-meta { color: #888; font-size: 12px; margin-bottom: 3px; }
    .note-entry.audit-only { color: #aaa; font-style: italic; }
    .note-form textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; min-height: 60px; }
    .note-form select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; margin-bottom: 8px; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    .notes-toggle { list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; background: #6e1423; color: #fff; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: bold; user-select: none; }
    .notes-toggle::-webkit-details-marker { display: none; }
    .notes-toggle:hover { background: #4a0c17; }
    .notes-count { background: #fff; color: #6e1423; border-radius: 10px; padding: 1px 8px; font-size: 12px; }
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
                    $backHref = 'users.php';
                    $backLabel = '&larr; Back to Manage Accounts';
                } else {
                    $backHref = 'students_lookup.php' . ($search !== '' ? '?q=' . urlencode($search) : '');
                    $backLabel = '&larr; Back to search';
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
                        </div>
                    </div>
                </div>

                <h3 style="color:#6e1423;font-size:15px;margin-top:22px;">Assessment History</h3>
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
                                <p style="font-size:13px;color:#666;margin:6px 0 0;"><strong>Self-reported skills:</strong> <?= htmlspecialchars($profile['skills']) ?></p>
                            <?php endif; ?>
                            <?php if ($profile['recommendations']): ?>
                                <div style="margin-top:10px;">
                                    <?php foreach ($profile['recommendations'] as $rec): ?>
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
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="empty" style="margin-top:8px;">No recommendations were saved for this submission.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="panel">
                <details <?= $noteMessage ? 'open' : '' ?>>
                    <summary class="notes-toggle">
                        📝 Counselor Notes &amp; Outcomes
                        <?php if ($recordedNoteCount): ?><span class="notes-count"><?= $recordedNoteCount ?></span><?php endif; ?>
                    </summary>

                    <div style="margin-top:18px;">
                        <p class="subtitle" style="margin-bottom:16px;">Record what was discussed or decided during a consultation. The student can see these notes on their own history page — the automatic view log below stays internal to staff.</p>

                        <form method="POST" class="note-form">
                            <input type="hidden" name="action" value="record_outcome">
                            <input type="hidden" name="student_id" value="<?= (int) $viewedStudent['student_id'] ?>">

                            <?php if ($allRecommendations): ?>
                                <label style="display:block;font-size:13px;font-weight:bold;margin-bottom:4px;">Relates to a specific recommendation (optional)</label>
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

                        <?php if (!$counselorLogEntries): ?>
                            <p class="empty" style="margin-top:16px;">No log entries yet for this student.</p>
                        <?php else: ?>
                            <div style="margin-top:18px;">
                                <?php foreach ($counselorLogEntries as $entry): ?>
                                    <?php $isNote = $entry['action'] === 'recorded_outcome' && !empty($entry['notes']); ?>
                                    <div class="note-entry <?= $isNote ? '' : 'audit-only' ?>">
                                        <div class="note-meta">
                                            <?= htmlspecialchars($entry['counselor_name']) ?> ·
                                            <?= htmlspecialchars($entry['created_at']) ?> ·
                                            <?= $isNote ? 'Outcome recorded (visible to student)' : htmlspecialchars(str_replace('_', ' ', $entry['action'])) ?>
                                        </div>
                                        <?php if ($isNote): ?>
                                            <div><?= nl2br(htmlspecialchars($entry['notes'])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        <?php else: ?>
            <div class="panel">
                <form class="search-form" method="GET">
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by student ID, name, or email" autofocus>
                    <button type="submit">Search</button>
                </form>

                <?php if ($search !== ''): ?>
                    <?php if (!$students): ?>
                        <p class="empty" style="margin-top:16px;">No students matched "<?= htmlspecialchars($search) ?>".</p>
                    <?php else: ?>
                        <table>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Grade level</th>
                                <th>Assessments</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                            <?php foreach ($students as $s): ?>
                                <tr>
                                    <td>#<?= (int) $s['student_id'] ?></td>
                                    <td><?= htmlspecialchars($s['name']) ?></td>
                                    <td><?= htmlspecialchars($s['email']) ?></td>
                                    <td><?= htmlspecialchars($s['grade_level'] ?? '—') ?></td>
                                    <td><?= (int) $s['submission_count'] ?></td>
                                    <td class="status-<?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></td>
                                    <td><a class="view-link" href="students_lookup.php?view=<?= (int) $s['student_id'] ?>&q=<?= urlencode($search) ?>">View →</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
