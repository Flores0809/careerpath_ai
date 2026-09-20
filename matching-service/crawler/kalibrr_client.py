"""
CareerPath AI - Kalibrr Client (Philippines)
------------------------------------------------------------------------
Scrapes publicly available job postings from Kalibrr
(https://www.kalibrr.com), a Philippines-based job board — added as a
second local source alongside crawler.py's PhilJobNet scraper, since
PhilJobNet alone (a government portal) doesn't cover every private-sector
posting a JHS/SHS student might be curious about.

robots.txt (https://www.kalibrr.com/robots.txt) only disallows /root and
/candidate/profile — job board search/listing pages are unrestricted, so
this is a clean scrape, same standard this project already holds itself to
(see remoteok_client.py's docstring on why Indeed/LinkedIn/Glassdoor were
*not* added: their robots.txt/ToS explicitly disallow it).

IMPORTANT — a real limitation, not a bug: Kalibrr's individual job-detail
pages (e.g. /c/{company}/jobs/{id}/{slug}) render their full description
and qualifications client-side via JavaScript after the page loads — a
plain HTTP request (what this script and Python's `requests` library see)
gets back a mostly-empty shell for those fields. The *listing* page itself
IS server-rendered with full job cards (verified by fetching it with no
JavaScript execution), so this script only reads listing pages — title,
employer, location, salary (if disclosed), employment type — and does NOT
attempt to visit each job's detail page. The resulting pending_careers row
therefore has a thin/empty description going in, same as any entry with no
usable source text; the existing auto-enrichment step (enrichment_helper.py)
already handles that case today (it works from the title alone if
description/qualifications are blank) and fills in a full description,
daily tasks, and educational pathway via Gemini for a counselor to review.

What this script does:
  1. For each target occupation keyword, fetches Kalibrr's public listing:
         https://www.kalibrr.com/job-board/te/{keyword}/1
  2. Parses job cards using the page's own schema.org structured data
     (itemtype="http://schema.org/ItemList" wraps the results grid,
     itemprop="name" marks each job title) rather than CSS classes, which
     Kalibrr's build tooling rewrites on every deploy — same
     markup-resilience reasoning as crawler.py's heading-anchored PhilJobNet
     parser.
  3. Applies the same rule-based RIASEC starting-point guess per keyword
     used by the other crawlers.
  4. Inserts new postings into `pending_careers` (duplicates, matched by
     source_url, are skipped), tagged data_source='kalibrr'.

Run:
    pip install -r requirements.txt
    python kalibrr_client.py
"""

import os
import re
import time
from urllib.parse import quote, urljoin

import requests
from bs4 import BeautifulSoup
import pymysql
import pymysql.cursors

from enrichment_helper import auto_enrich_pending

BASE_URL = "https://www.kalibrr.com"
SEARCH_URL_TEMPLATE = BASE_URL + "/job-board/te/{keyword}/1"

HEADERS = {
    "User-Agent": "CareerPathAI-ResearchCrawler/0.1 (Colegio de San Juan de Letran Calamba capstone project; contact via institution)"
}

CRAWL_DELAY_SECONDS = 2       # delay between HTTP requests (one per keyword — no per-job detail fetch, see docstring above)
MAX_JOBS_PER_KEYWORD = 5
REQUEST_TIMEOUT = 15

DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "localhost"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASSWORD", ""),
    "database": os.environ.get("DB_NAME", "careerpath_ai"),
    "cursorclass": pymysql.cursors.DictCursor,
}

# Same target occupations as the other crawlers, for the same reason
# (small, fixed, JHS/SHS-guidance-relevant list — see crawler.py).
TARGET_KEYWORDS = {
    "nurse":            {"R": 40, "I": 55, "A": 15, "S": 90, "E": 25, "C": 40},
    "teacher":          {"R": 20, "I": 45, "A": 40, "S": 90, "E": 35, "C": 45},
    "accountant":       {"R": 15, "I": 40, "A": 10, "S": 25, "E": 35, "C": 90},
    "software developer": {"R": 30, "I": 85, "A": 40, "S": 20, "E": 30, "C": 55},
    "electrician":      {"R": 90, "I": 40, "A": 10, "S": 20, "E": 25, "C": 40},
    "welder":           {"R": 90, "I": 35, "A": 15, "S": 15, "E": 20, "C": 35},
    "civil engineer":   {"R": 75, "I": 80, "A": 20, "S": 25, "E": 35, "C": 50},
    "graphic designer": {"R": 15, "I": 40, "A": 80, "S": 30, "E": 40, "C": 35},
    "sales":            {"R": 20, "I": 25, "A": 30, "S": 45, "E": 90, "C": 45},
    "chef":             {"R": 65, "I": 30, "A": 55, "S": 35, "E": 40, "C": 30},
}

TITLE_LINK_RE = re.compile(r"^/c/[^/]+/jobs/\d+/")
COMPANY_LINK_RE = re.compile(r"^/c/[^/]+/jobs\?")
EMPLOYMENT_TYPE_HINTS = ["Full time", "Part time", "Contract", "Internship", "Temporary", "Freelance"]


