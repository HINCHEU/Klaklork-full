import csv, sys, collections

# Per 30 s window of a k6 CSV: tables (max VUs), req/s, p50/p95 latency, error %, slowest endpoint.
win = collections.defaultdict(lambda: {"vus": 0, "dur": [], "fail": 0, "n": 0, "by": collections.defaultdict(list)})
t0 = None
with open(sys.argv[1], newline="") as f:
    for r in csv.DictReader(f):
        t = int(r["timestamp"])
        t0 = t if t0 is None else min(t0, t)
        w = win[t // 30 * 30]
        m, v = r["metric_name"], float(r["metric_value"])
        if m == "vus":
            w["vus"] = max(w["vus"], int(v))
        elif m == "http_req_duration":
            w["dur"].append(v)
            w["by"][r["name"]].append(v)
        elif m == "http_req_failed":
            w["n"] += 1
            w["fail"] += v

def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(len(xs) * p))] if xs else 0

print(f"{'t':>5} {'tables':>6} {'players':>7} {'req/s':>6} {'p50':>6} {'p95':>6} {'err%':>5}  slowest p95")
for k in sorted(win):
    w = win[k]
    if not w["dur"]:
        continue
    slow = max(w["by"].items(), key=lambda kv: pct(kv[1], .95))
    print(f"{k - t0:>5} {w['vus']:>6} {w['vus'] * 6:>7} {len(w['dur']) / 30:>6.1f} {pct(w['dur'], .5):>6.0f} {pct(w['dur'], .95):>6.0f} "
          f"{100 * w['fail'] / max(w['n'], 1):>5.1f}  {slow[0]} {pct(slow[1], .95):.0f}ms")
