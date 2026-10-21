<?php $PUBLIC_PAGE = true; require 'layout.php';
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];

// تطبيع عناصر السلة (سواء تمت إضافتها من الصفحة الرئيسية أو من صفحة المنتج)
$norm_cart = [];
foreach ($_SESSION['cart'] as $ck => $cv) {
    if (is_array($cv) && isset($cv['id'])) {
        $cv['plat'] = resolve_plat($cv['id'], $cv['plat'] ?? '');
        $cv['ref'] = active_ref($cv['ref'] ?? '');
        $cv = array_merge($cv, order_variant_selection($cv));
        $norm_cart[] = $cv;
    } elseif (is_numeric($cv) && strpos((string)$ck, '#') !== false) {
        list($cpl, $cid) = explode('#', (string)$ck, 2);
        $p = find_product_by_id($cid, $cpl);
        if ($p) {
            $pl = $p['_plat'] ?? resolve_plat($cid, $cpl);
            $pr = list_price($p, $pl) ?: display_price($p, $pl);
            $norm_cart[] = [
                'key' => $pl . '#' . $cid . '|',
                'plat' => $pl,
                'id' => $cid,
                'pname' => mb_substr($p['name'] ?? '', 0, 120),
                'price' => (float)$pr,
                'base' => (float)$pr,
                'variant' => '',
                'img' => $p['images'][0] ?? '',
                'ref' => active_ref(),
                'qty' => max(1, min(999, (int)$cv))
            ];
        }
    }
}

// 🔴 إعادة تسعير كل العناصر من السيرفر — أي تعديل في السلة من المتصفح يُرمَّم هنا
foreach ($norm_cart as &$nc) {
    $nc_pid  = (string)($nc['id'] ?? '');
    if ($nc_pid === '') continue;
    $nc_line = server_order_line($nc_pid, (string)($nc['plat'] ?? ''),
                                  max(1, min(999, (int)($nc['qty'] ?? 1))),
                                  isset($nc['price']) ? (float)$nc['price'] : null,
                                  active_ref((string)($nc['ref'] ?? '')));
    if (!$nc_line) { $nc['unavailable'] = true; continue; }
    $nc['plat']     = $nc_line['plat'];
    $nc['pname']    = $nc_line['name'] ?: ($nc['pname'] ?? '');
    $nc['base']     = $nc_line['base_price'];
    $nc['price']    = $nc_line['unit_price'];
    $nc['qty']      = $nc_line['qty'];          // مقيد: [سعر السيرفر .. +30%]
    $nc['img']      = $nc['img'] ?: ($nc_line['product']['images'][0] ?? '');
    $nc['unavailable'] = false;
}
unset($nc);
$_SESSION['cart'] = $norm_cart;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $pid = trim((string)($_POST['id'] ?? ''));
    $pl = resolve_plat($pid, $_POST['plat'] ?? '');
    $selection = order_variant_selection($_POST);
    // The old 60-character key merged different sizes of a long colour/offer.
    $key = $pl . '#' . $pid . '|' . hash('sha256', json_encode($selection, JSON_UNESCAPED_UNICODE));
    $found = false;
    foreach ($_SESSION['cart'] as &$c) {
        if (($c['plat'] ?? '') === $pl && (string)($c['id'] ?? '') === $pid && order_variant_selection($c) === $selection) {
            // Retain existing keys so previously opened quantity/remove URLs still work.
            $key = $c['key'] ?? $key;
            $c['qty'] = min(999, max(1, (int)($c['qty'] ?? 1)) + max(1, min(999, (int)($_POST['qty'] ?? 1))));
            $c['ref'] = active_ref($_POST['ref'] ?? ($c['ref'] ?? ''));
            $found = true;
            break;
        }
    }
    unset($c);
    if (!$found) {
        // 🔴 السعر والاسم والصورة كلها من بيانات المنتج على السيرفر — لا شيء من الفورم
        $nl = server_order_line($pid, $pl, max(1, min(999, (int)($_POST['qty'] ?? 1))), null, active_ref($_POST['ref'] ?? ''));
        if (!$nl) { header('Location: cart.php?unavail=1'); exit; }
        $_SESSION['cart'][] = [
            'key' => $key,
            'plat' => $nl['plat'],
            'id' => $pid,
            'pname' => mb_substr($nl['name'], 0, 120),
            'price' => $nl['unit_price'],
            'base' => $nl['base_price'],
            'variant' => $selection['variant'],
            'color' => $selection['color'],
            'size' => $selection['size'],
            'img' => ($nl['product']['images'][0] ?? ''),
            'ref' => active_ref($_POST['ref'] ?? ''),
            'qty' => $nl['qty'],
            'unavailable' => false
        ];
        $add_price = $nl['unit_price'];
        $add_name  = $nl['name'];
        $add_img   = $nl['product']['images'][0] ?? '';
    } else {
        $add_price = 0; $add_name = ''; $add_img = '';
        foreach ($_SESSION['cart'] as $cc) if (($cc['key'] ?? '') === $key) { $add_price = (float)($cc['price'] ?? 0); $add_name = $cc['pname'] ?? ''; $add_img = $cc['img'] ?? ''; break; }
    }
    if (function_exists('track_cart_add')) {
        track_cart_add($pl, $pid, $add_name, max(1, (int)($_POST['qty'] ?? 1)), $add_price, $_POST['variant'] ?? '', $add_img);
    }
    header('Location: cart.php?added=1');
    exit;
}
if (isset($_GET['step_key'], $_GET['delta'])) {
    $delta = (int)$_GET['delta'];
    foreach ($_SESSION['cart'] as $idx => &$c) {
        if (($c['key'] ?? '') === $_GET['step_key']) {
            $nq = (int)($c['qty'] ?? 1) + $delta;
            if ($nq <= 0) {
                unset($_SESSION['cart'][$idx]);
                $_SESSION['cart'] = array_values($_SESSION['cart']);
            } else {
                $c['qty'] = min(999, $nq);
            }
            break;
        }
    }
    unset($c);
    header('Location: cart.php');
    exit;
}
if (isset($_GET['del'])) {
    $_SESSION['cart'] = array_values(array_filter($_SESSION['cart'], function ($c) {
        return ($c['key'] ?? '') !== $_GET['del'];
    }));
    header('Location: cart.php');
    exit;
}
if (isset($_GET['clear'])) {
    $_SESSION['cart'] = [];
    header('Location: cart.php');
    exit;
}

