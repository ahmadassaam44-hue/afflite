#!/usr/bin/env python3
import json, time, urllib.request, traceback
HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
LOG = open("cezma_cat_probe_log.txt", "w", encoding="utf-8")
def log(*a):
    s = " ".join(str(x) for x in a); print(s, flush=True); LOG.write(s + "\n"); LOG.flush()
def get(url, tries=3):
    last=None
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers=HDR)
            with urllib.request.urlopen(req, timeout=90) as r: return json.loads(r.read().decode("utf-8","replace"))
        except Exception as e: last=e; time.sleep(2)
    raise last
try:
    for url in ["https://api.cezma.cloud/api/categories",
                "https://api.cezma.cloud/api/main-categories",
                "https://api.cezma.cloud/api/main_categories",
                "https://api.cezma.cloud/api/home"]:
        try:
            j=get(url); log("\n==",url,"keys:",list(j.keys())[:10])
            log(json.dumps(j,ensure_ascii=False)[:1500])
        except Exception as e: log("\n==",url,"ERR",e)
    # test filters on products list (category 9 = mobiles from website URL)
    for q in ["main_category_id=9","category_id=9","main_category=9","category=9"]:
        try:
            j=get(f"https://api.cezma.cloud/api/products?per_page=2&{q}")
            ids=[d.get("id") for d in j.get("data",[])]
            log(f"\nFILTER {q}: item_count={j.get('item_count')} ids={ids}")
        except Exception as e: log(f"\nFILTER {q} ERR {e}")
except Exception: log(traceback.format_exc())
LOG.close()
