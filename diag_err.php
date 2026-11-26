<?php
// عرض آخر الأخطاء الفادحة المسجّلة (بيتشال بعد الاستخدام)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'er5d') { http_response_code(404); echo '{"ok":false}'; exit; }
if (!empty($_GET['cleanup'])) { echo json_encode(['ok' => @unlink(__FILE__)]); exit; }
$path = __DIR__ . '/data/wf_errors.json';
$rows = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
if (!is_array($rows)) $rows = [];
$n = (int)($_GET['n'] ?? 30);
$out = [
    'ok' => true,
    'log_exists' => is_file($path),
    'count' => count($rows),
    'mem_limit' => ini_get('memory_limit'),
    'time_limit' => ini_get('max_execution_time'),
    'errors' => array_slice($rows, -$n),
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
