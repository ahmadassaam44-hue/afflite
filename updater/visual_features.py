# -*- coding: utf-8 -*-
"""Visual feature index for physical product photos (data/visual_features.json).

Each product keeps the legacy primary-photo fields (`h` / `t`) plus a `gallery`
array containing features for every other usable product photo. Search can then
score all photos and return the photo that actually matched, rather than only
comparing the first URL in a product's image list.

Features are calculated exactly like `extractVisualFeatures()` in
`assets/tracker.js`: a 64-bin colour histogram and an 8x8 greyscale shape grid.
The builder is resumable: unchanged photo URLs reuse cached features, failed URLs
are remembered, and a time budget lets the scheduled catalog job finish across
multiple runs.
"""
import concurrent.futures
import hashlib
import io
import json
import os
import re
import time
import urllib.request

from PIL import Image

GRID = 64          # thumbnail size before extracting features
THUMB = 8          # 8x8 shape grid
CELL = GRID // THUMB
BG_MIN = 244       # nearly-white pixels are treated as background
MAX_BYTES = 4_000_000
WORKERS = 8
CHUNK = 64
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ASSET_ROOT = os.path.realpath(os.path.join(ROOT, 'assets'))
UA = {'User-Agent': 'Mozilla/5.0 (compatible; afflite-visual/1.0)'}
PLACEHOLDER_MARKERS = ('placeholder', 'place-holder', 'image-place-holder', 'no-image', '/default.png', '/def.png')


