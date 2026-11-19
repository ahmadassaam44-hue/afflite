<?php
// 🏬 إدارة المتاجر (أدمن فقط): إنشاء متجر لتاجر، تعيين/إنشاء حسابه، فتح/إغلاق المتجر
require 'layout.php';
require_admin_or_die();

$msg = ''; $err = ''; $new_pass = '';
$action = (string)($_POST['act'] ?? ($_GET['act'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        $err = 'انتهت صلاحية الصفحة — حدّث وحاول تاني';
    } elseif ($action === 'create') {
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['owner_email'] ?? '')));
        $accent = (string)($_POST['accent'] ?? '#10b981');
        $ship = (float)($_POST['ship_fee'] ?? WFV_DEFAULT_SHIP);
        if (!wfv_slug_ok($slug)) {
            $err = 'رابط المتجر لازم 3–24 حرف إنجليزي صغير/أرقام/شرطة، ومش كلمة محجوزة';
        } elseif (wfv_store($slug)) {
            $err = 'الرابط ده مستخدم بالفعل';
        } elseif (mb_strlen($name) < 2) {
            $err = 'اكتب اسم المتجر';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'بريد صاحب المتجر غير صحيح';
        }
        if ($err === '') {
            // لو البريد مش موجود: نعمل حساب تاجر جديد بكلمة سر مؤقتة تظهر مرة واحدة
            if ($email !== '') {
                $existing = find_user($email);
                if ($existing) {
                    update_user($email, function (&$u) { $u['role'] = 'vendor'; });
                } else {
                    $new_pass = 'V' . bin2hex(random_bytes(4)) . 'a1';
                    $profile = [
                        'name'    => mb_substr($name, 0, 60),
                        'role'    => 'vendor',
                        'phone'   => '',
                        'created' => date('c'),
                    ];
                    if (function_exists('usr_create_account')) {
                        if (!usr_create_account($email, $new_pass, $profile)) {
                            $err = 'مش قدرنا نعمل حساب التاجر — جرّب بريد تاني أو أنشئه من صفحة التسجيل';
                        }
                    } else {
                        $err = 'إنشاء الحسابات غير متاح حاليًا';
                    }
                }
            }
            if ($err === '') {
                $ok = wfv_store_save($slug, [
                    'name'        => mb_substr($name, 0, 60),
                    'owner_email' => $email,
                    'accent'      => preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent) ? $accent : '#10b981',
                    'ship_fee'    => $ship,
                    'enabled'     => 1,
                    'created_at'  => date('c'),
                ]);
                if ($ok) {
                    wfv_store_dir($slug, true);
                    wfv_store_assets_dir($slug, true);
                    $msg = '✅ المتجر اتcreated واسمه «' . $name . '» → vendor.php?store=' . $slug;
                    if ($new_pass !== '') $msg .= ' — كلمة سر التاجر المؤقتة: ' . $new_pass;
                    if (function_exists('log_audit')) @log_audit('store_create', $slug, ['owner' => $email]);
                } else {
                    $err = 'مش قدرنا نحفظ المتجر';
                }
            }
        }
    } elseif ($action === 'toggle') {
        $slug = (string)($_POST['slug'] ?? '');
        $st = wfv_store($slug);
        if ($st) {
            wfv_store_save($slug, ['enabled' => (int)$st['enabled'] === 1 ? 0 : 1]);
            $msg = (int)$st['enabled'] === 1 ? '⏸️ المتجر اتقفل' : '▶️ المتجر اتفتح';
        }
    } elseif ($action === 'assign') {
        $slug = (string)($_POST['slug'] ?? '');
        $email = strtolower(trim((string)($_POST['owner_email'] ?? '')));
        if (wfv_store($slug) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            update_user($email, function (&$u) { $u['role'] = 'vendor'; });
            wfv_store_save($slug, ['owner_email' => $email]);
            $msg = '✅ صاحب المتجر اتغيّر لـ ' . $email;
        } else {
            $err = 'بيانات غير صحيحة';
        }
    } elseif ($action === 'delete') {
        $slug = (string)($_POST['slug'] ?? '');
        if ($slug !== '' && wfv_store($slug)) {
            jupdate('stores.json', function (&$all) use ($slug) {
                if (is_array($all)) unset($all[$slug]);
                return true;
            }, []);
            $dir = wfv_store_dir($slug);
            if ($dir !== '' && is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
                @rmdir($dir);
            }
            if (function_exists('log_audit')) @log_audit('store_delete', $slug, []);
            $msg = '🗑️ المتجر اتحذف';
        }
    }
}

