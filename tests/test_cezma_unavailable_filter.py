import json
import os
import tempfile
import unittest
from unittest.mock import patch

from updater import update_catalog


class CezmaUnavailableFilterTests(unittest.TestCase):
    def test_filter_removes_notice_in_any_nested_text_and_keeps_other_products(self):
        with tempfile.TemporaryDirectory() as data_dir:
            shard_dir = os.path.join(data_dir, 'cezma_shards')
            os.makedirs(shard_dir)
            shard_path = os.path.join(shard_dir, 'lite_cezma_01.json')
            rows = [
                {'id': 'cz-1', 'name': 'منتج سليم', 'extra': {'store_name': 'متجر'}},
                {'id': 'cz-2', 'name': 'هذا المنتج غير متوفر في الوقت الحالي'},
                {'id': 'cz-3', 'name': 'عنصر ثالث', 'extra': {'description': 'هذا المنتج\nغير متوفر في الوقت الحالي'}},
            ]
            with open(shard_path, 'w', encoding='utf-8') as stream:
                json.dump(rows, stream, ensure_ascii=False)

            with patch.object(update_catalog, 'DATA', data_dir), \
                 patch.object(update_catalog, 'changed_files', set()), \
                 patch.object(update_catalog, 'summary', {}):
                removed = update_catalog.remove_cezma_unavailable_notice_products()
                kept = update_catalog._cezma_load_shards()

        self.assertEqual(removed, 2)
        self.assertEqual([row['id'] for row in kept], ['cz-1'])

    def test_phrase_detection_handles_html_and_spacing(self):
        self.assertTrue(update_catalog._cezma_contains_unavailable_notice(
            {'description': '<p>هذا المنتج غير متوفر في الوقت الحالي</p>'}
        ))
        self.assertFalse(update_catalog._cezma_contains_unavailable_notice(
            {'name': 'منتج متوفر وجاهز للشحن'}
        ))

    def test_known_unavailable_id_is_excluded_without_name_based_deduplication(self):
        self.assertTrue(update_catalog._cezma_is_unavailable_record(
            {'id': 'cezma-337114', 'name': 'جاكت الترند الجديد'}
        ))
        self.assertFalse(update_catalog._cezma_is_unavailable_record(
            {'id': 'cezma-123456', 'name': 'جاكت الترند الجديد'}
        ))
        self.assertFalse(hasattr(update_catalog, 'deduplicate_catalog_names'))

    def test_price_placeholder_removes_current_row_and_persists_its_id(self):
        with tempfile.TemporaryDirectory() as data_dir:
            shard_dir = os.path.join(data_dir, 'cezma_shards')
            os.makedirs(shard_dir)
            shard_path = os.path.join(shard_dir, 'lite_cezma_01.json')
            row = {'id': 'cezma-99123', 'orig_id': '99123', 'name': 'منتج', 'price_text': 'هذا المنتج غير متوفر في الوقت الحالي'}
            with open(shard_path, 'w', encoding='utf-8') as stream:
                json.dump([row], stream, ensure_ascii=False)
            with (
                patch.object(update_catalog, 'DATA', data_dir),
                patch.object(update_catalog, 'changed_files', set()),
                patch.object(update_catalog, 'summary', {}),
                patch.object(update_catalog, '_CEZMA_UNAVAILABLE_IDS_CACHE', None),
            ):
                removed = update_catalog.remove_cezma_unavailable_notice_products()
                with open(os.path.join(data_dir, 'cezma_unavailable.json'), encoding='utf-8') as stream:
                    saved_ids = json.load(stream)
                remaining_shards = [name for name in os.listdir(shard_dir) if name.endswith('.json')]
                kept = []
                for name in remaining_shards:
                    with open(os.path.join(shard_dir, name), encoding='utf-8') as stream:
                        kept.extend(json.load(stream))
        self.assertEqual(removed, 1)
        self.assertIn('99123', saved_ids)
        self.assertEqual(kept, [])

    def test_update_cezma_removes_existing_product_when_price_field_is_notice(self):
        with tempfile.TemporaryDirectory() as data_dir:
            shard_dir = os.path.join(data_dir, 'cezma_shards')
            os.makedirs(shard_dir)
            shard_path = os.path.join(shard_dir, 'lite_cezma_01.json')
            with open(shard_path, 'w', encoding='utf-8') as stream:
                json.dump([{'id': 'cezma-99124', 'orig_id': '99124', 'name': 'منتج', 'price': 400}], stream)
            with (
                patch.object(update_catalog, 'DATA', data_dir),
                patch.object(update_catalog, 'changed_files', set()),
                patch.object(update_catalog, 'summary', {}),
                patch.object(update_catalog, 'CEZMA_DELETE_MAIN', set()),
                patch.object(update_catalog, '_CEZMA_UNAVAILABLE_IDS_CACHE', None),
                patch.object(update_catalog, '_cezma_get', return_value={'data': [{'id': 9999, 'name_ar': 'قسم'}]}),
                patch.object(update_catalog, '_cezma_scan_cat', return_value=[{
                    'id': 99124, 'price': 'هذا المنتج غير متوفر في الوقت الحالي', 'name_ar': 'منتج'
                }]),
            ):
                update_catalog.update_cezma()
                with open(os.path.join(data_dir, 'cezma_unavailable.json'), encoding='utf-8') as stream:
                    saved_ids = json.load(stream)
                remaining = []
                for name in os.listdir(shard_dir):
                    if name.endswith('.json'):
                        with open(os.path.join(shard_dir, name), encoding='utf-8') as stream:
                            remaining.extend(json.load(stream))
        self.assertIn('99124', saved_ids)
        self.assertEqual(remaining, [])

    def test_new_catalog_rows_keep_colors_types_sizes_variants_and_gallery(self):
        with tempfile.TemporaryDirectory() as data_dir:
            os.makedirs(os.path.join(data_dir, 'cezma_shards'))
            product = {
                'id': 990001, 'name_ar': 'جاكيت', 'price': 420,
                'image': {'url': 'https://images.example/main.jpg'},
                'images': [{'url': 'https://images.example/side.jpg'}],
                'attributes': [
                    {'parent': {'name_ar': 'اللون'}, 'value': {'name_ar': 'أسود'}},
                    {'parent': {'name_ar': 'المقاس'}, 'value': 'XL'},
                    {'parent': {'name_ar': 'النوع'}, 'value': 'جاكيت'},
                ],
                'variants': [{'color': 'أسود', 'size': 'XL', 'price': 420,
                              'image': {'url': 'https://images.example/black.jpg'}}],
            }
            with (
                patch.object(update_catalog, 'DATA', data_dir),
                patch.object(update_catalog, 'changed_files', set()),
                patch.object(update_catalog, 'summary', {}),
                patch.object(update_catalog, 'CEZMA_DELETE_MAIN', set()),
                patch.object(update_catalog, '_CEZMA_UNAVAILABLE_IDS_CACHE', None),
                patch.object(update_catalog, '_cezma_get', return_value={'data': [{'id': 9999, 'name_ar': 'قسم'}]}),
                patch.object(update_catalog, '_cezma_scan_cat', return_value=[product]),
            ):
                update_catalog.update_cezma()
                rows = update_catalog._cezma_load_shards()
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['sizes'], ['XL'])
        self.assertEqual(rows[0]['extra']['colors'], ['أسود'])
        self.assertEqual(rows[0]['extra']['types'], ['جاكيت'])
        self.assertIn('https://images.example/side.jpg', rows[0]['images'])
        self.assertEqual(rows[0]['extra']['variants_detailed'][0]['size'], 'XL')

    def test_extracts_api_option_attributes_and_variant_gallery(self):
        product = {
            'attributes': [
                {'parent': {'name_ar': 'اللون'}, 'value': {'name_ar': 'أسود'}},
                {'parent': {'name_ar': 'المقاس'}, 'value': 'XL'},
                {'parent': {'name_ar': 'النوع'}, 'value': 'جاكيت'},
            ],
            'variants': [{'color': 'أسود', 'size': 'XL', 'price': 400,
                          'image': {'url': 'https://images.example/black.jpg'}}],
        }
        options = update_catalog._cezma_extract_options(product)
        self.assertEqual(options['colors'], ['أسود'])
        self.assertEqual(options['sizes'], ['XL'])
        self.assertEqual(options['types'], ['جاكيت'])
        self.assertEqual(options['variants'][0]['image'], 'https://images.example/black.jpg')


if __name__ == '__main__':
    unittest.main()
