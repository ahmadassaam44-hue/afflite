#!/usr/bin/env python3
"""Apply user's deletions to cezma_both_2025_2026_full.csv (133,043):
- delete 17 whole main categories
- parent 364 (gifts & handmade): delete handmade subcats, keep gifts ones
- parent 8 (furniture & decor): keep decor subcats only (24 home decoration,
  360 mirrors/glass, 623 wall clocks), delete the rest
Output: cezma_filtered_2025_2026.csv + cezma_filter_summary.json
"""
import concurrent.futures, csv, json, time, traceback, urllib.request
from collections import Counter

HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
LOG = open("cezma_filter_log.txt", "w", encoding="utf-8")
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
def sub_ids_in_file(cid, file_ids):
    total = int(get(f"{BASE}?page=1&per_page=1&category_id={cid}")["item_count"])
    pages = (total - 1) // 500 + 1 if total else 0
    def one(p): return get(f"{BASE}?page={p}&per_page=500&category_id={cid}")["data"]
    ids = set()
    if pages:
        with concurrent.futures.ThreadPoolExecutor(max_workers=16) as ex:
            for data in ex.map(one, range(1, pages + 1)):
                ids.update(int(d["id"]) for d in data)
    return ids & file_ids

DELETE_MAIN = {614: "الطب التكميلي", 134: "الطباعة والتغليف", 421: "زراعة", 145: "السياحة والسفر",
               140: "حيوانات المزرعة ومستلزماتها", 136: "المواد الخام", 674: "عروض مجمعة", 22: "الفنون والحرف",
               413: "اكلات ومشروبات", 101: "الاسماك والحيوانات الاليفة ومستلزماتها", 16: "تجارة وصناعة",
               33: "عقارات وأراضي", 137: "المستلزمات والمعدات المهنية", 20: "خدمات", 28: "سيارات وقطع غيار",
               536: "منسوجات المنزل", 141: "منتجات طبيعية"}
HANDMADE_SUBS = [432, 367, 366, 517, 555, 548, 368, 650, 549, 556, 547]
DECOR_KEEP_SUBS = [24, 360, 623]
FURNITURE_PARENT, GIFTS_PARENT = 8, 364

try:
    rows = list(csv.DictReader(open("cezma_both_2025_2026_full.csv", encoding="utf-8-sig")))
    fieldnames = list(rows[0].keys())
    file_ids = set(int(r["id"]) for r in rows)
    log(f"before: {len(rows)}")

    delete_ids, reasons = set(), {}
    # whole categories straight from the file itself
    cnt_main = Counter(int(r["category_id"]) for r in rows)
    for cid, name in DELETE_MAIN.items():
        ids = {int(r["id"]) for r in rows if int(r["category_id"]) == cid}
        delete_ids |= ids; reasons[f"قسم كامل: {name}"] = len(ids)
        log(f" delete main {cid} {name}: {len(ids)} (file had {cnt_main.get(cid)})")
    # handmade subs
    hm = set()
    for cid in HANDMADE_SUBS:
        s = sub_ids_in_file(cid, file_ids); hm |= s; log(f" handmade sub {cid}: {len(s)}")
    delete_ids |= hm; reasons["مشغولات يدوية (من قسم الهدايا والمشغولات)"] = len(hm)
    # furniture: all parent-8 subs except decor-keep
    cats = get("https://api.cezma.cloud/api/categories")["data"]
    parent8 = next(c for c in cats if c["id"] == FURNITURE_PARENT)
    furn = set()
    for c in parent8.get("children", []):
        if c["id"] in DECOR_KEEP_SUBS: continue
        s = sub_ids_in_file(c["id"], file_ids); furn |= s; log(f" furniture sub {c['id']} {c.get('name_ar')}: {len(s)}")
    delete_ids |= furn; reasons["أثاث (من قسم اثاث وديكور - سيبنا الديكور فقط)"] = len(furn)
    decor_kept = sum(1 for r in rows if int(r["category_id"]) == FURNITURE_PARENT) - len(furn)
    gifts_kept = sum(1 for r in rows if int(r["category_id"]) == GIFTS_PARENT) - len(hm)
    log(f"kept from furniture&decor (decor): {decor_kept} | kept from gifts&handmade (gifts): {gifts_kept}")

    kept_rows = [r for r in rows if int(r["id"]) not in delete_ids]
    with open("cezma_filtered_2025_2026.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=fieldnames); w.writeheader(); w.writerows(kept_rows)
    out = {"before": len(rows), "removed_total": len(rows) - len(kept_rows), "removed_breakdown": reasons,
           "kept_gifts_only_from_gifts_handmade": gifts_kept, "kept_decor_only_from_furniture_decor": decor_kept,
           "remaining": len(kept_rows)}
    log(json.dumps(out, ensure_ascii=False, indent=1))
    with open("cezma_filter_summary.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
except Exception:
    log(traceback.format_exc())
LOG.close()
