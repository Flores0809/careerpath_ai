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

$sourceLabels = [
    'philjobnet' => 'PhilJobNet (Philippines)',
    'onet' => 'O*NET (International)',
    'adzuna' => 'Adzuna (International)',
    'remoteok' => 'RemoteOK (International)',
];

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
    } elseif ($action === 'run_crawler') {
        // Starts crawler/*.py as a background subprocess on the matching
        // service (see matching-service/app.py's CrawlResource) so a
        // counselor/admin never has to open a terminal to run it. This
        // request returns immediately — the crawl itself keeps running in
        // the background and results are polled via CRAWL_STATUS_SERVICE_URL.
        $crawlSource = $_POST['source'] ?? '';
        if (!array_key_exists($crawlSource, $sourceLabels)) {
            $message = ['type' => 'error', 'text' => 'Unknown crawler source.'];
        } else {
            $ch = curl_init(CRAWL_SERVICE_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['source' => $crawlSource]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10, // just needs to confirm the subprocess started, not wait for it
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $result = $curlError ? null : json_decode($response, true);

            if ($curlError) {
                $message = [
                    'type' => 'error',
                    'text' => "Could not reach the matching service ($curlError). Make sure python app.py (matching-service) is running.",
                ];
            } elseif (!$result || empty($result['started'])) {
                $message = ['type' => 'error', 'text' => 'Crawler could not start: ' . ($result['error'] ?? "HTTP $httpCode")];
            } else {
                $message = [
                    'type' => 'success',
                    'text' => "{$sourceLabels[$crawlSource]} crawler started in the background. New entries will appear in Pending as they're found — refresh in a minute or two, or watch the status below.",
                ];
            }
        }
    }
}

$sourceFilter = $_GET['source'] ?? '';
if (!array_key_exists($sourceFilter, $sourceLabels)) {
    $sourceFilter = '';
}

// Only meaningful on the Pending tab (that's the only place entries get
// split into New/Older — see below). '' means "show both".
$ageFilter = $_GET['age'] ?? '';
if (!in_array($ageFilter, ['new', 'older'], true)) {
    $ageFilter = '';
}
$showNew = $ageFilter !== 'older';
$showOlder = $ageFilter !== 'new';

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $statusFilter = 'pending';
}

if ($sourceFilter !== '') {
    $stmt = $pdo->prepare(
        "SELECT pc.*, u.name AS reviewer_name FROM pending_careers pc
         LEFT JOIN users u ON u.user_id = pc.reviewed_by
         WHERE pc.status = :status AND pc.data_source = :source
         ORDER BY pc.status = 'pending' DESC, pc.scraped_at DESC, pc.reviewed_at DESC"
    );
    $stmt->execute(['status' => $statusFilter, 'source' => $sourceFilter]);
    $pending = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT pc.*, u.name AS reviewer_name FROM pending_careers pc
         LEFT JOIN users u ON u.user_id = pc.reviewed_by
         WHERE pc.status = :status
         ORDER BY pc.status = 'pending' DESC, pc.scraped_at DESC, pc.reviewed_at DESC"
    );
    $stmt->execute(['status' => $statusFilter]);
    $pending = $stmt->fetchAll();
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

// Split "New" (scraped in the last 24 hours) vs everything else, so a
// fresh crawl doesn't just get buried among however many older entries
// were already sitting in the queue.
$newPending = [];
$olderPending = [];

