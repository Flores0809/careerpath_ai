"""
CareerPath AI - O*NET Client (International occupation standards)
--------------------------------------------------------------------
Pulls official occupation data from the US Department of Labor's O*NET
Web Services (https://services.onetcenter.org) — the same RIASEC/Holland
Code standard this system's assessment is built on, just applied
internationally rather than to PH-specific job postings.

Why O*NET and not scraping LinkedIn/Indeed/Glassdoor for "international"
data: those sites actively fight scraping with bot-detection and CAPTCHAs,
and their Terms of Service prohibit it. O*NET is a free, public, official
API designed for exactly this kind of integration — no bot-detection, no
ToS risk, and it's the authoritative source RIASEC itself comes from.

Setup (free):
  1. Sign up for a developer account: https://services.onetcenter.org/developer/signup
  2. You'll get a username/password (not an API key) used for HTTP Basic Auth.
  3. Set env vars: ONET_USERNAME, ONET_PASSWORD

What this script does, per target occupation keyword:
  1. Searches O*NET by keyword: GET /mnm/search?keyword=...
  2. For each matched occupation (identified by its O*NET-SOC code), fetches:
     - Overview (description + sample daily tasks): /mnm/careers/{code}/
     - Education/Job Zone (educational pathway text): /mnm/careers/{code}/education
     - Personality (the official top RIASEC interest): /mnm/careers/{code}/personality
  3. Builds a suggested RIASEC vector: starts from the same rule-based
     keyword profile the PH crawler (crawler.py) uses, then boosts the
     OFFICIAL top interest letter O*NET reports for that specific
     occupation — so the suggestion is grounded in real O*NET data where
     available, not a pure guess. Like every other source in this project,
     it's still just a *suggestion*: a counselor reviews and can adjust it
     freely before approving on php/careers.php.
  4. Inserts into pending_careers with data_source='onet',
     country='International (O*NET)'.

Run:
    pip install -r requirements.txt
    python onet_client.py

Be a considerate API citizen: this script rate-limits itself (see
REQUEST_DELAY_SECONDS) and only targets a small, fixed set of keywords,
capped occupations per keyword, per run.
"""

import base64
import os
import time

import requests
import pymysql
import pymysql.cursors

API_BASE = "https://api-v2.onetcenter.org"
ONET_USERNAME = os.environ.get("ONET_USERNAME", "")
ONET_PASSWORD = os.environ.get("ONET_PASSWORD", "")

REQUEST_DELAY_SECONDS = 1
MAX_OCCUPATIONS_PER_KEYWORD = 3
REQUEST_TIMEOUT = 15

DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "localhost"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASSWORD", ""),
    "database": os.environ.get("DB_NAME", "careerpath_ai"),
    "cursorclass": pymysql.cursors.DictCursor,
}

# Same target occupations as the PH crawler (crawler.py), so PH and
# international results line up side by side for the same job families.
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

# O*NET's "top_interest" names map onto our RIASEC letters.
INTEREST_NAME_TO_LETTER = {
    "Realistic": "R", "Investigative": "I", "Artistic": "A",
    "Social": "S", "Enterprising": "E", "Conventional": "C",
}


def get_auth_header():
    if not ONET_USERNAME or not ONET_PASSWORD:
        raise RuntimeError(
            "Set ONET_USERNAME and ONET_PASSWORD env vars — sign up free at "
            "https://services.onetcenter.org/developer/signup"
        )
    token = base64.b64encode(f"{ONET_USERNAME}:{ONET_PASSWORD}".encode()).decode()
    return {"Authorization": f"Basic {token}", "Accept": "application/json"}


def onet_get(path, params=None):
    resp = requests.get(f"{API_BASE}{path}", headers=get_auth_header(), params=params, timeout=REQUEST_TIMEOUT)
    resp.raise_for_status()
    return resp.json()


