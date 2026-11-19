<?php
// 🏬 صفحة منتج التاجر + نموذج الطلب (نفس تصميم الموقع)
$PUBLIC_PAGE = true;
$NO_SIDEBAR = true;
$GLOBALS['WF_PUBLIC_CHROME'] = true;
$GLOBALS['WF_NO_EASYORDER'] = true;   // فورم الطلب الخاص بالمتجر بدل النافذة العامة
require __DIR__ . '/dashboard/layout.php';

$slug = strtolower(trim((string)($_GET['store'] ?? $_POST['store'] ?? '')));
$store = $slug !== '' ? wfv_store($slug) : null;
$pid = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));

if (!$store || (int)($store['enabled'] ?? 1) !== 1) {
    http_response_code(404);
    layout_top('المتجر غير موجود');
    echo '<div style="padding:48px 16px;text-align:center"><h1>المتجر غير موجود</h1>'
        . '<a class="btn" href="index.php">العودة للرئيسية</a></div>';
    layout_end();
    exit;
}
$product = $pid !== '' ? wfv_product($slug, $pid) : null;
if (!$product || empty($product['active'])) {
    http_response_code(404);
    layout_top('غير موجود');
    echo '<div style="padding:48px 16px;text-align:center"><div style="font-size:52px">🔍</div>'
        . '<h1>المنتج غير موجود</h1><p class="mut">يمكن التاجر شاله أو بيحدّث فيه.</p>'
        . '<a class="wfv-btn" href="vendor.php?store=' . urlencode($slug) . '">رجوع للمتجر</a></div>';
    layout_end();
    exit;
}

$msg = ''; $err = ''; $done_no = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['place_order'])) {
    if (!csrf_ok()) {
        $err = 'انتهت صلاحية الصفحة — حدّثها وحاول تاني';
    } else {
        $done_no = wfv_add_order($slug, $_POST, $err);
        if ($done_no !== '') $msg = 'تم تسجيل طلبك بنجاح — رقم الطلب: ' . $done_no;
    }
}

$sold_out = (int)($product['stock'] ?? 1) === 0;
$imgs = isset($product['images']) && is_array($product['images']) ? $product['images'] : [];
$govs = function_exists('site_ship_map') ? site_ship_map() : [];
$ship = isset($product['ship_fee']) && $product['ship_fee'] !== null ? (float)$product['ship_fee'] : (float)$store['ship_fee'];

