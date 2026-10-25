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

## 2) الطريقة الأسهل: File Manager من لوحة التحكم (من غير تثبيت أي حاجة)
1. ادخل على لوحة تحكم InfinityFree (نفس المكان اللي بتدخل منه على File Manager).
2. **File Manager** → افتح `htdocs` → `data`.
3. اختار `orders.json` → **Download** (أو View لمعاينته).
4. احفظ الملف في مجلد على جهازك، وسمّيه `orders.json`.
5. لو عايز كل حاجة: حمّل كمان `users.json` و`customer_requests.json` (اختياري).

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