layout_top('سلة التسوق', 'cart');
$cart = $_SESSION['cart'];
?>
<div class="checkout-stepper" aria-label="خطوات إتمام الطلب">
  <div class="step active"><span class="num">1</span><span>السلة</span></div>
  <span data-ui="u-0ec29c9b d1a">›</span>
  <div class="step"><span class="num">2</span><span>بيانات الشحن</span></div>
  <span data-ui="u-0ec29c9b d1a">›</span>
  <div class="step"><span class="num">3</span><span>تأكيد الطلب</span></div>
</div>
<?php
if (isset($_GET['added'])) echo '<div class="ok" data-ui="u-5fddd367 dh d1p d1s">تمت الإضافة للسلة بنجاح — <a href="../index.php" data-ui="u-fcccab35 dc d1d">متابعة التسوق</a></div>';
if (!$cart) {
    echo '<div class="card" data-ui="u-1478eea5 df d5i"><h3 data-ui="u-01c70be2 d5j dc d2d">سلة التسوق فارغة حالياً</h3><p class="mut" data-ui="u-25724057 d0">تصفح أحدث العروض والمنتجات وأضف ما يعجبك إلى السلة.</p><a class="btn" href="../index.php" data-ui="u-4090fc41 dw">تصفح المنتجات</a></div>';
    layout_end();
    exit;
}
$sum = 0; $cdisc = 0.0;
$currencies = array_fill_keys(wf_ui_currencies($cart), true);
?>
<div class="cart-grid">
<div><div class="card" data-ui="u-a2bc2b26 d5k">
<div data-ui="u-e883eee1 dh d1o d1p d1r"><h2 data-ui="u-ecf1db9a d5l dc d19">منتجاتك في السلة (<?php echo count($cart); ?>)</h2><a href="?clear=1" data-confirm="هل أنت متأكد من تفريغ سلة التسوق بالكامل؟" class="btn sm outline" data-ui="u-4a65d709 d4y d5m">تفريغ السلة</a></div>
<div data-ui="u-62466bd8 dh d5n d3z">
<?php foreach ($cart as $c): $t = $c['price'] * $c['qty']; $sum += $t;
    // خصم الكمية لكل سطر: 70 ج.م عن كل قطعة بعد الأولى (المنتجات الرقمية والسوشيال مستثناة،
    // وكمان أي سطر عملته مش الجنيه — نفس العملة المعروضة في السطر هي اللي بتحكم)
    $c_cur = wf_ui_currency($c);
    $c_disc = function_exists('wf_bulk_discount') ? (float)wf_bulk_discount((int)($c['qty'] ?? 1), function_exists('resolve_plat') ? resolve_plat((string)($c['id'] ?? ''), $c['plat'] ?? '') : ($c['plat'] ?? ''), $c_cur) : 0.0;
    $cdisc += $c_disc; $t_net = round(max(0, $t - $c_disc), 2); ?>
