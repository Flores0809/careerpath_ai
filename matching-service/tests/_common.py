"""Shared helpers for the CareerPath AI matching-service tests.

The tests import the real matching-service module (app.py) and replace only
its database call (fetch_careers) with the RIASEC profiles of the careers
seeded in database/schema.sql, so no MySQL server is needed to run them.
"""
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.dirname(HERE))  # matching-service/

import app as matching_app  # noqa: E402


def seed_careers():
    """RIASEC profiles of the seeded careers, parsed from database/schema.sql."""
    sql = open(os.path.join(ROOT, "database", "schema.sql"), encoding="utf-8").read()
    pat = re.compile(r"\('([^']+)', '[^']*', '(?:[^']|'')*', '(?:[^']|'')*', '(?:[^']|'')*', (\d+), (\d+), (\d+), (\d+), (\d+), (\d+)\)")
    careers = []
    for i, m in enumerate(pat.finditer(sql), start=1):
        r, i_, a, s, e, c = (int(x) for x in m.groups()[1:])
        careers.append({
            "career_id": i, "career_title": m.group(1), "description": "", "daily_task": "",
            "educational_pathway": "", "key_subjects": "",
            "r_score": r, "i_score": i_, "a_score": a, "s_score": s, "e_score": e, "c_score": c,
        })
    return careers


def install_fake_db(careers):
    matching_app.fetch_careers = lambda: careers
