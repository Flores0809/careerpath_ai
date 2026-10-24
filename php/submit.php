<?php
// 1. Scores the RIASEC intake form (sum per type, normalize 0-1)

require __DIR__ . '/student_auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/skills_helper.php';
require_once __DIR__ . '/riasec_helper.php';
require_once __DIR__ . '/notifications_helper.php';

$currentStudent = require_student_login();

$pdo = get_db();

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
    $maxPerType = 10 * 4; // 10 questions x max score of 4

    $riasec = [];
    foreach ($types as $type) {
        $answers = $_POST[$type] ?? [];
        $sum = array_sum(array_map('intval', $answers));
        $riasec[$type] = round($sum / $maxPerType, 4); // normalize to 0-1
    }

    $studentSkillsRaw = trim($_POST['skills'] ?? '');
    if ($studentSkillsRaw === '') {
        http_response_code(400);
        die('Please list your skills (or type "N/A" if you are not sure). <a href="assessment.php">Go back</a>');
    }
    if (preg_match('/^n\.?\/?\s?a\.?$|^none$/i', $studentSkillsRaw)) {
        $studentSkillsRaw = '';
    }
    $academicAverageRaw = trim($_POST['academic_average'] ?? '');
    if ($academicAverageRaw === '' || !is_numeric($academicAverageRaw)) {
        http_response_code(400);
        die('Academic average is required. <a href="assessment.php">Go back</a>');
    }
    $academicAverage = max(0, min(100, (float) $academicAverageRaw));

    $dreamCategory = trim($_POST['dream_category'] ?? '');
    $dreamCareerId = 0;
    if ($dreamCategory === '') {
        http_response_code(400);
        die('Please choose the industry you want to work in. <a href="assessment.php">Go back</a>');
    }
    if ($dreamCategory !== '') {
        $catStmt = $pdo->prepare(
            "SELECT * FROM careers WHERE status = 'active' AND career_category = :cat"
        );
        $catStmt->execute(['cat' => $dreamCategory]);
        $bestScore = -1.0;
        foreach ($catStmt->fetchAll() as $catCareer) {
            $catVector = [
                'R' => $catCareer['r_score'] / 100, 'I' => $catCareer['i_score'] / 100, 'A' => $catCareer['a_score'] / 100,
                'S' => $catCareer['s_score'] / 100, 'E' => $catCareer['e_score'] / 100, 'C' => $catCareer['c_score'] / 100,
            ];
            $catScore = cosine_similarity_riasec($riasec, $catVector);
            if ($catScore > $bestScore) {
                $bestScore = $catScore;
                $dreamCareerId = (int) $catCareer['career_id'];
            }
        }
    }
}

$riasecTypeNames = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];

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
        return 50.0;
    }
    $r = $dot / (sqrt($normA) * sqrt($normB));
    return ($r + 1) / 2 * 100;
}

// Shared: turn a `careers` row into the display-ready array (with computed
// match score + top contributing dimensions) used by render_career_card().
// Used to rebuild every saved recommendation identically on replay.
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
        'source' => $careerRow['source'] ?? null,
        'updated_at' => $careerRow['updated_at'] ?? null,
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

$result = null;
$errorMessage = null;
$hasMatchResults = false;
$aiDreamCommentary = null;
$aiDreamSkillsSuggested = null;

