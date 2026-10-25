# إزاي تطلّع الطلبات من الموقع وهو متعلّق (InfinityFree)

## 0) الموقف دلوقتي
- الدومين `wafirinhalk.com` عليه صفحة **Domain Suspended — reaching server limits**.
  يعني الاستضافة شغالة والحساب موجود، بس الوصول للويب (HTTP/PHP) مقطوع.
- **الـ FTP والـ File Manager شغالين عادي** أثناء التعليق → ده طريقنا لإخراج الطلبات.
- ملف الطلبات: **`/htdocs/data/orders.json`** (مش موجود في الريبو — مستثنى من Git).

> ⚠️ الريبو Public على GitHub: ماترفعش `orders.json` ولا أي ملف فيه بيانات عملاء عليه.

## 1) بيانات الـ FTP (من updater/README.md)
| الحقل | القيمة |
|---|---|
| Host | `ftpupload.net` |
| Username | `if0_42981380` |
| Password | كلمة سر الـ FTP بتاعتك (من لوحة تحكم InfinityFree — لو نسيتها: Control Panel → FTP Settings → تغيير كلمة السر) |
| Port | 21 (سيبه فاضي في FileZilla) |
| المسار | `/htdocs/data/` |

## 2) أهم قاعدة قبل أي محاولة: فيه **كلمتا سر مختلفتان**
| النوع | يُستخدم لـ | فين تلاقيه |
|---|---|---|
| **كلمة سر الـ Client Area** | الدخول على dash.infinityfree.com | الإيميل + الباسورد بتاع الحساب |
| **كلمة سر حساب الاستضافة** (Hosting Account Password) | **File Manager + FTP + قواعد البيانات** | من جوه Client Area: الحساب → **FTP Details** |

