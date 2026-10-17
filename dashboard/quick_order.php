<?php $PUBLIC_PAGE = true; $NO_SIDEBAR = true; $GLOBALS['WF_PUBLIC_CHROME'] = true; require 'layout.php';
require_once __DIR__ . '/../hevo_api.php';
$id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$plat = resolve_plat($id, $_GET['plat'] ?? $_POST['plat'] ?? '');
$ref = active_ref($_GET['ref'] ?? $_POST['ref'] ?? '');
if (!in_array($plat, ['social', 'g2g'], true)) { header('Location: product.php?id=' . urlencode($id) . ($ref ? '&ref=' . urlencode($ref) : '')); exit; }
$found = find_product_by_id($id, $plat);
if (!$found) { layout_top('غير موجود'); echo '<div data-ui="u-a88c35b1 df d9z"><h3 data-ui="u-aaa621ac d1">الخدمة غير موجودة</h3><br><a class="btn" href="../index.php">رجوع للرئيسية</a></div>'; layout_end(); exit; }
$p = $found; $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
if (function_exists('sb_track_view')) sb_track_view($plat . '#' . $p['id']);
$pname = platform_name($plat, $st['platforms'] ?? []);
$base = store_price($p, $plat);
$cur = $p['currency'] ?? 'ج.م';
$is_social = ($plat === 'social');
$accent = $is_social ? 'var(--mode-social)' : 'var(--mode-digital)';
$back = $is_social ? '../index.php?mode=social#products' : '../index.php?mode=digital#products';

// === بيانات Hevo للسوشيال (أقل من 0.50 -> 0.50 ، ومن فوق 0.50 لحد 1 -> 1 ج.م) ===
$pkgs = [];
if ($is_social && !empty($ex['variants_detailed'])) {
    foreach ($ex['variants_detailed'] as $vd) {
        $raw_pk = (float)preg_replace('/[^\d.]/', '', (string)($vd['price'] ?? 0.5));
        $pk_price = social_norm_price($raw_pk);
        $pkgs[] = ['label' => trim(($vd['size'] ?? '')), 'price' => $pk_price];
    }
}
$smin = max(1, (int)($ex['order_min'] ?? 1));
$smax = max($smin, (int)($ex['order_max'] ?? 1000000));
$perK = social_norm_price((float)($ex['price_per_1000'] ?? 0.5));
$rateK = (float)($ex['rate_per_1000'] ?? round($perK * 0.77, 3));
// السوشيال ميديا: لا عمولة — سعر الألف هو سعر المصدر نفسه، والإضافة (10 قروش) بقت
// للخدمات اللي سعرها أقل من قيمتها بس (حسابها جوّه social_norm_price)
$display_perK = $perK;
$social_addon_val = (float)wf_social_price_addon();
// الإضافة بتظهر بس لو أقل كمية ممكن طلبها سعرها أقل من الإضافة نفسها (خدمة أقل من 10 قروش)
$pkg_addon_used = ($social_addon_val > 0 && $perK > 0 && ($perK * $smin / 1000) > 0 && ($perK * $smin / 1000) < $social_addon_val);
$extra_comm_url = $ref !== '' ? (float)marketer_store_product_comm($ref, $plat, (string)$p['id']) : 0.0;
if ($is_social && $pkgs) {
    foreach ($pkgs as &$pk) {
        $gross_pk = (float)$pk['price'];
        $pkg_num = (float)str_replace(',', '', preg_replace('/[^\d,]/', '', (string)$pk['label']));
        $cost_pk = min($gross_pk * 0.8, round($rateK * $pkg_num / 1000, 2));
        $comm_pk = platform_commission_amount($p, $plat, $gross_pk, max(0, $gross_pk - $cost_pk));
        $pk['gross_price'] = $gross_pk;
        $pk['cost'] = $cost_pk;
        $pk['commission'] = $comm_pk;
        $pk['price'] = store_price_from_gross($p, $plat, $gross_pk, $comm_pk);
    }
    unset($pk);
}
$unit_label = '';
if ($pkgs) { $parts = explode(' ', $pkgs[0]['label']); $unit_label = end($parts); }
$avg_time = $ex['avg_time'] ?? '';

