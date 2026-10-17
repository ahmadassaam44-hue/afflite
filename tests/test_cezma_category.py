import unittest

from updater.update_catalog import CEZMA_CAT_RULES, CEZMA_HOME_NAME_TERMS, cezma_category


class CezmaCategoryTests(unittest.TestCase):
    def assert_category(self, source, name, expected):
        self.assertEqual(cezma_category(source, name), expected, f"{source!r}: {name!r}")

    def test_home_terms_do_not_promote_clothing_or_miss_malaah(self):
        home_terms = {term for category, terms in CEZMA_CAT_RULES if category == 'المنزل والمطبخ' for term in terms}
        women_terms = {term for category, terms in CEZMA_CAT_RULES if category == 'ملابس حريمي' for term in terms}
        self.assertIn('ملاءة', CEZMA_HOME_NAME_TERMS)
        self.assertIn('ملاءة', home_terms)
        self.assertNotIn('ملحفة', CEZMA_HOME_NAME_TERMS)
        self.assertNotIn('ملحفه', CEZMA_HOME_NAME_TERMS)
        self.assertNotIn('ملحفة', home_terms)
        self.assertNotIn('ملحفه', home_terms)
        self.assertIn('ملحفة', women_terms)
        self.assertIn('ملحفه', women_terms)
        self.assert_category('الموضة والجمال', 'ملحفة سعودى', 'ملابس حريمي')
        self.assert_category('أجهزة منزلية', 'ملحفة خليجي', 'ملابس حريمي')
        self.assert_category('أجهزة منزلية', 'ملحفة سرير قطن', 'المنزل والمطبخ')
        self.assert_category('الموضة والجمال', 'ملاءة سرير قطن', 'المنزل والمطبخ')

    def test_exact_appliance_terms_avoid_clothing_substrings(self):
        self.assert_category('الموضة والجمال', 'بوي فرند بلوزة', 'ملابس حريمي')
        self.assert_category('الموضة والجمال', 'فرنسس طقم حريمي', 'ملابس حريمي')
        self.assert_category('الموضة والجمال', 'فرن كهربائي صغير', 'المنزل والمطبخ')

    def test_ambiguous_heaters_and_fans_use_context(self):
        cases = [
            ('دفاية كهربائية 2000 وات', 'المنزل والمطبخ'),
            ('دفاية حريمي قطيفة', 'ملابس حريمي'),
            ('دفاية رجالي شتوي', 'ملابس رجالي'),
            ('مروحة رقبة محمولة', 'إلكترونيات'),
            ('مروحه ميني محموله باستاند', 'إلكترونيات'),
            ('كاب مروحه بالطاقة الشمسية', 'إلكترونيات'),
            ('مروحة شفاط مطبخ', 'المنزل والمطبخ'),
            ('كم مروحة دريس حريمي', 'ملابس حريمي'),
            ('الخامة بتدفي ومروحه طقم بونشو ريب 3 قطع', 'ملابس حريمي'),
        ]
        for name, expected in cases:
            with self.subTest(name=name):
                self.assert_category('الموضة والجمال', name, expected)

    def test_beauty_items_with_home_word_overlap(self):
        cases = [
            ('مبخرة الشعر الترند', 'العناية والتجميل'),
            ('مبخرة كهربائية', 'المنزل والمطبخ'),
            ('قناع الوجه الحراري', 'العناية والتجميل'),
            ('صابونة على شكل ترمس', 'العناية والتجميل'),
            ('حلة pro wax100', 'العناية والتجميل'),
            ('غساله فرش', 'العناية والتجميل'),
            ('مجموعة لوجو الصينيه للشعر', 'العناية والتجميل'),
            ('اسكراب بطمعه وساده', 'العناية والتجميل'),
            ('زيت 212 سكسي رجالي فواحه', 'العناية والتجميل'),
            ('منظم المكياج الاكريليك', 'المنزل والمطبخ'),
        ]
        for name, expected in cases:
            with self.subTest(name=name):
                self.assert_category('الموضة والجمال', name, expected)

    def test_kitchen_appliances_and_accessories_stay_distinct(self):
        cases = [
            ('ثلاجة بشاشة وUSB', 'المنزل والمطبخ'),
            ('ماكينة اسبريسو بشاشة USB', 'المنزل والمطبخ'),
            ('سخان يونيون اير كهربا 50 لتر', 'المنزل والمطبخ'),
            ('ولاعة غاز سخان', 'إكسسوارات'),
            ('ولاعة مطبخ', 'المنزل والمطبخ'),
            ('ولاعة بخور', 'المنزل والمطبخ'),
            ('مسدس تحمير الطعام وولاعة 2*1', 'المنزل والمطبخ'),
            ('ولاعة USB', 'إكسسوارات'),
            ('طقم شوكر', 'إكسسوارات'),
            ('طقم شوك', 'المنزل والمطبخ'),
        ]
        for name, expected in cases:
            with self.subTest(name=name):
                self.assert_category('أجهزة منزلية' if name.startswith('ولاعة غاز') else 'ادوات ومستلزمات المنزل والحديقة', name, expected)


if __name__ == '__main__':
    unittest.main()
