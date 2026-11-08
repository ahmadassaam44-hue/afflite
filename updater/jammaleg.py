#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
جماليج (jammaleg.com) — جلب كتالوج المسوق بالعمولة
- يدعم تسجيل دخول ويب عبر POST https://jammaleg.com/login (CSRF) ثم سحب المنتجات من /products
- ويدعم API عبر POST https://jammaleg.com/api/login {phone,password} -> JWT
- لو فشل الويب، يحاول API؛ لو فشل الاثنين يحاول الضيف
"""
import os, re, json, time, urllib.request, urllib.error, urllib.parse, http.cookiejar, ssl
import requests

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'data')
UA = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36', 'Accept-Language': 'ar,en;q=0.8', 'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'}

DEFAULT_EMAIL = 'ae782236@gmail.com'
DEFAULT_PASS = 'aA55667788'

API_BASE = 'https://jammaleg.com'

# ---------- API helpers ----------
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

def try_login_api(email, password):
    payload = {"phone": email, "password": password}
    j, hdr, txt = _http_json(API_BASE + "/api/login", method='POST', data=payload)
    if j and isinstance(j, dict) and j.get('status') == 1:
        data = j.get('data', {})
        token = data.get('api_token') if isinstance(data, dict) else None
        if token:
            user = data.get('user', {}) if isinstance(data, dict) else {}
            ban = user.get('ban')
            if str(ban) == "1":
                print(f"[jammaleg] WARNING: API account ban=1")
            return {'token': token, 'user': user, 'raw': j}
    # try email variant
    payload2 = {"email": email, "password": password}
    j2, _, _ = _http_json(API_BASE + "/api/login", method='POST', data=payload2)
    if j2 and j2.get('status') == 1:
        data = j2.get('data', {})
        token = data.get('api_token') if isinstance(data, dict) else None
        if token:
            return {'token': token, 'user': data.get('user', {}), 'raw': j2}
    return None

def fetch_products_api(token):
    headers = {"Authorization": f"Bearer {token}"}
    all_rows = []
    for page in range(1, 20):
        urls = [
            f"{API_BASE}/api/products?page={page}&per_page=100",
            f"{API_BASE}/api/products?page={page}",
            f"{API_BASE}/api/products",
        ]
        found = False
        for url in urls:
            j, _, txt = _http_json(url, headers=headers)
            rows = None
            if isinstance(j, dict):
                if j.get('status') == 1:
                    data = j.get('data')
                    if isinstance(data, list):
                        rows = data
                    elif isinstance(data, dict):
                        for k in ('products','items','data','list','rows'):
                            if k in data and isinstance(data[k], list):
                                rows = data[k]; break
                    if rows is None and isinstance(j.get('data'), list):
                        rows = j['data']
                elif j.get('status') == 0 and 'No products' in str(j.get('msg')):
                    rows = []
            elif isinstance(j, list):
                rows = j
            if rows is not None:
                if len(rows)==0:
                    if page==1 and not all_rows:
                        continue
                    else:
                        return all_rows
                else:
                    all_rows.extend(rows)
                    found = True
                    if len(rows) < 20:
                        return all_rows
                    break
        if not found and page==1:
            # try per category
            cats = fetch_categories_api(token)
            if cats:
                for cat in cats:
                    cid = cat.get('id')
                    for url in [f"{API_BASE}/api/products?category_id={cid}&page={page}&per_page=100"]:
                        j,_,_= _http_json(url, headers=headers)
                        rows=None
                        if isinstance(j, dict) and j.get('status')==1:
                            data=j.get('data')
                            if isinstance(data, list):
                                rows=data
                            elif isinstance(data, dict):
                                for k in ('products','items'):
                                    if k in data and isinstance(data[k], list):
                                        rows=data[k]; break
                        if rows:
                            all_rows.extend(rows)
                    time.sleep(0.1)
                if all_rows:
                    return all_rows
            return all_rows
        if found:
            continue
        else:
            break
    return all_rows

def fetch_categories_api(token):
    headers = {"Authorization": f"Bearer {token}"}
    j, _, _ = _http_json(API_BASE + "/api/categories", headers=headers)
    if j and isinstance(j, dict) and j.get('status')==1:
        data=j.get('data',{})
        cats=data.get('categories') if isinstance(data, dict) else None
        if isinstance(cats, list):
            return cats
    return []

# ---------- Web scraping helpers ----------
def web_login(email, password):
    s = requests.Session()
    s.headers.update({"User-Agent":"Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36","Accept":"text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8","Accept-Language":"ar,en;q=0.8"})
    try:
        r = s.get("https://jammaleg.com/affiliate/login", timeout=20)
        m = re.search(r'name="_token" value="([^"]+)"', r.text)
        if not m:
            # try csrf-token meta
            m2 = re.search(r'name="csrf-token" content="([^"]+)"', r.text)
            if not m2:
                print("[jammaleg] web login: no csrf token")
                return None
            tok = m2.group(1)
        else:
            tok = m.group(1)
        # POST to https://jammaleg.com/login
        payload = {"_token": tok, "email": email, "password": password}
        r2 = s.post("https://jammaleg.com/login", data=payload, headers={"Referer":"https://jammaleg.com/affiliate/login","Origin":"https://jammaleg.com"}, timeout=20, allow_redirects=True)
        if "affiliate/dashboard" in r2.text or "لوحة التحكم" in r2.text or r2.url.endswith("/affiliate/dashboard") or "الرقم التعريفى" in r2.text:
            return s
        # also try phone field
        payload2 = {"_token": tok, "phone": email, "password": password}
        r3 = s.post("https://jammaleg.com/login", data=payload2, headers={"Referer":"https://jammaleg.com/affiliate/login","Origin":"https://jammaleg.com"}, timeout=20, allow_redirects=True)
        if "affiliate/dashboard" in r3.text or "لوحة التحكم" in r3.text or "الرقم التعريفى" in r3.text:
            return s
        # check if login succeeded via checking cookies or dashboard access
        r4 = s.get("https://jammaleg.com/products", timeout=15)
        if "المنتج" in r4.text and "سعر الشراء" in r4.text:
            return s
        print(f"[jammaleg] web login failed final url {r2.url} len {len(r2.text)}")
        return None
    except Exception as e:
        print(f"[jammaleg] web login exception {e}")
        return None

def fetch_products_web(session):
    all_rows = []
    page = 1
    last_page = 1
    while page <= last_page and page <= 30:
        try:
            url = f"https://jammaleg.com/products?page={page}"
            r = session.get(url, timeout=20)
            if r.status_code != 200:
                print(f"[jammaleg] web products page {page} status {r.status_code}")
                break
            html = r.text
            # detect pagination
            nums = [int(x) for x in re.findall(r'/products\?page=(\d+)', html)]
            if nums:
                last_page = max(last_page, max(nums))
            # parse table rows
            from bs4 import BeautifulSoup
            soup = BeautifulSoup(html, 'lxml')
            tbl = soup.find('table', class_=re.compile(r'table-products'))
            if not tbl:
                print(f"[jammaleg] no table on page {page}")
                break
            tbody = tbl.find('tbody')
            trs = tbody.find_all('tr') if tbody else []
            if not trs:
                trs = tbl.find_all('tr')[1:]
            page_rows = []
            for tr in trs:
                tds = tr.find_all(['td','th'])
                if len(tds) < 3:
                    continue
                # cols: المنتج, البائع, سعر الشراء, العمولة, عدد القطع, الكتالوج, العمليات
                # first td contains product name + image + catalog zip link
                name_td = tds[0]
                name = name_td.get_text(" ", strip=True).replace("تنزيل الكتالوج","").strip()
                # sometimes name includes extra, clean
                # get image
                img_tag = name_td.find('img')
                img_url = img_tag.get('src') if img_tag and img_tag.get('src') else ""
                if img_url.startswith('/'):
                    img_url = API_BASE + img_url
                # get product detail link
                detail_link = ""
                for a in tr.find_all('a', href=True):
                    href = a.get('href')
                    if '/product/detils/' in href:
                        detail_link = href
                        break
                pid = ""
                if detail_link:
                    m = re.search(r'/product/detils/(\d+)', detail_link)
                    if m:
                        pid = m.group(1)
                if not pid:
                    continue
                # seller
                seller = tds[1].get_text(" ", strip=True) if len(tds) > 1 else ""
                # purchase price
                price_text = tds[2].get_text(" ", strip=True) if len(tds) > 2 else ""
                price = 0
                pm = re.search(r'(\d+(?:[.,]\d+)?)', price_text.replace(',',''))
                if pm:
                    try: price = float(pm.group(1).replace(',',''))
                    except: price = 0
                # commission text (col 3) - currently "حسب سعر البيع"
                comm_text = tds[3].get_text(" ", strip=True) if len(tds) > 3 else ""
                commission = 0
                # try to parse numeric commission if present
                cm = re.search(r'(\d+(?:[.,]\d+)?)\s*ج\.م', comm_text)
                if cm:
                    try: commission = float(cm.group(1))
                    except: commission = 0
                # stock status
                stock_text = tds[4].get_text(" ", strip=True) if len(tds) > 4 else ""
                stock = 100
                if "علي وشك الانتهاء" in stock_text:
                    stock = 5
                elif "متوفر متوسط" in stock_text:
                    stock = 50
                elif "متوفر بكثرة" in stock_text:
                    stock = 100
                elif "غير متوفر" in stock_text or "نفذ" in stock_text:
                    stock = 0
                page_rows.append({
                    'id': pid,
                    'name': name,
                    'seller': seller,
                    'price': price,
                    'commission': commission,
                    'stock_text': stock_text,
                    'stock': stock,
                    'image': img_url,
                    'detail_url': detail_link if detail_link.startswith('http') else API_BASE + detail_link,
                })
            log_extra = f" page {page}: {len(page_rows)} rows"
            print(f"[jammaleg] fetched{log_extra} last_page={last_page}")
            if not page_rows:
                break
            all_rows.extend(page_rows)
            if page >= last_page:
                break
            page += 1
            time.sleep(0.3)
        except Exception as e:
            print(f"[jammaleg] fetch web page {page} fail {e}")
            break
    return all_rows

def fetch_product_detail_web(session, pid):
    try:
        url = f"{API_BASE}/product/detils/{pid}"
        r = session.get(url, timeout=20)
        if r.status_code != 200:
            return {}
        html = r.text
        from bs4 import BeautifulSoup
        soup = BeautifulSoup(html, 'lxml')
        # images - carousel
        imgs = re.findall(r'https://jammaleg\.com/uploads/products_images/[^\"\'\s<>]+', html)
        # deduplicate
        images = list(dict.fromkeys(imgs))
        # price already have from list, but re-parse from detail for accuracy
        price = 0
        m = re.search(r'سعر الشراء:\s*<span>\s*([\d.,]+)\s*ج\.م', html)
        if m:
            try: price = float(m.group(1).replace(',',''))
            except: price = 0
        # seller
        seller = ""
        sm = re.search(r'البائع\s*:\s*<span>(.*?)</span>', html)
        if sm:
            seller = re.sub(r'<[^>]+>', '', sm.group(1)).strip()
        # description
        desc = ""
        desc_p = soup.find('p', class_=re.compile(r'prodcut-titles'))
        if desc_p:
            desc = desc_p.get_text(" ", strip=True)[:800]
            if not desc:
                desc = soup.find('div', class_=re.compile(r'component-What')).get_text(" ", strip=True)[:800] if soup.find('div', class_=re.compile(r'component-What')) else ""
        # sizes, colors, stock table
        sizes = []
        colors = []
        variants = []
        # try to parse table with sizes
        tbl = soup.find('table', class_=re.compile(r'table-product'))
        if tbl:
            # header row
            ths = [th.get_text(strip=True) for th in tbl.find_all('th')]
            # first row is sizes header (after "المقاس")
            # We'll extract sizes from the HTML directly via parsing rows
            rows = tbl.find_all('tr')
            if len(rows) >= 3:
                # row 0: header sizes
                header_cells = [c.get_text(strip=True) for c in rows[0].find_all(['th','td'])]
                # first cell is "المقاس", rest are sizes
                if header_cells and header_cells[0] == "المقاس":
                    sizes_raw = header_cells[1:]
                    sizes = list(dict.fromkeys([s for s in sizes_raw if s and s != "المقاس"]))
                # row 1: stock per variant
                stock_cells = [c.get_text(strip=True) for c in rows[1].find_all(['th','td'])]
                # row 2: colors
                color_cells = [c.get_text(strip=True) for c in rows[2].find_all(['th','td'])]
                if color_cells and color_cells[0] == "اللون":
                    colors = list(dict.fromkeys([c for c in color_cells[1:] if c and c != "اللون"]))
                # Build variants
                # For each variant column, we have size, stock, color
                # sizes_raw length = colors len = stock len
                # Example: sizes = [L,xl,2XL] repeated per color, colors = [اسود,ابيض,...]
                # We can pair them by index
                stock_vals = stock_cells[1:] if stock_cells and stock_cells[0] == "عدد القطع" else stock_cells
                for idx, sz in enumerate(sizes_raw):
                    col = stock_vals[idx] if idx < len(stock_vals) else ""
                    col_color = colors[idx] if idx < len(colors) else ""
                    stock_v = 100
                    if "علي وشك" in col: stock_v = 5
                    elif "متوسط" in col: stock_v = 50
                    elif "بكثرة" in col: stock_v = 100
                    elif "غير" in col: stock_v = 0
                    variants.append({'size': sz, 'color': col_color, 'stock': stock_v})
            else:
                # fallback: try regex for sizes
                pass
        # if still empty, try to regex sizes from desc
        if not sizes:
            # try to find sizes in desc like L-XL-XXL or numeric
            sm2 = re.search(r'مقاسات\s*\(?([^\)]+)\)?', html, re.I)
            if sm2:
                block = sm2.group(1)
                sz = re.findall(r'\b(?:3xl|2xl|xl|l|m|s|xs|\d{2,3})\b', block, re.I)
                if sz:
                    sizes = list(dict.fromkeys([s.lower() for s in sz]))
        if not colors:
            # try to find colors in desc
            cm2 = re.search(r'الوان\s*\(?([^\)]+)\)?', html, re.I)
            if cm2:
                block2 = cm2.group(1)
                cols = re.findall(r'(?:اسود|ابيض|بني|بيج|احمر|ازرق|اخضر|اصفر|بنفسجي|رمادي|زهري|برتقالي|كحلي|لبني|نبيتي|رصاصى|سماوى)', block2)
                if cols:
                    colors = list(dict.fromkeys(cols))
        return {
            'images': images[:8],
            'price': price,
            'seller': seller,
            'desc': desc,
            'sizes': sizes,
            'colors': colors,
            'variants': variants,
        }
    except Exception as e:
        print(f"[jammaleg] detail {pid} fail {e}")
        return {}

# ---------- Common ----------
def guess_category(name):
    n = name.lower()
    if any(x in n for x in ["حذاء","كوتشي","شوز","جزمه","sneaker","boot","شبشب","صندل"]):
        return "أحذية"
    if "شنطة" in name or "شنطه" in name or "حقيبة" in name or "bag" in name.lower() or "محفظه" in name:
        return "شنط ومحافظ"
    if any(x in name for x in ["تيشرت","تيشيرت","ترنج","بنطلون","قميص","جاكيت","سويت","هودي","بلوزة","فستان","عباية","جيبة","تنورة","بوكسر","طقم","براه","لانجيري"]):
        if "اطفال" in name or "أطفال" in name or "طفل" in name.lower():
            return "أطفال وألعاب"
        if any(x in name for x in ["حريمي","نسائي","نساء","بناتي","انسات"]):
            return "ملابس حريمي"
        return "ملابس رجالي"
    if "مروحه" in name or "ماكينة" in name or "راديو" in name or "كبة" in name or "مروحة" in name:
        if "سيارة" in name:
            return "مستلزمات السيارات"
        return "المنزل والمطبخ"
    if "مكياج" in name or "عطر" in name:
        return "العناية والتجميل"
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
        "ميكساتك": "المنزل والمطبخ",
        "اكسسوارات": "إكسسوارات",
        "الكترونيات": "إلكترونيات",
    }
    if raw_cat in jammaleg_to_site:
        return jammaleg_to_site[raw_cat]
    return guess_category(name)

def normalize_row(raw, idx=0):
    # raw may be from web list + detail merged
    pid = str(raw.get('id') or raw.get('_id') or raw.get('product_id') or raw.get('code') or raw.get('slug') or f"jammaleg-{idx}")
    name = raw.get('name') or raw.get('title') or raw.get('name_ar') or raw.get('product_name') or pid
    price = raw.get('price') or raw.get('selling_price') or raw.get('purchase_price') or 0
    commission = raw.get('commission') or raw.get('affiliate_commission') or raw.get('profit') or 0
    images = raw.get('images') or []
    if isinstance(images, str):
        images = [images]
    # ensure absolute
    abs_images = []
    for u in images:
        if isinstance(u, str):
            if u.startswith('//'): u = 'https:'+u
            elif u.startswith('/'): u = API_BASE + u
            elif not u.startswith('http') and 'uploads' in u:
                u = API_BASE + '/' + u.lstrip('/')
            abs_images.append(u)
    images = abs_images
    # sizes/colors/variants
    sizes = raw.get('sizes') or []
    colors = raw.get('colors') or []
    variants = raw.get('variants') or []
    desc = raw.get('desc') or raw.get('description') or ""
    cat_raw = raw.get('category') or raw.get('category_name') or ""
    if isinstance(cat_raw, dict):
        cat_raw = cat_raw.get('name') or ""
    category = map_category(str(cat_raw), str(name))
    try: price=float(price)
    except: price=0
    try: commission=float(commission)
    except: commission=0
    total_price = price + commission if commission else price
    # stock
    stock = raw.get('stock') or 100
    try: stock=int(float(stock))
    except: stock=100
    if stock <=0:
        stock=0
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
        'images': images[:8],
        'sizes': sizes if isinstance(sizes, list) else [],
        'extra': {
            'colors': colors if isinstance(colors, list) else [],
            'variants_detailed': variants if isinstance(variants, list) else [],
            'source_commission_original': commission,
            'orig_suggested': float(total_price),
            'source_url': raw.get('source_url') or f'https://jammaleg.com/product/detils/{pid}',
            'jammaleg_id': str(pid),
            'category_raw': str(cat_raw),
            'seller': str(raw.get('seller') or ""),
        },
        'added_at': time.strftime('%Y-%m-%d'),
        'desc': str(desc)[:900],
    }

def fetch_all(email=None, password=None):
    email = email or os.environ.get('JAMMALEG_USER') or os.environ.get('MNFEAN_USER') or DEFAULT_EMAIL
    password = password or os.environ.get('JAMMALEG_PASS') or os.environ.get('MNFEAN_PASS') or DEFAULT_PASS
    print(f"[jammaleg] trying login as {email}")
    # First try web login (preferred - gets products even when ban=1 for API)
    sess = web_login(email, password)
    if sess:
        print(f"[jammaleg] web login ok")
        rows = fetch_products_web(sess)
        if rows:
            print(f"[jammaleg] web fetched {len(rows)} basic rows")
            out = []
            for i, r in enumerate(rows):
                pid = r['id']
                detail = fetch_product_detail_web(sess, pid)
                # merge
                merged = dict(r)
                if detail:
                    if detail.get('images'):
                        merged['images'] = detail['images']
                    if detail.get('price') and detail['price'] > 0:
                        merged['price'] = detail['price']
                    if detail.get('desc'):
                        merged['desc'] = detail['desc']
                    if detail.get('sizes'):
                        merged['sizes'] = detail['sizes']
                    if detail.get('colors'):
                        merged['colors'] = detail['colors']
                    if detail.get('variants'):
                        merged['variants'] = detail['variants']
                    if detail.get('seller'):
                        merged['seller'] = detail['seller']
                # build category guess if not present
                merged['category'] = guess_category(merged.get('name',''))
                out.append(normalize_row(merged, i))
                if i < 3:
                    print(f"[jammaleg] sample {i} {merged.get('name')} price {merged.get('price')} imgs {len(merged.get('images',[]))}")
                time.sleep(0.2)
            if out:
                return out
        else:
            print("[jammaleg] web fetched 0 rows - trying API")
    else:
        print("[jammaleg] web login failed - trying API")
    # fallback API
    login_info = try_login_api(email, password)
    if login_info:
        token = login_info.get('token')
        print(f"[jammaleg] API login ok ban={login_info.get('user',{}).get('ban')}")
        rows = fetch_products_api(token)
        if rows:
            print(f"[jammaleg] API returned {len(rows)} rows")
            out=[]
            for i, r in enumerate(rows):
                if not r.get('images'):
                    detail = fetch_product_detail_api(token, r.get('id')) if 'fetch_product_detail_api' in globals() else {}
                    if detail:
                        for k in ('images','gallery','description'):
                            if k in detail and detail[k]:
                                r[k]=detail[k]
                out.append(normalize_row(r, i))
            return out
        else:
            print("[jammaleg] API returned empty")
    # guest fallback
    print("[jammaleg] trying guest fallback")
    guest = scrape_guest_products()
    if guest:
        print(f"[jammaleg] guest scrape returned {len(guest)} rows")
        return [normalize_row(r,i) for i,r in enumerate(guest)]
    print("[jammaleg] no products found via any method")
    return []

def fetch_product_detail_api(token, pid):
    headers = {"Authorization": f"Bearer {token}"}
    for path in [f"/api/product/{pid}", f"/api/products/{pid}"]:
        j, _, _ = _http_json(API_BASE + path, headers=headers)
        if j and isinstance(j, dict) and j.get('status')==1:
            data=j.get('data')
            if isinstance(data, dict):
                return data
    return {}

def scrape_guest_products():
    # fallback homepage scrape as guest
    try:
        import requests as rq
        s=rq.Session()
        s.headers.update(UA)
        r=s.get(API_BASE + "/", timeout=20)
        ids=re.findall(r'/product/detils/(\d+)', r.text)
        seen=set(); uniq=[]
        for i in ids:
            if i not in seen:
                seen.add(i); uniq.append(i)
        rows=[]
        for pid in uniq[:20]:
            r2=s.get(f"{API_BASE}/product/detils/{pid}", timeout=15)
            if 'تسجيل الدخول' in r2.text:
                continue
            m=re.search(r'سعر الشراء.*?([\d.,]+)\s*ج\.م', r2.text)
            price=0
            if m:
                try: price=float(m.group(1).replace(',',''))
                except: price=0
            imgs=re.findall(r'https://jammaleg\.com/uploads/products_images/[^\"\'\s<>]+', r2.text)
            name=pid
            mt=re.search(r'<title>(.*?)</title>', r2.text, re.I|re.S)
            if mt:
                name=re.sub(r'\s*[-|].*jammal.*','', mt.group(1), flags=re.I).strip() or name
            if price>0:
                rows.append({'id':pid,'name':name,'price':price,'images':list(dict.fromkeys(imgs))[:4]})
            time.sleep(0.1)
        return rows
    except Exception as e:
        print(f"[jammaleg] guest fail {e}")
        return []

if __name__ == '__main__':
    prods = fetch_all()
    out = os.path.join(DATA, 'jammaleg.json')
    lite = os.path.join(DATA, 'lite_jammaleg.json')
    for p in (out, lite):
        with open(p, 'w', encoding='utf-8') as f:
            json.dump(prods, f, ensure_ascii=False, indent=2)
    print(f"Wrote {len(prods)} to {out}")