// === بيانات GamsGo للرقمي (متعددة الباقات والأسعار والمدد + كافة تفاصيل الصفحة) ===
$gvars = [];
$dur_counts = [];
if (!$is_social) {
    if (!empty($ex['variants_detailed'])) {
        foreach ($ex['variants_detailed'] as $vd) {
            $lbl = trim((string)($vd['label'] ?? (trim($vd['size'] ?? '') . ' ' . trim($vd['color'] ?? ''))));
            $vp_gross = (float)preg_replace('/[^\d.]/', '', (string)($vd['price'] ?? raw_list_price($p, $plat))) ?: raw_list_price($p, $plat);
            // الرقمي: سعر المصدر = سعر العرض بالدولار × 56 ج.م (أدق من السعر المخزن بالكتالوج)
            if (wf_plat_no_commission($plat) && !$is_social && isset($vd['unit_price_usd']) && is_numeric($vd['unit_price_usd']) && (float)$vd['unit_price_usd'] > 0) {
                $vp_usd_gross = round((float)$vd['unit_price_usd'] * wf_digital_usd_egp(), 2);
                if ($vp_usd_gross > 0) $vp_gross = $vp_usd_gross;
            }
            $vc = isset($vd['cost']) && is_numeric($vd['cost']) ? (float)$vd['cost'] : round($vp_gross * 0.85, 2);
            $vcomm = platform_commission_amount($p, $plat, $vp_gross, max(0, $vp_gross - $vc));
            $vp = store_price_from_gross($p, $plat, $vp_gross, $vcomm);
            $vo = isset($vd['orig_price_egp']) && is_numeric($vd['orig_price_egp']) ? (float)$vd['orig_price_egp'] : 0;
            if ($vo > 0) $vo = store_price_from_gross($p, $plat, $vo, $vcomm);
            $vd_disc = isset($vd['discount_pct']) && is_numeric($vd['discount_pct']) ? (int)$vd['discount_pct'] : 0;
            // الرقمي والسوشيال: مافيشش «توفير%» أو «بدلاً من» — السعر سعر المصدر من غير عمولة
            if (wf_plat_no_commission($plat)) { $vd_disc = 0; $vo = 0; }
            $dur = trim((string)($vd['duration'] ?? 'باقة أساسية'));
            if ($dur !== '') $dur_counts[$dur] = ($dur_counts[$dur] ?? 0) + 1;
            $gvars[] = [
                'label' => $lbl !== '' ? $lbl : 'الباقة الأساسية',
                'attr' => trim((string)($vd['attr'] ?? '')),
                'duration' => $dur,
                'plan_type' => trim((string)($vd['plan_type'] ?? '')),
                'warranty' => trim((string)($vd['warranty'] ?? ($ex['warranty'] ?? 'ضمان شامل'))),
                'delivery_speed' => trim((string)($vd['delivery_speed'] ?? ($ex['delivery'] ?? ' تسليم فوري تلقائي'))),
                'stock' => (int)($vd['stock'] ?? 10),
                'rating' => trim((string)($vd['rating'] ?? '4.9  (98%)')),
                'months' => (int)($vd['months'] ?? 1),
                'price' => $vp,
                'gross_price' => $vp_gross,
                'commission' => $vcomm,
                'cost' => $vc,
                'orig_price' => $vo,
                'discount_pct' => $vd_disc,
                'offer_desc' => trim((string)($vd['offer_desc'] ?? ($vd['desc'] ?? ''))),
            ];
        }
    } elseif (!empty($p['sizes'])) {
        foreach ($p['sizes'] as $sz) {
            $gross_sz = raw_list_price($p, $plat) ?: $base;
            $cost_sz = round($gross_sz * 0.85, 2);
            $comm_sz = platform_commission_amount($p, $plat, $gross_sz, max(0, $gross_sz - $cost_sz));
            $gvars[] = ['label' => $sz, 'attr' => $sz, 'duration' => $sz, 'plan_type' => '', 'warranty' => 'ضمان شامل', 'delivery_speed' => ' تسليم فوري', 'stock' => 10, 'rating' => '4.9 ', 'months' => 1, 'price' => store_price_from_gross($p, $plat, $gross_sz, $comm_sz), 'gross_price' => $gross_sz, 'commission' => $comm_sz, 'cost' => $cost_sz, 'orig_price' => 0, 'discount_pct' => 0];
        }
    }
}
if (!$gvars && !$is_social) {
    $gross_default = raw_list_price($p, $plat) ?: $base;
    $cost_default = round($gross_default * 0.85, 2);
    $comm_default = platform_commission_amount($p, $plat, $gross_default, max(0, $gross_default - $cost_default));
    $gvars[] = ['label' => 'الباقة الأساسية', 'attr' => 'شهر واحد • حساب خاص', 'duration' => 'شهر واحد', 'plan_type' => 'حساب خاص', 'warranty' => 'ضمان شامل', 'delivery_speed' => ' تسليم فوري', 'stock' => 10, 'rating' => '4.9 ', 'months' => 1, 'price' => store_price_from_gross($p, $plat, $gross_default, $comm_default), 'gross_price' => $gross_default, 'commission' => $comm_default, 'cost' => $cost_default, 'orig_price' => 0, 'discount_pct' => 0];
}
$g2g_platform = $ex['platform'] ?? '';
$g2g_delivery = $ex['delivery'] ?? 'تسليم فوري';
$g2g_warranty = $ex['warranty'] ?? 'ضمان طوال مدة الاشتراك';
$g2g_prompt = trim((string)($ex['prompt_tip'] ?? ''));
$g2g_intro = is_array($ex['introduction'] ?? null) ? $ex['introduction'] : [];
$g2g_sections = is_array($ex['article_sections'] ?? null) ? $ex['article_sections'] : [];
$g2g_hiw = is_array($ex['how_it_works'] ?? null) ? $ex['how_it_works'] : [];
$g2g_faqs = is_array($ex['faqs'] ?? null) ? $ex['faqs'] : [];
$qo_imgs = clean_product_images($p['images'] ?? []);
$prod_img = !empty($qo_imgs[0]) ? $qo_imgs[0] : '';

