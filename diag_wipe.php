<?php
// حذف ملفات التشخيص من الاستضافة ثم حذف نفسه
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'wp2m') { http_response_code(404); echo '{"ok":false}'; exit; }
$gone = []; $fail = [];
foreach (glob(__DIR__ . '/diag_*.php') ?: [] as $f) {
    $b = basename($f);
    if ($b === basename(__FILE__)) continue;
    if (@unlink($f)) $gone[] = $b; else $fail[] = $b;
}
echo json_encode(['ok' => true, 'deleted' => $gone, 'failed' => $fail], JSON_UNESCAPED_UNICODE);
@unlink(__FILE__);
