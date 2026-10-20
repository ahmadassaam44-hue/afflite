<?php $PUBLIC_PAGE = true; $NO_SIDEBAR = true; $GLOBALS['WF_PRODUCT_DETAIL'] = true; $GLOBALS['WF_PUBLIC_CHROME'] = true; require 'layout.php';
$id = trim((string)($_GET['id'] ?? ''));
$plat = resolve_plat($id, $_GET['plat'] ?? '');
$ref = active_ref($_GET['ref'] ?? '');
$found = find_product_by_id($id, $plat);
if ($found && !empty($found['_plat'])) $plat = $found['_plat'];
if (!$found) { layout_top('غير موجود'); echo '<div data-ui="u-a88c35b1 df d9z"><div data-ui="u-41dadbeb da0 d54"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-search></use></svg></div><h1 data-ui="u-aaa621ac d1">المنتج غير موجود</h1><br><a class="btn" href="../index.php">العودة للرئيسية</a></div>'; layout_end(); exit; }
// السوشيال والرقمي: دخول مباشر على ديالوج الشراء (بدون إظهار اسم المنصة في الرابط)
if ($plat === 'social' || $plat === 'g2g') { header('Location: quick_order.php?id=' . urlencode($id) . ($ref ? '&ref=' . urlencode($ref) : '')); exit; }
$p = $found; $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
$p = with_detail_imgs($p, $plat);
if (function_exists('wf_traffic_src')) wf_traffic_src($ref !== '' ? 'landing' : 'product');

// حدث موحّد: مشاهدة منتج (يغذّي الإحصائيات والـ Funnel)
if (function_exists('wf_event') && !empty($p['id'])) {
    wf_event('product_view', [
        'product_id' => (string)$p['id'],
        'plat'       => (string)($p['_plat'] ?? $plat),
        'name'       => mb_substr((string)($p['name'] ?? ''), 0, 120),
    ]);
}
$ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
$sdisc = src_discount($p, $plat);
// إخفاء أي اسم/إشارة لمنصات الأفلييت
$HIDE_PLAT = true;
$p['_name_clean'] = aff_clean($p['name'] ?? '', true);
if (!empty($p['desc'])) $p['desc'] = aff_clean($p['desc']);
if (!empty($ex['description_html'])) $ex['description_html'] = aff_clean_html($ex['description_html']);
$dname = $p['_name_clean'] !== '' ? $p['_name_clean'] : ($p['name'] ?? 'منتج');
{
    $og_img = $p['images'][0] ?? '';
    if ($og_img && !preg_match('~^https?://~', $og_img)) {
        $og_img = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/' . ltrim(str_replace('../', '', asset_url($og_img)), '/');
    }
    $og_pr = list_price($p, $plat) ?: display_price($p, $plat);
    $GLOBALS['OG'] = ['title' => $dname . ' — ' . number_format($og_pr) . ' ج.م', 'desc' => mb_substr(trim(strip_tags(aff_clean($p['desc'] ?? ''))) ?: ($dname . ' — الدفع عند الاستلام'), 0, 200), 'image' => $og_img, 'price' => $og_pr];
}
if ($plat === 'timehand') {
    $th_media = [];
    foreach ((array)($ex['media_links'] ?? []) as $ml) { if (is_string($ml) && $ml !== '') $th_media[] = $ml; }
    if (!empty($p['url_store']) && is_string($p['url_store'])) $th_media[] = $p['url_store'];
    if (!empty($p['url']) && is_string($p['url'])) $th_media[] = $p['url'];
    $th_blob = (string)($p['desc'] ?? '') . "\n" . (string)($ex['marketing_desc'] ?? '');
    if (preg_match_all('~https?://[^\s<>"\']+~iu', $th_blob, $mmu)) {
        foreach ($mmu[0] as $u) {
            $u = rtrim($u, '.,;)');
            if (stripos($u, 'time-hand.com') !== false) continue;
            $th_media[] = $u;
        }
    }
    $th_media = array_values(array_unique(array_filter($th_media)));
    $ex['media_links'] = array_values(array_unique(array_merge((array)($ex['media_links'] ?? []), $th_media)));
}
$pname = platform_name($plat, $st['platforms'] ?? []);
$base = base_price($p, $plat);
$base_store_price = list_price($p, $plat) ?: display_price($p, $plat);
$extra_comm = $ref ? marketer_store_product_comm($ref, $plat, (string)$p['id']) : 0.0;
$unit0 = round($base_store_price + $extra_comm, 2);
$base_comm = 0.0;
$comm = round($extra_comm, 2);
if ($sdisc) {
    $sdisc['old'] = round(store_price_from_saved($p, $plat, (float)$sdisc['old']) + $extra_comm, 2);
    $sdisc['pct'] = $sdisc['old'] > 0 ? (int)round((1 - $unit0 / $sdisc['old']) * 100) : 0;
}
if ($plat === 'm5zoon') {
    $manual_product_options = product_overrides()[$plat . '#' . (string)($p['id'] ?? '')] ?? [];
    if (!array_key_exists('sizes', $manual_product_options)) $p['sizes'] = [];
    if (!array_key_exists('colors', $manual_product_options)) $ex['colors'] = [];
    $ex['variants_detailed'] = [];
    $ex['stock_rows'] = [];
    $ex['variant_codes'] = [];
}
$base_cart = $unit0;
$cur = $p['currency'] ?? wf_country_currency($p['country'] ?? 'مصر');
if ($plat === 'm5zoon') { $mzCur = mz_src_currency($p); if ($mzCur !== '') $cur = $mzCur; }

// === FAVORITES (logged-in = saved to file, guest = session) ===
$user_email = $user['email'] ?? '';
$wish_file = DATA_DIR . '/wishlist.json';
$all_wish = file_exists($wish_file) ? json_decode(file_get_contents($wish_file), true) : [];
if (!is_array($all_wish)) $all_wish = [];

if (isset($_GET['wish'])) {
    $wk = $plat . '#' . $p['id'];
    $wish_key = $user_email ?: ('guest_' . visitor_id());
    if (!isset($all_wish[$wish_key])) $all_wish[$wish_key] = [];
    if (in_array($wk, $all_wish[$wish_key])) {
        $all_wish[$wish_key] = array_values(array_diff($all_wish[$wish_key], [$wk]));
        if (empty($all_wish[$wish_key])) unset($all_wish[$wish_key]);
        $added_love = false;
        $msg_wish = 'تمت الإزالة من المفضلة';
    } else {
        $all_wish[$wish_key][] = $wk;
        $added_love = true;
        $msg_wish = 'تمت الإضافة للمفضلة ';
    }
    file_put_contents($wish_file, json_encode($all_wish, JSON_UNESCAPED_UNICODE));
    if (function_exists('track_love_event')) {
        track_love_event($plat, $p['id'], $dname, $p['images'][0] ?? '', $unit0, $added_love);
    }
    if (!empty($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'loved' => $added_love]);
        exit;
    }
    header('Location: product.php?id=' . urlencode($p['id']) . ($ref ? '&ref=' . urlencode($ref) : ''));
    exit;
}
$wish_key = $user_email ?: ('guest_' . visitor_id());
$inwish = in_array($plat . '#' . $p['id'], ($all_wish[$wish_key] ?? []) ?: ($all_wish['guest_' . session_id()] ?? []));

