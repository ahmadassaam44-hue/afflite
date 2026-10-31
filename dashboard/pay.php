<?php $PUBLIC_PAGE = true; require 'layout.php';
// ===== صفحة الدفع للخدمات الرقمية والسوشيال: انستا باي / محافظ + رفع صورة التحويل =====
$ono = mb_substr(trim((string)($_GET['o'] ?? $_POST['o'] ?? '')), 0, 40);
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? $_POST['t'] ?? ''));
$orders = jload('orders.json', []); $oi = null; $o = null;
foreach ($orders as $i => $x) { if (($x['order_no'] ?? '') === $ono && $ono !== '') { $oi = $i; $o = $x; break; } }
$cu = current_user();
$mine = $o && ((!empty($o['pay_token']) && hash_equals($o['pay_token'], $tok)) || ($cu && ($o['user'] ?? '') === $cu['email']));
if (!$o || !$mine) { layout_top('الدفع'); echo '<div class="card" data-ui="u-c4fdb063 df d71"><h3>الطلب غير موجود</h3><br><a class="btn" href="orders.php">تتبع طلباتك</a></div>'; layout_end(); exit; }

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_proof'])) {
    $current_payment_status = (string)($o['payment_status'] ?? 'awaiting');
    if (!in_array($current_payment_status, ['awaiting', 'rejected'], true)) {
        $err = 'الإيصال قيد المراجعة أو تم تأكيد الدفع بالفعل.';
    } elseif (rate_limit_check('pay_proof', 6, 900) > 0) {
        $err = 'محاولات كثيرة — استنى شوية';
    } else {
        $img = save_uploaded_image('proof', 'pay');
        $ch = preg_replace('/[^a-z_]/', '', (string)($_POST['channel'] ?? ''));
        $sender = mb_substr(trim((string)($_POST['sender'] ?? '')), 0, 60);
        if ($img === '') $err = 'ارفع صورة واضحة لإيصال التحويل (JPG/PNG)';
        else {
            $saved = false;
            jupdate('orders.json', function (&$os) use ($oi, $img, $ch, $sender, &$saved) {
                if (!isset($os[$oi]) || !in_array((string)($os[$oi]['payment_status'] ?? 'awaiting'), ['awaiting', 'rejected'], true)) return false;
                $os[$oi]['payment_status'] = 'proof_uploaded';
                $os[$oi]['payment'] = ['channel' => $ch, 'sender' => $sender, 'proof' => $img, 'at' => date('c')];
                $os[$oi]['payment_reject_reason'] = '';
                $saved = true;
                return true;
            }, []);
            if (!$saved) {
                $err = 'تم تحديث حالة الدفع بالفعل. حدّث الصفحة وتحقق من حالة الطلب.';
                $upload_path = __DIR__ . '/../' . ltrim($img, '/');
                if (is_file($upload_path)) @unlink($upload_path);
            } else {
                notify('admin', ' إيصال دفع جديد للطلب ' . $ono . ' — ' . number_format((float)$o['total'], 2) . ' ج.م (' . ($o['cname'] ?? '') . ')', 'dashboard/admin.php?tab=payments');
                if (function_exists('wf_event')) wf_event('payment_proof', ['order_no' => $ono, 'value' => (float)$o['total']]);
                $msg = 'ok'; $o = jload('orders.json', [])[$oi];
            }
        }
    }
}
$channels = pay_channels();
$amount = (float)($o['total'] ?? 0);
$ps = $o['payment_status'] ?? 'awaiting';
layout_top('إتمام الدفع ', 'orders');
echo '<div data-ui="u-8ac484bd d72 d73">';
echo '<div class="card" data-ui="u-b47e8029 df d74 d54"><div class="mut" data-ui="u-5d7ebccb du">رقم الطلب</div><div dir="ltr" data-ui="u-8eedba40 d1 de">' . esc($ono) . '</div><div data-ui="u-694658a5 d62 d12">' . esc(mb_substr($o['pname'] ?? '', 0, 70)) . (!empty($o['variant']) ? ' — <b>' . esc($o['variant']) . '</b>' : '') . '</div><div data-ui="u-c8685dec d75 d1 d1x d49">' . number_format($amount, $amount == (int)$amount ? 0 : 2) . ' <span data-ui="u-36c621e1 d26">ج.م</span></div></div>';

