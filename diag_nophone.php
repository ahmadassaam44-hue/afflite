<?php
// مسح رقم/واتساب الدعم من إعدادات الموقع — بيتشال بعد الاستخدام
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'np4w') { http_response_code(404); echo '{"ok":false}'; exit; }
if (!empty($_GET['cleanup'])) { echo json_encode(['ok' => @unlink(__FILE__)]); exit; }
$out = ['ok' => true];
$before = jload('settings.json', []);
$out['before'] = ['phone' => $before['phone'] ?? null, 'whatsapp' => $before['whatsapp'] ?? null];
$out['wa_href_before'] = function_exists('wf_support_wa_href') ? (wf_support_wa_href() !== '') : null;
if (($_GET['go'] ?? '') === '1') {
    $saved = jupdate('settings.json', function (&$s) {
        if (!is_array($s)) $s = [];
        $s['phone'] = '';
        $s['whatsapp'] = '';
        return true;
    }, []);
    $out['saved'] = $saved;
    $after = jload('settings.json', []);
    $out['after'] = ['phone' => $after['phone'] ?? null, 'whatsapp' => $after['whatsapp'] ?? null];
    $out['wa_href_after'] = function_exists('wf_support_wa_href') ? (wf_support_wa_href() !== '') : null;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
