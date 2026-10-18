#!/usr/bin/env python3
"""Audit each locally saved Cezma product's actual public detail page.

The unavailable message is rendered on Cezma's product page (at the price area),
so scanning catalog names or cached price fields alone is not sufficient.
"""
import concurrent.futures
import datetime
import hashlib
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

from updater import update_catalog

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'data')
PHRASE = 'هذا المنتج غير متوفر في الوقت الحالي'
SITEMAP = 'https://cezma.com/sitemap.xml'
PAGE_RE = re.compile(r'https://cezma\.com/products/[^/]+/(\d+)(?:/|$)', re.I)
USER_AGENT = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
              'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36')


def fetch_bytes(url, timeout=25, limit=2_000_000):
    req = urllib.request.Request(url, headers={
        'User-Agent': USER_AGENT,
        'Accept': 'text/html,application/xml,application/xhtml+xml,*/*;q=0.8',
        'Accept-Language': 'ar,en;q=0.8',
    })
    last_error = None
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=timeout) as response:
                body = response.read(limit + 1)
                if len(body) > limit:
                    raise RuntimeError(f'response exceeded {limit} byte audit limit: {url}')
                return response.status, body
        except urllib.error.HTTPError as error:
            last_error = error
            if error.code not in (408, 425, 429, 500, 502, 503, 504):
                return error.code, b''
        except Exception as error:  # network timeout or transient TLS failure
            last_error = error
        time.sleep(0.5 * (attempt + 1))
    raise RuntimeError(str(last_error)[:240])


def get_saved_rows():
    rows = []
    for path in sorted(__import__('glob').glob(os.path.join(DATA, 'cezma_shards', 'lite_cezma_*.json'))):
        with open(path, encoding='utf-8') as stream:
            chunk = json.load(stream)
        if not isinstance(chunk, list):
            raise ValueError('Invalid Cezma shard: ' + path)
        rows.extend(row for row in chunk if isinstance(row, dict))
    return rows


def sitemap_product_urls(saved_ids):
    status, raw = fetch_bytes(SITEMAP, timeout=40, limit=12_000_000)
    if status != 200:
        raise RuntimeError('Cezma sitemap returned HTTP ' + str(status))
    root = ET.fromstring(raw)
    chunks = []
    for element in root.iter():
        loc = (element.text or '').strip()
        if loc.startswith('https://cezma.com/sitemap-products/') and loc.endswith('.xml'):
            chunks.append(loc)
    # Some versions expose the product sitemap as a direct URL rather than an index.
    if not chunks and SITEMAP.replace('.xml', '-products.xml') in raw.decode('utf-8', 'ignore'):
        chunks = ['https://cezma.com/sitemap-products.xml']
    urls = {}
    errors = []

    def read_chunk(url):
        code, body = fetch_bytes(url, timeout=40, limit=20_000_000)
        if code != 200:
            return url, [], 'HTTP ' + str(code)
        try:
            xml_root = ET.fromstring(body)
            found = []
            for node in xml_root.iter():
                loc = (node.text or '').strip()
                match = PAGE_RE.search(loc)
                if match:
                    found.append((match.group(1), loc))
            return url, found, ''
        except Exception as error:
            return url, [], 'invalid XML: ' + str(error)[:160]

    with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:
        for url, found, error in pool.map(read_chunk, chunks):
            if error:
                errors.append({'url': url, 'error': error})
            for product_id, page_url in found:
                if product_id in saved_ids and product_id not in urls:
                    urls[product_id] = page_url
    return urls, errors, len(chunks)


def audit_one(item):
    product_id, url = item
    try:
        status, body = fetch_bytes(url)
        if status != 200:
            return product_id, url, False, 'HTTP ' + str(status)
        if update_catalog._cezma_contains_unavailable_notice(body.decode('utf-8', 'replace')):
            return product_id, url, True, ''
        return product_id, url, False, ''
    except Exception as error:
        return product_id, url, False, str(error)[:240]