if ($statusFilter === 'pending') {
    // Flag entries that look like a duplicate of a career that's already
    // approved — stays entirely within the Pending tab as a small badge on
    // the card (see render_pending_card()); doesn't touch the careers
    // table, doesn't need its own tab/section, and doesn't change how
    // Approve/Reject work. Staff still decide either way, same buttons as
    // always — this just gives them a heads-up before they do.
    //
    // Matching compares the scraped posting's own title (source_title)
    // against each active career's title with PHP's similar_text() (a
    // character-overlap percentage) — exact match, or >=55% similar. Rule-
    // based and explainable, not perfect, but good enough to flag the
    // obvious case (e.g. a re-scraped "Welder" posting when "Welder" is
    // already an approved career).
    $approvedCareerTitles = $pdo->query("SELECT career_title FROM careers WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);

    $findDuplicateTitle = function (string $sourceTitle) use ($approvedCareerTitles): ?string {
        $needle = strtolower(trim($sourceTitle));
        if ($needle === '') {
            return null;
        }
        $bestMatch = null;
        $bestPercent = 0.0;
        foreach ($approvedCareerTitles as $title) {
            $normalized = strtolower(trim($title));
            if ($normalized === $needle) {
                return $title; // exact match — most confident, stop here
            }
            similar_text($normalized, $needle, $percent);
            if ($percent > $bestPercent) {
                $bestPercent = $percent;
                $bestMatch = $title;
            }
        }
        return $bestPercent >= 55 ? $bestMatch : null;
    };

    $newCutoff = time() - 86400;
    foreach ($pending as $row) {
        $row['_duplicate_of'] = $findDuplicateTitle($row['source_title'] ?? '');
        if (strtotime($row['scraped_at']) >= $newCutoff) {
            $newPending[] = $row;
        } else {
            $olderPending[] = $row;
        }
    }
}
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
    .card { background: #f5f5f5; border: 1px solid #ddd; border-radius: 8px; padding: 18px 22px; margin-bottom: 22px; }
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
    .section-heading { color: #6e1423; font-size: 16px; margin: 26px 0 10px; padding-top: 4px; border-top: 1px solid #eee; }
    .section-heading:first-of-type { border-top: none; padding-top: 0; margin-top: 4px; }
    .new-section-heading { border-top: none; margin-top: 4px; }
    .new-badge { display: inline-block; background: #ffc107; color: #664d03; font-weight: bold; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-right: 6px; }
    .duplicate-badge { background: #fdecea; color: #842029; border: 1px solid #f5c2c7; border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: bold; margin-bottom: 12px; }
    .source-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 6px; }
    .source-philjobnet { background: #f0dde1; color: #6e1423; }
    .source-onet { background: #e7d9f7; color: #4b2e83; }
    .source-adzuna { background: #d1e7dd; color: #0f5132; }
    .source-remoteok { background: #fff3cd; color: #856404; }
    .filter-bar { margin-bottom: 18px; font-size: 14px; }
    .filter-bar select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }

    /* Status tabs */
    .tabs { max-width: 900px; margin: 24px auto 0; display: flex; gap: 4px; border-bottom: 2px solid #eee; flex-wrap: wrap; }
    .tab-btn { background: none; border: none; padding: 10px 18px; font-size: 14px; font-weight: bold; color: #888; cursor: pointer; text-decoration: none; display: inline-block; border-bottom: 3px solid transparent; margin-bottom: -2px; font-family: inherit; }
    .tab-btn:hover { color: #6e1423; }
    .tab-btn.active { color: #6e1423; border-bottom-color: #6e1423; }
    .tab-count { display: inline-block; background: #eee; color: #555; border-radius: 10px; padding: 1px 8px; font-size: 11px; margin-left: 5px; }
    .tab-btn.active .tab-count { background: #f0dde1; color: #6e1423; }

    /* Search bar */
    .search-bar { position: relative; max-width: 340px; margin: 18px auto 4px; }
    .search-bar input { width: 100%; padding: 9px 14px 9px 32px; border: 1px solid #ccc; border-radius: 20px; font-size: 14px; box-sizing: border-box; }
    .search-bar input:focus { outline: none; border-color: #6e1423; box-shadow: 0 0 0 2px rgba(110,20,35,0.12); }
    .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; opacity: 0.55; pointer-events: none; }
    .no-results { max-width: 900px; margin: 20px auto; text-align: center; color: #888; font-style: italic; }

    /* Read-only reviewed (approved/rejected) rows */
    .reviewed-row { background: #f5f5f5; border: 1px solid #ddd; border-radius: 8px; padding: 12px 18px; margin-bottom: 12px; font-size: 14px; }
    .reviewed-row .title { font-weight: bold; color: #6e1423; }
    .reviewed-row .meta { margin-top: 4px; }

    /* Run Web Crawler panel */
    .crawler-panel { max-width: 900px; margin: 0 auto 20px; background: #faf0f1; border: 1px solid #f0dde1; border-radius: 8px; padding: 16px 20px; }
    .crawler-panel-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .crawler-panel select { padding: 7px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; font-size: 13px; }
    .crawler-panel button { background: #6e1423; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-size: 13px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .crawler-panel button:hover:not(:disabled) { background: #4a0c17; }
    .crawler-panel button:disabled { opacity: 0.6; cursor: not-allowed; }
    .crawler-panel .hint { font-size: 12px; color: #888; margin-top: 6px; }
    .crawler-status { margin-top: 10px; font-size: 13px; }
    .crawler-status .spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid #6e1423; border-top-color: transparent; border-radius: 50%; animation: crawler-spin 0.7s linear infinite; margin-right: 6px; vertical-align: -1px; }
    @keyframes crawler-spin { to { transform: rotate(360deg); } }
    .crawler-log { margin-top: 8px; background: #222; color: #d9f7d9; font-family: monospace; font-size: 11px; padding: 10px 12px; border-radius: 6px; max-height: 160px; overflow-y: auto; white-space: pre-wrap; display: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>Career Review Queue</h1>

    <div class="crawler-panel">
        <form method="POST" class="crawler-panel-row">
            <input type="hidden" name="action" value="run_crawler">
            <strong>Run Web Crawler:</strong>
            <select name="source" id="crawler-source">
                <option value="philjobnet">PhilJobNet (Philippines — no setup needed)</option>
                <option value="remoteok">RemoteOK (International — no setup needed)</option>
                <option value="onet">O*NET (International — needs ONET_USERNAME/PASSWORD)</option>
                <option value="adzuna">Adzuna (International — needs ADZUNA_APP_ID/KEY)</option>
            </select>
            <button type="submit">▶ Run Crawler</button>
        </form>
        <p class="hint">Runs in the background on the matching service (must be running — see <code>!START_HERE - Run Matching Service.bat</code>). New postings appear in the Pending tab as they're found; this page won't freeze while it runs.</p>
        <div class="crawler-status" id="crawler-status"></div>
        <pre class="crawler-log" id="crawler-log"></pre>
    </div>

    <div class="tabs">
        <a class="tab-btn <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="?status=pending<?= $sourceFilter !== '' ? '&source=' . urlencode($sourceFilter) : '' ?>">Pending <span class="tab-count"><?= $counts['pending'] ?? 0 ?></span></a>
        <a class="tab-btn <?= $statusFilter === 'approved' ? 'active' : '' ?>" href="?status=approved<?= $sourceFilter !== '' ? '&source=' . urlencode($sourceFilter) : '' ?>">Approved <span class="tab-count"><?= $counts['approved'] ?? 0 ?></span></a>
        <a class="tab-btn <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="?status=rejected<?= $sourceFilter !== '' ? '&source=' . urlencode($sourceFilter) : '' ?>">Rejected <span class="tab-count"><?= $counts['rejected'] ?? 0 ?></span></a>
    </div>

    <?php if ($message): ?>
        <div class="flash-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="filter-bar">
        <form method="GET">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <label style="display:inline;font-weight:bold;">Filter by source:</label>
            <select name="source" onchange="this.form.submit()">
                <option value="">All sources (<?= array_sum($sourceCounts) ?>)</option>
                <?php foreach ($sourceLabels as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $sourceFilter === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?> (<?= $sourceCounts[$key] ?? 0 ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($statusFilter === 'pending'): ?>
                <label style="display:inline;font-weight:bold;margin-left:16px;">Filter by age:</label>
                <select name="age" onchange="this.form.submit()">
                    <option value="" <?= $ageFilter === '' ? 'selected' : '' ?>>All entries (<?= count($newPending) + count($olderPending) ?>)</option>
                    <option value="new" <?= $ageFilter === 'new' ? 'selected' : '' ?>>🆕 New — last 24 hours (<?= count($newPending) ?>)</option>
                    <option value="older" <?= $ageFilter === 'older' ? 'selected' : '' ?>>Older entries (<?= count($olderPending) ?>)</option>
                </select>
            <?php endif; ?>
        </form>
    </div>

    <div class="search-bar">
        <span class="search-icon">🔍</span>
        <input type="text" id="career-search" placeholder="Search by title or keyword...">
    </div>

    <?php
        $filteredPendingCount = $statusFilter === 'pending'
            ? ($showNew ? count($newPending) : 0) + ($showOlder ? count($olderPending) : 0)
            : count($pending);
    ?>
    <?php if ($filteredPendingCount === 0): ?>
        <p class="empty">
            No <?= htmlspecialchars($statusFilter) ?> entries<?= $sourceFilter !== '' ? ' from ' . htmlspecialchars($sourceLabels[$sourceFilter]) : '' ?><?= $ageFilter === 'new' ? ' scraped in the last 24 hours' : ($ageFilter === 'older' ? ' older than 24 hours' : '') ?>.
            <?php if ($statusFilter === 'pending'): ?>
                Use "Run Web Crawler" above to fetch more, or run one of the scripts manually:
                <code>python crawler/crawler.py</code> (Philippines), <code>python crawler/onet_client.py</code>,
                <code>python crawler/adzuna_client.py</code>, or <code>python crawler/remoteok_client.py</code>
                (international).
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <div id="no-search-results" class="no-results" style="display:none;">No entries match your search.</div>

    <?php if ($statusFilter !== 'pending'): ?>
        <?php foreach ($pending as $row): ?>
            <div class="reviewed-row" data-search="<?= htmlspecialchars(strtolower(($row['source_title'] ?? '') . ' ' . ($row['search_keyword'] ?? ''))) ?>">
                <div class="title"><?= htmlspecialchars($row['source_title'] ?? '(untitled)') ?>
                    <span class="source-tag source-<?= htmlspecialchars($row['data_source']) ?>"><?= htmlspecialchars($sourceLabels[$row['data_source']] ?? $row['data_source']) ?></span>
                </div>
                <div class="meta">
                    <?= htmlspecialchars($row['employer'] ?? '—') ?> ·
                    <?= htmlspecialchars($row['career_category'] ?? '—') ?> ·
                    Reviewed <?= htmlspecialchars($row['reviewed_at'] ?? '—') ?>
                    <?php if (!empty($row['reviewer_name'])): ?>by <?= htmlspecialchars($row['reviewer_name']) ?><?php endif; ?>
                    · <a href="<?= htmlspecialchars($row['source_url']) ?>" target="_blank" rel="noopener">View original posting</a>
                    <?php if ($statusFilter === 'approved'): ?>
                        · <a href="careers_manage.php">Edit in Manage Careers</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <?php
            // Renders one pending-review card. Pulled into a function so the
            // "New" / "Older" grouping below can call it twice without
            // duplicating this whole block.
            function render_pending_card(array $row, array $sourceLabels, array $categoryOptions, bool $isNew): void
            {
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
        <div class="card" data-search="<?= htmlspecialchars(strtolower(($row['source_title'] ?? '') . ' ' . ($row['search_keyword'] ?? ''))) ?>">
            <?php if (!empty($row['_duplicate_of'])): ?>
                <div class="duplicate-badge">⚠️ Duplicate from approved careers: "<?= htmlspecialchars($row['_duplicate_of']) ?>" is already approved — consider rejecting this entry.</div>
            <?php endif; ?>
            <div class="meta">
                <?php if ($isNew): ?><span class="new-badge">🆕 New</span><?php endif; ?>
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
        <?php
            }
        ?>

        <?php if ($showNew && $newPending): ?>
            <h2 class="section-heading new-section-heading">🆕 New — added in the last 24 hours (<?= count($newPending) ?>)</h2>
            <?php foreach ($newPending as $row): render_pending_card($row, $sourceLabels, $categoryOptions, true); endforeach; ?>
        <?php endif; ?>

        <?php if ($showOlder && $olderPending): ?>
            <h2 class="section-heading <?= ($showNew && $newPending) ? '' : 'new-section-heading' ?>">Older entries (<?= count($olderPending) ?>)</h2>
            <?php foreach ($olderPending as $row): render_pending_card($row, $sourceLabels, $categoryOptions, false); endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <script>
    (function () {
        var searchInput = document.getElementById('career-search');
        var noResults = document.getElementById('no-search-results');
        searchInput.addEventListener('input', function () {
            var q = searchInput.value.trim().toLowerCase();
            var items = document.querySelectorAll('[data-search]');
            var visibleCount = 0;
            items.forEach(function (item) {
                var match = item.dataset.search.indexOf(q) !== -1;
                item.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });
            noResults.style.display = (items.length && visibleCount === 0 && q !== '') ? 'block' : 'none';
        });
    })();

    // Live status for the "Run Web Crawler" panel — polls crawler_status.php
    // (same-origin proxy to the matching service) every few seconds so staff
    // can watch a crawl finish without manually refreshing the page.
    (function () {
        var sourceSelect = document.getElementById('crawler-source');
        var statusEl = document.getElementById('crawler-status');
        var logEl = document.getElementById('crawler-log');
        if (!sourceSelect || !statusEl) return;

        function poll() {
            var source = sourceSelect.value;
            fetch('crawler_status.php?source=' + encodeURIComponent(source))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.state === 'running') {
                        statusEl.innerHTML = '<span class="spinner"></span>Running for ' + data.elapsed_seconds + 's...';
                    } else if (data.state === 'finished' && data.exit_code === 0) {
                        statusEl.textContent = 'Finished (ran for ' + data.elapsed_seconds + 's). Check the Pending tab for new entries.';
                    } else if (data.state === 'finished') {
                        statusEl.textContent = 'Crashed after ' + data.elapsed_seconds + 's (exit code ' + data.exit_code + ') — see the log below.';
                    } else if (data.state === 'unreachable') {
                        statusEl.textContent = 'Matching service not reachable — make sure it is running.';
                    } else {
                        statusEl.textContent = '';
                    }
                    if (data.log_tail) {
                        logEl.textContent = data.log_tail;
                        logEl.style.display = 'block';
                        logEl.scrollTop = logEl.scrollHeight;
                    } else {
                        logEl.style.display = 'none';
                    }
                })
                .catch(function () { /* matching service likely not running yet — stay quiet, the run button's own error message already covers this */ });
        }

        poll();
        setInterval(poll, 4000);
        sourceSelect.addEventListener('change', poll);
    })();
    </script>
</body>
</html>
