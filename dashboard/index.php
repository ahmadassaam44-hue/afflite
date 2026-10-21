<?php require 'layout.php';
/* ============================================================================
   «حسابي» — صفحة حساب العميل على الموقع الأساسي (حساب غير مسوّق).
   العميل شكله وشرائطه زي الزائر بالظبط (topbar + هيدر + نافبار المتجر)،
   والفرق إن معاه مميزات الحساب: تتبع الشحنة، التقييم والتعليقات،
   وأحدث العروض والأخبار. مسوّق هنا بيتحوّل للوحته، والمدير للوحة الإدارة.
   ========================================================================== */
$acct = current_user();
if (function_exists('is_admin_user') && is_admin_user()) { header('Location: admin.php'); exit; }
if (wf_is_marketer_account($acct)) { header('Location: marketer.php?mtab=performance'); exit; }
$acct_email = strtolower(trim((string)($acct['email'] ?? '')));

$acct_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $act = (string)($_POST['acct_action'] ?? '');
    if ($act === 'offers_on') {
        update_user($acct_email, function (&$u) { $u['offers'] = 1; });
        wf_offers_subscribe($acct_email, (string)($acct['name'] ?? ''), 'account');
        $acct_msg = '✅ تم تفعيل وصول أحدث العروض والأخبار على بريدك وإشعارات الموقع.';
    } elseif ($act === 'offers_off') {
        update_user($acct_email, function (&$u) { $u['offers'] = 0; });
        $acct_msg = 'تم إيقاف إرسال العروض على بريدك — تقدر ترجّعها في أي وقت من نفس الصفحة.';
    }
}

layout_top('حسابي', 'home');

// ===== طلبات العميل (نفس مصدر بيانات صفحة «طلباتي») =====
$acct_orders = array_values(array_filter(jload('orders.json', []), function ($o) use ($acct_email) {
    return is_array($o) && strcasecmp((string)($o['user'] ?? ''), $acct_email) === 0;
}));
usort($acct_orders, function ($a, $b) {
    return strcmp((string)($b['at'] ?? ($b['date'] ?? '')), (string)($a['at'] ?? ($a['date'] ?? '')));
});
$acct_open = array_values(array_filter($acct_orders, function ($o) {
    return !in_array(status_key($o['status'] ?? ''), ['delivered', 'cancelled', 'returned'], true);
}));
$acct_recent = array_slice($acct_open ? $acct_open : $acct_orders, 0, 3);
$acct_reviews = wf_customer_reviews($acct_email, 5);
$acct_news = wf_offers_news_items($acct_email, 5);
$acct_counts = wf_customer_perk_counts($acct_email);
$acct_offers_on = !array_key_exists('offers', (array)$acct) || !empty($acct['offers']);

// ===== ترحيب + مميزات الحساب =====
echo '<div class="card"><div data-ui="u-e883eee1 dh d1o d1p d1r"><div>';
echo '<h2 data-ui="u-67647882 d19">أهلاً ' . esc(mb_substr((string)($acct['name'] ?? 'عميلنا'), 0, 30)) . ' 👋</h2>';
echo '<p class="mut">حسابك على الموقع الأساسي شغّال — كل حاجة زي ما بتشتري كزائر، ومعاك كمان التقييم والتعليقات، وتتبع الشحنة، ووصول أحدث العروض والأخبار.</p>';
echo '</div><a href="../index.php" class="btn sm outline">متابعة التسوّق</a></div>';
echo '<div class="kpis">';
echo '<div class="kpi"><b>' . count($acct_orders) . '</b><span>إجمالي الطلبات</span></div>';
echo '<div class="kpi"><b>' . count($acct_open) . '</b><span>شحنات قيد التوصيل</span></div>';
echo '<div class="kpi"><b>' . (int)$acct_counts['reviews'] . '</b><span>تقييماتي وتعليقاتي</span></div>';
echo '<div class="kpi"><b>' . (int)$acct_counts['unread'] . '</b><span>إشعارات جديدة</span></div>';
echo '</div>';
echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">';
foreach (wf_customer_perks() as $perk) {
    echo '<span class="badge">' . wf_icon($perk[0], 14) . esc($perk[1]) . '</span>';
}
echo '</div></div>';

