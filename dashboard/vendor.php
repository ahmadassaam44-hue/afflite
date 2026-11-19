<?php
// 🏬 لوحة التاجر: متجري — منتجاتي — طلباتي — إعدادات المتجر
require 'layout.php';

$u = current_user();
$is_admin = function_exists('is_admin_user') && is_admin_user();
$role = function_exists('wf_user_role') ? wf_user_role($u) : ($u['role'] ?? '');

$store = $is_admin ? null : wfv_store_of_user((string)($u['email'] ?? ''));
$slug = strtolower(trim((string)($_GET['store'] ?? ($store['slug'] ?? ''))));

if ($is_admin) {
    $store = $slug !== '' ? wfv_store($slug) : null;
    if (!$store) {
        layout_top('المتاجر', 'home');
        $all = wfv_stores_all();
        echo '<div class="card"><h2>🏬 المتاجر</h2><p class="mut">اختر متجر لإدارته، أو أنشئ متجر جديد من لوحة الإدارة.</p>';
        if (!$all) {
            echo '<p>مفيش متاجر لسه. <a class="btn pri" href="stores_admin.php">إنشاء أول متجر</a></p>';
        } else {
            echo '<table class="data-table rtable"><tr><th>المتجر</th><th>المنتجات</th><th>الطلبات</th><th></th></tr>';
            foreach ($all as $sl => $row) {
                $stt = wfv_store_stats((string)$sl);
                echo '<tr><td><b>' . esc((string)($row['name'] ?? $sl)) . '</b><div class="mut" style="font-size:12px">' . esc((string)$sl) . '</div></td>'
                    . '<td>' . (int)$stt['items'] . '</td><td>' . (int)$stt['orders'] . '</td>'
                    . '<td><a class="btn sm" href="vendor.php?store=' . urlencode((string)$sl) . '">إدارة</a> '
                    . '<a class="btn sm" href="../vendor.php?store=' . urlencode((string)$sl) . '" target="_blank" rel="noopener">المتجر</a></td></tr>';
            }
            echo '</table><a class="btn pri" href="stores_admin.php">إدارة المتاجر</a>';
        }
        echo '</div>';
        layout_end();
        exit;
    }
}

if (!$store) {
    layout_top('متجري', 'home');
    echo '<div class="card" style="text-align:center"><div style="font-size:46px">🏬</div>'
        . '<h2>مفيش متجر مرتبط بحسابك</h2>'
        . '<p class="mut">لو إنت تاجر، تواصل مع إدارة الموقع لتفعيل متجرك على بريدك: <b>' . esc((string)($u['email'] ?? '')) . '</b></p>'
        . '<a class="btn" href="index.php">رجوع للوحة</a></div>';
    layout_end();
    exit;
}

$slug = (string)$store['slug'];
$msg = ''; $err = ''; $edit = null;
$tab = (string)($_GET['vtab'] ?? 'products');

// ------------------------------- العمليات ----------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string)($_POST['wfv_act'] ?? '');
    if (!csrf_ok()) {
        $err = 'انتهت صلاحية الصفحة — حدّث وحاول تاني';
    } elseif ($act === 'save_product') {
        $in = $_POST;
        // رفع صور جديدة (حتى 3 صور في المرة)
        $imgs = [];
        if (!empty($_POST['images_keep']) && is_array($_POST['images_keep'])) {
            $imgs = array_values(array_filter(array_map('strval', $_POST['images_keep'])));
        }
        foreach (['img1', 'img2', 'img3'] as $fld) {
            $url = wfv_upload_image($slug, $fld, $uerr);
            if ($uerr !== '') { $err = $uerr; break; }
            if ($url !== '') $imgs[] = $url;
        }
        if ($err === '') {
            $in['images'] = $imgs;
            $saved = wfv_product_save($slug, $in, $perr);
            if ($saved === false) $err = $perr;
            else { $msg = '✅ المنتج اتحفظ'; $tab = 'products'; }
        }
    } elseif ($act === 'delete_product') {
        if (wfv_product_delete($slug, (string)($_POST['id'] ?? ''))) $msg = '🗑️ المنتج اتحذف';
        else $err = 'المنتج مش موجود';
    } elseif ($act === 'del_image') {
        $url = (string)($_POST['url'] ?? '');
        wfv_delete_image($slug, $url);
        $pid = (string)($_POST['id'] ?? '');
        $prod = wfv_product($slug, $pid);
        if ($prod) {
            $prod['images'] = array_values(array_filter((array)$prod['images'], function ($x) use ($url) { return (string)$x !== $url; }));
            wfv_product_save($slug, $prod, $e2);
            $edit = wfv_product($slug, $pid);
        }
        $msg = '🗑️ الصورة اتشالت';
    } elseif ($act === 'save_store') {
        $up = [
            'name'     => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60),
            'tagline'  => mb_substr(trim((string)($_POST['tagline'] ?? '')), 0, 120),
            'about'    => mb_substr(trim((string)($_POST['about'] ?? '')), 0, 800),
            'accent'   => (string)($_POST['accent'] ?? '#10b981'),
            'phone'    => mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 20),
            'whatsapp' => mb_substr(trim((string)($_POST['whatsapp'] ?? '')), 0, 20),
            'announce' => mb_substr(trim((string)($_POST['announce'] ?? '')), 0, 160),
            'ship_fee' => (float)($_POST['ship_fee'] ?? 80),
        ];
        if ($up['name'] === '') $err = 'اسم المتجر مطلوب';
        if ($err === '' && $is_admin) $up['enabled'] = empty($_POST['enabled']) ? 0 : 1;
        if ($err === '') {
            $logo = wfv_upload_image($slug, 'logo', $lerr);
            if ($lerr !== '') $err = $lerr;
            elseif ($logo !== '') $up['logo'] = $logo;
        }
        if ($err === '') {
            if (wfv_store_save($slug, $up)) { $msg = '✅ إعدادات المتجر اتحفظت'; $store = wfv_store($slug); }
            else $err = 'مش قدرنا نحفظ الإعدادات';
        }
    }
}

