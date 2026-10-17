<?php
require_once __DIR__ . '/../config.php';
require_admin_or_die();
require 'layout.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fee'])) {
    $ok = false;
    if (SB_SERVICE) {
        $ok = true;
        foreach ($_POST['fee'] as $plat => $regs) {
            foreach ($regs as $region => $fee) {
                $r = sb_admin('PATCH', 'shipping', 'platform=eq.' . urlencode($plat) . '&region=eq.' . urlencode($region), ['fee' => max(0, (float)$fee)]);
                if ($r === null) { $ok = false; break 2; }
            }
        }
        @unlink(DATA_DIR . '/cache_shipping.json');
    }
    // نسخة محلية دائماً
    $sh = jload('shipping.json', []);
    foreach ($sh as &$p) {
        if (isset($_POST['fee'][$p['platform']])) foreach ($p['regions'] as &$rg) {
            if (isset($_POST['fee'][$p['platform']][$rg['region']])) $rg['fee'] = max(0, (float)$_POST['fee'][$p['platform']][$rg['region']]);
        }
        unset($rg);
    }
    unset($p);
    jsave('shipping.json', $sh);
    @unlink(DATA_DIR . '/cache_shipping.json');
    $msg = $ok ? 'تم الحفظ على Supabase ✅ يسمع فوراً على الموقع' : (SB_SERVICE ? 'تعذر الكتابة على Supabase — حُفظ محلياً' : 'حُفظ محلياً (ضع SB_SERVICE للتحديث المباشر)');
}
layout_top('إدارة الشحن ', 'shipping');
if ($msg) echo '<div role="status" data-save-feedback class="card" data-ui="u-9e82dcff d54">' . esc($msg) . '</div>';
echo '<div class="card"><p class="mut">الأسعار الحالية من Supabase. لإعداد التحديث المباشر تأكد من ضبط <b>SB_SERVICE</b> في إعدادات الخادم.</p><br><form method="post">';
foreach (site_shipping() as $p) {
    echo '<h3><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-truck></use></svg> ' . esc($p['name']) . '</h3><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>المنطقة</th><th>الرسوم</th><th></th></tr>';
    foreach ($p['regions'] as $rg) {
        echo '<tr><td>' . esc($rg['region']) . '</td><td><input name="fee[' . esc($p['platform']) . '][' . esc($rg['region']) . ']" type="number" step="0.5" min="0" value="' . esc($rg['fee'] ?? 0) . '" data-ui="u-d16c01ab d63 d64 d65 d66"></td><td class="mut">' . esc($rg['currency'] ?? '') . '</td></tr>';
    }
    echo '</table><br>';
}
echo '<button class="btn pri"> حفظ الرسوم</button></form></div>';
layout_end();
