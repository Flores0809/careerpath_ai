<?php
// CareerPath AI - Built-in FAQ chatbot endpoint
// --------------------------------------------------------------------
// Primary path: no API key, no external HTTP call, no internet dependency
// — this just scores the visitor's typed message against a fixed list of
// keyword sets (chatbot_data.php) and returns the best-matching canned
// answer. Safe to leave public (no login required) since it never touches
// student/staff data — it only explains how the system itself works.
//
// Fallback path (added to try real AI on this widget): if nothing in the
// FAQ list matches at all, this asks the matching-service's /chatbot_ask
// endpoint (Gemini), grounded in the same FAQ content sent along with the
// request, for a live answer instead of just the canned "try rephrasing"
// message. If that call is unavailable or fails for any reason — no
// GEMINI_API_KEY set, matching-service not running, rate limit, etc. —
// this falls straight back to the original canned message, so the AI
// experiment can't actually break the chatbot.

header('Content-Type: application/json');
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$faq = require __DIR__ . '/chatbot_data.php';

$message = trim((string) ($_POST['message'] ?? ''));
if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty message']);
    exit;
}

// Same "hand-roll it in PHP" spirit as the RIASEC matching elsewhere in
// this app: no ML, just tokenize + count keyword overlap.
$stopwords = ['a', 'an', 'the', 'is', 'are', 'was', 'were', 'do', 'does', 'did',
    'how', 'what', 'when', 'where', 'why', 'who', 'which', 'can', 'could',
    'would', 'should', 'i', 'my', 'me', 'you', 'your', 'to', 'of', 'in', 'on',
    'for', 'it', 'this', 'that', 'am', 'be', 'and', 'or', 'about', 'please',
    'ill', "i'll", 'im', "i'm", 'so', 'if', 'will', 'get'];

function cp_tokenize(string $text, array $stopwords): array {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $words = preg_split('/\s+/', trim($text));
    $words = array_filter($words, fn($w) => $w !== '' && !in_array($w, $stopwords, true));
    // Light stemming: drop a trailing "s" so "cars"/"skills" match "car"/"skill".
    return array_map(fn($w) => (strlen($w) > 3 && substr($w, -1) === 's') ? substr($w, 0, -1) : $w, array_values($words));
}

$inputTokens = cp_tokenize($message, $stopwords);

$bestScore = 0;
$bestEntry = null;
$bestRatio = 0;

foreach ($faq as $entry) {
    $keywordTokens = array_map(fn($k) => (strlen($k) > 3 && substr($k, -1) === 's') ? substr($k, 0, -1) : $k, $entry['keywords']);
    $matched = count(array_intersect($inputTokens, $keywordTokens));
    if ($matched === 0) {
        continue;
    }
    $ratio = $matched / count($keywordTokens);
    // Prefer more matched keywords first, then higher match ratio (more specific entry).
    if ($matched > $bestScore || ($matched === $bestScore && $ratio > $bestRatio)) {
        $bestScore = $matched;
        $bestRatio = $ratio;
        $bestEntry = $entry;
    }
}

// A single overlapping keyword isn't a reliable signal by itself -- e.g. a
// question like "is there a career for someone who likes fixing cars" only
// shares the one word "career" with the unrelated "What is a dream career?"
// entry, which used to win outright and hijack the question before it ever
// reached the AI/career-catalog tier below. Two or more overlapping
// keywords is a confident, instant, zero-API-call match exactly like
// before; a single keyword is treated as a weak guess -- kept as a
// fallback, but the AI tier (grounded in the same FAQ plus the live career
// catalog) gets first shot at giving a more specific answer.
$isConfidentMatch = $bestEntry !== null && $bestScore >= 2;

