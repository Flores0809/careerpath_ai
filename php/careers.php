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
require_once __DIR__ . '/change_log_helper.php';

$currentUser = require_role(['administrator', 'counselor']);
$pdo = get_db();
$message = null;

// Cards render collapsed by default (see render_pending_card()) so 84
// pending entries don't mean 84 fully-expanded forms on screen at once.
// Whichever entry the counselor just acted on (enriched, or hit a
// validation error on approve/reject) stays expanded after the page
// reloads, so they don't lose their spot.
$focusPendingId = (int) ($_POST['pending_id'] ?? 0);

$sourceLabels = [
    'philjobnet' => 'PhilJobNet (Philippines)',
    'kalibrr' => 'Kalibrr (Philippines)',
    'onet' => 'O*NET (International)',
    'adzuna' => 'Adzuna (International)',
    'remoteok' => 'RemoteOK (International)',
];

// Approval-time duplicate re-check (AI-assisted duplicate resolution — see
// DUPLICATE_CHECK_SERVICE_URL). Separate from the render-time duplicate
// badge below (which batches every approved career once per page load for
// efficiency across dozens of pending cards) — this one only ever needs to
// check a single title at the moment a counselor clicks Approve, so a
// fresh, targeted query is simpler and cheap enough on its own. Matches
// title-similarity against active careers in the SAME scope only (local vs
// international are intentionally separate lanes — see the render-time
// version's own comment for why), same >=55%-or-exact threshold.
function find_duplicate_career(PDO $pdo, string $sourceTitle, string $scope): ?array
{
    $needle = strtolower(trim($sourceTitle));
    if ($needle === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT career_id, career_title, career_category, description, daily_task, educational_pathway,
                r_score, i_score, a_score, s_score, e_score, c_score, career_scope
         FROM careers WHERE status = 'active' AND career_scope = :scope"
    );
    $stmt->execute(['scope' => $scope]);

    $bestMatch = null;
    $bestPercent = 0.0;
    foreach ($stmt->fetchAll() as $career) {
        $normalized = strtolower(trim($career['career_title']));
        if ($normalized === $needle) {
            return $career + ['_match_percent' => 100.0];
        }
        similar_text($normalized, $needle, $percent);
        if ($percent > $bestPercent) {
            $bestPercent = $percent;
            $bestMatch = $career;
        }
    }
    return ($bestMatch && $bestPercent >= 55) ? ($bestMatch + ['_match_percent' => $bestPercent]) : null;
}

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
            // Local (PhilJobNet) vs. international (O*NET/Adzuna/RemoteOK) —
            // captured here so php/submit.php can show a student's dream
            // career's local + international versions side by side when
            // both exist. See migration_19_career_scope.sql.
            $sourceStmt = $pdo->prepare("SELECT data_source FROM pending_careers WHERE pending_id = :id");
            $sourceStmt->execute(['id' => $pendingId]);
            $careerScope = $sourceStmt->fetchColumn() === 'philjobnet' ? 'local' : 'international';

            // AI-assisted duplicate resolution: if this posting (as it's
            // about to be saved — the counselor's own edited title/
            // description, not the stale scraped values) still matches an
            // already-approved career, ask Gemini whether it's genuinely the
            // same real-world career or just a similarly-titled but distinct
            // one. Fully automatic by design — clicking Approve on an entry
            // that already showed the duplicate badge IS the counselor's
            // confirmation, per how this was scoped. Any failure to reach
            // the AI (or a "different career" verdict) falls back to the
            // original, safe behavior: insert as a new, separate career.
            $duplicateMatch = find_duplicate_career($pdo, $careerTitle, $careerScope);
            $mergeIntoCareerId = null;

            if ($duplicateMatch) {
                $dupPayload = json_encode([
                    'posting_title' => $careerTitle,
                    'posting_description' => $description,
                    'existing_title' => $duplicateMatch['career_title'],
                    'existing_description' => $duplicateMatch['description'],
                    'existing_daily_task' => $duplicateMatch['daily_task'],
                    'existing_educational_pathway' => $duplicateMatch['educational_pathway'],
                ]);
                $chDup = curl_init(DUPLICATE_CHECK_SERVICE_URL);
                curl_setopt_array($chDup, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $dupPayload,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 35,
                ]);
                $dupResponse = curl_exec($chDup);
                $dupHttpCode = curl_getinfo($chDup, CURLINFO_HTTP_CODE);
                $dupCurlError = curl_error($chDup);
                curl_close($chDup);
                $dupResult = $dupCurlError ? null : json_decode($dupResponse, true);

                if (!$dupCurlError && $dupHttpCode === 200 && !empty($dupResult['ai_available']) && !empty($dupResult['same_career'])) {
                    $mergeIntoCareerId = (int) $duplicateMatch['career_id'];
                }
            }

            $pdo->beginTransaction();
            try {
                if ($mergeIntoCareerId) {
                    // Overwrite path — full replace, per the counselor's own
                    // choice that an AI-confirmed duplicate should just take
                    // over the existing entry rather than create a second
                    // one. Old values are captured and logged BEFORE the
                    // UPDATE so this is a one-click revert from Change
                    // History if the AI called it wrong.
                    $oldCareerStmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id");
                    $oldCareerStmt->execute(['id' => $mergeIntoCareerId]);
                    $oldCareerRow = $oldCareerStmt->fetch();

                    $newCareerValues = [
                        'career_title' => $careerTitle,
                        'career_category' => $careerCategory,
                        'description' => $description,
                        'daily_task' => $dailyTask,
                        'educational_pathway' => $educationalPathway,
                        'key_subjects' => $keySubjects !== '' ? $keySubjects : null,
                        'r_score' => $scores['r'], 'i_score' => $scores['i'], 'a_score' => $scores['a'],
                        's_score' => $scores['s'], 'e_score' => $scores['e'], 'c_score' => $scores['c'],
                        'career_scope' => $careerScope,
                    ];
                    $setSql = implode(', ', array_map(fn($col) => "$col = :$col", array_keys($newCareerValues)));
                    $pdo->prepare("UPDATE careers SET $setSql WHERE career_id = :career_id")
                        ->execute(array_merge($newCareerValues, ['career_id' => $mergeIntoCareerId]));

                    if ($oldCareerRow) {
                        log_change(
                            $pdo, 'careers', $mergeIntoCareerId, $careerTitle, 'update',
                            $oldCareerRow, array_merge($oldCareerRow, $newCareerValues), $currentUser['user_id']
                        );
                    }

                    // Full replace of skill_requirements too — logged per row
                    // (delete + insert), same granularity as the existing
                    // add_skill/delete_skill actions on careers_manage.php,
                    // so each individual skill change is independently
                    // revertible rather than one opaque bulk operation.
                    $oldSkillsStmt = $pdo->prepare("SELECT * FROM skill_requirements WHERE career_id = :id");
                    $oldSkillsStmt->execute(['id' => $mergeIntoCareerId]);
                    foreach ($oldSkillsStmt->fetchAll() as $oldSkill) {
                        log_change(
                            $pdo, 'skill_requirements', (int) $oldSkill['skill_req_id'], $oldSkill['skill_name'],
                            'delete', $oldSkill, null, $currentUser['user_id']
                        );
                    }
                    $pdo->prepare("DELETE FROM skill_requirements WHERE career_id = :id")->execute(['id' => $mergeIntoCareerId]);

                    $careerId = $mergeIntoCareerId;
                } else {
                    $insert = $pdo->prepare(
                        "INSERT INTO careers
                            (career_title, career_category, description, daily_task, educational_pathway, key_subjects,
                             r_score, i_score, a_score, s_score, e_score, c_score, source, career_scope, status)
                         VALUES (:title, :category, :description, :daily_task, :pathway, :key_subjects,
                                 :r, :i, :a, :s, :e, :c, 'crawler', :scope, 'active')"
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
                        'scope' => $careerScope,
                    ]);
                    $careerId = (int) $pdo->lastInsertId();
                }

                // Copy the (possibly counselor-edited) skills list into the
                // approved-side skill_requirements table — same
                // stage-then-copy pattern already used for key_subjects,
                // just per-row instead of a single column. Rows with an
                // emptied-out name are skipped (that's how a counselor
                // "removes" an AI-suggested skill from this form). On the
                // merge path this is rebuilding the list just cleared above;
                // on the new-career path it's the only skill insert.
                $skillInsert = $pdo->prepare(
                    "INSERT INTO skill_requirements (career_id, skill_name, proficiency_level, is_required)
                     VALUES (:career_id, :skill_name, :proficiency, :is_required)"
                );
                foreach (($_POST['skills'] ?? []) as $skillInput) {
                    $skillName = trim($skillInput['name'] ?? '');
                    if ($skillName === '') {
                        continue;
                    }
                    // Free text now, not a fixed basic/intermediate/advanced
                    // enum — see migration_23_freetext_proficiency.sql.
                    $proficiency = mb_substr(trim($skillInput['proficiency'] ?? ''), 0, 150);
                    $isRequired = isset($skillInput['required']) ? 1 : 0;
                    $skillInsert->execute([
                        'career_id' => $careerId,
                        'skill_name' => mb_substr($skillName, 0, 150),
                        'proficiency' => $proficiency,
                        'is_required' => $isRequired,
                    ]);
                    if ($mergeIntoCareerId) {
                        log_change(
                            $pdo, 'skill_requirements', (int) $pdo->lastInsertId(), $skillName, 'insert', null,
                            ['career_id' => $careerId, 'skill_name' => mb_substr($skillName, 0, 150), 'proficiency_level' => $proficiency, 'is_required' => $isRequired],
                            $currentUser['user_id']
                        );
                    }
                }

                // Records which live career this became — whether newly
                // created or merged into an existing one — so pages like
                // the staff dashboard's "Recent Review Activity" list can
                // link straight to it (migration_26_pending_career_link.sql).
                $update = $pdo->prepare(
                    "UPDATE pending_careers SET status = 'approved', reviewed_at = NOW(), reviewed_by = :uid, approved_career_id = :career_id WHERE pending_id = :id"
                );
                $update->execute(['uid' => $currentUser['user_id'], 'career_id' => $careerId, 'id' => $pendingId]);

                $pdo->commit();
                $message = $mergeIntoCareerId
                    ? ['type' => 'success', 'text' => "AI determined \"$careerTitle\" is the same career as the existing \"{$duplicateMatch['career_title']}\" entry — its data was updated in place instead of creating a duplicate. Review or revert this on Change History if it called it wrong."]
                    : ['type' => 'success', 'text' => "Approved \"$careerTitle\" into the live career database."];
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
            $categoryNames = $pdo->query("SELECT name FROM career_categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $payload = json_encode([
                'career_title' => $row['source_title'],
                'raw_description' => $row['description'],
                'raw_qualifications' => $row['qualifications'],
                'categories' => $categoryNames,
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
                        career_category = COALESCE(NULLIF(:category, ''), career_category),
                        ai_enriched_at = NOW()
                     WHERE pending_id = :id"
                );
                $update->execute([
                    'description' => $result['description'],
                    'daily_task' => $result['daily_task'],
                    'pathway' => $result['educational_pathway'],
                    'r' => $result['riasec']['R'], 'i' => $result['riasec']['I'], 'a' => $result['riasec']['A'],
                    's' => $result['riasec']['S'], 'e' => $result['riasec']['E'], 'c' => $result['riasec']['C'],
                    'category' => $result['category'] ?? '',
                    'id' => $pendingId,
                ]);

                // Replace any previously-staged suggested skills for this
                // entry with the fresh set, so re-running this (e.g. a
                // manual "Retry AI enrichment" click) is repeatable and
                // doesn't just keep appending duplicates each time.
                $pdo->prepare("DELETE FROM pending_career_skills WHERE pending_id = :id")->execute(['id' => $pendingId]);
                $skillInsert = $pdo->prepare(
                    "INSERT INTO pending_career_skills (pending_id, skill_name, proficiency_level, is_required)
                     VALUES (:pending_id, :skill_name, :proficiency_level, :is_required)"
                );
                foreach ($result['skills'] ?? [] as $skill) {
                    $skillName = trim($skill['skill_name'] ?? '');
                    if ($skillName === '') {
                        continue;
                    }
                    $skillInsert->execute([
                        'pending_id' => $pendingId,
                        'skill_name' => substr($skillName, 0, 150),
                        'proficiency_level' => substr(trim($skill['proficiency_level'] ?? ''), 0, 150),
                        'is_required' => !empty($skill['is_required']) ? 1 : 0,
                    ]);
                }

                $message = ['type' => 'success', 'text' => 'AI enrichment complete — review the updated fields (including suggested skills) below before approving.'];
            }
        }
    } elseif ($action === 'run_crawler') {
        // Starts one or more crawler/*.py scripts as background subprocesses
        // on the matching service (see matching-service/app.py's
        // CrawlResource) so a counselor/admin never has to open a terminal.
        // Each request returns immediately — the crawl itself keeps running
        // in the background and results are polled via CRAWL_STATUS_SERVICE_URL.
        //
        // Sources are now checkboxes (name="sources[]") instead of a single
        // <select>, so a counselor can kick off several at once with one
        // click. This doesn't need any concurrency work on the Python side —
        // CrawlResource already tracks one in-flight subprocess *per source*
        // (keyed by source name in _running_crawls), so firing off a POST
        // per selected source here just launches that many genuinely
        // separate, already-parallel subprocesses. This loop is only about
        // letting the UI request several in one click; each individual
        // /crawl call is the same one the single-source flow always used.
        $crawlSources = array_values(array_intersect((array) ($_POST['sources'] ?? []), array_keys($sourceLabels)));
        if (!$crawlSources) {
            $message = ['type' => 'error', 'text' => 'Pick at least one source to crawl.'];
        } else {
            $started = [];
            $failed = [];
            foreach ($crawlSources as $crawlSource) {
                $ch = curl_init(CRAWL_SERVICE_URL);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['source' => $crawlSource, 'user_id' => $currentUser['user_id']]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 10, // just needs to confirm the subprocess started, not wait for it
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                $result = $curlError ? null : json_decode($response, true);

                if ($curlError) {
                    $failed[] = "{$sourceLabels[$crawlSource]} (could not reach the matching service — is python app.py running?)";
                } elseif (!$result || empty($result['started'])) {
                    $failed[] = "{$sourceLabels[$crawlSource]} (" . ($result['error'] ?? "HTTP $httpCode") . ')';
                } else {
                    $started[] = $sourceLabels[$crawlSource];
                }
            }

            if ($started && !$failed) {
                $label = count($started) === 1 ? $started[0] : implode(', ', $started);
                $message = [
                    'type' => 'success',
                    'text' => "$label crawler" . (count($started) > 1 ? 's' : '') . " started in the background. New entries will appear in Pending as they're found — refresh in a minute or two, or watch the status below.",
                ];
            } elseif ($started && $failed) {
                $message = [
                    'type' => 'success',
                    'text' => 'Started: ' . implode(', ', $started) . '. Could not start: ' . implode('; ', $failed),
                ];
            } else {
                $message = ['type' => 'error', 'text' => 'Crawler(s) could not start: ' . implode('; ', $failed)];
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

// Only meaningful on the Pending tab, same as $ageFilter. '' means "show both".
$aiFilter = $_GET['ai'] ?? '';
if (!in_array($aiFilter, ['enriched', 'not_enriched'], true)) {
    $aiFilter = '';
}

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
$enrichedCount = 0;
$notEnrichedCount = 0;

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
    //
    // Scoped by career_scope (local vs international — same lane the
    // Local/International side-by-side feature on the results page uses):
    // a RemoteOK/O*NET/Adzuna posting is only compared against other
    // *international* approved careers, and a PhilJobNet posting only
    // against *local* ones. Local and international are intentionally
    // separate areas, not duplicates of each other — an international
    // "Architect" isn't a dupe of the local "Architect" any more than an
    // administrator account is a dupe of a counselor account on
    // php/users.php; they're different lanes that can coexist on purpose
    // (and are exactly what the results-page comparison feature pairs up).
    // AI-suggested required skills staged per pending entry (migration 20 —
    // pending_career_skills mirrors the approved-side skill_requirements
    // table). Grouped by pending_id so render_pending_card() can pre-fill an
    // editable skills list; counselors add/remove/edit rows before
    // approving, same as every other AI-suggested field on this form.
    $pendingSkillsByPendingId = [];
    $pendingSkillsStmt = $pdo->query(
        "SELECT pending_id, skill_name, proficiency_level, is_required FROM pending_career_skills ORDER BY pending_id, is_required DESC, skill_name"
    );
    foreach ($pendingSkillsStmt->fetchAll() as $skillRow) {
        $pendingSkillsByPendingId[(int) $skillRow['pending_id']][] = $skillRow;
    }

    $approvedCareersByScope = ['local' => [], 'international' => []];
    $approvedCareersStmt = $pdo->query(
        "SELECT career_id, career_title, career_category, description, daily_task, educational_pathway, r_score, i_score, a_score, s_score, e_score, c_score, career_scope
         FROM careers WHERE status = 'active'"
    );
    foreach ($approvedCareersStmt->fetchAll() as $approved) {
        $scope = $approved['career_scope'] === 'international' ? 'international' : 'local';
        $approvedCareersByScope[$scope][] = $approved;
    }

    // Returns the full matched career row (not just its title) plus the
    // similarity percent, so the duplicate badge can show staff exactly
    // what the approved entry actually says — title similarity alone can
    // false-positive (e.g. "Ship Electrician" vs "Electrician" are related
    // but genuinely different specializations), so seeing the real
    // description/RIASEC/category lets a counselor judge "real duplicate"
    // vs "just a coincidentally similar title" without leaving the page.
    $findDuplicateCareer = function (string $sourceTitle, string $scope) use ($approvedCareersByScope): ?array {
        $needle = strtolower(trim($sourceTitle));
        if ($needle === '') {
            return null;
        }
        $bestMatch = null;
        $bestPercent = 0.0;
        foreach ($approvedCareersByScope[$scope] as $career) {
            $normalized = strtolower(trim($career['career_title']));
            if ($normalized === $needle) {
                return $career + ['_match_percent' => 100.0]; // exact match — most confident, stop here
            }
            similar_text($normalized, $needle, $percent);
            if ($percent > $bestPercent) {
                $bestPercent = $percent;
                $bestMatch = $career;
            }
        }
        return ($bestMatch && $bestPercent >= 55) ? ($bestMatch + ['_match_percent' => $bestPercent]) : null;
    };

    $newCutoff = time() - 86400;
    foreach ($pending as $row) {
        $rowScope = $row['data_source'] === 'philjobnet' ? 'local' : 'international';
        $row['_duplicate_of'] = $findDuplicateCareer($row['source_title'] ?? '', $rowScope);

        // Counted before the AI filter is applied below (same convention as
        // the age split vs. source filter) so the dropdown's own counts
        // don't collapse to just whichever option happens to be selected.
        $isEnriched = !empty($row['ai_enriched_at']);
        if ($isEnriched) {
            $enrichedCount++;
        } else {
            $notEnrichedCount++;
        }
        if ($aiFilter === 'enriched' && !$isEnriched) {
            continue;
        }
        if ($aiFilter === 'not_enriched' && $isEnriched) {
            continue;
        }

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
    h1, .counts, .flash-success, .flash-error, .filter-bar, .card, .empty, .section-heading { max-width: 1100px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .counts { margin-bottom: 24px; font-size: 15.5px; color: #555; }
    .counts span { margin-right: 16px; }
    .flash-success { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0f5132; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .flash-error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .card { background: #f5f5f5; border: 1px solid #ddd; border-radius: 8px; padding: 18px 22px; margin-bottom: 22px; scroll-margin-top: 20px; }
    .card h3 { margin-top: 0; color: #6e1423; }

    /* Pending-review cards collapse to a one-line summary by default (84
       entries as 84 fully-expanded forms is overwhelming) and expand to the
       full review form only when a counselor clicks in. */
    .pending-card { padding: 0; margin-bottom: 8px; }
    .pending-summary { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; cursor: pointer; list-style: none; padding: 10px 18px; }
    .pending-summary::-webkit-details-marker { display: none; }
    .pending-summary::before { content: "▸"; color: #6e1423; font-size: 13.5px; margin-right: 2px; }
    .pending-card[open] .pending-summary::before { content: "▾"; }
    .pending-summary:hover { background: rgba(110,20,35,0.03); }
    .pending-summary-title { font-weight: bold; color: #222; font-size: 16.5px; }
    .pending-dup-tag { background: #fdecea; color: #842029; border: 1px solid #f5c2c7; border-radius: 10px; padding: 3px 10px; font-size: 12.5px; font-weight: bold; white-space: nowrap; }
    .pending-ai-tag { background: #f3edfb; color: #6f42c1; border: 1px solid #e2d4f5; border-radius: 10px; padding: 3px 10px; font-size: 12.5px; font-weight: bold; white-space: nowrap; }
    .pending-summary-date { margin-left: auto; color: #888; font-size: 13.5px; white-space: nowrap; }
    .pending-body { padding: 16px 18px 18px; border-top: 1px solid #e5e5e5; margin-top: 2px; }
    .meta { font-size: 14.5px; color: #666; margin-bottom: 10px; }
    .meta a { color: #6e1423; }
    .card-meta-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; }
    label { display: block; font-size: 14.5px; font-weight: bold; margin: 10px 0 4px; }
    input[type=text], textarea { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    .card select[name=career_category] { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; box-sizing: border-box; }
    textarea { min-height: 60px; }
    .riasec-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-top: 8px; }
    .riasec-grid div { text-align: center; }
    .riasec-grid input { text-align: center; }
    .actions { margin-top: 14px; }
    button { padding: 8px 18px; border: none; border-radius: 6px; font-size: 15.5px; cursor: pointer; margin-right: 8px; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .approve { background: #6e1423; color: #fff; }
    .reject { background: #b02a37; color: #fff; }
    .enrich { background: #6f42c1; color: #fff; }
    .skills-editor { display: flex; flex-direction: column; gap: 6px; margin-top: 4px; }
    .skill-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .skill-row input[type=text] { flex: 1; min-width: 160px; width: auto; }
    .skill-row select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; font-size: 14.5px; }
    .skill-required-label { display: flex; align-items: center; gap: 4px; font-weight: normal; margin: 0; font-size: 14.5px; white-space: nowrap; }
    .skill-required-label input { width: auto; }
    .skill-remove-btn { background: #f5f5f5; color: #888; border: 1px solid #ddd; border-radius: 4px; width: 28px; height: 28px; line-height: 1; padding: 0; cursor: pointer; margin: 0; }
    .skill-remove-btn:hover { background: #fdecea; color: #b02a37; border-color: #f5c2c7; }
    .add-skill-btn { background: #fff; color: #6e1423; border: 1px dashed #d8b9bf; border-radius: 6px; padding: 6px 14px; font-size: 14px; cursor: pointer; margin: 8px 0 0; }
    .add-skill-btn:hover { background: #faf0f1; }
    .ai-badge { display: inline-block; color: #6f42c1; font-weight: bold; background: #f3edfb; border: 1px solid #e2d4f5; border-radius: 10px; padding: 3px 12px; white-space: nowrap; }
    .empty { color: #666; font-style: italic; }
    .section-heading { color: #6e1423; font-size: 17.5px; margin: 26px auto 10px; padding-top: 4px; border-top: 1px solid #eee; }
    .section-heading:first-of-type { border-top: none; padding-top: 0; margin-top: 4px; }
    .new-section-heading { border-top: none; margin-top: 4px; }
    .new-badge { display: inline-block; background: #ffc107; color: #664d03; font-weight: bold; padding: 2px 8px; border-radius: 10px; font-size: 12.5px; margin-right: 6px; }
    .duplicate-badge { background: #fdecea; color: #842029; border: 1px solid #f5c2c7; border-radius: 6px; padding: 12px 16px; font-size: 14px; margin-bottom: 16px; }
    .duplicate-badge summary { cursor: pointer; font-weight: bold; list-style: none; line-height: 1.6; }
    .duplicate-badge summary::-webkit-details-marker { display: none; }
    .duplicate-badge summary::before { content: "▸ "; margin-right: 2px; }
    .duplicate-badge[open] summary::before { content: "▾ "; }
    .duplicate-detail { margin-top: 10px; padding-top: 10px; border-top: 1px solid #f5c2c7; font-weight: normal; }
    .duplicate-compare { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .duplicate-compare th, .duplicate-compare td { text-align: left; padding: 6px 10px; font-size: 13.5px; vertical-align: top; border-bottom: 1px solid #f5d9dc; }
    .duplicate-compare th { color: #a44553; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px; font-weight: bold; }
    .duplicate-compare td:first-child { font-weight: bold; color: #a44553; white-space: nowrap; width: 100px; }
    .duplicate-compare td:not(:first-child) { width: 50%; line-height: 1.5; }
    .duplicate-compare tr:last-child td { border-bottom: none; }
    .duplicate-scope-tag { display: inline-block; background: #f0dde1; color: #6e1423; border-radius: 10px; padding: 2px 9px; font-size: 12.5px; margin: 0 6px; font-weight: bold; vertical-align: 1px; }
    .duplicate-scope-tag.duplicate-scope-international { background: #e2e8f0; color: #1e3a5f; }
    .duplicate-scope-tag.duplicate-scope-local { background: #f0dde1; color: #6e1423; }
    .duplicate-detail-hint { margin: 0 0 10px; font-size: 13px; color: #8a5a5a; font-style: italic; line-height: 1.5; }
    /* Raw-vs-AI-enriched comparison — same full-width collapsible-table
       pattern as the "possible duplicate" box above (.duplicate-badge/
       .duplicate-compare), just re-themed purple to match this page's
       existing AI accent color (.pending-ai-tag/.ai-badge) instead of red.
       A narrow floating sidebar next to the tall form looked disconnected/
       orphaned once the form ran longer than the reference box, so this
       matches the duplicate box's own full-width row layout instead, which
       doesn't have that problem regardless of content height. */
    /* Raw-vs-AI-enriched comparison, permanent two-column layout (not a
       click-to-expand table) — left column is the raw scraped text,
       read-only reference; right column is the existing editable form
       (pre-filled from AI enrichment). Side by side by default so a
       counselor can scan both without an extra click. Stacks on narrow
       screens since a 260px reference column doesn't leave the form enough
       room below ~820px. */
    .pending-columns { display: flex; gap: 20px; align-items: flex-start; }
    .pending-raw-col { flex: 0 0 260px; background: #f7f7f7; border: 1px solid #e2e2e2; border-radius: 8px; padding: 14px 16px; }
    .pending-raw-heading { font-weight: bold; color: #555; font-size: 13px; margin-bottom: 8px; }
    .raw-fields dt { font-weight: bold; color: #6e1423; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; margin-top: 12px; }
    .raw-fields dt:first-child { margin-top: 0; }
    .raw-fields dd { margin: 3px 0 0; font-size: 13px; color: #555; line-height: 1.5; }
    .pending-form-col { flex: 1; min-width: 0; }
    @media (max-width: 820px) {
        .pending-columns { flex-direction: column; }
        .pending-raw-col { flex: 1 1 auto; width: 100%; box-sizing: border-box; }
    }
    .source-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12.5px; }
    .source-philjobnet { background: #f0dde1; color: #6e1423; }
    .source-kalibrr { background: #cfe8ff; color: #0b4f8a; }
    .source-onet { background: #e7d9f7; color: #4b2e83; }
    .source-adzuna { background: #d1e7dd; color: #0f5132; }
    .source-remoteok { background: #fff3cd; color: #856404; }
    .filter-bar { margin-top: 22px; margin-bottom: 18px; font-size: 15.5px; }
    .filter-bar form { display: flex; flex-wrap: wrap; gap: 16px 28px; align-items: flex-end; }
    .filter-group { display: flex; flex-direction: column; gap: 5px; }
    .filter-group label { font-weight: bold; font-size: 13.5px; text-transform: uppercase; letter-spacing: 0.3px; color: #888; margin: 0; }
    .filter-bar select { padding: 7px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; font-size: 15.5px; min-width: 220px; background: #fff; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }

    /* Status tabs */
    .tabs { max-width: 1100px; margin: 24px auto 0; display: flex; gap: 4px; border-bottom: 2px solid #eee; flex-wrap: wrap; }
    .tab-btn { background: none; border: none; padding: 10px 18px; font-size: 15.5px; font-weight: bold; color: #888; cursor: pointer; text-decoration: none; display: inline-block; border-bottom: 3px solid transparent; margin-bottom: -2px; font-family: inherit; }
    .tab-btn:hover { color: #6e1423; }
    .tab-btn.active { color: #6e1423; border-bottom-color: #6e1423; }
    .tab-count { display: inline-block; background: #eee; color: #555; border-radius: 10px; padding: 1px 8px; font-size: 12.5px; margin-left: 5px; }
    .tab-btn.active .tab-count { background: #f0dde1; color: #6e1423; }

    /* Search bar */
    .search-bar { position: relative; max-width: 340px; margin: 18px auto 4px; }
    .search-bar input { width: 100%; padding: 9px 14px 9px 32px; border: 1px solid #ccc; border-radius: 20px; font-size: 15.5px; box-sizing: border-box; }
    .search-bar input:focus { outline: none; border-color: #6e1423; box-shadow: 0 0 0 2px rgba(110,20,35,0.12); }
    .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14.5px; opacity: 0.55; pointer-events: none; }
    .no-results { max-width: 1100px; margin: 20px auto; text-align: center; color: #888; font-style: italic; }

    /* Read-only reviewed (approved/rejected) rows */
    .reviewed-row { background: #f5f5f5; border: 1px solid #ddd; border-radius: 8px; padding: 12px 18px; margin-bottom: 12px; font-size: 15.5px; }
    .reviewed-row .title { font-weight: bold; color: #6e1423; }
    .reviewed-row .meta { margin-top: 4px; }

    /* Run Web Crawler panel */
    .crawler-panel { max-width: 1100px; margin: 0 auto 20px; background: #faf0f1; border: 1px solid #f0dde1; border-radius: 8px; padding: 16px 20px; }
    .crawler-panel-row { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
    .crawler-panel select { padding: 7px 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; font-size: 14.5px; }
    .crawler-check { display: inline-flex; align-items: center; gap: 5px; font-size: 14px; font-weight: normal; color: #444; white-space: nowrap; }
    .crawler-check input { margin: 0; }
    .crawler-panel button { background: #6e1423; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-size: 14.5px; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease, background-color 0.15s ease; }
    .crawler-panel button:hover:not(:disabled) { background: #4a0c17; }
    .crawler-panel button:disabled { opacity: 0.6; cursor: not-allowed; }
    .crawler-panel .hint { font-size: 13.5px; color: #888; margin-top: 6px; }
    .crawler-status { margin-top: 10px; font-size: 14.5px; }
    .crawler-status .spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid #6e1423; border-top-color: transparent; border-radius: 50%; animation: crawler-spin 0.7s linear infinite; margin-right: 6px; vertical-align: -1px; }
    @keyframes crawler-spin { to { transform: rotate(360deg); } }
    .crawler-log { margin-top: 8px; background: #222; color: #d9f7d9; font-family: monospace; font-size: 12.5px; padding: 10px 12px; border-radius: 6px; max-height: 160px; overflow-y: auto; white-space: pre-wrap; display: none; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/nav.php'; ?>

    <h1>Career Review Queue</h1>

    <div class="crawler-panel">
        <form method="POST" class="crawler-panel-row" id="crawler-form">
            <input type="hidden" name="action" value="run_crawler">
            <strong>Run Web Crawler:</strong>
            <label class="crawler-check"><input type="checkbox" name="sources[]" value="philjobnet" checked> PhilJobNet (PH — no setup needed)</label>
            <label class="crawler-check"><input type="checkbox" name="sources[]" value="kalibrr" checked> Kalibrr (PH — no setup needed)</label>
            <label class="crawler-check"><input type="checkbox" name="sources[]" value="remoteok"> RemoteOK (Intl — no setup needed)</label>
            <label class="crawler-check"><input type="checkbox" name="sources[]" value="onet"> O*NET (Intl — needs ONET_USERNAME/PASSWORD)</label>
            <label class="crawler-check"><input type="checkbox" name="sources[]" value="adzuna"> Adzuna (Intl — needs ADZUNA_APP_ID/KEY)</label>
            <button type="submit">▶ Run Selected</button>
        </form>
        <p class="hint">Check as many sources as you want and run them together — each starts its own background crawl on the matching service (must be running — see <code>!START_HERE - Run Matching Service.bat</code>). New postings appear in the Pending tab as they're found; this page won't freeze while it runs.</p>
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
            <div class="filter-group">
                <label>Filter by source</label>
                <select name="source" onchange="this.form.submit()">
                    <option value="">All sources (<?= array_sum($sourceCounts) ?>)</option>
                    <?php foreach ($sourceLabels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $sourceFilter === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?> (<?= $sourceCounts[$key] ?? 0 ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($statusFilter === 'pending'): ?>
                <div class="filter-group">
                    <label>Filter by age</label>
                    <select name="age" onchange="this.form.submit()">
                        <option value="" <?= $ageFilter === '' ? 'selected' : '' ?>>All entries (<?= count($newPending) + count($olderPending) ?>)</option>
                        <option value="new" <?= $ageFilter === 'new' ? 'selected' : '' ?>>🆕 New — last 24 hours (<?= count($newPending) ?>)</option>
                        <option value="older" <?= $ageFilter === 'older' ? 'selected' : '' ?>>Older entries (<?= count($olderPending) ?>)</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Filter by AI enrichment</label>
                    <select name="ai" onchange="this.form.submit()">
                        <option value="" <?= $aiFilter === '' ? 'selected' : '' ?>>All entries (<?= $enrichedCount + $notEnrichedCount ?>)</option>
                        <option value="enriched" <?= $aiFilter === 'enriched' ? 'selected' : '' ?>>✨ AI-enriched (<?= $enrichedCount ?>)</option>
                        <option value="not_enriched" <?= $aiFilter === 'not_enriched' ? 'selected' : '' ?>>Not yet enriched (<?= $notEnrichedCount ?>)</option>
                    </select>
                </div>
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
            No <?= htmlspecialchars($statusFilter) ?> entries<?= $sourceFilter !== '' ? ' from ' . htmlspecialchars($sourceLabels[$sourceFilter]) : '' ?><?= $ageFilter === 'new' ? ' scraped in the last 24 hours' : ($ageFilter === 'older' ? ' older than 24 hours' : '') ?><?= $aiFilter === 'enriched' ? ' that are AI-enriched' : ($aiFilter === 'not_enriched' ? ' that still need AI enrichment' : '') ?>.
            <?php if ($statusFilter === 'pending'): ?>
                Use "Run Web Crawler" above to fetch more, or run one of the scripts manually:
                <code>python crawler/crawler.py</code> or <code>python crawler/kalibrr_client.py</code> (Philippines),
                <code>python crawler/onet_client.py</code>, <code>python crawler/adzuna_client.py</code>, or
                <code>python crawler/remoteok_client.py</code> (international).
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
            function render_pending_card(array $row, array $sourceLabels, array $categoryOptions, bool $isNew, int $focusPendingId = 0, array $skills = []): void
            {
                $isEnriched = !empty($row['ai_enriched_at']);
                $isFocused = $focusPendingId > 0 && $focusPendingId === (int) $row['pending_id'];
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
        <details class="card pending-card" id="pending-<?= (int) $row['pending_id'] ?>" data-search="<?= htmlspecialchars(strtolower(($row['source_title'] ?? '') . ' ' . ($row['search_keyword'] ?? ''))) ?>" <?= $isFocused ? 'open' : '' ?>>
            <summary class="pending-summary">
                <?php if ($isNew): ?><span class="new-badge">🆕 New</span><?php endif; ?>
                <span class="pending-summary-title"><?= htmlspecialchars($row['source_title'] ?? '(untitled)') ?></span>
                <span class="source-tag source-<?= htmlspecialchars($row['data_source']) ?>"><?= htmlspecialchars($sourceLabels[$row['data_source']] ?? $row['data_source']) ?></span>
                <?php if (!empty($row['_duplicate_of'])): ?>
                    <span class="pending-dup-tag">⚠️ Possible duplicate</span>
                <?php endif; ?>
                <?php if ($isEnriched): ?>
                    <span class="pending-ai-tag">✨ AI-enriched</span>
                <?php endif; ?>
                <span class="pending-summary-date">Scraped <?= htmlspecialchars($row['scraped_at']) ?></span>
            </summary>

            <div class="pending-body">
                <div class="meta card-meta-row">
                    <span>Keyword: <?= htmlspecialchars($row['search_keyword']) ?></span>
                    <span><?= htmlspecialchars($row['country'] ?? '—') ?></span>
                    <a href="<?= htmlspecialchars($row['source_url']) ?>" target="_blank" rel="noopener">View original posting</a>
                    <?php if ($isEnriched): ?>
                        <span class="ai-badge">✨ AI-enriched <?= htmlspecialchars(date('M j, Y g:i A', strtotime($row['ai_enriched_at']))) ?></span>
                    <?php endif; ?>
                </div>
                <p class="meta">
                    <?= htmlspecialchars($row['employer'] ?? '—') ?> ·
                    <?= htmlspecialchars($row['location'] ?? '—') ?> ·
                    <?= htmlspecialchars($row['education_level'] ?? '—') ?> ·
                    <?= htmlspecialchars($row['employment_type'] ?? '—') ?> ·
                    <?= htmlspecialchars($row['salary'] ?? '—') ?>
                </p>

                <?php if ($isEnriched): ?>
                <div class="pending-columns">
                    <div class="pending-raw-col">
                        <div class="pending-raw-heading">📄 Raw scraped data (reference only)</div>
                        <dl class="raw-fields">
                            <dt>Description</dt>
                            <dd><?= htmlspecialchars($row['description'] ?: '—') ?></dd>
                            <dt>Daily tasks / qualifications</dt>
                            <dd><?= htmlspecialchars($row['qualifications'] ?: '—') ?></dd>
                            <dt>Educational pathway</dt>
                            <dd><?= htmlspecialchars($row['education_level'] ?: '—') ?></dd>
                            <dt>Category</dt>
                            <dd>— the crawler doesn't suggest one</dd>
                            <dt>Suggested RIASEC (keyword rule of thumb)</dt>
                            <dd>R <?= (int) $row['suggested_r_score'] ?> · I <?= (int) $row['suggested_i_score'] ?> · A <?= (int) $row['suggested_a_score'] ?> · S <?= (int) $row['suggested_s_score'] ?> · E <?= (int) $row['suggested_e_score'] ?> · C <?= (int) $row['suggested_c_score'] ?></dd>
                        </dl>
                    </div>
                    <div class="pending-form-col">
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="pending_id" value="<?= (int) $row['pending_id'] ?>">

                    <?php if ($isEnriched): ?>
                        <div class="actions" style="margin-top:0;">
                            <span class="meta">AI enrichment ran automatically when this was staged — fields below (including category and suggested skills) are pre-filled from that response. Edit freely before approving.</span>
                        </div>
                    <?php else: ?>
                        <div class="actions" style="margin-top:0;">
                            <span class="meta">AI enrichment hasn't filled this one in yet (the automatic pass may have missed it, e.g. if the matching service wasn't running or the crawl was interrupted) — fill it in by hand below, or</span>
                            <button type="submit" name="action" value="enrich" class="enrich">✨ Retry AI enrichment</button>
                        </div>
                    <?php endif; ?>

                    <label>Career title (this is what students will see)</label>
                    <input type="text" name="career_title" value="<?= htmlspecialchars($row['source_title'] ?? '') ?>" required>

                    <label>Category / Industry Cluster</label>
                    <select name="career_category" required>
                        <option value="">— Select a category —</option>
                        <?php foreach ($categoryOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['name']) ?>" title="<?= htmlspecialchars($opt['description'] ?? '') ?>" <?= $opt['name'] === ($row['career_category'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:13.5px;color:#888;margin:4px 0 0;">Need a new category, or want to edit what one covers? <a href="career_categories.php" style="color:#6e1423;">Manage Categories</a>.</p>

                    <label>Description</label>
                    <textarea name="description"><?= htmlspecialchars($descriptionDefault) ?></textarea>

                    <label>Daily tasks / qualifications</label>
                    <textarea name="daily_task"><?= htmlspecialchars($dailyTaskDefault) ?></textarea>

                    <label>Educational pathway</label>
                    <input type="text" name="educational_pathway" value="<?= htmlspecialchars($pathwayDefault) ?>">

                    <label>Key subjects (JHS/SHS subjects to focus on for this career)</label>
                    <input type="text" name="key_subjects" value="<?= htmlspecialchars($row['key_subjects'] ?? '') ?>" placeholder="e.g. Mathematics, Physics, Computer/ICT electives">

                    <label>Required skills<?= $skills ? ' (AI-suggested — edit, remove, or add before approving)' : '' ?></label>
                    <div class="skills-editor">
                        <?php foreach ($skills as $idx => $skill): ?>
                            <div class="skill-row">
                                <input type="text" name="skills[<?= $idx ?>][name]" value="<?= htmlspecialchars($skill['skill_name']) ?>" placeholder="Skill name">
                                <input type="text" name="skills[<?= $idx ?>][proficiency]" value="<?= htmlspecialchars($skill['proficiency_level'] ?? '') ?>" placeholder="What level is needed? (e.g. Comfortable with basic HTML/CSS)">
                                <label class="skill-required-label"><input type="checkbox" name="skills[<?= $idx ?>][required]" value="1" <?= $skill['is_required'] ? 'checked' : '' ?>> Required</label>
                                <button type="button" class="skill-remove-btn" title="Remove this skill">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="add-skill-btn" data-next-index="<?= count($skills) ?>">+ Add skill</button>

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

                <?php if ($isEnriched): ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($row['_duplicate_of'])): ?>
                    <?php $dup = $row['_duplicate_of']; ?>
                    <details class="duplicate-badge">
                        <summary>⚠️ Possible duplicate <span class="duplicate-scope-tag duplicate-scope-<?= htmlspecialchars($dup['career_scope']) ?>"><?= htmlspecialchars(ucfirst($dup['career_scope'])) ?></span>: "<?= htmlspecialchars($dup['career_title']) ?>" is already approved (<?= round($dup['_match_percent']) ?>% title match) — click to compare, then decide if it's a real duplicate or just a similar title.</summary>
                        <div class="duplicate-detail">
                            <table class="duplicate-compare">
                                <tr>
                                    <th></th>
                                    <th>This posting</th>
                                    <th>Approved career</th>
                                </tr>
                                <tr>
                                    <td>Title</td>
                                    <td><?= htmlspecialchars($row['source_title'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($dup['career_title']) ?></td>
                                </tr>
                                <tr>
                                    <td>Category</td>
                                    <td><?= htmlspecialchars($row['career_category'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($dup['career_category'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <td>Description</td>
                                    <td><?= htmlspecialchars($descriptionDefault ?: '—') ?></td>
                                    <td><?= htmlspecialchars($dup['description'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <td>Daily tasks</td>
                                    <td><?= htmlspecialchars($dailyTaskDefault ?: '—') ?></td>
                                    <td><?= htmlspecialchars($dup['daily_task'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <td>Educ. pathway</td>
                                    <td><?= htmlspecialchars($pathwayDefault ?: '—') ?></td>
                                    <td><?= htmlspecialchars($dup['educational_pathway'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <td>RIASEC</td>
                                    <td>R <?= number_format((float) $rDefault, 0) ?> · I <?= number_format((float) $iDefault, 0) ?> · A <?= number_format((float) $aDefault, 0) ?> · S <?= number_format((float) $sDefault, 0) ?> · E <?= number_format((float) $eDefault, 0) ?> · C <?= number_format((float) $cDefault, 0) ?></td>
                                    <td>R <?= number_format((float) $dup['r_score'], 2) ?> · I <?= number_format((float) $dup['i_score'], 2) ?> · A <?= number_format((float) $dup['a_score'], 2) ?> · S <?= number_format((float) $dup['s_score'], 2) ?> · E <?= number_format((float) $dup['e_score'], 2) ?> · C <?= number_format((float) $dup['c_score'], 2) ?></td>
                                </tr>
                            </table>
                            <p class="duplicate-detail-hint">Title similarity only — <?= round($dup['_match_percent']) ?>% overlap. A lower percentage (well under 100%) often means a distinct specialization (e.g. "Ship Electrician" vs. "Electrician"), not a true duplicate — compare the rows above before rejecting this entry.</p>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </details>
        <?php
            }
        ?>

        <?php if ($showNew && $newPending): ?>
            <h2 class="section-heading new-section-heading">🆕 New — added in the last 24 hours (<?= count($newPending) ?>)</h2>
            <?php foreach ($newPending as $row): render_pending_card($row, $sourceLabels, $categoryOptions, true, $focusPendingId, $pendingSkillsByPendingId[(int) $row['pending_id']] ?? []); endforeach; ?>
        <?php endif; ?>

        <?php if ($showOlder && $olderPending): ?>
            <h2 class="section-heading <?= ($showNew && $newPending) ? '' : 'new-section-heading' ?>">Older entries (<?= count($olderPending) ?>)</h2>
            <?php foreach ($olderPending as $row): render_pending_card($row, $sourceLabels, $categoryOptions, false, $focusPendingId, $pendingSkillsByPendingId[(int) $row['pending_id']] ?? []); endforeach; ?>
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

    // Required-skills editor on each pending card: "+ Add skill" appends a
    // blank row (its own name/proficiency/required inputs, indexed past
    // whatever AI-suggested rows already exist so submitted array keys
    // never collide); the ✕ on a row just removes it from the DOM — an
    // emptied-out / removed row simply isn't in the POST, so the approve
    // handler skips it. Delegated to the document since every pending card
    // has its own independent skills-editor.
    (function () {
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('add-skill-btn')) {
                var btn = e.target;
                var editor = btn.previousElementSibling;
                var idx = parseInt(btn.dataset.nextIndex, 10) || 0;
                var row = document.createElement('div');
                row.className = 'skill-row';
                row.innerHTML =
                    '<input type="text" name="skills[' + idx + '][name]" placeholder="Skill name">' +
                    '<input type="text" name="skills[' + idx + '][proficiency]" placeholder="What level is needed? (e.g. Comfortable with basic HTML/CSS)">' +
                    '<label class="skill-required-label"><input type="checkbox" name="skills[' + idx + '][required]" value="1" checked> Required</label>' +
                    '<button type="button" class="skill-remove-btn" title="Remove this skill">✕</button>';
                editor.appendChild(row);
                btn.dataset.nextIndex = idx + 1;
                row.querySelector('input[type=text]').focus();
            } else if (e.target.classList.contains('skill-remove-btn')) {
                e.target.closest('.skill-row').remove();
            }
        });
    })();

    // Live status for the "Run Web Crawler" panel — polls crawler_status.php
    // (same-origin proxy to the matching service) every few seconds so staff
    // can watch a crawl finish without manually refreshing the page.
    //
    // Now polls every checkbox's source at once (not just one selected
    // value), since multiple crawlers can genuinely be running in parallel —
    // each checkbox fires its own independent /crawl subprocess on the
    // matching service (see careers.php's run_crawler handler + app.py's
    // CrawlResource, which already tracks one in-flight process per source).
    // One status line per source; the log panel shows whichever source is
    // still running, or the most recently finished one if none are.
    (function () {
        var checkboxes = document.querySelectorAll('#crawler-form input[name="sources[]"]');
        var statusEl = document.getElementById('crawler-status');
        var logEl = document.getElementById('crawler-log');
        if (!checkboxes.length || !statusEl) return;

        var sourceLabels = <?= json_encode($sourceLabels) ?>;

        function describe(source, data) {
            var label = sourceLabels[source] || source;
            if (data.state === 'running') {
                return '<div><span class="spinner"></span>' + label + ': running for ' + data.elapsed_seconds + 's...</div>';
            } else if (data.state === 'finished' && data.exit_code === 0) {
                return '<div>' + label + ': finished (ran for ' + data.elapsed_seconds + 's).</div>';
            } else if (data.state === 'finished') {
                return '<div>' + label + ': crashed after ' + data.elapsed_seconds + 's (exit code ' + data.exit_code + ').</div>';
            } else if (data.state === 'unreachable') {
                return '<div>' + label + ': matching service not reachable.</div>';
            }
            return '';
        }

        function poll() {
            var sources = Array.prototype.filter.call(checkboxes, function (cb) { return cb.checked; })
                .map(function (cb) { return cb.value; });
            if (!sources.length) {
                statusEl.innerHTML = '';
                logEl.style.display = 'none';
                return;
            }

            Promise.all(sources.map(function (source) {
                return fetch('crawler_status.php?source=' + encodeURIComponent(source))
                    .then(function (r) { return r.json(); })
                    .then(function (data) { return { source: source, data: data }; })
                    .catch(function () { return { source: source, data: { state: 'idle' } }; });
            })).then(function (results) {
                var lines = results.map(function (r) { return describe(r.source, r.data); }).filter(Boolean);
                statusEl.innerHTML = lines.join('') || 'Check the Pending tab for new entries.';

                // Prefer the log of whichever source is currently running;
                // fall back to the most recently finished one.
                var running = results.find(function (r) { return r.data.state === 'running' && r.data.log_tail; });
                var finished = results.slice().reverse().find(function (r) { return r.data.state === 'finished' && r.data.log_tail; });
                var chosen = running || finished;
                if (chosen) {
                    logEl.textContent = chosen.source + ':\n' + chosen.data.log_tail;
                    logEl.style.display = 'block';
                    logEl.scrollTop = logEl.scrollHeight;
                } else {
                    logEl.style.display = 'none';
                }
            });
        }

        poll();
        setInterval(poll, 4000);
        checkboxes.forEach(function (cb) { cb.addEventListener('change', poll); });
    })();
    </script>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
