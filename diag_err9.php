<?php
// لمرة واحدة: آخر أخطاء الموقع المسجلة (بيحذف نفسه)
if (($_GET['t'] ?? '') !== 'err9q') { http_response_code(404); exit; }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
$rows = jload('wf_errors.json', [], false);
$rows = is_array($rows) ? array_slice(array_values($rows), -6) : [];
$out = [];
foreach ($rows as $r) {
    $out[] = ['at' => substr((string)($r['at'] ?? ''), 0, 19), 'uri' => (string)($r['uri'] ?? ''),
              'file' => basename((string)($r['file'] ?? '')) . ':' . (string)($r['line'] ?? ''),
              'msg' => mb_substr((string)($r['msg'] ?? ''), 0, 220)];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@unlink(__FILE__);
