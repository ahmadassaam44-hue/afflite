#!/usr/bin/env python3
"""Two jobs:
A) Sub-category breakdown of parent 143 (Home & garden tools) for 2026 products.
B) Full scrape of Women's Clothing (sub-category 83) products created in 2026.
Method (validated): created-in-2026 <=> id >= first-2026 product id (auto-increment).
"""
import concurrent.futures, csv, json, time, traceback, urllib.request

HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
LOG = open("cezma_next_log.txt", "w", encoding="utf-8")
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
def cat_total(cid):
    return int(get(f"{BASE}?page=1&per_page=1&category_id={cid}")["item_count"])
def scan_pages(cid, per=500):
    """yield all items of a category, concurrently"""
    total = cat_total(cid); pages = (total - 1) // per + 1 if total else 0
    def one(p):
        return get(f"{BASE}?page={p}&per_page={per}&category_id={cid}")["data"]
    items = []
    if pages:
        with concurrent.futures.ThreadPoolExecutor(max_workers=24) as ex:
            for data in ex.map(one, range(1, pages + 1)): items.extend(data)
    return total, items

try:
    j1 = get(f"{BASE}?page=1&per_page=1"); total_global = int(j1["item_count"])
    def item_at(pos): return get(f"{BASE}?page={pos}&per_page=1")["data"][0]
    lo, hi = 1, total_global
    while lo < hi:
        mid = (lo + hi) // 2
        if detail_date(int(item_at(mid)["id"])) >= "2026-01-01": hi = mid
        else: lo = mid + 1
    THRESH = int(item_at(lo)["id"])
    log(f"threshold id={THRESH} global2026={total_global-lo+1}")

    # ---- A) Home & garden subcategories ----
    cats = get("https://api.cezma.cloud/api/categories")["data"]
    parent = next(c for c in cats if c["id"] == 143)
    subs = [{"id": c["id"], "name_ar": c.get("name_ar") or "", "name_en": c.get("name_en") or ""} for c in parent.get("children", [])]
    log(f"A) {parent.get('name_ar')}: {len(subs)} subcategories")
    resA = []
    for c in subs:
        total, items = scan_pages(c["id"])
        n26 = sum(1 for d in items if int(d["id"]) >= THRESH)
        resA.append({**c, "total_in_subcategory": total, "created_2026": n26})
        log(f"  {c['id']} {c['name_ar']}: total={total} 2026={n26}")
    resA.sort(key=lambda r: -r["created_2026"])
    sumA = sum(r["created_2026"] for r in resA)
    with open("cezma_home_garden_sub_2026.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f); w.writerow(["subcategory_id", "subcategory_ar", "subcategory_en", "products_created_2026", "total_products_in_subcategory", "pct"])
        for r in resA: w.writerow([r["id"], r["name_ar"], r["name_en"], r["created_2026"], r["total_in_subcategory"], round(100*r["created_2026"]/sumA, 2) if sumA else 0])
    log(f"A) sum subcategories 2026={sumA}")

    # ---- B) Women's clothing full scrape ----
    total83, items83 = scan_pages(83)
    rows = [d for d in items83 if int(d["id"]) >= THRESH]
    rows.sort(key=lambda d: int(d["id"]))
    log(f"B) women clothing: category total={total83} scraped 2026={len(rows)}")
    def clean(v): return "" if v is None else str(v).replace("\r", " ").replace("\n", " ").strip()
    with open("cezma_women_clothing_2026.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["id", "name_ar", "name_en", "price", "price_before_discount", "discount", "store_name", "store_username", "store_id", "image", "views", "rating", "is_available", "quantity", "product_url"])
        for r in rows:
            st = r.get("store") or {}; user = clean(st.get("username"))
            w.writerow([r.get("id"), clean(r.get("name_ar")), clean(r.get("name_en")), r.get("price"), r.get("basic_price"), r.get("discount"),
                        clean(st.get("name")), user, st.get("id"), clean(r.get("image")), r.get("views"), r.get("ratings_avg"),
                        r.get("is_available"), r.get("quantity"), f"https://cezma.com/products/{user}/{r.get('id')}"])
    out = {"threshold_id": THRESH, "home_garden_sub_2026_sum": sumA, "home_garden_subcategories": resA,
           "women_clothing_scraped_2026": len(rows)}
    with open("cezma_next_summary.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
    log(json.dumps({"home_garden_sum26": sumA, "women_scraped": len(rows)}, ensure_ascii=False))
except Exception:
    log(traceback.format_exc())
LOG.close()
