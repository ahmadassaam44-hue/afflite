<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ui-components.php';

$cu = current_user();
if ($cu && !empty($cu['email']) && ($cu['role'] ?? '') !== 'admin') {
    ensure_user_affiliate($cu['email']);
    $cu = current_user();
}

$store_code = trim((string)($_GET['store'] ?? ($_GET['ref'] ?? '')));
// الرابط المختصر للمتجر (slug) بيوصل لنفس المتجر: store.php?store=ahmed-essam
if ($store_code !== '' && !find_marketer_by_code($store_code)) {
    $slug_hit = marketer_store_by_slug($store_code);
    if ($slug_hit) {
        $store_code = $slug_hit['ref_code'];
        if (!headers_sent()) {
            @setcookie('aff_ref', $store_code, time() + 30 * 86400, '/');
        }
        $_SESSION['aff_ref'] = $store_code;
    }
}
if ($store_code === '' && $cu) {
    $me_u = find_user($cu['email']);
    $store_code = $me_u['ref_code'] ?? '';
}
if ($store_code === '') {
    $store_code = active_ref();
}

$mk_owner = $store_code !== '' ? find_marketer_by_code($store_code) : null;
if ($mk_owner && !empty($mk_owner['ref_code'])) {
    $store_code = $mk_owner['ref_code'];
    $_SESSION['aff_ref'] = $store_code;
    if (!headers_sent()) {
        @setcookie('aff_ref', $store_code, time() + 30 * 86400, '/');
    }
}

$is_owner = ($cu && $mk_owner && strcasecmp($cu['email'] ?? '', $mk_owner['email'] ?? '') === 0);

// حفظ إعدادات المتجر أو تعديل عمولة منتج داخل المتجر
$save_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_owner && $store_code !== '') {
    $st_cur = marketer_store_get($store_code);
    if (isset($_POST['save_store_settings'])) {
        $st_res = marketer_store_save_from_post($store_code, $_POST);
        $save_msg = !empty($st_res['warn'])
            ? '⚠️ رابط المتجر اللي كتبته محجوز لمتجر تاني، سيبناه زي ما هو'
            : '✅ تم حفظ إعدادات متجرك بنجاح!';
    } elseif (isset($_POST['update_prod_comm'])) {
        $pkey = trim((string)($_POST['pkey'] ?? ''));
        $pkey_plat = (strpos($pkey, '#') !== false) ? explode('#', $pkey, 2)[0] : '';
        // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة — أي قيمة بتتصفّر
        $new_comm = wf_is_digital_plat($pkey_plat) ? 0.0 : max(0, min((float)MAX_MARKUP_AMOUNT, round((float)($_POST['extra_comm'] ?? 0), 2)));
        $prods = $st_cur['products'] ?? [];
        if ($pkey !== '' && strpos($pkey, '#') !== false) {
            list($ppl, $ppid) = explode('#', $pkey, 2);
            if (!empty($_POST['remove_item'])) {
                unset($prods[$pkey]);
                unset($_SESSION['aff_custom_comm'][$store_code . '#' . $pkey]);
                $save_msg = '🗑️ تم حذف المنتج من متجرك.';
            } else {
                $prods[$pkey] = [
                    'plat'       => $ppl,
                    'id'         => $ppid,
                    'extra_comm' => $new_comm,
                    'added_at'   => $prods[$pkey]['added_at'] ?? date('c'),
                ];
                $_SESSION['aff_custom_comm'][$store_code . '#' . $pkey] = $new_comm;
                $save_msg = wf_is_digital_plat($ppl)
                    ? '✅ تم حفظ المنتج — سعر ثابت من غير عمولة مضافة.'
                    : '✅ تم تحديث عمولتك للمنتج إلى +' . number_format($new_comm, 0) . ' ج.م';
            }
            marketer_store_save($store_code, ['products' => $prods]);
        }
    }
}

