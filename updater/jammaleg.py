#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
جماليج (jammaleg.com) — جلب كتالوج المسوق بالعمولة
- تسجيل الدخول عبر POST https://jammaleg.com/api/login {phone, password} -> api_token (JWT)
- بعد الدخول يجلب التصنيفات والمنتجات مع الأسعار والعمولات والصور
- لو فشل أو الحساب محظور، يحاول السحب كضيف عبر الصفحة الرئيسية (بدون عمولة)
"""
import os, re, json, time, urllib.request, urllib.error, urllib.parse, http.cookiejar, ssl

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'data')
UA = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36', 'Accept-Language': 'ar,en;q=0.8', 'Accept': 'application/json, text/html,*/*'}

DEFAULT_EMAIL = 'ae782236@gmail.com'
DEFAULT_PASS = 'aA55667788'

API_BASE = 'https://jammaleg.com'
LOGIN_PATH = '/api/login'
PRODUCTS_PATH = '/api/products'
CATEGORIES_PATH = '/api/categories'
USER_PATH = '/api/user'

def _http_json(url, method='GET', data=None, headers=None, cookies=None):
    headers = dict(headers or {})
    headers.update(UA)
    body = None
    if data is not None:
        if isinstance(data, dict):
            body = json.dumps(data).encode('utf-8')
            headers['Content-Type'] = 'application/json'
        else:
            body = str(data).encode('utf-8')
    req = urllib.request.Request(url, data=body, headers=headers, method=method)
    ctx = ssl.create_default_context()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies) if cookies else urllib.request.HTTPCookieProcessor())
    try:
        with opener.open(req, timeout=25) as resp:
            raw = resp.read()
            charset = resp.headers.get_content_charset() or 'utf-8'
            text = raw.decode(charset, errors='ignore')
            try:
                j = json.loads(text)
                return j, resp.headers, text
            except:
                return None, resp.headers, text
    except urllib.error.HTTPError as e:
        try:
            raw = e.read().decode('utf-8', errors='ignore')
            try:
                j = json.loads(raw) if raw.strip().startswith('{') or raw.strip().startswith('[') else None
            except:
                j = None
            return j, e.headers, raw
        except:
            return None, {}, ''
    except Exception as e:
        return None, {}, str(e)

def try_login(email, password):
    # Jammaleg expects phone field even if value is email
    payload = {"phone": email, "password": password}
    j, hdr, txt = _http_json(API_BASE + LOGIN_PATH, method='POST', data=payload)
    if j and isinstance(j, dict) and j.get('status') == 1:
        data = j.get('data', {})
        token = data.get('api_token') if isinstance(data, dict) else None
        if token:
            # also check ban flag
            user = data.get('user', {}) if isinstance(data, dict) else {}
            ban = user.get('ban')
            if ban == 1 or ban == "1":
                print(f"[jammaleg] WARNING: account ban=1 (محظور) - قد لا يرجع منتجات")
            return {'token': token, 'user': user, 'raw': j}
    # also try email field variant
    payload2 = {"email": email, "password": password}
    j2, hdr2, txt2 = _http_json(API_BASE + LOGIN_PATH, method='POST', data=payload2)
    if j2 and j2.get('status') == 1:
        data = j2.get('data', {})
        token = data.get('api_token') if isinstance(data, dict) else None
        if token:
            return {'token': token, 'user': data.get('user', {}), 'raw': j2}
    return None

def fetch_categories(token):
    headers = {"Authorization": f"Bearer {token}"}
    j, hdr, txt = _http_json(API_BASE + CATEGORIES_PATH, headers=headers)
    if j and isinstance(j, dict) and j.get('status') == 1:
        data = j.get('data', {})
        cats = data.get('categories') if isinstance(data, dict) else None
        if isinstance(cats, list):
            return cats
    # guest fallback - categories visible even without token? try without
    j2, _, _ = _http_json(API_BASE + CATEGORIES_PATH)
    if j2 and j2.get('status') == 1:
        cats = j2.get('data', {}).get('categories')
        if isinstance(cats, list):
            return cats
    return []

def fetch_products_api(token):
    headers = {"Authorization": f"Bearer {token}"}
    all_rows = []
    # try pagination params
    for page in range(1, 20):
        urls = [
            f"{API_BASE}{PRODUCTS_PATH}?page={page}&per_page=100",
            f"{API_BASE}{PRODUCTS_PATH}?page={page}",
            f"{API_BASE}{PRODUCTS_PATH}?page={page}&limit=100",
            f"{API_BASE}{PRODUCTS_PATH}",
        ]
        found_page = False
        for url in urls:
            j, hdr, txt = _http_json(url, headers=headers)
            rows = None
            if isinstance(j, dict):
                if j.get('status') == 1:
                    # some APIs return data as list directly
                    data = j.get('data')
                    if isinstance(data, list):
                        rows = data
                    elif isinstance(data, dict):
                        for k in ('products','items','data','list','rows'):
                            if k in data and isinstance(data[k], list):
                                rows = data[k]; break
                        if rows is None and 'data' in data and isinstance(data['data'], list):
                            rows = data['data']
                    # also check top-level data array
                    if rows is None and isinstance(j.get('data'), list):
                        rows = j['data']
                elif j.get('status') == 0 and 'No products' in str(j.get('msg')):
                    # empty - either banned or no products for this filter
                    rows = []
                # check for pagination wrapper
                if rows is None and isinstance(j, list):
                    rows = j
            elif isinstance(j, list):
                rows = j
            if rows is not None:
                if len(rows) == 0:
                    if page == 1 and not all_rows:
                        # try next url variant
                        continue
                    else:
                        return all_rows
                else:
                    all_rows.extend(rows)
                    found_page = True
                    if len(rows) < 20:
                        return all_rows
                    break
        if not found_page and page == 1:
            # no valid response, maybe need category filter - try per category
            cats = fetch_categories(token)
            if cats:
                for cat in cats:
                    cid = cat.get('id')
                    for url in [f"{API_BASE}{PRODUCTS_PATH}?category_id={cid}&page={page}&per_page=100", f"{API_BASE}{PRODUCTS_PATH}?category_id={cid}"]:
                        j, _, _ = _http_json(url, headers=headers)
                        rows = None
                        if isinstance(j, dict) and j.get('status') == 1:
                            data = j.get('data')
                            if isinstance(data, list):
                                rows = data
                            elif isinstance(data, dict):
                                for k in ('products','items','data'):
                                    if k in data and isinstance(data[k], list):
                                        rows = data[k]; break
                        if rows:
                            all_rows.extend(rows)
                            print(f"[jammaleg] category {cid} returned {len(rows)}")
                        time.sleep(0.2)
                    if all_rows:
                        return all_rows
            return all_rows
        if found_page and len(all_rows) >= 20:
            continue
        else:
            break
    return all_rows

def fetch_product_detail(token, pid):
    headers = {"Authorization": f"Bearer {token}"}
    # try multiple detail endpoints
    for path in [f"/api/product/{pid}", f"/api/products/{pid}", f"/api/product/show/{pid}", f"/api/product/detail/{pid}"]:
        j, _, txt = _http_json(API_BASE + path, headers=headers)
        if j and isinstance(j, dict) and j.get('status') == 1:
            data = j.get('data')
            if isinstance(data, dict):
                return data
            elif isinstance(data, list) and data:
                return data[0]
        elif j and isinstance(j, dict) and 'No product' not in str(j.get('msg','')):
            # maybe success even with status 1
            if j.get('data'):
                return j['data']
    return {}

def guess_category(name):
    n = name.lower()
    if any(x in n for x in ["حذاء","كوتشي","شوز","جزمه","sneaker","boot"]):
        return "أحذية"
    if "شنطة" in name or "شنطه" in name or "حقيبة" in name or "bag" in name.lower():
        return "شنط ومحافظ"
    if "تيشرت" in name or "تيشيرت" in name or "ترنج" in name or "بنطلون" in name or "قميص" in name:
        if "اطفال" in name or "أطفال" in name:
            return "أطفال وألعاب"
        if "حريمي" in name or "نسائي" in name:
            return "ملابس حريمي"
        return "ملابس رجالي"
    if "شبشب" in name or "صندل" in name:
        return "أحذية"
    if "مروحه" in name or "لابتوب" in name:
        return "إلكترونيات"
    return "مستلزمات عامة"

def map_category(raw_cat, name):
    allowed = ["أحذية","أطفال وألعاب","إكسسوارات","إلكترونيات","الرياضة واللياقة","العناية والتجميل","المنزل والمطبخ","ساعات","شنط ومحافظ","عدد وأدوات","مستلزمات السيارات","ملابس حريمي","ملابس رجالي"]
    if raw_cat in allowed:
        return raw_cat
    jammaleg_to_site = {
        "شنط ومحافظ": "شنط ومحافظ",
        "ملابس": "ملابس رجالي",
        "احذيه": "أحذية",
        "ملابس النساء": "ملابس حريمي",
        "ملابس اطفال": "أطفال وألعاب",
        "ملابس موسم الصيف": "ملابس رجالي",
        "ملابس الشتاء": "ملابس رجالي",
        "ميكساتك": "مستلزمات عامة",
        "اكسسوارات": "إكسسوارات",
        "الكترونيات": "إلكترونيات",
    }
    if raw_cat in jammaleg_to_site:
        return jammaleg_to_site[raw_cat]
    return guess_category(name)

def normalize_row(raw, idx=0):
    # raw may be from API list or detail
    pid = str(raw.get('id') or raw.get('_id') or raw.get('product_id') or raw.get('code') or raw.get('slug') or f"jammaleg-{idx}")
    name = raw.get('name') or raw.get('title') or raw.get('name_ar') or raw.get('product_name') or pid
    # price fields - try several
    price = raw.get('price') or raw.get('selling_price') or raw.get('sale_price') or raw.get('offer_price') or raw.get('purchase_price') or raw.get('cost') or raw.get('price_after_commission') or 0
    # commission / profit
    commission = raw.get('commission') or raw.get('affiliate_commission') or raw.get('profit') or raw.get('commission_value') or raw.get('earn') or 0
    # images
    images = []
    for k in ('images','gallery','photos','product_images','media','image','thumbnail','image_path','images_path'):
        if k in raw:
            v = raw[k]
            if isinstance(v, list):
                for it in v:
                    if isinstance(it, str):
                        images.append(it)
                    elif isinstance(it, dict):
                        u = it.get('url') or it.get('src') or it.get('image') or it.get('path') or it.get('image_path')
                        if u:
                            images.append(u)
            elif isinstance(v, str) and v:
                images.append(v)
    # also check single image field like image_path
    if not images and raw.get('image_path'):
        images.append(raw['image_path'])
    # ensure absolute
    abs_images = []
    for u in images:
        if u.startswith('//'):
            u = 'https:' + u
        elif u.startswith('/'):
            u = API_BASE + u
        elif not u.startswith('http'):
            # relative uploads/products_images/... 
            if 'uploads' in u:
                u = API_BASE + '/' + u.lstrip('/')
            else:
                u = API_BASE + '/uploads/' + u
        abs_images.append(u)
    images = abs_images
    # fallback: if no images but product has uploads/products_images pattern in raw text
    if not images:
        # try to find any url in raw
        txt = json.dumps(raw, ensure_ascii=False)
        found = re.findall(r'https?://jammaleg\.com/uploads/[^\"\'\s<>]+?\.(?:jpg|jpeg|png|webp)', txt)
        if found:
            images = list(dict.fromkeys(found))
    colors = raw.get('colors') or raw.get('available_colors') or raw.get('color_options') or []
    sizes = raw.get('sizes') or raw.get('available_sizes') or raw.get('size_options') or []
    desc = raw.get('description') or raw.get('description_ar') or raw.get('desc') or raw.get('details') or ''
    # category
    cat_raw = raw.get('category') or raw.get('category_name') or raw.get('category_ar') or raw.get('categoryName') or ''
    if isinstance(cat_raw, dict):
        cat_raw = cat_raw.get('name') or cat_raw.get('title') or ''
    category = map_category(str(cat_raw), str(name))
    try:
        price = float(price)
    except:
        price = 0
    try:
        commission = float(commission)
    except:
        commission = 0
    # If commission is 0 and price includes commission, keep price as is; else price is purchase + commission?
    # For Jammaleg, homepage shows "سعر الشراء" = purchase price, commission separate. So total suggested = price + commission if commission known, else price.
    # We store price as purchase + commission if commission >0 else price
    total_price = price + commission if commission else price
    # stock
    stock = raw.get('stock') or raw.get('quantity') or raw.get('qty') or raw.get('available_quantity') or 999
    try:
        stock = int(float(stock))
    except:
        stock = 999
    if stock <= 0:
        stock = 0
    return {
        'id': f'jammaleg-{pid}' if not str(pid).startswith('jammaleg-') else str(pid),
        'orig_id': str(pid),
        'name': str(name),
        'sku': str(raw.get('sku') or raw.get('code') or pid),
        'price': float(total_price),
        'cost': float(price) if price else 0,
        'old_price': 0,
        'discount': 0,
        'price_text': f'{int(total_price) if float(total_price).is_integer() else total_price} جنيه',
        'commission': float(commission),
        'currency': 'جنيه',
        'category': category,
        'subcategory': '',
        'country': 'مصر',
        'stock': stock,
        'images': images[:6],
        'sizes': sizes if isinstance(sizes, list) else [],
        'extra': {
            'colors': colors if isinstance(colors, list) else [],
            'variants_detailed': [],
            'source_commission_original': commission,
            'orig_suggested': float(total_price),
            'source_url': raw.get('source_url') or f'https://jammaleg.com/product/detils/{pid}',
            'jammaleg_id': str(pid),
            'category_raw': str(cat_raw),
        },
        'added_at': time.strftime('%Y-%m-%d'),
        'desc': str(desc)[:800],
    }

def scrape_guest_products():
    # fallback: scrape homepage for product cards as guest (no commission)
    # Use API categories to guess? But we can fetch homepage HTML via public endpoint
    # Since direct fetch in Actions works with requests, we can try
    j, hdr, txt = _http_json(API_BASE + "/", headers=UA)
    if not txt:
        return []
    # Extract product IDs from homepage: /product/detils/{id}
    ids = re.findall(r'/product/detils/(\d+)', txt)
    seen=set(); uniq=[]
    for i in ids:
        if i not in seen:
            seen.add(i); uniq.append(i)
    rows=[]
    for pid in uniq[:60]:
        # try to fetch product detail page HTML as guest - may require login but we try
        _, _, html_txt = _http_json(f"{API_BASE}/product/detils/{pid}", headers=UA)
        if not html_txt or 'تسجيل الدخول' in html_txt:
            continue
        # parse name, price, images from HTML
        name = pid
        m=re.search(r'<title>(.*?)</title>', html_txt, re.I|re.S)
        if m:
            name = re.sub(r'\s*[-|].*jammal.*', '', m.group(1), flags=re.I).strip() or name
        pm=re.search(r'(\d{2,5}(?:\.\d+)?)\s*(?:ج\.م|جنيه|EGP)', html_txt)
        price=0
        if pm:
            try: price=float(pm.group(1))
            except: price=0
        imgs=re.findall(r'https?://jammaleg\.com/uploads/products_images/[^\"\'\s<>]+?\.(?:jpg|jpeg|png|webp|JPG|PNG)', html_txt)
        images=list(dict.fromkeys(imgs))
        if not name or price<=0:
            continue
        rows.append({
            'id': pid,
            'name': name,
            'price': price,
            'commission': 0,
            'category': guess_category(name),
            'images': images[:6],
            'desc': '',
        })
        time.sleep(0.2)
    return rows

def fetch_all(email=None, password=None):
    email = email or os.environ.get('JAMMALEG_USER') or os.environ.get('MNFEAN_USER') or DEFAULT_EMAIL
    password = password or os.environ.get('JAMMALEG_PASS') or os.environ.get('MNFEAN_PASS') or DEFAULT_PASS
    print(f"[jammaleg] trying login as {email}")
    login_info = try_login(email, password)
    if login_info:
        token = login_info.get('token')
        print(f"[jammaleg] login ok ban={login_info.get('user',{}).get('ban')} affiliate_code={login_info.get('user',{}).get('affiliate_code') or login_info.get('user',{}).get('affiliate_id')}")
        rows = fetch_products_api(token)
        if rows:
            print(f"[jammaleg] API returned {len(rows)} rows")
            # enrich with details if needed (first few)
            out=[]
            for i, r in enumerate(rows):
                # if row lacks images/desc, fetch detail
                if not r.get('images') and not r.get('image_path'):
                    detail = fetch_product_detail(token, r.get('id'))
                    if detail:
                        # merge
                        for k in ('images','gallery','description','sizes','colors'):
                            if k in detail and detail[k]:
                                r[k]=detail[k]
                out.append(normalize_row(r, i))
            return out
        else:
            print("[jammaleg] API returned empty (ban or no products) - trying guest scrape")
    else:
        print("[jammaleg] login failed, trying guest scrape")
        rows = fetch_products_api(None) if 'token' in locals() else []
        if rows:
            return [normalize_row(r,i) for i,r in enumerate(rows)]
    guest = scrape_guest_products()
    if guest:
        print(f"[jammaleg] guest scrape returned {len(guest)} rows")
        return [normalize_row(r,i) for i,r in enumerate(guest)]
    print("[jammaleg] no products found via any method")
    return []

if __name__ == '__main__':
    prods = fetch_all()
    out = os.path.join(DATA, 'jammaleg.json')
    lite = os.path.join(DATA, 'lite_jammaleg.json')
    for p in (out, lite):
        with open(p, 'w', encoding='utf-8') as f:
            json.dump(prods, f, ensure_ascii=False, indent=2)
    print(f"Wrote {len(prods)} to {out}")
