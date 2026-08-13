"""
CareerPath AI - Web Crawler (BeautifulSoup) — Philippines (PhilJobNet)
------------------------------------------------------------------------
Scrapes publicly available job vacancy postings from PhilJobNet
(https://philjobnet.gov.ph), DOLE's Bureau of Local Employment portal that
powers the PESO Employment Information System referenced in the paper.

This is the PH-specific source. For international coverage (added after
panel feedback that the system needed non-PH requirements too), see the
sibling scripts in this folder: onet_client.py (O*NET official occupation
standards), adzuna_client.py (live postings across several countries), and
remoteok_client.py (live remote postings). All four write to the same
pending_careers staging table, tagged by data_source/country so reviewers
can tell them apart on php/careers.php.

IMPORTANT — how this fits the paper's design:
  Chapter III / Data section states that crawled entries must be staged in a
  pending-review table and require administrator approval before entering
  the main career database. This script ONLY writes to `pending_careers`.
  Nothing it scrapes becomes visible to students until a counselor or
  administrator approves it via php/careers.php.

What this script does:
  1. For each target occupation keyword (e.g. "nurse", "teacher"), queries
     PhilJobNet's public keyword-search listing:
         https://philjobnet.gov.ph/job-vacancies/0/{keyword}/0
  2. Extracts links to individual job detail pages from that listing.
  3. Visits each detail page and parses title, employer, location,
     education requirement, employment type, salary, job description, and
     qualifications — using heading-text-anchored parsing (robust to markup
     changes since it doesn't depend on guessing exact CSS class names).
  4. Applies a simple rule-based RIASEC suggestion per search keyword, as a
     starting point for the admin to fine-tune on approval (no AI call
     involved — this is a plain lookup table).
  5. Inserts new postings into `pending_careers` (duplicates, matched by
     source_url, are skipped).

Run:
    pip install -r requirements.txt
    python crawler.py

Be a polite crawler: this script rate-limits itself (see CRAWL_DELAY_SECONDS)
and only targets a small, fixed set of keywords per run.
"""

import os
import re
import time
import sys
from urllib.parse import quote, urljoin

import requests
from bs4 import BeautifulSoup
import pymysql
import pymysql.cursors

BASE_URL = "https://philjobnet.gov.ph"
SEARCH_URL_TEMPLATE = BASE_URL + "/job-vacancies/0/{keyword}/0"

HEADERS = {
    # Identify the bot honestly and rate-limit — good scraping citizenship.
    "User-Agent": "CareerPathAI-ResearchCrawler/0.1 (Colegio de San Juan de Letran Calamba capstone project; contact via institution)"
}

CRAWL_DELAY_SECONDS = 2       # delay between HTTP requests
MAX_JOBS_PER_KEYWORD = 5      # how many detail pages to fetch per keyword per run
REQUEST_TIMEOUT = 15

DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "localhost"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASSWORD", ""),
    "database": os.environ.get("DB_NAME", "careerpath_ai"),
    "cursorclass": pymysql.cursors.DictCursor,
}

# Target occupation keywords, mapped to a rough RIASEC starting-point guess
# (0-100 scale, same convention as the `careers` table). These are only
# *suggestions* — the admin reviews and adjusts exact scores per posting
# before approval. Keep this list small and relevant to JHS/SHS guidance.
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


def fetch_html(url):
    resp = requests.get(url, headers=HEADERS, timeout=REQUEST_TIMEOUT)
    resp.raise_for_status()
    return resp.text


def get_job_links_from_listing(html):
    """Pull unique job-detail URLs out of a PhilJobNet listing page."""
    soup = BeautifulSoup(html, "html.parser")
    links = set()
    for a in soup.find_all("a", href=True):
        href = a["href"]
        if "/job-vacancies/job/" in href:
            links.add(urljoin(BASE_URL, href))
    return list(links)


SECTION_HEADINGS = {
    "job description": "description",
    "qualifications/requirements": "qualifications",
    "work location": "work_location",
    "remarks": "remarks",
}

EDUCATION_HINTS = [
    "college graduate", "vocational graduate", "high school graduate",
    "elementary graduate", "graduate school", "educ level not specified",
]
EMPLOYMENT_TYPE_HINTS = ["permanent", "contractual", "part-time", "part time", "project-based", "temporary"]


