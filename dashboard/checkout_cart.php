<?php $PUBLIC_PAGE = true; require 'layout.php';
if (empty($_SESSION['cart'])) { header('Location: cart.php'); exit; }
// دولة الشحن من السيرفر (مش من المتصفح): أول منتج في السلة هو اللي بيحدد الجدول والعملة.
// المنتجات اليدوية: مصر = جدول تايم هاند بالجنيه، والخليج = جدول مناطق الدولة بعملة الدولة.
$__first_cart = is_array($_SESSION['cart'][0] ?? null) ? $_SESSION['cart'][0] : [];
$ship_plat = function_exists('resolve_plat') ? resolve_plat((string)($__first_cart['id'] ?? ''), $__first_cart['plat'] ?? '') : (string)($__first_cart['plat'] ?? '');
$ship_country = 'مصر';
$__fp = null;
if (!empty($__first_cart['id'])) {
    $__fp = function_exists('find_product_by_id') ? find_product_by_id((string)$__first_cart['id'], (string)$ship_plat) : null;
    if ($__fp) $ship_country = wf_product_country($__fp);
}
// سعر شحن ثابت حدّده المالك للمنتج نفسه (منتجات يدوية) — بيكسب جدول مناطق الدولة
$ship_fixed = ($__fp && function_exists('wf_product_shipping')) ? wf_product_shipping($__fp) : null;
$ship_currency = wf_ship_currency($ship_plat, $ship_country);
$region_word = wf_ship_region_label($ship_country);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['name'])) {
    $shiprows = site_ship_regions($ship_plat !== '' ? $ship_plat : ($_POST['plat0'] ?? ''), $ship_country); $fee = 0;
    foreach ($shiprows as $sr) {
        if (($sr['region'] ?? '') === ($_POST['city'] ?? '')) { $fee = max(0, min(2000, (float)$sr['fee'])); break; }
    }
    if ($ship_fixed !== null) $fee = $ship_fixed;   // سعر شحن المنتج نفسه بيكسب جدول المناطق
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $phone = mb_substr($phone, 0, 20);
    $batch = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 5));
    $n = 0;
    $placed = [];

    foreach ($_SESSION['cart'] as $c) {
        $pid  = (string)($c['id'] ?? '');
        if ($pid === '') continue;
        $pl   = resolve_plat($pid, $c['plat'] ?? '');
        $qty  = max(1, min(999, (int)($c['qty'] ?? 1)));
        $ref  = active_ref($c['ref'] ?? '');

        // 🔴 السعر من السيرفر فقط — سعر السلة (اللي جاي من المتصفح) يُتجاهل تماماً
        $req  = (isset($c['price']) && (float)$c['price'] > 0) ? (float)$c['price'] : null;
        $line = server_order_line($pid, $pl, $qty, $req, $ref);
        if (!$line) continue;                       // منتج غير متاح → يُتخطى

        $prod = $line['product'];
        $pl   = $line['plat'];
        $unit = $line['unit_price'];
        $base = $line['base_price'];
        $ship = ($n === 0 ? $fee : 0);
        // خصم الكمية لكل سطر: 70 ج.م عن كل قطعة بعد الأولى (الرقمي والسوشيال مستثنيان،
        // وكمان أي منتج سعره مش بالجنيه — العملة من المنتج نفسه اللي جابه السيرفر)
        $line_disc = function_exists('wf_bulk_discount') ? (float)wf_bulk_discount($qty, $pl, $prod) : 0.0;

        $district = mb_substr(trim((string)($_POST['district'] ?? '')), 0, 80);
        $raw_addr = trim((string)($_POST['address'] ?? ''));
        if ($district !== '' && mb_stripos($raw_addr, $district) === false) {
            $raw_addr = $district . ($raw_addr !== '' ? ' - ' . $raw_addr : '');
        }
        $selection = order_variant_selection($c);
        $new_order = [
            'order_no'   => $batch . ($n > 0 ? '-' . ($n + 1) : ''),
            'batch'      => $batch,
            'user'       => current_user() ? current_user()['email'] : ('guest:' . mb_substr($phone, 0, 30)),
            'plat'       => $pl,
            'id'         => $pid,
            'pname'      => mb_substr($line['name'] ?: (string)($c['pname'] ?? $pid), 0, 120),
            'ref'        => $ref,
            'm_earn'     => 0,
            'comm_state' => 'pending',
            'sub'        => active_sub(),
            'base_price' => $base,
            'markup'     => $line['markup'],
            'variant'    => $selection['variant'],
            'color'      => $selection['color'],
            'size'       => $selection['size'],
            'qty'        => $qty,
            'unit'       => $unit,
            'ship'       => $ship,
            'discount'   => $line_disc,
            'total'      => round(max(0, $unit * $qty + $ship - $line_disc), 2),
            'cname'      => mb_substr((string)$_POST['name'], 0, 100),
            'cphone'     => $phone,
            'city'       => mb_substr((string)($_POST['city'] ?? ''), 0, 60),
            'district'   => $district,
            'address'    => mb_substr($raw_addr, 0, 300),
            'notes'      => mb_substr((string)($_POST['notes'] ?? ''), 0, 500),
            'at'         => date('c'),
            'src'        => function_exists('wf_traffic_src') ? wf_traffic_src() : '',
            'status'     => 'pending',
        ];

        jupdate('orders.json', function (&$orders) use ($new_order) {
            if (!is_array($orders)) $orders = [];
            $orders[] = $new_order;
            $GLOBALS['_WF_NEW_ORDER_IDX'] = count($orders) - 1;
            return true;
        }, []);
        $oi = $GLOBALS['_WF_NEW_ORDER_IDX'] ?? null;

        $GLOBALS['_WF_ORDER_IDX'] = $oi;
        $aff    = credit_affiliate_order($ref, $prod, $pl, $qty, $unit);
        $m_earn = (float)($aff['m_earn'] ?? 0);

        jupdate('orders.json', function (&$orders) use ($oi, $m_earn, $aff) {
            if ($oi !== null && isset($orders[$oi])) {
                $orders[$oi]['m_earn']     = $m_earn;
                $orders[$oi]['ref']        = $aff['ref'] ?? '';
                $orders[$oi]['comm_state'] = $aff['comm_status'] ?? ($m_earn > 0 ? 'pending' : 'none');
            }
            return true;
        }, []);

        if ($ref && ($mk2 = find_marketer_by_code($ref))) {
            notify($mk2['email'], '💰 عملية بيع برابطك: ' . mb_substr($new_order['pname'], 0, 40)
                   . ' — عمولة معلقة +' . number_format($m_earn, 2) . ' ج.م', 'dashboard/marketer.php');
        }
        if (function_exists('wf_event')) {
            wf_event('order', ['product_id' => $pid, 'plat' => $pl, 'order_no' => $new_order['order_no'],
                               'value' => $new_order['total'], 'qty' => $qty]);
        }
        $placed[] = $new_order;
        $n++;
    }

    $_SESSION['cart'] = [];
    if ($n > 0) notify('admin', '🧾 طلب جديد ' . $batch . ' (' . $n . ' منتجات)', 'dashboard/admin.php?tab=orders');
    layout_top('تم تأكيد الطلب', 'orders');
    echo '<div class="checkout-stepper" aria-label="خطوات إتمام الطلب">'
       . '<div class="step done"><span class="num"></span><span>السلة</span></div><span data-ui="u-0ec29c9b d1a">›</span>'
       . '<div class="step done"><span class="num"></span><span>بيانات الشحن</span></div><span data-ui="u-0ec29c9b d1a">›</span>'
       . '<div class="step active"><span class="num">3</span><span>تأكيد الطلب</span></div></div>';
    echo '<div class="card" data-ui="u-1478eea5 df d5i">'
       . '<div data-ui="u-7423804c d57 d58 d36 d15 d17 d3e d1p dw d6k dc d1r"></div>'
       . '<h2 data-ui="u-19b24cb7 d6l dc d2">تم استلام طلبك بنجاح!</h2>'
       . '<p class="mut" data-ui="u-528af71d d5s d11">الدفع عند الاستلام — جارٍ تجهيز طلبك للشحن وسنتواصل معك لتأكيد الموعد.</p>'
       . '<p data-ui="u-28336fe2 d5s dc d6m">رقم الطلب: <span dir="ltr" data-ui="u-83376f41 d6n d6o d1u">' . esc($batch) . '</span></p>'
       . '<div data-ui="u-02837c8e dh d1q dw dj"><a class="btn" href="../index.php">مواصلة التسوق</a><a class="btn outline" href="orders.php">متابعة طلباتي</a></div></div>';
    layout_end();
    exit;
}
$sum = array_sum(array_map(function ($c) { return $c['price'] * $c['qty']; }, $_SESSION['cart']));
// معاينة خصم الكمية في ملخص الطلب (القيمة النهائية بتتحسب على السيرفر سطر بسطر)
$cart_disc = 0.0;
foreach ($_SESSION['cart'] as $cd) {
    if (!is_array($cd)) continue;
    $cd_qty = max(1, min(999, (int)($cd['qty'] ?? 1)));
    $cart_disc += function_exists('wf_bulk_discount') ? (float)wf_bulk_discount($cd_qty, resolve_plat((string)($cd['id'] ?? ''), $cd['plat'] ?? ''), wf_ui_currency($cd)) : 0.0;
}
$cart_disc = round($cart_disc, 2);
$shiprows = site_ship_regions($ship_plat !== '' ? $ship_plat : ($_SESSION['cart'][0]['plat'] ?? 'vendoor'), $ship_country);
// لو المنتج له سعر شحن ثابت، كل الخيارات بتعرضه (والإجمالي بيتحسب بيه على السيرفر عند التأكيد)
if ($ship_fixed !== null) { foreach ($shiprows as &$__sr) { $__sr['fee'] = $ship_fixed; } unset($__sr); }
$first_fee = isset($shiprows[0]['fee']) ? (float)$shiprows[0]['fee'] : 0;
layout_top('إتمام الطلب', 'orders'); ?>
<div class="checkout-stepper" aria-label="خطوات إتمام الطلب">
  <div class="step done"><span class="num">1</span><span>السلة</span></div>
  <div class="step active" aria-current="step"><span class="num">2</span><span>بيانات الشحن</span></div>
  <div class="step"><span class="num">3</span><span>التأكيد</span></div>