// حدث زيارة متجر (يغذّي إحصاءات لوحة المسوق: زوار المتجر وطلباته)
if ($store_code !== '' && !$is_owner && function_exists('wf_event')) {
    wf_event('store_view', ['store' => $store_code, 'path' => mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 200)]);
}
if ($store_code !== '') wf_traffic_src('store');
$store_cfg = marketer_store_get($store_code);
$store_sec = $store_cfg['sections'] ?? marketer_store_sections_default();
$store_dark = (($store_cfg['mode'] ?? 'light') === 'dark');
$theme_color = $store_cfg['theme'] ?: '#059669';
$theme_fg   = wf_contrast_fg($theme_color);   // لون النص المناسب فوق لون المتجر
$theme_ink  = wf_theme_ink($theme_color);    // لون مقروء دايماً لاستخدامه كنص على خلفية بيضاء
$q = trim((string)($_GET['q'] ?? ''));
$cat_filter = trim((string)($_GET['cat'] ?? 'all'));
$show_all_catalog = !empty($_GET['all']);
// فلاتر المجموعات اللي المسوق بيختار لها المنتجات بنفسه (مميز / الأحدث / الأكثر مبيعاً)
$store_coll_defs = ['featured' => 'المنتجات المميزة', 'new' => 'أحدث المنتجات', 'best' => 'الأكثر مبيعاً', 'viewed' => 'الأكثر مشاهدة'];
$col_filter = trim((string)($_GET['col'] ?? ''));
if (!in_array($col_filter, array_keys($store_coll_defs), true)) $col_filter = '';

// بناء قائمة منتجات المتجر
$store_items = [];
$saved_prods = $store_cfg['products'] ?? [];
if (!empty($saved_prods) && !$show_all_catalog) {
    foreach ($saved_prods as $pkey => $pmeta) {
        $ppl = $pmeta['plat'] ?? '';
        $ppid = (string)($pmeta['id'] ?? '');
        if ($ppid === '' && strpos($pkey, '#') !== false) {
            list($ppl, $ppid) = explode('#', $pkey, 2);
        }
        $found = find_product_by_id($ppid, $ppl);
        if ($found) {
            $found['_plat'] = $found['_plat'] ?? ($ppl ?: resolve_plat($ppid));
            $found['_mk_extra_comm'] = wf_is_digital_plat($found['_plat'] ?? $ppl) ? 0.0 : max(0, (float)($pmeta['extra_comm'] ?? 0));
            $found['_mk_tags'] = array_values(array_intersect(array_map('strval', (array)($pmeta['tags'] ?? [])), array_keys($store_coll_defs)));
            $found['_in_mk_store'] = true;
            $store_items[] = $found;
        }
    }
}

$using_curated = !empty($store_items);
if (empty($store_items)) {
    $pool = physical_collection('featured', 'مصر', $cat_filter !== 'all' ? $cat_filter : 'all', $q);
    foreach (array_slice($pool, 0, 48) as $it) {
        $pl = $it['_plat'] ?? resolve_plat($it['id'] ?? '');
        $pkey = $pl . '#' . ($it['id'] ?? '');
        $it['_mk_extra_comm'] = wf_is_digital_plat($pl) ? 0.0 : (isset($saved_prods[$pkey]['extra_comm']) ? (float)$saved_prods[$pkey]['extra_comm'] : 0.0);
        $it['_mk_tags'] = array_values(array_intersect(array_map('strval', (array)($saved_prods[$pkey]['tags'] ?? [])), array_keys($store_coll_defs)));
        $it['_in_mk_store'] = isset($saved_prods[$pkey]);
        $store_items[] = $it;
    }
} else {
    if ($cat_filter !== '' && $cat_filter !== 'all') {
        $store_items = array_values(array_filter($store_items, function ($x) use ($cat_filter) {
            return wf_normalize_category($x['category'] ?? '') === $cat_filter || wf_normalize_category($x['subcategory'] ?? '', '', false) === $cat_filter;
        }));
    }
    if ($q !== '') {
        $store_items = array_values(array_filter($store_items, function ($x) use ($q) {
            return mb_stripos(($x['name'] ?? '') . ' ' . wf_normalize_category($x['category'] ?? ''), $q) !== false;
        }));
    }
}

// كل منتجات المتجر قبل فلتر المجموعة — بتُستخدم في عرض أقسام الصفحة الرئيسية
$store_items_all = $store_items;
if ($col_filter !== '') {
    $store_items = array_values(array_filter($store_items, function ($x) use ($col_filter) {
        return in_array($col_filter, (array)($x['_mk_tags'] ?? []), true);
    }));
}

