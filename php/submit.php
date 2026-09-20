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

$pdo = get_db();

// Post/Redirect/Get: a fresh submission (POST) is scored, saved, and then
// redirected to this same page as a GET with ?profile_id=. That GET is what
// actually renders the results below. This means the browser's history entry
// for the results page is a plain GET, so pressing Back just re-fetches it —
// no "Confirm Form Resubmission" prompt, and (more importantly) nothing in
// the render path re-runs the matching-service call, the Gemini commentary
// call, or the student_profiles/recommendations INSERTs on every back-nav or
// refresh. Everything the render code needs on the GET path is reloaded from
// the DB instead of recomputed, so results look identical either way.
$isReplay = $_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['profile_id']);

$replayProfile = null;
if ($isReplay) {
    $replayStmt = $pdo->prepare("SELECT * FROM student_profiles WHERE profile_id = :id AND student_id = :sid");
    $replayStmt->execute(['id' => (int) $_GET['profile_id'], 'sid' => $currentStudent['student_id']]);
    $replayProfile = $replayStmt->fetch();
    if (!$replayProfile) {
        http_response_code(404);
        die('Result not found. <a href="assessment.php">Take the assessment</a>');
    }

    // Reload everything the scoring/rendering code below needs from the
    // saved row instead of $_POST — this branch never touches $_POST.
    $riasec = [
        'R' => (float) $replayProfile['r_score'], 'I' => (float) $replayProfile['i_score'], 'A' => (float) $replayProfile['a_score'],
        'S' => (float) $replayProfile['s_score'], 'E' => (float) $replayProfile['e_score'], 'C' => (float) $replayProfile['c_score'],
    ];
    $studentSkillsRaw = $replayProfile['skills'] ?? '';
    $academicAverage = (float) $replayProfile['academic_average'];
    $dreamCareerId = (int) ($replayProfile['dream_career_id'] ?? 0);
} else {
    $types = ['R', 'I', 'A', 'S', 'E', 'C'];
    $maxPerType = 7 * 4; // 7 questions x max score of 4

    $riasec = [];
    foreach ($types as $type) {
        $answers = $_POST[$type] ?? [];
        $sum = array_sum(array_map('intval', $answers));
        $riasec[$type] = round($sum / $maxPerType, 4); // normalize to 0-1
    }

    // Skills verification mechanism (Specific Objective 2 / Research Gap #4) —
    // captured alongside RIASEC so recommendations can show a skills gap, not
    // just a personality match. Optional per client feedback: a student who
    // skips it just doesn't get a skills-match percentage (compute_skill_match()
    // in skills_helper.php already returns null rather than a misleading 0% for
    // a blank skills field) — academic average below is still required.
    // The form's placeholder tells students unsure what to put to type "N/A" —
    // treat that (and "none"/"n/a", any casing/punctuation) the same as a blank
    // field everywhere downstream, so it doesn't get stored or scored as if it
    // were a real (and misleadingly low) list of skills.
    $studentSkillsRaw = trim($_POST['skills'] ?? '');
    if (preg_match('/^n\.?\/?\s?a\.?$|^none$/i', $studentSkillsRaw)) {
        $studentSkillsRaw = '';
    }
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
}

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
        'career_scope' => $careerRow['career_scope'] ?? 'local',
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
    // On a replayed (GET) view, the AI commentary was already generated and
    // cached on student_profiles the first time this profile was submitted —
    // reuse it here instead of calling Gemini again on every back-nav/refresh.
    if ($isReplay) {
        $dreamCareerData['ai_summary'] = $replayProfile['ai_summary'] ?? null;
        $dreamCareerData['ai_career_commentary'] = $replayProfile['ai_career_commentary'] ?? null;
        $dreamCareerData['ai_skills_are_suggested'] = !empty($replayProfile['ai_skills_are_suggested']);
    }
}

