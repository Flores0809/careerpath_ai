<?php
// CareerPath AI - Crawler status proxy (JSON endpoint for careers.php's JS)

require __DIR__ . '/auth.php';
require_role(['administrator', 'counselor']);

header('Content-Type: application/json');

$source = $_GET['source'] ?? '';
$allowed = ['philjobnet', 'kalibrr', 'onet', 'adzuna', 'remoteok'];
if (!in_array($source, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown source']);
    exit;
}

$ch = curl_init(CRAWL_STATUS_SERVICE_URL . '?source=' . urlencode($source));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(503);
    echo json_encode(['error' => "Matching service unreachable: $curlError", 'state' => 'unreachable']);
    exit;
}

http_response_code($httpCode ?: 200);
echo $response;