def features_from_pil(im):
    """Return `{'h': [64 ints], 't': [64 ints]}` for a PIL image."""
    im = im.convert('RGBA')
    white = Image.new('RGBA', im.size, (255, 255, 255, 255))
    rgb = Image.alpha_composite(white, im).convert('RGB').resize((GRID, GRID), Image.BILINEAR)
    px = list(rgb.getdata())
    hist = [0] * 64
    gray_sum = [0.0] * (THUMB * THUMB)
    total = 0
    for i, (r, g, b) in enumerate(px):
        x = i % GRID
        y = i // GRID
        cell = (y // CELL) * THUMB + (x // CELL)
        if r > BG_MIN and g > BG_MIN and b > BG_MIN:
            gray_sum[cell] += 255
            continue
        gray_sum[cell] += 0.299 * r + 0.587 * g + 0.114 * b
        hist[(r >> 6) * 16 + (g >> 6) * 4 + (b >> 6)] += 1
        total += 1
    if total:
        h = [int(c * 255 / total + 0.5) for c in hist]
    else:
        h = [0] * 64
    per_cell = CELL * CELL
    t = [int(s / per_cell + 0.5) for s in gray_sum]
    return {'h': h, 't': t}


def _download(url):
    """Load a remote photo or a repository-local `assets/...` photo."""
    url = str(url or '').strip()
    if re.match(r'^https?://', url, re.I):
        req = urllib.request.Request(url, headers=UA)
        with urllib.request.urlopen(req, timeout=12) as r:
            data = r.read(MAX_BYTES + 1)
    else:
        rel = url.lstrip('/')
        if not rel.startswith('assets/'):
            raise ValueError('unsupported local image path')
        path = os.path.realpath(os.path.join(ROOT, rel))
        if not path.startswith(ASSET_ROOT + os.sep) or not os.path.isfile(path):
            raise ValueError('local image is outside assets or missing')
        with open(path, 'rb') as fh:
            data = fh.read(MAX_BYTES + 1)
    if len(data) > MAX_BYTES:
        raise ValueError('image too large')
    return data


def _image_urls(value):
    """Flatten common source image representations into URL strings."""
    out = []
    if isinstance(value, str):
        value = value.strip()
        if value:
            out.append(value)
    elif isinstance(value, dict):
        for field in ('image', 'image_url', 'url', 'src', 'path', 'original', 'full',
                      'images', 'product_images', 'gallery', 'photos', 'media', 'data', 'items'):
            out.extend(_image_urls(value.get(field)))
    elif isinstance(value, (list, tuple)):
        for item in value:
            out.extend(_image_urls(item))
    return out


def _usable_image(url):
    url = str(url or '').strip()
    low = url.lower()
    if not url or low.startswith('assets/icon-') or any(marker in low for marker in PLACEHOLDER_MARKERS):
        return False
    return bool(re.match(r'^https?://', url, re.I) or url.startswith('assets/'))


def _product_image_urls(it):
    urls = []
    urls.extend(_image_urls(it.get('images')))
    # Some supplier feeds store the lead photo separately from their gallery.
    urls.extend(_image_urls(it.get('image')))
    extra = it.get('extra') if isinstance(it.get('extra'), dict) else {}
    urls.extend(_image_urls(extra.get('detail_images')))
    unique = []
    for url in urls:
        url = url.strip()
        if _usable_image(url) and url not in unique:
            unique.append(url)
    return unique


def _work(item):
    key, url = item
    try:
        im = Image.open(io.BytesIO(_download(url)))
        im.load()
        return (key, url), features_from_pil(im)
    except Exception:
        return (key, url), None


def _valid_features(value):
    return (isinstance(value, dict)
            and isinstance(value.get('h'), list) and len(value['h']) == 64
            and isinstance(value.get('t'), list) and len(value['t']) == 64)


def _record_photos(record):
    """Read both v1 (one photo per product) and v2 cached records."""
    found = {}
    if not isinstance(record, dict):
        return found
    primary_url = str(record.get('u') or record.get('img') or '').strip()
    if primary_url and _valid_features(record):
        found[primary_url] = {'h': record['h'], 't': record['t']}
    gallery = record.get('gallery')
    if isinstance(gallery, list):
        for photo in gallery:
            if not isinstance(photo, dict) or not _valid_features(photo):
                continue
            url = str(photo.get('u') or photo.get('img') or '').strip()
            if url:
                found[url] = {'h': photo['h'], 't': photo['t']}
    return found


def _bad_key(product_key, url):
    digest = hashlib.sha1(url.encode('utf-8')).hexdigest()[:16]
    return product_key + '#' + digest


def _was_bad(old_bad, product_key, url):
    # Read legacy `bad[product_key] = url` entries as well as v2 per-photo keys.
    return old_bad.get(_bad_key(product_key, url)) == url or old_bad.get(product_key) == url


def _collect(data_dir):
    """Collect all usable photos for each physical product."""
    catalog = {}
    for plat in ('vendoor', 'timehand', 'safqa', 'angazny', 'm5zoon', 'shopmart'):
        path = os.path.join(data_dir, plat + '.json')
        if not os.path.isfile(path):
            continue
        try:
            with open(path, encoding='utf-8') as f:
                rows = json.load(f)
        except Exception:
            continue
        if not isinstance(rows, list):
            continue
        for it in rows:
            if not isinstance(it, dict):
                continue
            urls = _product_image_urls(it)
            if not urls:
                continue
            key = '%s#%s' % (plat, it.get('id'))
            catalog[key] = {
                'plat': plat,
                'id': str(it.get('id')),
                'name': str(it.get('name') or ''),
                'cat': str(it.get('category') or ''),
                'urls': urls,
            }
    return catalog


def build(data_dir, out_path, log=print, budget=900.0):
    """Build/update the index; returns `(indexed_products, changed)`.

    `budget` bounds image downloads for this run. Photos not reached before the
    limit remain absent from the output and are picked up on the next run.
    """
    started = time.monotonic()
    try:
        budget = max(0.0, float(budget))
    except (TypeError, ValueError):
        budget = 900.0

    old_items, old_bad = {}, {}
    if os.path.isfile(out_path):
        try:
            with open(out_path, encoding='utf-8') as f:
                previous = json.load(f) or {}
            old_items = previous.get('items') or {}
            old_bad = previous.get('bad') or {}
            if not isinstance(old_items, dict):
                old_items = {}
            if not isinstance(old_bad, dict):
                old_bad = {}
        except Exception:
            old_items, old_bad = {}, {}

    catalog = _collect(data_dir)
    cached = {key: _record_photos(rec) for key, rec in old_items.items()}
    todo = []
    for key, meta in catalog.items():
        existing = cached.get(key, {})
        for url in meta['urls']:
            if url in existing or _was_bad(old_bad, key, url):
                continue
            todo.append((key, url))
    log('visual: %d products, %d photos to compute' % (len(catalog), len(todo)))

    computed = {}
    if todo and budget > 0:
        with concurrent.futures.ThreadPoolExecutor(max_workers=WORKERS) as ex:
            for start in range(0, len(todo), CHUNK):
                if time.monotonic() - started >= budget:
                    break
                for identity, feat in ex.map(_work, todo[start:start + CHUNK]):
                    computed[identity] = feat

    items, bad = {}, {}
    success_count = 0
    fail_count = 0
    photo_count = 0
    for key, meta in catalog.items():
        photos = []
        previous_photos = cached.get(key, {})
        for url in meta['urls']:
            identity = (key, url)
            feat = computed.get(identity)
            if feat is None:
                feat = previous_photos.get(url)
            if _valid_features(feat):
                photos.append({'u': url, 'img': url, 'h': feat['h'], 't': feat['t']})
                photo_count += 1
                if identity in computed:
                    success_count += 1
            elif identity in computed or _was_bad(old_bad, key, url):
                bad[_bad_key(key, url)] = url
                if identity in computed:
                    fail_count += 1
        if not photos:
            continue
        first, *gallery = photos
        record = {
            'u': first['u'], 'img': first['img'], 'plat': meta['plat'], 'id': meta['id'],
            'name': meta['name'], 'cat': meta['cat'], 'h': first['h'], 't': first['t'],
        }
        if gallery:
            record['gallery'] = gallery
        items[key] = record

    log('visual: computed %d photos, failed %d; indexed %d photos across %d products' %
        (success_count, fail_count, photo_count, len(items)))
    payload = {
        'v': 2, 'grid': GRID, 'thumb': THUMB, 'count': len(items),
        'images_count': photo_count, 'items': items, 'bad': bad,
    }
    new = json.dumps(payload, ensure_ascii=False, separators=(',', ':'))
    old_text = None
    if os.path.isfile(out_path):
        with open(out_path, encoding='utf-8') as f:
            old_text = f.read()
    if old_text == new:
        log('visual: unchanged (%d products, %d photos)' % (len(items), photo_count))
        return len(items), False
    tmp = out_path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(new)
    os.replace(tmp, out_path)
    log('visual: wrote %s with %d products / %d photos' %
        (os.path.basename(out_path), len(items), photo_count))
    return len(items), True


if __name__ == '__main__':
    import sys
    here = os.path.dirname(os.path.abspath(__file__))
    data = os.path.join(here, '..', 'data')
    budget = float(sys.argv[1]) if len(sys.argv) > 1 else 900.0
    build(data, os.path.join(data, 'visual_features.json'), budget=budget)
    sys.exit(0)
