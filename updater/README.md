# 🤖 التحديث الأوتوماتيكي للكتالوج (GitHub Actions)

InfinityFree مفيهوش cron، فالتحديث بيشتغل من **GitHub Actions مجاناً** كل 6 ساعات ويرفع الملفات المتغيرة على الاستضافة بـ FTP.

## الإعداد (مرة واحدة — 5 دقائق)
1. ارفع المشروع على GitHub (Private):
   ```bash
   cd m5zoon-clone
   git init && git add . && git commit -m "init"
   git remote add origin https://github.com/USER/REPO.git
   git push -u origin main
   ```
   > `secrets.php` و `data/users.json` و `orders.json` إلخ متجاهَلين تلقائياً في `.gitignore`.
2. من الريبو: **Settings → Secrets and variables → Actions → New repository secret** وضيف:
   | Secret | القيمة |
   |---|---|
   | `FTP_HOST` | `ftpupload.net` |
   | `FTP_USER` | `if0_42981380` |
   | `FTP_PASS` | باسورد الـ FTP |
   | `SAFQA_API_KEY` | مفتاح Safqa العام |
   | `VENDOOR_USER`, `VENDOOR_PASS` | حساب المسوّق في Vendoor |
   | `M5ZOON_USER`, `M5ZOON_PASS` | حساب المسوّق في M5zoon |
   | `ANGAZNY_USER`, `ANGAZNY_PASS` | حساب المسوّق في Angazny |
   | `TIMEHAND_USER`, `TIMEHAND_PASS` | حساب المسوّق في Timehand |
3. (اختياري) **Variables** → `USD_EGP` = سعر الدولار (الافتراضي 56).
4. **Actions → تحديث الكتالوج والأسعار → Run workflow** لتشغيل أول مرة يدوياً.

## إيه اللي بيتحدّث
| المصدر | الطريقة | التحديث |
|---|---|---|
| **HevoTeam (سوشيال)** | صفحة الخدمات العامة | الأسعار، الحد الأدنى/الأقصى، خدمات جديدة (تُضاف بشارة ✨ جديد)، الخدمات المحذوفة تتعلّم `stock=0` |
| **G2G (رقمي)** | API لكل عرض `sls.g2g.com/offer/{id}` | سعر كل عرض بالدولار × سعر الصرف، العروض الخالصة `sold out` |
| **Vendoor** | تسجيل دخول + صفحات المنتجات | `VENDOOR_USER` / `VENDOOR_PASS` — يجلب الأسعار والمخزون والمنتجات الجديدة |
| **Safqa** | Public API الرسمي | `SAFQA_API_KEY` — يجلب الأسعار والمخزون والمنتجات الجديدة |
| **M5zoon** | تسجيل دخول + صفحات الكتالوج | `M5ZOON_USER` / `M5ZOON_PASS` — يقرأ التكلفة والربح والتوفر تلقائياً |
| **Angazny** | تسجيل دخول + صفحات المنتجات | `ANGAZNY_USER` / `ANGAZNY_PASS` — يحسب سعر العرض = السعر − العمولة ويحدّث المخزون |
| **Timehand** | تسجيل دخول AJAX + صفحات أحدث المنتجات والتصنيفات | `TIMEHAND_USER` / `TIMEHAND_PASS` — يحدّث العمولة والمخزون والأسعار ويضيف المنتجات الجديدة |
| **مصادر JSON إضافية** | `updater/sources.json` | اختياري للمنصات التي توفر رابط تصدير JSON |

قواعد ثابتة: خدمة `6489` لا تُلمس، السعر يقرّب لأقرب 0.5/1.0، والإضافة الثابتة 0.10 ج.م بقت للخدمات الأرخص منها بس (أي مبلغ أقل من 10 قروش بيتضاف عليه).

## اللي بيظهر على الموقع تلقائياً
- 🔻 **السعر نزل X%** لمدة 14 يوم بعد أي انخفاض (من `price_history.json`)
- ✨ **جديد** لمدة 7 أيام على أي منتج مضاف
- **نفذت الكمية** للمنتجات الخالصة، وتُخفى من العرض بعد 3 أيام
- 🕒 **آخر تحديث للسعر** في صفحة المنتج
- الأدمن → الإعدادات: وقت آخر تشغيل وملخّص النتائج

## تشغيل محلي
```bash
python3 updater/update_catalog.py            # الكل
python3 updater/update_catalog.py hevo       # مصدر واحد
FTP_HOST=ftpupload.net FTP_USER=… FTP_PASS=… python3 updater/update_catalog.py   # مع الرفع
```

## Hugging Face؟
Spaces مش مصمّمة للجدولة (بتنام بعد 48 ساعة بدون زيارات، ومفيش cron). GitHub Actions هو الأنسب ومجاني (2000 دقيقة/شهر — التشغيلة بتاخد ~3 دقائق).
