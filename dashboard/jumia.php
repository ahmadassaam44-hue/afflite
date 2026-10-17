<?php
// ============================================================================
//  🛍️ لوحة أفلييت جوميا للمسوق
//  المسوق يبعت طلب منتج من جوميا → الإدارة توافق وتحدد السعر ونسبة المسوق ولينك التتبع
//  → بعد الموافقة يظهر رابط التسويق في «الروابط المعتمدة» ومعاه المبلغ اللي هياخده على كل بيعة
// ============================================================================
require 'layout.php';

if (!empty($user['email']) && ($user['role'] ?? '') !== 'admin') {
    $me = ensure_user_affiliate($user['email']);
} else {
    $me = find_user($user['email'] ?? '');
}
if (!$me) { header('Location: ./'); exit; }
$code = (string)($me['ref_code'] ?? '');
if ($code === '') { header('Location: ./'); exit; }

// ======================== تحميل الصور / الفيديو ========================
// ?dl=1&lid=…&k=img|vid&i=N  → من رابط محفوظ (المسوق بتاعه أو الإدارة بس)
// ?dl=1&pf=1&k=img|vid&i=N   → من آخر «جلب بيانات المنتج» في الجلسة دي (صالح ساعة)
if (isset($_GET['dl'])) {
    $dl_kind = ((string)($_GET['k'] ?? '') === 'vid') ? 'vid' : 'img';
    $dl_idx  = max(0, (int)($_GET['i'] ?? 0));
    $dl_pid = '';
    $dl_list = [];
    if (isset($_GET['pf'])) {
        $dl_pf = $_SESSION['jmi_pf'] ?? null;
        if (is_array($dl_pf) && (int)($dl_pf['exp'] ?? 0) > time()) {
            $dl_pid = (string)($dl_pf['pid'] ?? '');
            $dl_list = jmi_media_list($dl_pf, $dl_kind);
        }
    } else {
        $dl_lid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['lid'] ?? ''));
        $dl_L   = $dl_lid !== '' ? jmi_link($dl_lid) : null;
        if ($dl_L && ((string)($dl_L['ref'] ?? '') === $code || is_admin_user())) {
            $dl_pid = (string)($dl_L['pid'] ?? '');
            $dl_list = jmi_media_list($dl_L, $dl_kind);
        }
    }
    if (!isset($dl_list[$dl_idx])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'الملف ده مش متاح للتحميل';
        exit;
    }
    jmi_stream_media($dl_list[$dl_idx], jmi_dl_name($dl_pid, $dl_kind, $dl_idx, $dl_list[$dl_idx]));
    exit;
}

// لوحة «الوصف والمواصفات والصور والفيديو» + أزرار التحميل (بتتعرض في الفورم وفي الكروت)
// $qs = 'pf=1' أو 'lid=…'  |  $quiet = true يخفي اللوحة لو مفيش بيانات
function jmi_media_panel_html(array $d, $qs, $open = false, $quiet = true) {
    $desc  = trim((string)($d['desc'] ?? ''));
    $specs = is_array($d['specs'] ?? null) ? $d['specs'] : [];
    $imgs  = jmi_media_list($d, 'img');
    $vids  = jmi_media_list($d, 'vid');
    if ($desc === '' && !$specs && !$imgs && !$vids) {
        if ($quiet) return '';
        return '<div class="jmi-notice" style="margin:12px 0 0">ℹ️ جوميا مرجعتش وصف ولا مواصفات ولا صور ولا فيديو للمنتج ده. تقدر تكتب البيانات يدوياً في الملاحظة.</div>';
    }
    $counts = [];
    if ($desc !== '') $counts[] = 'الوصف';
    if ($specs) $counts[] = count($specs) . ' مواصفة';
    if ($imgs) $counts[] = count($imgs) . ' صورة';
    if ($vids) $counts[] = count($vids) . ' فيديو';
    $h = '<details class="jmi-media"' . ($open ? ' open' : '') . '>'
       . '<summary>📦 ' . esc(implode(' · ', $counts)) . '</summary>';
    if ($desc !== '') {
        $h .= '<div class="jmi-desc">';
        foreach (explode("\n", $desc) as $dl_line) {
            // كل سطر باتجاهه (عربي أو إنجليزي) عشان النص المختلط ميتقلبش
            $h .= $dl_line === '' ? '<div class="jmi-gap"></div>' : '<div dir="auto">' . esc($dl_line) . '</div>';
        }
        $h .= '</div>';
    }
    if ($specs) {
        $h .= '<table class="jmi-specs">';
        foreach ($specs as $row) {
            $h .= '<tr><th dir="auto">' . esc((string)($row['k'] ?? '')) . '</th><td dir="auto">' . esc((string)($row['v'] ?? '')) . '</td></tr>';
        }
        $h .= '</table>';
    }
    if ($imgs) {
        $h .= '<div class="jmi-imgs">';
        foreach ($imgs as $i => $u) {
            $h .= '<div class="jmi-imgcard"><img src="' . esc($u) . '" alt="" loading="lazy" referrerpolicy="no-referrer">'
                . '<a class="btn sm" href="' . esc('?dl=1&' . $qs . '&k=img&i=' . $i) . '">⬇️ تحميل الصورة ' . ($i + 1) . '</a></div>';
        }
        $h .= '</div>';
    }
    if ($vids) {
        $h .= '<div class="jmi-row">';
        foreach ($vids as $i => $u) {
            $h .= '<a class="btn sm" href="' . esc('?dl=1&' . $qs . '&k=vid&i=' . $i) . '">🎬 تحميل الفيديو' . (count($vids) > 1 ? ' ' . ($i + 1) : '') . '</a>';
        }
        $h .= '</div>';
    }
    return $h . '</details>';
}

