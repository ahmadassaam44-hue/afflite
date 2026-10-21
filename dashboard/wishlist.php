<?php $PUBLIC_PAGE = true; require 'layout.php';
// صفحة المفضلة — نفس مفتاح التخزين بتاع القلوب ❤ في كل الصفحات:
// المسجّل = الإيميل، والزائر = guest_visitor_id (فلاشة المتصفح). كل الأسعار
// والصور بتتحل من بيانات السيرفر — مفيش حاجة بتيجي من المتصفح.
$wish_key = !empty($user['email']) ? (string)$user['email'] : ('guest_' . (function_exists('visitor_id') ? visitor_id() : session_id()));

// إزالة عنصر برابط عادي ?rm=plat%23id — شغال مع JS أو من غيره
if (isset($_GET['rm'])) {
    $rm = (string)$_GET['rm'];
    jupdate('wishlist.json', function (&$all) use ($wish_key, $rm) {
        if (!is_array($all) || !isset($all[$wish_key]) || !is_array($all[$wish_key])) return false;
        $all[$wish_key] = array_values(array_diff($all[$wish_key], [$rm]));
        if (empty($all[$wish_key])) unset($all[$wish_key]);
        return true;
    }, []);
    header('Location: wishlist.php');
    exit;
}

$w_all  = jload('wishlist.json', []);
$mine   = (isset($w_all[$wish_key]) && is_array($w_all[$wish_key])) ? array_values($w_all[$wish_key]) : [];

// حل المنتجات من الكتالوج — اللي اتشال من الكتالوج بيتجاهل بهدوء
$w_items = [];
foreach ($mine as $wk) {
    if (strpos((string)$wk, '#') === false) continue;
    [$wpl_k, $wid_k] = explode('#', (string)$wk, 2);
    $wp = find_product_by_id($wid_k, $wpl_k);
    if (!$wp) continue;
    $wpl = $wp['_plat'] ?? resolve_plat($wid_k, $wpl_k);
    $w_items[] = ['key' => $wk, 'plat' => $wpl, 'id' => (string)$wid_k, 'p' => $wp];
}

layout_top('المفضلة', 'wishlist');
?>
<div class="checkout-stepper" aria-label="مكاني في المتجر">
  <div class="step"><span class="num">1</span><span>المنتجات</span></div>
  <span data-ui="u-0ec29c9b d1a">›</span>
  <div class="step active"><span class="num">2</span><span>المفضلة</span></div>
  <span data-ui="u-0ec29c9b d1a">›</span>
  <div class="step"><span class="num">3</span><span>الطلب</span></div>
</div>
<?php
if (!$w_items) {
    echo '<div class="card" style="text-align:center;padding:34px 18px">'
        . '<div style="font-size:44px;line-height:1">🤍</div>'
        . '<h3 style="margin:10px 0 6px">قائمة المفضلة فاضية</h3>'
        . '<p class="mut" style="margin:0 0 14px">اضغط علامة القلب ❤ على أي منتج وهيتحفظ هنا — تقدر تطلبه في أي وقت.</p>'
        . '<a class="btn pri" href="../index.php">تصفح المنتجات</a></div>';
    layout_end();
    exit;
}
$w_cur_all = [];
?>
<div class="cart-grid">
<div><div class="card">
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px">
  <h2 style="margin:0;font-size:17px">منتجاتي المفضلة (<?php echo count($w_items); ?>)</h2>
  <a href="../index.php" class="btn outline" style="padding:7px 14px;font-size:13px">متابعة التسوق</a>
