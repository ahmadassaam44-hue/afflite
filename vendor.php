<?php
// 🏬 واجهة متجر التاجر — نفس تصميم الموقع (layout + pcard) ببراندنج المتجر
$PUBLIC_PAGE = true;
$NO_SIDEBAR = true;
$GLOBALS['WF_PUBLIC_CHROME'] = true;
$GLOBALS['WF_NO_EASYORDER'] = true;   // فورم الطلب الخاص بالمتجر بدل النافذة العامة
require __DIR__ . '/dashboard/layout.php';

$slug = strtolower(trim((string)($_GET['store'] ?? '')));
$store = $slug !== '' ? wfv_store($slug) : null;

if (!$store || (int)($store['enabled'] ?? 1) !== 1) {
    http_response_code(404);
    layout_top('المتجر غير موجود');
    echo '<div style="padding:48px 16px;text-align:center;max-width:560px;margin:0 auto">'
        . '<div style="font-size:52px">🏬</div>'
        . '<h1 style="margin:10px 0">المتجر غير موجود أو متوقف</h1>'
        . '<p class="mut">تأكد من رابط المتجر، أو ارجع للمتجر الأساسي.</p>'
        . '<a class="wfv-btn" href="index.php" style="margin-top:14px">العودة للمتجر الرئيسي</a></div>';
    layout_end();
    exit;
}

$products = wfv_products_live($slug);
$cats = [];
foreach ($products as $p) {
    $c = trim((string)($p['category'] ?? ''));
    if ($c !== '') $cats[$c] = ($cats[$c] ?? 0) + 1;
}
arsort($cats);
$cat_filter = trim((string)($_GET['cat'] ?? ''));
if ($cat_filter !== '' && $cat_filter !== 'all') {
    $products = array_values(array_filter($products, function ($p) use ($cat_filter) {
        return trim((string)($p['category'] ?? '')) === $cat_filter;
    }));
}
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $products = array_values(array_filter($products, function ($p) use ($q) {
        return mb_stripos((string)$p['name'], $q) !== false || mb_stripos((string)($p['desc'] ?? ''), $q) !== false;
    }));
}

layout_top(mb_substr((string)$store['name'], 0, 50), 'products');
echo wfv_theme_style($store);
?>
<div style="max-width:1180px;margin:0 auto;padding:18px 14px 40px">
<?php if (trim((string)$store['announce']) !== ''): ?>
    <div style="background:var(--wf-store-accent);color:#fff;border-radius:14px;padding:12px 16px;font-weight:800;text-align:center;margin-bottom:16px">
        <?php echo esc((string)$store['announce']); ?>
    </div>
<?php endif; ?>

    <div class="wfv-hero" style="border-radius:20px;padding:22px;display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <?php if (!empty($store['logo'])): ?>
            <img class="wfv-logo" src="<?php echo esc((string)$store['logo']); ?>" alt="<?php echo esc((string)$store['name']); ?>">
        <?php else: ?>
            <div class="wfv-logo" style="display:grid;place-items:center;font-size:26px">🏬</div>
        <?php endif; ?>
        <div style="flex:1;min-width:220px">
            <h1 style="margin:0 0 4px;font-size:24px"><?php echo esc((string)$store['name']); ?></h1>
            <?php if (trim((string)$store['tagline']) !== ''): ?>
                <p class="mut" style="margin:0"><?php echo esc((string)$store['tagline']); ?></p>
            <?php endif; ?>
            <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;font-size:13px" class="mut">
                <span><?php echo count($products); ?> منتج</span>
                <span>•</span>
                <span>الدفع عند الاستلام</span>
                <?php if (trim((string)$store['phone']) !== ''): ?>
                    <span>•</span><span dir="ltr"><?php echo esc((string)$store['phone']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (trim((string)$store['whatsapp']) !== ''): ?>
            <a class="wfv-btn" target="_blank" rel="noopener"
               href="https://wa.me/<?php echo esc(preg_replace('/\D+/', '', (string)$store['whatsapp'])); ?>">واتساب المتجر</a>
        <?php endif; ?>
    </div>

    <form method="get" action="vendor.php" style="margin:18px 0 8px;display:flex;gap:10px;flex-wrap:wrap">
        <input type="hidden" name="store" value="<?php echo esc($slug); ?>">
        <input class="wfv-field" style="max-width:320px" type="search" name="q" value="<?php echo esc($q); ?>" placeholder="ابحث في منتجات المتجر…">
        <button class="wfv-btn" type="submit">بحث</button>
    </form>

    <?php if ($cats): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 20px">
            <a class="wfv-chip<?php echo $cat_filter === '' || $cat_filter === 'all' ? ' on' : ''; ?>"
               href="vendor.php?store=<?php echo urlencode($slug); ?>">كل الأقسام</a>
            <?php foreach ($cats as $c => $n): ?>
                <a class="wfv-chip<?php echo $cat_filter === $c ? ' on' : ''; ?>"
                   href="vendor.php?store=<?php echo urlencode($slug); ?>&cat=<?php echo urlencode($c); ?>"><?php echo esc($c); ?> (<?php echo (int)$n; ?>)</a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$products): ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:38px;text-align:center">
            <div style="font-size:44px">🛍️</div>
            <h3 style="margin:8px 0">لسه مفيش منتجات في المتجر</h3>
            <p class="mut">التاجر هيضيف منتجاته قريب — ارجع بعد شوية.</p>
            <a class="wfv-btn" href="index.php" style="margin-top:10px">شوف متجر وفرنهالك</a>
        </div>
    <?php else: ?>
        <div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">
            <?php foreach ($products as $p) echo wfv_card($store, $p); ?>
        </div>
    <?php endif; ?>

    <?php if (trim((string)$store['about']) !== ''): ?>
        <div style="margin-top:26px;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:20px">
            <h3 style="margin:0 0 8px">عن المتجر</h3>
            <p class="mut" style="margin:0;line-height:1.9"><?php echo nl2br(esc((string)$store['about'])); ?></p>
        </div>
    <?php endif; ?>
</div>
<?php layout_end(); ?>
