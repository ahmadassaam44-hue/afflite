<?php
// اختبار إنشاء متجر + منتجات + طلب (يتشغّل مرة واحدة)
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'st5q') { http_response_code(404); echo '{"ok":false}'; exit; }

// وضع الحذف الذاتي
if (!empty($_GET['cleanup'])) {
    $self = basename(__FILE__);
    $ok = @unlink(__FILE__);
    echo json_encode(['ok' => $ok, 'deleted' => $self], JSON_UNESCAPED_UNICODE);
    exit;
}

// اختبار إنشاء حساب تاجر + الدور + الدخول، وبعدها حذف الحساب
if (!empty($_GET['test_vendor_account'])) {
    $email = 'vendor-test-' . substr(md5((string)microtime(true)), 0, 6) . '@wafirinhalk.ct.ws';
    $pass = 'Vt' . bin2hex(random_bytes(3)) . 'a1';
    $created = function_exists('usr_create_account') ? usr_create_account($email, $pass, ['name' => 'تاجر تجربة', 'role' => 'vendor', 'created' => date('c')]) : false;
    $role_after = '';
    $login_ok = false;
    if ($created) {
        $u = find_user($email);
        $role_after = $u ? wf_user_role($u) : '(no user)';
        $lg = function_exists('auth_login_profile') ? auth_login_profile($email, $pass) : null;
        $login_ok = !empty($lg);
        if (function_exists('usr_delete_account')) usr_delete_account($email);
    }
    echo json_encode(['created' => $created, 'role' => $role_after, 'login_ok' => $login_ok, 'email' => $email], JSON_UNESCAPED_UNICODE);
    exit;
}

$out = ['ok' => true];
$slug = 'demo-shop';

$out['save_store'] = wfv_store_save($slug, [
    'name' => 'متجر تجريبي', 'tagline' => 'ده متجر تاجر بنفس تصميم الموقع — للتجربة',
    'accent' => '#0ea5e9', 'ship_fee' => 80, 'enabled' => 1,
    'announce' => 'شحن مجاني للطلبات فوق 1000 ج.م', 'phone' => '01202954952', 'whatsapp' => '201202954952',
    'about' => "متجر تجريبي بيوضح شكل متجر التاجر على وفرنهالك.\nنفس التصميم، نفس سرعة الطلب، ومنتجات التاجر نفسه.",
]);
wfv_store_dir($slug, true);
wfv_store_assets_dir($slug, true);

$p1 = wfv_product_save($slug, [
    'name' => 'تيشيرت قطن أوفر سايز', 'price' => 320, 'old_price' => 420, 'category' => 'ملابس رجالي',
    'desc' => 'تيشيرت قطن 100% — مقاسات من S لـ 3XL. الدفع عند الاستلام.',
    'sizes' => 'S, M, L, XL, 2XL', 'colors' => 'أسود, أبيض, بيج', 'stock' => 1, 'active' => 1,
], $e1);
$p2 = wfv_product_save($slug, [
    'name' => 'شوز رياضي خفيف', 'price' => 550, 'category' => 'أحذية',
    'desc' => 'شوز رياضي مريح للاستخدام اليومي.', 'sizes' => '41, 42, 43, 44', 'colors' => 'أسود',
    'stock' => 1, 'active' => 1,
], $e2);
$out['products'] = ['p1' => $p1, 'p2' => $p2, 'errors' => [$e1, $e2], 'count' => count(wfv_products($slug))];

$ono = wfv_add_order($slug, [
    'id' => $p1, 'qty' => 2, 'size' => 'L', 'color' => 'أسود',
    'cname' => 'عميل تجربة', 'cphone' => '01000000000', 'cphone2' => '',
    'city' => 'القاهرة', 'address' => 'شارع التجربة 12 - الدور الثاني', 'notes' => 'طلب اختباري للنظام',
], $oerr);
$out['order'] = ['no' => $ono, 'err' => $oerr];

$found = false;
foreach (wfv_store_orders($slug) as $o) if ((string)($o['order_no'] ?? '') === $ono) $found = true;
$out['order_visible_to_vendor'] = $found;
$out['stats'] = wfv_store_stats($slug);

// شيل الطلب الاختباري من الطلبات الحقيقية
$removed = false;
if ($ono !== '') {
    jupdate('orders.json', function (&$os) use ($ono, &$removed) {
        $keep = [];
        foreach ((array)$os as $o) {
            if ((string)($o['order_no'] ?? '') === $ono) { $removed = true; continue; }
            $keep[] = $o;
        }
        $os = $keep;
        return true;
    }, []);
}
$out['test_order_removed'] = $removed;
$out['store_url'] = 'https://wafirinhalk.ct.ws/vendor.php?store=' . $slug;
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
