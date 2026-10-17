#!/usr/bin/env python3
"""Accurate count of Cezma products CREATED in 2026, via the official API.
GET https://api.cezma.cloud/api/products  Header: Platform: web
Items are ordered oldest->newest, so: binary-search the first position whose
created_at >= 2026-01-01, then correct locally around the boundary.
Everything is logged to cezma_api_log.txt so failures are visible in git.
"""
import json, sys, traceback, urllib.request

BASE = "https://api.cezma.cloud/api/products"
HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
LOG = open("cezma_api_log.txt", "w", encoding="utf-8")

def log(*a):
    s = " ".join(str(x) for x in a)
    print(s, flush=True); LOG.write(s + "\n"); LOG.flush()

def get(page, per):
    url = f"{BASE}?page={page}&per_page={per}"
    req = urllib.request.Request(url, headers=HDR)
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode("utf-8", "replace"))

def item_at(pos):  # 1-indexed position, one small request
    j = get(pos, 1)
    return j["data"][0] if j.get("data") else None

def created(item): return str((item or {}).get("created_at") or "")

def main():
    first = get(1, 1)
    total = int(first["item_count"])
    log("total products (API):", total)
    for pos in [1, total // 4, total // 2, (3 * total) // 4, (9 * total) // 10, total]:
        it = item_at(pos)
        log(f" sample pos={pos} id={it.get('id')} created_at={created(it)}")
    # binary search: first position with created_at >= 2026-01-01
    lo, hi = 1, total  # hi is known >=2026 (checked by sample above / last item)
    while lo < hi:
        mid = (lo + hi) // 2
        if created(item_at(mid)) >= "2026-01-01":
            hi = mid
        else:
            lo = mid + 1
    first_pos = lo
    log("first 2026 position:", first_pos, created(item_at(first_pos)), "| prev:", created(item_at(first_pos - 1)))
    # local correction: scan 3000 items either side of the boundary (per_page=100)
    start = max(1, first_pos - 3000); end = min(total, first_pos + 3000)
    pre_2026_after, in_2026_before = 0, 0
    p0, p1 = (start - 1) // 100 + 1, (end - 1) // 100 + 1
    for pg in range(p0, p1 + 1):
        j = get(pg, 100)
        for i, d in enumerate(j["data"]):
            pos = (pg - 1) * 100 + i + 1
            if pos < start or pos > end: continue
            y2026 = created(d) >= "2026-01-01"
            if pos >= first_pos and not y2026: pre_2026_after += 1
            if pos < first_pos and y2026: in_2026_before += 1
    log(f"local scan {start}..{end}: pre2026_at/after_boundary={pre_2026_after} 2026_before_boundary={in_2026_before}")
    # global sanity sampling outside the local window
    bad_after = bad_before = 0
    pos = first_pos + 3000
    while pos <= total:
        if created(item_at(pos)) < "2026-01-01": bad_after += 1; log("  BAD after at", pos)
        pos += 5000
    pos = 1
    while pos < first_pos - 3000:
        if created(item_at(pos)) >= "2026-01-01": bad_before += 1; log("  BAD before at", pos)
        pos += 5000
    log(f"global sample violations: after={bad_after} before={bad_before}")
    count = (total - first_pos + 1) - pre_2026_after + in_2026_before
    out = {"api_total_products": total, "first_2026_position": first_pos,
           "count_created_2026_api": count,
           "first_2026_item": {k: item_at(first_pos).get(k) for k in ("id", "created_at", "name_ar")},
           "last_item": {k: item_at(total).get(k) for k in ("id", "created_at", "name_ar")},
           "local_corrections": {"pre_2026_after_boundary": pre_2026_after, "in_2026_before_boundary": in_2026_before},
           "global_sample_violations": {"after": bad_after, "before": bad_before}}
    log(json.dumps(out, ensure_ascii=False, indent=1))
    with open("cezma_result_api.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)

if __name__ == "__main__":
    try:
        main()
    except Exception:
        log("FAILED:\n" + traceback.format_exc())
        sys.exit(1)
