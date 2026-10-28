#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
من فين (mnfean.co) — جلب كتالوج المسوق بالعمولة
- يحاول تسجيل الدخول عبر system.mnfean.co/api/auth/login (أو مسارات بديلة)
- بعد الدخول يجلب كل المنتجات مع الأسعار والعمولات والصور
- لو فشل تسجيل الدخول، يجلب المتاح للزوار (guest) عبر HTML/JSON
- يطبّق خصم PRICE_GLOBAL_DISCOUNT (12%) بشكل ضمني عبر config.php، لكن يخزن السعر الأساسي هنا
"""
import os, re, json, time, urllib.request, urllib.error, urllib.parse, http.cookiejar, ssl

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'data')
UA = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36', 'Accept-Language': 'ar,en;q=0.8', 'Accept': 'application/json, text/html,*/*'}

# Credentials — can be overridden by env vars MNFEAN_USER / MNFEAN_PASS
DEFAULT_EMAIL = 'ae782236@gmail.com'
DEFAULT_PASS = 'aA55667788'

API_HOSTS = [
    'https://system.mnfean.co',
    'https://mnfean.co',
    'https://api.mnfean.co',
]
LOGIN_PATHS = [
    '/api/auth/login',
    '/api/login',
    '/api/auth/signin',
    '/api/user/login',
    '/auth/login',
    '/login',
]
PRODUCTS_PATHS = [
    '/api/products',
    '/api/product/list',
    '/api/products/list',
    '/api/items',
    '/api/product',
    '/products/api',
]

# Fallback guest scraping: parse /products HTML for product cards
GUEST_PRODUCTS_URL = 'https://mnfean.co/products'

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
    # Some hosts have strict TLS — allow default but ignore verification if needed via unverified? keep default
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies) if cookies else urllib.request.HTTPCookieProcessor())
    try:
        with opener.open(req, timeout=20) as resp:
            raw = resp.read()
            charset = resp.headers.get_content_charset() or 'utf-8'
            text = raw.decode(charset, errors='ignore')
            # try json
            try:
                j = json.loads(text)
                return j, resp.headers, text
            except:
                return None, resp.headers, text
    except urllib.error.HTTPError as e:
        try:
            raw = e.read().decode('utf-8', errors='ignore')
            j = json.loads(raw) if raw.strip().startswith('{') or raw.strip().startswith('[') else None
            return j, e.headers, raw
        except:
            return None, {}, ''
    except Exception as e:
        return None, {}, str(e)

def try_login(email, password):
    cj = http.cookiejar.CookieJar()
    for host in API_HOSTS:
        for path in LOGIN_PATHS:
            url = host + path
            payloads = [
                {'email': email, 'password': password},
                {'email': email, 'password': password, 'username': email},
                {'phone': email, 'password': password},
                {'user': email, 'pass': password},
            ]
            for payload in payloads:
                j, hdr, txt = _http_json(url, method='POST', data=payload, cookies=cj)
                # success signals: token, access_token, data.token, success
                if j is not None:
                    token = None
                    if isinstance(j, dict):
                        token = j.get('token') or j.get('access_token') or j.get('data', {}).get('token') if isinstance(j.get('data'), dict) else None
                        if not token:
                            # nested
                            for k in ('token','accessToken','access_token','jwt','key'):
                                if k in j: token = j[k]; break
                                if isinstance(j.get('data'), dict) and k in j['data']: token = j['data'][k]; break
                    if token or (isinstance(j, dict) and j.get('success') is True):
                        return {'token': token or '', 'cookies': cj, 'host': host, 'raw': j}
                    # also check for user object indicating login
                    if isinstance(j, dict) and ('user' in j or 'data' in j):
                        # might be success even without explicit token (cookie auth)
                        if 'user' in j or (isinstance(j.get('data'), dict) and 'user' in j['data']):
                            return {'token': token or '', 'cookies': cj, 'host': host, 'raw': j}
                # also check set-cookie header
                if hdr and (hdr.get('Set-Cookie') or hdr.get('set-cookie')):
                    # might have logged in via cookie
                    if j and isinstance(j, dict) and j.get('message','').lower().find('success') != -1:
                        return {'token': '', 'cookies': cj, 'host': host, 'raw': j}
    return None

def fetch_products_api(login_info=None):
    headers = dict(UA)
    cookies = None
    host = 'https://system.mnfean.co'
    token = ''
    if login_info:
        token = login_info.get('token') or ''
        cookies = login_info.get('cookies')
        host = login_info.get('host') or host
        if token:
            headers['Authorization'] = f'Bearer {token}'
            headers['authorization'] = f'Bearer {token}'
    # try each products path with pagination
    all_rows = []
    for path in PRODUCTS_PATHS:
        for page in range(1, 20):
            # try several param styles
            urls = [
                f"{host}{path}?page={page}&per_page=100",
                f"{host}{path}?page={page}&limit=100",
                f"{host}{path}?page={page}",
                f"{host}{path}",
            ]
            # also POST with body
            for url in urls:
                j, hdr, txt = _http_json(url, headers=headers, cookies=cookies)
                rows = None
                if isinstance(j, list):
                    rows = j
                elif isinstance(j, dict):
                    # common wrappers
                    for k in ('data','products','items','result','rows'):
                        if k in j and isinstance(j[k], list):
                            rows = j[k]; break
                    if rows is None and 'data' in j and isinstance(j['data'], dict):
                        for k in ('products','items','data','list'):
                            if k in j['data'] and isinstance(j['data'][k], list):
                                rows = j['data'][k]; break
                if rows is None:
                    # empty array signal
                    if isinstance(j, list) and len(j)==0:
                        rows=[]
                    elif txt and txt.strip()=='[]':
                        rows=[]
                if rows is not None:
                    if len(rows)==0:
                        # if first page empty, try next path
                        if page==1 and not all_rows:
                            break  # try next path
                        else:
                            return all_rows
                    else:
                        # normalize rows
                        all_rows.extend(rows)
                        if len(rows) < 50:
                            # likely last page
                            return all_rows
                        break
            else:
                continue
            break
        if all_rows:
            return all_rows
    return all_rows

def scrape_guest_products():
    """Fallback: scrape /products HTML for product cards (guest view)"""
    j, hdr, txt = _http_json(GUEST_PRODUCTS_URL, headers=UA)
    if not txt:
        return []
    # txt is HTML markdown via fetch_page? But here via urllib we get raw HTML
    # Parse product links: /product/<slug>
    slugs = re.findall(r'/product/([a-z0-9\-]+)', txt)
    # deduplicate preserve order
    seen=set(); uniq=[]
    for s in slugs:
        if s not in seen:
            seen.add(s); uniq.append(s)
    # Also extract price near each
    rows=[]
    # Try to extract price blocks: look for pattern price like 270.00 or 270
    # We'll fetch each product detail for richer data, but for speed also try to parse listing prices
    # Extract product blocks: attempt to find name+price pairs
    # Use regex for product card: href="/product/SLUG" ... price?
    # Simpler: build basic rows from uniq list, fetch detail for each (limited to first 60 to avoid hammer)
    for slug in uniq[:80]:
        detail = fetch_product_detail(slug)
        if detail:
            rows.append(detail)
        time.sleep(0.2)
    return rows

def fetch_product_detail(slug):
    url = f'https://mnfean.co/product/{slug}'
    j, hdr, txt = _http_json(url, headers=UA)
    # txt may be HTML markdown via fetch_page style or raw HTML; we handle both
    # Try to parse via fetch_page markdown fallback: look for known fields
    # In raw HTML, images are in meta or img src
    # We'll use txt string search for known markers independent of format
    name = slug.replace('-',' ').title()
    price = None
    images=[]
    colors=[]
    sizes=[]
    desc=''
    # try to extract name from <title> or h1
    if txt:
        m = re.search(r'<title>(.*?)</title>', txt, re.I|re.S)
        if m: name = re.sub(r'\s*[-|].*mnfean.*', '', m.group(1), flags=re.I).strip() or name
        # price extraction: look for "270.00 ج.م" or "270.00"
        pm = re.search(r'(\d{2,4}(?:\.\d+)?)\s*(?:ج\.م|EGP|جنيه)', txt)
        if pm: 
            try: price=float(pm.group(1))
            except: price=None
        # images
        imgs = re.findall(r'https?://system\.mnfean\.co/uploads/[^\"\'\s<>]+\.(?:png|jpg|jpeg|webp|PNG|JPG|WEBP)', txt)
        # deduplicate
        images = list(dict.fromkeys(imgs))
        if not images:
            # fallback media host
            imgs2 = re.findall(r'https?://system\.mnfean\.co/uploads/media/[^\"\'\s<>]+', txt)
            images = list(dict.fromkeys(imgs2))
        # colors / sizes markers: look for Arabic labels
        # الخامه, الالوان, المقاسات
        # Extract section after الألوان and المقاسات
        # Use simple split
        # For sizes, look for patterns like ٣٧, ٤٢ل etc but we keep generic extraction via known tokens
        # We'll attempt to find list after المقاسات:
        sec = re.search(r'المقاسات[:\s]*([\s\S]{0,500})', txt)
        if sec:
            block = sec.group(1)
            # find numbers like 37-46 or L/XL
            sz = re.findall(r'\b(?:3xl|2xl|xl|l|m|s|xs|\d{2,3})\b', block, re.I)
            if sz: sizes = list(dict.fromkeys([s.lower() for s in sz]))
        sec2 = re.search(r'الألوان[:\s]*([\s\S]{0,500})', txt)
        if sec2:
            block2 = sec2.group(1)
            # find color words
            cols = re.findall(r'(?:اسود|ابيض|بني|بيج|احمر|ازرق|اخضر|اصفر|بنفسجي|رمادي|زهري|برتقالي|كحلي|لبني|نبيتي)', block2)
            if cols: colors = list(dict.fromkeys(cols))
    if price is None:
        price=0
    return {
        'slug': slug,
        'name': name,
        'price': price,
        'commission': 0,
        'cost': 0,
        'images': images[:6] if images else [],
        'colors': colors,
        'sizes': sizes,
        'desc': desc,
        'source_url': url,
    }

def normalize_row(raw, idx=0):
    """Convert raw API row or guest row to catalog format"""
    # raw may be guest dict from fetch_product_detail or API dict
    if 'slug' in raw:
        # guest
        slug = raw['slug']
        name = raw.get('name') or slug
        price = float(raw.get('price') or 0)
        images = raw.get('images') or []
        colors = raw.get('colors') or []
        sizes = raw.get('sizes') or []
        desc = raw.get('desc') or ''
        source_url = raw.get('source_url') or f'https://mnfean.co/product/{slug}'
        # commission unknown for guest — 0
        commission = 0
        suggested = price
        # category guess
        category = guess_category(name)
        return {
            'id': f'mnfean-{slug}',
            'orig_id': slug,
            'name': name,
            'sku': slug,
            'price': float(price),
            'cost': 0,
            'old_price': 0,
            'discount': 0,
            'price_text': f'{int(price) if float(price).is_integer() else price} جنيه',
            'commission': commission,
            'currency': 'جنيه',
            'category': category,
            'subcategory': '',
            'country': 'مصر',
            'stock': 999999,
            'images': images[:6],
            'sizes': sizes,
            'extra': {
                'colors': colors,
                'variants_detailed': [],
                'source_commission_original': commission,
                'orig_suggested': suggested,
                'source_url': source_url,
                'mnfean_slug': slug,
            },
            'added_at': '2026-10-28',
            'desc': desc,
        }
    else:
        # API row — try to map common fields
        # Possible keys: id, _id, product_id, name, title, price, cost_price, commission, images, gallery, etc.
        pid = str(raw.get('id') or raw.get('_id') or raw.get('product_id') or raw.get('slug') or raw.get('code') or f"mnfean-{idx}")
        name = raw.get('name') or raw.get('title') or raw.get('name_ar') or raw.get('product_name') or pid
        # price fields
        price = raw.get('price') or raw.get('selling_price') or raw.get('sale_price') or raw.get('offer_price') or raw.get('cost') or 0
        # commission fields
        commission = raw.get('commission') or raw.get('affiliate_commission') or raw.get('profit') or 0
        # images
        images=[]
        for k in ('images','gallery','photos','product_images','media','image','thumbnail'):
            if k in raw:
                v=raw[k]
                if isinstance(v, list):
                    for it in v:
                        if isinstance(it, str): images.append(it)
                        elif isinstance(it, dict):
                            u=it.get('url') or it.get('src') or it.get('image') or it.get('path')
                            if u: images.append(u)
                elif isinstance(v, str) and v:
                    images.append(v)
        # ensure absolute urls
        abs_images=[]
        for u in images:
            if u.startswith('//'): u='https:'+u
            elif u.startswith('/'): u='https://system.mnfean.co'+u
            elif not u.startswith('http'): u='https://system.mnfean.co/uploads/'+u
            abs_images.append(u)
        images=abs_images
        colors = raw.get('colors') or raw.get('available_colors') or []
        sizes = raw.get('sizes') or raw.get('available_sizes') or []
        desc = raw.get('description') or raw.get('description_ar') or raw.get('desc') or ''
        slug = raw.get('slug') or raw.get('code') or pid
        category_raw = raw.get('category') or raw.get('category_name') or raw.get('category_ar') or ''
        category = map_category(category_raw, name)
        try: price=float(price)
        except: price=0
        try: commission=float(commission)
        except: commission=0
        return {
            'id': f'mnfean-{slug}' if not str(pid).startswith('mnfean-') else str(pid),
            'orig_id': str(slug),
            'name': str(name),
            'sku': str(slug),
            'price': float(price),
            'cost': 0,
            'old_price': 0,
            'discount': 0,
            'price_text': f'{int(price) if float(price).is_integer() else price} جنيه',
            'commission': float(commission),
            'currency': 'جنيه',
            'category': category,
            'subcategory': '',
            'country': 'مصر',
            'stock': 999999,
            'images': images[:6],
            'sizes': sizes if isinstance(sizes, list) else [],
            'extra': {
                'colors': colors if isinstance(colors, list) else [],
                'variants_detailed': [],
                'source_commission_original': commission,
                'orig_suggested': float(price)+float(commission) if commission else float(price),
                'source_url': raw.get('source_url') or f'https://mnfean.co/product/{slug}',
                'mnfean_slug': str(slug),
            },
            'added_at': '2026-10-28',
            'desc': str(desc)[:500],
        }

def guess_category(name):
    n=name.lower()
    if any(x in n for x in ["حذاء","كوتشي","شوز","اسكتشير","سكيتشر","جزمه","slippers","clog","samba","shoes","sneaker","elite","classic","eva","new balance","hoka","adidas","air","comfort"]):
        return "أحذية"
    if any(x in n for x in ["بوكسر","طقم","حماله","دفاية","لانجيري","براه","تشيرت","تيشيرت","قميص","بولو","puma","nike","sportify","round"]):
        if "حريمي" in name or "نسائي" in name or "براه" in name: return "ملابس حريمي"
        if "اطفال" in name: return "أطفال وألعاب"
        return "ملابس رجالي"
    if "شنطة" in name or "شنطه" in name or "حزام" in name or "بوكس" in name: return "شنط ومحافظ"
    if "لابتوب" in name or "استاند" in name or "مروحه" in name or "مساج" in name: return "إلكترونيات"
    if "فيست" in name or "ووتر" in name: return "ملابس رجالي"
    if "سبراي" in name or "فروتينا" in name: return "العناية والتجميل"
    if "حشرات" in name: return "المنزل والمطبخ"
    if "تخزين" in name: return "المنزل والمطبخ"
    return "مستلزمات عامة"

def map_category(raw_cat, name):
    # raw_cat from mnfean may be Arabic already matching our site categories
    allowed = ["أحذية","أطفال وألعاب","إكسسوارات","إلكترونيات","الرياضة واللياقة","العناية والتجميل","المنزل والمطبخ","ساعات","شنط ومحافظ","عدد وأدوات","مستلزمات السيارات","ملابس حريمي","ملابس رجالي"]
    if raw_cat in allowed:
        return raw_cat
    # try to map mnfean categories (from /products filters) to our categories
    mnfean_to_site = {
        "ملابس رجالي":"ملابس رجالي",
        "احذيه رجالي":"أحذية",
        "ملابس رياضيه":"الرياضة واللياقة",
        "ملابس داخلية":"ملابس رجالي",
        "ملابس حريمي":"ملابس حريمي",
        "احذيه حريمي":"أحذية",
        "لانجيري":"ملابس حريمي",
        "ملابس اطفالي":"أطفال وألعاب",
        "احذية اطفالي":"أطفال وألعاب",
        "ساعات":"ساعات",
        "مستحضرات تجميل":"العناية والتجميل",
        "حقائب":"شنط ومحافظ",
        "اكسسورات سيارات":"مستلزمات السيارات",
        "مفروشات":"المنزل والمطبخ",
        "ادوات منزلية":"المنزل والمطبخ",
        "مستلزمات اطفال":"أطفال وألعاب",
        "مكتبة الكتب":"إلكترونيات",
        "العطور":"العناية والتجميل",
        "ادوات رياضية":"الرياضة واللياقة",
        "نظارات":"إكسسوارات",
        "الألعاب":"أطفال وألعاب",
        "شنط مدرسية":"شنط ومحافظ",
        "احذية رياضية":"أحذية",
    }
    if raw_cat in mnfean_to_site: return mnfean_to_site[raw_cat]
    # fallback guess by name
    return guess_category(name)

def fetch_all(email=None, password=None, max_pages=5):
    email = email or os.environ.get('MNFEAN_USER') or DEFAULT_EMAIL
    password = password or os.environ.get('MNFEAN_PASS') or DEFAULT_PASS
    print(f"[mnfean] trying login as {email}")
    login_info = try_login(email, password)
    if login_info:
        print(f"[mnfean] login ok host={login_info.get('host')} token={'yes' if login_info.get('token') else 'cookie'}")
        rows = fetch_products_api(login_info)
        if rows:
            print(f"[mnfean] API returned {len(rows)} rows via authenticated")
            return [normalize_row(r,i) for i,r in enumerate(rows)]
        else:
            print("[mnfean] API returned empty via auth, falling back to guest scrape")
    else:
        print("[mnfean] login failed, trying guest API")
        rows = fetch_products_api(None)
        if rows:
            print(f"[mnfean] guest API returned {len(rows)} rows")
            return [normalize_row(r,i) for i,r in enumerate(rows)]
    # fallback guest scrape
    guest = scrape_guest_products()
    if guest:
        print(f"[mnfean] guest scrape returned {len(guest)} rows")
        return [normalize_row(r,i) for i,r in enumerate(guest)]
    print("[mnfean] no products found via any method")
    return []

if __name__ == '__main__':
    prods = fetch_all()
    out = os.path.join(DATA, 'mnfean.json')
    lite = os.path.join(DATA, 'lite_mnfean.json')
    for p in (out, lite):
        with open(p, 'w', encoding='utf-8') as f:
            json.dump(prods, f, ensure_ascii=False, indent=2)
    print(f"Wrote {len(prods)} to {out}")
