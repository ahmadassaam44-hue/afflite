<?php
// تشخيص مؤقت: هل الطلبات اللي في صفقة (1335 / 1345) جاية من ربط الموقع؟
// بيطابق أرقام صفقة مع سجل الطلبات عندنا — من غير أي بيانات عملاء (مفيش أسماء ولا أرقام).
// بيحذف نفسه بعد أول طلب.
if (($_GET['t'] ?? '') !== 'sfk9z4') { http_response_code(404); exit; }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

$out = [];
$key = function_exists('safka_api_key') ? safka_api_key() : '';
$out['key'] = ['len' => strlen($key), 'first3' => substr($key, 0, 3), 'last4' => substr($key, -4), 'mode' => function_exists('safka_mode') ? safka_mode() : '?'];

// سجل عمليات صفقة عندنا
$log = (array)jload('safka_log.json', [], false);
$tail = array_slice(array_values($log), -15);
$out['log_total'] = count($log);
$out['log'] = array_map(function ($e) {
    return [
        'at' => $e['at'] ?? '', 'what' => $e['what'] ?? '',
        'order_no' => $e['order_no'] ?? '', 'serial' => $e['serial'] ?? '',
        'error' => mb_substr((string)($e['error'] ?? ''), 0, 90),
    ];
}, $tail);

// طلبات صفقة عندنا + أرقام صفقة المرتبطة بيها
$saf_orders = [];
foreach ((array)jload('orders.json', [], false) as $o) {
    if ((string)($o['plat'] ?? '') !== 'safqa') continue;
    $saf_orders[] = [
        'order_no' => $o['order_no'] ?? '',
        'at'       => substr((string)($o['at'] ?? ''), 0, 16),
        'total'    => $o['total'] ?? 0,
        'state'    => $o['safka']['state'] ?? '—',
        'serial'   => $o['safka']['serial'] ?? ($o['safka_serial'] ?? ''),
        'err'      => mb_substr((string)($o['safka']['error'] ?? ''), 0, 90),
    ];
}
$out['safqa_orders_total'] = count($saf_orders);
$out['safqa_orders_last'] = array_slice($saf_orders, -10);
$out['has_1335'] = (bool)array_filter($saf_orders, function ($o) { return (string)$o['serial'] === '1335'; });
$out['has_1345'] = (bool)array_filter($saf_orders, function ($o) { return (string)$o['serial'] === '1345'; });

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
