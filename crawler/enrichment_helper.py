"""
CareerPath AI - Shared auto-enrichment helper for the crawler scripts
-----------------------------------------------------------------------
Calls the matching-service's /enrich endpoint (must already be running --
same requirement already documented on php/careers.php's crawler panel)
right after a crawler stages a brand-new pending_careers row, so a
counselor opening Career Review usually finds AI-suggested content,
required skills, AND a pre-picked Category / Industry Cluster already
filled in, instead of having to click a manual "Enrich with AI" button and
choose a category by hand per entry (that button no longer exists in
php/careers.php -- see migration_20_pending_career_skills.sql). The
category suggestion is always one of the counselor's own existing
career_categories names (never invented) -- if nothing fits well, it's just
left blank for a counselor to pick, same as before this existed.

Best-effort only, by design: if the matching service isn't running, the
Gemini API key isn't configured, or the call fails for any reason, this
silently leaves the row unenriched. The counselor just fills those fields
in by hand for that one entry -- the same graceful fallback the system
already had before automatic enrichment existed. A crawl run should never
fail or stall because of this.

Imported by crawler.py, onet_client.py, adzuna_client.py, and
remoteok_client.py -- one shared implementation instead of four copies.
"""

import os
import sys
import requests

# Windows runs this script's stdout/stderr through the legacy cp1252 console
# codepage by default, which can't encode most Unicode symbols (emoji,
# curly quotes, etc.) -- printing one crashes the whole crawler subprocess
# with UnicodeEncodeError. Force UTF-8 with a safe fallback so a stray
# non-ASCII character in a log line never takes the crawler down again.
for _stream in (sys.stdout, sys.stderr):
    if hasattr(_stream, "reconfigure"):
        _stream.reconfigure(encoding="utf-8", errors="replace")

ENRICH_URL = os.environ.get("ENRICH_SERVICE_URL", "http://localhost:5000/enrich")
# Gemini calls can take a few seconds; generous but bounded so one slow
# entry can't hang an entire crawl run indefinitely.
ENRICH_TIMEOUT_SECONDS = 40


def auto_enrich_pending(conn, pending_id, source_title, description, qualifications):
    """
    Best-effort: enrich one freshly-staged pending_careers row in place via
    the matching service, and stage its suggested required skills into
    pending_career_skills. Never raises -- returns True if enrichment was
    applied, False otherwise (already logged either way).
    """
    try:
        return _auto_enrich_pending_impl(conn, pending_id, source_title, description, qualifications)
    except Exception as e:
        # Belt-and-suspenders: every expected failure mode below already has
        # its own try/except (network unreachable, bad response, DB write
        # failure), but this outer guard exists so a truly unexpected error
        # (e.g. the UnicodeEncodeError that used to happen on the success
        # print, or anything else not anticipated here) can NEVER escape and
        # kill the whole crawl loop for every posting after this one -- it
        # just gets logged and the crawler moves on to the next entry.
        print(f"  [!] Auto-enrich hit an unexpected error for '{source_title}': {e}")
        return False


def _auto_enrich_pending_impl(conn, pending_id, source_title, description, qualifications):
    # Current Category / Industry Cluster options (counselor-managed on
    # php/career_categories.php) -- sent along so /enrich can suggest one of
    # these verbatim instead of inventing a category that doesn't exist in
    # the system. Best-effort: if this lookup fails for any reason, just
    # enrich without a category suggestion rather than skipping enrichment
    # entirely.
    valid_categories = []
    try:
        conn.ping(reconnect=True)
        with conn.cursor() as cur:
            cur.execute("SELECT name FROM career_categories ORDER BY name")
            valid_categories = [row["name"] for row in cur.fetchall()]
    except Exception:
        pass

    try:
        resp = requests.post(
            ENRICH_URL,
            json={
                "career_title": source_title or "",
                "raw_description": description or "",
                "raw_qualifications": qualifications or "",
                "categories": valid_categories,
            },
            timeout=ENRICH_TIMEOUT_SECONDS,
        )
        result = resp.json()
    except Exception as e:
        print(f"  [!] Auto-enrich skipped for '{source_title}' -- matching service unreachable ({e}).")
        return False

    if not result.get("ai_enriched"):
        print(f"  [!] Auto-enrich skipped for '{source_title}': {result.get('error', 'unknown error')}")
        return False

    riasec = result.get("riasec", {})

    try:
        conn.ping(reconnect=True)
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE pending_careers SET
                    ai_description = %s, ai_daily_task = %s, ai_educational_pathway = %s,
                    ai_r_score = %s, ai_i_score = %s, ai_a_score = %s,
                    ai_s_score = %s, ai_e_score = %s, ai_c_score = %s,
                    career_category = NULLIF(%s, ''),
                    ai_enriched_at = NOW()
                WHERE pending_id = %s
                """,
                (
                    result.get("description", ""),
                    result.get("daily_task", ""),
                    result.get("educational_pathway", ""),
                    riasec.get("R", 0), riasec.get("I", 0), riasec.get("A", 0),
                    riasec.get("S", 0), riasec.get("E", 0), riasec.get("C", 0),
                    result.get("category", ""),
                    pending_id,
                ),
            )
            for skill in result.get("skills", []):
                name = (skill.get("skill_name") or "").strip()
                if not name:
                    continue
                cur.execute(
                    """
                    INSERT INTO pending_career_skills (pending_id, skill_name, proficiency_level, is_required)
                    VALUES (%s, %s, %s, %s)
                    """,
                    (
                        pending_id,
                        name[:150],
                        (skill.get("proficiency_level") or "")[:150],
                        bool(skill.get("is_required", True)),
                    ),
                )
        conn.commit()
    except Exception as e:
        print(f"  [!] Auto-enrich reached Gemini but the DB write failed for '{source_title}': {e}")
        return False

    print(f"  [AI] Auto-enriched: {source_title}")
    return True
