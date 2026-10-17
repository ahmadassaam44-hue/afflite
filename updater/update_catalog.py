#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
محدّث الكتالوج الأوتوماتيكي — يعمل من GitHub Actions كل 6 ساعات.
  1) يجيب الأسعار/المنتجات الجديدة من المصادر (HevoTeam, G2G, + أي مصدر JSON إضافي)
  2) يدمجها مع data/*.json مع الحفاظ على الـ IDs، ويسجّل تاريخ الأسعار (old_price / price_history)
  3) يحافظ على الكتالوج كاملاً ويعامل كل المنتجات كإنتاج مفتوح بغض النظر عن المخزون
  4) يعيد بناء lite_*.json ويكتب catalog_update.json
  5) يرفع الملفات المتغيرة فقط على FTP مع تحقق بايت-ببايت في نفس الاتصال
"""
import json, os, re, sys, time, io, hashlib, ftplib, urllib.request, urllib.parse, datetime, html

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'data')
USD_EGP = float(os.environ.get('USD_EGP', '56'))
HEVO_MARKUP = 1.29            # هامش السوشيال فوق سعر Hevo
SOCIAL_PRICE_ADDON = 0.10     # إضافة ثابتة للخدمات الأرخص منها بس (أقل من 10 قروش)
G2G_MARKUP = 1.0              # الرقمي: لا عمولة — سعر البيع = سعر المصدر (الدولار × USD_EGP) بالظبط
LITE_KEYS = ['id','orig_id','name','sku','price','cost','old_price','discount','price_text','commission','currency','category','subcategory','country','stock','images','sizes','extra']
UA = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36', 'Accept-Language': 'ar,en;q=0.8'}
NOW = datetime.datetime.now(datetime.timezone.utc).astimezone().isoformat(timespec='seconds')
TODAY = NOW[:10]
summary = {}
changed_files = set()

LOG_LINES = []  # بنحفظ سطور اللوج عشان تتكتب في data/_deploy_log.json وتُقرأ من الريبو (لوج الـActions نفسه بيضيع)

def log(*a):
    line = time.strftime('%H:%M:%S') + ' ' + ' '.join(str(x) for x in a)
    print(line, flush=True)
    LOG_LINES.append(line)
    if len(LOG_LINES) > 600:
        del LOG_LINES[:300]
def jload(name, default):
    p = os.path.join(DATA, name)
    if not os.path.exists(p): return default
    with open(p, encoding='utf-8') as f: return json.load(f)
def jsave(name, obj):
    p = os.path.join(DATA, name)
    new = json.dumps(obj, ensure_ascii=False, separators=(',', ':'))
    old = open(p, encoding='utf-8').read() if os.path.exists(p) else None
    if old == new: return False
    with open(p, 'w', encoding='utf-8') as f: f.write(new)
    changed_files.add('data/' + name); return True
def http(url, timeout=40):
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=timeout) as r: return r.read().decode('utf-8', 'ignore')
def round_half(v):
    """أقرب 0.5 / 1.0"""
    return round(round(float(v) * 2) / 2, 2)
def social_norm(v):
    """تقريب سعر السوشيال: فوق 1 ج.م لأقرب نص جنيه، وتحته بكسر الجنيه بالظبط.
    الإضافة الثابتة (10 قروش) بقت للخدمات الأرخص من الإضافة نفسها بس — أي مبلغ
    أقل من 0.10 ج.م بيتضاف عليه، وأي مبلغ أكبر بيفضل سعر المصدر زي ما هو."""
    v = float(v)
    if 0 < v < SOCIAL_PRICE_ADDON: v += SOCIAL_PRICE_ADDON
    return round_half(v) if v >= 1 else round(v, 2)


def round_5_or_0(v):
    """تقريب أسعار العروض لأقرب «5 أو 0» — نفس wf_round_5_or_0 في config.php:
    10 ج.م وأكتر → لأقرب رقم ينتهي بـ0 أو 5 (وما ينزلش أقل من السعر المحسوب)،
    من 1 لأقل من 10 → لأقرب نص جنيه، وأقل من جنيه → قروش زي ما هو."""
    v = float(v or 0)
    if v <= 0:
        return 0.0
    if v >= 10:
        return float(__import__('math').ceil(round(v, 4) / 5) * 5)
    if v >= 1:
        return float(__import__('math').ceil(round(v, 4) * 2) / 2)
    return round(v, 2)


PRICE_MENTION_RE_MAIN = r'بسعر\s*[\d.,]+\s*ج\.م(?:\s*=\s*\$[\d.]+)?'
PRICE_MENTION_RE_FROM = r'تبدأ من\s*[\d.,]+\s*ج\.م'


def heal_price_mentions(text, main_price, usd=0.0, cheapest=0.0):
    """يصلّح أي سعر مكتوب جوه نص المنتج (وصف/تنبيه) عشان يطابق السعر المعروض بعد التقريب.
    بيرجّع (النص, عدد التصحيحات)."""
    if not text:
        return text, 0
    reps = 0

    def _sub(pattern, repl, s):
        """subn بتحسب المطابقات كلها — هنا بنحسب اللي اتغيّر فعلاً بس (عشان العدّ يكون دقيق ومتكررش)."""
        n = [0]

        def _wrap(m):
            r = repl(m)
            if r != m.group(0):
                n[0] += 1
            return r

        return re.sub(pattern, _wrap, s), n[0]

    if main_price > 0:
        def _main(m):
            base = f'بسعر {main_price:g} ج.م'
            return base + (f' = ${usd:.2f}' if usd > 0 else '')
        text, k = _sub(PRICE_MENTION_RE_MAIN, _main, text)
        reps += k
    if cheapest > 0:
        text, k = _sub(PRICE_MENTION_RE_FROM, lambda m: f'تبدأ من {cheapest:g} ج.م', text)
        reps += k
    return text, reps


def price_text(v, cur='ج.م'):
    return (str(int(v)) if float(v) == int(v) else f'{v:.2f}'.rstrip('0').rstrip('.')) + ' ' + cur

# ---------------------------------------------------------------- price history
history = jload('price_history.json', {})
def record_price(key, new_price, old_price):
    new_price = round(float(new_price), 2); old_price = round(float(old_price or 0), 2)
    h = history.setdefault(key, [])
    if not h or abs(h[-1]['p'] - new_price) > 0.001:
        h.append({'d': TODAY, 'p': new_price})
        if len(h) > 60: del h[:-60]
    return old_price > 0 and abs(new_price - old_price) > 0.001

def apply_price(item, key, new_price, new_cost=None):
    """يحدّث السعر ويحط old_price/discount لو نزل"""
    old = float(item.get('price') or 0)
    changed = record_price(key, new_price, old)
    if changed:
        if new_price < old:
            item['old_price'] = old; item['discount'] = int(round((old - new_price) / old * 100))
            item['price_drop_at'] = TODAY
        elif new_price > old:
            item.pop('old_price', None); item.pop('discount', None); item.pop('price_drop_at', None)
        item['price'] = new_price; item['price_text'] = price_text(new_price, item.get('currency', 'ج.م'))
        item['price_updated_at'] = TODAY
    if new_cost is not None:
        item['cost'] = round(float(new_cost), 2)
        item['commission'] = round(max(0, item['price'] - item['cost']), 2)
    return changed

# ---------------------------------------------------------------- HevoTeam (social)
def update_hevo():
    log('Hevo: fetching services page…')
    page = http('https://hevoteam.com/services')
    cats = dict(re.findall(r'data-id="(\d+)"\s+onclick="svcCatSelect\(this,\'\d+\'\)">\s*<span class="svc-cat-item-ico">.*?</span>\s*<span>([^<]+)</span>', page, re.S))
    blocks = re.findall(r'<div class="services-list-item select-service"(.*?)(?=<div class="services-list-item select-service"|<script|$)', page, re.S)
    svcs = {}
    for b in blocks:
        m = re.search(r'data-svc-id="(\d+)"\s+data-svc-name="([^"]*)"', b)
        if not m: continue
        sid, name = m.group(1), html.unescape(m.group(2)).strip()
        mn = re.search(r'الحد الأدنى:\s*<b>([\d,]+)</b>', b); mx = re.search(r'الحد الأقصى:\s*<b>([\d,]+)</b>', b)
        pr = re.search(r'services-item-price">\s*([\d.,]+)\s*EGP', b)
        cat = re.search(r'data-filter-table-category-id="(\d+)"', b)
        tm = re.search(r'متوسط وقت التنفيذ[^<]*<b>([^<]+)</b>', b) or re.search(r'(\d+\s*(?:ساعات|ساعة|دقائق|دقيقة|أيام|يوم)[^<]{0,30})', b)
        refill = 'إعادة تعبئة' in name or 'refill' in name.lower()
        if not pr: continue
        svcs[sid] = {'id': sid, 'name': name, 'min': int((mn.group(1) if mn else '1').replace(',', '')), 'max': int((mx.group(1) if mx else '100000').replace(',', '')),
                     'rate': float(pr.group(1).replace(',', '')), 'cat': cats.get(cat.group(1) if cat else '', 'خدمات جديدة'), 'time': tm.group(1).strip() if tm else '', 'refill': refill}
    log(f'Hevo: parsed {len(svcs)} services')
    if len(svcs) < 100: raise RuntimeError('Hevo parse too small — page layout changed?')

    social = jload('social.json', [])
    by_sid = {str((x.get('extra') or {}).get('service_id', '')): x for x in social}
    updated = added = dropped = 0
    seen = set()
    for sid, s in svcs.items():
        seen.add(sid)
        if sid == '6489':  # الخدمة المخصصة — لا تُلمس
            continue
        if sid in by_sid:
            it = by_sid[sid]; ex = it.setdefault('extra', {})
            ratio = (float(ex.get('price_per_1000') or 0) / float(ex.get('rate_per_1000') or 1)) if ex.get('rate_per_1000') else HEVO_MARKUP
            ratio = min(max(ratio, 1.05), 2.5)
            new_pk = round_half(s['rate'] * ratio); new_pk = max(new_pk, 1.0)
            old_pk = float(ex.get('price_per_1000') or 0)
            ex['rate_per_1000'] = s['rate']; ex['order_min'] = s['min']; ex['order_max'] = s['max']
            if s['time']: ex['avg_time'] = s['time']
            it['stock'] = 999999
            if abs(new_pk - old_pk) > 0.001:
                ex['price_per_1000'] = new_pk
                # إعادة حساب الباقات
                for v in ex.get('variants_detailed', []):
                    q = float(str(v.get('size', '')).replace(',', '').split()[0] or 0) if str(v.get('size', '')).strip() else 0
                    if q > 0: v['price'] = social_norm(new_pk * q / 1000)
                unit_min = social_norm(new_pk * s['min'] / 1000)
                apply_price(it, 'social#' + it['id'], unit_min, round(s['rate'] * s['min'] / 1000, 2))
                it['desc'] = re.sub(r'الحد الأدنى للطلب: [\d,]+ \| الحد الأقصى: [\d,]+', f"الحد الأدنى للطلب: {s['min']:,} | الحد الأقصى: {s['max']:,}", it.get('desc', ''))
                updated += 1
        else:
            new_pk = max(1.0, round_half(s['rate'] * HEVO_MARKUP))
            sizes = [q for q in [100, 250, 500, 1000, 2500, 5000, 10000] if s['min'] <= q <= s['max']] or [s['min']]
            unit_min = social_norm(new_pk * s['min'] / 1000)
            it = {'id': 'hevo-' + sid, 'name': s['name'], 'sku': 'HEVO-' + sid, 'price': unit_min, 'cost': round(s['rate'] * s['min'] / 1000, 2),
                  'price_text': price_text(unit_min), 'currency': 'ج.م', 'commission': round(max(0, unit_min - s['rate'] * s['min'] / 1000), 2),
                  'category': 'سوشيال ميديا', 'subcategory': s['cat'], 'country': 'مصر', 'seller': 'HEVO', 'stock': 999999,
                  'images': ['assets/icon-512.png'],  # لوجو الموقع بدل صور placehold.co القديمة
                  'sizes': [f'{q:,}' for q in sizes],
                  'desc': f"{s['name']}\nالحد الأدنى للطلب: {s['min']:,} | الحد الأقصى: {s['max']:,}" + (f"\nمتوسط وقت التنفيذ: {s['time']}" if s['time'] else '') + "\n⚠️ عند الطلب ضع رابط الحساب أو المنشور في ملاحظات الطلب.",
                  'added_at': TODAY,
                  'extra': {'colors': [], 'variants_detailed': [{'size': f'{q:,} وحدة', 'color': '', 'price': social_norm(new_pk * q / 1000)} for q in sizes],
                            'service_id': sid, 'rate_per_1000': s['rate'], 'price_per_1000': new_pk, 'order_min': s['min'], 'order_max': s['max'],
                            'avg_time': s['time'], 'order_field': 'link', 'platform': 'social', 'material': 'رقمي', 'delivery': 'حسب متوسط الوقت',
                            'refill': 'مدعومة' if s['refill'] else 'غير مدعومة'}}
            record_price('social#' + it['id'], unit_min, 0)
            social.append(it); added += 1
    for it in social:
        sid = str((it.get('extra') or {}).get('service_id', ''))
        if sid and sid != '6489' and sid not in seen and it.get('stock', 1) != 0:
            it['stock'] = 0; it['unavailable_since'] = TODAY; dropped += 1
    jsave('social.json', social)
    summary['social'] = {'total': len(social), 'updated': updated, 'added': added, 'unavailable': dropped}
    log('Hevo done', summary['social'])

# ---------------------------------------------------------------- G2G (digital) — تحديث سعر/توفر كل عرض
def heal_g2g_item(it):
    """إصلاح أوفلاين (بدون شبكة) لسعر/وصف منتج رقمي:
    السعر = الدولار المخزّن (unit_price_usd أو price_usd) × USD_EGP مقرّب لأقرب 5 أو 0،
    وتصحيح أي سعر مكتوب في سطر العرض (سعر الحساب/إجمالي الباقة) أو في نصوص المنتج.
    بيرجّع (عدد أسعار العرض اللي اتصلحت, عدد النصوص اللي اتصلحت, هل سعر المنتج اتغيّر)."""
    ex = it.get('extra') if isinstance(it.get('extra'), dict) else {}
    vs = ex.get('variants_detailed') or []
    fixed_prices = 0
    for v in vs:
        # العرض ممكن يكون بصيغة جديدة (unit_price_usd) أو قديمة (price_usd)
        usd = float(v.get('unit_price_usd') or v.get('price_usd') or 0)
        if usd > 0:
            cost = round(usd * USD_EGP, 2)
            price = round_5_or_0(cost * G2G_MARKUP)
        else:
            # مافيشش سعر دولار مخزّن — نقرّب السعر المخزّن نفسه لأقرب 5 أو 0
            cost = float(v.get('cost') or 0)
            price = round_5_or_0(float(v.get('price') or 0))
        if price <= 0:
            continue
        price_changed = abs(price - float(v.get('price') or 0)) > 0.001
        cost_changed = usd > 0 and abs(cost - float(v.get('cost') or 0)) > 0.001
        usd_missing = usd > 0 and float(v.get('unit_price_usd') or 0) <= 0
        if not (price_changed or cost_changed or usd_missing):
            continue
        v['cost'] = cost
        v['price'] = price
        v['unit_price_egp'] = price
        if usd > 0:
            v['unit_price_usd'] = usd       # الصيغة الموحّدة (PHP بيحسب منها السعر المعروض)
            v['price_usd'] = usd
        attr = str(v.get('attr') or '')
        if 'سعر الحساب:' in attr:
            if usd > 0:
                attr = re.sub(r'سعر الحساب: [\d.,]+ ج\.م \(\$[\d.]+\)',
                              f'سعر الحساب: {price:g} ج.م (${usd:.2f})', attr)
            else:
                attr = re.sub(r'سعر الحساب: [\d.,]+ ج\.م', f'سعر الحساب: {price:g} ج.م', attr)
            q = re.search(r'الحد الأدنى:\s*(\d+)', attr)
            if q:
                attr = re.sub(r'إجمالي الباقة:\s*[\d.,]+\s*ج\.م',
                              f'إجمالي الباقة: {price * int(q.group(1)):g} ج.م', attr)
            v['attr'] = attr
        fixed_prices += 1
    live = [v for v in vs if v.get('stock', 1) != 0 and float(v.get('price') or 0) > 0]
    if not live:
        return fixed_prices, 0, False
    main = vs[0] if (vs[0].get('stock', 1) != 0 and float(vs[0].get('price') or 0) > 0) else min(live, key=lambda x: float(x['price']))
    mp = float(main['price'])
    musd = float(main.get('unit_price_usd') or main.get('price_usd') or 0)
    item_changed = abs(mp - float(it.get('price') or 0)) > 0.001
    if item_changed:
        apply_price(it, 'g2g#' + str(it['id']), mp, float(main.get('cost') or 0))
        # الرقمي بلا عمولة/خصم: تصحيح سعر قديم مايطلعش شارة «خصم» على المنتج
        for k in ('old_price', 'discount', 'price_drop_at'):
            it.pop(k, None)
    else:
        it['price'] = mp
        it['cost'] = float(main.get('cost') or 0)
        it['price_text'] = price_text(mp, it.get('currency', 'ج.م'))
    # تصحيح أي سعر مكتوب في نصوص المنتج
    cheapest = min(float(v['price']) for v in live)
    reps = 0

    def _heal_texts(o, depth=0):
        """يصلّح أي سعر مكتوب في نصوص المنتج (وصف/تنبيه/شرح/أسئلة) —
        ماعدا نصوص العروض نفسها (variants_detailed) لأن لكل عرض سعره."""
        nonlocal reps
        if depth > 6:
            return
        if isinstance(o, dict):
            for k, val in list(o.items()):
                if k in ('variants_detailed', 'colors', 'sizes', 'images'):
                    continue
                if isinstance(val, str):
                    t, kk = heal_price_mentions(val, mp, musd, cheapest)
                    if kk:
                        o[k] = t
                        reps += kk
                elif isinstance(val, (dict, list)):
                    _heal_texts(val, depth + 1)
        elif isinstance(o, list):
            for i, val in enumerate(o):
                if isinstance(val, str):
                    t, kk = heal_price_mentions(val, mp, musd, cheapest)
                    if kk:
                        o[i] = t
                        reps += kk
                elif isinstance(val, (dict, list)):
                    _heal_texts(val, depth + 1)

    _heal_texts(it)
    it['commission'] = 0
    return fixed_prices, reps, item_changed


def update_g2g():
    g = jload('g2g.json', [])
    updated = soldout = errors = 0; checked = 0
    for it in g:
        ex = it.get('extra') or {}
        vs = ex.get('variants_detailed') or []
        if not vs: continue
        changed_any = False
        for v in vs:
            oid = v.get('offer_id')
            if not oid or not str(oid).startswith('G'): continue
            checked += 1
            try:
                r = json.loads(http(f'https://sls.g2g.com/offer/{oid}?currency=USD&country=EG', 25))
                pl = r.get('payload') or {}
                if r.get('code') != 2000 or not pl:
                    v['stock'] = 0; soldout += 1; changed_any = True; continue
                usd = float(pl.get('converted_unit_price') or 0)
                if pl.get('is_sold_out') or pl.get('is_expired') or pl.get('delisted_reason'):
                    if v.get('stock', 1) != 0: v['stock'] = 0; soldout += 1; changed_any = True
                else:
                    if v.get('stock', 0) == 0: v['stock'] = int(pl.get('available_qty') or 999); changed_any = True
                    if usd > 0 and abs(usd - float(v.get('unit_price_usd') or 0)) > 0.0001:
                        v['unit_price_usd'] = usd; updated += 1; changed_any = True
                time.sleep(0.25)
            except Exception as e:
                errors += 1
        if changed_any:
            live = [v for v in vs if v.get('stock', 1) != 0 and v.get('price')]
            if live:
                it['stock'] = sum(int(v.get('stock') or 0) for v in live)
            else:
                it['stock'] = 0; it['unavailable_since'] = TODAY
            it['sizes'] = [v.get('label') or v.get('size') for v in vs if v.get('stock', 1) != 0][:40] or it.get('sizes', [])
    # ---- إصلاح أوفلاين لكل المنتجات: الأسعار المخزّنة/المكتوبة تتطابق مع سعر المصدر
    # (بيشتغل حتى لو G2G محجوب عن سيرفرات GitHub لأن السعر بيتحسب من الدولار المخزّن)
    healed_prices = text_healed = item_prices = 0
    for it in g:
        if not (it.get('extra') or {}).get('variants_detailed'):
            continue
        fp, reps, changed = heal_g2g_item(it)
        healed_prices += fp
        text_healed += 1 if reps else 0
        item_prices += 1 if changed else 0
    jsave('g2g.json', g)
    summary['g2g'] = {'total': len(g), 'offers_checked': checked, 'price_updated': updated,
                      'prices_healed': healed_prices, 'item_prices_healed': item_prices,
                      'text_healed': text_healed, 'sold_out': soldout, 'errors': errors}
    log('G2G done', summary['g2g'])


# ---------------------------------------------------------------- GamsGo (المصدر الجديد للمنتجات الرقمية — بدل G2G)
# GamsGo ملوش API رسمي منشور، لكن صفحات المنتجات بتترندر server-side وفيها كروت العروض:
# كل كارت = رابط /shop/<uuid> + سعر بالدولار + وقت التسليم + مدة الضمان + صورة alt فيها عنوان العرض.
# بنقرا الكروت دي ونعيّرها على نفس شكل بيانات G2G (variants_detailed + unit_price_usd)
# وسعر البيع = الدولار × USD_EGP (56) من غير أي عمولة أو تقريب لخمسات.
GAMSGO_BASE = 'https://www.gamsgo.com'
GAMSGO_PAGES = ('accounts/shahid', 'accounts/netflix', 'top-up/chatgpt', 'details/spotify', 'accounts/cannva', 'accounts/gemini')
GAMSGO_MAX_ITEMS = int(os.environ.get('GAMSGO_MAX_ITEMS', '400'))   # حد أقصى عدد منتجات في التشغيل الواحد (0 = بدون حد)
GAMSGO_SLEEP = float(os.environ.get('GAMSGO_SLEEP', '0.15'))
GAMSGO_MIN_OK_RATIO = float(os.environ.get('GAMSGO_MIN_OK_RATIO', '0.5'))  # لو أقل من النصف نجح — مابنكتبش حاجة


def gamsgo_slug_from_url(u):
    """يطلع slug صفحة المنتج من extra.source_url (accounts/shahid مثلاً)."""
    m = re.search(r'gamsgo\.com/((?:accounts|top-up|details|game|marketplace)/[^/?#]+)', str(u or ''))
    return m.group(1) if m else ''


def gamsgo_parse_offers(body):
    """يكشط كروت العروض من HTML صفحة المنتج عند GamsGo بدون الاعتماد على أسماء كلاسات.
    الكارت كله عبارة عن link واحد (href=/shop/<uuid>) وبعده العنوان والسعر، فبنقرا
    النص اللي بين رابط ورابط التاني — كده مافيشش تسريب سعر من كارت لجاره."""
    hits = [m for m in re.finditer(r'/shop/([0-9a-fA-F][0-9a-fA-F-]{15,})', body)]
    out, seen = [], set()
    for idx, m in enumerate(hits):
        sid = m.group(1)
        if sid in seen:
            continue
        seen.add(sid)
        nxt = hits[idx + 1].start() if idx + 1 < len(hits) else min(len(body), m.end() + 2400)
        win = body[m.end(): max(m.end() + 200, min(nxt, m.end() + 2400))]
        title = ''
        for a in re.findall(r'alt="([^"]{12,150})"', win):
            if 'avatar' in a.lower() or a.strip().lower().startswith('http'):
                continue
            title = html.unescape(a).strip()
            if title:
                break
        txt = re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', win)))
        pm = re.search(r'\$\s?([0-9]{1,5}(?:\.[0-9]{1,2})?)', txt)
        if not pm:
            continue
        usd = float(pm.group(1))
        if not (0.05 <= usd <= 5000):
            continue
        wm = re.search(r'\b(\d{1,3})\s+days\b', txt)
        dm = re.search(r'\b(Instant|[0-9.]+\s?(?:minutes?|hours?|days?))\b', txt, re.I)
        if not title:
            tm = re.search(r'title="([^"]{12,150})"', win) or re.search(r'>([^<>$]{18,120})<', win)
            if tm:
                title = html.unescape(tm.group(1)).strip()
        out.append({'shop_id': sid, 'usd': usd, 'title': title[:150],
                    'warranty': (wm.group(1) + ' days') if wm else '',
                    'delivery': (dm.group(1).title() if dm else '')})
    return out


def gamsgo_human_attr(o):
    """يحوّل وقت التسليم/الضمان الإنجليزي لصيغة عربية قصيرة تتماشى مع باقي الكتالوج."""
    d = str(o.get('delivery') or '').lower().strip()
    deliv = ''
    if d.startswith('instant'):
        deliv = 'تسليم فوري'
    else:
        mm = re.match(r'([0-9.]+)\s*(minute|hour|day)', d)
        if mm:
            unit = {'minute': 'دقيقة', 'hour': 'ساعة', 'day': 'يوم'}[mm.group(2)]
            deliv = f'تسليم خلال {mm.group(1)} {unit}'
    wm = re.match(r'(\d+)', str(o.get('warranty') or ''))
    war = f'ضمان {wm.group(1)} يوم' if wm else ''
    return ' • '.join(x for x in (deliv, war) if x)


def gamsgo_offers_to_variants(offers):
    """يحوّل كروت GamsGo لصيغة variants_detailed، ويرتّبهم بالأرخص أولًا (سعر «يبدأ من»)."""
    vs = []
    for o in offers:
        usd = float(o['usd'])
        cost = round(usd * USD_EGP, 2)
        price = round_5_or_0(cost * G2G_MARKUP)
        if price <= 0:
            continue
        vs.append({'offer_id': 'MS-' + str(o['shop_id'])[:16], 'label': o['title'] or 'عرض GamsGo',
                   'size': '', 'color': '',
                   'attr': gamsgo_human_attr(o),
                   'unit_price_usd': usd, 'cost': cost, 'price': price, 'unit_price_egp': price,
                   'stock': 1, 'source_url': f'{GAMSGO_BASE}/shop/{o["shop_id"]}'})
    vs.sort(key=lambda v: (float(v['price']), str(v['label'])))
    return vs


def update_gamsgo():
    g = jload('g2g.json', [])
    todo = [(i, gamsgo_slug_from_url((i.get('extra') or {}).get('source_url'))) for i in g]
    todo = [(i, s) for (i, s) in todo if s]
    if GAMSGO_MAX_ITEMS > 0 and len(todo) > GAMSGO_MAX_ITEMS:
        off = (int(TODAY[8:10]) * GAMSGO_MAX_ITEMS) % max(1, len(todo))     # دوران بسيط حتى كل المنتجات تتحدّث خلال كام تشغيل
        take = todo[off:off + GAMSGO_MAX_ITEMS]
        if len(take) < GAMSGO_MAX_ITEMS:
            take += todo[:GAMSGO_MAX_ITEMS - len(take)]
        todo = take
    ok = changed = errors = 0
    staged = []
    for it, slug in todo:
        try:
            body = http(f'{GAMSGO_BASE}/{slug}', 30)
            offers = gamsgo_parse_offers(body)
        except Exception as e:
            errors += 1
            log(f'⚠️ gamsgo {slug}: {str(e)[:90]}')
            time.sleep(GAMSGO_SLEEP)
            continue
        if len(body) < 4000 or not offers:
            errors += 1
            log(f'⚠️ gamsgo {slug}: مافيشش عروض مقروءة (len={len(body)}) — السعر ماتغيّرش')
            time.sleep(GAMSGO_SLEEP)
            continue
        vs = gamsgo_offers_to_variants(offers)
        if not vs:
            errors += 1
            time.sleep(GAMSGO_SLEEP)
            continue
        ok += 1
        main = vs[0]
        old_price = float(it.get('price') or 0)
        if abs(old_price - float(main['price'])) > 0.001:
            changed += 1
        staged.append((it, slug, vs, main))
        time.sleep(GAMSGO_SLEEP)
    tried = ok + errors
    if not ok or (tried and ok / tried < GAMSGO_MIN_OK_RATIO):
        # أمان: لو أغلب الصفحات مرجّعتش عروض (حظر/تغير شكل الصفحة) مانكتبش ومانرفعش حاجة
        raise RuntimeError(f'GamsGo: نسبة النجاح ضعيفة ({ok}/{tried}) — تم إيقاف التحديث من غير ما نلمس الكتالوج')
    for it, slug, vs, main in staged:
        ex = it.setdefault('extra', {})
        ex['source_url'] = f'{GAMSGO_BASE}/{slug}'
        ex['variants_detailed'] = vs
        ex['colors'] = []
        apply_price(it, 'g2g#' + str(it['id']), float(main['price']), float(main.get('cost') or 0))
        # الرقمي بلا عمولة: مانخليش حقل old_price/disdiscount يخلّي الموقع يطلع شارة خصم
        for k in ('old_price', 'discount', 'price_drop_at'):
            it.pop(k, None)
        it['price_text'] = price_text(main['price'], it.get('currency', 'ج.م'))
        it['cost'] = main['cost']
        it['commission'] = 0
        it['stock'] = max(1, len(vs))
        it['sizes'] = [str(v['label'])[:60] for v in vs][:40]
        it['seller'] = 'GamsGo'
        it['price_source'] = 'gamsgo'
        it['price_updated_at'] = TODAY
    jsave('g2g.json', g)
    summary['gamsgo'] = {'total': len(g), 'with_gamsgo_url': len(todo), 'pages_ok': ok,
                         'price_updated': changed, 'errors': errors}
    log('GamsGo done', summary['gamsgo'])


def gamsgo_probe():
    """مرحلة استكشاف (v2): بدل الكشط من الـ HTML بس، بندوّر على الـ JSON المضمّن في الصفحة
    (payload بتاع Nuxt) وعلى أي /api/ endpoints ظاهرة — دول الأمتن للتسعير.
    بيكتب تقرير data/gamsgo_probe.json على الفرع (البيئة المحلية ملهاش إنترنت)."""
    rep = {'at': NOW, 'base': GAMSGO_BASE, 'pages': {}}
    for slug in list(GAMSGO_PAGES) + ['accounts/netflix?currency=USD']:
        e = {}
        try:
            body = http(f'{GAMSGO_BASE}/{slug}', 30)
            e['len'] = len(body)
            e['counts'] = {k: body.count(k) for k in ('/shop/', '"total_price"', '"min_price"', '"delivery_type"', '__NUXT', 'application/json', '"currency"', '"price"')}
            blocks = re.findall(r'<script[^>]*type="application/json"[^>]*>(.*?)</script>', body, re.S)
            e['json_scripts'] = [{'len': len(b), 'head': re.sub(r'\s+', ' ', b)[:220]} for b in blocks][:4]
            objs = re.findall(r'\{[^{}]{0,2600}?"total_price"[^{}]{0,2600}?\}', body)
            e['offer_objects'] = len(objs)
            e['offer_obj_keys'] = sorted({k for o in objs[:14] for k in re.findall(r'"([A-Za-z_][A-Za-z0-9_]{1,28})"\s*:', o)})[:70]
            e['offer_obj_sample'] = [re.sub(r'\s+', ' ', o)[:520] for o in objs[:2]]
            e['api_paths'] = sorted(set(re.findall(r'["\']/?((?:api|v[0-9])/[A-Za-z0-9_\-/]{3,60})["\']', body)))[:20]
            e['shop_links_in_html'] = len(set(re.findall(r'/shop/([0-9a-fA-F-]{16,})', body)))
            e['parsed_by_html_scraper'] = len(gamsgo_parse_offers(body))
            e['items_found_text'] = (re.search(r'([0-9]{1,4})\s+items found', re.sub(r'<[^>]+>', ' ', body)) or [None, ''])[1] if re.search(r'([0-9]{1,4})\s+items found', re.sub(r'<[^>]+>', ' ', body)) else ''
        except Exception as ex:
            e['error'] = f'{type(ex).__name__}: {str(ex)[:200]}'
        rep['pages'][slug] = e
    jsave('gamsgo_probe.json', rep)
    summary['gamsgo_probe'] = {s: ('ERR ' + str(v.get('error'))[:60] if 'error' in v else f"objs={v.get('offer_objects')} links={v.get('shop_links_in_html')} found={v.get('items_found_text')}") for s, v in rep['pages'].items()}
    log('GamsGo probe v2 done', json.dumps(summary['gamsgo_probe'], ensure_ascii=False))


# ---------------------------------------------------------------- Safqa (Public API الرسمي)
CAT_RULES = [
 ('أحذية', ['حذاء','أحذية','احذية','أحذيه','احذيه','كوتشي','كوتش','شبشب','صندل','صنادل','بوت','سليبر','جزم','كروكس', 'shoes','shoe','sneaker','sneakers','sandal','sandals','slipper','slippers','boot','boots','crocs', 'skechers','اسكيتشرز','نيو بالانس','new balance','vans','كونفرس','converse']),
 ('ملابس حريمي', ['حريمي','حريمى','نسائي','نسائى','فستان','عباية','عبايه','إسدال','اسدال','بلوزة','بلوزه','جيبة','جيبه','لانجيري','طرحة','حجاب','تونيك','دريس','طقم حريمي','بيجامة حريمي', 'dress','women','woman','ladies','skirt','blouse','abaya','lingerie','hijab','nightgown']),
 ('ملابس رجالي', ['رجالي','رجالى','تيشيرت','تي شيرت','قميص','بنطلون','جاكيت','بدلة','بدله','ترنج','ترينج','شورت','بوكسر','جلابية','جلابيه','سويت شيرت','هودي','فانلة','بالطو','جينز','كارديجان','سلوبيت', 'shirt','tshirt','t-shirt','pants','trouser','trousers','jacket','men','mens','hoodie','jeans','sweatshirt','polo','suit','shorts','boxer']),
 ('أطفال وألعاب', ['أطفال','اطفال','طفل','بيبي','رضاعة','حفاض','مهد','عربة أطفال','بازل','مكعبات','دمية','عروسة','لعبة','ألعاب','العاب','مدرسي','تعليمي', 'kids','baby','toy','toys','child','children','newborn', 'كرسي سيارة أطفال','سرير أطفال','حفاضات','لانش بوكس أطفال']),
 ('ساعات', ['ساعة','ساعه','ساعات','واتش', 'watch','watches','wristwatch', 'rolex','casio','seiko','tissot','fossil','curren','naviforce','citizen','سيتيزن','رولكس','كاسيو','سيكو']),
 ('إلكترونيات', ['سماعة','سماعه','سماعات','شاحن','كابل','باور بانك','كاميرا','بلوتوث','ميكروفون','موبايل','تابلت','ماوس','كيبورد','لوحة مفاتيح','فلاشة','ذاكرة','ريسيفر','مكبر صوت','سبيكر','لاب توب','لابتوب','راوتر','شاشة','شاشه','تلفزيون','بروجكتور','سمارت','جهاز عرض','ترايبود','حامل موبايل','ستاند موبايل','أيربودز','ايربودز','ريموت','هارد','واي فاي','ميزان إلكتروني','ميزان الكتروني', 'headphone','headphones','headset','charger','cable','camera','bluetooth','microphone','mobile','phone','tablet','mouse','keyboard','laptop','router','tv','screen','monitor','power bank','speaker','smart','usb','earbuds','airpods','remote', 'كشاف','بروجكتر','ترانس','محول طاقة','سكانر','طابعة','ريموت كنترول', 'flashlight','projector','printer','scanner','transform','adapter']),
 ('العناية والتجميل', ['كريم','سيروم','مصل','شامبو','بلسم','عطر','برفان','مكياج','روج','ماسكارا','أحمر شفاه','مشط','فرشاة مكياج','أظافر','اظافر','حلاقة','ماسك','واقي شمس','تبييض','مكواة شعر','مكواه شعر','استشوار','مجفف شعر','رموش','حواجب','بشرة','بشره','شعر','عناية','صابون','بخور','مسك', 'cream','serum','shampoo','conditioner','perfume','makeup','lipstick','mascara','skincare','hair','nail','razor','mask','sunscreen','beauty','shaving', 'carolina herrera','عطر رجالي','عطر حريمي', 'مكواة شعر','مكواه شعر','مكواة الشعر','بادكير','تنعيم القدم','خيط تنظيف الأسنان','مجفف احذية', 'شفاط حليب','مضخة حليب','مساج','تدليك','ماساج','جهاز تخسيس','صنفرة أظافر', 'massage','breast pump','nail file']),
 ('شنط ومحافظ', ['شنطة','شنطه','حقيبة','حقيبه','حقائب','محفظة','محفظه','باك باك','شنط', 'bag','bags','wallet','backpack','tote','handbag','purse', 'louis vuitton','l v bag']),
 ('الرياضة واللياقة', ['دمبل','يوجا','مشاية','دراجة','دراجه','كرة','رياض','لياقة','لياقه','جيم','مصارعة','دواسة','دواسه','تمرين','تمارين','سباحة','سباحه','مسبح','سكيت','ترامبولين','تخييم','رحلات', 'sport','sports','fitness','gym','yoga','dumbbell','bike','bicycle','treadmill','camping','swimming','ball', 'أوزان','اوزان','حبل نط','سجادة رياضة','مقعد رياضي','بار رياضي','مشاية كهربائية','عجلة رياضية', 'dumbbells','exercise bike','jump rope','weights']),
 ('مستلزمات السيارات', ['سيارة','سياره','عربية','عربيه','كاوتش','منفاخ','مقاعد','وايبر','شماسة','زيت محرك','مكنسة سيارة','مجفف سيارة','غسيل السيارة','مساحة زجاج','فرش السيارة', 'car','auto','tire','tyre','wiper','engine oil','dashboard', 'غسل سيارات','غسيل سيارات','الة غسل السيارة', 'دشكام','حمالة سيارة','مضخة هواء','مكنسة السيارة','شاحن سيارة', 'tire inflator','car vacuum','dashcam']),
 ('عدد وأدوات', ['مفك','شنيور','منشار','دريل','كماشة','زرجينة','طقم عدة','شاكوش','بنسة','مسدس','أدوات','ادوات','عدة','معدات','لحام','مسامير','متر قياس', 'tool','tools','drill','wrench','screwdriver','hammer','saw','tape measure', 'مثقاب','صنفرة','فرشاة دهان','رولة دهان','مسدس لصق','مسدس شمع','هيلتي','صاروخ','ديسك','طقم مفكات', 'sander','paint brush','glue gun','grinder']),
 ('إكسسوارات', ['نظارة','نظاره','سلسلة','سلسله','خاتم','أسورة','اسورة','حلق','قبعة','قبعه','كاب','حزام','إكسسوار','اكسسوار','قفاز', 'sunglass','sunglasses','glasses','necklace','ring','bracelet','earring','cap','hat','belt','jewelry','jewellery','keychain']),
 ('المنزل والمطبخ', ['مطبخ','طبخ','حلة','حله','طاسة','طاسه','قطاعة','قطاعه','مبشرة','مبشره','منظم','ستارة','ستاره','وسادة','وساده','لحاف','بطانية','منشفة','منشفه','فوطة','فوطه','ممسحة','ممسحه','مكنسة','مكنسه','خلاط','عصارة','عصاره','ترمس','ديكور','معلقة','كوب','صينية','مفرمة','مفرمه','دفاية','مروحة','مروحه','سلة','سله','سرير','مرتبة','مرتبه','سجاد','سجادة','سجاده','إضاءة','اضاءة','لمبة','لمبه','شمعة','شمعه','معطر','بخاخ','حمام','أكواب','اكواب','حافظة','حافظه','مكتب','مصباح','ثلاجة','ثلاجه','غسالة','غساله','سخان','تكييف','أواني','اواني','مقلاة','مقلايه','ملعقة','ملعقه','طبق','غلاية','غلايه','ميكروويف','فرن','شواية','شوايه','مصفاة','كوباية','مستلزمات المنزل','جهاز تفريغ','جهاز خفق','صاعق', 'kitchen','home','house','curtain','pillow','blanket','towel','fan','blender','pan','pot','cup','mug','lamp','light','bed','mattress','sofa','chair','table','fridge','washing machine','cook','dish','hanger','organizer','decor','candle','mop','vacuum', 'ادوات المطبخ','أحذية المنزل', 'bathroom','bath mat','kitchen tools','storage box']),
]

# توحيد قيم الكاتيجوري عند الكتابة (لا تتكرر نفس الباقة بصيغتين)
CAT_CANON = {
 'المنتجات الرقمية': 'منتجات رقمية', 'منتجات رقميه': 'منتجات رقمية', 'رقميات': 'منتجات رقمية',
 'سوشال ميديا': 'سوشيال ميديا', 'سوشيال': 'سوشيال ميديا',
 'احذية': 'أحذية', 'احذيه': 'أحذية', 'اكسسوارات': 'إكسسوارات', 'اكسسوار': 'إكسسوارات',
 'الكترونيات': 'إلكترونيات', 'الالكترونيات': 'إلكترونيات', 'اجهزة': 'إلكترونيات',
 'اطفال': 'أطفال وألعاب', 'اطفال والعاب': 'أطفال وألعاب', 'العاب': 'أطفال وألعاب',
 'المنزل': 'المنزل والمطبخ', 'ادوات منزلية': 'المنزل والمطبخ',
 'العناية': 'العناية والتجميل', 'التجميل': 'العناية والتجميل',
 'عدد وادوات': 'عدد وأدوات', 'عدة وادوات': 'عدد وأدوات',
 'سيارات': 'مستلزمات السيارات', 'رياضة': 'الرياضة واللياقة',
 'شنط': 'شنط ومحافظ', 'حقائب': 'شنط ومحافظ',
}
AR_DIACRITICS = re.compile('[\u064B-\u0652\u0640]')
def normalize_category(value, fallback=''):
    c = str(value or '').strip()
    if not c: return fallback
    c = re.sub(r'\s+', ' ', c)
    if c in CAT_CANON: return CAT_CANON[c]
    for cat, _kws in CAT_RULES:
        if c == cat: return cat
    return c

def _ar_key(text):
    # توحيد الهمزات والتاء المربوطة والياء لزيادة دقة المطابقة
    t = AR_DIACRITICS.sub('', str(text or ''))
    for a, b in (('أ', 'ا'), ('إ', 'ا'), ('آ', 'ا'), ('ى', 'ي'), ('ة', 'ه'), ('ئ', 'ي'), ('ؤ', 'و')):
        t = t.replace(a, b)
    return t.lower()

CAT_RULES_AR = [(cat, [_ar_key(k) for k in kws]) for cat, kws in CAT_RULES]

# حرف عربي أو لاتيني أو رقم: الكلمة المفتاحية لا تُطابق إلا من بداية كلمة حقيقية
# (تمنع «الكريم» ← «كريم» و«Women» ← «men» و«Carolina» ← «car» و«Automatic» ← «auto»)
WORD_CHAR = '[\u0621-\u064A\u0660-\u0669A-Za-z0-9]'
LATIN_START = re.compile('[A-Za-z]')
def _has_keyword(text, keyword):
    # كلمة مفتاحية عربية: مطابقة من بداية الكلمة مع السماح بلواحق الكلمة
    # كلمة مفتاحية إنجليزية: مطابقة للكلمة كاملة (تمنع «Cartier» ← «car» و«Women» ← «men»)
    if LATIN_START.match(keyword):
        return re.search('(?<![A-Za-z0-9])' + re.escape(keyword) + '(?![A-Za-z0-9])', text) is not None
    return re.search('(?<!' + WORD_CHAR + ')' + re.escape(keyword), text) is not None

def guess_category(name):
    """أدق كلمة مفتاحية في الاسم هي اللي تحدد القسم (والترتيب أعلاه يفصل عند التعادل).

    ده بيمنع أخطاء زي: «مجموعة أدوات المطبخ» تروح عدد وأدوات بدل المنزل والمطبخ.
    """
    n = _ar_key(name)
    if not n: return 'المنزل والمطبخ'
    best_cat, best_len = '', 0
    for cat, kws in CAT_RULES_AR:
        for k in kws:
            if k and len(k) > best_len and _has_keyword(n, k):
                best_cat, best_len = cat, len(k)
    # لا نطابق شيئاً؟ نترك التصنيف الافتراضي كما هو بدل تخمين خاطئ
    return best_cat or 'المنزل والمطبخ'

# كلمات تصحيح مؤكدة: تُستخدم فقط للمنتجات الواقعة في القسم الافتراضي «المنزل والمطبخ» بالغلط
CAT_REPAIR = {
 'العناية والتجميل': ['مساج', 'تدليك', 'ماساج', 'شفاط حليب', 'مضخة حليب', 'مكواة شعر', 'مكواه شعر', 'مكواة تجعيد', 'سيشوار', 'استشوار', 'مجفف شعر', 'نزع الشعر', 'إزالة الشعر', 'ازالة الشعر', 'باديكير', 'بادكير', 'مانيكير', 'سيروم', 'شامبو', 'بلسم', 'ماكينة حلاقة', 'فرشاة أسنان', 'فرشاة اسنان', 'معجون أسنان', 'أظافر', 'مشط', 'فرشاة شعر', 'زيت شعر', 'massage'],
 'إلكترونيات': ['كشاف', 'مكبر صوت', 'سبيكر', 'ميكروفون', 'بروجكتر', 'بروجكتور', 'رسيفر', 'راوتر', 'فلاشة', 'هارد', 'طابعة', 'سكانر', 'باور بانك', 'شاحن', 'كابل', 'سماعة', 'بلوتوث', 'كاميرا', 'تابلت', 'لابتوب', 'ماوس', 'كيبورد', 'ريموت', 'محول طاقة', 'flashlight'],
 'عدد وأدوات': ['مثقاب', 'دريل', 'شنيور', 'صاروخ', 'ديسك', 'هيلتي', 'منشار', 'مفك', 'كماشة', 'زرجينة', 'شاكوش', 'مسدس لصق', 'مسدس شمع', 'لحام', 'مسامير', 'طقم عدة', 'عدة يدوية'],
 'الرياضة واللياقة': ['تردميل', 'ترد ميل', 'دمبل', 'أوزان', 'اوزان', 'بار رياضي', 'مقعد رياضي', 'سجادة رياضة', 'حبل نط', 'كرة قدم', 'دراجة', 'دراجه', 'ترامبولين', 'تخييم'],
 'مستلزمات السيارات': ['مضخة هواء', 'منفاخ', 'وايبر', 'شماسة', 'زيت محرك', 'مكنسة سيارة', 'غسيل السيارة', 'فرش السيارة', 'غطاء سيارة', 'كاميرا سيارة', 'دشكام', 'حمالة سيارة'],
 'أطفال وألعاب': ['أطفال', 'اطفال', 'أطفالي', 'اطفالي', 'رضّاعة', 'حفاض', 'بازل', 'مكعبات', 'دمية', 'عروسة', 'دبدوب', 'كرسي سيارة أطفال', 'تعليمي'],
 'أحذية': ['حذاء', 'أحذية', 'احذية', 'كوتشي', 'سنيكر', 'صندل', 'سليبر', 'كروكس', 'جزم'],
 'شنط ومحافظ': ['شنطة', 'حقيبة', 'حقيبه', 'محفظة', 'باك باك', 'حقائب'],
 'ساعات': ['ساعة', 'ساعه', 'ساعات', 'واتش'],
 'إكسسوارات': ['نظارة', 'سلسلة', 'خاتم', 'أسورة', 'اسورة', 'حلق', 'قبعة', 'حزام', 'إكسسوار', 'اكسسوار'],
}
CAT_REPAIR_AR = {cat: sorted([_ar_key(k) for k in kws], key=len, reverse=True) for cat, kws in CAT_REPAIR.items()}

def _has_repair_keyword(text, keyword):
    """مطابقة الكلمة القاطعة من بداية الكلمة مع السماح بـ«ال» التعريف قبلها
    (عشان «الشعر» تتطابق مع «شعر» و«الأحذية» مع «أحذية») — تُستخدم في التصحيح المحدود بس."""
    if LATIN_START.match(keyword):
        return re.search('(?<![A-Za-z0-9])' + re.escape(keyword) + '(?![A-Za-z0-9])', text) is not None
    return re.search('(?<!' + WORD_CHAR + ')(?:ب|ك|ف|ل|و|وال|بال|كال|فال|لل|ال)?' + re.escape(keyword), text) is not None

def repair_category(name, stored):
    """تصحيح محدود: يشتغل على المنتجات الواقعة في القسم الافتراضي «المنزل والمطبخ» بالغلط بس،
    وبس لو اسم المنتج فيه كلمة قاطعة (٤ حروف أو أكثر) بتحدد القسم الصح. مابيلمسش أي تصنيف صحيح."""
    stored = str(stored or '').strip()
    if stored != 'المنزل والمطبخ': return stored
    n = _ar_key(name)
    if not n: return stored
    best, best_len = '', 0
    for cat, kws in CAT_REPAIR_AR.items():
        for k in kws:
            if len(k) >= 4 and len(k) > best_len and _has_repair_keyword(n, k):
                best, best_len = cat, len(k)
    return best or stored

CATEGORY_OVERRIDES_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'category_overrides.json')
BLOCKED_PRODUCTS_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'blocked_products.json')

def remove_blocked_products():
    """منتجات مستبعدة يدوياً (blocked_products.json: قائمة ids) — بتتشال من الملفات الكاملة و lite_*.json
    بعد كل تحديث، فلو المصدر رجّعها ماتظهرش تاني."""
    try:
        with open(BLOCKED_PRODUCTS_FILE, encoding='utf-8') as f:
            blocked = set(str(x) for x in json.load(f))
    except Exception:
        return 0
    if not blocked:
        return 0
    removed = 0
    for plat in ('vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social'):
        for fname in (f'{plat}.json', f'lite_{plat}.json'):
            rows = jload(fname, [])
            if not isinstance(rows, list) or not rows:
                continue
            keep = [r for r in rows if not (isinstance(r, dict) and str(r.get('id')) in blocked)]
            if len(keep) != len(rows):
                jsave(fname, keep); removed += len(rows) - len(keep)
                log(f'blocked products[{fname}]: removed {len(rows) - len(keep)}')
    return removed

def apply_category_overrides():
    """أقسام مراجعة يدوياً: category_overrides.json (id → القسم). بتتطبق على الملفات الكاملة و lite_*.json
    بعد كل تحديث، فالتصحيح بيفضل ثابت حتى لو المصدر رجّع القسم القديم."""
    try:
        with open(CATEGORY_OVERRIDES_FILE, encoding='utf-8') as f:
            ov = json.load(f)
    except Exception:
        return 0
    if not isinstance(ov, dict) or not ov:
        return 0
    fixed = 0
    for plat in ('vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social'):
        for fname in (f'{plat}.json', f'lite_{plat}.json'):
            rows = jload(fname, [])
            if not isinstance(rows, list) or not rows:
                continue
            ch = 0
            for r in rows:
                if not isinstance(r, dict):
                    continue
                want = ov.get(str(r.get('id')))
                if want and r.get('category') != want:
                    r['category'] = want; ch += 1
            if ch:
                jsave(fname, rows); fixed += ch
                log(f'category overrides[{fname}]: {ch}')
    return fixed

def repair_product_categories():
    """مراجعة أقسام المنتجات في كل الأفلييتات: توحيد القيم + تصحيح الواقع في القسم الافتراضي غلط."""
    fixed = 0
    for plat in ('vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social', 'cezma'):
        rows = jload(plat + '.json', [])
        if not isinstance(rows, list) or not rows: continue
        ch = 0
        for r in rows:
            if not isinstance(r, dict): continue
            old = str(r.get('category') or '')
            new = normalize_category(old) or old
            rep = repair_category(r.get('name', ''), new)
            if rep != old:
                r['category'] = rep; ch += 1
        if ch:
            jsave(plat + '.json', rows); fixed += ch
            log(f'categories[{plat}]: fixed {ch}')
    fixed += apply_category_overrides()
    # سيزما: منتجاتها متخزّنة في شظايا (data/cezma_shards/lite_cezma_*.json) مش في cezma.json،
    # وأقسامها أقسام سيزما الرئيسية — بتتوحّد مع أقسام الموقع (شوف cezma_category تحت).
    fixed += repair_cezma_shards()
    if fixed: summary['categories'] = {'fixed': fixed}; log('categories: total fixed', fixed)
    return fixed

def update_safqa():
    key = os.environ.get('SAFQA_API_KEY', '')
    if not key: log('Safqa: no SAFQA_API_KEY — skip'); return
    hdr = dict(UA); hdr['api-safka-key'] = key; hdr['Accept'] = 'application/json'
    fresh = []; page = 1
    while True:
        req = urllib.request.Request(f'https://api.safka-eg.com/api/v1/public/products?page={page}&limit=100', headers=hdr)
        with urllib.request.urlopen(req, timeout=45) as r: d = json.loads(r.read().decode('utf-8', 'ignore'))
        fresh += d.get('data') or []
        if page >= int(d.get('pages') or 1): break
        page += 1; time.sleep(0.3)
    log(f'Safqa: fetched {len(fresh)} products')
    if len(fresh) < 50: raise RuntimeError('Safqa API returned too few products')
    cur = jload('safqa.json', []); by_id = {str(x.get('id')): x for x in cur}
    upd = add = drop = 0; seen = set()
    for f in fresh:
        fid = str(f.get('_id') or ''); 
        if not fid: continue
        seen.add(fid)
        props = f.get('properties') or []
        stock = sum(int(p.get('value') or 0) for p in props if p.get('is_available', True)) if props else 0
        price = float(f.get('sale_price') or 0)
        note = str(f.get('note') or '')
        m_c = re.search(r'(?:عمولتك|عمولتلك|عملوتك|عمولك|عموتتك|عمولة|العمولة)\s*[:：-]?\s*([\d.]+)', note)
        m_s = re.search(r'المقترح\s*(?:للقطعه|للقطعة|القطعه|القطعة)?\s*[:：-]?\s*([\d.]+)', note)
        comm = float(m_c.group(1)) if m_c else 0.0
        if comm <= 0 and m_s:
            s_val = float(m_s.group(1))
            if s_val > price > 0:
                comm = round(s_val - price, 2)
        # sale_price is the current net price; normalize gross so gross - commission exactly matches it.
        sugg = round(price + comm, 2) if price > 0 and comm > 0 else (float(m_s.group(1)) if m_s else price)
        imgs = f.get('images') or ([f['image']] if f.get('image') else [])
        media = [f['media_url']] if f.get('media_url') else []
        variants = [{'size': '', 'color': p.get('key', ''), 'price': ''} for p in props]
        stock_rows = [{'label': p.get('key', ''), 'qty': int(p.get('value') or 0), 'sale': float(p.get('sale_price') or price), 'min': p.get('min')} for p in props]
        # Open production: upstream stock never blocks a product from the catalog.
        active = bool(f.get('is_active', True))
        if fid in by_id:
            it = by_id[fid]
            if price > 0 and apply_price(it, 'safqa#' + fid, price): upd += 1
            it['commission'] = comm
            it['stock'] = stock if active else 0
            it['name'] = f.get('name') or it.get('name')
            if imgs: it['images'] = imgs
            ex = it.setdefault('extra', {}); ex['source_commission_original'] = comm; ex['note'] = note; ex['suggested'] = sugg; ex['stock_rows'] = stock_rows; ex['variants_detailed'] = variants
            if media: ex['media_links'] = media; it['files'] = media
            if active: it.pop('unavailable_since', None)
            elif not it.get('unavailable_since'): it['unavailable_since'] = TODAY; drop += 1
        else:
            if not active or price <= 0: continue
            it = {'id': fid, 'name': f.get('name') or '', 'sku': f.get('barcode') or '', 'price': price, 'price_text': price_text(price, 'جنيه'),
                  'commission': comm, 'images': imgs, 'videos': [], 'files': media, 'desc': re.sub(r'<[^>]+>', ' ', str(f.get('description') or '')).strip(),
                  'stock': stock, 'sizes': [], 'category': normalize_category(guess_category(f.get('name'))), 'rank': 99, 'country': 'مصر', 'added_at': TODAY,
                  'extra': {'source_commission_original': comm, 'note': note, 'faqs': f.get('faqs') or [], 'suggested': sugg, 'media_links': media, 'detail_images': [], 'variants_detailed': variants,
                            'stock_rows': stock_rows, 'size_chart': '', 'orig_suggested': sugg, 'description_html': f.get('description') or ''}}
            record_price('safqa#' + fid, price, 0); cur.append(it); add += 1
    for it in cur:
        if str(it.get('id')) not in seen and it.get('stock', 1) != 0:
            it['stock'] = 0; it['unavailable_since'] = TODAY; drop += 1
    jsave('safqa.json', cur)
    summary['safqa'] = {'total': len(cur), 'updated': upd, 'added': add, 'unavailable': drop}
    log('Safqa done', summary['safqa'])


# ---------------------------------------------------------------- Vendoor (تسجيل دخول + قراءة صفحات المنتجات)
from http import cookiejar as _cookiejar
def _session():
    cj = _cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    op.addheaders = list(UA.items())
    return op
def _get(op, url, timeout=40):
    with op.open(url, timeout=timeout) as r: return r.read().decode('utf-8', 'ignore')
def _post(op, url, data, referer, timeout=40):
    req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode(), headers={'Referer': referer, 'Content-Type': 'application/x-www-form-urlencoded'})
    with op.open(req, timeout=timeout) as r: return r.geturl(), r.read().decode('utf-8', 'ignore')
def _clean(t): return html.unescape(re.sub(r'<[^>]+>', ' ', t)).replace('\xa0', ' ').strip()

def vendoor_detail(op, pid):
    """صفحة منتج: الصور، الوصف، جدول اللون/المقاس/المخزون"""
    try: h = _get(op, f'https://aff.ven-door.com/product/{pid}')
    except Exception: return {}
    h1 = re.sub(r'\s+', ' ', h)
    imgs = []
    for m in re.findall(r'(https://aff\.ven-door\.com/uploads/products_images/[^"\' ]+)', h1):
        if m not in imgs: imgs.append(m)
    rows = re.findall(r'<tr>\s*<td>([^<]*)</td>\s*<td>([^<]*)</td>\s*<td>\s*(\d+)\s*</td>\s*</tr>', h1)
    stock_rows = [{'color': c.strip(), 'size': z.strip(), 'qty': int(q)} for c, z, q in rows]
    m = re.search(r'<p>(.*?)<h6 class="prodect-text', h1, re.S)
    desc = _clean(m.group(1)) if m else ''
    desc = re.sub(r'Link media.*$', '', desc).strip()
    media = []
    for raw in re.findall(r'https://drive\.google\.com/[^"\'<\s]+', h1):
        mid = re.search(r'(?:/folders/|/file/d/|/file/|open\?id=|[?&]id=)([A-Za-z0-9_-]{10,})', html.unescape(raw))
        if not mid: continue
        rid = mid.group(1)
        url = f'https://drive.google.com/file/d/{rid}' if '/file/' in raw else f'https://drive.google.com/drive/folders/{rid}'
        if url not in media: media.append(url)
    sizes = list(dict.fromkeys(('مقاس ' + r['size']) if re.fullmatch(r'\d{2}', r['size']) else r['size'] for r in stock_rows if r['size']))
    colors = list(dict.fromkeys(r['color'] for r in stock_rows if r['color']))
    return {'images': imgs, 'stock_rows': stock_rows, 'desc': desc, 'media': media, 'sizes': sizes, 'colors': colors}

def update_vendoor():
    user, pw = os.environ.get('VENDOOR_USER', ''), os.environ.get('VENDOOR_PASS', '')
    if not (user and pw): log('Vendoor: no VENDOOR_USER/VENDOOR_PASS — skip'); return
    op = _session()
    lp = _get(op, 'https://aff.ven-door.com/login')
    tok = re.search(r'name="_token" value="([^"]+)"', lp).group(1)
    url, _ = _post(op, 'https://aff.ven-door.com/affiliates-login', {'_token': tok, 'name': user, 'password': pw}, 'https://aff.ven-door.com/login')
    if url.rstrip('/').endswith('/login'): raise RuntimeError('Vendoor login failed')
    fresh = {}; page = 1; last = 1
    while page <= last:
        h = _get(op, f'https://aff.ven-door.com/products?page={page}')
        h1 = re.sub(r'\s+', ' ', h)
        pages = [int(x) for x in re.findall(r'products\?page=(\d+)', h1)]
        if pages: last = max(last, max(pages))
        for blk in re.split(r'<tr>\s*<td>\s*<label>\s*<input type="checkbox"', h1)[1:]:
            m = re.search(r'value="(\d+)"\s*data-name="([^"]*)"', blk)
            if not m: continue
            pid, name = m.group(1), html.unescape(m.group(2)).strip()
            img = re.search(r'src="(https://aff\.ven-door\.com/uploads/products_images/[^"]+)"', blk)
            seller = re.search(r'vendor_id=\d+">\s*([^<]+?)\s*</a>', blk)
            purch = re.search(r'<strong class="text-primary">\s*([\d.,]+)\s*</strong>', blk)
            mn = re.search(r'الأدنى:\s*([\d.,]+)', blk); mx = re.search(r'الأقصى:\s*([\d.,]+)', blk)
            com = re.search(r'([\d.,]+)\s*جنيه', blk)
            stock_pairs = re.findall(r'\(([^()]*)\)\s*=>\s*(\d+)', blk)
            stock = sum(int(q) for _, q in stock_pairs)
            fresh[pid] = {'id': pid, 'name': name, 'img': img.group(1) if img else '', 'seller': seller.group(1).strip() if seller else '',
                          'purchasing': float(purch.group(1).replace(',', '')) if purch else 0, 'min': float(mn.group(1).replace(',', '')) if mn else 0,
                          'max': float(mx.group(1).replace(',', '')) if mx else 0, 'commission': float(com.group(1).replace(',', '')) if com else 0, 'stock': stock}
        page += 1; time.sleep(0.4)
    log(f'Vendoor: fetched {len(fresh)} products from {last} pages')
    if len(fresh) < 100: raise RuntimeError('Vendoor listing too small — layout changed?')
    cur = jload('vendoor.json', []); by_id = {str(x.get('id')): x for x in cur}
    upd = add = drop = 0; details = 0
    for pid, f in fresh.items():
        price = round(max(0, f['purchasing'] - f['commission']), 2) if f['purchasing'] else 0
        if pid in by_id:
            it = by_id[pid]
            if price > 0 and apply_price(it, 'vendoor#' + pid, price): upd += 1
            it['commission'] = f['commission']
            it['stock'] = f['stock']; it['name'] = f['name'] or it.get('name')
            ex = it.setdefault('extra', {}); ex['source_commission_original'] = f['commission']; ex['purchasing'] = f['purchasing']; ex['minimum'] = f['min']; ex['maximum'] = f['max']; ex['price_min'] = f['min']; ex['price_max'] = f['max']
            if f['stock'] > 0: it.pop('unavailable_since', None)
            elif not it.get('unavailable_since'): it['unavailable_since'] = TODAY; drop += 1
        else:
            if price <= 0: continue
            d = vendoor_detail(op, pid) if details < 40 else {}; details += 1
            it = {'id': pid, 'name': f['name'], 'sku': '', 'price': price, 'price_text': price_text(price, 'جنيه'), 'commission': f['commission'],
                  'images': d.get('images') or ([f['img']] if f['img'] else []), 'videos': [], 'files': d.get('media', []), 'desc': d.get('desc', ''),
                  'seller': '', 'category': normalize_category(guess_category(f['name'])), 'stock': f['stock'], 'sizes': d.get('sizes', []), 'rank': 0, 'country': 'مصر', 'added_at': TODAY,
                  'extra': {'colors': d.get('colors', []), 'variants_detailed': [{'size': r['size'], 'color': r['color'], 'price': ''} for r in d.get('stock_rows', [])],
                            'stock_table': {}, 'stock_rows': d.get('stock_rows', []), 'coupons': [], 'detail_images': [], 'media_links': d.get('media', []),
                            'source_commission_original': f['commission'], 'purchasing': f['purchasing'], 'minimum': f['min'], 'maximum': f['max'], 'price_min': f['min'], 'price_max': f['max'], 'size_chart': '', 'catalog': '', 'orig_suggested': f['purchasing']}}
            record_price('vendoor#' + pid, price, 0); cur.append(it); add += 1
            time.sleep(0.3)
    for it in cur:
        if str(it.get('id')) not in fresh and it.get('stock', 1) != 0:
            it['stock'] = 0; it['unavailable_since'] = TODAY; drop += 1
    jsave('vendoor.json', cur)
    summary['vendoor'] = {'total': len(cur), 'updated': upd, 'added': add, 'unavailable': drop}
    log('Vendoor done', summary['vendoor'])

# ---------------------------------------------------------------- M5zoon (تسجيل دخول + قراءة الكتالوج)
def _login_laravel(op, url, user, password, label):
    login_html = _get(op, url)
    m = re.search(r'name="_token" value="([^"]+)"', login_html)
    if not m: raise RuntimeError(label + ': login page has no CSRF token')
    final, body = _post(op, url, {'_token': m.group(1), 'email': user, 'password': password}, url)
    if urllib.parse.urlsplit(final).path.rstrip('/') == urllib.parse.urlsplit(url).path.rstrip('/'):
        if re.search(r'<input[^>]+name=["\']password["\']', body, re.I):
            raise RuntimeError(label + ': login failed')
    return op

def _parse_amount(value):
    s = re.sub(r'[^0-9,.+\-]', '', str(value or '').strip())
    if not s: return 0.0
    comma, dot = s.rfind(','), s.rfind('.')
    if comma >= 0 and dot >= 0:
        s = s.replace(',', '') if dot > comma else s.replace('.', '').replace(',', '.')
    elif comma >= 0:
        parts = s.split(','); tail = parts[-1]
        if len(parts) == 2 and 0 < len(tail) <= 2: s = s.replace(',', '.')
        elif len(parts) > 2 and 0 < len(tail) <= 2: s = ''.join(parts[:-1]) + '.' + tail
        else: s = s.replace(',', '')
    try: return float(s)
    except ValueError: return 0.0

def _money_parts(value, default_currency='جنيه'):
    text = html.unescape(re.sub(r'<[^>]+>', ' ', str(value or ''))).replace('\xa0', ' ').strip()
    m = re.search(r'([\d][\d,.]*(?:\.\d+)?)', text)
    if not m: return 0.0, default_currency
    amount = _parse_amount(m.group(1))
    currency = (text[m.end():].strip() or default_currency)[:20]
    return amount, currency

def _source_text(value, limit=5000):
    text = _clean(value)
    kept = []
    for line in re.split(r'[\r\n]+|(?<=[.!؟])\s+', text):
        line = re.sub(r'(?i)(?:واتساب|واتس\s*آب|whatsapp|للتواصل|تواصل معنا|اتصل بنا|رقم الهاتف|phone)\s*[:：-]?.*$', '', line).strip()
        line = re.sub(r'(?<!\d)(?:\+?20|0)?1[0125]\d{8,9}(?!\d)', '', line)
        line = re.sub(r'(?i)\b(?:مخزن|warehouse)\s*[:#-]?\s*[A-Z]?\d{2,}\b', '', line).strip()
        if line: kept.append(line)
    return re.sub(r'\s+', ' ', ' '.join(kept)).strip()[:limit]

def _m5zoon_card_rows(page):
    out = []
    for block in re.split(r'<div\s+class="home-wrapper__product-item"', page, flags=re.I)[1:]:
        link = re.search(r'href=["\'][^"\']*/profile/showProduct/([^?"\']+)', block, re.I)
        if not link: continue
        pid = html.unescape(link.group(1)).strip('/')
        title = re.search(r'<span\s+class="home-wrapper__product-item-title"[^>]*>(.*?)</span>', block, re.I | re.S)
        im = re.search(r'<img[^>]+class="home-wrapper__product-item-image"[^>]+src=["\']([^"\']+)', block, re.I)
        costm = re.search(r'home-wrapper__product-item-price-item-price[^>]*>(.*?)</span>', block, re.I | re.S)
        profm = re.search(r'home-wrapper__product-item-profit-item-price[^>]*>(.*?)</span>', block, re.I | re.S)
        cost, currency = _money_parts(costm.group(1) if costm else '')
        profit, _ = _money_parts(profm.group(1) if profm else '', currency)
        name = _source_text(title.group(1) if title else '', 300)
        if not name or cost <= 0: continue
        out.append({'id': pid, 'name': name, 'cost': cost, 'commission': max(0, profit), 'suggested': cost + max(0, profit),
                    'currency': currency, 'image': html.unescape(im.group(1)) if im else ''})
    return out

def m5zoon_detail(op, pid):
    try: page = _get(op, f'https://m5zoon.com/profile/showProduct/{urllib.parse.quote(str(pid))}')
    except Exception: return {}
    main = page.split('home-wrapper__products', 1)[0]
    imgs = list(dict.fromkeys(re.findall(r'https://m5zoon\.com/public/uploads/products/[^"\'\s<>]+', main)))[:12]
    desc = ''
    for pat in (r'<div[^>]+class="product-description__body-description"[^>]*>(.*?)</div>',
                r'<div[^>]+id="(?:description|ProductDescription)"[^>]*>(.*?)</div>'):
        m = re.search(pat, main, re.I | re.S)
        if m:
            desc = _source_text(m.group(1));
            if desc: break
    sku = re.search(r'\bSKU\s*:\s*</?[^>]*>\s*([^<\s]+)', main, re.I)
    return {'images': imgs, 'desc': desc, 'sku': sku.group(1) if sku else ''}

def update_m5zoon():
    user, pw = os.environ.get('M5ZOON_USER', ''), os.environ.get('M5ZOON_PASS', '')
    if not (user and pw): log('M5zoon: no M5ZOON_USER/M5ZOON_PASS — skip'); return
    op = _session(); _login_laravel(op, 'https://m5zoon.com/login', user, pw, 'M5zoon')
    fresh = {}; page = 1; last = 1
    while page <= last and page <= 250:
        h = _get(op, f'https://m5zoon.com/profile/products?page={page}', 60)
        nums = [int(x) for x in re.findall(r'/profile/products\?page=(\d+)', h)]
        if nums: last = max(last, max(nums))
        for row in _m5zoon_card_rows(h): fresh[row['id']] = row
        page += 1
        if page <= last: time.sleep(0.25)
    log(f'M5zoon: fetched {len(fresh)} products from {last} pages')
    if len(fresh) < 100: raise RuntimeError('M5zoon listing too small — login or page layout changed?')
    cur = jload('m5zoon.json', []); by_id = {str(x.get('id')): x for x in cur}; old_base = {}
    for x in cur: old_base.setdefault(str(x.get('id', '')).split('-', 1)[0], x)
    upd = add = drop = 0; seen = set(); details = 0
    for pid, f in fresh.items():
        it = by_id.get(pid) or old_base.get(pid)
        key_id = str(it.get('id')) if it else pid
        seen.add(key_id)
        price = round(float(f['cost']), 2)
        commission = round(float(f['commission']), 2)
        currency = f['currency'] or 'جنيه'
        if it:
            it['currency'] = currency
            if price > 0 and apply_price(it, 'm5zoon#' + key_id, price): upd += 1
            it['name'] = f['name'] or it.get('name')
            it['price_text'] = price_text(price, currency)
            it['commission'] = price_text(commission, currency)
            it['stock'] = 999
            it['category'] = normalize_category(it.get('category')) or guess_category(f['name'])
            it.pop('unavailable_since', None)
            ex = it.setdefault('extra', {})
        else:
            d = m5zoon_detail(op, pid) if details < 50 else {}; details += 1
            imgs = d.get('images') or ([f['image']] if f['image'] else [])
            boxes = [price_text(price, currency) + ' سعر التكلفة', price_text(float(f['suggested']), currency) + ' سعر بيع مقترح', price_text(commission, currency) + ' الربح']
            ex = {'price_boxes': boxes, 'stock_id': '', 'cost': price_text(price, currency), 'suggested': price_text(float(f['suggested']), currency),
                  'profit': price_text(commission, currency), 'source_commission_original': price_text(commission, currency), 'quick_desc': d.get('desc') or f['name'], 'features': '', 'dimensions': '', 'ad_words': '',
                  'media_links': [], 'description_html': '', 'detail_images': imgs[1:], 'market': '', 'orig_category': '', 'orig_suggested': float(f['suggested'])}
            it = {'id': pid, 'name': f['name'], 'sku': d.get('sku', ''), 'price': price, 'price_text': price_text(price, currency), 'currency': currency,
                  'commission': price_text(commission, currency), 'images': imgs, 'videos': [], 'files': [], 'desc': d.get('desc') or f['name'],
                  'category': normalize_category(guess_category(f['name'])), 'stock': 999, 'sizes': [], 'rank': 0, 'country': 'مصر', 'added_at': TODAY, 'extra': ex}
            record_price('m5zoon#' + pid, price, 0); cur.append(it); by_id[pid] = it; add += 1
        ex['source_commission_original'] = price_text(commission, currency); ex['cost'] = price_text(price, currency); ex['suggested'] = price_text(float(f['suggested']), currency); ex['profit'] = price_text(commission, currency)
        ex['orig_suggested'] = float(f['suggested']); ex['price_boxes'] = [price_text(price, currency) + ' سعر التكلفة', price_text(float(f['suggested']), currency) + ' سعر بيع مقترح', price_text(commission, currency) + ' الربح']
        if f['image'] and not it.get('images'): it['images'] = [f['image']]
    for it in cur:
        if str(it.get('id')) not in seen and it.get('stock', 1) != 0:
            it['stock'] = 0; it['unavailable_since'] = TODAY; drop += 1
    jsave('m5zoon.json', cur)
    summary['m5zoon'] = {'total': len(cur), 'fetched': len(fresh), 'updated': upd, 'added': add, 'unavailable': drop, 'details': details}
    log('M5zoon done', summary['m5zoon'])

# ---------------------------------------------------------------- Angazny (تسجيل دخول + المنتجات والأسعار والمخزون)
def _angazny_card_rows(page):
    out = []
    for block in re.split(r'<div\s+class="card ecommerce-card"', page, flags=re.I)[1:]:
        link = re.search(r'href=["\'][^"\']*/product/viewproduct/(\d+)', block, re.I)
        if not link: continue
        pid = link.group(1)
        title = re.search(r'<div\s+class="item-name[^>]*>.*?<a[^>]*>(.*?)</a>', block, re.I | re.S)
        nums = re.findall(r'<h6\s+class="item-price"[^>]*>(.*?)</h6>', block, re.I | re.S)
        raw_price, _ = _money_parts(nums[0] if nums else '')
        stock, _ = _money_parts(nums[1] if len(nums) > 1 else '', 'جنيه')
        badge = re.search(r'badge-primary[^>]*>.*?<span>([\d,.]+)</span>', block, re.I | re.S)
        commission = float(badge.group(1).replace(',', '')) if badge else 0.0
        im = re.search(r'background-image\s*:\s*url\(["\']?([^"\')]+)', block, re.I)
        if not im: im = re.search(r'<img[^>]+src=["\'](https://[^"\']+)', block, re.I)
        name = _source_text(title.group(1) if title else '', 300)
        if not name or raw_price <= 0: continue
        out.append({'id': pid, 'name': name, 'suggested': raw_price, 'commission': max(0, commission),
                    'price': round(max(0, raw_price - commission), 2), 'stock': max(0, int(stock)),
                    'image': urllib.parse.urljoin('https://eg.angazny.com/', html.unescape(im.group(1))) if im else ''})
    return out

def angazny_detail(op, pid):
    try: page = _get(op, f'https://eg.angazny.com/product/viewproduct/{urllib.parse.quote(str(pid))}')
    except Exception: return {}
    imgs = list(dict.fromkeys(re.findall(r'https://angazny\.fra1\.cdn\.digitaloceanspaces\.com/[^"\'\s<>]+', page)))[:15]
    names = {}
    for m in re.finditer(r'<input\b([^>]*class="[^"]*options[^"]*"[^>]*)>', page, re.I):
        attrs = m.group(1)
        nm = re.search(r'name=["\']([^"\']+)', attrs, re.I); val = re.search(r'value=["\']([^"\']*)', attrs, re.I)
        if nm and val and nm.group(1) in ('اللون', 'المقاس'):
            names.setdefault(nm.group(1), []).append(html.unescape(val.group(1)))
    colors = list(dict.fromkeys(names.get('اللون', []))); sizes = list(dict.fromkeys(names.get('المقاس', [])))
    variants = [{'size': s, 'color': c, 'price': ''} for c in (colors or ['']) for s in (sizes or [''])]
    desc = ''
    m = re.search(r'<div[^>]+id="ProductDescription"[^>]*>(.*?)</div>\s*</div>', page, re.I | re.S)
    if m: desc = _source_text(m.group(1))
    if not desc:
        m = re.search(r'<h5[^>]*>.*?</h5>(.*?)(?:PriceEffectContainer|AvailableStockValue)', page, re.I | re.S)
        if m: desc = _source_text(m.group(1))
    return {'images': imgs, 'colors': colors, 'sizes': sizes, 'variants': variants, 'desc': desc}

def update_angazny():
    user, pw = os.environ.get('ANGAZNY_USER', ''), os.environ.get('ANGAZNY_PASS', '')
    if not (user and pw): log('Angazny: no ANGAZNY_USER/ANGAZNY_PASS — skip'); return
    op = _session(); _login_laravel(op, 'https://eg.angazny.com/login', user, pw, 'Angazny')
    fresh = {}; page = 1; last = 1
    while page <= last and page <= 100:
        h = _get(op, f'https://eg.angazny.com/product/list?page={page}', 60)
        nums = [int(x) for x in re.findall(r'/product/list\?page=(\d+)', h)]
        if nums: last = max(last, max(nums))
        for row in _angazny_card_rows(h): fresh[row['id']] = row
        page += 1
        if page <= last: time.sleep(0.25)
    log(f'Angazny: fetched {len(fresh)} products from {last} pages')
    if len(fresh) < 30: raise RuntimeError('Angazny listing too small — login or page layout changed?')
    cur = jload('angazny.json', []); by_id = {str(x.get('id')): x for x in cur}
    upd = add = drop = 0; seen = set(); details = 0
    for pid, f in fresh.items():
        seen.add(pid); price = float(f['price']); commission = float(f['commission'])
        if f['stock'] <= 0: price = max(price, 0)
        if pid in by_id:
            it = by_id[pid]
            if price > 0 and apply_price(it, 'angazny#' + pid, price): upd += 1
            it['commission'] = commission; it['stock'] = f['stock']; it['name'] = f['name'] or it.get('name')
            it['currency'] = 'جنيه'; it['price_text'] = price_text(price, 'جنيه')
            if not it.get('images') and f['image'] and len(f['image']) > 10 and not f['image'].rstrip('/').endswith('angazny.com'):
                it['images'] = [f['image']]
            it.pop('unavailable_since', None) if f['stock'] > 0 else it.setdefault('unavailable_since', TODAY)
            ex = it.setdefault('extra', {}); ex['source_commission_original'] = commission
        else:
            d = angazny_detail(op, pid) if details < 70 else {}; details += 1
            imgs = d.get('images') or ([f['image']] if f['image'] else [])
            colors, sizes = d.get('colors', []), d.get('sizes', [])
            variants = d.get('variants', [])
            ex = {'colors': colors, 'variants_detailed': variants, 'stock_rows': [], 'stock_table': {'sizes': sizes, 'quantities': []},
                  'warehouse': '', 'marketing_desc': '', 'detail_images': imgs[1:], 'media_links': [], 'options': [], 'shipping': '',
                  'rating': 0.0, 'card_badge': str(int(commission)), 'orig_category': '', 'orig_suggested': f['suggested'], 'source_commission_original': commission}
            it = {'id': pid, 'name': f['name'], 'sku': '', 'price': price, 'price_text': price_text(price, 'جنيه'), 'currency': 'جنيه',
                  'commission': commission, 'images': imgs, 'videos': [], 'files': [], 'desc': d.get('desc') or f['name'],
                  'category': normalize_category(guess_category(f['name'])), 'stock': f['stock'], 'sizes': sizes, 'rank': 99, 'country': 'مصر', 'added_at': TODAY, 'extra': ex}
            record_price('angazny#' + pid, price, 0); cur.append(it); by_id[pid] = it; add += 1
        ex['orig_suggested'] = f['suggested']; ex['card_badge'] = str(int(commission))
        if f['stock'] > 0: it.pop('unavailable_since', None)
        elif not it.get('unavailable_since'): it['unavailable_since'] = TODAY; drop += 1
    for it in cur:
        if str(it.get('id')) not in seen and it.get('stock', 1) != 0:
            it['stock'] = 0; it['unavailable_since'] = TODAY; drop += 1
    jsave('angazny.json', cur)
    summary['angazny'] = {'total': len(cur), 'fetched': len(fresh), 'updated': upd, 'added': add, 'unavailable': drop, 'details': details}
    log('Angazny done', summary['angazny'])

# ---------------------------------------------------------------- Timehand (دخول AJAX + صفحات تصنيفات المنتجات)
def _timehand_login(op, user, password):
    login_url = 'https://time-hand.com/affiliate/auth/login'
    page = _get(op, login_url)
    token = re.search(r'name="_token" value="([^"]+)"', page)
    csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', page)
    if not token: raise RuntimeError('Timehand: login page has no CSRF token')
    headers = {'Referer': login_url, 'Origin': 'https://time-hand.com', 'Content-Type': 'application/x-www-form-urlencoded',
               'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf.group(1) if csrf else token.group(1)}
    req = urllib.request.Request(login_url, data=urllib.parse.urlencode({'_token': token.group(1), 'email': user, 'password': password}).encode(), headers=headers)
    with op.open(req, timeout=45) as r: result = json.loads(r.read().decode('utf-8', 'ignore'))
    if result.get('errors'): raise RuntimeError('Timehand: login failed')
    home = _get(op, 'https://time-hand.com/affiliate', 60)
    if re.search(r'id="sign-in-form"', home): raise RuntimeError('Timehand: login session was not established')
    return home

def _timehand_card_rows(page):
    out = []
    for block in re.split(r'<div\s+class="productCard"', page, flags=re.I)[1:]:
        link = re.search(r'href=["\'][^"\']*/affiliate/product/([^?"\']+)', block, re.I)
        if not link: continue
        pid = html.unescape(link.group(1)).strip('/')
        raw_price = 0.0
        for v in re.findall(r'<span\s+class="text-accent"[^>]*>(.*?)</span>', block, re.I | re.S):
            raw_price, _ = _money_parts(v)
            if raw_price > 0: break
        stock = commission = 0.0
        for label, value in re.findall(r'<span[^>]*>(.*?)</span>\s*<h4[^>]*>(.*?)</h4>', block, re.I | re.S):
            label = _source_text(label, 60).lower(); amount, _ = _money_parts(value)
            if 'مخزون' in label or 'stock' in label: stock = amount
            elif 'عمول' in label or 'commission' in label: commission = amount
        title = re.search(r'<h3[^>]*>\s*<a[^>]*>(.*?)</a>', block, re.I | re.S)
        img = re.search(r'<div\s+class="productImage"[^>]*>.*?<img[^>]+data-src=["\']([^"\']+)', block, re.I | re.S)
        if not img:
            img = re.search(r'<div\s+class="productImage"[^>]*>.*?<img[^>]+src=["\']([^"\']+)', block, re.I | re.S)
        img_url = urllib.parse.urljoin('https://time-hand.com/', html.unescape(img.group(1))) if img else ''
        if any(bad in img_url.lower() for bad in ('place-holder', 'placeholder', '/product/meta/', '/def.png', '/default.png')):
            img_url = ''
        elif '/storage/app/public/product/thumbnail/' in img_url:
            img_url = img_url.replace('/storage/app/public/product/thumbnail/', '/storage/app/public/product/')
        name = _source_text(title.group(1) if title else '', 300)
        if not name or raw_price <= 0: continue
        gross = round(raw_price, 2); commission = round(max(0.0, commission), 2)
        out.append({'id': pid, 'name': name, 'suggested': gross, 'commission': commission,
                    'price': round(max(0, gross - commission), 2), 'stock': max(0, int(stock)),
                    'image': img_url})
    return out

def _timehand_last_page(page):
    pos = page.find('id="paginator-ajax"')
    area = page[pos:pos + 25000] if pos >= 0 else page
    nums = [int(x) for x in re.findall(r'(?:page=|page&amp;=)(\d+)', area)]
    return max(nums or [1])

def timehand_detail(op, pid):
    try: page = _get(op, 'https://time-hand.com/affiliate/product/' + urllib.parse.quote(str(pid), safe='-'), 45)
    except Exception: return {}
    main = re.split(r'id="paginator-ajax"|<div\s+class="productCard"', page, maxsplit=1, flags=re.I)[0]
    raw_matches = re.findall(r'(?:https?://time-hand\.com)?(/storage/app/public/product/(?!thumbnail/|meta/)[^"\'\s<>/]+)', main, flags=re.I)
    imgs = list(dict.fromkeys(
        'https://time-hand.com' + p
        for p in raw_matches
        if not p.lower().endswith(('/def.png', '/default.png')) and 'place-holder' not in p.lower() and 'placeholder' not in p.lower()
    ))[:15]
    if not imgs:
        thumb_matches = re.findall(r'(?:https?://time-hand\.com)?(/storage/app/public/product/thumbnail/[^"\'\s<>/]+)', main, flags=re.I)
        imgs = list(dict.fromkeys(
            'https://time-hand.com' + p.replace('/storage/app/public/product/thumbnail/', '/storage/app/public/product/')
            for p in thumb_matches
            if not p.lower().endswith(('/def.png', '/default.png')) and 'place-holder' not in p.lower() and 'placeholder' not in p.lower()
        ))[:15]
    media_links = list(dict.fromkeys(re.findall(r'https://drive\.google\.com/[^\s"\'<>]+', main)))
    colors = []
    for tag in re.findall(r'<input\b[^>]*name=["\']color["\'][^>]*>', main, re.I | re.S):
        ident = re.search(r'id=["\']([^"\']+)', tag, re.I)
        if ident:
            label = re.search(r'<label\b[^>]*for=["\']' + re.escape(ident.group(1)) + r'["\'][^>]*title=["\']([^"\']*)', main, re.I)
            if not label: label = re.search(r'<label\b[^>]*title=["\']([^"\']*)["\'][^>]*for=["\']' + re.escape(ident.group(1)), main, re.I)
            if label and label.group(1).strip(): colors.append(html.unescape(label.group(1).strip()))
    sizes = []
    for select in re.findall(r'<select\b[^>]*class=["\'][^"\']*attribute-selector[^"\']*["\'][^>]*>.*?</select>', main, re.I | re.S):
        for val in re.findall(r'<option\b[^>]*>(.*?)</option>', select, re.I | re.S):
            v = _source_text(val, 80)
            if v and v.lower() not in ('اختر', 'يختار', 'choose', 'select'): sizes.append(v)
    colors = list(dict.fromkeys(colors)); sizes = list(dict.fromkeys(sizes))
    variants = [{'size': s, 'color': c, 'price': ''} for c in (colors or ['']) for s in (sizes or [''])]
    desc = ''
    for pat in (r'<div[^>]+id=["\']overview["\'][^>]*>(.*?)</div>\s*</div>',
                r'<div[^>]+id=["\']overview["\'][^>]*>(.*?)</div>',
                r'<div[^>]+class=["\'][^"\']*spartan_item_details[^"\']*["\'][^>]*>(.*?)</div>'):
        m_d = re.search(pat, main, re.I | re.S)
        if m_d:
            desc = _source_text(m_d.group(1), 5000)
            if len(desc) >= 20: break
    return {'images': imgs, 'colors': colors, 'sizes': sizes, 'variants': variants, 'media_links': media_links, 'desc': desc}

def _fetch_timehand_details_parallel(op, pids):
    if not pids: return {}
    import concurrent.futures
    cookies = list(getattr(op, '_jar', []))
    def _worker(pid):
        s = _session()
        for c in cookies:
            s._jar.set_cookie(c)
        return pid, timehand_detail(s, pid)
    out = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        for pid, d in pool.map(_worker, pids):
            if d: out[pid] = d
    return out

def update_timehand():
    user, pw = os.environ.get('TIMEHAND_USER', ''), os.environ.get('TIMEHAND_PASS', '')
    if not (user and pw): log('Timehand: no TIMEHAND_USER/TIMEHAND_PASS — skip'); return
    op = _session(); home = _timehand_login(op, user, pw)
    cats = sorted(set(re.findall(r'/affiliate/products\?id=(\d+)(?:&amp;|&)data_from=category', home)))
    if not cats: raise RuntimeError('Timehand: no product categories found')
    fresh = {}; requests = 0
    # أحدث المنتجات يغطّي الأصناف غير المصنفة، ثم صفحات كل التصنيفات تُكمل الكتالوج.
    feeds = [('https://time-hand.com/affiliate/products?data_from=latest&sort_by=latest&page=1', 'latest')]
    first = _get(op, feeds[0][0], 60); requests += 1
    for row in _timehand_card_rows(first): fresh[row['id']] = row
    last = min(100, _timehand_last_page(first))
    for n in range(2, last + 1):
        h = _get(op, f'https://time-hand.com/affiliate/products?data_from=latest&sort_by=latest&page={n}', 60); requests += 1
        for row in _timehand_card_rows(h): fresh[row['id']] = row
        time.sleep(0.15)
    for cid in cats:
        base = f'https://time-hand.com/affiliate/products?id={cid}&data_from=category&sort_by=latest&page=1'
        h = _get(op, base, 60); requests += 1
        for row in _timehand_card_rows(h): fresh[row['id']] = row
        max_page = min(100, _timehand_last_page(h))
        for n in range(2, max_page + 1):
            url = f'https://time-hand.com/affiliate/products?id={cid}&data_from=category&sort_by=latest&page={n}'
            sub = _get(op, url, 60); requests += 1
            for row in _timehand_card_rows(sub): fresh[row['id']] = row
            time.sleep(0.15)
        time.sleep(0.1)
    log(f'Timehand: fetched {len(fresh)} unique products across {len(cats)} categories ({requests} pages)')
    if len(fresh) < 100: raise RuntimeError('Timehand listing too small — login or page layout changed?')
    cur = jload('timehand.json', []); by_id = {str(x.get('id')): x for x in cur}
    detail_map = _fetch_timehand_details_parallel(op, list(fresh.keys()))
    details = len(detail_map)
    log(f'Timehand: fetched detail galleries for {details}/{len(fresh)} products')
    upd = add = drop = 0; seen = set()
    for pid, f in fresh.items():
        seen.add(pid); price = float(f['price']); commission = float(f['commission'])
        d = detail_map.get(pid) or {}
        d_imgs = d.get('images') or ([f['image']] if f['image'] else [])
        if pid in by_id:
            it = by_id[pid]
            ex = it.setdefault('extra', {})
            old_comm = float(ex.get('source_commission_original') or it.get('commission') or 0)
            if commission <= 0 and old_comm > 0 and f['suggested'] > old_comm:
                commission = old_comm
                price = round(f['suggested'] - commission, 2)
            if price > 0 and apply_price(it, 'timehand#' + pid, price): upd += 1
            it['commission'] = commission; it['stock'] = f['stock']; it['name'] = f['name'] or it.get('name')
            it['currency'] = 'جنيه'; it['price_text'] = price_text(price, 'جنيه')
            ex['source_commission_original'] = commission; ex['orig_suggested'] = f['suggested']
            if d.get('images'):
                # صور صفحة التفاصيل أولاً، ثم أي صور قديمة موجودة عندنا، بدون فقدان أي صورة
                merged = list(dict.fromkeys(d['images'] + [u for u in (it.get('images') or []) if u]))[:15]
                it['images'] = merged
                ex['detail_images'] = merged[1:]
            elif not it.get('images') and d_imgs:
                # لو تفاصيل المنتج فشلت ومفيش صور قديمة، نستخدم صورة القائمة بس
                it['images'] = d_imgs
                ex['detail_images'] = d_imgs[1:]
            if d.get('media_links'):
                ex['media_links'] = list(dict.fromkeys((ex.get('media_links') or []) + d['media_links']))
                if not ex.get('media_url'): ex['media_url'] = d['media_links'][0]
            if d.get('sizes') and not it.get('sizes'): it['sizes'] = d['sizes']
            if d.get('colors') and not ex.get('colors'): ex['colors'] = d['colors']
            if d.get('variants') and not ex.get('variants_detailed'): ex['variants_detailed'] = d['variants']
            if d.get('desc') and (not it.get('desc') or it.get('desc') == it.get('name')):
                it['desc'] = d['desc']
        else:
            imgs = d_imgs
            sizes, colors, variants = d.get('sizes', []), d.get('colors', []), d.get('variants', [])
            media_links = d.get('media_links', [])
            ex = {'colors': colors, 'variants_detailed': variants, 'stock_rows': [], 'stock_table': {'sizes': sizes, 'quantities': []},
                  'variant_codes': {}, 'product_code': '', 'quality_report': '', 'delivery_rate': '', 'marketing_desc': '', 'source_commission_original': commission,
                  'media_url': media_links[0] if media_links else '', 'detail_images': imgs[1:], 'media_links': media_links, 'orig_suggested': f['suggested']}
            it = {'id': pid, 'name': f['name'], 'sku': '', 'price': price, 'price_text': price_text(price, 'جنيه'), 'currency': 'جنيه',
                  'commission': commission, 'images': imgs, 'videos': [], 'files': [], 'desc': d.get('desc') or f['name'], 'category': normalize_category(guess_category(f['name'])),
                  'stock': f['stock'], 'sizes': sizes, 'rank': 0, 'country': 'مصر', 'added_at': TODAY, 'extra': ex}
            record_price('timehand#' + pid, price, 0); cur.append(it); by_id[pid] = it; add += 1
        if f['stock'] > 0: it.pop('unavailable_since', None)
        elif not it.get('unavailable_since'): it['unavailable_since'] = TODAY; drop += 1
    for it in cur:
        if str(it.get('id')) not in seen and it.get('stock', 1) != 0:
            it['stock'] = 0; it['unavailable_since'] = TODAY; drop += 1
    jsave('timehand.json', cur)
    summary['timehand'] = {'total': len(cur), 'fetched': len(fresh), 'updated': upd, 'added': add, 'unavailable': drop, 'details': details}
    log('Timehand done', summary['timehand'])

# ---------------------------------------------------------------- Aff Shopmart (منصة أف شوب مارت)
# الـ API: api.aff-shopmart.com (NestJS) — تسجيل دخول POST /api/auth/login ثم GET /api/products
SHOPMART_API = 'https://api.aff-shopmart.com'
SHOPMART_LOGIN_PATHS = ('/api/auth/login', '/api/auth/signin', '/api/login', '/api/users/login', '/api/auth/sign-in')
SHOPMART_PRODUCT_PATHS = ('/api/products', '/api/affiliate/products', '/api/dashboard/products')
SHOPMART_HEADERS = {**UA, 'Accept': 'application/json', 'Content-Type': 'application/json',
                    'Origin': 'https://www.aff-shopmart.com', 'Referer': 'https://www.aff-shopmart.com/ar/auth/login'}

def _sm_pick(raw, *keys, default=''):
    """أول قيمة موجودة فعلًا من مجموعة مفاتيح (لأن أسماء حقول الـ API ممكن تختلف)."""
    for k in keys:
        v = raw.get(k)
        if v not in (None, '', [], {}, 0):
            return v
    return default

def _sm_num(v):
    if isinstance(v, (int, float)): return float(v)
    m = re.search(r'\d[\d,.]*', str(v or ''))
    if not m: return 0.0
    try: return float(m.group(0).replace(',', ''))
    except Exception: return 0.0

def _sm_find_token(obj, depth=0):
    """يدوّر على توكن الدخول جوه أي شكل JSON رجع من الـ API."""
    if depth > 6: return ''
    if isinstance(obj, dict):
        for k, v in obj.items():
            kl = str(k).lower()
            if isinstance(v, str) and v and 'refresh' not in kl and any(t in kl for t in ('access_token', 'accesstoken', 'token', 'jwt')):
                return v
        for v in obj.values():
            t = _sm_find_token(v, depth + 1)
            if t: return t
    elif isinstance(obj, list):
        for v in obj[:5]:
            t = _sm_find_token(v, depth + 1)
            if t: return t
    return ''

def _sm_find_list(obj, depth=0):
    """يدوّر على قائمة المنتجات جوه ردّ الـ API بأي شكل تغليف."""
    if depth > 6: return []
    if isinstance(obj, list):
        if obj and isinstance(obj[0], dict): return obj
        for v in obj[:5]:
            r = _sm_find_list(v, depth + 1)
            if r: return r
        return []
    if isinstance(obj, dict):
        for k in ('products', 'items', 'results', 'rows', 'list', 'data'):
            if k in obj:
                r = _sm_find_list(obj[k], depth + 1)
                if r: return r
        for v in obj.values():
            r = _sm_find_list(v, depth + 1)
            if r: return r
    return []

def _sm_total_pages(obj, limit):
    """عدد الصفحات من أي شكل meta/pagination."""
    meta = obj if isinstance(obj, dict) else {}
    for key in ('meta', 'pagination', 'pageInfo', 'data'):
        m = meta.get(key) if isinstance(meta, dict) else None
        if isinstance(m, dict):
            for tk in ('lastPage', 'totalPages', 'last_page', 'total_pages'):
                if tk in m:
                    try: return max(1, int(_sm_num(m[tk])))
                    except Exception: pass
            for tk in ('total', 'count', 'totalCount'):
                if tk in m:
                    try: return max(1, int((_sm_num(m[tk]) + limit - 1) // limit))
                    except Exception: pass
    for tk in ('total', 'totalCount', 'count'):
        if isinstance(meta, dict) and tk in meta:
            try: return max(1, int((_sm_num(meta[tk]) + limit - 1) // limit))
            except Exception: pass
    return 0

def _sm_login(user, pw):
    """تسجيل دخول Aff Shopmart — يرجّع (opener, token, trace, raw)"""
    import http.cookiejar
    cj = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    trace, raw = [], {}
    body = json.dumps({'email': user, 'password': pw}).encode()
    for path in SHOPMART_LOGIN_PATHS:
        req = urllib.request.Request(SHOPMART_API + path, data=body, headers=SHOPMART_HEADERS)
        try:
            with op.open(req, timeout=45) as r:
                txt, status = r.read().decode('utf-8', 'ignore'), r.status
        except urllib.error.HTTPError as e:
            status, txt = e.code, e.read().decode('utf-8', 'ignore')
        except Exception as e:
            trace.append(f'{path}: {type(e).__name__} {str(e)[:60]}'); continue
        try: data = json.loads(txt)
        except Exception: data = {}
        trace.append(f'{path}: HTTP {status}')
        if status == 200:
            token = _sm_find_token(data)
            if not token:
                for c in cj:
                    if 'token' in c.name.lower(): token = c.value
            trace.append(f'{path}: token={"yes" if token else "no"} cookies={[c.name for c in cj]}')
            return op, token, trace, data
        raw = data
    return op, '', trace, raw

def _sm_get(op, url, token='', timeout=45, headers=None):
    hdr = dict(SHOPMART_HEADERS)
    if token: hdr['Authorization'] = 'Bearer ' + token
    if headers: hdr.update(headers)
    req = urllib.request.Request(url, headers=hdr)
    try:
        with op.open(req, timeout=timeout) as r:
            return r.status, r.read().decode('utf-8', 'ignore')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'ignore')
    except Exception as e:
        return 0, str(e)

def _sm_images(raw):
    """يجمع صور المنتج. الـ API بيرجّع mainImage (و/أو قائمة images/media في التفاصيل)."""
    out = []
    for key in ('images', 'image', 'media', 'gallery', 'photos', 'pictures', 'productImages', 'product_images',
                'mainImage', 'main_image', 'thumbnail', 'imageUrl', 'image_url'):
        v = raw.get(key)
        if not v: continue
        items = v if isinstance(v, list) else [v]
        for it in items:
            u = ''
            if isinstance(it, str): u = it
            elif isinstance(it, dict): u = str(it.get('url') or it.get('path') or it.get('src') or it.get('image') or '')
            u = u.strip()
            if not u: continue
            if u.startswith('/'): u = SHOPMART_API + u
            if u.startswith('http') and u not in out: out.append(u)
    return out

def _sm_minor(raw, *minor_keys, **kw):
    """الأسعار في الـ API بالقرش (minor units = القيمة × 100)."""
    for k in minor_keys:
        if k in raw and raw[k] not in (None, ''):
            return round(_sm_num(raw[k]) / 100.0, 2)
    for k in kw.get('plain', ()):
        if k in raw and raw[k] not in (None, ''):
            return _sm_num(raw[k])
    return 0.0

def _sm_variants(raw):
    """المقاسات/المتغيرات من مصفوفة variants (options + sku)."""
    sizes, variants = [], []
    for v in (raw.get('variants') or []):
        if not isinstance(v, dict): continue
        vsz = ''
        opts = v.get('options')
        if isinstance(opts, list):
            for o in opts:
                if isinstance(o, dict):
                    nm = str(o.get('name') or o.get('optionName') or o.get('type') or '').strip()
                    vl = str(o.get('value') or o.get('optionValue') or '').strip()
                    if vl and (not nm or any(t in nm.lower() for t in ('مقاس', 'size', 'option', 'خيار'))):
                        vsz = vsz or vl
                elif isinstance(o, str) and o.strip() and not vsz:
                    vsz = o.strip()
        variants.append({'color': str(v.get('color') or '').strip(), 'size': vsz,
                         'price': _sm_minor(v, 'priceDeltaMinorUnits', plain=('price',)), 'sku': str(v.get('sku') or '')})
        if vsz and vsz not in sizes: sizes.append(vsz)
    return sizes, variants

def _sm_category(raw):
    cat = raw.get('categoryName') or raw.get('category_name') or raw.get('category') or ''
    if isinstance(cat, dict): cat = cat.get('name') or cat.get('title') or ''
    if not cat:
        cats = raw.get('categories')
        if isinstance(cats, list) and cats:
            first = cats[0]
            cat = (first.get('name') if isinstance(first, dict) else str(first)) or ''
    return str(cat or '').strip()

# أقسام Aff Shopmart كما بترجع من الـ API → أقسام كتالوجنا القانونية
SHOPMART_CATEGORY_MAP = {
    'ساعات رجالي': 'ساعات', 'ساعات حريمي': 'ساعات', 'ساعات': 'ساعات', 'ساعات ذكية': 'ساعات', 'ساعة': 'ساعات',
    'ملابس رجالي': 'ملابس رجالي', 'ملابس رجالى': 'ملابس رجالي', 'ملابس رجالية': 'ملابس رجالي', 'رجالي': 'ملابس رجالي',
    'ملابس نسائية': 'ملابس حريمي', 'ملابس حريمي': 'ملابس حريمي', 'ملابس حريمى': 'ملابس حريمي', 'ملابس بناتي': 'ملابس حريمي', 'حريمي': 'ملابس حريمي',
    'ملابس أطفال': 'أطفال وألعاب', 'ملابس اطفال': 'أطفال وألعاب', 'ملابس ولادي': 'أطفال وألعاب', 'أطفال': 'أطفال وألعاب', 'اطفال': 'أطفال وألعاب',
    'أحذية رجالي': 'أحذية', 'أحذية حريمي': 'أحذية', 'أحذية اطفالي': 'أحذية', 'أحذية أطفال': 'أحذية', 'أحذية': 'أحذية',
    'سليبر': 'أحذية', 'كروكس': 'أحذية', 'صنادل': 'أحذية', 'شبشب': 'أحذية',
    'عطور رجالي': 'العناية والتجميل', 'عطور حريمي': 'العناية والتجميل', 'عطور للجنسين': 'العناية والتجميل',
    'عطور': 'العناية والتجميل', 'عناية وتجميل': 'العناية والتجميل', 'مستحضرات تجميل': 'العناية والتجميل',
    'ادوات منزلية': 'المنزل والمطبخ', 'أدوات منزلية': 'المنزل والمطبخ', 'مستلزمات منزلية': 'المنزل والمطبخ',
    'مطبخ': 'المنزل والمطبخ', 'ديكور': 'المنزل والمطبخ', 'المنزل': 'المنزل والمطبخ', 'المنزل والمطبخ': 'المنزل والمطبخ',
    'الكترونيات': 'إلكترونيات', 'إلكترونيات': 'إلكترونيات', 'اجهزة': 'إلكترونيات', 'موبايلات': 'إلكترونيات',
    'اكسسوارات': 'إكسسوارات', 'إكسسوارات': 'إكسسوارات', 'مجوهرات': 'إكسسوارات', 'نظارات': 'إكسسوارات',
    'شنط': 'شنط ومحافظ', 'شنط وحقائب': 'شنط ومحافظ', 'حقائب': 'شنط ومحافظ', 'محافظ': 'شنط ومحافظ',
    'عدد وأدوات': 'عدد وأدوات', 'أدوات': 'عدد وأدوات', 'معدات': 'عدد وأدوات',
    'سيارات': 'مستلزمات السيارات', 'مستلزمات سيارات': 'مستلزمات السيارات',
    'رياضة': 'الرياضة واللياقة', 'رياضة ولياقة': 'الرياضة واللياقة', 'لياقة': 'الرياضة واللياقة',
    'منتجات رقمية': 'منتجات رقمية', 'سوشيال ميديا': 'سوشيال ميديا',
}
SHOPMART_CATEGORY_MAP_AR = {_ar_key(k): v for k, v in SHOPMART_CATEGORY_MAP.items()}

def _sm_watch_name(name):
    """اسم يبدأ بكلمة ساعة/واتش (بعض منتجات المصدر مصنّفة عندهم «الكترونيات» وهي ساعات)."""
    n = _ar_key(name).strip()
    return any(n.startswith(k) for k in ('ساعه', 'ساعات', 'watch', 'watches'))

def _sm_best_category(cat_src, name):
    """قسم المنتج: قسم المصدر الأول (فيه كل الأقسام المعروفة عندهم)، وبعدين تخمين من الاسم."""
    src = str(cat_src or '').strip()
    if src:
        cat = normalize_category(src)
        if cat == src:
            cat = SHOPMART_CATEGORY_MAP_AR.get(_ar_key(src), '')
        if not cat:
            g = guess_category(name)
            cat = g if (g and g != 'المنزل والمطبخ') else (normalize_category(src) or 'منتجات أصلية')
    else:
        cat = guess_category(name) or 'منتجات أصلية'
    # بعض منتجات المصدر مصنّفة «الكترونيات» وهي ساعات فعلًا (الاسم نفسه يبدأ بكلمة ساعة/واتش)
    if cat == 'إلكترونيات' and _sm_watch_name(name): cat = 'ساعات'
    return cat

def _sm_map(raw):
    """يحوّل منتج Aff Shopmart لسكيما الكتالوج عندنا.
    price = صافي التكلفة (basePrice)، والعمولة الحالية بتروح لعمولة المصدر، والسعر الكامل في orig_suggested."""
    if not isinstance(raw, dict): return None
    status = str(raw.get('status') or '').strip().upper()
    if status and status not in ('ACTIVE', 'PUBLISHED', 'ENABLED'): return None
    pid = str(_sm_pick(raw, 'id', 'productId', 'product_id', 'uuid', '_id')).strip()
    name = str(_sm_pick(raw, 'title', 'name', 'nameAr', 'name_ar', 'nameEn')).strip()
    if not pid or not name: return None
    base = _sm_minor(raw, 'basePriceMinorUnits', 'basePriceMinor', plain=('basePrice', 'cost', 'affiliatePrice', 'wholesalePrice'))
    comm = _sm_minor(raw, 'commissionMinorUnits', 'commissionMinor', plain=('commission', 'commissionAmount', 'profit'))
    gross = _sm_minor(raw, 'finalPriceMinorUnits', 'finalPriceMinor', plain=('finalPrice', 'price', 'sellingPrice'))
    if base <= 0: base = round(gross - comm, 2) if gross > comm else 0
    if base <= 0: return None
    if gross <= base: gross = round(base + comm, 2)
    stock = int(_sm_num(_sm_pick(raw, 'stock', 'stockQuantity', 'quantity', 'qty', default=999999)) or 999999)
    sizes, variants = _sm_variants(raw)
    imgs = _sm_images(raw)
    sku = str(_sm_pick(raw, 'productCode', 'sku', 'code', 'reference')).strip()
    cat_src = _sm_category(raw)
    cat = _sm_best_category(cat_src, name)
    extra = {'colors': [], 'variants_detailed': [], 'source_commission_original': comm,
             'orig_suggested': gross, 'source_category': cat_src, 'source': 'shopmart'}
    if variants: extra['shopmart_variants'] = variants[:20]
    return {
        'id': pid, 'name': name, 'sku': sku, 'price': base, 'price_text': price_text(base, 'جنيه'), 'currency': 'جنيه',
        'commission': comm, 'images': imgs, 'videos': [], 'files': [], 'desc': '',
        'category': cat, 'stock': stock, 'sizes': sizes, 'rank': 0, 'country': 'مصر', 'added_at': TODAY,
        'extra': extra,
    }

def _sm_fetch(op, token, limit=100, max_pages=300):
    """يجيب كل منتجات /api/products بالصفحات (data.items) مع كشف حجم الصفحة الفعلي."""
    fresh, page, eff, empty_streak, pages = {}, 1, 0, 0, 0
    while page <= max_pages:
        st, txt = _sm_get(op, f'{SHOPMART_API}/api/products?page={page}&limit={limit}', token)
        if st == 0: break
        if st != 200:
            log(f'Shopmart: page {page} → HTTP {st}'); break
        try: data = json.loads(txt)
        except Exception: break
        if isinstance(data, dict) and data.get('success') is False:
            log(f"Shopmart: page {page} → {str(data.get('message'))[:80]}"); break
        rows = _sm_find_list(data)
        pages = page
        if page == 1:
            eff = len(rows) or limit
        got = 0
        for r in rows:
            it = _sm_map(r)
            if it: fresh[it['id']] = it; got += 1
        if got == 0:
            empty_streak += 1
            if empty_streak >= 2: break
        else:
            empty_streak = 0
        if eff and len(rows) < eff: break
        page += 1
        time.sleep(0.15)
    log(f'Shopmart: fetched {len(fresh)} products ({pages} pages, page size {eff})')
    return fresh, '/api/products', pages

def probe_shopmart():
    """تشخيص: يسجّل الدخول ويكتب شكل ردّ الـ API في data/_shopmart_probe.json (بدون كتابة منتجات)."""
    user, pw = os.environ.get('SHOPMART_USER', ''), os.environ.get('SHOPMART_PASS', '')
    out = {'at': NOW, 'has_creds': bool(user and pw), 'login': [], 'endpoints': [], 'products_sample': None}
    if not (user and pw):
        jsave('_shopmart_probe.json', out); log('Shopmart probe: no credentials'); return out
    op, token, trace, raw = _sm_login(user, pw)
    out['login'] = trace
    out['login_raw_keys'] = sorted(list(raw.keys()))[:30] if isinstance(raw, dict) else []
    log('Shopmart probe login:', ' | '.join(trace))
    if token:
        # (أ) الشكل العام + الترقيم على صفحة واحدة كبيرة
        st, txt = _sm_get(op, f'{SHOPMART_API}/api/products?page=1&limit=100', token)
        entry = {'path': '/api/products?page=1&limit=100', 'status': st, 'len': len(txt), 'head': txt[:200]}
        first_id = ''
        try:
            data = json.loads(txt)
            d = data.get('data') if isinstance(data, dict) else None
            if isinstance(d, dict):
                entry['data_keys'] = sorted(d.keys())
                for k, v in d.items():
                    if isinstance(v, list): entry.setdefault('data_lists', {})[k] = len(v)
                    elif isinstance(v, dict): entry.setdefault('data_dicts', {})[k] = sorted(v.keys())
                    else: entry.setdefault('data_scalars', {})[k] = v
                items = d.get('items') if isinstance(d.get('items'), list) else []
                entry['items'] = len(items)
                if items:
                    first_id = str(items[0].get('id') or '')
                    entry['first_item'] = json.loads(json.dumps(items[0], ensure_ascii=False, default=str))
                    entry['last_item'] = json.loads(json.dumps(items[-1], ensure_ascii=False, default=str))
                    entry['first_ids'] = [str(i.get('id') or '') for i in items[:3]]
        except Exception as e:
            entry['err'] = str(e)[:120]
        out['endpoints'].append(entry)
        log(f"Shopmart probe limit=100: HTTP {st} items={entry.get('items')} data_keys={entry.get('data_keys')}")
        # (ب) الترقيم: صفحة 2 بحد صغير — نتحقق أن المنتجات مختلفة
        st2, txt2 = _sm_get(op, f'{SHOPMART_API}/api/products?page=2&limit=2', token)
        e2 = {'path': '/api/products?page=2&limit=2', 'status': st2, 'len': len(txt2)}
        try:
            d2 = json.loads(txt2).get('data') or {}
            it2 = d2.get('items') if isinstance(d2.get('items'), list) else []
            e2['items'] = len(it2); e2['ids'] = [str(i.get('id') or '') for i in it2]
            e2['data_keys'] = sorted(d2.keys()) if isinstance(d2, dict) else None
        except Exception: pass
        out['endpoints'].append(e2)
        log(f"Shopmart probe page2: HTTP {st2} items={e2.get('items')} same_as_first={e2.get('ids') and e2.get('ids') == entry.get('first_ids')}")
        # (ج) تفاصيل منتج واحد: هل فيه صور/وصف إضافي؟
        if first_id:
            for dpath in (f'/api/products/{first_id}', f'/api/products/{first_id}/details'):
                st3, txt3 = _sm_get(op, SHOPMART_API + dpath, token)
                e3 = {'path': dpath, 'status': st3, 'len': len(txt3), 'head': txt3[:300]}
                try:
                    dd = json.loads(txt3)
                    dd = dd.get('data') if isinstance(dd, dict) else None
                    if isinstance(dd, dict):
                        e3['keys'] = sorted(dd.keys())
                        e3['sample'] = json.loads(json.dumps(dd, ensure_ascii=False, default=str)) if len(json.dumps(dd, default=str)) < 4000 else None
                    elif isinstance(dd, list) and dd and isinstance(dd[0], dict):
                        e3['keys'] = sorted(dd[0].keys())
                except Exception: pass
                out['endpoints'].append(e3)
                log(f"Shopmart probe {dpath}: HTTP {st3} keys={e3.get('keys')}")
    jsave('_shopmart_probe.json', out)
    return out

SHOPMART_GOV_AR = {
    'cairo': 'القاهرة', 'giza': 'الجيزة', 'alexandria': 'الإسكندرية', 'dakahlia': 'الدقهلية',
    'red sea': 'البحر الأحمر', 'beheira': 'البحيرة', 'fayoum': 'الفيوم', 'gharbiya': 'الغربية',
    'ismailia': 'الإسماعيلية', 'menofia': 'المنوفية', 'minya': 'المنيا', 'qaliubiya': 'القليوبية',
    'new valley': 'الوادي الجديد', 'suez': 'السويس', 'aswan': 'أسوان', 'assiut': 'أسيوط',
    'beni suef': 'بني سويف', 'port said': 'بورسعيد', 'damietta': 'دمياط', 'sharkia': 'الشرقية',
    'south sinai': 'جنوب سيناء', 'kafr al sheikh': 'كفر الشيخ', 'kafr el sheikh': 'كفر الشيخ',
    'matrouh': 'مطروح', 'luxor': 'الأقصر', 'qena': 'قنا', 'sohag': 'سوهاج', 'north sinai': 'شمال سيناء',
}
# تسميات عربية بترجع من الـ API باختلافات إملائية — نطبّعها على أسماء محافظاتنا
SHOPMART_GOV_AR_FIX = {
    'الأسكندرية': 'الإسكندرية', 'الاسكندرية': 'الإسكندرية', 'الإسماعلية': 'الإسماعيلية',
    'الاسماعلية': 'الإسماعيلية', 'اسوان': 'أسوان', 'أسوان': 'أسوان', 'اسيوط': 'أسيوط', 'أسيوط': 'أسيوط',
    'بني سويف': 'بني سويف', 'بنى سويف': 'بني سويف', 'القليوبية': 'القليوبية',
    'البحيرة': 'البحيرة', 'الدقهلية': 'الدقهلية', 'الشرقية': 'الشرقية', 'الغربية': 'الغربية',
    'المنوفية': 'المنوفية', 'المنيا': 'المنيا', 'الفيوم': 'الفيوم', 'البحر الأحمر': 'البحر الأحمر',
    'الوادي الجديد': 'الوادي الجديد', 'الوادى الجديد': 'الوادي الجديد', 'مطروح': 'مطروح',
    'مرسى مطروح': 'مطروح', 'جنوب سيناء': 'جنوب سيناء', 'شمال سيناء': 'شمال سيناء',
    'البحرالاحمر': 'البحر الأحمر', 'القاهرة': 'القاهرة', 'الجيزة': 'الجيزة',
}
def _sm_gov_norm(name):
    return (str(name or '').strip().replace('أ', 'ا').replace('إ', 'ا').replace('آ', 'ا')
            .replace('ى', 'ي').replace('ة', 'ه').replace(' ', ''))

def _sm_gov_ar(name):
    """اسم المحافظة بالعربي الموحّد (يقبل الإنجليزي أو العربي بأي إملاء)."""
    raw = str(name or '').strip()
    if not raw: return ''
    ar = SHOPMART_GOV_AR.get(raw.lower(), '')
    if ar: return ar
    key = _sm_gov_norm(raw)
    for cand, norm in SHOPMART_GOV_AR_FIX.items():
        if key == _sm_gov_norm(cand) or key == _sm_gov_norm(norm):
            return norm
    return raw

def _sm_governorates(op, token, slug=''):
    """قائمة محافظات مصر من واجهة الشحن العامة في Aff Shopmart."""
    st, txt = _sm_get(op, SHOPMART_API + '/api/public/locations/governorates', token, timeout=40,
                      headers={'x-store-slug': slug} if slug else None)
    if st != 200:
        return []
    try:
        data = json.loads(txt).get('data')
        return (data.get('items') if isinstance(data, dict) else data) or []
    except Exception:
        return []

def _sm_cities(op, token, slug, gov_id):
    st, txt = _sm_get(op, SHOPMART_API + '/api/public/locations/cities?governorateId=' + str(gov_id), token,
                      timeout=40, headers={'x-store-slug': slug} if slug else None)
    if st != 200:
        return []
    try:
        data = json.loads(txt).get('data')
        return (data.get('items') if isinstance(data, dict) else data) or []
    except Exception:
        return []

def _sm_fee(minor):
    try:
        v = float(minor) / 100.0
    except Exception:
        return 0.0
    return round(v, 2) if (v * 100) % 100 else round(v)

def update_shopmart_shipping():
    """يسحب أسعار الشحن الحقيقية لمتجر شوب مارت (لكل مدينة) ويحدّث صف شوب مارت في data/shipping.json."""
    user, pw = os.environ.get('SHOPMART_USER', ''), os.environ.get('SHOPMART_PASS', '')
    if not (user and pw):
        log('Shopmart shipping: no credentials'); summary['shopmart_shipping'] = {'error': 'no credentials'}; return
    op, token, trace, _raw = _sm_login(user, pw)
    if not token:
        log('Shopmart shipping: login failed', trace); summary['shopmart_shipping'] = {'error': 'login failed'}; return
    slug = ''
    try:
        st, txt = _sm_get(op, SHOPMART_API + '/api/dashboard/store/config', token)
        d = json.loads(txt).get('data') or {}
        slug = str(d.get('storeSlug') or (d.get('profile') or {}).get('slug') or '')
    except Exception:
        pass
    govs = _sm_governorates(op, token, slug)
    if not govs:
        log('Shopmart shipping: no governorates'); summary['shopmart_shipping'] = {'error': 'no governorates'}; return
    regions, cities_detail, missing = [], {}, []
    for g in govs:
        gname = str(g.get('name') or '').strip()
        ar = _sm_gov_ar(gname)
        if not ar or ar == gname:
            missing.append(gname)
        cities = _sm_cities(op, token, slug, g.get('id'))
        fees = [_sm_fee(c.get('shippingAmountMinorUnits')) for c in cities if c.get('shippingAmountMinorUnits') is not None]
        if not fees:
            continue
        # السعر الممثل للمحافظة = الأكثر تكراراً بين مدنها (وعند التعادل الأقل)
        counts = {}
        for f in fees:
            counts[f] = counts.get(f, 0) + 1
        best = sorted(counts.items(), key=lambda kv: (-kv[1], kv[0]))[0][0]
        detail = ' ، '.join(f'{f} ج.م × {n} مدينة' for f, n in sorted(counts.items(), key=lambda kv: -kv[1])[:4])
        regions.append({'region': ar, 'fee': best, 'currency': 'جنيه', 'notes': detail})
        cities_detail[ar] = [{'name': str(c.get('name') or ''), 'fee': _sm_fee(c.get('shippingAmountMinorUnits'))} for c in cities]
    if not regions:
        log('Shopmart shipping: no priced cities'); summary['shopmart_shipping'] = {'error': 'no priced cities'}; return
    order = ['القاهرة', 'الجيزة', 'الإسكندرية', 'القليوبية', 'الدقهلية', 'الشرقية', 'الغربية', 'المنوفية',
             'البحيرة', 'كفر الشيخ', 'دمياط', 'بورسعيد', 'الإسماعيلية', 'السويس', 'البحر الأحمر', 'الفيوم',
             'بني سويف', 'المنيا', 'أسيوط', 'سوهاج', 'قنا', 'الأقصر', 'أسوان', 'مطروح', 'الوادي الجديد',
             'شمال سيناء', 'جنوب سيناء']
    regions.sort(key=lambda r: order.index(r['region']) if r['region'] in order else 999)
    shipping = jload('shipping.json', [])
    if not isinstance(shipping, list): shipping = []
    row = {
        'platform': 'shopmart', 'name': 'شوب مارت',
        'source': 'أسعار الشحن الرسمية من لوحة Aff Shopmart (لكل مدينة) — ' + NOW,
        'updated_at': NOW,
        'regions': regions,
    }
    replaced = False
    for i, item in enumerate(shipping):
        if isinstance(item, dict) and item.get('platform') == 'shopmart':
            shipping[i] = row; replaced = True; break
    if not replaced: shipping.append(row)
    jsave('shipping.json', shipping)
    jsave('shipping_cities_shopmart.json', {'at': NOW, 'source': 'api.aff-shopmart.com/api/public/locations', 'governorates': cities_detail})
    summary['shopmart_shipping'] = {'governorates': len(regions), 'cities': sum(len(v) for v in cities_detail.values()),
                                    'fees': sorted({r['fee'] for r in regions}), 'unmapped': missing}
    log('Shopmart shipping done', summary['shopmart_shipping'])

def update_shopmart():
    """يسحب منتجات Aff Shopmart (api.aff-shopmart.com) ويدمجها في data/shopmart.json"""
    user, pw = os.environ.get('SHOPMART_USER', ''), os.environ.get('SHOPMART_PASS', '')
    if not (user and pw): log('Shopmart: no SHOPMART_USER/SHOPMART_PASS — skip'); return
    op, token, trace, _raw = _sm_login(user, pw)
    for line in trace: log('Shopmart login →', line)
    if not token: raise RuntimeError('Shopmart: login failed (no access token)')
    fresh, used_path, pages = _sm_fetch(op, token)
    if len(fresh) < 5: raise RuntimeError(f'Shopmart: listing too small ({len(fresh)}, {pages} pages) — login or API changed?')
    cur = jload('shopmart.json', [])
    by_id = {str(x.get('id')): x for x in cur}
    upd = add = drop = 0; seen = set()
    for pid, f in fresh.items():
        seen.add(pid)
        if pid in by_id:
            it = by_id[pid]; ex = it.setdefault('extra', {})
            if f['price'] > 0 and apply_price(it, 'shopmart#' + pid, f['price']): upd += 1
            it.update({'name': f['name'] or it.get('name'), 'stock': f['stock'], 'currency': 'جنيه',
                       'price_text': price_text(f['price'], 'جنيه')})
            if f['images']: it['images'] = f['images']
            if f['desc'] and (not it.get('desc') or it.get('desc') == it.get('name')): it['desc'] = f['desc']
            if f.get('sku'): it['sku'] = f['sku']
            ex['source_commission_original'] = f['commission']; ex['orig_suggested'] = f['extra']['orig_suggested']
            if f['category']: it['category'] = f['category']  # أقسام شوب مارت مصدرها الـ API مباشرة
        else:
            record_price('shopmart#' + pid, f['price'], 0)
            cur.append(f); by_id[pid] = f; add += 1
        if f['stock'] > 0: by_id[pid].pop('unavailable_since', None)
        elif not by_id[pid].get('unavailable_since'):
            by_id[pid]['unavailable_since'] = TODAY; drop += 1
    for it in cur:
        if str(it.get('id')) not in seen and it.get('stock', 1) != 0:
            it['stock'] = 0; it['unavailable_since'] = TODAY; drop += 1
    jsave('shopmart.json', cur)
    summary['shopmart'] = {'total': len(cur), 'fetched': len(fresh), 'updated': upd, 'added': add, 'unavailable': drop, 'pages': pages}
    log('Shopmart done', summary['shopmart'])

# ---------------------------------------------------------------- مصادر JSON إضافية (المنصات الفيزيكال)
def update_json_sources():
    """
    updater/sources.json:
      [{"plat":"vendoor","url":"https://…/export.json","headers":{"Cookie":"…"},"env_headers":{"Authorization":"VENDOOR_TOKEN"}}]
    الملف المتوقع: قائمة منتجات بنفس مفاتيح data/<plat>.json (id, name, price, stock, images…)
    """
    p = os.path.join(ROOT, 'updater', 'sources.json')
    if not os.path.exists(p): return
    for src in json.load(open(p, encoding='utf-8')):
        plat = src['plat']; url = src['url']
        if not url: continue
        hdr = dict(UA); hdr.update(src.get('headers', {}))
        for k, envk in (src.get('env_headers') or {}).items():
            if os.environ.get(envk): hdr[k] = os.environ[envk]
        try:
            req = urllib.request.Request(url, headers=hdr)
            with urllib.request.urlopen(req, timeout=60) as r: fresh = json.loads(r.read().decode('utf-8', 'ignore'))
            if isinstance(fresh, dict): fresh = fresh.get('items') or fresh.get('products') or fresh.get('data') or []
        except Exception as e:
            log(f'{plat}: source error {e}'); summary[plat] = {'error': str(e)[:120]}; continue
        cur = jload(f'{plat}.json', []); by_id = {str(x.get('id')): x for x in cur}
        upd = add = drop = 0; seen = set()
        for f in fresh:
            fid = str(f.get('id') or ''); 
            if not fid: continue
            seen.add(fid)
            npr = float(f.get('price') or 0)
            if fid in by_id:
                it = by_id[fid]
                if npr > 0 and apply_price(it, f'{plat}#{fid}', npr, f.get('cost')): upd += 1
                for k in ('stock', 'images', 'sizes', 'commission', 'desc', 'name'):
                    if k in f and f[k] not in (None, '', []):
                        it[k] = f[k]
                        if k == 'commission' and plat in ('vendoor', 'timehand', 'safqa', 'angazny', 'm5zoon', 'shopmart'):
                            it.setdefault('extra', {})['source_commission_original'] = f[k]
                it.pop('unavailable_since', None)
            else:
                f['added_at'] = TODAY; f.setdefault('currency', 'جنيه'); f.setdefault('price_text', price_text(npr, f['currency']))
                if 'commission' in f and plat in ('vendoor', 'timehand', 'safqa', 'angazny', 'm5zoon', 'shopmart'):
                    f.setdefault('extra', {})['source_commission_original'] = f['commission']
                record_price(f'{plat}#{fid}', npr, 0); cur.append(f); add += 1
        for it in cur:
            if str(it.get('id')) not in seen and it.get('stock', 1) != 0:
                it['stock'] = 0; it['unavailable_since'] = TODAY; drop += 1
        jsave(f'{plat}.json', cur)
        summary[plat] = {'total': len(cur), 'updated': upd, 'added': add, 'unavailable': drop}
        log(plat, summary[plat])

# ---------------------------------------------------------------- clean names & images
def clean_catalog_names_and_images():
    """تنظيف أسماء المنتجات والصور من الروابط الوهمية (place-holder / '/') والتكرار والأسماء المبتورة"""
    for plat in ('vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social', 'cezma'):
        rows = jload(plat + '.json', None)
        if not isinstance(rows, list): continue
        dedup = []
        seen_ids = set()
        for item in rows:
            if not isinstance(item, dict): continue
            pid = str(item.get('id') or '')
            if pid and pid in seen_ids:
                continue
            if pid: seen_ids.add(pid)
            # Clean images
            raw_imgs = item.get('images') or []
            clean_imgs = []
            for u in raw_imgs:
                if not isinstance(u, str): continue
                u = u.strip()
                if not u or u in ('/', '#') or len(u) < 5: continue
                ul = u.lower()
                if ('place-holder' in ul or 'image-place-holder' in ul or 'no-image' in ul
                        or ul.endswith('/default.png') or ul.endswith('/def.png')
                        or '/product/meta/' in ul or '/product/thumbnail/' in ul):
                    continue
                if ' ' in u and u.startswith('http'):
                    u = u.replace(' ', '%20')
                if u not in clean_imgs:
                    clean_imgs.append(u)
            item['images'] = clean_imgs
            ex = item.get('extra')
            if isinstance(ex, dict) and isinstance(ex.get('detail_images'), list):
                first_img = clean_imgs[0] if clean_imgs else ''
                d_imgs = []
                for u in ex['detail_images']:
                    if not isinstance(u, str): continue
                    u = u.strip()
                    if not u or u in ('/', '#') or len(u) < 5: continue
                    ul = u.lower()
                    if ('place-holder' in ul or 'image-place-holder' in ul or 'no-image' in ul
                            or ul.endswith('/default.png') or ul.endswith('/def.png')
                            or '/product/meta/' in ul or '/product/thumbnail/' in ul):
                        continue
                    if ' ' in u and u.startswith('http'):
                        u = u.replace(' ', '%20')
                    if u != first_img and u not in d_imgs:
                        d_imgs.append(u)
                ex['detail_images'] = d_imgs
            # Clean product name
            nm = str(item.get('name') or '').replace('\ufffd', '').strip()
            if isinstance(item.get('desc'), str) and '\ufffd' in item['desc']:
                item['desc'] = item['desc'].replace('\ufffd', '')
            if plat == 'm5zoon' and ('...' in nm or 'copy' in nm.lower()):
                desc = str(item.get('desc') or '')
                m_full = re.match(r'^(.+?)\s*(?:كود المنتج|SKU:)', desc)
                if m_full and len(m_full.group(1).strip()) >= 5:
                    nm = m_full.group(1).strip()
                nm = re.sub(r'\s*\(\s*copy\s*\d*\s*\)', '', nm, flags=re.I)
                nm = re.sub(r'\.{2,}\s*$', '', nm).strip()
            # Fix broken tatweel with spaces like 'تــ شيرت' -> 'تيشيرت', 'شــروال' -> 'شروال'
            nm = re.sub(r'\bت[\u0640]+\s*شيرت\b', 'تيشيرت', nm)
            nm = re.sub(r'[\u0640]+', '', nm)
            # Remove warehouse/internal codes from display name
            if plat in ('vendoor', 'angazny', 'timehand', 'safqa', 'm5zoon', 'shopmart'):
                nm = re.sub(r'\s*[\(\[\{]\s*(?:مخزن|محزن|مخز|كود|code)[^\)\]\}]*[\)\]\}]\s*', ' ', nm, flags=re.I)
                nm = re.sub(r'\s+(?:كود|code)\s+[A-Za-z0-9\-_]+\b', ' ', nm, flags=re.I)
                nm = re.sub(r'\s+\b(?:Lan|SV|PL)\d{1,4}\b', ' ', nm, flags=re.I)
                nm = re.sub(r'\s{2,}', ' ', nm).lstrip(' ?؟-–—:،,.').rstrip(' -–—:،,.')
            if nm:
                item['name'] = nm
            raw_d = html.unescape(str(item.get('desc') or '')).replace('\xa0', ' ').replace('\ufffd', '').strip()
            if plat == 'm5zoon':
                if 'ملفات المنتج' in raw_d:
                    raw_d = raw_d.split('ملفات المنتج', 1)[1].strip()
                raw_d = re.sub(r'^.*?الربح\s*', '', raw_d, flags=re.S).strip()
            if not raw_d or raw_d == nm or len(raw_d) < 25:
                cat = str(item.get('category') or 'منتجات أصلية').strip()
                ex_i = item.get('extra') if isinstance(item.get('extra'), dict) else {}
                cols_i = [str(c) for c in (ex_i.get('colors') or []) if str(c).strip() and not str(c).startswith('#')]
                sz_i = [str(s) for s in (item.get('sizes') or []) if str(s).strip()]
                parts_d = [f"{nm} — منتج أصلي عالي الجودة من قسم {cat} بتقفيل ممتاز وخامات مختارة بعناية."]
                if cols_i: parts_d.append("🎨 الألوان المتاحة: " + " ، ".join(cols_i[:12]))
                if sz_i: parts_d.append("📏 المقاسات المتاحة: " + " ، ".join(sz_i[:12]))
                parts_d.append("✅ المميزات:\n• خامات عالية الجودة وتقفيل احترافي مطابق للصور على الطبيعة.\n• متاح معاينة وفحص الطلب قبل الاستلام.\n• شحن سريع لجميع المحافظات والدفع نقداً عند الاستلام.")
                raw_d = "\n".join(parts_d)
            item['desc'] = raw_d
            dedup.append(item)
        jsave(plat + '.json', dedup)

# ---------------------------------------------------------------- physical commission policy
def normalize_physical_commissions():
    """Keep the feed's commission separately for gross-price reconstruction, while the effective catalog commission is always zero."""
    physical = ('vendoor', 'timehand', 'safqa', 'angazny', 'm5zoon', 'shopmart')
    def amount(v):
        if isinstance(v, (int, float)): return max(0.0, float(v))
        m = re.search(r'\d[\d,.]*', str(v or ''))
        if not m: return 0.0
        try: return max(0.0, float(m.group(0).replace(',', '')))
        except Exception: return 0.0
    for plat in physical:
        rows = jload(plat + '.json', None)
        if not isinstance(rows, list): continue
        for item in rows:
            if not isinstance(item, dict): continue
            ex = item.get('extra') if isinstance(item.get('extra'), dict) else {}
            item['extra'] = ex
            current = item.get('commission')
            if amount(current) > 0:
                ex['source_commission_original'] = current
            elif 'source_commission_original' not in ex:
                ex['source_commission_original'] = ex.get('profit', 0) if amount(ex.get('profit')) > 0 else current or 0
            item['commission'] = 0
        jsave(plat + '.json', rows)

# ---------------------------------------------------------------- lite files
def rebuild_lite():
    """يحدّث lite_*.json مع الحفاظ على بنيته الحالية (نلمس فقط الحقول المتغيرة)"""
    SYNC = ['price', 'price_text', 'old_price', 'discount', 'stock', 'name', 'sizes', 'cost', 'commission', 'currency',
            'added_at', 'price_drop_at', 'price_updated_at', 'unavailable_since', 'availability', 'is_available']
    for plat in ['vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social', 'cezma']:
        full = jload(f'{plat}.json', None)
        if full is None: continue
        old_lite = {str(x.get('id')): x for x in jload(f'lite_{plat}.json', [])}
        lite = []
        for x in full:
            y = old_lite.get(str(x.get('id')))
            if y is None:
                y = {k: x[k] for k in LITE_KEYS if k in x}
                if isinstance(y.get('images'), list): y['images'] = y['images'][:15]
                ex = x.get('extra')
                if isinstance(ex, dict):
                    y['extra'] = {k: v for k, v in ex.items() if k in ('colors', 'variants_detailed', 'service_id', 'rate_per_1000', 'price_per_1000', 'order_min', 'order_max', 'avg_time', 'order_field', 'platform', 'refill', 'delivery', 'material', 'source_commission_original')}
            else:
                y = dict(y)
                for k in ('old_price', 'discount', 'price_drop_at', 'unavailable_since'):
                    if k not in x: y.pop(k, None) if k in ('price_drop_at', 'unavailable_since') else None
                if isinstance(x.get('images'), list): y['images'] = x['images'][:15]
                if plat in ('social', 'g2g') and isinstance(x.get('extra'), dict) and isinstance(y.get('extra'), dict):
                    for k in ('variants_detailed', 'price_per_1000', 'rate_per_1000', 'order_min', 'order_max', 'avg_time'):
                        if k in x['extra']: y['extra'][k] = x['extra'][k]
            for k in SYNC:
                if k in x: y[k] = x[k]
            if plat in ('vendoor', 'timehand', 'safqa', 'angazny', 'm5zoon', 'shopmart'):
                full_extra = x.get('extra') if isinstance(x.get('extra'), dict) else {}
                lite_extra = y.get('extra') if isinstance(y.get('extra'), dict) else {}
                lite_extra = dict(lite_extra)
                for ek in ('colors', 'source_commission_original', 'purchasing', 'orig_suggested', 'suggested', 'cost', 'profit', 'price_min', 'price_max', 'minimum', 'maximum'):
                    if ek in full_extra:
                        lite_extra[ek] = full_extra[ek]
                    elif ek == 'source_commission_original':
                        lite_extra.pop(ek, None)
                y['extra'] = lite_extra
            lite.append(y)
        jsave(f'lite_{plat}.json', lite)
    idx_map = {}
    for plat in ('vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social', 'cezma'):
        for fn in (f'{plat}.json', f'lite_{plat}.json'):
            rows = _cezma_load_shards() if plat == 'cezma' and fn.startswith('lite_') else jload(fn, [])
            if not isinstance(rows, list): continue
            for r in rows:
                pid = str(r.get('id', '')).strip() if isinstance(r, dict) else ''
                if pid: idx_map[pid] = plat
    jsave('id_index.json', idx_map)

# ---------------------------------------------------------------- Lightweight storefront count summary
def _catalog_image_is_valid(value):
    if not isinstance(value, str):
        return False
    url = value.strip()
    if not url or url in ('/', '#') or len(url) < 5:
        return False
    lower = url.lower()
    return not any(token in lower for token in (
        'place-holder', 'image-place-holder', 'no-image', '/product/meta/', '/product/thumbnail/'
    )) and not lower.endswith(('/default.png', '/def.png'))


def _catalog_row_has_image(row, plat, local_detail_ids):
    if not isinstance(row, dict):
        return False
    images = row.get('images') if isinstance(row.get('images'), list) else []
    if any(_catalog_image_is_valid(url) for url in images):
        return True
    extra = row.get('extra') if isinstance(row.get('extra'), dict) else {}
    detail = extra.get('detail_images') if isinstance(extra.get('detail_images'), list) else []
    if any(_catalog_image_is_valid(url) for url in detail):
        return True
    return f"{plat}#{str(row.get('id') or '')}" in local_detail_ids


def build_catalog_counts(cezma_index_signature='', cezma_rows=None):
    """Keep storefront category counts and the Cezma search-index freshness marker."""
    physical = ('timehand', 'safqa', 'vendoor', 'angazny', 'shopmart')
    local_details = jload('detail_index.json', {})
    local_detail_ids = set()
    if isinstance(local_details, dict):
        local_detail_ids = {
            str(key) for key, images in local_details.items()
            if isinstance(images, list) and any(_catalog_image_is_valid(url) for url in images)
        }
    platform_counts = {}
    physical_categories = {}
    for plat in physical:
        rows = jload(f'lite_{plat}.json', [])
        if not isinstance(rows, list):
            rows = []
        count = 0
        for row in rows:
            if not _catalog_row_has_image(row, plat, local_detail_ids):
                continue
            count += 1
            category = str(row.get('category') or '').strip()
            if category:
                physical_categories[category] = physical_categories.get(category, 0) + 1
        platform_counts[plat] = count

    if not isinstance(cezma_rows, list):
        cezma_rows = _cezma_load_shards()
    cezma_count = 0
    cezma_categories = {}
    for row in cezma_rows:
        if not _catalog_row_has_image(row, 'cezma', local_detail_ids):
            continue
        cezma_count += 1
        category = str(row.get('category') or '').strip()
        if category:
            cezma_categories[category] = cezma_categories.get(category, 0) + 1
    platform_counts['cezma'] = cezma_count
    return {
        'version': 2,
        'physical_with_images': sum(platform_counts.values()),
        'physical_platforms': platform_counts,
        'physical_categories': dict(sorted(physical_categories.items())),
        'cezma_categories': dict(sorted(cezma_categories.items())),
        'cezma_index_signature': str(cezma_index_signature or ''),
    }


# ---------------------------------------------------------------- Open production: normalized virtual stock; source inventory never limits sales.
def force_open_production():
    open_qty = 999999
    platforms = ['vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social', 'cezma']
    def open_item(item):
        if not isinstance(item, dict): return
        item['stock'] = open_qty
        item.pop('unavailable_since', None)
        ex = item.get('extra')
        if not isinstance(ex, dict): return
        for key in ('variants_detailed', 'variants', 'offers'):
            variants = ex.get(key)
            if isinstance(variants, list):
                for variant in variants:
                    if isinstance(variant, dict): variant['stock'] = open_qty
        rows = ex.get('stock_rows')
        if isinstance(rows, list):
            for row in rows:
                if not isinstance(row, dict): continue
                keys = [k for k in ('qty', 'quantity', 'stock') if k in row]
                for key in keys: row[key] = open_qty
        table = ex.get('stock_table')
        if isinstance(table, dict) and isinstance(table.get('quantities'), list):
            vals = table['quantities']
            if vals and all(isinstance(v, (int, float)) or (isinstance(v, str) and v.strip().isdigit()) for v in vals):
                table['quantities'] = [open_qty for _ in vals]
    for plat in platforms:
        for name in (plat + '.json', 'lite_' + plat + '.json'):
            rows = jload(name, None)
            if not isinstance(rows, list): continue
            for item in rows: open_item(item)
            jsave(name, rows)

# ---------------------------------------------------------------- FTP
def site_deploy_files():
    import glob
    out = set()
    for pat in ('*.php', 'dashboard/*.php', 'assets/*.css', 'assets/*.js', 'assets/*.woff2', 'assets/Cairo-OFL.txt'):
        for full in glob.glob(os.path.join(ROOT, pat)):
            if os.path.isfile(full):
                out.add(os.path.relpath(full, ROOT).replace('\\', '/'))
    # أيقونات الموقع (manifest.json + wf_product_placeholder بيستخدموا assets/icon-512.png)
    # وصور التفاصيل المحلية (data/*.json بتشاور على assets/detail/...) — كانوا بره النشر،
    # فالصور دي كانت بتضرب خطأ على الاستضافة وهي مطلوبة في كروت «صور وفيديوهات المنتج».
    for pat in ('assets/*.png', 'assets/*.webp', 'assets/detail/*.jpg', 'assets/detail/*.jpeg',
                'assets/detail/*.png', 'assets/detail/*.webp', 'assets/detail/*.json'):
        for full in glob.glob(os.path.join(ROOT, pat)):
            if os.path.isfile(full):
                out.add(os.path.relpath(full, ROOT).replace('\\', '/'))
    # ملفات إعداد السيرفر (حماية ملفات النظام — مثل منع فتح amazon_lib.php مباشرة)
    for extra in ('.htaccess', 'php.ini', 'robots.txt', 'manifest.json'):
        if os.path.isfile(os.path.join(ROOT, extra)):
            out.add(extra)
    import glob as _g
    out.update(os.path.relpath(f, ROOT).replace('\\', '/') for f in sorted(_g.glob(os.path.join(DATA, 'cezma_shards', 'lite_cezma_*.json'))))
    for plat in ('vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social', 'cezma'):
        for fn in (f'data/{plat}.json', f'data/lite_{plat}.json'):
            if os.path.isfile(os.path.join(ROOT, fn)):
                out.add(fn)
    # ملفات مساعدة لا يعيد المحدّث بناءها لكنها مطلوبة على الموقع
    # (visual_index.json يشغّل البحث بالصورة في api_image_search.php)
    for extra_fn in ('data/id_index.json', 'data/detail_index.json', 'data/catalog_counts.json', 'data/shipping.json', 'data/shipping_cities_shopmart.json', 'data/visual_index.json', 'data/visual_features.json'):
        if os.path.isfile(os.path.join(ROOT, extra_fn)):
            out.add(extra_fn)
    out.update(os.path.relpath(f, ROOT).replace(os.sep, '/') for f in sorted(_g.glob(os.path.join(DATA, 'visual_cezma.*.part-*'))))
    out.update(os.path.relpath(f, ROOT).replace(os.sep, '/') for f in sorted(_g.glob(os.path.join(DATA, 'zz_cezma_index', '*.json'))))
    return out

def ftp_upload(files, cleanup_source_shards=True):
    host, user, pw = os.environ.get('FTP_HOST'), os.environ.get('FTP_USER'), os.environ.get('FTP_PASS')
    if not (host and user and pw): log('FTP: no credentials — skipping upload'); return 0, []
    remote_root = os.environ.get('FTP_ROOT', '/htdocs')
    def connect():
        f = ftplib.FTP(host, timeout=90)
        f.login(user, pw)
        f.cwd(remote_root)
        return f
    ftp = connect()
    # إنشاء أي مجلدات فرعية ناقصة على الاستضافة (مثل data/cezma_shards)
    _dirs = sorted({os.path.dirname(r) for r in files if os.path.dirname(r)})
    for _d in _dirs:
        _cur = ''
        for _part in _d.split('/'):
            _cur = f'{_cur}/{_part}' if _cur else _part
            try: ftp.mkd(_cur)
            except Exception: pass
    ok = 0
    failed = []
    uploaded_files = set()
    # Shared PHP helpers must arrive before controllers; publish the index manifest last.
    def _upload_order(rel):
        priority = 0 if rel in ('ui-components.php', 'ui-runtime.php') else (2 if rel.startswith('data/') else 1)
        manifest_last = 1 if rel == 'data/zz_cezma_index/zz_manifest.json' else 0
        return priority, manifest_last, rel
    ordered_files = sorted(files, key=_upload_order)
    for rel in ordered_files:
        if (rel.startswith('data/cezma_shards/') or rel.startswith('data/zz_cezma_index/')) and 'data/catalog_counts.json' in failed:
            failed.append(rel)
            log('⏭️ skipped Cezma source/index because catalog signature did not upload:', rel)
            continue
        if rel == 'data/zz_cezma_index/zz_manifest.json':
            blockers = [name for name in failed if name.startswith('data/cezma_shards/') or
                        (name.startswith('data/zz_cezma_index/') and name != rel)]
            if blockers:
                failed.append(rel)
                log('⏭️ skipped Cezma index manifest because a source/index file failed')
                continue
        local_path = os.path.join(ROOT, rel)
        if not os.path.isfile(local_path): continue
        data = open(local_path, 'rb').read()
        # فهارس الصور متعددة الصور أكبر من ملفات الكتالوج العادية؛ ارفع لها حدًا مناسبًا.
        if rel in ('data/visual_cezma.bin', 'data/visual_cezma.idx'):
            _limit = 96 * 1024 * 1024
        elif rel == 'data/visual_features.json':
            _limit = 32 * 1024 * 1024
        else:
            _limit = 10 * 1024 * 1024
        if len(data) > _limit: log('SKIP >limit', rel, len(data)); continue
        done = False
        # Shared hosting FTP can briefly reject a newly created large index file.
        # Retry with a fresh connection and a short backoff; record whether the
        # failure happened while writing or while verifying so the next run is diagnosable.
        max_attempts = 5
        for attempt in range(max_attempts):
            phase = 'STOR'
            try:
                ftp.storbinary('STOR ' + rel, io.BytesIO(data))
                phase = 'RETR'
                buf = io.BytesIO(); ftp.retrbinary('RETR ' + rel, buf.write)
                if hashlib.md5(buf.getvalue()).hexdigest() == hashlib.md5(data).hexdigest():
                    ok += 1; uploaded_files.add(rel); log('✅ uploaded', rel, len(data)); done = True; break
                log('⚠️ بعد الرفع الملف على السيرفر مختلف:', rel, 'remote=', len(buf.getvalue()), 'local=', len(data))
            except Exception as e:
                log('FTP attempt %d/%d failed during %s on %s:' % (attempt + 1, max_attempts, phase, rel), str(e)[:160])
                try: ftp.quit()
                except Exception: pass
                if attempt + 1 < max_attempts:
                    time.sleep(min(2 ** attempt, 8))
                    try: ftp = connect()
                    except Exception as e2: log('❌ reconnect failed:', str(e2)[:160])
        if not done:
            failed.append(rel); log('❌ failed', rel)
            if 'visual_cezma' in rel:
                # تشخيص: هل الملف موجود على السيرفر؟ وحجمه؟
                try:
                    _names = [n for n in ftp.nlst('data') if 'visual' in n]
                    log('diag visual files on server:', _names)
                except Exception as _de:
                    log('diag nlst failed:', str(_de)[:160])
    # Index-only must not delete source or visual-index files; normal runs retain their cleanup.
    if cleanup_source_shards:
        try:
            import glob as _glob_mod
            _local_shards = {os.path.basename(f) for f in _glob_mod.glob(os.path.join(ROOT, 'data', 'cezma_shards', 'lite_cezma_*.json'))}
            if _local_shards:
                for _name in ftp.nlst('data/cezma_shards'):
                    _base = os.path.basename(_name)
                    if re.match(r'^lite_cezma_\d{2}\.json$', _base) and _base not in _local_shards:
                        try:
                            ftp.delete('data/cezma_shards/' + _base); log('🗑️ removed stale shard', _base)
                        except Exception as e:
                            log('❌ could not remove', _base, str(e)[:120])
            _local_visual_parts = {os.path.basename(f) for f in _glob_mod.glob(os.path.join(ROOT, 'data', 'visual_cezma.*.part-*'))}
            for _name in ftp.nlst('data'):
                _base = os.path.basename(_name)
                if re.match(r'^visual_cezma\.(?:bin|idx)\.part-\d{3,}$', _base) and _base not in _local_visual_parts:
                    try:
                        ftp.delete('data/' + _base); log('🗑️ removed stale visual index part', _base)
                    except Exception as e:
                        log('❌ could not remove visual index part', _base, str(e)[:120])
        except Exception as e:
            log('⚠️ shard/visual cleanup skipped:', str(e)[:160])
    else:
        log('⏭️ source shard and visual-index cleanup disabled for index-only deployment')

    if 'data/zz_cezma_index/zz_manifest.json' in uploaded_files:
        # Old derived index chunks are removed only after the new manifest is verified on FTP.
        try:
            import glob as _glob_mod
            _local_index = {os.path.basename(f) for f in _glob_mod.glob(os.path.join(ROOT, 'data', 'zz_cezma_index', '*.json'))}
            if _local_index:
                for _name in ftp.nlst('data/zz_cezma_index'):
                    _base = os.path.basename(_name)
                    if _base.endswith('.json') and _base not in _local_index:
                        try:
                            ftp.delete('data/zz_cezma_index/' + _base); log('🗑️ removed stale Cezma index file', _base)
                        except Exception as e:
                            log('❌ could not remove Cezma index file', _base, str(e)[:120])
        except Exception as e:
            log('⚠️ Cezma index cleanup skipped:', str(e)[:160])
    else:
        log('⏭️ stale Cezma index cleanup skipped until manifest upload is verified')
    try: ftp.quit()
    except Exception: pass
    return ok, failed


# ------------------------------------------------- سيزما: توحيد الأقسام مع أقسام الموقع
# أقسام سيزما الرئيسية (الموضة والجمال، سوبر ماركت، الكمبيوتر…) مش من أقسام الموقع،
# فبنترجمها لأقسام الموقع الموحّدة قبل الكتابة في الشظايا. «الموضة والجمال» لوحدها ~48%
# من الكتالوج (ملابس/أحذية/شنط/ساعات/عطور…) ومالهاش قسم واحد مقابل، فبنفصّلها بالاسم.
# ملاحظة: نفس الخريطة والقواعد (بنفس الترتيب) موجودة في config.php في
# wf_cezma_bucket_map / wf_cezma_name_rules — أي تعديل لازم يتعمل في المكانين.
CEZMA_CAT_MAP = {
    'الموضة والجمال': '',                      # متعدد الأقسام → يُفصَّل بالاسم
    'ادوات ومستلزمات المنزل والحديقة': 'المنزل والمطبخ',
    'أجهزة منزلية': 'المنزل والمطبخ',
    'اثاث و ديكور': 'المنزل والمطبخ',
    'الاضاءة والمصابيح': 'المنزل والمطبخ',
    'سوبر ماركت': 'المنزل والمطبخ',
    'مستلزمات الأطفال': 'أطفال وألعاب',
    'مكتبات و أدوات مدرسية': 'أطفال وألعاب',
    'كتب وروايات': 'أطفال وألعاب',
    'اجهزة ومستلزمات طبية': 'العناية والتجميل',
    'موبايل و تابلت و ملحقاتها': 'إلكترونيات',
    'أجهزة الكترونية': 'إلكترونيات',
    'الكمبيوتر': 'إلكترونيات',
    'الكاميرات واكسسواراتها': 'إلكترونيات',
    'الهدايا و المشغولات اليدوية ومستلزماتها': 'إكسسوارات',
    'الرياضة والتنزة': 'الرياضة واللياقة',
    'المعدات واللوازم الكهربائية': 'عدد وأدوات',
}
CEZMA_FASHION_FALLBACK = 'ملابس حريمي'   # الباقي من «الموضة والجمال» (ملابس/إنهاءات مش متطابقة)
# قواعد تفصيل «الموضة والجمال» — أول قسم يتطابق مع اسم المنتج هو اللي يكسب
CEZMA_CAT_RULES = [
    # ما نصنفش المنتج «رجالي» من الصفة وحدها؛ نوع القطعة (حذاء/ساعة/شنطة)
    # لازم يكسب قبل وصف الجنس.
    ('إكسسوارات', ['نظارات', 'نظارة', 'نظاره', 'نضارات', 'نضارة', 'نضاره', ]),
    ('أحذية', ['بوط', 'بوت', 'sambaa', 'samba', 'حذاء', 'احذيه', 'احذية', 'كوتش', 'كوتشي', 'سنيكر', 'صندل', 'شبشب', 'سليبر', 'كروكس', 'شوز', 'هاف', 'جزمة', 'جزمه', 'boot', 'boots', 'shoes', 'shoe', 'sneaker', 'sandal', 'slipper', 'crocs']),
    ('شنط ومحافظ', ['شنطة', 'شنطه', 'شنط', 'حقيبه', 'حقائب', 'محفظة', 'محفظه', 'باك باك', 'شنطة ظهر', 'حقيبة ظهر', 'bag', 'bags', 'wallet', 'backpack', 'handbag', 'tote', 'purse']),
    ('ساعات', ['ساعة', 'ساعه', 'ساعات', 'واتش', 'watch', 'watches', 'wrist']),
    ('مستلزمات السيارات', ['سيارة', 'سياره', 'عربية', 'عربيه', 'كاوتش', 'منفاخ', 'وايبر', 'شماسة', 'دشكام', 'محرك', 'car', 'cars', 'tire', 'tyre', 'wiper', 'dashcam']),
    ('ملابس حريمي', ['عبايات', 'لانجريهات', 'لانجري', 'لانجيري', 'شروال', 'مايوه', 'بوركيني', 'بيجامه', 'بيجامات', 'مخمريه', 'بلوز', 'بلاوز', 'كاردي', 'مشد', 'ميني روب', 'جلبيه', 'تريكو', 'اسكيرت', 'دفايه', 'مروحه', 'ملحفه', 'ملحفة', 'فرنسي', 'خمار', 'فستان', 'فساتين', 'عباية', 'عبايه', 'اسدال', 'بلوزة', 'بلوزه', 'جيبة', 'جيبه', 'طرحة', 'طرحه', 'حجاب', 'نقاب', 'تونيك', 'تونك', 'بيجامة', 'بجامة', 'دريس', 'كشمير', 'بلوفر', 'بلوفرات', 'جامبسوت', 'ليجن', 'قفطان', 'dress', 'dresses', 'skirt', 'blouse', 'abaya', 'lingerie', 'hijab', 'nightgown']),
    ('ملابس رجالي', ['ملابس رجالي', 'ملابس رجالى', 'سوت رجالي', 'سوت رجالى', 'بدلة رجالي', 'بدله رجالي', 'تيشيرت', 'تيشرت', 'تشيرت', 'قميص', 'قمصان', 'بنطلون', 'بنطال', 'جاكيت', 'بدلة', 'بدله', 'ترنج', 'ترينج', 'شورت', 'بوكسر', 'جلابية', 'جلابيه', 'سويت شيرت', 'سويتشرت', 'سويتشيرت', 'هودي', 'فانلة', 'بالطو', 'جينز', 'كارديجان', 'shirt', 'tshirt', 't-shirt', 'pants', 'trouser', 'trousers', 'jacket', 'hoodie', 'jeans', 'sweatshirt', 'polo', 'suit']),
    ('العناية والتجميل', ['ميكاب', 'ميكب', 'واكس', 'wax', 'كوبي', 'كوبى', 'عطر', 'عطور', 'بارفان', 'برفان', 'برفيوم', 'كولونيا', 'بودي', 'body', 'ميست', 'مزيل', 'ازاله عرق', 'رول اون', 'ايشادو', 'بلاشر', 'بلشر', 'ايلاينر', 'ليب', 'شفاه', 'مكياج', 'مقشر', 'سكراب', 'ماسك', 'تونر', 'سيروم', 'بودره', 'صبغه', 'باديكير', 'مانيكير', 'اظافر', 'الاظافر', 'شامبو', 'بلسم', 'ازاله الشعر', 'لازاله الشعر', 'حلاقه', 'ديرما', 'ليفه', 'سبراي', 'توكه', 'كونتور', 'اويل', 'كريم', 'لوشن', 'للبشره', 'البشره', 'للشعر', 'الشعر', 'الوجه', 'شاور جل', 'جل استحمام', 'جل شاور', 'بخاخ', 'خافي عيوب', 'شفايف', 'زيوت', 'delina', 'emilia', 'parfum', 'perfume', 'eau de', 'edp', 'edt', 'oil', 'dry oil', 'ميكاب', 'ميكب', 'كوبي', 'كوبى', 'كريم', 'سيرم', 'سيروم', 'شامبو', 'بلسم', 'عطر', 'برفان', 'برفن', 'برفيوم', 'اوديبرفان', 'اودوبرفان', 'مكياج', 'روج', 'ماسكارا', 'مشط', 'فرشاة اسنان', 'معجون', 'حلاقة', 'حلاقه', 'ماسك', 'مكواة شعر', 'مكواه شعر', 'استشوار', 'سشوار', 'مجفف شعر', 'بشرة', 'بشره', 'صابون', 'غسول', 'لوشن', 'مرطب', 'تفتيح', 'رموش', 'حواجب', 'اظافر', 'كحل', 'جلوس', 'اسكراب', 'بادكير', 'بخور', 'مسك', 'شعر', 'زيت', 'عناية', 'منظف', 'معقم', 'كولاجين', 'مزيل العرق', 'بدي سبلاش', 'اسبلاش', 'تجميل', 'حناء', 'حنه', 'perfume', 'parfum', 'cream', 'serum', 'shampoo', 'makeup', 'mascara', 'skincare', 'beauty', 'razor', 'body splash', 'hair']),
    ('أطفال وألعاب', ['اطفال', 'طفل', 'بيبي', 'رضاعة', 'رضاعه', 'حفاض', 'بازل', 'مكعبات', 'دمية', 'لعبة', 'لعبه', 'العاب', 'استرولر', 'kids', 'baby', 'toy', 'toys', 'child']),
    ('الرياضة واللياقة', ['دمبل', 'رياضة', 'رياضه', 'لياقة', 'لياقه', 'جيم', 'مشاية', 'دراجة', 'دراجه', 'ترامبولين', 'تمرين', 'يوجا', 'تخييم', 'سباحه', 'كاراتيه', 'sport', 'sports', 'fitness', 'gym', 'yoga', 'dumbbell', 'bike', 'cycle', 'camping', 'swimming']),
    ('إلكترونيات', ['موبايل', 'تابلت', 'شاحن', 'سماعة', 'سماعه', 'كابل', 'باور بانك', 'كاميرا', 'بلوتوث', 'ميكروفون', 'ماوس', 'كيبورد', 'لابتوب', 'راوتر', 'فلاشة', 'فلاشه', 'شاشة', 'شاشه', 'تلفزيون', 'بروجكتر', 'ريموت', 'mobile', 'charger', 'cable', 'camera', 'bluetooth', 'headphone', 'headphones', 'phone', 'tablet', 'mouse', 'keyboard', 'laptop', 'router', 'speaker', 'usb', 'power bank']),
    ('إكسسوارات', ['ولاعه', 'ولاعة', 'سجائر', 'سجاير', 'سلسه', 'اساور', 'أساور', 'سوار', 'النظارات', 'النظارة', 'نظارة', 'نظاره', 'سلسلة', 'سلسله', 'انسيال', 'خاتم', 'اسورة', 'اسوره', 'حلق', 'قبعة', 'قبعه', 'كاب', 'حزام', 'اكسسوار', 'عقد', 'شال', 'كوفية', 'ميدالية', 'سبحه', 'سبحة', 'كوليه', 'بروش', 'sunglass', 'sunglasses', 'glasses', 'necklace', 'ring', 'bracelet', 'earring', 'cap', 'hat', 'belt', 'jewelry', 'jewellery', 'keychain']),
    ('عدد وأدوات', ['مفك', 'مفاتيح', 'شنيور', 'منشار', 'دريل', 'كماشة', 'كماشه', 'زرجينة', 'شاكوش', 'بنسة', 'لحام', 'مسامير', 'عدة', 'معدات', 'ادوات', 'drill', 'wrench', 'screwdriver', 'hammer', 'saw', 'tool', 'tools']),
    ('المنزل والمطبخ', ['مطبخ', 'حلة', 'حله', 'طاسة', 'طاسه', 'قطاعة', 'قطاعه', 'مبشرة', 'مبشره', 'خلاط', 'عصارة', 'عصاره', 'مفرمة', 'مفرمه', 'كبة', 'كبه', 'غلاية', 'غلايه', 'قلاية', 'قلايه', 'مقلاة', 'مقلايه', 'جلاية', 'جلايه', 'كاتيل', 'كيتل', 'عجان', 'مضرب بيض', 'كوب', 'سرير', 'مرتبة', 'مرتبه', 'سجادة', 'سجاده', 'ستارة', 'ستاره', 'مفرش', 'ملاءة', 'ملاية', 'ملايات', 'لحاف', 'وسادة', 'وساده', 'بطانية', 'منشفة', 'منشفه', 'ممسحة', 'ممسحه', 'مكنسة', 'مكنسه', 'لمبة', 'لمبه', 'مصباح', 'اضاءة', 'ديكور', 'منظم', 'ترمس', 'صينية', 'صينيه', 'دفاية', 'دفايه', 'مروحة', 'مروحه', 'غسالة', 'غساله', 'ثلاجة', 'ثلاجه', 'سخان', 'تكييف', 'فرن كهربا', 'فرن غاز', 'ميكروويف', 'طقم مطبخ', 'اواني', 'ملعقة', 'ملعقه', 'ملاعق', 'معالق', 'كوباية', 'أكواب', 'اكواب', 'خزانة', 'بوتاجاز', 'سلة', 'سله', 'شواية', 'شوايه', 'حافظة طعام', 'حافظه طعام', 'قلاية هوائية', 'مطبقيه', 'طقم شوك', 'فواحة', 'فواحه', 'اباجورة', 'اباجوره', 'مغرفة', 'مصفاة', 'kitchen', 'curtain', 'pillow', 'blanket', 'towel', 'lamp', 'bed', 'mattress', 'sofa', 'chair', 'table', 'fridge', 'blender', 'pan', 'pot', 'cup', 'mug', 'organizer', 'decor', 'mop', 'vacuum']),
]
# علامات اتجاه النص (RTL/LTR) اللي بتطلع جوه أسماء أقسام سيزما — بنشيلها قبل أي مقارنة
CEZMA_MARK_RE = re.compile('[\u200b-\u200f\u061c\u202a-\u202e\u2060-\u2069\ufeff]')
CEZMA_WORD_CHAR = '[\u0621-\u064A\u0660-\u0669A-Za-z0-9]'


def cezma_plain(text):
    """تنظيف اسم القسم: شيل علامات الاتجاه والمسافات المكررة."""
    return re.sub(r'\s+', ' ', CEZMA_MARK_RE.sub('', str(text or ''))).strip()


def cezma_ar_key(text):
    """مفتاح مطابقة عربي/إنجليزي موحّد (بدون تشكيل وبتوحيد الهمزات والياء)."""
    return re.sub(r'\s+', ' ', _ar_key(cezma_plain(text))).strip()


def _cezma_patterns():
    """تعبيرات مطابقة جاهزة: عربي = من بداية الكلمة (مع السماح بلواحق)، إنجليزي = الكلمة كاملة."""
    out = []
    for cat, kws in CEZMA_CAT_RULES:
        pats = []
        for k in kws:
            kk = cezma_ar_key(k)
            if not kk:
                continue
            tail = '(?![A-Za-z0-9])' if LATIN_START.match(kk) else ''
            pats.append(re.compile('(?<!' + CEZMA_WORD_CHAR + ')' + re.escape(kk) + tail))
        out.append((cat, pats))
    return out


CEZMA_PATTERNS = _cezma_patterns()

# منتجات منزلية واضحة ظهرت تحت «الموضة والجمال» بسبب قواعد اسم عامة مثل «مروحة»
# و«دفاية» أو «شامبو السجاد». نختبرها قبل الملابس/الجمال، لكن بدون كلمات عامة
# مثل home أو stainless التي كانت تسحب عناوين صحيحة لقسم المنزل.
CEZMA_HOME_NAME_TERMS = [
    'مكنسة', 'خلاط', 'عصارة', 'مفرمة', 'كبة', 'غلاية', 'قلاية', 'مقلاة', 'طاسة',
    'حلة', 'فرن', 'فرن كهربائي', 'فرن غاز', 'ميكروويف', 'بوتاجاز', 'بوتجاز', 'ثلاجة',
    'غسالة', 'غساله', 'تلاجة', 'تلاجه', 'سخان مياه', 'سخان كهربائي', 'سخان غاز',
    'سخان كهربا', 'سخان حنفية', 'سخان حنفيه', 'سخان فوري', 'شواية', 'كاتيل', 'عجان',
    'مضرب بيض', 'مضرب كابتشينو', 'مضرب حليب', 'ماكينة اسبريسو', 'ماكينة قهوة',
    'محضر القهوة', 'صانع القهوة', 'صانعة سندوتشات',
    'مطبخ', 'طقم مطبخ', 'أدوات المطبخ', 'مستلزمات المطبخ', 'أواني المطبخ', 'ترمس', 'سجادة',
    'ستارة', 'مفرش', 'ملاءة', 'لحاف', 'بطانية', 'وسادة', 'منشفة', 'ممسحة',
    'مصباح', 'أباجورة', 'اباجورة', 'لمبة', 'ديكور', 'صينية', 'سلة', 'مصفاة', 'مغرفة',
    'قطاعة', 'مبشرة', 'ملاعق', 'معالق', 'أكواب', 'اكواب', 'كوباية', 'حافظة طعام',
    'شنطة تخزين طعام', 'شنطة حفظ طعام', 'شنطة حرارية لحفظ الطعام', 'كيس تخزين',
    'منظم اكسسوارات', 'منظم اكسسوار', 'حامل اكسسوارات', 'علبة اكسسوارات',
    'منظم مكياج', 'منظم المكياج', 'منظم ميكب', 'منظم الميكب', 'منظم ميكاب',
    'منظم مكياچ', 'حامل سشوار', 'حامل مكياج', 'منظم ساعات', 'مبخرة', 'مبخره', 'شامبو السجاد', 'شامبو سجاد', 'شامبو المفروشات', 'منظف السجاد',
    'منظف المفروشات', 'منظف الحمام', 'منظف الارضيات', 'ملمع الخشب', 'ملمع الارضيات',
    'قاهر الدهون', 'جل الصحون', 'منظف الاطباق', 'معطر جو', 'معطر الارضيات',
    'فواحة', 'فواحه', 'منفاخ بالونات', 'منفاخ البلالين', 'منشار تورتة', 'منشار لحوم',
    'مج بورسلين', 'مج ديزني', 'مج كابل', 'مج كابلز',
]


_CEZMA_MATCH_PATTERN_CACHE = {}

def _cezma_matches_any(text, keywords):
    key = tuple(keywords)
    patterns = _CEZMA_MATCH_PATTERN_CACHE.get(key)
    if patterns is None:
        compiled = []
        for keyword in key:
            normalized = cezma_ar_key(keyword)
            if not normalized:
                continue
            if LATIN_START.match(normalized):
                compiled.append(re.compile('(?<![A-Za-z0-9])' + re.escape(normalized) + '(?![A-Za-z0-9])'))
            else:
                prefix = '(?:ب|ك|ف|ل|و|وال|بال|كال|فال|لل|ال)?'
                compiled.append(re.compile('(?<!' + CEZMA_WORD_CHAR + ')' + prefix + re.escape(normalized) + '(?!' + CEZMA_WORD_CHAR + ')'))
        patterns = tuple(compiled)
        _CEZMA_MATCH_PATTERN_CACHE[key] = patterns
    return any(pattern.search(text) for pattern in patterns)


CEZMA_HOME_CLOTHING_CONTEXT = [
    'كم', 'عباية', 'عبايه', 'عبايات', 'دريس', 'فستان', 'فساتين', 'نقاب', 'حجاب',
    'اسدال', 'ادناء', 'ليجن', 'بنطلون', 'بلوزة', 'بلوزه', 'بيجامة', 'بيجامه', 'بيجامات',
    'ترنج', 'ملابس', 'حريمي', 'حريمى', 'نسائي', 'نسائى', 'رجالي', 'رجالى',
    'بناتي', 'عرايس', 'قطيفة', 'قطيفه', 'بونشو', 'طرحة', 'طرحه',
]


def cezma_home_name_category(name):
    """إشارات قوية إلى منتج منزلي داخل قسم سيزما المختلط «الموضة والجمال»."""
    n = cezma_ar_key(name)
    if not n:
        return ''
    # منتجات عناية شخصية/تجميل يتكرر في عنوانها شكل أو كلمة منزلية.
    if _cezma_matches_any(n, [
        'صابونة', 'صابونه', 'صابون', 'قناع الوجه', 'قناع الوجة',
        'جهاز بخار الوجه', 'بخار الوجه', 'واكس', 'wax', 'اسكراب', 'سكراب',
    ]):
        return ''
    if (_cezma_matches_any(n, ['مبخرة', 'مبخره'])
            and _cezma_matches_any(n, ['شعر', 'الجسم', 'بخار'])):
        return ''
    if (_cezma_matches_any(n, ['براه', 'برا', 'حمالة صدر', 'حماله صدر'])
            and _cezma_matches_any(n, ['ستارة', 'ستاره'])):
        return ''
    if (_cezma_matches_any(n, ['غسالة', 'غساله'])
            and _cezma_matches_any(n, ['فرش', 'فرشاة'])
            and not _cezma_matches_any(n, ['ملابس', 'مواعين', 'اطباق', 'سجاد'])):
        return ''
    if (_cezma_matches_any(n, ['فواحة', 'فواحه'])
            and _cezma_matches_any(n, ['عطر', 'برفان', 'برفيوم', 'زيت', 'perfume', '212', 'رجالي'])):
        return ''
    if (_cezma_matches_any(n, ['كبة', 'كبه'])
            and _cezma_matches_any(n, ['لانجيري', 'لانجري', 'بدلة', 'بدله', 'حزام وسط'])):
        return ''
    if (_cezma_matches_any(n, ['صينية', 'صينيه'])
            and _cezma_matches_any(n, ['شعر'])):
        return ''
    clothing_context = _cezma_matches_any(n, CEZMA_HOME_CLOTHING_CONTEXT)
    if _cezma_matches_any(n, ['لحاف']) and clothing_context:
        return ''
    if (_cezma_matches_any(n, ['سخان'])
            and _cezma_matches_any(n, ['كهربا', 'كهربائي', 'مياه', 'لتر', 'واط', 'وات', 'حنفية', 'حنفيه', 'فوري'])
            and not _cezma_matches_any(n, ['ولاعة', 'ولاعه', 'سجائر', 'سجاير'])):
        return 'المنزل والمطبخ'
    if _cezma_matches_any(n, ['دفاية']):
        # في «الموضة والجمال» الدفاية غالبًا قطعة شتوية؛ لا نعدّها جهازًا إلا
        # بوجود قرينة كهربائية/حرارية واضحة، مثل دفاية كهربائية أو 2000 وات.
        if _cezma_matches_any(n, [
            'كهربا', 'كهربائي', 'كهربائية', 'كهربائيه', 'سخان', 'واط', 'وات',
            'درجة', 'حراري', 'حرارة', 'heater', 'electric', '180', '2000',
        ]):
            return 'المنزل والمطبخ'
        return ''
    if _cezma_matches_any(n, ['مروحة']):
        # «كم مروحة / نقاب مروحة» وصف ملابس، بينما مروحة الرقبة/المحمولة جهاز.
        if clothing_context:
            return ''
        return 'المنزل والمطبخ'
    if _cezma_matches_any(n, CEZMA_HOME_NAME_TERMS):
        return 'المنزل والمطبخ'
    return ''


def repair_cezma_home_category(name, category):
    """تصحيح محافظ للمنتجات غير المنزلية الواقعة في «المنزل والمطبخ» عند العرض/الاستيراد.

    نستخدم أسماء منتجات صريحة فقط، ونستثني منظفات المنزل ومنظمات الإكسسوارات
    والشنط المخصصة للطعام وأمثلة الكلمات الملتبسة التي ظهرت في الكتالوج.
    """
    category = str(category or '').strip()
    if category != 'المنزل والمطبخ':
        return category
    n = cezma_ar_key(name)
    if not n:
        return category
    if (_cezma_matches_any(n, ['ملحفة', 'ملحفه'])
            and not _cezma_matches_any(n, ['سرير', 'مرتبة', 'مرتبه', 'ملاية', 'ملايات', 'مفرش', 'فراش', 'لحاف', 'بطانية', 'بطانيه', 'وسادة', 'مخدة'])):
        return 'ملابس حريمي'
    if (_cezma_matches_any(n, ['لحاف'])
            and _cezma_matches_any(n, ['بيجامة', 'بيجامه', 'بيجامات', 'لبس'])):
        return 'ملابس حريمي'
    if _cezma_matches_any(n, ['شوكر', 'choker']):
        return 'إكسسوارات'
    if (_cezma_matches_any(n, ['مبخرة', 'مبخره'])
            and _cezma_matches_any(n, ['شعر', 'الجسم', 'بخار'])):
        return 'العناية والتجميل'
    if (_cezma_matches_any(n, ['غسالة', 'غساله'])
            and _cezma_matches_any(n, ['فرش', 'فرشاة'])
            and not _cezma_matches_any(n, ['ملابس', 'مواعين', 'اطباق', 'سجاد'])):
        return 'العناية والتجميل'
    if (_cezma_matches_any(n, ['قناع الوجه', 'قناع الوجة', 'جهاز بخار الوجه', 'بخار الوجه', 'اسكراب', 'سكراب'])
            or (_cezma_matches_any(n, ['صينية', 'صينيه']) and _cezma_matches_any(n, ['شعر']))):
        return 'العناية والتجميل'
    if (_cezma_matches_any(n, ['براه', 'برا', 'حمالة صدر', 'حماله صدر'])
            and _cezma_matches_any(n, ['ستارة', 'ستاره'])):
        return 'ملابس حريمي'
    if (_cezma_matches_any(n, ['ساعة', 'ساعه'])
            and _cezma_matches_any(n, ['وسادة', 'وساده'])
            and _cezma_matches_any(n, ['جيس', 'watch', 'حريمي', 'رجالي'])):
        return 'ساعات'
    if (_cezma_matches_any(n, ['كبة', 'كبه'])
            and _cezma_matches_any(n, ['لانجيري', 'لانجري', 'بدلة', 'بدله', 'حزام وسط'])):
        return 'ملابس حريمي'
    if (_cezma_matches_any(n, ['فواحة', 'فواحه'])
            and _cezma_matches_any(n, ['عطر', 'برفان', 'برفيوم', 'زيت', 'perfume', '212', 'رجالي'])):
        return 'العناية والتجميل'

    home_cleaning_context = [
        'سجاد', 'سجادة', 'مفروشات', 'موكيت', 'ارضيات', 'الارضيات', 'خشب', 'حمام',
        'اقمشة', 'ملابس', 'جلود', 'صحون', 'اطباق', 'مواعين',
    ]
    home_cleaning_words = [
        'شامبو', 'منظف', 'ملمع', 'مزيل', 'معطر', 'مبيض', 'مذيب الدهون',
        'قاهر الدهون', 'جل الصحون', 'بلسم',
    ]
    if (_cezma_matches_any(n, home_cleaning_context)
            and _cezma_matches_any(n, home_cleaning_words)):
        return category
    if _cezma_matches_any(n, [
        'منظم اكسسوارات', 'منظم اكسسوار', 'حامل اكسسوارات', 'علبة اكسسوارات',
        'منظم مكياج', 'منظم المكياج', 'منظم ميكب', 'منظم الميكب', 'منظم ميكاب',
        'منظم مكياچ', 'حامل سشوار', 'حامل مكياج', 'مبخرة', 'مبخره',
        'شنطة تخزين', 'شنطة تخزين طعام', 'شنطة حفظ طعام', 'شنطة حرارية',
        'حفظ الطعام', 'تخزين الطعام', 'شنطة سكاكين', 'شنطة معالق',
        'منظم ساعات', 'منفاخ بالونات', 'منفاخ البلالين', 'كرتونة منظفات العروسة',
        'مج بورسلين', 'مج ديزني', 'مج كابل', 'مج كابلز', 'مج حراري', 'كوب حراري',
        'ماج حراري', 'ترمس بشاشة', 'ترموس بشاشة', 'فواحة usb', 'فواحه usb',
        'معطر جو usb', 'دش التربو', 'سماعة دش', 'سماعه دش', 'سماعة دوش',
        'سماعه دوش', 'منشار تورتة', 'منشار لحوم', 'براد شاكوش', 'شاكوش بفتيك',
        'ولاعة كوتشينة', 'الولاعة الكوتشينة', 'منظف الاحذية', 'منظف الاحذيه',
        'ملمع الاحذية', 'ملمع الاحذيه', 'لاصق الاحذية', 'لاصق الاحذيه',
        'فرشة تنظيف الاحذية', 'فرشة تنظيف الاحذيه', 'مجفف الاحذية', 'مجفف الاحذيه',
        'مناديل ازالة اتساخات الاحذية', 'مناديل ازاله اتساخات الاحذيه',
        'ستاند ملابس واحذية',
    ]):
        return category

    if (_cezma_matches_any(n, ['جهاز الشمع', 'حلة الشمع', 'حله الشمع', 'حلة wax', 'حله wax', 'حلة pro wax', 'حله pro wax', 'pro wax100'])
            or (_cezma_matches_any(n, ['حلة', 'حله']) and _cezma_matches_any(n, ['واكس', 'wax', 'شمع']))):
        return 'العناية والتجميل'
    if _cezma_matches_any(n, ['قناع الوجه', 'قناع الوجة', 'جهاز بخار الوجه', 'بخار الوجه']):
        return 'العناية والتجميل'
    if (_cezma_matches_any(n, ['ولاعة', 'ولاعه'])
            and not _cezma_matches_any(n, [
                'بوتاجاز', 'بوتجاز', 'موقد', 'شواية', 'شوايه', 'شمعة', 'شمع',
                'مطبخ', 'طعام', 'تحمير', 'فود تورش', 'بخور', 'تسخين',
            ])):
        return 'إكسسوارات'
    if _cezma_matches_any(n, ['دفاية']) and _cezma_matches_any(n, CEZMA_HOME_CLOTHING_CONTEXT):
        if _cezma_matches_any(n, ['رجالي', 'رجالى', 'رجال']):
            return 'ملابس رجالي'
        return 'ملابس حريمي'
    if _cezma_matches_any(n, ['مروحة']) and _cezma_matches_any(n, CEZMA_HOME_CLOTHING_CONTEXT):
        return 'ملابس حريمي'

    if _cezma_matches_any(n, [
        'مروحة رقبة', 'مروحة الرقبة', 'مروحة محمولة', 'مروحة يد', 'مروحة لاسلكية',
        'مروحة ذكية', 'كاب مروحة', 'كاب مروحه', 'قبعة مروحة', 'قبعه مروحه',
        'تكييف رقبة', 'تكييف الرقبة', 'neck fan',
    ]):
        return 'إلكترونيات'
    if (_cezma_matches_any(n, ['مروحة'])
            and _cezma_matches_any(n, [
                'محمول', 'محمولة', 'ميني', 'mini', 'قابلة لاعادة الشحن',
                'شحن', 'بطارية', 'بطاريه', 'طاقة شمسية', 'طاقه شمسيه',
            ])
            and not _cezma_matches_any(n, CEZMA_HOME_CLOTHING_CONTEXT)):
        return 'إلكترونيات'

    # أجهزة المطبخ والمنزل المتكاملة تبقى منزلية حتى لو كان عنوانها يذكر شاشة/USB.
    # لا نطبّق هذا الاستثناء على كشافات/كاميرات/سماعات صريحة.
    home_appliances = [
        'ديب فريزر', 'فريزر', 'ثلاجة', 'ثلاجه', 'تلاجة', 'تلاجه', 'غسالة', 'غساله',
        'بوتاجاز', 'بوتجاز', 'شفاط', 'سخان مياه', 'سخان كهربائي', 'سخان غاز',
        'سخان كهربا', 'سخان حنفية', 'سخان فوري', 'سخان', 'دفاية', 'دفايه', 'قلاية', 'قلايه', 'مقلاة', 'مقلايه', 'غلاية', 'غلايه',
        'ميكروويف', 'فرن كهربائي', 'فرن غاز', 'فرن', 'عجان', 'مفرمة', 'مفرمه',
        'كبة', 'كبه', 'خلاط', 'عصارة', 'مكنسة', 'مكنسه', 'مروحة', 'مروحه',
        'تكييف', 'شواية', 'شوايه', 'كاتيل', 'مضرب بيض', 'مضرب كابتشينو',
        'مضرب حليب', 'مضرب نسكافيه', 'ماكينة اسبريسو', 'ماكينة قهوة',
        'محضر القهوة', 'صانع القهوة', 'جهاز طهي',
    ]
    hard_electronics = [
        'كشاف', 'كاميرا', 'جرس باب', 'جرس الباب', 'باور بانك', 'راوتر', 'رسيفر',
        'فلاشة', 'لابتوب', 'تابلت', 'طابعة', 'سماعة', 'سبيكر', 'ميكروفون',
        'مكبر صوت', 'بروجكتور', 'بروجكتر', 'تلفزيون', 'headphone', 'camera',
        'speaker', 'power bank', 'router', 'laptop', 'tablet', 'printer',
    ]
    if (_cezma_matches_any(n, home_appliances)
            and not _cezma_matches_any(n, hard_electronics)
            and not _cezma_matches_any(n, ['ولاعة', 'ولاعه', 'سجائر', 'سجاير'])):
        return category

    # ساعة حائط/يد/رملية، لا مدة تشغيل مثل «يحفظ الحرارة 12 ساعة».
    if _cezma_matches_any(n, [
        'ساعة حائط', 'ساعه حائط', 'ساعات حائط', 'ساعة يد', 'ساعه يد',
        'ساعات يد', 'ساعة رملية', 'ساعه رمليه', 'ساعة رقمية', 'ساعه رقميه',
        'ساعة ديجيتال', 'ساعه ديجيتال', 'ساعة ذكية', 'ساعه ذكيه', 'منبه',
        'watch', 'watches',
    ]):
        return 'ساعات'

    kids_item = _cezma_matches_any(n, [
        'منظم حفاضات', 'حفاضات اطفال', 'حفاضات أطفال', 'لعبة', 'لعبه', 'العاب',
        'بازل', 'مكعبات', 'دمية', 'دمى', 'رضاعة اطفال', 'رضاعه اطفال',
        'لعبة اطفال', 'لعبه اطفال', 'toy', 'toys',
    ])
    food_context = _cezma_matches_any(n, [
        'لحم', 'لحمة', 'لحم مفروم', 'جبن', 'بط', 'دجاج', 'فراخ', 'سمك',
        'طعام', 'مكعبات ثلج', 'ثلج',
    ])
    if kids_item and not food_context:
        return 'أطفال وألعاب'

    if _cezma_matches_any(n, [
        'خاتم', 'سلسلة مفاتيح', 'سلسله مفاتيح', 'سلسلة', 'سلسله', 'قلادة',
        'اسورة', 'اسوارة', 'سوار', 'حلق', 'نظارة', 'نظاره', 'نضارة', 'قبعة',
        'كاب', 'حزام', 'ميدالية', 'سبحة', 'سبحه', 'keychain', 'necklace',
        'bracelet', 'earring', 'ring',
    ]):
        return 'إكسسوارات'

    if _cezma_matches_any(n, [
        'مكنسة سيارة', 'شاحن سيارة', 'كاميرا سيارة', 'حامل موبايل سيارة',
        'غطاء سيارة', 'فرش سيارة', 'سجاد سيارة', 'دشكام', 'منفاخ كاوتش',
        'منفاخ اطارات', 'منفاخ سيارات', 'منفاخ سيارة', 'وايبر',
    ]):
        return 'مستلزمات السيارات'

    electronics = [
        'كشاف', 'جرس الباب', 'جرس باب', 'كاميرا', 'كاميرات', 'باور بانك',
        'راوتر', 'رسيفر', 'ريسيفر', 'فلاشة', 'فلاشه', 'لابتوب', 'تابلت',
        'طابعة', 'طابعه', 'سماعة', 'سماعه', 'سبيكر', 'مكبر صوت', 'ميكروفون',
        'ريموت تكييف', 'ريموت تلفزيون', 'ريموت رسيفر', 'بروجكتور', 'بروجكتر',
        'تلفزيون', 'شاشة', 'شاشه', 'ماوس', 'كيبورد', 'هارد', 'بلوتوث',
        'ميزان ذكي', 'قفل باب ذكي', 'كابل شحن', 'شاحن موبايل', 'شاحن ايفون',
        'usb', 'bluetooth', 'camera', 'charger', 'speaker', 'headphone',
        'power bank', 'router', 'laptop', 'tablet', 'printer',
    ]
    if _cezma_matches_any(n, electronics):
        return 'إلكترونيات'
    if (_cezma_matches_any(n, ['شاحن', 'كابل', 'كابلز'])
            and not _cezma_matches_any(n, ['اباجورة', 'اباجوره', 'لمبة', 'لمبه', 'مصباح', 'مج كابل', 'مج كابلز'])):
        return 'إلكترونيات'

    beauty = [
        'مكواة شعر', 'مكواه شعر', 'مكواة تجعيد', 'مكواه تجعيد', 'استشوار',
        'سشوار', 'مجفف شعر', 'شامبو', 'بلسم', 'سيروم', 'زيت شعر', 'عطر',
        'قناع الوجه', 'قناع الوجة', 'جهاز بخار الوجه', 'بخار الوجه', 'صابونة', 'صابونه', 'صابون', 'واكس', 'wax',
        'عطور', 'بارفان', 'برفان', 'برفيوم', 'مكياج', 'ميكاب', 'ميكب',
        'ماسكارا', 'غسول الوجه', 'غسول البشرة', 'غسول البشره', 'كريم للبشرة',
        'كريم للبشره', 'كريم مرطب', 'كريم شعر', 'لوشن', 'مزيل عرق',
        'ازالة الشعر', 'ازاله الشعر', 'فرشاة اسنان', 'فرشاه اسنان',
        'معجون اسنان', 'مبرد الاظافر', 'مبرد اظافر', 'حلاقة', 'حلاقه',
        'perfume', 'shampoo', 'mascara', 'skincare', 'hair dryer',
    ]
    if _cezma_matches_any(n, beauty):
        return 'العناية والتجميل'

    if _cezma_matches_any(n, [
        'شنيور', 'دريل', 'مثقاب', 'منشار', 'مفك', 'كماشة', 'كماشه',
        'زرجينة', 'زرجينه', 'بنسة', 'بنسه', 'طقم عدة', 'عدة يدوية',
        'شنطة عدة', 'مفتاح ربط', 'مفتاح انجليزي', 'مطرقة', 'hammer',
        'drill', 'screwdriver', 'wrench', 'saw',
    ]):
        return 'عدد وأدوات'
    if (_cezma_matches_any(n, ['شاكوش'])
            and not _cezma_matches_any(n, ['براد', 'بفتيك', 'لحوم', 'مطبخ'])):
        return 'عدد وأدوات'

    if _cezma_matches_any(n, [
        'ترامبولين', 'دمبل', 'تردميل', 'ترد ميل', 'حبل نط', 'كرة قدم',
        'مشاية رياضية', 'دراجة تمارين', 'مضرب تنس', 'سجادة يوغا',
        'معدات رياضية', 'تمارين رياضية', 'fitness', 'treadmill', 'dumbbell',
        'trampoline',
    ]):
        return 'الرياضة واللياقة'

    if _cezma_matches_any(n, [
        'حذاء رجالي', 'حذاء نسائي', 'حذاء رياضي', 'حذاء اطفال', 'حذاء أطفال',
        'كوتشي رجالي', 'كوتشي نسائي', 'شبشب', 'سليبر', 'صندل', 'بوط',
        'بوت جلد', 'بوت رجالي', 'بوت حريمي', 'بوت نسائي', 'بوت شتوي',
        'جزمة', 'جزمه', 'كروكس', 'sneaker', 'shoes', 'shoe',
    ]):
        return 'أحذية'

    if _cezma_matches_any(n, [
        'شنطة سفر', 'شنطه سفر', 'شنطة يد', 'شنطه يد', 'شنطة ظهر', 'شنطه ظهر',
        'حقيبة ظهر', 'حقيبة يد', 'حقيبة لابتوب', 'حقيبة سفر', 'محفظة',
        'محفظه', 'باك باك', 'backpack', 'handbag', 'wallet', 'tote',
    ]):
        return 'شنط ومحافظ'

    return category


def cezma_name_category(name):
    """تخمين قسم الموقع من اسم منتج سيزما (لقسم «الموضة والجمال» المختلط)."""
    n = cezma_ar_key(name)
    if not n:
        return ''
    if _cezma_matches_any(n, ['شوكر', 'choker']):
        return 'إكسسوارات'
    if _cezma_matches_any(n, ['قناع الوجه', 'قناع الوجة', 'جهاز بخار الوجه', 'بخار الوجه', 'اسكراب', 'سكراب']):
        return 'العناية والتجميل'
    if (_cezma_matches_any(n, ['مبخرة', 'مبخره'])
            and _cezma_matches_any(n, ['شعر', 'الجسم', 'بخار'])):
        return 'العناية والتجميل'
    if (_cezma_matches_any(n, ['غسالة', 'غساله'])
            and _cezma_matches_any(n, ['فرش', 'فرشاة'])
            and not _cezma_matches_any(n, ['ملابس', 'مواعين', 'اطباق', 'سجاد'])):
        return 'العناية والتجميل'
    if (_cezma_matches_any(n, ['صينية', 'صينيه'])
            and _cezma_matches_any(n, ['شعر'])):
        return 'العناية والتجميل'
    if (_cezma_matches_any(n, ['براه', 'برا', 'حمالة صدر', 'حماله صدر'])
            and _cezma_matches_any(n, ['ستارة', 'ستاره'])):
        return 'ملابس حريمي'
    if (_cezma_matches_any(n, ['ساعة', 'ساعه'])
            and _cezma_matches_any(n, ['وسادة', 'وساده'])
            and _cezma_matches_any(n, ['جيس', 'watch', 'حريمي', 'رجالي'])):
        return 'ساعات'
    if (_cezma_matches_any(n, ['فواحة', 'فواحه'])
            and _cezma_matches_any(n, ['عطر', 'برفان', 'برفيوم', 'زيت', 'perfume', '212', 'رجالي'])):
        return 'العناية والتجميل'
    if (_cezma_matches_any(n, ['كبة', 'كبه'])
            and _cezma_matches_any(n, ['لانجيري', 'لانجري', 'بدلة', 'بدله', 'حزام وسط'])):
        return 'ملابس حريمي'
    if _cezma_matches_any(n, ['حلة الشمع', 'حله الشمع', 'حلة wax', 'حله wax', 'حلة pro wax', 'حله pro wax', 'pro wax100', 'جهاز الشمع']):
        return 'العناية والتجميل'
    if _cezma_matches_any(n, ['دفاية']):
        if _cezma_matches_any(n, ['كهربا', 'كهربائي', 'كهربائية', 'كهربائيه', 'سخان', 'واط', 'وات', 'درجة', 'حراري', 'حرارة', 'heater', 'electric', '180', '2000']):
            return 'المنزل والمطبخ'
        if _cezma_matches_any(n, ['رجالي', 'رجالى', 'رجال']):
            return 'ملابس رجالي'
        return 'ملابس حريمي'
    home = cezma_home_name_category(name)
    if home:
        return repair_cezma_home_category(name, home)
    for cat, pats in CEZMA_PATTERNS:
        for p in pats:
            if p.search(n):
                return repair_cezma_home_category(name, cat)
    return ''


def cezma_category(main_name, name=''):
    """قسم سيزما الرئيسي (+ اسم المنتج) → قسم الموقع الموحّد. فاضي لو القسم مش قسم سيزما."""
    c = cezma_plain(main_name)
    if not c:
        return ''
    if c in CEZMA_CAT_MAP:
        mapped = CEZMA_CAT_MAP[c]
        mapped = mapped or (cezma_name_category(name) or CEZMA_FASHION_FALLBACK)
    else:
        mapped = normalize_category(c) or c
    return repair_cezma_home_category(name, mapped)


CEZMA_SITE_CATEGORIES = {
    'منتجات رقمية', 'سوشيال ميديا', 'أحذية', 'ملابس رجالي', 'ملابس حريمي', 'إكسسوارات',
    'المنزل والمطبخ', 'العناية والتجميل', 'إلكترونيات', 'ساعات', 'أطفال وألعاب',
    'عدد وأدوات', 'مستلزمات السيارات', 'الرياضة واللياقة', 'شنط ومحافظ',
}


def cezma_site_category(main_name, name=''):
    """Mirror wf_cezma_site_category: canonical rows stay canonical, mapped source rows map once."""
    c = cezma_plain(main_name)
    if not c:
        return ''
    if c in CEZMA_SITE_CATEGORIES:
        return c
    if c in CEZMA_CAT_MAP:
        mapped = CEZMA_CAT_MAP[c]
        return mapped if mapped else (cezma_name_category(name) or CEZMA_FASHION_FALLBACK)
    return normalize_category(c) or c

def repair_cezma_shards():
    """توحيد أقسام منتجات سيزما داخل الشظايا. لو أي شظية اتقرأت غلط (أو فاضية)
    بنوقف التصحيح كله — مفيش أي كتابة، عشان ما نضيّعش منتجات."""
    import glob as _g
    files = sorted(_g.glob(os.path.join(DATA, 'cezma_shards', 'lite_cezma_*.json')))
    if not files:
        return 0
    rows = []
    for f in files:
        try:
            part = json.load(open(f, encoding='utf-8'))
        except Exception as e:
            log('categories[cezma]: repair skipped — read failed', os.path.basename(f), str(e)[:100])
            return 0
        if not isinstance(part, list):
            log('categories[cezma]: repair skipped — bad shard', os.path.basename(f))
            return 0
        rows.extend(part)
    if not rows:
        return 0
    ch = 0
    for r in rows:
        if not isinstance(r, dict):
            continue
        old = str(r.get('category') or '')
        new = cezma_category(old, r.get('name') or '')
        if new and new != old:
            r['category'] = new
            ch += 1
    if ch:
        _cezma_write_shards(rows)
        log(f'categories[cezma]: fixed {ch} في {len(files)} شظية')
    return ch


# ---------------------------------------------------------------- Cezma (سيزما)
# مصدر جديد: منتجات نزلت واتعدلت 2025-2026 بعد فلترة أقسام معينة (انظر cezma_filter.py).
# كل 6 ساعات: ندور في الأقسام المسموحة بس على منتجات مش موجودة عندنا (من أول 2025)
# ونجيب بياناتها من API سيزما ونضيفها لـ lite_cezma.json (التفاصيل الكاملة بتتغني
# عند فتح المنتج على الموقع نفسه - cezma_fetch_detail في config.php).
CEZMA_API = 'https://api.cezma.cloud/api/products'
CEZMA_HDR = {'User-Agent': 'Mozilla/5.0', 'Platform': 'web', 'Accept': 'application/json'}
CEZMA_DELETE_MAIN = {614, 134, 421, 145, 140, 136, 674, 22, 413, 101, 16, 33, 137, 20, 28, 536, 141}
CEZMA_GIFTS_KEEP_SUBS = {365, 691, 442, 557, 460}   # من قسم الهدايا والمشغولات: الهدايا فقط
CEZMA_DECOR_KEEP_SUBS = {24, 360, 623}              # من قسم اثاث وديكور: الديكور فقط
CEZMA_MIN_ID = 321286                               # أول منتج اتعمل في 2025
CEZMA_MIN_PRICE_EGP = 100.0                         # استبعاد منتجات سيزما الأقل من 100 جنيه
CEZMA_MAX_NEW_PER_RUN = 5000

def _cezma_get(url, tries=4):
    import urllib.request as _u
    last = None
    for i in range(tries):
        try:
            req = _u.Request(url, headers=CEZMA_HDR)
            with _u.urlopen(req, timeout=90) as r:
                return json.loads(r.read().decode('utf-8', 'replace'))
        except Exception as e:
            last = e; time.sleep(2 + i * 2)
    raise last

def _cezma_scan_cat(cid, per=500):
    import concurrent.futures
    j = _cezma_get(f'{CEZMA_API}?page=1&per_page=1&category_id={cid}')
    total = int(j.get('item_count') or 0); pages = (total - 1) // per + 1 if total else 0
    def one(pg): return _cezma_get(f'{CEZMA_API}?page={pg}&per_page={per}&category_id={cid}').get('data') or []
    out = []
    if pages:
        with concurrent.futures.ThreadPoolExecutor(max_workers=16) as ex:
            for data in ex.map(one, range(1, pages + 1)): out.extend(data)
    return out

def _cezma_add_image_values(out, value):
    """Flatten image URL strings from Cezma gallery values and object shapes."""
    if isinstance(value, str):
        url = value.strip()
        if url.startswith(('http://', 'https://')) and url not in out:
            out.append(url)
    elif isinstance(value, (list, tuple)):
        for item in value:
            _cezma_add_image_values(out, item)
    elif isinstance(value, dict):
        for field in ('image', 'image_url', 'url', 'src', 'path', 'original', 'full', 'thumbnail',
                      'images', 'product_images', 'gallery', 'photos', 'media', 'data', 'items'):
            if field in value:
                _cezma_add_image_values(out, value[field])


def _cezma_product_images(product):
    """Return the primary URL followed by any gallery photos supplied by the API."""
    if not isinstance(product, dict):
        out = []
        _cezma_add_image_values(out, product)
        return out
    out = []
    for field in ('image', 'image_url', 'main_image'):
        if field in product:
            _cezma_add_image_values(out, product[field])
    for field in ('images', 'product_images', 'gallery', 'photos', 'media'):
        if field in product:
            _cezma_add_image_values(out, product[field])
    return out

def _cezma_load_shards():
    import glob as _g
    rows = []
    for f in sorted(_g.glob(os.path.join(DATA, 'cezma_shards', 'lite_cezma_*.json'))):
        try:
            with open(f, encoding='utf-8') as stream:
                rows.extend(json.load(stream))
        except Exception:
            pass
    return rows

def _cezma_write_shards(rows):
    import glob as _g
    d = os.path.join(DATA, 'cezma_shards'); os.makedirs(d, exist_ok=True)
    for f in _g.glob(os.path.join(d, 'lite_cezma_*.json')): os.remove(f)
    for i in range(0, len(rows), 6000):
        name = f'lite_cezma_{i // 6000 + 1:02d}.json'; pth = os.path.join(d, name)
        new = json.dumps(rows[i:i + 6000], ensure_ascii=False, separators=(',', ':'))
        old = open(pth, encoding='utf-8').read() if os.path.exists(pth) else None
        if old != new:
            with open(pth, 'w', encoding='utf-8') as f: f.write(new)
            changed_files.add(f'data/cezma_shards/{name}')

def remove_low_price_cezma():
    """Permanently remove Cezma products below the EGP floor from every shard."""
    import glob as _g

    shard_files = sorted(_g.glob(os.path.join(DATA, 'cezma_shards', 'lite_cezma_*.json')))
    rows = []
    for path in shard_files:
        with open(path, encoding='utf-8') as stream:
            part = json.load(stream)
        if not isinstance(part, list):
            raise ValueError(f'invalid Cezma shard (expected list): {path}')
        rows.extend(part)

    kept = []
    for row in rows:
        try:
            price = float(row.get('price') or 0) if isinstance(row, dict) else 0.0
        except (TypeError, ValueError):
            price = 0.0
        if price >= CEZMA_MIN_PRICE_EGP:
            kept.append(row)

    removed = len(rows) - len(kept)
    if removed:
        _cezma_write_shards(kept)
        log(f'cezma price floor: removed {removed} products below {CEZMA_MIN_PRICE_EGP:g} EGP; retained {len(kept)} at or above the floor')
    summary['cezma_price_filter'] = {
        'minimum_price_egp': CEZMA_MIN_PRICE_EGP,
        'removed': removed,
        'retained': len(kept),
    }
    return removed


def update_cezma():
    lite = _cezma_load_shards()
    existing_by_id = {}
    for row in lite:
        try:
            pid = int((row.get('extra') or {}).get('cezma_id') or row.get('orig_id') or 0)
        except (TypeError, ValueError):
            pid = 0
        if pid:
            existing_by_id[pid] = row
    cats = _cezma_get('https://api.cezma.cloud/api/categories').get('data') or []
    targets = []  # (category_id للفحص, اسم القسم الرئيسي)
    for c in cats:
        cid = int(c['id'])
        if cid in CEZMA_DELETE_MAIN: continue
        if cid == 364: targets += [(s['id'], c.get('name_ar') or '') for s in (c.get('children') or []) if int(s['id']) in CEZMA_GIFTS_KEEP_SUBS]
        elif cid == 8: targets += [(s['id'], c.get('name_ar') or '') for s in (c.get('children') or []) if int(s['id']) in CEZMA_DECOR_KEEP_SUBS]
        else: targets.append((cid, c.get('name_ar') or ''))
    log(f'cezma: checking {len(targets)} allowed categories (have {len(lite)} products)')
    candidates = {}
    image_updates = 0
    below_min_price_ids = set()
    for cid, main_name in targets:
        for d in _cezma_scan_cat(cid):
            pid = int(d.get('id') or 0)
            if pid < CEZMA_MIN_ID:
                continue
            try:
                current_price = float(d.get('price'))
            except (TypeError, ValueError):
                current_price = None
            if pid in existing_by_id:
                if current_price is not None and current_price < CEZMA_MIN_PRICE_EGP:
                    below_min_price_ids.add(pid)
                    continue
                incoming_images = _cezma_product_images(d)
                if incoming_images:
                    row = existing_by_id[pid]
                    current_images = _cezma_product_images({'images': row.get('images')})
                    merged_images = incoming_images + [u for u in current_images if u not in incoming_images]
                    if merged_images != current_images:
                        row['images'] = merged_images
                        image_updates += 1
                continue
            if current_price is None or not current_price >= CEZMA_MIN_PRICE_EGP:
                continue
            if pid not in candidates:
                candidates[pid] = (d, main_name)

    price_filtered = 0
    if below_min_price_ids:
        retained = []
        for row in lite:
            try:
                row_id = int((row.get('extra') or {}).get('cezma_id') or row.get('orig_id') or 0)
            except (AttributeError, TypeError, ValueError):
                row_id = 0
            if row_id in below_min_price_ids:
                price_filtered += 1
            else:
                retained.append(row)
        lite = retained
        log(f'cezma: removed {price_filtered} existing products now below {CEZMA_MIN_PRICE_EGP:g} EGP')

    log(f'cezma: {len(candidates)} new products found; {image_updates} existing galleries refreshed')
    added = 0
    for pid, (d, main_name) in sorted(candidates.items()):
        if added >= CEZMA_MAX_NEW_PER_RUN: log('cezma: per-run cap reached, rest next run'); break
        price = float(d.get('price') or 0); old = float(d.get('basic_price') or 0) or price
        st = d.get('store') or {}; imgs = _cezma_product_images(d)
        pname = d.get('name_ar') or d.get('name_en') or ''
        lite.append({
            'id': f'cezma-{pid}', 'orig_id': str(pid), 'name': pname,
            'price': price, 'old_price': old if old > price else 0, 'discount': float(d.get('discount') or 0),
            'price_text': f'{price:g} ج.م', 'currency': 'ج.م', 'country': 'مصر',
            # قسم الموقع الموحّد (مش قسم سيزما الرئيسي) — نفس اللي بتكتبه الواجهة
            'category': cezma_category(main_name, pname), 'stock': 999999, 'images': imgs,
            'extra': {'cezma_id': pid, 'store_name': st.get('name') or '', 'cost': f'{price:g} ج.م',
                      'source_commission_original': '0 ج.م'}})
        added += 1
    if added or image_updates or price_filtered: _cezma_write_shards(lite)
    summary['cezma'] = {
        'total': len(lite), 'added': added, 'image_updates': image_updates,
        'removed_below_min_price': price_filtered,
    }
    log('cezma', summary['cezma'])

def deploy_cezma_index_only():
    """Build and publish only the Cezma index, reading live shards without modifying them."""
    import tempfile
    import glob as _glob
    if __package__:
        from . import cezma_search_index
    else:
        import cezma_search_index
    host, user, password = (os.environ.get('FTP_HOST'), os.environ.get('FTP_USER'), os.environ.get('FTP_PASS'))
    if not (host and user and password):
        raise RuntimeError('Index-only deployment requires configured FTP secrets')
    remote_root = os.environ.get('FTP_ROOT', '/htdocs')
    ftp = ftplib.FTP(host, timeout=90)
    try:
        ftp.login(user, password)
        ftp.cwd(remote_root)
        listing = ftp.nlst('data/cezma_shards')
        shard_names = []
        for remote_name in listing:
            basename = os.path.basename(str(remote_name).rstrip('/'))
            if not basename.startswith('lite_cezma_') or not basename.endswith('.json'):
                continue
            if not re.fullmatch(r'lite_cezma_[A-Za-z0-9_-]+\.json', basename):
                raise RuntimeError('Unexpected Cezma shard filename on server: ' + basename)
            shard_names.append(basename)
        shard_names = sorted(set(shard_names))
        if not shard_names:
            raise RuntimeError('No live Cezma source shards found on FTP')

        with tempfile.TemporaryDirectory(prefix='cezma-live-index-') as temp_data:
            shard_dir = os.path.join(temp_data, 'cezma_shards')
            os.makedirs(shard_dir, exist_ok=True)
            for basename in shard_names:
                local_path = os.path.join(shard_dir, basename)
                with open(local_path, 'wb') as stream:
                    ftp.retrbinary('RETR data/cezma_shards/' + basename, stream.write)
            counts_buffer = io.BytesIO()
            ftp.retrbinary('RETR data/catalog_counts.json', counts_buffer.write)
            live_counts = json.loads(counts_buffer.getvalue().decode('utf-8'))
            rows, source_signature = cezma_search_index.read_source_shards(temp_data)
        ftp.quit()
        ftp = None

        if not isinstance(live_counts, dict) or type(live_counts.get('physical_with_images')) is not int:
            raise RuntimeError('Live catalog_counts.json is missing a valid physical_with_images count')
        preserved_total = live_counts['physical_with_images']
        index_info = cezma_search_index.build(
            DATA, cezma_site_category, rows=rows, source_signature=source_signature,
            changed_files=changed_files,
        )
        if index_info['products'] != len(rows):
            raise RuntimeError('Index product count differs from live source shard count')

        # Preserve every existing count field verbatim; only add the new freshness signature.
        live_counts['cezma_index_signature'] = index_info['signature']
        jsave('catalog_counts.json', live_counts)
        if live_counts['physical_with_images'] != preserved_total:
            raise RuntimeError('Index-only deployment unexpectedly changed the catalog count')

        index_files = {
            os.path.relpath(path, ROOT).replace(os.sep, '/')
            for path in _glob.glob(os.path.join(DATA, 'zz_cezma_index', '*.json'))
        }
        if len(index_files) != index_info['files']:
            raise RuntimeError('Generated index file set is incomplete')
        upload_files = {'config.php', 'index.php', '.htaccess', 'data/catalog_counts.json'} | index_files
        missing = [path for path in upload_files if not os.path.isfile(os.path.join(ROOT, path))]
        if missing:
            raise RuntimeError('Required deployment file is missing: ' + ', '.join(sorted(missing)))

        ok, failed = ftp_upload(upload_files, cleanup_source_shards=False)
        report = {
            'at': NOW, 'event': 'index-only', 'code_deploy': True, 'index_only': True,
            'files_to_upload': len(upload_files), 'uploaded_ok': ok,
            'failed_count': len(failed), 'failed': failed[:80], 'lines': LOG_LINES[-300:],
        }
        jsave('_deploy_log.json', report)
        if failed or ok != len(upload_files):
            raise RuntimeError(f'Index-only FTP upload incomplete: {ok}/{len(upload_files)} verified')

        state = jload('deploy_state.json', {})
        if not isinstance(state, dict):
            state = {}
        for rel in upload_files:
            path = os.path.join(ROOT, rel)
            with open(path, 'rb') as stream:
                digest = hashlib.md5(stream.read()).hexdigest()
            state[rel] = {'md5': digest, 'size': os.path.getsize(path), 'at': NOW}
        jsave('deploy_state.json', state)
        log('✅ index-only deployment complete; live products and catalog count were not modified', {
            'products': index_info['products'], 'chunks': index_info['chunks'],
            'categories': index_info['categories'], 'files': index_info['files'],
            'preserved_physical_count': preserved_total,
        })
        with open(os.environ.get('GITHUB_STEP_SUMMARY', '/dev/null'), 'a', encoding='utf-8') as stream:
            stream.write('### نشر فهرس سيزما فقط\n'
                         f"- سجلات المصدر: {index_info['products']} (لم تُعدّل)\n"
                         f"- الملفات المؤكدة: {ok}/{len(upload_files)}\n"
                         f"- إجمالي المنتجات الفيزيائية المحفوظ: {preserved_total}\n")
    finally:
        if ftp is not None:
            try:
                ftp.quit()
            except Exception:
                pass


# ---------------------------------------------------------------- main
if __name__ == '__main__':
    only = sys.argv[1:] or ['hevo', 'g2g', 'gamsgo', 'safqa', 'vendoor', 'm5zoon', 'angazny', 'timehand', 'shopmart', 'shopmart_shipping', 'sources', 'cezma']
    if 'index-only' in only:
        try:
            deploy_cezma_index_only()
        except Exception as _index_deploy_error:
            log('❌ safe index-only deployment failed:', str(_index_deploy_error)[:240])
            raise
        sys.exit(0)
    # وضع الاستكشاف اليدوي (بيئة التطوير مفيهاش إنترنت، وworkflow_dispatch مقفول بالتوكن ده):
    # لو ملف data/gamsgo_probe.request موجود على الفرع، تشغيل الـ probe بيتم في أي وضع —
    # والتقرير بينزل في data/gamsgo_probe.json على الفرع من commit البوت، وبنمسح الطلب بإيدنا بعد القراءة.
    if os.path.exists(os.path.join(DATA, 'gamsgo_probe.request')):
        try:
            gamsgo_probe()
        except Exception as _pe:
            log('❌ gamsgo_probe failed:', str(_pe)[:200])
            summary['gamsgo_probe'] = {'error': str(_pe)[:200]}
            jsave('gamsgo_probe.json', {'at': NOW, 'error': str(_pe)[:300]})
    for step, fn in (('hevo', update_hevo), ('g2g', update_g2g), ('gamsgo', update_gamsgo), ('gamsgo_probe', gamsgo_probe), ('safqa', update_safqa), ('vendoor', update_vendoor),
                     ('m5zoon', update_m5zoon), ('angazny', update_angazny), ('timehand', update_timehand), ('sources', update_json_sources), ('shopmart_probe', probe_shopmart), ('shopmart', update_shopmart), ('shopmart_shipping', update_shopmart_shipping), ('cezma', update_cezma)):
        if step not in only: continue
        try: fn()
        except Exception as e:
            log(f'❌ {step} failed: {e}'); summary[step] = {'error': str(e)[:200]}
    # Enforce the Cezma price floor on every run, including deploy-only runs,
    # so removed products cannot return through a partial/update-skipped run.
    remove_low_price_cezma()
    clean_catalog_names_and_images()
    normalize_physical_commissions()
    remove_blocked_products()
    repair_product_categories()
    rebuild_lite()
    force_open_production()
    _cezma_index_signature = ''
    _cezma_index_rows = None
    try:
        import cezma_search_index
        _cezma_index_rows, _cezma_source_signature = cezma_search_index.read_source_shards(DATA)
        _cezma_index = cezma_search_index.build(
            DATA, cezma_site_category, rows=_cezma_index_rows,
            source_signature=_cezma_source_signature, changed_files=changed_files,
        )
        _cezma_index_signature = _cezma_index['signature']
        summary['cezma_search_index'] = {
            'products': _cezma_index['products'],
            'chunks': _cezma_index['chunks'],
            'categories': _cezma_index['categories'],
            'files': _cezma_index['files'],
        }
        log('cezma search index', summary['cezma_search_index'])
    except Exception as _index_error:
        # Empty signature disables a stale index if source/build validation fails.
        log('❌ Cezma search index failed:', str(_index_error)[:200])
        summary['cezma_search_index'] = {'error': str(_index_error)[:200]}
    try:
        _catalog_counts = build_catalog_counts(_cezma_index_signature, _cezma_index_rows)
        jsave('catalog_counts.json', _catalog_counts)
        summary['catalog_counts'] = {
            'physical_with_images': _catalog_counts['physical_with_images'],
            'cezma_categories': len(_catalog_counts['cezma_categories']),
        }
        log('catalog counts', summary['catalog_counts'])
    except Exception as _count_error:
        log('❌ catalog count summary failed:', str(_count_error)[:200])
        summary['catalog_counts'] = {'error': str(_count_error)[:200]}
    finally:
        _cezma_index_rows = None
    # فهرس البحث بالصورة (لون + شكل لكل صورة منتج): تدريجي وبميزانية زمنية؛ فشله ما يوقفش الكتالوج
    try:
        import visual_features
        _vn, _vchanged = visual_features.build(DATA, os.path.join(DATA, 'visual_features.json'), log,
                                                budget=float(os.environ.get('VISUAL_FEATURES_BUDGET', '900')))
        if _vchanged:
            changed_files.add('data/visual_features.json')
    except Exception as _ve:
        log('❌ visual_features failed:', str(_ve)[:200])
        summary['visual_features'] = {'error': str(_ve)[:200]}
    # فهرس البحث بالصورة لسيزما (90 ألف صورة): بحد زمني في كل تشغيل، والباقي بيكمل في التشغيلات الجاية
    # الملفات مش في الريبو (كبيرة)، الـ workflow بيرجّعها من الكاش قبل التشغيل عشان نكمّل من آخر حالة
    try:
        import visual_cezma
        _visual_bin = os.path.join(DATA, 'visual_cezma.bin')
        _visual_idx = os.path.join(DATA, 'visual_cezma.idx')
        _cn, _cchanged = visual_cezma.build(os.path.join(DATA, 'cezma_shards'),
                                            _visual_bin, _visual_idx,
                                            budget=float(os.environ.get('VISUAL_CEZMA_BUDGET', '1500')), log=log)
        _transport_changes = []
        for _raw_path in (_visual_bin, _visual_idx):
            _parts, _updated_parts = visual_cezma.split_for_ftp(_raw_path)
            _transport_changes.extend(_updated_parts)
        for _part in _transport_changes:
            changed_files.add(os.path.relpath(_part, ROOT).replace(os.sep, '/'))
        summary['visual_cezma'] = {'indexed': _cn}
    except Exception as _ce:
        log('❌ visual_cezma failed:', str(_ce)[:200])
        summary['visual_cezma'] = {'error': str(_ce)[:200]}
    jsave('price_history.json', history)
    jsave('catalog_update.json', {'at': NOW, 'summary': summary, 'changed': sorted(changed_files)})
    # الأمان: التشغيل المجدول (cron) يحدّث البيانات والأسعار بس — من غير ملفات الكود.
    # لولا كده أي تشغيل مجدول من فرع تاني (main) بيرفع نسخة الكود القديمة بتاعته عالاستضافة
    # ويبلّع كل التعديلات المنشرة (شكل الموقع/الواجهة) — وده اللي حصل يوم 2026-10-05.
    # نشر الكود بيحصل بس مع push (أو dispatch بوسيط deploy صراحةً).
    _ev = (os.environ.get('GITHUB_EVENT_NAME', '') or '').strip().lower()
    code_deploy = ('deploy' in only) or _ev == 'push'
    upload_set = set(changed_files) | (site_deploy_files() if code_deploy else set())
    log('code deploy:', 'yes' if code_deploy else 'no (data only — scheduled run)')
    # الاستضافة المشتركة بتختنق وقت النشر (الكتالوج لوحده ~10 ميجا)، وده بيظهر للزوار كـ 502 Bad Gateway.
    # بنقارن MD5 المحلي بآخر رفع ناجح (data/deploy_state.json) وبنرفع اللي اتغيّر بس.
    # نشر إجباري لكل الملفات: سيط الـ only بـ clean أو force.
    SKIP_ON_HOST = {'data/deploy_state.json', 'data/gamsgo_probe.json', 'data/gamsgo_probe.request', 'data/_deploy_log.json'}
    # نشر كامل متحقَّق منه مع أي push فيه كود (code_deploy): حالة النشر ملف مشترك بيتكتب في كل فرع،
    # فماتقدرش تكشف إن تشغيل مجدول من فرع تاني (main) رفع نسخته القديمة من ملفات الواجهة فوق نسخة الفرع
    # (وده اللي ضرب شكل الموقع يومي 2026-10-04 و 2026-10-05). النشر الكامل مع الـpush يرجّع كل حاجة لمكانها.
    _force_upload = ('clean' in only) or ('force' in only) or code_deploy
    _prev_state = jload('deploy_state.json', {}) or {}
    # الملفات الثابتة (بما فيها أيقونة 512) تُرفع عند تغير الـMD5 فقط، لا مع كل نشر.
    # الأيقونة على الاستضافة ما زالت مختلفة رغم إعادة الرفع، فلا نسمح لها بتعطيل نشر البيانات الأخرى.
    def _heavy_static(rel):
        return rel.startswith('data/zz_cezma_index/') or rel == 'assets/icon-512.png' or rel.startswith('assets/detail/') or (
            rel.startswith('assets/') and (rel.endswith('.webp') or rel.endswith('.png'))
        )
    _state = dict(_prev_state)
    _to_upload, _skipped = [], 0
    for _rel in sorted(upload_set):
        if _rel in SKIP_ON_HOST:
            continue
        _lp = os.path.join(ROOT, _rel)
        if not os.path.isfile(_lp):
            continue
        _h = hashlib.md5(open(_lp, 'rb').read()).hexdigest()
        _force_this = _force_upload and not _heavy_static(_rel)
        if (not _force_this) and isinstance(_prev_state.get(_rel), dict) and _prev_state[_rel].get('md5') == _h:
            _skipped += 1
            continue
        _to_upload.append(_rel)
        _state[_rel] = {'md5': _h, 'size': os.path.getsize(_lp), 'at': NOW}
    log(f'files to upload: {len(_to_upload)} (تم تخطي {_skipped} ملف مطابق على السيرفر)')
    _failed = []
    if os.environ.get('FTP_HOST'):
        _ok_up, _failed = ftp_upload(_to_upload)
        # لوج النشر بيتكتب في الريبو — لوج الـActions بيتمسح، وساعتها مابنعرفش أنهي ملف فشل وليه.
        jsave('_deploy_log.json', {'at': NOW, 'event': _ev or 'local', 'code_deploy': code_deploy,
                                   'force': _force_upload, 'files_to_upload': len(_to_upload),
                                   'uploaded_ok': _ok_up, 'failed_count': len(_failed),
                                   'failed': _failed[:80], 'lines': LOG_LINES[-300:]})
        if _ok_up >= len(_to_upload):
            jsave('deploy_state.json', _state)
        else:
            log(f'⚠️ FTP: {_ok_up}/{len(_to_upload)} ملف اتأكد رفعهم — حالة النشر ماتحدّثتش وهنعيد رفع الكل في التشغيل الجاي')
    with open(os.environ.get('GITHUB_STEP_SUMMARY', '/dev/null'), 'a') as f:
        f.write('### تحديث الكتالوج ' + NOW + '\n```json\n' + json.dumps(summary, ensure_ascii=False, indent=1) + '\n```\n')
        if _failed:
            f.write(f'### ⚠️ فشل تأكيد رفع {len(_failed)} ملف من {len(_to_upload)}\n'
                    + '\n'.join('- `' + x + '`' for x in _failed[:40]) + '\n')
