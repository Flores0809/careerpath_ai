<?php
// CareerPath AI - Career Review Queue (Counselor + Administrator)
//
// Lists everything the crawler (crawler/crawler.py) staged in pending_careers
// and lets a counselor (or administrator) edit + approve entries into the
// live `careers` table, or reject them. This matches the paper's design:
// crawled data always passes through human review before students ever see it.
//
// Formerly admin.php — renamed now that account management (users.php,
// administrator-only) is a separate page from career review (this page,
// open to both roles).

require __DIR__ . '/auth.php';

$currentUser = require_role(['administrator', 'counselor']);
$pdo = get_db();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pendingId = (int) ($_POST['pending_id'] ?? 0);

    if ($action === 'approve' && $pendingId) {
        $careerTitle = trim($_POST['career_title'] ?? '');
        $careerCategory = trim($_POST['career_category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $dailyTask = trim($_POST['daily_task'] ?? '');
        $educationalPathway = trim($_POST['educational_pathway'] ?? '');
        $keySubjects = trim($_POST['key_subjects'] ?? '');
        $scores = [
            'r' => (int) ($_POST['r_score'] ?? 0),
            'i' => (int) ($_POST['i_score'] ?? 0),
            'a' => (int) ($_POST['a_score'] ?? 0),
            's' => (int) ($_POST['s_score'] ?? 0),
            'e' => (int) ($_POST['e_score'] ?? 0),
            'c' => (int) ($_POST['c_score'] ?? 0),
        ];

        if ($careerTitle === '') {
            $message = ['type' => 'error', 'text' => 'Career title cannot be empty.'];
        } elseif ($careerCategory === '') {
            // No "Other" fallback — every career needs a real cluster so it's
            // eligible to appear in the assessment's dream-career picker.
            $message = ['type' => 'error', 'text' => 'Category / Industry Cluster is required before approving.'];
        } else {
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare(
                    "INSERT INTO careers
                        (career_title, career_category, description, daily_task, educational_pathway, key_subjects,
                         r_score, i_score, a_score, s_score, e_score, c_score, source, status)
                     VALUES (:title, :category, :description, :daily_task, :pathway, :key_subjects,
                             :r, :i, :a, :s, :e, :c, 'crawler', 'active')"
                );
                $insert->execute([
                    'title' => $careerTitle,
                    'category' => $careerCategory,
                    'description' => $description,
                    'daily_task' => $dailyTask,
                    'pathway' => $educationalPathway,
                    'key_subjects' => $keySubjects !== '' ? $keySubjects : null,
                    'r' => $scores['r'], 'i' => $scores['i'], 'a' => $scores['a'],
                    's' => $scores['s'], 'e' => $scores['e'], 'c' => $scores['c'],
                ]);

                $update = $pdo->prepare(
                    "UPDATE pending_careers SET status = 'approved', reviewed_at = NOW(), reviewed_by = :uid WHERE pending_id = :id"
                );
                $update->execute(['uid' => $currentUser['user_id'], 'id' => $pendingId]);

                $pdo->commit();
                $message = ['type' => 'success', 'text' => "Approved \"$careerTitle\" into the live career database."];
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = ['type' => 'error', 'text' => 'Failed to approve: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'reject' && $pendingId) {
        $update = $pdo->prepare(
            "UPDATE pending_careers SET status = 'rejected', reviewed_at = NOW(), reviewed_by = :uid WHERE pending_id = :id"
        );
        $update->execute(['uid' => $currentUser['user_id'], 'id' => $pendingId]);
        $message = ['type' => 'success', 'text' => 'Entry rejected and removed from the review queue.'];
    } elseif ($action === 'enrich' && $pendingId) {
        // Always enrich from the canonical raw scraped fields in the DB
        // (not whatever's currently typed in the form), so re-running
        // "Enrich with AI" is repeatable and doesn't compound edits.
        $stmt = $pdo->prepare("SELECT source_title, description, qualifications FROM pending_careers WHERE pending_id = :id");
        $stmt->execute(['id' => $pendingId]);
        $row = $stmt->fetch();

        if (!$row) {
            $message = ['type' => 'error', 'text' => 'Could not find that entry to enrich.'];
        } else {
            $payload = json_encode([
                'career_title' => $row['source_title'],
                'raw_description' => $row['description'],
                'raw_qualifications' => $row['qualifications'],
            ]);

            $ch = curl_init(ENRICH_SERVICE_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 35, // enrichment calls an LLM, give it room
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $result = $curlError ? null : json_decode($response, true);

            if ($curlError || $httpCode !== 200 || !$result || empty($result['ai_enriched'])) {
                // Fallback per the paper's design: leave raw scraped fields
                // untouched and just tell the reviewer AI enrichment didn't run.
                $reason = $curlError ?: ($result['error'] ?? "HTTP $httpCode");
                $message = [
                    'type' => 'error',
                    'text' => "AI enrichment unavailable ($reason). The raw scraped data is still there, unchanged — you can edit it manually or try again.",
                ];
            } else {
                $update = $pdo->prepare(
                    "UPDATE pending_careers SET
                        ai_description = :description,
                        ai_daily_task = :daily_task,
                        ai_educational_pathway = :pathway,
                        ai_r_score = :r, ai_i_score = :i, ai_a_score = :a,
                        ai_s_score = :s, ai_e_score = :e, ai_c_score = :c,
                        ai_enriched_at = NOW()
                     WHERE pending_id = :id"
                );
                $update->execute([
                    'description' => $result['description'],
                    'daily_task' => $result['daily_task'],
                    'pathway' => $result['educational_pathway'],
                    'r' => $result['riasec']['R'], 'i' => $result['riasec']['I'], 'a' => $result['riasec']['A'],
                    's' => $result['riasec']['S'], 'e' => $result['riasec']['E'], 'c' => $result['riasec']['C'],
                    'id' => $pendingId,
                ]);
                $message = ['type' => 'success', 'text' => 'AI enrichment complete — review the updated fields below before approving.'];
            }
        }
    }
}

$sourceLabels = [
    'philjobnet' => 'PhilJobNet (Philippines)',
    'onet' => 'O*NET (International)',
    'adzuna' => 'Adzuna (International)',
    'remoteok' => 'RemoteOK (International)',
];
$sourceFilter = $_GET['source'] ?? '';
if (!array_key_exists($sourceFilter, $sourceLabels)) {
    $sourceFilter = '';
}

if ($sourceFilter !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM pending_careers WHERE status = 'pending' AND data_source = :source ORDER BY scraped_at DESC"
    );
    $stmt->execute(['source' => $sourceFilter]);
    $pending = $stmt->fetchAll();
} else {
    $pending = $pdo->query(
        "SELECT * FROM pending_careers WHERE status = 'pending' ORDER BY scraped_at DESC"
    )->fetchAll();
}

$counts = $pdo->query(
    "SELECT status, COUNT(*) as n FROM pending_careers GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$sourceCounts = $pdo->query(
    "SELECT data_source, COUNT(*) as n FROM pending_careers WHERE status = 'pending' GROUP BY data_source"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Category / industry-cluster options — sourced from the career_categories
// lookup table (migration_13_category_management.sql), same as
// php/careers_manage.php, so newly-approved careers land with a consistent,
// managed cluster label the dream-career picker can group by.
$categoryOptions = $pdo->query("SELECT name, description FROM career_categories ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CareerPath AI — Career Review Queue</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    h1, .counts, .flash-success, .flash-error, .filter-bar, .card, .empty { max-width: 900px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .counts { margin-bottom: 24px; font-size: 14px; color: #555; }
    .counts span { margin-right: 16px; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .card { border: 1px solid #ddd; border-radius: 8px; padding: 18px 22px; margin-bottom: 22px; }
    .card h3 { margin-top: 0; color: #6e1423; }
    .meta { font-size: 13px; color: #666; margin-bottom: 10px; }
    .meta a { color: #6e1423; }
    label { display: block; font-size: 13px; font-weight: bold; margin: 10px 0 4px; }
    input[type=text], textarea { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    .card select[name=career_category] { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    textarea { min-height: 60px; }
    .riasec-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-top: 8px; }
    .riasec-grid div { text-align: center; }
    .riasec-grid input { text-align: center; }
    .actions { margin-top: 14px; }
    button { padding: 8px 18px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; margin-right: 8px; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .approve { background: #6e1423; color: #fff; }
    .reject { background: #b02a37; color: #fff; }
    .enrich { background: #6f42c1; color: #fff; }
    .ai-badge { color: #6f42c1; font-weight: bold; }
    .empty { color: #666; font-style: italic; }
    .source-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 6px; }
    .source-philjobnet { background: #f0dde1; color: #6e1423; }
    .source-onet { background: #e7d9f7; color: #4b2e83; }
    .source-adzuna { background: #d1e7dd; color: #0f5132; }
    .source-remoteok { background: #fff3cd; color: #856404; }
    .filter-bar { margin-bottom: 18px; font-size: 14px; }
    .filter-bar select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>Career Review Queue</h1>

    <div class="counts">
        <span><strong>Pending:</strong> <?= $counts['pending'] ?? 0 ?></span>
        <span><strong>Approved:</strong> <?= $counts['approved'] ?? 0 ?></span>
        <span><strong>Rejected:</strong> <?= $counts['rejected'] ?? 0 ?></span>
    </div>

    <?php if ($message): ?>
        <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="filter-bar">
        <form method="GET">
            <label style="display:inline;font-weight:bold;">Filter by source:</label>
            <select name="source" onchange="this.form.submit()">
                <option value="">All sources (<?= array_sum($sourceCounts) ?>)</option>
                <?php foreach ($sourceLabels as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $sourceFilter === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?> (<?= $sourceCounts[$key] ?? 0 ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (!$pending): ?>
        <p class="empty">
            No pending entries<?= $sourceFilter !== '' ? ' from ' . htmlspecialchars($sourceLabels[$sourceFilter]) : '' ?>.
            Run <code>python crawler/crawler.py</code> (Philippines), <code>python crawler/onet_client.py</code>,
            <code>python crawler/adzuna_client.py</code>, or <code>python crawler/remoteok_client.py</code>
            (international) to fetch more.
        </p>
    <?php endif; ?>

    <?php foreach ($pending as $row): ?>
        <?php
            $isEnriched = !empty($row['ai_enriched_at']);
            // Prefer AI-enriched fields when present, fall back to raw scraped fields.
            $descriptionDefault = $row['ai_description'] ?? $row['description'] ?? '';
            $dailyTaskDefault = $row['ai_daily_task'] ?? $row['qualifications'] ?? '';
            $pathwayDefault = $row['ai_educational_pathway'] ?? $row['education_level'] ?? '';
            $rDefault = $row['ai_r_score'] ?? $row['suggested_r_score'];
            $iDefault = $row['ai_i_score'] ?? $row['suggested_i_score'];
            $aDefault = $row['ai_a_score'] ?? $row['suggested_a_score'];
            $sDefault = $row['ai_s_score'] ?? $row['suggested_s_score'];
            $eDefault = $row['ai_e_score'] ?? $row['suggested_e_score'];
            $cDefault = $row['ai_c_score'] ?? $row['suggested_c_score'];
        ?>
        <div class="card">
            <div class="meta">
                Scraped <?= htmlspecialchars($row['scraped_at']) ?> ·
                Keyword: <?= htmlspecialchars($row['search_keyword']) ?> ·
                <?= htmlspecialchars($row['country'] ?? '—') ?>
                <span class="source-tag source-<?= htmlspecialchars($row['data_source']) ?>"><?= htmlspecialchars($sourceLabels[$row['data_source']] ?? $row['data_source']) ?></span>
                ·
                <a href="<?= htmlspecialchars($row['source_url']) ?>" target="_blank" rel="noopener">View original posting</a>
                <?php if ($isEnriched): ?>
                    · <span class="ai-badge">✨ AI-enriched <?= htmlspecialchars($row['ai_enriched_at']) ?></span>
                <?php endif; ?>
            </div>
            <h3><?= htmlspecialchars($row['source_title'] ?? '(untitled)') ?></h3>
            <p class="meta">
                <?= htmlspecialchars($row['employer'] ?? '—') ?> ·
                <?= htmlspecialchars($row['location'] ?? '—') ?> ·
                <?= htmlspecialchars($row['education_level'] ?? '—') ?> ·
                <?= htmlspecialchars($row['employment_type'] ?? '—') ?> ·
                <?= htmlspecialchars($row['salary'] ?? '—') ?>
            </p>

            <form method="POST">
                <input type="hidden" name="pending_id" value="<?= (int) $row['pending_id'] ?>">

                <div class="actions" style="margin-top:0;">
                    <button type="submit" name="action" value="enrich" class="enrich">✨ Enrich with AI</button>
                    <?php if ($isEnriched): ?><span class="meta">Fields below are pre-filled from the AI response — edit freely before approving.</span><?php endif; ?>
                </div>

                <label>Career title (this is what students will see)</label>
                <input type="text" name="career_title" value="<?= htmlspecialchars($row['source_title'] ?? '') ?>" required>

                <label>Category / Industry Cluster</label>
                <select name="career_category" required>
                    <option value="">— Select a category —</option>
                    <?php foreach ($categoryOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['name']) ?>" title="<?= htmlspecialchars($opt['description'] ?? '') ?>" <?= $opt['name'] === ($row['career_category'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($opt['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="font-size:12px;color:#888;margin:4px 0 0;">Need a new category, or want to edit what one covers? <a href="career_categories.php" style="color:#6e1423;">Manage Categories</a>.</p>

                <label>Description</label>
                <textarea name="description"><?= htmlspecialchars($descriptionDefault) ?></textarea>

                <label>Daily tasks / qualifications</label>
                <textarea name="daily_task"><?= htmlspecialchars($dailyTaskDefault) ?></textarea>

                <label>Educational pathway</label>
                <input type="text" name="educational_pathway" value="<?= htmlspecialchars($pathwayDefault) ?>">

                <label>Key subjects (JHS/SHS subjects to focus on for this career)</label>
                <input type="text" name="key_subjects" value="<?= htmlspecialchars($row['key_subjects'] ?? '') ?>" placeholder="e.g. Mathematics, Physics, Computer/ICT electives">

                <label>RIASEC scores (0–100 — from AI if enriched, otherwise the crawler's keyword-based guess; adjust before approving)</label>
                <div class="riasec-grid">
                    <div>R<br><input type="text" name="r_score" value="<?= (int) $rDefault ?>"></div>
                    <div>I<br><input type="text" name="i_score" value="<?= (int) $iDefault ?>"></div>
                    <div>A<br><input type="text" name="a_score" value="<?= (int) $aDefault ?>"></div>
                    <div>S<br><input type="text" name="s_score" value="<?= (int) $sDefault ?>"></div>
                    <div>E<br><input type="text" name="e_score" value="<?= (int) $eDefault ?>"></div>
                    <div>C<br><input type="text" name="c_score" value="<?= (int) $cDefault ?>"></div>
                </div>

                <div class="actions">
                    <button type="submit" name="action" value="approve" class="approve">Approve into career database</button>
                    <button type="submit" name="action" value="reject" class="reject" onclick="return confirm('Reject this entry?');">Reject</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</body>
</html>