layout_top(mb_substr((string)$product['name'], 0, 50), 'products');
echo wfv_theme_style($store);
?>
<div style="max-width:1100px;margin:0 auto;padding:18px 14px 40px">
    <div class="mut" style="margin-bottom:12px;font-size:13px">
        <a href="vendor.php?store=<?php echo urlencode($slug); ?>" style="color:inherit"><?php echo esc((string)$store['name']); ?></a>
        › <?php echo esc((string)$product['name']); ?>
    </div>

    <?php if ($msg !== ''): ?>
        <div style="background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;border-radius:14px;padding:14px 16px;font-weight:800;margin-bottom:14px">
            ✅ <?php echo esc($msg); ?>
        </div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
        <div style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;border-radius:14px;padding:14px 16px;font-weight:800;margin-bottom:14px">
            ⚠️ <?php echo esc($err); ?>
        </div>
    <?php endif; ?>

    <div style="display:grid;gap:22px;grid-template-columns:minmax(0,1fr) minmax(0,1fr)" class="wfv-row">
        <div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden">
                <?php if ($imgs): ?>
                    <img id="wfvMain" src="<?php echo esc((string)$imgs[0]); ?>" alt="<?php echo esc((string)$product['name']); ?>"
                         style="width:100%;display:block;aspect-ratio:1/1;object-fit:cover;background:#f1f5f9">
                <?php else: ?>
                    <div style="aspect-ratio:1/1;display:grid;place-items:center;background:#f1f5f9;font-size:52px">🛍️</div>
                <?php endif; ?>
            </div>
            <?php if (count($imgs) > 1): ?>
                <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
                    <?php foreach ($imgs as $i => $u): ?>
                        <img src="<?php echo esc((string)$u); ?>" alt="صورة <?php echo (int)$i + 1; ?>"
                             onclick="document.getElementById('wfvMain').src=this.src"
                             style="width:64px;height:64px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;cursor:pointer">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (trim((string)($product['desc'] ?? '')) !== ''): ?>
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:18px;margin-top:14px">
                    <h3 style="margin:0 0 8px">تفاصيل المنتج</h3>
                    <p class="mut" style="margin:0;line-height:1.9"><?php echo nl2br(esc((string)$product['desc'])); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <h1 style="margin:0 0 6px;font-size:24px"><?php echo esc((string)$product['name']); ?></h1>
            <?php if (trim((string)($product['category'] ?? '')) !== ''): ?>
                <div class="mut" style="margin-bottom:10px"><?php echo esc((string)$product['category']); ?></div>
            <?php endif; ?>
            <div style="font-size:26px;font-weight:900;color:var(--wf-store-accent);margin-bottom:4px">
                <?php echo number_format((float)$product['price'], 0); ?> ج.م
                <?php if ((float)($product['old_price'] ?? 0) > (float)$product['price']): ?>
                    <small style="font-size:15px;color:#94a3b8;text-decoration:line-through;font-weight:600">
                        <?php echo number_format((float)$product['old_price'], 0); ?> ج.م
                    </small>
                <?php endif; ?>
            </div>
            <div class="mut" style="font-size:13px;margin-bottom:14px">+ شحن <?php echo number_format($ship, 0); ?> ج.م · الدفع عند الاستلام</div>

            <?php if ($sold_out): ?>
                <div style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:14px;padding:14px;font-weight:800">
                    المنتج نافد حاليًا — جرّب منتج تاني من المتجر.
                </div>
            <?php elseif ($done_no !== ''): ?>
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:20px;text-align:center">
                    <div style="font-size:44px">🎉</div>
                    <h3 style="margin:8px 0">طلبك اتسجل</h3>
                    <p class="mut">رقم الطلب: <b><?php echo esc($done_no); ?></b><br>هنكلمك لتأكيد الطلب وموعد التوصيل.</p>
                    <a class="wfv-btn" href="vendor.php?store=<?php echo urlencode($slug); ?>">كمّل تسوق</a>
                </div>
            <?php else: ?>
                <form method="post" action="vendor_product.php" style="background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:18px">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="place_order" value="1">
                    <input type="hidden" name="store" value="<?php echo esc($slug); ?>">
                    <input type="hidden" name="id" value="<?php echo esc((string)$product['id']); ?>">

                    <?php if (!empty($product['sizes']) && is_array($product['sizes'])): ?>
                        <label class="wfv-label">المقاس</label>
                        <select class="wfv-field" name="size" style="margin-bottom:12px">
                            <option value="">— اختر المقاس —</option>
                            <?php foreach ($product['sizes'] as $s): ?>
                                <option value="<?php echo esc((string)$s); ?>"><?php echo esc((string)$s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php if (!empty($product['colors']) && is_array($product['colors'])): ?>
                        <label class="wfv-label">اللون</label>
                        <select class="wfv-field" name="color" style="margin-bottom:12px">
                            <option value="">— اختر اللون —</option>
                            <?php foreach ($product['colors'] as $c): ?>
                                <option value="<?php echo esc((string)$c); ?>"><?php echo esc((string)$c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <label class="wfv-label">الكمية</label>
                    <input class="wfv-field" type="number" name="qty" min="1" max="20" value="1" style="margin-bottom:12px">

                    <div class="wfv-row">
                        <div>
                            <label class="wfv-label">الاسم بالكامل *</label>
                            <input class="wfv-field" name="cname" required maxlength="100">
                        </div>
                        <div>
                            <label class="wfv-label">رقم الموبايل *</label>
                            <input class="wfv-field" name="cphone" required inputmode="numeric" maxlength="15" dir="ltr">
                        </div>
                    </div>
                    <div style="margin-top:12px">
                        <label class="wfv-label">رقم بديل (اختياري)</label>
                        <input class="wfv-field" name="cphone2" inputmode="numeric" maxlength="15" dir="ltr">
                    </div>
                    <div style="margin-top:12px">
                        <label class="wfv-label">المحافظة *</label>
                        <select class="wfv-field" name="city" required>
                            <option value="">— اختر المحافظة —</option>
                            <?php if ($govs): foreach ($govs as $g => $fee): ?>
                                <option value="<?php echo esc((string)$g); ?>"><?php echo esc((string)$g); ?></option>
                            <?php endforeach; else: ?>
                                <option value="القاهرة">القاهرة</option>
                                <option value="الجيزة">الجيزة</option>
                                <option value="الإسكندرية">الإسكندرية</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div style="margin-top:12px">
                        <label class="wfv-label">العنوان بالتفصيل *</label>
                        <textarea class="wfv-field" name="address" rows="2" required maxlength="300"></textarea>
                    </div>
                    <div style="margin-top:12px">
                        <label class="wfv-label">ملاحظات (اختياري)</label>
                        <input class="wfv-field" name="notes" maxlength="200">
                    </div>
                    <button class="wfv-btn" type="submit" style="width:100%;margin-top:16px">
                        تأكيد الطلب — الدفع عند الاستلام
                    </button>
                    <p class="mut" style="font-size:12px;text-align:center;margin:10px 0 0">
                        دفع كاش عند الاستلام · معاينة قبل الاستلام · شحن لكل المحافظات
                    </p>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php layout_end(); ?>