def save_unavailable_ids(product_ids):
    path = os.path.join(DATA, 'cezma_unavailable.json')
    try:
        with open(path, encoding='utf-8') as stream:
            saved = json.load(stream)
    except (OSError, ValueError):
        saved = {}
    if not isinstance(saved, dict):
        saved = {}
    changed = False
    timestamp = datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds')
    for product_id in sorted(set(product_ids)):
        if product_id not in saved:
            saved[product_id] = {'reason': 'unavailable message on Cezma detail page', 'detected_at': timestamp}
            changed = True
    if changed:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, 'w', encoding='utf-8') as stream:
            json.dump(saved, stream, ensure_ascii=False, separators=(',', ':'))
    return changed


def remove_rows_from_shards(product_ids):
    paths = sorted(__import__('glob').glob(os.path.join(DATA, 'cezma_shards', 'lite_cezma_*.json')))
    changed_paths = []
    for path in paths:
        with open(path, encoding='utf-8') as stream:
            rows = json.load(stream)
        kept = []
        for row in rows:
            if not isinstance(row, dict):
                kept.append(row)
                continue
            product_id = str((row.get('extra') or {}).get('cezma_id') or row.get('orig_id') or row.get('id') or '')
            product_id = re.sub(r'^cezma-', '', product_id, flags=re.I)
            if product_id not in product_ids:
                kept.append(row)
        if len(kept) != len(rows):
            with open(path, 'w', encoding='utf-8') as stream:
                json.dump(kept, stream, ensure_ascii=False, separators=(',', ':'))
            changed_paths.append(path)
    return changed_paths


def upload_files(relative_paths):
    import ftplib
    host, username, password = (os.environ.get('FTP_HOST'), os.environ.get('FTP_USER'), os.environ.get('FTP_PASS'))
    if not (host and username and password):
        raise RuntimeError('FTP credentials are required to publish the Cezma audit removals')
    remote_root = os.environ.get('FTP_ROOT', '/htdocs')
    ftp = ftplib.FTP(host, timeout=90)
    uploaded = []
    try:
        ftp.login(username, password)
        ftp.cwd(remote_root)
        for relative_path in relative_paths:
            rel = relative_path.replace(os.sep, '/')
            with open(os.path.join(ROOT, relative_path), 'rb') as stream:
                payload = stream.read()
            ftp.storbinary('STOR ' + rel, __import__('io').BytesIO(payload))
            downloaded = bytearray()
            ftp.retrbinary('RETR ' + rel, downloaded.extend)
            if bytes(downloaded) != payload:
                raise RuntimeError('FTP verification mismatch for ' + rel)
            uploaded.append(rel)
    finally:
        try:
            ftp.quit()
        except Exception:
            pass
    return uploaded


def write_summary(report, names):
    summary_path = os.environ.get('GITHUB_STEP_SUMMARY')
    if not summary_path:
        return
    lines = [
        '# Cezma product-page audit',
        '',
        f"- Saved products: {report['saved_products']}",
        f"- Product pages checked: {report['pages_checked']}",
        f"- Unavailable pages found: {len(report['matches'])}",
        f"- Page/sitemap errors: {report['error_count']}",
        f"- Scan complete: {report['scan_complete']}",
        '',
        '| Cezma ID | Product | Page |',
        '|---:|---|---|',
    ]
    for match in report['matches']:
        lines.append(f"| {match['id']} | {names.get(match['id'], '')} | {match['url']} |")
    if report['errors']:
        lines.extend(['', '## Pages or sitemap files that could not be checked', ''])
        for failure in report['errors'][:100]:
            lines.append(f"- ID: {failure.get('id', 'sitemap')}; URL: {failure.get('url', 'unknown')}; error: {failure.get('error', 'unknown')}")
        if len(report['errors']) > 100:
            lines.append(f"- Showing first 100 of {len(report['errors'])} errors here; the attached JSON artifact contains the complete list.")
    with open(summary_path, 'a', encoding='utf-8') as stream:
        stream.write('\n'.join(lines) + '\n')


