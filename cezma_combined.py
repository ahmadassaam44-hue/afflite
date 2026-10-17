#!/usr/bin/env python3
"""Categories of the combined ~110k: products created in 2026 + products updated in 2026.
- created-2026: id >= first-2026 id (validated method)
- updated-2026: product ids in cezma_2026_products.csv (sitemap lastmod in 2026)
Per category we count: created, updated, and union (no double counting).
"""
import concurrent.futures, csv, json, time, traceback, urllib.request

HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
LOG = open("cezma_combined_log.txt", "w", encoding="utf-8")
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
    updated_ids = set()
    with open("cezma_2026_products.csv", encoding="utf-8") as f:
        for r in csv.DictReader(f): updated_ids.add(int(r["id"]))
    log(f"updated-in-2026 ids from sitemap: {len(updated_ids)}")

    j1 = get(f"{BASE}?page=1&per_page=1"); total_global = int(j1["item_count"])
    def item_at(pos): return get(f"{BASE}?page={pos}&per_page=1")["data"][0]
    lo, hi = 1, total_global
    while lo < hi:
        mid = (lo + hi) // 2
        if detail_date(int(item_at(mid)["id"])) >= "2026-01-01": hi = mid
        else: lo = mid + 1
    THRESH = int(item_at(lo)["id"]); global_created = total_global - lo + 1
    log(f"threshold id={THRESH} global created2026={global_created} total={total_global}")

    cats = get("https://api.cezma.cloud/api/categories")["data"]
    mains = [{"id": c["id"], "name_ar": c.get("name_ar") or "", "name_en": c.get("name_en") or ""} for c in cats]
    PER = 500
    totals = {c["id"]: int(get(f"{BASE}?page=1&per_page=1&category_id={c['id']}")["item_count"]) for c in mains}
    tasks = [(c["id"], p) for c in mains for p in range(1, (totals[c["id"]] - 1) // PER + 2)]
    log(f"scanning {len(tasks)} pages over {len(mains)} categories")
    agg = {c["id"]: [0, 0, 0, 0] for c in mains}  # total, created, updated, union
    def one(task):
        cid, p = task
        j = get(f"{BASE}?page={p}&per_page={PER}&category_id={cid}")
        ids = [int(d["id"]) for d in j["data"]]
        return cid, len(ids), sum(i >= THRESH for i in ids), sum(i in updated_ids for i in ids), sum((i >= THRESH) or (i in updated_ids) for i in ids)
    done = 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=32) as ex:
        for cid, n, cr, up, un in ex.map(one, tasks):
            a = agg[cid]; a[0] += n; a[1] += cr; a[2] += up; a[3] += un
            done += 1
            if done % 200 == 0: log(f" pages {done}/{len(tasks)}")
    results = []
    for c in mains:
        t, cr, up, un = agg[c["id"]]
        results.append({**c, "total_in_category": totals[c["id"]], "created_2026": cr, "updated_2026": up, "union_2026": un})
    results.sort(key=lambda r: -r["union_2026"])
    sums = {k: sum(r[k] for r in results) for k in ("created_2026", "updated_2026", "union_2026")}
    log("SUMS: " + json.dumps(sums) + f" (created+updated={sums['created_2026']+sums['updated_2026']})")
    out = {"threshold_id": THRESH, "global_created_2026": global_created, "updated_ids_in_sitemap_file": len(updated_ids), "sums": sums, "categories": results}
    with open("cezma_combined_2026.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
    with open("cezma_combined_2026.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f); w.writerow(["category_id", "category_ar", "category_en", "created_2026_new", "updated_2026", "union_no_double_count", "sum_created_plus_updated", "total_products_in_category"])
        for r in results: w.writerow([r["id"], r["name_ar"], r["name_en"], r["created_2026"], r["updated_2026"], r["union_2026"], r["created_2026"] + r["updated_2026"], r["total_in_category"]])
except Exception:
    log(traceback.format_exc())
LOG.close()