</div>
<?php echo wf_ui_currency_notice($_SESSION['cart']); echo wf_ui_shipping_currency_notice($_SESSION['cart']); ?>
<div class="checkout-grid">
<section class="card">
<h2>بيانات التوصيل</h2>
<form method="post" id="cartCheckoutForm" data-checkout-subtotal="<?php echo esc($sum); ?>" data-checkout-discount="<?php echo esc($cart_disc); ?>" data-checkout-currency="<?php echo esc(wf_ui_currencies($_SESSION['cart'])[0] ?? 'ج.م'); ?>" data-checkout-mixed="<?php echo count(wf_ui_currencies($_SESSION['cart'])) > 1 || (wf_ui_currencies($_SESSION['cart'])[0] ?? 'ج.م') !== 'ج.م' ? '1' : '0'; ?>">
<?php echo csrf_field(); ?>
<div class="form-grid">
  <div><label for="checkoutName" class="field-label">اسم العميل</label><input id="checkoutName" name="name" autocomplete="name" required placeholder="الاسم بالكامل"></div>
  <div><label for="checkoutPhone" class="field-label">رقم الموبايل</label><input id="checkoutPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="01xxxxxxxxx" dir="ltr"></div>
  <div><label for="checkoutCity" class="field-label"><?php echo esc($region_word); ?> <?php if ($ship_country !== 'مصر'): ?><span class="mut">(<?php echo esc($ship_country); ?>)</span><?php endif; ?></label><select id="checkoutCity" name="city" required data-ship-currency="<?php echo esc($ship_currency); ?>">
  <?php foreach ($shiprows as $sr): ?><option value="<?php echo esc($sr['region']); ?>" data-ship="<?php echo esc($sr['fee'] ?? 0); ?>"><?php echo esc($sr['region']); ?> (+<?php echo esc($sr['fee'] ?? 0); ?> <?php echo esc($ship_currency); ?>)</option><?php endforeach; ?></select></div>
  <div><label for="checkoutDistrict" class="field-label">المدينة / المركز</label><input id="checkoutDistrict" name="district" required autocomplete="address-level2" placeholder="المدينة أو الحي"></div>