// If a Local and an International career for essentially the same role both
// exist in the catalog (e.g. both approved as "Registered Nurse" — one from
// PhilJobNet, one from O*NET/Adzuna/RemoteOK — see migration_19_career_scope.sql),
// show them side by side for the student's DREAM career specifically, so they
// can compare local vs. international pay/pathway/outlook. Matching by title
// similarity (PHP's similar_text(), same >=55% threshold used elsewhere in
// this app for "is this the same career?" checks) rather than a hard link,
// since most careers won't have a counterpart at all — in that case this
// just stays null and the section renders exactly as it did before.
$dreamCareerCounterpart = null;
if ($dreamCareer) {
    $wantScope = $dreamCareer['career_scope'] === 'local' ? 'international' : 'local';
    $candidatesStmt = $pdo->prepare(
        "SELECT * FROM careers WHERE status = 'active' AND career_scope = :scope AND career_id != :id"
    );
    $candidatesStmt->execute(['scope' => $wantScope, 'id' => $dreamCareer['career_id']]);

    $needle = strtolower(trim($dreamCareer['career_title']));
    $bestMatch = null;
    $bestPercent = 0.0;
    foreach ($candidatesStmt->fetchAll() as $candidate) {
        $title = strtolower(trim($candidate['career_title']));
        if ($title === $needle) {
            $bestMatch = $candidate;
            $bestPercent = 100.0;
            break;
        }
        similar_text($title, $needle, $percent);
        if ($percent > $bestPercent) {
            $bestPercent = $percent;
            $bestMatch = $candidate;
        }
    }
    if ($bestMatch && $bestPercent >= 55) {
        $dreamCareerCounterpart = build_career_match_data($bestMatch, $studentVectorAssoc);
    }
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

$result = null;
$errorMessage = null;
$hasMatchResults = false;

if ($isReplay) {
    // Rebuild the "other careers" list purely from what was already saved —
    // no Flask call, no re-insert. Each recommended career is re-run through
    // build_career_match_data(), the same local/deterministic function used
    // above for the dream career and field careers, so it comes back with
    // the exact same shape render_career_card() needs (title, description,
    // top_dimensions, career_riasec, etc.) — the raw Flask response never
    // had to be cached for this to work, since match_score is a pure
    // function of $riasec (unchanged, reloaded from the saved profile) and
    // the career's own row.
    $recStmt = $pdo->prepare(
        "SELECT career_id FROM recommendations WHERE profile_id = :id ORDER BY rank_position ASC"
    );
    $recStmt->execute(['id' => (int) $replayProfile['profile_id']]);
    $recCareerIds = array_map('intval', $recStmt->fetchAll(PDO::FETCH_COLUMN));

    $reconstructed = [];
    if ($recCareerIds) {
        $placeholders = implode(',', array_fill(0, count($recCareerIds), '?'));
        $careersStmt = $pdo->prepare("SELECT * FROM careers WHERE career_id IN ($placeholders)");
        $careersStmt->execute($recCareerIds);
        $careersById = [];
        foreach ($careersStmt->fetchAll() as $row) {
            $careersById[(int) $row['career_id']] = $row;
        }
        foreach ($recCareerIds as $cid) {
            if (isset($careersById[$cid])) {
                $reconstructed[] = build_career_match_data($careersById[$cid], $studentVectorAssoc);
            }
        }
    }
    $result = ['recommendations' => $reconstructed];
    $hasMatchResults = !empty($reconstructed);
} else {
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

    if ($curlError) {
        $errorMessage = "Could not reach the matching engine: $curlError. Is the Flask service running (python app.py) on port 5000?";
    } else {
        $result = json_decode($response, true);
        if ($httpCode !== 200 || !$result || isset($result['error'])) {
            $errorMessage = $result['error'] ?? "Matching engine returned an unexpected response (HTTP $httpCode).";
        }
    }
    $hasMatchResults = !$errorMessage && $result && !empty($result['recommendations']);
}

// Save this submission + its recommendations so the student can look back
// at it later on student_history.php. Saved whenever we have either a
// dream-career fit (computed locally, doesn't need the matching service) or
// a successful matching-service result — so a Flask hiccup doesn't wipe out
// the dream-career half of the feature. Skipped entirely on replay — this
// exact profile was already saved the first time it was submitted, and
// re-running it here on every back-nav/refresh is exactly the duplicate-save
// bug the redirect above exists to prevent.
if (!$isReplay && ($dreamCareerData || $hasMatchResults)) {
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

        // AI commentary on this student's own result (migration_21) — best
        // effort, generated once here (after the transaction above has
        // already committed, so this network call to an LLM — which can
        // take several seconds — never holds a DB transaction open) and
        // cached rather than regenerated on every future page view. Only
        // runs when there's a dream career to comment on (always true for
        // new submissions — dream career is required by assessment.php).
        // Grounded in that career's own counselor-approved fields; if it has
        // no verified skill_requirements yet, the Flask/Gemini side flags
        // that itself in Python (skills_are_suggested) so the pages can
        // label those skills distinctly rather than passing them off as
        // verified. Never blocks the results page — on any failure
        // (matching service down, no API key, Gemini error) the ai_*
        // columns simply stay NULL and every page that would show them
        // falls back to exactly what it shows today, same convention as
        // /enrich.
        if ($dreamCareerData) {
            try {
                $dreamSkillsStmt = $pdo->prepare(
                    "SELECT skill_name, proficiency_level, is_required FROM skill_requirements WHERE career_id = :id"
                );
                $dreamSkillsStmt->execute(['id' => $dreamCareerData['career_id']]);
                // PDO returns every column as a PHP string here (no
                // ATTR_STRINGIFY_FETCHES override in db.php), so is_required
                // comes back as "0"/"1" rather than a real bool. PHP's
                // (bool)"0" is false, but once that string crosses the JSON
                // boundary, Python's bool("0") is true -- cast to a real
                // bool now so json_encode emits an actual JSON true/false
                // instead of a truthy string.
                $dreamRequiredSkills = array_map(function ($s) {
                    $s['is_required'] = (bool) ((int) $s['is_required']);
                    return $s;
                }, $dreamSkillsStmt->fetchAll());

                $commentaryPayload = json_encode([
                    'riasec' => $studentRiasecPct,
                    'academic_average' => $academicAverage,
                    'student_skills' => $studentSkillsRaw !== '' ? $studentSkillsRaw : null,
                    'career_title' => $dreamCareerData['career_title'],
                    'career_description' => $dreamCareerData['description'],
                    'career_key_subjects' => $dreamCareerData['key_subjects'],
                    'career_required_skills' => $dreamRequiredSkills,
                ]);

                $chCommentary = curl_init(STUDENT_COMMENTARY_SERVICE_URL);
                curl_setopt_array($chCommentary, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $commentaryPayload,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 35, // calls an LLM, give it room, same budget as /enrich
                ]);
                $commentaryResponse = curl_exec($chCommentary);
                $commentaryHttpCode = curl_getinfo($chCommentary, CURLINFO_HTTP_CODE);
                $commentaryCurlError = curl_error($chCommentary);
                curl_close($chCommentary);

                $commentaryResult = $commentaryCurlError ? null : json_decode($commentaryResponse, true);

                if (!$commentaryCurlError && $commentaryHttpCode === 200 && !empty($commentaryResult['ai_commentary'])) {
                    $updateCommentary = $pdo->prepare(
                        "UPDATE student_profiles SET
                            ai_summary = :summary,
                            ai_career_commentary = :career_commentary,
                            ai_skills_are_suggested = :skills_are_suggested,
                            ai_commentary_generated_at = NOW()
                         WHERE profile_id = :id"
                    );
                    $updateCommentary->execute([
                        'summary' => $commentaryResult['summary'],
                        'career_commentary' => $commentaryResult['career_commentary'],
                        'skills_are_suggested' => !empty($commentaryResult['skills_are_suggested']) ? 1 : 0,
                        'id' => $profileId,
                    ]);
                    // Reflected into the in-memory array too, so the results
                    // page rendered further down this same request shows it
                    // immediately instead of only on the next page load.
                    $dreamCareerData['ai_summary'] = $commentaryResult['summary'];
                    $dreamCareerData['ai_career_commentary'] = $commentaryResult['career_commentary'];
                    $dreamCareerData['ai_skills_are_suggested'] = !empty($commentaryResult['skills_are_suggested']);
                }
                // Any other outcome (unreachable service, non-200, ai_commentary
                // false) — leave the ai_* columns NULL, exactly the same
                // fallback this system already uses for career enrichment.
            } catch (Exception $e) {
                // Never let this sub-step affect the rest of the submission.
            }
        }

        // Notification Module (Gantt chart item, not a named ERD entity —
        // see README) — assessment-completion notice. Best-effort: never
        // block the results page over a notification insert failing.
        try {
            notify_student($pdo, (int) $currentStudent['student_id'], 'Your new RIASEC assessment results are ready.', 'student_history.php', 'assessment');
        } catch (Exception $e) {
            // ignore
        }

        // Post/Redirect/Get: send the browser to a plain GET URL for this
        // same profile instead of rendering the results directly off this
        // POST. See $isReplay above — this is what makes the Back button
        // land on a safe re-fetch instead of a "Confirm Form Resubmission"
        // prompt, without duplicating this save on every back-nav.
        header('Location: submit.php?profile_id=' . $profileId);
        exit;
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CareerPath AI — Your Recommendations</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 1280px; margin: 40px auto; padding: 0 20px; color: #222; }
    body > h1, body > .error, body > .career, body > a.back { max-width: 760px; margin-left: auto; margin-right: auto; }
    body > .profile { max-width: 1240px; margin-left: auto; margin-right: auto; }
    body > .results-top-row { max-width: 1240px; margin-left: auto; margin-right: auto; }
    h1 { color: #6e1423; }
    .profile { background: #faf0f1; border-radius: 8px; padding: 14px 20px; margin-bottom: 26px; white-space: nowrap; overflow-x: auto; }
    .profile span { display: inline-block; margin-right: 14px; font-size: 15px; }
    /* Same wrap-instead-of-clip fix as student_history.php's .profile --
       a long "Your skills" list otherwise got cut off at the screen edge
       on mobile with no hint it was scrollable. */
    @media (max-width: 600px) {
        .profile { white-space: normal; overflow-x: visible; }
        .profile span { margin-bottom: 6px; }
    }
    /* RIASEC results card — labeled, animated bars in MEII's own maroon
       gradient (same #6e1423 -> #b3465c treatment already used for the
       trait bars on student_dashboard.php/career_profile.php), replacing
       the old plain-text "R: 61% I: 75% ..." line with something scannable
       at a glance. Bars fill in on page load via a CSS-only animation
       (width: 0 -> the real percentage) rather than JS, so it still works
       if scripts are blocked. */
    .riasec-card-title { font-weight: bold; color: #6e1423; font-size: 16px; margin-bottom: 14px; }
    .riasec-row { display: flex; align-items: center; gap: 14px; margin-bottom: 10px; }
    .riasec-row:last-of-type { margin-bottom: 0; }
    .riasec-row-label { flex: 0 0 190px; font-size: 14.5px; color: #333; }
    .riasec-row-track { flex: 1; background: #eddadd; border-radius: 6px; height: 14px; overflow: hidden; }
    .riasec-row-fill { display: block; height: 100%; border-radius: 6px; width: 0; background: linear-gradient(90deg, #6e1423, #b3465c); animation: riasec-fill-in 0.9s ease-out forwards; }
    @keyframes riasec-fill-in { to { width: var(--pct); } }
    .riasec-row-pct { flex: 0 0 48px; text-align: right; font-size: 14.5px; font-weight: bold; color: #6e1423; }
    .riasec-academic-row { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; padding-top: 14px; border-top: 1px solid #e3c9cd; font-size: 14.5px; color: #555; }
    /* At narrow widths the fixed 190px label + 48px pct + 2x14px gap (266px)
       left literally 0px for the middle bar in a ~266px-wide mobile card --
       the colored fill collapsed to nothing, leaving a label ... blank gap
       ... percentage layout that read as "the bar disappeared" / broken
       alignment. Shrinking the label/pct columns and the gap on mobile
       reclaims enough room for the bar to render at a visible width again. */
    @media (max-width: 480px) {
        .how-it-works, .riasec-card { padding: 16px; }
        .riasec-row { gap: 8px; }
        .riasec-row-label { flex-basis: 96px; font-size: 12.5px; }
        .riasec-row-pct { flex-basis: 36px; font-size: 12.5px; }
    }
    .riasec-academic-row strong { color: #6e1423; font-size: 15.5px; }
    .career { background: #f5f5f5; border: 1px solid #ddd; border-radius: 8px; padding: 16px 20px; margin-bottom: 16px; }
    .career h3 { margin: 0 0 6px 0; color: #6e1423; }
    .career h3 a { color: #6e1423; text-decoration: none; }
    .career h3 a:hover { text-decoration: underline; }
    .match { float: right; background: #6e1423; color: #fff; padding: 4px 10px; border-radius: 12px; font-size: 14.5px; }
    .scope-badge { float: right; clear: right; margin-top: 6px; padding: 3px 10px; border-radius: 10px; font-size: 12.5px; font-weight: bold; }
    .scope-local { background: #d1e7dd; color: #0f5132; }
    .scope-international { background: #e7d9f7; color: #4b2e83; }
    .error { background: #fdecea; border: 1px solid #f5c6cb; color: #611a15; padding: 14px 20px; border-radius: 8px; }
    a.back { display: inline-block; margin-top: 20px; color: #6e1423; }
    .skills-box { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 14.5px; }
    .skills-box .pct { font-weight: bold; color: #6e1423; }
    .skill-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 13.5px; }
    .skill-have { background: #d1e7dd; color: #0f5132; }
    .skill-need { background: #fff3cd; color: #856404; }
    /* Side-by-side layout for the "how were these calculated" explanation
       and the RIASEC bar chart — was two full-width stacked boxes, now a
       matched pair of cards so the page reads as a dashboard at a glance
       instead of a long scroll. Stacks back to one column on narrow
       screens/mobile rather than squeezing both into half-width. */
    .results-top-row { display: flex; gap: 20px; align-items: flex-start; margin-bottom: 26px; }
    .results-top-row > div { flex: 1 1 0; min-width: 0; }
    /* align-items: flex-start (above) is meant for the desktop side-by-side
       layout, so a shorter card doesn't stretch to match a taller one. But
       once flex-direction flips to column here, that same align-items now
       controls the CROSS axis, which is horizontal in column mode -- so
       flex-start made each stacked card shrink-wrap to its own minimum
       content width instead of using the phone's full width. With the
       RIASEC card's fixed-width label+percentage columns, that minimum
       content width left ~0px for the colored bar itself. align-items:
       stretch here restores full-width stacked cards, which is what
       actually fixes the vanishing bar (the column-width tweaks below are
       just a secondary safety margin on very narrow phones). */
    @media (max-width: 880px) { .results-top-row { flex-direction: column; align-items: stretch; } }
    .how-it-works, .riasec-card { background: #faf0f1; border-radius: 10px; padding: 20px 24px; box-shadow: 0 2px 6px rgba(110,20,35,0.06); transition: transform 0.15s ease, box-shadow 0.15s ease; }
    .how-it-works:hover, .riasec-card:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(110,20,35,0.14); }
    .how-it-works .heading { font-weight: bold; color: #6e1423; padding: 0 0 14px; font-size: 16px; }
    .how-it-works .content { font-size: 15px; line-height: 1.6; color: #444; }
    .how-it-works .content p:first-child { margin-top: 0; }
    .how-it-works .content p:last-child { margin-bottom: 0; }
    .why-match { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 14.5px; color: #444; }
    .why-match .dim { display: inline-block; background: #f0dde1; color: #6e1423; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 13.5px; font-weight: bold; }
    .growth-box { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 14.5px; color: #444; }
    .growth-box .growth-dim { display: inline-block; background: #fff3cd; color: #856404; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 13.5px; font-weight: bold; }
    .subjects-box { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 14.5px; color: #444; }
    .ai-insights-box { background: #f3edfb; border: 1px solid #e2d4f5; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px; font-size: 14.5px; color: #444; }
    .ai-insights-box p { margin: 0; line-height: 1.6; }
    .ai-insights-box-inline { margin: 10px 0 0; padding-top: 10px; padding-bottom: 10px; border-top: 1px solid #eee; background: none; border: none; border-radius: 0; padding-left: 0; padding-right: 0; }
    .ai-insights-box-inline p { background: #f3edfb; border: 1px solid #e2d4f5; border-radius: 8px; padding: 12px 16px; margin-top: 6px; }
    .ai-insights-label { font-weight: bold; color: #6f42c1; font-size: 14px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .ai-suggestion-tag { background: #fff3cd; color: #856404; border: 1px solid #ffe69c; border-radius: 10px; padding: 2px 9px; font-size: 12px; font-weight: bold; cursor: help; }
    .site-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 480px; max-width: 60vw; opacity: 0.15; z-index: -1; pointer-events: none; user-select: none; }

    .dream-career-section { max-width: 760px; margin: 0 auto 30px; }
    .dream-career-section.has-counterpart { max-width: 1180px; }
    .dream-career-section h2 { font-size: 17.5px; color: #6e1423; margin: 0 0 10px; }
    .career.dream-career-card { border: 2px solid #6e1423; box-shadow: 0 6px 18px rgba(110,20,35,0.14); }
    .dream-note { font-size: 13.5px; color: #888; margin: -4px 0 12px; }
    .dream-compare-row { display: flex; gap: 20px; align-items: flex-start; }
    .dream-compare-row .career { flex: 1; min-width: 0; margin-bottom: 0; }
    @media (max-width: 760px) { .dream-compare-row { flex-direction: column; } }

    .field-careers-section { max-width: 760px; margin: 0 auto 30px; }
    .field-careers-section h2 { font-size: 17.5px; color: #6e1423; margin: 0 0 10px; }

    .explore-others { max-width: 760px; margin: 0 auto 20px; }
    .explore-others summary { cursor: pointer; font-weight: bold; color: #6e1423; padding: 12px 0; font-size: 16.5px; }
    .explore-others .explore-note { font-size: 14px; color: #888; margin: -6px 0 16px; }
    .inline-note { font-size: 14.5px; color: #888; font-style: italic; max-width: 760px; margin: 0 auto 20px; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1>Your Career Recommendations</h1>

    <div class="results-top-row">
        <div class="how-it-works">
            <div class="heading">🔍 How were these recommendations calculated? (see the math)</div>
            <div class="content">
                <p>Your match score comes from your 42 answers. We compare your results to each career's profile, reviewed and approved by a guidance counselor, using cosine similarity, a rule-based formula, not AI, so the score is fair and consistent. Each result shows a "Why this match?" section explaining which traits mattered most.</p>
                <p><strong>AI Insights.</strong> Some career descriptions have an "AI Insights" label. These are written with the help of AI, then checked and approved by a guidance counselor. They're just extra info, they don't change your match score.</p>
            </div>
        </div>

        <div class="riasec-card">
            <div class="riasec-card-title">Your RIASEC Profile</div>
            <?php $riasecRowIndex = 0; foreach ($riasec as $type => $score): $pct = (int) round($score * 100); ?>
                <div class="riasec-row">
                    <span class="riasec-row-label"><?= htmlspecialchars($riasecTypeNames[$type] ?? $type) ?> (<?= $type ?>)</span>
                    <div class="riasec-row-track">
                        <span class="riasec-row-fill" style="--pct: <?= $pct ?>%; animation-delay: <?= number_format($riasecRowIndex * 0.08, 2) ?>s;"></span>
                    </div>
                    <span class="riasec-row-pct"><?= $pct ?>%</span>
                </div>
            <?php $riasecRowIndex++; endforeach; ?>
            <?php if ($academicAverage !== null): ?>
                <div class="riasec-academic-row">
                    <span>Academic average</span>
                    <strong><?= number_format($academicAverage, 2) ?></strong>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($studentSkillsRaw !== ''): ?>
        <div class="profile" style="margin-top:-8px;">
            <span><strong>Your skills:</strong> <?= htmlspecialchars(implode(', ', array_map('trim', explode(',', $studentSkillsRaw)))) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($dreamCareerData['ai_summary'])): ?>
        <div class="ai-insights-box">
            <div class="ai-insights-label">✨ AI Insights — a note on your own results</div>
            <p><?= htmlspecialchars($dreamCareerData['ai_summary']) ?></p>
        </div>
    <?php endif; ?>

    <?php
        // Shared card renderer for the dream-career highlight, same-field
        // careers, and the "explore other careers" list, so all three stay
        // visually/structurally identical.
        function render_career_card($career, $pdo, $studentSkillsRaw, $riasecNames, $studentRiasecPct, $extraClass = '')
        {
            // Pass null (not '') when the student left "Your skills" blank —
            // compute_skill_match() only skips showing a match percentage
            // (rather than a misleading "0% match") when this is null, same
            // as every other page that calls it from a saved DB column.
            $skillMatch = ($pdo && !empty($career['career_id']))
                ? compute_skill_match($pdo, (int) $career['career_id'], $studentSkillsRaw !== '' ? $studentSkillsRaw : null)
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
                <?php if (!empty($career['career_scope'])): ?>
                    <span class="scope-badge scope-<?= htmlspecialchars($career['career_scope']) ?>"><?= $career['career_scope'] === 'local' ? '🇵🇭 Local' : '🌍 International' ?></span>
                <?php endif; ?>
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

                <?php if (!empty($career['ai_career_commentary'])): ?>
                    <div class="ai-insights-box ai-insights-box-inline">
                        <div class="ai-insights-label">
                            ✨ AI Insights
                            <?php if (!empty($career['ai_skills_are_suggested'])): ?>
                                <span class="ai-suggestion-tag" title="This career doesn't have a counselor-verified skills list yet, so any skills mentioned here are general AI suggestions, not a verified requirement.">AI-suggested skills</span>
                            <?php endif; ?>
                        </div>
                        <p><?= htmlspecialchars($career['ai_career_commentary']) ?></p>
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
        <div class="dream-career-section<?= $dreamCareerCounterpart ? ' has-counterpart' : '' ?>">
            <h2>🎯 Your Dream Career<?= $dreamCareerData['career_category'] ? ' · ' . htmlspecialchars($dreamCareerData['career_category']) : '' ?></h2>
            <?php if ($dreamCareerCounterpart): ?>
                <p class="dream-note">This is the career you picked before the assessment — since it's available both locally and internationally, here's how your RIASEC profile fits each version.</p>
                <div class="dream-compare-row">
                    <?php render_career_card($dreamCareerData, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct, 'dream-career-card'); ?>
                    <?php render_career_card($dreamCareerCounterpart, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct, 'dream-career-card'); ?>
                </div>
            <?php else: ?>
                <p class="dream-note">This is the career you picked before the assessment — here's how your RIASEC profile actually fits it.</p>
                <?php render_career_card($dreamCareerData, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct, 'dream-career-card'); ?>
            <?php endif; ?>
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
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
