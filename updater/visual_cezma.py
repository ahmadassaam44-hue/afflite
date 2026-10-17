# -*- coding: utf-8 -*-
"""Photo-level visual index for Cezma products.

`data/visual_cezma.bin` uses one fixed 132-byte record per product photo:
  64 bytes colour histogram + 64 bytes greyscale shape + 4 bytes index offset.
`data/visual_cezma.idx` has one metadata line per photo:
  id TAB name TAB category TAB image URL TAB price

A product may therefore have several visual records, one for each URL in its
catalogue gallery (and any locally cached detail-gallery images). Existing
single-photo indexes remain readable and are reused by matching `(product id,
image URL)`. The time budget makes indexing resumable across scheduled runs.
"""
import concurrent.futures
import glob
import io
import json
import os
import struct
import time

from PIL import Image

try:
    import visual_features as vf
except ImportError:  # support importing the updater as a package in local tests
    from updater import visual_features as vf

REC = 132
OFF_FAIL = 0xFFFFFFFF
OFF_PENDING = 0xFFFFFFFE
ZERO_REC = bytes(128)
WORKERS = 12
CHUNK = 200
MIN_PRICE_EGP = 100.0
FTP_PART_BYTES = 8 * 1024 * 1024
# لازم يطابق visual_features.FEAT_VERSION — بيدبّر إعادة حساب من الصفر وقت تغيير خوارزمية الميزات
FEAT_VERSION = 3


def _clean(s):
    return str(s if s is not None else '').replace('\t', ' ').replace('\n', ' ').replace('\r', ' ').strip()


def _image_urls(value):
    """Flatten Cezma/API image strings and `{image|url: ...}` gallery entries."""
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
    return url.startswith(('http://', 'https://', 'assets/'))


def _collect(shards_dir, details_dir=None):
    """Return a de-duplicated row for every usable photo in every Cezma product."""
    rows, seen = [], set()
    if details_dir is None:
        details_dir = os.path.join(os.path.dirname(os.path.abspath(shards_dir)), 'cezma_details')
    has_details = os.path.isdir(details_dir)

    for path in sorted(glob.glob(os.path.join(shards_dir, 'lite_cezma_*.json'))):
        try:
            with open(path, encoding='utf-8') as fh:
                data = json.load(fh)
        except Exception:
            continue
        if not isinstance(data, list):
            continue
        for it in data:
            if not isinstance(it, dict):
                continue
            try:
                if float(it.get('price') or 0) < MIN_PRICE_EGP:
                    continue
            except (TypeError, ValueError):
                continue
            pid = str(it.get('id') or '').strip()
            if not pid:
                continue
            extra = it.get('extra') if isinstance(it.get('extra'), dict) else {}
            urls = []
            urls.extend(_image_urls(it.get('images')))
            urls.extend(_image_urls(it.get('image')))
            urls.extend(_image_urls(extra.get('detail_images')))

            if has_details:
                detail_id = str(extra.get('cezma_id') or it.get('orig_id') or pid.removeprefix('cezma-')).strip()
                if detail_id.isdigit():
                    detail_path = os.path.join(details_dir, detail_id + '.json')
                    try:
                        with open(detail_path, encoding='utf-8') as fh:
                            detail = json.load(fh)
                        if isinstance(detail, dict):
                            urls.extend(_image_urls(detail.get('images')))
                    except (OSError, ValueError, TypeError):
                        pass

            unique_urls = []
            for url in urls:
                url = url.strip()
                if _usable_image(url) and url not in unique_urls:
                    unique_urls.append(url)
            for img in unique_urls:
                identity = (pid, img)
                if identity in seen:
                    continue
                seen.add(identity)
                rows.append({
                    'key': identity,
                    'id': pid,
                    'img': img,
                    'name': _clean(it.get('name')),
                    'cat': _clean(it.get('category')),
                    'price': _clean(it.get('price')),
                })
    return rows


def _work(item):
    identity = (str(item[0]), str(item[1]))
    url = identity[1]
    try:
        data = vf._download(url)
        im = Image.open(io.BytesIO(data))
        im.load()
        return identity, vf.features_from_pil(im)
    except Exception:
        return identity, None


def _rec_bytes(feat):
    h = bytes(min(255, max(0, int(x))) for x in feat['h'])
    t = bytes(min(255, max(0, int(x))) for x in feat['t'])
    return h + t


def split_for_ftp(path, part_bytes=FTP_PART_BYTES):
    """Split a cached index into FTP-safe parts; return `(parts, changed_parts)`."""
    part_bytes = int(part_bytes)
    if part_bytes <= 0:
        raise ValueError('part_bytes must be positive')
    if not os.path.isfile(path):
        return [], []

    prefix = path + '.part-'
    previous = set(glob.glob(prefix + '*'))
    parts, changed = [], []
    with open(path, 'rb') as source:
        number = 1
        while True:
            chunk = source.read(part_bytes)
            if not chunk:
                break
            part_path = f'{prefix}{number:03d}'
            parts.append(part_path)
            same = False
            if os.path.isfile(part_path):
                with open(part_path, 'rb') as current:
                    same = current.read() == chunk
            if not same:
                temp_path = part_path + '.tmp'
                with open(temp_path, 'wb') as target:
                    target.write(chunk)
                os.replace(temp_path, part_path)
                changed.append(part_path)
            number += 1

    for stale in previous - set(parts):
        try:
            os.remove(stale)
        except FileNotFoundError:
            pass
    return parts, changed


