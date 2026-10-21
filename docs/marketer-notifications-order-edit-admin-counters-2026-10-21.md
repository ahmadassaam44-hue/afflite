# إشعارات المسوقين + تعديل/إلغاء المسوق لطلباته + عدّادات سايدبار الأدمن — 2026-10-21

طلب الإدارة (2026-10-21):
1. لما ينزل منتج جديد → إشعار يروح **لكل المسوقين**.
2. المسوق **يعدّل كل بيانات الطلب** بتاعه، و«الحذف» = **إلغاء ناعم**: الطلب يفضل عند الإدارة ويتكتب جنبه **«ملغي»**.
3. الإدارة تشوف تفاصيل الطلب كاملة: **السعر + العمولة + الشحن + اسم المسوق**.
4. **أي حاجة تحصل في أكونت المسوق** تظهر له في إشعاراته.
5. **أرقام في القائمة الجانبية للأدمن** لكل جديد (أوردرات/مسوقين/سحوبات/طلبات توفير/محادثات).

## 1) إشعارات مجموعة «المسوقين» — `config.php`

- `wf_notify_marketers($text, $link='')` → `notify('marketers', …)`: **صف واحد** في
  `data/notifications.json` بـ `for:'marketers'` يوصل لكل المسوقين (بدون تفنص لكل إيميل).
- `my_notifications($email, $role = null)` بقى بياخد الدور: صاحب دور `marketer` بيشوف كمان
  إشعارات `for:'marketers'` (لو `$role` ماتمررش بيتجلب من `find_user`). العملاء/الأدمن
  مايشوفوهاش. حدّثنا الناديين: جرس `dashboard/layout.php` وصفحة `dashboard/notifications.php`.
- مصادر الإرسال الجديدة:
  - `manual_products_lib.php` → `manual_product_insert()`: إشعار «🆕 منتج يدوي جديد: …».
  - `updater/update_catalog.py`: `snapshot_platform_ids()` قبل التحديثات، و
    `push_new_product_notifications()` في آخر التشغيل — فرق الـ ids لكل منصة
    (`hevo,g2g,gamsgo,safqa,vendoor,m5zoon,angazny,timehand,shopmart,sources,cezma`)؛
    أول 10 منتجات بتيجي بتفاصيلها والباقي في إشعار مجمع، والكتابة بتلحق بحد
    `notifications.json` (آخر 500). فشل الإشعارات ما يوقفش النشر.
  - طلب جديد برف مسوق (`dashboard/orders.php` بعد إنشاء الطلب):
    `notify_user(إيميل المسوق, '🛍️ طلب جديد على لينكك: …')`.

## 2) تعديل/إلغاء المسوق لطلباته — `dashboard/marketer.php` + `config.php`

- `wf_marketer_can_edit_order($o,$u)`: الطلب لازم يكون برف المسوق (`ref` = `ref_code`)
  وحالته لسه تقبل الإلغاء (`status_customer_cancellable`: قيد التأكيد/مؤكد).
- `wf_marketer_apply_order_edit($order_no,$u,$in)`: يعدّل
  (اسم العميل/الموبايل/موبايل بديل/الكمية/المحافظة/المدينة/العنوان/الملاحظات).
  **السيرفر** بيعيد حساب `discount` (خصم الكمية للجنيه فقط عبر `wf_bulk_discount`)
  و`total = unit×qty + ship − discount`، وبيختم `mk_edited_at`/`mk_edited_by`.
  مفيش ثقة في أي رقم من المتصفح، والعمولة (`m_earn`) ما بتتغيّرش من الفورم.
- `wf_marketer_cancel_order($order_no,$u,$reason)`: **حذف = إلغاء ناعم** —
  `status='cancelled'` (الليبل «ملغي»)، `comm_state='reversed'`،
  `cancel_reason='إلغاء المسوق: …'`، `cancelled_by='marketer:<ref>'`، والصف **بيفضل** في
  `orders.json` عند الإدارة. لو كان فيه عمولة مثبتة بتتعكس
  (`commission_reverse` + `team_commission_reverse_for_order`) زي إلغاء العميل بالظبط.
- الواجهة: عمود «إجراءات» في جدول طلبات المسوق — زر «تعديل» بيفتح فورم فوق الجدول
  (`?mtab=orders&edit=<order_no>`)، وزر «إلغاء» بتأكيد. Handler جديد
  `mk_order_action` (edit/cancel) بـ CSRF، وإشعار للأدمن + إشعار للمسوق نفسه في الحالتين،
  و`log_audit('marketer_edit'|'marketer_cancel')`.

