<?php require 'config.php';
require_once __DIR__ . '/ui-components.php';
$st = function_exists('site_stats') ? site_stats() : stats();
$country = $_GET['country'] ?? 'all';
$cat = $_GET['cat'] ?? 'all';
$col = $_GET['col'] ?? ''; // '' | featured | new | best | viewed
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$home_seed = isset($_GET['seed']) ? max(1, (int)$_GET['seed']) : mt_rand(1, 2147483646);
// نعرض 50 منتجًا في كل صفحة، والصفحات التالية تُحمّل عند طلب الزائر.
$per = 50;

$cart_n = 0; $cart_t = 0;
foreach ($_SESSION['cart'] ?? [] as $c) {
    if (is_array($c)) {
        $cart_n += (int)($c['qty'] ?? 1);
        $cart_t += (float)($c['price'] ?? 0) * (int)($c['qty'] ?? 1);
    }
}

$soldmap = [];
foreach (jload('orders.json', []) as $o) {
    $k = ($o['plat'] ?? '') . '#' . ($o['id'] ?? '');
    $soldmap[$k] = ($soldmap[$k] ?? 0) + ($o['qty'] ?? 1);
}
$cat_redirect = ['Back to school' => 'أحذية'];
if (isset($cat_redirect[$cat])) {
    header('Location: ?country=' . urlencode($country) . '&cat=' . urlencode($cat_redirect[$cat]));
    exit;
}

$plat_view = (($_GET['plat'] ?? '') === 'm5zoon') ? 'm5zoon' : '';

// ===== تحديد الوضع النشط: فيزيكال (افتراضي) / رقمي لوحده / سوشيال لوحدها =====
$mode = $_GET['mode'] ?? '';
if ($mode === '') {
    if ($cat === 'المنتجات الرقمية' || ($_GET['plat'] ?? '') === 'g2g') {
        $mode = 'digital';
        if ($cat === 'المنتجات الرقمية') $cat = 'all';
    } elseif ($cat === 'سوشيال ميديا' || ($_GET['plat'] ?? '') === 'social') {
        $mode = 'social';
        if ($cat === 'سوشيال ميديا') $cat = 'all';
    } elseif ($cat !== 'all') {
        // لا داعي لقراءة كتالوجي الرقمي والسوشيال في زيارة المتجر الافتراضية.
        $dig_cats = array_keys(categories_by_mode('digital'));
        $soc_cats = array_keys(categories_by_mode('social'));
        if (in_array($cat, $dig_cats, true)) $mode = 'digital';
        elseif (in_array($cat, $soc_cats, true)) $mode = 'social';
        else $mode = 'physical';
    } else {
        $mode = 'physical';
    }
}
if (!in_array($mode, ['physical', 'digital', 'social'], true)) $mode = 'physical';

// التوافق مع الفلتر الفرعي القديم (?sub=...)
if (!empty($_GET['sub']) && $_GET['sub'] !== 'all' && $cat === 'all') {
    $cat = $_GET['sub'];
}

$special_col_map = [
    'المنتجات المميزة' => 'featured',
    'أحدث المنتجات'    => 'new',
    'وصل حديثاً'       => 'new',
    'الأكثر مبيعاً'    => 'best',
    'الأكثر مشاهدة'    => 'viewed',
];
if (isset($special_col_map[$cat])) {
    $col = $special_col_map[$cat];
    $cat = 'all';
}
if (!in_array($col, ['', 'all', 'featured', 'new', 'best', 'viewed'], true)) $col = '';

$is_digital_only = ($mode === 'digital');
$is_social_only  = ($mode === 'social');
$is_special_view = ($is_digital_only || $is_social_only);

// التصنيفات الخاصة بالوضع الحالي فقط (فيزيكال فقط / رقمي فقط / سوشيال فقط)
// الصفحة الرئيسية لا تفك كتالوج سيزما كاملًا لعرض أسماء الأقسام: تستخدم ملخصًا
// صغيرًا يولّده المحدّث، وتظل المنتجات نفسها قابلة للفلترة والبحث من الكتالوج الكامل.
if ($mode === 'physical' && !in_array($country, wf_gulf_countries(), true)) {
    $catalog_counts = jload('catalog_counts.json', []);
    $cezma_category_counts = cezma_search_index_category_counts($country);
    if ($country === 'all' && !empty($catalog_counts['physical_categories']) && is_array($catalog_counts['physical_categories'])) {
        // أسماء وأعداد الأقسام جاهزة في ملخص صغير، من غير فكّ أي كتالوج منتجات عند الطلب.
        $mode_cats = [];
        foreach ($catalog_counts['physical_categories'] as $raw_cat => $count) {
            $normalized_cat = wf_normalize_category($raw_cat);
            if ($normalized_cat !== '' && !in_array($normalized_cat, ['منتجات رقمية', 'سوشيال ميديا'], true)) {
                $mode_cats[$normalized_cat] = ($mode_cats[$normalized_cat] ?? 0) + (int)$count;
            }
        }
        foreach ($cezma_category_counts as $raw_cat => $count) {
            $normalized_cat = wf_normalize_category($raw_cat);
            if ($normalized_cat !== '' && !in_array($normalized_cat, ['منتجات رقمية', 'سوشيال ميديا'], true)) {
                $mode_cats[$normalized_cat] = ($mode_cats[$normalized_cat] ?? 0) + (int)$count;
            }
        }
        foreach (manual_products_all() as $manual_product) {
            $manual_cat = wf_normalize_category($manual_product['category'] ?? '');
            if ($manual_cat !== '' && !in_array($manual_cat, ['منتجات رقمية', 'سوشيال ميديا'], true)) {
                $mode_cats[$manual_cat] = ($mode_cats[$manual_cat] ?? 0) + 1;
            }
        }
    } else {
        // لباقي البلدان نقرأ المصادر الصغيرة؛ أعداد سيزما من الفهرس الموقّع أو مسح كامل آمن كبديل.
        $mode_cats = categories_by_mode($mode, $country, false);
        foreach ($cezma_category_counts as $raw_cat => $count) {
            $cezma_cat = wf_normalize_category($raw_cat);
            if ($cezma_cat !== '' && !in_array($cezma_cat, ['منتجات رقمية', 'سوشيال ميديا'], true)) {
                $mode_cats[$cezma_cat] = ($mode_cats[$cezma_cat] ?? 0) + (int)$count;
            }
        }
    }
    foreach (wf_category_canonical() as $canonical_cat) {
        if (!in_array($canonical_cat, ['منتجات رقمية', 'سوشيال ميديا'], true)) {
            $mode_cats[$canonical_cat] = $mode_cats[$canonical_cat] ?? 0;
        }
    }
    arsort($mode_cats);
} else {
    $mode_cats = categories_by_mode($mode, $country);
}

$sort = trim((string)($_GET['sort'] ?? ''));

