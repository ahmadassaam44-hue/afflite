<?php require 'layout.php';
require_once __DIR__ . '/_payout.php';
if ($user && !empty($user['email']) && ($user['role'] ?? '') !== 'admin') {
    $me = ensure_user_affiliate($user['email']);
} else {
    $me = find_user($user['email'] ?? '');
}
if (!$me) { header('Location: ./'); exit; }
$code = $me['ref_code'] ?? '';
$msg = ''; $msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // عمولة المسوق على رابط المنتج (من تبويب الروابط): بتتحفظ وبتتضاف لسعر صفحة الهبوط والرابط
    if (isset($_POST['link_comm_action']) && $code !== '') {
        $lc_id = trim((string)($_POST['id'] ?? ''));
        if ($lc_id !== '') {
            $lc_pl  = resolve_plat($lc_id, trim((string)($_POST['plat'] ?? '')));
            $lc_key = $lc_pl . '#' . $lc_id;
            $lc_val = wf_is_digital_plat($lc_pl) ? 0.0 : max(0, min((float)MAX_MARKUP_AMOUNT, round((float)($_POST['extra_comm'] ?? 0), 2)));
            $st_lc = marketer_store_get($code);
            $lc_map = is_array($st_lc['link_comm'] ?? null) ? $st_lc['link_comm'] : [];
            $lc_map[$lc_key] = $lc_val;
            $lc_prods = is_array($st_lc['products'] ?? null) ? $st_lc['products'] : [];
            if (isset($lc_prods[$lc_key])) $lc_prods[$lc_key]['extra_comm'] = $lc_val;
            marketer_store_save($code, ['link_comm' => $lc_map, 'products' => $lc_prods]);
            $_SESSION['aff_custom_comm'][$code . '#' . $lc_key] = $lc_val;
            $msg = $lc_val > 0
                ? '✅ اتحفظت عمولتك على الرابط +' . wf_ui_money($lc_val) . ' ج.م — السعر في صفحة الهبوط اتحدّث.'
                : '✅ اتشالت العمولة المضافة من الرابط ده — السعر بقى سعر المنتج الأساسي.';
            $msg_type = 'ok';
            notify('admin', '💰 المسوق ' . $code . ' حدّد عمولته على منتج (' . $lc_key . ') — +' . wf_ui_money($lc_val) . ' ج.م');
            notify_user($user['email'], '💰 عمولتك على الرابط (' . $lc_key . ') اتحدّت: +' . wf_ui_money($lc_val) . ' ج.م', 'dashboard/marketer.php?mtab=store');
        }
        unset($_POST['extra_comm']); // عشان باقي صفوف الجدول ماتاخدش القيمة دي
    }
    if (isset($_POST['margin_pct'])) {
        $mp = max(0, min(500, (float)$_POST['margin_pct']));
        $pm = ($_POST['price_mode'] ?? 'pct') === 'fixed' ? 'fixed' : 'pct';
        update_user($user['email'], function (&$u) use ($mp, $pm) { $u['margin_pct'] = $mp; $u['price_mode'] = $pm; });
        $me = find_user($user['email']);
        $msg = 'تم حفظ سعرك ✅ (' . ($pm === 'fixed' ? '+' . $mp . ' ج.م' : $mp . '%') . ')';
        notify('admin', '⚙️ المسوق ' . ($me['name'] ?? $user['email']) . ' (' . $code . ') عدّل هامش سعره إلى '
            . ($pm === 'fixed' ? '+' . $mp . ' ج.م' : $mp . '%'), 'dashboard/admin.php?tab=marketers');
    }
    if (isset($_POST['save_store_settings']) && $code !== '') {
        $st_res = marketer_store_save_from_post($code, $_POST);
        if ($st_res['warn']) {
            $msg = '⚠️ رابط المتجر ده محجوز لمتجر تاني، سيبناه زي ما هو';
            $msg_type = 'warn';
        } else {
            $msg = '✅ تم حفظ إعدادات متجرك بنجاح!';
        }
        notify('admin', '🏪 المسوق ' . ($me['name'] ?? $user['email']) . ' (' . $code . ') حدّث إعدادات متجره'
            . ' — الاسم: ' . mb_substr(trim((string)($_POST['store_title'] ?? '')), 0, 60)
            . ' — واتساب: ' . trim((string)($_POST['store_whatsapp'] ?? ''))
            . ' — الرابط: ' . ($st_res['slug'] ?? ''),
            'dashboard/admin.php?tab=marketers');
    }
    // تعديل/إلغاء المسوق لطلباته: الإلغاء ناعم — الطلب يفضل عند الإدارة بحالة «ملغي»
    $mk_ord_msg = '';
    if (isset($_POST['mk_order_action'], $_POST['mk_order_no']) && $code !== '') {
        $mk_ono = mb_substr(trim((string)$_POST['mk_order_no']), 0, 40);
        if ($_POST['mk_order_action'] === 'cancel') {
            $mk_res = wf_marketer_cancel_order($mk_ono, $user, mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 300));
            if ($mk_res['ok']) {
                $mo = $mk_res['o'];
                if ((float)($mo['m_earn'] ?? 0) > 0) {
                    commission_reverse($user['email'], (float)$mo['m_earn'], $mk_res['idx'], 'إلغاء المسوق للطلب');
                    team_commission_reverse_for_order($mk_res['idx'], 'إلغاء المسوق للطلب');
                }
                notify('admin', '❌ المسوق ' . ($user['name'] ?? $user['email']) . ' (' . $code . ') ألغى الطلب ' . $mk_ono, 'dashboard/admin.php?tab=orders');
                notify_user($user['email'], '❌ تم إلغاء طلبك ' . $mk_ono . ' — فضل مسجّل عند الإدارة بحالة «ملغي».', 'dashboard/marketer.php?mtab=orders');
                log_audit('marketer_cancel', $code, ['order_no' => $mk_ono]);
            }
            $mk_ord_msg = $mk_res['msg'];
        } else {
            $mk_res = wf_marketer_apply_order_edit($mk_ono, $user, $_POST);
            if ($mk_res['ok']) {
                notify('admin', '✏️ المسوق ' . ($user['name'] ?? $user['email']) . ' (' . $code . ') عدّل بيانات الطلب ' . $mk_ono, 'dashboard/admin.php?tab=orders');
                notify_user($user['email'], '✏️ تم حفظ تعديلاتك على الطلب ' . $mk_ono . ' ✅', 'dashboard/marketer.php?mtab=orders');
                log_audit('marketer_edit', $code, ['order_no' => $mk_ono, 'changed' => $mk_res['changed'] ?? []]);
            }
            $mk_ord_msg = $mk_res['msg'];
        }
        unset($_POST['mk_order_action']);
    }
    if (isset($_POST['store_prod_action']) && $code !== '') {
        $st_cur = marketer_store_get($code);
        $prods = $st_cur['products'] ?? [];
        $ppl = trim((string)($_POST['plat'] ?? ''));
        $ppid = trim((string)($_POST['id'] ?? ''));
        if ($ppid !== '') {
            $ppl = resolve_plat($ppid, $ppl);
            $pkey = $ppl . '#' . $ppid;
            $is_digital_save = wf_is_digital_plat($ppl);
            // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة — السعر سعر المصدر بالظبط
            $ex_c = $is_digital_save ? 0.0 : max(0, min((float)MAX_MARKUP_AMOUNT, round((float)($_POST['extra_comm'] ?? 0), 2)));
            if (($_POST['store_prod_action'] ?? '') === 'remove') {
                unset($prods[$pkey]);
                unset($_SESSION['aff_custom_comm'][$code . '#' . $pkey]);
                $msg = '🗑️ تم حذف المنتج من متجرك.';
            } else {
                $tags = array_values(array_intersect(
                    array_map('strval', (array)($_POST['cols'] ?? [])),
                    ['featured', 'new', 'best', 'viewed']
                ));
                $prods[$pkey] = [
                    'plat'       => $ppl,
                    'id'         => $ppid,
                    'extra_comm' => $ex_c,
                    'tags'       => $tags,
                    'added_at'   => $prods[$pkey]['added_at'] ?? date('c'),
                ];
                $_SESSION['aff_custom_comm'][$code . '#' . $pkey] = $ex_c;
                $msg = $is_digital_save
                    ? '✅ تم حفظ المنتج في متجرك — سعر ثابت من غير عمولة مضافة.'
                    : '✅ تم حفظ المنتج في متجرك بعمولتك المضافة +' . wf_ui_money($ex_c) . ' ج.م';
                if ($tags) $msg .= ' — أقسام الرئيسية: ' . implode(' / ', array_map(function ($t) {
                    return ['featured' => 'مميز', 'new' => 'الأحدث', 'best' => 'الأكثر مبيعاً', 'viewed' => 'الأكثر مشاهدة'][$t] ?? $t;
                }, $tags));
            }
            marketer_store_save($code, ['products' => $prods]);
            $prod_row = find_product_by_id($ppid, $ppl);
            notify('admin', (($_POST['store_prod_action'] ?? '') === 'remove' ? '🗑️ حذف منتج من متجر المسوق ' : '➕ منتج أضافه المسوق لمتجره ')
                . ($me['name'] ?? $user['email']) . ' (' . $code . '): ' . (($prod_row['name'] ?? $ppid)) . ' [' . $pkey . ']'
                . (($_POST['store_prod_action'] ?? '') === 'remove' || $is_digital_save ? '' : ' — عمولة مضافة ' . wf_ui_money($ex_c) . ' ج.م'),
                'dashboard/admin.php?tab=marketers');
            notify_user($user['email'], ((($_POST['store_prod_action'] ?? '') === 'remove') ? '🗑️ منتج اتشال من متجرك: ' : '➕ منتج اتضاف لمتجرك: ') . (string)($prod_row['name'] ?? $ppid), 'dashboard/marketer.php?mtab=store');
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'in_store' => isset($prods[$pkey]), 'extra_comm' => $ex_c, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    if (($_POST['payout_action'] ?? '') === 'request') {
        require_once __DIR__ . '/_payout.php';
        payout_handle_post($user, $msg, $msg_type);
        $me = find_user($user['email']);
        if ($msg_type === 'err' && $msg === '') $msg = 'تعذر تنفيذ السحب';
    }
}
// تصدير طلباتي CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $code !== '') {
    $rows = array_values(array_filter(jload('orders.json', []), function ($o) use ($code) { return ($o['ref'] ?? '') === $code; }));
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="my_orders_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; $out = fopen('php://output', 'w');
    fputcsv($out, ['رقم الطلب', 'التاريخ', 'المنتج', 'اللون والمقاس', 'الكمية', 'السعر', 'الإجمالي', 'عمولتي', 'الحالة', 'الحملة']);
    foreach ($rows as $o) fputcsv($out, [$o['order_no'] ?? '', substr($o['at'] ?? '', 0, 16), $o['pname'] ?? '', order_variant_label($o), $o['qty'] ?? 1, $o['unit'] ?? 0, $o['total'] ?? 0, $o['m_earn'] ?? 0, status_label($o['status'] ?? ''), $o['sub'] ?? '']);
    fclose($out); exit;
}
$all_orders = jload('orders.json', []);
$clicks = array_values(array_filter(jload('clicks.json', []), function ($c) use ($code) { return $c['ref'] === $code; }));
$orders = array_values(array_filter($all_orders, function ($o) use ($code) { return ($o['ref'] ?? '') === $code; }));
$myearn = array_sum(array_map(function ($o) { return $o['m_earn'] ?? 0; }, $orders));
$team = array_values(array_filter(users(), function ($u) use ($code) { return ($u['referred_by'] ?? '') === $code; }));
// أرباح الفريق = العمولات اللي اتسجلت فعلاً في السجل (عمولة فريق (إحالة))، مش تقدير من أوردرات الفريق
$team_earn = 0.0;
foreach (jload('balance_ledger.json', []) as $lr) {
    if (strcasecmp((string)($lr['email'] ?? ''), (string)$me['email']) === 0 && ($lr['type'] ?? '') === 'commission' && ($lr['reason'] ?? '') === 'عمولة فريق (إحالة)') $team_earn += (float)($lr['amount'] ?? 0);
}
$myorders = count($orders);
$tiers = [500 => 500, 750 => 1000, 1000 => 1500];
$claimed = $me['bonuses'] ?? [];
$next_tier = null;
foreach ($tiers as $t => $b) if ($myorders < $t) { $next_tier = [$t, $b]; break; }

$mtab = $_GET['mtab'] ?? ($_GET['tab'] ?? 'performance');
// Removed/unknown tabs must render a real page rather than an empty content area.
if (!is_string($mtab) || !in_array($mtab, ['performance', 'pages', 'store', 'browse', 'orders', 'earnings', 'team', 'links', 'settings'], true)) {
    $mtab = 'performance';
}
$per = $_GET['per'] ?? 'all';
$valid_date = function ($v) {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return '';
    $ts = strtotime($v);
    return ($ts !== false && date('Y-m-d', $ts) === $v) ? $v : '';
};
$from_date = $valid_date($_GET['from_date'] ?? '');
$to_date = $valid_date($_GET['to_date'] ?? '');
if ($from_date !== '' && $to_date !== '' && $to_date < $from_date) { $from_date = ''; $to_date = ''; }
$custom_range = ($from_date !== '' || $to_date !== '');
$from = 0; $to = PHP_INT_MAX;
if ($custom_range) {
    if ($from_date !== '') $from = strtotime($from_date);
    if ($to_date !== '') $to = strtotime($to_date . ' +1 day');
} else {
    if ($per === 'today') $from = strtotime('today');
    elseif ($per === '7d') $from = time() - 7 * 86400;
    elseif ($per === '30d') $from = time() - 30 * 86400;
    elseif ($per === 'month') $from = strtotime(date('Y-m-01'));
}
$forders = array_values(array_filter($orders, function ($o) use ($from, $to) {
    $t = strtotime($o['at'] ?? '2000-01-01');
    return $t !== false && $t >= $from && $t < $to;
}));

$base = site_base_url();
$my_store_cfg = marketer_store_get($code);
$my_store_url = $base . '/store.php?store=' . urlencode($code);

layout_top('لوحة المسوق', 'marketer');

if ($msg) {
    $mc = $msg_type === 'err' ? ['#fee2e2','var(--danger-text)','var(--danger-border)'] : ['#dcfce7','var(--success)','var(--tone-86efac)'];
    echo '<div data-save-feedback class="card" style="margin-bottom:12px;background:' . $mc[0] . ';border-left:4px solid ' . $mc[1] . ';border-color:' . $mc[2] . ';color:' . $mc[1] . ';font-weight:800">' . esc(wf_ui_plain($msg)) . '</div>';
}
if (!empty($me['referred_by']) && ($inv = find_marketer_by_code($me['referred_by']))) {
    echo '<div class="card" data-ui="u-9918d7f8 d54 d7f d7g"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-users></use></svg> انضممت بدعوة من: <b>' . esc($inv['name']) . '</b> — نسبة عمولة إحالة الفريق قيد المراجعة.</div>';
}

if (($me['status'] ?? '') === 'suspended') {
    echo '<div class="alert warning">' . wf_svg_icon('info') . '<p>حسابك موقوف حالياً. تواصل مع <a href="support.php?ctx=marketer">الدعم</a> لمعرفة الخطوة التالية. عرض الأرصدة لا يعني إتاحة إجراء غير مصرح به.</p></div>';
}
echo '<p class="affiliate-disclosure">' . wf_svg_icon('info', 16) . 'العمولات مرتبطة بحالة الطلب.</p>';

