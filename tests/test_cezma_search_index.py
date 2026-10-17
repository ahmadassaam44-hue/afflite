import hashlib
import json
import os
import tempfile
import unittest

from updater.cezma_search_index import build, read_source_shards
from updater.update_catalog import cezma_category, cezma_site_category


class CezmaSearchIndexTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.data = self.temp.name
        os.makedirs(os.path.join(self.data, 'cezma_shards'))
        self.rows = [
            {
                'id': 'cz-shoe', 'name': 'حذاء مخزن ABC جديد', 'sku': 'PUMA-1',
                'category': 'الموضة والجمال', 'price': 300, 'images': ['https://img.test/shoe.jpg'],
            },
            {
                'id': 'cz-oven', 'name': 'فرن كهربائي صغير', 'category': 'الموضة والجمال',
                'price': 700, 'images': ['https://img.test/oven.jpg'],
            },
            {
                'id': 'cz-fridge', 'name': 'ثلاجة صغيرة', 'category': 'أجهزة منزلية',
                'price': 4000, 'images': [],
            },
        ]
        self._write_shard('lite_cezma_01.json', self.rows[:2])
        self._write_shard('lite_cezma_02.json', self.rows[2:])

    def _write_shard(self, name, rows):
        path = os.path.join(self.data, 'cezma_shards', name)
        with open(path, 'w', encoding='utf-8') as stream:
            json.dump(rows, stream, ensure_ascii=False, separators=(',', ':'))

    def _manifest(self):
        path = os.path.join(self.data, 'zz_cezma_index', 'zz_manifest.json')
        with open(path, encoding='utf-8') as stream:
            return json.load(stream)

    def test_index_contains_every_product_and_indexes_categories_and_search(self):
        rows, signature = read_source_shards(self.data)
        changed = set()
        result = build(
            self.data, cezma_site_category, rows=rows, source_signature=signature,
            changed_files=changed, chunk_size=2,
        )
        manifest = self._manifest()

        self.assertEqual(result['products'], 3)
        self.assertEqual(manifest['product_count'], 3)
        self.assertEqual(manifest['source_signature'], signature)
        self.assertFalse(manifest['has_subcategories'])
        self.assertEqual(manifest['signature'], result['signature'])
        for filename in manifest['files']:
            path = os.path.join(self.data, 'zz_cezma_index', filename)
            with open(path, 'rb') as stream:
                self.assertEqual(manifest['file_sha256'][filename], hashlib.sha256(stream.read()).hexdigest())
        self.assertIn('المنزل والمطبخ', manifest['categories'])
        self.assertEqual(manifest['country_category_counts']['مصر']['أحذية'], 1)
        self.assertTrue(changed)

        all_rows = []
        for filename in manifest['files']:
            if not filename.startswith('products_'):
                continue
            with open(os.path.join(self.data, 'zz_cezma_index', filename), encoding='utf-8') as stream:
                all_rows.extend(json.load(stream))
        self.assertEqual([row['id'] for row in sorted(all_rows, key=lambda row: row['__wf_cezma_order'])],
                         ['cz-shoe', 'cz-oven', 'cz-fridge'])
        self.assertEqual(
            {row['id']: row['category'] for row in all_rows},
            {
                'cz-shoe': 'أحذية',
                'cz-oven': 'المنزل والمطبخ',
                'cz-fridge': 'المنزل والمطبخ',
            },
        )
        # Search uses the displayed/cleaned name too, so removing an affiliate stock tag
        # cannot make a phrase disappear from the precomputed substring index.
        target_id = manifest['categories']['أحذية'][0]
        target_chunk = manifest['product_chunks'][target_id]
        gram = 'ء ج'
        bucket = __import__('hashlib').md5(gram.encode('utf-8')).hexdigest()[0]
        with open(os.path.join(self.data, 'zz_cezma_index', manifest['search_files'][bucket]), encoding='utf-8') as stream:
            postings = json.load(stream)
        with open(os.path.join(self.data, 'zz_cezma_index', target_chunk), encoding='utf-8') as stream:
            self.assertIn('cz-shoe', [row['id'] for row in json.load(stream)])
        self.assertIn(target_id, postings[gram])

    def test_rebuild_is_deterministic_and_signature_changes_with_source(self):
        rows, signature = read_source_shards(self.data)
        changed = set()
        first = build(self.data, cezma_site_category, rows=rows, source_signature=signature,
                      changed_files=changed, chunk_size=2)
        changed.clear()
        build(self.data, cezma_site_category, rows=rows, source_signature=signature,
              changed_files=changed, chunk_size=2)
        self.assertEqual(changed, set())
        previous_index_signature = self._manifest()['signature']
        self.assertEqual(previous_index_signature, first['signature'])

        self.rows[0]['price'] = 301
        self._write_shard('lite_cezma_01.json', self.rows[:2])
        updated_rows, updated_signature = read_source_shards(self.data)
        self.assertNotEqual(signature, updated_signature)
        updated = build(self.data, cezma_site_category, rows=updated_rows, source_signature=updated_signature,
                        changed_files=changed, chunk_size=2)
        self.assertEqual(self._manifest()['source_signature'], updated_signature)
        self.assertEqual(self._manifest()['signature'], updated['signature'])
        self.assertNotEqual(previous_index_signature, updated['signature'])
        self.assertIn('data/zz_cezma_index/zz_manifest.json', changed)

    def test_index_uses_php_site_category_not_updater_repair_category(self):
        raw_category = 'أجهزة منزلية'
        name = 'مبخرة الشعر الترند'
        self.assertEqual(cezma_category(raw_category, name), 'العناية والتجميل')
        self.assertEqual(cezma_site_category(raw_category, name), 'المنزل والمطبخ')

        rows = [{'id': 'cz-incense', 'name': name, 'category': raw_category, 'images': []}]
        build(self.data, cezma_site_category, rows=rows, source_signature='a' * 64, chunk_size=2)
        manifest = self._manifest()
        self.assertEqual(manifest['category_counts']['المنزل والمطبخ'], 1)
        chunk_id = manifest['categories']['المنزل والمطبخ'][0]
        filename = manifest['product_chunks'][chunk_id]
        with open(os.path.join(self.data, 'zz_cezma_index', filename), encoding='utf-8') as stream:
            self.assertEqual(json.load(stream)[0]['category'], 'المنزل والمطبخ')
        repair_classifier = build(self.data, cezma_category, rows=rows, source_signature='a' * 64, chunk_size=2)
        self.assertNotEqual(manifest['signature'], repair_classifier['signature'])

    def test_subcategory_presence_is_declared_for_safe_filter_fallback(self):
        rows = [{'id': 'cz-subcat', 'name': 'منتج', 'category': 'أجهزة منزلية', 'subcategory': 'أجهزة'}]
        build(self.data, cezma_site_category, rows=rows, source_signature='b' * 64, chunk_size=2)
        self.assertTrue(self._manifest()['has_subcategories'])

    def test_missing_or_empty_source_shards_fail_closed(self):
        with tempfile.TemporaryDirectory() as empty_data:
            with self.assertRaises(FileNotFoundError):
                read_source_shards(empty_data)
            os.makedirs(os.path.join(empty_data, 'cezma_shards'))
            with self.assertRaisesRegex(ValueError, 'No Cezma source shards'):
                read_source_shards(empty_data)

    def test_bad_source_shard_fails_closed_instead_of_building_partial_index(self):
        path = os.path.join(self.data, 'cezma_shards', 'lite_cezma_03.json')
        with open(path, 'w', encoding='utf-8') as stream:
            stream.write('{broken')
        with self.assertRaises(json.JSONDecodeError):
            read_source_shards(self.data)


if __name__ == '__main__':
    unittest.main()
