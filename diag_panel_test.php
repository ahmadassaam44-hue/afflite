<?php
// اختبار لوحة التاجر (رندر + إضافة منتج + رفع صورة) — بيتشال بعد الاستخدام
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'pn7k') { http_response_code(404); echo '{"ok":false}'; exit; }
if (!empty($_GET['cleanup'])) { echo json_encode(['ok' => @unlink(__FILE__)]); exit; }

error_reporting(E_ALL);
ini_set('display_errors', '0');
$out = ['ok' => true];
$mode = (string)($_GET['mode'] ?? 'render');

// دخول كأدمن (داخل الطلب ده بس)
$admin = null;
foreach (users_list() as $uu) { if ((string)($uu['role'] ?? '') === 'admin') { $admin = $uu; break; } }
if (!$admin) { echo json_encode(['ok' => false, 'err' => 'no admin user']); exit; }
$_SESSION['user'] = ['name' => (string)($admin['name'] ?? 'admin'), 'email' => (string)$admin['email'], 'role' => 'admin'];
$out['admin'] = $admin['email'];
$out['writable_assets_stores'] = is_writable(__DIR__ . '/assets/stores');

// اختبار الكتابة الفعلية في مجلد صور المتجر
$dir = wfv_store_assets_dir('demo-shop', true);
$probe = $dir . '/probe.txt';
$out['assets_dir'] = $dir;
$out['write_probe'] = @file_put_contents($probe, 'ok') !== false;
$out['unlink_probe'] = @unlink($probe);

$_GET = ['store' => 'demo-shop', 'vtab' => $mode === 'settings' ? 'settings' : 'products'];
$_SERVER['SCRIPT_NAME'] = '/dashboard/vendor.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/vendor.php';
if ($mode === 'save') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'csrf_token' => csrf_token(),
        'wfv_act'    => 'save_product',
        'id'         => '',
        'name'       => 'منتج اختبار اللوحة',
        'price'      => '199',
        'old_price'  => '250',
        'category'   => 'اختبار',
        'desc'       => 'منتج بيتشال أوتوماتيك بعد الاختبار',
        'sizes'      => 'M, L',
        'colors'     => 'أسود',
        'stock'      => '1',
        'active'     => '1',
        'ship_fee'   => '',
    ];
}

chdir(__DIR__ . '/dashboard');
ob_start();
try { include 'vendor.php'; } catch (\Throwable $e) { $out['throw'] = $e->getMessage(); }
$html = (string)ob_get_clean();
$out['html_len'] = strlen($html);
$out['saved'] = (strpos($html, 'المنتج اتحفظ') !== false);
$out['has_form'] = (strpos($html, 'wfv_act') !== false);
foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
    if (stripos($html, $needle) !== false) {
        $pos = stripos($html, $needle);
        $out['errors'][] = substr($html, $pos, 220);
    }
}
if ($mode === 'save') {
    $found = null;
    foreach (wfv_products('demo-shop') as $p) if ((string)$p['name'] === 'منتج اختبار اللوحة') $found = $p['id'];
    $out['created_id'] = $found;
    if ($found) $out['deleted'] = wfv_product_delete('demo-shop', $found);
    $out['products_now'] = count(wfv_products('demo-shop'));
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
