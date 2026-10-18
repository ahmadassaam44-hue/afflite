import json
import os
import tempfile
import unittest
from unittest.mock import patch

from updater import update_catalog


class CatalogNameDedupTests(unittest.TestCase):
    def test_name_key_normalizes_case_arabic_diacritics_and_punctuation(self):
        self.assertEqual(
            update_catalog._product_name_dedupe_key('  NEW—Balance  '),
            update_catalog._product_name_dedupe_key('new balance'),
        )
        self.assertEqual(
            update_catalog._product_name_dedupe_key('إِكْسِسْوَار ـ ذهبي!'),
            update_catalog._product_name_dedupe_key('اكسسوار ذهبي'),
        )

    def test_dedup_keeps_one_best_name_across_platforms(self):
        with tempfile.TemporaryDirectory() as data_dir:
            feeds = {
                'vendoor.json': [
                    {'id': 'v-eg', 'name': 'ساعة ذكية', 'country': 'مصر', 'price': 500,
                     'stock': 1, 'images': ['https://img.example/watch.jpg'], 'desc': 'مواصفات المنتج' * 5},
                    {'id': 'v-copy', 'name': 'ساعة ذكية', 'country': 'مصر', 'price': 700,
                     'stock': 1, 'images': ['https://img.example/watch-copy.jpg']},
                ],
                'm5zoon.json': [
                    {'id': 'm-ae', 'name': 'ساعة ذكية', 'country': 'الإمارات', 'price': 100,
                     'stock': 1, 'images': ['https://img.example/watch-ae.jpg'], 'desc': 'عرض خليجي'},
                ],
            }
            for name, rows in feeds.items():
                with open(os.path.join(data_dir, name), 'w', encoding='utf-8') as stream:
                    json.dump(rows, stream, ensure_ascii=False)

            with patch.object(update_catalog, 'DATA', data_dir), \
                 patch.object(update_catalog, 'changed_files', set()), \
                 patch.object(update_catalog, 'summary', {}):
                removed = update_catalog.deduplicate_catalog_names()
                kept_vendoor = update_catalog.jload('vendoor.json', [])
                kept_m5zoon = update_catalog.jload('m5zoon.json', [])

        self.assertEqual(removed, 2)
        self.assertEqual([row['id'] for row in kept_vendoor], ['v-eg'])
        self.assertEqual(kept_m5zoon, [])


if __name__ == '__main__':
    unittest.main()