def search_occupations(keyword):
    data = onet_get("/mnm/search", params={"keyword": keyword})
    return data.get("career", [])[:MAX_OCCUPATIONS_PER_KEYWORD]


def get_overview(code):
    return onet_get(f"/mnm/careers/{code}/")


def get_education(code):
    # Not every occupation has education/Job Zone data — degrade gracefully.
    try:
        return onet_get(f"/mnm/careers/{code}/education")
    except requests.RequestException:
        return {}


def get_personality(code):
    # Not every occupation has Interests data collected — degrade gracefully.
    try:
        return onet_get(f"/mnm/careers/{code}/personality")
    except requests.RequestException:
        return {}


def build_riasec_suggestion(keyword, personality):
    """Start from the keyword's rule-based baseline, then boost the letter
    O*NET's own data reports as this occupation's top interest — grounds
    the suggestion in real data instead of being a pure guess."""
    riasec = dict(TARGET_KEYWORDS.get(keyword, {"R": 0, "I": 0, "A": 0, "S": 0, "E": 0, "C": 0}))
    top_interest_name = (personality.get("top_interest") or {}).get("name")
    letter = INTEREST_NAME_TO_LETTER.get(top_interest_name)
    if letter:
        riasec[letter] = max(riasec.get(letter, 0), 85)
    return riasec


def get_connection():
    return pymysql.connect(**DB_CONFIG)


def save_pending_career(conn, occupation, keyword):
    """
    Fetch full details for one O*NET occupation and insert it into
    pending_careers. Mirrors crawler.py's conn.ping(reconnect=True) +
    one-retry pattern, since this script can also run long enough between
    writes for MySQL to drop an idle connection.
    """
    code = occupation["code"]
    overview = get_overview(code)
    time.sleep(REQUEST_DELAY_SECONDS)
    education = get_education(code)
    time.sleep(REQUEST_DELAY_SECONDS)
    personality = get_personality(code)
    time.sleep(REQUEST_DELAY_SECONDS)

    riasec = build_riasec_suggestion(keyword, personality)

    job_zone = education.get("job_zone") or {}
    tasks = overview.get("on_the_job") or []
    qualifications = job_zone.get("education") or ""
    if tasks:
        qualifications = (qualifications + "\n\nTypical tasks: " + "; ".join(tasks)).strip()

    job = {
        "source_title": overview.get("title") or occupation.get("title"),
        "source_url": f"https://www.mynextmove.org/profile/summary/{code}",
        "employer": None,
        "location": None,
        "education_level": job_zone.get("title"),
        "employment_type": None,
        "salary": None,
        "description": overview.get("what_they_do") or "",
        "qualifications": qualifications,
    }

    conn.ping(reconnect=True)
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
        "onet", "International (O*NET)",
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

    return affected, job


def run():
    conn = get_connection()
    total_new = 0
    total_seen = 0
    try:
        for keyword in TARGET_KEYWORDS:
            print(f"\n=== Searching O*NET for: '{keyword}' ===")
            try:
                occupations = search_occupations(keyword)
            except requests.RequestException as e:
                print(f"  [!] Could not search O*NET for '{keyword}': {e}")
                continue

            print(f"  Found {len(occupations)} occupation(s) to check.")

            for occ in occupations:
                total_seen += 1
                try:
                    affected, job = save_pending_career(conn, occ, keyword)
                except requests.RequestException as e:
                    print(f"  [!] Failed to fetch details for {occ.get('title')}: {e}")
                    continue

                if affected:
                    total_new += 1
                    print(f"  [+] Staged: {job['source_title']}")
                else:
                    print(f"  [=] Already staged/seen: {job['source_title']}")

            time.sleep(REQUEST_DELAY_SECONDS)
    finally:
        conn.close()

    print(f"\nDone. Checked {total_seen} occupations, staged {total_new} new entries into pending_careers.")
    print("Open php/careers.php to review and approve them into the live career database.")


if __name__ == "__main__":
    run()
