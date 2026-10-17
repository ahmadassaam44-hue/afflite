#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Build compact, precomputed Cezma category/search chunks for storefront reads.

The source shards stay authoritative and untouched. This index is a derived cache:
category chunks contain every source record, while the trigram maps only identify
which chunks need to be read for a query. PHP still applies the original exact
category/search filters, visibility overrides, and ordering after loading them.
"""
from __future__ import annotations

import hashlib
import json
import os
import re
from collections import defaultdict
from typing import Callable

# Bump this and config.php's accepted format together for incompatible schema changes.
# The manifest signature also hashes derived metadata and all chunk/posting payloads.
INDEX_FORMAT = 1
INDEX_DIRNAME = 'zz_cezma_index'
MANIFEST_NAME = 'zz_manifest.json'
CHUNK_SIZE = 500
SEARCH_BUCKETS = '0123456789abcdef'

# These patterns mirror the name cleanup used by aff_clean($name, true) in
# config.php. They are used only to avoid false-negative trigram candidates;
# the storefront continues to run its real PHP cleaner and exact match.
_URL_RE = re.compile(
    r'https?://[^\s<>"\']*(?:ven-door|angazny|ledgerandlogic|time-hand|m5zoon|'
    r'safka-eg|safka|aff-shopmart|shopmart)[^\s<>"\']*', re.I | re.U
)
_PLAT_NAMES = (
    'تايم هاند', 'تايم-هاند', 'تايمهاند', 'timehand', 'time hand', 'time-hand',
    'فيندور', 'فين دور', 'فين-دور', 'vendoor', 'ven-door', 'ven door',
    'ليدجر', 'ledger', 'ledgerandlogic', 'أنجزني', 'انجزني', 'angazny',
    'مخزون', 'm5zoon', 'm5 zone', 'm5-zone', 'صفقة', 'safqa', 'safka',
    'شوب مارت', 'شوب-مارت', 'شوبمارت', 'أف شوب مارت', 'اف شوب مارت',
    'shopmart', 'shop mart', 'shop-mart',
)
_WORD_BOUNDARY = r'(?<![\w]){}(?![\w])'
_PLAT_PATTERNS = tuple(
    re.compile(_WORD_BOUNDARY.format(re.escape(name)), re.I | re.U)
    for name in _PLAT_NAMES
)
_AFFILIATE_RE = re.compile(
    _WORD_BOUNDARY.format(r'(?:affiliate|أفلييت|افيليت|التسويق بالعمولة|عمولة المسوق)'),
    re.I | re.U,
)
_CODE_RE = re.compile(r'\s*[\(\[\{]\s*(?:مخزن|محزن|مخز|كود|code)[^\)\]\}]*[\)\]\}]\s*', re.I | re.U)
_STORE_CODE_RE = re.compile(r'\s+(?:مخزن|محزن)\s+[A-Za-z0-9\-]{1,12}(?=\s|$)', re.U)
_CONTACT_RE = re.compile(
    r'(?:📞\s*)?(?:للمتابعة|للتواصل)\s+مع\s+(?:مسؤل|مسئول|مسؤول)[^\n\r.،!]*?(?:01[0125]\d{8})?',
    re.U,
)
_PERSON_PHONE_RE = re.compile(
    r'(?:عبد\s*الرحمن\s*(?:فوزي|قرطام)|أ?حمد\s*حلمي)\s*[:：\-]?\s*(?:01[0125]\d{8})?',
    re.U,
)
_PHONE_RE = re.compile(r'(?:\+?2)?01[0125]\d{8}', re.U)
_EDGE_PUNCT_RE = re.compile(r'^[\s\-–—:،؛,.]+|[\s\-–—:،؛,.]+$')


def _aff_clean_name(value: object) -> str:
    text = str(value or '')
    text = _URL_RE.sub('', text)
    for pattern in _PLAT_PATTERNS:
        text = pattern.sub(' ', text)
    text = _AFFILIATE_RE.sub(' ', text)
    text = _CODE_RE.sub(' ', text)
    text = _STORE_CODE_RE.sub(' ', text)
    text = _CONTACT_RE.sub(' ', text)
    text = _PERSON_PHONE_RE.sub(' ', text)
    text = _PHONE_RE.sub(' ', text)
    text = text.replace('\ufffd', '')
    text = re.sub(r'\(\s*\)', ' ', text)
    text = re.sub(r'\[\s*\]', ' ', text)
    text = _EDGE_PUNCT_RE.sub('', text)
    text = re.sub(r'\s{2,}', ' ', text)
    return _EDGE_PUNCT_RE.sub('', text).strip()


def _search_text(row: dict) -> str:
    original_name = str(row.get('name') or '')
    cleaned_name = _aff_clean_name(original_name) if original_name else ''
    # normalize_product_record() keeps the original name if aff_clean returns ''
    name = cleaned_name if cleaned_name else original_name
    sku = str(row.get('sku') or '')
    return (name + ' ' + sku).lower()


def _trigrams(text: str) -> set[str]:
    chars = list(text)
    return {''.join(chars[i:i + 3]) for i in range(max(0, len(chars) - 2))}


def read_source_shards(data_dir: str) -> tuple[list[dict], str]:
    """Read every source shard strictly and return rows plus a stable signature.

    A bad/missing shard fails the index build rather than silently publishing an
    index that would omit products. The normal storefront can then fall back to
    the source shards until the updater repairs the input.
    """
    shard_dir = os.path.join(data_dir, 'cezma_shards')
    if not os.path.isdir(shard_dir):
        raise FileNotFoundError(f'Cezma shard directory is missing: {shard_dir}')
    names = sorted(
        name for name in os.listdir(shard_dir)
        if name.startswith('lite_cezma_') and name.endswith('.json')
    )
    if not names:
        raise ValueError('No Cezma source shards found; refusing to publish an empty index')
    numeric_ids = [
        int(match.group(1)) for name in names
        if (match := re.fullmatch(r'lite_cezma_(\d+)\.json', name))
    ]
    if numeric_ids and sorted(numeric_ids) != list(range(1, max(numeric_ids) + 1)):
        raise ValueError('Cezma source shard sequence has a gap or duplicate')
    files = [os.path.join(shard_dir, name) for name in names]
    signature = hashlib.sha256()
    signature.update(f'afflite-cezma-index:{INDEX_FORMAT}\0'.encode('ascii'))
    rows: list[dict] = []
    for path in files:
        with open(path, 'rb') as stream:
            raw = stream.read()
        signature.update(os.path.basename(path).encode('utf-8') + b'\0')
        signature.update(hashlib.sha256(raw).digest())
        decoded = json.loads(raw.decode('utf-8'))
        if not isinstance(decoded, list):
            raise ValueError(f'Cezma shard is not a list: {os.path.basename(path)}')
        if any(not isinstance(row, dict) for row in decoded):
            raise ValueError(f'Cezma shard contains a non-object row: {os.path.basename(path)}')
        rows.extend(decoded)
    return rows, signature.hexdigest()


def _write_json(
    path: str,
    value: object,
    data_dir: str,
    changed_files: set[str] | None,
    payload_hashes: dict[str, bytes] | None = None,
) -> bool:
    payload = json.dumps(value, ensure_ascii=False, separators=(',', ':'))
    if payload_hashes is not None:
        payload_hashes[os.path.basename(path)] = hashlib.sha256(payload.encode('utf-8')).digest()
    try:
        with open(path, encoding='utf-8') as stream:
            if stream.read() == payload:
                return False
    except OSError:
        pass
    os.makedirs(os.path.dirname(path), exist_ok=True)
    temporary = path + '.tmp'
    with open(temporary, 'w', encoding='utf-8') as stream:
        stream.write(payload)
    os.replace(temporary, path)
    if changed_files is not None:
        rel = os.path.relpath(path, data_dir).replace('\\', '/')
        changed_files.add('data/' + rel)
    return True


def build(
    data_dir: str,
    categorize: Callable[[object, object], str],
    *,
    rows: list[dict] | None = None,
    source_signature: str | None = None,
    changed_files: set[str] | None = None,
    output_dir: str | None = None,
    chunk_size: int = CHUNK_SIZE,
) -> dict:
    """Write all category chunks, trigram postings, and the manifest.

    Records are never filtered by image, price, stock, or category. Chunk files
    are a complete read-optimized copy of the current lite source records.
    """
    if rows is None or source_signature is None:
        rows, source_signature = read_source_shards(data_dir)
    if not isinstance(rows, list) or not isinstance(source_signature, str):
        raise ValueError('Invalid Cezma search-index input')
    if chunk_size < 1:
        raise ValueError('chunk_size must be positive')

    grouped: dict[str, list[tuple[int, dict, str]]] = defaultdict(list)
    country_category_counts: dict[str, dict[str, int]] = defaultdict(dict)
    has_subcategories = False
    for order, source_row in enumerate(rows):
        if not isinstance(source_row, dict):
            raise ValueError('Cezma search index received a non-object row')
        raw_category = source_row.get('category') or ''
        if source_row.get('subcategory') not in (None, ''):
            has_subcategories = True
        category = str(categorize(raw_category, source_row.get('name') or '') or '')
        record = dict(source_row)
        record['category'] = category
        record['__wf_cezma_order'] = order
        grouped[category].append((order, record, _search_text(source_row)))
        country_value = source_row.get('country')
        country = str('مصر' if country_value is None else country_value)
        country_counts = country_category_counts[country]
        country_counts[category] = country_counts.get(category, 0) + 1

    root = output_dir or os.path.join(data_dir, INDEX_DIRNAME)
    os.makedirs(root, exist_ok=True)
    category_chunks: dict[str, list[int]] = {}
    chunk_names: list[str] = []
    postings: dict[str, set[int]] = defaultdict(set)
    category_counts: dict[str, int] = {}
    payload_hashes: dict[str, bytes] = {}

    for category in sorted(grouped):
        slug = hashlib.sha1(category.encode('utf-8')).hexdigest()[:12]
        entries = grouped[category]
        category_counts[category] = len(entries)
        chunk_ids = []
        for chunk_number, start in enumerate(range(0, len(entries), chunk_size), 1):
            filename = f'products_{slug}_{chunk_number:04d}.json'
            chunk_id = len(chunk_names)
            chunk_ids.append(chunk_id)
            chunk_names.append(filename)
            batch = entries[start:start + chunk_size]
            _write_json(os.path.join(root, filename), [entry[1] for entry in batch], data_dir, changed_files, payload_hashes)
            for _, _, text in batch:
                for gram in _trigrams(text):
                    postings[gram].add(chunk_id)
        category_chunks[category] = chunk_ids

    bucket_maps: dict[str, dict[str, list[int]]] = {bucket: {} for bucket in SEARCH_BUCKETS}
    for gram, chunk_ids in postings.items():
        bucket = hashlib.md5(gram.encode('utf-8')).hexdigest()[0]
        bucket_maps[bucket][gram] = sorted(chunk_ids)

    search_files: dict[str, str] = {}
    for bucket in SEARCH_BUCKETS:
        filename = f'search_{bucket}.json'
        search_files[bucket] = filename
        _write_json(os.path.join(root, filename), bucket_maps[bucket], data_dir, changed_files, payload_hashes)

    all_files = sorted(chunk_names + list(search_files.values()))
    metadata = {
        'format': INDEX_FORMAT,
        'product_count': len(rows),
        'chunk_size': chunk_size,
        'has_subcategories': has_subcategories,
        'category_counts': dict(sorted(category_counts.items())),
        'product_chunks': chunk_names,
        'country_category_counts': {
            country: dict(sorted(counts.items()))
            for country, counts in sorted(country_category_counts.items())
        },
        'categories': category_chunks,
        'search_files': search_files,
        'files': all_files,
        'file_sha256': {filename: payload_hashes[filename].hex() for filename in all_files},
    }
    signature_hash = hashlib.sha256()
    signature_hash.update(source_signature.encode('ascii'))
    signature_hash.update(json.dumps(metadata, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode('utf-8'))
    for filename in all_files:
        signature_hash.update(filename.encode('utf-8') + b'\0')
        signature_hash.update(payload_hashes[filename])
    index_signature = signature_hash.hexdigest()
    manifest = dict(metadata, signature=index_signature, source_signature=source_signature)
    # Publish the manifest last: PHP rejects an index without a matching
    # catalog_counts signature and falls back to the source shards.
    manifest_name = MANIFEST_NAME
    _write_json(os.path.join(root, manifest_name), manifest, data_dir, changed_files)

    expected = set(all_files + [manifest_name])
    for name in os.listdir(root):
        if name.endswith('.json') and name not in expected:
            try:
                os.remove(os.path.join(root, name))
            except OSError:
                pass

    return {
        'signature': index_signature,
        'products': len(rows),
        'chunks': len(chunk_names),
        'categories': len(category_chunks),
        'search_buckets': len(search_files),
        'files': len(all_files) + 1,
    }