// === إضافة المنتج لمتجر المسوق مع تحديد عمولة مخصصة ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_mk_store']) && current_user() && wf_current_is_marketer()) {
    $me_aff = ensure_user_affiliate(current_user()['email']);
    $mcode_s = $me_aff['ref_code'] ?? '';
    if ($mcode_s !== '') {
        $custom_extra = max(0, min((float)MAX_MARKUP_AMOUNT, round((float)($_POST['mk_extra_comm'] ?? 0), 2)));
        $st_mk = marketer_store_get($mcode_s);
        $prods_mk = $st_mk['products'] ?? [];
        $pkey = $plat . '#' . $p['id'];
        if (!empty($_POST['remove_from_store'])) {
            unset($prods_mk[$pkey]);
            unset($_SESSION['aff_custom_comm'][$mcode_s . '#' . $pkey]);
        } else {
            $prods_mk[$pkey] = [
                'plat' => $plat,
                'id' => (string)$p['id'],
                'extra_comm' => $custom_extra,
                'added_at' => date('c'),
            ];
            $_SESSION['aff_custom_comm'][$mcode_s . '#' . $pkey] = $custom_extra;
        }
        marketer_store_save($mcode_s, ['products' => $prods_mk]);
        if (empty($_POST['remove_from_store'])) {
            notify('admin', '➕ منتج أضافه مسوق لمتجره (' . ($p['name'] ?? $p['id']) . ') [' . $pkey . '] — عمولة مضافة '
                . wf_ui_money($custom_extra) . ' ج.م — كود ' . $mcode_s, 'dashboard/admin.php?tab=marketers');
        }
        if (empty($_POST['ajax'])) {
            // رجوع لنفس الصفحة بالسعر بعد العمولة محسوب من جديد
            header('Location: product.php?id=' . urlencode((string)$p['id']) . '&ref=' . urlencode($ref !== '' ? $ref : $mcode_s) . '&saved=1');
            exit;
        }
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            $host_u = site_base_url();
            echo json_encode([
                'ok' => true,
                'in_store' => isset($prods_mk[$pkey]),
                'extra_comm' => $custom_extra,
                'sale_price' => round($base_store_price + $custom_extra, 2),
                'total_comm' => round($custom_extra, 2),
                'store_url' => $host_u . '/store.php?store=' . urlencode($mcode_s),
                'landing_url' => landing_page_url($p['id'], $mcode_s, $custom_extra, $plat),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// === TRACK CART ADD (log to admin) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $cart_log = jload('cart_log.json', []);
    $cart_log[] = [
        'user' => $user_email ?: 'guest_' . substr(session_id(), 0, 8),
        'name' => $user['name'] ?? '',
        'plat' => $_POST['plat'] ?? '',
        'id' => $_POST['id'] ?? '',
        'pname' => $_POST['pname'] ?? '',
        'qty' => (int)($_POST['qty'] ?? 1),
        'variant' => $_POST['variant'] ?? '',
        'at' => date('c')
    ];
    jsave('cart_log.json', array_slice($cart_log, -2000));
}

$views = sb_track_view($plat . '#' . $p['id']) ?? 0;
if (!isset($_SESSION['rv'])) $_SESSION['rv'] = [];
$_SESSION['rv'] = array_slice(array_unique(array_merge([['plat' => $plat, 'id' => $p['id']]], $_SESSION['rv']), SORT_REGULAR), 0, 12);
$sold = 0; foreach (jload('orders.json', []) as $o) if (($o['plat'] ?? '') === $plat && (string)($o['id'] ?? '') === (string)$id) $sold += ($o['qty'] ?? 1);
$uid = $plat . '#' . $p['id'];
$revs = sb_reviews($uid) ?? [];
$avg = $revs ? round(array_sum(array_column($revs, 'rating')) / count($revs), 1) : 0;
$allorders = jload('orders.json', []);
$tot_o = count($allorders); $del_o = count(array_filter($allorders, function ($o) { return ($o['status'] ?? '') === 'تم التوصيل'; }));
$rate_o = $tot_o ? round($del_o / $tot_o * 100, 1) : 0;
$imgs = clean_product_images($p['images'] ?? []);
if (!$imgs) $imgs = [product_first_image($p, $plat)];
// رتّب: الصورة الرئيسية أولًا، وبعدها صور التفاصيل (جداول المقاسات) مباشرة —
// عشان المنتجات اللي عندها أكتر من 12 صورة ما تقطّعش صور التفاصيل من المعرض
if ($imgs) {
    $dimgs = array_values(array_filter($imgs, function($u) { return strpos((string)$u, 'assets/detail/') === 0; }));
    if ($dimgs) {
        $others = array_values(array_filter($imgs, function($u) { return strpos((string)$u, 'assets/detail/') !== 0; }));
        $imgs = $others ? array_merge(array_slice($others, 0, 1), $dimgs, array_slice($others, 1)) : $dimgs;
    }
}
$seo_image = $imgs[0] ?? '';
if ($seo_image !== '' && !preg_match('~^https?://~i', $seo_image)) $seo_image = 'https://wafirinhalk.com/' . ltrim(str_replace('../', '', asset_url($seo_image)), '/');
$seo_currency = ['ج.م' => 'EGP', 'د.إ' => 'AED', 'ر.س' => 'SAR', 'د.أ' => 'JOD', 'ر.ع' => 'OMR'][$cur] ?? 'EGP';
$GLOBALS['SEO_PRODUCT'] = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $dname, 'description' => mb_substr(trim(strip_tags((string)($p['desc'] ?? ''))) ?: ($dname . ' — الدفع عند الاستلام من ' . BRAND), 0, 500), 'image' => $seo_image !== '' ? [$seo_image] : [], 'sku' => (string)$p['id'], 'offers' => ['@type' => 'Offer', 'url' => 'https://wafirinhalk.com/dashboard/product.php?id=' . rawurlencode((string)$p['id']), 'priceCurrency' => $seo_currency, 'price' => number_format((float)$unit0, 2, '.', ''), 'seller' => ['@type' => 'Organization', 'name' => BRAND]]];
layout_top(mb_substr($dname, 0, 50), 'products');
?>
<!-- BREADCRUMB -->
<nav aria-label="مسار التصفح" data-ui="u-37e72b77 da1 da2 dr">
<p data-ui="u-b5d5de5e d5t dn dh d1p di dj">
<a href="../index.php" data-ui="u-c40e0d0f d1d dc">الرئيسية</a>
<span>›</span>
<?php if (!empty($p['category'])): ?>
<a href="../index.php?cat=<?php echo urlencode(wf_normalize_category($p['category'] ?? '')); ?>#products" data-ui="u-81663a3c d1d d5u"><?php echo esc(wf_normalize_category($p['category'] ?? '')); ?></a>
<span>›</span>
<?php endif; ?>
<span aria-current="page" data-ui="u-03327588 dv d5u"><?php echo esc(mb_substr($dname, 0, 65)); ?></span>
</p>
</nav>

<header class="product-page-heading"><h1 id="productHeading" data-ui="u-bba5e600 d6l dc d8j d2d"><?php echo esc($dname); ?></h1></header>
<div class="detail product-overview">
<!-- GALLERY -->
<section class="gal product-gallery" aria-labelledby="productGalleryHeading"><h2 class="sr-only" id="productGalleryHeading">صور المنتج</h2>
<?php if ($imgs): ?>
<a class="product-zoom" data-product-zoom href="<?php echo esc(asset_url($imgs[0])); ?>" target="_blank" rel="noopener" aria-label="تكبير صورة <?php echo esc($dname); ?>" data-ui="u-a2040b1c da3 da4">
<img id="mainimg" fetchpriority="high" decoding="async" alt="<?php echo esc($dname); ?>" referrerpolicy="no-referrer" src="<?php echo esc(asset_url($imgs[0])); ?>" data-ui="u-0e61a915 da d4u da5 dx da6 d5q d27">
<span data-ui="u-e50fd946 da7 da8 da9 daa d3v d9k d36 do dc dab">عرض الصورة بالحجم الكامل</span>
</a>
<?php if (count($imgs) > 1): ?>
<div class="gal-thumbs">
<?php foreach (array_slice($imgs, 0, 12) as $i => $im): ?>
<img loading="lazy" decoding="async" width="72" height="72" alt="<?php echo esc($dname . ' - صورة ' . ($i + 1)); ?>" referrerpolicy="no-referrer" src="<?php echo esc(asset_url($im)); ?>" class="<?php echo $i === 0 ? 'active' : ''; ?>" onclick="document.getElementById('mainimg').src=this.src;document.querySelectorAll('.gal-thumbs img').forEach(function(e){e.classList.remove('active')});this.classList.add('active')" ondblclick="openImgModal(this.src)" onerror="this.style.display='none'">
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php foreach ((wf_product_extras_visible() ? ($p['videos'] ?? []) : []) as $v): ?>
<video class="video" controls preload="none" src="<?php echo esc($v); ?>"></video>
<?php endforeach; ?>
<?php $media_html = wf_product_media_html($p, false, $plat); if ($media_html !== ''): ?><div id="productMedia"><?php echo $media_html; ?></div><?php endif; ?>
</section>

<!-- INFO -->
<section class="info product-summary" aria-labelledby="productHeading">
<?php
$cat_name = wf_normalize_category($p['category'] ?? '');
?>
<div data-ui="u-dd92f95f dh di dj d11">
<?php if ($cat_name): ?><span class="badge green"><?php echo esc($cat_name); ?></span><?php endif; ?>
<?php if (!empty($p['country'])): ?><span class="badge yellow"><?php echo esc($p['country']); ?></span><?php endif; ?>
<?php if (!empty($p['sku']) && current_user()): ?><span class="badge dark" data-ui="u-142eea1e d8e"><?php echo esc($p['sku']); ?></span><?php endif; ?>
</div>



<div data-ui="u-a5e7c15b dh d3z d1p d5t dn d54 dj">
<?php if ($avg): ?>
<span data-ui="u-76e1019e d3e d1p d4g">
<span data-ui="u-439bcc40 dac dz"><?php echo str_repeat('', (int)round($avg)); ?></span>
<span data-ui="u-c6e98247 dc d2k"><?php echo $avg; ?></span>
<span>(<?php echo count($revs); ?> تقييم)</span>
</span>
<?php endif; ?>
<?php if ($sold > 0): ?><span>طلبات مسجلة: <?php echo number_format($sold); ?></span><?php endif; ?>
</div>

<div data-ui="u-f1fed976 dh dad d1s d54 dj">
<span data-ui="u-3433ea83 dae dc d2k" id="pprice"><?php echo wf_ui_money($unit0); ?></span>
<span data-ui="u-e43cea4e daf dn d5u"><?php echo esc($cur); ?></span>
<?php if ($sdisc): ?><s data-ui="u-be633587 daf d1a d5u"><?php echo number_format((float)$sdisc['old']); ?></s><?php endif; ?>
</div>

<?php
$urole = current_user()['role'] ?? '';
$pb_cost = $base; $pb_sale = $unit0; $pb_comm = $comm;
$pb_sugg = raw_list_price($p, $plat);
$pb_min = isset($ex['price_min']) && is_numeric($ex['price_min']) && (float)$ex['price_min'] > 0 ? (float)$ex['price_min'] : null;
$pb_max = isset($ex['price_max']) && is_numeric($ex['price_max']) && (float)$ex['price_max'] > 0 ? (float)$ex['price_max'] : null;
?>
<?php if (wf_current_is_marketer() && ($pb_cost > 0 || $pb_sale > 0)): ?>
<div data-ui="u-95df95d3 dag d43 dah dai da du" style="display:block;width:100%;max-width:100%;box-sizing:border-box;color:#000">
<b><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> بيانات المسوق والعمولة لهذا المنتج:</b>
<div data-ui="u-32212ec8 d21 d2b d1s d62">
<div> السعر الأساسي للمنتج: <b data-ui="u-9aa26154 d2p"><?php echo number_format((float)$base_store_price); ?> <?php echo esc($cur); ?></b></div>
<?php if ($pb_sugg > $base_store_price): ?><div><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> السعر المقترح الأصلي: <b><?php echo number_format((float)$pb_sugg); ?> <?php echo esc($cur); ?></b></div><?php endif; ?>
<div><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg> العمولة الأساسية: <b data-ui="u-0ec29c9b d1a">0 <?php echo esc($cur); ?></b></div>
<div><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-plus></use></svg> عمولتك المحددة: <b data-ui="u-61a4e8ed d1x"><?php echo $extra_comm > 0 ? '+' . number_format((float)$extra_comm) . ' ' . esc($cur) : 'حدد عمولتك بنفسك من الأسفل'; ?></b></div>
<?php if ($pb_min !== null || $pb_max !== null): ?><div><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> نطاق السعر المقترح: <b><?php echo $pb_min !== null ? number_format((float)$pb_min) : '—'; ?> — <?php echo $pb_max !== null ? number_format((float)$pb_max) : '—'; ?> <?php echo esc($cur); ?></b></div><?php endif; ?>
</div>
</div>
<?php endif; ?>

<?php
// زرار «السعر عالي؟ تواصل معنا» للمسوقين بس (الزوار مابيشوفوهوش)، ومش للمنتجات الرقمية
// بيفتح خدمة العملاء، ومعاها تفاصيل المنتج جاهزة في خانة الرسالة (المستخدم يقدر يعدلها قبل الإرسال)
if (!wf_is_digital_plat($plat) && function_exists('wf_current_is_marketer') && wf_current_is_marketer()) {
    $cta_code = trim((string)$ref);
    if ($cta_code === '' && !empty($_SESSION['aff_ref'])) $cta_code = trim((string)$_SESSION['aff_ref']);
    $cta_name = function_exists('aff_clean') ? (aff_clean($p['name'] ?? '', true) ?: (string)($p['name'] ?? '')) : (string)($p['name'] ?? '');
    $cta_base = (float)(list_price($p, $plat) ?: display_price($p, $plat));
    $cta_comm = $cta_code !== '' ? (float)marketer_store_product_comm($cta_code, $plat, (string)$p['id']) : 0.0;
    $cta_lines = [
        'السلام عليكم، السعر عالي شوية — محتاج مساعدة في المنتج ده',
        'المنتج: ' . $cta_name,
        'الكود: ' . $p['id'] . ' (' . $plat . ')',
        'السعر: ' . number_format($cta_base + $cta_comm, 2) . ' ' . $cur,
    ];
    if ($cta_code !== '') $cta_lines[] = 'الرابط: ' . landing_page_url((string)$p['id'], $cta_code, $cta_comm, $plat);
    $cta_href = 'support.php?ctx=customer&prefill=' . rawurlencode(mb_substr(implode(' | ', $cta_lines), 0, 900));
    echo '<div class="price-cta-wrap"><a class="btn lp-cta-btn price-cta" href="' . esc($cta_href) . '"><span>' . wf_svg_icon('wallet', 18) . ' السعر عالي؟ تواصل معنا</span></a></div>';
}
?>
<ul class="product-assurance" aria-label="معلومات الشراء">
<li><?php echo wf_svg_icon('wallet',18); ?>الدفع عند الاستلام</li>
<li><?php echo wf_svg_icon('truck',18); ?>تكلفة الشحن تظهر بعد اختيار المحافظة</li>
<li><a href="../pages.php?p=returns"><?php echo wf_svg_icon('refresh',18); ?>سياسة الاسترجاع والاستبدال</a></li>
</ul>
<nav class="product-section-nav" aria-label="معلومات المنتج"><a href="#pane-desc">التفاصيل والمواصفات</a><?php if (!empty($media_html)): ?><a href="#productMedia">صور وفيديوهات</a><?php endif; ?><a href="#pane-revs">التقييمات</a></nav>

<?php if ($sdisc): ?>
<div data-ui="u-48377e69 d3i do d5y daj d53 d1u d6x d31"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> خصم <?php echo $sdisc['pct']; ?>% — السعر قبل الخصم: <s><?php echo number_format((float)$sdisc['old']); ?> <?php echo esc($cur); ?></s></div>
<?php endif; ?>
<?php if ($plat === 'social'): ?>
<div data-ui="u-5cb9855b dak d43 d32 d33 da do dal">
<b><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-link></use></svg> مهم:</b> عند إتمام الطلب ضع <b>رابط الحساب أو المنشور</b> في ملاحظات الطلب حتى يتم التنفيذ.
<?php if (!empty($ex['avg_time'])): ?><br><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-clock></use></svg> متوسط التنفيذ: <b><?php echo esc($ex['avg_time']); ?></b><?php endif; ?>
</div>
<?php endif; ?>

<section class="product-options" aria-label="اختيارات المنتج">
<?php
// تجميع المتغيرات حسب اللون (كل لون بمقاساته)
// ليدجر: color = مقاس، price = "لون + كود" — نفصل الكود (ld_split) ونجمّع بمفتاح موحّد (ld_key)
// صفقة/فيندور: تسميات زائفة — نفلترها ونعرض الألوان الحقيقية فقط
// أنجزني: التسميات مركبة "موديل لون" — نفصلها (ساده ابيض ← موديل=ساده، لون=ابيض)
$color_groups = [];
$ld_codes = []; // ليدجر: key اللون → الكود (للعرض تحت اسم اللون)
$ag_model_mode = false;
$ag_split_variants = [];
$ag_all_models = [];
if ($plat === 'angazny') {
    foreach ((array)($ex['variants_detailed'] ?? []) as $vd) {
        if (trim((string)($vd['color'] ?? '')) === '') continue;
        list($am, $ac) = ag_split((string)$vd['color']);
        if ($ac === '') continue;
        $szv = trim((string)($vd['size'] ?? ''));
        $ag_split_variants[] = ['model' => $am, 'color' => $ac, 'size' => $szv, 'price' => (string)($vd['price'] ?? '')];
        if ($am !== '') $ag_all_models[$am] = 1;
    }
    $ag_model_mode = count($ag_all_models) >= 2;
    foreach ($ag_split_variants as $s) {
        $key = $ag_model_mode ? ($s['model'] !== '' ? $s['model'] : 'عام') : $s['color'];
        if ($key === '') $key = 'عام';
        $color_groups[$key][] = $s;
    }
} elseif (!empty($ex['variants_detailed']) && is_array($ex['variants_detailed'])) {
    foreach ($ex['variants_detailed'] as $vd) {
        if ($plat === 'ledger') {
            // ليدجر: المقاس في color، "اللون + الكود" في price — نفصلهم ونجمّع باللون فقط
            // (عشان لون بمقاسات 37-46 ولون تاني بمقاسات 42-46 ما يتلخبطوش: كل variant مربوط بلونه)
            list($ld_col, $ld_code) = ld_split($vd['price'] ?? '');
            $cc = ld_key($ld_col);
            if ($ld_code !== '') $ld_codes[$cc] = $ld_code;
        } else {
            $cc = trim((string)($vd['color'] ?? ''));
            if ($plat === 'safqa' && !is_color_label($cc)) continue;
            if ($plat === 'vendoor' && !vd_color_ok($cc)) continue;
        }
        if ($cc === '') { if ($plat === 'safqa' || $plat === 'vendoor') continue; $cc = 'عام'; }
        $color_groups[$cc][] = $vd;
    }
}
$sf_sizes = $p['sizes'] ?? [];
if ($plat === 'safqa' || $plat === 'vendoor' || $plat === 'angazny') {
    $sv = [];
    foreach ((array)($ex['variants_detailed'] ?? []) as $vd) { $s2 = trim((string)($vd['size'] ?? '')); if ($s2 !== '') $sv[] = $s2; }
    $sf_sizes = array_values(array_unique(array_merge($sf_sizes, $sv)));
}
$use_grouped = $plat === 'safqa' ? false : (count($color_groups) > 1 || (count($color_groups) === 1 && !isset($color_groups['عام'])));
if (($plat === 'vendoor' || $plat === 'angazny') && empty($sf_sizes)) $use_grouped = false; // بدون مقاسات → ملتقط مسطح
if ($plat === 'safqa') {
    $colors_eff = array_keys($color_groups);
} elseif ($plat === 'vendoor') {
    $colors_eff = [];
    foreach ((array)($ex['colors'] ?? []) as $cl0) { if (vd_color_ok($cl0)) $colors_eff[vd_color_show($cl0)] = 1; }
    if (!$colors_eff) foreach (array_keys($color_groups) as $cl0) $colors_eff[vd_color_show($cl0)] = 1;
    $colors_eff = array_keys($colors_eff);
} elseif ($plat === 'angazny') {
    $colors_eff = [];
    foreach ($ag_split_variants as $s) $colors_eff[$s['color']] = 1;
    if (!$colors_eff) foreach ((array)($ex['colors'] ?? []) as $cl0) { list($am0, $ac0) = ag_split($cl0); if ($ac0 !== '') $colors_eff[$ac0] = 1; }
    $colors_eff = array_keys($colors_eff);
} else {
    // أي منصة تانية: الألوان لازم تكون أسماء ألوان حقيقية (hex أو اسم معروف) — مش شفرات/تسميات زائفة
    $colors_eff = [];
    foreach ((array)($ex['colors'] ?? []) as $cl0) {
        $cl0 = trim((string)$cl0);
        if ($cl0 === '' || !is_color_label($cl0)) continue;
        $colors_eff[$cl0] = 1;
    }
    $colors_eff = array_keys($colors_eff);
}
if (!is_array($colors_eff)) $colors_eff = [];
// قاعدة عامة: مفيش ألوان ومفيش مقاسات → ما يبان سيلكتور خالص (مخزون/أي منصة بلا متغيرات)
if ($plat !== 'ledger' && empty($sf_sizes) && !$colors_eff) { $use_grouped = false; $color_groups = []; }
?>
<?php if ($plat === 'm5zoon' && !empty($ex['sibling_variants']) && is_array($ex['sibling_variants'])): ?>
<div data-ui="u-f992b352 dak">
<b data-ui="u-89a895c9 d12 d27 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-image></use></svg> اختيارات اللون (<?php echo count($ex['sibling_variants']); ?>):</b>
<div data-ui="u-e743b3f7 dh dj di">
<?php foreach ($ex['sibling_variants'] as $sv): ?>
<a href="product.php?id=<?php echo urlencode($sv['id']); ?><?php echo $ref ? '&ref=' . urlencode($ref) : ''; ?>" data-ui="u-83d65adb d2l">
<button type="button" class="vbtn<?php echo !empty($sv['current']) ? ' on' : ''; ?>" style="display:flex;align-items:center;gap:6px;<?php echo !empty($sv['current']) ? '' : 'opacity:.85;'; ?>">
<?php if (!empty($sv['img'])): ?><img src="<?php echo esc(asset_url($sv['img'])); ?>" data-ui="u-cf7557c8 dam dan d2t dao" alt=""><?php endif; ?>
<?php echo esc($sv['color'] ?: 'بدون لون'); ?>
</button>
</a>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
<?php if ($use_grouped && $plat === 'angazny' && $ag_model_mode): ?>
<div data-ui="u-f992b352 dak">
<b data-ui="u-89a895c9 d12 d27 d2d"> الموديل (<?php echo count($color_groups); ?>):</b>
<div data-ui="u-e743b3f7 dh dj di" id="cbtns">
<?php $gi = 0; foreach ($color_groups as $cc => $rows): ?>
<button type="button" class="cbtn<?php echo $gi === 0 ? ' on' : ''; ?>" data-g="<?php echo $gi; ?>" style="padding:8px 14px;border-radius:50px;border:2px solid <?php echo $gi === 0 ? 'var(--electro-blue)' : 'var(--slate-200)'; ?>;background:<?php echo $gi === 0 ? 'var(--info-bg)' : '#fff'; ?>;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer"><?php echo esc($cc); ?> (<?php echo count($rows); ?>)</button>
<?php $gi++; endforeach; ?>
</div>
</div>
<?php $gi = 0; foreach ($color_groups as $cc => $rows): ?>
<?php
$agByColor = [];
foreach ($rows as $r) { $agByColor[$r['color']][] = $r; }
?>
<div class="cgroup" data-g="<?php echo $gi; ?>" style="margin:14px 0;<?php echo $gi === 0 ? '' : 'display:none'; ?>">
<div data-ui="u-d6f1cf29 dh d1p d1s dj d2d">
<b data-ui="u-5d7ebccb du"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-image></use></svg> اللون:</b>
<?php $ci = 0; foreach ($agByColor as $ac2 => $arows): ?>
<button type="button" class="agchip<?php echo $ci === 0 ? ' on' : ''; ?>" data-g="<?php echo $gi; ?>" data-c="<?php echo $ci; ?>" style="padding:7px 13px;border-radius:50px;border:2px solid <?php echo $ci === 0 ? 'var(--electro-blue)' : 'var(--slate-200)'; ?>;background:<?php echo $ci === 0 ? 'var(--info-bg)' : '#fff'; ?>;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer"><?php echo esc(color_name($ac2)); ?></button>
<?php $ci++; endforeach; ?>
</div>
<b data-ui="u-91e9afba du d27 d2"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> المقاس:</b>
<?php $ci = 0; foreach ($agByColor as $ac2 => $arows): ?>
<div class="crow" data-g="<?php echo $gi; ?>" data-c="<?php echo $ci; ?>" style="display:flex;flex-wrap:wrap;gap:6px;<?php echo $ci === 0 ? '' : 'display:none'; ?>">
<?php $ri = 0; foreach ($arows as $r):
    $vparts = [];
    if ($cc !== 'عام') $vparts[] = $cc;
    $vparts[] = $ac2;
    if ($r['size'] !== '') $vparts[] = $r['size'];
    $vl3 = implode(' / ', $vparts);
    $vp3_raw = preg_replace('/[^\d.]/', '', (string)($r['price'] ?? ''));
    $vp3 = $vp3_raw !== '' ? store_price_from_gross($p, $plat, (float)$vp3_raw) : '';
?>
<button type="button" class="vbtn<?php echo ($gi === 0 && $ci === 0 && $ri === 0) ? ' on' : ''; ?>" data-variant-kind="pair" data-variant-description="<?php echo esc($cc !== 'عام' ? $cc : ''); ?>" data-color="<?php echo esc(color_name($ac2)); ?>" data-size="<?php echo esc($r['size']); ?>" data-v="<?php echo esc($vl3); ?>" data-price="<?php echo esc($vp3); ?>"><?php echo esc($r['size'] !== '' ? $r['size'] : '—'); ?></button>
<?php $ri++; endforeach; ?>
</div>
<?php $ci++; endforeach; ?>
</div>
<?php $gi++; endforeach; ?>

<?php elseif ($use_grouped): ?>
<div data-ui="u-f992b352 dak">
<b data-ui="u-89a895c9 d12 d27 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-image></use></svg> اللون:</b>
<div data-ui="u-e743b3f7 dh dj di" id="cbtns">
<?php $gi = 0; foreach ($color_groups as $cc => $rows): ?>
<?php $is_hex = preg_match('/^#[0-9A-Fa-f]{3,8}$/', $cc); ?>
<button type="button" class="cbtn<?php echo $gi === 0 ? ' on' : ''; ?>" data-g="<?php echo $gi; ?>" style="padding:8px 14px;border-radius:50px;border:2px solid <?php echo $gi === 0 ? 'var(--electro-blue)' : 'var(--slate-200)'; ?>;background:<?php echo $gi === 0 ? 'var(--info-bg)' : '#fff'; ?>;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px"><?php if ($is_hex): ?><span style="width:16px;height:16px;border-radius:50%;background:<?php echo esc($cc); ?>;border:1px solid rgba(0,0,0,.15);display:inline-block;flex-shrink:0"></span><?php endif; ?><?php echo esc($plat === 'ledger' ? vd_color_show($cc) : color_name($cc)); ?><?php if ($plat === 'ledger' && !empty($ld_codes[$cc])): ?> <span data-ui="u-20795b36 do d1a d8e"><?php echo esc($ld_codes[$cc]); ?></span><?php endif; ?></button>
<?php $gi++; endforeach; ?>
</div>
</div>
<?php $gi = 0; foreach ($color_groups as $cc => $rows): ?>
<div class="cgroup" data-g="<?php echo $gi; ?>" style="margin:14px 0;<?php echo $gi === 0 ? '' : 'display:none'; ?>">
<b data-ui="u-89a895c9 d12 d27 d2d"><?php echo $plat === 'ledger' ? ' المقاسات المتاحة:' : ' مقاسات ' . esc($cc) . ':'; ?></b>
<div data-ui="u-e743b3f7 dh dj di">
<?php foreach ($rows as $ri => $vd): ?>
<?php
if ($plat === 'ledger') {
    // ليدجر: المقاس في color، اللون في price — نعرض المقاس فقط (اللون = اسم الجروب)
    $vl2 = trim(($vd['color'] ?? '') . ($cc !== 'عام' ? ' / ' . vd_color_show($cc) : ''));
    $vp2 = preg_replace('/[^\d.]/', '', (string)($vd['price'] ?? ''));
} else {
    $sz2 = trim((string)($vd['size'] ?? ''));
    $parts2 = [];
    if ($sz2 !== '') $parts2[] = $sz2;
    if ($cc !== 'عام') $parts2[] = color_name($cc);
    $vl2 = implode(' / ', $parts2);
    $vp2 = preg_replace('/[^\d.]/', '', (string)($vd['price'] ?? ''));
}
if ($vp2 !== '') $vp2 = (string)store_price_from_gross($p, $plat, (float)$vp2);
$vlbl = $plat === 'ledger' ? (string)($vd['color'] ?? '') : ($sz2 !== '' ? $sz2 : color_name((string)($vd['color'] ?? '')));
?>
<button type="button" class="vbtn<?php echo ($gi === 0 && $ri === 0) ? ' on' : ''; ?>" data-variant-kind="pair" data-color="<?php echo esc($cc !== 'عام' ? ($plat === 'ledger' ? vd_color_show($cc) : color_name($cc)) : ''); ?>" data-size="<?php echo esc($plat === 'ledger' ? ($vd['color'] ?? '') : ($vd['size'] ?? '')); ?>" data-v="<?php echo esc($vl2); ?>" data-price="<?php echo esc($vp2); ?>"><?php echo esc($vlbl); ?></button>
<?php endforeach; ?>
</div>
</div>
<?php $gi++; endforeach; ?>

<?php endif; ?>

<?php if (!$use_grouped && !empty($sf_sizes)): ?>
<div data-ui="u-f992b352 dak">
<b data-ui="u-89a895c9 d12 d27 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> المقاسات المتاحة:</b>
<div data-ui="u-e743b3f7 dh dj di">
<?php foreach (array_slice($sf_sizes, 0, 30) as $i => $sz): ?>
<button type="button" class="vbtn<?php echo $i === 0 ? ' on' : ''; ?>" data-variant-kind="size" data-size="<?php echo esc($sz); ?>" data-v="<?php echo esc($sz); ?>" style="min-width:52px;padding:10px 16px;border-radius:10px;border:2px solid <?php echo $i === 0 ? 'var(--electro-blue)' : 'var(--slate-200)'; ?>;background:<?php echo $i === 0 ? 'var(--info-bg)' : '#fff'; ?>;font-family:var(--font);font-size:14px;font-weight:800;cursor:pointer;transition:all .2s"><?php echo esc($sz); ?></button>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<?php if (!$use_grouped && !empty($colors_eff)): ?>
<div data-ui="u-f992b352 dak">
<b data-ui="u-89a895c9 d12 d27 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-image></use></svg> الألوان المتاحة:</b>
<div data-ui="u-e743b3f7 dh dj di">
<?php foreach (array_slice($colors_eff, 0, 20) as $i => $cl): ?>
<button type="button" class="vbtn<?php echo $i === 0 ? ' on' : ''; ?>" data-variant-kind="color" data-color="<?php echo esc(color_name($cl)); ?>" data-v="<?php echo esc($cl); ?>" data-ui="u-0664c8c0 dh d1p di"><?php if (preg_match('/^#?([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $cl)): ?><span style="width:14px;height:14px;border-radius:50%;background:<?php echo esc($cl); ?>;border:1px solid rgba(0,0,0,.15);flex-shrink:0"></span><?php endif; ?><?php echo esc(color_name($cl)); ?></button>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<?php if (!$use_grouped && !in_array($plat, ['safqa', 'vendoor', 'angazny'], true) && !empty($ex['variants_detailed']) && is_array($ex['variants_detailed']) && wf_product_extras_visible()): ?>
<div data-ui="u-f992b352 dak">
<b data-ui="u-89a895c9 d12 d27 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-copy></use></svg> جدول المتغيرات:</b>
<div data-ui="u-f64a71bf dap d99 dt d24">
<table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-ecc2604f d19 do dx">
<tr><th>اختيار</th><th>المقاس</th><th>اللون</th><th>السعر</th></tr>
<?php foreach (array_slice($ex['variants_detailed'], 0, 50) as $vi => $vd): ?>
<?php $vl = trim(($vd['size'] ?? '') . ' / ' . ($vd['color'] ?? '')); $vp_raw = preg_replace('/[^\d.]/', '', (string)($vd['price'] ?? '')); $vp = $vp_raw !== '' ? store_price_from_gross($p, $plat, (float)$vp_raw) : 0; ?>
<tr data-ui="u-250d5bf3 d3j" onclick="this.querySelector('input').click()">
<td><input type="radio" name="variant_pick" aria-label="<?php echo esc('اختيار '.trim($vl, ' /')); ?>" data-color="<?php echo esc(color_name($vd['color'] ?? '')); ?>" data-size="<?php echo esc($vd['size'] ?? ''); ?>" value="<?php echo esc($vl); ?>" data-price="<?php echo esc($vp); ?>"<?php echo $vi === 0 ? ' checked' : ''; ?>></td>
<td><?php echo esc($vd['size'] ?? ''); ?></td>
<td><?php echo esc(color_name($vd['color'] ?? '')); ?></td>
<td data-ui="u-f30ba456 dm d2p"><?php echo $vp > 0 ? number_format($vp, 2) : '—'; ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

</div>
<?php endif; ?>

<?php if (!empty($ex['size_chart']) && $plat !== 'vendoor'): ?>
<div data-ui="u-f992b352 dak">
<b data-ui="u-89a895c9 d12 d27 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> جدول المقاسات:</b>
<div data-ui="u-1ae67359 d13"><?php echo $ex['size_chart']; ?></div>
</div>
<?php endif; ?>

</section>
<p class="product-choice-summary" id="productChoiceSummary" aria-live="polite" hidden></p>

<div class="product-quantity" id="orderbox" data-ui="u-b905033b dh d1q d1p daq dj">
<label for="bqty" data-ui="u-ad0a9ff4 dc d12">الكمية:</label>
<div data-ui="u-808be6c3 d3e d1p dar d24 d2n d4u">
  <button type="button" aria-label="تقليل الكمية" onclick="var q=document.getElementById('bqty');q.value=Math.max(1,(parseInt(q.value||1,10)||1)-1);" data-ui="u-033dc7b5 d5v d5w d3w ds dc de d3j">−</button>
  <input id="bqty" type="number" value="1" min="1" aria-label="الكمية" data-ui="u-f5bdfaf9 das d5w d3w df dc d1v">
  <button type="button" aria-label="زيادة الكمية" onclick="var q=document.getElementById('bqty');q.value=(parseInt(q.value||1,10)||1)+1;" data-ui="u-033dc7b5 d5v d5w d3w ds dc de d3j">+</button>
</div>
<div data-ui="u-aaa3fbb4 d12 ds dt d24 d3d">قيمة المنتجات قبل الشحن: <b id="buytotal" data-ui="u-9dfa35fd d2k d9h"><?php echo wf_ui_money($unit0); ?></b> <?php echo esc($cur); ?></div>
<?php if (function_exists('wf_bulk_discount_plat_ok') && wf_bulk_discount_plat_ok($plat) && (float)$unit0 > 0): ?>
<div data-ui="u-aaa3fbb4 d12 ds dt d24 d3d" id="buyBulkRow" hidden><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> خصم الكمية: <b id="buyBulkExtra">1</b> قطعة إضافية × <b><?php echo (int)LP_BUNDLE_DISCOUNT; ?></b> ج.م = <b id="buyBulkDisc" style="color:var(--success)">0</b> <?php echo esc($cur); ?></div>
<?php endif; ?>
</div>


<div class="product-buy-actions" data-ui="u-53c8146e dh d1q dj">
<button type="button" class="btn" data-ui="u-0600db7b dat dau dw dy d26 d4e d3v" onclick="openProductCheckoutDialog()">اطلب الآن — الدفع عند الاستلام</button>
<form method="post" action="cart.php" data-ui="u-4d05275d d2i dav" id="addform">
<?php echo csrf_field(); ?>
<input type="hidden" name="add" value="1"><input type="hidden" name="plat" value="<?php echo esc($plat); ?>"><input type="hidden" name="id" value="<?php echo esc($p['id']); ?>">
<input type="hidden" name="pname" value="<?php echo esc(mb_substr($dname, 0, 120)); ?>"><input type="hidden" name="price" value="<?php echo esc($unit0); ?>">
<input type="hidden" name="base" value="<?php echo esc($base_cart); ?>"><input type="hidden" name="img" value="<?php echo esc($imgs[0] ?? ''); ?>">
<input type="hidden" name="ref" value="<?php echo esc($ref); ?>"><input type="hidden" name="qty" id="addqty" value="1">
<input type="hidden" name="variant" id="variantpick" value="">
<input type="hidden" name="color" id="productColorPick" value="">
<input type="hidden" name="size" id="productSizePick" value="">
<button type="button" class="btn outline" data-ui="u-0b6d0c74 dx dw dy" onclick="document.getElementById('addqty').value=document.getElementById('bqty').value;pickV();this.disabled=true;this.form.submit()">أضف للسلة</button>
</form>
</div>


<!-- FAVORITES + SHARE -->
<div class="product-save-actions" data-ui="u-8bfe5d3b dh d1q dp">
<a class="btn sm outline" role="button" data-wishlist data-wishlist-text href="?id=<?php echo urlencode($p['id']); ?><?php echo $ref ? '&ref=' . urlencode($ref) : ''; ?>&wish=1" data-ui="u-46e920c9 d2i dw df d2l"><?php echo $inwish ? ' في المفضلة' : ' أضف للمفضلة'; ?></a>
</div>
<div class="product-share-actions" data-ui="u-6ae2ccb7 dh d3z d23 do dn">
<span>مشاركة:</span>
<a target="_blank" href="https://wa.me/?text=<?php echo urlencode($dname); ?>" data-ui="u-e9788d31 d28">واتساب</a>
<a target="_blank" href="https://t.me/share/url?url=<?php echo urlencode((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']); ?>" data-ui="u-e9788d31 d28">تيليجرام</a>
<a target="_blank" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']); ?>" data-ui="u-e9788d31 d28">فيسبوك</a>
</div>

<?php
$has_opts = (!empty($p['sizes']) || !empty($ex['colors']) || !empty($ex['variants_detailed']));

?>

<?php if (current_user() && wf_current_is_marketer()): ?>
<?php
$me2 = ensure_user_affiliate(current_user()['email']);
$mcode = $me2['ref_code'] ?? '';
$mk_st_cur = $mcode ? marketer_store_get($mcode) : ['products' => []];
$pkey_cur = $plat . '#' . $p['id'];
$in_my_store = isset($mk_st_cur['products'][$pkey_cur]);
$my_saved_extra = $in_my_store ? (float)($mk_st_cur['products'][$pkey_cur]['extra_comm'] ?? 0) : $extra_comm;
$host_base = site_base_url();
$plink = $mcode ? aff_link($p['id'], $mcode, $my_saved_extra, $plat) : '';
$landing_link = $mcode ? landing_page_url($p['id'], $mcode, $my_saved_extra, $plat) : '';
$landing_rel = $mcode ? ('../landing.php?id=' . urlencode($p['id']) . '&ref=' . urlencode($mcode) . ($my_saved_extra > 0 ? '&comm=' . $my_saved_extra : '')) : '';
$store_link = $mcode ? ($host_base . '/store.php?store=' . urlencode($mcode)) : '';
$store_rel = $mcode ? ('../store.php?store=' . urlencode($mcode)) : '';
?>
<?php if ($mcode): ?>
<details class="product-marketer-tools" open data-ui="u-b49b6509 dl d7 daw dax day du"><summary>أدوات المسوق وعمولتي</summary>
<form id="mkSaveForm" method="post"><?php echo csrf_field(); ?><input type="hidden" name="add_to_mk_store" value="1"></form>
<?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
  <p class="badge green" role="status">✅ اتحفظت عمولتك على المنتج: +<?php echo number_format((float)$my_saved_extra, 2); ?> <?php echo esc($cur); ?> — دي اللي هتظهر لأي حد يفتح لينك المنتج ده.</p>
<?php endif; ?>
  <div data-ui="u-606cffe3 dh d1p d1o d1s dj d11">
    <b data-ui="u-ddcfc892 d12 d84"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> أدوات المسوق (متجرك + صفحة الهبوط + عمولتك المخصصة)</b>
    <span id="mkStoreBadge" class="badge <?php echo $in_my_store ? 'green' : 'neutral'; ?>"><?php echo $in_my_store ? ' مضاف في متجرك' : 'غير مضاف لمتجرك بعد'; ?></span>
  </div>
  <div data-ui="u-45f84e93 d4u d64 da dg d11">
    <label for="mkCustomCommInput" data-ui="u-658be79d do dc d9m d27 d2"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> العمولة الأساسية 0 <?php echo esc($cur); ?> — حدد عمولتك التي تريد إضافتها على المنتج:</label>
    <div data-ui="u-9db13808 dh d1s d1p dj">
      <input type="number" id="mkCustomCommInput" name="mk_extra_comm" form="mkSaveForm" value="<?php echo (float)$my_saved_extra; ?>" min="0" max="5000" step="5" data-ui="u-15c86a20 d93 d85 d8v d24 d1 d12 d19" oninput="updateMkPreview()">
      <button type="submit" form="mkSaveForm" class="btn sm">✔ حفظ العمولة</button>
      <div data-ui="u-9b2ba0e0 dh d4g dj">
        <?php foreach ([30, 50, 75, 100, 150] as $qcomm): ?>
        <button type="button" onclick="document.getElementById('mkCustomCommInput').value=<?php echo $qcomm; ?>;updateMkPreview();" data-ui="u-b1c28ce4 d4i d1u d64 ds d2k do dc d3j">+<?php echo $qcomm; ?></button>
        <?php endforeach; ?>
      </div>
      <span data-ui="u-70bda48d do dc d2k">سعر البيع للعميل: <b id="mkPrevPrice" data-ui="u-c0c478b3 d4y"><?php echo number_format($base_store_price + $my_saved_extra, 0); ?></b> <?php echo esc($cur); ?> | إجمالي عمولتك: <b id="mkPrevComm" data-ui="u-61a4e8ed d1x"><?php echo number_format($my_saved_extra, 0); ?></b> <?php echo esc($cur); ?></span>
    </div>
    <?php
    $mk_clean_summary = product_marketing_summary($p, $plat);
    $mk_cols_list = clean_product_colors($p, $plat);
    $mk_sizes_list = clean_product_sizes($p, $plat);
    $mk_ad_post = " " . $dname . "\n\n"
        . ($mk_clean_summary ? mb_substr($mk_clean_summary, 0, 600) . "\n\n" : '')
        . ($mk_cols_list ? " الألوان المتوفرة: " . implode(' - ', array_slice($mk_cols_list, 0, 10)) . "\n" : '')
        . ($mk_sizes_list ? " المقاسات المتاحة: " . implode(' - ', array_slice($mk_sizes_list, 0, 12)) . "\n" : '')
        . " شحن سريع لجميع المحافظات والدفع نقداً عند الاستلام مع إمكانية المعاينة قبل الدفع ";
    ?>
    <div data-ui="u-442099d2 dh d1s d23 dj">
      <button type="submit" form="mkSaveForm" class="btn sm" data-ui="u-8254dc57 d4e d3v"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-store></use></svg> حفظ وإضافة لمتجري</button>
      <a id="mkOpenLandingBtn" href="<?php echo esc($landing_rel); ?>" target="_blank" class="btn sm" data-ui="u-6ba13958 d4m d3v d2l"> فتح صفحة الهبوط</a>
      <button type="button" class="btn sm outline" onclick="wfCopy(document.getElementById('mkLandingInput').value)"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-copy></use></svg> نسخ رابط الهبوط</button>
      <button type="button" class="btn sm outline" data-ui="u-ef253412 d8x d8y d8z" onclick="wfCopy(<?php echo htmlspecialchars(json_encode($mk_ad_post, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?> + '\n السعر: ' + document.getElementById('mkPrevPrice').textContent + ' <?php echo esc($cur); ?>')"> نسخ البوست الإعلاني</button>
    </div>
  </div>
  <div data-ui="u-6e4a8249 d21 daz di do">
    <div data-ui="u-e2abaef1 dh di d1p">
      <span data-ui="u-31eb99cd dc db0"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> صفحة الهبوط:</span>
      <input id="mkLandingInput" readonly value="<?php echo esc($landing_link); ?>" dir="ltr" data-ui="u-7c1a3bfd d2i d1t d8g d1u do d4u d19">
    </div>
    <div data-ui="u-e2abaef1 dh di d1p">
      <span data-ui="u-31eb99cd dc db0"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-store></use></svg> رابط متجرك:</span>
      <input id="mkStoreInput" readonly value="<?php echo esc($store_link); ?>" dir="ltr" data-ui="u-7c1a3bfd d2i d1t d8g d1u do d4u d19">
      <a href="<?php echo esc($store_rel); ?>" target="_blank" class="btn sm outline" data-ui="u-0d2e14c4 d2l d6x">فتح متجري</a>
    </div>
  </div>
</details>

<?php endif; endif; ?>
</section></div>

<!-- ===== STACKED PRODUCT DETAILS & REVIEWS (تحت بعض بدون تكرار) ===== -->
<div class="product-details-stack" data-ui="u-4c659caf da1 db1 db2 dh d5n db3">

<!-- DESC -->
<section class="product-detail-panel" id="pane-desc" data-ui="u-3d942159 d4u dt d67 d68 db4">
<h3 data-ui="u-f8657a26 d48 d1 d0 dh d1p d1s d2k"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> تفاصيل ومواصفات المنتج</h3>
<?php echo render_product_details_html($p, $plat, ['is_landing' => false, 'in_root' => false, 'hide_media' => true]); ?>
</section>

<!-- REVIEWS -->
<section class="product-detail-panel" id="pane-revs" data-ui="u-3d942159 d4u dt d67 d68 db4">
<h3 data-ui="u-b2e91e62 de d1 d54"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-star></use></svg> التقييمات والمراجعات (<?php echo count($revs); ?>)<?php echo $avg ? ' — المتوسط: ' . $avg . '/5' : ''; ?></h3>
<?php if ($revs): ?>
<div data-ui="u-25724057 d0">
<?php $dist = [5=>0,4=>0,3=>0,2=>0,1=>0]; foreach ($revs as $rv) $dist[(int)$rv['rating']]++; ?>
<?php foreach ([5,4,3,2,1] as $s): $pc = $revs ? round($dist[$s]/count($revs)*100) : 0; ?>
<div data-ui="u-dff2a3e5 dh d1q d1p du d45">
<span data-ui="u-4d1dcfe9 db5"><?php echo $s; ?> </span>
<div data-ui="u-ae1d1054 d2i d6n d6j db6"><div style="width:<?php echo $pc; ?>%;background:var(--amber-400);height:6px;border-radius:4px"></div></div>
<span data-ui="u-e4a11819 d2u dn"><?php echo $pc; ?>%</span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (current_user() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rating'])): ?>
<?php $ok = sb_add_review($uid, current_user()['name'], $_POST['rating'], $_POST['comment'] ?? '', current_user()['email'] ?? '');
if ($ok) notify('admin', '⭐ تقييم جديد من ' . current_user()['name'] . ' على ' . ($p['name'] ?? $uid) . ' — '
    . (int)$_POST['rating'] . '/5 — ' . mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 90), 'dashboard/admin.php?tab=reviews'); ?>
<div style="padding:10px;border-radius:8px;margin-bottom:10px;background:<?php echo $ok ? '#dcfce7' : 'var(--danger-bg)' ?>;color:<?php echo $ok ? 'var(--tone-166534)' : 'var(--danger-text)' ?>"><?php echo $ok ? ' تم النشر' : ' تعذر'; ?></div>
<?php $revs = sb_reviews($uid) ?? $revs; endif; ?>
<?php foreach (array_slice($revs, 0, 20) as $rv): ?>
<div data-ui="u-0123a372 d2f d6e">
<div data-ui="u-db23a61e dh d1o"><b data-ui="u-5d7ebccb du"><?php echo esc($rv['user_name']); ?></b><span data-ui="u-490d566d dac du"><?php echo str_repeat(wf_svg_icon('star',16), (int)$rv['rating']); ?></span></div>
<p class="mut" data-ui="u-392dd6f0 d3i"><?php echo esc($rv['comment']); ?></p>
</div>
<?php endforeach; ?>
<?php if (current_user()): ?>
<form method="post" data-ui="u-cb56e193 dq dh d1s dj d1p">
<?php echo csrf_field(); ?>
<select name="rating" data-ui="u-a1763d12 d6h dt d1u d1v"><?php foreach ([5,4,3,2,1] as $i): ?><option value="<?php echo $i; ?>"><?php echo $i; ?> </option><?php endforeach; ?></select>
<input name="comment" data-ui="u-33053624 d2i d3d dt d1u d1v" placeholder="اكتب رأيك...">
<button class="btn sm">نشر</button>
</form>
<?php else: ?>
<?php if (!$revs): ?><p class="mut">لا توجد تقييمات لهذا المنتج حتى الآن.</p><?php endif; ?>
<?php endif; ?>
</section>

<!-- RELATED -->
<?php
$cat_for_related = wf_normalize_category($p['category'] ?? '', 'all');
$rel_pool = physical_collection('all', $p['country'] ?? 'all', $cat_for_related !== '' ? $cat_for_related : 'all');
$rel = [];
foreach ($rel_pool as $rp_item) {
    if ((string)($rp_item['id'] ?? '') === (string)$p['id']) continue;
    if (empty($rp_item['images'][0])) continue;
    $rel[] = $rp_item;
    if (count($rel) >= 8) break;
}
if (!$rel) {
    $rel = sb_related($plat, $cat_for_related === 'all' ? '' : $cat_for_related, $p['id'], 8) ?? [];
}
?>
<?php if ($rel): ?>
<?php $show_comm_rel = (bool)wf_current_is_marketer(); ?>
<div data-ui="u-89a6204f db7">
<div class="section-head"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-link></use></svg> منتجات مماثلة — <?php echo esc(wf_normalize_category($p['category'] ?? '')); ?></h2></div>
<div class="grid">
<?php foreach ($rel as $r): ?>
<?php $rpl = $r['_plat'] ?? $plat; $rlink = 'product.php?id=' . urlencode($r['id']) . ($ref ? '&ref=' . urlencode($ref) : ''); $rbase = base_price($r, $rpl); $rcomm = product_commission_unit($r, $rpl, null, $ref); $rimg = product_first_image($r, $rpl); $rname = aff_clean($r['name'] ?? '', true); $rpr = marketer_effective_price($r, $rpl, $ref); ?>
<div class="pcard">
<div class="pcard-img"><a aria-label="<?php echo esc($rname); ?>" href="<?php echo esc($rlink); ?>"><img alt="صورة المنتج" loading="lazy" referrerpolicy="no-referrer" src="<?php echo esc($rimg); ?>" onerror="this.style.display='none'"></a></div>
<div class="pcard-title"><a href="<?php echo $rlink; ?>" data-ui="u-10c055d6 d2l db8"><?php echo esc(mb_substr($rname, 0, 60)); ?></a></div>
<div class="pcard-price"><?php echo wf_ui_money($rpr); ?> <small><?php echo esc($r['currency'] ?? $cur); ?></small></div>
<?php if ($show_comm_rel && $rcomm > 0): ?><div data-ui="u-234050a1 do dn d3i">عمولة: <b data-ui="u-61a4e8ed d1x"><?php echo wf_ui_money($rcomm); ?></b></div><?php endif; ?>
<div data-ui="u-63c618f4 d62 dh di">
<button type="button" class="btn sm" data-ui="u-cc6dd4b9 d2i dw d4e d3v" data-easy-order="<?php echo esc(json_encode(["id"=>(string)$r["id"],"plat"=>$rpl,"name"=>$rname,"price"=>$rpr,"base_price"=>list_price($r,$rpl)?:display_price($r,$rpl),"extra_comm"=>marketer_store_product_comm($ref,$rpl,(string)$r["id"]),"img"=>$rimg,"ref"=>$ref], JSON_UNESCAPED_UNICODE)); ?>"> اطلب الآن</button>
</div>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<!-- RECENTLY VIEWED -->
<?php if (!empty($_SESSION['rv'])): ?>
<div data-ui="u-89a6204f db7">
<div class="section-head"><h2> شوهدت مؤخراً</h2></div>
<div class="grid"><?php $n=0; foreach ($_SESSION['rv'] as $rv2): if(($rv2['plat']===$plat)&&(string)$rv2['id']===(string)$id)continue;$pp=find_product_by_id($rv2['id'],$rv2['plat']);if($pp&&!empty($pp['images'])){$pp['_plat']=$rv2['plat'];pcard($pp,$rv2['plat']);if(++$n>=4)break;} endforeach;?></div>
</div>
<?php endif; ?>
</div>

<!-- IMAGE LIGHTBOX MODAL WITH CLOSE BUTTON -->
<div id="imgLightbox" role="dialog" aria-modal="true" aria-label="معرض صور المنتج" onclick="if(event.target===this)closeImgModal()" data-ui="u-18bd3fde db9 dba dbb dbc dbd dbe d1p dw d68">
<button type="button" onclick="closeImgModal()" aria-label="إغلاق" data-ui="u-c1e4f2d3 dba dbf dbg dbh d4f d3v dbi d36 d9p d1v dz d1 d3j d3e d1p d1s dbj"> إغلاق</button>
<button type="button" id="lbPrev" onclick="event.stopPropagation();stepImgModal(-1)" aria-label="السابق" data-ui="u-11ca782c dba dbg dbk dbl dbh dbm d3v dbn dbo d2z d30 d18 d1 d3j dh d1p dw"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg></button>
<button type="button" id="lbNext" onclick="event.stopPropagation();stepImgModal(1)" aria-label="التالي" data-ui="u-3aff51d1 dba dbp dbk dbl dbh dbm d3v dbn dbo d2z d30 d18 d1 d3j dh d1p dw"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-right></use></svg></button>
<div data-ui="u-6805ea1c da3 dbq dbr dh d5n d1p" onclick="event.stopPropagation()">

<img id="imgLightboxTarget" src="" alt="<?php echo esc($dname); ?>" data-ui="u-29839303 dbx dby d5q d42 d4u dbz">
</div>
</div>


<!-- STICKY MOBILE BUY BAR -->
<div class="lp-sticky-bar product-sticky-bar sm:hidden">
  <div data-ui="u-fdd6f40e dh d1p d1q d2j">
    <?php if (!empty($imgs[0])): ?><img src="<?php echo esc(asset_url($imgs[0])); ?>" alt="" width="42" height="42" data-ui="u-9260e3c5 dc0 dc1 d5q d1u dt d4u"><?php endif; ?>
    <div data-ui="u-9405b6d6 d2j">
      <div data-ui="u-c7a83a6e do dn d2m d2n d2o d51"><?php echo esc($dname); ?></div>
      <div data-ui="u-9ee5e841 d26 dc d2k"><span id="productStickyPrice"><?php echo wf_ui_money($unit0); ?></span> <small data-ui="u-5d985ec3 do"><?php echo esc($cur); ?></small></div>
    </div>
  </div>
  <button type="button" class="btn" data-ui="u-b3d80fb8 d4e d3v dc2" onclick="openProductCheckoutDialog()">اطلب الآن</button>
</div>

<div hidden id="wfProductState" data-state="<?php echo esc(json_encode(['id'=>(string)$p['id'], 'plat'=>(string)$plat, 'name'=>$dname, 'unit'=>(float)$unit0, 'base_price'=>(float)$base_store_price, 'extra_comm'=>(float)$extra_comm, 'img'=>asset_url($imgs[0] ?? ''), 'ref'=>(string)$ref, 'currency'=>$cur, 'colors'=>array_values(array_map('color_name', array_slice($colors_eff ?? [], 0, 20))), 'sizes'=>array_values(array_slice($sf_sizes ?? [], 0, 30)), 'mk_landing_url'=>!empty($mcode) ? (($host_base ?? '') . '/landing.php?id=' . urlencode($p['id']) . '&ref=' . urlencode($mcode)) : '', 'mk_landing_rel'=>!empty($mcode) ? ('../landing.php?id=' . urlencode($p['id']) . '&ref=' . urlencode($mcode)) : ''], JSON_UNESCAPED_UNICODE)); ?>"></div>
<?php layout_end(); ?>
