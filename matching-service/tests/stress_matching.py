"""Stress test of the CareerPath AI matching service (POST /match).

Runs the real Flask endpoint in-process (no network, no MySQL: the career
list is the schema.sql seed data, and the scaling scenario repeats it) and
reports response time, throughput and error rate. Run from matching-service/:

    python tests/stress_matching.py
"""
import platform
import random
import statistics
import threading
import time

import _common
from _common import matching_app as m

BASE = _common.seed_careers()
KEYS = ["R", "I", "A", "S", "E", "C"]
rng = random.Random(42)


def rand_profile():
    return {k: round(rng.random(), 3) for k in KEYS}


def pct(sorted_vals, p):
    return sorted_vals[min(len(sorted_vals) - 1, int(len(sorted_vals) * p))]


def run(label, careers, users, per_user):
    _common.install_fake_db(careers)
    lat, errors = [], [0]
    lock = threading.Lock()

    def worker(seed):
        local = random.Random(seed)
        client = m.app.test_client()
        for _ in range(per_user):
            body = {"riasec": {k: round(local.random(), 3) for k in KEYS}, "top_n": 5}
            t0 = time.perf_counter()
            r = client.post("/match", json=body)
            dt = (time.perf_counter() - t0) * 1000
            with lock:
                lat.append(dt)
                if r.status_code != 200:
                    errors[0] += 1

    t_start = time.perf_counter()
    threads = [threading.Thread(target=worker, args=(i,)) for i in range(users)]
    for t in threads: t.start()
    for t in threads: t.join()
    wall = time.perf_counter() - t_start
    lat.sort()
    total = users * per_user
    print(f"| {label} | {len(careers)} | {users} | {total} | {statistics.mean(lat):.2f} | {pct(lat, .5):.2f} | {pct(lat, .95):.2f} | {lat[-1]:.2f} | {total / wall:.0f} | {errors[0]} |")


if __name__ == "__main__":
    print(f"Environment: {platform.platform()} | Python {platform.python_version()} | CPU cores: {__import__('os').cpu_count()}")
    print("| Scenario | Careers in catalog | Concurrent users | Total requests | Mean (ms) | Median (ms) | 95th pct (ms) | Max (ms) | Throughput (req/s) | Errors |")
    print("|---|---|---|---|---|---|---|---|---|---|")
    run("Normal load", BASE, 1, 100)
    run("Class-size load", BASE, 50, 10)
    run("Evaluation-size load (227 respondents)", BASE, 227, 1)
    run("Heavy load", BASE, 100, 50)
    run("Burst (500 simultaneous users)", BASE, 500, 1)
    big = [dict(c, career_id=i) for i in range(100) for c in BASE]
    run("Large catalog (x100 careers)", big, 50, 10)
