import json
import os
import tempfile
import unittest
from unittest.mock import patch

from updater import update_catalog


class CatalogCountSummaryTests(unittest.TestCase):
    def test_summary_counts_image_visible_physical_products_and_cezma_categories(self):
        with tempfile.TemporaryDirectory() as data_dir:
            fixtures = {
                'lite_timehand.json': [
                    {'id': 'th-1', 'category': 'أحذية', 'images': ['https://img.example/product.jpg']},
                    {'id': 'th-2', 'images': ['https://img.example/no-image.png']},
                    {'id': 'th-3', 'images': []},
                ],
                'lite_safqa.json': [
                    {'id': 'sf-1', 'images': [], 'extra': {'detail_images': ['assets/detail/item.jpg']}},
                ],
                'lite_vendoor.json': [],
                'lite_angazny.json': [{'id': 'az-1', 'images': []}],
                'lite_shopmart.json': [],
                'lite_mnfean.json': [
                    {'id': 'mn-1', 'category': 'أجهزة منزلية'},
                ],
                'detail_index.json': {'angazny#az-1': ['assets/detail/angazny.jpg']},
            }
            for name, rows in fixtures.items():
                with open(os.path.join(data_dir, name), 'w', encoding='utf-8') as stream:
                    json.dump(rows, stream)
            with patch.object(update_catalog, 'DATA', data_dir):
                counts = update_catalog.build_catalog_counts()

        # السلوك الحالي الموثّق في build_catalog_counts: منصات فيزيائية + mnfean/storedooo
        # (شظايا cezma القديمة لم تعد تدخل في العدّ).
        self.assertEqual(counts['physical_platforms'], {
            'timehand': 1,
            'safqa': 1,
            'vendoor': 0,
            'angazny': 1,
            'shopmart': 0,
            'mnfean': 0,
        })
        self.assertEqual(counts['physical_with_images'], 3)
        self.assertEqual(counts['physical_categories'], {'أحذية': 1})
        self.assertEqual(counts['mnfean_categories'], {'أجهزة منزلية': 1})


if __name__ == '__main__':
    unittest.main()