function build_filter_url($params = []) {
    global $mode, $country, $cat, $col, $q, $plat_view, $sort;
    $merged = [
        'mode'    => $params['mode'] ?? $mode,
        'country' => $params['country'] ?? $country,
        'cat'     => $params['cat'] ?? $cat,
        'col'     => $params['col'] ?? $col,
        'q'       => $params['q'] ?? $q,
        'plat'    => $params['plat'] ?? $plat_view,
        'sort'    => $params['sort'] ?? $sort,
        'page'    => $params['page'] ?? '',
    ];
    $qs = [];
    if ($merged['plat'] !== '') $qs['plat'] = $merged['plat'];
    if ($merged['mode'] !== '' && $merged['mode'] !== 'physical') $qs['mode'] = $merged['mode'];
    if ($merged['country'] !== '' && $merged['country'] !== 'all') $qs['country'] = $merged['country'];
    if ($merged['cat'] !== '' && $merged['cat'] !== 'all') $qs['cat'] = $merged['cat'];
    if ($merged['col'] !== '' && $merged['col'] !== 'all') $qs['col'] = $merged['col'];
    if ($merged['q'] !== '') $qs['q'] = $merged['q'];
    if ($merged['sort'] !== '' && $merged['sort'] !== 'default') $qs['sort'] = $merged['sort'];
    if ($merged['page'] !== '' && (int)$merged['page'] > 1) $qs['page'] = (int)$merged['page'];
    if (!empty($GLOBALS['home_seed_url']) && array_diff(array_keys($qs), ['page']) === []) $qs['seed'] = (int)$GLOBALS['home_seed_url'];
    return $qs ? ('?' . http_build_query($qs)) : 'index.php';
}