// ===== تنفيذ الطلب =====
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy'])) {
    $cname = trim(mb_substr($_POST['name'] ?? '', 0, 100));
    $cphone = trim(mb_substr($_POST['phone'] ?? '', 0, 30));
    $link = trim(mb_substr($_POST['link'] ?? '', 0, 300));
    $notes_in = trim(mb_substr($_POST['notes'] ?? '', 0, 500));
    $variant = ''; $qty = 1; $unit = 0; $cost = 0; $total = 0; $commission_total = 0;
    if ($cname === '' || $cphone === '') $err = 'اكتب الاسم ورقم الموبايل';
    elseif ($is_social && (strlen($link) < 6 || strpos($link, '.') === false)) $err = 'ضع رابط الحساب أو المنشور بشكل صحيح';
    elseif (!$is_social && strlen($link) < 3) $err = 'ضع بيانات للتواصل مع العميل (بريد أو اسم مستخدم أو واتساب)';
    else {
        if ($is_social) {
            $mode = $_POST['qmode'] ?? 'pkg';
            if ($mode === 'custom') {
                $qty = max(1, (int)($_POST['qty'] ?? 0));
                if ($qty < $smin || $qty > $smax) $err = "الكمية لازم تكون بين " . number_format($smin) . " و " . number_format($smax);
                else {
                    $raw_tot = $perK * $qty / 1000;
                    $gross_total = social_norm_price($raw_tot);
                    $cost = min($gross_total * 0.8, round($rateK * $qty / 1000, 2));
                    $commission_total = platform_commission_amount($p, $plat, $gross_total, max(0, $gross_total - $cost));
                    $total = store_price_from_gross($p, $plat, $gross_total, $commission_total);
                    $unit = round($total / $qty, 4);
                    $variant = number_format($qty) . ' ' . $unit_label;
                }
            } else {
                $pi = (int)($_POST['pkg'] ?? 0);
                if (!isset($pkgs[$pi])) $err = 'اختار الباقة';
                else {
                    $qty = 1;
                    // سعر الباقة جاهز من store_price_from_gross (سعر المصدر، والإضافة للخدمات الصغيرة بس) — مايعادش تقريبه
                    $unit = round((float)$pkgs[$pi]['price'], 2);
                    $total = $unit;
                    $commission_total = (float)($pkgs[$pi]['commission'] ?? 0);
                    $variant = $pkgs[$pi]['label'];
                    $cost = (float)($pkgs[$pi]['cost'] ?? 0);
                }
            }
        } else {
            $vi = (int)($_POST['variant'] ?? 0);
            if (!isset($gvars[$vi])) $err = 'اختار الباقة أو المدة';
            else {
                $qty = max(1, min(100, (int)($_POST['qty'] ?? 1)));
                $unit = (float)$gvars[$vi]['price'];
                $variant = $gvars[$vi]['label'];
                $v_cost = (float)($gvars[$vi]['cost'] ?? round($unit * 0.85, 2));
                $cost = round($v_cost * $qty, 2);
                $commission_total = round((float)($gvars[$vi]['commission'] ?? 0) * $qty, 2);
                $total = round($unit * $qty, 2);
            }
        }
    }
    if ($err === '') {
        $valid_ref = active_ref($ref);
        // «سعر الاتفاق مع العميل» اتشال — الإجمالي دايمًا سعر الموقع + عمولة المسوق المحسوبة سيرفرًا
        $base_total = round($total, 2);   // الإجمالي قبل أي عمولة إضافية من المسوق
        $extra_c = $valid_ref !== '' ? marketer_store_product_comm($valid_ref, $plat, (string)$p['id']) : 0.0;
        if ($extra_c > 0) {
            if ($is_social && ($_POST['qmode'] ?? 'pkg') === 'custom') {
                $total = round($total + $extra_c, 2);
                $unit = round($total / max(1, $qty), 4);
                $target_earn = round($extra_c, 2);
            } else {
                $unit = round($unit + $extra_c, 2);
                $total = round($unit * $qty, 2);
                $target_earn = round($extra_c * $qty, 2);
            }
        } else {
            $target_earn = 0.0;
        }
        $notes = ($is_social ? ' ' . $link : ' بيانات للتوصيل: ' . $link) . ($notes_in ? ' | ' . $notes_in : '');
        $ono = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 5));
        $ptoken = bin2hex(random_bytes(16));
        $has_pay = count(pay_channels()) > 0;
        $new_order = [
            'order_no' => $ono, 'pay_token' => $ptoken,
            'user' => current_user() ? current_user()['email'] : ('guest:' . $cphone), 'plat' => $plat, 'id' => $p['id'],
            'pname' => mb_substr($p['name'] ?? '', 0, 120), 'ref' => $ref, 'm_earn' => 0, 'comm_state' => 'pending', 'sub' => active_sub(),
            'variant' => mb_substr($variant, 0, 120), 'qty' => $qty, 'unit' => $unit, 'cost' => $cost, 'ship' => 0, 'total' => $total,
            'cname' => $cname, 'cphone' => $cphone, 'city' => '', 'address' => '', 'notes' => $notes, 'link' => $link,
            'service_id' => $is_social ? ($ex['service_id'] ?? '') : '',
            'payment_status' => $has_pay ? 'awaiting' : 'cod', 'at' => date('c'), 'status' => 'pending', 'src' => function_exists('wf_traffic_src') ? wf_traffic_src() : '',
            'base_total' => $base_total,
        ];
        jupdate('orders.json', function (&$os) use ($new_order) { if (!is_array($os)) $os = []; $os[] = $new_order; $GLOBALS['_WF_NEW_ORDER_IDX'] = count($os) - 1; return true; }, []);
        $oi = $GLOBALS['_WF_NEW_ORDER_IDX'] ?? null;
        // العمولة الأساسية صفر؛ تُحتسب فقط العمولة الإضافية التي يحددها المسوق بنفسه
        $m_earn = 0;
        $aff = credit_affiliate_amount($valid_ref, $target_earn, $oi, 'عمولة طلب ' . ($is_social ? 'سوشيال' : 'رقمي'));
        $ref = $aff['ref'] ?? '';
        $m_earn = (float)($aff['m_earn'] ?? 0);
        jupdate('orders.json', function (&$os) use ($oi, $m_earn, $ref, $aff) {
            if (isset($os[$oi])) {
                $os[$oi]['m_earn'] = $m_earn;
                $os[$oi]['ref'] = $ref;
                $os[$oi]['comm_state'] = $aff['comm_status'] ?? ($m_earn > 0 ? 'pending' : 'none');
            }
            return true;
        }, []);
        if ($m_earn > 0 && $ref && ($mk2 = find_marketer_by_code($ref))) {
            notify($mk2['email'], ' طلب ' . ($is_social ? 'سوشيال' : 'رقمي') . ' برابطك — عمولة معلقة +' . number_format($m_earn, 2) . ' ج.م', 'dashboard/marketer.php');
        }
        if (function_exists('wf_event')) wf_event('order', ['product_id' => (string)$p['id'], 'plat' => $plat, 'order_no' => $ono, 'value' => $total, 'qty' => $qty]);
        $admin_report = [
            '🧾 طلب ' . ($is_social ? 'سوشيال' : 'رقمي') . ' جديد: ' . $ono,
            '📦 ' . mb_substr($p['name'] ?? '', 0, 80) . ' (' . $plat . ')',
            '🔢 الكمية: ' . $qty . ($variant !== '' ? ' — ' . $variant : ''),
            '💵 الإجمالي: ' . number_format($total, 2) . ' ج.م — سعر الموقع' . ($base_total != $total ? ' (يشمل عمولة المسوق: ' . number_format($total - $base_total, 2) . ' ج.م)' : ''),
            '👤 العميل: ' . $cname . ' — ' . $cphone,
            '🔗 الرابط/الحساب: ' . mb_substr($link, 0, 120),
        ];
        if ($notes_in !== '') $admin_report[] = '📝 ملاحظات: ' . $notes_in;
        if ($ref !== '') {
            $mk_admin = find_marketer_by_code($ref);
            $admin_report[] = '🔗 المسوق: ' . (($mk_admin['name'] ?? '') !== '' ? $mk_admin['name'] : $ref) . ' (' . $ref . ')'
                . ($m_earn > 0 ? ' — عمولة معلقة: ' . number_format($m_earn, 2) . ' ج.م' : '');
        }
        notify('admin', implode("\n", $admin_report), 'dashboard/admin.php?tab=payments');
        if (current_user()) notify(current_user()['email'], ' طلبك ' . $ono . ' مسجّل — أكمل الدفع لبدء التنفيذ', 'dashboard/pay.php?o=' . $ono);
        header('Location: pay.php?o=' . urlencode($ono) . '&t=' . $ptoken); exit;
    }
}
layout_top(($is_social ? 'طلب خدمة ' : 'طلب منتج رقمي '), 'products'); ?>
<?php $media_html = wf_product_media_html($p, false, $plat); if ($media_html !== ''): ?><div id="productMedia"><?php echo $media_html; ?></div><?php endif; ?>
<div data-ui="u-649977dc dc9 dca dcb">
<div class="card" data-ui="u-3792e76b d3w da5 d67 dcc d2n">
<div style="background:linear-gradient(135deg,<?php echo $is_social ? 'var(--mode-social-bg)' : 'var(--mode-digital-bg)'; ?>,#fff);border-bottom:3px solid <?php echo $accent; ?>;color:var(--slate-950);padding:22px">
<div data-ui="u-e7fa512d dh d1p d3q">
<?php if ($prod_img): ?>
<img id="qoMainImg" src="<?php echo esc($prod_img); ?>" alt="<?php echo esc($p['name'] ?? ''); ?>" loading="lazy" onerror="this.onerror=null;this.style.display='none'" data-ui="u-1f7cc593 d5o d5p day d2t d4u d59 dcd dce">
<?php endif; ?>
<div data-ui="u-d2b411f5 d2i d2j">
<div data-ui="u-e95d8a87 do dcf d45"><?php echo $is_social ? 'خدمات سوشيال ميديا' : 'منتجات رقمية'; ?> • <?php echo esc($p['subcategory'] ?? $p['category'] ?? ''); ?></div>
<div data-ui="u-ba68aadf d18 d1 dcg"><?php echo esc(mb_substr($p['name'] ?? '', 0, 90)); ?></div>
<?php if (!$is_social && count($gvars) > 1): ?>
<div data-ui="u-3c677a84 d49 d31 dch d35 d36 do dc"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-copy></use></svg> متوفر <?php echo count($gvars); ?> باقات ومدد اشتراك مختلفة</div>
<?php endif; ?>
</div>
</div>
<?php if ($is_social): ?>
<div data-ui="u-d481c41f do d23 dci dh dj d3z">
<?php if (!empty($ex['service_id'])): ?><span> معرف الخدمة: <?php echo esc($ex['service_id']); ?></span><?php endif; ?>
<span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-download></use></svg> الحد الأدنى: <?php echo number_format($smin); ?></span>
<span>⬆ الحد الأقصى: <?php echo number_format($smax); ?></span>
<span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-clock></use></svg> وقت الاكتمال: <?php echo esc($avg_time ?: 'غير متوفر'); ?></span>
<?php if (!empty($ex['refill'])): ?><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-refresh></use></svg> <?php echo esc($ex['refill']); ?></span><?php endif; ?>
<span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> <?php echo $smin === 1 ? (number_format($perK / 1000, ($perK % 1000 === 0 ? 0 : 2)) . ' ' . esc($cur) . ' للواحدة') : (number_format($perK, ($perK == (int)$perK ? 0 : 2)) . ' ' . esc($cur) . ' / 1000 وحدة'); ?></span>
</div>
<?php if (!empty($p['desc'])): ?>
<div data-ui="u-472e5428 dcj dck d24 d3d d23 do dcl d3v">
ℹ <?php echo nl2br(esc($p['desc'])); ?>
</div>
<?php endif; ?>
<?php else: ?>
<div data-ui="u-65543126 do dp dci dh dj d3z">
<?php if ($g2g_platform): ?><span> <?php echo esc($g2g_platform); ?></span><?php endif; ?>
<?php if ($g2g_delivery): ?><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> <?php echo esc($g2g_delivery); ?></span><?php endif; ?>
<?php if ($g2g_warranty): ?><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-shield></use></svg> <?php echo esc($g2g_warranty); ?></span><?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php if ($is_social && count($qo_imgs) > 1): ?>
<div class="gal-thumbs" aria-label="صور الخدمة">
<?php foreach (array_slice($qo_imgs, 0, 8) as $qi): $qi_u = (string)$qi; ?>
<img src="<?php echo esc($qi_u); ?>" alt="صورة الخدمة" loading="lazy" onclick="var m=document.getElementById('qoMainImg');if(m){m.onerror=null;m.style.display='';m.src=this.src;}" onerror="this.onerror=null;this.parentNode.removeChild(this)">
<?php endforeach; ?>
</div>
<?php endif; ?>
<form method="post" data-ui="u-43ca478d d74">
<?php echo csrf_field(); ?>
<input type="hidden" name="buy" value="1"><input type="hidden" name="plat" value="<?php echo esc($plat); ?>"><input type="hidden" name="id" value="<?php echo esc($p['id']); ?>"><input type="hidden" name="ref" value="<?php echo esc($ref); ?>">
<?php if ($err): ?><div data-ui="u-88de2c2e d5y daj d4y d24 d3d du dm d54"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-warning></use></svg> <?php echo esc($err); ?></div><?php endif; ?>
<?php if ($is_social): ?>
<b data-ui="u-89a895c9 d12 d27 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg> اختار الباقة أو العدد المطلوب تزويده:</b>
<div data-ui="u-2b84a134 dh d5n d1s d2">
<?php foreach ($pkgs as $i => $pk):
    $pk_p = (float)$pk['price'];
    $pk_fmt = ($pk_p >= 1.0 && abs($pk_p - round($pk_p)) < 0.001) ? number_format($pk_p, 0) : ($pk_p >= 1.0 ? number_format($pk_p, 1) : number_format($pk_p, 2));
