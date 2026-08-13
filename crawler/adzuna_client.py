"""
CareerPath AI - Adzuna Client (International live job postings)
--------------------------------------------------------------------
Pulls real, currently-posted job vacancies from multiple countries via
Adzuna's free public Jobs API (https://developer.adzuna.com) — the
international counterpart to crawler.py's PhilJobNet scraping, giving real
live postings rather than just occupational standards (that's what
onet_client.py covers).

Why Adzuna and not Indeed/LinkedIn/Glassdoor: those sites actively fight
scraping with bot-detection and CAPTCHAs, and their Terms of Service
prohibit it. Adzuna publishes an official, free, public API specifically
for this kind of integration — no bot-detection, no ToS risk.

Setup (free):
  1. Register for a free account: https://developer.adzuna.com/signup
  2. You'll get an app_id and app_key.
  3. Set env vars: ADZUNA_APP_ID, ADZUNA_APP_KEY

What this script does, per (country, keyword) pair:
  1. Queries Adzuna's search endpoint:
         https://api.adzuna.com/v1/api/jobs/{country}/search/1
     with what=<keyword>, results_per_page=<small N>.
  2. Extracts title, company, location, description snippet, salary range,
     contract type, and the posting's redirect_url.
  3. Applies the same rule-based RIASEC suggestion per keyword used by the
     PH crawler — a counselor reviews and adjusts it on approval, same as
     every other source.
  4. Inserts into pending_careers with data_source='adzuna' and country
     set to the posting's actual country (e.g. "United States").

Run:
    pip install -r requirements.txt
    python adzuna_client.py

Be a considerate API citizen: this script only targets a small, fixed set
of countries/keywords, capped results per search, per run.
"""

import os
import time

import requests
import pymysql
import pymysql.cursors

API_BASE = "https://api.adzuna.com/v1/api/jobs"
ADZUNA_APP_ID = os.environ.get("ADZUNA_APP_ID", "")
ADZUNA_APP_KEY = os.environ.get("ADZUNA_APP_KEY", "")

REQUEST_DELAY_SECONDS = 1.5
MAX_RESULTS_PER_SEARCH = 2
REQUEST_TIMEOUT = 15

DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "localhost"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASSWORD", ""),
    "database": os.environ.get("DB_NAME", "careerpath_ai"),
    "cursorclass": pymysql.cursors.DictCursor,
}

# Adzuna country codes -> (display name, currency symbol for salary formatting).
# Singapore is included deliberately: a very common OFW/overseas destination
# for Filipino workers, directly relevant to JHS/SHS career guidance.
TARGET_COUNTRIES = {
    "us": ("United States", "$"),
    "gb": ("United Kingdom", "£"),
    "au": ("Australia", "A$"),
    "ca": ("Canada", "C$"),
    "sg": ("Singapore", "S$"),
}

# Same target occupations as crawler.py/onet_client.py, so results line up
# across sources for the same job families.
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


def format_salary(result, currency_symbol):
    lo = result.get("salary_min") or 0
    hi = result.get("salary_max") or 0
    if not lo and not hi:
        return None
    if lo and hi and lo != hi:
        return f"{currency_symbol}{lo:,.0f} - {currency_symbol}{hi:,.0f}"
    return f"{currency_symbol}{(lo or hi):,.0f}"


def format_employment_type(result):
    parts = [p for p in (result.get("contract_time"), result.get("contract_type")) if p]
    return ", ".join(p.replace("_", " ").title() for p in parts) or None


def search_jobs(country_code, keyword):
    if not ADZUNA_APP_ID or not ADZUNA_APP_KEY:
        raise RuntimeError(
            "Set ADZUNA_APP_ID and ADZUNA_APP_KEY env vars — register free at "
            "https://developer.adzuna.com/signup"
        )
    url = f"{API_BASE}/{country_code}/search/1"
    params = {
        "app_id": ADZUNA_APP_ID,
        "app_key": ADZUNA_APP_KEY,
        "what": keyword,
        "results_per_page": MAX_RESULTS_PER_SEARCH,
        "content-type": "application/json",
    }
    resp = requests.get(url, params=params, timeout=REQUEST_TIMEOUT)
    resp.raise_for_status()
    return resp.json().get("results", [])


def get_connection():
    return pymysql.connect(**DB_CONFIG)


def save_pending_career(conn, result, keyword, country_name, currency_symbol):
    conn.ping(reconnect=True)

    riasec = TARGET_KEYWORDS.get(keyword, {"R": 0, "I": 0, "A": 0, "S": 0, "E": 0, "C": 0})
    location = (result.get("location") or {}).get("display_name")
    company = (result.get("company") or {}).get("display_name")

    job = {
        "source_title": result.get("title"),
        "source_url": result.get("redirect_url"),
        "employer": company,
        "location": location,
        "education_level": None,  # Adzuna doesn't reliably expose this
        "employment_type": format_employment_type(result),
        "salary": format_salary(result, currency_symbol),
        "description": (result.get("description") or "").strip(),
        "qualifications": "",  # Adzuna's description is already a short snippet
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
        job["source_title"], job["source_url"], job["employer"], job["location"],
        job["education_level"], job["employment_type"], job["salary"],
        job["description"], job["qualifications"], keyword,
        "adzuna", country_name,
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
        for country_code, (country_name, currency_symbol) in TARGET_COUNTRIES.items():
            for keyword in TARGET_KEYWORDS:
                print(f"\n=== Searching Adzuna [{country_name}] for: '{keyword}' ===")
                try:
                    results = search_jobs(country_code, keyword)
                except requests.RequestException as e:
                    print(f"  [!] Could not search '{keyword}' in {country_name}: {e}")
                    continue

                print(f"  Found {len(results)} posting(s) to check.")

                for result in results:
                    total_seen += 1
                    if not result.get("redirect_url"):
                        continue  # nothing to dedupe/link against
                    affected, job = save_pending_career(conn, result, keyword, country_name, currency_symbol)
                    if affected:
                        total_new += 1
                        print(f"  [+] Staged: {job['source_title']} ({job['employer']})")
                    else:
                        print(f"  [=] Already staged/seen: {job['source_title']}")

                time.sleep(REQUEST_DELAY_SECONDS)
    finally:
        conn.close()

    print(f"\nDone. Checked {total_seen} postings, staged {total_new} new entries into pending_careers.")
    print("Open php/careers.php to review and approve them into the live career database.")


if __name__ == "__main__":
    run()