// كارت المنتج بنظام التصميم الموحّد + فصل واجهة الأفلييت للزوار وإظهارها للمسجلين فقط
function pcard2($p, $soldmap) {
    $plat = $p['_plat'] ?? resolve_plat($p['id'] ?? '');
    $img = product_first_image($p, $plat);
    if ((!$p['images'] || empty($p['images'][0])) && $plat === 'g2g') $img = 'assets/img/g2g.svg';
    if ((!$p['images'] || empty($p['images'][0])) && $plat === 'social') $img = 'assets/img/social.svg';
    $cu_w = current_user();
    // حساب العميل العادي يشوف الكارت زي الزائر بالظبط (بدون صف العمولة وصفحة الهبوط)
    $cu_is_mk = wf_is_marketer_account($cu_w);
    static $my_aff_code = null;
    if ($my_aff_code === null) {
        if ($cu_w && !empty($cu_w['email'])) {
            $me_a = $cu_is_mk ? ensure_user_affiliate($cu_w['email']) : null;
            $my_aff_code = (string)($me_a['ref_code'] ?? '');
        } else {
            $my_aff_code = '';
        }
    }
    $ref_cur = active_ref() ?: $my_aff_code;
    $link = 'dashboard/product.php?id=' . urlencode((string)$p['id']) . ($ref_cur ? '&ref=' . urlencode($ref_cur) : '');
    $sold = $soldmap[$plat . '#' . $p['id']] ?? 0;
    $cols = clean_product_colors($p, $plat);
    $sizes_clean = clean_product_sizes($p, $plat);
    $base_store = list_price($p, $plat) ?: display_price($p, $plat);
    $extra_c = $ref_cur ? marketer_store_product_comm($ref_cur, $plat, (string)$p['id']) : 0.0;
    $base = round($base_store + $extra_c, 2);
    $sdisc = src_discount($p, $plat);
    $platform_labels = ['g2g' => 'رقمي', 'social' => 'سوشيال'];
    $plat_label = $platform_labels[$plat] ?? '';
    $brand = $p['brand_category'] ?? '';
    $cat = ($plat === 'social') ? ($p['_soc_plat'] ?? ($p['subcategory'] ?? 'سوشيال ميديا')) : (($plat === 'g2g') ? ($p['subcategory'] ?? 'منتجات رقمية') : ($p['category'] ?? 'منتجات'));
    // المنتجات الرقمية/السوشيال بتعرض القسم الفرعي كما هو (بدون مطابقة تقريبية) حتى لا يتحول
    // «ألعاب وشحن وبطاقات» إلى «أطفال وألعاب»؛ التقليدية بتعرض الكاتيجوري الموحّد.
    $cat = wf_normalize_category(wf_ui_plain($cat), 'منتجات', !in_array($plat, ['g2g', 'social'], true));
    static $my_wishes = null;
    if ($my_wishes === null) {
        $w_all = jload('wishlist.json', []);
        $wk_u = !empty($cu_w['email']) ? $cu_w['email'] : ('guest_' . (function_exists('visitor_id') ? visitor_id() : session_id()));
        $my_wishes = is_array($w_all[$wk_u] ?? null) ? $w_all[$wk_u] : [];
    }
    $is_loved = in_array($plat . '#' . $p['id'], $my_wishes, true);
    $clean_title = aff_clean($p['name'] ?? '', true) ?: 'منتج';
    $is_new_p = !empty($p['added_at']) && (time() - strtotime($p['added_at'])) < 7 * 86400;
    $drop_pct_raw = (!empty($p['price_drop_at']) && (time() - strtotime($p['price_drop_at'])) < 14 * 86400 && (float)($p['old_price'] ?? 0) > (float)($p['price'] ?? 0))
        ? (int)round(((float)$p['old_price'] - (float)$p['price']) / (float)$p['old_price'] * 100)
        : (int)($sdisc['pct'] ?? 0);
    // الرقمي والسوشيال ميديا: السعر سعر المصدر — مافيشش شارة خصم/عمولة
    $drop_pct = (function_exists('wf_plat_no_commission') && wf_plat_no_commission($plat)) ? 0 : $drop_pct_raw;

    echo '<article class="pcard">';
    // نظام شارات موحد (حد أقصى 2 أعلى الصورة)
    echo '<div class="pcard-badges">';
    if ($plat_label !== '') {
        echo '<span class="pcard-badge" style="background:' . ($plat === 'social' ? 'var(--mode-social)' : 'var(--mode-digital)') . '">' . esc($plat_label) . '</span>';
    }
    if ($drop_pct > 0) {
        echo '<span class="pcard-badge" data-ui="u-62e5c3a2 ugx udq">خصم ' . (int)$drop_pct . '%</span>';
    } elseif ($is_new_p) {
        echo '<span class="pcard-badge" data-ui="u-4cfe8d80 ugy udq">جديد</span>';
    }
    echo '</div>';
    echo '<a role="button" data-wishlist class="pcard-wish ' . ($is_loved ? 'is-loved' : '') . '" aria-label="تبديل حفظ المنتج في المفضلة" aria-pressed="' . ($is_loved ? 'true' : 'false') . '" href="dashboard/product.php?id=' . urlencode((string)$p['id']) . '&wish=1">' . wf_icon('heart') . '</a>';
    echo '<div class="pcard-img"><a href="' . esc($link) . '">';
    if ($img && strpos($img, 'assets/img/') !== 0) echo '<img src="' . esc(asset_url($img)) . '" alt="' . esc($clean_title) . '" width="260" height="260" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
    else echo '<span class="pcard-placeholder" role="img" aria-label="صورة المنتج غير متاحة">' . wf_icon($plat === 'g2g' ? 'game' : ($plat === 'social' ? 'megaphone' : 'box'), 44) . '</span>';
    echo '</a></div><div class="pcard-cat">' . esc($cat) . '</div><h3 class="pcard-title"><a href="' . esc($link) . '">' . esc($clean_title) . '</a></h3>';
    echo wf_product_media_link($p, $link, $plat);

    if ($cols || $sizes_clean) {
        echo '<div data-ui="u-59228d74 ue1 uet ugz uh0">';
        if ($cols) {
            echo '<span data-ui="u-8d47d2c3 ufw ufx ufz udt ue6 uh1 uh2" title="' . esc(implode('، ', $cols)) . '"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-image></use></svg> ' . esc(implode(' • ', array_slice($cols, 0, 2))) . (count($cols) > 2 ? ' +' . (count($cols) - 2) : '') . '</span>';
        }
        if ($sizes_clean) {
            echo '<span data-ui="u-9370c038 uga ugb ugc udt ue6 uh1 uh2"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> ' . esc(implode('/', array_slice($sizes_clean, 0, 3))) . (count($sizes_clean) > 3 ? '+' : '') . '</span>';
        }
        echo '</div>';
    }
    if ($cu_is_mk && !wf_is_digital_plat($plat)) {
        // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة — الكارت مايعرضش «عمولتك»
        $u_comm = round((float)$extra_c, 2);
        $lp_href = 'landing.php?id=' . urlencode((string)$p['id']) . ($ref_cur ? '&ref=' . urlencode($ref_cur) : '') . ($u_comm > 0 ? '&comm=' . $u_comm : '');
        echo '<div data-ui="u-4bb75b8d uh3 uh4 uf6 ugi ugp udt ue6 ugj ue1 ue2 ue7"><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> عمولتك: ' . ($u_comm > 0 ? '+' . wf_ui_money($u_comm) . ' ج.م' : '0 ج.م (أضف عمولتك)') . '</span><a href="' . esc($lp_href) . '" target="_blank" data-ui="u-214a0a6c ued ufn udt"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> هبوط</a></div>';
    } else {
        if ($sold > 0) echo '<p class="mut">طلبات مسجلة: ' . number_format($sold) . '</p>';
    }
    if ($plat === 'g2g') {
        $opts_cnt = max((int)($p['extra']['variants_count'] ?? 0), count($p['sizes'] ?? []), count($p['extra']['variants_detailed'] ?? []));
        echo '<div data-ui="u-c3f45f27 ue1 ue7 uft uh3 udt ue6 uh5"><span data-ui="u-47f16839 uh6 uh7 uf5 uh8 udo"></span>' . ($opts_cnt > 1 ? 'متوفر ' . $opts_cnt . ' باقات وعروض' : 'تسليم رقمي فوري') . '</div>';
    } elseif ($plat === 'social') {
        echo '<div data-ui="u-df1ef83c ue1 ue7 uft uh3 udt ue6 uh9"><span data-ui="u-289823eb uh6 uh7 uf5 ugy udo"></span>تنفيذ سريع تلقائي</div>';
    }
    $price_str = $base > 0 ? number_format($base, ($base == (int)$base ? 0 : 2)) : ($p['price_text'] ?? $p['price'] ?? '');
    $start_lbl = ($plat === 'g2g' && max((int)($p['extra']['variants_count'] ?? 0), count($p['sizes'] ?? []), count($p['extra']['variants_detailed'] ?? [])) > 1) ? '<small data-ui="u-84fdabe9 uh5 ue6 uha">يبدأ من</small>' : '';
    $old_html = ($sdisc && !empty($sdisc['old']) && (float)$sdisc['old'] > $base) ? '<s>' . number_format((float)$sdisc['old'], 0) . '</s>' : '';
    echo '<div class="pcard-footer"><div class="pcard-price' . ($base > 0 && deal_active() ? ' red' : '') . '">' . $start_lbl . esc($price_str) . ' <small>' . esc(wf_ui_currency($p)) . '</small>' . $old_html . '</div>';
    if (!in_array($plat, ['g2g', 'social'], true)) {
        $eo_payload = json_encode([
            'id' => (string)$p['id'],
            'plat' => $plat,
            'name' => $clean_title,
            'price' => (float)$base,
            'base_price' => (float)$base_store,
            'extra_comm' => (float)$extra_c,
            'img' => $img,
            'ref' => $ref_cur,
            'currency' => wf_ui_currency($p),
            // دولة المنتج: بتحدد جدول الشحن (مصر = محافظات بالجنيه، الخليج = مناطق الدولة بعملتها)
            'country' => wf_product_country($p),
            // سعر شحن المنتج نفسه (لو متحدد) — بيكسب جدول المناطق في النافذة والحساب
            'ship_fee' => function_exists('wf_product_shipping') ? wf_product_shipping($p) : null,
            'colors' => array_slice($cols, 0, 15),
            'sizes' => array_slice($sizes_clean, 0, 20),
        ], JSON_UNESCAPED_UNICODE);
        echo '<button type="button" data-easy-order="' . esc($eo_payload) . '" class="pcard-cart" aria-label="اطلب الآن ' . esc($clean_title) . '">اطلب الآن</button>';
    } else {
        echo '<a href="' . esc($link) . '" class="pcard-cart" aria-label="اطلب الآن ' . esc($clean_title) . '">اطلب الآن</a>';
    }
    echo '</div>';
    echo '</article>';
}
$top_phone = function_exists('site_setting') ? (site_setting('phone', '01202954952') ?: '01202954952') : '01202954952';
$top_email = function_exists('site_setting') ? (site_setting('email', 'support@wafirinhalk.ct.ws') ?: 'support@wafirinhalk.ct.ws') : 'support@wafirinhalk.ct.ws';
$top_wa = function_exists('wf_support_wa_href') ? wf_support_wa_href() : ''; // واتساب خدمة العملاء (من الإعدادات، ورقم الموبايل كبديل)
$top_wa_img = $top_wa !== '' && function_exists('wf_support_wa_href') ? wf_support_wa_href('السلام عليكم، عايز أطلب منتج مش موجود في الموقع — هبعت صورته هنا') : '';
$vis_ref = function_exists('active_ref') ? active_ref() : '';
$vis_mk = ($vis_ref !== '' && function_exists('find_marketer_by_code')) ? find_marketer_by_code($vis_ref) : null;
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><link rel="manifest" href="manifest.json"><?php echo tracking_pixels_html(); ?>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f172a">
<title><?php echo esc(BRAND); ?> أفلييت | التسويق بالعمولة والربح من الإنترنت</title>
<meta name="description" content="<?php echo esc(BRAND); ?> أفلييت: تعرّف على التسويق بالعمولة والربح من الإنترنت بمشاركة المنتجات وكسب عمولة على الطلبات المستلمة. لا يوجد دخل مضمون.">
<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">
<link rel="canonical" href="https://wafirinhalk.com/">
<meta property="og:type" content="website">
<meta property="og:locale" content="ar_EG">
<meta property="og:site_name" content="<?php echo esc(BRAND); ?>">
<meta property="og:title" content="<?php echo esc(BRAND); ?> أفلييت | التسويق بالعمولة والربح من الإنترنت">
<meta property="og:description" content="منصة وفرنهالك أفلييت للتسوق والتسويق بالعمولة في مصر. اكتشف المنتجات والخدمات الرقمية أو ابدأ كمسوق وأنشئ متجرك ورابطك الخاص.">
<meta property="og:url" content="https://wafirinhalk.com/">
<meta property="og:image" content="https://wafirinhalk.com/assets/icon-512.png">
<meta name="twitter:card" content="summary">
<script type="application/ld+json"><?php echo json_encode(['@context' => 'https://schema.org', '@graph' => [['@type' => 'Organization', '@id' => 'https://wafirinhalk.com/#organization', 'name' => BRAND, 'url' => 'https://wafirinhalk.com/', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://wafirinhalk.com/assets/icon-512.png'], 'email' => $top_email, 'telephone' => $top_phone, 'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'EG']], ['@type' => 'WebSite', '@id' => 'https://wafirinhalk.com/#website', 'url' => 'https://wafirinhalk.com/', 'name' => BRAND, 'inLanguage' => 'ar-EG', 'publisher' => ['@id' => 'https://wafirinhalk.com/#organization'], 'potentialAction' => ['@type' => 'SearchAction', 'target' => 'https://wafirinhalk.com/?q={search_term_string}', 'query-input' => 'required name=search_term_string']]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

<link rel="preload" href="assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=<?= wf_asset_v('style.css') ?>"></head><body class="has-mob-nav">
<?php wf_ui_sprite(); ?>
<a href="#products" class="skip-link">تخطي إلى المحتوى</a>

<?php
// ===== الشريط العلوي الموحّد: نفس الأزرار في كل صفحات الموقع الأساسي =====
$idx_user = current_user();
$idx_is_marketer = wf_is_marketer_account($idx_user);
$idx_code = '';
if ($idx_user && $idx_is_marketer && !empty($idx_user['email'])) {
    $me_idx = ensure_user_affiliate($idx_user['email']);
    $idx_code = (string)($me_idx['ref_code'] ?? '');
}
echo wf_site_topbar(['phone' => $top_phone, 'user' => $idx_user, 'ref_code' => $idx_code]);
// بلوك مزايا الحساب اتشال من الرئيسية (للعميل العادي) — مكانه لوحة الحساب بس
?>


<?php
// ===== الهيدر الموحّد: شعار + بحث (حقل واحد) + كاميرا + كتالوج + حساب + سلة =====
echo wf_store_header([
    'brand' => BRAND,
    'home' => 'index.php',
    'search_action' => 'index.php',
    'search_value' => $q,
    'hidden' => ['mode' => ($mode !== 'physical' ? $mode : ''), 'country' => $country, 'cat' => $cat],
    'cart_href' => 'dashboard/cart.php',
    'cart_count' => $cart_n,
    'cart_total' => $cart_t,
    'catalog_href' => '', // الرئيسية فيها كل المنتجات: شيلنا أيقونة الكتالوج اللي كانت بتفتح dashboard/products.php
    'account' => $idx_user ? [
        'href' => $idx_is_marketer ? 'dashboard/marketer.php' : 'dashboard/index.php',
        'initial' => mb_substr((string)$idx_user['name'], 0, 1),
        'caption' => $idx_is_marketer ? 'لوحة المسوق' : 'حسابي',
        'label' => mb_substr((string)$idx_user['name'], 0, 15),
    ] : null,
]);
?>

<!-- NAVBAR الموحّد: نفس الشكل في كل الصفحات -->
<?php
// الرئيسية: زر «جميع الأقسام» يفضل جوه الرئيسية (مفيش ربط من الرئيسية لصفحات الداشبورد)
$nav_lead = wf_storefront_nav_lead(['catalog_href' => 'index.php?cat=all#products']);
// أدوات المسوقين (طلب توفير منتج للعميل) لا تظهر في الموقع الأساسي إطلاقاً — مكانها لوحة المسوق
$nav_items = wf_storefront_nav_items([
    'active' => $col,
    'support_href' => 'dashboard/support.php' . ($idx_code ? '?ctx=marketer' : '?ctx=customer'),
    'support_label' => $idx_code ? 'شات المسوقين' : 'دعم العملاء',
    'featured_href' => esc(build_filter_url(['col' => 'featured', 'cat' => 'all', 'page' => 1])) . '#products',
    'best_href' => esc(build_filter_url(['col' => 'best', 'cat' => 'all', 'page' => 1])) . '#products',
    'new_href' => esc(build_filter_url(['col' => 'new', 'cat' => 'all', 'page' => 1])) . '#products',
]);
echo wf_store_navbar($nav_items, ['lead' => $nav_lead]);
?>

<!-- HERO SECTION: SIDEBAR + SLIDER + PROMO -->
<div class="hero-section">
<!-- SIDEBAR CATEGORIES (يعرض فقط تصنيفات الوضع المختار: فيزيكال فقط / رقمي فقط / سوشيال فقط) -->
<aside class="sidebar">
<div class="sidebar-header"> <?php echo $is_digital_only ? 'أقسام المنتجات الرقمية' : ($is_social_only ? 'أقسام السوشيال ميديا' : 'أقسام المنتجات'); ?></div>

<!-- أزرار التبديل بين الأوضاع الثلاثة -->
<div data-ui="u-96460cf9 uho uhp ugz ugs udd uhq">
<a href="?mode=physical&country=<?php echo urlencode($country); ?>&cat=all#products" style="text-align:center;padding:6px 4px;border-radius:8px;font-size:12px;font-weight:800;text-decoration:none;background:<?php echo $mode==='physical'?'var(--slate-950)':'#fff'; ?>;color:<?php echo $mode==='physical'?'#fff':'var(--slate-700)'; ?>;border:1px solid var(--slate-200)"> منتجات</a>
<a href="?mode=digital&cat=all#products" style="text-align:center;padding:6px 4px;border-radius:8px;font-size:12px;font-weight:800;text-decoration:none;background:<?php echo $mode==='digital'?'#7c3aed':'#fff'; ?>;color:<?php echo $mode==='digital'?'#fff':'#7c3aed'; ?>;border:1px solid #ddd6fe"> رقمي</a>
<a href="?mode=social&cat=all#products" style="text-align:center;padding:6px 4px;border-radius:8px;font-size:12px;font-weight:800;text-decoration:none;background:<?php echo $mode==='social'?'#e11d48':'#fff'; ?>;color:<?php echo $mode==='social'?'#fff':'#e11d48'; ?>;border:1px solid #fecdd3"> سوشيال</a>
</div>

<?php if ($is_digital_only): ?>
<!-- تصنيفات الرقمي فقط في القائمة الجانبية -->
<div class="sidebar-item"><a href="?mode=digital&cat=all#products" class="<?php echo $cat==='all'?'on':''; ?>"><span><span class="dot" data-ui="u-6c9a0620 uh8"></span> كل منتجات رقمية</span></a></div>
<?php foreach ($mode_cats as $cn => $ccn): ?>
<div class="sidebar-item"><a href="?mode=digital&cat=<?php echo urlencode($cn); ?>#products" class="<?php echo $cat===$cn?'on':''; ?>"><span><span class="dot" data-ui="u-6c9a0620 uh8"></span><?php echo esc($cn); ?></span></a></div>
<?php endforeach; ?>

<?php elseif ($is_social_only): ?>
<!-- تصنيفات السوشيال فقط في القائمة الجانبية -->
<div class="sidebar-item"><a href="?mode=social&cat=all#products" class="<?php echo $cat==='all'?'on':''; ?>"><span><span class="dot" data-ui="u-fe0671f2 uhs"></span> كل خدمات السوشيال</span></a></div>
<?php foreach ($mode_cats as $cn => $ccn): ?>
<div class="sidebar-item"><a href="?mode=social&cat=<?php echo urlencode($cn); ?>#products" class="<?php echo $cat===$cn?'on':''; ?>"><span><span class="dot" data-ui="u-fe0671f2 uhs"></span><?php echo esc($cn); ?></span></a></div>
<?php endforeach; ?>

<?php else: ?>
<!-- تصنيفات الفيزيكال فقط في القائمة الجانبية (بدون أي تصنيف رقمي أو سوشيال) -->
<div class="sidebar-item"><a href="<?php echo esc(build_filter_url(['mode'=>'physical','cat'=>'all','col'=>''])); ?>#products" class="<?php echo ($cat==='all' && $col==='')?'on':''; ?>"><span><span class="dot"></span> كل المنتجات</span></a></div>
<div class="sidebar-item"><a href="<?php echo esc(build_filter_url(['mode'=>'physical','cat'=>'all','col'=>'featured'])); ?>#products" class="<?php echo $col==='featured'?'on':''; ?>"><span><span class="dot" data-ui="u-716024c6 uht"></span>⭐ المنتجات المميزة</span></a></div>
<div class="sidebar-item"><a href="<?php echo esc(build_filter_url(['mode'=>'physical','cat'=>'all','col'=>'new'])); ?>#products" class="<?php echo $col==='new'?'on':''; ?>"><span><span class="dot" data-ui="u-1e3fbc81 udp"></span> أحدث المنتجات</span></a></div>
<div class="sidebar-item"><a href="<?php echo esc(build_filter_url(['mode'=>'physical','cat'=>'all','col'=>'best'])); ?>#products" class="<?php echo $col==='best'?'on':''; ?>"><span><span class="dot" data-ui="u-fe0671f2 uhs"></span> الأكثر مبيعاً</span></a></div>
<div class="sidebar-item"><a href="<?php echo esc(build_filter_url(['mode'=>'physical','cat'=>'all','col'=>'viewed'])); ?>#products" class="<?php echo $col==='viewed'?'on':''; ?>"><span><span class="dot" data-ui="u-0cb65d0c ugy"></span> الأكثر مشاهدة</span></a></div>
<?php foreach ($mode_cats as $cn => $ccn): ?>
<div class="sidebar-item">
<a href="<?php echo esc(build_filter_url(['mode'=>'physical','cat'=>$cn,'col'=>''])); ?>#products" class="<?php echo ($cat===$cn && $col==='')?'on':''; ?>"><span><span class="dot"></span><?php echo esc($cn); ?></span></a>
</div>
<?php endforeach; ?>
<?php endif; ?>
</aside>

<!-- HERORIGHT -->
<div class="hero-right">
<?php $hero_prods = hero_product_slides(); $slides = $hero_prods ?: jload('slides.json', []); $is_prod_hero = (bool)$hero_prods; ?>
<div class="hero-carousel" data-wf-carousel="1" data-wf-interval="<?php echo $is_prod_hero ? 15000 : 5000; ?>" role="region" aria-label="العروض الرئيسية">
<?php if ($slides): ?>
<div class="hero-track">
<?php foreach ($slides as $i => $sl): ?>
<div class="hero-slide" aria-hidden="<?php echo $i === 0 ? 'false' : 'true'; ?>">
<a href="<?php echo esc($sl['link'] ?? '#products'); ?>"><?php if ($is_prod_hero): ?><img class="hero-bg" src="<?php echo esc($sl['image']); ?>" alt="" aria-hidden="true" loading="lazy" decoding="async"><?php endif; ?><img class="<?php echo $is_prod_hero ? 'hero-img' : ''; ?>" src="<?php echo esc($sl['image']); ?>" alt="<?php echo esc($sl['title'] ?: BRAND); ?>" width="960" height="360" <?php echo $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"'; ?> decoding="async"><?php if ($is_prod_hero && !empty($sl['price'])): ?></a><div class="hero-cap"><b><?php echo esc($sl['title']); ?></b><span><?php echo esc($sl['price']); ?></span></div><?php else: ?></a><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php if (count($slides) > 1): ?>
<button type="button" class="hero-ctrl prev" aria-label="الشريحة السابقة"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg></button>
<button type="button" class="hero-ctrl next" aria-label="الشريحة التالية"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-right></use></svg></button>
<div class="hero-dots" role="tablist" aria-label="شرائح العرض">
<?php foreach ($slides as $i => $_sl): ?>
<button type="button" class="hero-dot <?php echo $i === 0 ? 'active' : ''; ?>" role="tab" aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>" aria-label="شريحة <?php echo $i + 1; ?>"></button>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div data-ui="u-044f63db uhu uhv ue1 ufp uf9 ue7 udn uhw">
<h1 data-ui="u-2c985d16 uhx ue6 udq ueb">تسوق بأفضل الأسعار من <?php echo esc(BRAND); ?></h1>
<p data-ui="u-3e45d0d3 uhj uhy ufi">توصيل سريع لجميع المحافظات • الدفع عند الاستلام</p>
<a class="hero-btn" href="#products">تصفح المنتجات الآن</a>
</div>
<?php endif; ?>
</div>
<section class="section" aria-labelledby="affiliate-intro-title">
  <h2 id="affiliate-intro-title">وفرنهالك أفلييت — منصة تسويق بالعمولة في مصر</h2>
  <a class="btn sm outline" href="affiliate-register.php">اعرف تفاصيل برنامج وفرنهالك أفلييت</a>
</section>

<!-- VALUE PROPS STRIP -->
<div class="value-strip">
  <div class="value-item">
    <div class="value-item-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></div>
    <div><h3>الدفع عند الاستلام</h3><p>افحص طلبك قبل الدفع</p></div>
  </div>
  <div class="value-item">
    <div class="value-item-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
    <div><h3>توصيل سريع</h3><p>لجميع المحافظات لباب البيت</p></div>
  </div>
  <div class="value-item">
    <div class="value-item-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
    <div><h3>تسليم رقمي فوري</h3><p>للألعاب والبطاقات والسوشيال</p></div>
  </div>
  <div class="value-item">
    <div class="value-item-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
    <div><h3>ضمان استرجاع 14 يوم</h3><p>استبدال واسترجاع بسهولة</p></div>
  </div>
</div>
<!-- برامج التسويق بالعمولة (4 كروت، كل كارت يفتح صفحة الشرح) -->
<?php require_once __DIR__ . '/affiliate_programs.php'; ?>
<section class="aff-row" aria-label="برامج التسويق بالعمولة">
<?php foreach (aff_programs() as $k => $c): ?>
<a class="aff-card" href="affiliate-program.php?p=<?php echo esc($k); ?>">
  <img class="aff-bg" src="assets/aff-<?php echo esc($k); ?>.png" alt="" aria-hidden="true" loading="lazy" decoding="async">
  <img class="aff-img" src="assets/aff-<?php echo esc($k); ?>.png" alt="<?php echo esc($c['title']); ?>" width="960" height="540" loading="lazy" decoding="async">
  <span class="aff-cap"><b><?php echo esc($c['title']); ?></b><span><?php echo esc($c['teaser']); ?></span></span>
</a>
<?php endforeach; ?>
</section>
</div>
</div>

<!-- NEWS -->
<?php $news = jload('news.json', []); ?>
<?php if ($news): ?><div class="banner info" data-ui="u-96f26fb6 uhz ui0"><?php foreach ($news as $nw): ?><span><?php echo esc($nw['text']); ?></span><?php endforeach; ?></div><?php endif; ?>

<!-- FILTERS & PRODUCTS -->
<main class="section" id="products">

<?php if ($mode === 'physical'):
// فلتر الدولة بنفس شكل التابات المستخدم في صفحة الكتالوج (مفيش صف شيبس في الرئيسية)
$country_tabs = [];
$country_tab_defs = [['c'=>'all','n'=>'كل الدول']];
foreach (wf_country_list() as $country_name) $country_tab_defs[] = ['c'=>$country_name,'n'=>$country_name];
foreach ($country_tab_defs as $cc) {
    $country_tabs[] = ['href' => esc(build_filter_url(['country'=>$cc['c'],'page'=>1])) . '#products', 'label' => $cc['n'], 'on' => ($country === $cc['c'])];
}
echo wf_tabs($country_tabs, ['label' => 'فلترة الدول']);
endif; ?>

<?php
// جلب المنتجات حسب الوضع المختار (فيزيكال بالتناوب بين كل الأفلييت / رقمي / سوشيال)
$catalog_counts = jload('catalog_counts.json', []);
$catalog_count_valid = is_array($catalog_counts)
    && isset($catalog_counts['physical_with_images'])
    && is_int($catalog_counts['physical_with_images'])
    && $catalog_counts['physical_with_images'] >= 0;
$fast_physical_listing = $catalog_count_valid && $mode === 'physical' && $country === 'all' && $cat === 'all'
    && $col === '' && $q === '' && $plat_view === '' && $sort === '';
$all_items = [];
if ($is_digital_only) {
    $all_items = platform_products_lite('g2g');
    if ($cat !== 'all' && $cat !== '') {
        $all_items = array_values(array_filter($all_items, function ($x) use ($cat) {
            return wf_normalize_category($x['subcategory'] ?? '', '', false) === $cat || wf_normalize_category($x['category'] ?? '') === $cat;
        }));
    }
    if ($q !== '') {
        $all_items = array_values(array_filter($all_items, function ($x) use ($q) {
            return mb_stripos(($x['name'] ?? '') . ' ' . ($x['subcategory'] ?? ''), $q) !== false;
        }));
    }
} elseif ($is_social_only) {
    $all_items = platform_products_lite('social');
    if ($cat !== 'all' && $cat !== '') {
        $all_items = array_values(array_filter($all_items, function ($x) use ($cat) {
            $sp = $x['_soc_plat'] ?? social_plat($x['name'] ?? '');
            return $sp === $cat || ($x['subcategory'] ?? '') === $cat;
        }));
    }
    if ($q !== '') {
        $all_items = array_values(array_filter($all_items, function ($x) use ($q) {
            return mb_stripos(($x['name'] ?? ''), $q) !== false;
        }));
    }
} else {
    $grid_seed = (($mode === 'physical' || $mode === '') && $country === 'all' && $cat === 'all' && $col === '' && $q === '' && $plat_view === '') ? $home_seed : null;
    if ($grid_seed !== null) $GLOBALS['home_seed_url'] = $grid_seed;
    $GLOBALS['wf_home_page'] = $page;
    // حُمّل فقط ما يكفي للصفحة المطلوبة؛ زيادة النافذة تحفظ ترتيب التناوب وتملأ
    // الصفحة حتى مع المنتجات المخفية أو التي لا تملك صورة صالحة.
    $source_limit = $fast_physical_listing ? ($page * $per + 100) : null;
    $all_items = physical_collection($col ?: 'all', $country, $cat, $q, $plat_view ?: 'all', $grid_seed, $source_limit);
}

// تطبيق الترتيب (Sort)
if ($sort === 'price_asc') {
    usort($all_items, function ($a, $b) {
        $pa = (float)(list_price($a, $a['_plat'] ?? '') ?: ($a['price'] ?? 0));
        $pb = (float)(list_price($b, $b['_plat'] ?? '') ?: ($b['price'] ?? 0));
        return $pa <=> $pb;
    });
} elseif ($sort === 'price_desc') {
    usort($all_items, function ($a, $b) {
        $pa = (float)(list_price($a, $a['_plat'] ?? '') ?: ($a['price'] ?? 0));
        $pb = (float)(list_price($b, $b['_plat'] ?? '') ?: ($b['price'] ?? 0));
        return $pb <=> $pa;
    });
} elseif ($sort === 'best') {
    usort($all_items, function ($a, $b) use ($soldmap) {
        $sa = (int)($soldmap[($a['_plat'] ?? '') . '#' . ($a['id'] ?? '')] ?? 0);
        $sb = (int)($soldmap[($b['_plat'] ?? '') . '#' . ($b['id'] ?? '')] ?? 0);
        return $sb <=> $sa;
    });
}

$total = count($all_items);
if ($fast_physical_listing) {
    if (!isset($catalog_counts) || !is_array($catalog_counts)) $catalog_counts = jload('catalog_counts.json', []);
    if (is_array($catalog_counts) && array_key_exists('physical_with_images', $catalog_counts)) {
        // الملخص يحافظ على إجمالي الترقيم من دون فكّ كتالوج سيزما عند كل طلب.
        $total = max(0, (int)$catalog_counts['physical_with_images']);
        $manual_visible = 0;
        foreach (manual_products_all() as $manual_product) {
            $manual_product = normalize_product_record($manual_product, 'manual');
            if (!empty($manual_product['images'])) $manual_visible++;
        }
        // لو الرئيسية استبعدت منتجات اليدوي في الزيارة دي، منحسبهاش في الترقيم.
        if (array_key_exists('wf_home_manual_shown', $GLOBALS)) {
            $manual_visible = min($manual_visible, max(0, (int)$GLOBALS['wf_home_manual_shown']));
        }
        $total += $manual_visible;
        // تصحيحات الأدمن قليلة؛ نتحقق من سجلاتها فقط كي يظل الإجمالي دقيقًا مع الإخفاء/الصورة البديلة.
        $physical_override_plats = ['timehand', 'safqa', 'vendoor', 'angazny', 'shopmart', 'cezma', 'manual'];
        foreach (product_overrides() as $override_key => $override) {
            $pair = explode('#', (string)$override_key, 2);
            if (count($pair) !== 2 || !in_array($pair[0], $physical_override_plats, true)) continue;
            $base_product = find_product_by_id($pair[1], $pair[0]);
            if (!$base_product) continue;
            $base_has_image = !empty($base_product['images']);
            $override_has_image = !empty(clean_product_images((array)($override['image'] ?? [])));
            $is_visible = empty($override['hidden']) && ($base_has_image || $override_has_image);
            $total += (int)$is_visible - (int)$base_has_image;
        }
    }
}
$items = array_slice($all_items, ($page - 1) * $per, $per);
$pages = max(1, (int)ceil($total / $per));
$from_n = $total ? (($page - 1) * $per + 1) : 0;
$to_n   = min($total, $page * $per);
?>

<!-- فلاتر التصنيفات: في الرئيسية الفيزيكال مفيش صف فلاتر ولا شيبس أقسام -->
<?php if ($is_digital_only || $is_social_only): ?>
<div class="filter-chips-scroll" role="navigation" aria-label="فلتر الأقسام">
<?php if ($is_digital_only): ?>
<a href="<?php echo esc(build_filter_url(['mode'=>'digital','cat'=>'all','page'=>1])); ?>#products" class="chip <?php echo $cat === 'all' ? 'is-active' : ''; ?>">كل الرقمي</a>
<?php foreach ($mode_cats as $cn => $ccn): $top_on = ($cat === $cn); ?>
<a href="<?php echo esc(build_filter_url(['mode'=>'digital','cat'=>$cn,'page'=>1])); ?>#products" class="chip <?php echo $top_on ? 'is-active' : ''; ?>"><?php echo esc($cn); ?></a>
<?php endforeach; ?>
<?php elseif ($is_social_only): ?>
<a href="<?php echo esc(build_filter_url(['mode'=>'social','cat'=>'all','page'=>1])); ?>#products" class="chip <?php echo $cat === 'all' ? 'is-active' : ''; ?>">كل السوشيال</a>
<?php foreach ($mode_cats as $cn => $ccn): $top_on = ($cat === $cn); ?>
<a href="<?php echo esc(build_filter_url(['mode'=>'social','cat'=>$cn,'page'=>1])); ?>#products" class="chip <?php echo $top_on ? 'is-active' : ''; ?>"><?php echo esc($cn); ?></a>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endif; ?>

<?php
$sec_title = 'كل المنتجات';
if ($plat_view === 'm5zoon') $sec_title = 'منتجات عربية';
elseif ($is_digital_only) $sec_title = 'منتجات رقمية — تسليم فوري' . ($cat !== 'all' ? ' (' . $cat . ')' : '');
elseif ($is_social_only) $sec_title = 'سوشيال ميديا — تنفيذ رقمي' . ($cat !== 'all' ? ' (' . $cat . ')' : '');
elseif ($col === 'featured') $sec_title = 'المنتجات المميزة';
elseif ($col === 'new') $sec_title = 'أحدث المنتجات';
elseif ($col === 'best') $sec_title = 'الأكثر مبيعاً';
elseif ($col === 'viewed') $sec_title = 'الأكثر مشاهدة';
elseif ($cat !== 'all') $sec_title = $cat;
?>

<!-- CATALOG TOOLBAR الموحّد: نفس الشكل في كل الصفحات -->
<?php
echo wf_catalog_toolbar([
    'title' => $sec_title,
    'meta' => $total ? 'عرض ' . number_format($from_n) . '–' . number_format($to_n) . ' من ' . number_format($total) . ' منتج' : 'لا توجد منتجات مطابقة',
    'sort_id' => 'catalogSortSelect',
    'sort_current' => ($sort !== '' ? $sort : 'default'),
    'sort_options' => [
        'default' => ['label' => 'الافتراضي', 'url' => esc(build_filter_url(['sort' => 'default', 'page' => 1])) . '#products'],
        'price_asc' => ['label' => 'السعر: من الأقل للأعلى', 'url' => esc(build_filter_url(['sort' => 'price_asc', 'page' => 1])) . '#products'],
        'price_desc' => ['label' => 'السعر: من الأعلى للأقل', 'url' => esc(build_filter_url(['sort' => 'price_desc', 'page' => 1])) . '#products'],
        'best' => ['label' => 'الأكثر مبيعاً', 'url' => esc(build_filter_url(['sort' => 'best', 'page' => 1])) . '#products'],
    ],
    'clear_url' => ($q !== '' || $cat !== 'all' || $col !== '' || $sort !== '') ? '?mode=' . urlencode($mode) . '&country=' . urlencode($country) . '&cat=all#products' : '',
]);
?>

<div class="grid">
<?php foreach ($items as $p) pcard2($p, $soldmap); ?>
</div>

<?php if (!$items): ?>
<div class="card" data-ui="u-0dd0904f udn ui6">
  <h3 data-ui="u-751c4669 ui7 ueb">لا توجد نتائج مطابقة لبحثك</h3>
  <p class="mut" data-ui="u-d86dd2cb ufi">جرّب مسح الفلاتر الحالية أو ابحث بكلمة أخرى، أو اطلب توفير المنتج بصورته مباشرة.</p>
  <div data-ui="u-9c9ee36b ue1 ufj uf9 uet">
    <a href="index.php#products" class="btn outline sm">مسح كل الفلاتر</a>
    <a href="dashboard/support.php?ctx=customer" class="btn sm">تواصل مع خدمة العملاء</a>
    <?php if ($top_wa_img !== ''): ?><a href="<?php echo esc($top_wa_img); ?>" target="_blank" rel="noopener" class="btn outline sm"><?php echo wf_icon('whatsapp', 16); ?>ابعت صورة المنتج على واتساب</a><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="ترقيم الصفحات">
  <?php if ($page > 1): ?>
    <a href="<?php echo esc(build_filter_url(['page' => $page - 1])); ?>#products" aria-label="الصفحة السابقة">السابق</a>
  <?php else: ?>
    <span class="pager-disabled" aria-disabled="true">السابق</span>
  <?php endif; ?>
  <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
    <a href="<?php echo esc(build_filter_url(['page' => $i])); ?>#products" class="<?php echo $i===$page?'on':''; ?>" <?php echo $i===$page?'aria-current="page"':''; ?>><?php echo $i; ?></a>
  <?php endfor; ?>
  <?php if ($page < $pages): ?>
    <a href="<?php echo esc(build_filter_url(['page' => $page + 1])); ?>#products" aria-label="الصفحة التالية">التالي</a>
  <?php else: ?>
    <span class="pager-disabled" aria-disabled="true">التالي</span>
  <?php endif; ?>
</nav>
<?php endif; ?>
</main>

<!-- الأقسام الخاصة في الصفحة الرئيسية: صفوف أفقية قابلة للسحب على الموبايل -->
<?php if ($page === 1 && $q === '' && $cat === 'all' && $col === '' && !$is_special_view && $plat_view !== 'm5zoon'):
    // الأقسام الأفقية معاينة فقط؛ 500 مرشح حديث لكل منصة كافٍ لاختيار 8 بطاقات،
    // والروابط «عرض الكل» تستمر باستخدام الكتالوج والفلاتر الكاملين.
    $home_section_candidates = 300;
    $feat_sec   = array_slice(physical_collection('featured', $country, 'all', '', 'all', $home_seed + 1, $home_section_candidates), 0, 8);
    $best_sec   = array_slice(physical_collection('best', $country, 'all', '', 'all', $home_seed + 2, $home_section_candidates), 0, 8);
    $new_sec    = array_slice(physical_collection('new', $country, 'all', '', 'all', $home_seed + 3, $home_section_candidates), 0, 8);
    $viewed_sec = array_slice(physical_collection('viewed', $country, 'all', '', 'all', $home_seed + 4, $home_section_candidates), 0, 8);
?>
<?php if ($feat_sec): ?>
<section class="section" id="featured"><div class="section-head"><h2>المنتجات المميزة</h2><a href="<?php echo esc(build_filter_url(['col'=>'featured','cat'=>'all','page'=>1])); ?>#products">عرض الكل ←</a></div><div class="h-scroll-row"><?php foreach ($feat_sec as $p) pcard2($p, $soldmap); ?></div></section>
<?php endif; ?>

<?php if ($best_sec): ?>
<section class="section" id="best"><div class="section-head"><h2>الأكثر مبيعاً</h2><a href="<?php echo esc(build_filter_url(['col'=>'best','cat'=>'all','page'=>1])); ?>#products">عرض الكل ←</a></div><div class="h-scroll-row"><?php foreach ($best_sec as $p) pcard2($p, $soldmap); ?></div></section>
<?php endif; ?>

<?php if ($new_sec): ?>
<section class="section" id="new"><div class="section-head"><h2>أحدث المنتجات</h2><a href="<?php echo esc(build_filter_url(['col'=>'new','cat'=>'all','page'=>1])); ?>#products">عرض الكل ←</a></div><div class="h-scroll-row"><?php foreach ($new_sec as $p) pcard2($p, $soldmap); ?></div></section>
<?php endif; ?>

<?php if ($viewed_sec): ?>
<section class="section" id="viewed"><div class="section-head"><h2>الأكثر مشاهدة</h2><a href="<?php echo esc(build_filter_url(['col'=>'viewed','cat'=>'all','page'=>1])); ?>#products">عرض الكل ←</a></div><div class="h-scroll-row"><?php foreach ($viewed_sec as $p) pcard2($p, $soldmap); ?></div></section>
<?php endif; ?>
<?php endif; ?>

<!-- SINGLE UNIFIED FOOTER -->
<footer class="site-footer">
<div class="footer-grid">
<div class="footer-brand">
<h4><?php echo esc(BRAND); ?></h4>
<p>منصتك المتكاملة للتسوق الإلكتروني بأفضل الأسعار مع الدفع عند الاستلام وتوصيل سريع لجميع المحافظات، بالإضافة إلى المنتجات الرقمية وخدمات السوشيال.</p>
<div class="footer-contact">
<a href="tel:<?php echo esc($top_phone); ?>" aria-label="اتصال هاتفي" data-ui="u-2c17356e uik uil uf5 uim udq ufo ue7 uf9">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
  </a>
  <a href="mailto:<?php echo esc($top_email); ?>" aria-label="البريد الإلكتروني" data-ui="u-2c17356e uik uil uf5 uim udq ufo ue7 uf9">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
  </a>
</div>
</div>
<div class="footer-col"><h4>السياسات والشفافية</h4><a href="pages.php?p=privacy">سياسة الخصوصية</a><a href="pages.php?p=returns">سياسة الاسترجاع والاستبدال</a><a href="pages.php?p=terms">الشروط والأحكام</a><?php if (!current_user() || wf_current_is_marketer()): ?><a href="affiliate-register.php">برنامج المسوقين</a><?php endif; ?></div>
<div class="footer-col"><h4>خدمة العملاء</h4><?php if (current_user()): ?><a href="<?php echo wf_current_is_marketer() ? 'dashboard/marketer.php' : 'dashboard/index.php'; ?>">حسابي</a><?php endif; ?><a href="dashboard/orders.php">تتبع طلبك</a><a href="dashboard/cart.php">عربة التسوق</a><a href="dashboard/support.php?ctx=customer">خدمة العملاء والدعم المباشر</a></div>
</div>
<div class="bottom">
<a href="affiliate-register.php">برنامج التسويق بالعمولة</a>
<span>© <?php echo date('Y') . ' ' . esc(BRAND); ?> — جميع الحقوق محفوظة</span>
<span>الدفع عند الاستلام • شحن سريع • ضمان 14 يوماً</span>
</div>
</footer>

<!-- MOBILE BOTTOM NAVIGATION الموحّد: نفس الشريط في كل الصفحات -->
<?php echo wf_bottom_nav('home', ['cart_count' => $cart_n, 'logged_in' => (bool)$idx_user, 'prefix' => '', 'shop_href' => 'index.php#products']); ?>
<?php echo wf_whatsapp_float_html(); // زر واتساب خدمة العملاء — ثابت في كل صفحات الموقع الأساسي ?>

<?php if (function_exists('render_easyorder_dialog_html')) render_easyorder_dialog_html(); ?>
<div hidden data-track-meta="<?php echo esc(json_encode(['ref_code'=>function_exists('active_ref') ? active_ref() : ''], JSON_UNESCAPED_UNICODE)); ?>"></div>
<script src="assets/ui.js?v=<?= wf_asset_v('ui.js') ?>" defer></script>
<script src="assets/tracker.js?v=<?= wf_asset_v('tracker.js') ?>" defer></script>
</body></html>
