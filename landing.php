<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ui-components.php';

$cu = current_user();
if ($cu && !empty($cu['email']) && ($cu['role'] ?? '') !== 'admin') {
    ensure_user_affiliate($cu['email']);
    $cu = current_user();
}

$id = trim((string)($_GET['id'] ?? ''));
$plat_hint = trim((string)($_GET['plat'] ?? ($_SESSION['lp_last_plat_' . $id] ?? '')));
if ($id !== '' && !empty($_GET['plat'])) {
    $_SESSION['lp_last_plat_' . $id] = trim((string)$_GET['plat']);
}
$ref = active_ref($_GET['ref'] ?? '');

$p = find_product_by_id($id, $plat_hint);
// بديل محدود جدًا (٦ منتجات كحد أقصى) لو الرابط قديم/المنتج اتشال — من غير ما نحمّل الكتالوج كله.
// قبل كده كنا بنبني «المنتجات المميزة» من كل المنصات، وده اللي كان بيوقّع صفحة الهبوط بخطأ التحميل.
if (!$p && $ref !== '') {
    $st_fallback = marketer_store_get($ref);
    $fb_try = 0;
    foreach (($st_fallback['products'] ?? []) as $fb_key => $fb_meta) {
        if ($fb_try >= 6) break;
        $fb_pl = $fb_meta['plat'] ?? '';
        $fb_id = (string)($fb_meta['id'] ?? '');
        if ($fb_id === '' && strpos((string)$fb_key, '#') !== false) {
            list($fb_pl, $fb_id) = explode('#', (string)$fb_key, 2);
        }
        if ($fb_id === '') continue;
        $fb_try++;
        $p = find_product_by_id($fb_id, $fb_pl);
        if ($p) {
            $id = (string)$p['id'];
            $plat_hint = $p['_plat'] ?? $fb_pl;
            break;
        }
    }
}
if (!$p) {
    http_response_code(404);
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>صفحة الهبوط</title><link rel="preload" href="assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields"><body data-ui="u-96cfb774 uj6 udd ue1 ue7 uf9 uj7 ufu"><div data-ui="u-24080e41 ueg uj8 uj9 uja udn ujb"><div data-ui="u-74f76427 ujc"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-store></use></svg></div><h2 data-ui="u-f097fe05 ujd">هذا العرض غير متاح حالياً</h2><p data-ui="u-58f762da udy uda">تأكد من رابط صفحة الهبوط أو تواصل مع المسوق.</p></div></body></html>';
    exit;
}
$plat = $p['_plat'] ?? resolve_plat($id, $plat_hint);
$p = with_detail_imgs($p, $plat);
$ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];

// حفظ العمولة المخصصة للمسوق من الرابط أو من إعدادات متجره
$extra_comm = 0.0;
if (isset($_GET['comm']) && is_numeric($_GET['comm']) && (float)$_GET['comm'] >= 0) {
    $extra_comm = min((float)MAX_MARKUP_AMOUNT, round((float)$_GET['comm'], 2));
    if ($ref !== '') {
        $_SESSION['aff_custom_comm'][$ref . '#' . $plat . '#' . $p['id']] = $extra_comm;
    }
} elseif ($ref !== '') {
    $extra_comm = marketer_store_product_comm($ref, $plat, (string)$p['id']);
}

// السماح للمسوق بحفظ العمولة المخصصة مباشرة من شريط التحكم في صفحة الهبوط
$mk_owner = $ref !== '' ? find_marketer_by_code($ref) : null;
// أدوات المسوق في صفحة الهبوط لصاحب حساب مسوّق فقط (حساب العميل العادي مفيش فيه عمولة يحددها)
$is_mk_viewer = (bool)($cu && !empty($cu['email']) && function_exists('wf_current_is_marketer') && wf_current_is_marketer());
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_landing_comm']) && $is_mk_viewer) {
    $me_aff = ensure_user_affiliate($cu['email']);
    $my_code = $me_aff['ref_code'] ?? $ref;
    $new_extra = max(0, min((float)MAX_MARKUP_AMOUNT, round((float)($_POST['custom_comm'] ?? 0), 2)));
    if ($my_code !== '') {
        $_SESSION['aff_custom_comm'][$my_code . '#' . $plat . '#' . $p['id']] = $new_extra;
        $st_cur = marketer_store_get($my_code);
        $prods = $st_cur['products'] ?? [];
        $prods[$plat . '#' . $p['id']] = [
            'plat' => $plat,
            'id' => (string)$p['id'],
            'extra_comm' => $new_extra,
            'added_at' => date('c'),
        ];
        marketer_store_save($my_code, ['products' => $prods]);
        header('Location: landing.php?id=' . urlencode((string)$p['id']) . '&ref=' . urlencode($my_code) . ($new_extra > 0 ? '&comm=' . $new_extra : '') . '&saved=1');
        exit;
    }
}

