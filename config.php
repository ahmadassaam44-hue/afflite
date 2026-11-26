<?php
// سقف ذاكرة أعلى قدر ما تسمح به الاستضافة — بيقلل الأخطاء الفادحة (OOM) المسجلة في «آخر أخطاء الموقع»
@ini_set('memory_limit', '256M');
// ============================================================================
// تحميل الأسرار من ملف منفصل (مستبعد من Git) — مع قيم احتياطية للطوارئ فقط
// ============================================================================
if (file_exists(__DIR__ . '/secrets.php')) require_once __DIR__ . '/secrets.php';
if (!defined('ADMIN_PASS'))    define('ADMIN_PASS', 'CHANGE_ME_NOW_' . bin2hex(random_bytes(6)));
if (!defined('HEVO_API_KEY'))  define('HEVO_API_KEY', '');
if (!defined('SB_SERVICE'))    define('SB_SERVICE', '');
// إرسال الإيميلات عن طريق Supabase Edge Function (send-mail). المفتاح السري في secrets.php بس
if (!defined('MAIL_FN_URL'))    define('MAIL_FN_URL', 'https://hbhuxhphlpgwqynstytw.supabase.co/functions/v1/send-mail');
if (!defined('MAIL_FN_SECRET')) define('MAIL_FN_SECRET', '');
// تسجيل الدخول بجوجل: يتفعل (true) بعد ما تتضبط بيانات جوجل في Supabase (GitHub Secrets)
if (!defined('GOOGLE_LOGIN_ON')) define('GOOGLE_LOGIN_ON', false);

define('BRAND', 'وفرنهالك');
define('SITE_VER', 'v61');
define('DEAL_PCT', 10);
define('DEAL_ENDS', '2026-12-31 23:59:59');
// خصم المنصات: فحصنا كل المصادر الستة — مفيش أي حقل خصم (discount/old_price=0 في كل منتج).
// → الخصم العام (DEAL_PCT) ملغى: مفيش خصم من المنصة مفيش خصم عندنا.
// لو المنصة بعتت خصم مستقبلًا (old_price > price أو discount > 0) هيتبان تلقائيًا في product.php
// من غير ما نفعّل الخصم العام.

// ============================================================================
// config.php صار مُحمِّلاً رفيعاً: الكود كله في أجزاء config_*.php بالترتيب
// الأصلي نفسه، من غير أي تغيير في المنطق أو القيم. كل صفحة بتطلب config.php
// زي ما هي، وكل الدوال والثوابت متاحة بنفس الأسماء.
// ============================================================================
require_once __DIR__ . '/config_pricing.php';
require_once __DIR__ . '/config_orders.php';
require_once __DIR__ . '/config_ledger.php';
require_once __DIR__ . '/config_security.php';
require_once __DIR__ . '/config_admin.php';
require_once __DIR__ . '/config_catalog.php';
require_once __DIR__ . '/config_geo.php';
require_once __DIR__ . '/config_users.php';
require_once __DIR__ . '/config_order_ops.php';
require_once __DIR__ . '/config_affiliate.php';
require_once __DIR__ . '/config_storefront.php';
require_once __DIR__ . '/config_checkout.php';
require_once __DIR__ . '/config_analytics.php';
require_once __DIR__ . '/config_auth.php';

// «تذكرني» — آخر سطر في config.php (تعتمد عليه اختبارات tests/ui).
remember_login();
