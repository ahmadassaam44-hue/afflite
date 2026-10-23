# التحديث المباشر لرسوم الشحن (SB_SERVICE / Supabase) — 2026-10-23

## الواقع قبل الإصلاح
- `SB_SERVICE` (مفتاح service_role) بيتحط في `secrets.php` على الاستضافة (مش في Git).
- الكتابة على Supabase كانت شغالة لو SB_SERVICE مضبوط، لكن **القراءة من Supabase مقفولة بعلم `USE_SUPABASE` المتعرّف false بشكل ثابت في config.php** (قرار سرعة: المحلي <30ms) — فتنشيط «التحديث المباشر» من Seite الإدارة كان ناقص خطوة مش موضحة.
- وقراءة Supabase (لو اتفعّلت) كانت **بتتجاهل عمود الدولة** — جداول الدول (يدوي الخليج/مخزون) كانت هتتلخبط مع بعضها.

## الإصلاح
1. **`USE_SUPABASE` بقى قابل للتفعيل من secrets.php:** `if (!defined('USE_SUPABASE')) define('USE_SUPABASE', false);` — الافتراضي محلي (الأسرع)، وصاحب الموقع يقدر يكتب `define('USE_SUPABASE', true);` في secrets.php لو عايز القراءة المباشرة.
2. **قراءة Supabase بقَت بلد-واعية** (supabase.php): الـselect بيجيب عمود `country` والتجميع بمنصة+دولة، وكل جدول بيحتفظ بدولته — مفيش خلط رسوم بين الدول.
3. **صفحة إدارة الشحن بقَت صادقة وواضحة** (dashboard/admin_shipping.php): بتقول الوضع الفعلي (محلي / مرآة Supabase متزامنة / قراءة مباشرة) والخطوة المطلوبة بالظبط في كل وضع، ورسائل الحفظ بقت دقيقة («الموقع محدّث فورًا» بدل الوعود المبالغ فيها).

## لإعداد التحديث المباشر على الاستضافة (خطوة يدوية واحدة في secrets.php)
```php
define('SB_SERVICE', 'مفتاح service_role من Supabase → Project Settings → API');
define('USE_SUPABASE', true);   // اختياري: لو عايز الموقع يقرأ من Supabase مباشرة بدل الملف المحلي
```
ملحوظة: حتى من غير USE_SUPABASE، أي حفظ رسوم بيحدّث الموقع فورًا (الملف المحلي على نفس الاستضافة) — SB_SERVICE بيضيف نسخة Supabase المتزامنة.

## الاختبارات
`node tests/ui/manual-country-currency.cjs` — **507 تأكيد، 0 فشل** (+3: القراءة المحلية افتراضيًا/قراءة Supabase مقفولة/الرجوع للملف المحلي) + wiring (عمود الدولة، التجميع بلد-واعي، العلم القابل للتفعيل، وصدق صفحة الإدارة).

## ملفات
`supabase.php` · `config.php` · `dashboard/admin_shipping.php` · `tests/ui/manual-country-currency.cjs`
