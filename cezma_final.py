#!/usr/bin/env python3
"""Final accurate count of Cezma products created in 2026 (v2).
Binary search over EXISTING products (list ordered by id, per_page=1),
using detail API product.date as creation date. Deleted IDs are skipped
automatically because positions only contain existing products.
"""
import json, time, urllib.request, traceback
HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
LOG = open("cezma_final_log.txt", "w", encoding="utf-8")
def log(*a):
    s = " ".join(str(x) for x in a); print(s, flush=True); LOG.write(s + "\n"); LOG.flush()
def get(url, tries=4):
    last = None
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers=HDR)
            with urllib.request.urlopen(req, timeout=90) as r:
                return json.loads(r.read().decode("utf-8", "replace"))
        except Exception as e:
            last = e; log(f" retry {i+1} {url}: {e}"); time.sleep(3)
    raise last
def list_item(pos):
    j = get(f"https://api.cezma.cloud/api/products?page={pos}&per_page=1")
    return j["data"][0], int(j["item_count"])
def detail_date(pid):
    d = get(f"https://api.cezma.cloud/api/products/{pid}")
    p = (d.get("data") or {}).get("product") or {}
    return str(p.get("date") or "")
def date_at(pos):
    it, _ = list_item(pos)
    return detail_date(int(it["id"])), int(it["id"])
try:
    _, total = list_item(1)
    log("total existing products:", total)
    lo, hi = 1, total
    while lo < hi:
        mid = (lo + hi) // 2
        dt, pid = date_at(mid)
        log(f" probe pos={mid} id={pid} date={dt}")
        if dt >= "2026-01-01": hi = mid
        else: lo = mid + 1
    pos = lo
    dt_first, id_first = date_at(pos); dt_prev, id_prev = date_at(pos - 1)
    log(f"FIRST 2026: pos={pos} id={id_first} date={dt_first}")
    log(f"PREV:       pos={pos-1} id={id_prev} date={dt_prev}")
    for p in range(pos - 5, pos + 6):
        dt, pid = date_at(p); log(f" neigh pos={p} id={pid} date={dt}")
    count = total - pos + 1
    out = {"api_total_existing_products": total, "first_2026_position": pos,
           "first_2026_product_id": id_first, "first_2026_date": dt_first,
           "last_2025_product_id": id_prev, "last_2025_date": dt_prev,
           "count_products_created_in_2026": count}
    log(json.dumps(out, ensure_ascii=False, indent=1))
    with open("cezma_result_final.json", "w", encoding="utf-8") as f: json.dump(out, f, ensure_ascii=False, indent=1)
except Exception:
    log(traceback.format_exc())
LOG.close()
