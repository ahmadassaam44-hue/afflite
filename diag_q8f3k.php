<?php
// ملف تشخيص مؤقت — بيتشال بعد الفحص
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['t'] ?? '') !== 'q8f3k') { http_response_code(404); exit; }

$id  = (string)($_GET['id'] ?? 'tyshyrt-ns-sosth-shtoy-paTDAI');
$key = 'timehand#' . $id;
$ov  = product_overrides();
echo 'overrides_total=' . count($ov) . "\n";
echo 'entry=' . json_encode($ov[$key] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
$hidden = 0; $deleted = 0;
foreach ($ov as $k => $v) {
    if (!empty($v['hidden'])) $hidden++;
    if (!empty($v['deleted'])) $deleted++;
}
echo 'hidden_total=' . $hidden . ' deleted_total=' . $deleted . "\n";
$file = DATA_DIR . '/product_overrides.json';
echo 'file=' . (is_readable($file) ? (int)filesize($file) . 'B md5=' . md5_file($file) : 'none') . "\n";

if (($_GET['unhide'] ?? '') === '1' && isset($ov[$key])) {
    @copy($file, $file . '.bak-' . date('Ymd-His'));
    unset($ov[$key]);
    $ok = jupdate('product_overrides.json', function (&$all) use ($key) {
        if (!is_array($all)) $all = [];
        unset($all[$key]);
        return true;
    }, []);
    $GLOBALS['_WF_PRODUCT_OVERRIDES'] = $ov;
    $p = find_product_by_id($id, 'timehand');
    echo 'unhide_saved=' . ($ok ? 'yes' : 'no') . ' find_after=' . ($p ? ('yes ' . $p['name']) : 'no') . "\n";
}