$msg = ''; $err = ''; $result = null; $prefill = []; $fetched = null;

if (!jmi_enabled()) {
    layout_top('أفلييت جوميا 🛍️', 'jumia');
    echo '<div class="card" style="text-align:center;padding:40px"><div style="font-size:48px">🛍️</div>'
       . '<h2>نظام أفلييت جوميا موقوف حالياً</h2><p class="mut">تواصل مع الإدارة لتفعيله.</p>'
       . '<a class="btn" href="marketer.php">رجوع للوحة المسوق</a></div>';
    layout_end();
    exit;
}

// ======================== معالجة الطلبات ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');

    // 1) إرسال طلب منتج (مفيش رابط تسويق ولا أي نسبة/ربح قبل موافقة الإدارة)
    if ($act === 'create') {
        $res = jmi_create_link($code, (string)($_POST['jmi_url'] ?? ''), [
            'cat'   => (string)($_POST['cat'] ?? 'other'),
            'title' => (string)($_POST['title'] ?? ''),
            'price' => (string)($_POST['price'] ?? ''),
            'img'   => (string)($_POST['img'] ?? ''),
            'note'  => (string)($_POST['note'] ?? ''),
            'desc'   => (string)($_POST['desc'] ?? ''),
            'specs'  => (string)($_POST['specs'] ?? ''),
            'images' => (string)($_POST['images'] ?? ''),
            'videos' => (string)($_POST['videos'] ?? ($_POST['video'] ?? '')),
        ]);
        if (!empty($res['ok'])) {
            $result = $res;
            if (!empty($res['existing']) && (string)($res['status'] ?? '') === 'approved') {
                $msg = '✅ الرابط ده معتمد عندك بالفعل — لاقيته في «الروابط المعتمدة»';
            } elseif (!empty($res['existing'])) {
                $msg = '⏳ طلب المنتج ده مبعوت قبل كده وبانتظار موافقة الإدارة';
            } else {
                $msg = !empty($res['resubmitted']) ? '📨 اتبعت طلب المنتج تاني للإدارة' : '📨 اتبعت طلب المنتج للإدارة بنجاح';
            }
        } else {
            $err = (string)($res['error'] ?? 'تعذر إرسال الطلب');
            $prefill = [
                'url'   => (string)($_POST['jmi_url'] ?? ''),
                'cat'   => (string)($_POST['cat'] ?? 'other'),
                'title' => (string)($_POST['title'] ?? ''),
                'price' => (string)($_POST['price'] ?? ''),
                'img'    => (string)($_POST['img'] ?? ''),
                'desc'   => (string)($_POST['desc'] ?? ''),
                'specs'  => (string)($_POST['specs'] ?? ''),
                'images' => (string)($_POST['images'] ?? ''),
                'videos' => (string)($_POST['videos'] ?? ($_POST['video'] ?? '')),
            ];
        }
    }

    // 2) جلب بيانات المنتج من جوميا (اختياري)
    if ($act === 'fetch') {
        $u = (string)($_POST['jmi_url'] ?? '');
        $prefill = ['url' => $u, 'cat' => (string)($_POST['cat'] ?? 'other'), 'title' => '', 'price' => ''];
        if (rate_limit_check('jmi_fetch', 25, 3600) > 0) {
            $err = 'محاولات كتير — استنى شوية وجرّب تاني (أو اكتب بيانات المنتج يدوياً)';
        } else {
            $meta = jmi_fetch_meta($u);
            if (!$meta) {
                $err = 'مقدرتش أجيب بيانات المنتج تلقائياً من جوميا — اكتب اسم المنتج وسعره يدوياً ✅';
            } else {
                $prefill['title'] = (string)($meta['title'] ?? '');
                $prefill['price'] = isset($meta['price']) ? (string)$meta['price'] : '';
                $prefill['img']    = (string)($meta['img'] ?? '');
                $prefill['desc']   = (string)($meta['desc'] ?? '');
                $prefill['specs']  = $meta['specs'] ?? [];
                $prefill['images'] = $meta['images'] ?? [];
                $prefill['videos'] = $meta['videos'] ?? [];
                if (!empty($meta['cat'])) $prefill['cat'] = (string)$meta['cat'];   // القسم بيتعرف تلقائياً من جوميا
                $fetched = $meta;
                $fetched_cur = jmi_currency(jmi_detect_marketplace($u));   // عملة المتجر من الرابط
                $_SESSION['jmi_pf'] = [
                    'pid'   => jmi_extract_pid($u),
                    'img'    => (string)($meta['img'] ?? ''),
                    'images' => $meta['images'] ?? [],
                    'videos' => $meta['videos'] ?? [],
                    'exp'    => time() + 3600,
                ];
                $msg = '✅ تم جلب بيانات المنتج — راجعها واضغط "أرسل طلب المنتج"';
            }
        }
    }

    // 3) حذف رابط بتاعي (أو سحب طلب لسه بانتظار الإدارة)
    // 👤 طلب عميل: المسوق بيختار رابط معتمد له، ويبعت بيانات العميل (الإدارة تطلب المنتج، أو تتابع)
    if ($act === 'cust_request') {
        $pref = (string)($_POST['product_ref'] ?? '');
        $plabel = '';
        foreach (jmi_links_for($code) as $lk => $lnk) {
            $lid = (string)($lnk['id'] ?? $lk);
            if ($lid === $pref && (string)($lnk['status'] ?? 'approved') === 'approved') {
                $plabel = (string)($lnk['title'] ?? '') !== '' ? (string)$lnk['title'] : ('رقم المنتج ' . (string)($lnk['pid'] ?? ''));
            }
        }
        if ($plabel === '') {
            $err = 'اختار منتج معتمد من روابطك';
        } else {
            $res = cust_req_create('jumia', (string)$me['email'], $plabel, $pref, cust_post_data());
            if (!empty($res['ok'])) $msg = '📨 اتبعت طلب العميل للإدارة، هيوصلك إشعار لما يتنفذ';
            else $err = (string)($res['error'] ?? 'تعذر إرسال الطلب');
        }
    }

    if ($act === 'delete') {
        $lid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['link_id'] ?? ''));
        $l = $lid !== '' ? jmi_link($lid) : null;
        if ($l && (string)($l['ref'] ?? '') === $code) {
            jmi_link_delete($lid);
            $msg = '🗑️ تم حذف الرابط';
        } else {
            $err = 'الرابط غير موجود أو مش بتاعك';
        }
    }
    // 4) تظلم على نسبة المسوق (طلب زيادتها) على رابط معتمد — الإدارة هي اللي بتقرر
    if ($act === 'pct_appeal') {
        $lid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['link_id'] ?? ''));
        $res = jmi_pct_appeal_create($code, $lid, is_string($_POST['note'] ?? null) ? $_POST['note'] : '');
        if (!empty($res['ok'])) {
            $msg = '📨 اتبعت التظلم للإدارة — هيظهر في «طلباتي للإدارة» لحد ما يتراجع، وهيوصلك إشعار بالرد';
        } else {
            $err = (string)($res['error'] ?? 'تعذر إرسال التظلم');
        }
    }
}

