<?php
// اختبار منطق مكتبة صفقة من غير شبكة (كاش شحن + كاش منتج + وضع dry).
// التشغيل: php tests/safka_order_test.php  — أو فوق php-wasm (shim للـ mb_* في polyfill.php)
require __DIR__ . '/polyfill.php';

define('BRAND', 'وفرنهالك');
define('DATA_DIR', sys_get_temp_dir() . '/safka_test_data');
@mkdir(DATA_DIR, 0777, true);

$GLOBALS['ST'] = ['safqa_auto_order' => 'dry'];
$GLOBALS['FILES'] = [];
function site_setting($k, $d = '') { return $GLOBALS['ST'][$k] ?? $d; }
function jload($f, $d = [], $memo = true) { return $GLOBALS['FILES'][$f] ?? $d; }
function jupdate($f, $fn, $d = []) {
    $cur = $GLOBALS['FILES'][$f] ?? $d;
    $fn($cur);
    $GLOBALS['FILES'][$f] = $cur;
    return true;
}
define('SAFKA_API_KEY', 'spk_TEST123');

// كاش قائمة الشحن (شكل رد صفقة بعد التنظيف)
$GLOBALS['FILES']['safka_price_list.json'] = ['at' => time(), 'rows' => [
    ['id' => 'gov_cairo', 'number' => '1', 'label' => 'القاهرة', 'en' => 'Cairo', 'price' => 60, 'cities' => [
        ['id' => '336', 'label' => 'مدينة نصر', 'en' => 'Nasr City'],
        ['id' => '337', 'label' => 'المعادي', 'en' => 'Maadi'],
    ]],
    ['id' => 'gov_giza', 'number' => '2', 'label' => 'الجيزة', 'en' => 'Giza', 'price' => 65, 'cities' => [
        ['id' => '400', 'label' => '6 أكتوبر', 'en' => '6th of October'],
    ]],
    ['id' => 'gov_alex', 'number' => '3', 'label' => 'الإسكندرية', 'en' => 'Alexandria', 'price' => 70, 'cities' => [
        ['id' => '500', 'label' => 'سموحة', 'en' => 'Smouha'],
    ]],
]];
// كاش منتج صفقة (properties بمعرّفات)
$GLOBALS['FILES']['safka_products.json'] = ['prod1' => ['at' => time(), 'product' => [
    '_id' => 'prod1', 'name' => 'تيشيرت نص سوسته شتوي', 'is_active' => true,
    'properties' => [
        ['_id' => 'prop_white', 'key' => 'أبيض'],
        ['_id' => 'prop_black', 'key' => 'أسود'],
        ['_id' => 'prop_grey', 'key' => 'رمادي غامق'],
    ],
]]];

require __DIR__ . '/../safka_order_lib.php';

