#!/usr/bin/env python3
"""Full scrape of products POSTED and EDITED in 2025+2026.
edited = id in sitemap with lastmod 2025/2026; posted = id >= first-2025 id.
Scanning per main category also gives each product's category.
Output: cezma_both_2025_2026_full.csv + summary json (with per-category counts).
"""
import concurrent.futures, csv, json, re, time, traceback, urllib.request

HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
LOG = open("cezma_scrape_both_log.txt", "w", encoding="utf-8")
def log(*a):
    s = " ".join(str(x) for x in a); print(s, flush=True); LOG.write(s + "\n"); LOG.flush()
def get_raw(url, tries=5, headers=None):
    last = None
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers=headers or HDR)
            with urllib.request.urlopen(req, timeout=120) as r: return r.read().decode("utf-8", "replace")
        except Exception as e: last = e; time.sleep(2 + i * 2)
    raise last
def get(url, **kw): return json.loads(get_raw(url, **kw))
def detail_date(pid):
    d = get(f"{BASE}/{pid}")
    return str(((d.get("data") or {}).get("product") or {}).get("date") or "")

try:
    # 1) edited ids from sitemap (gentle + retry passes)
    index = get_raw("https://cezma.com/sitemap.xml", headers={"User-Agent": "Mozilla/5.0"})
    files = sorted(set(re.findall(r"sitemap-products/(\d+)\.xml", index)), key=int)
    log(f"sitemap files {len(files)}")
    def one(n):
        try: xml = get_raw(f"https://cezma.com/sitemap-products/{n}.xml", tries=7, headers={"User-Agent": "Mozilla/5.0"})
        except Exception as e: log(f" file {n} failed: {e}"); return n, []
        urls = re.findall(r"<loc>(.*?)</loc>", xml); mods = re.findall(r"<lastmod>(.*?)</lastmod>", xml)
        out = []
        for i, u in enumerate(urls):
            m = re.search(r"/products/[^/]+/(\d+)/", u)
            if m and i < len(mods) and mods[i][:4] in ("2025", "2026"): out.append(int(m.group(1)))
        return n, out
    edited = set(); failed = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=12) as ex:
        for n, ids in ex.map(one, files):
            if not ids: failed.append(n)
            edited.update(ids)
    for attempt in range(3):
        if not failed: break
        log(f"retry pass {attempt+1}: {len(failed)} files")
        still = []
        for n in failed:
            _, ids = one(n)
            if not ids: still.append(n)
            edited.update(ids); time.sleep(0.5)
        failed = still
    log(f"edited(2025/2026) ids={len(edited)} missing files={len(failed)}")

    # 2) first-2025 threshold
    total_global = int(get(f"{BASE}?page=1&per_page=1")["item_count"])
    def item_at(pos): return get(f"{BASE}?page={pos}&per_page=1")["data"][0]
    lo, hi = 1, total_global
    while lo < hi:
        mid = (lo + hi) // 2
        if detail_date(int(item_at(mid)["id"])) >= "2025-01-01": hi = mid
        else: lo = mid + 1
    THRESH = int(item_at(lo)["id"])
    log(f"threshold first-2025 id={THRESH} total={total_global}")

    # 3) scan categories, keep matching products
    cats = get("https://api.cezma.cloud/api/categories")["data"]
    mains = [{"id": c["id"], "name_ar": c.get("name_ar") or "", "name_en": c.get("name_en") or ""} for c in cats]
    PER = 500
    totals = {c["id"]: int(get(f"{BASE}?page=1&per_page=1&category_id={c['id']}")["item_count"]) for c in mains}
    def scan_cat(c):
        total = totals[c["id"]]; pages = (total - 1) // PER + 1 if total else 0; kept = []
        def one_page(p): return get(f"{BASE}?page={p}&per_page={PER}&category_id={c['id']}")["data"]
        if pages:
            with concurrent.futures.ThreadPoolExecutor(max_workers=16) as ex:
                for data in ex.map(one_page, range(1, pages + 1)):
                    for d in data:
                        if int(d["id"]) >= THRESH and int(d["id"]) in edited: kept.append(d)
        return c, kept
    all_rows, per_cat = [], {}
    for c in mains:
        _, kept = scan_cat(c)
        per_cat[c["id"]] = len(kept)
        for d in kept: d["_cat"] = c
        all_rows.extend(kept)
        log(f" cat {c['id']} {c['name_ar']}: kept {len(kept)} (cum {len(all_rows)})")
    all_rows.sort(key=lambda d: int(d["id"]))
    log(f"TOTAL scraped both-2025-2026: {len(all_rows)}")

    def clean(v): return "" if v is None else str(v).replace("\r", " ").replace("\n", " ").strip()
    with open("cezma_both_2025_2026_full.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["id", "name_ar", "name_en", "price", "price_before_discount", "discount", "store_name", "store_username", "store_id", "category_ar", "category_en", "category_id", "image", "views", "rating", "is_available", "quantity", "product_url"])
        for r in all_rows:
            st = r.get("store") or {}; user = clean(st.get("username")); cat = r["_cat"]
            w.writerow([r.get("id"), clean(r.get("name_ar")), clean(r.get("name_en")), r.get("price"), r.get("basic_price"), r.get("discount"),
                        clean(st.get("name")), user, st.get("id"), cat["name_ar"], cat["name_en"], cat["id"], clean(r.get("image")),
                        r.get("views"), r.get("ratings_avg"), r.get("is_available"), r.get("quantity"),
                        f"https://cezma.com/products/{user}/{r.get('id')}"])
    out = {"threshold_first_2025_id": THRESH, "edited_ids_2025_2026": len(edited), "missing_sitemap_files": len(failed),
           "scraped_posted_and_edited_2025_2026": len(all_rows),
           "per_category": [{"id": c["id"], "name_ar": c["name_ar"], "count": per_cat[c["id"]]} for c in sorted(mains, key=lambda c: -per_cat[c["id"]])]}
    with open("cezma_scrape_both_summary.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
    log(json.dumps(out, ensure_ascii=False)[:800])
except Exception:
    log(traceback.format_exc())
LOG.close()