// ترتيب منتجات المتجر (نفس ترتيب الكتالوج في الصفحة الرئيسية)
$store_sort = trim((string)($_GET['sort'] ?? ''));
if (!in_array($store_sort, ['', 'default', 'price_asc', 'price_desc', 'name'], true)) $store_sort = '';
if ($store_sort === 'price_asc' || $store_sort === 'price_desc') {
    $store_price_of = function ($item) {
        $pl = $item['_plat'] ?? resolve_plat($item['id'] ?? '');
        $base = list_price($item, $pl) ?: display_price($item, $pl);
        return round((float)$base + (float)($item['_mk_extra_comm'] ?? 0), 2);
    };
    usort($store_items, function ($a, $b) use ($store_sort, $store_price_of) {
        return $store_sort === 'price_asc' ? ($store_price_of($a) <=> $store_price_of($b)) : ($store_price_of($b) <=> $store_price_of($a));
    });
} elseif ($store_sort === 'name') {
    usort($store_items, function ($a, $b) {
        return strnatcasecmp(aff_clean($a['name'] ?? '', true), aff_clean($b['name'] ?? '', true));
    });
}

// استخراج الأقسام المتاحة في المتجر
$store_cats = [];
foreach ($store_items as $sit) {
    $c = wf_normalize_category($sit['category'] ?? '');
    if ($c !== '') $store_cats[$c] = ($store_cats[$c] ?? 0) + 1;
}

