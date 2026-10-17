#!/usr/bin/env python3
"""Breakdown by main category of Cezma products created in 2026 (v2).
Category lists are NOT date-ordered, so instead:
1) Find the global first-2026 product ID (IDs are auto-increment => created>=2026-01-01 iff id>=threshold,
   verified at the boundary and by samples in earlier runs).
2) Scan every main category's full product list (list API, ids only needed) and count id>=threshold.
Validation: category totals must sum to the global total, and 2026 counts to the global 2026 count.
"""
import concurrent.futures, csv, json, time, traceback, urllib.request

HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
LOG = open("cezma_categories_log.txt", "w", encoding="utf-8")
def log(*a):
    s = " ".join(str(x) for x in a); print(s, flush=True); LOG.write(s + "\n"); LOG.flush()
def get(url, tries=5):
    last = None
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers=HDR)
            with urllib.request.urlopen(req, timeout=120) as r:
                return json.loads(r.read().decode("utf-8", "replace"))
        except Exception as e:
            last = e; time.sleep(2 + i * 2)
    raise last
def detail_date(pid):
    d = get(f"{BASE}/{pid}")
    return str(((d.get("data") or {}).get("product") or {}).get("date") or "")

try:
    # 1) threshold ID via binary search over existing products (position -> detail date)
    j1 = get(f"{BASE}?page=1&per_page=1"); total_global = int(j1["item_count"])
    def item_at(pos):
        j = get(f"{BASE}?page={pos}&per_page=1"); return j["data"][0]
    lo, hi = 1, total_global
    while lo < hi:
        mid = (lo + hi) // 2
        if detail_date(int(item_at(mid)["id"])) >= "2026-01-01": hi = mid
        else: lo = mid + 1
    first_it = item_at(lo); THRESH = int(first_it["id"]); global_2026 = total_global - lo + 1
    log(f"global total={total_global} first2026 pos={lo} id={THRESH} date={detail_date(THRESH)} => global 2026={global_2026}")

    # 2) categories + totals, determine effective per_page
    cats = get("https://api.cezma.cloud/api/categories")["data"]
    mains = [{"id": c["id"], "name_ar": c.get("name_ar") or "", "name_en": c.get("name_en") or ""} for c in cats]
    test = get(f"{BASE}?page=1&per_page=500&category_id={mains[0]['id']}")
    PER = int(test.get("per_page") or len(test["data"]) or 100)
    log(f"main categories={len(mains)} effective per_page={PER}")
    totals = {}
    for c in mains:
        j = get(f"{BASE}?page=1&per_page=1&category_id={c['id']}")
        totals[c["id"]] = int(j["item_count"])
    log("category totals sum:", sum(totals.values()))

    tasks = [(c["id"], p) for c in mains for p in range(1, (totals[c["id"]] - 1) // PER + 2)]
    log(f"scanning {len(tasks)} category pages...")
    counts = {c["id"]: [0, 0] for c in mains}  # id -> [total_scanned, n2026]
    def one(task):
        cid, p = task
        j = get(f"{BASE}?page={p}&per_page={PER}&category_id={cid}")
        ids = [int(d["id"]) for d in j["data"]]
        return cid, len(ids), sum(1 for i in ids if i >= THRESH)
    done = 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=32) as ex:
        for cid, n, n26 in ex.map(one, tasks):
            counts[cid][0] += n; counts[cid][1] += n26
            done += 1
            if done % 200 == 0: log(f" pages {done}/{len(tasks)}")
    results = []
    for c in mains:
        results.append({**c, "total_in_category": totals[c["id"]], "scanned": counts[c["id"]][0], "created_2026": counts[c["id"]][1]})
    results.sort(key=lambda r: -r["created_2026"])
    sum26 = sum(r["created_2026"] for r in results)
    log(f"SUM 2026 by category={sum26} (global={global_2026}, diff={sum26-global_2026})")
    out = {"threshold_first_2026_id": THRESH, "global_total": total_global, "global_created_2026": global_2026,
           "sum_by_category_2026": sum26, "categories": results}
    log(json.dumps(out, ensure_ascii=False, indent=1))
    with open("cezma_categories_2026.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
    with open("cezma_categories_2026.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f); w.writerow(["category_id", "category_ar", "category_en", "products_created_2026", "total_products_in_category", "pct_of_2026"])
        for r in results: w.writerow([r["id"], r["name_ar"], r["name_en"], r["created_2026"], r["total_in_category"], round(100*r["created_2026"]/sum26, 2) if sum26 else 0])
except Exception:
    log(traceback.format_exc())
LOG.close()
