<?php
/**
 * _payout.php — نظام سحب الأرباح للمسوقين
 * الوسائل: محافظ إلكترونية (فودافون/أورانج/اتصالات/وي) · انستا باي · تحويل بنكي · بايبال
 */
if (!defined('BRAND')) return;

function payout_handle_post($user, &$msg, &$msg_type) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (($_POST['payout_action'] ?? '') !== 'request') return;

    $me = find_user($user['email']) ?: $user;
    $balance = (float)($me['balance'] ?? 0);

    // تحديد معدل: 3 طلبات سحب كل ساعة
    $wait = rate_limit_check('payout', 3, 3600);
    if ($wait > 0) {
        $msg = '⏳ طلبات كتيرة — استنى ' . fmt_wait($wait);
        $msg_type = 'err';
        return;
    }

    $method  = trim((string)($_POST['payout_method'] ?? ''));
    $account = trim((string)($_POST['payout_account'] ?? ''));
    $amount  = round((float)($_POST['payout_amount'] ?? 0), 2);
    $notes   = mb_substr(trim((string)($_POST['payout_notes'] ?? '')), 0, 300);

    $methods = payout_methods();
    if (!isset($methods[$method])) {
        $msg = 'اختار وسيلة سحب صحيحة'; $msg_type = 'err'; return;
    }
    $cfg = $methods[$method];

    $err = validate_payout_account($method, $account);
    if ($err) { $msg = $err; $msg_type = 'err'; return; }

    if ($amount <= 0) { $msg = 'اكتب مبلغ صحيح'; $msg_type = 'err'; return; }
    if ($amount < $cfg['min']) {
        $msg = 'الحد الأدنى للسحب عبر ' . $cfg['label'] . ' هو ' . number_format($cfg['min']) . ' ج.م';
        $msg_type = 'err'; return;
    }
    if ($amount > $balance) {
        $msg = 'المبلغ أكبر من رصيدك المتاح (' . number_format($balance, 2) . ' ج.م)';
        $msg_type = 'err'; return;
    }

    // خصم المبلغ (تحت قفل) + تسجيل الطلب
    $ok = false;
    update_user((string)$user['email'], function (&$u) use ($amount, &$ok) {
        $bal = (float)($u['balance'] ?? 0);
        if ($bal + 0.0001 < $amount) { $ok = false; return; }
        $u['balance'] = round($bal - $amount, 2);
        $ok = true;
        return true;
    });

    if (!$ok) {
        $msg = 'تعذر تنفيذ السحب — الرصيد غير كافٍ، حدّث الصفحة وجرّب تاني';
        $msg_type = 'err'; return;
    }

    jupdate('payouts.json', function (&$po) use ($user, $amount, $method, $account, $notes) {
        if (!is_array($po)) $po = [];
        $po[] = [
            'user'           => $user['email'],
            'name'           => $user['name'] ?? '',
            'amount'         => $amount,
            'method'         => $method,
            'method_label'   => payout_method_label($method),
            'account_number' => $account,
            'notes'          => $notes,
            'status'         => 'قيد المراجعة',
            'transfer_ref'   => '',
            'processed_by'   => '',
            'at'             => date('c'),
            'processed_at'   => '',
        ];
        return true;
    });

    notify('admin', '💳 طلب سحب جديد: ' . ($user['name'] ?? $user['email']) . ' — ' . number_format($amount, 2)
           . ' ج.م عبر ' . payout_method_label($method), 'dashboard/admin.php?tab=commissions');
    notify_user($user['email'], '💳 تم استلام طلب سحب ' . number_format($amount, 2) . ' ج.م عبر '
                . payout_method_label($method) . ' — جاري المراجعة', 'dashboard/marketer.php');
    log_audit('payout_request', $user['email'], ['amount' => $amount, 'method' => $method]);
    balance_ledger_add($user['email'], 'payout', -$amount, 'طلب سحب عبر ' . payout_method_label($method));

    $msg = '✅ تم إرسال طلب السحب (' . number_format($amount, 2) . ' ج.م عبر ' . $cfg['label'] . ') — هيتحوّل خلال مراجعة الإدارة';
    $msg_type = 'ok';
}

