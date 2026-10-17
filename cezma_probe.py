#!/usr/bin/env python3
import json, urllib.request, traceback
HDR = {"User-Agent": "Mozilla/5.0", "Platform": "web", "Accept": "application/json"}
LOG = open("cezma_probe_log.txt", "w", encoding="utf-8")
def log(*a):
    s = " ".join(str(x) for x in a); print(s, flush=True); LOG.write(s + "\n"); LOG.flush()
def get(url):
    req = urllib.request.Request(url, headers=HDR)
    with urllib.request.urlopen(req, timeout=90) as r:
        return json.loads(r.read().decode("utf-8", "replace"))
try:
    j = get("https://api.cezma.cloud/api/products?page=1&per_page=1")
    item = j["data"][0]
    log("LIST item keys:", sorted(item.keys()))
    log("LIST item full:", json.dumps(item, ensure_ascii=False)[:4000])
    for pid in [509278, 92171, 607884]:
        for url in [f"https://api.cezma.cloud/api/products/{pid}",
                    f"https://api.cezma.cloud/api/product/{pid}"]:
            try:
                d = get(url)
                log(f"\nDETAIL {url} keys:", sorted((d.get('data') or {}).keys()) if isinstance(d.get('data'), dict) else type(d.get('data')))
                log("DETAIL body:", json.dumps(d, ensure_ascii=False)[:4000])
                break
            except Exception as e:
                log(f"DETAIL {url} ERR {e}")
except Exception:
    log(traceback.format_exc())
LOG.close()