// كارت منتج واحد في المتجر — نفس الشكل في القائمة الرئيسية وفي أقسام المجموعات
// (المنتجات الرقمية/السوشيال وكل منتجات المتجر بدون بلوك «صور وفيديوهات المنتج» وبدون روابط درايف)
function wf_store_card_html($p, $store_code) {
    ob_start();

    $plat = $p['_plat'] ?? resolve_plat($p['id'] ?? '');
    $pid = (string)($p['id'] ?? '');
    if (empty($p['desc'])) {
        $full_p = find_product_by_id($pid, $plat);
        if ($full_p) {
            $p = array_merge($full_p, $p);
        }
    }
    $pkey = $plat . '#' . $pid;
    $clean_name = aff_clean($p['name'] ?? '', true);
    $img = product_first_image($p, $plat);
    $base_p = list_price($p, $plat) ?: display_price($p, $plat);
    $extra_c = wf_is_digital_plat($plat) ? 0.0 : (float)($p['_mk_extra_comm'] ?? marketer_store_product_comm($store_code, $plat, $pid));
    $final_p = round($base_p + $extra_c, 2);
    $old_p = round($final_p * 1.25);
    $cols = array_slice(clean_product_colors($p, $plat), 0, 15);
    $sizes = array_slice(clean_product_sizes($p, $plat), 0, 20);
    $short_desc = product_marketing_summary($p, $plat);
    if (mb_strlen($short_desc) > 95) $short_desc = mb_substr($short_desc, 0, 95) . '...';
    // المنتجات الرقمية وخدمات السوشيال تُطلب من صفحة الطلب السريع (بدون شحن أو عنوان)
    $is_digital_item = wf_is_digital_plat($plat);
    $landing_url = $is_digital_item
        ? 'dashboard/quick_order.php?id=' . urlencode($pid) . '&ref=' . urlencode($store_code)
        : 'landing.php?id=' . urlencode($pid) . '&ref=' . urlencode($store_code) . ($extra_c > 0 ? '&comm=' . $extra_c : '');
    $eo_payload = json_encode([
        'id' => $pid,
        'plat' => $plat,
        'name' => $clean_name,
        'price' => $final_p,
        'base_price' => $base_p,
        'extra_comm' => $extra_c,
        'img' => $img,
        'ref' => $store_code,
        'currency' => wf_ui_currency($p),
        'colors' => $cols,
        'sizes' => $sizes,
    ], JSON_UNESCAPED_UNICODE);
  ?>
  <div class="eo-card">
    <a href="<?php echo esc($landing_url); ?>" class="eo-card-img">
      
      <span class="eo-cod-badge"><?php echo $is_digital_item ? 'تسليم فوري / خدمة رقمية' : 'الدفع عند الاستلام'; ?></span>
      <img src="<?php echo esc($img); ?>" alt="<?php echo esc($clean_name); ?>" width="230" height="230" loading="lazy" decoding="async" referrerpolicy="no-referrer">
    </a>
    <div class="eo-card-body" data-ui="u-045bc630 ue1 ufp uft">
      <div class="eo-card-cat"><?php echo esc(wf_normalize_category($p['category'] ?? '', 'منتجات أصلية')); ?> • <span data-ui="u-6b87f6a7 ufz"></span></div>
      <a href="<?php echo esc($landing_url); ?>" class="eo-card-title" data-ui="u-bf2d9e87 uit"><?php echo esc($clean_name); ?></a>

      <div data-ui="u-a4a22267 ue1 ufp uiu uew">
        <?php if ($short_desc !== '' && $short_desc !== $clean_name): ?>
        <div data-ui="u-e26dcf0b udt udy uiv uiw uix uiy uiz"><?php echo esc($short_desc); ?></div>
        <?php endif; ?>
        <?php if ($cols && !$is_digital_item): ?>
        <div data-ui="u-2b94768f ufw ufx ufz udt ue6 uh4 ugp"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-image></use></svg> الألوان: <?php echo esc(implode(' • ', array_slice($cols, 0, 4))); ?><?php echo count($cols) > 4 ? ' +' . (count($cols) - 4) : ''; ?></div>
        <?php endif; ?>
        <?php if ($sizes && !$is_digital_item): ?>
        <div data-ui="u-6d788fc5 uga ugb ugc udt ue6 uh4 ugp"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> المقاسات: <?php echo esc(implode(' • ', array_slice($sizes, 0, 5))); ?><?php echo count($sizes) > 5 ? ' +' . (count($sizes) - 5) : ''; ?></div>
        <?php endif; ?>
      </div>

      <div class="eo-card-price-row">
        <span class="eo-card-price"><?php echo wf_ui_money($final_p); ?> <small data-ui="u-27054827 udt">ج.م</small></span>
        
      </div>

      <?php if ($is_digital_item): ?>
      <a href="<?php echo esc($landing_url); ?>" class="eo-buy-btn">
        <span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> اطلب الآن</span>
      </a>
      <?php else: ?>
      <button type="button" class="eo-buy-btn" data-easy-order="<?php echo esc($eo_payload); ?>">
        <span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> اطلب الآن</span>
      </button>
      <?php endif; ?>

      <a href="<?php echo esc($landing_url); ?>" data-ui="u-eb2229f5 udz udn udt ue6 udi ufn uj0 ue9 uep ude uj1"><?php echo $is_digital_item ? 'اطلب الخدمة وحدد الباقة' : 'تفاصيل ومواصفات المنتج'; ?></a>
    </div>
  </div>

    <?php
    return ob_get_clean();
}
?>
<!doctype html>
<html lang="ar" dir="rtl" class="<?php echo $store_dark ? 'store-dark' : ''; ?>" style="--shop: <?php echo esc($theme_color); ?>; --shop-fg: <?php echo esc($theme_fg); ?>; --shop-ink: <?php echo esc($theme_ink); ?>; --shop-ann: <?php echo esc($store_cfg['announcement_color']); ?>; --eo-primary: var(--shop); --eo-primary-fg: <?php echo esc($theme_fg); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="<?php echo esc($theme_color); ?>">
<title><?php echo esc($store_cfg['title'] ?: BRAND); ?> | تسوق الآن والدفع عند الاستلام</title>



<link rel="preload" href="assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields">

</head>
<body class="is-store has-mob-nav<?php echo $store_dark ? ' is-store-dark' : ''; ?>">
<?php wf_ui_sprite(); ?>
<a class="skip-link" href="#storeProducts">تخطي إلى المنتجات</a>

<?php if (!empty($store_sec['announcement']) && ($store_cfg['announcement_text'] ?? '') !== ''): ?>
<div class="st-announce" role="region" aria-label="إعلان المتجر" style="background: <?php echo esc($store_cfg['announcement_color']); ?>; color: <?php echo esc(wf_contrast_fg($store_cfg['announcement_color'])); ?>">
  <div class="st-announce-track"><span><?php echo esc($store_cfg['announcement_text']); ?></span></div>
</div>
<?php endif; ?>

