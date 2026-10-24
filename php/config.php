<?php
// Update these to match your local MySQL / Flask setup.
$cp_env = fn(string $name, string $default) => (getenv($name) !== false && getenv($name) !== '') ? getenv($name) : $default;

$cp_matching_base = rtrim($cp_env('MATCHING_SERVICE_BASE_URL', 'http://localhost:5000'), '/');

define('MATCHING_SERVICE_URL', $cp_matching_base . '/match');

// AI enrichment endpoint (Python Flask + Gemini API, Gemini 3.5 Flash-Lite).
// Only used by careers.php. Requires GEMINI_API_KEY to be set in the
// environment where app.py runs — if it's not, this call fails gracefully
// and careers.php keeps the raw scraped fields untouched.
define('ENRICH_SERVICE_URL', $cp_matching_base . '/enrich');

define('STUDENT_COMMENTARY_SERVICE_URL', $cp_matching_base . '/student_commentary');

define('CHATBOT_AI_SERVICE_URL', $cp_matching_base . '/chatbot_ask');

define('DUPLICATE_CHECK_SERVICE_URL', $cp_matching_base . '/duplicate_check');

// Web crawler launcher endpoints — lets careers.php start crawler/*.py with
// a button press (runs in the background on the matching-service process)
// instead of someone having to open a terminal. Same Flask app as above.
define('CRAWL_SERVICE_URL', $cp_matching_base . '/crawl');
define('CRAWL_STATUS_SERVICE_URL', $cp_matching_base . '/crawl/status');

define('DB_HOST', $cp_env('MYSQLHOST', 'localhost'));
define('DB_PORT', $cp_env('MYSQLPORT', '3306'));
define('DB_NAME', $cp_env('MYSQLDATABASE', 'careerpath_ai'));
define('DB_USER', $cp_env('MYSQLUSER', 'root'));
define('DB_PASS', $cp_env('MYSQLPASSWORD', '')); // default XAMPP MySQL has no root password
