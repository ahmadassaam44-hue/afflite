# بدائل InfinityFree لنقل الموقع عليه (أكتوبر 2026)

الغرض: اختيار استضافة مجانية تشيل حجم المشروع الحالي (كتالوج ~46,667 منتج + ~60MB بيانات JSON + ~28MB أصول،
أكبر ملف بيانات 8.1MB) وتستحمل عدد الزيارات، مع **cron حقيقي** و**صلاحيات كتابة** على `data/`.

## 1) الترشيح السريع

| # | الاستضافة | ليه مناسبة لينا | أهم قيد لازم تعرفه |
|---|-----------|-----------------|---------------------|
| 1 | **Serv00** | أقرب حاجة لـ «سيرفر حقيقي» مجاني: SSH + Cron + MySQL 8 + PostgreSQL + 3GB SSD + ترانسفير غير محدود + PHP 8.3 و`.user.ini` لرفع `memory_limit` | الحساب بيتلغي لو مفيش دخول (Panel أو SSH) **مرة كل 90 يوم**، والرام للحساب 512MB |
| 2 | **Oracle Cloud Always Free** | 2 OCPU + 12GB RAM + 200GB تخزين + 10TB ترانسفير، تحكّم كامل (nginx + PHP-FPM + MySQL + cron) | محتاج كارت + تليفون للتحقق، والحد نُصّ من 4/24 إلى 2/12 في يونيو 2026 |
| 3 | **Byet.host** | أقرب بديل مباشر لـ InfinityFree (نفس عائلة iFastNet): 5GB NVMe + PHP 8.3 + MySQL 8/MariaDB + cron + FTP + دومين مجاني | **10MB كحد أقصى للملف الواحد**، مفيش SSH، وتعليق الحساب عند تجاوز CPU |
| 4 | **GoogieHost** | DirectAdmin + cron + FTP + PHP حتى 8.5 + MySQL + SSL مجاني، واجهة أقرب لـ cPanel | 1GB NVMe بس (مكاننا ~90MB فماشي)، وتقارير عن بطء/تقلب |

**الخلاصة:** لو عايز «ارفع الكود زي ما هو + كرون» → **Serv00**. لو عايز أقصى تحكّم وعندك كارت → **Oracle Cloud**.
لو عايز أسهل بديل بنفس شكل InfinityFree → **Byet.host**.

## 2) مقارنة استضافات PHP المشتركة المجانية

| الاستضافة | مساحة | ترانسفير | قواعد بيانات | PHP | Cron | SSH | أقصى ملف | ملاحظات |
|-----------|--------|-----------|--------------|-----|------|-----|-----------|----------|
| InfinityFree (الحالي) | 5GB / 30,000 inode | «غير محدود» مع سقف ~50 ألف زيارة/يوم | MySQL 400 | 8.1–8.3 | ❌ | ❌ | ~10MB | مفيش `mail()`، أقصى تنفيذ قصير |
| Serv00 | 3GB SSD | غير محدود | MySQL 8 ×10، PostgreSQL ×3، MongoDB ×3 | 5.6–8.5 (افتراضي 8.3) | ✅ | ✅ | بدون حد صناعي | 512MB رام، 15 عملية، 100 موقع |
| Byet.host | 5GB NVMe | غير محدود (fair use) | MySQL 8/MariaDB 11.4 (غير محدود) | 8.3 | ✅ (VistaPanel) | ❌ | 10MB | نفس منظومة iFastNet؛ تعليق على CPU |
| GoogieHost | 1GB NVMe | ~100GB | MySQL | 7.1–8.5 | ✅ | بالطلب | — | LiteSpeed + Cloudflare |
| WebHostMost | 125MB NVMe | غير محدود | MySQL ×1 | حتى 8.3 | ✅ | ❌ | — | فير جن وCRON، بس 125MB ضيقة علينا |
| AwardSpace | 1GB | 5GB/شهر | MySQL ×1 | 7.x بس ⚠️ | ❌ | ❌ | — | PHP قديم = مشكلة |
| Freehostia | 250MB | 6GB/شهر | محدودة | 8.x | ❌ | ❌ | 2MB | مساحة وترانسفير قليلين |
| 000webhost | 300MB | 3GB | MySQL | محدود | ❌ | ❌ | — | مش مناسب لحجمنا |