def parse_job_detail(html, url):
    """
    Parse a PhilJobNet job-detail page.
    Uses heading-text-anchored extraction rather than guessed CSS classes,
    since the exact markup wasn't directly inspectable while building this —
    verify against a live page and adjust selectors below if a field comes
    back empty consistently.
    """
    soup = BeautifulSoup(html, "html.parser")
    data = {
        "source_url": url,
        "source_title": None,
        "employer": None,
        "location": None,
        "education_level": None,
        "employment_type": None,
        "salary": None,
        "description": "",
        "qualifications": "",
    }

    h1 = soup.find("h1")
    if h1:
        data["source_title"] = h1.get_text(strip=True)

    employer_link = soup.find("a", href=lambda h: h and "/job-vacancies/company/" in h)
    if employer_link:
        data["employer"] = employer_link.get_text(strip=True)

    # Heading-anchored sections: collect all text between one heading and the next
    headings = soup.find_all(["h1", "h2", "h3", "h4", "h5", "h6"])
    for h in headings:
        label = h.get_text(strip=True).lower()
        target_key = SECTION_HEADINGS.get(label)
        if not target_key:
            continue
        collected = []
        for sib in h.find_next_siblings():
            if sib.name in ["h1", "h2", "h3", "h4", "h5", "h6"]:
                break
            text = sib.get_text(" ", strip=True)
            if text:
                collected.append(text)
        data[target_key] = " ".join(collected).strip()

    # Salary is typically its own small heading near the top (e.g. "Salary not specified"
    # or a peso amount) — scan headings for a currency symbol or the phrase "salary".
    for h in headings:
        text = h.get_text(strip=True)
        if "₱" in text or "salary" in text.lower():
            data["salary"] = text
            break

    # Location / education / employment type: scan all plain text on the page for
    # recognizable patterns, since these fields aren't under their own heading.
    all_text_lines = [t.strip() for t in soup.stripped_strings]
    for line in all_text_lines:
        lower = line.lower()
        if not data["education_level"] and any(hint in lower for hint in EDUCATION_HINTS):
            data["education_level"] = line
        if not data["employment_type"] and lower in EMPLOYMENT_TYPE_HINTS:
            data["employment_type"] = line
        # Location heuristic: ALL CAPS line containing a comma, e.g. "UBAY, BOHOL"
        if not data["location"] and line.isupper() and "," in line and 2 <= len(line.split(",")) <= 3:
            data["location"] = line

    return data


def get_connection():
    return pymysql.connect(**DB_CONFIG)


def save_pending_career(conn, job, keyword):
    """
    Insert one scraped posting into pending_careers.

    The crawler's HTTP requests (with polite delays) can take long enough
    between database writes that MySQL closes the connection as idle
    ("MySQL server has gone away"). conn.ping(reconnect=True) checks the
    connection right before use and transparently reconnects if needed, so a
    long crawl run doesn't die partway through.
    """
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
        "philjobnet", "Philippines",
        riasec["R"], riasec["I"], riasec["A"], riasec["S"], riasec["E"], riasec["C"],
    )

    try:
        with conn.cursor() as cur:
            affected = cur.execute(sql, params)
        conn.commit()
    except pymysql.err.OperationalError as e:
        # One retry: the connection may have dropped between the ping above
        # and the actual query (rare, but happens under flaky networking).
        print(f"  [!] DB connection hiccup ({e}); reconnecting and retrying once...")
        conn.ping(reconnect=True)
        with conn.cursor() as cur:
            affected = cur.execute(sql, params)
        conn.commit()

    return affected  # 0 means it already existed (duplicate source_url)


def run():
    conn = get_connection()
    total_new = 0
    total_seen = 0
    try:
        for keyword in TARGET_KEYWORDS:
            print(f"\n=== Searching PhilJobNet for: '{keyword}' ===")
            search_url = SEARCH_URL_TEMPLATE.format(keyword=quote(keyword))
            try:
                listing_html = fetch_html(search_url)
            except requests.RequestException as e:
                print(f"  [!] Could not fetch listing for '{keyword}': {e}")
                continue

            job_links = get_job_links_from_listing(listing_html)[:MAX_JOBS_PER_KEYWORD]
            print(f"  Found {len(job_links)} job link(s) to check.")

            for link in job_links:
                total_seen += 1
                time.sleep(CRAWL_DELAY_SECONDS)
                try:
                    detail_html = fetch_html(link)
                except requests.RequestException as e:
                    print(f"  [!] Failed to fetch {link}: {e}")
                    continue

                job = parse_job_detail(detail_html, link)
                affected = save_pending_career(conn, job, keyword)
                if affected:
                    total_new += 1
                    print(f"  [+] Staged: {job['source_title']} ({job['employer']})")
                else:
                    print(f"  [=] Already staged/seen: {job['source_title']}")

            time.sleep(CRAWL_DELAY_SECONDS)
    finally:
        conn.close()

    print(f"\nDone. Checked {total_seen} postings, staged {total_new} new entries into pending_careers.")
    print("Open php/careers.php to review and approve them into the live career database.")


if __name__ == "__main__":
    run()
