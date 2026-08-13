"""
CareerPath AI - Hybrid Recommendation Engine + AI Enrichment Layer
--------------------------------------------------------------------
Dedicated Python microservice with two independent jobs:

  1. /match   - RIASEC-based career matching using Scikit-learn cosine
                similarity (Chapter III, Layer 2 - Python Flask Matching
                Microservice). Runs completely independently of any
                external AI service and needs only MySQL.

  2. /enrich  - AI enrichment layer using the Gemini API (Gemini 2.5
                Flash-Lite) to turn a raw scraped career title/description
                into a polished description, daily-task list, educational
                pathway, and a suggested RIASEC vector. This is the
                "secondary enrichment layer" described in the paper's
                Technology Stack section — if the API key is missing or the
                call fails for any reason, /enrich reports that clearly so
                the caller (careers.php) can fall back to the raw scraped
                fields untouched. Nothing here is required for /match to
                keep working.

This only needs a MySQL connection to the `careerpath_ai` database created by
database/schema.sql.

Run:
    pip install -r requirements.txt
    set GEMINI_API_KEY=...      (only needed for /enrich; get one at
                                  https://aistudio.google.com/apikey)
    python app.py
Then POST a RIASEC vector to http://localhost:5000/match
Or POST a career title to http://localhost:5000/enrich
"""

import os
from flask import Flask, request, jsonify
from flask_restful import Api, Resource
import numpy as np
from sklearn.metrics.pairwise import cosine_similarity
import pymysql
import pymysql.cursors
from pydantic import BaseModel

app = Flask(__name__)
api = Api(app)

RIASEC_KEYS = ["R", "I", "A", "S", "E", "C"]

# --- AI enrichment config -------------------------------------------------
GEMINI_API_KEY = os.environ.get("GEMINI_API_KEY", "")
GEMINI_MODEL = os.environ.get("GEMINI_MODEL", "gemini-2.5-flash-lite")
_gemini_client = None


def get_gemini_client():
    """Lazily create the Gemini client so the whole service doesn't crash
    at startup just because no API key is configured yet — /match keeps
    working either way, only /enrich needs this."""
    global _gemini_client
    if _gemini_client is None:
        if not GEMINI_API_KEY:
            raise RuntimeError("GEMINI_API_KEY is not set")
        from google import genai
        _gemini_client = genai.Client(api_key=GEMINI_API_KEY)
    return _gemini_client

DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "localhost"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASSWORD", ""),
    "database": os.environ.get("DB_NAME", "careerpath_ai"),
    "cursorclass": pymysql.cursors.DictCursor,
}


def get_connection():
    return pymysql.connect(**DB_CONFIG)


def fetch_careers():
    """Load all active careers with their RIASEC vectors from MySQL."""
    conn = get_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                """
                SELECT career_id, career_title, description, daily_task,
                       educational_pathway, key_subjects, r_score, i_score,
                       a_score, s_score, e_score, c_score
                FROM careers
                WHERE status = 'active'
                """
            )
            return cursor.fetchall()
    finally:
        conn.close()


def normalize_vector(raw_scores, scale_max=100):
    """Scale a raw 0-100 RIASEC score set down to a 0-1 vector, in R,I,A,S,E,C order."""
    return np.array([raw_scores[k] / scale_max for k in RIASEC_KEYS], dtype=float)


def profile_similarity(student_vec, career_vecs):
    """Cosine similarity of *mean-centered* RIASEC vectors (equivalent to the
    Pearson correlation between the two profiles' shapes), still computed
    with sklearn's cosine_similarity per the paper's described methodology.

    Plain cosine similarity on raw 0-1 RIASEC vectors is a poor
    discriminator here: every vector lives in the same all-positive octant
    of a 6-dimensional space, so even a mismatched career (e.g. Architect
    for a strongly hands-on Realistic student) still scores 90%+ purely
    because the vectors point in a "generally similar enough" direction.
    Mean-centering each profile before comparing removes that baseline
    overlap and scores the *shape* of the profile instead (which traits are
    relatively high/low vs each other) — this is what actually
    differentiates "Architect" from "Welder" for the same student.

    Returns similarity scores rescaled from [-1, 1] to [0, 100] so a
    student never sees a negative percentage.
    """
    student_centered = (student_vec - student_vec.mean()).reshape(1, -1)
    career_centered = career_vecs - career_vecs.mean(axis=1, keepdims=True)
    raw = cosine_similarity(student_centered, career_centered)[0]
    return (raw + 1) / 2 * 100