</div>
<input name="plat0" type="hidden" value="<?php echo esc($_SESSION['cart'][0]['plat'] ?? 'vendoor'); ?>">
<label for="checkoutAddress" class="field-label">العنوان بالتفصيل</label><input id="checkoutAddress" name="address" autocomplete="street-address" required placeholder="الشارع، رقم العقار، علامة مميزة">
<label for="checkoutNotes" class="field-label">ملاحظات (اختياري)</label><input id="checkoutNotes" name="notes" placeholder="تعليمات تساعد المندوب">
<div class="alert info"><?php echo wf_svg_icon('shield'); ?><p>الدفع عند الاستلام. راجع بياناتك قبل التأكيد.</p></div>
<button class="btn pri btn-block" type="submit">تأكيد الطلب</button>
</form>
</section>
<aside class="card order-summary" aria-label="ملخص الطلب">
<h2>ملخص الطلب</h2>
<?php foreach ($_SESSION['cart'] as $c): ?>
<div class="checkout-line">
<?php if($c['img']): ?><img src="<?php echo esc(asset_url($c['img'])); ?>" alt="<?php echo esc($c['pname']); ?>" width="52" height="52" loading="lazy"><?php endif; ?>
<div><b><?php echo esc(mb_substr($c['pname'],0,50)); ?></b><?php if (order_variant_label($c) !== ''): ?><p class="mut"><?php echo esc(order_variant_label($c)); ?></p><?php endif; ?><p class="mut">الكمية: <?php echo (int)$c['qty']; ?></p></div>
<b><?php echo number_format($c['price']*$c['qty'], 2); ?> <?php echo esc(wf_ui_currency($c)); ?></b>
<?php endforeach; ?>

