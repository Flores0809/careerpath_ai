"""Unit and integration tests for the CareerPath AI matching service.

Run from the matching-service folder:   python -m unittest discover -s tests -v
"""
import unittest

import numpy as np

import _common
from _common import matching_app as m

CAREERS = _common.seed_careers()
_common.install_fake_db(CAREERS)
KEYS = ["R", "I", "A", "S", "E", "C"]

def profile(**kw):
    return {k: kw.get(k, 0.5) for k in KEYS}

class UnitTests(unittest.TestCase):
    def test_seed_data_loaded(self):
        self.assertGreaterEqual(len(CAREERS), 10)

    def test_normalize_vector_scales_to_0_1_in_riasec_order(self):
        v = m.normalize_vector({"R": 100, "I": 50, "A": 0, "S": 25, "E": 75, "C": 10})
        self.assertTrue(np.allclose(v, [1.0, 0.5, 0.0, 0.25, 0.75, 0.1]))

    def test_identical_profiles_score_100(self):
        s = np.array([0.9, 0.2, 0.1, 0.4, 0.3, 0.6])
        score = m.profile_similarity(s, np.array([s]))[0]
        self.assertAlmostEqual(score, 100.0, places=6)

    def test_opposite_profiles_score_0(self):
        s = np.array([1.0, 0.8, 0.6, 0.4, 0.2, 0.0])
        opp = 1.0 - s
        score = m.profile_similarity(s, np.array([opp]))[0]
        self.assertAlmostEqual(score, 0.0, places=6)

    def test_mean_centering_ignores_uniform_level_shift(self):
        s = np.array([0.8, 0.3, 0.2, 0.5, 0.4, 0.6])
        c = np.array([0.7, 0.4, 0.1, 0.6, 0.3, 0.5])
        a = m.profile_similarity(s, np.array([c]))[0]
        b = m.profile_similarity(s, np.array([c - 0.1]))[0]
        self.assertAlmostEqual(a, b, places=6)

    def test_flat_profile_does_not_crash_and_is_neutral(self):
        flat = np.array([0.5] * 6)
        score = m.profile_similarity(flat, np.array([[0.9, 0.1, 0.2, 0.3, 0.4, 0.5]]))[0]
        self.assertAlmostEqual(score, 50.0, places=6)

    def test_scores_always_within_0_and_100(self):
        rng = np.random.default_rng(1)
        careers = rng.random((500, 6))
        for _ in range(200):
            scores = m.profile_similarity(rng.random(6), careers)
            self.assertTrue(((scores >= 0) & (scores <= 100)).all())

    def test_same_input_always_gives_same_output(self):
        s = np.array([0.7, 0.9, 0.2, 0.3, 0.4, 0.5])
        c = np.array([[0.1, 0.8, 0.3, 0.2, 0.6, 0.7], [0.9, 0.2, 0.1, 0.5, 0.4, 0.3]])
        self.assertTrue(np.array_equal(m.profile_similarity(s, c), m.profile_similarity(s, c)))

class EndpointTests(unittest.TestCase):
    def setUp(self):
        self.client = m.app.test_client()

    def test_health_endpoint(self):
        r = self.client.get("/health")
        self.assertEqual(r.status_code, 200)

    def test_match_returns_ranked_top_n_best_first(self):
        r = self.client.post("/match", json={"riasec": profile(R=0.95, I=0.4, A=0.1, S=0.2, E=0.3, C=0.5), "top_n": 5})
        self.assertEqual(r.status_code, 200)
        body = r.get_json()
        items = body.get("recommendations") or body.get("careers") or body
        scores = [x.get("match_score", x.get("similarity")) for x in items]
        self.assertEqual(len(items), 5)
        self.assertEqual(scores, sorted(scores, reverse=True))

    def test_realistic_student_gets_a_hands_on_career_first(self):
        r = self.client.post("/match", json={"riasec": profile(R=0.95, I=0.4, A=0.1, S=0.1, E=0.2, C=0.5), "top_n": 3})
        items = r.get_json().get("recommendations") or r.get_json().get("careers")
        top = max(CAREERS, key=lambda c: c["r_score"])  # most Realistic seeded career
        titles = [x["career_title"] for x in items]
        self.assertIn(top["career_title"], titles)

    def test_artistic_student_does_not_get_realistic_career_first(self):
        r = self.client.post("/match", json={"riasec": profile(R=0.1, I=0.3, A=0.95, S=0.5, E=0.4, C=0.1), "top_n": 1})
        first = (r.get_json().get("recommendations") or r.get_json().get("careers"))[0]
        row = next(c for c in CAREERS if c["career_title"] == first["career_title"])
        self.assertGreater(row["a_score"], row["r_score"])

    def test_missing_dimension_is_rejected_with_400(self):
        r = self.client.post("/match", json={"riasec": {"R": 0.5, "I": 0.5}})
        self.assertEqual(r.status_code, 400)

    def test_empty_body_is_rejected_with_400(self):
        r = self.client.post("/match", data="not json", content_type="text/plain")
        self.assertEqual(r.status_code, 400)

    def test_raw_0_to_100_scores_are_accepted(self):
        r = self.client.post("/match", json={"riasec": {k: 80 for k in KEYS}, "top_n": 2})
        self.assertEqual(r.status_code, 200)

if __name__ == "__main__":
    unittest.main(verbosity=2)