def fetch_html(url):
    resp = requests.get(url, headers=HEADERS, timeout=REQUEST_TIMEOUT)
    resp.raise_for_status()
    return resp.text


def parse_listing(html):
    """
    Pull job cards out of a Kalibrr listing page. Anchored on schema.org
    markup (ItemList container + itemprop="name" job titles) rather than
    Kalibrr's build-hashed CSS classes (e.g. "css-1gzvnis"), which change on
    every deploy and would silently break a class-name-based scraper.
    """
    soup = BeautifulSoup(html, "html.parser")
    jobs = []

    container = soup.find(attrs={"itemtype": "http://schema.org/ItemList"})
    if not container:
        return jobs  # page structure changed enough that our anchor is gone — nothing to safely parse

    cards = container.find_all("div", recursive=False)
    for card in cards:
        title_link = card.find("a", attrs={"itemprop": "name"}, href=TITLE_LINK_RE)
        if not title_link:
            continue
        title = title_link.get_text(strip=True)
        job_url = urljoin(BASE_URL, title_link["href"])

        company_link = card.find("a", href=COMPANY_LINK_RE)
        employer = company_link.get_text(strip=True) if company_link else None

        card_text = card.get_text(" ", strip=True)

        location = None
        loc_match = re.search(r"([A-Za-zÀ-ÿ.'\- ]+,\s*Philippines)", card_text)
        if loc_match:
            location = loc_match.group(1).strip()

        salary = None
        if "Salary Undisclosed" in card_text:
            salary = "Salary Undisclosed"
        else:
            salary_match = re.search(r"₱[\d,]+(?:\.\d+)?(?:\s*-\s*₱?[\d,]+(?:\.\d+)?)?\s*/\s*month", card_text)
            if salary_match:
                salary = salary_match.group(0).strip()

        employment_type = next((h for h in EMPLOYMENT_TYPE_HINTS if h in card_text), None)

        jobs.append({
            "source_title": title,
            "source_url": job_url,
            "employer": employer,
            "location": location,
            "education_level": None,
            "employment_type": employment_type,
            "salary": salary,
            # Full description/qualifications aren't available without running
            # the page's JavaScript (see module docstring) — left blank so
            # auto-enrichment fills them in from the title via Gemini.
            "description": "",
            "qualifications": "",
        })

    return jobs


def get_connection():
    return pymysql.connect(**DB_CONFIG)


def save_pending_career(conn, job, keyword):
    conn.ping(reconnect=True)

    riasec = TARGET_KEYWORDS.get(keyword, {"R": 0, "I": 0, "A": 0, "S": 0, "E": 0, "C": 0})
    sql = """
        INSERT IGNORE INTO pending_careers
            (source_title, source_url, employer, location, education_level,
             employment_type, salary, description, qualifications, search_keyword,
             data_source, country,
             suggested_r_score, suggested_i_score, suggested_a_score,
             suggested_s_score, suggested_e_score, suggested_c_score)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
    """
    params = (
        job["source_title"], job["source_url"], job["employer"], job["location"],
        job["education_level"], job["employment_type"], job["salary"],
        job["description"], job["qualifications"], keyword,
        "kalibrr", "Philippines",
        riasec["R"], riasec["I"], riasec["A"], riasec["S"], riasec["E"], riasec["C"],
    )

    try:
        with conn.cursor() as cur:
            affected = cur.execute(sql, params)
            new_pending_id = cur.lastrowid if affected else None
        conn.commit()
    except pymysql.err.OperationalError as e:
        print(f"  [!] DB connection hiccup ({e}); reconnecting and retrying once...")
        conn.ping(reconnect=True)
        with conn.cursor() as cur:
            affected = cur.execute(sql, params)
            new_pending_id = cur.lastrowid if affected else None
        conn.commit()

    return new_pending_id


def run():
    conn = get_connection()
    total_new = 0
    total_seen = 0
    try:
        for keyword in TARGET_KEYWORDS:
            print(f"\n=== Searching Kalibrr for: '{keyword}' ===")
            search_url = SEARCH_URL_TEMPLATE.format(keyword=quote(keyword))
            try:
                listing_html = fetch_html(search_url)
            except requests.RequestException as e:
                print(f"  [!] Could not fetch listing for '{keyword}': {e}")
                continue

            jobs = parse_listing(listing_html)[:MAX_JOBS_PER_KEYWORD]
            print(f"  Found {len(jobs)} job listing(s) to check.")

            for job in jobs:
                total_seen += 1
                new_pending_id = save_pending_career(conn, job, keyword)
                if new_pending_id:
                    total_new += 1
                    print(f"  [+] Staged: {job['source_title']} ({job['employer']})")
                    auto_enrich_pending(conn, new_pending_id, job["source_title"], job["description"], job["qualifications"])
                else:
                    print(f"  [=] Already staged/seen: {job['source_title']}")

            time.sleep(CRAWL_DELAY_SECONDS)
    finally:
        conn.close()

    print(f"\nDone. Checked {total_seen} postings, staged {total_new} new entries into pending_careers.")
    print("Open php/careers.php to review and approve them into the live career database.")


if __name__ == "__main__":
    run()
