# تقرير حالة الاستضافة — 2026-11-11

## الخلاصة في سطر
الموقع (`https://wafirinhalk.ct.ws`) **مش واقف بسبب الكود**؛ السيرفر اللي عليه الحساب
(`185.27.134.151` — سيرفرات InfinityFree) **مش بيقبل أي اتصال** على 80 ولا 443،
من أي مكان في العالم. الكود سليم وبيترندر عادي لما يتشغّل محليًا.

## الدليل (اتجمع من GitHub Actions لأن بيئة Arena مش بتوصل للدومين)

الفاحص: `.github/workflows/site-probe.yml` — النتيجة الخام: `data/_site_probe.json`

| الاختبار | النتيجة |
|---|---|
| DNS لـ `wafirinhalk.ct.ws` | ✅ بيرجع `185.27.134.151` (الدومين سليم) |
| TCP 443 | ❌ CLOSED (connection timed out) |
| TCP 80 | ❌ CLOSED (connection timed out) |
| `https://wafirinhalk.ct.ws/` | ❌ `000` — صفر بايت، timeout بعد 30 ثانية |
| `/index.php` و `/hello.html` و `/robots.txt` | ❌ نفس النتيجة (يعني مش مشكلة في ملف بعينه) |
| شبكات تحقق خارجية (ألمانيا، إسرائيل، بولندا، صربيا، السويد، إنجلترا) | ❌ كلها `Connection timed out` |
| بروكسي خارجي (Cloudflare) | ❌ `error code: 522` (Origin connection timed out) |
| موقع تحكم `example.com` | ✅ `200` في 0.05 ثانية |
| لوحة الاستضافة `dash.infinityfree.com` | ✅ `302` (لوحة InfinityFree شغالة) |

**الاستنتاج:** الدومين شغال والـ DNS صح، لوحة الاستضافة نفسها شغالة، لكن **السيرفر
اللي عليه الحساب مقطوع**. دي حالة من ناحية الاستضافة، مفيش أي حاجة في الكود تعملها.

## الكود سليم (اختبار محلي)
اتشغّل `index.php` فعليًا على PHP 8.4 (WASM) بنفس ملفات الريبو + `data/`:
الصفحة الرئيسية طلعت كاملة (HTML ~24KB، عنوانها صح، مفيش fatal error).
يعني مفيش `parse error` في `config.php` ولا مشكلة في ملفات الـ JSON.

## اللي محتاج تعمله (بالترتيب)
1. افتح لوحة الاستضافة: <https://dash.infinityfree.com> بنفس إيميل الحساب.
2. بص على **Account Status**:
   - **Suspended** → اضغط **Reactivate** (لو مفيش زرار، اعمل Deactivate وبعدين Reactivate).
     لاحظ السبب المكتوب (غالبًا: تخطي حدود الموارد / CPU / عدد الطلبات).
   - **Active** والموقع لسه واقف → افتح تذكرة دعم بالنص ده:
     > My account for `wafirinhalk.ct.ws` (server IP `185.27.134.151`) is not accepting
     > TCP connections on ports 80/443 from any location — connection times out
     > (verified from 6 countries; Cloudflare returns 522). DNS resolves correctly.
     > Please check the server / reactivate the vhost.
3. لو الحساب اتعلّق بسبب الموارد: متعملش ريفريش كتير للموقع وهو واقف، وبعد الرجوع
   نعمل التخفيف اللي تحت.

## ليه ممكن يتعلّق تاني (ومحتاج تخفيف)
- كل زيارة للصفحة الرئيسية بتقرأ وتفكّ **~2.2 ميجا JSON** (`lite_timehand` + `lite_safqa`
  + `lite_vendoor` + `lite_angazny` + `lite_shopmart` + `lite_mnfean`) — ده حمل CPU عالي
  على استضافة مشتركة.
- بوت الكتالوج بيرفع على السيرفر **كل 6 ساعات** (16+ ملف، منهم ملفات ميجابايت).

### تخفيف مقترح (جاهز للتنفيذ بعد رجوع الموقع)
1. كاش HTML للصفحة الرئيسية للزوار (مدتها 10 دقايق) → الصفحة تتولّد مرة واحدة بدل كل زيارة.
2. دمج/ضغط ملفات `lite_*.json` (gzip على المستوى) أو تقليل الأعمدة غير المستخدمة.
3. جدولة تحديث الكتالوج كل 12 ساعة بدل 6.
4. صفحة `ping.php` للحماية من إعلانات «الموقع واقف» الكدابة.

## إزاي تفحص تاني بعد كده
في GitHub → Actions → **فحص حالة الموقع (تشخيص)** → Run workflow.
بيحفظ النتيجة في `data/_site_probe.json` (كود الحالة + الهيدرز + فحص من شبكات عالمية + Cloudflare).