?>
<label style="display:flex;align-items:center;gap:10px;padding:12px 14px;border:2px solid <?php echo $i === 0 ? $accent : 'var(--slate-200)'; ?>;border-radius:12px;cursor:pointer;font-size:14px;font-weight:700" class="soc-pkg-lbl" onclick="document.querySelectorAll('.soc-pkg-lbl').forEach(function(e){e.style.borderColor='var(--slate-200)'});this.style.borderColor='<?php echo $accent; ?>'">
<input type="radio" name="pkg" value="<?php echo $i; ?>" class="pkgl"<?php echo $i === 0 ? ' checked' : ''; ?> onchange="document.getElementById('qmode_pkg').checked=true;updTotal()">
<span data-ui="u-47f21793 d2i"><?php echo esc($pk['label']); ?></span><b style="color:<?php echo $accent; ?>"><?php echo $pk_fmt; ?> <?php echo esc($cur); ?></b>
</label>
<?php endforeach; ?>
<label data-ui="u-9087a317 dh d1p d1q d8d dcm da d12 dm">
<input type="radio" name="qmode" id="qmode_pkg" value="pkg" checked data-ui="u-e7dd51fd db9">
<input type="radio" name="qmode" id="qmode_custom" value="custom" onchange="updTotal()">
<span>عدد مخصص:</span>
<input name="qty" id="cqty" type="number" min="<?php echo $smin; ?>" max="<?php echo $smax; ?>" value="<?php echo $smin; ?>" oninput="document.getElementById('qmode_custom').checked=true;updTotal()" data-ui="u-e3fee654 d2i d6h dt d1u d1v dm">
</label>
</div>
<div data-ui="u-79171a06 do dn d54">الكمية من <?php echo number_format($smin); ?> إلى <?php echo number_format($smax); ?> • السعر: <?php echo $smin === 1 ? (number_format($display_perK / 1000, ($display_perK % 1000 === 0 ? 0 : 2)) . ' ' . esc($cur) . ' للواحدة') : (number_format($display_perK, ($display_perK == (int)$display_perK ? 0 : 2)) . ' ' . esc($cur) . ' / 1000'); ?> — السعر سعر المصدر من غير أي عمولة<?php if ($pkg_addon_used && $social_addon_val > 0): ?> • الطلبات الأقل من <?php echo number_format($social_addon_val, 2); ?> <?php echo esc($cur); ?> عليها إضافة <?php echo number_format($social_addon_val * 100, ($social_addon_val * 100) == (int)($social_addon_val * 100) ? 0 : 2); ?> قرش<?php endif; ?></div>
<?php else: ?>
<input type="hidden" name="qty" id="gqty" value="1">
<?php if ($g2g_prompt !== ''): ?>
<div data-ui="u-4710d56a d7f d9 da d3d d54 do db dm">
 <?php echo esc($g2g_prompt); ?>
