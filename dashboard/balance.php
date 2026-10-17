<?php
require 'layout.php';
// «الرصيد والسحب» أداة مسوّقين: حساب العميل العادي بيرجع لصفحة حسابه
if (!wf_current_is_marketer()) { header('Location: index.php'); exit; }
require_once __DIR__ . '/_payout.php';

$msg = ''; $msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    payout_handle_post($user, $msg, $msg_type);
}

$me = find_user($user['email']) ?: $user;
$balance   = (float)($me['balance'] ?? 0);
$pending   = (float)($me['pending_balance'] ?? 0);
$all_orders = jload('orders.json', []);
if (!is_array($all_orders)) $all_orders = [];
$my_orders = array_values(array_filter($all_orders, function ($o) use ($user) {
    return strcasecmp((string)($o['user'] ?? ''), (string)$user['email']) === 0;
}));
$confirmed = count(array_filter($my_orders, function ($o) { return in_array(status_key($o['status'] ?? ''), ['delivered','confirmed','shipped'], true); }));
$withdrawn = 0;
foreach (jload('payouts.json', []) as $po) {
    if (strcasecmp((string)($po['email'] ?? ''), (string)$user['email']) === 0 && in_array($po['status'] ?? '', ['موافق عليه', 'approved', 'paid'], true)) $withdrawn += (float)($po['amount'] ?? 0);
}
$ledger = balance_ledger_for($user['email'], 100);

layout_top('الرصيد والسحب ', 'balance');

if ($msg) {
    $bg  = $msg_type === 'err' ? '#fee2e2' : '#dcfce7';
    $bd  = $msg_type === 'err' ? 'var(--danger-border)' : 'var(--tone-86efac)';
    $col = $msg_type === 'err' ? 'var(--red-700)' : 'var(--tone-166534)';
    echo '<div style="padding:14px 18px;background:' . $bg . ';border:1px solid ' . $bd . ';color:'
       . $col . ';border-radius:12px;margin-bottom:16px;font-weight:800">' . esc($msg) . '</div>';
}

echo '<div data-ui="u-3946f9f6 d3u d3v d67 d68 d69">';
echo '<div data-ui="u-d75c9e74 du d46 d45"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> الرصيد القابل للسحب</div>';
echo '<div data-ui="u-ff1183e3 d6a d1 d1m d6b">' . number_format($balance, 2) . ' <span data-ui="u-ac514ae7 de">ج.م</span></div>';
echo '<div data-ui="u-395d0534 dh d6c dj dq d4w d6d">';
echo '<span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-clock></use></svg> تحت التأكيد: <b data-ui="u-bc7695a0 d1g">' . number_format($pending, 2) . ' ج.م</b></span>';
echo '<span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-orders></use></svg> إجمالي طلباتك: <b>' . count($my_orders) . '</b></span>';
echo '<span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> مؤكدة: <b data-ui="u-3b819047 d1m">' . $confirmed . '</b></span>';
echo '<span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> تم سحبه: <b>راجع سجل السحب</b></span>';
echo '</div></div>';

if (($user['role'] ?? '') === 'marketer') {
    payout_render_section($user);
    $tl = ['commission' => ['⏳ عمولة معلقة', 'var(--warning-text)'], 'release' => [' تأكيد عمولة', 'var(--success)'], 'reversal' => ['↩ سحب عمولة', 'var(--danger-text)'],
           'payout' => [' طلب سحب', 'var(--blue-600)'], 'payout_refund' => [' استرداد سحب', 'var(--mode-digital)'], 'adjust' => [' تعديل إداري', 'var(--slate-500)'], 'referral' => [' عمولة فريق', '#0891b2']];
    echo '<div class="card" data-ui="u-d7ab73d7 dl"><h3><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> سجل حركات الرصيد</h3>';
    if (!$ledger) echo '<p class="mut" data-ui="u-e76c74bd d6e">لا توجد حركات بعد.</p>';
    else {
        echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-41d6c862 d4w"><tr><th>التاريخ</th><th>النوع</th><th>المبلغ</th><th>السبب</th></tr>';
        foreach ($ledger as $r) {
            $t = $tl[$r['type'] ?? ''] ?? [$r['type'] ?? '', 'var(--slate-500)'];
            $amt = (float)($r['amount'] ?? 0);
            echo '<tr><td>' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</td><td><b style="color:' . $t[1] . '">' . wf_svg_icon('wallet',16) . esc(wf_ui_plain($t[0])) . '</b></td><td dir="ltr" style="font-weight:900;color:' . ($amt < 0 ? 'var(--danger-text)' : 'var(--success)') . '">' . ($amt > 0 ? '+' : '') . number_format($amt, 2) . '</td><td class="mut">' . esc($r['reason'] ?? '') . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
} else {
    echo '<div class="card" data-ui="u-9c8a0931 df d6f">';
    echo '<div data-ui="u-15c09ce3 d20 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-megaphone></use></svg></div>';
    echo '<h3 data-ui="u-0316c139 d1 d2">السحب متاح للمسوقين</h3>';
    echo '<p class="mut" data-ui="u-71a974e5 dd">صفحة السحب مخصصة للمسوقين اللي بيكسبوا عمولات من روابط الإحالة. '
       . 'لو عايز تبقى مسوق وتبدأ تكسب، قدّم من صفحة التسجيل واختار "مسوق".</p>';
    echo '<a href="../register.php?role=marketer" class="btn" data-ui="u-89901732 dp"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> انضم كمسوق</a>';
    echo '</div>';
}

layout_end();
