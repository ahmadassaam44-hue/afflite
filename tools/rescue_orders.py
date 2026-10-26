#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
إنقاذ الطلبات من الاستضافة (FTP) وتحويلها لملف CSV/XLSX على جهازك.

ليه السكربت ده؟
  ملف الطلبات `data/orders.json` مش موجود في الريبو (مستثنى في .gitignore لأنه بيانات عملاء)،
  فلو النطاق وقع أو الاستضافة اتقفلت، الملف ده بيضيع معاها. السكربت بينزّله من السيرفر
  على جهازك إنت — من غير ما يترفع على GitHub ولا أي حاجة تانية.

⚠️  الريبو ده **Public** (مش خاص) — ماترفعش orders.json ولا أي ملف فيه بيانات عملاء
    عليه إطلاقًا: لا في Git، ولا كـ Artifact في Actions (أي حد يقدر ينزّله).

الاستخدام:
  # 1) نزّل الطلبات من الاستضافة وحولّها CSV
  FTP_HOST=ftpupload.net FTP_USER=if0_xxxxx FTP_PASS=xxxxx python3 tools/rescue_orders.py

  # 2) شوف إيه الموجود على السيرفر الأول (من غير تنزيل)
  FTP_HOST=ftpupload.net FTP_USER=if0_xxxxx FTP_PASS=xxxxx python3 tools/rescue_orders.py --list

  # 3) نزّل ملفات تانية كمان (الحسابات، طلبات العملاء، المحفظة...)
  FTP_HOST=... python3 tools/rescue_orders.py --files data/orders.json data/users.json data/customer_requests.json

  # 4) عندك orders.json بالفعل على جهازك؟ حوّله بس
  python3 tools/rescue_orders.py --local path/to/orders.json

متغيرات البيئة:
  FTP_HOST   سيرفر الـ FTP   (InfinityFree: ftpupload.net)
  FTP_USER   مستخدم الـ FTP  (InfinityFree: if0_4298...)
  FTP_PASS   كلمة سر الـ FTP
  FTP_ROOT   المسار الجذر على السيرفر (الافتراضي /htdocs)