</div>
<div style="display:grid;gap:12px">
<?php foreach ($w_items as $wi):
    $wp = $wi['p']; $wpl = $wi['plat'];
    $wimg   = (string)($wp['images'][0] ?? '');
    $wname  = aff_clean($wp['name'] ?? '', true) ?: 'منتج';
    $wprice = (float)display_price($wp, $wpl);
    $wbase  = (float)store_price($wp, $wpl);
    if ($wbase <= 0) $wbase = $wprice;
    $wcur   = function_exists('wf_ui_currency') ? wf_ui_currency($wp) : 'ج.م';
    $w_cur_all[$wcur] = true;
    $wlink  = 'product.php?id=' . urlencode($wi['id']) . '&plat=' . urlencode($wpl) . (!empty($user['ref_code']) ? '&ref=' . urlencode($user['ref_code']) : '');
    $wcolors = function_exists('clean_product_colors') ? array_slice(clean_product_colors($wp), 0, 15) : [];
    $wsizes  = function_exists('clean_product_sizes') ? array_slice(clean_product_sizes($wp), 0, 20) : [];
    $eo_w = ['id' => $wi['id'], 'plat' => $wpl, 'name' => $wname, 'price' => $wprice, 'base_price' => $wbase,
             'extra_comm' => 0, 'img' => $wimg ? asset_url($wimg) : '', 'ref' => (string)($user['ref_code'] ?? ''),
             'currency' => $wcur,
             'country' => function_exists('wf_product_country') ? wf_product_country($wp) : 'مصر',
             'ship_fee' => function_exists('wf_product_shipping') ? wf_product_shipping($wp) : null,
             'colors' => $wcolors, 'sizes' => $wsizes];
?>
<article class="cart-line">
<?php if ($wimg): ?><img src="<?php echo esc(asset_url($wimg)); ?>" alt="<?php echo esc($wname); ?>" width="72" height="72" loading="lazy"><?php endif; ?>
<div style="flex:1;min-width:0">
  <a href="<?php echo esc($wlink); ?>" style="font-weight:700;color:var(--text);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?php echo esc($wname); ?></a>
  <div style="margin:6px 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <b style="font-size:16px;color:var(--brand)"><?php echo number_format($wprice, 2); ?> <?php echo esc($wcur); ?></b>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?php if (!in_array($wpl, ['g2g', 'social'], true)): ?>
    <button type="button" class="btn pri" style="padding:8px 16px;font-size:13px" data-easy-order="<?php echo esc(json_encode($eo_w, JSON_UNESCAPED_UNICODE)); ?>">اطلب الآن</button>
    <form method="post" action="cart.php" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
      <input type="hidden" name="add" value="1">
      <input type="hidden" name="plat" value="<?php echo esc($wpl); ?>">
      <input type="hidden" name="id" value="<?php echo esc($wi['id']); ?>">
      <input type="hidden" name="pname" value="<?php echo esc($wname); ?>">
      <input type="hidden" name="price" value="<?php echo esc((string)$wprice); ?>">
      <input type="hidden" name="base" value="<?php echo esc((string)$wbase); ?>">
      <input type="hidden" name="img" value="<?php echo esc($wimg ? asset_url($wimg) : ''); ?>">
      <input type="hidden" name="ref" value="<?php echo esc((string)($user['ref_code'] ?? '')); ?>">
      <input type="hidden" name="qty" value="1">
      <button type="submit" class="btn outline" style="padding:8px 16px;font-size:13px">أضف للسلة 🛒</button>
    </form>
    <?php else: ?>
    <a class="btn pri" style="padding:8px 16px;font-size:13px" href="<?php echo esc($wlink); ?>">اطلب الآن</a>
    <?php endif; ?>
    <a class="btn outline" style="padding:8px 16px;font-size:13px" href="<?php echo esc($wlink); ?>">عرض المنتج</a>
    <a href="wishlist.php?rm=<?php echo urlencode($wi['key']); ?>" style="padding:8px 12px;font-size:13px;color:var(--danger);border:1px solid var(--danger);border-radius:10px;font-weight:700;text-decoration:none">إزالة 💔</a>
  </div>
</div>
</article>
<?php endforeach; ?>
</div>
</div></div>
</div>
<?php layout_end(); ?>
