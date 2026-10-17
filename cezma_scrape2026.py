#!/usr/bin/env python3
"""Scrape ALL Cezma products created in 2026 (full list) via official API.
1) Binary-search first 2026 position (detail dates, existing products only).
2) Page through the list API from there to the end (per_page=100, concurrent).
Writes cezma_2026_full.csv (+ summary json). Log: cezma_scrape_log.txt
"""
import concurrent.futures, csv, json, time, traceback, urllib.request

HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
LOG = open("cezma_scrape_log.txt", "w", encoding="utf-8")
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
def list_page(page, per=100):
    j = get(f"{BASE}?page={page}&per_page={per}")
    return j["data"], int(j["item_count"])
def list_item(pos):
    j = get(f"{BASE}?page={pos}&per_page=1")
    return j["data"][0], int(j["item_count"])
def detail_date(pid):
    d = get(f"{BASE}/{pid}")
    return str(((d.get("data") or {}).get("product") or {}).get("date") or "")

try:
    _, total = list_item(1)
    log("total existing:", total)
    lo, hi = 1, total
    while lo < hi:
        mid = (lo + hi) // 2
        it, _ = list_item(mid); dt = detail_date(int(it["id"]))
        if dt >= "2026-01-01": hi = mid
        else: lo = mid + 1
    start_pos = lo
    first_it, _ = list_item(start_pos)
    log(f"2026 starts at position {start_pos} id={first_it['id']} date={detail_date(int(first_it['id']))}")
    count_expected = total - start_pos + 1
    log("expected 2026 count:", count_expected)

    PER = 100
    first_page = (start_pos - 1) // PER + 1
    last_page = (total - 1) // PER + 1
    pages = list(range(first_page, last_page + 1))
    log(f"fetching list pages {first_page}..{last_page} ({len(pages)} pages)")
    items_by_pos = {}
    done = 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=24) as ex:
        futs = {ex.submit(list_page, p, PER): p for p in pages}
        for fut in concurrent.futures.as_completed(futs):
            p = futs[fut]
            data, _ = fut.result()
            for i, d in enumerate(data):
                items_by_pos[(p - 1) * PER + i + 1] = d
            done += 1
            if done % 50 == 0: log(f" pages done {done}/{len(pages)}")
    rows = [items_by_pos[p] for p in sorted(items_by_pos) if p >= start_pos]
    log("rows collected:", len(rows))
    sample_cat = [ (r.get("category") or {}) for r in rows[:5] ]
    log("sample categories:", json.dumps(sample_cat, ensure_ascii=False)[:300])

    def clean(v):
        return "" if v is None else str(v).replace("\r", " ").replace("\n", " ").strip()
    with open("cezma_2026_full.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["id", "name_ar", "name_en", "price", "price_before_discount", "discount",
                    "store_name", "store_username", "store_id", "category_name_ar", "category_name_en",
                    "image", "views", "rating", "is_available", "quantity", "product_url"])
        for r in rows:
            st = r.get("store") or {}; cat = r.get("category") or {}
            user = clean(st.get("username"))
            w.writerow([r.get("id"), clean(r.get("name_ar")), clean(r.get("name_en")),
                        r.get("price"), r.get("basic_price"), r.get("discount"),
                        clean(st.get("name")), user, st.get("id"),
                        clean(cat.get("name_ar")), clean(cat.get("name_en")),
                        clean(r.get("image")), r.get("views"), r.get("ratings_avg"),
                        r.get("is_available"), r.get("quantity"),
                        f"https://cezma.com/products/{user}/{r.get('id')}" if user else f"https://cezma.com/products/-/{r.get('id')}"])
    out = {"total_existing_products": total, "first_2026_position": start_pos,
           "expected_2026": count_expected, "scraped_2026_rows": len(rows),
           "first_id": rows[0]["id"] if rows else None, "last_id": rows[-1]["id"] if rows else None}
    log(json.dumps(out, ensure_ascii=False, indent=1))
    with open("cezma_scrape_summary.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
except Exception:
    log(traceback.format_exc())
LOG.close()
