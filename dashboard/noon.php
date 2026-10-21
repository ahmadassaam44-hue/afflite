<?php
// ============================================================================
//  🟡 أفلييت نون للمسوق — كود الكوبون بدل اللينك، بنفس شكل أفلييت أمازون
//  تبويبات: طلب كود · كودي · مبيعاتي وعمولاتي · إزاي النظام بيشتغل
//  الكود مفيهوش لينك، فمفيش نقرات: العداد بيسجل مرات استخدام الكود (البيعات بيه)
// ============================================================================
require 'layout.php';

if (empty($user['email']) || ($user['role'] ?? '') === 'admin') { header('Location: ./'); exit; }
$me = find_user($user['email']);
if (!$me) { header('Location: ./'); exit; }
$email = strtolower(trim((string)$user['email']));

// ---------- الأفعال (POST) ----------
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'request') {
        $res = noon_code_request($email, (string)($_POST['noon_code'] ?? ''), (string)($_POST['note'] ?? ''));
        if (!empty($res['ok'])) {
            $msg = '📨 اتبعت الكود للإدارة، هيتفعّل بعد المراجعة';
            notify('admin', '🟡 طلب كود نون جديد: ' . $res['rec']['code'] . ' — ' . ($res['rec']['name'] !== '' ? $res['rec']['name'] : $email), 'dashboard/admin.php?tab=noon');
        } else {
            $err = (string)($res['error'] ?? 'تعذر إرسال الكود');
        }
    } elseif ($act === 'stop') {
        $res = noon_code_stop_by_marketer($email, (string)($_POST['code_id'] ?? ''));
        if (!empty($res['ok'])) $msg = '🗑️ اتوقف الكود، والبيعات الجاية بيه مش هتتسجل';
        else $err = (string)($res['error'] ?? 'تعذر إيقاف الكود');
    } elseif ($act === 'product_request') {
        $res = noon_product_request($email, (string)($_POST['product_url'] ?? ''), (string)($_POST['note'] ?? ''));
        if (!empty($res['ok'])) {
            $msg = '📨 اتبعت المنتج للإدارة، هيوصلك إشعار لما المنتج يتعتمد';
            $pm = (isset($res['rec']['meta']) && is_array($res['rec']['meta'])) ? $res['rec']['meta'] : [];
            $pm_txt = '🛒 طلب منتج نون جديد — ' . ($res['rec']['name'] !== '' ? $res['rec']['name'] : $email);
            if (!empty($pm)) {
                if (!empty($pm['title'])) $pm_txt .= "\nالمنتج: " . $pm['title'];
                if (!empty($pm['price'])) $pm_txt .= "\nالسعر: " . number_format((float)$pm['price'], 2) . ' ' . ((string)($pm['currency'] ?? '') !== '' ? $pm['currency'] : 'د.إ');
                if (!empty($pm['crumbs'])) $pm_txt .= "\nالكاتيجوري في نون: " . implode(' › ', (array)$pm['crumbs']);
                if (!empty($pm['img'])) $pm_txt .= "\nصورة: " . $pm['img'];
            } else {
                $pm_txt .= "\n(مقدرناش نسحب بيانات الصفحة، محتاج تدخل يدوي)";
            }
            notify('admin', $pm_txt, 'dashboard/admin.php?tab=noon');
        } else {
            $err = (string)($res['error'] ?? 'تعذر إرسال المنتج');
        }
    } elseif ($act === 'cust_request') {
        // 👤 طلب عميل: المسوق بيختار منتج معتمد له، ويبعت بيانات العميل (الإدارة تطلب المنتج، أو تتابع)
        $pref = (string)($_POST['product_ref'] ?? '');
        $plabel = '';
        foreach (noon_products_for($email) as $pp) {
            if ((string)($pp['id'] ?? '') === $pref && ($pp['status'] ?? '') === 'approved') $plabel = (string)($pp['pname'] ?? '');
        }
        if ($plabel === '') {
            $err = 'اختار منتج معتمد من منتجاتك';
        } else {
            $res = cust_req_create('noon', $email, $plabel, $pref, cust_post_data());
            if (!empty($res['ok'])) $msg = '📨 اتبعت طلب العميل للإدارة، هيوصلك إشعار لما يتنفذ';
            else $err = (string)($res['error'] ?? 'تعذر إرسال الطلب');
        }
    } elseif ($act === 'appeal') {
        $res = noon_appeal_create($email, (string)($_POST['code_id'] ?? ''), (string)($_POST['note'] ?? ''));
        if (!empty($res['ok'])) $msg = '📨 اتبعت التظلم للإدارة، هيوصلك إشعار بالرد';
        else $err = (string)($res['error'] ?? 'تعذر إرسال التظلم');
    }
}

