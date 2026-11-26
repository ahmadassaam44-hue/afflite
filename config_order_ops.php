<?php
// ============================================================================
// config_order_ops.php — جزء من config.php (السطور 3037–3576 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// ============================================================================
//  تعديل الطلبات الكامل (مسوق + إدارة): كل التفاصيل بما فيها العمولة
//  - التحقق والحسابات كلها سيرفر-سايد: الإجمالي = سعر القطعة × الكمية + الشحن − خصم الكمية
//  - فرق العمولة بيتسوى في المحفظة بحسب حالة الطلب (معلقة/مؤكدة) — من غير ازدواج
//  - الرقمي/السوشيال: مفيش عمولة إضافية (زي الإنشاء بالظبط)
// ============================================================================
function wf_order_edit_validate($in, $is_admin = false) {
    $cname = mb_substr(trim((string)($in['cname'] ?? '')), 0, 100);
    $cphone = preg_replace('/\D+/', '', (string)($in['cphone'] ?? ''));
    if ($cname === '' || mb_strlen((string)$cphone) < 10) return ['error' => 'اسم العميل ورقم موبايل صحيح (10 أرقام على الأقل) مطلوبين'];
    // الحقول الرقمية اختيارية: الغايب/الفاضي = سيب القيمة الحالية (التوافق مع أي نداء قديم)
    $qty = null;
    if (array_key_exists('qty', $in) && trim((string)$in['qty']) !== '') {
        $qty = (int)$in['qty'];
        if ($qty < 1 || $qty > 99) return ['error' => 'الكمية لازم تكون بين 1 و99'];
    }
    $unit = null;
    if (array_key_exists('unit', $in) && trim((string)$in['unit']) !== '') {
        $unit = round((float)$in['unit'], 2);
        if (!is_finite($unit) || $unit < 0 || $unit > 200000) return ['error' => 'سعر القطعة غير صالح'];
    }
    $ship = null;
    if (array_key_exists('ship', $in) && trim((string)$in['ship']) !== '') {
        $ship = round((float)$in['ship'], 2);
        if (!is_finite($ship) || $ship < 0 || $ship > 20000) return ['error' => 'مصاريف الشحن غير صالحة'];
    }
    $m_earn = null;
    if (array_key_exists('m_earn', $in) && trim((string)$in['m_earn']) !== '') {
        $m_earn = round((float)$in['m_earn'], 2);
        if (!is_finite($m_earn) || $m_earn < 0 || $m_earn > 50000) return ['error' => 'قيمة العمولة غير صالحة'];
    }
    $extra = null;
    if (array_key_exists('extra_comm', $in) && trim((string)$in['extra_comm']) !== '') {
        $extra = round((float)$in['extra_comm'], 2);
        if (!is_finite($extra) || $extra < 0 || $extra > (float)MAX_MARKUP_AMOUNT) return ['error' => 'العمولة الإضافية لازم تكون بين 0 و' . (float)MAX_MARKUP_AMOUNT];
    }
    $out = [
        'cname' => $cname, 'cphone' => $cphone,
        'cphone2' => preg_replace('/\D+/', '', (string)($in['cphone2'] ?? '')),
        'city' => mb_substr(trim((string)($in['city'] ?? '')), 0, 60),
        'district' => mb_substr(trim((string)($in['district'] ?? '')), 0, 60),
        'address' => mb_substr(trim((string)($in['address'] ?? '')), 0, 300),
        'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 500),
        'pname' => mb_substr(trim((string)($in['pname'] ?? '')), 0, 120),
        'variant' => mb_substr(trim((string)($in['variant'] ?? '')), 0, 60),
        'color' => mb_substr(trim((string)($in['color'] ?? '')), 0, 60),
        'size' => mb_substr(trim((string)($in['size'] ?? '')), 0, 60),
        'qty' => $qty, 'unit' => $unit, 'ship' => $ship, 'm_earn' => $m_earn, 'extra_comm' => $extra,
    ];
    if ($is_admin) {
        if (array_key_exists('sub', $in)) $out['sub'] = mb_substr(trim((string)$in['sub']), 0, 60);
        if (isset($in['status']) && trim((string)$in['status']) !== '') {
            $raw = trim((string)$in['status']);
            // المقبول: مفاتيح الحالة الإنجليزية أو تسمياتها العربية (أو الأسماء القديمة) — أي حاجة تانية تترفض
            $legacy = ['قيد التنفيذ', 'قيد المراجعة', 'تم التسليم', 'مرفوض', 'مكتمل'];
            if (!array_key_exists($raw, order_status_list()) && !in_array($raw, order_status_list(), true) && !in_array($raw, $legacy, true)) {
                return ['error' => 'حالة الطلب غير صالحة'];
            }
            $out['status'] = status_key($raw);
        }
        if (isset($in['ref'])) $out['ref'] = mb_substr(trim((string)$in['ref']), 0, 40);
    }
    return $out;
}
// كتابة القيم المعدَّلة في صف الطلب + إعادة حساب خصم الكمية والإجمالي من بيانات السيرفر
// القاعدة: حقل ناقص/فاضي = سيب زي ما هو (مفيش مسح بالغلط)، والفلوس الغايب ما يصفّرش الموجود
function wf_order_apply_row_edit(&$row, $v) {
    $plat = (string)($row['plat'] ?? '');
    $is_dig = function_exists('wf_is_digital_plat') && wf_is_digital_plat($plat);
    $row['cname'] = $v['cname'];
    $row['cphone'] = $v['cphone'];
    if ($v['cphone2'] !== '') $row['cphone2'] = $v['cphone2'];
    if ($v['city'] !== '') $row['city'] = $v['city'];
    if ($v['district'] !== '') $row['district'] = $v['district'];
    if ($v['address'] !== '') $row['address'] = $v['address'];
    $row['notes'] = $v['notes'];
    if ($v['pname'] !== '') $row['pname'] = $v['pname'];
    if ($v['variant'] !== '') $row['variant'] = $v['variant'];
    if ($v['color'] !== '') $row['color'] = $v['color'];
    if ($v['size'] !== '') $row['size'] = $v['size'];
    if ($v['qty'] !== null) $row['qty'] = $v['qty'];
    if ($v['unit'] !== null) $row['unit'] = $v['unit'];
    if ($v['ship'] !== null) $row['ship'] = $v['ship'];
    // الرقمي والسوشيال: مافيش عمولة إضافية — نفس قاعدة الإنشاء
    if ($is_dig) $row['extra_comm'] = 0.0;
    elseif ($v['extra_comm'] !== null) $row['extra_comm'] = $v['extra_comm'];
    if ($v['m_earn'] !== null) $row['m_earn'] = $v['m_earn'];
    $qty = max(1, min(99, (int)($row['qty'] ?? 1)));
    $row['qty'] = $qty;
    $unit = round((float)($row['unit'] ?? 0), 2);
    $ship = round((float)($row['ship'] ?? 0), 2);
    $prod = function_exists('find_product_by_id') ? find_product_by_id((string)($row['id'] ?? ''), $plat) : null;
    $cur = $prod ? wf_ui_currency($prod) : wf_ui_currency($row);
    $disc = function_exists('wf_bulk_discount') ? (float)wf_bulk_discount($qty, $plat, $cur) : 0.0;
    $row['discount'] = $disc;
    $row['total'] = round(max(0, $unit * $qty + $ship - $disc), 2);
    // حالة العمولة تتبع حالة الطلب والعمولة الفعلية (نفس خريطة معالج الحالة في الإدارة)
    $earn = round((float)($row['m_earn'] ?? 0), 2);
    $st = status_key($row['status'] ?? 'pending');
    $row['comm_state'] = status_releases_commission($st) ? ($earn > 0 ? 'confirmed' : 'none')
                       : (status_reverses_commission($st) ? 'reversed' : ($earn > 0 ? 'pending' : 'none'));
}
// قائمة الحقول اللي اتغيرت فعلًا (للأودت والرسائل)
function wf_order_edit_changed_fields($old, $new) {
    $keys = ['cname', 'cphone', 'cphone2', 'city', 'district', 'address', 'notes', 'pname',
             'variant', 'color', 'size', 'qty', 'unit', 'ship', 'extra_comm', 'm_earn',
             'discount', 'total', 'status', 'ref', 'sub'];
    $changed = [];
    foreach ($keys as $k) {
        $a = is_array($old) ? ($old[$k] ?? '') : '';
        $b = is_array($new) ? ($new[$k] ?? '') : '';
        if (is_numeric($a) && is_numeric($b)) {
            if (abs((float)$a - (float)$b) >= 0.01) $changed[$k] = [(float)$a, (float)$b];
        } elseif ((string)$a !== (string)$b) {
            $changed[$k] = [mb_substr((string)$a, 0, 60), mb_substr((string)$b, 0, 60)];
        }
    }
    return $changed;
}
// تسوية فرق العمولة (Δ = جديد − قديم) في المحفظة بحسب حالة الطلب — الفريق بنفس نسبة الإنشاء
function wf_order_comm_adjust($ref, $old, $new, $comm_state, $idx, $order_no) {
    $old = round((float)$old, 2); $new = round((float)$new, 2);
    $d = round($new - $old, 2);
    if (abs($d) < 0.01) return 0.0;
    // الطلب الملغي/المرفوض ميتفضلش ميتحرك في المحفظة
    if (in_array((string)$comm_state, ['reversed', 'suspended'], true)) return 0.0;
    $mk_email = ''; $par = null;
    if ($ref !== '' && ($mk = find_marketer_by_code($ref))) {
        $mk_email = (string)($mk['email'] ?? '');
        if ($mk_email !== '' && !empty($mk['referred_by']) && ($pr = find_marketer_by_code((string)$mk['referred_by']))
            && ($pr['status'] ?? 'approved') === 'approved') {
            $joined = strtotime((string)($mk['at'] ?? 'now'));
            if ($joined !== false && (time() - $joined) < 243 * 86400) $par = $pr;
        }
    }
    if ($mk_email === '') return 0.0;
    $team_d = $par ? round($d * REF_TEAM_RATE, 2) : 0.0;
    if ($d > 0) {
        commission_credit_pending($mk_email, $d, $idx, 'زيادة عمولة — تعديل الطلب ' . $order_no);
        if ($par && $team_d > 0) commission_credit_pending((string)$par['email'], $team_d, $idx, 'عمولة فريق (إحالة)');
        if ((string)$comm_state === 'confirmed') {
            commission_release($mk_email, $d, $idx, 'تأكيد زيادة العمولة لطلب مُسلَّم ' . $order_no);
            if ($par && $team_d > 0) team_commission_release_for_order($idx, 'تعديل طلب مُسلَّم ' . $order_no);
        }
    } else {
        commission_reverse($mk_email, -$d, $idx, 'تخفيض عمولة — تعديل الطلب ' . $order_no);
        if ($par && $team_d < 0) commission_reverse((string)$par['email'], -$team_d, $idx, 'إلغاء عمولة فريق (إحالة) — تعديل الطلب ' . $order_no);
    }
    return $d;
}
// تعديل المسوق لطلبه: كل التفاصيل (بيانات العميل/المنتج/الكمية/السعر/الشحن/العمولات) —
// السيرفر بيعيد حساب الإجمالي وخصم الكمية وبيسوّي فرق العمولة في المحفظة
function wf_marketer_apply_order_edit($order_no, $u, $in) {
    $order_no = mb_substr(trim((string)$order_no), 0, 40);
    if ($order_no === '' || !is_array($u) || trim((string)($u['ref_code'] ?? '')) === '') return ['ok' => false, 'msg' => 'الطلب غير موجود أو مش من حقك'];
    $v = wf_order_edit_validate($in);
    if (!empty($v['error'])) return ['ok' => false, 'msg' => $v['error']];
    $res = ['ok' => false, 'msg' => 'الطلب غير موجود أو مش من حقك'];
    jupdate('orders.json', function (&$os) use ($order_no, $u, $v, &$res) {
        if (!is_array($os)) return false;
        foreach ($os as $i => $o) {
            if (($o['order_no'] ?? '') !== $order_no) continue;
            if (!wf_marketer_can_edit_order($o, $u)) { $res['msg'] = 'الطلب مش بتاعك أو حالته مابقتش تقبل تعديل'; return false; }
            $old_earn = round((float)($o['m_earn'] ?? 0), 2);
            $old_comm = (string)($o['comm_state'] ?? 'pending');
            $old_status = status_key($o['status'] ?? 'pending');
            wf_order_apply_row_edit($os[$i], $v);
            // المسوق مايغيّرش الحالة/الكود/المصدر — يرجعوا زي ما كانوا (comm_state اتحسب من الحالة الحالية جوه apply)
            $os[$i]['status'] = $o['status'] ?? 'pending';
            $os[$i]['ref'] = $o['ref'] ?? '';
            if (array_key_exists('sub', $o)) $os[$i]['sub'] = $o['sub'];
            $os[$i]['mk_edited_at'] = date('c');
            $os[$i]['mk_edited_by'] = 'marketer:' . (string)($u['ref_code'] ?? '');
            $new_earn = round((float)($os[$i]['m_earn'] ?? 0), 2);
            $moved = 0.0;
            if (abs($new_earn - $old_earn) >= 0.01) {
                $moved = wf_order_comm_adjust((string)($o['ref'] ?? ''), $old_earn, $new_earn, $old_comm, $i, $order_no);
            }
            $changed = wf_order_edit_changed_fields($o, $os[$i]);
            $res = ['ok' => true, 'idx' => $i, 'changed' => $changed,
                    'msg' => 'تم حفظ تعديلاتك على الطلب ✅' . ($moved != 0.0 ? ' — فرق العمولة (' . number_format($moved, 2) . ' ج.م) اتسوّى في محفظتك' : '')];
            return true;
        }
        return false;
    }, []);
    return $res;
}
// تعديل الإدارة الكامل: كل تفاصيل الطلب + الحالة + كود المسوق (تحويل العمولة بين المسوقين)
function wf_admin_apply_order_edit($order_no, $in) {
    $order_no = mb_substr(trim((string)$order_no), 0, 40);
    if ($order_no === '') return ['ok' => false, 'msg' => 'رقم الطلب مطلوب'];
    $v = wf_order_edit_validate($in, true);
    if (!empty($v['error'])) return ['ok' => false, 'msg' => $v['error']];
    $old_row = null; $old_idx = null;
    foreach ((array)jload('orders.json', []) as $i => $o) {
        if (($o['order_no'] ?? '') === $order_no) { $old_row = $o; $old_idx = $i; break; }
    }
    if ($old_row === null) return ['ok' => false, 'msg' => 'الطلب غير موجود'];
    $old_ref  = trim((string)($old_row['ref'] ?? ''));
    $new_ref  = array_key_exists('ref', $v) && $v['ref'] !== '' ? $v['ref'] : $old_ref;
    $ref_changed = strcasecmp($new_ref, $old_ref) !== 0;
    if ($ref_changed && find_marketer_by_code($new_ref) === null) {
        return ['ok' => false, 'msg' => 'كود المسوق الجديد غير موجود: ' . $new_ref];
    }
    $old_key  = status_key($old_row['status'] ?? 'pending');
    $new_key  = isset($v['status']) ? $v['status'] : $old_key;
    $old_earn = round((float)($old_row['m_earn'] ?? 0), 2);
    // عمولة ناقصة في المدخلات = سيب الحالية
    $new_earn = $v['m_earn'] !== null ? $v['m_earn'] : $old_earn;
    $old_comm = (string)($old_row['comm_state'] ?? 'pending');
    $old_mk_email = ($old_ref !== '' && ($mk0 = find_marketer_by_code($old_ref))) ? (string)($mk0['email'] ?? '') : '';
    $extra = '';

    // ===== عمليات المحفظة قبل كتابة الصف (نفس نمط معالج تغيير الحالة) =====
    if ($ref_changed) {
        // سحب عمولة المسوق القديم (مباشرة + فريق) بحسب حالتها
        if ($old_mk_email !== '' && $old_earn > 0 && in_array($old_comm, ['pending', 'confirmed'], true)) {
            commission_reverse($old_mk_email, $old_earn, $old_idx, 'تحويل الطلب لمسوق آخر بقرار الإدارة', $old_comm === 'confirmed');
            team_commission_reverse_for_order($old_idx, 'تحويل الطلب لمسوق آخر بقرار الإدارة');
        }
        // تسجيل العمولة الجديدة كاملة للمسوق الجديد (فريقه بيتسجل تلقائيًا جوه الدالة)
        if ($new_earn > 0 && !status_reverses_commission($new_key)) {
            credit_affiliate_amount($new_ref, $new_earn, $old_idx, 'عمولة أوردر — تحويل الإدارة');
            if (status_releases_commission($new_key)) {
                $nmk = find_marketer_by_code($new_ref);
                if ($nmk) {
                    commission_release((string)$nmk['email'], $new_earn, $old_idx, 'طلب محوّل مُسلَّم بالفعل', true);
                    team_commission_release_for_order($old_idx, 'طلب محوّل مُسلَّم بالفعل');
                }
            }
        }
        $extra = '🔁 العمولة بقت على كود المسوق ' . $new_ref;
    } else {
        if ($new_key !== $old_key && $old_mk_email !== '' && $old_earn > 0) {
            // دورة حياة الحالة على القيمة القديمة (زي معالج الحالة بالظبط)
            if (status_releases_commission($new_key) && !status_releases_commission($old_key)) {
                commission_release($old_mk_email, $old_earn, $old_idx, 'تأكيد تسليم الأوردر', true);
                team_commission_release_for_order($old_idx, 'تأكيد تسليم الأوردر');
                $extra = '✅ تم تحرير عمولة ' . number_format($old_earn, 2) . ' ج.م للمسوق';
            } elseif (status_reverses_commission($new_key) && !status_reverses_commission($old_key)) {
                commission_reverse($old_mk_email, $old_earn, $old_idx, status_label($new_key), status_releases_commission($old_key));
                team_commission_reverse_for_order($old_idx, status_label($new_key));
                $extra = '↩️ تم سحب عمولة ' . number_format($old_earn, 2) . ' ج.م من المسوق';
            } elseif (status_releases_commission($old_key) && !status_releases_commission($new_key)) {
                commission_reverse($old_mk_email, $old_earn, $old_idx, 'تراجع عن التسليم: ' . status_label($new_key), true);
                team_commission_reverse_for_order($old_idx, 'تراجع عن التسليم: ' . status_label($new_key));
                $extra = '↩️ تم سحب العمولة بعد تغيير الحالة';
            }
        }
        // فرق العمولة بعد تغيير الحالة (على الحالة الجديدة)
        $mid_comm = status_releases_commission($new_key) ? 'confirmed' : (status_reverses_commission($new_key) ? 'reversed' : 'pending');
        if (abs($new_earn - $old_earn) >= 0.01 && $old_mk_email !== '') {
            $moved = wf_order_comm_adjust($old_ref, $old_earn, $new_earn, $mid_comm, $old_idx, $order_no);
            if ($moved != 0.0) $extra = trim($extra . ($extra !== '' ? '<br>' : '') . '💰 فرق العمولة (' . number_format($moved, 2) . ' ج.م) اتسوّى في محفظة المسوق');
        }
    }

    // ===== كتابة الصف =====
    // سبب الإلغاء/الإرجاع/عدم الرد — يظهر للمسوق والعميل في تفاصيل الطلب
    $st_reason = mb_substr(trim((string)($in['status_reason'] ?? '')), 0, 300);
    $res = ['ok' => false, 'msg' => 'تعذر حفظ التعديلات'];
    jupdate('orders.json', function (&$os) use ($order_no, $v, $new_key, $new_ref, $ref_changed, $old_row, $old_idx, $st_reason, &$res) {
        if (!is_array($os)) return false;
        foreach ($os as $i => $o) {
            if (($o['order_no'] ?? '') !== $order_no) continue;
            wf_order_apply_row_edit($os[$i], $v);
            if (array_key_exists('sub', $v)) $os[$i]['sub'] = $v['sub'];
            if ($ref_changed) $os[$i]['ref'] = $new_ref;
            if (status_key($o['status'] ?? 'pending') !== $new_key) {
                $os[$i]['status'] = $new_key;
                $os[$i]['status_at'] = date('c');
            } else {
                $os[$i]['status'] = $o['status'] ?? $new_key;
            }
            // حالة العمولة النهائية: من الحالة + العمولة الفعلية بعد التعديل
            $eff18 = round((float)($os[$i]['m_earn'] ?? 0), 2);
            $os[$i]['comm_state'] = status_releases_commission($new_key) ? ($eff18 > 0 ? 'confirmed' : 'none')
                                  : (status_reverses_commission($new_key) ? 'reversed' : ($eff18 > 0 ? 'pending' : 'none'));
            if ($st_reason !== '' && in_array($new_key, ['cancelled', 'no_answer'], true)) $os[$i]['cancel_reason'] = 'الإدارة: ' . $st_reason;
            if ($st_reason !== '' && $new_key === 'returned') $os[$i]['return_reason'] = 'الإدارة: ' . $st_reason;
            $os[$i]['adm_edited_at'] = date('c');
            $os[$i]['adm_edited_by'] = function_exists('admin_actor') ? admin_actor() : 'admin';
            $res = ['ok' => true, 'idx' => $i, 'changed' => wf_order_edit_changed_fields($old_row, $os[$i]),
                    'status_changed' => status_key($o['status'] ?? 'pending') !== $new_key,
                    'ref_changed' => $ref_changed, 'new_key' => $new_key];
            return true;
        }
        return false;
    }, []);
    if (empty($res['ok'])) return $res;

    // ===== إشعارات + أودت =====
    $row_now = null;
    foreach ((array)jload('orders.json', [], false) as $o2) if (($o2['order_no'] ?? '') === $order_no) { $row_now = $o2; break; }
    if ($row_now) {
        if (!empty($res['status_changed']) && !empty($row_now['user']) && strpos((string)$row_now['user'], 'guest:') !== 0) {
            notify_user((string)$row_now['user'], '📦 حالة طلبك أصبحت: ' . status_label($new_key), 'dashboard/orders.php');
        }
        if ($new_ref !== '' && ($mkN = find_marketer_by_code($new_ref))) {
            notify_user((string)$mkN['email'], '✏️ الإدارة عدّلت طلبًا على كودك: ' . $order_no
                . ($ref_changed ? ' (اتحوّل لك)' : ''), 'dashboard/marketer.php?mtab=orders');
        }
        if ($ref_changed && $old_ref !== '' && ($mkO = find_marketer_by_code($old_ref))) {
            notify_user((string)$mkO['email'], '🔁 الإدارة حوّلت الطلب ' . $order_no . ' لمسوق آخر — عمولته اتسحبت منه', 'dashboard/marketer.php?mtab=orders');
        }
    }
    if (function_exists('log_audit')) {
        log_audit('admin_order_full_edit', $new_ref ?: '-', [
            'order_no' => $order_no, 'changed' => $res['changed'],
            'status' => [$old_key, $new_key], 'ref_changed' => $ref_changed, 'm_earn' => [$old_earn, $new_earn],
        ]);
    }
    $res['msg'] = 'تم حفظ تعديلات الطلب ✅' . ($extra !== '' ? ' — ' . $extra : '');
    $res['extra'] = $extra;
    return $res;
}
// «حذف» المسوق للطلب = إلغاء ناعم: الطلب بيفضل عند الإدارة بحالة «ملغي» ومش بيتمسح
function wf_marketer_cancel_order($order_no, $u, $reason = '', $to = 'cancelled') {
    $order_no = mb_substr(trim((string)$order_no), 0, 40);
    $to = ($to === 'returned') ? 'returned' : 'cancelled';
    $res = ['ok' => false, 'msg' => 'الطلب غير موجود أو مش من حقك'];
    jupdate('orders.json', function (&$os) use ($order_no, $u, $reason, $to, &$res) {
        if (!is_array($os)) return false;
        foreach ($os as $i => $o) {
            if (($o['order_no'] ?? '') !== $order_no) continue;
            if (!wf_marketer_can_cancel_order($o, $u)) { $res['msg'] = 'الطلب مش بتاعك أو حالته منتهية ومش تقبل إلغاء أو إرجاع'; return false; }
            $os[$i]['status'] = $to;
            $os[$i]['comm_state'] = 'reversed';
            if ($to === 'returned') $os[$i]['return_reason'] = 'إرجاع المسوق' . ($reason !== '' ? ': ' . $reason : '');
            else $os[$i]['cancel_reason'] = 'إلغاء المسوق' . ($reason !== '' ? ': ' . $reason : '');
            $os[$i]['cancelled_by'] = 'marketer:' . (string)($u['ref_code'] ?? '');
            $os[$i]['status_at'] = date('c');
            $res = ['ok' => true, 'msg' => ($to === 'returned' ? 'تم إرجاع الطلب — فضل مسجّل عند الإدارة بحالة «مرتجع» ✅' : 'تم إلغاء الطلب — فضل مسجّل عند الإدارة بحالة «ملغي» ✅'), 'idx' => $i, 'o' => $o, 'to' => $to];
            return true;
        }
        return false;
    }, []);
    return $res;
}
// فحص عدم تكرار سحب العمولة عند إعادة محاولة حذف فشل بعد السحب: هل اتسجل قيد سحب «حذف الإدارة» للطلب ده قبل كده؟
function wf_order_delete_reversal_done($idx, $mk_email, $order_no) {
    $mk_email = strtolower(trim((string)$mk_email));
    if ($mk_email === '') return false;
    $mark = 'حذف الإدارة للطلب ' . (string)$order_no;
    foreach ((array)jload('balance_ledger.json', [], false) as $r) {
        if (!is_array($r) || ($r['type'] ?? '') !== 'reversal') continue;
        if (!isset($r['order_idx']) || (int)$r['order_idx'] !== (int)$idx) continue;
        if (strcasecmp((string)($r['email'] ?? ''), $mk_email) !== 0) continue;
        if (strpos((string)($r['reason'] ?? ''), $mark) !== false) return true;
    }
    return false;
}
// ============================================================================
//  حذف الإدارة النهائي للطلبات (واحد أو مجموعة) — للطلبات التست/المكررة/الغلط
//  الحذف «نهائي»: الصف بيتمسح من orders.json والمصفوفة بتتعاد فهرستها، فبنرحّل
//  order_idx في دفتر العمولات (balance_ledger.json) عشان عمولات باقي الطلبات
//  (خصوصاً عمولة الفريق اللي بتتجمّع بالفهرس) تفضل مربوطة بطلباتها الصح.
//  عمولة الطلب المحذوف بتتسحب بنفس قواعد إلغاء الإدارة بالظبط (مباشرة + فريق)
//  لو لسه مفعّلة (pending/confirmed) — الملغي/المرتجع أصلاً عمولته مسحوبة.
//  نسخة من كل طلب محذوف بتتأرشف في deleted_orders.json + سجل audit.
//  $targets: أرقام طلبات، أو توكن «idx|فهرس|تاريخ» للصفوف القديمة اللي من غير رقم طلب.
// ============================================================================
function wf_admin_delete_orders($targets, $reason = '') {
    $reason = mb_substr(trim((string)$reason), 0, 300);
    // ===== تطبيع الأهداف: أرقام طلبات فريدة + توكنز صفوف قديمة =====
    $want_no = [];    // order_no => true
    $want_idx = [];   // idx => at (للصفوف اللي من غير order_no)
    foreach ((array)$targets as $t) {
        if (!is_string($t) && !is_numeric($t)) continue;
        $t = mb_substr(trim((string)$t), 0, 80);
        if ($t === '') continue;
        if (preg_match('/^idx\|(\d+)\|(.+)$/', $t, $m)) $want_idx[(int)$m[1]] = $m[2];
        else $want_no[mb_substr($t, 0, 40)] = true;
    }
    if (!$want_no && !$want_idx) return ['ok' => false, 'count' => 0, 'msg' => 'مفيش طلبات محددة للحذف'];
    if (count($want_no) + count($want_idx) > 200) {
        return ['ok' => false, 'count' => 0, 'msg' => 'أقصى حد للحذف في المرة الواحدة 200 طلب — قلّل التحديد وكرّر العملية'];
    }

    // ===== تحديد الصفوف الحالية (كل الصفوف اللي رقمها مطلوب — مش أول واحد بس) =====
    $all = (array)jload('orders.json', []);
    $found = [];      // idx => row
    foreach ($all as $i => $o) {
        if (!is_array($o)) continue;
        $ono = trim((string)($o['order_no'] ?? ''));
        if ($ono !== '' && isset($want_no[$ono])) { $found[$i] = $o; continue; }
        if ($ono === '' && isset($want_idx[$i]) && (string)($o['at'] ?? '') === $want_idx[$i]) $found[$i] = $o;
    }
    if (!$found) return ['ok' => false, 'count' => 0, 'msg' => 'الطلبات المحددة مش موجودة (يمكن اتحذفت قبل كده)'];
    $missing = [];
    foreach (array_keys($want_no) as $no) {
        $hit = false;
        foreach ($found as $o) if ((string)($o['order_no'] ?? '') === $no) { $hit = true; break; }
        if (!$hit) $missing[] = $no;
    }

    // ===== 1) سحب العمولات قبل المسح (نفس قواعد إلغاء الإدارة) بالفهرس الحالي =====
    $rev_total = 0.0;
    foreach ($found as $idx => $o) {
        $ono = trim((string)($o['order_no'] ?? '')) !== '' ? (string)$o['order_no'] : ('#' . ((int)$idx + 1));
        $del_reason = 'حذف الإدارة للطلب ' . $ono . ' نهائيًا' . ($reason !== '' ? ' — ' . $reason : '');
        $ref  = trim((string)($o['ref'] ?? ''));
        $earn = round((float)($o['m_earn'] ?? 0), 2);
        $key  = status_key($o['status'] ?? 'pending');
        $comm = (string)($o['comm_state'] ?? '');
        if ($comm === '') $comm = status_releases_commission($key) ? 'confirmed'
                                : (status_reverses_commission($key) ? 'reversed' : ($earn > 0 ? 'pending' : 'none'));
        if ($ref !== '' && $earn > 0 && in_array($comm, ['pending', 'confirmed'], true) && !status_reverses_commission($key)) {
            $mk = find_marketer_by_code($ref);
            $mk_email = $mk ? (string)($mk['email'] ?? '') : '';
            if ($mk_email !== '' && !wf_order_delete_reversal_done($idx, $mk_email, $ono)) {
                if (commission_reverse($mk_email, $earn, $idx, $del_reason)) $rev_total = round($rev_total + $earn, 2);
            }
        }
        // عمولة الفريق: الدالة نفسها بتمنع التكرار (بتعكس المسجّل ناقص المعكوس بس)
        $rev_total = round($rev_total + team_commission_reverse_for_order($idx, $del_reason), 2);
    }

    // ===== 2) مسح الصفوف ذرّياً بالمطابقة على رقم الطلب/التوكن على أحدث نسخة =====
    $removed = [];    // [['idx'=>old,'order'=>row], ...]
    jupdate('orders.json', function (&$os) use ($want_no, $want_idx, &$removed) {
        if (!is_array($os)) return false;
        $removed = [];   // إعادة المحاولة عند التضارب بتشغّل الكولباك تاني: نبدأ من صفر
        $keep = [];
        foreach ($os as $i => $o) {
            $ono = is_array($o) ? trim((string)($o['order_no'] ?? '')) : '';
            $hit = ($ono !== '' && isset($want_no[$ono]))
                || ($ono === '' && isset($want_idx[$i]) && is_array($o) && (string)($o['at'] ?? '') === $want_idx[$i]);
            if ($hit) { $removed[] = ['idx' => $i, 'order' => $o]; continue; }
            $keep[] = $o;
        }
        if (!$removed) return false;
        $os = $keep;     // إعادة فهرسة تسلسلية (مفيش فجوات)
        return true;
    }, []);
    if (!$removed) {
        return ['ok' => false, 'count' => 0, 'msg' => 'تعذر حذف الطلبات دلوقتي — حاول تاني (أي عمولات اتسحبت مش هتتسحب تاني عند إعادة المحاولة)'];
    }

    // ===== 3) ترحيل order_idx في دفتر العمولات بعد إعادة الفهرسة =====
    $del_idxs = [];
    $del_no_by_idx = [];
    foreach ($removed as $r) {
        $del_idxs[] = (int)$r['idx'];
        $del_no_by_idx[(int)$r['idx']] = (string)($r['order']['order_no'] ?? '');
    }
    sort($del_idxs);
    $ledger_changed = false;
    $ledger_res = jupdate('balance_ledger.json', function (&$lg) use ($del_idxs, $del_no_by_idx, &$ledger_changed) {
        if (!is_array($lg)) return false;
        $ledger_changed = false;
        foreach ($lg as $k => $r) {
            if (!is_array($r) || !isset($r['order_idx']) || !is_numeric($r['order_idx'])) continue;
            $old = (int)$r['order_idx'];
            if (in_array($old, $del_idxs, true)) {
                // قيد لطلب اتمسح: نفك ربطه بالفهرس (عشان مايتلخبطش مع طلب تاني خد مكانه) ونختمه برقم الطلب
                $lg[$k]['order_idx'] = null;
                if (empty($lg[$k]['order_no']) && ($del_no_by_idx[$old] ?? '') !== '') $lg[$k]['order_no'] = $del_no_by_idx[$old];
                $ledger_changed = true;
                continue;
            }
            $shift = 0;
            foreach ($del_idxs as $d) { if ($d < $old) $shift++; else break; }
            if ($shift > 0) { $lg[$k]['order_idx'] = $old - $shift; $ledger_changed = true; }
        }
        return $ledger_changed;
    }, []);
    $remap_failed = $ledger_changed && ($ledger_res === false);
    if ($remap_failed) error_log('wf_admin_delete_orders: balance_ledger order_idx remap failed for deleted idxs ' . implode(',', $del_idxs));

    // ===== 4) أرشيف المحذوفات (نسخة أمان قابلة للاسترجاع اليدوي) =====
    $actor = function_exists('admin_actor') ? admin_actor() : 'admin';
    jupdate('deleted_orders.json', function (&$arc) use ($removed, $reason, $actor) {
        if (!is_array($arc)) $arc = [];
        foreach ($removed as $r) {
            $arc[] = ['order' => $r['order'], 'old_idx' => (int)$r['idx'], 'deleted_at' => date('c'), 'deleted_by' => $actor, 'reason' => $reason];
        }
        if (count($arc) > 2000) $arc = array_slice($arc, -2000);
        return true;
    }, []);

    // ===== 5) إشعارات مجمّعة (للمسوق صاحب العمولة + العميل المسجل) =====
    $by_ref = []; $by_user = [];
    $order_nos = []; $totals = 0.0;
    foreach ($removed as $r) {
        $o = $r['order'];
        $ono = trim((string)($o['order_no'] ?? '')) !== '' ? (string)$o['order_no'] : ('#' . ((int)$r['idx'] + 1));
        $order_nos[] = $ono;
        $totals = round($totals + (float)($o['total'] ?? 0), 2);
        $ref = trim((string)($o['ref'] ?? ''));
        if ($ref !== '') $by_ref[$ref][] = $ono;
        $usr = trim((string)($o['user'] ?? ''));
        if ($usr !== '' && strpos($usr, 'guest:') !== 0 && strpos($usr, '@') !== false) $by_user[strtolower($usr)][] = $ono;
    }
    $rsfx = $reason !== '' ? ' — السبب: ' . $reason : '';
    foreach ($by_ref as $ref => $nos) {
        $mk = find_marketer_by_code($ref);
        if (!$mk || empty($mk['email'])) continue;
        $txt = count($nos) === 1
            ? '🗑️ حذفت الإدارة الطلب ' . $nos[0] . ' نهائيًا' . $rsfx
            : '🗑️ حذفت الإدارة ' . count($nos) . ' طلب نهائيًا من طلبات لينكك: ' . implode('، ', array_slice($nos, 0, 10)) . (count($nos) > 10 ? '…' : '') . $rsfx;
        notify_user((string)$mk['email'], $txt, 'dashboard/marketer.php?mtab=orders');
    }
    foreach ($by_user as $email => $nos) {
        $txt = count($nos) === 1
            ? '🗑️ حذفت الإدارة طلبك ' . $nos[0] . ' نهائيًا' . $rsfx
            : '🗑️ حذفت الإدارة ' . count($nos) . ' من طلباتك نهائيًا: ' . implode('، ', array_slice($nos, 0, 10)) . (count($nos) > 10 ? '…' : '') . $rsfx;
        notify_user($email, $txt, 'dashboard/orders.php');
    }

    // ===== 6) أودت + رسالة =====
    if (function_exists('log_audit')) {
        log_audit('admin_order_delete', '-', [
            'count' => count($removed), 'order_nos' => array_slice($order_nos, 0, 100),
            'old_idxs' => $del_idxs, 'commission_reversed' => $rev_total,
            'orders_total' => $totals, 'reason' => $reason, 'ledger_remapped' => !$remap_failed,
        ]);
    }
    $msg = count($removed) === 1
        ? 'تم حذف الطلب ' . esc($order_nos[0]) . ' نهائيًا 🗑'
        : 'تم حذف ' . count($removed) . ' طلب نهائيًا 🗑';
    if ($rev_total > 0) $msg .= ' — واتسحبت عمولات ' . number_format($rev_total, 2) . ' ج.م من المسوقين (زي الإلغاء)';
    if ($missing) $msg .= ' — ملقيناش: ' . esc(implode('، ', array_slice($missing, 0, 10)));
    if ($remap_failed) $msg .= ' — ⚠️ تنبيه: تعذر تحديث ربط دفتر العمولات، راجع سجل الأخطاء';
    return ['ok' => true, 'count' => count($removed), 'order_nos' => $order_nos,
            'commission_reversed' => $rev_total, 'missing' => $missing, 'msg' => $msg];
}
function find_user($email) { return find_user_row($email); }
function find_marketer_by_code($code) {
    static $by_code = [];
    $code = trim((string)$code);
    if ($code === '') return null;
    if (array_key_exists($code, $by_code)) return $by_code[$code];
    $found = null;
    foreach (users() as $u) {
        if (!empty($u['ref_code']) && strcasecmp((string)$u['ref_code'], $code) === 0) { $found = $u; break; }
    }
    return $by_code[$code] = $found;
}