// ===== تتبع الشحنة =====
echo '<div class="card"><div data-ui="u-e883eee1 dh d1o d1p d1r"><h2 data-ui="u-67647882 d19">طلباتي وتتبّع الشحنة</h2>';
echo '<a href="orders.php" data-ui="u-4e5e81cb d1d du dc">كل الطلبات وإلغاء/استرجاع</a></div>';
if (!$acct_recent) {
    echo '<p class="mut">لما تطلب أي منتج هتلاقي هنا حالة الطلب خطوة بخطوة: مؤكد ← جاهز ← جاري الشحن ← تم التوصيل.</p>';
    echo '<a href="../index.php#products" class="btn sm">تصفّح المنتجات</a>';
} else {
    echo '<table class="data-table rtable"><thead><tr><th>رقم الطلب</th><th>المنتج</th><th>الحالة</th><th>الإجمالي</th><th>التاريخ</th></tr></thead><tbody>';
    foreach ($acct_recent as $o) {
        $k = status_key($o['status'] ?? '');
        $track = '<details class="row-details"><summary>مراحل الطلب</summary>' . wf_order_timeline($k) . '</details>';
        echo '<tr><td dir="ltr">' . esc($o['order_no'] ?? '—') . '</td><td><a href="product.php?id=' . urlencode((string)($o['id'] ?? '')) . '">' . esc(mb_substr((string)($o['pname'] ?? $o['id'] ?? ''), 0, 60)) . '</a></td>';
        echo '<td>' . wf_order_badge($k) . $track . '</td>';
        echo '<td><b>' . number_format((float)($o['total'] ?? 0), 2) . ' ' . esc(wf_ui_currency($o)) . '</b></td>';
        echo '<td dir="ltr">' . esc(mb_substr((string)($o['at'] ?? ($o['date'] ?? '')), 0, 10)) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

// ===== تقييماتي وتعليقاتي =====
echo '<div class="card" id="myReviews"><div data-ui="u-e883eee1 dh d1o d1p d1r"><h2 data-ui="u-67647882 d19">تقييماتي وتعليقاتي</h2>';
echo '<a href="../index.php#products" data-ui="u-4e5e81cb d1d du dc">تقييم منتج جديد</a></div>';
if (!$acct_reviews) {
    echo '<p class="mut">لسه ما كتبت أي تقييم. بعد الاستلام افتح صفحة المنتج من «طلباتي» واكتب رأيك وادّي نجوم — تعليقك بيظهر لباقي العملاء.</p>';
} else {
    echo '<table class="data-table rtable"><thead><tr><th>المنتج</th><th>تقييمي</th><th>تعليقي</th><th>التاريخ</th></tr></thead><tbody>';
    foreach ($acct_reviews as $r) {
        echo '<tr><td>';
        if (!empty($r['id'])) {
            echo '<a href="product.php?id=' . esc($r['id']) . '">' . esc(mb_substr($r['name'], 0, 60)) . '</a>';
        } else {
            echo esc(mb_substr($r['name'], 0, 60));
        }
        echo '</td><td>' . str_repeat('★', (int)$r['rating']) . '<span class="mut">' . str_repeat('☆', max(0, 5 - (int)$r['rating'])) . '</span></td>';
        echo '<td>' . wf_det_text($r['comment'], 80, 'تعليقي على: ' . mb_substr((string)$r['name'], 0, 60), 'التعليق كامل') . '</td>';
        echo '<td>' . esc(mb_substr((string)$r['at'], 0, 10)) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

// ===== أحدث العروض والأخبار =====
echo '<div class="card"><div data-ui="u-e883eee1 dh d1o d1p d1r"><h2 data-ui="u-67647882 d19">أحدث العروض والأخبار</h2>';
echo '<a href="notifications.php" data-ui="u-4e5e81cb d1d du dc">كل الإشعارات</a></div>';
if ($acct_msg !== '') echo '<div class="ok">' . esc($acct_msg) . '</div>';
if (!$acct_news) {
    echo '<p class="mut">مفيش عروض منشورة حالياً — أول ما ينزل عرض أو تحديث سعر هيوصلك إشعار هنا وعلى بريدك.</p>';
} else {
    echo '<ul style="margin:0;padding-inline-start:18px;line-height:1.9">';
    foreach ($acct_news as $n) {
        $t = $n['text'] !== '' ? $n['text'] : (string)$n['title'];
        $news_rows = [['العنوان', (string)$n['title']], ['التفاصيل', $t]];
        if ($n['link'] !== '') $news_rows[] = ['الرابط', (string)$n['link']];
        echo '<li>' . ($n['link'] !== '' ? '<a href="' . esc($n['link']) . '">' . esc(mb_substr($t, 0, 160)) . '</a>' : esc(mb_substr($t, 0, 160)))
           . (mb_strlen($t) > 160 ? ' ' . wf_details_btn('تفاصيل: ' . mb_substr((string)$n['title'] !== '' ? (string)$n['title'] : $t, 0, 60), $news_rows, 'التفاصيل الكاملة') : '')
           . ($n['at'] !== '' ? ' <span class="mut">(' . esc(mb_substr((string)$n['at'], 0, 10)) . ')</span>' : '') . '</li>';
    }
    echo '</ul>';
}
echo '<form method="post" data-ui="u-2d4ec034 d19 dh di d1p dj">' . csrf_field() . '<input type="hidden" name="acct_action" value="' . ($acct_offers_on ? 'offers_off' : 'offers_on') . '">'
   . '<label class="mut" style="display:inline-flex;gap:8px;align-items:center;font-size:var(--fs-12)">'
   . '<input type="checkbox"' . ($acct_offers_on ? ' checked' : '') . ' onchange="this.form.acct_action.value=this.checked?\'offers_off\':\'offers_on\';this.form.submit()">'
   . ' إرسال العروض والأخبار على ' . esc($acct_email) . '</label></form>';
echo '</div>';

// ===== بيانات الحساب =====
echo '<div class="card"><h2 data-ui="u-67647882 d19">بيانات حسابي</h2><div class="kpis">';
foreach ([
    ['name', 'الاسم', (string)($acct['name'] ?? '—')],
    ['email', 'البريد الإلكتروني', $acct_email !== '' ? $acct_email : '—'],
    ['whatsapp', 'رقم الموبايل', (string)($acct['whatsapp'] ?? ($acct['phone'] ?? '')) ?: '—'],
    ['gov', 'المحافظة والمدينة', trim((string)($acct['governorate'] ?? '')) !== '' ? ((string)$acct['governorate'] . ' — ' . (string)($acct['city'] ?? '')) : '—'],
] as $row) {
    echo '<div class="value-item"><b>' . esc($row[1]) . '</b><span>' . esc($row[2]) . '</span></div>';
}
echo '</div>';
if (trim((string)($acct['governorate'] ?? '')) === '') {
    echo '<p class="mut">مفيش محافظة/مدينة مسجّلين على حسابك — لو هتطلب توصيل أكّدهم في نموذج الطلب، أكّدهم في نموذج الطلب.</p>';
}
echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">';
echo '<a href="../forgot.php" class="btn sm outline">تغيير كلمة المرور</a>';
echo '<a href="../logout.php" class="btn sm outline">تسجيل الخروج</a>';
echo '</div></div>';

layout_end();