<?php
// ===== نفس الشريط العلوي والهيدر والنافبار المستخدمين في كل الصفحات =====
$store_home = 'store.php?store=' . urlencode($store_code);
$cart_s = wf_cart_summary();
// متجر مسوّق: منمنعش روابط المتجر الرئيسي/تتبع الطلب/برنامج المسوقين (العميل يتعامل مع المسوق صاحب المتجر)
// أي متجر بيتفتح بـ store=<كود> هو متجر مسوّق (حتى لو سجل المستخدم مش موجود/اتغيّر)
$is_mk_store = ($store_code !== '');
// قناة التواصل مع صاحب المتجر: واتساب المتجر ثم موبايل المسوق، وإلا صفحة الدعم
$store_owner_wa = '';
foreach ([($store_cfg['whatsapp'] ?? ''), ($mk_owner['phone'] ?? ''), ($mk_owner['whatsapp'] ?? '')] as $wa_try) {
    $wa_digits = preg_replace('/\D+/', '', (string)$wa_try);
    if (strlen($wa_digits) >= 9) { $store_owner_wa = $wa_digits; break; }
}
$store_contact_href = ($store_owner_wa !== '')
    ? 'https://wa.me/2' . $store_owner_wa . '?text=' . rawurlencode('السلام عليكم، عندي استفسار بخصوص أحد منتجات متجر ' . ($store_cfg['title'] ?: BRAND))
    : 'dashboard/support.php?ctx=customer&store=' . urlencode($store_code);
$top_links = [];
if (!$is_mk_store) $top_links[] = ['href' => 'dashboard/orders.php', 'label' => 'تتبع طلبك', 'icon' => 'truck'];
$cu_is_mk = function_exists('wf_is_marketer_account') && wf_is_marketer_account($cu);
if ($cu) {
    $me_links = find_user($cu['email']);
    $cu_code = $me_links['ref_code'] ?? '';
    if ($cu_is_mk) {
        $top_links[] = ['href' => 'dashboard/marketer.php', 'label' => 'لوحة المسوق', 'icon' => 'megaphone'];
        if ($cu_code && strcasecmp((string)$cu_code, (string)$store_code) !== 0) $top_links[] = ['href' => 'store.php?store=' . urlencode($cu_code), 'label' => 'متجري', 'icon' => 'store'];
    }
    // العميل: «حسابي» و«الإشعارات» مش في الشريط العلوي
    if ($cu_is_mk) $top_links[] = ['href' => 'dashboard/notifications.php', 'label' => 'الإشعارات', 'icon' => 'bell'];
    $top_links[] = ['href' => 'logout.php', 'label' => 'خروج', 'icon' => 'logout'];
} elseif (!$is_mk_store) {
    // متجر المسوق ما بيعرضش تسجيل دخول — العميل بيطلب من غير حساب
    $top_links[] = ['href' => 'index.php', 'label' => 'المتجر الرئيسي', 'icon' => 'store'];
    $top_links[] = ['href' => 'login.php', 'label' => 'تسجيل الدخول', 'icon' => 'user'];
    $top_links[] = ['href' => 'affiliate-register.php', 'label' => 'برنامج المسوقين', 'class' => 'hidden sm:inline'];
}
// متجر المسوق: بدون رقم الموقع في الشريط العلوي (العميل بيتعامل مع المسوق نفسه)
echo wf_store_topbar(['phone' => $is_mk_store ? '' : (function_exists('site_setting') ? (site_setting('phone', '01202954952') ?: '01202954952') : '01202954952'), 'links' => $top_links]);

