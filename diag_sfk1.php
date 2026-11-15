<?php
// ملف تشخيص مؤقت لربط صفقة — بيشتغل مرة واحدة وبعدها بيمسح نفسه أوتوماتيك.
if (($_GET['t'] ?? '') !== 'sfk7q1') { http_response_code(404); exit; }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

$out = ['php' => PHP_VERSION, 'curl' => function_exists('curl_init'), 'allow_url_fopen' => (bool)ini_get('allow_url_fopen')];
$key = safka_api_key();
$out['key_len'] = strlen($key);
$out['key_tail'] = $key !== '' ? substr($key, -4) : '';
$out['mode'] = safka_mode();

// 1) الاتصال بقائمة الشحن
$res = safka_http('GET', '/price-list?page=1&size=1', null, 15);
$out['ping'] = ['ok' => $res['ok'], 'status' => $res['status'], 'error' => $res['error']];
$rows = safka_price_list(true);
$out['gov_count'] = count($rows);
$out['gov_sample'] = array_map(function ($r) {
    return ['id' => $r['id'], 'num' => $r['number'], 'label' => $r['label'], 'price' => $r['price'], 'cities' => count($r['cities'])];
}, array_slice($rows, 0, 12));

// 2) مطابقة المحافظات
foreach (['القاهرة', 'الجيزة', 'الإسكندرية', 'أسيوط', 'المنيا', 'الدقهلية'] as $c) {
    $g = safka_find_governorate($c, $rows);
    $out['match'][$c] = $g ? ($g['label'] . ' #' . $g['id'] . ' | ' . $g['price']) : 'NO MATCH';
}

// 3) منتج صفقة + خصائصه
$pid = 'tyshyrt-ns-sosth-shtoy-paTDAI';
$p = safka_product($pid);
$out['product'] = $p ? [
    'name' => mb_substr((string)($p['name'] ?? ''), 0, 40),
    'props' => array_map(function ($x) { return ['id' => $x['_id'] ?? '', 'key' => $x['key'] ?? '']; }, array_slice((array)($p['properties'] ?? []), 0, 10)),
    'active' => $p['is_active'] ?? null,
] : 'NULL';

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