## 3) السحاب والـ PaaS — اللي اتقفل أو مش مناسب

| المنصة | حالة الـ Free في 2026 | المناسبية |
|--------|------------------------|-----------|
| **Render** | خدمة ويب مجانية 512MB / 0.1 CPU / 750 ساعة، **بتنام بعد 15 دقيقة** وتصحى في ~دقيقة، والباندويث بقى 5GB/شهر (أبريل 2026) | ❌ أول زيارة بعد السكون بطيئة جدًا لمتجر |
| **Koyeb** | الـ Starter المجاني **اتقفل للمستخدمين الجدد** بعد استحواذ Mistral (فبراير 2026)؛ الجديد لازم Pro بـ $29/شهر | ❌ لم يعد مجاني |
| **Fly.io** | مفيش free allowance للعملاء الجدد من 2024 | ❌ |
| **Vercel / Netlify / Cloudflare Pages / GitHub Pages** | ممتازة للستاتيك، **لكن مش بتشغّل PHP** | ❌ إلا لو عُدنا هيكلة المشروع |

## 4) طبقة البيانات — أهم قرار (أهم من اسم الاستضافة)

المشروع دلوقتي بيخزّن الكتالوج في ملفات JSON داخل `data/`: `g2g.json` (8.1MB)، `visual_features.json` (8.1MB)،
`m5zoon.json` (5.6MB)، `vendoor.json` (4.1MB) — والموقع بيقراها في كل طلب (علشان كده موجودة نسخ `lite_*.json`
وسَلف `cezma_shards/`). على أي استضافة مجانية، ملفات بحجم 8MB في الذاكرة مع `memory_limit` محدود = بطء أو 500.

الخيارات المجانية:

| الخيار | السعة المجانية | المميز | العيب |
|--------|----------------|--------|-------|
| **MySQL على نفس الاستضافة** (Serv00/Byet) | ضمن مساحة الحساب | أسرع حل، PDO جاهز، فهارس على 46 ألف منتج | لازم سكربت تحويل من JSON → SQL |
| **SQLite محلي** | ضمن المساحة | صفر إعداد، `pdo_sqlite`، ملف واحد ~30–50MB | لازم القفل والكتابة متاحة |
| **Turso** (libSQL) | 5GB، 100 قاعدة، 500M قراءة/شهر | سريع + مش بيتوقف، سيرفرلت SQLite | محتاج HTTP/PDO-over-HTTP |
| **Neon** (Postgres) | ~1GB/مشروع، 100 مشروع، بيصغّر للصفر ويصحى في ~0.5 ث | Postgres قياسي، branching | بارد بعد 5 دقائق سكون |
| **Supabase** (مستخدم حاليًا للـ Auth) | 500MB DB، 5GB egress | الـ Auth شغال عندنا عليه أصلًا | **المشروع المجاني بيتوقف بعد 7 أيام بدون نشاط** |

**الترشيح:** انقل الكتالوج (المنتجات + الفهارس) إلى **MySQL على نفس السيرفر** (أو SQLite لو الاستضافة بتحدّ
قواعد البيانات)، وسيب `users/orders/sessions` الحساسة في Supabase، وخزّن الصور **hotlink** زي ما هو دلوقتي.

## 5) فحص التوافق قبل النقل (Checklist للمشروع ده بالتحديد)