</div>
<?php endif; ?>
<div data-ui="u-eb850063 dh d1p d1o dj d1s d11">
<b data-ui="u-53bdb806 dz dv"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-copy></use></svg> جميع العروض والباقات المتاحة داخل الصفحة (<?php echo count($gvars); ?> عرض متاح):</b>
</div>
<?php if (count($dur_counts) > 1): ?>
<div data-ui="u-af0bccc6 dh dj di d54">
<button type="button" class="g2g-dur-btn" onclick="filterG2GDur('', this)" style="padding:6px 12px;border-radius:99px;border:1.5px solid <?php echo $accent; ?>;background:<?php echo $is_social ? 'var(--mode-social-bg)' : 'var(--mode-digital-bg)'; ?>;color:var(--slate-950);font-family:var(--font);font-size:12px;font-weight:800;cursor:pointer">الكل (<?php echo count($gvars); ?>)</button>
<?php foreach ($dur_counts as $d_lbl => $d_cnt): ?>
<button type="button" class="g2g-dur-btn" onclick="filterG2GDur(<?php echo esc(json_encode($d_lbl)); ?>, this)" data-ui="u-e62215e2 d9k d36 dar d4u d9m d1v do dc d3j"><?php echo esc($d_lbl); ?> (<?php echo (int)$d_cnt; ?>)</button>
<?php endforeach; ?>
</div>
<?php endif; ?>
<div data-ui="u-31ebc370 dh d5n d1q d69 dcn d99 dco">
<?php foreach ($gvars as $i => $gv):
    $is_priv = (mb_stripos($gv['label'] . ' ' . $gv['attr'], 'خاص') !== false || mb_stripos($gv['label'] . ' ' . $gv['attr'], 'Private') !== false || mb_stripos($gv['label'] . ' ' . $gv['attr'], 'كامل') !== false);
    $is_rech = (mb_stripos($gv['label'] . ' ' . $gv['attr'], 'شحن') !== false || mb_stripos($gv['label'] . ' ' . $gv['attr'], 'حسابك') !== false || mb_stripos($gv['label'] . ' ' . $gv['attr'], 'إهداء') !== false);
    $badge_txt = $is_rech ? ' تفعيل على حسابك' : ($is_priv ? ' وصول كامل / ملف خاص' : ' حساب مشترك مضمون');
    $badge_bg = $is_rech ? 'var(--amber-100)' : ($is_priv ? '#dcfce7' : 'var(--tone-e0e7ff)');
    $badge_col = $is_rech ? 'var(--warning-text)' : ($is_priv ? 'var(--tone-166534)' : '#3730a3');
    $p_fmt = (abs($gv['price'] - round($gv['price'])) > 0.01) ? number_format($gv['price'], 1) : number_format($gv['price'], 0);
    $o_fmt = (abs($gv['orig_price'] - round($gv['orig_price'])) > 0.01) ? number_format($gv['orig_price'], 1) : number_format($gv['orig_price'], 0);