// ---------- البيانات ----------
$codes    = noon_codes_for($email);     // كل أكوادك (الأحدث الأول)
$live     = null;                       // الطلب اللي قيد المراجعة
$approved = [];                         // الأكواد المعتمدة
foreach ($codes as $c) {
    $cs = (string)($c['status'] ?? '');
    if ($cs === 'pending' && $live === null) $live = $c;
    if ($cs === 'approved') $approved[] = $c;
}
$st       = noon_stats_for($email);
$sales    = noon_sales_for($email, 200);
$has_code = count($approved) > 0;
$req_open = ($live !== null ? 1 : 0);
foreach ($codes as $c) {
    foreach ((array)($c['appeals'] ?? []) as $ap) { if (($ap['status'] ?? '') === 'pending') $req_open++; }
}
$tab = (string)($_GET['tab'] ?? ($has_code ? 'code' : 'new'));
if (!in_array($tab, ['new', 'code', 'products', 'customers', 'sales', 'help'], true)) $tab = $has_code ? 'code' : 'new';

$sale_lbl = ['pending' => '⏳ معلقة', 'confirmed' => '✅ مؤكدة', 'reversed' => '↩️ ملغية'];
$code_lbl = ['pending' => ['⏳ قيد المراجعة', 'y'], 'approved' => ['✅ معتمد', 'g'], 'stopped' => ['⛔ موقوف', 'r'], 'rejected' => ['⛔ مرفوض', 'r'], 'deleted' => ['🗑️ محذوف من الإدارة', 'r']];
$share_of = function ($c) { return is_numeric($c['share'] ?? null) ? (float)$c['share'] : noon_default_share(); };
$pct_txt  = function ($v) { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') . '%'; };

layout_top('أفلييت نون 🟡', 'noon');
?>
<style>
.noon-hero{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-radius:16px;padding:16px 20px;margin-bottom:14px}
.noon-hero .btn.outline{color:#fff;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.4)}
.noon-box{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px;margin-bottom:14px}
.noon-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
.noon-tag{display:inline-block;font-size:11px;font-weight:800;padding:3px 9px;border-radius:99px;background:#fff7ed;color:#b45309;border:1px solid #fed7aa}
.noon-tag.g{background:#dcfce7;color:#15803d;border-color:#86efac}
.noon-tag.r{background:#fee2e2;color:#b91c1c;border-color:#fecaca}
.noon-tag.b{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.noon-tag.y{background:#fef3c7;color:#b45309;border-color:#fde68a}
.noon-notice{background:#fffbeb;border:1px dashed #f59e0b;color:#92400e;border-radius:12px;padding:12px 14px;font-size:13px;font-weight:700;line-height:1.9;margin:0 0 14px}
.noon-per{background:#ecfdf5;border:1px solid #86efac;border-radius:14px;padding:14px 16px;margin:14px 0 0;font-size:13px;font-weight:700;color:#065f46;line-height:1.9}
.noon-per-big{font-size:15px;font-weight:900;color:#14532d}
.noon-code{font-size:22px;font-weight:900;letter-spacing:1px;direction:ltr;text-align:start;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:8px 14px;display:inline-block}
.noon-appeal{margin-top:12px;border:1px dashed #fde68a;border-radius:12px;padding:10px 12px;background:#fffbeb}
.noon-appeal summary{cursor:pointer;font-size:12.5px;font-weight:800}
.noon-appeal-form{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:10px}
.noon-appeal-form label{font-size:11px;font-weight:800;flex:1;min-width:200px}
.noon-appeal-form input{width:100%;padding:7px 9px;border:1px solid #cbd5e1;border-radius:8px;font-size:12.5px;background:#fff}
.noon-help li{margin-bottom:8px;line-height:1.9}
</style>

<div class="noon-hero">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div style="font-size:19px;font-weight:900">🟡 أفلييت نون — كودك على نون</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if (!$has_code && $live === null): ?><a class="btn sm" style="background:#facc15;color:#111" href="?tab=new">➕ طلب كود</a><?php endif; ?>
      <a class="btn sm outline" href="?tab=code">🎟️ كودي (<?php echo count($approved); ?>)</a>
      <a class="btn sm outline" href="?tab=sales">🧾 مبيعاتي وعمولاتي (<?php echo count($sales); ?>)</a>
      <a class="btn sm outline" href="marketer.php">📣 لوحة المسوق</a>
    </div>
  </div>
</div>

<?php if ($msg !== ''): ?><div class="card" style="background:#dcfce7;border-left:4px solid #16a34a;color:#15803d;font-weight:800;margin-bottom:12px"><?php echo esc($msg); ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="card" style="background:#fee2e2;border-left:4px solid #dc2626;color:#b91c1c;font-weight:800;margin-bottom:12px"><?php echo esc($err); ?></div><?php endif; ?>

<div class="kpis">
  <div class="kpi"><b><?php echo number_format(count($approved)); ?></b><span>🎟️ كود معتمد</span></div>
  <div class="kpi"><b><?php echo number_format($st['uses']); ?></b><span>🔁 مرات استخدام الكود</span></div>
  <div class="kpi"><b><?php echo number_format($st['sales'], 2); ?></b><span>🛒 قيمة البيعات بالكود (ج.م)</span></div>
  <div class="kpi"><b style="color:#16a34a"><?php echo number_format($st['confirmed'], 2); ?></b><span>✅ أرباحك المؤكدة (ج.م)</span></div>
  <div class="kpi"><b style="color:#d97706"><?php echo number_format($st['pending'], 2); ?></b><span>⏳ أرباحك المعلقة (ج.م)</span></div>
  <div class="kpi"><b><?php echo number_format($req_open); ?></b><span>📨 طلبات بانتظار الموافقة</span></div>
</div>

<div class="tabs">
  <a href="?tab=new"<?php echo $tab === 'new' ? ' class="on"' : ''; ?>>➕ طلب كود</a>
  <a href="?tab=code"<?php echo $tab === 'code' ? ' class="on"' : ''; ?>>🎟️ كودي (<?php echo count($approved); ?>)</a>
  <a href="?tab=products"<?php echo $tab === 'products' ? ' class="on"' : ''; ?>>🛒 منتجاتي (<?php echo count(noon_products_for($email)); ?>)</a>
  <a href="?tab=customers"<?php echo $tab === 'customers' ? ' class="on"' : ''; ?>>👤 طلبات العملاء (<?php echo count(cust_reqs_for($email, 'noon')); ?>)</a>
  <a href="?tab=sales"<?php echo $tab === 'sales' ? ' class="on"' : ''; ?>>🧾 مبيعاتي وعمولاتي (<?php echo count($sales); ?>)</a>
  <a href="?tab=help"<?php echo $tab === 'help' ? ' class="on"' : ''; ?>>❓ إزاي النظام بيشتغل</a>
</div>

<?php
// ============================================================================
//  تبويب: طلب كود
// ============================================================================
if ($tab === 'new') {
    if ($has_code) { ?>
  <div class="noon-notice">✅ عندك كود معتمد بالفعل: <b dir="ltr"><?php echo esc((string)$approved[0]['code']); ?></b> — مش محتاج تطلب كود تاني. <a class="btn sm" href="?tab=code">🎟️ افتح كودي</a></div>
<?php } elseif ($live !== null) { ?>
  <div class="noon-notice">⏳ كودك <b dir="ltr"><?php echo esc((string)$live['code']); ?></b> بانتظار موافقة الإدارة، وهيظهر في «كودي» أول ما يتوافق عليه.</div>
<?php } else { ?>
  <div class="noon-box">
    <h3 style="margin:0 0 6px;font-size:17px;font-weight:900">🟡 ابعت كود الكوبون بتاعك من نون</h3>
    <?php if (!empty($codes) && in_array((string)($codes[0]['status'] ?? ''), ['stopped', 'rejected', 'deleted'], true) && (string)($codes[0]['reject_note'] ?? '') !== ''): ?>
      <div class="noon-notice" style="margin-top:10px">الكود السابق اتوقف/اترفض/اتحذف: <?php echo esc((string)$codes[0]['reject_note']); ?></div>
    <?php endif; ?>
    <form method="post" class="row-actions">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="act" value="request">
      <label class="field-label" for="noonCode">🏷️ كود الكوبون من نون</label>
      <input id="noonCode" name="noon_code" type="text" required maxlength="40" dir="ltr" placeholder="مثال: AHMED10" pattern="[A-Za-z0-9_.\-]{3,40}">
      <label class="field-label" for="noonNote">ملاحظة للإدارة (اختياري)</label>
      <input id="noonNote" name="note" type="text" maxlength="300">
      <button class="btn" type="submit">📨 ابعت الكود للإدارة</button>
    </form>
  </div>
<?php }
}

// ============================================================================
//  تبويب: كودي (كارت لكل كود معتمد + طلباتي)
// ============================================================================
elseif ($tab === 'code') {
    if (!$approved) { ?>
  <div class="noon-box" style="text-align:center;padding:36px"><div style="font-size:40px">🎟️</div>
    <h3 style="margin:8px 0">لسه مفيش كود معتمد</h3>
    <p class="mut"><?php echo $live !== null ? 'كودك ' . esc((string)$live['code']) . ' بانتظار موافقة الإدارة.' : 'ابعت كود الكوبون بتاعك من نون، وأول ما الإدارة توافق عليه هيظهر هنا.'; ?></p>
    <?php if ($live === null): ?><a class="btn" href="?tab=new">➕ طلب كود</a><?php endif; ?>
  </div>
<?php }
    foreach ($approved as $c) {
        $cid  = (string)$c['id'];
        $code = (string)$c['code'];
        $cst  = noon_stats_for_code($cid);
        $sh   = $share_of($c);
        $open = null; $last = null;
        foreach ((array)($c['appeals'] ?? []) as $ap) {
            if (($ap['status'] ?? '') === 'pending') $open = $ap; else $last = $ap;
        }
?>
  <div class="noon-box">
    <div class="noon-code"><?php echo esc($code); ?></div>
    <div class="noon-row">
      <span class="noon-tag g">✅ معتمد</span>
      <button class="btn sm outline" type="button" data-code="<?php echo esc($code); ?>" onclick="if(navigator.clipboard){navigator.clipboard.writeText(this.dataset.code);}this.textContent='✅ اتنسخ'">📋 نسخ الكود</button>
    </div>
    <div class="kpis" style="margin-top:12px">
      <div class="kpi"><b><?php echo number_format($cst['uses']); ?></b><span>🔁 مرات الاستخدام</span></div>
      <div class="kpi"><b><?php echo number_format($cst['sales'], 2); ?></b><span>🛒 قيمة البيعات (ج.م)</span></div>
      <div class="kpi"><b style="color:#d97706"><?php echo number_format($cst['pending'], 2); ?></b><span>⏳ معلق (ج.م)</span></div>
      <div class="kpi"><b style="color:#16a34a"><?php echo number_format($cst['confirmed'], 2); ?></b><span>✅ مؤكد (ج.م)</span></div>
    </div>
    <form method="post" class="row-actions" style="margin-top:12px" onsubmit="return confirm('إيقاف الكود؟ البيعات الجاية بيه مش هتتسجل')">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="act" value="stop">
      <input type="hidden" name="code_id" value="<?php echo esc($cid); ?>">
      <button class="btn sm outline" type="submit">🗑️ إيقاف الكود</button>
    </form>
  </div>
<?php }

    $others = array_values(array_filter($codes, function ($c) { return (string)($c['status'] ?? '') !== 'approved'; }));
    if ($others) { ?>
  <div class="noon-box">
    <h3 style="margin:0 0 10px;font-size:15px;font-weight:900">📨 طلباتي للإدارة</h3>
    <div class="table-wrap"><table class="data-table rtable">
      <thead><tr><th>الكود</th><th>الحالة</th><th>تاريخ الطلب</th><th>ملاحظة</th></tr></thead>
      <tbody>
<?php foreach ($others as $o):
        $lb = $code_lbl[(string)($o['status'] ?? '')] ?? ['—', ''];
?>
        <tr>
          <td data-label="الكود" dir="ltr"><b><?php echo esc((string)($o['code'] ?? '')); ?></b></td>
          <td data-label="الحالة"><span class="noon-tag <?php echo esc($lb[1]); ?>"><?php echo esc($lb[0]); ?></span></td>
          <td data-label="تاريخ الطلب"><?php echo esc(substr((string)($o['requested_at'] ?? ''), 0, 16)); ?></td>
          <td data-label="ملاحظة"><?php echo wf_det_text($o['reject_note'] ?? '', 30, 'ملاحظة الإدارة على الكود ' . (string)($o['code'] ?? ''), 'الملاحظة كاملة', '—'); ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php }
}

// ============================================================================
//  تبويب: مبيعاتي وعمولاتي
// ============================================================================
elseif ($tab === 'sales') {
    if (!$sales) { ?>
  <div class="noon-box" style="text-align:center;padding:36px"><div style="font-size:40px">🧾</div>
    <h3 style="margin:8px 0">لا توجد مبيعات مسجلة بعد</h3>
    <p class="mut">أول ما يتسجل استخدام لكودك على نون، البيعة بتظهر هنا ومعاها أرباحك.</p>
  </div>
<?php } else {
        $tot = 0.0;
        foreach ($sales as $s) { if ((string)($s['state'] ?? '') !== 'reversed') $tot += (float)($s['mk_egp'] ?? 0); }
?>
  <div class="noon-box">
    <div class="table-wrap"><table class="data-table rtable">
      <thead><tr><th>التاريخ</th><th>الكود</th><th>رقم الأوردر</th><th>قيمة البيع</th><th>أرباحك</th><th>الحالة</th></tr></thead>
      <tbody>
<?php foreach ($sales as $s): ?>
        <tr>
          <td data-label="التاريخ"><?php echo esc((string)($s['at'] ?? '')); ?></td>
          <td data-label="الكود" dir="ltr"><?php echo esc((string)($s['code'] ?? '')); ?></td>
          <td data-label="رقم الأوردر" dir="ltr"><?php echo esc((string)($s['order_no'] ?? '')); ?></td>
          <td data-label="قيمة البيع"><?php echo number_format((float)($s['sale'] ?? 0), 2); ?> ج.م</td>
          <td data-label="أرباحك"><?php echo number_format((float)($s['mk_egp'] ?? 0), 2); ?> ج.م</td>
          <td data-label="الحالة"><?php $stt = (string)($s['state'] ?? 'pending'); echo esc($sale_lbl[$stt] ?? $stt); ?></td>
        </tr>
<?php endforeach; ?>
        <tr><td colspan="4"><b>الإجمالي (بدون الملغية)</b></td><td colspan="2"><b><?php echo number_format($tot, 2); ?> ج.م</b></td></tr>
      </tbody>
    </table></div>
    <p class="mut" style="font-size:12.5px;margin:10px 0 0">⏳ يعني إيه معلقة؟ البيعة اتسجلت وأرباحها مستنية تأكيد الإدارة.</p>
  </div>
<?php }
}

// ============================================================================
//  تبويب: إزاي النظام بيشتغل
// ============================================================================
// ============================================================================
//  تبويب: منتجات (لينك المنتج → الإدارة بتحدد الفئة والنسبة → المسوق بيشوف ربحه على كل بيعة والكود)
// ============================================================================
elseif ($tab === 'customers') {
    $cl_prods = [];
    foreach (noon_products_for($email) as $pp) {
        if (($pp['status'] ?? '') === 'approved') $cl_prods[] = ['id' => (string)$pp['id'], 'label' => (string)$pp['pname'] . ' — ' . number_format(noon_product_calc($pp)['price'], 2) . ' ' . noon_product_cur_label($pp)];
    }
    $ac_   = noon_code_active_for($email);
    $ncode = ($ac_ && ($ac_['status'] ?? '') === 'approved') ? (string)$ac_['code'] : '';
    echo cust_marketer_html('noon', $email, $cl_prods, '?tab=customers', $ncode);
}
elseif ($tab === 'products') {
    $pl = noon_products_for($email);
    $kcode = $has_code ? (string)$approved[0]['code'] : '';
    $pstat = ['pending' => ['⏳ قيد المراجعة', 'y'], 'approved' => ['✅ معتمد', 'g'], 'rejected' => ['⛔ مرفوض', 'r']];
?>
  <div class="noon-box">
    <h3 style="margin:0 0 6px;font-size:17px;font-weight:900">🛒 ابعت لينك المنتج اللي هتسوقه</h3>
    <form method="post" class="row-actions" style="margin-top:10px">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="act" value="product_request">
      <label class="field-label" for="noonProdUrl">🔗 لينك المنتج من نون</label>
      <input id="noonProdUrl" name="product_url" type="url" required maxlength="500" dir="ltr" placeholder="https://www.noon.com/uae-en/.../p/">
      <label class="field-label" for="noonProdNote">ملاحظة للإدارة (اختياري)</label>
      <input id="noonProdNote" name="note" type="text" maxlength="300">
      <button class="btn" type="submit">📨 ابعت المنتج للإدارة</button>
    </form>
  </div>
  <?php if ($kcode === '') { ?>
    <div class="noon-notice" style="margin-top:12px">الكود اللي هتسوق بيه بيظهر هنا أول ما يتعتمد كودك. لو لسه ماعندكش كود، ابعته من تبويب «طلب كود».</div>
  <?php } ?>

  <div class="noon-box" style="margin-top:14px">
    <h3 style="margin:0 0 10px;font-size:16px;font-weight:900">📦 منتجاتك (<?php echo count($pl); ?>)</h3>
    <?php if (!$pl) { ?>
      <p class="mut">لسه ماضفتش منتجات. ابعت لينك أول منتج هتسوقه.</p>
    <?php } ?>
    <?php foreach ($pl as $pp) {
      $ps = (string)($pp['status'] ?? 'pending');
      [$pl_lbl, $pl_cls] = $pstat[$ps] ?? [$ps, ''];
    ?>
      <div class="noon-prod" style="border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:10px;background:#fff">
        <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">
          <b><?php echo esc($ps === 'approved' && $pp['pname'] !== '' ? (string)$pp['pname'] : 'منتج نون'); ?></b>
          <span class="noon-tag <?php echo esc($pl_cls); ?>"><?php echo esc($pl_lbl); ?></span>
        </div>
        <div class="mut" dir="ltr" style="font-size:12px;word-break:break-all;margin-top:4px"><?php echo esc((string)$pp['url']); ?></div>
        <?php $pmeta = (isset($pp['meta']) && is_array($pp['meta'])) ? $pp['meta'] : []; ?>
        <?php if ($ps === 'approved') {
          $pc = noon_product_calc($pp);
        ?>
          <div style="display:flex;gap:12px;align-items:flex-start;margin-top:8px;flex-wrap:wrap">
            <?php if (!empty($pmeta['img'])) { ?><img src="<?php echo esc((string)$pmeta['img']); ?>" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:72px;height:72px;object-fit:contain;border:1px solid #e2e8f0;border-radius:10px;background:#fff"><?php } ?>
            <div style="flex:1;min-width:200px;font-size:13px;line-height:1.9">
              📂 الفئة: <b><?php echo esc(noon_product_cat_label((string)$pp['category'])); ?></b><br>
              <?php if ((float)$pc['price'] > 0) { ?>💵 السعر: <b><?php echo number_format((float)$pc['price'], 2) . ' ' . esc(noon_product_cur_label($pp)); ?></b><?php } ?>
            </div>
          </div>
          <div style="margin-top:8px;font-size:14px;font-weight:800;color:#16a34a">هتاخد <?php echo number_format($pc['egp'], 2); ?> ج.م على كل بيعة</div>
          <?php echo noon_product_panel_html($pmeta); ?>
          <div style="margin-top:8px;font-size:13px">🎟️ الكود اللي هتسوق بيه: <?php if ($kcode !== '') { ?><b dir="ltr"><?php echo esc($kcode); ?></b> <button type="button" class="btn sm outl" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?php echo esc($kcode); ?>');this.textContent='✅ اتنسخ'">📋 نسخ</button><?php } else { ?><span class="mut">لسه ماعندكش كود معتمد</span><?php } ?></div>
        <?php } elseif ($ps === 'rejected') { ?>
          <div class="alert danger" role="alert" style="margin-top:8px">⛔ <b>المنتج اترفض</b> — <?php echo wf_det_text($pp['reject_note'] ?? '', 60, 'سبب رفض المنتج', 'السبب كامل', 'من غير سبب'); ?></div>
        <?php } else { ?>
          <div class="mut" style="margin-top:6px;font-size:13px">الإدارة هتراجع بيانات المنتج، وهيوصلك إشعار لما يتعتمد.</div>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
<?php }

else { ?>
  <div class="noon-box">
    <h3 style="margin:0 0 10px;font-size:16px;font-weight:900">❓ إزاي نظام أفلييت نون بيشتغل هنا؟</h3>
    <p class="noon-help" style="font-size:13px;color:#334155;line-height:1.9;margin:0">انت بتسوّق الكود، وأي عملية بتتم باستخدام الكود بتاخد عمولتك عليها.</p>
  </div>
<?php }

layout_end();