$clean_name = aff_clean($p['name'] ?? '', true) ?: ($p['name'] ?? 'منتج أصلي');
$clean_desc = product_marketing_summary($p, $plat);
$imgs = clean_product_images($p['images'] ?? []);
if (!$imgs) $imgs = [product_first_image($p, $plat)];

$base_store_price = list_price($p, $plat) ?: display_price($p, $plat);
$final_price = round($base_store_price + $extra_comm, 2);
$old_price = round($final_price * 1.28);
$savings = max(0, $old_price - $final_price);
$base_comm = 0.0;
$total_comm = round($extra_comm, 2);

$colors = clean_product_colors($p, $plat);
$sizes = clean_product_sizes($p, $plat);
// دولة المنتج بتحدد جدول الشحن: مصر = محافظات بالجنيه (جدول تايم هاند)، والخليج = مناطق الدولة بعملتها
$lp_country = wf_product_country($p);
$ship_rates = function_exists('site_ship_map') ? site_ship_map($plat, $lp_country) : ['القاهرة' => 70, 'الجيزة' => 70, 'الإسكندرية' => 75];
$ship_cur = wf_ship_currency($plat, $lp_country);
$region_word = wf_ship_region_label($lp_country);
$store_cfg = $ref !== '' ? marketer_store_get($ref) : ['title' => BRAND, 'whatsapp' => ''];

