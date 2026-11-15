<?php
// تشخيص مؤقت: ليه منتج «تيشيرت نص سوسته شتوي» مش بيتلاقى على السيرفر — بيحذف نفسه بعد أول طلب.
if (($_GET['t'] ?? '') !== 'ov7x2') { http_response_code(404); exit; }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
$id = 'tyshyrt-ns-sosth-shtoy-paTDAI';
$out = [];

$p = find_product_by_id($id);
$out['find_no_hint'] = $p ? ['ok' => true, 'plat' => $p['_plat'] ?? '', 'name' => $p['name'] ?? ''] : 'NULL';
$p2 = find_product_by_id($id, 'timehand');
$out['find_timehand'] = $p2 ? ['ok' => true, 'plat' => $p2['_plat'] ?? '', 'name' => $p2['name'] ?? ''] : 'NULL';

$ov = jload('product_overrides.json', [], false);
$out['overrides_total'] = is_array($ov) ? count($ov) : 0;
$out['override_entry'] = $ov['timehand#' . $id] ?? 'MISSING';
$out['override_keys_sample'] = is_array($ov) ? array_slice(array_keys($ov), 0, 5) : [];

$raw = wf_catalog_raw('timehand');
$out['raw_len'] = strlen((string)$raw);
$out['in_raw'] = strpos((string)$raw, '"' . $id . '"') !== false;
$idx = jload('id_index.json', [], false);
$out['id_index'] = $idx[$id] ?? 'MISSING';
$out['plat_disabled'] = function_exists('wf_platform_disabled') ? wf_platform_disabled('timehand') : null;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
