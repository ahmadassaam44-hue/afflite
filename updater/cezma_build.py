#!/usr/bin/env python3
"""Build the 2026-only Cezma catalog shards from cezma_filtered_2025_2026.csv.
Products created before 2026 (id < MIN_ID) are skipped: the 2025 catalog was
deleted on purpose and must not be rebuilt from the historical scrape file.
Writes data/cezma_shards/lite_cezma_XX.json (~6,000 products each, <10MB so the
FTP deployer accepts them). Site loader merges shards (config.php).
Full details (gallery + description) are enriched on demand from Cezma's detail
API per product page (cezma_fetch_detail in config.php).
"""
import csv, json, os
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "cezma_filtered_2025_2026.csv"); DATA = os.path.join(ROOT, "data")
SHARD_DIR = os.path.join(DATA, "cezma_shards"); PER_SHARD = 6000
MIN_PRICE_EGP = 100.0
MIN_ID = 509251  # أول منتج اتعمل في 2026 — نفس CEZMA_MIN_ID في updater/update_catalog.py
def num(v):
    try: return float(v)
    except Exception: return 0
def image_urls(r):
    out = []
    def add(value):
        if isinstance(value, str):
            value = value.strip()
            if value.startswith("[") or value.startswith("{"):
                try: add(json.loads(value)); return
                except Exception: pass
            if value.startswith(("http://", "https://")) and value not in out: out.append(value)
        elif isinstance(value, dict):
            for key in ("image", "image_url", "url", "src", "path", "original", "full", "thumbnail", "images", "gallery", "photos", "data", "items"):
                if key in value: add(value[key])
        elif isinstance(value, (list, tuple)):
            for item in value: add(item)
    # Keep the primary API image first, then retain any gallery column if present.
    add(r.get("image"))
    for key in ("images_json", "images", "gallery_json", "gallery", "photos"):
        if key in r: add(r.get(key))
    return out
def build_rows():
    with open(SRC, encoding="utf-8-sig", newline="") as source:
        rows = list(csv.DictReader(source))
    lite = []
    for r in rows:
        try: pid = int(r["id"])
        except (TypeError, ValueError): continue
        if pid < MIN_ID:   # منتجات 2025 (وأقدم) محذوفة من الكتالوج
            continue
        price = num(r["price"])
        if price < MIN_PRICE_EGP:
            continue
        old = num(r["price_before_discount"]) or price
        imgs = image_urls(r)
        lite.append({
            "id": f"cezma-{r['id']}", "orig_id": str(r["id"]), "name": r["name_ar"] or r["name_en"],
            "price": price, "old_price": old if old > price else 0, "discount": num(r["discount"]),
            "price_text": f"{price:g} ج.م", "currency": "ج.م", "country": "مصر",
            "category": r["category_ar"] or "", "stock": 999999, "images": imgs,
            "extra": {"cezma_id": int(r["id"]), "store_name": r["store_name"],
                      "cost": f"{price:g} ج.م", "source_commission_original": "0 ج.م"}})
    return lite
def write_shards(lite):
    os.makedirs(SHARD_DIR, exist_ok=True)
    def keep(r):
        if num(r.get("price")) < MIN_PRICE_EGP: return False
        oid = str(r.get("orig_id") or "")
        return not (oid.isdigit() and int(oid) < MIN_ID)   # مفيش منتجات 2025 في الشظايا
    lite = [r for r in lite if keep(r)]
    for old in os.listdir(SHARD_DIR):
        if old.startswith("lite_cezma_"): os.remove(os.path.join(SHARD_DIR, old))
    n = 0
    for i in range(0, len(lite), PER_SHARD):
        n += 1; p = os.path.join(SHARD_DIR, f"lite_cezma_{n:02d}.json")
        with open(p, "w", encoding="utf-8") as f: json.dump(lite[i:i + PER_SHARD], f, ensure_ascii=False, separators=(",", ":"))
        print(os.path.basename(p), f"{os.path.getsize(p)/1e6:.1f} MB")
    return n
def main():
    lite = build_rows(); n = write_shards(lite)
    idx_path = os.path.join(DATA, "id_index.json")
    idx = json.load(open(idx_path, encoding="utf-8")) if os.path.exists(idx_path) else {}
    idx = {key: plat for key, plat in idx.items() if plat != "cezma"}
    for it in lite: idx[it["id"]] = "cezma"
    with open(idx_path, "w", encoding="utf-8") as f: json.dump(idx, f, ensure_ascii=False, separators=(",", ":"))
    print(f"shards={n} products={len(lite)} id_index={len(idx)}")
if __name__ == "__main__": main()