$eo_payload = json_encode([
    'id' => (string)$p['id'],
    'plat' => $plat,
    'name' => $clean_name,
    'price' => $final_price,
    'base_price' => $base_store_price,
    'extra_comm' => $extra_comm,
    'bundle_discount' => (int)LP_BUNDLE_DISCOUNT,   // لكل قطعة إضافية (مش للطلب كله)
    // الخصم بالجنيه بس: منتج سعره بعملة تانية (ر.س/د.إ/د.أ/ر.ع) مفيش عليه خصم كمية
    'bulk_applies' => function_exists('wf_bulk_discount_plat_ok') ? wf_bulk_discount_plat_ok($plat, wf_ui_currency($p)) : true,
    'img' => asset_url($imgs[0]),
    'ref' => $ref,
    'currency' => wf_ui_currency($p),
    // دولة المنتج: بتحدد جدول الشحن (مصر = محافظات بالجنيه، الخليج = مناطق الدولة بعملتها)
    'country' => wf_product_country($p),
    'colors' => $colors,
    'sizes' => $sizes,
], JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="ar" dir="rtl" style="--lp: <?php echo esc($store_cfg['theme'] ?? '#059669'); ?>; --lp-fg: <?php echo esc(wf_contrast_fg($store_cfg['theme'] ?? '#059669')); ?>; --lp-ink: <?php echo esc(wf_theme_ink($store_cfg['theme'] ?? '#059669')); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#059669">
<title><?php echo esc($clean_name); ?> — عرض خاص والدفع عند الاستلام</title>
<meta property="og:title" content="<?php echo esc($clean_name); ?> — <?php echo wf_ui_money($final_price); ?> <?php echo esc(wf_ui_currency($p)); ?>">
<meta property="og:description" content="<?php echo esc(mb_substr($clean_desc ?: $clean_name, 0, 160)); ?>">
<meta property="og:image" content="<?php echo esc(asset_url($imgs[0])); ?>">



<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields">

</head>
<body class="is-landing">
<?php wf_ui_sprite(); ?>
<a class="skip-link" href="#landingContent">تخطي إلى تفاصيل المنتج</a>

<?php
// ===== صفحة هبوط مستقلة: بدون شريط المتجر ولا البحث ولا السلة ولا أسماء المنصات =====
wf_traffic_src('landing');
?>
<header class="lp-topbar" id="lpTopBar">
  <div class="lp-topbar-in">
    <span class="lp-topbar-title"><?php echo esc(mb_substr($clean_name, 0, 42)); ?></span>
    <span class="lp-topbar-price"><?php echo wf_ui_money($final_price); ?> <small><?php echo esc(wf_ui_currency($p)); ?></small></span>
    <button type="button" class="lp-cta-btn" data-ui="u-915a89a5 ufk uk8 uee uk6" onclick="triggerLandingModal()">
      <svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> اطلب الآن
    </button>
  </div>
</header>


<?php
// حدث مشاهدة صفحة هبوط (لإحصاءات روابط المسوق: كام شخص دخل وكام شخص طلب)
if (function_exists('wf_event')) {
    wf_event('landing_view', ['product_id' => (string)$p['id'], 'plat' => (string)$plat, 'ref' => (string)$ref]);
}
?>

<main class="lp-container" id="landingContent">

  <div class="lp-hero">
    <!-- معرض الصور -->
    <div>
      <div class="lp-main-img-wrap">
        <img id="lpMainImg" fetchpriority="high" decoding="async" src="<?php echo esc(asset_url($imgs[0])); ?>" alt="<?php echo esc($clean_name); ?>" referrerpolicy="no-referrer">
      </div>
      <?php if (count($imgs) > 1): ?>
      <div class="lp-thumbs">
        <?php foreach (array_slice($imgs, 0, 10) as $idx => $im): $im_u = asset_url($im); ?>
          <img src="<?php echo esc($im_u); ?>" loading="lazy" decoding="async" width="68" height="68" class="<?php echo $idx === 0 ? 'active' : ''; ?>" onclick="document.getElementById('lpMainImg').src=this.src;document.querySelectorAll('.lp-thumbs img').forEach(function(e){e.classList.remove('active')});this.classList.add('active')" referrerpolicy="no-referrer" alt="<?php echo esc($clean_name . ' - صورة ' . ($idx + 1)); ?>">
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- تفاصيل العرض والشراء الفوري -->
    <div>
      <div data-ui="u-c1403068 ue1 ue7 ues uet ueb">
        <span data-ui="u-e5ee3285 uf6 ugj uiq uds udt ue6 ugi"><?php echo esc(wf_normalize_category($p['category'] ?? '', 'منتج أصلي')); ?></span>
        <span data-ui="u-651a8f7c uey uex uji uds udt ue6">متاح للطلب</span>
      </div>

      <h1 data-ui="u-1db0611b ujj udu ujk ujl uej"><?php echo esc($clean_name); ?></h1>

      <div class="lp-price-box">
        <div>
          <div data-ui="u-42e44135 udt ue6 ugj uew">سعر المنتج:</div>
          <div data-ui="u-c6b7bd94 ue1 ugh ufj">
            <span class="lp-now-price"><?php echo wf_ui_money($final_price); ?> <small data-ui="u-c9e0a4c3 ujm">ج.م</small></span>
            
          </div>
        </div>
        
      </div>

      <?php if ($colors): ?>
      <div data-ui="u-fe8011b4 ueh">
        <label data-ui="u-ef892320 udg ue6 uer ugv"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-image></use></svg> اختر اللون:</label>
        <div class="lp-chip-row" id="lpColorChips">
          <?php foreach ($colors as $ci => $c): ?>
            <button type="button" class="lp-chip <?php echo $ci === 0 ? 'active' : ''; ?>" data-val="<?php echo esc($c); ?>" onclick="selectLpChip('lpColorChips', this)"><?php echo esc($c); ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($sizes): ?>
      <div data-ui="u-fe8011b4 ueh">
        <label data-ui="u-ef892320 udg ue6 uer ugv"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> اختر المقاس:</label>
        <div class="lp-chip-row" id="lpSizeChips">
          <?php foreach ($sizes as $si => $s): ?>
            <button type="button" class="lp-chip <?php echo $si === 0 ? 'active' : ''; ?>" data-val="<?php echo esc($s); ?>" onclick="selectLpChip('lpSizeChips', this)"><?php echo esc($s); ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div data-ui="u-3ca58e94 ufe">
        <label data-ui="u-afaa35ca udg ue6 uer ugv ufc"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg> اختر العرض المناسب لك:</label>
        <div data-ui="u-12d0069a uho ujn ufj" id="lpQtyBundles">
          <div class="lp-chip active" data-qty="1" onclick="selectLpBundle(1, this)" data-ui="u-df5e3f1c udn ujo">
            <div data-ui="u-e0552804 udu uda">قطعة واحدة</div>
            <div data-ui="u-4e43534e uhn ue6 udg"><?php echo wf_ui_money($final_price); ?> <?php echo esc(wf_ui_currency($p)); ?></div>
          </div>
          <?php $lp_cur = wf_ui_currency($p); $lp_b2 = function_exists('wf_bulk_discount') ? (float)wf_bulk_discount(2, $plat, $lp_cur) : 0.0; $lp_b3 = function_exists('wf_bulk_discount') ? (float)wf_bulk_discount(3, $plat, $lp_cur) : 0.0; ?>
          <?php if ($lp_b2 > 0): ?>
          <div class="lp-chip" data-qty="2" onclick="selectLpBundle(2, this)" data-ui="u-94aa87b6 udn ujo uhk">
            <span class="lp-bundle-save">وفّر <?php echo (int)$lp_b2; ?> ج.م — قطعتين</span>
            <div data-ui="u-e0552804 udu uda">قطعتين (2x)</div>
            <div data-ui="u-4e43534e uhn ue6 udg"><?php echo number_format(function_exists('wf_bundle_total') ? wf_bundle_total($final_price, 2, $plat, $lp_cur) : ($final_price * 2 - 70), 0); ?> <?php echo esc(wf_ui_currency($p)); ?></div>
          </div>
          <?php endif; ?>
          <?php if ($lp_b3 > $lp_b2): ?>
          <div class="lp-chip" data-qty="3" onclick="selectLpBundle(3, this)" data-ui="u-94aa87b6 udn ujo uhk">
            <span class="lp-bundle-save">وفّر <?php echo (int)$lp_b3; ?> ج.م — 3 قطع</span>
            <div data-ui="u-e0552804 udu uda">3 قطع (3x)</div>
            <div data-ui="u-4e43534e uhn ue6 udg"><?php echo number_format(function_exists('wf_bundle_total') ? wf_bundle_total($final_price, 3, $plat, $lp_cur) : ($final_price * 3 - 140), 0); ?> <?php echo esc(wf_ui_currency($p)); ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <button type="button" class="lp-cta-btn" onclick="triggerLandingModal()">
        <span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> اضغط هنا للطلب الآن — الدفع عند الاستلام</span>
      </button>
      <div data-ui="u-3cd7aa62 udn ujt">
        <a href="#directOrderSection" data-ui="u-309842d0 uf1 uhn ue6 ufn">أو املأ استمارة الشراء المباشر بالأسفل ↓</a>
      </div>

      <div class="lp-guarantees">
        <div class="lp-guar-item"><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg></span><span>الدفع كاش عند الاستلام</span></div>
        <div class="lp-guar-item"><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-truck></use></svg></span><span>شحن سريع لكل المحافظات</span></div>
        <div class="lp-guar-item"><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-search></use></svg></span><span>معاينة الطلب قبل الاستلام</span></div>
        <div class="lp-guar-item"><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-shield></use></svg></span><span>ضمان استبدال واسترجاع 14 يوم</span></div>
      </div>
    </div>
  </div>

  <!-- مميزات وتفاصيل المنتج (تحت بعض) -->
  <div class="lp-sec-card">
    <h2 data-ui="u-e0a7649c uju udu ujv uej ue1 ue7 ues"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> تفاصيل ومواصفات <?php echo esc($clean_name); ?></h2>
    <?php
    // صفحة الهبوط: بدون بلوك «صور وفيديوهات المنتج» وبدون روابط Google Drive (المسوق بيجهز الإعلان من لوحته)
    echo render_product_details_html($p, $plat, ['is_landing' => true, 'in_root' => true, 'hide_media' => true]);
    ?>
  </div>

  <!-- نموذج الشراء المباشر المدمج في صفحة الهبوط -->
  <div class="lp-sec-card" id="directOrderSection" data-ui="u-9359ae56 ujw ujx">
    <div data-ui="u-17f0c29e udn ufi">
      <span data-ui="u-c4ce3153 uf6 uf7 udr uds udt udu"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-lock></use></svg> طلب آمن وسريع 100% — الدفع عند الاستلام</span>
      <h2 data-ui="u-0150ecf9 ujy udu ujz uej"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-copy></use></svg> سجّل بياناتك الآن لإتمام الطلب فوراً</h2>
      <p data-ui="u-f56e2cf4 udg uek ufu">لا تحتاج لدفع أي مبلغ الآن — الدفع نقداً للمندوب عند استلام ومعاينة طلبك</p>
    </div>

    <form id="lpInlineOrderForm" onsubmit="return submitLpInlineOrder(event)" data-ui="u-e54a67cf uk0 uj4 ueg udj uk1 ug6 uk2 ue1 ufp ugg">
      <?php echo csrf_field(); ?>
      <div>
        <label data-ui="u-c6c3b1ec uf1 ue6 udi ugv uew"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-user></use></svg> الاسم بالكامل *</label>
        <input type="text" name="name" required placeholder="اكتب اسمك الثلاثي" data-ui="u-4aefbbed uem uk3 uig udf ufr uda">
      </div>
      <div data-ui="u-578211d7 uho uk4 ugg">
        <div>
          <label data-ui="u-c6c3b1ec uf1 ue6 udi ugv uew"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-phone></use></svg> رقم الموبايل (11 رقم) *</label>
          <input type="tel" name="phone" required pattern="01[0125][0-9]{8}" placeholder="01xxxxxxxxx" dir="ltr" data-ui="u-36b603ad uem uk3 uig udf ufr uda ufh">
        </div>
        <div>
          <label data-ui="u-c6c3b1ec uf1 ue6 udi ugv uew"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-phone></use></svg> رقم موبايل بديل (اختياري)</label>
          <input type="tel" name="alt_phone" placeholder="رقم آخر للتأكيد السريع" dir="ltr" data-ui="u-36b603ad uem uk3 uig udf ufr uda ufh">
        </div>
      </div>
      <div data-ui="u-578211d7 uho uk4 ugg">
        <div>
          <label data-ui="u-c6c3b1ec uf1 ue6 udi ugv uew"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-map></use></svg> <?php echo esc($region_word); ?> *</label>
          <select name="gov" id="lpInlineGov" required onchange="updateLpInlineSummary();wfFillCityList('lpInlineGov','lpInlineDistrictSel','lpInlineDistrict','lpInlineDistrictLbl')" data-ui="u-b0da173d uem uk3 uig udf ufr uda ueg">
            <option value="">— اختر <?php echo esc($region_word); ?> —</option>
            <?php foreach ($ship_rates as $g_name => $g_cost): ?>
              <option value="<?php echo esc($g_name); ?>" data-ship="<?php echo (int)$g_cost; ?>"><?php echo esc($g_name); ?> (شحن <?php echo (int)$g_cost; ?> <?php echo esc($ship_cur); ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label id="lpInlineDistrictLbl" for="lpInlineDistrictSel" data-ui="u-c6c3b1ec uf1 ue6 udi ugv uew"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-map></use></svg> المدينة / المركز *</label>
          <select name="district" id="lpInlineDistrictSel" data-ui-field="1" disabled data-ui="u-b0da173d uem uk3 uig udf ufr uda ueg"><option value="">-- اختر المحافظة أولاً --</option></select>
          <input type="text" name="district" id="lpInlineDistrict" data-ui-field="1" aria-labelledby="lpInlineDistrictLbl" placeholder="<?php echo esc($lp_country === 'مصر' ? 'مثال: مدينة نصر، المعادي، طنطا...' : 'اكتب المدينة والحي بالتفصيل'); ?>" hidden disabled data-ui="u-4aefbbed uem uk3 uig udf ufr uda">
        </div>
      </div>
      <div>
        <label data-ui="u-c6c3b1ec uf1 ue6 udi ugv uew"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-home></use></svg> العنوان بالتفصيل (المنطقة / الشارع / رقم العقار / علامة مميزة) *</label>
        <input type="text" name="address" required placeholder="مثال: شارع عباس العقاد - عمارة 12 - بجوار..." data-ui="u-4aefbbed uem uk3 uig udf ufr uda">
      </div>
      <div>
        <label data-ui="u-c6c3b1ec uf1 ue6 udi ugv uew"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-edit></use></svg> ملاحظات إضافية للمندوب (اختياري)</label>
        <input type="text" name="notes" placeholder="ميعاد الاستلام المفضل أو أي تفاصيل أخرى" data-ui="u-a53328ff uem ug0 uig udf ufr udg">
      </div>

            <div data-ui="u-864c506f udd uk5 ufg uea uh0 udg ue1 ue2 ue7 uet ues">
        <span>إجمالي المنتج (<b id="lpInQtyTxt">1</b> قطعة): <b id="lpInSubTxt"><?php echo wf_ui_money($final_price); ?></b> ج.م + الشحن: <b id="lpInShipTxt">—</b> ج.م</span>
        <span id="lpInDiscWrap" hidden>خصم الكمية (<b id="lpInPerPiece"><?php echo (int)LP_BUNDLE_DISCOUNT; ?></b> ج.م × <b id="lpInExtraTxt">1</b> قطعة إضافية): <b id="lpInDiscTxt"><?php echo (int)LP_BUNDLE_DISCOUNT; ?></b>- ج.م</span>
        <span data-ui="u-4f0be630 uee udu uhn">الإجمالي عند الاستلام: <b id="lpInTotTxt"><?php echo number_format($final_price, 0); ?></b> ج.م</span>
      </div>

      <div id="lpInlineMsg" data-ui="u-83457fd7 uev uea udf ue6 ufd udn"></div>

      <button type="submit" id="lpInlineSubmitBtn" class="lp-cta-btn" data-ui="u-27f04ef2 uk6">
        <span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> تأكيد الطلب الآن</span>
      </button>
    </form>
  </div>

</main>

<!-- الشريط السفلي الثابت للموبايل -->
<div class="lp-sticky-bar">
  <div>
    <div data-ui="u-89578f55 udt udy ue6"><?php echo esc(mb_substr($clean_name, 0, 34)); ?></div>
    <div data-ui="u-c6d36116 uju udu uhn"><?php echo wf_ui_money($final_price); ?> <?php echo esc(wf_ui_currency($p)); ?> </div>
  </div>
  <button type="button" class="lp-cta-btn" data-ui="u-915a89a5 ufk uk8 uee uk6" onclick="triggerLandingModal()">
    <svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> اطلب الآن
  </button>
</div>

<?php render_easyorder_dialog_html($ref, $plat, wf_ui_currency($p), $lp_country); ?>

<div hidden id="wfLandingState" data-state="<?php echo esc(json_encode(['id'=>(string)$p['id'], 'plat'=>$plat, 'ref'=>$ref, 'unit'=>(float)$final_price, 'bundle_discount'=>(int)LP_BUNDLE_DISCOUNT, 'bundle_max'=>99, 'bulk_applies'=>function_exists('wf_bulk_discount_plat_ok')?wf_bulk_discount_plat_ok($plat, wf_ui_currency($p)):true, 'currency'=>wf_ui_currency($p), 'payload'=>json_decode($eo_payload, true)], JSON_UNESCAPED_UNICODE)); ?>"></div>
<?php if (!empty($store_cfg['whatsapp'])): ?>
<a class="wa-float" href="https://wa.me/2<?php echo esc(preg_replace('/\D+/', '', $store_cfg['whatsapp'])); ?>" target="_blank" rel="noopener" aria-label="تواصل عبر واتساب"></a>
<?php endif; ?>
<?php if (function_exists('render_marketer_pixels_html')) render_marketer_pixels_html($store_cfg); ?>

<div hidden data-track-meta="<?php echo esc(json_encode(['ref_code'=>$ref, 'product_id'=>(string)$p['id']], JSON_UNESCAPED_UNICODE)); ?>"></div>
<script src="assets/ui.js?v=20261018-visfeat" defer></script>
<script src="assets/tracker.js?v=20261018-visfeat" defer></script>
</body>
</html>