if ($isReplay) {
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
    // AI commentary is only generated for the single best match (rank 1), at
    // submit time, and cached on student_profiles — reuse it here instead of
    // calling the AI again on every back-nav/refresh.
    if ($reconstructed) {
        $reconstructed[0]['ai_career_commentary'] = $replayProfile['ai_career_commentary'] ?? null;
        $reconstructed[0]['ai_skills_are_suggested'] = !empty($replayProfile['ai_skills_are_suggested']);
    }
    $aiDreamCommentary = $replayProfile['ai_dream_commentary'] ?? null;
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

if (!$isReplay && $hasMatchResults) {
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
            'dream_career_id' => $dreamCareerId > 0 ? $dreamCareerId : null,
        ]);
        $profileId = (int) $pdo->lastInsertId();

        if ($hasMatchResults) {
            $insertRecommendation = $pdo->prepare(
                "INSERT INTO recommendations (profile_id, student_id, career_id, match_score, rank_position)
                 VALUES (:profile_id, :student_id, :career_id, :match_score, :rank_position)"
            );
            foreach ($result['recommendations'] as $rank => $career) {
                if (empty($career['career_id'])) {
                    continue;
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

        $topCareerId = (int) ($result['recommendations'][0]['career_id'] ?? 0);
        $topCareerStmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id");
        $topCareerStmt->execute(['id' => $topCareerId]);
        $topCareerRow = $topCareerId > 0 ? $topCareerStmt->fetch() : false;
        if ($topCareerRow) {
            try {
                $topSkillsStmt = $pdo->prepare(
                    "SELECT skill_name, proficiency_level, is_required FROM skill_requirements WHERE career_id = :id"
                );
                $topSkillsStmt->execute(['id' => $topCareerId]);
                $topRequiredSkills = array_map(function ($s) {
                    $s['is_required'] = (bool) ((int) $s['is_required']);
                    return $s;
                }, $topSkillsStmt->fetchAll());

                $commentaryData = [
                    'riasec' => $studentRiasecPct,
                    'academic_average' => $academicAverage,
                    'student_skills' => $studentSkillsRaw !== '' ? $studentSkillsRaw : null,
                    'career_title' => $topCareerRow['career_title'],
                    'career_description' => $topCareerRow['description'],
                    'career_key_subjects' => $topCareerRow['key_subjects'] ?? null,
                    'career_required_skills' => $topRequiredSkills,
                ];

                // Optional dream career: its note comes from the SAME Gemini call
                // (one request for both careers), only when the student picked one
                // and it is not the same career as the best match.
                if ($dreamCareerId > 0 && $dreamCareerId !== $topCareerId) {
                    $dreamRowStmt = $pdo->prepare("SELECT * FROM careers WHERE career_id = :id");
                    $dreamRowStmt->execute(['id' => $dreamCareerId]);
                    $dreamRow = $dreamRowStmt->fetch();
                    if ($dreamRow) {
                        $dreamSkillsStmt = $pdo->prepare(
                            "SELECT skill_name, proficiency_level, is_required FROM skill_requirements WHERE career_id = :id"
                        );
                        $dreamSkillsStmt->execute(['id' => $dreamCareerId]);
                        $dreamSkills = array_map(function ($sk) {
                            $sk['is_required'] = (bool) ((int) $sk['is_required']);
                            return $sk;
                        }, $dreamSkillsStmt->fetchAll());
                        $commentaryData['dream_career'] = [
                            'career_title' => $dreamRow['career_title'],
                            'career_description' => $dreamRow['description'],
                            'career_key_subjects' => $dreamRow['key_subjects'] ?? null,
                            'riasec' => [
                                'R' => (int) $dreamRow['r_score'], 'I' => (int) $dreamRow['i_score'], 'A' => (int) $dreamRow['a_score'],
                                'S' => (int) $dreamRow['s_score'], 'E' => (int) $dreamRow['e_score'], 'C' => (int) $dreamRow['c_score'],
                            ],
                            'required_skills' => $dreamSkills,
                        ];
                    }
                }
                $commentaryPayload = json_encode($commentaryData);

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
                    ensure_dream_commentary_column($pdo);
                    $updateCommentary = $pdo->prepare(
                        "UPDATE student_profiles SET
                            ai_career_commentary = :career_commentary,
                            ai_skills_are_suggested = :skills_are_suggested,
                            ai_commentary_generated_at = NOW()
                         WHERE profile_id = :id"
                    );
                    $updateCommentary->execute([
                        'career_commentary' => $commentaryResult['career_commentary'],
                        'skills_are_suggested' => !empty($commentaryResult['skills_are_suggested']) ? 1 : 0,
                        'id' => $profileId,
                    ]);
                    if (!empty($commentaryResult['dream_commentary'])) {
                        try {
                            $pdo->prepare("UPDATE student_profiles SET ai_dream_commentary = :d WHERE profile_id = :id")
                                ->execute(['d' => $commentaryResult['dream_commentary'], 'id' => $profileId]);
                        } catch (Exception $e) {
                            // column missing and could not be added: note just isn't stored
                        }
                    }
                    $result['recommendations'][0]['ai_career_commentary'] = $commentaryResult['career_commentary'];
                    $result['recommendations'][0]['ai_skills_are_suggested'] = !empty($commentaryResult['skills_are_suggested']);
                    $aiDreamCommentary = $commentaryResult['dream_commentary'] ?? null;
                    if ($dreamCareerId > 0 && $dreamCareerId !== $topCareerId && empty($commentaryResult['dream_commentary'])) {
                        // The matching service answered but sent no dream note: it is
                        // most likely still running the older version of app.py.
                        error_log('student_commentary: no dream_commentary returned (is the matching service up to date?)');
                    }
                } else {
                    error_log('student_commentary failed: ' . ($commentaryCurlError ?: ('HTTP ' . $commentaryHttpCode . ' ' . substr((string) $commentaryResponse, 0, 300))));
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
    .riasec-card-title { font-weight: bold; color: #6e1423; font-size: 16px; margin-bottom: 14px; }
    .riasec-row { display: flex; align-items: center; gap: 14px; margin-bottom: 10px; }
    .riasec-row:last-of-type { margin-bottom: 0; }
    .riasec-row-label { flex: 0 0 190px; font-size: 14.5px; color: #333; }
    .riasec-row-track { flex: 1; background: #eddadd; border-radius: 6px; height: 14px; overflow: hidden; }
    .riasec-row-fill { display: block; height: 100%; border-radius: 6px; width: 0; background: linear-gradient(90deg, #6e1423, #b3465c); animation: riasec-fill-in 0.9s ease-out forwards; }
    @keyframes riasec-fill-in { to { width: var(--pct); } }
    .riasec-row-pct { flex: 0 0 48px; text-align: right; font-size: 14.5px; font-weight: bold; color: #6e1423; }
    .riasec-academic-row { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; padding-top: 14px; border-top: 1px solid #e3c9cd; font-size: 14.5px; color: #555; }
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
    .skills-box .skills-good { display: block; background: #e8f5ec; border: 1px solid #b9dfc6; color: #0f5132; border-radius: 8px; padding: 10px 14px; font-weight: bold; line-height: 1.5; }
    .skill-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; margin: 2px 4px 2px 0; font-size: 13.5px; }
    .skill-have { background: #d1e7dd; color: #0f5132; }
    .skill-need { background: #fff3cd; color: #856404; }
    .skill-needed { background: #f0dde1; color: #6e1423; }
    .results-top-row { display: flex; gap: 20px; align-items: flex-start; margin-bottom: 26px; }
    .results-top-row > div { flex: 1 1 0; min-width: 0; }
    @media (max-width: 880px) { .results-top-row { flex-direction: column; align-items: stretch; } }
    .how-it-works, .riasec-card { background: #faf0f1; border-radius: 10px; padding: 20px 24px; box-shadow: 0 2px 6px rgba(110,20,35,0.06); transition: transform 0.15s ease, box-shadow 0.15s ease; }
    .how-it-works:hover, .riasec-card:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(110,20,35,0.14); }
    .how-it-works .heading { font-weight: bold; color: #6e1423; padding: 0 0 14px; font-size: 16px; }
    .how-it-works .content { font-size: 15px; line-height: 1.6; color: #444; }
    .how-it-works .content p:first-child { margin-top: 0; }
    .how-it-works .content p:last-child { margin-bottom: 0; }
    .source-line { margin: 4px 0 8px; }
    .src-badge, .upd-badge { display: inline-block; border-radius: 12px; padding: 3px 10px; font-size: 12.5px; font-weight: bold; margin: 2px 6px 2px 0; }
    .src-badge { background: #f0dde1; color: #6e1423; }
    .upd-badge { background: #e7f1ff; color: #0a4a9e; border: 1px solid #b6d4fe; }
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

    .best-match-section, .other-matches-section { max-width: 760px; margin: 0 auto 30px; }
    .best-match-section h2, .other-matches-section h2 { font-size: 17.5px; color: #6e1423; margin: 0 0 10px; }
    .career.best-match-card { border: 2px solid #6e1423; box-shadow: 0 6px 18px rgba(110,20,35,0.14); }
    .match-note { font-size: 13.5px; color: #888; margin: -4px 0 12px; }
/* Optional dream-career comparison: system's best match on the left, the
       student's own dream career on the right. Stacks on narrow screens. */
    .compare-section { max-width: 1240px; margin: 0 auto 30px; }
    .compare-verdict { background: #faf0f1; border: 1px solid #e3c9cd; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 14.5px; line-height: 1.5; }
    .compare-grid { display: flex; gap: 20px; align-items: flex-start; }
    .compare-col { flex: 1 1 0; min-width: 0; }
    .compare-col h2 { font-size: 17.5px; color: #6e1423; margin: 0 0 4px; }
    .compare-col .match-note { margin: 0 0 12px; }
    .compare-col .career { margin-bottom: 0; }
    .career.dream-card { border: 2px dashed #6e1423; }
    .compare-table { width: 100%; border-collapse: collapse; margin-top: 0; font-size: 14.5px; background: #fff; border: 1px solid #e3c9cd; border-radius: 8px; }
    .compare-table th, .compare-table td { padding: 8px 12px; border-bottom: 1px solid #eee; text-align: center; }
    .compare-table th:first-child, .compare-table td:first-child { text-align: left; }
    .compare-table th { background: #faf0f1; color: #6e1423; font-size: 13.5px; }
    @media (max-width: 800px) { .compare-grid { flex-direction: column; align-items: stretch; } }
.info-details { background: #f9f3f4; border: 1px solid #ecd5d9; border-radius: 8px; padding: 10px 14px; margin: 10px 0; font-size: 14.5px; line-height: 1.5; }
    .info-details summary { cursor: pointer; font-weight: bold; color: #6e1423; }
    .info-details ul { margin: 8px 0 0; padding-left: 20px; }
</style>
</head>
<body>
    <img src="assets/img/logo.png" alt="" class="site-watermark">

    <?php require __DIR__ . '/student_nav.php'; ?>

    <h1>Your Career Recommendations</h1>
    <?php render_riasec_guide(); ?>

    <div class="results-top-row">
        <div class="how-it-works">
            <div class="heading">🔍 How were these recommendations calculated? (see the math)</div>
            <div class="content">
                <p>Your match score comes from your 60 answers. We compare your results to each career's profile, reviewed and approved by a guidance counselor, using cosine similarity, a rule-based formula, not AI, so the score is fair and consistent. Each result shows a "Why this match?" section explaining which traits mattered most.</p>
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

    <?php
        // Shared card renderer for the best match and the other recommended
        // careers, so they stay visually/structurally identical.
        function render_career_card($career, $pdo, $studentSkillsRaw, $riasecNames, $studentRiasecPct, $extraClass = '')
        {
            // Pass null (not '') when the student left "Your skills" blank —
            // compute_skill_match() only skips showing a match percentage
            // (rather than a misleading "0% match") when this is null, same
            // as every other page that calls it from a saved DB column.
            $skillMatch = ($pdo && !empty($career['career_id']))
                ? compute_skill_match($pdo, (int) $career['career_id'], $studentSkillsRaw !== '' ? $studentSkillsRaw : null)
                : null;

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
                <p><strong>Programs you can enroll in:</strong> <?= htmlspecialchars($career['educational_pathway']) ?></p>
                <p class="source-line"><?= career_source_badges($career) ?></p>

                <?php if (!empty($career['top_dimensions'])): ?>
                    <div class="why-match">
                        <strong>Why this match?</strong> <?= htmlspecialchars(recommendation_reason($career)) ?><br>Your strongest overlap with this career:
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

                <?php if ($skillMatch && $skillMatch['required_count'] > 0): ?>
                    <?php $skillMsg = skill_match_message($skillMatch); ?>
                    <div class="skills-box">
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
                <?php elseif ($skillMatch): ?>
                    <div class="skills-box" style="color:#888;font-style:italic;">No required-skills list has been set up for this career yet.</div>
                <?php endif; ?>
            </div>
            <?php
        }
    ?>

    <?php if ($errorMessage && !$hasMatchResults): ?>
        <div class="error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php elseif ($hasMatchResults): ?>
        <?php
            $allRecommendations = array_values($result['recommendations']);
            $bestMatch = $allRecommendations[0];
            $otherMatches = array_slice($allRecommendations, 1);
        ?>
        <?php
            // Optional dream career, scored the same way as every other career.
            $dreamData = ($pdo && !empty($dreamCareerId)) ? load_dream_career($pdo, $dreamCareerId, $studentVectorAssoc) : null;
            $dreamCard = $dreamData ? build_career_match_data($dreamData['row'], $studentVectorAssoc) : null;
            if ($dreamCard) {
                // AI note for the dream career (generated in the same call as the
                // best-match note, cached on the profile). Flag general suggestions
                // when the dream career has no counselor-verified skills yet.
                $dreamCard['ai_career_commentary'] = $aiDreamCommentary;
                $dreamSkillCount = $pdo->prepare("SELECT COUNT(*) FROM skill_requirements WHERE career_id = :id");
                $dreamSkillCount->execute(['id' => $dreamCard['career_id']]);
                $dreamCard['ai_skills_are_suggested'] = ((int) $dreamSkillCount->fetchColumn() === 0);
            }
        ?>
        <?php if ($dreamCard): ?>
            <?php $sameCareer = (int) $dreamCard['career_id'] === (int) $bestMatch['career_id']; ?>
            <div class="compare-section">
                <div class="compare-verdict">
                    <strong>Your results vs. your chosen industry.</strong>
                    <?= htmlspecialchars(dream_comparison_text($bestMatch['career_title'], (float) $bestMatch['match_score'], $dreamCard['career_title'], (float) $dreamCard['match_score'], (int) $dreamData['rank'], (int) $dreamData['total'], $sameCareer)) ?>
                </div>
                <table class="compare-table">
                    <tr>
                        <th>RIASEC trait</th>
                        <th>You</th>
                        <th>System's best match<br><?= htmlspecialchars($bestMatch['career_title']) ?></th>
                        <th>Closest fit in your chosen industry<br><?= htmlspecialchars($dreamCard['career_title']) ?></th>
                    </tr>
                    <?php foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $k): ?>
                        <tr>
                            <td><?= htmlspecialchars($riasecTypeNames[$k]) ?> (<?= $k ?>)</td>
                            <td><strong><?= (int) round($studentRiasecPct[$k]) ?>%</strong></td>
                            <td><?= (int) ($bestMatch['career_riasec'][$k] ?? 0) ?>%</td>
                            <td><?= (int) ($dreamCard['career_riasec'][$k] ?? 0) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><strong>Match with your profile</strong></td>
                        <td>&mdash;</td>
                        <td><strong><?= number_format($bestMatch['match_score'], 0) ?>%</strong></td>
                        <td><strong><?= number_format($dreamCard['match_score'], 0) ?>%</strong></td>
                    </tr>
                </table>
                <div class="compare-grid" style="margin-top:20px;">
                    <div class="compare-col">
                        <h2>🎯 What best suits you</h2>
                        <p class="match-note">The career with the highest RIASEC match for your answers.</p>
                        <?php render_career_card($bestMatch, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct, 'best-match-card'); ?>
                    </div>
                    <div class="compare-col">
                        <h2>💭 Your chosen industry<?= !empty($dreamData['row']['career_category']) ? ' · ' . htmlspecialchars($dreamData['row']['career_category']) : '' ?></h2>
                        <p class="match-note">The career you said you are aiming for, scored the same way.</p>
                        <?php render_career_card($dreamCard, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct, 'dream-card'); ?>
                        <?php if (!$sameCareer && empty($aiDreamCommentary)): ?>
                            <p class="match-note" style="margin-top:8px;font-style:italic;">No AI insight was saved for this career. The AI service may have been unavailable when this assessment was submitted; retaking the assessment will generate one.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
        <div class="best-match-section">
            <h2>🎯 Your Best Match</h2>
            <p class="match-note">Based only on your answers, this is the career your RIASEC profile fits best.</p>
            <?php render_career_card($bestMatch, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct, 'best-match-card'); ?>
        </div>
        <?php endif; ?>

        <?php if ($otherMatches): ?>
            <div class="other-matches-section">
                <h2>More careers that fit you (<?= count($otherMatches) ?>)</h2>
                <p class="match-note">Ranked by how well your RIASEC profile fits each career.</p>
                <?php foreach ($otherMatches as $career): ?>
                    <?php render_career_card($career, $pdo, $studentSkillsRaw, $riasecTypeNames, $studentRiasecPct); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <a class="back" href="assessment.php">&larr; Take the assessment again</a>
<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
