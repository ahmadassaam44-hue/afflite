<?php
// لمرة واحدة: تشغيل وضع الإغلاق (بيحذف نفسه). للحاجة: زوّد ?off=1 للفتح تاني.
define('WF_NO_CLOSURE', true);
if (($_GET['t'] ?? '') !== 'cls7k') { http_response_code(404); exit; }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
$want = (($_GET['off'] ?? '') === '1') ? '0' : '1';
jupdate('settings.json', function (&$st) use ($want) { if (!is_array($st)) $st = []; $st['site_closed'] = $want; return true; }, []);
$now = jload('settings.json', [], false);
echo json_encode(['ok' => true, 'site_closed' => (string)($now['site_closed'] ?? '?')], JSON_UNESCAPED_UNICODE);
@unlink(__FILE__);