if ($mtab === 'performance') { wfp('MTAB performance');
    echo '<div class="mut" data-ui="u-9eb4e939 dc d7h">تحليل حسب:</div><div class="tabs" data-ui="u-c767c453 d62">';
    foreach (['all' => 'كل الوقت', 'today' => 'تحليل اليوم', '7d' => '7 أيام', '30d' => '30 يوم', 'month' => 'تحليل هذا الشهر'] as $k => $n) echo '<a href="?mtab=' . $mtab . '&per=' . $k . '"' . ($per === $k ? ' class="on"' : '') . '>' . $n . '</a>';
    echo '</div><br>';
    echo '<form method="get" class="card" data-ui="u-35e745a3 dh d7i d1q dj d7j dg">';
    echo '<input type="hidden" name="mtab" value="performance">';
    echo '<label data-ui="u-01f4ea64 do dc">من: <input type="date" name="from_date" value="' . esc($from_date) . '" data-ui="u-23aad8b4 d27 d25 dt d1u"></label>';
    echo '<label data-ui="u-01f4ea64 do dc">إلى: <input type="date" name="to_date" value="' . esc($to_date) . '" data-ui="u-23aad8b4 d27 d25 dt d1u"></label>';
    echo '<button class="btn sm">تطبيق الفترة</button><a class="btn sm outline" href="?mtab=performance">مسح الفترة</a>';
    echo '</form>';
}