?>
<label class="g2g-opt-card" data-dur="<?php echo esc($gv['duration']); ?>" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border:2px solid <?php echo $i === 0 ? $accent : 'var(--slate-200)'; ?>;background:<?php echo $i === 0 ? 'var(--mode-digital-bg)' : '#fff'; ?>;border-radius:14px;cursor:pointer;transition:all .15s" onclick="selG2GCard(this, <?php echo $i; ?>)">
<div data-ui="u-c5bea17c dh dcp d1q d2i d2j">
<input type="radio" name="variant" value="<?php echo $i; ?>" data-price="<?php echo esc($gv['price']); ?>" class="g2g-radio"<?php echo $i === 0 ? ' checked' : ''; ?> onchange="updTotal()" style="margin-top:4px;accent-color:<?php echo $accent; ?>">
<div data-ui="u-d2b411f5 d2i d2j">
<div data-ui="u-759377d9 dc d12 d2k d8j"><?php echo esc($gv['label']); ?></div>
<?php if (!empty($gv['attr'])): ?>
<div data-ui="u-040132cd do dcq dm dc6"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-pin></use></svg> <?php echo esc($gv['attr']); ?></div>
<?php endif; ?>
<div data-ui="u-c7c3b43c dh dj di d49">
<span style="background:<?php echo $badge_bg; ?>;color:<?php echo $badge_col; ?>;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:800"><?php echo $badge_txt; ?></span>
<?php if (!empty($gv['warranty'])): ?>
<span data-ui="u-80f3aeec d15 d1x d6v d2w do dc"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-shield></use></svg> <?php echo esc($gv['warranty']); ?></span>
<?php endif; ?>
<?php if (!empty($gv['delivery_speed'])): ?>
<span data-ui="u-88fe88a0 dcr dcs d6v d2w do dm"><?php echo esc($gv['delivery_speed']); ?></span>
<?php endif; ?>
<?php if (!empty($gv['rating'])): ?>
<span data-ui="u-e1200fc9 ds dn d6v d2w do dm"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-star></use></svg> <?php echo esc($gv['rating']); ?></span>
<?php endif; ?>
<?php if (!empty($gv['discount_pct']) && $gv['discount_pct'] > 0): ?>
<span data-ui="u-b82c566c d5y d53 d6v d2w do dc"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> توفير <?php echo (int)$gv['discount_pct']; ?>%</span>
<?php endif; ?>
</div>
</div>
</div>
<div data-ui="u-e91dc01c d2a dce">
<div style="font-size:18px;font-weight:900;color:<?php echo $accent; ?>"><?php echo $p_fmt; ?> <span data-ui="u-5d985ec3 do"><?php echo esc($cur); ?></span></div>
<?php if (!empty($gv['orig_price']) && $gv['orig_price'] > $gv['price']): ?>
<div data-ui="u-7eca40e6 do d1a dct">بدلاً من <?php echo $o_fmt; ?> <?php echo esc($cur); ?></div>
<?php endif; ?>
</div>
</label>
<?php endforeach; ?>
</div>