$ok = 0; $bad = 0;
function t($name, $cond, $extra = '') {
    global $ok, $bad;
    if ($cond) { $ok++; echo "✅ $name\n"; }
    else { $bad++; echo "❌ $name" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}

// 1) تطبيع العربي
t('تطبيع: الإسكندرية == الاسكندريه', safka_norm_ar('الإسكندرية') === safka_norm_ar('الاسكندريه'));
t('تطبيع: شيل «محافظة»', safka_norm_ar('محافظة الجيزة') === safka_norm_ar('الجيزة'));

// 2) مطابقة المحافظة
$g = safka_find_governorate('الاسكندريه');
t('محافظة «الاسكندريه» اتطابقت', ($g['id'] ?? '') === 'gov_alex', json_encode($g, JSON_UNESCAPED_UNICODE));
t('محافظة «محافظة القاهرة» اتطابقت', (safka_find_governorate('محافظة القاهرة')['id'] ?? '') === 'gov_cairo');
t('محافظة غريبة مابتتطابقش', safka_find_governorate('كوكب المريخ') === null);

// 3) مطابقة المدينة
$c = safka_find_city($g, 'سموحه');
t('مدينة «سموحه» اتطابقت', ($c['id'] ?? '') === '500', json_encode($c, JSON_UNESCAPED_UNICODE));

// 4) اختيار الخاصية (اللون)
$prod = safka_product('prod1');
$order = [
    'order_no' => 'ORD-TEST-1', 'plat' => 'safqa', 'id' => 'prod1', 'qty' => 2,
    'total' => 700, 'm_earn' => 50, 'cname' => 'أحمد محمد', 'cphone' => '01012345678',
    'cphone2' => '01198765432', 'city' => 'القاهرة', 'district' => 'المعادي',
    'address' => 'شارع 9 - عمارة 12', 'color' => 'أسود', 'size' => 'XL',
    'variant' => 'اللون: أسود | المقاس: XL', 'notes' => 'اتصل قبل التسليم',
];
$p = safka_pick_property($prod, $order);
t('الخاصية = أسود', ($p['_id'] ?? '') === 'prop_black', json_encode($p, JSON_UNESCAPED_UNICODE));

// 5) بناء الطلب + الإرسال في وضع dry
$GLOBALS['FILES']['orders.json'] = [$order];
$res = safka_push_order('ORD-TEST-1');
$pl = $res['payload'] ?? [];
t('dry رجّع ok', !empty($res['ok']) && ($res['state'] ?? '') === 'dry', json_encode($res, JSON_UNESCAPED_UNICODE));
t('اسم العميل', ($pl['client_name'] ?? '') === 'أحمد محمد');
t('الموبايل أرقام بس', ($pl['client_phone1'] ?? '') === '01012345678');
t('هاتف بديل', ($pl['client_phone2'] ?? '') === '01198765432');
t('معرّف المحافظة من صفقة', ($pl['shipping_governorate'] ?? '') === 'gov_cairo');
t('مفيش مدينة (تفاصيل إضافية)', !isset($pl['city']));
t('الكمية نص', ($pl['items'][0]['qty'] ?? '') === '2');
t('المنتج', ($pl['items'][0]['product'] ?? '') === 'prod1');
t('الخاصية', ($pl['items'][0]['property'] ?? '') === 'prop_black');
t('الإجمالي نص', ($pl['total'] ?? '') === '700');
t('مفيش عمولة', !isset($pl['commission']));
t('مفيش صفحات (page_name)', !isset($pl['page_name']) && !isset($pl['page_id']));
t('العنوان زي ما العميل كتبه', (string)($pl['client_address'] ?? '') === 'شارع 9 - عمارة 12', (string)($pl['client_address'] ?? ''));
t('مفاتيح الطلب = الأساسية بس + الحد الأدنى', array_values(array_intersect(array_keys($pl), ['client_name','client_phone1','client_phone2','client_address','note','shipping_governorate','items','total'])) === array_keys($pl), implode(',', array_keys($pl)));
t('ملاحظة', ($pl['note'] ?? '') === 'اتصل قبل التسليم');

// 6) الحالة اتسجلت في الطلب
$saved = $GLOBALS['FILES']['orders.json'][0] ?? [];
t('حالة الطلب = dry', ($saved['safka']['state'] ?? '') === 'dry');
t('safka_state محفوظ', ($saved['safka_state'] ?? '') === 'dry');

// 7) طلبات المنصات التانية بتتخطى
$GLOBALS['FILES']['orders.json'] = [['order_no' => 'X1', 'plat' => 'timehand', 'id' => 'p', 'city' => 'القاهرة', 'total' => 300, 'qty' => 1]];
$r2 = safka_push_order('X1');
t('منتج مش من صفقة → skipped', ($r2['state'] ?? '') === 'skipped', json_encode($r2, JSON_UNESCAPED_UNICODE));

// 8) محافظة غريبة → failed ومش بيرمي exception
$GLOBALS['FILES']['orders.json'] = [['order_no' => 'X2', 'plat' => 'safqa', 'id' => 'prod1', 'city' => 'كوكب', 'total' => 100, 'qty' => 1, 'cphone' => '0100']];
$r3 = safka_push_order('X2');
t('محافظة غريبة → failed برسالة', ($r3['state'] ?? '') === 'failed' && ($r3['error'] ?? '') !== '', json_encode($r3, JSON_UNESCAPED_UNICODE));

// 9) الوضع off + force
$GLOBALS['ST']['safqa_auto_order'] = '0';
t('mode = off', safka_mode() === 'off');
t('can_push = false', safka_can_push() === false);

// 10) كشف رسالة التكرار من صفقة («في طلب زي ده موجود»)
t('رسالة التكرار بتتكشف', safka_is_duplicate_error('في طلب زي ده موجود') === true);
t('رسالة عادية مش تكرار', safka_is_duplicate_error('رقم الهاتف الأول غير صحيح') === false);

echo "\nالنتيجة: $ok ناجح / $bad فاشل\n";
