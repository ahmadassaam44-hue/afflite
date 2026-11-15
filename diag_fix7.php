<?php
// إصلاح مؤقت: إرجاع منتج «تيشيرت نص سوسته شتوي» (شيل علم الحذف من overrides) — بيحذف نفسه بعد أول طلب.
// بيعمل نسخة احتياطية من الملف قبل أي تعديل.
if (($_GET['t'] ?? '') !== 'fx7x3') { http_response_code(404); exit; }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
$id = 'tyshyrt-ns-sosth-shtoy-paTDAI';
$key = 'timehand#' . $id;
$out = [];

$before = find_product_by_id($id, 'timehand');
$out['before'] = $before ? ['found' => true, 'name' => $before['name'] ?? ''] : 'NULL';

$ov_path = DATA_DIR . '/product_overrides.json';
$out['backup'] = @copy($ov_path, $ov_path . '.bak-' . date('Ymd-His'));

$out['removed'] = jupdate('product_overrides.json', function (&$ov) use ($key) {
    if (!is_array($ov)) return false;
    if (!isset($ov[$key])) return false;
    unset($ov[$key]);
    return true;
}, []);

$after = find_product_by_id($id, 'timehand');
$out['after'] = $after ? ['found' => true, 'name' => $after['name'] ?? '', 'plat' => $after['_plat'] ?? ''] : 'NULL';
$out['overrides_total_now'] = count((array)jload('product_overrides.json', [], false));

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