class MatchResource(Resource):
    def post(self):
        payload = request.get_json(force=True, silent=True) or {}
        student_scores = payload.get("riasec")
        top_n = int(payload.get("top_n", 5))

        if not student_scores or not all(k in student_scores for k in RIASEC_KEYS):
            return {
                "error": "Missing or incomplete 'riasec' object. Expected keys: R, I, A, S, E, C"
            }, 400

        # Student scores are expected already normalized 0-1 by the PHP intake layer,
        # but we defensively clip to [0,1] in case raw 0-100 values are sent instead.
        student_vector_raw = np.array([float(student_scores[k]) for k in RIASEC_KEYS])
        if student_vector_raw.max() > 1.0:
            student_vector = student_vector_raw / 100.0
        else:
            student_vector = student_vector_raw

        careers = fetch_careers()
        if not careers:
            return {"error": "No careers available in the database yet."}, 404

        career_vectors = np.array([
            normalize_vector({
                "R": c["r_score"], "I": c["i_score"], "A": c["a_score"],
                "S": c["s_score"], "E": c["e_score"], "C": c["c_score"],
            })
            for c in careers
        ])

        similarities = profile_similarity(student_vector, career_vectors)

        ranked = sorted(
            zip(careers, similarities), key=lambda pair: pair[1], reverse=True
        )[:top_n]

        # Explainability ("glass box," not black box) — for each recommended
        # career, expose the career's own RIASEC profile plus which specific
        # RIASEC dimensions drove the match, so the results page can show its
        # work instead of just a bare percentage. The per-dimension
        # "contribution" is simply student_value * career_value for that
        # letter — the individual terms that sum to the cosine similarity's
        # dot product — ranked to surface the top shared traits.
        results = []
        for c, score in ranked:
            career_riasec = {
                "R": c["r_score"], "I": c["i_score"], "A": c["a_score"],
                "S": c["s_score"], "E": c["e_score"], "C": c["c_score"],
            }
            career_vec = normalize_vector(career_riasec)
            # Rank "why this match" dimensions by their contribution to the
            # *centered* similarity actually used for match_score above (not
            # raw student*career, which would just surface whichever two
            # dimensions both happen to be numerically large — not
            # necessarily what distinguishes this career from any other).
            student_centered = student_vector - student_vector.mean()
            career_centered = career_vec - career_vec.mean()
            contributions = student_centered * career_centered
            top_idx = np.argsort(contributions)[::-1][:2]
            top_dimensions = [
                {
                    "type": RIASEC_KEYS[i],
                    "student_pct": round(float(student_vector[i]) * 100, 1),
                    "career_pct": round(float(career_vec[i]) * 100, 1),
                }
                for i in top_idx
            ]

            results.append({
                "career_id": c["career_id"],
                "career_title": c["career_title"],
                "description": c["description"],
                "daily_task": c["daily_task"],
                "educational_pathway": c["educational_pathway"],
                "key_subjects": c["key_subjects"],
                "match_score": round(float(score), 2),  # already 0-100
                "career_riasec": career_riasec,
                "top_dimensions": top_dimensions,
            })

        return {"student_riasec": student_scores, "recommendations": results}, 200


class HealthResource(Resource):
    def get(self):
        ai_configured = bool(GEMINI_API_KEY)
        return {
            "status": "ok",
            "service": "careerpath-matching-engine",
            "ai_enrichment_configured": ai_configured,
        }, 200


class RiasecScores(BaseModel):
    R: int
    I: int
    A: int
    S: int
    E: int
    C: int


class CareerEnrichment(BaseModel):
    """Structured output schema for Gemini — the SDK enforces this shape
    directly, so there's no free-form JSON parsing to defend against here."""
    description: str
    daily_task: str
    educational_pathway: str
    riasec: RiasecScores


ENRICH_PROMPT_TEMPLATE = """You are an assistant helping a Philippine career-guidance system enrich \
raw job-posting data into a clean, student-friendly career profile.

Given the career/job title and raw scraped text below, produce:
- description: a 2-3 sentence overview of the career, written for a Junior/Senior High School student.
- daily_task: a short comma-separated list of 3-5 typical daily tasks.
- educational_pathway: the typical Philippine degree/TVET path, e.g. "BS Information Technology" or "TVET / Vocational Certificate".
- riasec: integer scores from 0-100 for each of R, I, A, S, E, C, representing the Holland Code / RIASEC profile of this career per Holland's Theory of Vocational Choice.

Career/job title: {career_title}
Raw scraped description (may be messy or empty): {raw_description}
Raw scraped qualifications (may be messy or empty): {raw_qualifications}
"""


def call_gemini_enrichment(career_title, raw_description="", raw_qualifications=""):
    client = get_gemini_client()
    prompt = ENRICH_PROMPT_TEMPLATE.format(
        career_title=career_title,
        raw_description=raw_description,
        raw_qualifications=raw_qualifications,
    )
    response = client.models.generate_content(
        model=GEMINI_MODEL,
        contents=prompt,
        config={
            "response_format": {
                "text": {"mime_type": "application/json", "schema": CareerEnrichment.model_json_schema()}
            },
        },
    )
    parsed = CareerEnrichment.model_validate_json(response.text)

    clamped_riasec = {
        key: max(0, min(100, getattr(parsed.riasec, key)))
        for key in RIASEC_KEYS
    }

    return {
        "description": parsed.description.strip(),
        "daily_task": parsed.daily_task.strip(),
        "educational_pathway": parsed.educational_pathway.strip(),
        "riasec": clamped_riasec,
    }


class EnrichResource(Resource):
    """
    POST { "career_title": "...", "raw_description": "...", "raw_qualifications": "..." }

    Returns 200 with {"ai_enriched": true, ...fields...} on success.
    Returns 503 with {"ai_enriched": false, "error": "..."} if the API key is
    missing or the Gemini call fails for any reason — callers (careers.php)
    should treat this as "keep the raw scraped data as-is," matching the
    paper's described fallback behavior.
    """

    def post(self):
        payload = request.get_json(force=True, silent=True) or {}
        career_title = (payload.get("career_title") or "").strip()
        if not career_title:
            return {"ai_enriched": False, "error": "career_title is required"}, 400

        raw_description = payload.get("raw_description", "") or ""
        raw_qualifications = payload.get("raw_qualifications", "") or ""

        try:
            result = call_gemini_enrichment(career_title, raw_description, raw_qualifications)
            result["ai_enriched"] = True
            return result, 200
        except Exception as e:
            # Any failure (missing key, network error, rate limit, schema
            # validation error, etc.) falls back gracefully rather than
            # crashing the request — the raw scraped data is still usable.
            return {"ai_enriched": False, "error": str(e)}, 503


api.add_resource(MatchResource, "/match")
api.add_resource(HealthResource, "/health")
api.add_resource(EnrichResource, "/enrich")

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=True)
