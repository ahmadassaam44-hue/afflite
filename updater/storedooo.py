#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Storedooo (www.storedooo.com) — جلب كتالوج المسوق بالعمولة
- يحاول تسجيل الدخول بالبريد ae782236@gmail.com عبر مسارات PHP شائعة
- بعد الدخول يجرب مسارات المنتجات حتى يجد بيانات
- fallback: لو مقفول للضيوف يحاول سحب HTML
- يطبق نفس خصم 12% عبر config.php (storedooo في قائمة wf_is_physical_plat)
"""
import os, re, json, time, urllib.request, urllib.error, urllib.parse, http.cookiejar, ssl, html as htmlmod

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'data')
UA = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36', 'Accept-Language': 'ar,en;q=0.8', 'Accept': 'application/json, text/html,*/*'}

DEFAULT_EMAIL = os.environ.get('STOREDOOO_USER') or os.environ.get('MNFEAN_USER') or 'ae782236@gmail.com'
DEFAULT_PASS = os.environ.get('STOREDOOO_PASS') or os.environ.get('MNFEAN_PASS') or 'aA55667788'

LOGIN_HOSTS = ['https://www.storedooo.com', 'https://storedooo.com']
LOGIN_PATHS = [
    '/login.php',
    '/user/login.php',
    '/auth/login.php',
    '/api/login.php',
    '/ajax/login.php',
    '/login_action.php',
    '/user/login_action.php',
    '/check_login.php',
    '/do_login.php',
    '/signin.php',
]
PRODUCT_PATHS = [
    '/api/products.php',
    '/api/get_products.php',
    '/ajax/get_products.php',
    '/user/products.php',
    '/user/api/products.php',
    '/affiliate/products.php',
    '/dashboard/products.php',
    '/products.php',
    '/product/list.php',
    '/api/product/list.php',
    '/get_products.php',
]

def _http(url, method='GET', data=None, headers=None, cookies=None, timeout=20):
    headers = dict(headers or {})
    headers.update(UA)
    body = None
    ctype = None
    if data is not None:
        if isinstance(data, dict):
            # try as form-encoded for PHP
            body = urllib.parse.urlencode(data).encode('utf-8')
            ctype = 'application/x-www-form-urlencoded'
        else:
            body = str(data).encode('utf-8')
            ctype = 'text/plain'
        headers['Content-Type'] = ctype
    req = urllib.request.Request(url, data=body, headers=headers, method=method)
    # For storedooo self-signed or strict, ignore verify
    ctx = ssl._create_unverified_context()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies) if cookies else urllib.request.HTTPCookieProcessor())
    try:
        with opener.open(req, timeout=timeout) as resp:
            raw = resp.read()
            charset = resp.headers.get_content_charset() or 'utf-8'
            text = raw.decode(charset, errors='ignore')
            try:
                j = json.loads(text)
                return j, resp.headers, text, resp.status
            except:
                return None, resp.headers, text, resp.status
    except urllib.error.HTTPError as e:
        try:
            raw = e.read().decode('utf-8', errors='ignore')
            j = None
            try:
                j = json.loads(raw)
            except:
                pass
            return j, e.headers, raw, e.code
        except:
            return None, {}, '', e.code
    except Exception as e:
        return None, {}, str(e), 0

def try_login(email, password):
    cj = http.cookiejar.CookieJar()
    for host in LOGIN_HOSTS:
        for path in LOGIN_PATHS:
            url = host + path
            # payload variants for PHP
            payloads = [
                {'email': email, 'password': password},
                {'email': email, 'password': password, 'submit': 'login'},
                {'username': email, 'password': password},
                {'user_email': email, 'user_password': password},
                {'phone': email, 'password': password},
                {'user': email, 'pass': password},
                {'login_email': email, 'login_password': password},
                {'email_address': email, 'password': password},
            ]
            for payload in payloads:
                j, hdr, txt, code = _http(url, method='POST', data=payload, cookies=cj)
                # success heuristics
                txt_lower = (txt or '').lower()
                if code in (200, 302) and any(k in txt_lower for k in ('success', 'dashboard', 'products', 'welcome', 'تم تسجيل', 'مرحبا')):
                    # check if not error
                    if 'error' not in txt_lower and 'invalid' not in txt_lower and 'خطأ' not in txt_lower:
                        return {'cookies': cj, 'host': host, 'raw': j or txt}
                if j and isinstance(j, dict):
                    if j.get('success') is True or j.get('status') in ('success', 'ok', 1, '1'):
                        return {'cookies': cj, 'host': host, 'raw': j}
                    if 'token' in j or 'access_token' in j:
                        return {'cookies': cj, 'host': host, 'raw': j, 'token': j.get('token') or j.get('access_token')}
                # also set-cookie indicates login attempt
                if hdr and (hdr.get('Set-Cookie') or hdr.get('set-cookie')):
                    # if login returned cookie, try to verify by fetching products
                    pass
    # Try cookie even if not definitively success: return cj if we got any cookie
    if len(list(cj)) > 0:
        return {'cookies': cj, 'host': LOGIN_HOSTS[0], 'raw': None}
    return None

def fetch_products_api(login_info=None):
    headers = dict(UA)
    cookies = None
    host = 'https://www.storedooo.com'
    token = ''
    if login_info:
        cookies = login_info.get('cookies')
        host = login_info.get('host') or host
        token = login_info.get('token') or ''
        if token:
            headers['Authorization'] = f'Bearer {token}'
    all_rows = []
    for path in PRODUCT_PATHS:
        for page in (1,2,3,4,5):
            urls = [
                f"{host}{path}?page={page}&limit=100",
                f"{host}{path}?page={page}",
                f"{host}{path}",
            ]
            for url in urls:
                j, hdr, txt, code = _http(url, headers=headers, cookies=cookies)
                rows = None
                if isinstance(j, list):
                    rows = j
                elif isinstance(j, dict):
                    for k in ('data','products','items','result','rows','records'):
                        if k in j and isinstance(j[k], list):
                            rows = j[k]; break
                    if rows is None and 'data' in j and isinstance(j['data'], dict):
                        for k in ('products','items','list'):
                            if k in j['data'] and isinstance(j['data'][k], list):
                                rows = j['data'][k]; break
                # try to parse HTML if not json
                if rows is None and txt and ('<div' in txt or '<img' in txt):
                    # try to extract product cards from HTML
                    # Storedooo likely renders products with images like /uploads/products/xxxx.jpg
                    # Extract via regex
                    # Look for product image + price patterns
                    # We'll attempt generic HTML product extraction
                    html_rows = parse_html_products(txt, host)
                    if html_rows:
                        all_rows.extend(html_rows)
                        # if we got html rows, continue pagination
                        if len(html_rows) < 20:
                            return all_rows
                        break
                if rows is not None:
                    if len(rows)==0:
                        if page==1 and not all_rows:
                            break
                        else:
                            return all_rows
                    else:
                        all_rows.extend(rows)
                        if len(rows) < 30:
                            return all_rows
                        break
            else:
                continue
            break
        if all_rows:
            return all_rows
    return all_rows

def parse_html_products(html_text, host):
    rows=[]
    # Extract product blocks: look for <img ... uploads ...> near price
    # Find all image urls
    imgs = re.findall(r'(https?://[^\"\'\s>]*storedooo[^\"\'\s>]*\.(?:jpg|jpeg|png|webp))|((?:/)?uploads/[^\"\'\s>]*\.(?:jpg|jpeg|png|webp))', html_text, re.I)
    flat_imgs=[]
    for a,b in imgs:
        u=a or b
        if u.startswith('/'):
            u=host+u
        elif not u.startswith('http'):
            u=host+'/'+u
        flat_imgs.append(u)
    # Try to find product names near images: look for data-name or title=
    # Generic: find all <a ...>product</a> patterns
    # Fallback: create synthetic rows from images if we have many
    # We need more precise: search for JSON embedded in HTML like var products = [...]
    m = re.search(r'var\s+products\s*=\s*(\[.*?\]);', html_text, re.S)
    if m:
        try:
            j=json.loads(m.group(1))
            if isinstance(j, list):
                return j
        except:
            pass
    m2 = re.search(r'products["\']\s*:\s*(\[.*?\])', html_text, re.S)
    if m2:
        try:
            j=json.loads(m2.group(1))
            if isinstance(j, list):
                return j
        except:
            pass
    # If we have images but no structured data, we will return empty to trigger other path
    return []

def guess_category(name):
    n=name.lower()
    if any(x in n for x in ["حذاء","كوتشي","شوز","اسكتشير","بوت","جزمة","sneaker","shoe","boot"]):
        return "أحذية"
    if any(x in n for x in ["بوكسر","طقم","حمالة","لانجري","برا","تيشيرت","قميص","عباية","فستان","بلوزة"]):
        if any(x in name for x in ["حريمي","نسائي","لانجري"]): return "ملابس حريمي"
        return "ملابس رجالي"
    if "شنطة" in name or "حقيبة" in name: return "شنط ومحافظ"
    if any(x in name for x in ["موبايل","هاتف","لابتوب","سماعة","شاحن","كابل"]): return "إلكترونيات"
    if any(x in name for x in ["كريم","مكياج","عطر","بشرة","شعر"]): return "العناية والتجميل"
    if any(x in name for x in ["مطبخ","منزل","تنظيف","مفرش"]): return "المنزل والمطبخ"
    return "مستلزمات عامة"

def map_category(raw_cat, name):
    allowed = ["أحذية","أطفال وألعاب","إكسسوارات","إلكترونيات","الرياضة واللياقة","العناية والتجميل","المنزل والمطبخ","ساعات","شنط ومحافظ","عدد وأدوات","مستلزمات السيارات","ملابس حريمي","ملابس رجالي"]
    if raw_cat in allowed:
        return raw_cat
    mapping = {
        "ملابس رجالي":"ملابس رجالي",
        "احذية":"أحذية",
        "ملابس حريمي":"ملابس حريمي",
        "اكسسوارات":"إكسسوارات",
        "مستحضرات تجميل":"العناية والتجميل",
        "ادوات منزلية":"المنزل والمطبخ",
        "الكترونيات":"إلكترونيات",
    }
    if raw_cat in mapping:
        return mapping[raw_cat]
    return guess_category(name)

def normalize_row(raw, idx=0):
    # raw may be API dict or synthetic
    pid = str(raw.get('id') or raw.get('product_id') or raw.get('_id') or raw.get('slug') or f"storedooo-{idx}")
    name = raw.get('name') or raw.get('title') or raw.get('product_name') or raw.get('name_ar') or pid
    price = raw.get('price') or raw.get('sale_price') or raw.get('selling_price') or raw.get('cost') or raw.get('cost_price') or 0
    commission = raw.get('commission') or raw.get('profit') or raw.get('affiliate_commission') or raw.get('earn') or 0
    images=[]
    for k in ('images','gallery','photos','product_images','image','thumbnail','img'):
        if k in raw:
            v=raw[k]
            if isinstance(v, list):
                for it in v:
                    if isinstance(it, str): images.append(it)
                    elif isinstance(it, dict):
                        u=it.get('url') or it.get('src') or it.get('image')
                        if u: images.append(u)
            elif isinstance(v, str) and v:
                images.append(v)
    # normalize urls
    abs_images=[]
    for u in images:
        if u.startswith('//'): u='https:'+u
        elif u.startswith('/'): u='https://www.storedooo.com'+u
        elif not u.startswith('http'): u='https://www.storedooo.com/uploads/'+u
        abs_images.append(u)
    images=abs_images
    colors = raw.get('colors') or raw.get('color_options') or []
    sizes = raw.get('sizes') or raw.get('size_options') or []
    desc = raw.get('description') or raw.get('description_ar') or raw.get('desc') or ''
    slug = raw.get('slug') or raw.get('code') or pid
    category_raw = raw.get('category') or raw.get('category_name') or ''
    category = map_category(category_raw, name)
    try: price=float(price)
    except: price=0
    try: commission=float(commission)
    except: commission=0
    # if commission given, price is likely cost, suggested = price+commission
    # but we store price as selling price for display
    selling = float(price) + float(commission) if commission and price < 5000 else float(price)
    # ensure at least 12% discount logic will apply later; store base
    return {
        'id': f'storedooo-{slug}' if not str(pid).startswith('storedooo-') else str(pid),
        'orig_id': str(slug),
        'name': str(name),
        'sku': str(slug),
        'price': float(selling),
        'cost': float(price) if commission else 0,
        'old_price': 0,
        'discount': 0,
        'price_text': f'{int(selling) if float(selling).is_integer() else selling} جنيه',
        'commission': float(commission),
        'currency': 'جنيه',
        'category': category,
        'subcategory': '',
        'country': 'مصر',
        'stock': 999999,
        'images': images[:6] if images else ['https://www.storedooo.com/images/storage.svg'],
        'sizes': sizes if isinstance(sizes, list) else [],
        'extra': {
            'colors': colors if isinstance(colors, list) else [],
            'variants_detailed': [],
            'source_commission_original': commission,
            'orig_suggested': float(selling),
            'source_url': raw.get('source_url') or f'https://www.storedooo.com/product/{slug}',
            'storedooo_slug': str(slug),
            'storedooo_id': str(pid),
        },
        'added_at': '2026-10-28',
        'desc': str(desc)[:500],
    }

def fetch_all(email=None, password=None):
    email = email or os.environ.get('STOREDOOO_USER') or os.environ.get('MNFEAN_USER') or DEFAULT_EMAIL
    password = password or os.environ.get('STOREDOOO_PASS') or os.environ.get('MNFEAN_PASS') or DEFAULT_PASS
    print(f"[storedooo] trying login as {email}")
    login_info = try_login(email, password)
    if login_info:
        print(f"[storedooo] login ok host={login_info.get('host')}")
        rows = fetch_products_api(login_info)
        if rows:
            print(f"[storedooo] API returned {len(rows)} rows via authenticated")
            return [normalize_row(r,i) for i,r in enumerate(rows)]
        else:
            print("[storedooo] API returned empty via auth, trying guest")
    else:
        print("[storedooo] login failed, trying guest API")
    rows = fetch_products_api(None)
    if rows:
        print(f"[storedooo] guest API returned {len(rows)} rows")
        return [normalize_row(r,i) for i,r in enumerate(rows)]
    print("[storedooo] no products found via any method — returning empty (will retry on Actions)")
    return []

if __name__ == '__main__':
    prods = fetch_all()
    out = os.path.join(DATA, 'storedooo.json')
    lite = os.path.join(DATA, 'lite_storedooo.json')
    for p in (out, lite):
        with open(p, 'w', encoding='utf-8') as f:
            json.dump(prods, f, ensure_ascii=False, indent=2)
    print(f"Wrote {len(prods)} to {out}")
