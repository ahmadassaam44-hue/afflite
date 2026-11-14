<?php
// ملف تشخيص مؤقت — بيتشال بعد الفحص
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['t'] ?? '') !== 'q8f3k') { http_response_code(404); exit; }
echo 'PHP=' . PHP_VERSION . ' mem=' . ini_get('memory_limit') . ' DATA_DIR=' . DATA_DIR . "\n";
foreach (['timehand.json', 'lite_timehand.json', 'id_index.json'] as $f) {
    $p = DATA_DIR . '/' . $f;
    echo $f . ' size=' . (int)@filesize($p) . ' readable=' . (is_readable($p) ? 1 : 0) . ' md5=' . (is_readable($p) ? md5_file($p) : '-') . "\n";
}
$id = 'tyshyrt-ns-sosth-shtoy-paTDAI';
$raw = wf_catalog_raw('timehand');
echo 'raw_len=' . strlen($raw) . ' needle_pos=' . var_export(strpos($raw, '"' . $id . '"'), true) . "\n";
$rec = wf_catalog_find_record('timehand', $id);
echo 'record=' . ($rec ? ('yes ' . ($rec['name'] ?? '')) : 'no') . "\n";
$nr = $rec ? normalize_product_record($rec, 'timehand') : null;
echo 'normalized=' . ($nr ? 'yes' : (is_array($nr) ? 'array' : 'null')) . "\n";
$ov = $rec ? apply_product_override_record($nr, 'timehand') : null;
echo 'override=' . ($ov ? 'yes' : 'null') . "\n";
$p2 = find_product_by_id($id, 'timehand');
echo 'find=' . ($p2 ? ('yes ' . ($p2['name'] ?? '')) : 'no') . "\n";
$idx = jload('id_index.json', []);
echo 'index_entry=' . var_export($idx[$id] ?? null, true) . "\n";
$liteHit = 0;
foreach (platform_products_lite('timehand') as $row) if ((string)($row['id'] ?? '') === $id) $liteHit++;
echo 'lite_hits=' . $liteHit . ' lite_count=' . count(platform_products_lite('timehand')) . "\n";
