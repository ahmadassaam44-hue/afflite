#!/usr/bin/env python3
"""Sub-category breakdown of 'الموضة والجمال' (id=5) for products created in 2026.
Same validated method: count, inside each sub-category list, products with
id >= first-2026 product ID (auto-increment => created in 2026).
"""
import concurrent.futures, csv, json, time, traceback, urllib.request

HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
PARENT = 5
LOG = open("cezma_fashion_sub_log.txt", "w", encoding="utf-8")
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
    j1 = get(f"{BASE}?page=1&per_page=1"); total_global = int(j1["item_count"])
    def item_at(pos):
        return get(f"{BASE}?page={pos}&per_page=1")["data"][0]
    lo, hi = 1, total_global
    while lo < hi:
        mid = (lo + hi) // 2
        if detail_date(int(item_at(mid)["id"])) >= "2026-01-01": hi = mid
        else: lo = mid + 1
    THRESH = int(item_at(lo)["id"])
    log(f"threshold first-2026 id={THRESH} (global 2026={total_global-lo+1})")

    cats = get("https://api.cezma.cloud/api/categories")["data"]
    parent = next(c for c in cats if c["id"] == PARENT)
    subs = [{"id": c["id"], "name_ar": c.get("name_ar") or "", "name_en": c.get("name_en") or ""} for c in parent.get("children", [])]
    log(f"parent: {parent.get('name_ar')} | subcategories: {len(subs)}")
    PER = 500
    totals = {}
    for c in subs:
        totals[c["id"]] = int(get(f"{BASE}?page=1&per_page=1&category_id={c['id']}")["item_count"])
    log("subs total sum:", sum(totals.values()), "| parent total:", int(get(f"{BASE}?page=1&per_page=1&category_id={PARENT}")["item_count"]))

    def scan(cid):
        total = totals[cid]; n26 = scanned = 0
        pages = (total - 1) // PER + 1 if total else 0
        def one(p):
            j = get(f"{BASE}?page={p}&per_page={PER}&category_id={cid}")
            ids = [int(d["id"]) for d in j["data"]]
            return len(ids), sum(1 for i in ids if i >= THRESH)
        if pages:
            with concurrent.futures.ThreadPoolExecutor(max_workers=16) as ex:
                for n, k in ex.map(one, range(1, pages + 1)): scanned += n; n26 += k
        return scanned, n26

    results = []
    for c in subs:
        sc, n26 = scan(c["id"])
        results.append({**c, "total_in_subcategory": totals[c["id"]], "created_2026": n26})
        log(f" {c['id']} {c['name_ar']}: total={totals[c['id']]} 2026={n26}")
    results.sort(key=lambda r: -r["created_2026"])
    sum26 = sum(r["created_2026"] for r in results)
    out = {"parent_id": PARENT, "parent_name_ar": parent.get("name_ar"), "threshold_id": THRESH,
           "sum_2026_in_subcategories": sum26, "subcategories": results}
    log(json.dumps(out, ensure_ascii=False, indent=1))
    with open("cezma_fashion_sub_2026.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
    with open("cezma_fashion_sub_2026.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f); w.writerow(["subcategory_id", "subcategory_ar", "subcategory_en", "products_created_2026", "total_products_in_subcategory", "pct_of_fashion_2026"])
        for r in results: w.writerow([r["id"], r["name_ar"], r["name_en"], r["created_2026"], r["total_in_subcategory"], round(100*r["created_2026"]/sum26, 2) if sum26 else 0])
except Exception:
    log(traceback.format_exc())
LOG.close()
