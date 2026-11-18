<?php
// ملف مؤقت: بيفتح الموقع للزوار (site_closed = 0) وبيمسح نفسه بعد التنفيذ
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'opn5r') { http_response_code(404); echo '{"ok":false}'; exit; }
$done = false;
if (function_exists('jupdate')) {
    jupdate('settings.json', function (&$st) {
        if (!is_array($st)) $st = [];
        $st['site_closed'] = '0';
        return true;
    }, []);
    $done = true;
}
$now = function_exists('site_setting') ? (string)site_setting('site_closed', '?') : '?';
echo json_encode(['ok' => $done, 'site_closed' => $now], JSON_UNESCAPED_UNICODE);
@unlink(__FILE__);