function payout_render_section($user) {
    $me = find_user($user['email']) ?: $user;
    $balance = (float)($me['balance'] ?? 0);
    $pending_balance = (float)($me['pending_balance'] ?? 0);
    $methods = payout_methods();

    $all = jload('payouts.json', []);
    if (!is_array($all)) $all = [];
    $mine = array_values(array_filter($all, function ($p) use ($user) {
        return strcasecmp((string)($p['user'] ?? ''), (string)$user['email']) === 0;
    }));
    $mine = array_reverse($mine, true);

    // تجميع الوسائل في مجموعات
    $groups = [];
    foreach ($methods as $k => $m) $groups[$m['group']][] = ['k' => $k, 'm' => $m];

    

    echo '<div class="card" data-ui="u-25724057 d0">';
    echo '<h3 data-ui="u-0316c139 d1 d2"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> سحب الأرباح</h3>';
    echo '<div class="kpis" data-ui="u-f657f7f3 d3">';
    echo '<div class="kpi" data-ui="u-3824e45f d4"><b>' . number_format($balance, 2) . ' ج.م</b><span>الرصيد القابل للسحب</span></div>';
    echo '<div class="kpi" data-ui="u-97b96627 d5"><b>' . number_format($pending_balance, 2) . ' ج.م</b><span>عمولات تحت التأكيد</span></div>';
    echo '<div class="kpi" data-ui="u-bc691366 d6"><b>' . count($mine) . '</b><span>إجمالي طلبات السحب</span></div>';
    echo '</div>';

    if ($balance <= 0) {
        echo '<div data-ui="u-aae930ba d7 d8 d9 da db dc dd">';
        echo '⏳ مفيش رصيد قابل للسحب حالياً — لازم رصيدك يوصل للحد الأدنى لأي وسيلة سحب (يبدأ من 50 ج.م).';
        echo '</div>';
    } else {
        echo '<form method="post" id="payoutForm" data-payout-balance="' . esc($balance) . '">';
        echo csrf_field();
        echo '<input type="hidden" name="payout_action" value="request">';

        echo '<label for="pmAmount" class="field-label"> مبلغ السحب (ج.م)</label>';
        echo '<input type="number" name="payout_amount" id="pmAmount" step="0.5" min="50" max="' . esc($balance) . '" required
               placeholder="اكتب المبلغ (الحد الأقصى ' . number_format($balance, 2) . ')"
               data-ui="u-01940751 de d1 df dg">';
        echo '<div data-ui="u-b8bc3cb1 dh di dj dk">';
        foreach ([0.25, 0.5, 0.75, 1] as $f) {
            $v = floor($balance * $f);
            if ($v >= 50) echo '<button type="button" class="btn sm outline" onclick="document.getElementById(\'pmAmount\').value=' . $v . '">' . number_format($v) . ' ج.م</button>';
        }
        echo '</div>';

        echo '<p class="field-label"> اختار وسيلة السحب</p>';
        $first = true;
        foreach ($groups as $gname => $opts) {
            echo '<div class="pm-grp-t">' . esc($gname) . '</div>';
            echo '<div class="pm-grid">';
            foreach ($opts as $o) {
                $dis = ($balance < $o['m']['min']) ? 'aria-disabled="true"' : '';
                echo '<label class="pm-opt' . ($first ? ' on' : '') . '" ' . $dis . ' data-min="' . esc($o['m']['min']) . '">';
                echo '<input type="radio" name="payout_method" value="' . esc($o['k']) . '"' . ($first ? ' checked' : '') . '>';
                echo '<span class="ico">' . wf_svg_icon($o['m']['pattern'] === 'phone' ? 'phone' : ($o['m']['pattern'] === 'email' ? 'globe' : 'bank'), 28) . '</span>';
                echo '<span class="nm">' . esc(wf_ui_plain($o['m']['label'])) . '</span>';
                echo '<span class="mn">حد أدنى ' . number_format($o['m']['min']) . ' ج.م</span>';
                echo '</label>';
                if ($first && $balance >= $o['m']['min']) $first = false;
            }
            echo '</div>';
        }

        echo '<div data-ui="u-d7ab73d7 dl">';
        echo '<label for="pmAccount" class="field-label"> بيانات الحساب <span id="pmHint" data-ui="u-cbcd23ef dm dn do"></span></label>';
        echo '<input name="payout_account" id="pmAccount" required placeholder="—">';
        echo '</div>';

        echo '<div data-ui="u-89901732 dp">';
        echo '<label for="pmNotes" class="field-label"> ملاحظات (اختياري)</label>';
        echo '<input id="pmNotes" name="payout_notes" placeholder="أي تفاصيل تساعد الإدارة في التحويل">';
        echo '</div>';

        echo '<div id="pmSummary" data-ui="u-738715d7 dq dr ds dt da du dm dv"></div>';

        echo '<button class="btn" data-ui="u-0adcae0c dw dx dq dy dz"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> إرسال طلب السحب</button>';
        echo '</form>';
    }

    // سجل الطلبات
    if ($mine) {
        echo '<div data-ui="u-69941f1f d10">';
        echo '<h4 data-ui="u-d664a888 d1 d11 d12"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> سجل طلبات السحب</h4>';
        echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do">';
        echo '<tr><th>التاريخ</th><th>المبلغ</th><th>الوسيلة</th><th>الحساب</th><th>الحالة</th><th>رقم التحويل</th></tr>';
        foreach (array_slice($mine, 0, 15) as $p) {
            $st = $p['status'] ?? 'قيد المراجعة';
            $col = $st === 'موافق عليه' ? 'var(--success)' : ($st === 'مرفوض' ? 'var(--danger-text)' : 'var(--amber-600)');
            $bg  = $st === 'موافق عليه' ? '#dcfce7' : ($st === 'مرفوض' ? '#fee2e2' : 'var(--amber-100)');
            echo '<tr>';
            echo '<td dir="ltr">' . esc(substr($p['at'] ?? '', 0, 16)) . '</td>';
            echo '<td><b>' . number_format((float)($p['amount'] ?? 0), 2) . ' ج.م</b></td>';
            echo '<td>' . wf_svg_icon('wallet', 16) . esc(wf_ui_plain($p['method_label'] ?? payout_method_label($p['method'] ?? ''))) . '</td>';
            echo '<td dir="ltr">' . esc($p['account_number'] ?? '') . '</td>';
            echo '<td><span class="badge ' . ($st === 'موافق عليه' ? 'green' : ($st === 'مرفوض' ? 'red' : 'yellow')) . '">' . wf_svg_icon($st === 'موافق عليه' ? 'check' : ($st === 'مرفوض' ? 'close' : 'clock'), 16) . esc($st) . '</span><details class="row-details"><summary>مراحل الطلب</summary><ol class="payout-timeline"><li>إرسال الطلب: ' . esc(substr($p['at'] ?? '', 0, 16)) . '</li><li>الحالة الحالية: ' . esc($st) . '</li><li>آخر معالجة: ' . esc(substr($p['processed_at'] ?? '', 0, 16) ?: 'لم تُسجل بعد') . '</li></ol></details></td>';
            echo '<td dir="ltr">' . esc($p['transfer_ref'] ?? '—') . '</td>';
            echo '</tr>';
        }
        echo '</table></div></div>';
    }

    echo '</div>';

    // سكربت تفاعلي
    $js_methods = [];
    foreach ($methods as $k => $m) {
        $js_methods[$k] = ['hint' => $m['hint'], 'label' => $m['label'], 'min' => $m['min'], 'icon' => $m['icon'], 'pattern' => $m['pattern']];
    }
    echo '<script type="application/json" id="wfPayoutConfig">' . json_encode($js_methods, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
}
