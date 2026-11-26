# وفرنهالك أفلييت — Afflite

متجر أفلييت عربي (واجهة عربية RTL) يجمع منتجات أمازون وجوميا ونون، مع منتجات رقمية وخدمات سوشيال ميديا، وبرنامج مسوّقين بالعمولة، ولوحة تحكم للعملاء والمسوّقين والإدارة. الدفع عند الاستلام.

> **English summary:** Afflite is an Arabic (RTL) affiliate store built with plain PHP and JSON-file storage, with optional Supabase. It aggregates Amazon, Jumia and Noon products, plus digital products and social-media services, and includes a marketer/affiliate program, customer and marketer dashboards, and an admin area. Cash on delivery. See [docs/README.md](docs/README.md) for the full docs index.

## المميزات

- متجر منتجات فيزيائية (أمازون / جوميا / نون) مع بحث وفلترة حسب الدولة والتصنيف، وترتيب حسب المميز / الأحدث / الأكثر مبيعاً / الأكثر مشاهدة.
- منتجات رقمية وخدمات سوشيال ميديا في أوضاع عرض مستقلة.
- صفحات هبوط لكل منتج مع زر طلب سريع (Easy Order) والدفع عند الاستلام.
- برنامج مسوّقين: رابط إحالة خاص، متجر خاص بكل مسوّق، وعمولة مخصصة لكل منتج.
- حسابات عملاء: تسجيل دخول وتسجيل جديد (منها تسجيل بجوجل عند تفعيله)، مفضلة، سلة، تتبع الطلبات، ودعم مباشر.
- لوحة تحكم للمسوّق والإدارة: الطلبات، العمولات، المدفوعات، والتنبيهات.
- تحديث تلقائي للكتالوج عبر GitHub Actions (بوت الكتالوج)، ونسخ احتياطي للبيانات.

## التقنيات

- **PHP** بدون فريمورك (بدون Composer) — ملفات PHP مباشرة مع `config.php` المركزي.
- تخزين **JSON** في مجلد `data/` للطلبات والمستخدمين والكتالوج.
- **Supabase** (اختياري، عبر `USE_SUPABASE`) — انظر `supabase_schema.sql` و `supabase.php`.
- واجهة عربية RTL بخط Cairo، وملف `assets/style.css` و `assets/ui.js`.
- اختبارات PHP في `tests/` (تخزين ذرّي، تدفقات مالية، دورة حياة العمولة) واختبارات Python (`python3 -m unittest discover -s tests`).

## التشغيل محلياً

```bash
git clone https://github.com/ahmadassaam44-hue/afflite.git
cd afflite
php -S 127.0.0.1:8000
# ثم افتح http://127.0.0.1:8000
```

1. انسخ قائمة المفاتيح من `.env.example` وجهّز `secrets.php` (غير مرفوع على Git) بالقيم الحقيقية — لا تضع أي مفتاح سري داخل الكود أو في أي ملف مرفوع.
2. تأكد أن مجلد `data/` قابل للكتابة (755 أو 775) لأن الطلبات والمستخدمين يُحفظون فيه.
3. غيّر اسم المتجر من ثابت `BRAND` في `config.php`.
4. (اختياري) فعّل Supabase: طبّق `supabase_schema.sql` في مشروع Supabase، وعرّف مفاتيحه في `secrets.php`، وفعّل `USE_SUPABASE`.

## متغيرات البيئة / الأسرار

المفاتيح المطلوبة موثّقة في [`.env.example`](.env.example) بقيم وهمية فقط. التطبيق يقرؤها من `secrets.php` (مستثنى في `.gitignore`)، وليس من ملف `.env` مباشرة. أهمها:

| المفتاح | الاستخدام |
| --- | --- |
| `ADMIN_EMAIL` / `ADMIN_PASS` | حساب الإدارة |
| `SB_KEY` / `SB_SERVICE` | مفاتيح Supabase |
| `HEVO_API_KEY` | واجهة Hevo |
| `MAIL_FN_URL` / `MAIL_FN_SECRET` | إرسال الإيميل عبر Supabase Edge Function |
| `TG_BOT_TOKEN` / `TG_CHAT_ID` | تنبيهات تليجرام للإدارة (اختياري) |

راجع [SECURITY.md](SECURITY.md) قبل أي نشر.

## النشر على InfinityFree

1. أنشئ حساباً ودوميناً من لوحة InfinityFree.
2. ارفع محتويات هذا المستودع كاملة داخل `htdocs/` (وليس المجلد نفسه).
3. اجعل `data/` قابلاً للكتابة (755 أو 775).
4. سجّل حساباً جديداً من `register.php` — أول دخول يفتح لوحة التحكم.
5. ملاحظة: InfinityFree بلا cron حقيقي — حدّث ملفات `data/*.json` من جهازك وارفعها دورياً.

التفاصيل الكاملة في `README-infinityfree.txt` و [`docs/free-hosting-alternatives-2026-10-25.md`](docs/free-hosting-alternatives-2026-10-25.md).

## بنية المشروع

```text
index.php / landing.php / store.php   الصفحات الرئيسية (متجر، هبوط، منتج)
config.php                            إعدادات وثوابت (الأسرار في secrets.php فقط)
ui-components.php / ui-runtime.php    مكوّنات الواجهة المشتركة
assets/                               CSS/JS والخطوط والصور
dashboard/                            لوحات العميل والمسوّق والإدارة
data/                                 ملفات JSON (كتالوج وطلبات — الحساسة مستثناة من Git)
supabase*.php / supabase_schema.sql   تكامل Supabase
tools/ / updater/                     أدوات التحديث والصيانة
tests/                                اختبارات PHP و Python
docs/                                 ملاحظات تغييرات سابقة (فهرسها في docs/README.md)
.github/workflows/                    lint وفحص الموقع والنسخ الاحتياطي وتحديث الكتالوج
```

## سير العمل (Workflows)

- `lint.yml` — فحص `php -l` لكل ملفات PHP، وفحص JS، وتشغيل اختبارات PHP و Python.
- `check-site.yml` — فحص الموقع بعد النشر.
- `update-catalog.yml` — تحديث الكتالوج تلقائياً.
- `backup-data.yml` — نسخ احتياطي للبيانات.

## الاختبارات

```bash
php tests/storage_atomic.php
php tests/money_flows.php
python3 -m unittest discover -s tests -p 'test_*.py' -t .
```

## المساهمة

راجع [CONTRIBUTING.md](CONTRIBUTING.md) — التعديلات تكون في فرع جديد مع Pull Request إلى `main`، ولا يُدفع مباشرة إلى `main`.

## إفصاح الأفلييت

روابط المنتجات روابط أفلييت: قد يحصل المتجر على عمولة عند إتمام طلب من خلالها، دون تكلفة إضافية على المشتري. لا يوجد دخل مضمون من التسويق بالعمولة.

## الترخيص

MIT — راجع [LICENSE](LICENSE).
