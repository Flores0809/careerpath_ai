<?php
// CareerPath AI - Built-in FAQ chatbot endpoint
// --------------------------------------------------------------------
// No API key, no external HTTP call, no internet dependency: this just
// scores the visitor's typed message against a fixed list of keyword
// sets (chatbot_data.php) and returns the best-matching canned answer.
// Safe to leave public (no login required) since it never touches
// student/staff data — it only explains how the system itself works.

header('Content-Type: application/json');

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

if ($bestEntry === null) {
    echo json_encode([
        'matched' => false,
        'answer' => "I don't have a canned answer for that yet — I can only explain how CareerPath AI itself works (the assessment, RIASEC, recommendations, consultations, career review, etc.). Try rephrasing, or ask your counselor directly for anything account-specific.",
        'suggestions' => ['What is RIASEC?', 'How do I take the assessment?', 'How are career recommendations generated?', 'How do I request a consultation?'],
    ]);
    exit;
}

echo json_encode([
    'matched' => true,
    'question' => $bestEntry['question'],
    'answer' => $bestEntry['answer'],
]);
