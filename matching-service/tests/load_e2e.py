"""End-to-end HTTP load test for CareerPath AI (real network, real PHP, real MySQL).

Unlike stress_matching.py (which calls the Flask function in-process), this
sends real HTTP requests to the running system, so the numbers include Apache/
PHP, MySQL and the matching service. Standard library only - nothing to install.

Run it on the same computer that runs XAMPP (do NOT point it at the live site):

  python tests/load_e2e.py --base http://localhost/careerpath-ai-mvp/php \
      --match http://localhost:5000 --email loadtest@example.com --password YourPass123

Scenarios (each is run at 10, 25, 50, 100 and 200 simultaneous users):
  match  : POST /match straight to the matching service
  login  : student login + dashboard page
  flow   : login -> open assessment -> submit 60 answers -> results page
           (this one writes an assessment per user and, if GEMINI_API_KEY is
            set, makes one Gemini call each - see notes in the chat)
Use --only match,login to skip a scenario, --levels 10,50 to change the levels.
Results are printed as a table and saved to load_results.csv.
"""
import argparse, csv, json, random, statistics, threading, time, urllib.parse, urllib.request, http.cookiejar, platform, os

KEYS = "RIASEC"

def pct(v, p):
    return v[min(len(v) - 1, int(len(v) * p))]

class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def get(self, url):
        with self.op.open(url, timeout=60) as r:
            return r.status, r.geturl(), r.read()

    def post(self, url, data, js=False):
        if js:
            body = json.dumps(data).encode(); h = {"Content-Type": "application/json"}
        else:
            body = urllib.parse.urlencode(data, doseq=True).encode(); h = {}
        req = urllib.request.Request(url, data=body, headers=h)
        with self.op.open(req, timeout=60) as r:
            return r.status, r.geturl(), r.read()

def do_match(a, rng):
    s = Session()
    body = {"riasec": {k: round(rng.random(), 3) for k in KEYS}, "top_n": 5}
    st, _, txt = s.post(a.match.rstrip("/") + "/match", body, js=True)
    return st == 200 and b"recommendations" in txt or st == 200

def login(a, s):
    st, url, txt = s.post(a.base.rstrip("/") + "/student_login.php",
                          {"email": a.email, "password": a.password, "next": "student_dashboard.php"})
    if st == 200 and "student_login" not in url:
        return True
    return f"login failed (HTTP {st}, ended at {url.split('/')[-1][:40]})"

def do_login(a, rng):
    s = Session()
    r = login(a, s)
    if r is not True: return r
    st, _, _ = s.get(a.base.rstrip("/") + "/student_dashboard.php")
    return st == 200 or f"dashboard HTTP {st}"

def do_flow(a, rng):
    s = Session()
    r = login(a, s)
    if r is not True: return r
    st, _, page = s.get(a.base.rstrip("/") + "/assessment.php")
    if st != 200: return f"assessment page HTTP {st}"
    text = page.decode("utf-8", "ignore")
    import re
    cat = re.search(r'name="dream_category".*?<option value="([^"]+)"', text, re.S)
    form = {"skills": "teamwork, communication, basic computer skills", "academic_average": str(round(rng.uniform(75, 98), 2)),
            "dream_category": cat.group(1) if cat else ""}
    for t in KEYS:
        for i in range(10):
            form[f"{t}[{i}]"] = str(rng.randint(1, 4))
    st, url, body = s.post(a.base.rstrip("/") + "/submit.php", form)
    if st == 200 and "profile_id=" in url: return True
    html = body.decode("utf-8", "ignore")
    import re as _re
    m = _re.search(r'class="error"[^>]*>(.*?)</', html, _re.S)
    msg = _re.sub(r"\s+", " ", _re.sub(r"<[^>]+>", " ", m.group(1))).strip()[:200] if m else "no error box on page (history save may have failed)"
    return f"submit did not reach results (HTTP {st}): {msg}"

SCEN = {"match": do_match, "login": do_login, "flow": do_flow}

def run(name, fn, a, users):
    lat, errs, lock = [], [0], threading.Lock()
    why = {}
    go = threading.Barrier(users)

    def worker(seed):
        rng = random.Random(seed)
        go.wait()
        for _ in range(a.per_user):
            t0 = time.perf_counter()
            reason = None
            try:
                ok = fn(a, rng)
                if ok is not True and ok is not False:
                    reason, ok = (ok if isinstance(ok, str) else None), (ok is True)
            except Exception as ex:
                ok, reason = False, f"{type(ex).__name__}: {str(ex)[:100]}"
            dt = (time.perf_counter() - t0) * 1000
            with lock:
                lat.append(dt)
                if not ok:
                    errs[0] += 1
                    k = reason or "check failed"
                    why[k] = why.get(k, 0) + 1

    t0 = time.perf_counter()
    th = [threading.Thread(target=worker, args=(i,)) for i in range(users)]
    for t in th: t.start()
    for t in th: t.join()
    wall = time.perf_counter() - t0
    lat.sort(); n = len(lat)
    for k, v in sorted(why.items(), key=lambda x: -x[1])[:3]:
        print(f"   ! {v} x {k}")
    return [name, users, n, round(statistics.mean(lat), 1), round(pct(lat, .5), 1), round(pct(lat, .95), 1), round(lat[-1], 1),
            round(n / wall, 1), errs[0], round(100 * errs[0] / n, 2)]

if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://localhost/careerpath-ai-mvp/php")
    ap.add_argument("--match", default="http://localhost:5000")
    ap.add_argument("--email"); ap.add_argument("--password")
    ap.add_argument("--levels", default="10,25,50,100,200")
    ap.add_argument("--per-user", dest="per_user", type=int, default=2)
    ap.add_argument("--only", default="match,login,flow")
    a = ap.parse_args()
    print(f"Environment: {platform.platform()} | Python {platform.python_version()} | CPU cores: {os.cpu_count()}")
    head = ["Scenario", "Users", "Requests", "Mean ms", "Median ms", "95th pct ms", "Max ms", "Req/s", "Errors", "Error %"]
    rows = []
    print("| " + " | ".join(head) + " |"); print("|" + "---|" * len(head))
    for name in a.only.split(","):
        if name in ("login", "flow") and not (a.email and a.password):
            print(f"(skipped {name}: give --email and --password of a test student)"); continue
        for u in [int(x) for x in a.levels.split(",")]:
            r = run(name, SCEN[name], a, u); rows.append(r)
            print("| " + " | ".join(map(str, r)) + " |", flush=True)
            time.sleep(2)
    fname = "load_results.csv"
    try:
        f = open(fname, "w", newline="")
    except PermissionError:
        fname = time.strftime("load_results_%H%M%S.csv")
        f = open(fname, "w", newline="")
    with f:
        w = csv.writer(f); w.writerow(head); w.writerows(rows)
    print("saved", fname)
