<?php
// تشخيص مؤقت: قياس أداء المسارات التقيلة على الاستضافة (بيحذف نفسه بعد أول طلب).
if (($_GET['t'] ?? '') !== 'perf5k') { http_response_code(404); exit; }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
$t0 = microtime(true);
$out = [];
$mark = function ($k) use (&$out, $t0) { $out[$k] = round((microtime(true) - $t0) * 1000) . ' ms'; };

$id = (string)($_GET['id'] ?? 'tyshyrt-ns-sosth-shtoy-paTDAI');
$p = find_product_by_id($id);
$mark('find_product_by_id');
$out['found'] = $p ? 'yes' : 'no';

$rows = safka_price_list();
$mark('safka_price_list (cached)');
$out['govs'] = count($rows);

$o = (array)jload('orders.json', [], false);
$out['orders_count'] = count($o);
$mark('load orders.json');

$t = microtime(true);
jupdate('orders.json', function (&$arr) { return true; }, []);
$out['orders_write_ms'] = round((microtime(true) - $t) * 1000);
$mark('write orders.json');

$out['mem_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
$out['data_dir_mb'] = round(array_sum(array_map(function ($f) { return is_file($f) ? filesize($f) : 0; }, glob(DATA_DIR . '/*'))) / 1048576, 1);
$out['users'] = count((array)jload('users.json', [], false));
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
