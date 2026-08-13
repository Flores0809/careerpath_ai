"""
CareerPath AI - RemoteOK Client (International remote job postings)
--------------------------------------------------------------------
Pulls real, currently-posted remote job vacancies from RemoteOK's public
API (https://remoteok.com/api) — no authentication needed, no
bot-detection, and RemoteOK explicitly publishes this feed for third-party
use (their only ask, honored below, is attribution back to RemoteOK).

Why RemoteOK and not Indeed/LinkedIn/Glassdoor: those sites actively fight
scraping with bot-detection and CAPTCHAs, and their Terms of Service
prohibit it. RemoteOK's API is public and scraping-tolerant by design.

What this script does:
  1. Fetches the full public feed (one request, no pagination needed).
  2. Filters it down to postings relevant to this project's target
     occupation keywords (RemoteOK skews tech/design/marketing-heavy, so
     not every keyword will find matches — that's expected).
  3. Strips HTML out of the description field and applies the same
     rule-based RIASEC suggestion per keyword used by the other crawlers.
  4. Inserts into pending_careers with data_source='remoteok',
     country='Remote / International'.

Run:
    pip install -r requirements.txt
    python remoteok_client.py

Per RemoteOK's API terms (shown in the feed's own first entry): this
script credits RemoteOK as the source in the data it stores (source_url
links back to the original posting).
"""

import os
import time

import requests
from bs4 import BeautifulSoup
import pymysql
import pymysql.cursors

API_URL = "https://remoteok.com/api"

HEADERS = {
    "User-Agent": "CareerPathAI-ResearchCrawler/0.1 (Colegio de San Juan de Letran Calamba capstone project; contact via institution)"
}

REQUEST_TIMEOUT = 15
MAX_RESULTS_PER_KEYWORD = 3
DESCRIPTION_MAX_CHARS = 1200

DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "localhost"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASSWORD", ""),
    "database": os.environ.get("DB_NAME", "careerpath_ai"),
    "cursorclass": pymysql.cursors.DictCursor,
}

# Same target occupations as the other crawlers, though RemoteOK's actual
# coverage skews toward tech/design/marketing/sales — some keywords here
# (e.g. "welder", "electrician") may simply find nothing, which is fine.
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


def fetch_feed():
    resp = requests.get(API_URL, headers=HEADERS, timeout=REQUEST_TIMEOUT)
    resp.raise_for_status()
    data = resp.json()
    # First element is RemoteOK's own legal/attribution notice, not a job.
    return [item for item in data if "position" in item]


def match_keyword(job):
    """Returns the first target keyword found in the job's title or tags, or None."""
    haystack = (job.get("position") or "").lower() + " " + " ".join(job.get("tags") or []).lower()
    for keyword in TARGET_KEYWORDS:
        if keyword in haystack:
            return keyword
    return None


def clean_description(html):
    if not html:
        return ""
    text = BeautifulSoup(html, "html.parser").get_text(" ", strip=True)
    return text[:DESCRIPTION_MAX_CHARS]


def format_salary(job):
    lo = job.get("salary_min") or 0
    hi = job.get("salary_max") or 0
    if not lo and not hi:
        return None
    if lo and hi and lo != hi:
        return f"${lo:,.0f} - ${hi:,.0f}"
    return f"${(lo or hi):,.0f}"


def format_employment_type(job):
    tags = [t for t in (job.get("tags") or []) if t.lower() in ("full time", "part time", "contract")]
    label = tags[0].title() if tags else "Full Time"
    return f"Remote, {label}"


def get_connection():
    return pymysql.connect(**DB_CONFIG)


def save_pending_career(conn, job, keyword):
    conn.ping(reconnect=True)

    riasec = TARGET_KEYWORDS.get(keyword, {"R": 0, "I": 0, "A": 0, "S": 0, "E": 0, "C": 0})
    source_url = job.get("url") or job.get("apply_url")

    row = {
        "source_title": job.get("position"),
        "source_url": source_url,
        "employer": job.get("company"),
        "location": (job.get("location") or "").strip() or "Worldwide / Remote",
        "education_level": None,
        "employment_type": format_employment_type(job),
        "salary": format_salary(job),
        "description": clean_description(job.get("description")),
        "qualifications": "",
    }

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
        row["source_title"], row["source_url"], row["employer"], row["location"],
        row["education_level"], row["employment_type"], row["salary"],
        row["description"], row["qualifications"], keyword,
        "remoteok", "Remote / International",
        riasec["R"], riasec["I"], riasec["A"], riasec["S"], riasec["E"], riasec["C"],
    )

    try:
        with conn.cursor() as cur:
            affected = cur.execute(sql, params)
        conn.commit()
    except pymysql.err.OperationalError as e:
        print(f"  [!] DB connection hiccup ({e}); reconnecting and retrying once...")
        conn.ping(reconnect=True)
        with conn.cursor() as cur:
            affected = cur.execute(sql, params)
        conn.commit()

    return affected, row


def run():
    print("=== Fetching RemoteOK public feed ===")
    try:
        jobs = fetch_feed()
    except requests.RequestException as e:
        print(f"[!] Could not fetch RemoteOK feed: {e}")
        return
    print(f"Fetched {len(jobs)} total postings from RemoteOK.")

    per_keyword_counts = {kw: 0 for kw in TARGET_KEYWORDS}
    conn = get_connection()
    total_new = 0
    total_seen = 0
    try:
        for job in jobs:
            keyword = match_keyword(job)
            if not keyword or per_keyword_counts[keyword] >= MAX_RESULTS_PER_KEYWORD:
                continue
            if not job.get("url") and not job.get("apply_url"):
                continue  # nothing to dedupe/link against

            per_keyword_counts[keyword] += 1
            total_seen += 1
            affected, row = save_pending_career(conn, job, keyword)
            if affected:
                total_new += 1
                print(f"  [+] Staged ({keyword}): {row['source_title']} ({row['employer']})")
            else:
                print(f"  [=] Already staged/seen: {row['source_title']}")
    finally:
        conn.close()

    print(f"\nDone. Checked {total_seen} matching postings, staged {total_new} new entries into pending_careers.")
    print("Open php/careers.php to review and approve them into the live career database.")


if __name__ == "__main__":
    run()
