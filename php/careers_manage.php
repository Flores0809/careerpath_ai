<?php
// CareerPath AI - Manage Live Careers (counselors & administrators)
//
// careers.php only handles the pending_careers review queue (approve/reject
// before a career ever goes live). This page is for the other side: editing
// careers that are ALREADY in the live `careers` table — fixing a typo,
// updating a stale description, or adjusting a RIASEC score after the fact.
//
// No hard delete here on purpose: recommendations.career_id references
// careers ON DELETE CASCADE, so deleting a career would silently wipe every
// student's saved recommendation history that included it. Instead,
// "Deactivate" flips status to 'inactive', which the matching engine
// (app.py, WHERE status = 'active') simply stops matching against — the
// row and all history referencing it stay intact.

require __DIR__ . '/auth.php';
require_once __DIR__ . '/change_log_helper.php';
$currentUser = require_role(['administrator', 'counselor']);

$pdo = get_db();
$message = null;
$refreshedCareerId = null;
$refreshedScores = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $careerId = (int) ($_POST['career_id'] ?? 0);

    if ($action === 'refresh_riasec' && $careerId) {
        // One-click AI-assisted RIASEC score refresh (Use Case Diagram /
        // Flowchart). Reuses the same Gemini /enrich endpoint as the crawler
        // review queue, but only takes the RIASEC suggestion — nothing is
        // saved until the counselor reviews it and clicks "Save Changes"
        // below, per the paper's "for administrator review and confirmation."
        $title = trim($_POST['career_title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $payload = json_encode([
            'career_title' => $title,
            'raw_description' => $description,
            'raw_qualifications' => trim($_POST['daily_task'] ?? ''),
        ]);

        $ch = curl_init(ENRICH_SERVICE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 35,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result = $curlError ? null : json_decode($response, true);

        if ($curlError || $httpCode !== 200 || !$result || empty($result['ai_enriched'])) {
            $reason = $curlError ?: ($result['error'] ?? "HTTP $httpCode");
            $message = [
                'type' => 'error',
                'text' => "AI RIASEC refresh unavailable ($reason). Existing scores are unchanged — adjust manually or try again.",
            ];
        } else {
            $refreshedCareerId = $careerId;
            $refreshedScores = $result['riasec'];
            $message = [
                'type' => 'success',
                'text' => 'AI-suggested RIASEC scores are shown below — review and click "Save Changes" to apply them, or edit further first.',
            ];
        }
    } elseif ($action === 'bulk_apply_riasec' && $careerId) {
        // Bulk counterpart to 'refresh_riasec' above, only reachable via the
        // "Refresh RIASEC with AI for All Careers" button below — not exposed
        // as a per-career option. The single-career button deliberately stops
        // at a preview so a counselor reviews AI-suggested scores before
        // saving (see refresh_riasec above, and the paper's "for
        // administrator review and confirmation"). Reviewing dozens of
        // previews one at a time isn't practical for a bulk run, so this path
        // applies each result immediately instead -- but every change is
        // still logged to change_log exactly like a normal manual edit, so
        // any single career's AI-refreshed scores can be reviewed and, if
        // they look wrong, reverted individually from Change History without
        // undoing the whole batch.
        $careerStmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id");
        $careerStmt->execute(['id' => $careerId]);
        $careerRow = $careerStmt->fetch();

        if (!$careerRow) {
            $message = ['type' => 'error', 'text' => 'Could not find that career.'];
        } else {
            $payload = json_encode([
                'career_title' => $careerRow['career_title'],
                'raw_description' => $careerRow['description'],
                'raw_qualifications' => $careerRow['daily_task'],
            ]);

            $ch = curl_init(ENRICH_SERVICE_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 35,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $result = $curlError ? null : json_decode($response, true);

            if ($curlError || $httpCode !== 200 || !$result || empty($result['ai_enriched'])) {
                $reason = $curlError ?: ($result['error'] ?? "HTTP $httpCode");
                $message = ['type' => 'error', 'text' => "AI RIASEC refresh unavailable for \"{$careerRow['career_title']}\" ($reason)."];
            } else {
                $riasec = $result['riasec'];
                $stmt = $pdo->prepare(
                    "UPDATE careers SET r_score=:r, i_score=:i, a_score=:a, s_score=:s, e_score=:e, c_score=:c WHERE career_id=:id"
                );
                $stmt->execute([
                    'r' => $riasec['R'], 'i' => $riasec['I'], 'a' => $riasec['A'],
                    's' => $riasec['S'], 'e' => $riasec['E'], 'c' => $riasec['C'],
                    'id' => $careerId,
                ]);
                $newCareerStmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id");
                $newCareerStmt->execute(['id' => $careerId]);
                log_change($pdo, 'careers', $careerId, $careerRow['career_title'], 'update', $careerRow, $newCareerStmt->fetch(), $currentUser['user_id']);
                $message = ['type' => 'success', 'text' => "Updated RIASEC scores for \"{$careerRow['career_title']}\"."];
            }
        }
    } elseif ($action === 'ai_suggest_skills' && $careerId) {
        // Backfills required skills for an ALREADY-APPROVED career that has
        // none yet — the original seed catalog and any career approved
        // before migration_20 added AI skill suggestions to the pending-side
        // review flow never got this. Reuses the same /enrich endpoint the
        // crawler pipeline already calls, just pointed at an approved
        // career's own description instead of a raw scraped posting.
        // Inserted directly (not staged for a separate review step) since a
        // counselor triggered this deliberately here and can remove/edit any
        // resulting row immediately with the existing "✕" button below —
        // same as a skill they'd typed in by hand.
        $careerStmt = $pdo->prepare("SELECT career_title, description, daily_task FROM careers WHERE career_id = :id");
        $careerStmt->execute(['id' => $careerId]);
        $careerRow = $careerStmt->fetch();

        if (!$careerRow) {
            $message = ['type' => 'error', 'text' => 'Could not find that career.'];
        } else {
            $categoryNames = $pdo->query("SELECT name FROM career_categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $payload = json_encode([
                'career_title' => $careerRow['career_title'],
                'raw_description' => $careerRow['description'],
                'raw_qualifications' => $careerRow['daily_task'],
                'categories' => $categoryNames,
            ]);

            $ch = curl_init(ENRICH_SERVICE_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 35,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $result = $curlError ? null : json_decode($response, true);

            if ($curlError || $httpCode !== 200 || !$result || empty($result['ai_enriched'])) {
                $reason = $curlError ?: ($result['error'] ?? "HTTP $httpCode");
                $message = ['type' => 'error', 'text' => "AI skill suggestion unavailable ($reason). Add skills manually below, or try again."];
            } else {
                // Skip anything that (case-insensitively) already exists for
                // this career, in case this gets run more than once.
                $existingStmt = $pdo->prepare("SELECT skill_name FROM skill_requirements WHERE career_id = :id");
                $existingStmt->execute(['id' => $careerId]);
                $existingNames = array_map('strtolower', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

                $insertSkill = $pdo->prepare(
                    "INSERT INTO skill_requirements (career_id, skill_name, proficiency_level, is_required)
                     VALUES (:career_id, :skill_name, :proficiency, :is_required)"
                );
                $addedCount = 0;
                foreach (($result['skills'] ?? []) as $skill) {
                    $skillName = trim($skill['skill_name'] ?? '');
                    if ($skillName === '' || in_array(strtolower($skillName), $existingNames, true)) {
                        continue;
                    }
                    $proficiency = mb_substr(trim($skill['proficiency_level'] ?? ''), 0, 150);
                    $isRequired = !empty($skill['is_required']) ? 1 : 0;
                    $insertSkill->execute([
                        'career_id' => $careerId,
                        'skill_name' => $skillName,
                        'proficiency' => $proficiency,
                        'is_required' => $isRequired,
                    ]);
                    $newSkillId = (int) $pdo->lastInsertId();
                    log_change($pdo, 'skill_requirements', $newSkillId, $skillName, 'insert', null, [
                        'career_id' => $careerId,
                        'skill_name' => $skillName,
                        'proficiency_level' => $proficiency,
                        'is_required' => $isRequired,
                    ], $currentUser['user_id']);
                    $existingNames[] = strtolower($skillName);
                    $addedCount++;
                }
                $message = $addedCount > 0
                    ? ['type' => 'success', 'text' => "AI added $addedCount suggested skill(s) below — review, edit, or remove any before students see them."]
                    : ['type' => 'error', 'text' => 'AI enrichment ran but returned no new skills to add (all suggestions already existed).'];
            }
        }
    } elseif ($action === 'add_skill' && $careerId) {
        $skillName = trim($_POST['skill_name'] ?? '');
        // Free text now, not a fixed basic/intermediate/advanced enum — see
        // migration_23_freetext_proficiency.sql.
        $proficiency = mb_substr(trim($_POST['proficiency_level'] ?? ''), 0, 150);
        $isRequired = isset($_POST['is_required']) ? 1 : 0;

        if ($skillName === '') {
            $message = ['type' => 'error', 'text' => 'Skill name cannot be empty.'];
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO skill_requirements (career_id, skill_name, proficiency_level, is_required)
                 VALUES (:career_id, :skill_name, :proficiency, :is_required)"
            );
            $stmt->execute([
                'career_id' => $careerId,
                'skill_name' => $skillName,
                'proficiency' => $proficiency,
                'is_required' => $isRequired,
            ]);
            $newSkillId = (int) $pdo->lastInsertId();
            $newSkillStmt = $pdo->prepare("SELECT * FROM skill_requirements WHERE skill_req_id = :id");
            $newSkillStmt->execute(['id' => $newSkillId]);
            log_change($pdo, 'skill_requirements', $newSkillId, $skillName, 'insert', null, $newSkillStmt->fetch(), $currentUser['user_id']);
            $message = ['type' => 'success', 'text' => "Added skill \"$skillName\"."];
        }
    } elseif ($action === 'delete_skill') {
        $skillReqId = (int) ($_POST['skill_req_id'] ?? 0);
        if ($skillReqId) {
            $oldSkillStmt = $pdo->prepare("SELECT * FROM skill_requirements WHERE skill_req_id = :id");
            $oldSkillStmt->execute(['id' => $skillReqId]);
            $oldSkill = $oldSkillStmt->fetch();

            $stmt = $pdo->prepare("DELETE FROM skill_requirements WHERE skill_req_id = :id");
            $stmt->execute(['id' => $skillReqId]);

            if ($oldSkill) {
                log_change($pdo, 'skill_requirements', $skillReqId, $oldSkill['skill_name'], 'delete', $oldSkill, null, $currentUser['user_id']);
            }
            $message = ['type' => 'success', 'text' => 'Skill removed.'];
        }
    } elseif ($action === 'update' && $careerId) {
        $title = trim($_POST['career_title'] ?? '');
        $category = trim($_POST['career_category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $dailyTask = trim($_POST['daily_task'] ?? '');
        $pathway = trim($_POST['educational_pathway'] ?? '');
        $keySubjects = trim($_POST['key_subjects'] ?? '');
        $careerScope = ($_POST['career_scope'] ?? '') === 'international' ? 'international' : 'local';
        $scores = [
            'r' => (int) ($_POST['r_score'] ?? 0),
            'i' => (int) ($_POST['i_score'] ?? 0),
            'a' => (int) ($_POST['a_score'] ?? 0),
            's' => (int) ($_POST['s_score'] ?? 0),
            'e' => (int) ($_POST['e_score'] ?? 0),
            'c' => (int) ($_POST['c_score'] ?? 0),
        ];

        if ($title === '') {
            $message = ['type' => 'error', 'text' => 'Career title cannot be empty.'];
        } elseif ($category === '') {
            // No "Other" fallback — every career needs a real cluster so it's
            // eligible to appear in the assessment's dream-career picker.
            $message = ['type' => 'error', 'text' => 'Category / Industry Cluster is required.'];
        } else {
            $oldCareerStmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id");
            $oldCareerStmt->execute(['id' => $careerId]);
            $oldCareer = $oldCareerStmt->fetch();

            $stmt = $pdo->prepare(
                "UPDATE careers SET
                    career_title = :title, career_category = :category, description = :description, daily_task = :daily_task,
                    educational_pathway = :pathway, key_subjects = :key_subjects, career_scope = :scope,
                    r_score = :r, i_score = :i, a_score = :a, s_score = :s, e_score = :e, c_score = :c
                 WHERE career_id = :id"
            );
            $stmt->execute([
                'title' => $title, 'category' => $category, 'description' => $description, 'daily_task' => $dailyTask,
                'pathway' => $pathway, 'key_subjects' => $keySubjects !== '' ? $keySubjects : null, 'scope' => $careerScope,
                'r' => $scores['r'], 'i' => $scores['i'], 'a' => $scores['a'],
                's' => $scores['s'], 'e' => $scores['e'], 'c' => $scores['c'],
                'id' => $careerId,
            ]);

            if ($oldCareer) {
                $newCareerStmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id");
                $newCareerStmt->execute(['id' => $careerId]);
                log_change($pdo, 'careers', $careerId, $title, 'update', $oldCareer, $newCareerStmt->fetch(), $currentUser['user_id']);
            }
            $message = ['type' => 'success', 'text' => "Updated \"$title\"."];
        }
    } elseif ($action === 'toggle_status' && $careerId) {
        $stmt = $pdo->prepare("SELECT career_title, status FROM careers WHERE career_id = :id");
        $stmt->execute(['id' => $careerId]);
        $target = $stmt->fetch();

        if (!$target) {
            $message = ['type' => 'error', 'text' => 'Career not found.'];
        } else {
            $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
            $stmt = $pdo->prepare("UPDATE careers SET status = :status WHERE career_id = :id");
            $stmt->execute(['status' => $newStatus, 'id' => $careerId]);
            log_change($pdo, 'careers', $careerId, $target['career_title'], 'update', ['status' => $target['status']], ['status' => $newStatus], $currentUser['user_id']);
            $message = ['type' => 'success', 'text' => htmlspecialchars($target['career_title']) . " is now $newStatus."];
        }
    }
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM careers WHERE career_title LIKE :like ORDER BY career_title LIMIT 200");
    $stmt->execute(['like' => '%' . $search . '%']);
} else {
    $stmt = $pdo->query("SELECT * FROM careers ORDER BY career_title LIMIT 200");
}
$careers = $stmt->fetchAll();

// Load skill requirements for every displayed career in one query rather
// than N+1 — the skills-verification mechanism (Specific Objective 2 /
// Research Gap #4) needs this per-career list for the edit form below.
$skillsByCareer = [];
if ($careers) {
    $ids = array_column($careers, 'career_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $skillStmt = $pdo->prepare(
        "SELECT * FROM skill_requirements WHERE career_id IN ($placeholders) ORDER BY is_required DESC, skill_name"
    );
    $skillStmt->execute($ids);
    foreach ($skillStmt->fetchAll() as $skillRow) {
        $skillsByCareer[$skillRow['career_id']][] = $skillRow;
    }
}

// Category / industry-cluster options — sourced from the career_categories
// lookup table (migration_13_category_management.sql) instead of a
// free-text field + suggestion list, so every career is tagged with a real,
// managed category. Add/rename/remove categories on career_categories.php.
$categoryOptions = $pdo->query("SELECT name, description FROM career_categories ORDER BY name")->fetchAll();

// Count of currently-listed careers with zero skill_requirements rows — most
// of these are original seed-catalog entries approved before the AI-skill-
// suggestion feature (migration_20) existed, so they never got backfilled.
// Drives the "Backfill Missing Skills for All Careers" button below: only
// shown when there's actually something to do, and only counts careers on
// THIS page (respects the search filter, same as everything else here).
$missingSkillsCareerIds = [];
foreach ($careers as $c) {
    if (empty($skillsByCareer[$c['career_id']])) {
        $missingSkillsCareerIds[] = (int) $c['career_id'];
    }
}

// Every career already has RIASEC scores (never null/missing — they're
// seeded or AI-enriched at approval time), so there's no "missing" subset
// to target the way skills has one. This is every career_id on the current
// page (respects the search filter, same as the skills list above) — the
// "Refresh RIASEC with AI for All Careers" button below OVERWRITES existing
// scores for all of these, which is why it's kept as a separate, deliberate
// button rather than folded into the skills backfill (see also: keeping
// these as two buttons means a counselor who only wants one kind of AI call
// isn't paying for the other).
$allCareerIds = array_map('intval', array_column($careers, 'career_id'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — Manage Careers</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 0; padding: 40px 20px; color: #222; background: #faf7f5; }
    .wrap { max-width: 1280px; margin: 0 auto; }
    h1 { color: #6e1423; margin-bottom: 4px; }
    .subtitle { color: #666; margin-top: 0; margin-bottom: 24px; }

    .panel { background: #f5f5f5; border-radius: 12px; box-shadow: 0 4px 16px rgba(74,12,23,0.08); padding: 22px 26px; margin-bottom: 20px; }

    .search-form { display: flex; gap: 10px; }
    .search-form input[type=text] { flex: 1; padding: 10px 14px; border: 1px solid #ccc; border-radius: 6px; font-size: 15.5px; }
    .search-form button { padding: 10px 20px; background: #6e1423; color: #fff; border: none; border-radius: 6px; font-size: 15.5px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .search-form button:hover { background: #4a0c17; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.15); }

    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }

    .career-row { border: 1px solid #eee; border-radius: 8px; padding: 14px 18px; margin-bottom: 12px; }
    .career-row summary { cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; font-size: 15.5px; }
    .career-row summary::-webkit-details-marker { display: none; }
    .career-row summary .title { font-weight: bold; color: #6e1423; font-size: 16.5px; }
    .career-row summary .meta { color: #888; font-size: 13.5px; }
    .status-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12.5px; text-transform: uppercase; margin-left: 8px; }
    .status-active { background: #d1e7dd; color: #0f5132; }
    .status-inactive { background: #eee; color: #666; }
    .status-pending { background: #fff3cd; color: #856404; }

    label { display: block; font-size: 14.5px; font-weight: bold; margin: 12px 0 4px; }
    input[type=text], textarea { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    select[name=career_category] { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    textarea { min-height: 56px; }
    .riasec-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-top: 8px; }
    .riasec-grid div { text-align: center; }
    .riasec-grid input { text-align: center; }
    .actions { margin-top: 14px; }
    button.btn { padding: 8px 18px; border: none; border-radius: 6px; font-size: 14.5px; cursor: pointer; margin-right: 8px; }
    .btn-primary { background: #6e1423; color: #fff; }
    .btn-outline { background: #fff; color: #6e1423; border: 1px solid #6e1423; }
    .empty { color: #888; font-style: italic; font-size: 15.5px; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <div class="wrap">
        <?php require __DIR__ . '/nav.php'; ?>

        <h1>Manage Careers</h1>
        <p class="subtitle">Edit or deactivate careers already live in the database. For new careers from the crawler, use Career Review instead.</p>

        <?php if ($message): ?>
            <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
        <?php endif; ?>

        <div class="panel">
            <form class="search-form" method="GET">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search careers by title">
                <button type="submit">Search</button>
            </form>
        </div>

        <?php if ($missingSkillsCareerIds || $allCareerIds): ?>
            <div class="panel">
                <p style="margin:0 0 14px;font-size:13.5px;color:#888;">Bulk AI actions for the careers listed below — kept as two separate buttons on purpose, so running one doesn't also spend API calls on the other.</p>

                <?php if ($missingSkillsCareerIds): ?>
                    <div id="bulk-skill-fill-panel" style="padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid #eee;">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                            <div>
                                <strong style="color:#6e1423;"><?= count($missingSkillsCareerIds) ?> career(s) below have no required skills listed yet</strong>
                                <p style="margin:4px 0 0;font-size:13.5px;color:#888;">Mostly the original seed catalog, approved before AI skill suggestion existed. Runs "✨ Suggest Skills with AI" for each one in turn — same result as clicking it individually <?= count($missingSkillsCareerIds) ?> times, just automated. Only touches careers with no skills yet, so it never re-spends tokens on ones already filled in.</p>
                            </div>
                            <button type="button" id="bulk-skill-fill-btn" class="btn btn-primary" style="white-space:nowrap;">✨ Backfill Missing Skills for All (<?= count($missingSkillsCareerIds) ?>)</button>
                        </div>
                        <div id="bulk-skill-fill-status" style="margin-top:10px;font-size:14.5px;display:none;">
                            <div style="background:#eee;border-radius:6px;height:8px;overflow:hidden;">
                                <div id="bulk-skill-fill-bar" style="background:#6e1423;height:100%;width:0%;transition:width 0.2s ease;"></div>
                            </div>
                            <p id="bulk-skill-fill-text" style="margin:8px 0 0;color:#555;"></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($allCareerIds): ?>
                    <div id="bulk-riasec-refresh-panel">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                            <div>
                                <strong style="color:#6e1423;">Refresh RIASEC scores with AI for all <?= count($allCareerIds) ?> career(s) below</strong>
                                <p style="margin:4px 0 0;font-size:13.5px;color:#888;">Unlike the single-career "Refresh RIASEC with AI" button (which only previews a suggestion for you to review before saving), this applies each result right away — but every change is logged to Change History same as a manual edit, so any one career's new scores can be reviewed and reverted individually if they look off. This OVERWRITES existing scores for every career listed, so use the search box above to narrow this to specific careers if you don't want a full re-run.</p>
                            </div>
                            <button type="button" id="bulk-riasec-refresh-btn" class="btn btn-primary" style="white-space:nowrap;">🔄 Refresh RIASEC with AI for All (<?= count($allCareerIds) ?>)</button>
                        </div>
                        <div id="bulk-riasec-refresh-status" style="margin-top:10px;font-size:14.5px;display:none;">
                            <div style="background:#eee;border-radius:6px;height:8px;overflow:hidden;">
                                <div id="bulk-riasec-refresh-bar" style="background:#6e1423;height:100%;width:0%;transition:width 0.2s ease;"></div>
                            </div>
                            <p id="bulk-riasec-refresh-text" style="margin:8px 0 0;color:#555;"></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$careers): ?>
            <p class="empty"><?= $search !== '' ? 'No careers matched "' . htmlspecialchars($search) . '".' : 'No careers in the database yet.' ?></p>
        <?php endif; ?>

        <?php foreach ($careers as $c): ?>
            <?php
                $wasRefreshed = $refreshedCareerId === (int) $c['career_id'];
                $riasecDefaults = $wasRefreshed ? [
                    'r' => $refreshedScores['R'], 'i' => $refreshedScores['I'], 'a' => $refreshedScores['A'],
                    's' => $refreshedScores['S'], 'e' => $refreshedScores['E'], 'c' => $refreshedScores['C'],
                ] : [
                    'r' => $c['r_score'], 'i' => $c['i_score'], 'a' => $c['a_score'],
                    's' => $c['s_score'], 'e' => $c['e_score'], 'c' => $c['c_score'],
                ];
            ?>
            <details class="career-row" data-career-id="<?= (int) $c['career_id'] ?>" data-title="<?= htmlspecialchars($c['career_title']) ?>" <?= $wasRefreshed ? 'open' : '' ?>>
                <summary>
                    <span>
                        <span class="title"><?= htmlspecialchars($c['career_title']) ?></span>
                        <span class="status-tag status-<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span>
                        <?php if ($c['career_category']): ?>
                            <span class="status-tag" style="background:#f0dde1;color:#6e1423;"><?= htmlspecialchars($c['career_category']) ?></span>
                        <?php else: ?>
                            <span class="status-tag" style="background:#fff3cd;color:#856404;">uncategorized</span>
                        <?php endif; ?>
                    </span>
                    <span class="meta">source: <?= htmlspecialchars($c['source'] ?? 'seed') ?></span>
                </summary>

                <form method="POST" style="margin-top:14px;">
                    <input type="hidden" name="career_id" value="<?= (int) $c['career_id'] ?>">

                    <label>Career title</label>
                    <input type="text" name="career_title" value="<?= htmlspecialchars($c['career_title']) ?>" required>

                    <label>Category / Industry Cluster</label>
                    <select name="career_category" required>
                        <option value="">— Select a category —</option>
                        <?php foreach ($categoryOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['name']) ?>" title="<?= htmlspecialchars($opt['description'] ?? '') ?>" <?= $opt['name'] === ($c['career_category'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:13.5px;color:#888;margin:4px 0 0;">Need a new category, or want to edit what one covers? <a href="career_categories.php" style="color:#6e1423;">Manage Categories</a>.</p>

                    <label>Description</label>
                    <textarea name="description"><?= htmlspecialchars($c['description']) ?></textarea>

                    <label>Daily tasks</label>
                    <textarea name="daily_task"><?= htmlspecialchars($c['daily_task']) ?></textarea>

                    <label>Educational pathway</label>
                    <input type="text" name="educational_pathway" value="<?= htmlspecialchars($c['educational_pathway']) ?>">

                    <label>Key subjects (JHS/SHS subjects to focus on for this career)</label>
                    <input type="text" name="key_subjects" value="<?= htmlspecialchars($c['key_subjects'] ?? '') ?>" placeholder="e.g. Mathematics, Physics, Computer/ICT electives">

                    <label>Local or International?</label>
                    <select name="career_scope">
                        <option value="local" <?= ($c['career_scope'] ?? 'local') === 'local' ? 'selected' : '' ?>>🇵🇭 Local (Philippines)</option>
                        <option value="international" <?= ($c['career_scope'] ?? '') === 'international' ? 'selected' : '' ?>>🌍 International</option>
                    </select>
                    <p style="font-size:13.5px;color:#888;margin:4px 0 0;">Careers approved before this feature existed default to Local — correct it here if this one actually came from an international source. On the student results page, if a Local and International career share a similar title, both are shown side by side for the student's dream career.</p>

                    <label>
                        RIASEC scores (0–100)
                        <?php if ($wasRefreshed): ?><span style="color:#6f42c1;font-weight:normal;">— ✨ AI-suggested, review before saving</span><?php endif; ?>
                    </label>
                    <div class="riasec-grid">
                        <div>R<br><input type="text" name="r_score" value="<?= (int) $riasecDefaults['r'] ?>"></div>
                        <div>I<br><input type="text" name="i_score" value="<?= (int) $riasecDefaults['i'] ?>"></div>
                        <div>A<br><input type="text" name="a_score" value="<?= (int) $riasecDefaults['a'] ?>"></div>
                        <div>S<br><input type="text" name="s_score" value="<?= (int) $riasecDefaults['s'] ?>"></div>
                        <div>E<br><input type="text" name="e_score" value="<?= (int) $riasecDefaults['e'] ?>"></div>
                        <div>C<br><input type="text" name="c_score" value="<?= (int) $riasecDefaults['c'] ?>"></div>
                    </div>

                    <div class="actions">
                        <button type="submit" name="action" value="update" class="btn btn-primary">Save Changes</button>
                        <button type="submit" name="action" value="refresh_riasec" class="btn btn-outline">🔄 Refresh RIASEC with AI</button>
                    </div>
                </form>

                <form method="POST" class="actions" onsubmit="return confirm('<?= $c['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?> this career? <?= $c['status'] === 'active' ? 'It will stop appearing in student matches.' : 'It will start appearing in student matches again.' ?>');">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="career_id" value="<?= (int) $c['career_id'] ?>">
                    <button type="submit" class="btn btn-outline"><?= $c['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?></button>
                </form>

                <div style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <label style="margin-top:0;">Required skills (skills-verification mechanism)</label>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="ai_suggest_skills">
                            <input type="hidden" name="career_id" value="<?= (int) $c['career_id'] ?>">
                            <button type="submit" class="btn btn-outline" style="padding:4px 10px;font-size:13.5px;">✨ Suggest Skills with AI</button>
                        </form>
                    </div>
                    <?php if (empty($skillsByCareer[$c['career_id']])): ?>
                        <p class="empty" style="margin:4px 0 10px;">No skills defined yet for this career.</p>
                    <?php else: ?>
                        <div style="margin-bottom:10px;">
                            <?php foreach ($skillsByCareer[$c['career_id']] as $skill): ?>
                                <form method="POST" style="display:inline-block;margin:0 6px 6px 0;" onsubmit="return confirm('Remove this skill requirement?');">
                                    <input type="hidden" name="action" value="delete_skill">
                                    <input type="hidden" name="skill_req_id" value="<?= (int) $skill['skill_req_id'] ?>">
                                    <button type="submit" class="btn btn-outline" style="padding:4px 10px;font-size:13.5px;">
                                        <?= htmlspecialchars($skill['skill_name']) ?>
                                        <?php $profNote = trim($skill['proficiency_level'] ?? ''); ?>
                                        <?php if ($profNote !== '' || !$skill['is_required']): ?>
                                            (<?= htmlspecialchars($profNote) ?><?= ($profNote !== '' && !$skill['is_required']) ? ', ' : '' ?><?= $skill['is_required'] ? '' : 'optional' ?>)
                                        <?php endif; ?> ✕
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="hidden" name="action" value="add_skill">
                        <input type="hidden" name="career_id" value="<?= (int) $c['career_id'] ?>">
                        <input type="text" name="skill_name" placeholder="e.g. basic coding" style="flex:1;min-width:160px;" required>
                        <input type="text" name="proficiency_level" placeholder="What level is needed? (optional)" style="flex:1;min-width:200px;padding:6px 8px;border:1px solid #ccc;border-radius:4px;">
                        <label style="display:flex;align-items:center;gap:4px;font-weight:normal;margin:0;font-size:14.5px;">
                            <input type="checkbox" name="is_required" value="1" checked style="width:auto;"> Required
                        </label>
                        <button type="submit" class="btn btn-primary" style="padding:6px 14px;">Add Skill</button>
                    </form>
                </div>
            </details>
        <?php endforeach; ?>
    </div>

    <?php if ($missingSkillsCareerIds || $allCareerIds): ?>
    <script>
    (function () {
        // Drives both bulk-AI buttons above (skills backfill, RIASEC
        // refresh). Deliberately client-side rather than one big
        // server-side loop: dozens of careers x up to ~35s each for the
        // Gemini call (see ENRICH_SERVICE_URL timeout in the PHP handlers)
        // would very likely blow past PHP's max_execution_time and/or
        // Railway's proxy request timeout if done as a single POST.
        // Instead each button fires its existing single-career POST action
        // once per career, sequentially (not in parallel -- the
        // matching-service is a single Flask process, see migration notes
        // on concurrency) via fetch() so the page never navigates away and
        // can show progress.
        var titleById = {};
        document.querySelectorAll('.career-row[data-career-id]').forEach(function (row) {
            titleById[row.dataset.careerId] = row.dataset.title;
        });

        // Both buttons hit the same matching-service process, so running
        // them at the same time would just make each other slower (and
        // muddy the progress text) rather than anything actually going
        // wrong -- this just disables whichever button isn't running so
        // it's not confusing to click both at once.
        var allBulkButtons = [];

        function runBulkAction(opts) {
            var btn = document.getElementById(opts.btnId);
            if (!btn) return;
            allBulkButtons.push(btn);
            var statusBox = document.getElementById(opts.statusId);
            var bar = document.getElementById(opts.barId);
            var text = document.getElementById(opts.textId);

            btn.addEventListener('click', function () {
                if (!confirm(opts.confirmText)) {
                    return;
                }
                allBulkButtons.forEach(function (b) { b.disabled = true; });
                btn.textContent = 'Running…';
                statusBox.style.display = 'block';

                var i = 0;
                var succeeded = 0;
                var failed = 0;

                function next() {
                    if (i >= opts.ids.length) {
                        text.textContent = 'Done — ' + succeeded + ' career(s) updated' + (failed ? ', ' + failed + ' failed (try those individually above)' : '') + '. Reloading…';
                        setTimeout(function () { location.reload(); }, 1200);
                        return;
                    }
                    var id = opts.ids[i];
                    var pct = Math.round((i / opts.ids.length) * 100);
                    bar.style.width = pct + '%';
                    text.textContent = opts.progressVerb + ' ' + (i + 1) + ' of ' + opts.ids.length + ': ' + (titleById[id] || ('career #' + id)) + '…';

                    fetch(window.location.pathname + window.location.search, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=' + opts.action + '&career_id=' + encodeURIComponent(id),
                    }).then(function (resp) {
                        // The PHP handler always responds 200 (it renders the
                        // full page either way, success or "AI unavailable") --
                        // resp.ok alone can't tell success from failure, so check
                        // which flash-message class the response actually
                        // contains instead of trusting the HTTP status.
                        return resp.text();
                    }).then(function (html) {
                        if (html.indexOf('flash-success') !== -1) { succeeded++; } else { failed++; }
                    }).catch(function () {
                        failed++;
                    }).finally(function () {
                        i++;
                        next();
                    });
                }
                next();
            });
        }

        <?php if ($missingSkillsCareerIds): ?>
        runBulkAction({
            ids: <?= json_encode($missingSkillsCareerIds) ?>,
            action: 'ai_suggest_skills',
            btnId: 'bulk-skill-fill-btn',
            statusId: 'bulk-skill-fill-status',
            barId: 'bulk-skill-fill-bar',
            textId: 'bulk-skill-fill-text',
            progressVerb: 'Suggesting skills',
            confirmText: 'Run AI skill suggestion for <?= count($missingSkillsCareerIds) ?> career(s)? This calls Gemini once per career and can take a few minutes total -- keep this tab open until it finishes.',
        });
        <?php endif; ?>

        <?php if ($allCareerIds): ?>
        runBulkAction({
            ids: <?= json_encode($allCareerIds) ?>,
            action: 'bulk_apply_riasec',
            btnId: 'bulk-riasec-refresh-btn',
            statusId: 'bulk-riasec-refresh-status',
            barId: 'bulk-riasec-refresh-bar',
            textId: 'bulk-riasec-refresh-text',
            progressVerb: 'Refreshing RIASEC',
            confirmText: 'Refresh AI-suggested RIASEC scores for ALL <?= count($allCareerIds) ?> career(s) listed? This OVERWRITES existing scores immediately for each one (logged to Change History, individually revertible) and calls Gemini once per career -- keep this tab open until it finishes.',
        });
        <?php endif; ?>
    })();
    </script>
    <?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
