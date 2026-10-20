import glob
import json
import os
import tempfile
import unittest
from unittest.mock import patch

from updater import update_catalog

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHARD_DIR = os.path.join(ROOT, 'data', 'cezma_shards')


def write_shards(data_dir, rows, per_shard=2):
    shard_dir = os.path.join(data_dir, 'cezma_shards')
    os.makedirs(shard_dir, exist_ok=True)
    for name in os.listdir(shard_dir):
        if name.startswith('lite_cezma_'):
            os.remove(os.path.join(shard_dir, name))
    for start in range(0, len(rows), per_shard):
        name = f'lite_cezma_{start // per_shard + 1:02d}.json'
        with open(os.path.join(shard_dir, name), 'w', encoding='utf-8') as stream:
            json.dump(rows[start:start + per_shard], stream, ensure_ascii=False)
    return shard_dir


def row(product_id, name='منتج'):
    return {'id': f'cezma-{product_id}', 'orig_id': str(product_id), 'name': name,
            'price': 250.0, 'images': ['https://img.test/a.jpg'], 'extra': {'cezma_id': product_id}}


class Cezma2026OnlyTests(unittest.TestCase):
    def test_products_created_before_2026_are_deleted_from_every_shard(self):
        first_2026 = update_catalog.CEZMA_MIN_ID
        rows = [
            row(321286, 'منتج 2025'),                       # أول منتج 2025
            row(first_2026 - 1, 'منتج 2025 الأخير'),
            row(first_2026, 'أول منتج 2026'),
            row(first_2026 + 900, 'منتج 2026'),
        ]
        with tempfile.TemporaryDirectory() as data_dir:
            write_shards(data_dir, rows)
            with patch.object(update_catalog, 'DATA', data_dir), \
                 patch.object(update_catalog, 'changed_files', set()), \
                 patch.object(update_catalog, 'summary', {}):
                removed = update_catalog.remove_pre_2026_cezma()
                kept = update_catalog._cezma_load_shards()
                summary = dict(update_catalog.summary)
                names = sorted(name for name in os.listdir(os.path.join(data_dir, 'cezma_shards'))
                               if name.startswith('lite_cezma_'))

        self.assertEqual(removed, 2)
        self.assertEqual([int(r['orig_id']) for r in kept], [first_2026, first_2026 + 900])
        # الشظايا بتترقم من جديد من غير فجوات (بناء فهرس البحث بيرفض أي ترقيم ناقص)
        self.assertEqual(names, ['lite_cezma_01.json'])
        self.assertEqual(summary['cezma_2026_only']['removed'], 2)
        self.assertEqual(summary['cezma_2026_only']['minimum_id'], first_2026)

    def test_rows_without_readable_id_are_kept(self):
        rows = [row(600000), {'id': 'cz-manual', 'name': 'صف بلا رقم', 'images': []}]
        with tempfile.TemporaryDirectory() as data_dir:
            write_shards(data_dir, rows, per_shard=5)
            with patch.object(update_catalog, 'DATA', data_dir), \
                 patch.object(update_catalog, 'changed_files', set()), \
                 patch.object(update_catalog, 'summary', {}):
                removed = update_catalog.remove_pre_2026_cezma()
                kept = update_catalog._cezma_load_shards()

        self.assertEqual(removed, 0)
        self.assertEqual([r['id'] for r in kept], ['cezma-600000', 'cz-manual'])

    def test_unavailable_ids_of_deleted_2025_products_are_pruned(self):
        first_2026 = update_catalog.CEZMA_MIN_ID
        rows = [row(400001, 'منتج 2025'), row(first_2026 + 5, 'منتج 2026')]
        with tempfile.TemporaryDirectory() as data_dir:
            write_shards(data_dir, rows, per_shard=5)
            with open(os.path.join(data_dir, 'cezma_unavailable.json'), 'w', encoding='utf-8') as stream:
                json.dump({'400001': 'unavailable-price-message',
                           str(first_2026 + 5): 'unavailable-price-message'}, stream)
            with patch.object(update_catalog, 'DATA', data_dir), \
                 patch.object(update_catalog, 'changed_files', set()), \
                 patch.object(update_catalog, 'summary', {}), \
                 patch.object(update_catalog, '_CEZMA_UNAVAILABLE_IDS_CACHE', None):
                update_catalog.remove_pre_2026_cezma()
                with open(os.path.join(data_dir, 'cezma_unavailable.json'), encoding='utf-8') as stream:
                    saved = json.load(stream)

        self.assertEqual(list(saved), [str(first_2026 + 5)])

    def test_new_products_below_the_2026_threshold_are_never_imported(self):
        # update_cezma بيتجاهل أي منتج أقل من CEZMA_MIN_ID، فمنتجات 2025 مش هترجع تاني
        self.assertEqual(update_catalog.CEZMA_MIN_ID, 509251)
        with open(os.path.join(ROOT, 'cezma_scrape_summary.json'), encoding='utf-8') as stream:
            scrape = json.load(stream)
        self.assertEqual(update_catalog.CEZMA_MIN_ID, int(scrape['first_id']))

    def test_committed_catalog_shards_hold_no_2025_products(self):
        files = sorted(glob.glob(os.path.join(SHARD_DIR, 'lite_cezma_*.json')))
        if not files:
            self.skipTest('no committed Cezma shards')
        oldest = None
        for path in files:
            with open(path, encoding='utf-8') as stream:
                for record in json.load(stream):
                    product_id = update_catalog._cezma_row_id(record)
                    if product_id and (oldest is None or product_id < oldest):
                        oldest = product_id
        self.assertIsNotNone(oldest)
        self.assertGreaterEqual(oldest, update_catalog.CEZMA_MIN_ID)


if __name__ == '__main__':
    unittest.main()
