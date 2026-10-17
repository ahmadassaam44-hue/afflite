<?php require 'layout.php';
// Shipping configuration remains available to checkout/admin, not as an affiliate section.
if (($user['role'] ?? '') === 'marketer') {
    header('Location: marketer.php?mtab=performance');
    exit;
}
$sh = site_shipping();
$govs = function_exists('site_ship_map') ? site_ship_map() : [];
layout_top('مصاريف الشحن', 'shipping');
if ($govs) {
    echo '<div class="card" data-ui="u-f2235598 d1r"><h3><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-truck></use></svg> أسعار الشحن لجميع المحافظات</h3><p class="mut">التوصيل لباب البيت والدفع عند الاستلام</p><br><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>المحافظة</th><th>الرسوم</th></tr>';
    foreach ($govs as $gov => $fee) {
        echo '<tr><td><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-map></use></svg> ' . esc($gov) . '</td><td><b>' . number_format((float)$fee) . ' جنيه</b></td></tr>';
    }
    echo '</table></div>';
}
$plats = isset($sh['platforms']) && is_array($sh['platforms']) ? $sh['platforms'] : $sh;
if (is_array($plats)) {
    foreach ($plats as $p) {
        if (!is_array($p) || empty($p['name']) || empty($p['regions']) || !is_array($p['regions'])) continue;
        echo '<div class="card" data-ui="u-f2235598 d1r"><h3><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-truck></use></svg> ' . esc($p['name']) . '</h3><p class="mut">المصدر: ' . esc($p['source'] ?? '') . '</p><br><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>المنطقة</th><th>الرسوم</th><th>ملاحظات</th></tr>';
        foreach ($p['regions'] as $r) {
            $fee = ($r['fee'] ?? 0) > 0 ? number_format($r['fee']) . ' ' . esc($r['currency'] ?? 'جنيه') : '—';
            echo '<tr><td>' . esc($r['region'] ?? '') . '</td><td><b>' . $fee . '</b></td><td class="mut">' . esc($r['notes'] ?? '') . '</td></tr>';
        }
        echo '</table></div>';
    }
}
layout_end();