المخرجات: مجلد ./rescue-YYYYmmdd-HHMMSS/ فيه الملفات المنزّلة + orders.csv (+ orders.xlsx لو openpyxl متاح)
"""

import argparse
import csv
import datetime
import ftplib
import json
import os
import sys

DEFAULT_FILES = ['data/orders.json']

CSV_HEADERS = [
    'رقم الطلب', 'التاريخ', 'المنتج', 'اللون', 'المقاس', 'الكمية',
    'سعر القطعة', 'الشحن', 'الخصم', 'الإجمالي', 'العملة',
    'اسم العميل', 'الهاتف', 'المحافظة', 'العنوان',
    'المسوق', 'عمولة المسوق', 'الحالة', 'ملاحظات',
]


def log(*a):
    print(*a, flush=True)


# ------------------------------------------------------------------ FTP
def ftp_connect():
    host = os.environ.get('FTP_HOST', '').strip()
    user = os.environ.get('FTP_USER', '').strip()
    pw = os.environ.get('FTP_PASS', '')
    root = os.environ.get('FTP_ROOT', '/htdocs').strip() or '/htdocs'
    missing = [n for n, v in (('FTP_HOST', host), ('FTP_USER', user), ('FTP_PASS', pw)) if not v]
    if missing:
        log('❌ ناقص متغيرات بيئة: ' + ', '.join(missing))
        log('   مثال: FTP_HOST=ftpupload.net FTP_USER=if0_xxx FTP_PASS=xxx python3 tools/rescue_orders.py')
        sys.exit(2)
    try:
        ftp = ftplib.FTP(host, timeout=60)
        ftp.login(user, pw)
    except Exception as e:
        log('❌ فشل الاتصال بالـ FTP: %s' % e)
        log('   لو الحساب اتلغى/اتحذف من الاستضافة، الملفات بتبقى راحت — جرّب تدخل على')
        log('   لوحة تحكم الاستضافة الأول وتأكد إن الحساب لسه موجود.')
        sys.exit(3)
    try:
        ftp.cwd(root)
    except Exception as e:
        log('⚠️  ماقدرتش أفتح المجلد %s (%s) — هكمّل من الجذر' % (root, e))
    return ftp


def ftp_list(ftp, path='data'):
    out = []
    try:
        ftp.retrlines('LIST ' + path, out.append)
    except Exception as e:
        log('⚠️  ماقدرتش أقرا المجلد %s: %s' % (path, e))
        return
    log('\n📂 محتويات %s على السيرفر:' % path)
    for line in out:
        log('   ' + line)
    log('')


def ftp_download(ftp, remote, dest_dir):
    name = os.path.basename(remote)
    dest = os.path.join(dest_dir, name)
    buf = bytearray()
    try:
        ftp.retrbinary('RETR ' + remote, buf.extend)
    except Exception as e:
        log('❌ فشل تنزيل %s: %s' % (remote, e))
        return None
    if not buf:
        log('⚠️  %s فاضي أو مش موجود' % remote)
        return None
    with open(dest, 'wb') as fh:
        fh.write(bytes(buf))
    log('✅ %s — %s (%.1f KB)' % (remote, dest, len(buf) / 1024.0))
    return dest


# ------------------------------------------------------------------ تحويل
def rows_from_orders(data):
    if isinstance(data, dict):
        for k in ('orders', 'data', 'items'):
            if isinstance(data.get(k), list):
                data = data[k]
                break
    if not isinstance(data, list):
        return []
    rows = []
    for o in data:
        if not isinstance(o, dict):
            continue
        notes = []
        if o.get('cancel_reason'):
            notes.append('سبب الإلغاء: %s' % o['cancel_reason'])
        if o.get('return_reason'):
            notes.append('سبب الاسترجاع: %s' % o['return_reason'])
        if o.get('return_requested'):
            notes.append('استرجاع قيد المراجعة')
        if o.get('tracking'):
            notes.append('تتبع الشحن: %s' % o['tracking'])
        rows.append({
            'رقم الطلب': o.get('order_no', ''),
            'التاريخ': (o.get('at') or '')[:16],
            'المنتج': o.get('pname') or o.get('id') or '',
            'اللون': o.get('color', ''),
            'المقاس': o.get('size', ''),
            'الكمية': o.get('qty', 1),
            'سعر القطعة': o.get('unit', 0),
            'الشحن': o.get('ship', 0),
            'الخصم': o.get('discount', 0),
            'الإجمالي': o.get('total', 0),
            'العملة': o.get('cur') or o.get('currency') or 'ج.م',
            'اسم العميل': o.get('cname', ''),
            'الهاتف': o.get('cphone', ''),
            'المحافظة': o.get('city', ''),
            'العنوان': o.get('address', ''),
            'المسوق': o.get('ref', ''),
            'عمولة المسوق': o.get('m_earn', 0),
            'الحالة': o.get('status', ''),
            'ملاحظات': ' | '.join(notes),
        })
    return rows


def write_csv(rows, path):
    with open(path, 'w', newline='', encoding='utf-8-sig') as fh:  # utf-8-sig = Excel يقرا العربي
        w = csv.DictWriter(fh, fieldnames=CSV_HEADERS, extrasaction='ignore')
        w.writeheader()
        w.writerows(rows)
    return path


def write_xlsx(rows, path):
    try:
        from openpyxl import Workbook
    except ImportError:
        return None
    wb = Workbook()
    ws = wb.active
    ws.title = 'الطلبات'
    ws.append(CSV_HEADERS)
    for r in rows:
        ws.append([r.get(h, '') for h in CSV_HEADERS])
    for col in ws.columns:
        width = max((len(str(c.value)) for c in col if c.value is not None), default=10)
        ws.column_dimensions[col[0].column_letter].width = min(40, max(12, width + 2))
    wb.save(path)
    return path


def summarize(rows):
    if not rows:
        log('⚠️  مفيش طلبات في الملف')
        return
    total = sum(float(r['الإجمالي'] or 0) for r in rows)
    by_status = {}
    for r in rows:
        by_status[str(r['الحالة'] or 'غير محدد')] = by_status.get(str(r['الحالة'] or 'غير محدد'), 0) + 1
    log('\n📊 الملخص:')
    log('   عدد الطلبات: %d' % len(rows))
    log('   إجمالي القيمة: %.2f' % total)
    log('   توزيع الحالات: %s' % '، '.join('%s=%d' % (k, v) for k, v in sorted(by_status.items())))


# ------------------------------------------------------------------ main
def main():
    ap = argparse.ArgumentParser(description='إنقاذ الطلبات من الاستضافة عبر FTP وتحويلها CSV/XLSX')
    ap.add_argument('--files', nargs='*', default=DEFAULT_FILES,
                    help='ملفات نسبية من جذر الموقع (الافتراضي: data/orders.json)')
    ap.add_argument('--list', action='store_true', help='اعرض محتويات مجلد data على السيرفر بدون تنزيل')
    ap.add_argument('--local', metavar='PATH', help='حوّل ملف orders.json موجود على جهازك (بدون FTP)')
    ap.add_argument('--out', metavar='DIR', help='مجلد المخرجات (الافتراضي: ./rescue-<تاريخ>)')
    ap.add_argument('--no-xlsx', action='store_true', help='CSV فقط بدون ملف Excel')
    args = ap.parse_args()

    out_dir = args.out or ('rescue-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S'))
    os.makedirs(out_dir, exist_ok=True)
    log('📁 المخرجات: %s' % out_dir)

    orders_path = None
    if args.local:
        orders_path = args.local
        log('📄 ملف محلي: %s' % orders_path)
    else:
        ftp = ftp_connect()
        log('✅ اتصلت بالسيرفر')
        try:
            if args.list:
                ftp_list(ftp, 'data')
                if not args.files:
                    return
            for remote in args.files:
                p = ftp_download(ftp, remote, out_dir)
                if p and remote.endswith('orders.json'):
                    orders_path = p
        finally:
            try:
                ftp.quit()
            except Exception:
                pass

    if not orders_path or not os.path.exists(orders_path):
        log('\nمافيش ملف طلبات أحوّله.')
        return

    with open(orders_path, 'r', encoding='utf-8') as fh:
        try:
            data = json.load(fh)
        except Exception as e:
            log('❌ الملف مش JSON صالح: %s' % e)
            return

    rows = rows_from_orders(data)
    csv_path = write_csv(rows, os.path.join(out_dir, 'orders.csv'))
    log('📄 CSV: %s' % csv_path)
    if not args.no_xlsx:
        xlsx_path = write_xlsx(rows, os.path.join(out_dir, 'orders.xlsx'))
        log('📄 Excel: %s' % (xlsx_path or '(مفيش openpyxl — نزّله بـ: pip install openpyxl)'))
    summarize(rows)
    log('\n🔒 الملفات دي على جهازك إنت بس — ماترفعهاش على الريبو (Public) ولا على أي مكان عام.')


if __name__ == '__main__':
    main()
