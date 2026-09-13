<?php
// CareerPath AI - barebone config
// Update these to match your local MySQL / Flask setup.

// Matching microservice (Python Flask + Scikit-learn)
define('MATCHING_SERVICE_URL', 'http://localhost:5000/match');

// AI enrichment endpoint (Python Flask + Gemini API, Gemini 2.5 Flash-Lite).
// Only used by careers.php. Requires GEMINI_API_KEY to be set in the
// environment where app.py runs — if it's not, this call fails gracefully
// and careers.php keeps the raw scraped fields untouched.
define('ENRICH_SERVICE_URL', 'http://localhost:5000/enrich');

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