> ⚠️ File Manager **بيستخدم بيانات الـ FTP مش بتاعة اللوحة** [10](https://forum.infinityfree.com/t/i-cannot-log-in-my-file-manager-and-control-panel/49078).
> ⚠️ **عمرك** ما تستعمل «Reset Password» اللي جوه الـ Control Panel — بيبوّظ المزامنة مع الـ Client Area.
> غيّرها بس من Client Area → الحساب → **Edit/Account Settings** → **Hosting Account Password**، واستنى 5–15 دقيقة [3](https://forum.infinityfree.com/t/cannot-change-ftp-password/16576)، [12](https://forum.infinityfree.com/t/lost-password-contact-support/82086).

## 2-bis) لو مش قادر تدخل File Manager — السبب والعلاج
**السبب الأرجح دلوقتي:** كان فيه عطل رسمي في سيرفر الـ FTP عندهم (إمبارح 2026-10-24) استمر ~9 ساعات،
والـ **File Manager بيعتمد على الـ FTP** فبيفشل معاه — حالة الخدمات دلوقتي كلها **Operational** على
[status.infinityfree.com](https://status.infinityfree.com/) → **جرّب تاني دلوقتي** [21](https://status.infinityfree.com/).

اختار حالتك:
1. **مش داخل على dash.infinityfree.com أصلًا** → اعمل reset لكلمة سر الـ Client Area من صفحة الدخول،
   أو جرّب الدخول بحساب جوجل/جيت هاب لو كنت رابطه.
2. **داخل اللوحة، بس زر Control Panel أو File Manager بيرفض** → الحساب → Edit Account →
   Hosting Account Password → كلمة جديدة → استنى 5–15 دقيقة → جرّب تاني.
3. **اللوحة بطيحة أو الزر بيعلّق** → ادخل على الـ Control Panel مباشرةً بأحد الروابط دي
   ببيانات **حساب الاستضافة** (`if0_42981380` + باسورد الاستضافة):
   `https://cpanel.epizy.com` أو `https://cpanel.rf.gd` أو `https://panel.myownfreehost.net:2083/`
   [2](https://forum.infinityfree.com/t/resolved-control-panel-down-november-13/116523/15)، [4](https://forum.infinityfree.com/t/cpanel/116519).
4. **مش لاقي باسورد الـ FTP** → Client Area → الحساب → **FTP Details** (مكتوب هناك).
   لو ضاع خالص: Edit Account → Hosting Account Password → جديد، وحدّثه كمان في GitHub
   (Settings → Secrets → `FTP_PASS`) عشان الـ workflow يفضل شغال.

## 2-ب) الطريقة الأسهل (من غير تثبيت ولا لوحة تحكم): File Manager على الويب
1. افتح **https://filestash.infinityfree.com** — مدير ملفات في المتصفح زي File Manager بالظبط [23](https://forum.infinityfree.com/t/file-manager/117003).
2. اتصال: Protocol = **FTP** · Host = `ftpupload.net` · Port = 21 · Username = `if0_42981380` · Password = باسورد الاستضافة.
3. ادخل `htdocs/data` → نزّل `orders.json` على جهازك.

## 3) الطريقة الأضمن (لو الملف كبير أو File Manager بيعلّق): FileZilla
1. نزّل **FileZilla Client** من filezilla-project.org وثبّته.
2. فوق في الشريط: Host = `ftpupload.net`، Username = `if0_42981380`، Password = بتاعك، Port = فاضي → **Quickconnect**.
3. على اليمين (السيرفر) ادخل: `htdocs` → `data`.
4. على الشمال اختار مجلد على جهازك (مثل Desktop\afflite-backup).
5. جرّر `orders.json` من اليمين للشمال → يتنزّل.
6. (اختياري) جرّر كمان `users.json` و`customer_requests.json`.

لو الاتصال فشل: تأكد إنك داخل على لوحة التحكم بنفس بيانات الحساب، وإن كلمة سر الـ FTP مش باسورد اللوحة (InfinityFree بيفصل بينهم: Control Panel → FTP Accounts).

## 4) تحويل orders.json لـ Excel/CSV على جهازك
### لو عندك Python (أسهل حاجة):
```bash
cd afflite
python3 tools/rescue_orders.py --local path/to/orders.json
```
هيطلّع `rescue-.../orders.csv` و`orders.xlsx` + ملخص (عدد الطلبات، الإجمالي، توزيع الحالات).

### من غير Python (Excel Power Query):
1. Excel → **Data** → **Get Data** → **From File** → **From JSON**.
2. اختار `orders.json` → **To Table** → **OK**.
3. اضغط أيقونة السهمين ⬌ في عنوان العمود → **Expand** → اختار كل الأعمدة → OK.
4. **Close & Load** → هتلاقي الطلبات في شيت.

## 5) بعد ما تطّلع الطلبات
- **إعادة تشغيل الموقع:** من لوحة تحكم InfinityFree فيه زر **Reactivate** للحساب المتعلّق —
  وحدود الزيارات بتصفّر كل يوم (السقف ~50 ألف زيارة/يوم). لو بيتكرر كل شوية،
  شوف `docs/free-hosting-alternatives-2026-10-25.md` (Serv00 مثلًا فيه cron وSSH بدون سقف زيارات).
- **انقل `orders.json` على الاستضافة الجديدة** في نفس المسار `htdocs/data/`، والكود هيقراه زي ما هو.
- **خلّيك دايمًا واخد نسخة:** زر التصدير اللي ضفناه في `dashboard/orders.php`
  (و`dashboard/admin.php?tab=orders`) بينزّل الطلبات CSV في أي وقت — بس محتاج الموقع يكون مفتوح.

## 6) لو الـ FTP نفسه مش راضي يتصل
يبقى الحساب اتقفل من الاستضافة مش الدومين بس → كلمة سر غلط / الحساب اتلغى لعدم النشاط.
افتح تكت من Control Panel → Support واطلب فتح الـ FTP مؤقتًا لتنزيل ملفاتك (بيانات عملاء).