$stores = wfv_stores_all();
layout_top('المتاجر', 'home');
?>
<div style="max-width:1080px;margin:0 auto;padding:16px 12px 40px">
    <h1 style="margin:0 0 6px;font-size:22px">🏬 إدارة المتاجر</h1>
    <p class="mut" style="margin:0 0 16px">كل متجر بياخد نفس التصميم والوظائف، وله منتجاته وطلباته ورابطه الخاص.</p>

    <?php if ($msg !== ''): ?><div style="background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;border-radius:12px;padding:12px 14px;font-weight:800;margin-bottom:12px"><?php echo esc($msg); ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;border-radius:12px;padding:12px 14px;font-weight:800;margin-bottom:12px">⚠️ <?php echo esc($err); ?></div><?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0">➕ إنشاء متجر جديد</h2>
        <form method="post" action="stores_admin.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="create">
            <div style="display:grid;gap:12px;grid-template-columns:1fr 1fr 1fr" class="wfv-row">
                <label>رابط المتجر (إنجليزي) *<input class="input" name="slug" required maxlength="24" placeholder="ahmed-store" dir="ltr"></label>
                <label>اسم المتجر *<input class="input" name="name" required maxlength="60" placeholder="متجر أحمد"></label>
                <label>اللون الأساسي<input class="input" name="accent" type="color" value="#10b981"></label>
            </div>
            <div style="display:grid;gap:12px;grid-template-columns:2fr 1fr;margin-top:12px" class="wfv-row">
                <label>بريد صاحب المتجر (لو مش موجود هنعمل حسابه) <input class="input" name="owner_email" type="email" dir="ltr" placeholder="owner@mail.com"></label>
                <label>مصاريف الشحن<input class="input" name="ship_fee" type="number" min="0" value="<?php echo (int)WFV_DEFAULT_SHIP; ?>"></label>
            </div>
            <div style="margin-top:16px"><button class="btn pri" type="submit">إنشاء المتجر</button></div>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0">المتاجر (<?php echo count($stores); ?>)</h2>
        <?php if (!$stores): ?>
            <p class="mut">مفيش متاجر لسه.</p>
        <?php else: ?>
            <table class="data-table rtable">
                <tr><th>المتجر</th><th>صاحب المتجر</th><th>منتجات</th><th>طلبات</th><th>مبيعات</th><th>الحالة</th><th></th></tr>
                <?php foreach ($stores as $sl => $row):
                    $stt = wfv_store_stats((string)$sl);
                    $on = (int)($row['enabled'] ?? 1) === 1; ?>
                    <tr>
                        <td><b><?php echo esc((string)($row['name'] ?? $sl)); ?></b>
                            <div class="mut" style="font-size:12px"><a href="../vendor.php?store=<?php echo urlencode((string)$sl); ?>" target="_blank" rel="noopener">vendor.php?store=<?php echo esc((string)$sl); ?></a></div></td>
                        <td style="font-size:12px"><?php echo esc((string)($row['owner_email'] ?? '—')); ?></td>
                        <td><?php echo (int)$stt['items']; ?></td>
                        <td><?php echo (int)$stt['orders']; ?></td>
                        <td><?php echo number_format($stt['revenue'], 0); ?> ج.م</td>
                        <td><?php echo $on ? '<span style="color:#047857">مفتوح</span>' : '<span style="color:#b91c1c">مقفول</span>'; ?></td>
                        <td style="white-space:nowrap">
                            <a class="btn sm" href="vendor.php?store=<?php echo urlencode((string)$sl); ?>">إدارة</a>
                            <form method="post" action="stores_admin.php" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="act" value="toggle">
                                <input type="hidden" name="slug" value="<?php echo esc((string)$sl); ?>">
                                <button class="btn sm" type="submit"><?php echo $on ? 'إغلاق' : 'فتح'; ?></button>
                            </form>
                            <form method="post" action="stores_admin.php" style="display:inline" onsubmit="return confirm('حذف المتجر ومنتجاته نهائيًا؟')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="act" value="delete">
                                <input type="hidden" name="slug" value="<?php echo esc((string)$sl); ?>">
                                <button class="btn sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