// Second pass: nothing matched exactly (or only a weak 1-keyword guess),
// so tolerate small typos (a missing space, a dropped/extra letter) by
// allowing a keyword to match an input token that's only 1-2 edits away
// from it, via levenshtein(). This can only IMPROVE on a weak guess, never
// downgrade a confident one, so it's skipped entirely once confident.
if (!$isConfidentMatch) {
    $bestFuzzyScore = 0;
    foreach ($faq as $entry) {
        $keywordTokens = array_map(fn($k) => (strlen($k) > 3 && substr($k, -1) === 's') ? substr($k, 0, -1) : $k, $entry['keywords']);
        $fuzzyMatched = 0;
        foreach ($inputTokens as $tok) {
            if (strlen($tok) < 3) {
                continue; // too short for a meaningful edit-distance comparison
            }
            foreach ($keywordTokens as $kw) {
                if (strlen($kw) < 3) {
                    continue;
                }
                $maxDist = strlen($kw) >= 6 ? 2 : 1;
                if (levenshtein($tok, $kw) <= $maxDist) {
                    $fuzzyMatched++;
                    break;
                }
            }
        }
        if ($fuzzyMatched > $bestFuzzyScore) {
            $bestFuzzyScore = $fuzzyMatched;
            $bestEntry = $entry;
        }
    }
}

if ($isConfidentMatch) {
    echo json_encode([
        'matched' => true,
        'question' => $bestEntry['question'],
        'answer' => $bestEntry['answer'],
    ]);
    exit;
}

// From here, $bestEntry is either null, or a WEAK guess (a single keyword
// overlap, or a fuzzy/typo-tolerance hit) -- not confident enough to return
// outright. $canned is what gets shown if the AI tier below doesn't return
// anything better: the weak guess if there is one (still better than
// nothing), or the generic "no canned answer" message if there's truly
// nothing to go on.
$canned = $bestEntry !== null
    ? ['matched' => true, 'question' => $bestEntry['question'], 'answer' => $bestEntry['answer']]
    : [
        'matched' => false,
        'answer' => "I don't have a canned answer for that yet — I can only explain how CareerPath AI itself works (the assessment, RIASEC, recommendations, consultations, career review, etc.). Try rephrasing, or ask your counselor directly for anything account-specific.",
        'suggestions' => ['What is RIASEC?', 'How do I take the assessment?', 'How are career recommendations generated?', 'How do I request a consultation?'],
    ];

// Also ground it in the live, counselor-approved career catalog (title,
// category, scope, description, key subjects) — not just the FAQ about how
// the system itself works — so it can answer things like "is there a
// career for X" or "what does a Civil Engineer do here" from real data
// instead of refusing every career-specific question as out of scope.
// Public info already visible to any logged-in student on
// careers_manage.php/career_profile.php, so safe to expose here too.
// Wrapped in try/catch: if this read fails for any reason, the chatbot
// should still work with just the FAQ, not break entirely.
$careerCatalog = [];
try {
    $stmt = get_db()->query(
        "SELECT career_title, career_category, career_scope, description, key_subjects
         FROM careers WHERE status = 'active' ORDER BY career_title"
    );
    $careerCatalog = $stmt->fetchAll();
} catch (Exception $e) {
    $careerCatalog = [];
}

// Second-tier fallback: ask Gemini (via the matching-service), grounded in
// this same $faq list and the career catalog above, before settling for
// $canned. Any failure here — key not set, matching-service down, timeout,
// rate limit — just falls straight through to $canned, so trying this out
// can't actually break the chatbot for anyone.
$ch = curl_init(CHATBOT_AI_SERVICE_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['question' => $message, 'faq' => $faq, 'career_catalog' => $careerCatalog]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 20, // a visitor is actively waiting on this, shorter budget than submit.php's 35s
]);
$aiResponse = curl_exec($ch);
$aiHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$aiCurlError = curl_error($ch);
curl_close($ch);

$aiResult = $aiCurlError ? null : json_decode($aiResponse, true);

if (!$aiCurlError && $aiHttpCode === 200 && !empty($aiResult['ai_answered']) && !empty($aiResult['in_scope']) && !empty($aiResult['answer'])) {
    echo json_encode([
        'matched' => false,
        'ai_answered' => true,
        'answer' => $aiResult['answer'],
    ]);
    exit;
}

echo json_encode($canned);
