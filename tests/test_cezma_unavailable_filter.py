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


if __name__ == '__main__':
    unittest.main()
