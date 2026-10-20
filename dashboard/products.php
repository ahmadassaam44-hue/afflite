<?php $PUBLIC_PAGE = true; require 'layout.php';
// صفحة «الكتالوج» اتشالت من الموقع الأساسي: متاحة لصاحب حساب مسوّق/إدارة بس
if (!function_exists('wf_current_is_marketer') || !wf_current_is_marketer()) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false)) { http_response_code(403); exit; }
    header('Location: ../index.php'); exit;
}
// ===== فصل النوع بالكامل: فيزيكال (افتراضي) vs رقمي لوحده vs سوشيال لوحدها =====
$ptype = $_GET['ptype'] ?? 'physical';
if (!in_array($ptype, ['physical', 'digital', 'social'], true)) $ptype = 'physical';
$DIGITAL_PLATS = ['g2g', 'social'];
$plat_req = $_GET['plat'] ?? 'all';
if ($ptype === 'digital') $plat = 'g2g';
elseif ($ptype === 'social') $plat = 'social';
elseif (in_array($plat_req, $DIGITAL_PLATS, true)) $plat = 'all';
else $plat = $plat_req;

$country = $_GET['country'] ?? 'مصر';
$cat = $_GET['cat'] ?? 'all';
$col = $_GET['col'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 24;

if ($ptype === 'digital') {
    $all = platform_products_lite('g2g');
    if ($cat !== 'all' && $cat !== '') {
        $all = array_values(array_filter($all, function ($x) use ($cat) {
            return ($x['subcategory'] ?? '') === $cat || ($x['category'] ?? '') === $cat;
        }));
    }
    if ($q !== '') {
        $all = array_values(array_filter($all, function ($x) use ($q) {
            return mb_stripos(($x['name'] ?? '') . ' ' . ($x['subcategory'] ?? ''), $q) !== false;
        }));
    }
} elseif ($ptype === 'social') {
    $all = platform_products_lite('social');
    if ($cat !== 'all' && $cat !== '') {
        $all = array_values(array_filter($all, function ($x) use ($cat) {
            $sp = $x['_soc_plat'] ?? social_plat($x['name'] ?? '');
            return $sp === $cat || ($x['subcategory'] ?? '') === $cat;
        }));
    }
    if ($q !== '') {
        $all = array_values(array_filter($all, function ($x) use ($q) {
            return mb_stripos($x['name'] ?? '', $q) !== false;
        }));
    }
} else {
    // فيزيكال: دمج منتج من كل أفلييت جنب بعضه بالتناوب (تايم هاند → صفقة → فيندور → ليدجر → أنجزني)
    $all = physical_collection($col ?: 'all', $country, $cat, $q, $plat);
}

$total = count($all);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$items = array_slice($all, ($page - 1) * $per, $per);

$cats = categories_by_mode($ptype, $country);

$qs = function ($o = []) use ($plat, $country, $cat, $col, $q, $ptype) {
    return '?ptype=' . urlencode($o['ptype'] ?? $ptype)
        . '&plat=' . urlencode($o['p'] ?? $plat)
        . '&country=' . urlencode($o['c'] ?? $country)
        . '&cat=' . urlencode($o['g'] ?? $cat)
        . '&col=' . urlencode($o['col'] ?? $col)
        . '&q=' . urlencode($q);
};

layout_top('المنتجات', 'products');

// تبويب النوع أولاً: 3 أنواع منفصلة تماماً
echo wf_tabs([
    ['href' => '?ptype=physical&country=' . urlencode($country) . '&cat=all', 'label' => 'منتجات', 'icon' => 'box', 'on' => ($ptype === 'physical')],
    ['href' => '?ptype=digital&cat=all', 'label' => 'منتجات رقمية (فوري)', 'icon' => 'game', 'on' => ($ptype === 'digital')],
    ['href' => '?ptype=social&cat=all', 'label' => 'سوشيال ميديا (فوري)', 'icon' => 'megaphone', 'on' => ($ptype === 'social')],
], ['label' => 'نوع المنتجات']);

if ($ptype === 'physical') {
    // فلاتر الدول
    $country_tabs = [];
    $country_tab_defs = [['c' => 'all', 'n' => 'جميع الدول']];
    foreach (wf_country_list() as $country_name) $country_tab_defs[] = ['c' => $country_name, 'n' => $country_name];
    foreach ($country_tab_defs as $c) {
        $country_tabs[] = ['href' => $qs(['c' => $c['c']]), 'label' => $c['n'], 'on' => ($country === $c['c'])];
    }
    echo wf_tabs($country_tabs, ['label' => 'فلترة الدول']);

    // الأقسام الخاصة (المنتجات المميزة / أحدث المنتجات / الأكثر مبيعاً / الأكثر مشاهدة) — كل قسم فيه منتج من كل أفلييت
    $col_tabs = [];
    foreach ([
        '' => 'كل المنتجات',
        'featured' => 'المنتجات المميزة',
        'new' => 'أحدث المنتجات',
        'best' => 'الأكثر مبيعاً',
        'viewed' => 'الأكثر مشاهدة',
    ] as $ck => $clbl) {
        $col_tabs[] = ['href' => $qs(['col' => $ck]), 'label' => $clbl, 'on' => ($col === $ck)];
    }
    echo wf_tabs($col_tabs, ['label' => 'أقسام المنتجات']);
}

// فلاتر التصنيفات الخاصة بالنوع المختار فقط (فيزيكال فقط / رقمي فقط / سوشيال فقط)
if ($cats) {
    $cat_tabs = [['href' => $qs(['g' => 'all']), 'label' => 'كل الأقسام', 'on' => ($cat === 'all')]];
    foreach ($cats as $cn => $ccn) {
        $cat_tabs[] = ['href' => $qs(['g' => $cn]), 'label' => (string)$cn, 'on' => ($cat === (string)$cn)];
    }
    echo wf_tabs($cat_tabs, ['label' => 'أقسام الكتالوج']);
}

$prod_title = $ptype === 'digital' ? 'منتجات رقمية — تسليم فوري' : ($ptype === 'social' ? 'سوشيال ميديا — تنفيذ رقمي' : 'كل المنتجات');
if ($cat !== 'all' && $cat !== '') $prod_title .= ' (' . $cat . ')';
echo wf_catalog_toolbar([
    'title' => $prod_title,
    'meta' => $total ? 'عرض ' . number_format(($page - 1) * $per + 1) . '–' . number_format(min($total, $page * $per)) . ' من ' . number_format($total) . ' منتج' : 'لا توجد منتجات مطابقة',
    'clear_url' => ($q !== '' || ($cat !== 'all' && $cat !== '') || $col !== '' || $country !== 'مصر' || $ptype !== 'physical') ? '?ptype=physical&country=' . urlencode($country) . '&cat=all' : '',
]);
echo '<form class="search" method="get" data-ui="u-9db13808 dh d1s d1p dj"><input type="hidden" name="ptype" value="' . esc($ptype) . '"><input type="hidden" name="plat" value="' . esc($plat) . '"><input type="hidden" name="country" value="' . esc($country) . '"><input type="hidden" name="cat" value="' . esc($cat) . '"><input type="hidden" name="col" value="' . esc($col) . '"><input name="q" value="' . esc($q) . '" placeholder="بحث بالاسم..." data-ui="u-6be42c14 d2i d9q"><button class="btn"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-search></use></svg> بحث</button><button type="button" onclick="window.wfOpenVisualSearch && window.wfOpenVisualSearch()" class="btn outline" data-ui="u-e456f13a d3e d1p di"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-camera></use></svg> بحث بالصورة</button><a href="support.php?ctx=customer" class="btn outline" data-ui="u-133ba71c d3e d1p di d2l">اطلب منتج غير موجود</a></form>';
echo '<p class="mut">' . ($ptype === 'digital' ? 'رقمي — تسليم فوري بدون شحن' : ($ptype === 'social' ? 'سوشيال — تنفيذ رقمي بدون شحن' : 'منتجات — شحن وعنوان')) . '</p><br>';
echo '<div class="grid">';
foreach ($items as $p) pcard($p, $p['_plat']);
echo '</div><div class="pager">';
for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) {
    $c = $i === $page ? ' class="on"' : '';
    echo '<a href="' . $qs() . '&page=' . $i . '"' . $c . '>' . $i . '</a>';
}
echo '</div>';
layout_end();
