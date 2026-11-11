# حذف منتجات Shopmart — 2026-11-11

## اللي اتحذف
| الملف | قبل | بعد |
|---|---|---|
| `data/shopmart.json` | 252 منتج | `[]` |
| `data/lite_shopmart.json` | 252 منتج | `[]` |
| `data/id_index.json` | 252 مفتاح مربوط بـ shopmart | اتشالوا |
| `data/visual_features.json` | 250 item + 2 bad | اتشالوا |
| `data/price_history.json` | 174 مفتاح | اتشالوا |
| `data/shipping.json` | جدول شحن Shopmart | اتشال |
| `data/catalog_counts.json` | 2436 منتج بصورة | **2184** |

## إيقاف السحب من المحدّث
- `updater/update_catalog.py`: `shopmart` / `shopmart_shipping` / `shopmart_probe` اتشالوا من قائمة
  التشغيل الافتراضية (اللي بيشغّلها الـ cron كل 12 ساعة)، ومن خطوات التشغيل نفسها.
- `.github/workflows/update-catalog.yml`: وصف المصادر اتحدّث.

## حماية في الكود (مهمة)
```php
// config.php (أول الملف)
if (!defined('WF_DISABLED_PLATFORMS')) define('WF_DISABLED_PLATFORMS', ['shopmart']);
```
`wf_platform_disabled()` بتُستخدم في:
`platform_products()`, `platform_products_lite()`, `wf_catalog_find_record()`, `wf_catalog_plats()`.

يعني حتى لو المحدّث رجّع ملفات Shopmart تاني (تشغيل مجدول من فرع `main` قبل دمج الـ PR)،
المنتجات مش هتظهر في الموقع ولا في البحث ولا في صفحات المنتج.

**لإرجاع Shopmart:** شيل `'shopmart'` من `WF_DISABLED_PLATFORMS` وارجع الاسم لقائمة المحدّث،
والمنتجات هترجع مع أول تشغيل للكتالوج.

## الواجهات
- فلتر المنصات في لوحة المسوق (`dashboard/marketer.php`) وفي لوحة الأدمن (`dashboard/admin.php`).
- جدول الشحن في ديالوج الطلب السريع (`config.php` → `render_easyorder_dialog_html`).

## التحقق بعد الرفع (الموقع المباشر)
- `/` = 200، `/login.php` = 200، `/dashboard/cart.php` = 200، مفيش أي `PHP error`.
- عدد مرات ظهور كلمة shopmart في صفحات الموقع = **0**، وصور `aff-shopmart` = **0**.
- الملفات على السيرفر مطابقة بالـ MD5 (التقرير في `data/_code_deploy.json`).