## 3) تفاصيل الطلب كاملة عند الإدارة — `dashboard/admin.php`

جدول الطلبات الرئيسي بقى يعرض: **السعر بعملة المنتج** (`wf_ui_currency($o)`)،
عمود جديد **«العمولة»** (`+m_earn ج.م` وتحتها «إضافية» لو فيه `extra_comm`)،
**الشحن بعملته** (`wf_ship_currency(plat, ship_country)`)، الإجمالي بعملة الطلب،
و**اسم المسوق** (`find_marketer_by_code($o['ref'])` → الاسم + الكود تحته) بدل الكود وحده.

## 4) إشعارات المسوق عن كل حاجة في أكونته — `dashboard/marketer.php`

جنب إشعار الأدمن الموجود، المسوق بقى بياخد `notify_user` على:
تحديد/إزالة عمولته على رابط (`💰 عمولتك على الرابط … اتحدّت`)،
إضافة/حذف منتج من متجره (`➕/🗑️ …`)،
تعديل طلبه (`✏️ تم حفظ تعديلاتك…`)، وإلغاء طلبه (`❌ … فضل مسجّل عند الإدارة بحالة «ملغي»`).
(باقي أحداث الحساب كانت مغطية أصلاً: تغيير حالة الطلب، تأكيد/عكس العمولة، إنشاء/قبول/رفض السحب، ردود الشات.)

## 5) عدّادات القائمة الجانبية للأدمن — `wf_admin_pending_counts()` + `layout.php`

- `orders`: الطلبات بحالة pending (تشمل «قيد التأكيد»).
- `marketers`: مسوقون اتسجلوا في آخر **7 أيام** (التسجيل فوري بدور marketer).
- `commissions`: طلبات سحب «قيد المراجعة».
- `requests`: طلبات توفير منتجات `pending`.
- `chats`: محادثات دعم آخر رسالة فيها من المستخدم (مش من الأدمن).
السايدبار بيطلع `<span class="notification-count side-count">` أحمر جنب:
الطلبات، المسوّقون، العمولات والسحب، طلبات توفير المنتجات، المحادثات.
CSS جديد في `assets/style.css`: `.dash .side .side-count` (وضع static + `margin-inline-start:auto`)
وصيغة مصغّرة لوضع السايدبار المطوي (`body.side-collapsed`).

## الاختبارات

`tests/ui/manual-country-currency.cjs`:
- فيشرات محلية جوه WASM (`orders/payouts/product_requests/support_chats/notifications.json`)
  و`define('DOCS_REMOTE', [])` قبل تضمين `config.php` عشان كل الملفات تبقى محلية (Supabase مطفي في الاختبار).
- 28 assertion جديدة (القسم 12): العدّادات الخمسة، بث `marketers` وظهوره للمسوق فقط،
  صلاحيات التعديل (رف غريب/مشحون/ملغي مرفوض)، إعادة حساب الإجمالي
  (100×2 + 10 − 70 خصم كمية = **140**)، ختم `mk_edited_by`، الإلغاء الناعم
  (الصف فاضل + `cancelled` + ليبل «ملغي» + `reversed` + سبب «إلغاء المسوق»).
- Wiring جديد (Node): المساعدات في `config.php`، بث `manual_products_lib`،
  diff المنتجات الجديدة في `update_catalog.py`، عدّادات وشارات `layout.php`،
  تمرير الدور في الجرس وصفحة الإشعارات، handler وفورم وعمود «إجراءات» في `marketer.php`
  (و≥4 `notify_user` ذاتية)، إشعار الطلب الجديد في `orders.php`، عمود «العمولة»
  واسم المسوق في `admin.php`.
- النتيجة: **336 assertion / 0 فشل** (كانت 308) + كل ملفات PHP المعدلة بتتبارس نظيفة (php-parser).

## حدود معروفة

- عدّاد «المسوقون الجدد» بيعتمد على `users()` (Supabase) — لو خدمة المستخدمين معطلة هيفضل 0.
- إشعارات `marketers` مجموعة واحدة: مفيش «تعليم كمقروء» جماعي — بتتعرض في جرس/صفحة كل مسوق.
- تعديل المسوق مسموح قبل الشحن فقط (نفس قاعدة إلغاء العميل)؛ بعد الشحن/التوصيل الأزرار تختفي.
