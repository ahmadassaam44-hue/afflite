<?php
// تشخيص: مين مصدر الرقم والتاج على السيرفر — بيتشال بعد الاستخدام
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'sr7c') { http_response_code(404); echo '{"ok":false}'; exit; }
if (!empty($_GET['cleanup'])) { echo json_encode(['ok' => @unlink(__FILE__)]); exit; }
$out = ['ok' => true];

// 1) ملف index.php على السيرفر — فيه إيه؟
$idx = (string)@file_get_contents(__DIR__ . '/index.php');
$out['index_size'] = strlen($idx);
$out['index_md5'] = md5($idx);
if (preg_match_all('/impact-site-verification"\s+value="([^"]*)"/', $idx, $m)) $out['index_impact_values'] = $m[1];
$out['index_has_fe2ca6e6'] = strpos($idx, 'fe2ca6e6') !== false;
$out['index_has_b55678b9'] = strpos($idx, 'b55678b9') !== false;
$out['index_has_phone'] = strpos($idx, '01202954952') !== false;
if (preg_match('/\$top_phone\s*=\s*([^;]+);/', $idx, $m2)) $out['index_top_phone_line'] = substr($m2[0], 0, 160);

// 2) الصفحة اللي بتتخدّم فعلاً (fetch داخلي مع كسر الكاش)
$ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'header' => "User-Agent: DiagBot/1.0\r\n"]]);
$html = (string)@file_get_contents('https://wafirinhalk.ct.ws/?cb=' . time(), false, $ctx);
$out['served_size'] = strlen($html);
if (preg_match_all('/impact-site-verification"\s+value="([^"]*)"/', $html, $m3)) $out['served_impact_values'] = $m3[1];
$out['served_phone_hits'] = substr_count($html, '01202954952');
$ctxs = [];
$pos = 0;
while (($p = strpos($html, '01202954952', $pos)) !== false && count($ctxs) < 4) {
    $ctxs[] = substr($html, max(0, $p - 110), 220);
    $pos = $p + 11;
}
$out['served_phone_context'] = $ctxs;

// 3) الإعدادات
$st = @json_decode((string)@file_get_contents(__DIR__ . '/data/settings.json'), true) ?: [];
$out['settings'] = ['phone' => $st['phone'] ?? null, 'whatsapp' => $st['whatsapp'] ?? null, 'wa_admin_to' => $st['wa_admin_to'] ?? null];

// 4) هل فيه نسخة تانية من index.php في مكان مختلف؟
$roots = [__DIR__, __DIR__ . '/htdocs', __DIR__ . '/public_html', dirname(__DIR__) . '/htdocs'];
foreach ($roots as $r) { if (@is_file($r . '/index.php')) $out['found_index'][] = $r . '/index.php (' . filesize($r . '/index.php') . ' bytes)'; }
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