$store_header_extra = '';
if ($is_owner) {
    $store_header_extra .= '<a class="icon-btn" href="dashboard/marketer.php?mtab=orders" aria-label="طلبات متجري" title="طلبات متجري">' . wf_icon('orders', 20) . '</a>';
}
echo wf_store_header([
    'brand' => ($store_cfg['title'] ?: BRAND),
    'home' => $store_home,
    'search_action' => 'store.php',
    'search_value' => $q,
    'search_placeholder' => 'ابحث في ' . ($store_cfg['title'] ?: BRAND) . ' عن منتجك...',
    'hidden' => ['store' => $store_code, 'cat' => ($cat_filter !== 'all' ? $cat_filter : '')],
    'cart_href' => 'dashboard/cart.php',
    'cart_count' => $cart_s['count'],
    'cart_total' => $cart_s['total'],
    'catalog_href' => '', // المتجر مستقل: مفيش ربط بكتالوج الموقع الرئيسي
    'extra' => $store_header_extra,
    'account' => $cu ? [
        'href' => $cu_is_mk ? 'dashboard/marketer.php' : 'dashboard/index.php',
        'initial' => mb_substr((string)$cu['name'], 0, 1),
        'caption' => $cu_is_mk ? 'لوحة المسوق' : 'حسابي',
        'label' => mb_substr((string)$cu['name'], 0, 15),
    ] : null,
]);
// متجر المسوق: مفيش شريط تنقل (لا «كل المنتجات» ولا «السلة») — المنتجات تبدأ على طول
if (!$is_mk_store) {
$store_nav_items = [
    ['href' => $store_home, 'label' => 'كل المنتجات', 'icon' => 'store', 'on' => ($cat_filter === 'all' && $q === '')],
];
if (!$is_mk_store) $store_nav_items[] = ['href' => 'index.php', 'label' => 'المتجر الرئيسي', 'icon' => 'home'];
if (!$is_mk_store) $store_nav_items[] = ['href' => 'dashboard/orders.php', 'label' => 'تتبع طلبك', 'icon' => 'truck'];
$store_nav_items[] = ['href' => 'dashboard/cart.php', 'label' => 'السلة' . ($cart_s['count'] ? ' (' . (int)$cart_s['count'] . ')' : ''), 'icon' => 'cart'];
if ($is_owner) {
    $store_nav_items[] = ['href' => 'dashboard/marketer.php?mtab=store', 'label' => 'إدارة متجري', 'icon' => 'settings', 'class' => 'ml-auto'];
} elseif (!$is_mk_store) {
    $store_nav_items[] = ['href' => $store_contact_href, 'label' => 'خدمة العملاء', 'icon' => 'chat', 'class' => 'ml-auto'];
}
echo wf_store_navbar($store_nav_items, ['label' => 'تنقل المتجر']);
}
?>

<h1 class="sr-only"><?php echo esc($store_cfg['title'] ?: BRAND); ?></h1>

<?php if (!empty($store_sec['trust'])): ?>
<section class="st-trust" aria-label="مميزات المتجر">
  <div class="st-trust-item"><span class="st-trust-ico"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg></span><b>الدفع عند الاستلام</b><span>افحص المنتج قبل ما تدفع</span></div>
  <div class="st-trust-item"><span class="st-trust-ico"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-truck></use></svg></span><b>توصيل سريع</b><span>لكل محافظات مصر</span></div>
  <div class="st-trust-item"><span class="st-trust-ico"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-refresh></use></svg></span><b>استبدال وإرجاع</b></div>
</section>
<?php endif; ?>

<h1 class="sr-only"><?php echo esc($store_cfg['title'] ?: BRAND); ?></h1>

<?php
// أقسام المجموعات اللي المسوق مختار لها منتجات بنفسه: مميز / الأحدث / الأكثر مبيعاً
$store_coll_view = [];
if (!empty($store_sec['collections']) && $col_filter === '') {
    foreach (array_keys($store_coll_defs) as $ck) {
        $picks = [];
        foreach ($store_items_all as $it) {
            if (in_array($ck, (array)$it['_mk_tags'] ?? [], true)) $picks[] = $it;
            if (count($picks) >= 6) break;
        }
        if ($picks) $store_coll_view[$ck] = $picks;
    }
}
?>
<?php if ($store_coll_view): ?>
<?php foreach ($store_coll_view as $ck => $picks): ?>
<section class="st-sec st-collection" id="stCol-<?php echo esc($ck); ?>" aria-labelledby="stColHead-<?php echo esc($ck); ?>">
  <div class="st-col-head">
    <h2 id="stColHead-<?php echo esc($ck); ?>"><?php echo esc($store_coll_defs[$ck]); ?></h2>
    <a class="chip" href="store.php?store=<?php echo urlencode($store_code); ?>&col=<?php echo urlencode($ck); ?>#storeProducts">شوف الكل <small>(<?php echo count(array_filter($store_items_all, function ($it) use ($ck) { return in_array($ck, (array)$it['_mk_tags'] ?? [], true); })); ?>)</small></a>
  </div>
  <div class="eo-grid">
    <?php foreach ($picks as $p) echo wf_store_card_html($p, $store_code); ?>
  </div>
</section>
<?php endforeach; ?>
<?php endif; ?>