<article class="cart-line">
<?php if ($c['img']): ?><img src="<?php echo esc(asset_url($c['img'])); ?>" alt="<?php echo esc($c['pname']); ?>" width="72" height="72" data-ui="u-af50c98c d5o d5p d5q d4u dt d24"><?php endif; ?>
<div data-ui="u-efaf3a8d d2i d5r">
  <a href="product.php?id=<?php echo urlencode($c['id']); ?>&plat=<?php echo urlencode($c['plat'] ?? ''); ?><?php echo !empty($c['ref']) ? '&ref=' . urlencode($c['ref']) : ''; ?>" data-ui="u-3f77f76d dc d5s d2k"><?php echo esc(mb_substr($c['pname'], 0, 60)); ?></a>
  <?php if (order_variant_label($c) !== ''): ?><div class="mut" data-ui="u-46c5a79e d5t d4x">المواصفات: <?php echo esc(order_variant_label($c)); ?></div><?php endif; ?>
  <div data-ui="u-c8d75059 d49 d5s dh d1p d1q dj">
    <span data-ui="u-03327588 dv d5u"><?php echo number_format($c['price'], 2); ?> <?php echo esc(wf_ui_currency($c)); ?></span>
    <div data-ui="u-feddc0c8 d3e d1p dt d1u d4u d2n">
      <a <?php echo (int)$c['qty'] <= 1 ? 'data-cart-remove' : ''; ?> href="?step_key=<?php echo urlencode($c['key']); ?>&delta=-1" aria-label="تقليل الكمية" data-ui="u-1cf807cb d5v d5w d3e d1p dw dc dv">−</a>
      <span data-ui="u-5c34898b d5x dc d5s"><?php echo (int)$c['qty']; ?></span>
      <a href="?step_key=<?php echo urlencode($c['key']); ?>&delta=1" aria-label="زيادة الكمية" data-ui="u-1cf807cb d5v d5w d3e d1p dw dc dv">+</a>
    </div>
    <b data-ui="u-8bf4bd79 d2k">= <?php echo number_format($t_net, 2); ?> <?php echo esc(wf_ui_currency($c)); ?><?php if ($c_disc > 0): ?> <span class="mut" data-ui="u-46c5a79e d5t d4x" style="color:var(--success);font-weight:700">(خصم الكمية <?php echo number_format($c_disc, 2); ?>)</span><?php endif; ?></b>
  </div>
</div>
<a data-cart-remove href="?del=<?php echo urlencode($c['key']); ?>" aria-label="حذف المنتج من السلة" data-ui="u-05ec11e3 d4y d26 dc d2l d5v d5w d3e d1p dw d1u d5y"></a>
</article><?php endforeach; ?>
</div></div></div>
<div><div class="card" data-ui="u-72dbea98 d5k d5z d60">
<h3 data-ui="u-e205cc36 d5l dc d1r">ملخص الطلب</h3>
<div data-ui="u-5a42ece0 dh d5n d1q">
<div data-ui="u-530c6095 dh d1o d5s"><span class="mut">المجموع الفرعي</span><b><?php echo count($currencies) > 1 ? 'عملات متعددة' : number_format($sum, 2) . ' ' . esc(array_key_first($currencies) ?: 'ج.م'); ?></b></div>
<div data-ui="u-530c6095 dh d1o d5s"><span class="mut">مصاريف الشحن</span><span class="mut">تُحدد في الخطوة التالية</span></div>
<?php if ($cdisc > 0): ?>
<div data-ui="u-530c6095 dh d1o d5s"><span class="mut">خصم الكمية (<?php echo (int)LP_BUNDLE_DISCOUNT; ?> ج.م لكل قطعة إضافية)</span><b style="color:var(--success)">-<?php echo number_format($cdisc, 2); ?> <?php echo esc(array_key_first($currencies) ?: 'ج.م'); ?></b></div>
<?php endif; ?>
<div data-ui="u-deea4ad5 d29 d61 dh d1o d5l"><b>الإجمالي المبدئي</b><b data-ui="u-8bf4bd79 d2k"><?php echo count($currencies) > 1 ? 'عملات متعددة' : number_format(max(0, $sum - $cdisc), 2) . ' ' . esc(array_key_first($currencies) ?: 'ج.م'); ?></b></div></div>
<a class="btn" href="checkout_cart.php" data-ui="u-40b1c549 dx dw dl dy d4e d3v">متابعة لبيانات الشحن</a>
<a href="../index.php" class="btn outline" data-ui="u-ce6969ff dx dw d62">متابعة التسوق</a></div></div></div>
<?php layout_end(); ?>
