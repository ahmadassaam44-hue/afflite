#!/usr/bin/env python3
"""Count Cezma products published in 2026.

Two independent methods:
1) Sitemap: https://cezma.com/sitemap-products/N.xml  (loc + lastmod per product)
2) API probe: https://api.cezma.cloud/api/products with various Platform headers,
   plus inspecting the Nuxt JS bundles to find the exact header/endpoint the site uses.
Results are printed and written to cezma_result.json / cezma_2026_products.csv
"""
import concurrent.futures, csv, json, re, sys, urllib.request, urllib.error

UA = {"User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126 Safari/537.36"}

def fetch(url, headers=None, timeout=30):
    req = urllib.request.Request(url, headers={**UA, **(headers or {})})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode("utf-8", "replace")

def main():
    out = {}

    # ---------- 1) Sitemap method ----------
    index = fetch("https://cezma.com/sitemap.xml")
    files = sorted(set(re.findall(r"sitemap-products/(\d+)\.xml", index)), key=int)
    print(f"sitemap product files: {len(files)} (1..{files[-1] if files else '-'})", flush=True)
    out["sitemap_files"] = len(files)

    def one(n):
        try:
            xml = fetch(f"https://cezma.com/sitemap-products/{n}.xml", timeout=60)
        except Exception as e:
            return n, [], str(e)
        urls = re.findall(r"<loc>(.*?)</loc>", xml)
        mods = re.findall(r"<lastmod>(.*?)</lastmod>", xml)
        rows = []
        for i, u in enumerate(urls):
            if "/products/" not in u:
                continue
            d = mods[i] if i < len(mods) else ""
            m = re.search(r"/products/([^/]+)/(\d+)/", u)
            rows.append({"store": m.group(1) if m else "", "id": int(m.group(2)) if m else None,
                         "url": u, "date": d})
        return n, rows, None

    all_rows, errors = [], []
    with concurrent.futures.ThreadPoolExecutor(max_workers=24) as ex:
        for n, rows, err in ex.map(one, files):
            if err: errors.append((n, err))
            all_rows.extend(rows)
    print(f"sitemap fetch errors: {len(errors)} {errors[:3]}", flush=True)

    # de-duplicate by product id/url
    seen, uniq = set(), []
    for r in all_rows:
        k = r["id"] or r["url"]
        if k not in seen:
            seen.add(k); uniq.append(r)
    by_year = {}
    for r in uniq:
        y = (r["date"] or "")[:4]
        by_year[y] = by_year.get(y, 0) + 1
    rows2026 = sorted([r for r in uniq if (r["date"] or "").startswith("2026")],
                      key=lambda r: (r["date"], r["id"] or 0))
    out.update(total_products_sitemap=len(uniq), by_year=by_year,
               count_2026_sitemap=len(rows2026),
               first_2026=rows2026[0] if rows2026 else None,
               last_2026=rows2026[-1] if rows2026 else None)
    print(json.dumps(out, ensure_ascii=False, indent=1), flush=True)
    with open("cezma_2026_products.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["id", "store", "date", "url"]); w.writeheader(); w.writerows(rows2026)

    # ---------- 2) API probe ----------
    print("\n=== API probe ===", flush=True)
    for plat in ["web", "Web", "WEB", "android", "ios", "website", "1", "2"]:
        for hdr in ["Platform", "platform", "X-Platform"]:
            try:
                body = fetch("https://api.cezma.cloud/api/products?page=1&per_page=2", {hdr: plat})
                j = json.loads(body)
                if j.get("status") or j.get("data"):
                    print(f"OK hdr={hdr} val={plat}: {body[:800]}", flush=True)
                    out.setdefault("api_ok", []).append({"hdr": hdr, "val": plat, "sample": body[:2000]})
                    # try to read pagination total + walk pages filtering 2026 if feasible
                    raise SystemExit_after_api(out, rows2026)
                else:
                    print(f"no  hdr={hdr} val={plat}: {body[:150]}", flush=True)
            except SystemExit:
                raise
            except Exception as e:
                print(f"err hdr={hdr} val={plat}: {e}", flush=True)
    # inspect Nuxt bundles for the header value the real site sends
    try:
        home = fetch("https://cezma.com/")
        js = sorted(set(re.findall(r"/_nuxt/[^\"']+\.js", home)))
        print(f"nuxt js files on homepage: {len(js)}", flush=True)
        for path in js[:40]:
            try:
                code = fetch("https://cezma.com" + path, timeout=30)
            except Exception:
                continue
            for m in re.finditer(r".{60}Platform.{60}", code):
                s = m.group(0)
                if "latform" in s: print(path, ">>", s.replace("\n", " ")[:140], flush=True)
            for m in re.finditer(r".{40}api\.cezma.{40}", code):
                print(path, "API>>", m.group(0).replace("\n", " ")[:120], flush=True)
    except Exception as e:
        print("bundle inspect err", e, flush=True)

    with open("cezma_result.json", "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    print("\nFINAL:", json.dumps({k: out[k] for k in out if k != 'api_ok'}, ensure_ascii=False), flush=True)

def SystemExit_after_api(out, rows2026):
    with open("cezma_result.json", "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    return SystemExit(0)

if __name__ == "__main__":
    main()