<?php $store_filters_on = ($cat_filter !== 'all' || $q !== '' || $store_sort !== '' || $col_filter !== ''); ?>
<!-- فلاتر الأقسام + شريط أدوات الكتالوج: نفس الشكل المستخدم في الرئيسية -->
<div class="eo-toolbar-note">
<?php if ($store_cats && !empty($store_sec['chips'])): ?>
<div class="filter-chips-scroll" role="navigation" aria-label="فلتر أقسام المتجر">
  <a href="store.php?store=<?php echo urlencode($store_code); ?>" class="chip <?php echo $cat_filter === 'all' ? 'is-active' : ''; ?>">كل المنتجات</a>
  <?php foreach ($store_cats as $cn => $cnt): ?>
    <a href="store.php?store=<?php echo urlencode($store_code); ?>&cat=<?php echo urlencode($cn); ?>" class="chip <?php echo $cat_filter === $cn ? 'is-active' : ''; ?>"><?php echo esc($cn); ?> <small>(<?php echo (int)$cnt; ?>)</small></a>
  <?php endforeach; ?>
  <?php if (!empty($store_sec['collections'])): foreach ($store_coll_defs as $ck => $clbl):
      $ccnt = count(array_filter($store_items_all, function ($it) use ($ck) { return in_array($ck, (array)$it['_mk_tags'] ?? [], true); }));
      if ($ccnt === 0) continue; ?>
    <a href="store.php?store=<?php echo urlencode($store_code); ?>&col=<?php echo urlencode($ck); ?>#storeProducts" class="chip <?php echo $col_filter === $ck ? 'is-active' : ''; ?>"><?php echo esc($clbl); ?> <small>(<?php echo (int)$ccnt; ?>)</small></a>
  <?php endforeach; endif; ?>
</div>
<?php endif; ?>
<?php
$store_sort_url = function ($sort) use ($store_code, $cat_filter, $q, $col_filter) {
    $params = ['store' => $store_code];
    if ($cat_filter !== 'all') $params['cat'] = $cat_filter;
    if ($q !== '') $params['q'] = $q;
    if ($col_filter !== '') $params['col'] = $col_filter;
    if ($sort !== '') $params['sort'] = $sort;
    return 'store.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . '#storeProducts';
};
echo wf_catalog_toolbar([
    'title' => ($col_filter !== '' ? ($store_coll_defs[$col_filter] ?? 'كل المنتجات') : ($cat_filter !== 'all' ? $cat_filter : ($q !== '' ? 'نتائج البحث' : 'كل المنتجات'))),
    'meta' => count($store_items) . ' منتج متاح الآن',
    'sort_id' => 'storeSortSelect',
    'sort_current' => ($store_sort !== '' ? $store_sort : 'default'),
    'sort_options' => [
        'default' => ['label' => 'الافتراضي', 'url' => $store_sort_url('')],
        'price_asc' => ['label' => 'السعر: من الأقل للأعلى', 'url' => $store_sort_url('price_asc')],
        'price_desc' => ['label' => 'السعر: من الأعلى للأقل', 'url' => $store_sort_url('price_desc')],
        'name' => ['label' => 'الاسم (أ - ي)', 'url' => $store_sort_url('name')],
    ],
    'clear_url' => $store_filters_on ? 'store.php?store=' . urlencode($store_code) . '#storeProducts' : '',
]);
?>
</div>

<main class="eo-grid" id="storeProducts">
  <?php foreach ($store_items as $p): echo wf_store_card_html($p, $store_code); ?>
  <?php endforeach; ?>
</main>

<?php if (!empty($store_sec['faq']) && !empty($store_cfg['faq'])): ?>
<section class="st-sec" aria-labelledby="stFaqTitle">
  <h2 id="stFaqTitle">الأسئلة الشائعة</h2>
  <div class="st-faq">
    <?php foreach ($store_cfg['faq'] as $qa): ?>
    <details class="st-faq-item"><summary><?php echo esc($qa['q']); ?></summary><p><?php echo esc($qa['a']); ?></p></details>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php
