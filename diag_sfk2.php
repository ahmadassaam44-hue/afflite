<?php
// تشخيص مؤقت (رقم 2) لربط صفقة — بيشتغل مرة واحدة وبعدها بيمسح نفسه.
if (($_GET['t'] ?? '') !== 'sfk7q2') { http_response_code(404); exit; }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
$out = [];

// 1) منتج واحد بالمعرّف — عايزين نتأكد من شكل الـ properties
$ids = array_slice(array_column((array)jload('safqa.json', []), 'id'), 0, 3);
foreach ($ids as $pid) {
    $r = safka_http('GET', '/product/' . rawurlencode($pid), null, 15);
    $body = is_array($r['body']) ? $r['body'] : [];
    $p = $body['product'] ?? $body;
    $props = [];
    foreach ((array)($p['properties'] ?? []) as $x) {
        $props[] = ['id' => $x['_id'] ?? '', 'key' => $x['key'] ?? '', 'value' => $x['value'] ?? null];
    }
    $out['products'][$pid] = [
        'status' => $r['status'], 'ok' => $r['ok'], 'error' => $r['error'],
        'name' => mb_substr((string)($p['name'] ?? ''), 0, 45),
        'props' => $props,
        'body_head' => $r['ok'] ? '' : mb_substr(is_string($r['body']) ? $r['body'] : json_encode($r['body'], JSON_UNESCAPED_UNICODE), 0, 200),
    ];
}

// 2) بروب على /orders ببيانات غير صالحة — عشان نشوف رسايل التحقق (مابيتعملش طلب)
$probe = [
    'client_name' => 'x', 'client_phone1' => '1', 'client_address' => 'x',
    'shipping_governorate' => '64879004dc16090c1858e69e', 'city' => null,
    'commission' => 0, 'total' => '0', 'items' => [], 'page_name' => 'probe',
];
$r1 = safka_http('POST', '/orders', $probe, 20);
$out['probe_valid_gov'] = ['status' => $r1['status'], 'error' => $r1['error'], 'body' => $r1['body']];

// وبمعرّف محافظة بايظ
$probe2 = $probe; $probe2['shipping_governorate'] = 'bogus-id-123';
$r2 = safka_http('POST', '/orders', $probe2, 20);
$out['probe_bogus_gov'] = ['status' => $r2['status'], 'error' => $r2['error'], 'body' => $r2['body']];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
