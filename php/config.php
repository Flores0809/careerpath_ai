<?php
// CareerPath AI - barebone config
// Update these to match your local MySQL / Flask setup.
//
// Everything below reads from an environment variable first, falling back
// to the original hardcoded XAMPP/localhost value if that variable isn't
// set. On a normal local XAMPP setup no env vars exist, so nothing changes.
// On a host like Railway, each service injects its own env vars (the
// matching-service's private network URL, Railway's MySQL connection
// details, etc.) -- setting those is the ONLY change needed to deploy this
// same code, no separate "production config" file to keep in sync.
$cp_env = fn(string $name, string $default) => (getenv($name) !== false && getenv($name) !== '') ? getenv($name) : $default;

// Matching microservice (Python Flask + Scikit-learn). On Railway this
// should be that service's private network URL, e.g.
// http://matching-service.railway.internal:PORT (see README/deployment
// notes) -- keeping it on Railway's private network means it's never
// reachable from the public internet, which matters since none of its
// endpoints have their own authentication.
$cp_matching_base = rtrim($cp_env('MATCHING_SERVICE_BASE_URL', 'http://localhost:5000'), '/');

define('MATCHING_SERVICE_URL', $cp_matching_base . '/match');

// AI enrichment endpoint (Python Flask + Gemini API, Gemini 3.5 Flash-Lite).
// Only used by careers.php. Requires GEMINI_API_KEY to be set in the
// environment where app.py runs — if it's not, this call fails gracefully
// and careers.php keeps the raw scraped fields untouched.
define('ENRICH_SERVICE_URL', $cp_matching_base . '/enrich');

// AI student-result commentary endpoint (Python Flask + Gemini API) — used
// by submit.php right after a new assessment submission is saved, to
// generate the student-facing AI commentary described in
// migration_21_student_ai_commentary.sql. Same graceful-fallback rule as
// ENRICH_SERVICE_URL above: if GEMINI_API_KEY isn't set or the call fails,
// submit.php just leaves the ai_* columns on that submission NULL.
define('STUDENT_COMMENTARY_SERVICE_URL', $cp_matching_base . '/student_commentary');

// AI chatbot fallback endpoint — used by chatbot_ask.php only when its own
// built-in FAQ keyword/fuzzy lookup (chatbot_data.php) finds no match at
// all. Same graceful-fallback rule as the others above: if GEMINI_API_KEY
// isn't set or the call fails, chatbot_ask.php just shows its existing
// canned "no match" message, so this is safe to try without risking the
// chatbot breaking.
define('CHATBOT_AI_SERVICE_URL', $cp_matching_base . '/chatbot_ask');

// AI-assisted duplicate resolution endpoint — used by careers.php's approve
// handler when a pending posting still matches an already-approved career
// at approval time, to decide whether that approval should overwrite the
// existing career in place or insert as a new, separate one. Same
// graceful-fallback rule: if unreachable, careers.php falls back to
// inserting as new, its original (safe) behavior before this existed.
define('DUPLICATE_CHECK_SERVICE_URL', $cp_matching_base . '/duplicate_check');

// Web crawler launcher endpoints — lets careers.php start crawler/*.py with
// a button press (runs in the background on the matching-service process)
// instead of someone having to open a terminal. Same Flask app as above.
define('CRAWL_SERVICE_URL', $cp_matching_base . '/crawl');
define('CRAWL_STATUS_SERVICE_URL', $cp_matching_base . '/crawl/status');

// Direct MySQL access — used by the career review page (careers.php) to read
// pending_careers and promote approved entries into the live careers table.
// Railway's MySQL plugin injects MYSQLHOST/MYSQLDATABASE/MYSQLUSER/
// MYSQLPASSWORD automatically once you add its variables to this service --
// paste them in Railway's dashboard, never here in the file.
define('DB_HOST', $cp_env('MYSQLHOST', 'localhost'));
define('DB_PORT', $cp_env('MYSQLPORT', '3306'));
define('DB_NAME', $cp_env('MYSQLDATABASE', 'careerpath_ai'));
define('DB_USER', $cp_env('MYSQLUSER', 'root'));
define('DB_PASS', $cp_env('MYSQLPASSWORD', '')); // default XAMPP MySQL has no root password