$st_social = array_filter((array)($store_cfg['social'] ?? []), function ($v) { return trim((string)$v) !== ''; });
$st_contact = (array)($store_cfg['contact'] ?? []);
$st_has_contact = ($st_contact['phone'] ?? '') !== '' || ($st_contact['email'] ?? '') !== '' || ($st_contact['address'] ?? '') !== '' || $store_cfg['whatsapp'] !== '';
?>
<?php if (!empty($store_sec['contact']) && ($st_has_contact || $st_social)): ?>
<section class="st-sec" aria-labelledby="stContactTitle">
  <h2 id="stContactTitle">ابقَ على تواصل</h2>
  <div class="st-contact">
    <?php if ($store_cfg['whatsapp'] !== ''): $wa_d = preg_replace('/\D+/', '', $store_cfg['whatsapp']); ?>
    <a class="st-contact-item" href="https://wa.me/2<?php echo esc($wa_d); ?>" target="_blank" rel="noopener"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chat></use></svg><b>واتساب</b><span dir="ltr"><?php echo esc($store_cfg['whatsapp']); ?></span></a>
    <?php endif; ?>
    <?php if (($st_contact['phone'] ?? '') !== ''): ?>
    <a class="st-contact-item" href="tel:<?php echo esc(preg_replace('/[^0-9+]/', '', $st_contact['phone'])); ?>"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-phone></use></svg><b>هاتف</b><span dir="ltr"><?php echo esc($st_contact['phone']); ?></span></a>
    <?php endif; ?>
    <?php if (($st_contact['email'] ?? '') !== ''): ?>
    <a class="st-contact-item" href="mailto:<?php echo esc($st_contact['email']); ?>"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-mail></use></svg><b>بريد إلكتروني</b><span dir="ltr"><?php echo esc($st_contact['email']); ?></span></a>
    <?php endif; ?>
    <?php if (($st_contact['address'] ?? '') !== ''): ?>
    <div class="st-contact-item"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-map></use></svg><b>العنوان</b><span><?php echo esc($st_contact['address']); ?></span></div>
    <?php endif; ?>
    <?php foreach ($st_social as $sk => $sv): $lbl = ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'tiktok' => 'تيك توك'][$sk] ?? $sk; ?>
    <a class="st-contact-item" href="<?php echo esc($sv); ?>" target="_blank" rel="noopener"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-share></use></svg><b><?php echo esc($lbl); ?></b><span dir="ltr"><?php echo esc(preg_replace('~^https?://~', '', $sv)); ?></span></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($store_sec['footer'])): ?>
<footer data-ui="u-a2815e52 udk udy uj2 udn udg">
  <div data-ui="u-71c14017 uj3 uj4">
    <?php if ($st_social): ?>
    <div class="st-social" aria-label="روابط المتجر على السوشيال">
      <?php foreach ($st_social as $sk => $sv): $lbl = ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'tiktok' => 'تيك توك'][$sk] ?? $sk; ?>
      <a href="<?php echo esc($sv); ?>" target="_blank" rel="noopener"><?php echo esc($lbl); ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div data-ui="u-85a5eafc udx udt uhr">© <?php echo date('Y'); ?> <?php echo esc($store_cfg['title']); ?> — جميع الحقوق محفوظة</div>
  </div>
</footer>
<?php endif; ?>

<?php if (!empty($store_cfg['whatsapp'])): ?>
<a class="wa-float" href="https://wa.me/2<?php echo esc(preg_replace('/\D+/', '', $store_cfg['whatsapp'])); ?>" target="_blank" rel="noopener" aria-label="تواصل عبر واتساب"><?php echo wf_whatsapp_glyph(26); ?></a>
<?php endif; ?>
<?php if (function_exists('render_marketer_pixels_html')) render_marketer_pixels_html($store_cfg); ?>
<?php render_easyorder_dialog_html($store_code); ?>
<!-- MOBILE BOTTOM NAVIGATION الموحّد -->
<?php echo wf_bottom_nav('shop', ['cart_count' => $cart_s['count'], 'logged_in' => (bool)$cu, 'prefix' => '', 'shop_href' => $store_home . '#products', 'home_href' => $store_home, 'hide' => ['support']]); ?>

<div hidden data-track-meta="<?php echo esc(json_encode(['ref_code'=>$store_code], JSON_UNESCAPED_UNICODE)); ?>"></div>
<script src="assets/ui.js?v=20261009-city-mk1" defer></script>
<script src="assets/tracker.js" defer></script>
</body>
</html>