$tab = (string)($_GET['tab'] ?? 'new');
if (!in_array($tab, ['new', 'links', 'customers', 'conversions', 'help'], true)) $tab = 'new';

$my_links  = jmi_links_for($code);
uasort($my_links, function ($a, $b) { return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')); });
$approved  = array_filter($my_links, function ($l) { return (string)($l['status'] ?? 'approved') === 'approved'; });
$my_orders = jmi_orders_for($code);
$st        = jmi_stats_for($code);
$money     = jmi_money_by_link();
$my_reqs   = array_filter(jmi_reqs_all(), function ($r) use ($code) {
    return (string)($r['ref'] ?? '') === $code && in_array((string)($r['type'] ?? ''), ['approval'], true);
});
uasort($my_reqs, function ($a, $b) { return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')); });
$req_open = count(array_filter($my_reqs, function ($r) { return (string)($r['status'] ?? '') === 'pending'; }));

layout_top('أفلييت جوميا 🛍️', 'jumia');
?>
<style>
.jmi-hero{background:linear-gradient(135deg,#0f172a,#232f3e);color:#fff;border-radius:18px;padding:20px 22px;margin-bottom:14px}
.jmi-hero b{color:#ff9900}
.jmi-box{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;margin-bottom:14px}
.jmi-url{dir:ltr;text-align:left;font-size:12px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;width:100%;box-sizing:border-box;overflow-x:auto;white-space:nowrap}
.jmi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}
.jmi-grid4{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}
.jmi-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;text-align:center}
.jmi-stat b{display:block;font-size:22px;font-weight:900;color:#0f172a}
.jmi-stat span{font-size:12px;color:#64748b;font-weight:700}
.jmi-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
.jmi-thumb{width:52px;height:52px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc}
.jmi-pct-box{margin-top:12px}
.jmi-pct-line{font-size:12.5px;margin-top:8px;font-weight:700}
.jmi-appeal{margin-top:10px;border:1px dashed #fde68a;border-radius:12px;padding:10px 12px;background:#fffbeb}
.jmi-appeal summary{cursor:pointer;font-size:12.5px;font-weight:800}
.jmi-appeal-form{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:10px}
.jmi-appeal-form label{font-size:11px;font-weight:800}
.jmi-appeal-form label.grow{flex:1;min-width:200px}
.jmi-appeal-form input{padding:7px 9px;border:1px solid #cbd5e1;border-radius:8px;font-size:12.5px;background:#fff}
.jmi-tag{display:inline-block;font-size:11px;font-weight:800;padding:3px 9px;border-radius:99px;background:#fff7ed;color:#b45309;border:1px solid #fed7aa}
.jmi-tag.g{background:#dcfce7;color:#15803d;border-color:#86efac}
.jmi-tag.r{background:#fee2e2;color:#b91c1c;border-color:#fecaca}
.jmi-tag.b{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.jmi-tag.y{background:#fef3c7;color:#b45309;border-color:#fde68a}
.jmi-note{font-size:12.5px;line-height:1.9;color:#475569}
.jmi-note li{margin-bottom:6px}
.jmi-notice{background:#fffbeb;border:1px dashed #f59e0b;color:#92400e;border-radius:12px;padding:12px 14px;font-size:13px;font-weight:700;line-height:1.9;margin:0 0 14px}
.jmi-per{background:#ecfdf5;border:1px solid #86efac;border-radius:14px;padding:14px 16px;margin:14px 0 0;font-size:13px;font-weight:700;color:#065f46;line-height:1.9}
.jmi-per-big{font-size:15px;font-weight:900;color:#14532d}
.jmi-lhead{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.jmi-media{margin-top:12px;border-top:1px dashed #e2e8f0;padding-top:10px}
.jmi-media>summary{cursor:pointer;font-size:13px;font-weight:900;color:#0f172a}
.jmi-desc{font-size:12.5px;line-height:1.9;color:#334155;margin:10px 0;max-height:280px;overflow:auto}
.jmi-gap{height:8px}
.jmi-specs{width:100%;font-size:12px;border-collapse:collapse;margin:8px 0}
.jmi-specs th,.jmi-specs td{border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:start;vertical-align:top}
.jmi-specs th{width:38%;color:#475569;font-weight:800;background:#f8fafc}
.jmi-imgs{display:grid;grid-template-columns:repeat(auto-fill,minmax(136px,1fr));gap:10px;margin-top:8px}
.jmi-imgcard{border:1px solid #e2e8f0;border-radius:12px;padding:8px;text-align:center;background:#fff}
.jmi-imgcard img{width:100%;height:112px;object-fit:contain;border-radius:8px;background:#f8fafc}
.jmi-imgcard .btn{margin-top:6px;font-size:11.5px}
</style>

<div class="jmi-hero">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <div style="font-size:19px;font-weight:900">🛍️ أفلييت جوميا — ابعت طلب المنتج</div>
      <div style="font-size:12.5px;color:#cbd5e1;margin-top:4px">
        الرابط المعتمد بيظهر في <b>الروابط المعتمدة</b> ومعاه النقرات والمبيعات والأرباح.
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn sm" style="background:#ff9900;color:#111" href="?tab=new">➕ طلب منتج جديد</a>
      <a class="btn sm outline" style="color:#fff;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.4)" href="?tab=links">🔗 الروابط المعتمدة (<?php echo count($approved); ?>)</a>
      <a class="btn sm outline" style="color:#fff;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.4)" href="marketer.php">📣 لوحة المسوق</a>
    </div>
  </div>
</div>

<?php if ($msg):  echo '<div class="card" style="background:#dcfce7;border-left:4px solid #16a34a;color:#15803d;font-weight:800;margin-bottom:12px">' . esc($msg) . '</div>'; endif; ?>
<?php if ($err):  echo '<div class="card" style="background:#fee2e2;border-left:4px solid #dc2626;color:#b91c1c;font-weight:800;margin-bottom:12px">' . esc($err) . '</div>'; endif; ?>

<div class="kpis">
  <div class="kpi"><b><?php echo number_format(count($approved)); ?></b><span>🔗 روابط معتمدة</span></div>
  <div class="kpi"><b><?php echo number_format($st['clicks']); ?></b><span>👆 نقرات على روابطك</span></div>
  <div class="kpi"><b><?php echo number_format($st['orders']); ?></b><span>🛒 بيعات محققة</span></div>
  <div class="kpi"><b style="color:#16a34a"><?php echo number_format($st['confirmed_egp'], 2); ?></b><span>✅ أرباحك المؤكدة (ج.م)</span></div>
  <div class="kpi"><b style="color:#d97706"><?php echo number_format($st['pending_egp'], 2); ?></b><span>⏳ أرباحك المعلقة (ج.م)</span></div>
  <div class="kpi"><b><?php echo number_format($req_open); ?></b><span>📨 طلبات بانتظار الموافقة</span></div>
</div>

<div class="tabs">
  <a href="?tab=new"<?php echo $tab === 'new' ? ' class="on"' : ''; ?>>➕ طلب منتج</a>
  <a href="?tab=links"<?php echo $tab === 'links' ? ' class="on"' : ''; ?>>🔗 الروابط المعتمدة (<?php echo count($approved); ?>)</a>
  <a href="?tab=customers"<?php echo $tab === 'customers' ? ' class="on"' : ''; ?>>👤 طلبات العملاء (<?php echo count(cust_reqs_for((string)$me['email'], 'jumia')); ?>)</a>
  <a href="?tab=conversions"<?php echo $tab === 'conversions' ? ' class="on"' : ''; ?>>🧾 مبيعاتي وعمولاتي (<?php echo count($my_orders); ?>)</a>
  <a href="?tab=help"<?php echo $tab === 'help' ? ' class="on"' : ''; ?>>❓ إزاي النظام بيشتغل</a>
</div>

<?php
// ============================================================================
//  تبويب: طلب منتج جديد
// ============================================================================
if ($tab === 'new') {
    $cats = jmi_categories();
    $pv = function ($k, $d = '') use ($prefill) { return isset($prefill[$k]) ? (string)$prefill[$k] : $d; };
?>
<div class="jmi-box">
  <h3 style="margin:0 0 6px;font-size:17px;font-weight:900">📥 الصق لينك المنتج من جوميا</h3>
  <p class="mut" style="font-size:12.5px;margin:0 0 12px">افتح المنتج على جوميا → انسخ اللينك من شريط العنوان → الصقه هنا.</p>
  <div class="jmi-notice">📨 أرسل طلب المنتج فقط وتوافق قبل إظهار رابط التسويق.</div>
  <form method="post">
    <input type="hidden" name="act" value="create">
    <?php foreach (['desc', 'specs', 'images', 'videos'] as $hk):
        $hv = $prefill[$hk] ?? ''; ?>
    <input type="hidden" name="<?php echo $hk; ?>" value="<?php echo esc(is_string($hv) ? $hv : (string)json_encode($hv, JSON_UNESCAPED_UNICODE)); ?>">
    <?php endforeach; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">
      <label style="grid-column:1/-1;font-size:12.5px;font-weight:800">
        🔗 لينك المنتج من جوميا *
        <input name="jmi_url" required dir="ltr" style="width:100%;padding:12px;margin-top:5px;border:1px solid #cbd5e1;border-radius:10px"
               placeholder="https://www.jumia.com.eg/اسم-المنتج-123ABC.html" value="<?php echo esc($pv('url')); ?>">
      </label>
      <label style="font-size:12.5px;font-weight:800">🏷️ قسم المنتج
        <select name="cat" style="width:100%;padding:12px;margin-top:5px;border:1px solid #cbd5e1;border-radius:10px">
          <?php foreach ($cats as $slug => $c): ?>
            <option value="<?php echo esc($slug); ?>"<?php echo $pv('cat', 'other') === $slug ? ' selected' : ''; ?>><?php echo esc($c[0]); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:12.5px;font-weight:800">📝 اسم المنتج (اختياري)
        <input name="title" style="width:100%;padding:12px;margin-top:5px;border:1px solid #cbd5e1;border-radius:10px"
               placeholder="مثال: سماعة بلوتوث JBL" value="<?php echo esc($pv('title')); ?>">
      </label>
      <label style="font-size:12.5px;font-weight:800">💵 سعر المنتج (اختياري)
        <input name="price" inputmode="decimal" style="width:100%;padding:12px;margin-top:5px;border:1px solid #cbd5e1;border-radius:10px"
               placeholder="مثال: 300" value="<?php echo esc($pv('price')); ?>">
      </label>
      <label style="font-size:12.5px;font-weight:800">🖼️ رابط صورة المنتج (اختياري)
        <input name="img" dir="ltr" style="width:100%;padding:12px;margin-top:5px;border:1px solid #cbd5e1;border-radius:10px"
               placeholder="https://m.media-jumia.com/..." value="<?php echo esc($pv('img')); ?>">
      </label>
      <label style="grid-column:1/-1;font-size:12.5px;font-weight:800">💬 ملاحظة للإدارة (اختياري)
        <textarea name="note" rows="2" style="width:100%;padding:10px;margin-top:5px;border:1px solid #cbd5e1;border-radius:10px"
                  placeholder="مثال: المنتج ده هروّج له في فيسبوك"><?php echo esc($pv('note')); ?></textarea>
      </label>
    </div>
    <div class="jmi-row">
      <button class="btn" type="submit">📨 أرسل طلب المنتج للإدارة</button>
      <button class="btn outline sm" type="submit" name="act" value="fetch" formnovalidate>🔄 جلب بيانات المنتج تلقائياً</button>
      <span class="mut" style="font-size:11.5px">لازم تحط اللينك الأول قبل زر "جلب بيانات".</span>
    </div>
  </form>
</div>

<?php if (!empty($fetched)): ?>
<div class="jmi-box" style="border:2px solid #16a34a;background:#f0fdf4">
  <div class="jmi-lhead">
    <?php if (!empty($fetched['img'])): ?>
      <img src="<?php echo esc((string)$fetched['img']); ?>" alt="" class="jmi-thumb" style="width:66px;height:66px" referrerpolicy="no-referrer">
    <?php endif; ?>
    <div style="flex:1;min-width:240px">
      <div style="font-size:15px;font-weight:900"><?php echo esc((string)(($fetched['title'] ?? '') !== '' ? $fetched['title'] : 'منتج جوميا')); ?></div>
      <div class="jmi-row" style="gap:6px">
        <span class="jmi-tag b">🏷️ القسم: <?php echo esc(jmi_cat_label((string)($prefill['cat'] ?? 'other'))); ?><?php echo !empty($fetched['cat']) ? ' — اتعرف تلقائياً' : ''; ?></span>
        <?php if (!empty($fetched['price'])): ?><span class="jmi-tag g">💰 <?php echo esc(jmi_money((float)$fetched['price'], (string)$fetched_cur)); ?></span><?php endif; ?>
      </div>
    </div>
  </div>
  <?php echo jmi_media_panel_html($fetched, 'pf=1', true, false); ?>
  <div class="mut" style="font-size:11.5px;margin-top:10px">لو عايز تغيّر أي حاجة قبل الإرسال عدّلها في الفورم فوق. أزرار التحميل بتشتغل لمدة ساعة من وقت الجلب.</div>
</div>
<?php endif; ?>
<?php if ($result && !empty($result['ok'])):
    $RL = jmi_link((string)($result['id'] ?? '')) ?: (array)($result['link'] ?? []);
    $r_approved = (string)($RL['status'] ?? 'pending') === 'approved';
?>
<div class="jmi-box" style="border:2px solid #ff9900;background:#fffaf0">
  <div class="jmi-lhead">
    <?php if (!empty($RL['img'])): ?>
      <img src="<?php echo esc((string)$RL['img']); ?>" alt="" class="jmi-thumb" style="width:66px;height:66px" referrerpolicy="no-referrer">
    <?php endif; ?>
    <div style="flex:1;min-width:240px">
      <div style="font-size:16px;font-weight:900"><?php echo esc(($RL['title'] ?? '') !== '' ? (string)$RL['title'] : ('منتج جوميا ' . (string)($RL['pid'] ?? ''))); ?></div>
      <div class="jmi-row" style="gap:6px">
        <span class="jmi-tag b"><?php echo esc(jmi_mkt_label((string)($RL['mkt'] ?? 'eg'))); ?></span>
        <span class="jmi-tag"><?php echo esc(jmi_cat_label((string)($RL['cat'] ?? 'other'))); ?></span>
        <span class="jmi-tag <?php echo $r_approved ? 'g' : 'y'; ?>"><?php echo $r_approved ? '✅ معتمد' : '⏳ بانتظار موافقة الإدارة'; ?></span>
      </div>
    </div>
  </div>
  <?php if (!$r_approved): ?>
    <div class="jmi-notice" style="margin-top:14px;margin-bottom:0">
      ⏳ طلبك اتبعت للإدارة.
    </div>
  <?php else: ?>
    <div class="jmi-notice" style="margin-top:14px;margin-bottom:0">✅ الرابط ده اتعتمد — الروابط والأرقام موجودة في «الروابط المعتمدة».</div>
  <?php endif; ?>
  <?php echo jmi_media_panel_html($RL, 'lid=' . preg_replace('/[^a-zA-Z0-9]/', '', (string)($result['id'] ?? ''))); ?>
  <div class="jmi-row" style="margin-top:12px"><a class="btn sm" href="?tab=links">🔗 افتح الروابط المعتمدة</a></div>
</div>
<?php endif; ?>
<?php
}

// ============================================================================
//  تبويب: الروابط المعتمدة (كروت لكل رابط + طلباتي)
// ============================================================================
elseif ($tab === 'links') {
    if (!$approved) {
        echo '<div class="jmi-box" style="text-align:center;padding:36px"><div style="font-size:40px">🔗</div>'
           . '<h3 style="margin:8px 0">لسه مفيش روابط معتمدة</h3>'
           . '<p class="mut">ابعت طلب منتج، أول ما الإدارة توافق رابط التسويق بيظهر هنا.</p>'
           . '<a class="btn" href="?tab=new">➕ ابعت طلب منتج</a></div>';
    }
    foreach ($approved as $lid => $L) {
        $track  = jmi_track_url((string)$lid);
        $ps     = jmi_per_sale($L);
        $mon    = $money[(string)$lid] ?? ['sales' => 0, 'qty' => 0, 'pending' => 0, 'confirmed' => 0];
        $cur    = (string)($L['currency'] ?? 'EGP');
        $idk    = preg_replace('/[^a-zA-Z0-9]/', '', (string)$lid);
?>
<div class="jmi-box jmi-lcard">
  <div class="jmi-lhead">
    <?php if (!empty($L['img'])): ?>
      <img src="<?php echo esc((string)$L['img']); ?>" alt="" class="jmi-thumb" style="width:64px;height:64px" referrerpolicy="no-referrer">
    <?php else: ?>
      <div class="jmi-thumb" style="display:flex;align-items:center;justify-content:center;font-size:22px;width:64px;height:64px">🛍️</div>
    <?php endif; ?>
    <div style="flex:1;min-width:220px">
      <div style="font-size:15px;font-weight:900"><?php echo esc(($L['title'] ?? '') !== '' ? (string)$L['title'] : ('منتج جوميا ' . (string)($L['pid'] ?? ''))); ?></div>
      <div class="jmi-row" style="gap:6px">
        <span class="jmi-tag b"><?php echo esc(jmi_mkt_label((string)($L['mkt'] ?? 'eg'))); ?></span>
        <span class="jmi-tag"><?php echo esc(jmi_cat_label((string)($L['cat'] ?? 'other'))); ?></span>
        <span class="jmi-tag g">✅ معتمد</span>
        <span class="jmi-tag" dir="ltr">رقم المنتج: <?php echo esc((string)($L['pid'] ?? '')); ?></span>
      </div>
    </div>
  </div>

  <div class="jmi-per">
    <?php if ($ps['ok']): ?>
      <div class="jmi-per-big">هتاخد <?php echo esc(number_format((float)$ps['amount'], 2)); ?> <?php echo esc(jmi_cur_label($cur)); ?> على كل بيعة<?php if ($cur !== 'EGP'): ?> <span class="mut" style="font-size:12px;font-weight:700">(≈ <?php echo esc(number_format((float)$ps['egp'], 2)); ?> ج.م)</span><?php endif; ?></div>
    <?php else: ?>
      <span class="mut">الإدارة بتحدد سعر المنتج — هيظهر هنا أول ما يتحدد.</span>
    <?php endif; ?>
  </div>

  <?php
    $pct_now  = jmi_mk_pct($L);
    $pct_open = jmi_pct_open_for_link((string)$lid);
    $idk_p    = preg_replace('/[^a-zA-Z0-9]/', '', (string)$lid);
  ?>
  <div class="jmi-grid4">
    <div class="jmi-stat"><b><?php echo number_format((int)($L['clicks'] ?? 0)); ?></b><span>👆 نقرات على الرابط<br><small class="mut"><?php echo number_format((int)($L['uniq'] ?? 0)); ?> زائر</small></span></div>
    <div class="jmi-stat"><b><?php echo number_format((int)$mon['sales']); ?></b><span>🛒 بيعات</span></div>
    <div class="jmi-stat"><b style="color:#d97706"><?php echo number_format((float)$mon['pending'], 2); ?></b><span>⏳ أرباحك المعلقة (ج.م)</span></div>
    <div class="jmi-stat"><b style="color:#16a34a"><?php echo number_format((float)$mon['confirmed'], 2); ?></b><span>✅ أرباحك المؤكدة (ج.م)</span></div>
  </div>

  <hr style="border:none;border-top:1px dashed #e2e8f0;margin:16px 0">

  <div style="font-size:12.5px;font-weight:800;margin-bottom:4px">🔗 رابط التتبع (ده اللي تسوّق بيه — بيحسب النقرات)</div>
  <div class="jmi-row">
    <input readonly class="jmi-url" style="flex:1;min-width:220px" id="jmi_t_<?php echo esc($idk); ?>" value="<?php echo esc($track); ?>">
    <button type="button" class="btn sm" onclick="navigator.clipboard.writeText(document.getElementById('jmi_t_<?php echo esc($idk); ?>').value);this.textContent='✅ نسخ'">📋 نسخ</button>
  </div>

  <?php echo jmi_media_panel_html($L, 'lid=' . preg_replace('/[^a-zA-Z0-9]/', '', (string)$lid)); ?>
  <div class="jmi-row" style="margin-top:14px;border-top:1px dashed #e2e8f0;padding-top:12px;justify-content:space-between">
    <form method="post" onsubmit="return confirm('حذف الرابط ده؟')" style="margin:0">
      <input type="hidden" name="act" value="delete"><input type="hidden" name="link_id" value="<?php echo esc((string)$lid); ?>">
      <button class="btn sm outline" style="color:#b91c1c">🗑️ حذف الرابط</button>
    </form>
  </div>
</div>
<?php
    }

    if ($my_reqs) {
        // كل طلب في كارت لوحده (زي كروت الروابط المعتمدة) بدل صف في جدول
        $smap = ['pending' => ['⏳ بانتظار موافقة الإدارة', 'y'], 'approved' => ['✅ اتوافق عليه', 'g'], 'rejected' => ['⛔ مرفوض', 'r']];
        echo '<div style="font-size:15px;font-weight:900;margin:18px 0 4px">📨 طلباتي للإدارة (' . count($my_reqs) . ')</div>';
        foreach ($my_reqs as $r) {
            $st    = (string)($r['status'] ?? 'pending');
            $sv    = $smap[$st] ?? $smap['pending'];
            $rlid  = preg_replace('/[^a-zA-Z0-9]/', '', (string)($r['link_id'] ?? ''));
            $rL    = $rlid !== '' ? jmi_link($rlid) : null;
            $rttl  = (string)($r['title'] ?? '') !== '' ? (string)$r['title'] : ('رقم المنتج ' . (string)($r['pid'] ?? ''));
            $rdate = str_replace('T', ' ', substr((string)($r['at'] ?? ''), 0, 16));
            echo '<div class="jmi-box jmi-lcard">';
            echo '<div class="jmi-lhead">';
            if ($rL && !empty($rL['img'])) {
                echo '<img src="' . esc((string)$rL['img']) . '" alt="" class="jmi-thumb" style="width:64px;height:64px" referrerpolicy="no-referrer">';
            } else {
                echo '<div class="jmi-thumb" style="display:flex;align-items:center;justify-content:center;font-size:22px;width:64px;height:64px">🛍️</div>';
            }
            echo '<div style="flex:1;min-width:220px">';
            echo '<div style="font-size:15px;font-weight:900">' . esc($rttl) . '</div>';
            echo '<div class="jmi-row" style="gap:6px">';
            if ($rL) echo '<span class="jmi-tag b">' . esc(jmi_mkt_label((string)($rL['mkt'] ?? 'eg'))) . '</span>';
            echo '<span class="jmi-tag">' . esc(jmi_cat_label((string)($r['cat'] ?? ''))) . '</span>';
            echo '<span class="jmi-tag ' . $sv[1] . '">' . esc($sv[0]) . '</span>';
            if ($rdate !== '') echo '<span class="jmi-tag" dir="ltr">' . esc($rdate) . '</span>';
            echo '</div>';
            if ($st === 'rejected' && (string)($r['admin_note'] ?? '') !== '') {
                echo '<div class="jmi-notice" style="margin:10px 0 0">ملاحظة الإدارة: ' . esc(mb_substr((string)$r['admin_note'], 0, 200)) . '</div>';
            }
            echo '</div></div>';
            // المنتج المعتمد بلوحته الكاملة في «الروابط المعتمدة» — هنا بس للطلبات اللي لسه بانتظار أو اترفضت
            if ($rL && $st !== 'approved') echo jmi_media_panel_html($rL, 'lid=' . $rlid);
            echo '</div>';
        }
    }
}

// ============================================================================
//  تبويب: مبيعاتي وعمولاتي
// ============================================================================
elseif ($tab === 'customers') {
    $cl_prods = [];
    foreach ($approved as $lk => $lnk) {
        $lid = (string)($lnk['id'] ?? $lk);
        $cl_prods[] = ['id' => $lid, 'label' => ((string)($lnk['title'] ?? '') !== '' ? (string)$lnk['title'] : ('رقم المنتج ' . (string)($lnk['pid'] ?? ''))) . ' (' . (string)($lnk['pid'] ?? '') . ')'];
    }
    echo cust_marketer_html('jumia', (string)$me['email'], $cl_prods, '?tab=customers');
}
elseif ($tab === 'conversions') {
    if (!$my_orders) {
        echo '<div class="jmi-box" style="text-align:center;padding:36px"><div style="font-size:40px">🧾</div>'
           . '<h3 style="margin:8px 0">لا توجد مبيعات مسجلة بعد</h3>'
           . '<p class="mut">أول ما عميل يشتري من لينك جوميا بتاعك، البيعة بتظهر هنا ومعاها أرباحك.</p></div>';
    } else {
        usort($my_orders, function ($a, $b) { return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')); });
        echo '<div class="jmi-box" style="padding:0;overflow:hidden"><table style="width:100%;font-size:12.5px">';
        echo '<tr><th>التاريخ</th><th>المنتج</th><th>الكمية</th><th>أرباحك</th><th>الحالة</th></tr>';
        $tot_mk = 0;
        foreach ($my_orders as $o) {
            $rev = ((string)($o['status'] ?? '') === 'reversed');
            if (!$rev) { $tot_mk += (float)($o['mk_egp'] ?? 0); }
            $smap = ['pending' => '⏳ معلقة', 'confirmed' => '✅ مؤكدة', 'reversed' => '↩️ ملغية'];
            echo '<tr' . ($rev ? ' style="opacity:.6"' : '') . '>';
            echo '<td dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($o['at'] ?? ''), 0, 16)) . '</td>';
            echo '<td>' . esc(mb_substr((string)($o['title'] ?? ''), 0, 34) ?: (string)($o['pid'] ?? '')) . '<br><span class="mut" style="font-size:11px" dir="ltr">' . esc((string)($o['pid'] ?? '')) . '</span></td>';
            echo '<td>' . number_format((int)($o['qty'] ?? 1)) . '</td>';
            echo '<td dir="ltr"><b style="color:#16a34a">' . esc(number_format((float)($o['mk_egp'] ?? 0), 2)) . '</b> ج.م</td>';
            echo '<td>' . esc($smap[(string)($o['status'] ?? 'pending')] ?? '⏳') . '</td>';
            echo '</tr>';
        }
        echo '<tr style="background:#f8fafc;font-weight:900"><td colspan="3">الإجمالي (بدون الملغية)</td>'
           . '<td dir="ltr" style="color:#16a34a">' . esc(number_format($tot_mk, 2)) . ' ج.م</td><td></td></tr>';
        echo '</table></div>';
        echo '<div class="jmi-box jmi-note" style="background:#f8fafc">';
        echo '<b>⏳ يعني إيه معلقة؟</b> البيعة اتسجلت وأرباحها مستنية تأكيد الإدارة.';
        echo '</div>';
    }
}

// ============================================================================
//  تبويب: الشرح
// ============================================================================
elseif ($tab === 'help') {
?>
<div class="jmi-box">
  <h3 style="margin:0 0 10px;font-size:16px;font-weight:900">❓ إزاي نظام أفلييت جوميا بيشتغل هنا؟</h3>
  <div class="jmi-note">
    <ol style="padding-right:18px">
      <li>افتح أي منتج على جوميا (مصر) وانسخ اللينك بتاعه.</li>
      <li>الصقه في تبويب <b>«طلب منتج»</b> واختار القسم، وابعته للإدارة.</li>
      <li>أول ما الإدارة توافق، الرابط بيظهر في <b>«الروابط المعتمدة»</b> ومعاه المبلغ اللي هتاخده على كل بيعة.</li>
      <li>كل نقرة بتتسجل تلقائياً، والمبيعات وأرباحك (معلقة / مؤكدة) بتظهر في كارت الرابط وفي <b>«مبيعاتي وعمولاتي»</b>.</li>
    </ol>
  </div>
</div>
<?php
}

layout_end();
