#!/usr/bin/env python3
"""Products posted AND edited in 2025+2026 (and related totals).
- edited in year Y: product id appears in sitemap with lastmod in Y (scan all sitemap files)
- posted in 2025+: id >= first product id created in 2025 (API detail-date binary search)
- existing products: full global list scan (ids only) to intersect the sets exactly.
"""
import concurrent.futures, json, re, time, traceback, urllib.request

HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
LOG = open("cezma_2025plus_log.txt", "w", encoding="utf-8")
def log(*a):
    s = " ".join(str(x) for x in a); print(s, flush=True); LOG.write(s + "\n"); LOG.flush()
def get_text(url, tries=4):
    last = None
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0", "Platform": "web"})
            with urllib.request.urlopen(req, timeout=120) as r: return r.read().decode("utf-8", "replace")
        except Exception as e: last = e; time.sleep(2)
    raise last
def get(url, tries=5):
    return json.loads(get_text(url, tries))
def detail_date(pid):
    d = get(f"{BASE}/{pid}")
    return str(((d.get("data") or {}).get("product") or {}).get("date") or "")

try:
    # --- edited sets from sitemap ---
    index = get_text("https://cezma.com/sitemap.xml")
    files = sorted(set(re.findall(r"sitemap-products/(\d+)\.xml", index)), key=int)
    log(f"sitemap files: {len(files)}")
    def one(n):
        try:
            xml = get_text(f"https://cezma.com/sitemap-products/{n}.xml", tries=7)
        except Exception as e:
            log(f" sitemap file {n} FAILED (skipped): {e}")
            return []
        urls = re.findall(r"<loc>(.*?)</loc>", xml); mods = re.findall(r"<lastmod>(.*?)</lastmod>", xml)
        out = []
        for i, u in enumerate(urls):
            m = re.search(r"/products/[^/]+/(\d+)/", u)
            if m and i < len(mods): out.append((int(m.group(1)), mods[i][:4]))
        return out
    edited = {"2025": set(), "2026": set()}
    failed = []
    def absorb(n, rows):
        if not rows and n: failed.append(n)
        for pid, y in rows:
            if y in edited: edited[y].add(pid)
    with concurrent.futures.ThreadPoolExecutor(max_workers=12) as ex:
        for n, rows in zip(files, ex.map(one, files)): absorb(n, rows)
    for attempt in range(3):
        if not failed: break
        log(f"retry pass {attempt+1} for {len(failed)} failed sitemap files (gentle)")
        still = []
        for n in failed:
            rows = one(n)
            if not rows: still.append(n)
            else:
                for pid, y in rows:
                    if y in edited: edited[y].add(pid)
            time.sleep(0.5)
        failed = still
    log(f"sitemap files still missing after retries: {len(failed)} {failed[:10]}")
    log(f"edited ids: 2025={len(edited['2025'])} 2026={len(edited['2026'])}")
    edited_both_years = edited["2025"] | edited["2026"]

    # --- first-2025 threshold ---
    j1 = get(f"{BASE}?page=1&per_page=1"); total = int(j1["item_count"])
    def item_at(pos): return get(f"{BASE}?page={pos}&per_page=1")["data"][0]
    lo, hi = 1, total
    while lo < hi:
        mid = (lo + hi) // 2
        if detail_date(int(item_at(mid)["id"])) >= "2025-01-01": hi = mid
        else: lo = mid + 1
    T2025 = int(item_at(lo)["id"]); pos2025 = lo
    log(f"first 2025: pos={pos2025} id={T2025} date={detail_date(T2025)} total_existing={total}")

    # --- scan all existing product ids (global list, per_page=500) ---
    PER = 500; pages = (total - 1) // PER + 1
    def page_ids(p):
        j = get(f"{BASE}?page={p}&per_page={PER}")
        return [int(d["id"]) for d in j["data"]]
    all_ids = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=32) as ex:
        for ids in ex.map(page_ids, range(1, pages + 1)): all_ids.extend(ids)
    log(f"existing ids scanned: {len(all_ids)}")
    posted_25_26 = [i for i in all_ids if i >= T2025]
    both = [i for i in posted_25_26 if i in edited_both_years]
    edited_existing = [i for i in all_ids if i in edited_both_years]
    out = {
        "total_existing_products": total,
        "first_2025_product_id": T2025,
        "posted_in_2025_and_2026": len(posted_25_26),
        "edited_in_2025_or_2026_sitemap_ids": len(edited_both_years),
        "edited_in_2025_or_2026_and_still_existing": len(edited_existing),
        "posted_AND_edited_in_2025_2026": len(both),
        "edited_only_2025_ids": len(edited["2025"]), "edited_only_2026_ids": len(edited["2026"]),
    }
    log(json.dumps(out, ensure_ascii=False, indent=1))
    with open("cezma_2025plus_result.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
except Exception:
    log(traceback.format_exc())
LOG.close()
