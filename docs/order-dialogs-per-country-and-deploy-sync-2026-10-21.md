# كل نوافذ الطلب حسب دولة المنتج + مزامنة ملفات البيانات للنشر — 2026-10-21

متابعة لـ`docs/stock-shipping-by-country-and-product-fee-2026-10-20.md`. بعد الدمج وصلت لقطتان:
منتج إماراتي/سعودي بتظهر له محافظات مصر، أو أسماء الدول نفسها كمناطق. الفحص بيّن ثلاث فجوات
مختلفة، وكلهم اتصلحوا هنا.

## 1) أزرار بتفتح النافذة من غير ما تبعت دولة المنتج

نافذة الطلب نفسها سليمة، بس مش كل اللي بيفتحها كان بيمرّر `country`/`ship_fee` — فالواجهة
كانت بتقع على جدول مصر الافتراضي:

| المكان | المشكلة | الإصلاح |
| --- | --- | --- |
| `dashboard/product.php` (زر «اطلب الآن» في صفحة التفاصيل + `openProductCheckoutDialog` في `assets/ui.js`) | الـpayload مبني من `wfProductState` من غير `country`/`ship_fee` | `country:t.country,ship_fee:t.ship_fee` في نداء `openEasyOrderModal` |
| `dashboard/product.php` (كروت «منتجات ذات صلة») | الـpayload فيه id/plat/name/price بس | `"currency"` + `"country"` + `"ship_fee"` |
| `dashboard/marketer.php` (زر «طلب لعميل» في جدول المتجر) | `$eo_p` فيه `currency` من غير دولة | `'country'` + `'ship_fee'` |
| `dashboard/marketer.php` (كروت كتالوج المسوق ⇒ `mkCardOrderNow`) | `$mk_ui_states` من غير دولة | `'country'` + `'ship_fee'` |

الأزرار اللي كانت سليمة من قبل: كروت الرئيسية/المتجر (`index.php`/`store.php`) وصفحة الهبوط
(`landing.php`) — بتبعت `country` و`ship_fee` من دمج 2026-10-20.

## 2) نص «المحافظة» في قائمة المدينة/المركز كان ثابت

- `config.php` (نافذة الطلب): الـplaceholder الأولي بقت `-- اختر المنطقة أولاً --` لما دولة الصفحة
  الافتتاحية خليجية، و`-- اختر المحافظة أولاً --` لمصر.
- `landing.php` (الفورم المدمج): نفس الشيء حسب `$lp_country`، و`lpInlineGov` بقى عليه
  `data-shipping-country` عشان الـJS يعرف الدولة.
- `assets/ui.js` ⇒ `wfFillCityList`: بيقرأ `data-shippingCountry`/`data-country` من قائمة المناطق
  ويكتب الكلمة الصح («المنطقة»/«المحافظة») في الـplaceholder، وبيعيد البناء لما الكلمة تتغير
  (`s.dataset.wordFor`) — عشان التبديل من مصر لدولة خليجية (والعكس) يحدّث النص فوراً.

## 3) باج النشر: `data/shipping_cities_gulf.json` كان ساقط من قائمة الرفع

`site_deploy_files()` في `updater/update_catalog.py` بتحدد الملفات اللي بترفع FTP لكل ران.
`data/shipping.json` و`data/shipping_cities_shopmart.json` فيها، بس **ملف مدن الخليج اللي ضافه
دمج 2026-10-20 مش فيها** ⇒ النشر التلقائي عمره ما كان هيرفعه ⇒ قوايم مدن الخليج المنسدلة
مستحيلة تشتغل على الاستضافة، وحقل المدينة بيرجع وضع الكتابة الحرة.

كمان: لقطة «السعودية (الشحن: 25 ر.س) كمنطقة» معناها إن `data/shipping.json` **على الاستضافة**
لسه النسخة القديمة (جدول مخزون الواحد بأسماء الدول) — الكود الجديد بيقرأ الملف ده وقت التشغيل،
فأي تأخير في مزامنته بيظهر كسلوك قديم رغم إن الكود محدّث. الإجراء على الاستضافة: رفع
`data/shipping.json` و`data/shipping_cities_gulf.json` من `main` (FTP على `/htdocs/data/`)
أو استنى ران نشر كامل بعد الدمج ده واتأكد إن تقرير الرفع (`data/_deploy_report.json`) كاتب
إن الملفين `match: true`.

الإصلاح هنا: `'data/shipping_cities_gulf.json'` ضافت لقائمة `site_deploy_files()` + فحص رجعة في
`tests/ui/manual-country-currency.cjs` يتأكد إن الملف جوه القائمة (عشان ملفات البيانات ماتتخلفش
عن الكود تاني من غير ما نلاحظ).

## التحقق

```bash
npm install --prefix .cache/ui-tools @php-wasm/node php-parser
node tests/ui/manual-country-currency.cjs   # 307 assertion — 0 فشل
```

فحوص الربط الجديدة: كل باني payload لنوافذ الطلب (صفحة التفاصيل/ذات صلة/متجر المسوق/كروت المسوق/
الهبوط) بيمرّر `country` و`ship_fee`، و`openProductCheckoutDialog` بيمرّرهم من `wfProductState`،
وplaceholder قائمة المدينة بيتبدّل حسب الدولة في `config.php` و`landing.php` و`wfFillCityList`،
و`site_deploy_files()` فيها ملف مدن الخليج. وعلى مستوى PHP: النافذة المرسومة لمنتج سعودي فيها
`-- اختر المنطقة أولاً --` ومفيهاش `-- اختر المحافظة أولاً --`.

## المتوقع على الاستضافة بعد مزامنة الملفين

- منتج سعودي (مخزون أو يدوي) من **أي** زر/صفحة ⇒ 13 منطقة سعودية (الرياض…الجوف) برسوم ر.س،
  واختيار منطقة ⇒ قائمة مدن منسدلة (الرياض ⇒ الرياض، الخرج، الدرعية…).
- منتج إماراتي ⇒ 7 مناطق بـ25 د.إ (مخزون) أو 0 د.إ (يدوي لحد ما تتملّى)، وهكذا الأردن/عُمان.
- منتج مصر ⇒ 27 محافظة بالجنيه وقائمة مدن المحافظة، وكل النصوص «المحافظة».
