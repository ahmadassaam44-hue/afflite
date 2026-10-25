# إزاي تطلّع الطلبات (أكتوبر 2026)

## ⚡ الخلاصة: الطلبات **مش على الاستضافة** — دي على Supabase
`config.php` فيه قائمة `DOCS_REMOTE` وأول ملف فيها **`orders.json`** — يعني كل قراءة/كتابة للطلبات
بتتم على **جدول `public.app_docs` في Supabase** (صف `name='orders.json'`، والبيانات في عمود `data` jsonb)،
**مش** على ملف `data/orders.json` على السيرفر.

يومها:
- تعليق الدومين أو وقوف الـ FTP **مش مهدد للطلبات** — هي في قاعدة بيانات تانية خالص [supabase/migrations/20261010_app_docs.sql](supabase/migrations/20261010_app_docs.sql).
- الملف اللي على الاستضافة (لو موجود) نسخة قديمة/فاضية — استخدمهاش كمصدر.
- الجدول عليه RLS ومفيش سياسات → بيُقرأ بـ service_role من السيرفر، أو **من لوحة Supabase** (SQL Editor/Table Editor) لأنها بتشتغل بصلاحيات أعلى.

> 🔒 `data` فيها أسماء وعناوين وتليفونات عملاء: الريبو Public على GitHub → ماترفعش الملف ولا تلزقه في الشات.

---

## الطريقة 1 (الأسهل — من غير FTP ولا باسورد): SQL Editor في Supabase
1. ادخل على https://supabase.com/dashboard → المشروع (**`hbhuxhphlpgwqynstytw`**).
   - لو ظهرلك إن المشروع **Paused** (بيحصل بعد 7 أيام بدون نشاط) → اضغط **Restore/Unpause** واستنى دقيقة.
2. من القايمة: **SQL Editor** → **New query**.
3. الأول شوف عندك كام طلب (استعلام آمن):
   ```sql
   select name,
          updated_at,
          jsonb_typeof(data)           as type,
          jsonb_array_length(data)     as orders_count
   from public.app_docs
   where name = 'orders.json';
   ```
4. بعدين نفّذ استعلام التصدير (كل الأعمدة كنص — ما بيقعش لو فيه قيمة فاضية):
   ```sql
   select
     o->>'order_no'         as "رقم الطلب",
     left(o->>'at', 16)     as "التاريخ",
     o->>'pname'            as "المنتج",
     coalesce(o->>'color','') as "اللون",
     coalesce(o->>'size','')  as "المقاس",
     o->>'qty'              as "الكمية",
     o->>'unit'             as "سعر القطعة",
     o->>'ship'             as "الشحن",
     o->>'discount'         as "الخصم",
     o->>'total'            as "الإجمالي",
     o->>'cname'            as "العميل",
     o->>'cphone'           as "الهاتف",
     o->>'cphone2'          as "هاتف 2",
     o->>'city'             as "المحافظة",
     o->>'district'         as "المنطقة",
     o->>'address'          as "العنوان",
     o->>'notes'            as "ملاحظات العميل",
     o->>'ref'              as "المسوق",
     o->>'m_earn'           as "عمولة المسوق",
     o->>'status'           as "الحالة",
     o->>'cancel_reason'    as "سبب الإلغاء",
     o->>'return_reason'    as "سبب الاسترجاع"
   from public.app_docs d
   cross join lateral jsonb_array_elements(
     case when jsonb_typeof(d.data) = 'array' then d.data else '[]'::jsonb end
   ) as o
   where d.name = 'orders.json'
   order by (o->>'at') desc;
   ```
5. من نتيجة الاستعلام اضغط **Export / Download as CSV** (أو Copy) → الملف عندك، يفتح في Excel.

**نسخة بأرقام حقيقية** (للجمع والفرز في Excel) — بدّل السطور دي في الاستعلام:
```sql
   nullif(o->>'qty','')::numeric        as "الكمية",
   nullif(o->>'unit','')::numeric       as "سعر القطعة",
   nullif(o->>'ship','')::numeric       as "الشحن",
   nullif(o->>'discount','')::numeric   as "الخصم",
   nullif(o->>'total','')::numeric      as "الإجمالي",
   nullif(o->>'m_earn','')::numeric     as "عمولة المسوق",
```

## الطريقة 2: من Table Editor (من غير SQL)
1. Supabase → **Table Editor** → جدول **`app_docs`**.
2. افتح الصف اللي `name = orders.json` → اضغط على خانة **`data`** → **Copy** (النسخة JSON كاملة).
3. اللزقها في ملف عندك اسمه `orders.json` (محرر نصوص عادي، UTF-8).
4. حوّلها:
   ```bash
   python3 tools/rescue_orders.py --local orders.json
   ```
   → يطلّع `orders.csv` و`orders.xlsx` + ملخص عدد الطلبات والإجمالي وتوزيع الحالات.
5. من غير Python: Excel → Data → Get Data → From File → From JSON → To Table → ⬌ Expand → Close & Load.

## الطريقة 3: FTP (للنسخة القديمة على السيرفر أو ملفات تانية — مش مصدر الطلبات)
- Host: `ftpupload.net` · User: `if0_42981380` · Password: **Hosting Account Password**
  (من Client Area → الحساب → **FTP Details** — **مش** باسورد اللوحة) [10](https://forum.infinityfree.com/t/i-cannot-log-in-my-file-manager-and-control-panel/49078).
- ويب file manager بديل: https://filestash.infinityfree.com [23](https://forum.infinityfree.com/t/file-manager/117003).
- لو «Invalid account» أو الـ FTP رافض: Client Area → الحساب → **Edit Account** → **Hosting Account Password**
  → كلمة جديدة → **استنى 5–15 دقيقة** [15](https://forum.infinityfree.com/t/help-i-cannot-connect-to-ftp/49307).
  ⚠️ عمرك ما تستعمل Reset Password اللي جوه الـ Control Panel [24](https://forum.infinityfree.com/t/vistapanel-login-not-working/76947).
- لو «Domain Suspended»: افتح تكت من Client Area → Support [18](https://forum.infinityfree.com/t/error-on-control-panel-connection/59601).

## بعد ما تطلّع الطلبات
- الملفات دي تقدر تفتحها في Excel وتفلتر/تفرز/تطبع.
- الاحتفاظ بنسخة دورية: بعد ما الموقع يرجع، زر التصدير في `dashboard/orders.php`
  و`dashboard/admin.php?tab=orders` بينزّل CSV في أي وقت.
- عشان التعليق مايرجعش: شوف `docs/free-hosting-alternatives-2026-10-25.md`.
