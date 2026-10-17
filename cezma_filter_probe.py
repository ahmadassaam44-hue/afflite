#!/usr/bin/env python3
"""Probe sub-categories of parents 364 (gifts & handmade) and 8 (furniture & decor):
for each sub-category, count how many of the 133,043 products in
cezma_both_2025_2026_full.csv belong to it. Log: cezma_filter_probe_log.txt
"""
import concurrent.futures, csv, json, time, traceback, urllib.request

HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
LOG = open("cezma_filter_probe_log.txt", "w", encoding="utf-8")
def log(*a):
    s = " ".join(str(x) for x in a); print(s, flush=True); LOG.write(s + "\n"); LOG.flush()
def get(url, tries=5):
    last = None
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers=HDR)
            with urllib.request.urlopen(req, timeout=120) as r: return json.loads(r.read().decode("utf-8", "replace"))
        except Exception as e: last = e; time.sleep(2 + i * 2)
    raise last

try:
    rows = list(csv.DictReader(open("cezma_both_2025_2026_full.csv", encoding="utf-8-sig")))
    file_ids = set(int(r["id"]) for r in rows)
    from collections import Counter
    per_main = Counter(int(r["category_id"]) for r in rows)
    log(f"file rows={len(rows)} unique ids={len(file_ids)}")
    log("per-main counts for target parents: 364=", per_main.get(364), " 8=", per_main.get(8))
    cats = get("https://api.cezma.cloud/api/categories")["data"]
    out = {}
    for parent_id in (364, 8):
        parent = next(c for c in cats if c["id"] == parent_id)
        subs = [{"id": c["id"], "name_ar": c.get("name_ar") or "", "name_en": c.get("name_en") or ""} for c in parent.get("children", [])]
        log(f"\nPARENT {parent_id} {parent.get('name_ar')} — {len(subs)} subcategories")
        res = []
        for c in subs:
            total = int(get(f"{BASE}?page=1&per_page=1&category_id={c['id']}")["item_count"])
            pages = (total - 1) // 500 + 1 if total else 0
            def one(p): return get(f"{BASE}?page={p}&per_page=500&category_id={c['id']}")["data"]
            ids = []
            if pages:
                with concurrent.futures.ThreadPoolExecutor(max_workers=16) as ex:
                    for data in ex.map(one, range(1, pages + 1)): ids.extend(int(d["id"]) for d in data)
            in_file = len(set(ids) & file_ids)
            res.append({**c, "total_in_subcategory": total, "in_133k_file": in_file})
            log(f"  sub {c['id']} {c['name_ar']} / {c['name_en']}: total={total} in_file={in_file}")
        out[str(parent_id)] = res
    with open("cezma_filter_probe.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
except Exception:
    log(traceback.format_exc())
LOG.close()