<?php $first_odesc = trim((string)($gvars[0]['offer_desc'] ?? '')); ?>
<div id="g2g-offer-desc-box" style="display:<?php echo $first_odesc !== '' ? 'block' : 'none'; ?>;background:var(--info-bg);border:1px solid var(--info-border);border-radius:14px;padding:14px 16px;margin-bottom:14px;font-size:13px;line-height:1.75;color:var(--tone-1e3a8a)">
<b data-ui="u-d7d2907a d3s d27 d2 d12"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> تفاصيل العرض المحدد (Offer Details):</b>
<div id="g2g-offer-desc-text" dir="auto" data-ui="u-d6007ec2 dcu d1v"><?php echo esc($first_odesc); ?></div>
</div>

<?php if ($g2g_intro): ?>
<div data-ui="u-ac40afc9 ds dt d42 dcv d1r du d5e dv">
<b data-ui="u-0f94a3d0 d2k d27 d2 d12"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> مميزات وتفاصيل الخدمة:</b>
<ul data-ui="u-6b67f6dc d19 d5h">
<?php foreach ($g2g_intro as $in_line): ?>
<li data-ui="u-e3b173ef d45"><?php echo esc($in_line); ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>

<?php if ($g2g_sections): ?>
<div data-ui="u-bdef0656 d4u dt d42 d7 d1r du dcw d9m">
<b data-ui="u-675a206c d2k d27 d11 dz"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> الدليل الشامل وكافة تفاصيل الخدمة:</b>
<?php foreach ($g2g_sections as $sec): ?>
<div data-ui="u-385c41ea d1r dcx dcy">
<h3 data-ui="u-75a72da9 d12 d1 dcq dcz"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-pin></use></svg> <?php echo esc($sec['title'] ?? ''); ?></h3>
<?php foreach (($sec['paragraphs'] ?? []) as $pr): ?>
<p data-ui="u-bf9dee41 dcz dn"><?php echo esc($pr); ?></p>
<?php endforeach; ?>
<?php if (!empty($sec['bullets'])): ?>
<ul data-ui="u-5da37ecd dd0 d5h dv">
<?php foreach ($sec['bullets'] as $bl): ?>
<li data-ui="u-e3b173ef d45"><?php echo esc($bl); ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($g2g_hiw): ?>
<div data-ui="u-09981611 dd1 dd2 d42 dcv d1r du d5e dcq">
<b data-ui="u-ce44678a dd3 d27 d2 d12"> طريقة التفعيل والاستخدام:</b>
<?php foreach ($g2g_hiw as $idx => $step): ?>
<div data-ui="u-915ed8b1 d2">
<b><?php echo esc($step['title'] ?? ('خطوة ' . ($idx + 1))); ?>:</b> <?php echo esc($step['content'] ?? ''); ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($g2g_faqs): ?>
<div data-ui="u-767eaeb5 d4u dt d42 dcv d0 du dcl">
<b data-ui="u-7b8eda80 d2k d27 d2d d12"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> الأسئلة الشائعة:</b>
<?php foreach ($g2g_faqs as $fq): ?>
<div data-ui="u-40bdccfc d2d dd4 dd5">
<div data-ui="u-a6a17487 dc dv">• <?php echo esc($fq['q'] ?? ''); ?></div>
<div data-ui="u-d25f0204 dn d4x"><?php echo esc($fq['a'] ?? ''); ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<b data-ui="u-89a895c9 d12 d27 d2d"><?php echo $is_social ? ' لينك الحساب أو المنشور' : ' بيانات للتواصل مع العميل (لتسليم الاشتراك أو التفعيل)'; ?> <span data-ui="u-c0c478b3 d4y">*</span>:</b>
<input name="link" dir="ltr" required placeholder="<?php echo $is_social ? 'https://instagram.com/username أو رابط المنشور' : 'البريد الإلكتروني للحساب / رقم واتساب لاستلام الاشتراك'; ?>" value="<?php echo esc($_POST['link'] ?? ''); ?>" data-ui="u-96ae6ac2 dx dr dt d24 d12 d54">
<div style="background:<?php echo $is_social ? 'var(--mode-social-bg)' : 'var(--tone-f0f9ff)'; ?>;border:1px solid <?php echo $is_social ? 'var(--tone-fecdd3)' : '#bae6fd'; ?>;border-radius:10px;padding:10px 14px;font-size:12px;color:<?php echo $is_social ? 'var(--tone-9f1239)' : 'var(--tone-0369a1)'; ?>;margin-bottom:12px">
 <?php echo $is_social ? 'ضع رابط الحساب أو المنشور بشكل صحيح عشان يتم التنفيذ' : 'اكتب بريدك الإلكتروني (في حال التفعيل على حسابك) أو رقم الواتساب لاستلام بيانات الحساب فوراً'; ?>
