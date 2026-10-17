#!/usr/bin/env python3
"""تشغيل إصلاح الأوفلاين على كتالوج جاهز (بدون شبكة) — للاختبار المحلي.

الاستخدام:
  python3 tests/local_heal.py [--repo /home/user/afflite] [--out /tmp/affh/out_data] [--sub-dir]

--sub-dir: يشغّل الإصلاح على مجموعة فرعية (اسم مجلد داخل data/ يتبع نفس الأسماء)
"""
import argparse, importlib.util, json, os, shutil, sys, tempfile

ap = argparse.ArgumentParser()
ap.add_argument('--repo', default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ap.add_argument('--out', default='/tmp/affh/out_data')
a = ap.parse_args()

spec = importlib.util.spec_from_file_location('uc', os.path.join(a.repo, 'updater', 'update_catalog.py'))
uc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(uc)

work = tempfile.mkdtemp(prefix='healdata_')
for f in ('g2g.json', 'lite_g2g.json'):
    src = os.path.join(a.repo, 'data', f)
    if os.path.exists(src):
        shutil.copy2(src, os.path.join(work, f))
uc.DATA = work

g2g = json.load(open(os.path.join(work, 'g2g.json'), encoding='utf-8'), object_pairs_hook=dict)
tot = [0, 0, 0]
for it in g2g:
    if not (it.get('extra') or {}).get('variants_detailed'):
        continue
    fp, reps, ch = uc.heal_g2g_item(it)
    tot[0] += fp
    tot[1] += reps
    tot[2] += 1 if ch else 0
print('pass1: أسعار عروض =', tot[0], '| نصوص =', tot[1], '| سعر منتج اتغيّر =', tot[2])

tot2 = [0, 0, 0]
for it in g2g:
    if not (it.get('extra') or {}).get('variants_detailed'):
        continue
    fp, reps, ch = uc.heal_g2g_item(it)
    tot2[0] += fp
    tot2[1] += reps
    tot2[2] += 1 if ch else 0
print('pass2 (المفروض أصفار):', tot2)

bad = [it['id'] for it in g2g for v in (it.get('extra') or {}).get('variants_detailed') or []
       if float(v.get('price') or 0) >= 10 and abs(float(v['price']) % 5) > 0.001]
small = [it['id'] for it in g2g for v in (it.get('extra') or {}).get('variants_detailed') or []
         if 0 < float(v.get('price') or 0) < 10 and abs(float(v['price']) * 2 - round(float(v['price']) * 2)) > 0.001]
print('أسعار >=10 مش منتهية بـ 0/5:', len(bad), '| أسعار 1..10 مش بنصف جنيه:', len(small))
print('عدد المنتجات:', len(g2g), '| فيها unit_price_usd:', sum(1 for it in g2g for v in (it.get('extra') or {}).get('variants_detailed') or [] if float(v.get('unit_price_usd') or 0) > 0))

json.dump(g2g, open(os.path.join(work, 'g2g.json'), 'w', encoding='utf-8'), ensure_ascii=False)
rs = uc.rebuild_lite()
print('rebuild_lite:', rs.get('g2g') if isinstance(rs, dict) else rs)

os.makedirs(a.out, exist_ok=True)
for f in ('g2g.json', 'lite_g2g.json'):
    if os.path.exists(os.path.join(work, f)):
        shutil.copy2(os.path.join(work, f), os.path.join(a.out, f))
        print('out:', os.path.join(a.out, f), os.path.getsize(os.path.join(a.out, f)))
