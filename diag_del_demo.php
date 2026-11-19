<?php
// حذف المتجر التجريبي demo-shop بالكامل (سجّل + منتجاته + صوره + أي طلبات تجريبية)
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'dl9x') { http_response_code(404); echo '{"ok":false}'; exit; }
if (!empty($_GET['cleanup'])) { echo json_encode(['ok' => @unlink(__FILE__)]); exit; }
if (($_GET['go'] ?? '') !== '1') { echo json_encode(['ok' => true, 'dry' => true, 'store' => wfv_store('demo-shop')]); exit; }

$slug = 'demo-shop';
$out = ['ok' => true];

// 1) الطلبات التجريبية بتاعة المتجر
$removed_orders = 0;
jupdate('orders.json', function (&$os) use ($slug, &$removed_orders) {
    $keep = [];
    foreach ((array)$os as $o) {
        $is_demo = (string)($o['store'] ?? '') === $slug || (string)($o['src'] ?? '') === 'store:' . $slug;
        if ($is_demo) { $removed_orders++; continue; }
        $keep[] = $o;
    }
    $os = $keep;
    return true;
}, []);
$out['removed_orders'] = $removed_orders;

// 2) مجلد المنتجات + مجلد الصور
$rm = function ($dir) {
    $n = 0;
    if ($dir === '' || !is_dir($dir)) return 0;
    foreach ((array)glob($dir . '/{,.}*', GLOB_BRACE) as $f) {
        if (is_file($f) && basename($f) !== '.' && basename($f) !== '..') { if (@unlink($f)) $n++; }
    }
    @rmdir($dir);
    return $n;
};
$out['deleted_product_files'] = $rm(wfv_store_dir($exp1 = $slug));
$out['deleted_assets'] = $rm(wfv_store_assets_dir($slug));

// 3) شيل السجل
$removed_store = false;
jupdate('stores.json', function (&$all) use ($slug, &$removed_store) {
    if (!is_array($all)) $all = [];
    if (isset($all[$slug])) { unset($all[$slug]); $removed_store = true; }
    return true;
}, []);
$out['removed_store_record'] = $removed_store;
$out['store_now'] = wfv_store($slug);
$out['stores_left'] = array_keys(wfv_stores_all());
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