</div>
<div data-ui="u-82bd01d1 d21 d2b d1q d11">
<input name="name" required placeholder="الاسم *" value="<?php echo esc($_POST['name'] ?? (current_user()['name'] ?? '')); ?>" data-ui="u-4a676f7a dr dt d24 d12 d1v">
<input name="phone" required placeholder="الموبايل / واتساب *" value="<?php echo esc($_POST['phone'] ?? ''); ?>" data-ui="u-4a676f7a dr dt d24 d12 d1v">
</div>
<input name="notes" placeholder="ملاحظات إضافية (اختياري)" value="<?php echo esc($_POST['notes'] ?? ''); ?>" data-ui="u-a8d0fb2c dx dr dt d24 d12 d1v d1r">
<div data-ui="u-65440ee8 dh d1o d1p ds da dcv d1r">
<span data-ui="u-93ba712f dc dz">الإجمالي المطلوب</span>
<b id="gtotal" style="font-size:22px;font-weight:900;color:<?php echo $accent; ?>"></b>
</div>
<?php if ($extra_comm_url > 0): ?>
<p data-ui="u-ded3e017 d49">الإجمالي يشمل عمولة المسوق المضافة (<?php echo wf_ui_money($extra_comm_url); ?> <?php echo esc($cur); ?>) — نفس المبلغ المسجَّل على الطلب.</p>
<?php endif; ?>
<script>
(function () {
  function patch() {
  var state = document.getElementById('wfQuickOrderState');
  if (!state) return;
  var cfg = {};
  try { cfg = JSON.parse(state.dataset.state || '{}'); } catch (e) { return; }
  var extra = parseFloat(cfg.extraComm || 0) || 0;
  if (extra <= 0 || typeof window.updTotal !== 'function' || window._wfExtraPatched) return;
  window._wfExtraPatched = true;
  var base = window.updTotal;
  window.updTotal = function () {
    base.apply(this, arguments);
    var out = document.getElementById('gtotal');
    if (!out) return;
    var shown = parseFloat((out.textContent || '').replace(/[^0-9.]/g, '')) || 0;
    var units = 1;
    if (!cfg.isSocial) {
      var gq = document.getElementById('gqty');
      units = Math.max(1, parseInt((gq && gq.value) || 1, 10) || 1);
    }
    var total = Math.round((shown + extra * units) * 100) / 100;
    out.textContent = total.toLocaleString('en-US', { maximumFractionDigits: 2 }) + ' ' + (cfg.currency || 'ج.م');
  };
  window.updTotal();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', patch); else patch();
  window.addEventListener('load', patch);
})();
</script>
<button class="btn" style="width:100%;justify-content:center;padding:14px;font-size:16px;background:<?php echo $accent; ?>;border-color:<?php echo $accent; ?>"> تأكيد الطلب الآن</button>
<div data-ui="u-1d624dee df d23 do dn"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> <?php echo $is_social ? 'التنفيذ حسب متوسط الوقت الموضح' : 'تسليم رقمي فوري ومضمون'; ?> • بدون شحن • بدون عنوان</div>
</form>
</div>
</div>
<div hidden id="wfQuickOrderState" data-state="<?php echo esc(json_encode(['perk'=>$is_social ? $perK : 0, 'ratek'=>$is_social ? $rateK : 0, 'socialPct'=>-1, 'socialAddon'=>$is_social ? wf_social_price_addon() : 0, 'socialFixedPrice'=>$is_social && (string)($ex['service_id'] ?? '') === '6489', 'pkgs'=>$is_social ? array_column($pkgs,'price') : [], 'g2gDescs'=>!$is_social ? array_column($gvars,'offer_desc') : [], 'isSocial'=>$is_social, 'accent'=>$accent, 'currency'=>$cur, 'extraComm'=>round($extra_comm_url, 2)], JSON_UNESCAPED_UNICODE)); ?>"></div>
<?php layout_end(); ?>
