import json
import os
import sys
import tempfile
import unittest
from unittest import mock

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, ROOT)
from updater import cezma_build, update_catalog, visual_cezma, visual_features


class InlinePool:
    """Run the Cezma builder's small test batches synchronously."""
    def __init__(self, *args, **kwargs):
        pass

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def map(self, function, items):
        return map(function, items)


class VisualGalleryIndexTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = self.tmp.name

    def tearDown(self):
        self.tmp.cleanup()

    def write_json(self, path, value):
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, 'w', encoding='utf-8') as fh:
            json.dump(value, fh, ensure_ascii=False)

    def read_json(self, path):
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)

    def test_feature_index_keeps_and_scores_all_product_photos(self):
        data = os.path.join(self.root, 'data')
        os.makedirs(data)
        urls = [
            'https://cdn.example.test/main.jpg',
            'https://cdn.example.test/side.jpg',
            'https://cdn.example.test/detail.jpg',
        ]
        self.write_json(os.path.join(data, 'vendoor.json'), [{
            'id': 'multi', 'name': 'منتج متعدد الصور', 'category': 'أحذية',
            'images': [urls[0], {'url': urls[1]}],
            'extra': {'detail_images': [urls[2], urls[1]]},
        }])

        calls = []

        def fake_work(item):
            key, url = item
            calls.append(url)
            marker = len(calls)
            return (key, url), {'h': [marker] * 64, 't': [marker + 1] * 64}

        output = os.path.join(data, 'visual_features.json')
        with mock.patch.object(visual_features, '_work', side_effect=fake_work):
            count, changed = visual_features.build(data, output, log=lambda *_: None, budget=30)

        self.assertEqual(count, 1)
        self.assertTrue(changed)
        self.assertCountEqual(calls, urls)
        payload = self.read_json(output)
        product = payload['items']['vendoor#multi']
        indexed_urls = [product['u']] + [photo['u'] for photo in product['gallery']]
        self.assertEqual(indexed_urls, urls)
        self.assertEqual(payload['images_count'], 3)

        # A second build reuses the features for every unchanged gallery image.
        with mock.patch.object(visual_features, '_work', side_effect=AssertionError('unexpected download')):
            count, changed = visual_features.build(data, output, log=lambda *_: None, budget=30)
        self.assertEqual(count, 1)
        self.assertFalse(changed)

    def test_legacy_primary_feature_is_reused_while_new_gallery_photo_is_added(self):
        data = os.path.join(self.root, 'data')
        os.makedirs(data)
        primary = 'https://cdn.example.test/main.jpg'
        secondary = 'https://cdn.example.test/second.jpg'
        self.write_json(os.path.join(data, 'vendoor.json'), [{
            'id': 'legacy', 'images': [primary, secondary],
        }])
        old = {
            'v': 1, 'items': {
                'vendoor#legacy': {
                    'u': primary, 'img': primary, 'plat': 'vendoor', 'id': 'legacy',
                    'name': '', 'cat': '', 'h': [7] * 64, 't': [8] * 64,
                },
            },
            'bad': {},
        }
        output = os.path.join(data, 'visual_features.json')
        self.write_json(output, old)
        calls = []

        def fake_work(item):
            key, url = item
            calls.append(url)
            return (key, url), {'h': [9] * 64, 't': [10] * 64}

        with mock.patch.object(visual_features, '_work', side_effect=fake_work):
            visual_features.build(data, output, log=lambda *_: None, budget=30)
        self.assertEqual(calls, [secondary])
        product = self.read_json(output)['items']['vendoor#legacy']
        self.assertEqual(product['h'], [7] * 64)
        self.assertEqual(product['gallery'][0]['h'], [9] * 64)

    def test_cezma_catalog_update_keeps_api_gallery_images(self):
        urls = [
            'https://cdn.example.test/primary.jpg',
            'https://cdn.example.test/second.jpg',
            'https://cdn.example.test/third.jpg',
        ]
        product = {
            'image': urls[0],
            'images': [urls[1], {'image': urls[2]}, {'url': urls[1]}],
        }
        self.assertEqual(update_catalog._cezma_product_images(product), urls)
        csv_images = cezma_build.image_urls({
            'image': urls[0], 'images_json': json.dumps([urls[1], {'url': urls[2]}]),
        })
        self.assertEqual(csv_images, urls)

    def test_cezma_price_filter_removes_below_threshold_and_keeps_exact_threshold(self):
        data = os.path.join(self.root, 'data')
        shards = os.path.join(data, 'cezma_shards')
        self.write_json(os.path.join(shards, 'lite_cezma_01.json'), [
            {'id': 'cezma-low', 'price': 99.99},
            {'id': 'cezma-floor', 'price': 100},
        ])
        self.write_json(os.path.join(shards, 'lite_cezma_02.json'), [
            {'id': 'cezma-high', 'price': 250},
            {'id': 'cezma-too-low', 'price': 80},
        ])

        with (
            mock.patch.object(update_catalog, 'DATA', data),
            mock.patch.object(update_catalog, 'changed_files', set()),
            mock.patch.object(update_catalog, 'summary', {}) as summary,
            mock.patch.object(update_catalog, 'log', lambda *_: None),
        ):
            removed = update_catalog.remove_low_price_cezma()
            rows = update_catalog._cezma_load_shards()
        self.assertEqual(removed, 2)
        self.assertEqual({row['id'] for row in rows}, {'cezma-floor', 'cezma-high'})
        self.assertEqual(summary['cezma_price_filter']['minimum_price_egp'], 100.0)
        self.assertEqual(summary['cezma_price_filter']['removed'], 2)

    def test_cezma_updater_filters_new_and_newly_cheap_products_but_keeps_100(self):
        data = os.path.join(self.root, 'data')
        shards = os.path.join(data, 'cezma_shards')
        self.write_json(os.path.join(shards, 'lite_cezma_01.json'), [
            {
                'id': 'cezma-600001', 'orig_id': '600001', 'price': 150,
                'extra': {'cezma_id': 600001},
            },
            {
                'id': 'cezma-600002', 'orig_id': '600002', 'price': 100,
                'extra': {'cezma_id': 600002},
            },
        ])
        products = [
            {'id': 600001, 'price': 99},
            {'id': 600002, 'price': 100},
            {'id': 600003, 'price': 99},
            {'id': 600004, 'price': 100},
            {'id': 600005, 'price': 150},
        ]

        with (
            mock.patch.object(update_catalog, 'DATA', data),
            mock.patch.object(update_catalog, 'changed_files', set()),
            mock.patch.object(update_catalog, 'summary', {}) as summary,
            mock.patch.object(update_catalog, 'log', lambda *_: None),
            mock.patch.object(update_catalog, '_cezma_get', return_value={'data': [{'id': 1, 'name_ar': 'قسم'}]}),
            mock.patch.object(update_catalog, '_cezma_scan_cat', return_value=products),
        ):
            update_catalog.update_cezma()
            rows = update_catalog._cezma_load_shards()

        self.assertEqual({row['id'] for row in rows}, {'cezma-600002', 'cezma-600004', 'cezma-600005'})
        self.assertEqual(summary['cezma']['added'], 2)
        self.assertEqual(summary['cezma']['removed_below_min_price'], 1)
        self.assertTrue(all(float(row['price']) >= 100 for row in rows))

    def test_cezma_csv_builder_skips_2025_products_and_prices_below_100(self):
        csv_path = os.path.join(self.root, 'cezma.csv')
        with open(csv_path, 'w', encoding='utf-8') as fh:
            fh.write(
                'id,price,price_before_discount,image,name_ar,name_en,discount,category_ar,store_name\n'
                '400001,900,,https://cdn.example.test/old.jpg,قديم,Old,,قسم,متجر\n'
                '600001,99,,https://cdn.example.test/cheap.jpg,رخيص,Cheap,,قسم,متجر\n'
                '600002,100,,https://cdn.example.test/floor.jpg,الحد,Floor,,قسم,متجر\n'
            )
        with mock.patch.object(cezma_build, 'SRC', csv_path):
            rows = cezma_build.build_rows()
        # 400001 منتج 2025 — اتحذف من الكتالوج ومايتبنيش تاني من ملف السكراب القديم
        self.assertEqual([(row['id'], row['price']) for row in rows], [('cezma-600002', 100.0)])

        shards = os.path.join(self.root, 'builder_shards')
        with mock.patch.object(cezma_build, 'SHARD_DIR', shards):
            cezma_build.write_shards(rows + [{'id': 'cezma-extra-low', 'price': 99},
                                             {'id': 'cezma-400002', 'orig_id': '400002', 'price': 500}])
        with open(os.path.join(shards, 'lite_cezma_01.json'), encoding='utf-8') as fh:
            written = json.load(fh)
        self.assertEqual([row['id'] for row in written], ['cezma-600002'])

    def test_visual_cezma_ftp_parts_round_trip_and_remove_stale_chunks(self):
        path = os.path.join(self.root, 'visual_cezma.bin')
        payload = bytes(range(256)) * 40
        with open(path, 'wb') as fh:
            fh.write(payload)

        parts, changed = visual_cezma.split_for_ftp(path, part_bytes=4096)
        self.assertEqual(len(parts), 3)
        self.assertEqual(parts, changed)
        self.assertTrue(all(os.path.getsize(part) <= 4096 for part in parts))
        combined = b''
        for part in parts:
            with open(part, 'rb') as fh:
                combined += fh.read()
        self.assertEqual(combined, payload)

        same_parts, changed = visual_cezma.split_for_ftp(path, part_bytes=4096)
        self.assertEqual(same_parts, parts)
        self.assertEqual(changed, [])

        with open(path, 'wb') as fh:
            fh.write(payload[:3000])
        shorter_parts, changed = visual_cezma.split_for_ftp(path, part_bytes=4096)
        self.assertEqual(len(shorter_parts), 1)
        self.assertEqual(changed, shorter_parts)
        self.assertFalse(os.path.exists(parts[1]))
        self.assertFalse(os.path.exists(parts[2]))

    def test_cezma_index_contains_each_catalog_and_cached_detail_photo(self):
        data = os.path.join(self.root, 'data')
        shards = os.path.join(data, 'cezma_shards')
        details = os.path.join(data, 'cezma_details')
        os.makedirs(shards)
        urls = [
            'https://cdn.example.test/cezma-main.jpg',
            'https://cdn.example.test/cezma-gallery.jpg',
            'https://cdn.example.test/cezma-extra.jpg',
            'https://cdn.example.test/cezma-cached-detail.jpg',
        ]
        self.write_json(os.path.join(shards, 'lite_cezma_01.json'), [{
            'id': 'cezma-123', 'orig_id': '123', 'name': 'حذاء', 'category': 'أحذية', 'price': 250,
            'images': [urls[0], {'image': urls[1]}],
            'extra': {'cezma_id': 123, 'detail_images': [urls[2], urls[1]]},
        }])
        floor_url = 'https://cdn.example.test/cezma-exactly-100.jpg'
        low_url = 'https://cdn.example.test/cezma-under-100.jpg'
        self.write_json(os.path.join(shards, 'lite_cezma_02.json'), [
            {'id': 'cezma-100', 'name': 'سعر الحد', 'category': 'أحذية', 'price': 100, 'images': [floor_url]},
            {'id': 'cezma-99', 'name': 'أقل من الحد', 'category': 'أحذية', 'price': 99, 'images': [low_url]},
        ])
        self.write_json(os.path.join(details, '123.json'), {'images': [urls[1], urls[3]]})

        rows = visual_cezma._collect(shards)
        self.assertEqual([row['img'] for row in rows], urls + [floor_url])

        def fake_work(identity):
            return identity, {'h': [11] * 64, 't': [12] * 64}

        bin_path = os.path.join(data, 'visual_cezma.bin')
        idx_path = os.path.join(data, 'visual_cezma.idx')
        with mock.patch.object(visual_cezma.concurrent.futures, 'ProcessPoolExecutor', InlinePool), \
             mock.patch.object(visual_cezma, '_work', side_effect=fake_work):
            indexed, changed = visual_cezma.build(shards, bin_path, idx_path, budget=30, log=lambda *_: None)
        self.assertEqual(indexed, 5)
        self.assertTrue(changed)
        self.assertEqual(os.path.getsize(bin_path), 5 * visual_cezma.REC)
        with open(idx_path, encoding='utf-8') as fh:
            lines = fh.read().splitlines()
        self.assertEqual([line.split('\t')[3] for line in lines], urls + [floor_url])

        with mock.patch.object(visual_cezma.concurrent.futures, 'ProcessPoolExecutor', InlinePool), \
             mock.patch.object(visual_cezma, '_work', side_effect=AssertionError('unexpected download')):
            indexed, changed = visual_cezma.build(shards, bin_path, idx_path, budget=30, log=lambda *_: None)
        self.assertEqual(indexed, 5)
        self.assertFalse(changed)


if __name__ == '__main__':
    unittest.main()
