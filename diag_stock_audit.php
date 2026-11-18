<?php
// تدقيق مخزون/إتاحة المنتجات في ملفات الكتالوج الحية (قراءة فقط)
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'stk9x') { http_response_code(404); echo '{"ok":false}'; exit; }

$skip = ['orders.json','deleted_orders.json','payouts.json','settings.json','audit_logs.json','balance_ledger.json','shipping.json'];
$out = ['at' => date('c'), 'files' => [], 'totals' => ['items'=>0,'stock0'=>0,'unavail'=>0,'closed'=>0,'allsoldout'=>0]];
$flag_keys = ['closed','is_closed','blocked','disabled','is_blocked','out_of_stock'];
foreach (glob(DATA_DIR . '/*.json') ?: [] as $f) {
    $base = basename($f);
    if (in_array($base, $skip, true)) continue;
    $size = @filesize($f);
    if (!$size || $size < 1000 || $size > 25*1024*1024) continue;
    $d = json_decode((string)@file_get_contents($f), true);
    if (!is_array($d) || !$d) continue;
    $items = array_values($d);
    $f0 = $items[0] ?? null;
    if (!is_array($f0) || !array_key_exists('id', $f0) || (!array_key_exists('name', $f0) && !array_key_exists('price', $f0))) continue;

    $c = ['items'=>0,'stock0'=>0,'unavail'=>0,'closed'=>0,'allsoldout'=>0,'flags'=>[],'samples'=>[],'mtime'=>date('c', (int)@filemtime($f))];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $c['items']++;
        $st = $it['stock'] ?? null;
        $is0 = (is_numeric($st) && (float)$st <= 0);
        if ($is0) { $c['stock0']++; }
        if (!empty($it['unavailable_since'])) { $c['unavail']++; }
        foreach ($flag_keys as $k) { if (!empty($it[$k])) { $c['closed']++; $c['flags'][$k] = ($c['flags'][$k] ?? 0) + 1; break; } }
        if (isset($it['is_active']) && $it['is_active'] === false) { $c['closed']++; $c['flags']['is_active'] = ($c['flags']['is_active'] ?? 0) + 1; }
        if (isset($it['available']) && $it['available'] === false) { $c['closed']++; $c['flags']['available'] = ($c['flags']['available'] ?? 0) + 1; }
        if (isset($it['availability']) && $it['availability'] === false) { $c['closed']++; $c['flags']['availability'] = ($c['flags']['availability'] ?? 0) + 1; }
        if (isset($it['status']) && is_string($it['status'])) {
            $s = strtolower($it['status']);
            if (in_array($s, ['closed','sold_out','soldout','inactive','unavailable','out_of_stock','disabled'], true)) {
                $c['closed']++; $c['flags']['status:' . $s] = ($c['flags']['status:' . $s] ?? 0) + 1;
            }
        }
        $vs = $it['extra']['variants_detailed'] ?? null;
        if (is_array($vs) && $vs) {
            $sold = 0; $seen = 0;
            foreach ($vs as $v) { if (!is_array($v) || !array_key_exists('stock', $v)) continue; $seen++; if ((int)$v['stock'] === 0) $sold++; }
            if ($seen > 0 && $sold === $seen) { $c['allsoldout']++; }
        }
        if ((count($c['samples']) < 4) && ($is0 || !empty($it['unavailable_since']) || !empty($it['closed']))) {
            $c['samples'][] = ['id' => (string)($it['id'] ?? ''), 'name' => mb_substr((string)($it['name'] ?? ''), 0, 60), 'stock' => $st, 'since' => $it['unavailable_since'] ?? null];
        }
    }
    $out['files'][$base] = $c;
    foreach (['items','stock0','unavail','closed','allsoldout'] as $k) $out['totals'][$k] += $c[$k];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