def build(shards_dir, bin_path, idx_path, budget=1500.0, log=print):
    """Build/continue the photo-level index; return `(indexed_photos, changed)`."""
    t0 = time.monotonic()
    try:
        budget = max(0.0, float(budget))
    except (TypeError, ValueError):
        budget = 1500.0
    rows = _collect(shards_dir)

    # إعادة بناء من الصفر بقت تغيير خوارزمية الميزات (ملف الإصدار)
    ver_file = os.path.join(os.path.dirname(os.path.abspath(bin_path)), 'visual_cezma.ver')
    if not os.path.isfile(ver_file) or (open(ver_file).read().strip() != str(FEAT_VERSION)):
        for p in (bin_path, idx_path):
            try:
                os.remove(p)
            except FileNotFoundError:
                pass
        with open(ver_file, 'w') as fh:
            fh.write(str(FEAT_VERSION))
        log('visual_cezma: feature v%d - index reset for full recompute' % FEAT_VERSION)

    # Reuse old records by the product id AND URL so adding a gallery photo does
    # not invalidate the already-computed primary image.
    old_recs = {}
    if os.path.isfile(bin_path) and os.path.isfile(idx_path):
        try:
            with open(bin_path, 'rb') as fh:
                blob = fh.read()
            with open(idx_path, encoding='utf-8') as fh:
                meta_lines = fh.read().splitlines()
            for i in range(len(blob) // REC):
                if i >= len(meta_lines):
                    break
                parts = meta_lines[i].split('\t')
                if len(parts) < 4:
                    continue
                identity = (parts[0], parts[3])
                old_recs[identity] = blob[i * REC:i * REC + REC]
        except (OSError, UnicodeError):
            old_recs = {}

    status = {}  # (product id, image URL) -> ('ok', feature bytes) | ('fail', None)
    todo = []
    for row in rows:
        identity = row['key']
        prev = old_recs.get(identity)
        if prev:
            off = struct.unpack('<I', prev[128:132])[0]
            if off == OFF_FAIL:
                status[identity] = ('fail', None)
                continue
            if off != OFF_PENDING:
                status[identity] = ('ok', prev[:128])
                continue
        todo.append(identity)

    log('visual_cezma: %d product photos, %d to compute' % (len(rows), len(todo)))
    done_now = 0
    fails = 0
    if todo and budget > 0:
        with concurrent.futures.ProcessPoolExecutor(max_workers=WORKERS) as ex:
            for start in range(0, len(todo), CHUNK):
                if time.monotonic() - t0 >= budget:
                    break
                for identity, feat in ex.map(_work, todo[start:start + CHUNK]):
                    if feat is None:
                        status[identity] = ('fail', None)
                        fails += 1
                    else:
                        status[identity] = ('ok', _rec_bytes(feat))
                    done_now += 1
    log('visual_cezma: computed %d photos (failed %d) in %.0fs' %
        (done_now, fails, time.monotonic() - t0))

    meta_chunks = []
    bin_chunks = []
    pos = 0
    pending = 0
    for row in rows:
        line = '\t'.join([row['id'], row['name'], row['cat'], row['img'], row['price']]) + '\n'
        lb = line.encode('utf-8')
        kind, feat = status.get(row['key'], ('pending', None))
        if kind == 'ok':
            feat_part, off = feat, pos
        elif kind == 'fail':
            feat_part, off = ZERO_REC, OFF_FAIL
        else:
            feat_part, off = ZERO_REC, OFF_PENDING
            pending += 1
        bin_chunks.append(feat_part + struct.pack('<I', off))
        meta_chunks.append(lb)
        pos += len(lb)

    new_bin = b''.join(bin_chunks)
    new_idx = b''.join(meta_chunks)
    changed = True
    if os.path.isfile(bin_path) and os.path.isfile(idx_path):
        try:
            with open(bin_path, 'rb') as fh:
                same_bin = fh.read() == new_bin
            with open(idx_path, 'rb') as fh:
                same_idx = fh.read() == new_idx
            changed = not (same_bin and same_idx)
        except OSError:
            changed = True
    if changed:
        for path, data in ((idx_path, new_idx), (bin_path, new_bin)):
            os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
            tmp = path + '.tmp'
            with open(tmp, 'wb') as fh:
                fh.write(data)
            os.replace(tmp, path)
        log('visual_cezma: wrote %d photo records (%d still pending)' % (len(rows), pending))
    else:
        log('visual_cezma: unchanged (%d photos, %d pending)' % (len(rows), pending))
    return len(rows) - pending, changed


if __name__ == '__main__':
    import sys
    here = os.path.dirname(os.path.abspath(__file__))
    data = os.path.join(here, '..', 'data')
    budget = float(sys.argv[1]) if len(sys.argv) > 1 else 1500.0
    build(os.path.join(data, 'cezma_shards'), os.path.join(data, 'visual_cezma.bin'),
          os.path.join(data, 'visual_cezma.idx'), budget=budget)
    sys.exit(0)