if ($edit === null && !empty($_GET['edit'])) {
    $edit = wfv_product($slug, (string)$_GET['edit']);
}

$stats = wfv_store_stats($slug);
$products = wfv_products($slug);
$orders = wfv_store_orders($slug);
$cats = function_exists('wf_category_canonical') ? wf_category_canonical() : [];

layout_top('متجري — ' . mb_substr((string)$store['name'], 0, 30), 'home');
?>
<div style="max-width:1080px;margin:0 auto;padding:16px 12px 40px">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
        <div>
            <h1 style="margin:0;font-size:22px">🏬 <?php echo esc((string)$store['name']); ?></h1>
            <div class="mut" style="font-size:13px">رابط المتجر: <a href="../vendor.php?store=<?php echo urlencode($slug); ?>" target="_blank" rel="noopener">vendor.php?store=<?php echo esc($slug); ?></a></div>
        </div>
        <a class="btn pri" href="../vendor.php?store=<?php echo urlencode($slug); ?>" target="_blank" rel="noopener">افتح المتجر ↗</a>
    </div>

    <?php if ($msg !== ''): ?><div style="background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;border-radius:12px;padding:12px 14px;font-weight:800;margin-bottom:12px"><?php echo esc($msg); ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;border-radius:12px;padding:12px 14px;font-weight:800;margin-bottom:12px">⚠️ <?php echo esc($err); ?></div><?php endif; ?>

    <div style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:16px">
        <div class="card" style="margin:0"><div class="mut" style="font-size:12px">منتجات</div><div style="font-size:24px;font-weight:900"><?php echo (int)$stats['items']; ?></div></div>
        <div class="card" style="margin:0"><div class="mut" style="font-size:12px">طلبات</div><div style="font-size:24px;font-weight:900"><?php echo (int)$stats['orders']; ?></div></div>
        <div class="card" style="margin:0"><div class="mut" style="font-size:12px">قيد التنفيذ</div><div style="font-size:24px;font-weight:900"><?php echo (int)$stats['pending']; ?></div></div>
        <div class="card" style="margin:0"><div class="mut" style="font-size:12px">إجمالي المبيعات</div><div style="font-size:24px;font-weight:900"><?php echo number_format($stats['revenue'], 0); ?> ج.م</div></div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
        <?php foreach (['products' => '📦 منتجاتي', 'orders' => '🧾 طلباتي', 'settings' => '⚙️ إعدادات المتجر'] as $k => $lbl): ?>
            <a class="btn <?php echo $tab === $k ? 'pri' : ''; ?>" href="?store=<?php echo urlencode($slug); ?>&vtab=<?php echo $k; ?>"><?php echo $lbl; ?></a>
        <?php endforeach; ?>
    </div>

