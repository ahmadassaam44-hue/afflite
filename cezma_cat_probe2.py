#!/usr/bin/env python3
import json, time, urllib.request, traceback
HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
BASE = "https://api.cezma.cloud/api/products"
LOG = open("cezma_cat_probe2_log.txt", "w", encoding="utf-8")
def log(*a):
    s=" ".join(str(x) for x in a); print(s, flush=True); LOG.write(s+"\n"); LOG.flush()
def get(url, tries=4):
    last=None
    for i in range(tries):
        try:
            req=urllib.request.Request(url, headers=HDR)
            with urllib.request.urlopen(req, timeout=90) as r: return json.loads(r.read().decode("utf-8","replace"))
        except Exception as e: last=e; time.sleep(2)
    raise last
def item(cat,pos):
    j=get(f"{BASE}?page={pos}&per_page=1&category_id={cat}")
    return j["data"][0], int(j["item_count"])
def ddate(pid):
    d=get(f"{BASE}/{pid}"); return str(((d.get("data") or {}).get("product") or {}).get("date") or "")
try:
    for cat in [143, 5, 9]:
        _, total = item(cat,1)
        log(f"\ncategory {cat} total={total}")
        for pos in [1,2,3, total//4, total//2, (3*total)//4, total-2, total-1, total]:
            it,_=item(cat,pos); log(f" pos={pos} id={it['id']} created={ddate(int(it['id']))}")
except Exception: log(traceback.format_exc())
LOG.close()
