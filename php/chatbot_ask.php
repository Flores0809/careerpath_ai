<?php
// CareerPath AI - Built-in FAQ chatbot endpoint
// --------------------------------------------------------------------

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
    'ill', "i'll", 'im', "i'm", 'so', 'if', 'will', 'get',
    // Tagalog/Taglish function words -- filtered out the same way as their
    // English equivalents above, so a Taglish sentence like "paano po ba
    // gumawa ng account" still matches on its actual content word
    // ("gumawa"/"account") instead of being diluted by filler.
    'ano', 'paano', 'bakit', 'saan', 'kailan', 'sino', 'alin', 'ba', 'po',
    'opo', 'ng', 'nang', 'mga', 'ang', 'sa', 'na', 'ay', 'at', 'o', 'kung',
    'ko', 'mo', 'niya', 'namin', 'natin', 'nila', 'akin', 'iyo', 'kanya',
    'ito', 'iyan', 'iyon', 'yun', 'yan', 'dito', 'diyan', 'doon', 'may',
    'meron', 'mayroon', 'wala', 'din', 'rin', 'lang', 'lamang', 'pa',
    'para', 'dahil', 'kasi', 'pero', 'gusto', 'pwede', 'puede'];

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

$isConfidentMatch = $bestEntry !== null
    && ($bestScore >= 2 || (!empty($bestEntry['instant']) && count($inputTokens) <= 3));

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

$canned = $bestEntry !== null
    ? ['matched' => true, 'question' => $bestEntry['question'], 'answer' => $bestEntry['answer']]
    : [
        'matched' => false,
        'answer' => "Hmm, not sure about that one. I can help with how CareerPath AI works — the assessment, RIASEC, recommendations, consultations, and more. Try rephrasing, or ask your counselor directly for anything account-specific.",
        'suggestions' => ['What is RIASEC?', 'How do I take the assessment?', 'How are career recommendations generated?', 'How do I request a consultation?'],
    ];

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

$ch = curl_init(CHATBOT_AI_SERVICE_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['question' => $message, 'faq' => $faq, 'career_catalog' => $careerCatalog]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 20,
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
