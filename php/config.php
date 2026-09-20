<?php
// CareerPath AI - barebone config
// Update these to match your local MySQL / Flask setup.

// Matching microservice (Python Flask + Scikit-learn)
define('MATCHING_SERVICE_URL', 'http://localhost:5000/match');

// AI enrichment endpoint (Python Flask + Gemini API, Gemini 3.5 Flash-Lite).
// Only used by careers.php. Requires GEMINI_API_KEY to be set in the
// environment where app.py runs — if it's not, this call fails gracefully
// and careers.php keeps the raw scraped fields untouched.
define('ENRICH_SERVICE_URL', 'http://localhost:5000/enrich');

// AI student-result commentary endpoint (Python Flask + Gemini API) — used
// by submit.php right after a new assessment submission is saved, to
// generate the student-facing AI commentary described in
// migration_21_student_ai_commentary.sql. Same graceful-fallback rule as
// ENRICH_SERVICE_URL above: if GEMINI_API_KEY isn't set or the call fails,
// submit.php just leaves the ai_* columns on that submission NULL.
define('STUDENT_COMMENTARY_SERVICE_URL', 'http://localhost:5000/student_commentary');

// AI chatbot fallback endpoint — used by chatbot_ask.php only when its own
// built-in FAQ keyword/fuzzy lookup (chatbot_data.php) finds no match at
// all. Same graceful-fallback rule as the others above: if GEMINI_API_KEY
// isn't set or the call fails, chatbot_ask.php just shows its existing
// canned "no match" message, so this is safe to try without risking the
// chatbot breaking.
define('CHATBOT_AI_SERVICE_URL', 'http://localhost:5000/chatbot_ask');

// AI-assisted duplicate resolution endpoint — used by careers.php's approve
// handler when a pending posting still matches an already-approved career
// at approval time, to decide whether that approval should overwrite the
// existing career in place or insert as a new, separate one. Same
// graceful-fallback rule: if unreachable, careers.php falls back to
// inserting as new, its original (safe) behavior before this existed.
define('DUPLICATE_CHECK_SERVICE_URL', 'http://localhost:5000/duplicate_check');

// Web crawler launcher endpoints — lets careers.php start crawler/*.py with
// a button press (runs in the background on the matching-service process)
// instead of someone having to open a terminal. Same Flask app as above.
define('CRAWL_SERVICE_URL', 'http://localhost:5000/crawl');
define('CRAWL_STATUS_SERVICE_URL', 'http://localhost:5000/crawl/status');

// Direct MySQL access — used by the career review page (careers.php) to read
// pending_careers and promote approved entries into the live careers table.
define('DB_HOST', 'localhost');
define('DB_NAME', 'careerpath_ai');
define('DB_USER', 'root');
define('DB_PASS', ''); // default XAMPP MySQL has no root password
