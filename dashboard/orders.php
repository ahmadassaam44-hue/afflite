<?php $PUBLIC_PAGE = true; require 'layout.php';
// طلب بدون تسجيل: يُقبل برقم الموبايل كهوية
$guest_mode = !current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $guest_mode && !empty($_POST['id'])) {
    $_GUEST_OK = true;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) {
    $pid    = trim((string)$_POST['id']);
    $plat   = resolve_plat($pid, $_POST['plat'] ?? '');
    $qty    = max(1, min(999, (int)($_POST['qty'] ?? 1)));
    $ref    = active_ref($_POST['ref'] ?? '');
    $extra_comm = (isset($_POST['extra_comm']) && is_numeric($_POST['extra_comm']) && (float)$_POST['extra_comm'] >= 0) ? (float)$_POST['extra_comm'] : null;

    // «سعر الاتفاق مع العميل» اتشال من الموقع كله: مفيش سعر بيُدخل من المتصفح
    // 🔴 السعر من السيرفر مع دعم العمولة الإضافية المحددة من المسوق على المتجر أو صفحة الهبوط
    $raw_req = $_POST['unit_price'] ?? ($_POST['sale_price'] ?? null);
    $req    = ($raw_req !== null && (float)$raw_req > 0) ? (float)$raw_req : null;
    $honor_agreed = false;
    // خصم الكمية لكل الطلبات (بيتحسب تحت بعد ما نعرف البلاتفورم النهائي من السيرفر)
    $bundle_disc = 0.0;
    $line   = server_order_line($pid, $plat, $qty, $req, $ref, $extra_comm, $honor_agreed);

    if (!$line) {
        $order_err = '⚠️ المنتج غير متاح حالياً — جرّب منتج تاني';
    } else {
        $prod = $line['product'];
        $plat = $line['plat'];
        // خصم الكمية: LP_BUNDLE_DISCOUNT عن كل قطعة بعد الأولى — فيزيائي فقط (الرقمي والسوشيال مستثنيان)
        // والمنتجات اللي سعرها مش بالجنيه مستثناة كمان: الخصم مبلغ ثابت بالجنيه والموقع ما بيحوّلش عملات
        $bundle_disc = function_exists('wf_bulk_discount') ? (float)wf_bulk_discount($qty, $plat, $prod) : 0.0;
        $unit = $line['unit_price'];           // سعر محسوب على السيرفر
        $base = $line['base_price'];
        $city = mb_substr(trim((string)($_POST['city'] ?? '')), 0, 60);
        $district = mb_substr(trim((string)($_POST['district'] ?? '')), 0, 80);
        $raw_addr = trim((string)($_POST['address'] ?? ''));
        if ($district !== '' && mb_stripos($raw_addr, $district) === false) {
            $raw_addr = $district . ($raw_addr !== '' ? ' - ' . $raw_addr : '');
        }
        // جدول الشحن حسب دولة المنتج: مصر = محافظات بالجنيه، والخليج = مناطق الدولة بعملة الدولة
        $ord_country = wf_product_country($prod);
        $ord_cur = function_exists('wf_ui_currency') ? wf_ui_currency($prod) : wf_product_currency($prod, $plat);
        $ship_cur = wf_ship_currency($plat, $ord_country);
        $ship_map = function_exists('site_ship_map') ? site_ship_map($plat, $ord_country) : [];
        $shipfee = max(0, min(2000, (float)($_POST['ship_fee'] ?? ($ship_map[$city] ?? 0))));
        $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
        $phone = mb_substr($phone, 0, 20);
        $phone2 = preg_replace('/\D+/', '', (string)($_POST['phone2'] ?? ''));
        $phone2 = mb_substr($phone2, 0, 20);
        if ($phone === '') { $order_err = '⚠️ لازم تدخل رقم موبايل صحيح'; }

        if (empty($order_err)) {
            $total = round(max(0, $unit * $qty + $shipfee - $bundle_disc), 2);
            $owner = $user ? $user['email'] : ('guest:' . mb_substr($phone, 0, 30));
            $ord_no = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 5));
            $notes_str = trim((string)($_POST['notes'] ?? ''));
            if ($phone2 !== '') {
                $notes_str = ($notes_str !== '' ? $notes_str . ' | ' : '') . 'هاتف بديل: ' . $phone2;
            }

            $selection = order_variant_selection($_POST);
            $new_order = [
                'order_no'   => $ord_no,
                'user'       => $owner,
                'plat'       => $plat,
                'id'         => $pid,
                'pname'      => mb_substr($line['name'] ?: (string)($_POST['pname'] ?? $pid), 0, 120),
                'ref'        => $ref,
                'm_earn'     => 0,            // يُحدَّث بعد حساب العمولة
                'comm_state' => 'pending',
                'sub'        => active_sub(),
                'base_price' => $base,
                'markup'     => $line['markup'],
                // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة — بنسجّل اللي اتحسب فعلاً في السعر
                'extra_comm' => ($extra_comm !== null && !wf_is_digital_plat($plat)) ? $extra_comm : (float)($line['markup'] ?? 0),
                'qty'        => $qty,
                'unit'       => $unit,
                'ship'       => $shipfee,
                'total'      => $total,
                'variant'    => $selection['variant'],
                'color'      => $selection['color'],
                'size'       => $selection['size'],
                'cname'      => mb_substr((string)($_POST['name'] ?? ''), 0, 100),
                'cphone'     => $phone,
                'cphone2'    => $phone2,
                'city'       => $city,
                'district'   => $district,
                'address'    => mb_substr($raw_addr, 0, 300),
                'notes'      => mb_substr($notes_str, 0, 500),
                'at'         => date('c'),
                'src'        => function_exists('wf_traffic_src') ? wf_traffic_src() : '',  // مصدر الزيارة: store / landing
                'discount'    => (float)$bundle_disc,                     // خصم الكمية (كل قطعة إضافية)
                'status'     => 'pending',     // حالة إنجليزية ثابتة
            ];

            // كتابة الأوردر بقفل ذري
            jupdate('orders.json', function (&$orders) use ($new_order) {
                if (!is_array($orders)) $orders = [];
                $orders[] = $new_order;
                $GLOBALS['_WF_NEW_ORDER_IDX'] = count($orders) - 1;
                return true;
            }, []);
            $oi = $GLOBALS['_WF_NEW_ORDER_IDX'] ?? null;

            // حساب العمولة على الأرقام المحسوبة سيرفرًّا، وتسجيلها كمعلقة
            $GLOBALS['_WF_ORDER_IDX'] = $oi;
            $aff = credit_affiliate_order($ref, $prod, $plat, $qty, $unit, $extra_comm, $honor_agreed);
            $m_earn = (float)($aff['m_earn'] ?? 0);

            jupdate('orders.json', function (&$orders) use ($oi, $m_earn, $aff) {
                if ($oi !== null && isset($orders[$oi])) {
                    $orders[$oi]['m_earn']     = $m_earn;
                    $orders[$oi]['ref']        = $aff['ref'] ?? '';
                    $orders[$oi]['comm_state'] = $aff['comm_status'] ?? ($m_earn > 0 ? 'pending' : 'none');
                }
                return true;
            }, []);

            $ord_name  = mb_substr($new_order['pname'], 0, 60);
            $ord_total = $total;

            if (function_exists('wf_event')) {
                wf_event('order', ['product_id' => $pid, 'plat' => $plat, 'order_no' => $ord_no,
                                   'value' => $ord_total, 'qty' => $qty]);
            }
            // كل بيانات الديالوج تُرسل للأدمن (إشعار داخلي + تليجرام)
            $admin_report = [
                '🧾 طلب جديد: ' . $ord_no,
                '📦 المنتج: ' . $ord_name . ($plat !== '' ? ' (' . $plat . ')' : ''),
                '🔢 الكمية: ' . $qty . ($selection['variant'] !== '' ? ' — ' . $selection['variant'] : ''),
                '💵 سعر الوحدة: ' . number_format($unit, 2) . ' ' . $ord_cur . ' — سعر الرابط المحسوب' . ($ord_country !== 'مصر' ? ' (' . $ord_country . ')' : ''),
                // الشحن بعملة دولة المنتج (مفيش تحويل)، وخصم الكمية بالجنيه دايمًا لأنه ما ينطبقش على غير الجنيه
                '🚚 الشحن: ' . number_format($shipfee, 2) . ' ' . $ship_cur . ' — الإجمالي: ' . number_format($ord_total, 2) . ' ' . $ord_cur
                    . ($bundle_disc > 0 ? ' — خصم الكمية (' . (int)$qty . ' قطع): ' . number_format($bundle_disc, 2) . ' ج.م' : ''),
                '👤 العميل: ' . $new_order['cname'] . ' — ' . $phone . ($phone2 !== '' ? ' / ' . $phone2 : ''),
                '📍 ' . trim($city . ($district !== '' ? ' — ' . $district : '') . ' — ' . $new_order['address'], ' —'),
            ];
            if ($notes_str !== '') $admin_report[] = '📝 ملاحظات: ' . $notes_str;
            if ($ref !== '') {
                $mk_admin = find_marketer_by_code($ref);
                $admin_report[] = '🔗 المسوق: ' . (($mk_admin['name'] ?? '') !== '' ? $mk_admin['name'] : $ref) . ' (' . $ref . ')'
                    . ($m_earn > 0 ? ' — عمولة معلقة: ' . number_format($m_earn, 2) . ' ج.م' : '');
            }
            $admin_report[] = '🌐 مصدر الزيارة: ' . ($new_order['src'] !== '' ? $new_order['src'] : 'غير محدد');
            notify('admin', implode("\n", $admin_report), 'dashboard/admin.php?tab=orders');
            if ($ref && ($mk2 = find_marketer_by_code($ref))) {
                notify($mk2['email'], '💰 عملية بيع برابطك: ' . $ord_name . ' — عمولة معلقة +' . number_format($m_earn, 2) . ' ج.م', 'dashboard/marketer.php');
            }
            if ($user) notify($user['email'], '✅ طلبك ' . $ord_no . ' قيد التأكيد: ' . $ord_name, 'dashboard/orders.php');

            $GLOBALS['_WF_ORDER_NO'] = $ord_no;
        }
    }
    if (!empty($_POST['ajax_order'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!empty($order_err)) {
            echo json_encode(['ok' => false, 'error' => $order_err], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'ok'           => true,
                'order_no'     => $GLOBALS['_WF_ORDER_NO'] ?? '',
                'product_name' => $new_order['pname'] ?? '',
                'variant'      => order_variant_label($new_order),
                'color'        => $new_order['color'] ?? '',
                'size'         => $new_order['size'] ?? '',
                'qty'          => $qty,
                'unit_price'   => $unit ?? 0,
                'ship_fee'     => $shipfee ?? 0,
                'grand_total'  => number_format($total ?? 0, 2, '.', ''),
                'city'         => $city ?? '',
                'ref'          => $ref ?? '',
                'm_earn'       => $m_earn ?? 0,
                'discount'     => (float)($bundle_disc ?? 0),           // خصم الكمية (كل قطعة إضافية)
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}
if ($guest_mode && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) {
    layout_top('تم استلام طلبك ', 'orders');
    if (!empty($order_err)) {
        echo '<div class="card" data-ui="u-c4fdb063 df d71"><h2>' . esc($order_err) . '</h2><br><a class="btn pri" href="../index.php">العودة للرئيسية</a></div>';
    } else {
        $ono = $GLOBALS['_WF_ORDER_NO'] ?? '';
        echo '<div class="card" data-ui="u-c4fdb063 df d71"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> تم استلام طلبك بنجاح!</h2>'
           . '<p class="mut">الدفع عند الاستلام — جارٍ تجهيز طلبك للشحن</p>'
           . ($ono ? '<p data-ui="u-d048ba01 d9r d1">رقم الطلب: <span dir="ltr" data-ui="u-f61cf51c d6n d9s d1u">' . esc($ono) . '</span></p><p class="mut" data-ui="u-5d985ec3 do">احتفظ بالرقم لتتبع طلبك أو إلغائه</p>' : '')
           . ($bundle_disc > 0 ? '<p class="mut" data-ui="u-5d985ec3 do" style="color:var(--success)">🎁 تم خصم ' . number_format($bundle_disc, 2) . ' ج.م — خصم الكمية (' . (int)$qty . ' قطع)</p>' : '')
           . '<br><a class="btn pri" href="../index.php">مواصلة التسوق</a> <a class="btn" href="orders.php?phone=' . urlencode($phone ?? '') . '">تتبع طلبك</a></div>';
    }
    layout_end(); exit;
}

// ===== إلغاء / طلب استرجاع من العميل =====
$cust_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cust_action'], $_POST['order_no'])) {
    $act = $_POST['cust_action'] === 'return' ? 'return' : 'cancel';
    $ono = mb_substr(trim((string)$_POST['order_no']), 0, 40);
    $ph  = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 300);
    $res = ['ok' => false, 'msg' => 'الطلب غير موجود'];
    jupdate('orders.json', function (&$os) use ($ono, $ph, $act, $reason, $user, &$res) {
        if (!is_array($os)) return false;
        foreach ($os as $i => $o) {
            if (($o['order_no'] ?? '') !== $ono) continue;
            $mine = $user ? (($o['user'] ?? '') === $user['email']) : ($ph !== '' && substr(preg_replace('/\D+/', '', (string)($o['cphone'] ?? '')), -9) === substr($ph, -9));
            if (!$mine) { $res['msg'] = 'الطلب ده مش بتاعك'; return false; }
            $k = status_key($o['status'] ?? '');
            if ($act === 'cancel') {
                if (!status_customer_cancellable($k)) { $res['msg'] = 'لا يمكن الإلغاء بعد الشحن — اطلب استرجاع بدلاً من ذلك'; return false; }
                $os[$i]['status'] = 'cancelled';
                $os[$i]['comm_state'] = 'reversed';
                $os[$i]['cancel_reason'] = $reason;
                $os[$i]['status_at'] = date('c');
                $res = ['ok' => true, 'msg' => 'تم إلغاء الطلب ✅', 'idx' => $i, 'o' => $o];
            } else {
                if (!in_array($k, ['shipped', 'delivered'], true)) { $res['msg'] = 'الاسترجاع متاح بعد الشحن/التسليم فقط'; return false; }
                if (!empty($o['return_requested'])) { $res['msg'] = 'طلب الاسترجاع مسجّل بالفعل'; return false; }
                $os[$i]['return_requested'] = date('c');
                $os[$i]['return_reason'] = $reason;
                $res = ['ok' => true, 'msg' => 'تم تسجيل طلب الاسترجاع — هنتواصل معاك ✅', 'idx' => $i, 'o' => $o];
            }
            return true;
        }
        return false;
    }, []);
    if ($res['ok']) {
        $o = $res['o'];
        if ($act === 'cancel' && !empty($o['ref']) && (float)($o['m_earn'] ?? 0) > 0 && ($mk = find_marketer_by_code($o['ref']))) {
            commission_reverse($mk['email'], (float)$o['m_earn'], $res['idx'], 'إلغاء العميل للطلب');
            team_commission_reverse_for_order($res['idx'], 'إلغاء العميل للطلب');
        }
        notify('admin', ($act === 'cancel' ? '❌ العميل ألغى الطلب ' : '↩️ طلب استرجاع ') . $ono . ($reason ? ' — ' . $reason : ''), 'dashboard/admin.php?tab=orders');
        log_audit('customer_' . $act, $o['ref'] ?? '-', ['order_no' => $ono, 'reason' => $reason]);
    }
    $cust_msg = $res['msg'];
}

if (!$user && empty($_GET['phone'])) {
    layout_top('تتبع طلبك', 'orders');
    echo '<div class="auth"><h1 data-ui="u-19b24cb7 d6l dc d2">تتبع طلبك</h1><p class="mut">أدخل رقم الموبايل الذي سجلت به الطلب لمتابعة حالة الشحن</p><form method="get"><label for="trkPhone" class="sr-only">رقم الموبايل</label><input id="trkPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="010xxxxxxxx" data-ui="u-a2fd8dfa d9t d9u" required><button class="btn pri" data-ui="u-beeb564f dx dw dg">عرض حالة الطلبات</button></form></div>';
    layout_end(); exit;
}
$guest_phone = !$user ? preg_replace('/\D+/', '', trim($_GET['phone'])) : '';
$orders = array_values(array_filter(jload('orders.json', []), function ($o) use ($user, $guest_phone) {
    if ($user) return ($o['user'] ?? '') === $user['email'];
    return substr(preg_replace('/\D+/', '', (string)($o['cphone'] ?? '')), -9) === substr($guest_phone, -9) || ($o['user'] ?? '') === ('guest:' . $guest_phone);
}));
$grand = array_sum(array_map(function ($o) { return $o['total'] ?? 0; }, $orders));
layout_top('طلباتي ومتابعة الشحن', 'orders');
if ($cust_msg) echo '<div class="ok" data-ui="u-f2235598 d1r">' . esc($cust_msg) . '</div>';
echo '<div class="kpis"><div class="kpi"><b>' . count($orders) . '</b><span>إجمالي الطلبات</span></div><div class="kpi"><b>' . (count(wf_ui_currencies($orders)) > 1 ? 'عملات متعددة' : number_format($grand,2) . ' ' . esc(wf_ui_currencies($orders)[0] ?? 'ج.م')) . '</b><span>إجمالي القيمة</span></div></div>';
echo wf_ui_currency_notice($orders) . wf_ui_shipping_currency_notice($orders);
$steps = ['pending' => 'جديد', 'confirmed' => 'مؤكد', 'shipped' => 'مشحون', 'delivered' => 'تم التوصيل'];
echo '<div class="card"><h2 data-ui="u-e205cc36 d5l dc d1r">سجل الطلبات ومتابعة الحالة</h2>';
if (!$orders) {
    echo '<div data-ui="u-d3dfef62 df d9v"><p class="mut" data-ui="u-9e82dcff d54">لا توجد طلبات مسجلة حتى الآن.</p><a href="../index.php" class="btn">تصفح المنتجات</a></div>';
} else {
    echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><thead><tr><th>رقم الطلب</th><th>المنتج</th><th>الكمية</th><th>الإجمالي</th><th>حالة الطلب والتتبع</th><th>التاريخ</th><th>إجراء</th></tr></thead><tbody>';
    foreach (array_reverse($orders) as $o) {
        $nm = $o['pname'] ?? $o['id'];
        $k = status_key($o['status'] ?? '');
        $ono = $o['order_no'] ?? '';
        $bar = '';
        if (isset($steps[$k])) {
            $pos = array_search($k, array_keys($steps), true);
            $bar = '<div data-ui="u-8761a4a1 dh d4g d49 d9w">';
            foreach ($steps as $sk => $slbl) {
                $si = array_search($sk, array_keys($steps), true);
                $done = $si <= $pos;
                $bar .= '<div data-ui="u-16bc804e d2i df"><div style="height:5px;border-radius:9px;background:' . ($done ? 'var(--success)' : 'var(--slate-200)') . ';margin-bottom:3px"></div><span style="font-size:12px;color:' . ($done ? 'var(--slate-800)' : 'var(--slate-400)') . ';font-weight:' . ($si === $pos ? '800' : '600') . '">' . esc($slbl) . '</span></div>';
            }
            $bar .= '</div>';
        }
        $bar = wf_order_timeline($k);
        $col = $k === 'delivered' ? 'var(--success)' : (in_array($k, ['cancelled', 'returned'], true) ? 'var(--danger)' : 'var(--info)');
        $action = '';
        if ($ono !== '') {
            $ph_in = $user ? '' : '<input type="hidden" name="phone" value="' . esc($guest_phone) . '">';
            if (status_customer_cancellable($k)) {
                $action = '<form method="post" data-ui="u-2d4ec034 d19 dh di d1p dj" data-confirm="سيتم إلغاء الطلب وعكس عمولته إن وجدت. هل تريد المتابعة؟">' . csrf_field() . $ph_in . '<input type="hidden" name="order_no" value="' . esc($ono) . '"><input type="hidden" name="cust_action" value="cancel"><input name="reason" placeholder="السبب (اختياري)" data-ui="u-417926f1 d9x d9y d3g do d19"> <button class="btn sm" data-ui="u-634b8f15 d4f d3v">إلغاء</button></form>';
            } elseif (in_array($k, ['shipped', 'delivered'], true) && empty($o['return_requested'])) {
                $action = '<form method="post" data-confirm="سيتم تسجيل طلب استرجاع للمراجعة، وليس رد المبلغ فوراً. هل تريد المتابعة؟" data-ui="u-2d4ec034 d19 dh di d1p dj">' . csrf_field() . $ph_in . '<input type="hidden" name="order_no" value="' . esc($ono) . '"><input type="hidden" name="cust_action" value="return"><input name="reason" placeholder="سبب الاسترجاع" required data-ui="u-417926f1 d9x d9y d3g do d19"> <button class="btn sm outline">طلب استرجاع</button></form>';
            } elseif (!empty($o['return_requested'])) {
                $action = '<span class="badge yellow">طلب استرجاع قيد المراجعة</span>';
            }
        }
        echo '<tr><td dir="ltr" data-ui="u-01f4ea64 do dc">' . esc($ono ?: '—') . '</td><td><a href="product.php?id=' . urlencode($o['id']) . (!empty($o['ref']) ? '&ref=' . urlencode($o['ref']) : '') . '" data-ui="u-00703584 dc">' . esc(mb_substr($nm, 0, 45)) . '</a>' . (order_variant_label($o) !== '' ? '<br><span class="mut" data-ui="u-5d985ec3 do">' . esc(order_variant_label($o)) . '</span>' : '') . '</td><td>' . (int)($o['qty'] ?? 1) . '</td><td><b>' . number_format($o['total'] ?? 0, 2) . ' ' . esc(wf_ui_currency($o)) . '</b>' . ((float)($o['discount'] ?? 0) > 0 ? '<br><span class="mut" data-ui="u-5d985ec3 do" style="color:var(--success)">خصم الكمية ' . number_format((float)$o['discount'], 2) . ' ج.م</span>' : '') . '</td><td>' . wf_order_badge($k) . '<details class="row-details"><summary>مراحل الطلب</summary>' . $bar . '</details>' . '</td><td dir="ltr" data-ui="u-5d985ec3 do">' . esc(substr($o['at'] ?? '', 0, 10)) . '</td><td>' . $action . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';
layout_end();