<?php if ($tab === 'products'): ?>
    <div class="card">
        <h2 style="margin-top:0"><?php echo $edit ? '✏️ تعديل منتج' : '➕ إضافة منتج جديد'; ?></h2>
        <form method="post" enctype="multipart/form-data" action="?store=<?php echo urlencode($slug); ?>&vtab=products">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="wfv_act" value="save_product">
            <input type="hidden" name="id" value="<?php echo esc((string)($edit['id'] ?? '')); ?>">
            <?php if ($edit && !empty($edit['images'])): foreach ($edit['images'] as $im): ?>
                <input type="hidden" name="images_keep[]" value="<?php echo esc((string)$im); ?>">
            <?php endforeach; endif; ?>

            <div style="display:grid;gap:12px;grid-template-columns:2fr 1fr 1fr" class="wfv-row">
                <label>اسم المنتج *<input class="input" name="name" required maxlength="140" value="<?php echo esc((string)($edit['name'] ?? '')); ?>"></label>
                <label>السعر (ج.م) *<input class="input" name="price" type="number" step="0.5" min="1" required value="<?php echo esc((string)($edit['price'] ?? '')); ?>"></label>
                <label>السعر قبل الخصم<input class="input" name="old_price" type="number" step="0.5" min="0" value="<?php echo esc((string)($edit['old_price'] ?? '')); ?>"></label>
            </div>
            <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr;margin-top:12px" class="wfv-row">
                <label>القسم
                    <select class="input" name="category">
                        <option value="">— اختياري —</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?php echo esc($c); ?>" <?php echo ($edit && (string)($edit['category'] ?? '') === $c) ? 'selected' : ''; ?>><?php echo esc($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>المقاسات (بفاصلة)<input class="input" name="sizes" value="<?php echo esc($edit && !empty($edit['sizes']) ? implode(', ', (array)$edit['sizes']) : ''); ?>" placeholder="S, M, L, XL"></label>
                <label>الألوان (بفاصلة)<input class="input" name="colors" value="<?php echo esc($edit && !empty($edit['colors']) ? implode(', ', (array)$edit['colors']) : ''); ?>" placeholder="أسود, أبيض"></label>
            </div>
            <label style="display:block;margin-top:12px">الوصف<textarea class="input" name="desc" rows="3" maxlength="2000"><?php echo esc((string)($edit['desc'] ?? '')); ?></textarea></label>

            <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr;margin-top:12px" class="wfv-row">
                <label>صورة 1<input class="input" type="file" name="img1" accept="image/*"></label>
                <label>صورة 2<input class="input" type="file" name="img2" accept="image/*"></label>
                <label>صورة 3<input class="input" type="file" name="img3" accept="image/*"></label>
            </div>

            <?php if ($edit && !empty($edit['images'])): ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                    <?php foreach ((array)$edit['images'] as $im): ?>
                        <div style="position:relative">
                            <img src="../<?php echo esc((string)$im); ?>" alt="" style="width:78px;height:78px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px">
                <label><input type="checkbox" name="stock" value="1" <?php echo (!$edit || (int)($edit['stock'] ?? 1) === 1) ? 'checked' : ''; ?>> متوفر للطلب</label>
                <label><input type="checkbox" name="active" value="1" <?php echo (!$edit || !empty($edit['active'])) ? 'checked' : ''; ?>> معروض في المتجر</label>
                <label>شحن خاص بالمنتج (اختياري)<input class="input" style="width:120px" name="ship_fee" type="number" min="0" value="<?php echo esc($edit && $edit['ship_fee'] !== null ? (string)$edit['ship_fee'] : ''); ?>"></label>
            </div>

            <div style="margin-top:16px;display:flex;gap:10px">
                <button class="btn pri" type="submit"><?php echo $edit ? 'حفظ التعديل' : 'أضف المنتج'; ?></button>
                <?php if ($edit): ?><a class="btn" href="?store=<?php echo urlencode($slug); ?>&vtab=products">إلغاء</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0">📦 المنتجات (<?php echo count($products); ?>)</h2>
        <?php if (!$products): ?>
            <p class="mut">لسه مفيش منتجات — ابدأ من الفورم فوق.</p>
        <?php else: ?>
            <table class="data-table rtable">
                <tr><th>المنتج</th><th>السعر</th><th>الحالة</th><th></th></tr>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td>
                            <div style="display:flex;gap:10px;align-items:center">
                                <?php if (!empty($p['images'][0])): ?>
                                    <img src="../<?php echo esc((string)$p['images'][0]); ?>" alt="" style="width:42px;height:42px;object-fit:cover;border-radius:8px">
                                <?php endif; ?>
                                <div><b><?php echo esc((string)$p['name']); ?></b><div class="mut" style="font-size:12px"><?php echo esc((string)($p['category'] ?? '')); ?></div></div>
                            </div>
                        </td>
                        <td><?php echo number_format((float)$p['price'], 0); ?> ج.م</td>
                        <td>
                            <?php if (empty($p['active'])): ?><span style="color:#b91c1c">مخفي</span>
                            <?php elseif ((int)($p['stock'] ?? 1) === 0): ?><span style="color:#b45309">نافد</span>
                            <?php else: ?><span style="color:#047857">معروض</span><?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <a class="btn sm" href="?store=<?php echo urlencode($slug); ?>&vtab=products&edit=<?php echo urlencode((string)$p['id']); ?>">تعديل</a>
                            <form method="post" action="?store=<?php echo urlencode($slug); ?>&vtab=products" style="display:inline" onsubmit="return confirm('حذف المنتج نهائيًا؟')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="wfv_act" value="delete_product">
                                <input type="hidden" name="id" value="<?php echo esc((string)$p['id']); ?>">
                                <button class="btn sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'orders'): ?>
    <div class="card">
        <h2 style="margin-top:0">🧾 طلبات المتجر (<?php echo count($orders); ?>)</h2>
        <?php if (!$orders): ?>
            <p class="mut">مفيش طلبات لسه.</p>
        <?php else: ?>
            <table class="data-table rtable">
                <tr><th>رقم الطلب</th><th>التاريخ</th><th>العميل</th><th>المحافظة</th><th>الإجمالي</th><th>الحالة</th></tr>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td style="font-size:12px"><?php echo esc((string)($o['order_no'] ?? '')); ?></td>
                        <td style="font-size:12px"><?php echo esc(substr((string)($o['at'] ?? ''), 0, 10)); ?></td>
                        <td><b><?php echo esc((string)($o['cname'] ?? '')); ?></b><div class="mut" dir="ltr" style="font-size:12px"><?php echo esc((string)($o['cphone'] ?? '')); ?></div></td>
                        <td><?php echo esc((string)($o['city'] ?? '')); ?></td>
                        <td><?php echo number_format((float)($o['total'] ?? 0), 0); ?> ج.م</td>
                        <td><?php echo esc((string)($o['status'] ?? 'pending')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="card">
        <h2 style="margin-top:0">⚙️ إعدادات المتجر</h2>
        <form method="post" enctype="multipart/form-data" action="?store=<?php echo urlencode($slug); ?>&vtab=settings">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="wfv_act" value="save_store">
            <div style="display:grid;gap:12px;grid-template-columns:2fr 1fr" class="wfv-row">
                <label>اسم المتجر *<input class="input" name="name" required maxlength="60" value="<?php echo esc((string)$store['name']); ?>"></label>
                <label>اللون الأساسي<input class="input" name="accent" type="color" value="<?php echo esc((string)$store['accent']); ?>"></label>
            </div>
            <label style="display:block;margin-top:12px">وصف قصير<input class="input" name="tagline" maxlength="120" value="<?php echo esc((string)$store['tagline']); ?>"></label>
            <label style="display:block;margin-top:12px">إعلان أعلى المتجر<input class="input" name="announce" maxlength="160" value="<?php echo esc((string)$store['announce']); ?>" placeholder="مثال: شحن مجاني على الطلبات فوق 1000 ج.م"></label>
            <label style="display:block;margin-top:12px">عن المتجر<textarea class="input" name="about" rows="3" maxlength="800"><?php echo esc((string)$store['about']); ?></textarea></label>
            <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr 1fr;margin-top:12px" class="wfv-row">
                <label>تليفون<input class="input" name="phone" dir="ltr" maxlength="20" value="<?php echo esc((string)$store['phone']); ?>"></label>
                <label>واتساب<input class="input" name="whatsapp" dir="ltr" maxlength="20" value="<?php echo esc((string)$store['whatsapp']); ?>"></label>
                <label>مصاريف الشحن<input class="input" name="ship_fee" type="number" min="0" value="<?php echo esc((string)(int)$store['ship_fee']); ?>"></label>
                <label>لوجو المتجر<input class="input" type="file" name="logo" accept="image/*"></label>
            </div>
            <?php if (!empty($store['logo'])): ?>
                <div style="margin-top:10px"><img src="../<?php echo esc((string)$store['logo']); ?>" alt="logo" style="width:70px;height:70px;object-fit:cover;border-radius:14px;border:1px solid #e2e8f0"></div>
            <?php endif; ?>
            <?php if ($is_admin): ?>
                <label style="display:block;margin-top:12px"><input type="checkbox" name="enabled" value="1" <?php echo (int)$store['enabled'] === 1 ? 'checked' : ''; ?>> المتجر مفتوح للزوار</label>
            <?php endif; ?>
            <div style="margin-top:16px"><button class="btn pri" type="submit">حفظ الإعدادات</button></div>
        </form>
    </div>
<?php endif; ?>
</div>
<?php layout_end(); ?>