- [ ] `data/` قابل للكتابة ( التسجيل/الطلبات/الكاش ) — 755 أو 775.
- [ ] `memory_limit` ≥ 256M عبر `.user.ini` (Serv00) أو `php.ini` — المشروع بيطلب 256M في `config.php`.
- [ ] `max_execution_time` ≥ 60 (Serv00 افتراضي 180 للـ web، وغير محدود من SSH).
- [ ] **cron حقيقي** لتحديث الكتالوج (`updater/update_catalog.py` أو `cron.php`) بدل الرفع اليدوي.
- [ ] اتصال خارجي HTTPS (`allow_url_fopen` / cURL) — مطلوب لـ Amazon / Jumia / Noon / Supabase.
- [ ] `mail()`: لو مش متاح (زى InfinityFree) نكمل على Supabase Edge Function `send-mail`.
- [ ] حجم أكبر ملف (8.1MB) أقل من حد رفع الملف الواحد — على Byet الحد 10MB ✅، FTP بيعدّي الحد.
- [ ] `mod_rewrite` و`.htaccess` مفعّلين (الموقع بيعتمد عليهم في الحماية والروابط).
- [ ] SSL مجاني + دومين مخصص (Serv00 بي ops Let's Encrypt تلقائي، Byet تلقائي للسب دومين ويدوي للدومين).
- [ ] **ماترفعش** ملفات التطوير (CSV/XLSX/بايثون — 180MB) على الاستضافة؛ دي للجيت/local بس.

## 6) خطة نقل مقترحة (على Serv00 كمثال)

1. حساب على serv00.com → DevilWEB → أضف الدومين (سب دومين `USER.serv00.net`) كـ WWW page نوع **PHP**.
2. من SSH: `git clone` المستودع أو ارفع بالـ SFTP محتويات المشروع **بدون** ملفات CSV/XLSX و`__pycache__`.
3. أنشئ `.user.ini` جوه `public_html`:
   ```ini
   memory_limit = 256M
   max_execution_time = 120
   post_max_size = 32M
   upload_max_filesize = 16M
   allow_url_fopen = On
   ```
4. أنشئ قاعدة MySQL من DevilWEB، ونفّذ سكربت تحويل JSON → SQL (مهمة تانية نقدر نعملها هنا).
5. اضبط المتغيرات في `secrets.php` (مفاتيح Supabase/Amazon/Jumia) — **من غير ما تعمله commit**.
6. فعّل Let's Encrypt للدومين من DevilWEB، واتأكد إن `data/` مش متاح للعامة (`.htaccess` موجود).
7. أضف cron: تحديث الكتالوج كل 6 ساعات + مهمة «تنظيف الكاش» + **دخول دوري** كل شهر علشان الـ 90 يوم.
8. اختبر: تسجيل مستخدم، طلب، بحث في كتالوج سيزما (44 ألف منتج)، وقياس زمن الصفحة.

## 7) إمتى تدفع (خطة خروج)

لما الموقع يبدأ مبيعات حقيقية، استضافة مشتركة مدفوعة رخيصة أضمن بكتير من أي Free:
Hostinger من ~$2.99/شهر، IONOS من $1/شهر، أو VPS (Netcup /Contabo) بـ €3–5 لو حابب تحكّم كامل.

## مصادر

- Serv00 (حدود الحساب والمزايا): https://www.serv00.com/ — وثائق PHP و`.user.ini`: https://docs.serv00.com/PHP/ — Cron: https://docs.serv00.com/Cron/
- Byet.host الخطة المجانية: https://byet.host/free-hosting
- GoogieHost: https://googiehost.com/blog/infinityfree-alternative/
- مقارنة استضافات PHP مجانية 2026: https://www.deployhq.com/blog/how-to-deploy-a-php-website-for-free
- حالة الـ Free Tiers في 2026 (Render/Koyeb/Fly/Oracle): https://snapdeploy.dev/state-of-free-hosting
- Oracle Cloud Always Free بعد التخفيض: https://dev.to/karthik_yk/oracles-famous-free-4-core24gb-arm-tier-is-now-2-core12gb-check-the-docs-2jln
- Supabase: إيقاف المشاريع المجانية بعد 7 أيام: https://supabase.com/docs/guides/platform/free-project-pausing
- Turso (5GB مجانًا): https://basekv.com/articles/turso-pricing-free-tier-limits-2026