// ===== PERFORMANCE =====
if ($mtab === 'performance') { wfp('MTAB performance');
    echo '<div class="alert">' . wf_svg_icon('store') . '<div><b>خطوتك التالية</b><p>' . (empty($my_store_cfg['products']) ? '<a href="?mtab=browse">أضف أول منتج لمتجرك وحدد عمولته</a>' : '<a href="?mtab=store">راجع هوية متجرك وروابط منتجاتك</a>') . '</p></div></div>';
    $exp = $ern = 0;
    foreach ($forders as $o) {
        $ok = status_key($o['status'] ?? '');
        if ($ok === 'delivered') $ern += ($o['m_earn'] ?? 0);
        elseif (!in_array($ok, ['cancelled', 'returned'], true)) $exp += ($o['m_earn'] ?? 0);
    }
    // ملخص مالي مباشر من سجلات الموقع، وليس أرقاماً ثابتة أو بيانات خارجية.
    $payout_rows = jload('payouts.json', []);
    if (!is_array($payout_rows)) $payout_rows = [];
    $myout = $paid_out = $payout_waiting = 0.0;
    foreach ($payout_rows as $payrow) {
        if (strcasecmp((string)($payrow['user'] ?? ''), (string)$user['email']) !== 0) continue;
        $pamt = max(0, (float)($payrow['amount'] ?? 0));
        $pstatus = trim((string)($payrow['status'] ?? 'قيد المراجعة'));
        if ($pstatus !== 'مرفوض') $myout += $pamt;
        if ($pstatus === 'موافق عليه') $paid_out += $pamt;
        elseif ($pstatus === 'قيد المراجعة') $payout_waiting += $pamt;
    }
    $fine_total = 0.0;
    $ledger_rows = jload('balance_ledger.json', []);
    if (is_array($ledger_rows)) foreach ($ledger_rows as $lr) {
        if (strcasecmp((string)($lr['email'] ?? ''), (string)$user['email']) !== 0) continue;
        $lt = mb_strtolower(trim((string)($lr['type'] ?? '')));
        if (in_array($lt, ['fine', 'penalty', 'غرامة', 'غرامات'], true)) $fine_total += abs((float)($lr['amount'] ?? 0));
    }
    $all_statuses = []; $earned_total = $year_earned = $month_earned = $shipped_commission_all = 0.0;
    $delivered_units = 0; $year_months = array_fill(1, 12, 0.0);
    $current_year = date('Y'); $current_month = date('n');
    foreach ($orders as $eo) {
        $esk = status_key($eo['status'] ?? '');
        $all_statuses[$esk] = ($all_statuses[$esk] ?? 0) + 1;
        $eamt = max(0, (float)($eo['m_earn'] ?? 0));
        $ets = strtotime($eo['at'] ?? '');
        if ($esk === 'shipped') $shipped_commission_all += $eamt;
        if ($esk === 'delivered') $delivered_units += max(0, (int)($eo['qty'] ?? 1));
        if (!in_array($esk, ['cancelled', 'returned'], true) && $ets !== false) {
            $earned_total += $eamt;
            if (date('Y', $ets) === $current_year) {
                $year_earned += $eamt;
                $ym = (int)date('n', $ets); $year_months[$ym] += $eamt;
                if ($ym === (int)$current_month) $month_earned += $eamt;
            }
        }
    }
    $today_start = strtotime('today'); $tomorrow_start = strtotime('tomorrow');
    $today_orders = array_values(array_filter($orders, function ($o) use ($today_start, $tomorrow_start) {
        $ts = strtotime($o['at'] ?? ''); return $ts !== false && $ts >= $today_start && $ts < $tomorrow_start;
    }));
    $today_statuses = [];
    foreach ($today_orders as $to_order) { $tsk = status_key($to_order['status'] ?? ''); $today_statuses[$tsk] = ($today_statuses[$tsk] ?? 0) + 1; }
    $today_total = count($today_orders);
    $today_confirmed = ($today_statuses['confirmed'] ?? 0) + ($today_statuses['shipped'] ?? 0) + ($today_statuses['delivered'] ?? 0);
    $today_cancelled = ($today_statuses['cancelled'] ?? 0);
    $today_shipped = ($today_statuses['shipped'] ?? 0) + ($today_statuses['delivered'] ?? 0);
    $delivery_rate_all = $myorders ? round(($all_statuses['delivered'] ?? 0) / $myorders * 100, 1) : 0;

    // Revenue chart data (last 7 days)
    $chart_data = [];
    for ($d = 6; $d >= 0; $d--) {
        $dt = date('Y-m-d', time() - $d * 86400);
        $day_orders = array_filter($forders, function ($o) use ($dt) { return substr($o['at'] ?? '', 0, 10) === $dt; });
        $chart_data[] = ['date' => date('d/m', strtotime($dt)), 'orders' => count($day_orders), 'revenue' => array_sum(array_map(function ($o) { return $o['m_earn'] ?? 0; }, $day_orders))];
    }
    echo '<div data-ui="u-81c46419 dh d1p d1o d1q dj d54"><h2 data-ui="u-c1940140 de d1 d19"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> ملخص الأداء والأرباح</h2><a class="btn sm" href="?mtab=store"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> صفحات الهبوط ومتجري</a></div>';
    echo '<div class="kpis">';
    echo '<div class="kpi" data-ui="u-c934e5d3 d7k d3v"><b data-ui="u-0aebe4c1 d3v">' . number_format($me['balance'] ?? 0) . ' ج.م</b><span data-ui="u-360e7328 d7l">الرصيد المتاح للسحب </span></div>';
    echo '<div class="kpi" data-ui="u-5f7b0709 d7m d3v"><b data-ui="u-0aebe4c1 d3v">' . number_format($ern) . ' ج.م</b><span data-ui="u-7cb803b4 d7n">أرباح مكتسبة </span></div>';
    echo '<div class="kpi" data-ui="u-bcd43264 d7o d3v"><b data-ui="u-0aebe4c1 d3v">' . number_format($exp) . ' ج.م</b><span data-ui="u-4171ea86 d7p">أرباح قيد التوصيل ⏳</span></div>';
    echo '<div class="kpi" data-ui="u-cd297ee0 d7q d3v"><b data-ui="u-0aebe4c1 d3v">' . number_format($team_earn) . ' ج.م</b><span data-ui="u-c57cc268 d7r">أرباح الفريق </span></div>';
    echo '</div>';

    // ===== مصادر الأرباح (من سجل الرصيد) للفترة المختارة =====
    // كل عمولة بتتسجل في balance_ledger.json: أوردرات الموقع + أمازون + احالات الفريق + بونص التارجت.
    $src_totals = ['order' => 0.0, 'amazon' => 0.0, 'jumia' => 0.0, 'noon' => 0.0, 'team' => 0.0, 'bonus' => 0.0, 'other' => 0.0];
    $src_ledger = jload('balance_ledger.json', []);
    if (is_array($src_ledger)) foreach ($src_ledger as $lr) {
        if (strcasecmp((string)($lr['email'] ?? ''), (string)$user['email']) !== 0) continue;
        $lts = strtotime((string)($lr['at'] ?? ''));
        if ($lts === false || $lts < $from || $lts >= $to) continue;
        $ltype = (string)($lr['type'] ?? '');
        $lreason = (string)($lr['reason'] ?? '');
        $lamt = (float)($lr['amount'] ?? 0);
        if ($ltype === 'commission') {
            if (strpos($lreason, 'عمولة جوميا') !== false) $lsrc = 'jumia';
            elseif (strpos($lreason, 'عمولة أمازون') !== false) $lsrc = 'amazon';
            elseif (strpos($lreason, 'عمولة نون') === 0) $lsrc = 'noon';
            elseif ($lreason === 'عمولة فريق (إحالة)') $lsrc = 'team';
            elseif (strpos($lreason, 'بونص تارجت') === 0) $lsrc = 'bonus';
            elseif (strpos($lreason, 'عمولة أوردر') === 0) $lsrc = 'order';
            else $lsrc = 'other';
        } elseif ($ltype === 'reversal') {
            if (strpos($lreason, 'جوميا') !== false) $lsrc = 'jumia';
            elseif (strpos($lreason, 'أمازون') !== false) $lsrc = 'amazon';
            elseif (strpos($lreason, 'عمولة نون') !== false) $lsrc = 'noon';
            else $lsrc = 'order';
        } elseif ($ltype === 'adjust') {
            $lsrc = (strpos($lreason, 'جوميا') !== false) ? 'jumia' : 'amazon';   // amz/jmi_adjust_balance هي الكاتبة الوحيدة لصفوف التعديل
        } else {
            continue;           // تحويل معلق→متاح وسحوبات: مش ربح جديد
        }
        $src_totals[$lsrc] += $lamt;
    }
    $src_total = array_sum($src_totals);
    echo '<div class="card"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> مصادر أرباحك (الفترة المختارة)</h2>';
    echo '<div class="kpis">';
    foreach ([['order', 'التسويق العادي (أوردرات الموقع)'], ['amazon', 'أمازون'], ['jumia', 'جوميا أفلييت'], ['noon', 'أفلييت نون (أكواد الكوبون)'], ['team', 'أرباح احالات الفريق'], ['bonus', 'مكافأة الأوردرات (بونص التارجت)']] as $sr) {
        echo '<div class="kpi"><b>' . number_format($src_totals[$sr[0]], 2) . ' ج.م</b><span>' . esc($sr[1]) . '</span></div>';
    }
    if (abs($src_totals['other']) >= 0.005) echo '<div class="kpi"><b>' . number_format($src_totals['other'], 2) . ' ج.م</b><span>مصادر أخرى</span></div>';
    echo '<div class="kpi"><b>' . number_format($src_total, 2) . ' ج.م</b><span>الإجمالي</span></div>';
    echo '</div>';
    echo '<p class="mut">كل عمولة بتتسجل في رصيدك (معلقة أو متاحة) بتظهر هنا حسب مصدرها. الإلغاء والإرجاع بيتخصموا من نفس المصدر.</p></div>';

    echo '<div class="card" data-ui="u-63a03c4e d7s"><h2 data-ui="u-5d8a7984 dz d1 d11"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> تفاصيل الرصيد والسحوبات</h2><div class="kpis">';
    $financial_tiles = [
        ['العمولة المدفوعة (سحوبات معتمدة)', (float)$paid_out, 'ج.م', 'var(--success)'],
        ['سحوبات قيد المراجعة', (float)$payout_waiting, 'ج.م', '#9333ea'],
        ['أرباح هذا الشهر', (float)$month_earned, 'ج.م', 'var(--blue-600)'],
        ['أرباح السنة (' . $current_year . ')', (float)$year_earned, 'ج.م', '#0891b2'],
    ];
    foreach ($financial_tiles as $ft) echo '<div class="kpi" style="border-right:4px solid ' . $ft[3] . '"><b>' . number_format($ft[1], 2) . ' ' . $ft[2] . '</b><span>' . esc($ft[0]) . '</span></div>';
    echo '</div></div>';

    // إحصائيات وحالات الطلبات الموحدة
    $stc = [];
    foreach ($forders as $o) { $sk = status_key($o['status'] ?? ''); $stc[$sk] = ($stc[$sk] ?? 0) + 1; }
    $tot = count($forders);
    $conf = ($stc['confirmed'] ?? 0) + ($stc['shipped'] ?? 0) + ($stc['delivered'] ?? 0);
    $del = ($stc['delivered'] ?? 0);
    $shipped = ($stc['shipped'] ?? 0) + $del + ($stc['returned'] ?? 0);
    echo '<div class="card" data-ui="u-9e82dcff d54"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg> إحصائيات وحالات الطلبات</h2>';
    echo '<div class="kpis">';
    echo '<div class="kpi"><b>' . $tot . '</b><span>إجمالي الطلبات</span></div>';
    echo '<div class="kpi"><b>' . $today_total . '</b><span>طلبات اليوم</span></div>';
    foreach (order_status_list() as $sk => $sl) {
        $v = ($sk === 'delivered') ? $del : ($stc[$sk] ?? 0);
        echo '<div class="kpi"><b>' . (int)$v . '</b><span>' . esc($sl) . '</span></div>';
    }
    echo '</div>';
    echo '<div data-ui="u-0e0e1c21 d23 du dh d2c dj">';
    echo '<span data-ui="u-cc2b6fba dn">معدل التأكيد: <b>' . ($tot ? round($conf / $tot * 100, 1) : 0) . '%</b></span>';
    echo '<span data-ui="u-cc2b6fba dn">• معدل التسليم: <b>' . ($shipped ? round($del / $shipped * 100, 1) : 0) . '%</b></span>';
    echo '<span data-ui="u-cc2b6fba dn">• معدل المرتجع: <b>' . ($tot ? round(($stc['returned'] ?? 0) / $tot * 100, 1) : 0) . '%</b></span>';
    echo '<span data-ui="u-cc2b6fba dn">• قطع تم تسليمها: <b>' . (int)$delivered_units . '</b></span>';
    echo '</div></div>';

    // ===== تحليلات الزيارات والتحويل =====
    $days = (int)($_GET['days'] ?? 30); if (!in_array($days, [7, 30, 90], true)) $days = 30;
    $since = time() - $days * 86400;
    $clk_all = array_filter(jload('clicks.json', []), function ($c) use ($code, $since) { return ($c['ref'] ?? '') === $code && strtotime($c['at'] ?? '') >= $since; });
    $uniq_ips = []; $daily = []; $camp = [];
    for ($d = $days - 1; $d >= 0; $d--) $daily[date('Y-m-d', time() - $d * 86400)] = ['clicks' => 0, 'orders' => 0, 'earn' => 0];
    foreach ($clk_all as $c) { $dk = substr($c['at'], 0, 10); if (isset($daily[$dk])) $daily[$dk]['clicks']++; $uniq_ips[$c['ip'] ?? ''] = 1; $sb = $c['sub'] ?? ''; if ($sb !== '') { $camp[$sb]['clicks'] = ($camp[$sb]['clicks'] ?? 0) + 1; } }
    $p_orders = array_filter($forders, function ($o) use ($since) { return strtotime($o['at'] ?? '') >= $since; });
    foreach ($p_orders as $o) { $dk = substr($o['at'], 0, 10); if (isset($daily[$dk])) { $daily[$dk]['orders']++; $daily[$dk]['earn'] += (float)($o['m_earn'] ?? 0); } $sb = $o['sub'] ?? ''; if ($sb !== '') { $camp[$sb]['orders'] = ($camp[$sb]['orders'] ?? 0) + 1; $camp[$sb]['earn'] = ($camp[$sb]['earn'] ?? 0) + (float)($o['m_earn'] ?? 0); } }
    $tot_clicks = count($clk_all); $uniq_clicks = count($uniq_ips); $tot_ord = count($p_orders);
    $conv = $uniq_clicks > 0 ? round($tot_ord / $uniq_clicks * 100, 2) : 0;
    $epc = $tot_clicks > 0 ? round(array_sum(array_column($daily, 'earn')) / $tot_clicks, 2) : 0;

    echo '<div class="card" data-ui="u-9e82dcff d54"><div data-ui="u-65aba31e dh d1o d1p dj d1s"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> أداء الزيارات والتحويل (آخر ' . $days . ' يوم)</h2><div data-ui="u-a8c7802a dh di">';
    foreach ([7, 30, 90] as $dd) echo '<a class="btn sm' . ($dd === $days ? ' pri' : ' outline') . '" href="?mtab=performance&days=' . $dd . '">' . $dd . ' يوم</a>';
    echo '<a class="btn sm outline" href="?export=csv"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-download></use></svg> CSV</a></div></div>';
    echo '<div class="kpis" data-ui="u-c7e91745 d23"><div class="kpi"><b>' . number_format($tot_clicks) . '</b><span>إجمالي الكليكات</span></div><div class="kpi"><b>' . number_format($uniq_clicks) . '</b><span>زيارات فريدة</span></div><div class="kpi"><b>' . $tot_ord . '</b><span>طلبات الفترة</span></div><div class="kpi"><b>' . $conv . '%</b><span>نسبة التحويل</span></div><div class="kpi"><b>' . number_format($epc, 2) . ' ج.م</b><span>ربح لكل كليك (EPC)</span></div></div>';
    // رسم بياني SVG
    $mx_c = max(1, max(array_column($daily, 'clicks'))); $mx_o = max(1, max(array_column($daily, 'orders')));
    $W = 900; $H = 180; $n = count($daily); $sx = $n > 1 ? ($W - 50) / ($n - 1) : 0; $pc = $po = ''; $i = 0; $lbls = '';
    foreach ($daily as $dk => $dv) { $x = 30 + $i * $sx; $pc .= $x . ',' . ($H - 25 - $dv['clicks'] / $mx_c * ($H - 50)) . ' '; $po .= $x . ',' . ($H - 25 - $dv['orders'] / $mx_o * ($H - 50)) . ' '; if ($i % max(1, intdiv($n, 8)) === 0) $lbls .= '<text x="' . $x . '" y="' . ($H - 6) . '" font-size="12" text-anchor="middle" fill="var(--slate-400)">' . date('d/m', strtotime($dk)) . '</text>'; $i++; }
    echo '<div data-ui="u-ada86fd7 d13 d23"><svg role="img" aria-label="تطور النقرات والطلبات؛ البيانات في الجدول النصي التالي" viewBox="0 0 ' . $W . ' ' . $H . '" data-ui="u-be0d21c0 dx d7t d7u" preserveAspectRatio="none"><polyline points="' . trim($pc) . '" fill="none" stroke="#3b82f6" stroke-width="2.5"/><polyline points="' . trim($po) . '" fill="none" stroke="var(--success)" stroke-width="2.5"/>' . $lbls . '</svg></div>';
    echo '<div data-ui="u-7f4c4701 dh d3q do d3i"><span><span data-ui="u-2b39d81c d31 d7v d7w d4m d7x"></span> كليكات (أقصى ' . $mx_c . ')</span><span><span data-ui="u-24998e92 d31 d7v d7w d4e d7x"></span> طلبات (أقصى ' . $mx_o . ')</span></div>';
    echo '</div>';

    echo '<details class="card chart-data"><summary>عرض بيانات الرسم كنص وجدول</summary><p class="chart-summary">الخطوط تعرض نقرات وطلبات كل يوم، بمقياس مستقل لكل سلسلة. القيم الفعلية موضحة في الجدول.</p><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><thead><tr><th>اليوم</th><th>النقرات</th><th>الطلبات</th><th>العمولات المسجلة (ج.م)</th></tr></thead><tbody>';
    foreach ($daily as $chart_day => $chart_value) echo '<tr><td>' . esc($chart_day) . '</td><td>' . (int)$chart_value['clicks'] . '</td><td>' . (int)$chart_value['orders'] . '</td><td>' . number_format($chart_value['earn'], 2) . '</td></tr>';
    echo '</tbody></table></details>';

    // الحملات (sub-ID)
    if ($camp) {
        echo '<div class="card" data-ui="u-9e82dcff d54"><h2> تتبع الحملات (sub-ID)</h2>';
        uasort($camp, function ($a, $b) { return ($b['earn'] ?? 0) <=> ($a['earn'] ?? 0); });
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-41d6c862 d4w"><tr><th>الحملة</th><th>كليكات</th><th>طلبات</th><th>تحويل</th><th>أرباح</th></tr>';
        foreach ($camp as $ck => $cv) { $cc = (int)($cv['clicks'] ?? 0); $co = (int)($cv['orders'] ?? 0); echo '<tr><td dir="ltr"><b>' . esc($ck) . '</b></td><td>' . $cc . '</td><td>' . $co . '</td><td>' . ($cc ? round($co / $cc * 100, 1) : 0) . '%</td><td><b>' . number_format((float)($cv['earn'] ?? 0), 2) . '</b></td></tr>'; }
        echo '</table></div>';
    }

    // تحليل الأرباح للسنة الحالية
    echo '<div class="card" data-ui="u-9e82dcff d54"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> توزيع الأرباح الشهرية — ' . esc($current_year) . '</h2>';
    echo '<div data-ui="u-6e99ba94 dh d5n di d23">';
    $max_month_earn = max(1, max($year_months));
    for ($mi = 1; $mi <= 12; $mi++) {
        $bar_width = round($year_months[$mi] / $max_month_earn * 100, 1);
        echo '<div data-ui="u-411a3f05 d21 d7y d1s d1p do"><b>' . sprintf('%02d', $mi) . '</b><div data-ui="u-1516e324 d7z d80 d36 d2n"><div style="height:100%;width:' . $bar_width . '%;background:linear-gradient(90deg,var(--blue-600),var(--success));border-radius:99px"></div></div><span data-ui="u-c822a29c d2a">' . number_format($year_months[$mi], 2) . ' ج.م</span></div>';
    }
    echo '</div></div>';

    // Top products
    $psales = [];
    foreach ($forders as $o) {
        $k = ($o['pname'] ?? $o['id']);
        if (!isset($psales[$k])) $psales[$k] = ['n' => 0, 'q' => 0, 'e' => 0];
        $psales[$k]['n']++; $psales[$k]['q'] += ($o['qty'] ?? 1); $psales[$k]['e'] += ($o['m_earn'] ?? 0);
    }
    uasort($psales, function ($a, $b) { return $b['q'] - $a['q']; });
    if ($psales) {
        echo '<div class="card" data-ui="u-9e82dcff d54"><h2> أفضل المنتجات مبيعة</h2><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>#</th><th>المنتج</th><th>الطلبات</th><th>الكمية</th><th>الأرباح</th></tr>';
        $rank = 1;
        foreach (array_slice($psales, 0, 10, true) as $nm => $v) {
            echo '<tr><td><b>' . $rank++ . '</b></td><td>' . esc(mb_substr($nm, 0, 45)) . '</td><td>' . $v['n'] . '</td><td>' . $v['q'] . '</td><td class="comm">+' . number_format($v['e']) . ' ج.م</td></tr>';
        }
        echo '</table></div>';
    }
    // Top regions
    $region_stats = [];
    foreach ($forders as $o) { $k = $o['city'] ?? 'غير معروف'; $region_stats[$k] = ($region_stats[$k] ?? 0) + 1; }
    arsort($region_stats);
    if ($region_stats) {
        echo '<div class="card" data-ui="u-9e82dcff d54"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-map></use></svg> أعلى المحافظات طلباتاً</h2><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>المحافظة</th><th>عدد الطلبات</th><th>%</th></tr>';
        foreach (array_slice($region_stats, 0, 8, true) as $k => $v) {
            echo '<tr><td>' . esc($k) . '</td><td>' . $v . '</td><td>' . ($tot ? round($v / $tot * 100, 1) : 0) . '%</td></tr>';
        }
        echo '</table></div>';
    }
    // Target bonuses
    echo '<div class="card"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> بونص التارجت</h2><p class="mut">500 أوردر → 500 ج.م • 750 → 1000 ج.م • 1000 → 1500 ج.م (مرة واحدة لكل مستوى)</p>';
    echo '<div class="kpis">';
    echo '<div class="kpi"><b>' . $myorders . '</b><span>طلباتي</span></div>';
    if ($next_tier) echo '<div class="kpi"><b>' . ($next_tier[0] - $myorders) . '</b><span>متبقي للمستوى ' . $next_tier[1] . ' ج.م</span></div>';
    else echo '<div class="kpi" data-ui="u-8254dc57 d4e d3v"><b data-ui="u-0aebe4c1 d3v">مكتمل </b><span data-ui="u-7cb803b4 d7n">كل التارجت</span></div>';
    echo '</div></div>';
}
// ===== STORE (Marketer Store & Landing Pages) =====
elseif ($mtab === 'store') { wfp('MTAB store');
    $st_cfg = marketer_store_get($code);
    $st_prods = $st_cfg['products'] ?? [];
    echo '<div class="card" data-ui="u-a980d1b7 d1r d81 d82">';
    echo '<div data-ui="u-ce18f648 dh d1o d1p dj d3z">';
    echo '<div><h2 data-ui="u-97a5d8b3 d83 d84"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-store></use></svg> متجرك الإلكتروني المستقل</h2><p data-ui="u-58bdb163 d4w d17 d19">رابط متجرك جاهز للمشاركة في إعلاناتك الممولة أو البايو — يظهر لعملائك كمتجر إلكتروني مستقل باسمك وألوانك مع احتساب عمولتك تلقائياً مع كل طلب (عمولة الإضافة للمنتجات بس — الرقمي والسوشيال سعر ثابت).</p></div>';
    echo '<div data-ui="u-c98a6604 dh d1s dj">';
    echo '<a href="../store.php?store=' . urlencode($code) . '" target="_blank" class="btn sm" data-ui="u-8254dc57 d4e d3v"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-globe></use></svg> فتح متجري في تبويب جديد</a>';
    echo '<button type="button" class="btn sm outline" data-copy="' . esc($my_store_url) . '"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-copy></use></svg> نسخ رابط المتجر</button>';
    echo '<a href="?mtab=browse" class="btn sm" data-ui="u-6a2602f0 d4m d3v"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-plus></use></svg> إضافة منتجات جديدة لمتجري</a>';
    echo '</div></div>';
    echo '<div data-ui="u-eab08bf4 d23 dh d1s d1p"><input aria-label="رابط المتجر أو صفحة الهبوط" readonly value="' . esc($my_store_url) . '" dir="ltr" data-ui="u-07d53ffd d2i d85 d24 d16 d4u d4w d19"></div>';
    echo '</div>';

    $st_slug = $st_cfg['slug'] ?: strtolower((string)$code);
    $st_slug_url = $base . '/store.php?store=' . urlencode($st_slug);
    echo '<div class="card sb-builder" data-ui="u-f2235598 d1r">';
    echo '<h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-settings></use></svg> أداة تهيئة المتجر</h2><p class="mut">خصّص مظهر متجرك وأقسامه، وشوف المعاينة على اليمين قبل الحفظ.</p>';
    echo '<div class="builder-tabs" role="tablist" aria-label="محرر المتجر"><button type="button" role="tab" aria-selected="true" class="btn pri" data-builder-view="edit">تعديل</button><button type="button" role="tab" aria-selected="false" class="btn outline" data-builder-view="preview">معاينة</button></div>';
    echo '<div class="store-editor-grid" data-view="edit">';
    echo '<form method="post" id="storeSettingsForm" data-store-editor>';
    echo csrf_field();
    echo '<input type="hidden" name="save_store_settings" value="1">';
    // 1) المظهر والعلامة التجارية
    echo '<details class="sb-sec" open><summary><span class="sb-ico">' . wf_svg_icon('store',16) . '</span> المظهر والعلامة التجارية</summary><div class="sb-body">';
    echo '<div class="sb-grid">';
    echo '<div><label class="field-label" for="storeTitle">اسم المتجر</label><input id="storeTitle" maxlength="80" type="text" name="store_title" value="' . esc($st_cfg['title']) . '" required></div>';
    echo '<div><label class="field-label" for="storeSlug">رابط المتجر</label><div class="sb-slug"><span dir="ltr">store.php?store=</span><input id="storeSlug" maxlength="40" type="text" name="store_slug" dir="ltr" value="' . esc($st_slug) . '" pattern="[a-z0-9\-]{3,40}"></div>';
    $slug_free = !marketer_store_slug_taken($st_slug, $code);
    echo '<p class="field-hint" id="storeSlugHint">' . ($slug_free ? '✅ الرابط متاح' : '⚠️ الرابط محجوز — اكتب رابط تاني') . '</p></div>';
    echo '<div><label class="field-label" for="storeBio">وصف المتجر الترحيبي</label><input id="storeBio" maxlength="220" type="text" name="store_bio" value="' . esc($st_cfg['bio']) . '"></div>';
    echo '<div><label class="field-label" for="storeTheme">اللون الأساسي</label><input id="storeTheme" type="color" name="store_theme" value="' . esc($st_cfg['theme'] ?: '#059669') . '" data-ui="u-a852e8ca d5w d59"></div>';
    echo '<div><label class="field-label" for="storeMode">القالب الأساسي</label><select id="storeMode" name="store_mode"><option value="light"' . ($st_cfg['mode'] === 'light' ? ' selected' : '') . '>فاتح (Light)</option><option value="dark"' . ($st_cfg['mode'] === 'dark' ? ' selected' : '') . '>داكن (Dark)</option></select></div>';
    echo '</div>';
    echo '<div class="color-presets" aria-label="ألوان جاهزة">';
    foreach (['#fed701', '#0f172a', '#059669', '#2563eb', '#7c3aed', '#B85CF6'] as $preset) echo '<button type="button" data-color-preset="' . esc($preset) . '" style="--preset:' . esc($preset) . '" aria-label="اختيار اللون ' . esc($preset) . '"></button>';
    echo '</div><p id="storeContrastHint" class="field-hint"></p><span class="character-count" data-count-for="store_title"></span><span class="character-count" data-count-for="store_bio"></span>';
    echo '</div></details>';
    // 2) شريط الإعلان
    echo '<details class="sb-sec"><summary><span class="sb-ico">' . wf_svg_icon('truck',16) . '</span> شريط الإعلان المتحرك</summary><div class="sb-body"><div class="sb-grid">';
    echo '<div><label class="field-label" for="annText">نص الشريط</label><input id="annText" maxlength="160" type="text" name="announcement_text" value="' . esc($st_cfg['announcement_text']) . '" placeholder="مثال: توصيل لكل المحافظات — الدفع عند الاستلام"></div>';
    echo '<div><label class="field-label" for="annColor">لون الشريط</label><input id="annColor" type="color" name="announcement_color" value="' . esc($st_cfg['announcement_color']) . '"></div>';
    echo '</div><label class="sb-toggle"><input type="checkbox" name="sections[announcement]" value="1"' . (!empty($st_cfg['sections']['announcement']) ? ' checked' : '') . '> إظهار شريط الإعلان في المتجر</label></div></details>';
    // 3) أقسام الصفحة الرئيسية
    echo '<details class="sb-sec"><summary><span class="sb-ico">' . wf_svg_icon('box',16) . '</span> أقسام الصفحة الرئيسية للمتجر</summary><div class="sb-body sb-sections">';
    $sec_labels = [
        'trust' => 'شارات الثقة (الدفع عند الاستلام والتوصيل)',
        'chips' => 'الفئات والعروض (فلتر الأقسام)',
        'collections' => 'أقسام المجموعات في الرئيسية (المميز / الأحدث / الأكثر مبيعاً)',
        'faq' => 'الأسئلة الشائعة',
        'contact' => 'معلومات التواصل',
        'footer' => 'الفوتر',
    ];
    foreach ($sec_labels as $sec_k => $sec_lbl) {
        echo '<label class="sb-toggle"><input type="checkbox" name="sections[' . $sec_k . ']" value="1"' . (!empty($st_cfg['sections'][$sec_k]) ? ' checked' : '') . '> ' . esc($sec_lbl) . '</label>';
    }
    echo '</div></details>';
    // 4) الأسئلة الشائعة
    echo '<details class="sb-sec"><summary><span class="sb-ico">' . wf_svg_icon('chat',16) . '</span> الأسئلة الشائعة</summary><div class="sb-body">';
    for ($i = 0; $i < 4; $i++) {
        $qa = $st_cfg['faq'][$i] ?? ['q' => '', 'a' => ''];
        echo '<div class="sb-grid sb-qa"><div><label class="field-label">السؤال ' . ($i + 1) . '</label><input maxlength="160" type="text" name="faq_q[]" value="' . esc($qa['q']) . '"></div>';
        echo '<div><label class="field-label">الإجابة ' . ($i + 1) . '</label><input maxlength="400" type="text" name="faq_a[]" value="' . esc($qa['a']) . '"></div></div>';
    }
    echo '</div></details>';
    // 5) معلومات التواصل والسوشيال
    echo '<details class="sb-sec"><summary><span class="sb-ico">' . wf_svg_icon('phone',16) . '</span> معلومات التواصل والسوشيال</summary><div class="sb-body"><div class="sb-grid">';
    echo '<div><label class="field-label" for="storeWhatsapp">رقم واتساب خدمة عملاء متجرك</label><input id="storeWhatsapp" maxlength="30" type="text" name="store_whatsapp" value="' . esc($st_cfg['whatsapp']) . '" placeholder="01xxxxxxxxx"></div>';
    echo '<div><label class="field-label" for="contactPhone">رقم الهاتف</label><input id="contactPhone" maxlength="30" type="text" name="contact_phone" value="' . esc($st_cfg['contact']['phone']) . '" placeholder="01xxxxxxxxx"></div>';
    echo '<div><label class="field-label" for="contactEmail">البريد الإلكتروني</label><input id="contactEmail" maxlength="80" type="email" name="contact_email" value="' . esc($st_cfg['contact']['email']) . '"></div>';
    echo '<div><label class="field-label" for="contactAddress">العنوان</label><input id="contactAddress" maxlength="160" type="text" name="contact_address" value="' . esc($st_cfg['contact']['address']) . '"></div>';
    echo '<div><label class="field-label" for="socFb">فيسبوك</label><input id="socFb" maxlength="120" dir="ltr" type="url" name="social_facebook" value="' . esc($st_cfg['social']['facebook']) . '" placeholder="https://facebook.com/..."></div>';
    echo '<div><label class="field-label" for="socIg">إنستجرام</label><input id="socIg" maxlength="120" dir="ltr" type="url" name="social_instagram" value="' . esc($st_cfg['social']['instagram']) . '" placeholder="https://instagram.com/..."></div>';
    echo '<div><label class="field-label" for="socTt">تيك توك</label><input id="socTt" maxlength="120" dir="ltr" type="url" name="social_tiktok" value="' . esc($st_cfg['social']['tiktok']) . '" placeholder="https://tiktok.com/@..."></div>';
    echo '</div></div></details>';
    // 6) التكاملات
    echo '<details class="sb-sec"><summary><span class="sb-ico">' . wf_svg_icon('chart',16) . '</span> التكاملات (بيكسل الإعلانات)</summary><div class="sb-body"><div class="sb-grid">';
    echo '<div><label class="field-label" for="pxFb">Facebook Pixel ID (اختياري)</label><input id="pxFb" type="text" name="pixel_fb" value="' . esc($st_cfg['pixel_fb'] ?? '') . '" placeholder="مثال: 1234567890"></div>';
    echo '<div><label class="field-label" for="pxTt">TikTok Pixel ID (اختياري)</label><input id="pxTt" type="text" name="pixel_tt" value="' . esc($st_cfg['pixel_tt'] ?? '') . '" placeholder="مثال: C8ABCDEF..."></div>';
    echo '</div></div></details>';
    echo '<button type="submit" class="btn sb-save" data-ui="u-40b3aef5 d4e d3v d49"> حفظ إعدادات المتجر</button>';
    echo '</form>';
    echo '<section class="editor-preview sb-preview" aria-label="معاينة هوية المتجر"><div class="sb-preview-bar" id="sbPreviewBar"' . ($st_cfg['announcement_text'] !== '' ? '' : ' hidden') . ' style="background:' . esc($st_cfg['announcement_color']) . ';color:' . esc(wf_contrast_fg($st_cfg['announcement_color'])) . '">' . esc($st_cfg['announcement_text'] !== '' ? $st_cfg['announcement_text'] : 'نص شريط الإعلان') . '</div><header class="preview-store-header"><h2 data-preview-title>' . esc($st_cfg['title']) . '</h2><p data-preview-bio>' . esc($st_cfg['bio']) . '</p></header><div class="preview-content"><p class="mut">معاينة محلية للهوية والإعلان؛ الحفظ يتم بالزر فوق.</p><a class="btn outline" target="_blank" rel="noopener" href="' . esc($my_store_url) . '">فتح المتجر الحالي</a><a class="btn outline" target="_blank" rel="noopener" href="' . esc($st_slug_url) . '">فتح رابط المتجر المختصر</a></div></section></div></div>';

    echo '<div class="card"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg> المنتجات المضافة في متجري — تحكم في عمولتك المخصصة وصفحات الهبوط</h2>';
    if ($st_prods) {
        echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>الصورة</th><th>المنتج</th><th>السعر الأساسي</th><th>عمولتك المخصصة (ج.م)</th><th>سعر البيع للعميل</th><th>صفحة الهبوط والطلب</th></tr>';
        foreach ($st_prods as $pkey => $pm) {
            $ppl = $pm['plat'] ?? '';
            $ppid = (string)($pm['id'] ?? '');
            if ($ppid === '' && strpos($pkey, '#') !== false) list($ppl, $ppid) = explode('#', $pkey, 2);
            $fp = find_product_by_id($ppid, $ppl);
            if (!$fp) continue;
            $ppl = $fp['_plat'] ?? resolve_plat($ppid, $ppl);
            $pname_c = aff_clean($fp['name'] ?? '', true);
            $pimg = product_first_image($fp, $ppl);
            $base_p = list_price($fp, $ppl) ?: display_price($fp, $ppl);
            $is_digital_row = wf_is_digital_plat($ppl);
            // الرقمي والسوشيال ميديا: القيمة المحفوظة قديمًا بتتجاهل — السعر سعر المصدر بالظبط
            $ex_c = $is_digital_row ? 0.0 : max(0, (float)($pm['extra_comm'] ?? 0));
            $cust_p = round($base_p + $ex_c, 2);
            $lp_href = wf_client_order_url($ppid, $ppl, $code, $ex_c, true);
            $lp_url = wf_client_order_url($ppid, $ppl, $code, $ex_c, false);
            $eo_p = json_encode(['id' => $ppid, 'plat' => $ppl, 'name' => $pname_c, 'price' => $cust_p, 'base_price' => $base_p, 'extra_comm' => $ex_c, 'img' => $pimg, 'ref' => $code, 'currency' => wf_ui_currency($fp), 'country' => wf_product_country($fp), 'ship_fee' => function_exists('wf_product_shipping') ? wf_product_shipping($fp) : null, 'colors' => clean_product_colors($fp, $ppl), 'sizes' => clean_product_sizes($fp, $ppl)], JSON_UNESCAPED_UNICODE);
            echo '<tr>';
            echo '<td><img alt="صورة المنتج" src="' . esc($pimg) . '" data-ui="u-aef7c762 d87 d88 d5q d1u d4u dt"></td>';
            echo '<td><b>' . esc(mb_substr($pname_c, 0, 50)) . '</b><div class="mut" data-ui="u-5d985ec3 do">' . esc($fp['category'] ?? '') . ($is_digital_row ? ' • منتج رقمي/خدمة — يُطلب من صفحة الطلب' : '') . '</div></td>';
            echo '<td><b>' . wf_ui_money($base_p) . ' ' . esc(wf_ui_currency($fp)) . '</b></td>';
            echo '<td><form method="post" data-ui="u-605a7de4 dh d4g d1p d19">' . csrf_field() . '<input type="hidden" name="store_prod_action" value="add"><input type="hidden" name="plat" value="' . esc($ppl) . '"><input type="hidden" name="id" value="' . esc($ppid) . '">'
                . ($is_digital_row
                    ? '<span class="mut" data-ui="u-28b0a83a d89 d8a d19 d1">سعر ثابت — من غير عمولة مضافة</span>'
                    : '<input type="number" name="extra_comm" value="' . $ex_c . '" min="0" max="' . esc(MAX_MARKUP_AMOUNT) . '" step="5" data-ui="u-28b0a83a d89 d8a d19 d1">');
            $row_tags = array_map('strval', (array)($pm['tags'] ?? []));
            echo '<div data-ui="u-605a7de4 dh d4g d1p d19" style="gap:10px;flex-wrap:wrap">';
            foreach (['featured' => 'مميز', 'new' => 'الأحدث', 'best' => 'الأكثر مبيعاً', 'viewed' => 'الأكثر مشاهدة'] as $tg_k => $tg_lbl) {
                echo '<label class="sb-toggle" style="font-size:12px"><input type="checkbox" name="cols[]" value="' . esc($tg_k) . '"' . (in_array($tg_k, $row_tags, true) ? ' checked' : '') . '> ' . esc($tg_lbl) . '</label>';
            }
            echo '</div>';
            echo '<button type="submit" class="btn sm" data-ui="u-cca2700e d4e d3v d6x">حفظ</button></form></td>';
            echo '<td data-ui="u-bff22eac d2k dc d12">' . wf_ui_money($cust_p) . ' ' . esc(wf_ui_currency($fp)) . '</td>';
            echo '<td><div data-ui="u-77a33778 dh d4a dj">';
            echo '<a href="' . esc($lp_href) . '" target="_blank" class="btn sm" data-ui="u-6a2602f0 d4m d3v"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> ' . ($is_digital_row ? 'صفحة الطلب' : 'صفحة الهبوط') . '</a>';
            echo '<button type="button" class="btn sm outline" data-copy="' . esc($lp_url) . '"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-copy></use></svg> ' . ($is_digital_row ? 'نسخ رابط الطلب' : 'نسخ الهبوط') . '</button>';
            echo wf_product_media_html($fp, false, $ppl);
            echo $is_digital_row
                ? '<a href="' . esc($lp_href) . '" class="btn sm" data-ui="u-8254dc57 d4e d3v"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> طلب لعميل</a>'
                : '<button type="button" class="btn sm" data-ui="u-8254dc57 d4e d3v" data-easy-order="' . esc($eo_p) . '"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> طلب لعميل</button>';
            echo '<form method="post" data-confirm="سيُزال المنتج من متجرك فقط، ولن يُحذف من الكتالوج. تأكيد الإزالة؟" data-ui="u-67647882 d19">' . csrf_field() . '<input type="hidden" name="store_prod_action" value="remove"><input type="hidden" name="plat" value="' . esc($ppl) . '"><input type="hidden" name="id" value="' . esc($ppid) . '"><button type="submit" class="btn sm" data-ui="u-5b8bbcf5 d5y d53" aria-label="إزالة المنتج من متجري"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-trash></use></svg></button></form>';
            echo '</div></td>';
            echo '</tr>';
        }
        echo '</table></div>';
    } else {
        echo '<div data-ui="u-70cab7c5 df d8b"><div data-ui="u-15c09ce3 d20 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-store></use></svg></div><p class="mut">لم تضف منتجات مخصصة لمتجرك بعد (يعرض المتجر حالياً المنتجات المميزة تلقائياً بكود إحالتك).</p><br><a href="?mtab=browse" class="btn" data-ui="u-8254dc57 d4e d3v"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg> تصفح الكتالوج وأضف منتجات بعمولتك الخاصة</a></div>';
    }
    echo '</div>';
}
// ===== ORDERS =====
elseif ($mtab === 'orders') { wfp('MTAB orders');
    echo '<div data-ui="u-b27a4db8 dh d1o d1p dj d1q d54"><h2 data-ui="u-c35cd038 d26 d1 d19"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-orders></use></svg> طلباتي ومبيعاتي (' . count($forders) . ')</h2><div data-ui="u-3ecc8ff6 dh d1s"><a href="?mtab=orders&export=csv" class="btn sm outline"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-download></use></svg> تصدير Excel / CSV</a><a href="?mtab=browse" class="btn sm" data-ui="u-8254dc57 d4e d3v"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-plus></use></svg> تسجيل طلب جديد لعميل</a></div></div>';
    $mk_edit_o = null;
    $mk_edit_no = trim((string)($_GET['edit'] ?? ''));
    if ($mk_edit_no !== '') { foreach ($forders as $oo) if (($oo['order_no'] ?? '') === $mk_edit_no && wf_marketer_can_edit_order($oo, $user)) { $mk_edit_o = $oo; break; } }
    if ($mk_ord_msg !== '') echo '<div class="alert info" role="status" data-ui="u-1ae67359 d13">' . esc($mk_ord_msg) . '</div>';
    if ($mk_edit_o) {
        // تعديل كامل: كل تفاصيل الطلب بما فيها السعر والعمولات — السيرفر بيحسب الإجمالي وبيسوّي فرق العمولة
        $mk_cur  = wf_ui_currency($mk_edit_o);
        $mk_dig  = wf_is_digital_plat((string)($mk_edit_o['plat'] ?? ''));
        $mk_ship_cur = function_exists('wf_ship_currency') ? wf_ship_currency((string)($mk_edit_o['plat'] ?? ''), (string)($mk_edit_o['ship_country'] ?? '')) : 'ج.م';
        echo '<div class="card" data-ui="u-1ae67359 d13"><h3 data-ui="u-c35cd038 d26 d1 d19">تعديل الطلب <span dir="ltr">' . esc((string)$mk_edit_o['order_no']) . '</span> — كل التفاصيل</h3>'
           . '<p class="mut">الإجمالي بيتحسب على السيرفر بعد الحفظ (سعر القطعة × الكمية + الشحن − خصم الكمية)، وأي فرق في عمولتك بيتسوّى تلقائيًا في محفظتك. العمولات دائمًا بالجنيه المصري.</p>'
           . '<form method="post" class="form-grid">' . csrf_field() . '<input type="hidden" name="mk_order_action" value="edit"><input type="hidden" name="mk_order_no" value="' . esc((string)$mk_edit_o['order_no']) . '">'
           . '<div><label class="field-label">اسم العميل</label><input name="cname" required maxlength="100" value="' . esc((string)($mk_edit_o['cname'] ?? '')) . '"></div>'
           . '<div><label class="field-label">رقم الموبايل</label><input name="cphone" required inputmode="tel" value="' . esc((string)($mk_edit_o['cphone'] ?? '')) . '"></div>'
           . '<div><label class="field-label">رقم بديل / واتساب (اختياري)</label><input name="cphone2" inputmode="tel" value="' . esc((string)($mk_edit_o['cphone2'] ?? '')) . '"></div>'
           . '<div style="grid-column:1/-1"><label class="field-label">اسم المنتج</label><input name="pname" maxlength="120" value="' . esc((string)($mk_edit_o['pname'] ?? '')) . '"></div>'
           . '<div><label class="field-label">الكمية (1–99)</label><input name="qty" type="number" min="1" max="99" value="' . (int)($mk_edit_o['qty'] ?? 1) . '"></div>'
           . '<div><label class="field-label">سعر القطعة (' . esc($mk_cur) . ')</label><input name="unit" type="number" min="0" max="200000" step="0.01" value="' . esc((string)(float)($mk_edit_o['unit'] ?? 0)) . '"></div>'
           . '<div><label class="field-label">مصاريف الشحن (' . esc($mk_ship_cur) . ')</label><input name="ship" type="number" min="0" max="20000" step="0.01" value="' . esc((string)(float)($mk_edit_o['ship'] ?? 0)) . '"></div>'
           . '<div><label class="field-label">عمولتك على الطلب كله (ج.م)</label><input name="m_earn" type="number" min="0" max="50000" step="0.01" value="' . esc((string)(float)($mk_edit_o['m_earn'] ?? 0)) . '"></div>'
           . '<div><label class="field-label">العمولة الإضافية على القطعة (ج.م)' . ($mk_dig ? ' — الرقمي/السوشيال: مفيش' : '') . '</label><input name="extra_comm" type="number" min="0" max="' . esc((string)(float)MAX_MARKUP_AMOUNT) . '" step="0.01" value="' . esc((string)(float)($mk_edit_o['extra_comm'] ?? 0)) . '"' . ($mk_dig ? ' disabled' : '') . '>' . ($mk_dig ? '<input type="hidden" name="extra_comm" value="0">' : '') . '</div>'
           . '<div><label class="field-label">المحافظة / المنطقة</label><input name="city" maxlength="60" value="' . esc((string)($mk_edit_o['city'] ?? '')) . '"></div>'
           . '<div><label class="field-label">المدينة / المركز</label><input name="district" maxlength="60" value="' . esc((string)($mk_edit_o['district'] ?? '')) . '"></div>'
           . '<div><label class="field-label">المواصفات / العرض</label><input name="variant" maxlength="60" value="' . esc((string)($mk_edit_o['variant'] ?? '')) . '"></div>'
           . '<div><label class="field-label">اللون</label><input name="color" maxlength="60" value="' . esc((string)($mk_edit_o['color'] ?? '')) . '"></div>'
           . '<div><label class="field-label">المقاس</label><input name="size" maxlength="60" value="' . esc((string)($mk_edit_o['size'] ?? '')) . '"></div>'
           . '<div style="grid-column:1/-1"><label class="field-label">العنوان بالتفصيل</label><input name="address" maxlength="300" value="' . esc((string)($mk_edit_o['address'] ?? '')) . '"></div>'
           . '<div style="grid-column:1/-1"><label class="field-label">ملاحظات</label><input name="notes" maxlength="500" value="' . esc((string)($mk_edit_o['notes'] ?? '')) . '"></div>'
           . '<div style="grid-column:1/-1"><button class="btn pri" type="submit">حفظ التعديلات</button> <a class="btn outline" href="?mtab=orders">رجوع</a></div></form></div>';
    }
    echo '<div class="card" data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>رقم الطلب</th><th>التاريخ</th><th>المنتج</th><th>اللون والمقاس</th><th>العميل</th><th>الهاتف</th><th>المحافظة والعنوان</th><th>كمية</th><th>سعر البيع</th><th>الشحن</th><th>الإجمالي</th><th>عمولتي</th><th>الحالة</th><th>إجراءات</th></tr>';
    foreach (array_reverse($forders) as $o) {
        echo '<tr>';
        echo '<td dir="ltr" data-ui="u-75c8ccd8 d8e dc">' . esc($o['order_no'] ?? '—') . '</td>';
        echo '<td>' . esc(substr($o['at'] ?? '', 0, 10)) . '</td>';
        echo '<td><b>' . esc(mb_substr($o['pname'] ?? '', 0, 35)) . '</b></td>';
        echo '<td>' . esc(order_variant_label($o) ?: '—') . '</td>';
        echo '<td>' . esc($o['cname'] ?? '') . '</td>';
        echo '<td dir="ltr">' . esc($o['cphone'] ?? '') . (!empty($o['cphone2']) ? '<br><small class="mut">' . esc($o['cphone2']) . '</small>' : '') . '</td>';
        echo '<td><b>' . esc($o['city'] ?? '') . '</b> — ' . esc(mb_substr($o['address'] ?? '', 0, 35)) . '<br>' . wf_details_btn('تفاصيل الطلب ' . ($o['order_no'] ?? ''), wf_order_details_rows($o), 'كل التفاصيل') . '</td>';
        echo '<td>' . esc($o['qty'] ?? 1) . '</td>';
        echo '<td>' . number_format($o['unit'] ?? 0) . ' ' . esc(wf_ui_currency($o)) . '</td>';
        echo '<td>' . number_format($o['ship'] ?? 0) . ' ج.م</td>';
        echo '<td><b>' . number_format($o['total'] ?? 0) . ' ' . esc(wf_ui_currency($o)) . '</b></td>';
        echo '<td class="comm" data-ui="u-b135454c d1 d1x">+' . number_format($o['m_earn'] ?? 0, 2) . ' ج.م</td>';
        echo '<td>' . wf_order_badge($o['status'] ?? '') . wf_order_reasons_btns($o) . '</td>';
        if (wf_marketer_can_edit_order($o, $user)) {
            echo '<td><div style="display:flex;gap:6px;flex-wrap:wrap"><a class="btn sm outline" href="?mtab=orders&edit=' . urlencode((string)($o['order_no'] ?? '')) . '">تعديل</a>'
               . '<form method="post" data-confirm="هتلغي الطلب؟ هيفضل مسجّل عند الإدارة بحالة «ملغي» وهتتعكس عمولته." style="display:inline">' . csrf_field() . '<input type="hidden" name="mk_order_action" value="cancel"><input type="hidden" name="mk_order_no" value="' . esc((string)($o['order_no'] ?? '')) . '"><button class="btn sm" type="submit">إلغاء</button></form></div></td>';
        } else {
            echo '<td><span class="mut">—</span></td>';
        }
        echo '</tr>';
    }
    echo '</table></div>';
}
// ===== BROWSE PRODUCTS (FULL MARKETER DATA HUB) =====
elseif ($mtab === 'browse') { wfp('MTAB browse');
    // The filters operate on the entire catalogue before pagination, not only 24 visible cards.
    $mk_param = static function ($key, $fallback) {
        $value = $_GET[$key] ?? $fallback;
        return is_string($value) ? trim($value) : $fallback;
    };
    $mq = $mk_param('pq', '');
    $mcat = $mk_param('mcat', 'all');
    $mmode = $mk_param('mmode', 'physical');
    if (!in_array($mmode, ['physical', 'digital', 'social'], true)) $mmode = 'physical';
    // الدول من القايمة الموحّدة (config.php) — نفس دول المنتجات اليدوية وفلاتر الموقع
    $countries = ['all' => 'الكل'];
    foreach (wf_country_list() as $country_name) $countries[$country_name] = $country_name;
    $mcountry = $mk_param('country', 'all');
    if (!isset($countries[$mcountry]) || $mmode !== 'physical') $mcountry = 'all';
    $platforms = ['all', 'timehand', 'safqa', 'vendoor', 'angazny', 'm5zoon', 'shopmart', 'cezma', 'manual'];
    $mplat = $mk_param('plat', 'all');
    if (!in_array($mplat, $platforms, true) || $mmode !== 'physical') $mplat = 'all';
    $sorts = ['default'=>'الافتراضي', 'new'=>'أحدث المنتجات', 'featured'=>'المنتجات المميزة', 'viewed'=>'الأكثر مشاهدة', 'best'=>'الأكثر مبيعاً', 'commission'=>'أعلى عمولة مضافة', 'price_asc'=>'السعر: الأقل أولاً', 'price_desc'=>'السعر: الأعلى أولاً', 'name'=>'الاسم'];
    // الرقمي والسوشيال مافيهمش عمولة مضافة — نشيل خيار الترتيب ده من تبويباتهم
    if (($mmode ?? 'physical') !== 'physical') unset($sorts['commission']);
    $msort = $mk_param('sort', 'default');
    if (!isset($sorts[$msort])) $msort = 'default';
    $mpage = max(1, (int)$mk_param('mpage', '1'));
    $mper = 24;
    // تنويع كل زيارة للكتالوج الافتراضي (أحدث منتجات من كل أفلييت، بتتغير كل مرة الصفحة تتفتح)
    $mseed_url = null;
    $mseed = null;
    if ($mmode === 'physical' && $mcountry === 'all' && $mcat === 'all' && $mq === '' && $mplat === 'all' && $msort === 'default') {
        $mseed = isset($_GET['mseed']) ? max(1, (int)$_GET['mseed']) : mt_rand(1, 2147483646);
        $mseed_url = $mseed;
    }
    $st_saved_prods = $my_store_cfg['products'] ?? [];
    $mk_url = static function ($changes = []) use ($mmode, $mcat, $mq, $mcountry, $mplat, $msort, $mseed_url) {
        $params = array_replace(['mtab'=>'browse', 'mmode'=>$mmode, 'mcat'=>$mcat, 'pq'=>$mq, 'country'=>$mcountry, 'plat'=>$mplat, 'sort'=>$msort, 'mpage'=>1], $changes);
        foreach (['mcat'=>'all', 'pq'=>'', 'country'=>'all', 'plat'=>'all', 'sort'=>'default'] as $key=>$empty) {
            if ($params[$key] === $empty) unset($params[$key]);
        }
        if ($params['mpage'] <= 1) unset($params['mpage']);
        if ($mseed_url !== null && !array_diff_key($params, ['mtab' => 1, 'mmode' => 1, 'mpage' => 1])) $params['mseed'] = $mseed_url;
        return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . '#mkCatalogue';
    };

    $cats = categories_by_mode($mmode, $mcountry);
    $mcollection = in_array($msort, ['new', 'featured', 'viewed', 'best'], true) ? $msort : 'all';
    if ($mmode === 'physical') {
        $mall = physical_collection($mcollection, $mcountry, $mcat, $mq, $mplat, $mseed);
        if ($mplat === 'all' && $mcountry === 'all') {
            // The homepage's generic Egyptian feed excludes M5zoon; the requested affiliate hub includes it.
            $mall = mix_by_platform(array_merge($mall, physical_collection($mcollection, 'all', $mcat, $mq, 'm5zoon', $mseed)));
        }
        if ($mplat !== 'all' || $mcountry === 'all') {
            if ($mplat !== 'all') $cats = [];
            $category_plat = $mplat === 'all' ? 'm5zoon' : $mplat;
            foreach (platform_products_lite($category_plat) as $cat_product) {
                if ($mcountry !== 'all' && ($cat_product['country'] ?? 'مصر') !== $mcountry) continue;
                $cat_name = wf_normalize_category($cat_product['category'] ?? '');
                if ($cat_name === '' || in_array($cat_name, ['المنتجات الرقمية', 'سوشيال ميديا'], true)) continue;
                $cats[$cat_name] = ($cats[$cat_name] ?? 0) + 1;
            }
            arsort($cats);
        }
    } else {
        $digital_key = $mmode === 'digital' ? 'g2g' : 'social';
        $mres = sb_platform_products($digital_key, $mq, 1, PHP_INT_MAX, 'all', $mcat === 'all' ? '' : $mcat);
        $mall = $mres ? $mres[0] : [];
        if ($msort === 'new') {
            $mall = array_reverse($mall);
            // الأحدث فعلًا: ختم added_at تنازليًا (usort مستقر — القديم من غير ختم يفضل بترتيبه)
            usort($mall, function ($a, $b) {
                return strcmp((string)($b['added_at'] ?? ''), (string)($a['added_at'] ?? ''));
            });
        }
        elseif ($msort === 'featured') {
            // Same existing featured heuristic as the homepage; no fabricated feature flags/ratings.
            usort($mall, static function ($a, $b) {
                $cmp = mz_num($b['commission'] ?? 0) <=> mz_num($a['commission'] ?? 0);
                return $cmp ?: count($b['images'] ?? []) <=> count($a['images'] ?? []);
            });
        } elseif ($msort === 'viewed') {
            $viewed = jload('views.json', []);
            usort($mall, static function ($a, $b) use ($viewed) {
                return ($viewed[($b['_plat'] ?? '') . '#' . ($b['id'] ?? '')] ?? 0) <=> ($viewed[($a['_plat'] ?? '') . '#' . ($a['id'] ?? '')] ?? 0);
            });
        }
    }
    if ($msort === 'best') {
        // Match the homepage's sort=best selector using the existing order quantity data.
        $sold = [];
        foreach ($all_orders as $sale) {
            $sale_key = ($sale['plat'] ?? '') . '#' . ($sale['id'] ?? '');
            $sold[$sale_key] = ($sold[$sale_key] ?? 0) + ($sale['qty'] ?? 1);
        }
        usort($mall, static function ($a, $b) use ($sold) {
            return ($sold[($b['_plat'] ?? '') . '#' . ($b['id'] ?? '')] ?? 0) <=> ($sold[($a['_plat'] ?? '') . '#' . ($a['id'] ?? '')] ?? 0);
        });
    } elseif (in_array($msort, ['price_asc', 'price_desc', 'commission'], true)) {
        $ranks = [];
        foreach ($mall as $rank_product) {
            $rank_plat = $rank_product['_plat'] ?? '';
            $rank_key = $rank_plat . '#' . ($rank_product['id'] ?? '');
            $rank_extra = isset($st_saved_prods[$rank_key]) ? (float)($st_saved_prods[$rank_key]['extra_comm'] ?? 0) : marketer_store_product_comm($code, $rank_plat, (string)($rank_product['id'] ?? ''));
            $rank_base = list_price($rank_product, $rank_plat) ?: display_price($rank_product, $rank_plat);
            $rank_currency = wf_ui_currency_label(($rank_product['currency'] ?? '') ?: wf_country_currency($rank_product['country'] ?? 'مصر'));
            $ranks[$rank_key] = ['price'=>round($rank_base + $rank_extra, 2), 'commission'=>round($rank_extra, 2), 'currency'=>$rank_currency];
        }
        usort($mall, static function ($a, $b) use ($ranks, $msort) {
            $ra = $ranks[($a['_plat'] ?? '') . '#' . ($a['id'] ?? '')];
            $rb = $ranks[($b['_plat'] ?? '') . '#' . ($b['id'] ?? '')];
            $currency_cmp = strcmp($ra['currency'], $rb['currency']);
            if ($currency_cmp) return $currency_cmp; // Never pretend different currencies are converted/comparable.
            if ($msort === 'commission') return $rb['commission'] <=> $ra['commission'];
            return $msort === 'price_desc' ? $rb['price'] <=> $ra['price'] : $ra['price'] <=> $rb['price'];
        });
    } elseif ($msort === 'name') {
        usort($mall, static function ($a, $b) { return strnatcasecmp(aff_clean($a['name'] ?? '', true), aff_clean($b['name'] ?? '', true)); });
    }
    $mcount = count($mall);
    $mpages = max(1, (int)ceil($mcount / $mper));
    $mpage = min($mpage, $mpages);
    $mitems = array_slice($mall, ($mpage - 1) * $mper, $mper);

    echo '<div class="card" id="mkCatalogue" data-ui="u-64dbc598 d1r d8f d8g">';
    echo '<h2 data-ui="u-c5b7b8af d26 d1 d83 d8h">' . wf_svg_icon('box') . 'كتالوج المنتجات والعمولات</h2>';
    echo '<p data-ui="u-292457aa d4w d3s d19">حدّد عمولتك (للمنتجات)، أضف المنتج لمتجرك، وشارك رابطك. المنتجات الرقمية وخدمات السوشيال ميديا سعرها ثابت من غير عمولة مضافة.</p></div>';
    echo '<nav class="tabs" aria-label="نوع كتالوج المنتجات">';
    foreach (['physical'=>['box','المنتجات'], 'digital'=>['game','منتجات رقمية'], 'social'=>['megaphone','سوشيال ميديا']] as $mode_key=>$mode_label) {
        $mode_on = $mmode === $mode_key;
        echo '<a href="' . esc($mk_url(['mmode'=>$mode_key, 'mcat'=>'all', 'country'=>'all', 'plat'=>'all'])) . '"' . ($mode_on ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon($mode_label[0]) . esc($mode_label[1]) . '</a>';
    }
    echo '</nav>';
    if ($mmode === 'physical') {
        echo '<nav class="filter-chips-scroll" id="mkCountryFilter" aria-label="فلتر الدولة">';
        foreach ($countries as $country_key=>$country_label) {
            echo '<a href="' . esc($mk_url(['country'=>$country_key])) . '" class="chip' . ($mcountry === $country_key ? ' is-active' : '') . '"' . ($mcountry === $country_key ? ' aria-current="page"' : '') . '>' . esc($country_label) . '</a>';
        }
        echo '</nav>';
    }
    echo '<details class="catalog-categories"><summary>تصفية القسم: ' . esc($mcat === 'all' ? 'كل الأقسام' : $mcat) . '</summary><div class="tabs" data-ui="u-ded3e017 d49">';
    echo '<a href="' . esc($mk_url(['mcat'=>'all'])) . '"' . ($mcat === 'all' ? ' class="on" aria-current="page"' : '') . '>كل الأقسام</a>';
    foreach ($cats as $cn=>$ccn) echo '<a href="' . esc($mk_url(['mcat'=>(string)$cn])) . '"' . ($mcat === (string)$cn ? ' class="on" aria-current="page"' : '') . '>' . esc($cn) . '</a>';
    echo '</div></details>';
    echo '<form method="get" class="search" data-ui="u-c767c453 d62">';
    foreach (['mtab'=>'browse', 'mmode'=>$mmode, 'mcat'=>$mcat, 'country'=>$mcountry, 'plat'=>$mplat, 'sort'=>$msort] as $filter_key=>$filter_value) echo '<input type="hidden" name="' . esc($filter_key) . '" value="' . esc($filter_value) . '">';
    echo '<label class="sr-only" for="mkCatalogQuery">بحث في كتالوج المنتجات</label><input type="search" id="mkCatalogQuery" name="pq" value="' . esc($mq) . '" placeholder="ابحث عن منتج بالاسم أو الكود..."><button class="btn">بحث</button></form>';
    echo '<form method="get" class="catalog-toolbar catalog-filter-form" aria-label="فلاتر كتالوج المنتجات">';
    foreach (['mtab'=>'browse', 'mmode'=>$mmode, 'mcat'=>$mcat, 'pq'=>$mq, 'country'=>$mcountry] as $filter_key=>$filter_value) echo '<input type="hidden" name="' . esc($filter_key) . '" value="' . esc($filter_value) . '">';
    echo '<div><label for="mkCatalogSort" class="field-label">ترتيب حسب</label><select id="mkCatalogSort" name="sort" onchange="this.form.submit()">';
    foreach ($sorts as $sort_key=>$sort_label) echo '<option value="' . esc($sort_key) . '"' . ($msort === $sort_key ? ' selected' : '') . '>' . esc($sort_label) . '</option>';
    echo '</select></div>';
    echo '<input type="hidden" id="mkPlatformFilter" name="plat" value="' . esc($mplat) . '">';
    echo '<div class="catalog-filter-actions"><button class="btn outline" type="submit">تطبيق الفلاتر</button><a class="btn outline" href="' . esc($mk_url(['mcat'=>'all', 'pq'=>'', 'country'=>'all', 'plat'=>'all', 'sort'=>'default'])) . '">مسح الفلاتر</a></div></form>';
    if ($mitems) {
        echo '<div class="marketer-catalog" data-catalog-grid>';
        $mk_ui_states = []; // Presentation metadata only, never persisted.
        foreach ($mitems as $idx_m => $mp) {
            $pl = $mp['_plat'] ?? resolve_plat($mp['id'] ?? '');
            $pid_m = (string)($mp['id'] ?? '');
            $full_mp = find_product_by_id($pid_m, $pl) ?: $mp;
            $ex_m = isset($full_mp['extra']) && is_array($full_mp['extra']) ? $full_mp['extra'] : [];
            $img = product_first_image($full_mp, $pl);
            $all_imgs_m = clean_product_images($full_mp['images'] ?? []);
            $cname_m = aff_clean($full_mp['name'] ?? '', true);
            $cdesc_m = product_marketing_summary($full_mp, $pl);
            $base_p = list_price($full_mp, $pl) ?: display_price($full_mp, $pl);
            $sugg_p = raw_list_price($full_mp, $pl);
            $base_c = 0.0;
            $pkey_m = $pl . '#' . $pid_m;
            $in_st = isset($st_saved_prods[$pkey_m]);
            $is_digital_card = wf_is_digital_plat($pl);
            $saved_ex = $is_digital_card ? 0.0 : ($in_st ? (float)($st_saved_prods[$pkey_m]['extra_comm'] ?? 0) : marketer_store_product_comm($code, $pl, $pid_m));
            $sale_p = round($base_p + $saved_ex, 2);
            $tot_c = round($saved_ex, 2);

            $cols_m = clean_product_colors($full_mp, $pl);
            $sizes_m = clean_product_sizes($full_mp, $pl);

            $ad_copy_base = " " . $cname_m . "\n\n" . ($cdesc_m ? mb_substr($cdesc_m, 0, 500) . "\n\n" : '')
                . ($cols_m ? " الألوان المتاحة: " . implode(' - ', array_slice($cols_m, 0, 8)) . "\n" : '')
                . ($sizes_m ? " المقاسات المتاحة: " . implode(' - ', array_slice($sizes_m, 0, 10)) . "\n" : '')
                . " شحن سريع لجميع المحافظات والدفع عند الاستلام ومعاينة الطلب قبل الدفع ";

            $clean_p_url = 'product.php?id=' . urlencode($pid_m) . '&ref=' . urlencode($code);
            // المنتجات الرقمية والسوشيال تُطلب من صفحة الطلب السريع، والتقليدية من صفحة الهبوط
            $lp_rel_full = wf_client_order_url($pid_m, $pl, $code, $saved_ex, true);
            $lp_full_url = wf_client_order_url($pid_m, $pl, $code, $saved_ex, false);
            $order_btn_label = $is_digital_card ? 'اطلب لعميل' : 'طلب لعميل';
            $card_uid = 'mkc_' . $idx_m . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $pid_m);

            $mk_ui_states[$card_uid] = ['id'=>$pid_m,'plat'=>$pl,'name'=>$cname_m,'base_price'=>(float)$base_p,'img'=>$img,'ref'=>$code,'currency'=>wf_ui_currency($full_mp),'country'=>wf_product_country($full_mp),'ship_fee'=>function_exists('wf_product_shipping') ? wf_product_shipping($full_mp) : null,'colors'=>$cols_m,'sizes'=>$sizes_m,'copy'=>$ad_copy_base];
            echo '<article class="pcard marketer-product" id="' . esc($card_uid) . '" data-commission="' . esc($tot_c) . '" data-price="' . esc($sale_p) . '" data-name="' . esc($cname_m) . '" data-plat="' . esc($pl) . '" data-country="' . esc($full_mp['country'] ?? '') . '" data-card-state="1">';
            echo '<div class="pcard-img"><a href="' . esc($clean_p_url) . '" aria-label="' . esc($cname_m) . '"><img src="' . esc($img) . '" alt="' . esc($cname_m) . '" width="260" height="260" loading="lazy" decoding="async" referrerpolicy="no-referrer"></a><span class="badge ' . ($in_st ? 'green' : 'neutral') . ' marketer-store-badge" id="badge_' . esc($card_uid) . '">' . ($in_st ? 'في متجرك' : esc(wf_normalize_category($full_mp['category'] ?? '', 'منتج'))) . '</span></div>';
            echo '<h3 class="pcard-title"><a href="' . esc($clean_p_url) . '">' . esc($cname_m) . '</a></h3>';
            if ($cdesc_m !== '') echo '<p class="marketer-description">' . esc(mb_substr($cdesc_m, 0, 120)) . '</p>';
            echo '<p class="marketer-base-price">السعر الأساسي: <b>' . wf_ui_money($base_p) . ' ' . esc(wf_ui_currency($full_mp)) . '</b></p>';
            if ($is_digital_card) {
                // الرقمي والسوشيال ميديا: سعر ثابت من غير عمولة مضافة — مفيش خانة عمولة ولا «سعر العميل / ربحك»
                echo '<p class="marketer-preview" data-ui="u-1f8c4581 do dc d15"><span>سعر ثابت: <b>' . wf_ui_money($sale_p) . '</b> ' . esc(wf_ui_currency($full_mp)) . ' — من غير عمولة مضافة</span></p>';
            } else {
                echo '<label class="field-label" for="ex_' . esc($card_uid) . '">عمولتك المضافة للقطعة (' . esc(wf_ui_currency($full_mp)) . ')</label><input type="number" id="ex_' . esc($card_uid) . '" data-card-markup value="' . esc($saved_ex) . '" min="0" max="' . esc(MAX_MARKUP_AMOUNT) . '" step="5">';
                echo '<div class="marketer-preview"><span>سعر العميل: <b id="sp_' . esc($card_uid) . '">' . wf_ui_money($sale_p) . '</b> ' . esc(wf_ui_currency($full_mp)) . '</span><span>ربحك: <b id="tc_' . esc($card_uid) . '">+' . wf_ui_money($tot_c) . '</b> ' . esc(wf_ui_currency($full_mp)) . '</span></div>';
                echo '<p class="field-hint">بالنسبة للسعر الأساسي: <span id="tp_' . esc($card_uid) . '">' . ($base_p > 0 ? number_format($tot_c / $base_p * 100,1) . '%' : '—') . '</span> — المعاينة فقط؛ الحساب النهائي على الخادم.</p>';
            }
            echo '<div class="marketer-actions"><button type="button" class="btn pri sm" data-card-action="save">' . wf_svg_icon('store',16) . 'حفظ بمتجري</button>'
                . ($is_digital_card
                    ? '<a href="' . esc(wf_client_order_url($pid_m, $pl, $code, $saved_ex, true)) . '" class="btn outline sm">' . esc($order_btn_label) . '</a>'
                    : '<button type="button" class="btn outline sm" data-card-action="order">طلب لعميل</button>')
                . '</div>';
            echo '<details class="marketing-tools"><summary>' . wf_svg_icon('share',16) . 'روابط وأدوات التسويق</summary><div class="marketer-actions"><a id="lpa_' . esc($card_uid) . '" href="' . esc($lp_rel_full) . '" target="_blank" rel="noopener" class="btn sm outline">' . ($is_digital_card ? 'صفحة الطلب' : 'صفحة الهبوط') . '</a><button type="button" id="lpb_' . esc($card_uid) . '" data-url="' . esc($lp_full_url) . '" data-card-action="copy-link" class="btn sm outline">انسخ رابطي</button><button type="button" class="btn sm outline" data-card-action="copy-ad">نسخ الإعلان</button><a href="' . esc($clean_p_url) . '" class="btn sm outline">كل التفاصيل</a></div>';
            echo '<div class="share-actions" data-share-group="' . esc($card_uid) . '"><a class="btn sm outline" data-share="whatsapp" target="_blank" rel="noopener" href="https://wa.me/?text=' . urlencode($lp_full_url) . '">واتساب</a><a class="btn sm outline" data-share="facebook" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=' . urlencode($lp_full_url) . '">فيسبوك</a><a class="btn sm outline" data-share="telegram" target="_blank" rel="noopener" href="https://t.me/share/url?url=' . urlencode($lp_full_url) . '">تيليجرام</a></div>';
            if ($cols_m || $sizes_m || !empty($full_mp['sku'])) {
                echo '<div class="marketer-variants">';
                if (!empty($full_mp['sku'])) echo '<span class="badge neutral">SKU: ' . esc($full_mp['sku']) . '</span>';
                if ($cols_m) echo '<span class="badge neutral">الألوان: ' . esc(implode(' • ',array_slice($cols_m,0,3))) . '</span>';
                if ($sizes_m) echo '<span class="badge neutral">المقاسات: ' . esc(implode('/',array_slice($sizes_m,0,4))) . '</span>';
                echo '</div>';
            }
            echo wf_product_media_html($full_mp, false, $pl);
            echo '</details></article>';
        }
        echo '<script type="application/json" id="wfCatalogState">' . json_encode($mk_ui_states, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        echo '</div>';
        ?>

        <?php
        if ($mpages > 1) {
            echo '<nav class="pager" aria-label="ترقيم صفحات الكتالوج">';
            for ($i = max(1, $mpage - 3); $i <= min($mpages, $mpage + 3); $i++) {
                echo '<a href="' . esc($mk_url(['mpage'=>$i])) . '" class="' . ($i === $mpage ? 'on' : '') . '"' . ($i === $mpage ? ' aria-current="page"' : '') . '>' . $i . '</a>';
            }
            echo '</nav>';
        }
    } else {
        echo '<div class="card" data-ui="u-c4fdb063 df d71"><p data-ui="u-063acb8a d90 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-search></use></svg></p><p class="mut">لا توجد نتائج مطابقة — جرّب تغيير البلد أو القسم أو مسح الفلاتر</p></div>';
    }
}
// ===== EARNINGS =====
elseif ($mtab === 'earnings') { wfp('MTAB earnings');
    $exp = $ern = 0;
    foreach ($forders as $o) {
        $ok = status_key($o['status'] ?? '');
        if ($ok === 'delivered') $ern += ($o['m_earn'] ?? 0);
        elseif (!in_array($ok, ['cancelled', 'returned'], true)) $exp += ($o['m_earn'] ?? 0);
    }
    $myout = array_sum(array_map(function ($p) use ($user) { return ($p['user'] === $user['email'] && ($p['status'] ?? '') !== 'مرفوض') ? $p['amount'] : 0; }, jload('payouts.json', [])));
    echo '<div data-ui="u-9e82dcff d54"><h2 data-ui="u-769590a6 d26 d1"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> الأرباح والسحوبات</h2></div>';
    echo '<div class="kpis">';
    echo '<div class="kpi" data-ui="u-5f7b0709 d7m d3v"><b data-ui="u-0aebe4c1 d3v">' . number_format($me['balance'] ?? 0) . ' ج.م</b><span data-ui="u-7cb803b4 d7n">الرصيد المتاح </span></div>';
    echo '<div class="kpi"><b>' . number_format($ern) . ' ج.م</b><span>أرباح مكتسبة (تم التسليم)</span></div>';
    echo '<div class="kpi"><b>' . number_format($exp) . ' ج.م</b><span>أرباح في الطريق</span></div>';
    echo '<div class="kpi"><b>' . number_format($myout) . ' ج.م</b><span>سحوبات مدفوعة/معلقة</span></div>';
    echo '</div>';
    // ===== نظام السحب الجديد: محافظ إلكترونية · انستا باي · تحويل بنكي · بايبال =====
    payout_render_section($user);
    // Earnings per period breakdown
    echo '<div class="card" data-ui="u-89901732 dp"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> كشف حساب تفصيلي</h2>';
    echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>المنتج</th><th>الكمية</th><th>الإجمالي</th><th>العمولة</th><th>الحالة</th></tr>';
    foreach (array_reverse($forders) as $o) {
        $osk = status_key($o['status'] ?? '');
        $color = $osk === 'delivered' ? 'var(--success)' : (in_array($osk, ['cancelled', 'returned'], true) ? 'var(--danger-text)' : 'var(--warning-text)');
        echo '<tr><td>' . esc(mb_substr($o['pname'] ?? '', 0, 40)) . '</td><td>' . esc($o['qty'] ?? 1) . '</td><td>' . number_format($o['total'] ?? 0) . ' ' . esc(wf_ui_currency($o)) . '</td><td class="comm">+' . number_format($o['m_earn'] ?? 0) . ' ج.م</td><td>' . wf_order_badge($o['status'] ?? '') . '</td></tr>';
    }
    echo '</table></div>';
}
// ===== TEAM =====
elseif ($mtab === 'team') { wfp('MTAB team');
    $team_rate_pct = wf_team_rate_display(); // TODO: owner approval required before displaying a team percentage.
    echo '<div data-ui="u-9e82dcff d54"><h2 data-ui="u-f64c0b8c d26 dc">فريقي والإحالات</h2></div>';
    echo '<div class="kpis">';
    echo '<div class="kpi"><b>' . count($team) . '</b><span>أعضاء الفريق</span></div>';
    echo '<div class="kpi"><b>' . number_format($team_earn) . ' ج.م</b><span>أرباح الفريق</span></div>';
    echo '</div>';
    echo '<div class="alert info">' . wf_svg_icon('info') . '<p>نسبة إحالة الفريق غير معروضة لحين مراجعتها واعتمادها. الأرصدة والسجلات الحالية لم تتغير.</p></div>';
    // Invite link
    $invite_link = $base . '/register.php?ref=' . urlencode($code);
    echo '<div class="card" data-ui="u-9e82dcff d54"><h2>رابط الدعوة الخاص بك</h2><p class="mut">شارك الرابط لينضم المسوق لفريقك. نسبة مكافأة إحالة الفريق قيد المراجعة؛ لا تُعرض نسبة قبل اعتمادها</p>';
    echo '<div data-ui="u-3ecc8ff6 dh d1s"><input id="invlink" aria-label="رابط دعوة الفريق" readonly value="' . esc($invite_link) . '" dir="ltr" data-ui="u-28cdb008 d2i d6h d64 d1u do d19"><button class="btn" onclick="wfCopy(document.getElementById(\'invlink\').value);if(window.wfToast)wfToast(\'تم نسخ رابط الدعوة\');">نسخ الرابط</button></div>';
    echo '<p class="mut" data-ui="u-ded3e017 d49">كود الدعوة: <b dir="ltr">' . esc($code) . '</b></p></div>';
    // Team list
    echo '<div class="card"><h2>أعضاء فريقي (' . count($team) . ')</h2>';
    if ($team) {
        echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><thead><tr><th>الاسم</th><th>البريد الإلكتروني</th><th>تاريخ الانضمام</th><th>المتبقي من الفترة</th><th>طلباته</th><th>عمولتي</th></tr></thead><tbody>';
        foreach ($team as $t) {
            $left = max(0, 243 - floor((time() - strtotime($t['at'] ?? 'now')) / 86400));
            $tm_orders = array_filter($all_orders, function ($o) use ($t) { return ($o['ref'] ?? '') === ($t['ref_code'] ?? ''); });
            $tm_earn = array_sum(array_map(function ($o) { return $o['m_earn'] ?? 0; }, $tm_orders));
            $my_from_tm = round($tm_earn * REF_TEAM_RATE, 2);
            echo '<tr><td>' . esc($t['name']) . '</td><td dir="ltr">' . esc($t['email']) . '</td><td dir="ltr">' . esc(substr($t['at'] ?? '', 0, 10)) . '</td><td>' . ($left > 0 ? $left . ' يوم' : 'انتهت') . '</td><td>' . count($tm_orders) . '</td><td class="comm">+' . number_format($my_from_tm) . ' ج.م</td></tr>';
        }
        echo '</tbody></table></div>';
    } else echo '<p class="mut">لا يوجد أعضاء في فريقك بعد — شارك رابط الدعوة لبدء بناء فريقك.</p>';
    echo '</div>';
}
// ===== صفحات الهبوط (صفحة مستقلة في شريط لوحة المسوق) =====
elseif ($mtab === 'pages') { wfp('MTAB pages');
    // كل صفحة هبوط لوحدها: الرابط + كام شخص شافها + كام شخص طلب منها + المنتج اللي عليها
    $pg_days = (int)($_GET['ldays'] ?? 0);
    if (!in_array($pg_days, [0, 7, 30], true)) $pg_days = 0;
    $ls = wf_marketer_link_stats_safe($code, $pg_days);
    if (!empty($ls['meta']['error']) || !empty($ls['meta']['truncated'])) {
        echo '<div class="alert warning">' . wf_svg_icon('info') . '<p>' . esc((string)($ls['meta']['error'] ?: 'السجل كبير — الإحصاءات اتحسبت من آخر جزء منه بس.'))
           . ' جرّب فترة أقصر فوق عشان الأرقام تتظبط بسرعة.</p></div>';
    }
    $pg_labels = [0 => 'كل الوقت', 7 => 'آخر 7 أيام', 30 => 'آخر 30 يوم'];
    $pg_url = function ($d) { return '?mtab=pages' . ($d ? '&ldays=' . $d : ''); };

    echo '<div data-ui="u-9e82dcff d54"><h2 data-ui="u-769590a6 d26 d1">' . wf_svg_icon('link') . ' صفحات الهبوط</h2>';
    echo '<p class="mut">كل رابط هبوط خاص بك: الرابط، كام شخص شافها، وكام شخص طلب منها فعلاً — والأرقام هنا لصفحات الهبوط بس.</p></div>';
    echo wf_tabs(array_map(function ($d, $label) use ($pg_days, $pg_url) {
        return ['href' => $pg_url($d), 'label' => $label, 'on' => ($pg_days === $d)];
    }, array_keys($pg_labels), array_values($pg_labels)), ['label' => 'فترة إحصاءات صفحات الهبوط']);

    // صفحات الهبوط لوحدها: نجمعها من روابط المنتجات (بلاتفورم#منتج) — بدون المتجر
    $pg_rows = [];
    foreach ((array)$ls['links'] as $lk => $lr) {
        $p_plat = ''; $p_id = (string)$lk;
        if (strpos((string)$lk, '#') !== false) list($p_plat, $p_id) = explode('#', (string)$lk, 2);
        if ($p_id === '') continue;
        $pg_rows[$p_plat . '#' . $p_id] = [
            'plat' => $p_plat, 'id' => $p_id,
            'views' => (int)($lr['views'] ?? 0), 'visitors' => (int)($lr['visitors_count'] ?? 0),
            'orders' => (int)($lr['orders'] ?? 0), 'clients' => (int)($lr['clients_count'] ?? 0),
            'earn' => (float)($lr['earn'] ?? 0), 'last' => (string)($lr['last'] ?? ''),
        ];
    }
    // منتجات متجري: تظهر صفحات هبوطها حتى لو لسه مفيش زيارات (بأصفار) — ودي «المنتجات اللي عملتها» بروابطها
    foreach ((array)($my_store_cfg['products'] ?? []) as $sp_key => $sp_meta) {
        $sp_plat = (string)($sp_meta['plat'] ?? ''); $sp_id = (string)($sp_meta['id'] ?? '');
        if ($sp_plat === '' && strpos((string)$sp_key, '#') !== false) list($sp_plat, $sp_id) = explode('#', (string)$sp_key, 2);
        if ($sp_id === '') continue;
        if (!isset($pg_rows[$sp_plat . '#' . $sp_id])) {
            $pg_rows[$sp_plat . '#' . $sp_id] = ['plat' => $sp_plat, 'id' => $sp_id, 'views' => 0, 'visitors' => 0, 'orders' => 0, 'clients' => 0, 'earn' => 0.0, 'last' => ''];
        }
    }
    uasort($pg_rows, function ($a, $b) { return [$b['views'], $b['orders'], $b['visitors']] <=> [$a['views'], $a['orders'], $a['visitors']]; });

    $pg_products = count((array)($my_store_cfg['products'] ?? []));
    $pg_views = $pg_visitors = $pg_orders = $pg_clients = 0; $pg_earn = 0.0;
    foreach ($pg_rows as $r) {
        $pg_views += $r['views']; $pg_visitors += $r['visitors']; $pg_orders += $r['orders'];
        $pg_clients += $r['clients']; $pg_earn += $r['earn'];
    }
    echo '<div class="kpis">';
    echo '<div class="kpi"><b>' . number_format(count($pg_rows)) . '</b><span>صفحات هبوط</span></div>';
    echo '<div class="kpi"><b>' . number_format($pg_products) . '</b><span>منتجاتك في المتجر</span></div>';
    echo '<div class="kpi"><b>' . number_format($pg_views) . '</b><span>كام مشاهدة</span></div>';
    echo '<div class="kpi"><b>' . number_format($pg_visitors) . '</b><span>كام شخص شافها</span></div>';
    echo '<div class="kpi"><b>' . number_format($pg_clients) . '</b><span>كام شخص طلب منها</span></div>';
    echo '<div class="kpi"><b>' . number_format($pg_orders) . '</b><span>عدد الطلبات</span></div>';
    echo '<div class="kpi"><b>+' . number_format($pg_earn, 2) . ' ج.م</b><span>عمولتها</span></div>';
    echo '</div>';
    echo '<div class="alert info">' . wf_svg_icon('info') . '<p>«كام شخص شافها» = زوار مختلفون (زيارة فريدة لكل جهاز)، و«كام شخص طلب منها» = عملاء مختلفون بأرقام موبايل مختلفة — مش عدد الطلبات. طلبات المتجر مش محسوبة هنا.</p></div>';

    if (!$pg_rows) {
        echo '<div class="card"><p class="mut">لسه مفيش صفحات هبوط. اضغط «' . 'كتالوج المنتجات والعمولات' . '» وضيف منتجات لمتجرك، وهتلاقي رابط صفحة الهبوط لكل منتج هنا فوراً.</p></div>';
    }
    foreach ($pg_rows as $lk => $r) {
        $lprod = find_product_by_id((string)$r['id'], (string)$r['plat']);
        $lname = aff_clean((string)($lprod['name'] ?? ('منتج #' . $r['id'])), true);
        $lcat = wf_normalize_category($lprod['category'] ?? '', 'غير مصنّف');
        $limg = product_first_image($lprod ?: [], (string)$r['plat']);
        $lcomm = marketer_store_product_comm($code, (string)$r['plat'], (string)$r['id']);
        $lrel = '../' . ($is_digit = wf_is_digital_plat((string)$r['plat']) ? 'dashboard/quick_order.php' : 'landing.php')
              . '?id=' . urlencode((string)$r['id']) . '&ref=' . urlencode($code)
              . ($lcomm > 0 && !$is_digit ? '&comm=' . $lcomm : '');
        $lurl = rtrim($base, '/') . '/' . ltrim($lrel, './');
        $cv = (int)$r['visitors']; $co = (int)$r['orders'];
        echo '<div class="card" data-ui="u-9e82dcff d54"><div class="row-actions" data-ui="u-3c8ea4da dh d1o d1p dj">';
        if ($limg !== '') echo '<img src="' . esc($limg) . '" alt="' . esc($lname) . '" width="64" height="64" loading="lazy" data-ui="u-aef7c762 d87 d88 d5q d1u d4u dt">';
        echo '<div data-ui="u-3b1c15ba d1n d1o"><b>' . esc(mb_substr($lname, 0, 60)) . '</b><div class="mut">' . esc($lcat) . ' • ' . esc($r['plat']) . ($r['last'] !== '' ? ' • آخر زيارة: ' . esc(substr($r['last'], 0, 16)) : '') . '</div></div></div>';
        echo '<div class="kpis" data-ui="u-6f2f0a59 d54">';
        echo '<div class="kpi"><b>' . number_format($r['views']) . '</b><span>مشاهدات</span></div>';
        echo '<div class="kpi"><b>' . number_format($cv) . '</b><span>كام شخص شافها</span></div>';
        echo '<div class="kpi"><b>' . number_format((int)$r['clients']) . '</b><span>كام شخص طلب منها</span></div>';
        echo '<div class="kpi"><b>' . number_format($co) . '</b><span>طلبات</span></div>';
        echo '<div class="kpi"><b>' . ($cv ? round($co / $cv * 100, 1) : 0) . '%</b><span>نسبة التحويل</span></div>';
        echo '<div class="kpi"><b>+' . number_format((float)$r['earn'], 2) . ' ج.م</b><span>العمولة</span></div>';
        echo '</div>';
        echo '<div data-ui="u-e53e8630 dh d1s dj"><input readonly aria-label="رابط صفحة الهبوط" value="' . esc($lurl) . '" dir="ltr">';
        echo '<a class="btn sm" target="_blank" rel="noopener" href="' . esc($lrel) . '">' . wf_svg_icon('eye', 16) . ($is_digit ? 'فتح صفحة الطلب' : 'فتح صفحة الهبوط') . '</a>';
        echo '<button type="button" class="btn sm outline" data-copy="' . esc($lurl) . '">' . wf_svg_icon('copy', 16) . 'نسخ الرابط</button></div></div>';
    }

    // ملخص المتجر (لوحده) حتى تبقى الصورة كاملة: المتجر مش صفحة هبوط
    echo '<div class="card" data-ui="u-83e33068 d54"><h2>' . wf_svg_icon('store') . ' المتجر (منفصل عن صفحات الهبوط)</h2>';
    echo '<p class="mut">متجرك له مشاهداته وزواره وطلباته لوحده، وبيحمل كل منتجاتك (' . number_format($pg_products) . ' منتج).</p>';
    echo '<div class="kpis">';
    echo '<div class="kpi"><b>' . number_format((int)($ls['store']['views'] ?? 0)) . '</b><span>مشاهدات المتجر</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)($ls['store']['visitors'] ?? 0)) . '</b><span>زوار المتجر</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)($ls['store']['clients'] ?? 0)) . '</b><span>كام شخص طلب من المتجر</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)($ls['store']['orders'] ?? 0)) . '</b><span>طلبات المتجر</span></div>';
    echo '</div>';
    echo '<div data-ui="u-e53e8630 dh d1s dj"><input readonly aria-label="رابط متجري" value="' . esc($my_store_url) . '" dir="ltr">';
    echo '<a class="btn" target="_blank" rel="noopener" href="../store.php?store=' . urlencode($code) . '">' . wf_svg_icon('store', 16) . 'فتح المتجر</a>';
    echo '<button type="button" class="btn outline" data-copy="' . esc($my_store_url) . '">' . wf_svg_icon('copy', 16) . 'نسخ رابط المتجر</button>';
    echo '<a class="btn outline" href="?mtab=store">' . wf_svg_icon('settings', 16) . 'إدارة متجري</a>';
    if (function_exists('amz_stats')) echo '<a class="btn outline" href="amazon.php">' . wf_svg_icon('store', 16) . 'أفلييت أمازون</a>';
    if (function_exists('jmi_stats')) echo '<a class="btn outline" href="jumia.php">' . wf_svg_icon('store', 16) . 'أفلييت جوميا</a>';
    echo '</div></div>';
}
// ===== LINKS =====
elseif ($mtab === 'links') { wfp('MTAB links');
    // ===== إحصاءات الروابط: كام شخص دخل كل رابط وكام شخص طلب منه (قراءة فقط) =====
    $ls_days = (int)($_GET['ldays'] ?? 0);
    if (!in_array($ls_days, [0, 7, 30], true)) $ls_days = 0;
    $ls = wf_marketer_link_stats_safe($code, $ls_days, $ls_cats);
    if (!is_array($ls_cats) || $ls_cats === []) $ls_cats = [];
    if (!empty($ls['meta']['error']) || !empty($ls['meta']['truncated'])) {
        echo '<div class="alert warning">' . wf_svg_icon('info') . '<p>' . esc((string)($ls['meta']['error'] ?: 'السجل كبير — الإحصاءات اتحسبت من آخر جزء منه بس.'))
           . ' جرّب «آخر 7 أيام» أو «آخر 30 يوم» فوق، أو قلّل عدد الروابط النشطة.</p></div>';
    }
    $ls_labels = [0 => 'كل الوقت', 7 => 'آخر 7 أيام', 30 => 'آخر 30 يوم'];
    $ls_period_url = function ($d) use ($mtab) { return '?mtab=' . $mtab . ($d ? '&ldays=' . $d : ''); };

    echo '<div data-ui="u-9e82dcff d54"><h2 data-ui="u-769590a6 d26 d1">' . wf_svg_icon('link') . ' روابطي التسويقية وإحصاءاتها</h2></div>';
    echo wf_tabs(array_map(function ($d, $label) use ($ls_days, $ls_period_url) {
        return ['href' => $ls_period_url($d), 'label' => $label, 'on' => ($ls_days === $d)];
    }, array_keys($ls_labels), array_values($ls_labels)), ['label' => 'فترة إحصاءات الروابط']);

    // ملخص سريع: صفحات الهبوط لوحدها، والمتجر لوحده (من غير أي خلط بينهم)
    $lp_t = $ls['landing'];
    $st_t = $ls['store'];
    echo '<div class="kpis">';
    echo '<div class="kpi"><b>' . number_format((int)$lp_t['pages']) . '</b><span>صفحات هبوط شغّالة</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)$lp_t['views']) . '</b><span>مشاهدات صفحات الهبوط</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)$lp_t['visitors']) . '</b><span>زوار صفحات الهبوط</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)$lp_t['orders']) . '</b><span>طلبات من صفحات الهبوط</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)$lp_t['clients']) . '</b><span>أشخاص طلبوا من الهبوط</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)$st_t['views']) . '</b><span>مشاهدات المتجر</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)$st_t['orders']) . '</b><span>طلبات المتجر</span></div>';
    echo '<div class="kpi"><b>' . number_format((int)$st_t['clients']) . '</b><span>أشخاص طلبوا من المتجر</span></div>';
    echo '</div>';
    echo '<div class="alert info">' . wf_svg_icon('info') . '<p>الأرقام مفصولة تمامًا: كل صفحة هبوط ليها مشاهداتها وزوارها وطلباتها لوحدها، والمتجر ليه مشاهداته وزواره وطلباته لوحده — طلب المتجر مبيتحسبش على صفحة الهبوط والعكس.</p></div>';

    // ===== المتجر لوحده: كام مشاهدة وكام شخص طلب منه =====
    $store_visitors = (int)$ls['store']['visitors'];
    $store_orders = (int)$ls['store']['orders'];
    $store_clients = (int)($ls['store']['clients'] ?? 0);
    $store_conv = $store_visitors > 0 ? round($store_orders / $store_visitors * 100, 2) : 0;
    echo '<div class="card" data-ui="u-83e33068 d54 d8f d91"><h2>' . wf_svg_icon('store') . ' المتجر (لوحده): المشاهدات والطلبات</h2>';
    echo '<p class="mut">رابط متجرك المستقل — كل الأرقام هنا خاصة بالمتجر فقط، منفصلة عن صفحات الهبوط.</p>';
    echo '<div class="kpis">';
    echo '<div class="kpi"><b>' . number_format((int)$ls['store']['views']) . '</b><span>مشاهدات المتجر</span></div>';
    echo '<div class="kpi"><b>' . number_format($store_visitors) . '</b><span>زوار المتجر (زيارات فريدة)</span></div>';
    echo '<div class="kpi"><b>' . number_format($store_orders) . '</b><span>طلبات المتجر</span></div>';
    echo '<div class="kpi"><b>' . number_format($store_clients) . '</b><span>أشخاص طلبوا من المتجر</span></div>';
    echo '<div class="kpi"><b>' . $store_conv . '%</b><span>نسبة التحويل</span></div>';
    echo '<div class="kpi"><b>+' . number_format((float)$ls['store']['earn'], 2) . ' ج.م</b><span>عمولة المتجر</span></div>';
    echo '</div>';
    echo '<div class="alert info">' . wf_svg_icon('info') . '<p>«أشخاص طلبوا» = عدد العملاء المختلفين (تليفونات مختلفة) اللي عملوا طلبات — مش عدد الطلبات. لو نفس العميل طلب مرتين بيتحسب شخص واحد.</p></div>';
    echo '<div data-ui="u-e53e8630 dh d1s dj">';
    echo '<input id="storelink" aria-label="رابط متجري" readonly value="' . esc($my_store_url) . '" dir="ltr">';
    echo '<a href="../store.php?store=' . urlencode($code) . '" target="_blank" class="btn"><span>' . wf_svg_icon('store', 16) . 'فتح المتجر</span></a>';
    echo '<button type="button" class="btn outline" data-copy="' . esc($my_store_url) . '">' . wf_svg_icon('copy', 16) . 'نسخ الرابط</button>';
    echo '<a href="?mtab=store" class="btn outline">' . wf_svg_icon('settings', 16) . 'إدارة منتجات المتجر</a>';
    echo '</div></div>';

    // روابط صفحات الهبوط حسب القسم (كاتيجوري)
    echo '<div class="card" data-ui="u-9e82dcff d54"><h2>' . wf_svg_icon('box') . ' صفحات الهبوط حسب القسم (كاتيجوري)</h2>';
    echo '<p class="mut">تجميع صفحات الهبوط بس (بدون المتجر) حسب قسم المنتج: عدد الصفحات، الزوار، المشاهدات، الطلبات.</p>';
    if ($ls_cats) {
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><thead><tr><th>القسم (كاتيجوري)</th><th>روابط</th><th>زوار</th><th>مشاهدات</th><th>طلبات</th><th>تحويل</th><th>عمولة</th></tr></thead><tbody>';
        foreach ($ls_cats as $cat_row) {
            $cv = (int)$cat_row['visitors'];
            $co = (int)$cat_row['orders'];
            echo '<tr><td><b>' . esc($cat_row['category']) . '</b></td><td>' . (int)$cat_row['links'] . '</td><td>' . number_format($cv) . '</td><td>' . number_format((int)$cat_row['views']) . '</td><td>' . number_format($co) . '</td><td>' . ($cv ? round($co / $cv * 100, 1) : 0) . '%</td><td class="comm">+' . number_format((float)$cat_row['earn'], 2) . ' ج.م</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="mut">لا توجد زيارات أو طلبات مسجلة على روابطك في هذه الفترة بعد. انسخ أي رابط من الأسفل وشاركه، وهتظهر الإحصاءات هنا فوراً.</p>';
    }
    echo '</div>';

    // ===== صفحات الهبوط (لوحدها): كل صفحة وكام مشاهدة وكام شخص طلب منها =====
    echo '<div class="card" data-ui="u-9e82dcff d54"><h2>' . wf_svg_icon('link') . ' صفحات الهبوط (لوحدها): مشاهدات / زوار / طلبات</h2>';
    echo '<p class="mut">كل صفحة هبوط على حدة: كام واحد شافها، كام زائر دخلها، وكام شخص طلب منها فعلاً. أي طلب جاي من المتجر مبيتحسبش هنا.</p>';
    $lp_rows = [];
    foreach ((array)$ls['links'] as $lk => $lr) {
        $p_plat = ''; $p_id = (string)$lk;
        if (strpos((string)$lk, '#') !== false) list($p_plat, $p_id) = explode('#', (string)$lk, 2);
        $lp_rows[$p_plat . '#' . $p_id] = [
            'plat' => $p_plat, 'id' => $p_id,
            'views' => (int)($lr['views'] ?? 0), 'visitors' => (int)($lr['visitors_count'] ?? 0),
            'orders' => (int)($lr['orders'] ?? 0), 'clients' => (int)($lr['clients_count'] ?? 0),
            'earn' => (float)($lr['earn'] ?? 0), 'last' => (string)($lr['last'] ?? ''),
        ];
    }
    // صفحات المنتجات المنشورة في متجري تظهر حتى لو لسه مفيش زيارات (بأصفار)
    foreach ((array)($my_store_cfg['products'] ?? []) as $sp_key => $sp_meta) {
        $sp_plat = (string)($sp_meta['plat'] ?? '');
        $sp_id = (string)($sp_meta['id'] ?? '');
        if ($sp_plat === '' && strpos((string)$sp_key, '#') !== false) list($sp_plat, $sp_id) = explode('#', (string)$sp_key, 2);
        if ($sp_id === '') continue;
        if (!isset($lp_rows[$sp_plat . '#' . $sp_id])) {
            $lp_rows[$sp_plat . '#' . $sp_id] = ['plat' => $sp_plat, 'id' => $sp_id, 'views' => 0, 'visitors' => 0, 'orders' => 0, 'clients' => 0, 'earn' => 0.0, 'last' => ''];
        }
    }
    uasort($lp_rows, function ($a, $b) {
        return [$b['orders'], $b['views'], $b['visitors']] <=> [$a['orders'], $a['views'], $a['visitors']];
    });
    if ($lp_rows) {
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><thead><tr><th>صفحة الهبوط</th><th>القسم</th><th>مشاهدات</th><th>زوار</th><th>أشخاص طلبوا</th><th>طلبات</th><th>تحويل</th><th>عمولة</th><th>آخر زيارة</th><th>الرابط</th></tr></thead><tbody>';
        foreach ($lp_rows as $lk => $lr) {
            $lprod = find_product_by_id((string)$lr['id'], (string)$lr['plat']);
            $lname = aff_clean((string)($lprod['name'] ?? ('منتج #' . $lr['id'])), true);
            $lcat = wf_normalize_category($lprod['category'] ?? '', 'غير مصنّف');
            $lcomm = marketer_store_product_comm($code, (string)$lr['plat'], (string)$lr['id']);
            $lurl = $base . '/landing.php?id=' . urlencode((string)$lr['id']) . '&ref=' . urlencode($code) . ($lcomm > 0 ? '&comm=' . $lcomm : '');
            $lrel = '../landing.php?id=' . urlencode((string)$lr['id']) . '&ref=' . urlencode($code) . ($lcomm > 0 ? '&comm=' . $lcomm : '');
            $lv = (int)$lr['visitors'];
            $lo = (int)$lr['orders'];
            echo '<tr><td><b>' . esc(mb_substr($lname, 0, 45)) . '</b></td><td>' . esc($lcat) . '</td>';
            echo '<td>' . number_format((int)$lr['views']) . '</td><td>' . number_format($lv) . '</td>';
            echo '<td>' . number_format((int)$lr['clients']) . '</td><td>' . number_format($lo) . '</td>';
            echo '<td>' . ($lv ? round($lo / $lv * 100, 1) : 0) . '%</td><td class="comm">+' . number_format((float)$lr['earn'], 2) . ' ج.م</td>';
            echo '<td>' . esc(substr((string)$lr['last'], 0, 16)) . '</td>';
            echo '<td><div data-ui="u-e53e8630 dh d1s dj"><input readonly aria-label="رابط صفحة الهبوط" value="' . esc($lurl) . '" dir="ltr"><a class="btn sm" target="_blank" rel="noopener" href="' . esc($lrel) . '">' . wf_svg_icon('eye', 16) . 'فتح</a><button type="button" class="btn sm outline" data-copy="' . esc($lurl) . '">' . wf_svg_icon('copy', 16) . 'نسخ</button></div></td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="mut">لسه مفيش صفحات هبوط. ضيف منتجات لمتجرك من «إدارة متجري» أو استخدم البحث تحت وانسخ رابط صفحة الهبوط وشاركه.</p>';
    }
    echo '</div>';

    echo '<div class="card" data-ui="u-9e82dcff d54"><h2>' . wf_svg_icon('calc') . ' هامش الربح الافتراضي</h2><p class="mut">حدد هامشك الإضافي: نسبة % أو مبلغ ثابت — يُضاف لعمولتك في روابطك.</p>';
    echo '<form method="post" class="row-actions">' . csrf_field();
    echo '<label class="sr-only" for="marginPctInput">هامش الربح</label>';
    echo '<select name="price_mode" aria-label="نوع الهامش"><option value="pct"' . (($me['price_mode'] ?? 'pct') === 'pct' ? ' selected' : '') . '>نسبة %</option><option value="fixed"' . (($me['price_mode'] ?? '') === 'fixed' ? ' selected' : '') . '>مبلغ ثابت (ج.م)</option></select>';
    echo '<input id="marginPctInput" name="margin_pct" type="number" step="0.5" min="0" max="500" value="' . esc($me['margin_pct'] ?? 15) . '">';
    echo '<button class="btn">حفظ السعر</button></form>';
    echo '<p class="mut">أي هامش إضافي تحدده يُضاف لرصيدك عند تسليم الطلب.</p></div>';

    // Generate links for top products
    echo '<div class="card" data-ui="u-9e82dcff d54"><h2>' . wf_svg_icon('link') . ' روابط صفحات الهبوط السريعة للمنتجات</h2><p class="mut">انسخ رابط صفحة الهبوط المباشر لأي منتج وشاركه على واتساب / فيسبوك / إنستجرام / تيك توك.</p>';
    echo '<form method="get" class="search"><input type="hidden" name="mtab" value="links"><label class="sr-only" for="lqInput">بحث عن منتج</label><input id="lqInput" name="lq" value="' . esc($_GET['lq'] ?? '') . '" placeholder="ابحث عن منتج لنسخ رابط هبوطه..."><button class="btn">بحث</button></form>';
    $lq = trim($_GET['lq'] ?? '');
    $lres = sb_platform_products('all', $lq, 1, 15);
    $litems = $lres ? $lres[0] : [];
    if ($litems) {
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><thead><tr><th>المنتج</th><th>السعر الأساسي</th><th>عمولتك المضافة</th><th>رابط صفحة الهبوط</th><th>رابط الموقع الأساسي</th><th></th></tr></thead><tbody>';
        foreach ($litems as $lp) {
            $lpl = $lp['_plat'] ?? resolve_plat($lp['id'] ?? '');
            $lpr = list_price($lp, $lpl) ?: display_price($lp, $lpl);
            $lcm = wf_is_digital_plat($lpl) ? 0.0 : marketer_store_product_comm($code, $lpl, (string)$lp['id']);
            $link = landing_page_url((string)$lp['id'], $code, $lcm, $lpl);
            $rel_lp = '../landing.php?id=' . urlencode((string)$lp['id']) . '&ref=' . urlencode($code) . ($lcm > 0 ? '&comm=' . $lcm : '');
            $mlink = main_site_product_url((string)$lp['id'], $code, $lcm); // نفس العمولة، بس بيفتح صفحة المنتج في الموقع الأساسي
            $l_is_digital = wf_is_digital_plat($lpl);
            echo '<tr><td><b>' . esc(mb_substr($lp['name'] ?? '', 0, 45)) . '</b></td><td>' . number_format($lpr, 2) . ' ' . esc(wf_ui_currency($lp)) . '</td><td class="comm">' . ($l_is_digital ? '— <span class="mut">سعر ثابت</span>' : '<form method="post" class="row-actions">' . csrf_field() . '<input type="hidden" name="link_comm_action" value="1"><input type="hidden" name="plat" value="' . esc($lpl) . '"><input type="hidden" name="id" value="' . esc((string)$lp['id']) . '"><input type="number" name="extra_comm" value="' . esc((string)$lcm) . '" min="0" max="' . esc((string)MAX_MARKUP_AMOUNT) . '" step="5" inputmode="decimal" aria-label="عمولتك المضافة للقطعة" style="width:90px"> <span class="mut">' . esc(wf_ui_currency($lp)) . '</span> <button type="submit" class="btn sm outline">حفظ</button></form>') . '</td>';
            echo '<td><input aria-label="رابط صفحة الهبوط" readonly value="' . esc($link) . '" dir="ltr"></td>';
            echo '<td><input aria-label="رابط الموقع الأساسي" readonly value="' . esc($mlink) . '" dir="ltr"> <button type="button" class="btn sm outline" data-copy="' . esc($mlink) . '">' . wf_svg_icon('copy', 16) . 'نسخ</button></td>';
            echo '<td><div data-ui="u-e53e8630 dh d1s dj"><a class="btn sm" target="_blank" rel="noopener" href="' . esc($rel_lp) . '">' . wf_svg_icon('eye', 16) . 'هبوط</a><button type="button" class="btn sm outline" data-copy="' . esc($link) . '">' . wf_svg_icon('copy', 16) . 'نسخ</button></div></td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="mut">مفيش نتائج مطابقة للبحث. جرّب كلمة تانية.</p>';
    }
    echo '</div>';

    // Click history
    echo '<div class="card"><h2>' . wf_svg_icon('chart') . ' سجل الضغطات (' . count($clicks) . ')</h2>';
    if ($clicks) {
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><thead><tr><th>التاريخ</th><th>المنتج</th><th>النوع</th></tr></thead><tbody>';
        foreach (array_reverse(array_slice($clicks, -30)) as $c) {
            echo '<tr><td>' . esc(substr((string)($c['at'] ?? ''), 0, 16)) . '</td><td>' . esc($c['id'] ?? '') . '</td><td>' . esc($c['type'] ?? '') . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="mut">لا توجد ضغطات بعد.</p>';
    }
    echo '</div>';
}
// ===== SETTINGS =====
elseif ($mtab === 'settings') { wfp('MTAB settings');
    echo '<div data-ui="u-9e82dcff d54"><h2 data-ui="u-769590a6 d26 d1"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-settings></use></svg> إعدادات حسابي</h2></div>';
    echo '<div class="card"><table class="data-table rtable" data-ui-table="pending" aria-busy="true">';
    echo '<tr><td data-ui="u-d7d5fd7a dm d94">الاسم</td><td>' . esc($me['name'] ?? '') . '</td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">الإيميل</td><td dir="ltr">' . esc($me['email'] ?? '') . '</td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">كود المسوق</td><td dir="ltr"><b>' . esc($code) . '</b></td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">تاريخ الانضمام</td><td>' . esc(substr($me['at'] ?? '', 0, 10)) . '</td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">الدور</td><td>مسوق </td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">مُدعو من</td><td>' . esc($me['referred_by'] ?? '—') . '</td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">نطاق السعر</td><td>' . esc(($me['price_mode'] ?? 'pct') === 'fixed' ? '+' . ($me['margin_pct'] ?? 0) . ' ج.م' : ($me['margin_pct'] ?? 0) . '%') . '</td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">الرصيد</td><td><b>' . number_format($me['balance'] ?? 0) . ' ج.م</b></td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">إجمالي الأرباح</td><td><b>' . number_format($myearn) . ' ج.م</b></td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">عدد طلباتي</td><td>' . $myorders . '</td></tr>';
    echo '<tr><td data-ui="u-49bd2e85 dm">الفريق</td><td>' . count($team) . ' عضو</td></tr>';
    echo '</table></div>';
}
layout_end();