if ($ps === 'confirmed') {
    echo '<div class="card" data-ui="u-167e6fae df d76 d15 d5b"><div data-ui="u-c1b7cac7 d77"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg></div><h3>تم تأكيد الدفع وجاري التنفيذ</h3><p class="mut">هيوصلك إشعار عند اكتمال الطلب</p></div>' . tracking_event_js('purchase', $amount, 'EGP', ['content_ids' => [(string)$o['id']]]);
} elseif ($ps === 'rejected') {
    echo '<div class="card" data-ui="u-c817c14f df d78 d5y d5m"><div data-ui="u-cc519402 d20"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-close></use></svg></div><h3>لم نتمكن من تأكيد التحويل</h3><p class="mut">' . esc($o['payment_reject_reason'] ?? 'راجع الإيصال وارفعه من جديد أو تواصل معنا') . '</p></div>';
    $ps = 'awaiting';
}
if ($ps === 'proof_uploaded' || $msg === 'ok') {
    echo '<div class="card" data-ui="u-9330a276 df d78 d8 d79"><div data-ui="u-cc519402 d20"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-clock></use></svg></div><h3>استلمنا إيصالك — جاري المراجعة</h3><p class="mut">بنراجع التحويل يدوياً وهنأكد طلبك في أقرب وقت (وفق دور المراجعة). هيوصلك إشعار.</p>';
    if (!empty($o['payment']['proof'])) echo '<img alt="صورة المنتج" src="' . esc(asset_url($o['payment']['proof'])) . '" data-ui="u-1ff33781 d4p da d23 dt">';

    echo '</div>' . tracking_event_js('checkout', $amount);
} elseif ($ps === 'awaiting') {
    if ($err) echo '<div class="card" data-ui="u-fa3891a7 dr d54 d5y d5m dc">' . esc($err) . '</div>';
    if (!$channels) {
        echo '<div class="card" data-ui="u-dc259471 df d78"><h3>الدفع عند التواصل</h3><p class="mut">هنتواصل معاك على ' . esc($o['cphone'] ?? '') . ' لإتمام الدفع والتنفيذ.</p></div>';
    } else {
        echo '<div class="card" data-ui="u-9e82dcff d54"><h3>1 حوّل المبلغ على أي وسيلة</h3><p class="mut" data-ui="u-797f2f4d d4w d11">' . esc(site_setting('pay_note', 'حوّل المبلغ بالظبط ثم ارفع صورة الإيصال — التنفيذ يبدأ فور مراجعة التحويل.')) . '</p>';
        foreach ($channels as $k => $c) {
            echo '<div data-ui="u-c6e7a8d4 dh d1p d1q d7a dt da d2d"><span data-ui="u-53b3dabc d7b">' . wf_svg_icon('bank',24) . '</span><div data-ui="u-47f21793 d2i"><div data-ui="u-f4182ff0 dc dd">' . esc(wf_ui_plain($c['label'])) . '</div><div dir="ltr" data-ui="u-91f47939 d1 d26 d7c">' . esc($c['account']) . '</div>' . (site_setting('pay_name') ? '<div class="mut" data-ui="u-5d985ec3 do">باسم: ' . esc(site_setting('pay_name')) . '</div>' : '') . '</div><button type="button" class="btn sm outline" data-copy="' . esc($c['account']) . '">نسخ</button></div>';
        }
        echo '</div>';
        echo '<form method="post" enctype="multipart/form-data" class="card"><h3>2 ارفع صورة إيصال التحويل</h3>' . csrf_field() . '<input type="hidden" name="o" value="' . esc($ono) . '"><input type="hidden" name="t" value="' . esc($tok ?: ($o['pay_token'] ?? '')) . '"><input type="hidden" name="upload_proof" value="1">';
        echo '<label data-ui="u-8fdda8e5 d27 d4w dc d7d">حوّلت من خلال</label><select name="channel" data-ui="u-edd9e589 dx d6h dt d24 d1v">';
        foreach ($channels as $k => $c) echo '<option value="' . esc($k) . '">' . esc(wf_ui_plain($c['label'])) . '</option>';
        echo '</select><label data-ui="u-8fdda8e5 d27 d4w dc d7d">رقم / اسم الحساب اللي حوّلت منه</label><input name="sender" dir="ltr" placeholder="01xxxxxxxxx" data-ui="u-e0a07ae5 dx d6h dt d24">';
        echo '<label data-ui="u-8fdda8e5 d27 d4w dc d7d">صورة الإيصال *</label><input type="file" name="proof" accept="image/*" required data-ui="u-c3bc7aa3 dx d6h d7e d24 ds">';
        echo '<button class="btn pri" data-ui="u-762ba8ee dx dw dq dy"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-download></use></svg> إرسال الإيصال</button></form>';
    }
}
echo '<p data-ui="u-de6a5cf4 df dq"><a href="orders.php' . ($cu ? '' : '?phone=' . urlencode($o['cphone'] ?? '')) . '" class="mut" data-ui="u-5d7ebccb du"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg> تتبع طلباتي</a></p></div>';
layout_end();
