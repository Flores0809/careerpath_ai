"""
CareerPath AI - Matching service load test
--------------------------------------------------------------------
Fires a burst of concurrent, realistic /match requests at the matching
service to see whether it holds up under "a whole class submits their
assessment around the same time" conditions -- the scenario the code
review flagged as the main deployment risk (Flask's dev server can only
handle one request at a time; this script proves it, and proves the
waitress fix in app.py resolves it).

Run this on the SAME machine as the matching service, from a normal
Command Prompt / PowerShell / Git Bash (not the app's own venv needed --
it only uses the standard library, no install required):

    1. Start the matching service as usual (the .bat file, or
       `python app.py` inside matching-service\\venv).
    2. In a second terminal:
           python load_test.py
       (optionally: python load_test.py --requests 60 --concurrency 30)

What to look for:
    - "Old" Flask dev server (app.run(), no threaded=True): total time
      scales almost linearly with --requests no matter how high you set
      --concurrency, because everything runs one-at-a-time. 30 requests
      at ~0.3s each = ~9s wall time even though nothing individually is
      slow.
    - Fixed version (waitress, threads=8): wall time stays close to a
      single request's latency as long as --concurrency <= 8, since up
      to 8 requests genuinely run in parallel.

This only exercises /match (pure CPU + one MySQL read, no Gemini call),
which is deliberately the CHEAPEST endpoint -- it's the one that should
never be slow, and under the old single-threaded server it still queued
up behind anything else in flight. If you want to simulate the worst
case (someone's /student_commentary call blocking everyone else), start
one of those requests manually in a browser tab first, then run this
script while it's still pending.
"""

import argparse
import json
import statistics
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

DEFAULT_URL = "http://127.0.0.1:5000/match"

# A handful of varied-but-plausible RIASEC profiles so requests aren't
# all identical (not that it matters for load testing, but it's a more
# honest simulation of real traffic).
SAMPLE_PROFILES = [
    {"R": 0.8, "I": 0.6, "A": 0.2, "S": 0.3, "E": 0.4, "C": 0.5},
    {"R": 0.2, "I": 0.9, "A": 0.7, "S": 0.3, "E": 0.2, "C": 0.4},
    {"R": 0.3, "I": 0.3, "A": 0.9, "S": 0.6, "E": 0.5, "C": 0.2},
    {"R": 0.4, "I": 0.3, "A": 0.2, "S": 0.9, "E": 0.6, "C": 0.5},
    {"R": 0.3, "I": 0.4, "A": 0.3, "S": 0.5, "E": 0.9, "C": 0.6},
    {"R": 0.5, "I": 0.4, "A": 0.2, "S": 0.4, "E": 0.5, "C": 0.9},
]


def one_request(url, i):
    profile = SAMPLE_PROFILES[i % len(SAMPLE_PROFILES)]
    body = json.dumps({"riasec": profile, "top_n": 5}).encode()
    req = urllib.request.Request(
        url, data=body, headers={"Content-Type": "application/json"}, method="POST"
    )
    start = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=40) as resp:
            resp.read()
            ok = resp.status == 200
    except urllib.error.URLError as e:
        return {"i": i, "elapsed": time.perf_counter() - start, "ok": False, "error": str(e)}
    return {"i": i, "elapsed": time.perf_counter() - start, "ok": ok}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--url", default=DEFAULT_URL, help=f"Match endpoint (default: {DEFAULT_URL})")
    parser.add_argument("--requests", type=int, default=30, help="Total requests to fire (default: 30)")
    parser.add_argument("--concurrency", type=int, default=15, help="How many in flight at once (default: 15)")
    args = parser.parse_args()

    print(f"Firing {args.requests} requests at {args.url} with concurrency={args.concurrency} ...\n")

    wall_start = time.perf_counter()
    results = []
    with ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        futures = [pool.submit(one_request, args.url, i) for i in range(args.requests)]
        for f in as_completed(futures):
            results.append(f.result())
    wall_elapsed = time.perf_counter() - wall_start

    oks = [r for r in results if r["ok"]]
    fails = [r for r in results if not r["ok"]]
    latencies = [r["elapsed"] for r in oks]

    print(f"Wall time for all {args.requests} requests: {wall_elapsed:.2f}s")
    print(f"Succeeded: {len(oks)}/{args.requests}   Failed: {len(fails)}")
    if latencies:
        print(f"Per-request latency  min={min(latencies):.3f}s  "
              f"avg={statistics.mean(latencies):.3f}s  "
              f"max={max(latencies):.3f}s")
    if fails:
        print("\nFailures (first 5):")
        for r in fails[:5]:
            print(f"  #{r['i']}: {r.get('error')}")

    if latencies:
        theoretical_serial = sum(latencies)
        speedup = theoretical_serial / wall_elapsed if wall_elapsed else 0
        print(f"\nSum of individual latencies: {theoretical_serial:.2f}s  "
              f"(wall time was {speedup:.1f}x faster than doing them one-by-one)")
        if speedup < 1.5 and args.concurrency > 2:
            print("-> Speedup is near 1x despite concurrency > 2: requests are effectively\n"
                  "   running one at a time. If you're still on the old Flask dev server,\n"
                  "   this is exactly the bottleneck the code review flagged.")
        else:
            print("-> Requests are genuinely overlapping -- the service is handling\n"
                  "   concurrent load, not serializing it.")


if __name__ == "__main__":
    main()