def main():
    rows = get_saved_rows()
    by_id = {}
    for row in rows:
        product_id = str((row.get('extra') or {}).get('cezma_id') or row.get('orig_id') or row.get('id') or '')
        product_id = re.sub(r'^cezma-', '', product_id, flags=re.I)
        if product_id.isdigit():
            by_id.setdefault(product_id, row)
    saved_ids = set(by_id)
    print(f'Loading Cezma sitemap URLs for {len(saved_ids)} saved product IDs...', flush=True)
    urls, sitemap_errors, sitemap_chunks = sitemap_product_urls(saved_ids)
    missing = saved_ids - set(urls)
    for product_id in missing:
        # Cezma serves the public detail route without requiring the title slug.
        urls[product_id] = f'https://cezma.com/products/store/{product_id}'
    print(f'Found {len(saved_ids) - len(missing)} canonical URLs; fallback URLs: {len(missing)}; sitemap chunks: {sitemap_chunks}', flush=True)

    matches = []
    failures = list(sitemap_errors)
    checked = 0
    items = sorted(urls.items(), key=lambda pair: int(pair[0]))
    workers = max(1, min(32, int(os.environ.get('CEZMA_PAGE_AUDIT_WORKERS', '24'))))
    batch_size = max(64, workers * 16)
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
        for start in range(0, len(items), batch_size):
            batch = items[start:start + batch_size]
            for product_id, url, is_unavailable, error in pool.map(audit_one, batch):
                checked += 1
                if is_unavailable:
                    matches.append({'id': product_id, 'url': url})
                if error:
                    failures.append({'id': product_id, 'url': url, 'error': error})
            if checked % 1000 == 0 or checked == len(items):
                print(f'Checked {checked}/{len(items)} product pages; unavailable={len(matches)} errors={len(failures)}', flush=True)

    # These two pages have already been directly verified; retain them in the permanent blocklist.
    unavailable_ids = {match['id'] for match in matches} | {'337112', '337114'}
    id_file_changed = save_unavailable_ids(unavailable_ids)
    changed_shards = remove_rows_from_shards(unavailable_ids)
    upload_relative = ['data/cezma_unavailable.json'] if id_file_changed else []
    upload_relative.extend(os.path.relpath(path, ROOT) for path in changed_shards)
    uploaded = []
    upload_error = ''
    if upload_relative:
        try:
            uploaded = upload_files(upload_relative)
        except Exception as error:
            upload_error = str(error)[:500]
            print('FTP publish failed: ' + upload_error, flush=True)

    report = {
        'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'),
        'saved_products': len(saved_ids),
        'pages_checked': checked,
        'sitemap_chunks': sitemap_chunks,
        'canonical_url_count': len(saved_ids) - len(missing),
        'fallback_url_count': len(missing),
        'matches': matches,
        'error_count': len(failures),
        'errors': failures,
        'scan_complete': checked == len(saved_ids) and not failures,
        'removed_from_shards': sum(
            1 for row in rows
            if re.sub(r'^cezma-', '', str((row.get('extra') or {}).get('cezma_id') or row.get('orig_id') or row.get('id') or ''), flags=re.I) in unavailable_ids
        ),
        'changed_shards': [os.path.relpath(path, ROOT) for path in changed_shards],
        'uploaded_files': uploaded,
        'publish_error': upload_error,
    }
    names = {product_id: row.get('name', '') for product_id, row in by_id.items()}
    report_path = os.path.join(ROOT, 'cezma_page_audit_report.json')
    with open(report_path, 'w', encoding='utf-8') as stream:
        json.dump(report, stream, ensure_ascii=False, indent=2)
    write_summary(report, names)
    print(json.dumps({key: value for key, value in report.items() if key != 'errors'}, ensure_ascii=False), flush=True)
    if failures:
        print('Audit has page/sitemap failures; inspect cezma_page_audit_report.json before claiming full coverage.', flush=True)
        return 2
    if upload_error:
        print('Page scan completed, but publishing failed; the site may still contain unavailable products.', flush=True)
        return 3
    return 0


if __name__ == '__main__':
    sys.exit(main())