<div class="summary-row"><span>المجموع الفرعي</span><b><?php echo count(wf_ui_currencies($_SESSION['cart'])) > 1 ? 'عملات متعددة — راجع السطور' : number_format($sum,2) . ' ' . esc(wf_ui_currencies($_SESSION['cart'])[0] ?? 'ج.م'); ?></b></div>
<?php if ($cart_disc > 0): ?>
<div class="summary-row"><span>خصم الكمية (70 ج.م لكل قطعة إضافية)</span><b id="chkBulkDisc" style="color:var(--success)">-<?php echo number_format($cart_disc, 2); ?> ج.م</b></div>
<?php endif; ?>
<div class="summary-row"><span>رسوم الشحن المسجلة<?php if ($ship_country !== 'مصر'): ?> (<?php echo esc($ship_country); ?>)<?php endif; ?></span><b id="chkShipFee"><?php echo number_format($first_fee,2); ?> <?php echo esc($ship_currency); ?></b></div>
<div class="summary-row summary-total"><span>الإجمالي</span><b id="chkGrandTotal"><?php $__pc = wf_ui_currencies($_SESSION['cart'])[0] ?? 'ج.م'; echo count(wf_ui_currencies($_SESSION['cart'])) > 1 ? 'عملات متعددة — راجع السطور' : (($__pc !== $ship_currency) ? 'منتجات بـ' . esc($__pc) . ' + شحن بـ' . esc($ship_currency) : number_format(max(0, $sum + $first_fee - $cart_disc),2) . ' ' . esc($__pc)); ?></b></div>
<p class="field-hint">رسوم الشحن تُراجع على الخادم عند إرسال الطلب. لا تقوم الواجهة بتحويل العملات.</p>
</aside>
</div>
<?php layout_end(); ?>
