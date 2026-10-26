<?php
$PUBLIC_PAGE = true;
require 'layout.php';

// الرجوع من حساب مستخدم مُتقمَّص إلى حساب الأدمن الأصلي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['wf_stop_impersonate']) && csrf_ok() && function_exists('wf_impersonate_stop')) {
    $imp_stop = wf_impersonate_stop();
    if (!empty($imp_stop['ok'])) {
        header('Location: ' . ($imp_stop['dest'] ?? 'admin.php?tab=users'));
        exit;
    }
}

require_admin_or_die();

// لوحة الإدارة متاحة فقط لحساب مسجل بدور admin في users.
$user = current_user();
$fresh_admin = $user ? find_user($user['email'] ?? '') : null;
if (!$fresh_admin || ($fresh_admin['role'] ?? '') !== 'admin') {
    require_admin_or_die();
}
$user = $fresh_admin;
$_SESSION['admin_role'] = 'super_admin';

// سجل دخول الأدمن في الـ audit log (مرة كل ساعة)
if (empty($_SESSION['admin_seen_at']) || (time() - (int)$_SESSION['admin_seen_at']) > 3600) {
    $_SESSION['admin_seen_at'] = time();
    log_audit('admin_session', $user['email'] ?? 'admin', ['tab' => $_GET['tab'] ?? 'overview']);
}

$tab = $_GET['tab'] ?? 'overview';
if (!is_string($tab) || !in_array($tab, ['overview', 'visitors', 'cart', 'loves', 'views', 'orders', 'products', 'marketers', 'users', 'money', 'reviews', 'notifications', 'requests', 'amazon', 'jumia', 'noon', 'chats', 'commissions', 'payments', 'audit', 'settings'], true)) {
    $tab = 'overview';
}
$msg = '';

// ===== ACTIONS =====
// الدخول بحساب أي مستخدم مسجل (للأدمن فقط، بدون تغيير كلمة سر المستخدم)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['impersonate_email'])) {
    if (!csrf_ok()) {
        $msg = '⛔ انتهت صلاحية الجلسة — حدّث الصفحة وحاول مرة أخرى';
    } else {
        $imp_res = wf_impersonate_start((string)$_POST['impersonate_email'], (string)($_POST['impersonate_from'] ?? $tab));
        if (!empty($imp_res['ok'])) {
            header('Location: ' . ($imp_res['dest'] ?? 'marketer.php'));
            exit;
        }
        $msg = '⛔ ' . ($imp_res['error'] ?? 'تعذر الدخول بحساب المستخدم');
    }
}
// 👤 طلبات العملاء (نون وأمازون): الإدارة بتعلّم «تم» أو «رفض» مع ملاحظة اختيارية للمسوق
if (isset($_POST['cust_act'], $_POST['cust_id'])) {
    $cr_res = cust_req_decide((string)$_POST['cust_id'], (string)$_POST['cust_act'], (string)($_POST['note'] ?? ''), (string)($user['email'] ?? 'admin'));
    if (!empty($cr_res['ok'])) {
        $msg = '✅ اتسجل قرار طلب العميل';
        log_audit('customer_req_' . $cr_res['rec']['status'], (string)$_POST['cust_id'], ['src' => $cr_res['rec']['source'], 'mk' => $cr_res['rec']['email']]);
    } else {
        $msg = '⚠️ ' . ($cr_res['error'] ?? 'تعذر تسجيل القرار');
    }
}
// مراجعة الدفع: لا يُقبل التأكيد/الرفض إلا بعد رفع إيصال، والتحويل ذري لمنع التكرار.
if (isset($_POST['pay_confirm_idx'])) {
    $pi = (int)$_POST['pay_confirm_idx'];
    $act = ($_POST['pay_action'] ?? '') === 'reject' ? 'reject' : 'confirm';
    $reason = mb_substr(trim((string)($_POST['pay_reason'] ?? '')), 0, 200);
    $claimed = false;
    $old_order = null;

    if ($act === 'confirm') {
        jupdate('orders.json', function (&$os) use ($pi, &$claimed, &$old_order) {
            if (!isset($os[$pi]) || ($os[$pi]['payment_status'] ?? '') !== 'proof_uploaded') return false;
            $old_order = $os[$pi];
            $os[$pi]['payment_status'] = 'confirmed';
            $os[$pi]['payment_confirmed_at'] = date('c');
            $os[$pi]['payment_confirmed_by'] = admin_actor();
            $os[$pi]['status'] = 'confirmed';
            $os[$pi]['status_at'] = date('c');
            $claimed = true;
            return true;
        }, []);
        if (!$claimed) {
            $msg = 'لا يمكن تأكيد الدفع: يلزم وجود إيصال مرفوع، أو أن الطلب سبق تأكيده.';
        } else {
            $po = jload('orders.json', [])[$pi] ?? $old_order;
            $hevo_msg = '';
            if (($po['plat'] ?? '') === 'social' && !empty($po['service_id']) && empty($po['hevo_order'])) {
                if (!function_exists('hevo_key') || hevo_key() === '' || !function_exists('curl_init')) {
                    $hr = ['error' => 'تكامل Hevo أو cURL غير مهيأ على الخادم'];
                } else {
                    $hr = hevo_add($po['service_id'], $po['link'] ?? '', (int)($po['qty'] ?? 1));
                }
                if (isset($hr['order'])) {
                    $hevo_order = (string)$hr['order'];
                    jupdate('orders.json', function (&$os) use ($pi, $hevo_order) {
                        if (!isset($os[$pi])) return false;
                        $os[$pi]['hevo_order'] = $hevo_order;
                        $os[$pi]['source_sync_status'] = 'sent';
                        $os[$pi]['source_last_error'] = '';
                        return true;
                    }, []);
                    $hevo_msg = ' — تم إرسال التنفيذ (#' . $hevo_order . ')';
                } else {
                    $hevo_error = mb_substr((string)($hr['error'] ?? 'خطأ غير معروف'), 0, 200);
                    jupdate('orders.json', function (&$os) use ($pi, $hevo_error) {
                        if (!isset($os[$pi])) return false;
                        $os[$pi]['source_sync_status'] = 'failed';
                        $os[$pi]['source_last_error'] = $hevo_error;
                        return true;
                    }, []);
                    $hevo_msg = ' — ⚠️ فشل الإرسال الآلي: ' . esc($hevo_error) . ' (يلزم التنفيذ/المراجعة يدوياً)';
                }
            }
            $txt = '✅ تم تأكيد دفع طلبك ' . ($po['order_no'] ?? '') . ' وجاري التنفيذ';
            if (!empty($po['user']) && strpos($po['user'], 'guest:') !== 0) notify_user($po['user'], $txt, 'dashboard/orders.php');
            if (!empty($po['cphone'])) wa_notify($po['cphone'], $txt . "\n" . BRAND);
            $msg = 'تم تأكيد الدفع ✅' . $hevo_msg;
            log_audit('payment_confirm', $po['ref'] ?? '-', ['order_no' => $po['order_no'] ?? '', 'amount' => $po['total'] ?? 0]);
        }
    } else {
        jupdate('orders.json', function (&$os) use ($pi, $reason, &$claimed, &$old_order) {
            if (!isset($os[$pi]) || ($os[$pi]['payment_status'] ?? '') !== 'proof_uploaded') return false;
            $old_order = $os[$pi];
            $os[$pi]['payment_status'] = 'rejected';
            $os[$pi]['payment_reject_reason'] = $reason;
            $claimed = true;
            return true;
        }, []);
        if (!$claimed) {
            $msg = 'لا يمكن رفض الإيصال: لا يوجد إيصال بانتظار المراجعة.';
        } else {
            $po = $old_order;
            $txt = '❌ لم نتمكن من تأكيد تحويل الطلب ' . ($po['order_no'] ?? '') . ($reason ? ': ' . $reason : '') . ' — ارفع الإيصال مرة أخرى';
            if (!empty($po['user']) && strpos($po['user'], 'guest:') !== 0) notify_user($po['user'], $txt, 'dashboard/pay.php?o=' . ($po['order_no'] ?? ''));
            if (!empty($po['cphone'])) wa_notify($po['cphone'], $txt);
            $msg = 'تم رفض الإيصال';
            log_audit('payment_reject', $po['ref'] ?? '-', ['order_no' => $po['order_no'] ?? '', 'amount' => $po['total'] ?? 0, 'reason' => $reason]);
        }
    }
    $orders = jload('orders.json', []);
}
if (isset($_POST['order_idx'], $_POST['new_status'])) {
    $i = (int)$_POST['order_idx'];
    $new_key  = status_key($_POST['new_status']);
    $old_orders = jload('orders.json', []);
    $old_key  = status_key($old_orders[$i]['status'] ?? 'pending');

    if (isset($old_orders[$i])) {
        // ===== دورة حياة العمولة: تحرير عند التسليم / عكس عند الإلغاء أو الإرجاع =====
        $o_ref = (string)($old_orders[$i]['ref'] ?? '');
        $o_ern = (float)($old_orders[$i]['m_earn'] ?? 0);
        $mk_email = '';
        if ($o_ref !== '' && ($mk = find_marketer_by_code($o_ref))) $mk_email = (string)($mk['email'] ?? '');

        if ($mk_email !== '' && $o_ern > 0) {
            if (status_releases_commission($new_key) && !status_releases_commission($old_key)) {
                commission_release($mk_email, $o_ern, $i, 'تأكيد تسليم الأوردر');
                team_commission_release_for_order($i, 'تأكيد تسليم الأوردر');
                $msg_extra = '✅ تم تحرير عمولة ' . number_format($o_ern, 2) . ' ج.م للمسوق';
            } elseif (status_reverses_commission($new_key) && !status_reverses_commission($old_key)) {
                commission_reverse($mk_email, $o_ern, $i, status_label($new_key));
                team_commission_reverse_for_order($i, status_label($new_key));
                $msg_extra = '↩️ تم سحب عمولة ' . number_format($o_ern, 2) . ' ج.م من المسوق';
            } elseif (status_releases_commission($old_key) && !status_releases_commission($new_key)) {
                commission_reverse($mk_email, $o_ern, $i, 'تراجع عن التسليم: ' . status_label($new_key));
                team_commission_reverse_for_order($i, 'تراجع عن التسليم: ' . status_label($new_key));
                $msg_extra = '↩️ تم سحب العمولة بعد تغيير الحالة';
            }
        }

        // سبب الإلغاء/الإرجاع/عدم الرد من الإدارة — يظهر للمسوق والعميل في تفاصيل الطلب
        $st_reason_q = mb_substr(trim((string)($_POST['status_reason'] ?? '')), 0, 300);
        jupdate('orders.json', function (&$os) use ($i, $new_key, $st_reason_q) {
            if (!is_array($os) || !isset($os[$i])) return false;
            $os[$i]['status']     = $new_key;
            $os[$i]['status_at']  = date('c');
            $os[$i]['comm_state'] = status_releases_commission($new_key) ? 'confirmed'
                                  : (status_reverses_commission($new_key) ? 'reversed' : 'pending');
            if ($st_reason_q !== '' && in_array($new_key, ['cancelled', 'no_answer'], true)) $os[$i]['cancel_reason'] = 'الإدارة: ' . $st_reason_q;
            if ($st_reason_q !== '' && $new_key === 'returned') $os[$i]['return_reason'] = 'الإدارة: ' . $st_reason_q;
            return true;
        }, []);

        // إشعار العميل والمسوق بتغيير الحالة
        $oo = jload('orders.json', [])[$i] ?? null;
        if ($oo) {
            $st_lbl = status_label($new_key);
            // لو الإدارة كتبت سبب إلغاء/إرجاع/عدم رد — السبب يوصل نصًا في الإشعار نفسه
            $st_reason_sfx = ($st_reason_q !== '' && in_array($new_key, ['cancelled', 'no_answer', 'returned'], true)) ? ' — السبب: ' . $st_reason_q : '';
            if (!empty($oo['user']) && strpos($oo['user'], 'guest:') !== 0) {
                notify_user($oo['user'], '📦 حالة طلبك أصبحت: ' . $st_lbl . $st_reason_sfx, 'dashboard/orders.php');
            }
            if ($o_ref !== '' && ($mk3 = find_marketer_by_code($o_ref))) {
                notify_user($mk3['email'], '📦 حالة أوردر من لينكك: ' . $st_lbl . $st_reason_sfx, 'dashboard/marketer.php');
            }
        }
        log_audit('order_status_change', $o_ref ?: '-', [
            'order_idx' => $i, 'from' => $old_key, 'to' => $new_key, 'commission' => $o_ern
        ]);
        $msg = 'تم تحديث حالة الطلب بنجاح ✅' . (($st_reason_q !== '' && in_array($new_key, ['cancelled', 'no_answer', 'returned'], true)) ? ' — وحُفظ السبب وهيظهر للمسوق والعميل' : '') . (isset($msg_extra) ? '<br>' . $msg_extra : '');
    }
}
// ملاحظة الإدارة على الطلب (سجل ملاحظات بتتراكم على الطلب نفسه)
if (isset($_POST['order_note_idx'])) {
    $ni = (int)$_POST['order_note_idx'];
    $ntext = mb_substr(trim((string)($_POST['order_note_text'] ?? '')), 0, 500);
    if ($ntext === '') {
        $msg = 'اكتب نص الملاحظة الأول.';
    } else {
        $saved_note = false;
        $saved_ref = '';
        $saved_ono = '';
        jupdate('orders.json', function (&$os) use ($ni, $ntext, &$saved_note, &$saved_ref, &$saved_ono) {
            if (!is_array($os) || !isset($os[$ni])) return false;
            if (!isset($os[$ni]['admin_notes']) || !is_array($os[$ni]['admin_notes'])) $os[$ni]['admin_notes'] = [];
            $os[$ni]['admin_notes'][] = ['note' => $ntext, 'by' => admin_actor(), 'at' => date('c')];
            $os[$ni]['admin_note'] = $ntext;
            $saved_note = true;
            $saved_ref  = (string)($os[$ni]['ref'] ?? '');
            $saved_ono  = (string)($os[$ni]['order_no'] ?? '');
            return true;
        }, []);
        if ($saved_note) {
            $msg = 'تم حفظ ملاحظة الإدارة ✅';
            log_audit('order_note', '#' . $ni, ['len' => mb_strlen($ntext)]);
            // الملاحظة توصل للمسوق صاحب الطلب في شات الدعم بتاعه + إشعار — مش بس تتخزن على الطلب
            $mk_note = ($saved_ref !== '') ? find_marketer_by_code($saved_ref) : null;
            if (!$mk_note && $saved_ref !== '' && strpos($saved_ref, '@') !== false && function_exists('find_user')) $mk_note = find_user($saved_ref); // طلبات قديمة ref بتاعها إيميل مش كود
            if ($mk_note) {
                $mk_note_email = strtolower(trim((string)($mk_note['email'] ?? '')));
                if ($mk_note_email !== '') {
                    $mk_note_text = '📝 ملاحظة إدارة على الطلب ' . $saved_ono . ': ' . $ntext;
                    if (function_exists('wf_support_admin_message')) wf_support_admin_message($mk_note_email, $mk_note_text);
                    notify_user($mk_note_email, $mk_note_text, 'dashboard/support.php?ctx=marketer');
                }
            }
        } else {
            $msg = '⚠️ تعذر حفظ الملاحظة (الطلب غير موجود).';
        }
    }
    $orders = jload('orders.json', []);
}
// تعديل الإدارة الكامل لأي طلب: كل التفاصيل (العميل/المنتج/الكمية/السعر/الشحن/العمولات/الحالة/كود المسوق)
if (isset($_POST['adm_order_action'], $_POST['adm_order_no']) && (string)$_POST['adm_order_action'] === 'edit') {
    $adm_edit_res = wf_admin_apply_order_edit((string)$_POST['adm_order_no'], $_POST);
    $msg = !empty($adm_edit_res['ok']) ? (string)$adm_edit_res['msg'] : ('⚠️ ' . (string)($adm_edit_res['msg'] ?? 'تعذر حفظ التعديلات'));
    $orders = jload('orders.json', []);
}
// حذف الإدارة النهائي للطلبات: طلب واحد (زرار الصف / شاشة التعديل الكامل) أو مجموعة محددة بمربعات التحديد
if (isset($_POST['adm_order_action'], $_POST['adm_order_no']) && (string)$_POST['adm_order_action'] === 'delete') {
    if (!csrf_ok()) {
        $msg = 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.';
    } else {
        $adm_del_res = wf_admin_delete_orders([(string)$_POST['adm_order_no']], (string)($_POST['delete_reason'] ?? ''));
        $msg = !empty($adm_del_res['ok']) ? (string)$adm_del_res['msg'] : ('⚠️ ' . (string)($adm_del_res['msg'] ?? 'تعذر حذف الطلب'));
    }
    $orders = jload('orders.json', []);
}
if (isset($_POST['bulk_delete_orders'])) {
    $bulk_order_nos = is_array($_POST['bulk_order_nos'] ?? null) ? $_POST['bulk_order_nos'] : [];
    if (!csrf_ok()) {
        $msg = 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.';
    } elseif (!$bulk_order_nos) {
        $msg = '⚠️ حدّد طلب واحد على الأقل من مربعات التحديد قبل الحذف الجماعي.';
    } else {
        $adm_bulk_del_res = wf_admin_delete_orders($bulk_order_nos, (string)($_POST['delete_reason'] ?? ''));
        $msg = !empty($adm_bulk_del_res['ok']) ? (string)$adm_bulk_del_res['msg'] : ('⚠️ ' . (string)($adm_bulk_del_res['msg'] ?? 'تعذر حذف الطلبات'));
    }
    $orders = jload('orders.json', []);
}
// إعادة محاولة حقيقية للطلب الاجتماعي المؤكد الذي فشل إرساله إلى Hevo.
if (isset($_POST['retry_order'])) {
    $ri = (int)$_POST['retry_order'];
    $retry_claimed = false;
    $retry_order_data = null;
    jupdate('orders.json', function (&$os) use ($ri, &$retry_claimed, &$retry_order_data) {
        if (!isset($os[$ri]) || ($os[$ri]['payment_status'] ?? '') !== 'confirmed'
            || ($os[$ri]['plat'] ?? '') !== 'social' || empty($os[$ri]['service_id'])
            || !empty($os[$ri]['hevo_order']) || ($os[$ri]['source_sync_status'] ?? '') !== 'failed') return false;
        $os[$ri]['source_sync_attempts'] = (int)($os[$ri]['source_sync_attempts'] ?? 0) + 1;
        $os[$ri]['source_sync_status'] = 'processing';
        $os[$ri]['source_last_error'] = '';
        $retry_order_data = $os[$ri];
        $retry_claimed = true;
        return true;
    }, []);
    if (!$retry_claimed) {
        $msg = 'لا يمكن إعادة الإرسال: الطلب ليس طلباً اجتماعياً مؤكداً وفاشلاً، أو تم إرساله بالفعل.';
    } else {
        $attempts = (int)($retry_order_data['source_sync_attempts'] ?? 0);
        if (!function_exists('hevo_key') || hevo_key() === '' || !function_exists('curl_init')) {
            $hr = ['error' => 'Hevo API غير مهيأ على الخادم'];
        } else {
            $hr = hevo_add($retry_order_data['service_id'], $retry_order_data['link'] ?? '', (int)($retry_order_data['qty'] ?? 1));
        }
        if (isset($hr['order'])) {
            $hevo_order = (string)$hr['order'];
            jupdate('orders.json', function (&$os) use ($ri, $hevo_order) {
                if (!isset($os[$ri]) || !empty($os[$ri]['hevo_order'])) return false;
                $os[$ri]['hevo_order'] = $hevo_order;
                $os[$ri]['source_sync_status'] = 'sent';
                $os[$ri]['source_last_error'] = '';
                return true;
            }, []);
            $msg = 'تم إرسال الطلب للمصدر بنجاح (#' . esc($hevo_order) . ') ✅';
        } else {
            $hevo_error = mb_substr((string)($hr['error'] ?? 'خطأ غير معروف'), 0, 200);
            jupdate('orders.json', function (&$os) use ($ri, $hevo_error) {
                if (!isset($os[$ri])) return false;
                $os[$ri]['source_sync_status'] = 'failed';
                $os[$ri]['source_last_error'] = $hevo_error;
                return true;
            }, []);
            $msg = 'فشل إعادة الإرسال: ' . esc($hevo_error);
        }
        log_audit('retry_order_source_sync', '#' . $ri, ['attempts' => $attempts, 'success' => isset($hr['order'])]);
    }
    $orders = jload('orders.json', []);
}
// حفظ تعديلات المنتج كـ overrides مستقلة عن ملفات المصدر حتى لا يمحوها تحديث الكتالوج.
if (isset($_POST['ov_plat'], $_POST['ov_id'], $_POST['ov_action'])) {
    $raw_plat = is_scalar($_POST['ov_plat']) ? (string)$_POST['ov_plat'] : '';
    $raw_id = is_scalar($_POST['ov_id']) ? trim((string)$_POST['ov_id']) : '';
    $plat = preg_replace('/[^a-z0-9_]/i', '', $raw_plat);
    $action = is_scalar($_POST['ov_action']) ? (string)$_POST['ov_action'] : '';
    $valid_plats = wf_catalog_plats();
    if (!csrf_ok()) {
        $msg = 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.';
    } elseif (!in_array($plat, $valid_plats, true) || $raw_id === '' || strlen($raw_id) > 180) {
        $msg = 'بيانات المنتج غير صالحة.';
    } else {
        $ok = $plat . '#' . $raw_id;
        $ov_fields = ['hide', 'pin', 'title', 'category', 'image', 'full_details', 'delete'];
        if (!in_array($action, $ov_fields, true)) {
            $msg = 'نوع التعديل غير معروف.';
        } else {
            $saved = false;
            $fail_reason = '';
            // قسم المنتج الحالي وقت التعديل: الفورم بيعرضه كخيار، فالسيرفر لازم يقبله حتى لو مش من الأقسام الموحدة
            $cur_cat = '';
            if ($action === 'full_details' || $action === 'category') {
                $cur_prod = function_exists('find_product_by_id') ? find_product_by_id($raw_id, $plat) : null;
                $cur_cat = is_array($cur_prod) ? trim((string)($cur_prod['category'] ?? '')) : '';
            }
            jupdate('product_overrides.json', function (&$ov) use ($ok, $action, &$saved, &$fail_reason, $cur_cat) {
                if (!is_array($ov)) $ov = [];
                if (!isset($ov[$ok]) || !is_array($ov[$ok])) $ov[$ok] = [];
                if ($action === 'hide') $ov[$ok]['hidden'] = empty($ov[$ok]['hidden']);
                elseif ($action === 'pin') $ov[$ok]['pinned'] = empty($ov[$ok]['pinned']);
                elseif ($action === 'delete') {
                    $ov[$ok]['deleted'] = true;
                    $ov[$ok]['deleted_at'] = date('c');
                } elseif ($action === 'title') $ov[$ok]['title'] = mb_substr(trim((string)($_POST['ov_title'] ?? '')), 0, 160);
                elseif ($action === 'category') {
                    $category = trim((string)($_POST['ov_category'] ?? ''));
                    if ($category === '' || in_array($category, wf_category_canonical(), true) || ($cur_cat !== '' && $category === $cur_cat)) $ov[$ok]['category'] = $category;
                    else { $fail_reason = 'القسم المختار غير معروف: ' . mb_substr($category, 0, 60); return false; }
                } elseif ($action === 'image') {
                    $image = trim((string)($_POST['ov_image'] ?? ''));
                    if ($image === '' || wf_image_url_allowed($image)) $ov[$ok]['image'] = mb_substr($image, 0, 600);
                    else { $fail_reason = 'رابط الصورة غير مقبول: ' . mb_substr($image, 0, 80); return false; }
                } elseif ($action === 'full_details') {
                    $name = trim((string)($_POST['full_name'] ?? ''));
                    $category = trim((string)($_POST['full_category'] ?? ''));
                    $price_raw = trim((string)($_POST['full_price'] ?? ''));
                    if ($name === '' || mb_strlen($name) > 200) { $fail_reason = 'الاسم فاضي أو أطول من 200 حرف'; return false; }
                    if (!is_numeric($price_raw) || (float)$price_raw < 0) { $fail_reason = 'السعر لازم يكون رقمًا صحيحًا أو عشريًا 0 أو أكبر'; return false; }
                    if ($category !== '' && !in_array($category, wf_category_canonical(), true) && $category !== $cur_cat) { $fail_reason = 'القسم غير معروف: ' . mb_substr($category, 0, 60); return false; }
                    $split_lines = function ($value, $max_items = 40, $pattern = '/[\r\n,]+/u') {
                        $parts = preg_split($pattern, (string)$value) ?: [];
                        $parts = array_slice(array_values(array_unique(array_filter(array_map('trim', $parts)))), 0, $max_items);
                        return $parts;
                    };
                    // الصور: رابط في كل سطر — والفواصل ماتبقتش فاصل تقسيم عشان فيه روابط CDN بتحتوي فاصلة في اسم الملف
                    $images = $split_lines($_POST['full_images'] ?? '', 30, '/[\r\n]+/u');
                    foreach ($images as $image) if (!wf_image_url_allowed($image)) { $fail_reason = 'رابط صورة غير مقبول: ' . mb_substr($image, 0, 80); return false; }
                    $variants_raw = trim((string)($_POST['full_variants'] ?? ''));
                    $variants = $variants_raw === '' ? [] : json_decode($variants_raw, true);
                    if (!is_array($variants) || count($variants) > 120) { $fail_reason = 'JSON الخيارات المركبة غير صالح (أو أكثر من 120 خيارًا)'; return false; }
                    foreach ($variants as $variant) {
                        if (!is_array($variant)) { $fail_reason = 'كل خيار مركّب لازم يكون JSON object'; return false; }
                        $variant_image = trim((string)($variant['image'] ?? ''));
                        if ($variant_image !== '' && !wf_image_url_allowed($variant_image)) { $fail_reason = 'رابط صورة الخيار غير مقبول: ' . mb_substr($variant_image, 0, 80); return false; }
                    }
                    $ov[$ok]['name'] = mb_substr($name, 0, 200);
                    $ov[$ok]['category'] = $category;
                    $ov[$ok]['price'] = (float)$price_raw;
                    $ov[$ok]['sku'] = mb_substr(trim((string)($_POST['full_sku'] ?? '')), 0, 100);
                    $ov[$ok]['description'] = mb_substr(trim((string)($_POST['full_description'] ?? '')), 0, 12000);
                    $ov[$ok]['images'] = $images;
                    $ov[$ok]['sizes'] = $split_lines($_POST['full_sizes'] ?? '', 80);
                    $ov[$ok]['colors'] = $split_lines($_POST['full_colors'] ?? '', 80);
                    $ov[$ok]['features'] = mb_substr(trim((string)($_POST['full_features'] ?? '')), 0, 6000);
                    $ov[$ok]['specifications'] = mb_substr(trim((string)($_POST['full_specs'] ?? '')), 0, 6000);
                    $ov[$ok]['variants'] = array_values($variants);
                }
                $saved = true;
                return true;
            }, []);
            if ($saved) {
                unset($GLOBALS['_WF_PRODUCT_OVERRIDES']);
                if ($action === 'delete') {
                    log_audit('product_permanent_delete', $ok, ['scope' => 'storefront catalog only']);
                    $msg = 'تم حذف المنتج من المتجر نهائيًا ✅ (لم تُمس الطلبات أو السجلات التاريخية)';
                } else {
                    log_audit('product_override_' . $action, $ok, $action === 'full_details' ? ['fields' => 'name,category,price,images,description,options,specifications'] : []);
                    $msg = 'تم حفظ التعديلات ✅';
                }
            } else $msg = 'تعذر الحفظ' . ($fail_reason !== '' ? ': ' . $fail_reason : '. راجع الاسم والسعر والقسم وروابط الصور.');
        }
    }
}
// حذف جماعي: تحديد أكتر من منتج وحذفهم مرة واحدة (overrides مستقلة عن ملفات المصدر).
if (isset($_POST['bulk_delete_products'])) {
    $bulk_keys = is_array($_POST['bulk_keys'] ?? null) ? $_POST['bulk_keys'] : [];
    if (!csrf_ok()) {
        $msg = 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.';
    } else {
        $bulk_count = 0;
        jupdate('product_overrides.json', function (&$ov) use ($bulk_keys, &$bulk_count) {
            if (!is_array($ov)) $ov = [];
            foreach ($bulk_keys as $raw_key) {
                if (!is_string($raw_key) || strpos($raw_key, '#') === false) continue;
                [$plat_part, $id_part] = explode('#', $raw_key, 2);
                $plat_part = preg_replace('/[^a-z0-9_]/i', '', $plat_part);
                $id_part = trim($id_part);
                if ($id_part === '' || strlen($id_part) > 180 || !in_array($plat_part, wf_catalog_plats(), true)) continue;
                $key = $plat_part . '#' . $id_part;
                if (!isset($ov[$key]) || !is_array($ov[$key])) $ov[$key] = [];
                if (empty($ov[$key]['deleted'])) {
                    $ov[$key]['deleted'] = true;
                    $ov[$key]['deleted_at'] = date('c');
                    $bulk_count++;
                }
            }
            return $bulk_count > 0;
        }, []);
        unset($GLOBALS['_WF_PRODUCT_OVERRIDES']);
        if ($bulk_count > 0) {
            log_audit('product_bulk_delete', '-', ['count' => $bulk_count]);
            $msg = 'تم حذف ' . $bulk_count . ' منتج من المتجر نهائيًا ✅ (لم تُمس الطلبات أو السجلات التاريخية)';
        } else {
            $msg = 'مفيش منتجات محددة صالحة للحذف.';
        }
    }
}
// تحديث السعر السريع يكتب override دائمًا بدل تعديل ملفات المصدر التي يعاد بناؤها.
if (isset($_POST['edit_prod_plat'], $_POST['edit_prod_id'], $_POST['new_price'])) {
    $eplat = is_scalar($_POST['edit_prod_plat']) ? preg_replace('/[^a-z0-9_]/i', '', (string)$_POST['edit_prod_plat']) : '';
    $epid = is_scalar($_POST['edit_prod_id']) ? trim((string)$_POST['edit_prod_id']) : '';
    $raw_price = trim((string)$_POST['new_price']);
    if (!csrf_ok()) $msg = 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.';
    elseif (in_array($eplat, wf_catalog_plats(), true) && $epid !== '' && is_numeric($raw_price) && (float)$raw_price >= 0) {
        $price = (float)$raw_price; $key = $eplat . '#' . $epid;
        jupdate('product_overrides.json', function (&$ov) use ($key, $price) {
            if (!is_array($ov)) $ov = [];
            if (!isset($ov[$key]) || !is_array($ov[$key])) $ov[$key] = [];
            $ov[$key]['price'] = $price;
            return true;
        }, []);
        unset($GLOBALS['_WF_PRODUCT_OVERRIDES']);
        log_audit('product_override_price', $key, ['price' => $price]);
        $msg = 'تم تحديث السعر وحفظه بشكل دائم ✅';
    } else $msg = 'السعر أو بيانات المنتج غير صالحة.';
}
// حذف مستخدم (مع حماية: منع حذف النفس + منع حذف آخر أدمن)
if (isset($_GET['del_user'])) {
    $del_email = (string)$_GET['del_user'];
    $tgt = find_user($del_email);
    if ($tgt) {
        if (!empty($user['email']) && strcasecmp((string)$user['email'], $del_email) === 0) {
            $msg = '⛔ مش مسموح تحذف حسابك وأنت داخل به';
        } elseif (($tgt['role'] ?? '') === 'admin') {
            $admins = array_filter(users(), function ($u) { return ($u['role'] ?? '') === 'admin'; });
            if (count($admins) <= 1) {
                $msg = '⛔ مش مسموح حذف آخر حساب أدمن في النظام';
            }
        }
        if ($msg === '') {
            usr_delete_account($del_email);
            log_audit('delete_user', $del_email, ['name' => $tgt['name'] ?? '', 'role' => $tgt['role'] ?? '']);
            $msg = 'تم حذف المستخدم ✅';
        }
    }
}
// تعديل دور مستخدم أو رصيده (مع حماية: منع تغيير دور الذات + تسجيل في Audit Log)
if (isset($_POST['edit_email'], $_POST['new_role'])) {
    $tgt_email = (string)$_POST['edit_email'];
    $new_role  = in_array($_POST['new_role'], ['marketer', 'admin'], true) ? $_POST['new_role'] : 'marketer'; // حساب العميل اتلغى
    $before = find_user($tgt_email);
    $is_self = !empty($user['email']) && strcasecmp((string)$user['email'], $tgt_email) === 0;

    // حماية: الأدمن مش يقدر ينزّل رتبة نفسه (يمنع قفل اللوحة بالغلط)
    if ($is_self && ($before['role'] ?? '') === 'admin' && $new_role !== 'admin') {
        $msg = '⛔ مش مسموح تنزّل رتبة حسابك بنفسك — استخدم حساب أدمن تاني';
    } else {
        // حماية: لازم يفضل أدمن واحد على الأقل في النظام
        $admins = array_filter(users(), function ($u) { return ($u['role'] ?? '') === 'admin'; });
        if (($before['role'] ?? '') === 'admin' && $new_role !== 'admin' && count($admins) <= 1) {
            $msg = '⛔ لازم يفضل أدمن واحد على الأقل في النظام';
        } else {
            $old_role = $before['role'] ?? 'marketer';
            $old_bal  = (float)($before['balance'] ?? 0);
            $new_bal  = null;
            if (isset($_POST['new_balance']) && $_POST['new_balance'] !== '') $new_bal = (float)$_POST['new_balance'];

            update_user($tgt_email, function (&$u) use ($new_role, $new_bal) {
                $u['role'] = $new_role;
                if ($new_bal !== null) $u['balance'] = $new_bal;
            });

            if ($old_role !== $new_role) {
                log_audit('change_user_role', $tgt_email, ['from' => $old_role, 'to' => $new_role]);
                notify_user($tgt_email, '🔔 تم تحديث صلاحيات حسابك إلى: ' . ($new_role === 'admin' ? 'مدير' : 'مسوق'), 'dashboard/');
            }
            if ($new_bal !== null && abs($new_bal - $old_bal) > 0.001) {
                log_audit('change_user_balance', $tgt_email, ['from' => $old_bal, 'to' => $new_bal, 'diff' => round($new_bal - $old_bal, 2)]);
            }
            $msg = 'تم تحديث بيانات المستخدم ✅';
        }
    }
}
// حذف إشعار
if (isset($_GET['del_notif'])) {
    $ns = jload('notifications.json', []);
    $ns = array_values(array_filter($ns, function ($n, $k) { return (int)$k !== (int)$_GET['del_notif']; }, ARRAY_FILTER_USE_KEY));
    jsave('notifications.json', $ns);
    $msg = 'تم حذف الإشعار ✅';
}
// حذف مراجعة
if (isset($_GET['del_review'])) {
    $rs = jload('reviews.json', []);
    $rs = array_values(array_filter($rs, function ($r, $k) { return (int)$k !== (int)$_GET['del_review']; }, ARRAY_FILTER_USE_KEY));
    jsave('reviews.json', $rs);
    $msg = 'تم حذف المراجعة ✅';
}
// تصفير سجل معين
if (isset($_POST['clear_log'])) {
    $cl = $_POST['clear_log'];
    if ($cl === 'cart') { jsave('cart_log.json', []); $msg = 'تم مسح سجل السلة ✅'; }
    elseif ($cl === 'love') { jsave('love_log.json', []); $msg = 'تم مسح سجل اللاڤ ✅'; }
}
// ===== إدارة المسوقين: موافقة / إيقاف / تفعيل =====
if (isset($_GET['mk_status'], $_GET['mk_email'])) {
    $new_st = in_array($_GET['mk_status'], ['approved', 'suspended', 'pending'], true) ? $_GET['mk_status'] : 'approved';
    update_user($_GET['mk_email'], function (&$u) use ($new_st) { $u['status'] = $new_st; });
    log_audit('change_marketer_status', $_GET['mk_email'], ['status' => $new_st]);
    $msg = 'تم تحديث حالة المسوق إلى: ' . ($new_st === 'approved' ? 'موافق عليه ✅' : ($new_st === 'suspended' ? 'موقوف ⛔' : 'قيد المراجعة ⏳'));
}
// ===== تعيين نسبة عمولة مخصصة لمسوق مميز =====
if (isset($_POST['mk_email_comm'], $_POST['mk_comm_pct'])) {
    $cmk_email = (string)$_POST['mk_email_comm'];
    $cmk_pct = max(0, min(100, (float)$_POST['mk_comm_pct']));
    update_user($cmk_email, function (&$u) use ($cmk_pct) { $u['custom_comm_pct'] = $cmk_pct; });
    log_audit('set_custom_commission', $cmk_email, ['pct' => $cmk_pct]);
    $msg = 'تم تعيين نسبة عمولة مخصصة (' . $cmk_pct . '%) للمسوق بنجاح ✅';
}
// ===== تغيير حالة العمولة (pending → confirmed → paid) =====
if (isset($_POST['comm_email'], $_POST['comm_new_status'])) {
    $new_cs = in_array($_POST['comm_new_status'], ['pending', 'confirmed', 'paid'], true) ? $_POST['comm_new_status'] : 'pending';
    update_user($_POST['comm_email'], function (&$u) use ($new_cs) { $u['comm_status'] = $new_cs; });
    log_audit('change_commission_status', $_POST['comm_email'], ['status' => $new_cs]);
    $msg = 'تم تحديث حالة عمولة المسوق ✅';
}
// ===== رفض طلب سحب (مع إرجاع المبلغ لرصيد المسوق) =====
if (isset($_POST['payout_reject'])) {
    $ri = (int)$_POST['payout_reject'];
    // سبب رفض السحب (اختياري) — بيتخزن ويظهر للمسوق في سجله وفي ديالوج التفاصيل
    $r_reason = mb_substr(trim((string)($_POST['payout_reject_reason'] ?? '')), 0, 300);
    jupdate('payouts.json', function (&$po) use ($ri, $r_reason) {
        if (!is_array($po) || !isset($po[$ri])) return false;
        if (($po[$ri]['status'] ?? '') !== 'قيد المراجعة') return false;
        $po[$ri]['status'] = 'مرفوض';
        $po[$ri]['reject_reason'] = $r_reason;
        $po[$ri]['processed_at'] = date('c');
        $po[$ri]['processed_by'] = $user['email'] ?? 'admin';
        return true;
    }, []);
    $po_now = jload('payouts.json', []);
    if (isset($po_now[$ri]) && ($po_now[$ri]['status'] ?? '') === 'مرفوض') {
        $amt = (float)($po_now[$ri]['amount'] ?? 0);
        $who = $po_now[$ri]['user'] ?? '';
        if ($amt > 0 && $who !== '') {
            update_user($who, function (&$u) use ($amt) {
                $u['balance'] = round((float)($u['balance'] ?? 0) + $amt, 2);
            });
            notify_user($who, '↩️ تم رفض طلب السحب (' . number_format($amt, 2) . ' ج.م) ورجوع المبلغ لرصيدك' . ($r_reason !== '' ? ' — السبب: ' . $r_reason : ''), 'dashboard/balance.php');
        }
        log_audit('reject_payout', $who, ['amount' => $amt, 'idx' => $ri, 'reason' => $r_reason]);
        if ($amt > 0 && $who !== '') balance_ledger_add($who, 'payout_refund', $amt, 'رفض طلب السحب — إرجاع المبلغ');
        $msg = 'تم رفض طلب السحب وإرجاع ' . number_format($amt, 2) . ' ج.م لرصيد المسوق ✅';
    }
}

// ===== تسجيل رقم تحويل لطلب السحب =====
if (isset($_POST['payout_idx'], $_POST['payout_transfer'])) {
    $pi = (int)$_POST['payout_idx'];
    jupdate('payouts.json', function (&$po_arr) use ($pi, $user) {
        if (!is_array($po_arr) || !isset($po_arr[$pi])) return false;
        $po_arr[$pi]['transfer_ref'] = trim((string)$_POST['payout_transfer']);
        $po_arr[$pi]['status'] = 'موافق عليه';
        $po_arr[$pi]['processed_at'] = date('c');
        $po_arr[$pi]['processed_by'] = $user['email'] ?? 'admin';
        return true;
    }, []);
    $po_chk = jload('payouts.json', []);
    if (isset($po_chk[$pi]) && ($po_chk[$pi]['status'] ?? '') === 'موافق عليه') {
        log_audit('approve_payout_with_transfer', $po_chk[$pi]['user'] ?? '', [
            'amount' => $po_chk[$pi]['amount'] ?? 0,
            'method' => $po_chk[$pi]['method'] ?? '',
            'transfer_ref' => $po_chk[$pi]['transfer_ref'] ?? ''
        ]);
        notify_user($po_chk[$pi]['user'] ?? '', '✅ تم تحويل ' . number_format((float)($po_chk[$pi]['amount'] ?? 0), 2)
                    . ' ج.م — رقم العملية: ' . ($po_chk[$pi]['transfer_ref'] ?: '—'), 'dashboard/balance.php');
        $msg = 'تم اعتماد طلب السحب وتسجيل رقم التحويل ✅';
    }
}

// ===== تسجيل رقم تحويل لطلب السحب (قديم) =====
if (isset($_POST['payout_idx'], $_POST['payout_transfer'])) {
    $po_arr = jload('payouts.json', []);
    $pi = (int)$_POST['payout_idx'];
    if (isset($po_arr[$pi])) {
        $po_arr[$pi]['transfer_ref'] = trim((string)$_POST['payout_transfer']);
        $po_arr[$pi]['status'] = 'موافق عليه';
        $po_arr[$pi]['processed_at'] = date('c');
        $po_arr[$pi]['processed_by'] = $user['email'] ?? 'admin';
        jsave('payouts.json', $po_arr);
        log_audit('approve_payout_with_transfer', $po_arr[$pi]['user'] ?? '', ['amount' => $po_arr[$pi]['amount'] ?? 0, 'transfer_ref' => $po_arr[$pi]['transfer_ref']]);
        $msg = 'تم اعتماد طلب السحب وتسجيل رقم التحويل ✅';
    }
}
// ===== LOAD ALL ANALYTICS DATA =====
$an = jload('analytics.json', []);
$visitors_map = is_array($an['visitors'] ?? null) ? $an['visitors'] : [];
$total_pageviews = (int)($an['total_pageviews'] ?? 0);
$daily_map = is_array($an['daily'] ?? null) ? $an['daily'] : [];
$pages_map = is_array($an['pages'] ?? null) ? $an['pages'] : [];
$sources_map = is_array($an['sources'] ?? null) ? $an['sources'] : [];
$devices_map = is_array($an['devices'] ?? null) ? $an['devices'] : [];

$views_map = jload('views.json', []);
if (!is_array($views_map)) $views_map = [];
$total_prod_views = array_sum($views_map);

// إذا كان analytics.json جديداً، ندمج إحصائيات المشاهدات السابقة حتى لا يبدأ من الصفر
$unique_visitors_count = count($visitors_map);
if ($total_pageviews < $total_prod_views) {
    $total_pageviews = $total_prod_views + $unique_visitors_count;
}

$now_ts = time();
$online_now = 0;
$today_str = date('Y-m-d');
$today_visitors = isset($daily_map[$today_str]['uv']) && is_array($daily_map[$today_str]['uv']) ? count($daily_map[$today_str]['uv']) : 0;
$today_views = (int)($daily_map[$today_str]['views'] ?? 0);
foreach ($visitors_map as $vinfo) {
    $lts = (int)($vinfo['last_ts'] ?? strtotime($vinfo['last'] ?? ''));
    if ($lts > 0 && ($now_ts - $lts) <= 300) $online_now++;
}

// حساب إحصائيات السلة (كم شخص حط منتجات في السلة + إجمالي الإضافات)
$cart_log = jload('cart_log.json', []);
if (!is_array($cart_log)) $cart_log = [];
$cart_unique_users = [];
$cart_prod_counts = [];
$cart_total_qty = 0;
$cart_total_val = 0;
foreach ($cart_log as $ce) {
    $uk = $ce['vid'] ?? ($ce['user'] ?? '');
    if ($uk !== '') $cart_unique_users[$uk] = 1;
    $q_c = max(1, (int)($ce['qty'] ?? 1));
    $p_c = (float)($ce['price'] ?? 0);
    $cart_total_qty += $q_c;
    $cart_total_val += $q_c * $p_c;
    $pk = ($ce['plat'] ?? '') . '#' . ($ce['id'] ?? '');
    if ($pk !== '#') {
        if (!isset($cart_prod_counts[$pk])) {
            $cart_prod_counts[$pk] = [
                'plat' => $ce['plat'] ?? '',
                'id' => $ce['id'] ?? '',
                'pname' => $ce['pname'] ?? '',
                'img' => $ce['img'] ?? '',
                'price' => $p_c,
                'adds' => 0,
                'qty' => 0,
                'users' => []
            ];
        }
        $cart_prod_counts[$pk]['adds']++;
        $cart_prod_counts[$pk]['qty'] += $q_c;
        if ($uk !== '') $cart_prod_counts[$pk]['users'][$uk] = 1;
    }
}
$cart_people_count = count($cart_unique_users);
$cart_events_count = count($cart_log);

// حساب إحصائيات اللاڤ / المفضلة (كم شخص عمل لاف على منتج + أكثر المنتجات حصولاً على لاف)
$wish_file = DATA_DIR . '/wishlist.json';
$all_wish = file_exists($wish_file) ? json_decode(file_get_contents($wish_file), true) : [];
if (!is_array($all_wish)) $all_wish = [];
$love_log = jload('love_log.json', []);
if (!is_array($love_log)) $love_log = [];

$love_unique_users = [];
$love_prod_counts = [];
$active_wish_total = 0;
foreach ($all_wish as $wk => $witems) {
    if (!is_array($witems) || empty($witems)) continue;
    $love_unique_users[$wk] = 1;
    foreach ($witems as $wuid) {
        $active_wish_total++;
        if (!isset($love_prod_counts[$wuid])) $love_prod_counts[$wuid] = 0;
        $love_prod_counts[$wuid]++;
    }
}
foreach ($love_log as $le) {
    if (($le['action'] ?? 'love') === 'love') {
        $uk = $le['vid'] ?? ($le['user'] ?? '');
        if ($uk !== '') $love_unique_users[$uk] = 1;
        $luid = ($le['plat'] ?? '') . '#' . ($le['id'] ?? '');
        if ($luid !== '#' && !isset($love_prod_counts[$luid])) {
            $love_prod_counts[$luid] = 1;
        }
    }
}
arsort($love_prod_counts);
$love_people_count = count($love_unique_users);
$love_total_count = max($active_wish_total, count(array_filter($love_log, function ($x) { return ($x['action'] ?? 'love') === 'love'; })));

// الطلبات والمبيعات والمستخدمين
$orders = jload('orders.json', []);
if (!is_array($orders)) $orders = [];
$rev = array_sum(array_map(function ($o) { return (float)($o['total'] ?? 0); }, $orders));
$mout = array_sum(array_map(function ($o) { return (float)($o['m_earn'] ?? 0); }, $orders));
$delivered = count(array_filter($orders, function ($o) { return ($o['status'] ?? '') === 'تم التوصيل'; }));
$pending = count(array_filter($orders, function ($o) { return status_key($o['status'] ?? '') === 'pending'; }));
$all_users = users();
// حساب العميل اتلغى: كل حساب مش أدمن = مسوق
$marketers = array_filter($all_users, function ($u) { return ($u['role'] ?? '') !== 'admin'; });

// ===== بيانات أنظمة الشات وطلبات المنتجات والعمولات =====
$audit_logs = jload('audit_logs.json', []);
if (!is_array($audit_logs)) $audit_logs = [];
$support_chats = jload('support_chats.json', []);
if (!is_array($support_chats)) $support_chats = [];
$product_requests = jload('product_requests.json', []);
if (!is_array($product_requests)) $product_requests = [];
$open_prod_reqs = array_filter($product_requests, function ($r) { return in_array(($r['status'] ?? 'pending'), ['pending', 'sourcing'], true); });
$mk_clicks = jload('marketer_clicks.json', []);
if (!is_array($mk_clicks)) $mk_clicks = [];
$prod_counters = jload('product_counters.json', []);
if (!is_array($prod_counters)) $prod_counters = [];
$events_log = jload('events.json', []);
if (!is_array($events_log)) $events_log = [];

// دالة مساعدة سريعة لجلب بيانات منتج (الاسم والصورة والسعر) للعرض في الجداول
function admin_prod_info($uid) {
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $plat = ''; $id = $uid;
    if (strpos($uid, '#') !== false) list($plat, $id) = explode('#', $uid, 2);
    else $plat = resolve_plat($id);
    $p = find_product_by_id($id, $plat);
    if (!$p) {
        return $cache[$uid] = ['id' => $id, 'plat' => $plat, 'name' => $uid, 'img' => '', 'price' => 0, 'cur' => 'ج.م'];
    }
    $cname = aff_clean($p['name'] ?? '', true);
    $pr = list_price($p, $plat) ?: display_price($p, $plat);
    return $cache[$uid] = [
        'id' => $p['id'] ?? $id,
        'plat' => $plat,
        'name' => $cname !== '' ? $cname : ($p['name'] ?? $uid),
        'img' => $p['images'][0] ?? '',
        'price' => $pr,
        'cur' => $p['currency'] ?? 'ج.م'
    ];
}

layout_top('لوحة الإدارة الاحترافية ', 'admin');
?>


<?php if ($msg): ?>
<div data-ui="u-d80a5488 d14 d15 d16 d17 da d0 dc">
<?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- ===== HERO LIVE ANALYTICS SUMMARY (يظهر دائماً في أعلى لوحة الإدارة) ===== -->
<?php if ($tab === 'overview'): ?>
<div class="adm-hero">
  <div class="adm-hero-top">
    <div>
      <h2 data-ui="u-3e7462f0 d18 d1 d19"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> مركز إحصائيات الموقع المباشر</h2>
      <p data-ui="u-f1e79350 do d1a d1b">متابعة لحظية لعدد الأشخاص الذين شاهدوا الموقع، أضافوا للسلة، أو ضغطوا لاڤ  على المنتجات</p>
    </div>
    <div class="adm-live-pill">
      <span class="adm-live-dot"></span>
      <span>متواجدون الآن: <?php echo number_format($online_now); ?> زائر نشط</span>
    </div>
  </div>

  <div class="adm-main-counters">
    <a href="?tab=visitors" class="adm-big-card">
      <div class="ic" data-ui="u-6de878de d1c d1d"></div>
      <div class="num"><?php echo number_format($unique_visitors_count); ?> <small data-ui="u-45e480c7 d12 dm d1e">شخص</small></div>
      <div class="lbl">شافوا الموقع (زوار فريدين)</div>
      <div class="sub">إجمالي تصفح الصفحات: <b><?php echo number_format($total_pageviews); ?></b> • زوار اليوم: <b><?php echo number_format($today_visitors); ?></b></div>
    </a>

    <a href="?tab=cart" class="adm-big-card">
      <div class="ic" data-ui="u-3647abe1 d1f d1g"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-cart></use></svg></div>
      <div class="num"><?php echo number_format($cart_people_count); ?> <small data-ui="u-ee682f17 d12 dm d1h">شخص</small></div>
      <div class="lbl">حطوا منتجات في السلة</div>
      <div class="sub">إجمالي مرات الإضافة: <b><?php echo number_format($cart_events_count); ?></b> • القطع: <b><?php echo number_format($cart_total_qty); ?></b></div>
    </a>

    <a href="?tab=loves" class="adm-big-card">
      <div class="ic" data-ui="u-5b4158d7 d1i d1j"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-heart></use></svg></div>
      <div class="num"><?php echo number_format($love_people_count); ?> <small data-ui="u-826fad6d d12 dm d1k">شخص</small></div>
      <div class="lbl">عملوا لاڤ على منتج</div>
      <div class="sub">إجمالي اللاڤ : <b><?php echo number_format($love_total_count); ?></b> • منتجات مفضلة: <b><?php echo number_format(count($love_prod_counts)); ?></b></div>
    </a>

    <a href="?tab=orders" class="adm-big-card">
      <div class="ic" data-ui="u-b718f698 d1l d1m"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-orders></use></svg></div>
      <div class="num"><?php echo number_format(count($orders)); ?> <small data-ui="u-6e9d092b d12 dm d1n">طلب</small></div>
      <div class="lbl">إجمالي الطلبات المسجلة</div>
      <div class="sub">المبيعات: <b><?php echo number_format($rev); ?> ج.م</b> • تم التوصيل: <b><?php echo number_format($delivered); ?></b></div>
    </a>
  </div>
</div>

<?php endif; ?>
<!-- ===== NAVIGATION TABS ===== -->
<?php
// 🛍️ أفلييت أمازون: عدد الروابط والطلبات المفتوحة (تظهر كشارات في التبويب)
$amz_open_cnt = 0;
if (function_exists('amz_links_all')) {
    foreach (amz_reqs_all() as $__r) { if ((string)($__r['status'] ?? '') === 'pending' && in_array((string)($__r['type'] ?? ''), ['approval', 'pct'], true)) $amz_open_cnt++; }
}
unset($__r);

$jmi_open_cnt = 0;
if (function_exists('jmi_reqs_all')) {
    foreach (jmi_reqs_all() as $__jr) { if ((string)($__jr['status'] ?? '') === 'pending' && in_array((string)($__jr['type'] ?? ''), ['approval', 'pct'], true)) $jmi_open_cnt++; }
}
unset($__jr);

$tabs = [
    'overview'      => [' نظرة عامة', ''],
    'visitors'      => [' زوار الموقع', $unique_visitors_count],
    'cart'          => [' نشاط السلة', $cart_people_count],
    'loves'         => [' نشاط اللاڤ', $love_people_count],
    'views'         => [' الأكثر مشاهدة', count($views_map)],
    'orders'        => [' الطلبات', count($orders)],
    'products'      => [' المنتجات', ''],
    'marketers'     => [' المسوقين', count($marketers)],
    'commissions'   => [' العمولات', ''],
    'requests'      => [' طلبات المنتجات', count($open_prod_reqs)],
    'amazon'        => ['🛍️ أمازون أفلييت', $amz_open_cnt],
    'jumia'         => ['🟧 جوميا أفلييت', $jmi_open_cnt],
    'chats'         => [' الشات المباشر', count($support_chats)],
    'users'         => [' المسجلين', count($all_users)],
    'money'         => [' المالية', ''],
    'reviews'       => ['⭐ المراجعات', ''],
    'payments'      => [' مراجعة المدفوعات', count(array_filter($orders, function ($o) { return ($o['payment_status'] ?? '') === 'proof_uploaded'; }))],
    'audit'         => [' سجل النشاط', count($audit_logs)],
    'notifications' => [' الإشعارات', ''],
    'settings'      => [' الإعدادات', ''],
];
echo '<details class="admin-extra-nav"><summary>أقسام متابعة إضافية</summary><nav class="adm-tabs" aria-label="أقسام الإدارة الإضافية">';
foreach ($tabs as $k => $tinfo) {
    if (in_array($k, ['overview','orders','products','users','marketers','commissions','payments','requests','amazon','jumia','chats','notifications','audit','settings'], true)) continue;
    $on = ($tab === $k) ? ' class="on"' : '';
    $cnt_html = ($tinfo[1] !== '' && $tinfo[1] > 0) ? '<span class="adm-badge-cnt">' . number_format((int)$tinfo[1]) . '</span>' : '';
    echo '<a href="?tab=' . $k . '"' . $on . '>' . wf_svg_icon('file',16) . esc(wf_ui_plain($tinfo[0])) . $cnt_html . '</a>';
}
echo '</nav></details>';

// =========================================================================
// 1. TAB: OVERVIEW (نظرة عامة شاملة)
// =========================================================================
if ($tab === 'overview') {
    echo '<section class="card"><h2>تحتاج مراجعتك</h2><div class="action-queue"><a class="btn outline" href="?tab=orders&status=pending">' . wf_svg_icon('orders') . 'طلبات جديدة (' . (int)$pending . ')</a>' . '<a class="btn outline" href="?tab=commissions">' . wf_svg_icon('wallet') . 'مراجعة السحوبات</a><a class="btn outline" href="?tab=requests">' . wf_svg_icon('camera') . 'طلبات توفير منتج (' . count($open_prod_reqs) . ')</a></div></section>';
    // آخر الأخطاء الفادحة المسجّلة تلقائيًا (تساعد في معرفة سبب «تعذر تحميل الصفحة مؤقتًا»)
    $wf_err_rows = jload('wf_errors.json', []);
    if (is_array($wf_err_rows) && $wf_err_rows) {
        echo '<section class="card"><h2>' . wf_svg_icon('shield', 18) . ' آخر أخطاء الموقع (' . count($wf_err_rows) . ')</h2>';
        echo '<p class="mut">دي أخطاء سيرفر حقيقية اتسجلت لحظة ظهور صفحة «تعذر تحميل الصفحة مؤقتًا» — آخر ٨ أخطاء بالترتيب من الأحدث.</p>';
        echo '<div class="table-wrap"><table class="data-table rtable"><tr><th>الوقت</th><th>الصفحة</th><th>الملف</th><th>الرسالة</th></tr>';
        foreach (array_reverse(array_slice($wf_err_rows, -8)) as $er) {
            echo '<tr><td dir="ltr">' . esc(substr((string)($er['at'] ?? ''), 0, 19)) . '</td>'
               . '<td dir="ltr">' . esc(mb_substr((string)($er['uri'] ?? ''), 0, 60)) . '</td>'
               . '<td dir="ltr">' . esc((string)($er['file'] ?? '')) . '</td>'
               . '<td>' . wf_det_text($er['msg'] ?? '', 100, 'تفاصيل خطأ الموقع', 'الرسالة كاملة') . '</td></tr>';
        }
        echo '</table></div></section>';
    }
    // ===== فلتر الفترة الزمنية + رسم بياني خطي للزيارات والطلبات عبر الزمن =====
    $range = $_GET['range'] ?? '30d';
    $from_d = $_GET['from'] ?? '';
    $to_d = $_GET['to'] ?? '';
    if ($range === 'custom' && $from_d !== '' && $to_d !== '') {
        $d_start = strtotime($from_d);
        $d_end = strtotime($to_d) + 86399;
    } elseif ($range === 'today') {
        $d_start = strtotime('today'); $d_end = time();
    } elseif ($range === '7d') {
        $d_start = time() - 6 * 86400; $d_end = time();
    } else {
        $d_start = time() - 29 * 86400; $d_end = time();
    }
    $days_arr = [];
    for ($dt = $d_start; $dt <= $d_end; $dt += 86400) {
        $dk = date('Y-m-d', $dt);
        $days_arr[$dk] = [
            'uv' => isset($daily_map[$dk]['uv']) && is_array($daily_map[$dk]['uv']) ? count($daily_map[$dk]['uv']) : 0,
            'views' => (int)($daily_map[$dk]['views'] ?? 0),
            'orders' => (int)($daily_map[$dk]['orders'] ?? 0),
        ];
    }
    foreach ($orders as $oo) {
        $ok = substr($oo['at'] ?? '', 0, 10);
        if (isset($days_arr[$ok])) $days_arr[$ok]['orders']++;
    }

    $max_uv = 1; $max_or = 1;
    foreach ($days_arr as $dr) { if ($dr['uv'] > $max_uv) $max_uv = $dr['uv']; if ($dr['orders'] > $max_or) $max_or = $dr['orders']; }
    $n_days = max(1, count($days_arr));
    $chart_w = 1000; $chart_h = 220;
    $step_x = $n_days > 1 ? ($chart_w - 60) / ($n_days - 1) : 0;

    $pts_uv = []; $pts_or = [];
    $i = 0;
    foreach ($days_arr as $dk => $dr) {
        $x = 45 + $i * $step_x;
        $y1 = $chart_h - 30 - (($dr['uv'] / $max_uv) * ($chart_h - 70));
        $y2 = $chart_h - 30 - (($dr['orders'] / $max_or) * ($chart_h - 70));
        $pts_uv[] = round($x, 1) . ',' . round($y1, 1);
        $pts_or[] = round($x, 1) . ',' . round($y2, 1);
        $i++;
    }
    $area_uv = '45,' . ($chart_h - 30) . ' ' . implode(' ', $pts_uv) . ' ' . (45 + ($n_days - 1) * $step_x) . ',' . ($chart_h - 30);

    echo '<div class="adm-box" data-ui="u-25724057 d0">';
    echo '<div data-ui="u-ccc8f25c dh d1o d1p dj d1q d1r">';
    echo '<h2 data-ui="u-67647882 d19"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> الزيارات والطلبات عبر الزمن</h2>';
    echo '<div data-ui="u-75c24b28 dh di dj d1p">';
    foreach (['today' => 'اليوم', '7d' => '7 أيام', '30d' => '30 يوم', 'custom' => 'مخصص'] as $rk => $rl) {
        echo '<a class="btn sm ' . ($range === $rk ? '' : 'outline') . '" href="?tab=overview&range=' . $rk . ($rk === 'custom' ? '&from=' . urlencode($from_d ?: date('Y-m-d', strtotime('-7 days'))) . '&to=' . urlencode($to_d ?: date('Y-m-d')) : '') . '">' . $rl . '</a>';
    }
    echo '</div></div>';
    if ($range === 'custom') {
        echo '<form method="get" data-ui="u-d1ac1b08 dh d1s dj d1r d1p"><input type="hidden" name="tab" value="overview"><input type="hidden" name="range" value="custom"><input type="date" name="from" value="' . esc($from_d) . '" data-ui="u-bc65d770 d1t dt d1u d1v"><span>إلى</span><input type="date" name="to" value="' . esc($to_d) . '" data-ui="u-bc65d770 d1t dt d1u d1v"><button class="btn sm">تطبيق</button></form>';
    }
    echo '<div data-ui="u-a79055df dh d1w d11 do dc"><span data-ui="u-f1ca7b59 d1d">● الزوار الفريدين</span><span data-ui="u-61a4e8ed d1x">● الطلبات</span><span class="mut">(من ' . esc(date('Y-m-d', $d_start)) . ' إلى ' . esc(date('Y-m-d', $d_end)) . ')</span></div>';
    echo '<div data-ui="u-1ae67359 d13"><svg viewBox="0 0 ' . $chart_w . ' ' . $chart_h . '" data-ui="u-105dace9 dx d1y d1z" preserveAspectRatio="none">';
    echo '<defs><linearGradient id="gradUv" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#3b82f6" stop-opacity=".28"/><stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/></linearGradient></defs>';
    echo '<line x1="45" y1="' . ($chart_h - 30) . '" x2="' . ($chart_w - 10) . '" y2="' . ($chart_h - 30) . '" stroke="var(--slate-200)" stroke-width="1"/>';
    echo '<line x1="45" y1="20" x2="' . ($chart_w - 10) . '" y2="20" stroke="var(--mode-physical-bg)" stroke-width="1"/>';
    echo '<text x="8" y="24" fill="var(--slate-400)" font-size="12">' . esc(number_format($max_uv)) . '</text>';
    echo '<text x="8" y="' . ($chart_h - 28) . '" fill="var(--slate-400)" font-size="12">0</text>';
    echo '<polygon points="' . $area_uv . '" fill="url(#gradUv)"/>';
    echo '<polyline points="' . implode(' ', $pts_uv) . '" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linejoin="round"/>';
    echo '<polyline points="' . implode(' ', $pts_or) . '" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linejoin="round" stroke-dasharray="5 3"/>';
    if ($n_days <= 31) {
        $j = 0;
        foreach ($days_arr as $dk => $dr) {
            $x = 45 + $j * $step_x;
            echo '<text x="' . round($x, 1) . '" y="' . ($chart_h - 10) . '" fill="var(--slate-400)" font-size="12" text-anchor="middle">' . esc(substr($dk, 5)) . '</text>';
            $j++;
        }
    }
    echo '</svg></div></div>';

    $base_f = max(1, $unique_visitors_count, $total_prod_views);
    $f_rows = [
        [' أشخاص زاروا الموقع', $unique_visitors_count, 'var(--info)', 100],
        [' مشاهدات صفحات المنتجات', $total_prod_views, 'var(--mode-digital)', min(100, round($total_prod_views / $base_f * 100))],
        [' أشخاص عملوا لاڤ لمنتج', $love_people_count, 'var(--danger-text)', min(100, round($love_people_count / max(1, $unique_visitors_count) * 100))],
        [' أشخاص أضافوا منتجات للسلة', $cart_people_count, 'var(--warning-text)', min(100, round($cart_people_count / max(1, $unique_visitors_count) * 100))],
        [' طلبات شراء مكتملة', count($orders), 'var(--success)', min(100, round(count($orders) / max(1, $unique_visitors_count) * 100))],
    ];

    // ===== متواجدون الآن + تسوية مالية =====
    $online_now = wf_online_now(5);
    $ws = wf_stats(30);
    $rec = ['pending' => 0, 'available' => 0, 'paid' => 0, 'requested' => 0, 'rejected' => 0];
    foreach (users() as $uu) { if (($uu['role'] ?? '') === 'admin') continue; $rec['pending'] += (float)($uu['pending_balance'] ?? 0); $rec['available'] += (float)($uu['balance'] ?? 0); }
    foreach (jload('payouts.json', []) as $pp) { $st = $pp['status'] ?? ''; $a = (float)($pp['amount'] ?? 0); if (in_array($st, ['موافق عليه', 'approved', 'paid'], true)) $rec['paid'] += $a; elseif ($st === 'مرفوض') $rec['rejected'] += $a; else $rec['requested'] += $a; }
    echo '<div class="adm-grid-2" data-ui="u-f2235598 d1r">';
    echo '<div class="adm-box"><h2><span> متواجدون الآن</span><span class="badge green">آخر 5 دقائق</span></h2><div data-ui="u-1f01646e d20 d1 d1x">' . $online_now . '</div>';
    echo '<div data-ui="u-1cfee9c4 d21 d22 d1s d23 do">';
    foreach ([['زوار فريدون /30ي', $ws['totals']['uv']], ['مشاهدات منتجات', $ws['totals']['product_views']], ['إضافات سلة', $ws['totals']['carts']], ['لايكات', $ws['totals']['loves']], ['طلبات', $ws['totals']['orders']], ['إيراد ج.م', number_format($ws['totals']['revenue'])]] as $kv) echo '<div data-ui="u-01ef0727 ds d24 d25 df"><b data-ui="u-d0c21d4e d26 d27">' . $kv[1] . '</b><span class="mut">' . $kv[0] . '</span></div>';
    echo '</div></div>';
    echo '<div class="adm-box"><h2><span> التسوية المالية للمسوقين</span><a href="?tab=commissions" data-ui="u-4f5b7b31 do d28">التفاصيل ←</a></h2><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d7ebccb du">';
    foreach ([['⏳ عمولات معلقة (لم تُسلَّم بعد)', $rec['pending'], 'var(--warning-text)'], [' رصيد متاح للسحب (التزام علينا)', $rec['available'], 'var(--success)'], [' طلبات سحب قيد المراجعة', $rec['requested'], 'var(--blue-600)'], [' تم دفعه فعلاً', $rec['paid'], 'var(--slate-500)'], [' سحوبات مرفوضة', $rec['rejected'], 'var(--danger-text)']] as $r) echo '<tr><td>' . $r[0] . '</td><td style="text-align:left;font-weight:900;color:' . $r[2] . '" dir="ltr">' . number_format($r[1], 2) . '</td></tr>';
    echo '<tr data-ui="u-350db0a6 d29"><td><b>إجمالي الالتزامات الحالية</b></td><td data-ui="u-db8f6737 d2a d1" dir="ltr">' . number_format($rec['pending'] + $rec['available'] + $rec['requested'], 2) . '</td></tr></table></div>';
    echo '</div>';

    echo '<div class="adm-grid-2">';
    // قمع التحويل
    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> قمع التحويل وتفاعل الزوار</span><span class="badge blue">مباشر</span></h2>';
    foreach ($f_rows as $fr) {
        echo '<div class="adm-funnel-row">';
        echo '<div class="adm-funnel-head"><span>' . $fr[0] . '</span><span style="color:' . $fr[2] . '">' . number_format($fr[1]) . ' (' . $fr[3] . '%)</span></div>';
        echo '<div class="adm-funnel-bar"><div class="adm-funnel-fill" style="width:' . max(4, $fr[3]) . '%;background:' . $fr[2] . '"></div></div>';
        echo '</div>';
    }
    echo '</div>';

    // مصادر الزيارات والأجهزة
    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-globe></use></svg> مصادر الزيارات والأجهزة</span><a href="?tab=visitors" data-ui="u-4f5b7b31 do d28">التفاصيل ←</a></h2>';
    echo '<div data-ui="u-5f49f455 d21 d2b d2c">';
    echo '<div><div data-ui="u-defaf4f2 do dc dn d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-link></use></svg> مصادر الدخول</div>';
    if ($sources_map) {
        arsort($sources_map);
        foreach ($sources_map as $sk => $sv) {
            echo '<div data-ui="u-f4d66a5c dh d1o d2e d2f du"><span>' . esc($sk) . '</span><b>' . number_format($sv) . '</b></div>';
        }
    } else {
        echo '<p class="mut" data-ui="u-5d985ec3 do">يتم التجميع مع دخول الزوار...</p>';
    }
    echo '</div>';
    echo '<div><div data-ui="u-defaf4f2 do dc dn d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-phone></use></svg> نوع الجهاز</div>';
    if ($devices_map) {
        arsort($devices_map);
        foreach ($devices_map as $dk => $dv) {
            echo '<div data-ui="u-f4d66a5c dh d1o d2e d2f du"><span>' . esc($dk) . '</span><b>' . number_format($dv) . '</b></div>';
        }
    } else {
        echo '<p class="mut" data-ui="u-5d985ec3 do">يتم التجميع مع دخول الزوار...</p>';
    }
    echo '<div data-ui="u-4af16ba5 do dc dn d2g"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> أكثر الصفحات تصفحاً</div>';
    if ($pages_map) {
        arsort($pages_map);
        foreach (array_slice($pages_map, 0, 4, true) as $pk => $pv) {
            echo '<div data-ui="u-652fcd13 dh d1o d2h do"><span>' . esc($pk) . '</span><b>' . number_format($pv) . '</b></div>';
        }
    }
    echo '</div></div></div>';
    echo '</div>';

    // 3 أعمدة: أكثر المنتجات سلة / لاڤ / مشاهدة
    echo '<div class="adm-grid-3">';

    // أكثر المنتجات إضافة للسلة
    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-cart></use></svg> الأكثر إضافة للسلة</span><a href="?tab=cart" data-ui="u-4f5b7b31 do d28">الكل ←</a></h2>';
    if ($cart_prod_counts) {
        uasort($cart_prod_counts, function ($a, $b) { return $b['adds'] <=> $a['adds']; });
        foreach (array_slice($cart_prod_counts, 0, 5, true) as $cpk => $cpd) {
            $pinfo = admin_prod_info($cpk);
            $img_u = $cpd['img'] ?: $pinfo['img'];
            echo '<div class="adm-pitem">';
            if ($img_u) echo '<img alt="صورة المنتج" src="' . esc(asset_url($img_u)) . '" onerror="this.style.display=\'none\'">';
            echo '<div data-ui="u-d2b411f5 d2i d2j"><a href="product.php?id=' . urlencode($pinfo['id']) . '" target="_blank" data-ui="u-6dd7ac5c du dc d2k d2l d27 d2m d2n d2o">' . esc($cpd['pname'] ?: $pinfo['name']) . '</a>';
            echo '<div data-ui="u-7acc46dc do dn">' . count($cpd['users']) . ' شخص • إجمالي ' . $cpd['qty'] . ' قطعة</div></div>';
            echo '<span class="badge amber">' . $cpd['adds'] . ' مرة</span>';
            echo '</div>';
        }
    } else {
        echo '<p class="mut" data-ui="u-5d7ebccb du">لا توجد إضافات للسلة مسجلة بعد.</p>';
    }
    echo '</div>';

    // أكثر المنتجات حصولاً على لاڤ
    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-heart></use></svg> الأكثر حصولاً على لاڤ</span><a href="?tab=loves" data-ui="u-4f5b7b31 do d28">الكل ←</a></h2>';
    if ($love_prod_counts) {
        foreach (array_slice($love_prod_counts, 0, 5, true) as $luid => $lcnt) {
            $pinfo = admin_prod_info($luid);
            echo '<div class="adm-pitem">';
            if ($pinfo['img']) echo '<img alt="صورة المنتج" src="' . esc(asset_url($pinfo['img'])) . '" onerror="this.style.display=\'none\'">';
            echo '<div data-ui="u-d2b411f5 d2i d2j"><a href="product.php?id=' . urlencode($pinfo['id']) . '" target="_blank" data-ui="u-6dd7ac5c du dc d2k d2l d27 d2m d2n d2o">' . esc($pinfo['name']) . '</a>';
            echo '<div data-ui="u-7acc46dc do dn">' . number_format($pinfo['price']) . ' ' . esc($pinfo['cur']) . '</div></div>';
            echo '<span class="badge neutral"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-heart></use></svg> ' . number_format($lcnt) . '</span>';
            echo '</div>';
        }
    } else {
        echo '<p class="mut" data-ui="u-5d7ebccb du">لا توجد منتجات في المفضلة بعد.</p>';
    }
    echo '</div>';

    // أكثر المنتجات مشاهدة
    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> الأكثر مشاهدة</span><a href="?tab=views" data-ui="u-4f5b7b31 do d28">الكل ←</a></h2>';
    if ($views_map) {
        arsort($views_map);
        foreach (array_slice($views_map, 0, 5, true) as $vuid => $vcnt) {
            $pinfo = admin_prod_info($vuid);
            echo '<div class="adm-pitem">';
            if ($pinfo['img']) echo '<img alt="صورة المنتج" src="' . esc(asset_url($pinfo['img'])) . '" onerror="this.style.display=\'none\'">';
            echo '<div data-ui="u-d2b411f5 d2i d2j"><a href="product.php?id=' . urlencode($pinfo['id']) . '" target="_blank" data-ui="u-6dd7ac5c du dc d2k d2l d27 d2m d2n d2o">' . esc($pinfo['name']) . '</a>';
            echo '<div data-ui="u-7acc46dc do dn">' . number_format($pinfo['price']) . ' ' . esc($pinfo['cur']) . '</div></div>';
            echo '<span class="badge blue"> ' . number_format($vcnt) . '</span>';
            echo '</div>';
        }
    } else {
        echo '<p class="mut" data-ui="u-5d7ebccb du">لا توجد مشاهدات مسجلة بعد.</p>';
    }
    echo '</div>';
    echo '</div>';

    // آخر 10 طلبات
    $last10 = array_reverse(array_slice($orders, -10));
    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-orders></use></svg> أحدث الطلبات</span><a href="?tab=orders" data-ui="u-4f5b7b31 do d28">كل الطلبات ←</a></h2>';
    if ($last10) {
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>التاريخ</th><th>المنتج</th><th>العميل</th><th>الهاتف</th><th>المحافظة</th><th>الإجمالي</th><th>الحالة</th></tr>';
        foreach ($last10 as $o) {
            echo '<tr><td>' . esc(substr($o['at'] ?? '', 0, 16)) . '</td><td><b>' . esc(mb_substr(aff_clean($o['pname'] ?? '', true), 0, 35)) . '</b></td><td>' . esc($o['cname'] ?? '') . '</td><td dir="ltr">' . esc($o['cphone'] ?? '') . '</td><td>' . esc($o['city'] ?? '') . '</td><td><b data-ui="u-9aa26154 d2p">' . number_format($o['total'] ?? 0) . ' ج.م</b></td><td><span class="badge green">' . esc(status_label($o['status'] ?? '')) . '</span> ' . wf_details_btn('كل تفاصيل الطلب ' . ($o['order_no'] ?? ''), wf_order_details_rows($o, true), 'تفاصيل إضافية') . '</td></tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="mut">لا توجد طلبات مسجلة حتى الآن.</p>';
    }
    echo '</div>';
}

// =========================================================================
// 2. TAB: VISITORS (كم شخص شاف الموقع + سجل الزوار المباشر)
// =========================================================================
elseif ($tab === 'visitors') {
    echo '<div class="kpis" data-ui="u-25724057 d0">';
    echo '<div class="kpi" data-ui="u-bc691366 d6"><b>' . number_format($unique_visitors_count) . '</b><span>إجمالي الأشخاص الذين زاروا الموقع</span></div>';
    echo '<div class="kpi" data-ui="u-3824e45f d4"><b>' . number_format($online_now) . '</b><span>متواجدون الآن في الموقع </span></div>';
    echo '<div class="kpi" data-ui="u-85fe13fb d2q"><b>' . number_format($today_visitors) . '</b><span>زوار اليوم (' . esc($today_str) . ')</span></div>';
    echo '<div class="kpi" data-ui="u-97b96627 d5"><b>' . number_format($total_pageviews) . '</b><span>إجمالي تصفح الصفحات</span></div>';
    echo '</div>';

    // جدول إحصائيات الأيام
    if ($daily_map) {
        krsort($daily_map);
        echo '<div class="adm-box" data-ui="u-25724057 d0"><h2> إحصائيات الزيارات اليومية</h2>';
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>التاريخ</th><th>أشخاص فريدين (زوار)</th><th>تصفح صفحات</th><th>إضافات للسلة </th><th>لاڤ على منتجات </th></tr>';
        foreach (array_slice($daily_map, 0, 14, true) as $day => $drow) {
            $uv_c = isset($drow['uv']) && is_array($drow['uv']) ? count($drow['uv']) : 0;
            echo '<tr><td><b>' . esc($day) . '</b></td><td><b data-ui="u-f1ca7b59 d1d">' . number_format($uv_c) . ' شخص</b></td><td>' . number_format((int)($drow['views'] ?? 0)) . '</td><td>' . number_format((int)($drow['carts'] ?? 0)) . '</td><td>' . number_format((int)($drow['loves'] ?? 0)) . '</td></tr>';
        }
        echo '</table></div>';
    }

    // سجل الزوار التفصيلي
    $v_list = [];
    foreach ($visitors_map as $vid => $vd) {
        $vd['vid'] = $vid;
        $v_list[] = $vd;
    }
    usort($v_list, function ($a, $b) { return (int)($b['last_ts'] ?? 0) <=> (int)($a['last_ts'] ?? 0); });

    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-users></use></svg> سجل الأشخاص الذين دخلوا الموقع (' . number_format(count($v_list)) . ' شخص)</span></h2>';
    echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>الحالة</th><th>الزائر / الحساب</th><th>الجهاز والمتصفح</th><th>مصدر الدخول</th><th>عدد الصفحات</th><th>آخر صفحة شوهدت</th><th>آخر تواجد</th></tr>';
    foreach (array_slice($v_list, 0, 150) as $v) {
        $is_on = (($now_ts - (int)($v['last_ts'] ?? 0)) <= 300);
        $who = !empty($v['name']) ? ('<b>' . esc($v['name']) . '</b><br><span class="mut">' . esc($v['user']) . '</span>') : (' زائر <span dir="ltr" class="mut">(' . esc(substr($v['vid'], 0, 10)) . ')</span>');
        echo '<tr>';
        echo '<td>' . ($is_on ? '<span class="badge green"> متصل الآن</span>' : '<span class="badge">غادر</span>') . '</td>';
        echo '<td>' . $who . '</td>';
        echo '<td>' . esc($v['device'] ?? '') . ' • <span class="mut">' . esc($v['browser'] ?? '') . '</span></td>';
        echo '<td><span class="badge blue">' . esc($v['source'] ?? 'مباشر') . '</span></td>';
        echo '<td><b>' . number_format((int)($v['hits'] ?? 1)) . '</b> صفحة</td>';
        echo '<td>' . esc($v['last_page'] ?? '') . '<br><span dir="ltr" class="mut" data-ui="u-5d985ec3 do">' . esc($v['last_uri'] ?? '') . '</span></td>';
        echo '<td dir="ltr">' . esc(substr($v['last'] ?? '', 0, 16)) . '</td>';
        echo '</tr>';
    }
    echo '</table></div></div>';
}

// =========================================================================
// 3. TAB: CART (كم شخص حط منتجات في السلة + أكثر المنتجات سلة + السجل)
// =========================================================================
elseif ($tab === 'cart') {
    echo '<div class="kpis" data-ui="u-25724057 d0">';
    echo '<div class="kpi" data-ui="u-97b96627 d5"><b>' . number_format($cart_people_count) . ' شخص</b><span>أشخاص وضعوا منتجات في السلة</span></div>';
    echo '<div class="kpi" data-ui="u-bc691366 d6"><b>' . number_format($cart_events_count) . ' مرة</b><span>إجمالي عمليات الإضافة للسلة</span></div>';
    echo '<div class="kpi" data-ui="u-3824e45f d4"><b>' . number_format($cart_total_qty) . ' قطعة</b><span>إجمالي القطع المضافة للسلة</span></div>';
    echo '<div class="kpi" data-ui="u-85fe13fb d2q"><b>' . number_format($cart_total_val) . ' ج.م</b><span>إجمالي قيمة المنتجات المضافة</span></div>';
    echo '</div>';

    if ($cart_prod_counts) {
        uasort($cart_prod_counts, function ($a, $b) { return $b['adds'] <=> $a['adds']; });
        echo '<div class="adm-box" data-ui="u-25724057 d0"><h2> أكثر المنتجات التي وُضعت في السلة (' . count($cart_prod_counts) . ' منتج)</h2>';
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>الصورة</th><th>المنتج</th><th>السعر</th><th>كم شخص أضافه للسلة</th><th>عدد مرات الإضافة</th><th>إجمالي الكمية</th></tr>';
        foreach (array_slice($cart_prod_counts, 0, 50, true) as $cpk => $cpd) {
            $pinfo = admin_prod_info($cpk);
            $img_u = $cpd['img'] ?: $pinfo['img'];
            echo '<tr>';
            echo '<td>' . ($img_u ? '<img alt="صورة المنتج" src="' . esc(asset_url($img_u)) . '" data-ui="u-93daa356 d2r d2s d1u d2t">' : '') . '</td>';
            echo '<td><a href="product.php?id=' . urlencode($pinfo['id']) . '" target="_blank" data-ui="u-f14488a7 dc d2k d2l">' . esc($cpd['pname'] ?: $pinfo['name']) . '</a></td>';
            echo '<td><b>' . number_format($cpd['price'] ?: $pinfo['price']) . ' ج.م</b></td>';
            echo '<td><span class="badge blue"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-user></use></svg> ' . count($cpd['users']) . ' شخص</span></td>';
            echo '<td><span class="badge amber"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-cart></use></svg> ' . $cpd['adds'] . ' مرة</span></td>';
            echo '<td><b>' . $cpd['qty'] . ' قطعة</b></td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }

    echo '<div class="adm-box"><h2><span> السجل التفصيلي لإضافات السلة (' . number_format($cart_events_count) . ')</span>';
    if ($cart_events_count > 0) {
        echo '<form method="post" data-confirm="مسح سجل السلة؟" data-ui="u-67647882 d19"><input type="hidden" name="clear_log" value="cart"><button class="btn sm outline" data-ui="u-9aa26154 d2p"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-trash></use></svg> مسح السجل</button></form>';
    }
    echo '</h2>';
    if ($cart_log) {
        echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>التاريخ والوقت</th><th>الشخص / الزائر</th><th>المنتج</th><th>المقاس / اللون</th><th>الكمية</th><th>السعر</th><th>الجهاز</th></tr>';
        foreach (array_reverse(array_slice($cart_log, -200)) as $e) {
            $pinfo = admin_prod_info(($e['plat'] ?? '') . '#' . ($e['id'] ?? ''));
            $img_u = !empty($e['img']) ? $e['img'] : $pinfo['img'];
            echo '<tr>';
            echo '<td dir="ltr">' . esc(substr($e['at'] ?? '', 0, 16)) . '</td>';
            echo '<td><b>' . esc($e['name'] ?? 'زائر') . '</b><br><span dir="ltr" class="mut">' . esc($e['user'] ?? '') . '</span></td>';
            echo '<td><div data-ui="u-5fddd367 dh d1p d1s">' . ($img_u ? '<img alt="صورة المنتج" src="' . esc(asset_url($img_u)) . '" data-ui="u-d70dc584 d2u d2v d2w d2t">' : '') . '<a href="product.php?id=' . urlencode($e['id'] ?? '') . '" target="_blank" data-ui="u-f14488a7 dc d2k d2l">' . esc(mb_substr($e['pname'] ?: $pinfo['name'], 0, 45)) . '</a></div></td>';
            echo '<td>' . esc(($e['variant'] ?? '') !== '' ? $e['variant'] : '—') . '</td>';
            echo '<td><b>' . (int)($e['qty'] ?? 1) . '</b></td>';
            echo '<td><b>' . number_format((float)($e['price'] ?? $pinfo['price'])) . ' ج.م</b></td>';
            echo '<td>' . esc($e['device'] ?? '—') . '</td>';
            echo '</tr>';
        }
        echo '</table></div>';
    } else {
        echo '<p class="mut">لم يقم أحد بإضافة منتجات للسلة بعد.</p>';
    }
    echo '</div>';
}

// =========================================================================
// 4. TAB: LOVES / WISHLIST (كم شخص عمل لاف على منتج + أكثر المنتجات لاڤ)
// =========================================================================
elseif ($tab === 'loves') {
    echo '<div class="kpis" data-ui="u-25724057 d0">';
    echo '<div class="kpi" data-ui="u-970e92a7 d2x"><b>' . number_format($love_people_count) . ' شخص</b><span>أشخاص عملوا لاڤ  على منتجات</span></div>';
    echo '<div class="kpi" data-ui="u-544b3082 d2y"><b>' . number_format($love_total_count) . ' لاڤ </b><span>إجمالي اللاڤ على المنتجات</span></div>';
    echo '<div class="kpi" data-ui="u-85fe13fb d2q"><b>' . number_format(count($love_prod_counts)) . ' منتج</b><span>عدد المنتجات التي حصلت على لاڤ</span></div>';
    echo '</div>';

    if ($love_prod_counts) {
        echo '<div class="adm-box" data-ui="u-25724057 d0"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-heart></use></svg> أكثر المنتجات حصولاً على لاڤ من الزوار (' . count($love_prod_counts) . ' منتج)</h2>';
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>الصورة</th><th>المنتج</th><th>السعر</th><th>عدد الأشخاص المعجبين </th><th>رابط المنتج</th></tr>';
        foreach (array_slice($love_prod_counts, 0, 60, true) as $luid => $lcnt) {
            $pinfo = admin_prod_info($luid);
            echo '<tr>';
            echo '<td>' . ($pinfo['img'] ? '<img alt="صورة المنتج" src="' . esc(asset_url($pinfo['img'])) . '" data-ui="u-c963e7fb d2z d30 d1u d2t">' : '') . '</td>';
            echo '<td><b>' . esc($pinfo['name']) . '</b></td>';
            echo '<td><b data-ui="u-9aa26154 d2p">' . number_format($pinfo['price']) . ' ' . esc($pinfo['cur']) . '</b></td>';
            echo '<td><span class="badge red" data-ui="u-5d7ebccb du"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-heart></use></svg> ' . number_format($lcnt) . ' شخص</span></td>';
            echo '<td><a href="product.php?id=' . urlencode($pinfo['id']) . '" target="_blank" class="btn sm outline">عرض المنتج ↗</a></td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }

    // سجل حركات اللاڤ اللحظي
    if ($love_log) {
        echo '<div class="adm-box" data-ui="u-25724057 d0"><h2><span> سجل اللاڤ اللحظي (' . count($love_log) . ')</span>';
        echo '<form method="post" data-confirm="مسح سجل اللاڤ؟" data-ui="u-67647882 d19"><input type="hidden" name="clear_log" value="love"><button class="btn sm outline" data-ui="u-9aa26154 d2p"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-trash></use></svg> مسح السجل</button></form></h2>';
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>التاريخ</th><th>الشخص / الزائر</th><th>المنتج</th><th>السعر</th><th>الحركة</th><th>الجهاز</th></tr>';
        foreach (array_reverse(array_slice($love_log, -150)) as $le) {
            $is_lv = ($le['action'] ?? 'love') === 'love';
            echo '<tr>';
            echo '<td dir="ltr">' . esc(substr($le['at'] ?? '', 0, 16)) . '</td>';
            echo '<td><b>' . esc($le['name'] ?? 'زائر') . '</b><br><span dir="ltr" class="mut">' . esc($le['user'] ?? '') . '</span></td>';
            echo '<td><a href="product.php?id=' . urlencode($le['id'] ?? '') . '" target="_blank" data-ui="u-f14488a7 dc d2k d2l">' . esc($le['pname'] ?? '') . '</a></td>';
            echo '<td><b>' . number_format((float)($le['price'] ?? 0)) . ' ج.م</b></td>';
            echo '<td>' . ($is_lv ? '<span class="badge neutral"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-heart></use></svg> عمل لاڤ</span>' : '<span class="badge">إزالة</span>') . '</td>';
            echo '<td>' . esc($le['device'] ?? '—') . '</td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }

    // قوائم المفضلة الحالية لكل شخص
    echo '<div class="adm-box"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-copy></use></svg> قوائم المفضلة الحالية حسب الزائر/المستخدم (' . count($all_wish) . ' شخص)</h2>';
    if ($all_wish) {
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>الشخص / الزائر</th><th>المنتجات التي عمل لها لاڤ </th><th>العدد</th></tr>';
        foreach ($all_wish as $wk => $witems) {
            if (!is_array($witems) || empty($witems)) continue;
            $disp = (strpos($wk, 'guest_') === 0) ? (' زائر (' . substr($wk, 6, 10) . ')') : (' ' . $wk);
            $p_links = [];
            foreach (array_slice($witems, 0, 6) as $wuid) {
                $pi = admin_prod_info($wuid);
                $p_links[] = '<a href="product.php?id=' . urlencode($pi['id']) . '" target="_blank" data-ui="u-9b1180ec d31 d32 d33 d34 d35 d36 do dm d2l d37"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-heart></use></svg> ' . esc(mb_substr($pi['name'], 0, 32)) . '</a>';
            }
            echo '<tr><td><b>' . esc($disp) . '</b></td><td>' . implode(' ', $p_links) . (count($witems) > 6 ? ' <span class="mut">+' . (count($witems) - 6) . '</span>' : '') . '</td><td><b>' . count($witems) . '</b></td></tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="mut">لا توجد قوائم مفضلة حالياً.</p>';
    }
    echo '</div>';
}

// =========================================================================
// 5. TAB: VIEWS (الأكثر مشاهدة)
// =========================================================================
elseif ($tab === 'views') {
    arsort($views_map);
    echo '<div class="adm-box"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> أكثر المنتجات مشاهدة من الزوار (إجمالي ' . number_format($total_prod_views) . ' مشاهدة على ' . number_format(count($views_map)) . ' منتج)</h2>';
    echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>#</th><th>الصورة</th><th>المنتج</th><th>السعر</th><th>عدد المشاهدات </th><th>لاڤ </th><th>رابط</th></tr>';
    $rank = 0;
    foreach (array_slice($views_map, 0, 100, true) as $vuid => $vcnt) {
        $rank++;
        $pinfo = admin_prod_info($vuid);
        $lvc = $love_prod_counts[$vuid] ?? 0;
        echo '<tr>';
        echo '<td><b>' . $rank . '</b></td>';
        echo '<td>' . ($pinfo['img'] ? '<img alt="صورة المنتج" src="' . esc(asset_url($pinfo['img'])) . '" data-ui="u-93daa356 d2r d2s d1u d2t">' : '') . '</td>';
        echo '<td><b>' . esc($pinfo['name']) . '</b></td>';
        echo '<td><b data-ui="u-9aa26154 d2p">' . number_format($pinfo['price']) . ' ' . esc($pinfo['cur']) . '</b></td>';
        echo '<td><span class="badge blue" data-ui="u-5d7ebccb du"> ' . number_format($vcnt) . ' مشاهدة</span></td>';
        echo '<td>' . ($lvc > 0 ? '<span class="badge neutral"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-heart></use></svg> ' . $lvc . '</span>' : '—') . '</td>';
        echo '<td><a href="product.php?id=' . urlencode($pinfo['id']) . '" target="_blank" class="btn sm outline">فتح ↗</a></td>';
        echo '</tr>';
    }
    echo '</table></div>';
}

// =========================================================================
// 6. TAB: ORDERS (إدارة الطلبات الاحترافية)
// =========================================================================
elseif ($tab === 'orders') {
    if (isset($_GET['export'])) {
        // «رقم الطلب» و«ملاحظات» اتضافوا: التصدير بيُستخدم لتسجيل الطلبات عند المورد/الشحن
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders_' . date('Y-m-d_His') . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'رقم الطلب', 'التاريخ', 'المنتج', 'اللون والمقاس', 'العميل', 'الهاتف',
            'المحافظة', 'العنوان', 'الكمية', 'سعر القطعة', 'الشحن', 'الخصم', 'الإجمالي',
            'العملة', 'عمولة المسوق', 'المسوق', 'الحالة', 'ملاحظات',
        ]);
        foreach ($orders as $o) {
            $notes = [];
            if (!empty($o['cancel_reason']))    $notes[] = 'سبب الإلغاء: ' . $o['cancel_reason'];
            if (!empty($o['return_reason']))    $notes[] = 'سبب الاسترجاع: ' . $o['return_reason'];
            if (!empty($o['return_requested'])) $notes[] = 'استرجاع قيد المراجعة';
            if (!empty($o['tracking']))         $notes[] = 'تتبع الشحن: ' . $o['tracking'];
            fputcsv($out, [
                (string)($o['order_no'] ?? ''),
                substr((string)($o['at'] ?? ''), 0, 16),
                (string)($o['pname'] ?? ''),
                function_exists('order_variant_label') ? order_variant_label($o) : '',
                (string)($o['cname'] ?? ''),
                (string)($o['cphone'] ?? ''),
                (string)($o['city'] ?? ''),
                (string)($o['address'] ?? ''),
                (int)($o['qty'] ?? 1),
                number_format((float)($o['unit'] ?? 0), 2, '.', ''),
                number_format((float)($o['ship'] ?? 0), 2, '.', ''),
                number_format((float)($o['discount'] ?? 0), 2, '.', ''),
                number_format((float)($o['total'] ?? 0), 2, '.', ''),
                function_exists('wf_ui_currency') ? (string)wf_ui_currency($o) : 'ج.م',
                number_format((float)($o['m_earn'] ?? 0), 2, '.', ''),
                (string)($o['ref'] ?? ''),
                (string)status_label($o['status'] ?? ''),
                implode(' | ', $notes),
            ]);
        }
        fclose($out);
        exit;
    }
    $statuses = array_keys(order_status_list());
    $filter_s = $_GET['status'] ?? '';
    $sq = trim($_GET['q'] ?? '');
    $rev_orders = array_reverse($orders, true);
    if ($filter_s !== '') {
        $rev_orders = array_filter($rev_orders, function ($o) use ($filter_s) { return status_key($o['status'] ?? '') === status_key($filter_s); });
    }
    if ($sq !== '') {
        $rev_orders = array_filter($rev_orders, function ($o) use ($sq) {
            $blob = ($o['cname'] ?? '') . ' ' . ($o['cphone'] ?? '') . ' ' . ($o['pname'] ?? '') . ' ' . ($o['city'] ?? '');
            return mb_stripos($blob, $sq) !== false;
        });
    }

    echo '<div class="adm-box">';
    echo '<div data-ui="u-ccc8f25c dh d1o d1p dj d1q d1r">';
    echo '<h2 data-ui="u-67647882 d19"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-orders></use></svg> إدارة الطلبات (' . count($rev_orders) . ')</h2>';
    echo '<div data-ui="u-c98a6604 dh d1s dj">';
    echo '<form method="get" data-ui="u-a8c7802a dh di"><input type="hidden" name="tab" value="orders"><input name="q" value="' . esc($sq) . '" placeholder="بحث باسم العميل أو الموبايل..." data-ui="u-22fbc61c d38 dt d1u d1v"><button class="btn sm">بحث</button></form>';
    echo '<a class="btn sm outline" href="?tab=orders&export=1"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-download></use></svg> تصدير Excel/CSV</a>';
    echo '</div></div>';

    echo '<div data-ui="u-2f8e8373 dh di dj d1r">';
    echo '<a class="btn sm ' . ($filter_s === '' ? '' : 'outline') . '" href="?tab=orders">الكل (' . count($orders) . ')</a>';
    foreach ($statuses as $s) {
        $scnt = count(array_filter($orders, function ($o) use ($s) { return ($o['status'] ?? '') === $s; }));
        echo '<a class="btn sm ' . ($filter_s === $s ? '' : 'outline') . '" href="?tab=orders&status=' . urlencode($s) . '">' . esc(wf_ui_plain(status_label($s))) . ' (' . $scnt . ')</a>';
    }
    echo '</div>';

    // ===== تعديل كامل لطلب محدد (?tab=orders&edit=ORD-...) — كل التفاصيل بما فيها العمولة =====
    $adm_edit_no = trim((string)($_GET['edit'] ?? ''));
    $adm_edit_o = null;
    if ($adm_edit_no !== '') { foreach ($orders as $oe) if (($oe['order_no'] ?? '') === $adm_edit_no) { $adm_edit_o = $oe; break; } }
    if ($adm_edit_o) {
        $ae_plat = (string)($adm_edit_o['plat'] ?? '');
        $ae_cur  = wf_ui_currency($adm_edit_o);
        $ae_dig  = wf_is_digital_plat($ae_plat);
        $ae_ship_cur = function_exists('wf_ship_currency') ? wf_ship_currency($ae_plat, (string)($adm_edit_o['ship_country'] ?? '')) : 'ج.م';
        $ae_mk = ((string)($adm_edit_o['ref'] ?? '') !== '') ? find_marketer_by_code((string)$adm_edit_o['ref']) : null;
        $ae_in = function ($k, $d = '') use ($adm_edit_o) { return esc((string)($adm_edit_o[$k] ?? $d)); };
        echo '<div class="card" style="margin-bottom:14px"><h2>✏️ تعديل كامل للطلب <span dir="ltr">' . $ae_in('order_no') . '</span></h2>'
           . '<p class="mut">كل حاجة قابلة للتعديل: بيانات العميل والمنتج والكمية وسعر القطعة والشحن والعمولة الإضافية وعمولة المسوق والحالة وكود المسوق. الإجمالي بيتحسب من السيرفر (سعر القطعة × الكمية + الشحن − خصم الكمية)، وفرق العمولة بيتسوّى تلقائيًا في محفظة المسوق (وعمولة فريقه). العمولات دائمًا بالجنيه المصري.</p>'
           . '<form method="post" class="form-grid">' . csrf_field()
           . '<input type="hidden" name="adm_order_action" value="edit"><input type="hidden" name="adm_order_no" value="' . $ae_in('order_no') . '">'
           . '<div><label class="field-label">حالة الطلب</label><select name="status">';
        foreach (array_keys(order_status_list()) as $ae_sk) {
            echo '<option value="' . esc($ae_sk) . '"' . (status_key($adm_edit_o['status'] ?? '') === $ae_sk ? ' selected' : '') . '>' . esc(status_label($ae_sk)) . '</option>';
        }
        echo '</select></div>'
           . '<div><label class="field-label">سبب الإلغاء / الإرجاع / عدم الرد (يظهر للمسوق)</label><input name="status_reason" maxlength="300" placeholder="يُسجل مع الحالة لو ملغي/مرتجع/لم يتم الرد" value="' . esc((string)($adm_edit_o['cancel_reason'] ?? $adm_edit_o['return_reason'] ?? '')) . '"></div>'
           . '<div><label class="field-label">كود المسوق (تحويل العمولة)</label><input name="ref" dir="ltr" maxlength="40" value="' . $ae_in('ref') . '">'
           . '<span class="mut" style="font-size:12px">المسوق الحالي: ' . esc($ae_mk ? (string)($ae_mk['name'] ?? '') : '—') . ' — تغيير الكود بينقل العمولة كاملة (بفريقها) من القديم للجديد.</span></div>'
           . '<div><label class="field-label">الحملة / المصدر (sub)</label><input name="sub" maxlength="60" value="' . $ae_in('sub') . '"></div>'
           . '<div><label class="field-label">اسم العميل</label><input name="cname" required maxlength="100" value="' . $ae_in('cname') . '"></div>'
           . '<div><label class="field-label">رقم الموبايل</label><input name="cphone" required inputmode="tel" value="' . $ae_in('cphone') . '"></div>'
           . '<div><label class="field-label">رقم بديل / واتساب</label><input name="cphone2" inputmode="tel" value="' . $ae_in('cphone2') . '"></div>'
           . '<div style="grid-column:1/-1"><label class="field-label">اسم المنتج</label><input name="pname" maxlength="120" value="' . $ae_in('pname') . '"></div>'
           . '<div><label class="field-label">الكمية (1–99)</label><input name="qty" type="number" min="1" max="99" value="' . (int)($adm_edit_o['qty'] ?? 1) . '"></div>'
           . '<div><label class="field-label">سعر القطعة (' . esc($ae_cur) . ')</label><input name="unit" type="number" min="0" max="200000" step="0.01" value="' . esc((string)(float)($adm_edit_o['unit'] ?? 0)) . '"></div>'
           . '<div><label class="field-label">مصاريف الشحن (' . esc($ae_ship_cur) . ')</label><input name="ship" type="number" min="0" max="20000" step="0.01" value="' . esc((string)(float)($adm_edit_o['ship'] ?? 0)) . '"></div>'
           . '<div><label class="field-label">عمولة المسوق على الطلب كله (ج.م)</label><input name="m_earn" type="number" min="0" max="50000" step="0.01" value="' . esc((string)(float)($adm_edit_o['m_earn'] ?? 0)) . '"></div>'
           . '<div><label class="field-label">العمولة الإضافية على القطعة (ج.م)' . ($ae_dig ? ' — الرقمي/السوشيال: مفيش' : '') . '</label><input name="extra_comm" type="number" min="0" max="' . esc((string)(float)MAX_MARKUP_AMOUNT) . '" step="0.01" value="' . esc((string)(float)($adm_edit_o['extra_comm'] ?? 0)) . '"' . ($ae_dig ? ' disabled' : '') . '>' . ($ae_dig ? '<input type="hidden" name="extra_comm" value="0">' : '') . '</div>'
           . '<div><label class="field-label">المحافظة / المنطقة</label><input name="city" maxlength="60" value="' . $ae_in('city') . '"></div>'
           . '<div><label class="field-label">المدينة / المركز</label><input name="district" maxlength="60" value="' . $ae_in('district') . '"></div>'
           . '<div><label class="field-label">المواصفات / العرض</label><input name="variant" maxlength="60" value="' . $ae_in('variant') . '"></div>'
           . '<div><label class="field-label">اللون</label><input name="color" maxlength="60" value="' . $ae_in('color') . '"></div>'
           . '<div><label class="field-label">المقاس</label><input name="size" maxlength="60" value="' . $ae_in('size') . '"></div>'
           . '<div style="grid-column:1/-1"><label class="field-label">العنوان بالتفصيل</label><input name="address" maxlength="300" value="' . $ae_in('address') . '"></div>'
           . '<div style="grid-column:1/-1"><label class="field-label">ملاحظات الطلب</label><input name="notes" maxlength="500" value="' . $ae_in('notes') . '"></div>'
           . '<div style="grid-column:1/-1"><button class="btn pri" type="submit">حفظ كل التعديلات</button> <a class="btn outline" href="?tab=orders">رجوع للطلبات</a></div>'
           . '</form>'
           . '<form method="post" data-confirm="حذف الطلب ' . $ae_in('order_no') . ' نهائيًا من النظام؟ هيتمسح من قوائم الإدارة والمسوق والعميل ومش هيرجع، وأي عمولة لسه مفعّلة هتتسحب من المسوق (زي الإلغاء). للإلغاء العادي غيّر الحالة بدل الحذف." style="margin-top:14px;padding-top:12px;border-top:1px dashed #fecaca;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">' . csrf_field()
           . '<input type="hidden" name="adm_order_action" value="delete"><input type="hidden" name="adm_order_no" value="' . $ae_in('order_no') . '">'
           . '<label style="font-size:12px;font-weight:800;color:#b91c1c">منطقة خطر: حذف الطلب نهائيًا<br><input name="delete_reason" maxlength="300" placeholder="سبب الحذف (اختياري — يوصل للمسوق والعميل)" style="width:280px;padding:8px;border:1px solid #fecaca;border-radius:8px;margin-top:4px;font-weight:400"></label>'
           . '<button class="btn sm" style="background:#b91c1c;border-color:#b91c1c;color:#fff">🗑 حذف الطلب نهائيًا</button>'
           . '</form></div>';
    }
    echo '<form id="ordBulkForm" method="post" data-confirm="حذف كل الطلبات المحددة نهائيًا من النظام؟ الحذف النهائي مابيرجعش، وأي عمولة لسه مفعّلة هتتسحب من مسوقها (زي الإلغاء). للإلغاء العادي استخدم تغيير الحالة بدل الحذف." style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0">' . csrf_field()
        . '<input type="hidden" name="bulk_delete_orders" value="1">'
        . '<label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" onchange="document.querySelectorAll(\'.ord-pick\').forEach(function(c){c.checked=this.checked;},this)"> تحديد كل المعروض</label>'
        . '<button type="submit" class="btn sm" style="color:#b91c1c">🗑 حذف المحدد نهائيًا</button>'
        . '<span class="mut" style="font-size:12px">الحذف النهائي للطلبات التست/المكررة/الغلط بس — الطلب بيتمسح من الإدارة والمسوق والعميل وعمولته المفعّلة بتتسحب، ونسخة أمان بتتأرشف عند الإدارة.</span>'
        . '</form>';
    echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>تحديد</th><th>#</th><th>التاريخ</th><th>المنتج</th><th>العميل</th><th>الإجمالي</th><th>المسوق</th><th>الحالة</th><th>ملاحظة الإدارة</th><th>حالة الإرسال للمصدر</th></tr>';
    foreach ($rev_orders as $idx => $o) {
        // قيمة الحذف: رقم الطلب، وللصفوف القديمة اللي من غير رقم طلب بنستخدم الفهرس + تاريخ الطلب كهوية آمنة
        $del_val = trim((string)($o['order_no'] ?? '')) !== '' ? (string)$o['order_no'] : ('idx|' . (int)$idx . '|' . (string)($o['at'] ?? ''));
        $del_lbl = trim((string)($o['order_no'] ?? '')) !== '' ? (string)$o['order_no'] : ('#' . ((int)$idx + 1));
        echo '<tr>';
        echo '<td><input type="checkbox" class="ord-pick" form="ordBulkForm" name="bulk_order_nos[]" value="' . esc($del_val) . '" aria-label="تحديد الطلب للحذف الجماعي"></td>';
        echo '<td><b>#' . ($idx + 1) . '</b><br><span class="mut" dir="ltr">' . esc($o['order_no'] ?? '') . '</span></td>';
        echo '<td dir="ltr">' . esc(substr($o['at'] ?? '', 0, 16)) . '</td>';
        // كل التفاصيل (العميل والهاتف والعنوان والكمية والسعر والشحن والعمولة والملاحظات) في الديالوج الكبير — الجدول فضل بسيط
        echo '<td><a href="product.php?id=' . urlencode($o['id'] ?? '') . '" target="_blank" data-ui="u-f14488a7 dc d2k d2l">' . esc(mb_substr(aff_clean($o['pname'] ?? '', true), 0, 38)) . '</a><br>' . wf_details_btn('كل تفاصيل الطلب ' . ($o['order_no'] ?? ''), wf_order_details_rows($o, true), 'تفاصيل إضافية') . '</td>';
        echo '<td><b>' . esc($o['cname'] ?? '') . '</b></td>';
        echo '<td><b data-ui="u-8bf4bd79 d2k">' . number_format((float)($o['total'] ?? 0),2) . ' ' . esc(wf_ui_currency($o)) . '</b></td>';
        $mk_row = ((string)($o['ref'] ?? '') !== '') ? find_marketer_by_code((string)$o['ref']) : null;
        echo '<td>' . ($mk_row ? '<b>' . esc((string)($mk_row['name'] ?? '')) . '</b><br>' : '') . '<span dir="ltr" class="mut">' . esc($o['ref'] ?: '—') . '</span></td>';
        echo '<td><form data-confirm="تغيير حالة الطلب قد يؤكد أو يعكس عمولاته بحسب منطق النظام الحالي. هل تريد حفظ الحالة المختارة؟" method="post" data-ui="u-67647882 d19"><input type="hidden" name="order_idx" value="' . (int)$idx . '"><input name="status_reason" maxlength="300" placeholder="سبب الإلغاء/الإرجاع (يظهر للمسوق)" style="margin-bottom:6px"><select name="new_status" onchange="wfRequestConfirmSubmit(this.form)" data-ui="u-3173ae86 d39 d1u dt d1v do dm">';
        foreach ($statuses as $sk) echo '<option value="' . esc($sk) . '"' . (status_key($o['status'] ?? '') === $sk ? ' selected' : '') . '>' . esc(status_label($sk)) . '</option>';
        echo '</select><button class="btn sm outline" style="margin-top:6px" title="يحفظ السبب المكتوب مع الحالة الحالية — حتى من غير ما تغيّر الحالة">💾 حفظ السبب</button></form>'
           . '<a class="btn sm outline" style="margin-top:6px" href="?tab=orders&edit=' . urlencode((string)($o['order_no'] ?? '')) . '" title="تعديل كل تفاصيل الطلب بما فيها العمولة">تعديل كامل</a>'
           . '<form method="post" data-confirm="حذف الطلب ' . esc($del_lbl) . ' نهائيًا من النظام؟ هيتمسح من قوائم الإدارة والمسوق والعميل ومش هيرجع، وأي عمولة لسه مفعّلة هتتسحب من المسوق (زي الإلغاء). للإلغاء العادي غيّر الحالة بدل الحذف." style="margin-top:6px">' . csrf_field()
           . '<input type="hidden" name="adm_order_action" value="delete"><input type="hidden" name="adm_order_no" value="' . esc($del_val) . '">'
           . '<button class="btn sm" style="color:#b91c1c;border-color:#fecaca" title="حذف الطلب نهائيًا من النظام">🗑 حذف نهائي</button></form></td>';
        // الملاحظات المكتوبة بتظهر في ديالوج «تفاصيل إضافية» — الخلية فيها فورم الإضافة فقط
        echo '<td data-ui="u-5d985ec3 do">';
        echo '<form method="post" data-ui="u-67647882 d19"><input type="hidden" name="order_note_idx" value="' . (int)$idx . '"><input name="order_note_text" maxlength="500" placeholder="اكتب ملاحظة..." data-ui="u-a2e2dc8d d3l d3m do dt d2w" style="width:150px"> <button class="btn sm" data-ui="u-03ebc769 do d3h">حفظ</button></form></td>';
        $sync_st = $o['source_sync_status'] ?? 'pending';
        $sync_badges = ['pending' => ['⏳ لم يُرسل', 'var(--amber-100)', 'var(--amber-600)'], 'processing' => [' جارٍ الإرسال', '#dbeafe', 'var(--electro-blue)'], 'sent' => [' أُرسل للمصدر', '#dcfce7', 'var(--tone-15803d)'], 'failed' => [' فشل الإرسال', '#fee2e2', 'var(--red-700)']];
        $snb = $sync_badges[$sync_st] ?? $sync_badges['pending'];
        echo '<td><span style="padding:3px 9px;border-radius:99px;font-size:12px;font-weight:800;background:' . $snb[1] . ';color:' . $snb[2] . '">' . wf_svg_icon('clock',16) . esc(wf_ui_plain($snb[0])) . '</span>';
        if ($sync_st === 'failed') {
            echo ' <form data-confirm="إعادة إرسال الطلب للمصدر قد تبدأ تنفيذه. تحقق من عدم إرساله بالفعل ثم أكد المحاولة." method="post" data-ui="u-dd31d1d6 d3a d19"><input type="hidden" name="retry_order" value="' . (int)$idx . '"><button class="btn sm outline" data-ui="u-45cb4c14 d3b do"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-refresh></use></svg> إعادة المحاولة</button></form>';
            if (!empty($o['source_last_error'])) echo '<br>' . wf_det_text($o['source_last_error'], 40, 'خطأ الإرسال للمصدر — طلب ' . ($o['order_no'] ?? ''), 'تفاصيل الخطأ');
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</table></div></div>';
}

// =========================================================================
// 7. TAB: PRODUCTS (إدارة المنتجات وتعديل الأسعار مباشرة)
// =========================================================================
elseif ($tab === 'products') {
    $q = trim($_GET['q'] ?? '');
    $plat_f = $_GET['plat'] ?? 'all';
    $pg = max(1, (int)($_GET['pg'] ?? 1));
    $res = sb_platform_products($plat_f ?: 'all', $q, $pg, 40);
    $items = $res[0] ?? [];
    $total_p = (int)($res[1] ?? 0);

    echo '<div class="adm-box">';
    echo '<h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg> إدارة المنتجات وتفاصيلها</span></h2><p class="mut">يمكن تعديل بيانات المنتج بالكامل أو حذفه من المتجر. الحذف لا يمس الطلبات أو السجلات التاريخية.</p>';
    echo '<form method="get" data-ui="u-1dcf4cfc dh d1s dj d0">';
    echo '<input type="hidden" name="tab" value="products">';
    echo '<input name="q" value="' . esc($q) . '" placeholder="ابحث باسم المنتج أو الكود..." data-ui="u-b05aa79a d2i d3c d3d dt d24 d1v">';
    echo '<select name="plat" data-ui="u-747828c1 d3d dt d24 d1v">';
    foreach (['all' => 'كل المنتجات', 'manual' => 'منتجات يدوية', 'vendoor' => 'قسم 1 (Vendoor)', 'safqa' => 'قسم 2 (Safqa)', 'timehand' => 'قسم 3 (Timehand)', 'angazny' => 'قسم 4 (Angazny)', 'm5zoon' => 'الخليج (M5zoon)', 'shopmart' => 'Shopmart', 'cezma' => 'Cezma (سيزما)', 'g2g' => 'المنتجات الرقمية', 'social' => 'خدمات السوشيال'] as $pk => $plbl) {
        echo '<option value="' . esc($pk) . '"' . ($plat_f === $pk ? ' selected' : '') . '>' . esc($plbl) . '</option>';
    }
    echo '</select><button class="btn"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-search></use></svg> بحث</button></form>';

    echo '<form id="prodBulkForm" method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0">' . csrf_field()
        . '<input type="hidden" name="bulk_delete_products" value="1">'
        . '<label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" onchange="document.querySelectorAll(\'.prod-pick\').forEach(function(c){c.checked=this.checked;},this)"> تحديد كل الصفحة</label>'
        . '<button type="submit" class="btn sm" style="color:#b91c1c" onclick="return confirm(\'حذف كل المنتجات المحددة نهائيًا من المتجر؟ الطلبات والسجلات التاريخية مش هتتأثر.\')">🗑 حذف المحدد</button>'
        . '</form>';

    echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-server-page="1" data-ui="u-5d985ec3 do"><tr><th>تحديد</th><th>الصورة</th><th>اسم المنتج</th><th>القسم</th><th>المشاهدات </th><th>اللاڤ </th><th>إضافات السلة </th><th>الطلبات </th><th>نسبة التحويل</th><th>تعديل السعر الحالي</th><th>تحكم</th><th>معاينة</th></tr>';
    foreach ($items as $p) {
        $pl_k = $p['_plat'] ?? resolve_plat($p['id'] ?? '', $plat_f);
        $uid = $pl_k . '#' . ($p['id'] ?? '');
        $ovk = $pl_k . '#' . ($p['id'] ?? ''); $ovr = product_overrides()[$ovk] ?? [];
        $img = $p['images'][0] ?? '';
        $vw = (int)($views_map[$uid] ?? (int)($prod_counters[$uid]['views'] ?? 0));
        $lv = (int)($love_prod_counts[$uid] ?? 0);
        $ca = (int)($prod_counters[$uid]['carts'] ?? 0);
        $ord_c = (int)($prod_counters[$uid]['orders'] ?? 0) + count(array_filter($orders, function ($o) use ($p) { return (string)($o['id'] ?? '') === (string)($p['id'] ?? 'x'); }));
        $conv_rate = $vw > 0 ? round($ord_c / $vw * 100, 1) : 0;
        $pr_now = list_price($p, $pl_k) ?: display_price($p, $pl_k);
        echo '<tr>';
        echo '<td><input type="checkbox" class="prod-pick" form="prodBulkForm" name="bulk_keys[]" value="' . esc($uid) . '" aria-label="تحديد المنتج"></td>';
        echo '<td>' . ($img ? '<img alt="' . esc(aff_clean($p['name'] ?? 'صورة المنتج', true)) . '" loading="lazy" width="48" height="48" src="' . esc(asset_url($img)) . '" data-ui="u-25323dcd d2z d30 d2t d1u">' : '') . '</td>';
        echo '<td><b>' . esc(mb_substr(aff_clean($p['name'] ?? '', true), 0, 55)) . '</b><br><span class="mut" dir="ltr">#' . esc($p['id'] ?? '') . '</span></td>';
        $category_options = wf_category_canonical();
        $product_category = trim((string)($p['category'] ?? ''));
        if ($product_category !== '' && !in_array($product_category, $category_options, true)) $category_options[] = $product_category;
        echo '<td><span class="badge blue">' . esc($product_category ?: 'بدون قسم') . '</span><details><summary class="btn sm outline">تعديل القسم</summary><form method="post" class="form-row">' . csrf_field() . '<input type="hidden" name="ov_plat" value="' . esc($pl_k) . '"><input type="hidden" name="ov_id" value="' . esc($p['id'] ?? '') . '"><input type="hidden" name="ov_action" value="category"><select name="ov_category"><option value="">قسم المصدر (افتراضي)</option>';
        foreach ($category_options as $category_option) echo '<option value="' . esc($category_option) . '"' . (($ovr['category'] ?? '') === $category_option ? ' selected' : '') . '>' . esc($category_option) . '</option>';
        echo '</select><button class="btn sm">حفظ القسم</button></form></details></td>';
        echo '<td><b>' . number_format($vw) . '</b></td>';
        echo '<td>' . ($lv > 0 ? '<span class="badge neutral"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-heart></use></svg> ' . $lv . '</span>' : '0') . '</td>';
        echo '<td>' . ($ca > 0 ? '<span class="badge amber"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-cart></use></svg> ' . $ca . '</span>' : '0') . '</td>';
        echo '<td>' . ($ord_c > 0 ? '<span class="badge green"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-orders></use></svg> ' . $ord_c . '</span>' : '0') . '</td>';
        echo '<td><b style="color:' . ($conv_rate >= 3 ? 'var(--success)' : ($conv_rate >= 1 ? 'var(--warning-text)' : 'var(--slate-400)')) . '">' . $conv_rate . '%</b></td>';
        echo '<td><form data-confirm="سيُحفظ السعر الجديد لهذا المنتج ويؤثر على الطلبات القادمة. تأكيد الحفظ؟" method="post" data-ui="u-fd0a8ea7 d3e di d1p d19">' . csrf_field();
        echo '<input type="hidden" name="edit_prod_plat" value="' . esc($pl_k) . '">';
        echo '<input type="hidden" name="edit_prod_id" value="' . esc($p['id'] ?? '') . '">';
        echo '<input name="new_price" type="number" step="0.5" value="' . esc($pr_now) . '" data-ui="u-39d7d7e4 d3f d3g dt d1u dc">';
        echo '<button class="btn sm"> حفظ</button></form></td>';
        echo '<td data-ui="u-176669be d2m"><form data-confirm="سيتم تبديل إظهار المنتج في المتجر. هذا لا يحذف المنتج من المصدر. تأكيد التغيير؟" method="post" data-ui="u-dd31d1d6 d3a d19">' . csrf_field() . '<input type="hidden" name="ov_plat" value="' . esc($pl_k) . '"><input type="hidden" name="ov_id" value="' . esc($p['id'] ?? '') . '"><input type="hidden" name="ov_action" value="hide"><button class="btn sm' . (!empty($ovr['hidden']) ? '' : ' outline') . '" data-ui="u-03ebc769 do d3h" title="إخفاء/إظهار">' . (!empty($ovr['hidden']) ? ' مخفي' : ' ظاهر') . '</button></form> ';
        echo '<form method="post" data-ui="u-dd31d1d6 d3a d19">' . csrf_field() . '<input type="hidden" name="ov_plat" value="' . esc($pl_k) . '"><input type="hidden" name="ov_id" value="' . esc($p['id'] ?? '') . '"><input type="hidden" name="ov_action" value="pin"><button class="btn sm' . (!empty($ovr['pinned']) ? ' pri' : ' outline') . '" data-ui="u-03ebc769 do d3h" title="تثبيت في الأول"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-pin></use></svg></button></form>';
        $extra_data = is_array($p['extra'] ?? null) ? $p['extra'] : [];
        $edit_images = implode("\n", (array)($p['images'] ?? []));
        $edit_colors = implode("\n", (array)($extra_data['colors'] ?? []));
        $edit_sizes = implode("\n", (array)($p['sizes'] ?? []));
        $edit_features = (string)($extra_data['features'] ?? '');
        $edit_specs = (string)($extra_data['specifications'] ?? '');
        $edit_variants = json_encode(array_values((array)($extra_data['variants_detailed'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo '<details><summary class="btn sm outline">تعديل كل التفاصيل</summary><form method="post" class="form-row" style="min-width:300px;max-width:520px;display:grid;gap:8px">' . csrf_field()
            . '<input type="hidden" name="ov_plat" value="' . esc($pl_k) . '"><input type="hidden" name="ov_id" value="' . esc($p['id'] ?? '') . '"><input type="hidden" name="ov_action" value="full_details">'
            . '<label>الاسم<input name="full_name" required maxlength="200" value="' . esc($p['name'] ?? '') . '"></label>'
            . '<label>السعر<input name="full_price" required type="number" min="0" step="0.01" value="' . esc($pr_now) . '"></label>'
            . '<label>القسم<select name="full_category"><option value="">قسم المصدر</option>';
        $full_categories = wf_category_canonical();
        if (!empty($p['category']) && !in_array($p['category'], $full_categories, true)) $full_categories[] = $p['category'];
        foreach ($full_categories as $full_cat) echo '<option value="' . esc($full_cat) . '"' . (($p['category'] ?? '') === $full_cat ? ' selected' : '') . '>' . esc($full_cat) . '</option>';
        echo '</select></label>'
            . '<label>كود المنتج / SKU<input name="full_sku" value="' . esc($p['sku'] ?? '') . '"></label>'
            . '<label>روابط الصور (رابط في كل سطر)<textarea name="full_images" rows="4" dir="ltr">' . esc($edit_images) . '</textarea></label>'
            . '<label>الوصف<textarea name="full_description" rows="5">' . esc($p['desc'] ?? '') . '</textarea></label>'
            . '<label>الألوان (لون في كل سطر)<textarea name="full_colors" rows="3">' . esc($edit_colors) . '</textarea></label>'
            . '<label>المقاسات / الخيارات (خيار في كل سطر)<textarea name="full_sizes" rows="3">' . esc($edit_sizes) . '</textarea></label>'
            . '<label>المميزات<textarea name="full_features" rows="4">' . esc($edit_features) . '</textarea></label>'
            . '<label>المواصفات<textarea name="full_specs" rows="4">' . esc($edit_specs) . '</textarea></label>'
            . '<label>خيارات المنتج المركبة (JSON: color, size, price, image)<textarea name="full_variants" rows="8" dir="ltr">' . esc($edit_variants ?: '[]') . '</textarea></label>'
            . '<button class="btn">حفظ كل التعديلات</button></form></details>'
            . '<form method="post" style="margin-top:6px" onsubmit="return confirm(\'حذف هذا المنتج نهائياً من المتجر؟ لن يتم حذف أي طلب أو سجل تاريخي.\')">' . csrf_field()
            . '<input type="hidden" name="ov_plat" value="' . esc($pl_k) . '"><input type="hidden" name="ov_id" value="' . esc($p['id'] ?? '') . '"><input type="hidden" name="ov_action" value="delete"><button class="btn sm outline" style="color:#b91c1c">🗑 حذف نهائي</button></form></td>';
        echo '<td><a href="product.php?id=' . urlencode($p['id'] ?? '') . '&plat=' . urlencode($pl_k) . '" target="_blank" class="btn sm outline">عرض ↗</a></td>';
        echo '</tr>';
    }
    echo '</table></div><nav class="pager" aria-label="صفحات المنتجات">';
    if ($pg > 1) echo '<a href="?tab=products&pg=' . ($pg - 1) . '&plat=' . urlencode($plat_f) . '&q=' . urlencode($q) . '">السابق</a>';
    echo '<span>' . ($total_p ? (($pg - 1) * 40 + 1) : 0) . '–' . min($pg * 40, $total_p) . ' من ' . number_format($total_p) . '</span>';
    if ($pg * 40 < $total_p) echo '<a href="?tab=products&pg=' . ($pg + 1) . '&plat=' . urlencode($plat_f) . '&q=' . urlencode($q) . '">التالي</a>';
    echo '</nav></div>';
}

// =========================================================================
// 8. TAB: MARKETERS (المسوقين وطلبات السحب)
// =========================================================================
elseif ($tab === 'marketers') {
    //  روابط GET القديمة للموافقة/الرفض أُزيلت — العمليات المالية تتم عبر POST + CSRF فقط

    // ===== صفحة تفاصيل مسوق محدد =====
    $mk_view = trim((string)($_GET['mk'] ?? ''));
    if ($mk_view !== '') {
        $mu = null;
        foreach ($marketers as $x) { if (($x['email'] ?? '') === $mk_view) { $mu = $x; break; } }
        if ($mu) {
            $mref = $mu['ref_code'] ?? '';
            $mo = function_exists('wf_orders_by_ref') ? wf_orders_by_ref($orders, $mref) : array_values(array_filter($orders, function ($o) use ($mref) { return ($o['ref'] ?? '') === $mref; }));
            $mo_rev = array_reverse($mo, true);
            $mrev = array_sum(array_map(function ($o) { return (float)($o['total'] ?? 0); }, $mo));
            $mcomm = array_sum(array_map(function ($o) { return (float)($o['m_earn'] ?? 0); }, $mo));
            $mclicks = (int)($mk_clicks[$mref]['total_clicks'] ?? 0);
            $muv = is_array($mk_clicks[$mref]['unique_visitors'] ?? null) ? count($mk_clicks[$mref]['unique_visitors']) : 0;
            $mconv = $mclicks > 0 ? round(count($mo) / $mclicks * 100, 2) : 0;
            $mteam = array_filter($all_users, function ($x) use ($mref) { return ($x['referred_by'] ?? '') === $mref; });
            $mstatus = $mu['comm_status'] ?? 'pending';
            $mpending = $mstatus === 'pending' ? $mcomm : 0;
            $mconfirmed = $mstatus === 'confirmed' ? $mcomm : 0;
            $mpaid = $mstatus === 'paid' ? $mcomm : 0;

            // أفضل منتجاته مبيعاً
            $mprods = [];
            foreach ($mo as $oo) {
                $k = (string)($oo['id'] ?? '');
                if ($k === '') continue;
                if (!isset($mprods[$k])) $mprods[$k] = ['name' => $oo['pname'] ?? '', 'qty' => 0, 'total' => 0, 'comm' => 0];
                $mprods[$k]['qty'] += (int)($oo['qty'] ?? 1);
                $mprods[$k]['total'] += (float)($oo['total'] ?? 0);
                $mprods[$k]['comm'] += (float)($oo['m_earn'] ?? 0);
            }
            uasort($mprods, function ($a, $b) { return $b['comm'] <=> $a['comm']; });

            echo '<div class="adm-box" data-ui="u-fcea9ae7 d0 d3n">';
            echo '<div data-ui="u-4f57474c dh d1o d1p dj d1q d0">';
            echo '<h2 data-ui="u-67647882 d19"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-user></use></svg> صفحة المسوق: <b data-ui="u-f1ca7b59 d1d">' . esc($mu['name'] ?? '') . '</b> <span class="badge blue" dir="ltr">' . esc($mref) . '</span></h2>';
            echo '<div data-ui="u-4f57474c dh d1p dj d4g">'
               . '<form method="post" class="impersonate-form">' . csrf_field()
               . '<input type="hidden" name="impersonate_email" value="' . esc($mu['email'] ?? '') . '">'
               . '<input type="hidden" name="impersonate_from" value="marketers">'
               . '<button type="submit" class="btn sm pri" title="الدخول إلى لوحة تحكم هذا المسوق">' . wf_svg_icon('user', 16) . '<span>دخول لحسابه</span></button></form>'
               . '<a href="?tab=marketers" class="btn sm outline">← الرجوع لكل المسوقين</a></div></div>';

            echo '<div class="kpis" data-ui="u-f2235598 d1r">';
            echo '<div class="kpi" data-ui="u-bc691366 d6"><b>' . number_format($mclicks) . '</b><span> نقرات على رابطه</span></div>';
            echo '<div class="kpi" data-ui="u-85fe13fb d2q"><b>' . number_format($muv) . '</b><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-user></use></svg> زوار فريدين</span></div>';
            echo '<div class="kpi" data-ui="u-97b96627 d5"><b>' . count($mo) . '</b><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-orders></use></svg> الطلبات</span></div>';
            echo '<div class="kpi" data-ui="u-3824e45f d4"><b>' . $mconv . '%</b><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chart></use></svg> نسبة التحويل</span></div>';
            echo '<div class="kpi" data-ui="u-3824e45f d4"><b>' . number_format($mcomm, 2) . ' ج.م</b><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> إجمالي العمولة</span></div>';
            echo '<div class="kpi" data-ui="u-61cbd4d0 d3o"><b>' . count($mteam) . '</b><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-users></use></svg> فريقه</span></div>';
            echo '</div>';

            echo '<div data-ui="u-4757b09b d21 d3p d3q">';
            echo '<div><div data-ui="u-4e2cf757 du d1 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> حالة العمولات</div>';
            echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>معلقة</th><th>مؤكدة</th><th>مدفوعة</th></tr><tr>';
            echo '<td><b data-ui="u-f1fe78ea d3r">' . number_format($mpending, 2) . ' ج.م</b></td>';
            echo '<td><b data-ui="u-7306e747 d3s">' . number_format($mconfirmed, 2) . ' ج.م</b></td>';
            echo '<td><b data-ui="u-5f2f9411 d3t">' . number_format($mpaid, 2) . ' ج.م</b></td>';
            echo '</tr></table></div>';

            echo '<div><div data-ui="u-4e2cf757 du d1 d2d"> أفضل منتجاته مبيعاً</div>';
            if ($mprods) {
                echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>المنتج</th><th>الكمية</th><th>المبيعات</th><th>عمولته</th></tr>';
                foreach (array_slice($mprods, 0, 6, true) as $mkp => $mpd) {
                    echo '<tr><td>' . esc(mb_substr(aff_clean($mpd['name'], true), 0, 38)) . '</td><td><b>' . $mpd['qty'] . '</b></td><td>' . number_format($mpd['total']) . ' ج.م</td><td><b data-ui="u-61a4e8ed d1x">' . number_format($mpd['comm'], 2) . ' ج.م</b></td></tr>';
                }
                echo '</table>';
            } else { echo '<p class="mut" data-ui="u-5d985ec3 do">لا توجد مبيعات مسجلة.</p>'; }
            echo '</div>';
            echo '</div>';

            echo '<div data-ui="u-aec60660 dl du d1 d2d"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-orders></use></svg> كل طلبات المسوق بكل تفاصيلها (' . count($mo_rev) . ')</div>';
            if ($mo_rev) {
                echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>رقم الطلب/التاريخ</th><th>المنتج</th><th>العميل</th><th>المنطقة والعنوان</th><th>الإجمالي</th><th>عمولته</th><th>الحالة</th><th>التفاصيل</th></tr>';
                foreach ($mo_rev as $oo) {
                    $oo_no = (string)($oo['order_no'] ?? '');
                    $cs19 = (string)($oo['comm_state'] ?? '');
                    $cs_lbl19 = ['pending' => 'معلقة', 'confirmed' => 'مؤكدة', 'reversed' => 'مسحوبة', 'suspended' => 'موقوفة', 'none' => 'لا توجد'][$cs19] ?? $cs19;
                    echo '<tr>';
                    echo '<td dir="ltr"><b>' . esc($oo_no) . '</b><br><span class="mut">' . esc(substr((string)($oo['at'] ?? ''), 0, 16)) . '</span></td>';
                    echo '<td><b>' . esc(mb_substr(aff_clean($oo['pname'] ?? '', true), 0, 40)) . '</b>' . (function_exists('order_variant_label') && order_variant_label($oo) !== '' ? '<br><span class="mut">' . esc(order_variant_label($oo)) . '</span>' : '') . '<br><span class="mut">' . (int)($oo['qty'] ?? 1) . ' قطعة</span></td>';
                    echo '<td>' . esc($oo['cname'] ?? '') . '<br><span dir="ltr">' . esc($oo['cphone'] ?? '') . '</span>' . (trim((string)($oo['cphone2'] ?? '')) !== '' ? '<br><span dir="ltr" class="mut">' . esc($oo['cphone2']) . '</span>' : '') . '</td>';
                    echo '<td>' . esc($oo['city'] ?? '') . ' — ' . esc($oo['district'] ?? '') . '<br><span class="mut">' . esc(mb_substr((string)($oo['address'] ?? ''), 0, 40)) . '</span></td>';
                    echo '<td><b>' . number_format((float)($oo['total'] ?? 0), 2) . ' ' . esc(function_exists('wf_ui_currency') ? wf_ui_currency($oo) : 'ج.م') . '</b>' . ((float)($oo['discount'] ?? 0) > 0 ? '<br><span class="mut" style="color:var(--success)">خصم ' . number_format((float)($oo['discount'] ?? 0), 2) . ' ج.م</span>' : '') . '</td>';
                    echo '<td data-ui="u-8b877a44 d1x dc">+' . number_format((float)($oo['m_earn'] ?? 0), 2) . ' ج.م<br><span class="mut">' . esc($cs_lbl19) . '</span></td>';
                    echo '<td>' . wf_order_badge($oo['status'] ?? '') . '</td>';
                    echo '<td>' . wf_details_btn('كل تفاصيل الطلب ' . $oo_no, wf_order_details_rows($oo, true), 'تفاصيل إضافية') . ' <a class="btn sm outline" href="?tab=orders&edit=' . urlencode($oo_no) . '">تعديل كامل</a></td>';
                    echo '</tr>';
                }
                echo '</table></div>';
            }
            echo '</div>';
        }
    }

    // ===== Leaderboard لأفضل المسوقين =====
    $lb = [];
    foreach ($marketers as $u) {
        $mo = array_filter($orders, function ($o) use ($u) { return ($o['ref'] ?? '') === ($u['ref_code'] ?? ''); });
        $mc = array_sum(array_map(function ($o) { return (float)($o['m_earn'] ?? 0); }, $mo));
        if ($mc > 0) $lb[] = ['name' => $u['name'] ?? '', 'ref' => $u['ref_code'] ?? '', 'earn' => $mc, 'orders' => count($mo)];
    }
    usort($lb, function ($a, $b) { return $b['earn'] <=> $a['earn']; });
    if ($lb) {
        echo '<div class="adm-box" data-ui="u-4db121fa d0 d3u d3v d3w"><h2 data-ui="u-46feac88 d3x"> لوحة الصدارة — أفضل المسوقين (Leaderboard)</h2>';
        echo '<div data-ui="u-70f1f405 d21 d3y d3z">';
        $medals = ['', '', '', '4', '5', '6', '7', '8'];
        foreach (array_slice($lb, 0, 8) as $li => $lr) {
            echo '<div data-ui="u-6b0bad9b d40 d41 d42 d43 df">';
            echo '<div data-ui="u-92370b6e d44 d45">' . ($medals[$li] ?? '') . '</div>';
            echo '<div data-ui="u-3b8656fe d1 d12">' . esc($lr['name']) . '</div>';
            echo '<div data-ui="u-4c065b1d do d46 d47" dir="ltr">' . esc($lr['ref']) . '</div>';
            echo '<div data-ui="u-fa8b09ee d48 d1 d1m d49">' . number_format($lr['earn'], 2) . ' ج.م</div>';
            echo '<div data-ui="u-4d923dc4 do d1a">' . $lr['orders'] . ' طلب</div>';
            echo '</div>';
        }
        echo '</div></div>';
    }

    echo '<div class="adm-box" data-ui="u-25724057 d0"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-megaphone></use></svg> المسوقين المسجلين (' . count($marketers) . ')</h2>';
    echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>الاسم</th><th>الإيميل</th><th>واتساب</th><th>كود الإحالة</th><th>الحالة</th><th>الفريق</th><th>الطلبات</th><th>المبيعات</th><th>إجمالي العمولة</th><th>الرصيد الحالي</th><th>نسبة عمولة مخصصة</th><th>إجراءات الحالة</th></tr>';
    foreach ($marketers as $u) {
        $mo = array_filter($orders, function ($o) use ($u) { return ($o['ref'] ?? '') === ($u['ref_code'] ?? ''); });
        $ms = array_sum(array_map(function ($o) { return (float)($o['total'] ?? 0); }, $mo));
        $mc = array_sum(array_map(function ($o) { return (float)($o['m_earn'] ?? 0); }, $mo));
        $tm = count(array_filter($all_users, function ($x) use ($u) { return ($x['referred_by'] ?? '') === ($u['ref_code'] ?? ''); }));
        $mkst = $u['status'] ?? 'approved';
        $st_badge = $mkst === 'approved' ? '<span class="badge green"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> مفعل</span>' : ($mkst === 'suspended' ? '<span class="badge neutral"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-close></use></svg> موقوف</span>' : '<span class="badge amber"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-clock></use></svg> قيد المراجعة</span>');
        echo '<tr>';
        echo '<td><a href="?tab=marketers&mk=' . urlencode((string)($u['email'] ?? '')) . '" title="كل طلباته بكل تفاصيلها"><b>' . esc($u['name'] ?? '') . '</b></a></td>';
        echo '<td dir="ltr" class="mut"><a href="?tab=marketers&mk=' . urlencode((string)($u['email'] ?? '')) . '">' . esc($u['email'] ?? '') . '</a></td>';
        $mk_wa = trim((string)($u['whatsapp'] ?? $u['phone'] ?? ''));
        echo '<td dir="ltr">' . ($mk_wa !== '' ? '<a href="' . esc(wa_link($mk_wa)) . '" target="_blank" rel="noopener" data-ui="u-8b877a44 d1x dc">' . esc($mk_wa) . ' </a>' : '—') . '</td>';
        echo '<td dir="ltr"><span class="badge blue">' . esc($u['ref_code'] ?? '') . '</span></td>';
        echo '<td>' . $st_badge . '</td>';
        echo '<td>' . $tm . ' مسوق</td>';
        echo '<td><b>' . count($mo) . '</b></td>';
        echo '<td><b>' . number_format($ms) . ' ج.م</b></td>';
        echo '<td data-ui="u-8b877a44 d1x dc">+' . number_format($mc, 2) . ' ج.م</td>';
        echo '<td><b>' . number_format((float)($u['balance'] ?? 0), 2) . ' ج.م</b></td>';
        echo '<td><form data-confirm="سيتم تغيير نسبة العمولة المخصصة لهذا المسوق. راجع النسبة وأثرها على العمليات القادمة ثم أكد الحفظ." method="post" data-ui="u-ee455706 d3e d4a d1p d19"><input type="hidden" name="mk_email_comm" value="' . esc($u['email'] ?? '') . '"><input type="number" step="0.5" min="0" max="100" name="mk_comm_pct" value="' . esc((float)($u['custom_comm_pct'] ?? 0)) . '" data-ui="u-e5b06130 d4b d4c dt d1u dc do"><button class="btn sm"></button></form></td>';
        echo '<td data-ui="u-176669be d2m">'
           . '<form method="post" class="impersonate-form">' . csrf_field()
           . '<input type="hidden" name="impersonate_email" value="' . esc($u['email'] ?? '') . '">'
           . '<input type="hidden" name="impersonate_from" value="marketers">'
           . '<button type="submit" class="btn sm pri" data-ui="u-a41a5b62 d4d do" title="الدخول لحساب هذا المسوق">' . wf_svg_icon('user', 15) . '<span>دخول لحسابه</span></button></form> '
           . '<a class="btn sm outline" data-ui="u-a41a5b62 d4d do" href="?tab=marketers&mk=' . urlencode($u['email'] ?? '') . '"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> صفحته</a> ';
        echo '<a data-confirm="سيتم تحديث حالة حساب المسوق؛ الإيقاف أو التعليق قد يمنع إجراءات الحساب. تأكيد التغيير؟" class="btn sm" data-ui="u-6e6bb2a3 d4e d3v d4d do" href="?tab=marketers&mk_status=approved&mk_email=' . urlencode($u['email'] ?? '') . '"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> موافقة</a> ';
        echo '<a data-confirm="سيتم تحديث حالة حساب المسوق؛ الإيقاف أو التعليق قد يمنع إجراءات الحساب. تأكيد التغيير؟" class="btn sm" data-ui="u-deb28f62 d4f d3v d4d do" href="?tab=marketers&mk_status=suspended&mk_email=' . urlencode($u['email'] ?? '') . '"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-close></use></svg> إيقاف</a> ';
        echo '<a data-confirm="سيتم تحديث حالة حساب المسوق؛ الإيقاف أو التعليق قد يمنع إجراءات الحساب. تأكيد التغيير؟" class="btn sm outline" data-ui="u-a41a5b62 d4d do" href="?tab=marketers&mk_status=pending&mk_email=' . urlencode($u['email'] ?? '') . '"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-clock></use></svg> تعليق</a>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</table></div>';

    $po = jload('payouts.json', []);
    if ($po) {
        echo '<div class="adm-box"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> طلبات سحب الأرباح</h2><table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>المسوق</th><th>المبلغ</th><th>الحالة</th><th>التاريخ</th><th>إجراء</th></tr>';
        foreach (array_reverse($po, true) as $pi => $p) {
            echo '<tr><td dir="ltr">' . esc($p['user'] ?? '') . '</td><td><b>' . number_format((float)($p['amount'] ?? 0)) . ' ج.م</b></td><td>' . esc($p['status'] ?? '') . '</td><td>' . esc(substr($p['at'] ?? '', 0, 10)) . '</td><td>';
            if (($p['status'] ?? '') === 'قيد المراجعة') {
                echo '<form data-confirm="سيُعتمد طلب السحب ويُسجل رقم التحويل الذي أدخلته. تأكد من المبلغ والحساب ومرجع التحويل قبل المتابعة." method="post" data-ui="u-769bdaf4 d3e d4g d19 d1p">'
                   . csrf_field()
                   . '<input type="hidden" name="payout_idx" value="' . (int)$pi . '">'
                   . '<input name="payout_transfer" placeholder="رقم التحويل..." data-ui="u-eb16f3fc d4h d39 dt d1u do">'
                   . '<button class="btn sm" data-ui="u-803b951b d4e d3v d4i do"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> موافقة</button></form> ';
            }
            echo '</td></tr>';
        }
        echo '</table></div>';
    }
}

// =========================================================================
// 9. TAB: USERS (المستخدمين المسجلين)
// =========================================================================
elseif ($tab === 'users') {
    $all_u = array_reverse($all_users);
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $all_u = array_filter($all_u, function ($u) use ($q) {
            return mb_stripos(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['whatsapp'] ?? $u['phone'] ?? ''), $q) !== false;
        });
    }
    echo '<div class="adm-box"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-users></use></svg> المستخدمين المسجلين (' . count($all_u) . ')</h2>';
    echo '<form method="get" data-ui="u-4be50be1 dh d1s d1r"><input type="hidden" name="tab" value="users"><input name="q" value="' . esc($q) . '" placeholder="بحث بالاسم أو البريد..." data-ui="u-36858c0d d2i d4j dt d1u d1v"><button class="btn">بحث</button></form>';
    echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>الاسم</th><th>البريد الإلكتروني</th><th>واتساب</th><th>الدور</th><th>الرصيد</th><th>كود الإحالة</th><th>تاريخ التسجيل</th><th>إجراءات</th></tr>';
    foreach ($all_u as $u) {
        $is_me_row = !empty($user['email']) && strcasecmp((string)$user['email'], (string)($u['email'] ?? '')) === 0;
        echo '<tr>';
        echo '<td><b>' . esc($u['name'] ?? '') . '</b></td>';
        echo '<td dir="ltr" class="mut">' . esc($u['email'] ?? '') . '</td>';
        $user_wa = trim((string)($u['whatsapp'] ?? $u['phone'] ?? ''));
        echo '<td dir="ltr">' . ($user_wa !== '' ? '<a href="' . esc(wa_link($user_wa)) . '" target="_blank" rel="noopener" data-ui="u-8b877a44 d1x dc">' . esc($user_wa) . ' </a>' : '—') . '</td>';
        echo '<td><form data-confirm="سيتم تحديث دور هذا المستخدم ورصيده؛ تغيير الدور يؤثر على الصلاحيات. راجع القيم ثم أكد التنفيذ." method="post" data-ui="u-fd0a8ea7 d3e di d1p d19"><input type="hidden" name="edit_email" value="' . esc($u['email'] ?? '') . '"><select name="new_role" data-ui="u-a8b13c04 d4k dt d2w d1v">';
        $u_role = (($u['role'] ?? '') === 'admin') ? 'admin' : 'marketer'; // حساب العميل اتلغى
        foreach (['marketer' => 'مسوق', 'admin' => 'مدير'] as $rv => $rl) echo '<option value="' . $rv . '"' . ($u_role === $rv ? ' selected' : '') . '>' . $rl . '</option>';
        echo '</select><input type="number" step="0.5" name="new_balance" value="' . esc($u['balance'] ?? 0) . '" data-ui="u-edbe590c d4l d4k dt d2w"><button class="btn sm">حفظ</button></form></td>';
        echo '<td><b>' . number_format((float)($u['balance'] ?? 0), 2) . ' ج.م</b></td>';
        echo '<td dir="ltr">' . esc($u['ref_code'] ?? '—') . '</td>';
        echo '<td dir="ltr">' . esc(substr($u['at'] ?? '', 0, 10)) . '</td>';
        echo '<td data-ui="u-176669be d2m">';
        if (!$is_me_row) {
            echo '<form method="post" class="impersonate-form">' . csrf_field()
               . '<input type="hidden" name="impersonate_email" value="' . esc($u['email'] ?? '') . '">'
               . '<input type="hidden" name="impersonate_from" value="users">'
               . '<button type="submit" class="btn sm pri" data-ui="u-a41a5b62 d4d do" title="الدخول لحساب هذا المستخدم بدون كلمة مرور">' . wf_svg_icon('user', 15) . '<span>دخول لحسابه</span></button></form> ';
        } else {
            echo '<span class="badge neutral">حسابك</span> ';
        }
        echo '<a href="?tab=users&del_user=' . urlencode($u['email'] ?? '') . '" aria-label="حذف المستخدم" data-confirm="حذف المستخدم؟" data-ui="u-9aa26154 d2p"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-trash></use></svg></a></td>';
        echo '</tr>';
    }
    echo '</table></div>';
}

// =========================================================================
// 10. TAB: MONEY (التقارير المالية)
// =========================================================================
elseif ($tab === 'money') {
    $po = jload('payouts.json', []);
    $pout = array_sum(array_map(function ($p) { return ($p['status'] ?? '') !== 'مرفوض' ? (float)($p['amount'] ?? 0) : 0; }, $po));
    $net = $rev - $mout;
    echo '<div class="kpis" data-ui="u-25724057 d0">';
    echo '<div class="kpi"><b>' . number_format($rev) . ' ج.م</b><span>إجمالي المبيعات</span></div>';
    echo '<div class="kpi"><b>' . number_format($mout) . ' ج.م</b><span>عمولات المسوقين</span></div>';
    echo '<div class="kpi"><b>' . number_format($pout) . ' ج.م</b><span>سحوبات المسوقين</span></div>';
    echo '<div class="kpi"><b>' . number_format($net) . ' ج.م</b><span>صافي المبيعات بعد العمولة</span></div>';
    if (function_exists('jmi_stats')) {
        $jmis = jmi_stats();
        echo '<div class="kpi" style="background:#fff7ed;border-color:#fdba74"><b>' . number_format($jmis['site_egp'], 2) . ' ج.م</b><span>🟧 عمولة الموقع من جوميا</span></div>';
        echo '<div class="kpi" style="background:#fff7ed;border-color:#fdba74"><b>' . number_format($jmis['mk_egp'], 2) . ' ج.م</b><span>🟧 عمولة المسوقين من جوميا</span></div>';
        echo '<div class="kpi" style="background:#fff7ed;border-color:#fdba74"><b>' . number_format($jmis['clicks']) . '</b><span>🟧 نقرات روابط جوميا</span></div>';
        echo '<div class="kpi" style="background:#fff7ed;border-color:#fdba74"><a href="?tab=jumia" style="font-size:13px;color:#c2410c;font-weight:900;text-decoration:none">تفاصيل جوميا ←</a><span>🟧 أفلييت جوميا</span></div>';
    }
    if (function_exists('amz_stats')) {
        $amzs = amz_stats();
        echo '<div class="kpi" style="background:#fffaf0;border-color:#fed7aa"><b>' . number_format($amzs['site_egp'], 2) . ' ج.م</b><span>🛍️ عمولة الموقع من أمازون</span></div>';
        echo '<div class="kpi" style="background:#fffaf0;border-color:#fed7aa"><b>' . number_format($amzs['mk_egp'], 2) . ' ج.م</b><span>🛍️ عمولة المسوقين من أمازون</span></div>';
        echo '<div class="kpi" style="background:#fffaf0;border-color:#fed7aa"><b>' . number_format($amzs['clicks']) . '</b><span>🛍️ نقرات روابط أمازون</span></div>';
        echo '<div class="kpi" style="background:#fffaf0;border-color:#fed7aa"><a href="?tab=amazon" style="font-size:13px;color:#b45309;font-weight:900;text-decoration:none">تفاصيل أمازون ←</a><span>🛍️ أفلييت أمازون</span></div>';
    }
    echo '</div>';
}

// =========================================================================
// 11. TAB: REVIEWS (المراجعات)
// =========================================================================
elseif ($tab === 'reviews') {
    $all_r = jload('reviews.json', []);
    echo '<div class="adm-box"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-star></use></svg> مراجعات العملاء (' . count($all_r) . ')</h2>';
    echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>المنتج</th><th>العميل</th><th>التقييم</th><th>التعليق</th><th>التاريخ</th><th>حذف</th></tr>';
    foreach (array_reverse($all_r, true) as $ri => $r) {
        $pinfo = admin_prod_info($r['product_uid'] ?? '');
        echo '<tr>';
        echo '<td><a href="product.php?id=' . urlencode($pinfo['id']) . '" target="_blank" data-ui="u-f14488a7 dc d2k d2l">' . esc(mb_substr($pinfo['name'], 0, 40)) . '</a></td>';
        echo '<td><b>' . esc($r['user_name'] ?? '') . '</b></td>';
        echo '<td data-ui="u-64531203 db">' . str_repeat('', (int)($r['rating'] ?? 5)) . '</td>';
        echo '<td>' . esc($r['comment'] ?? '') . '</td>';
        echo '<td dir="ltr">' . esc(substr($r['at'] ?? '', 0, 10)) . '</td>';
        echo '<td><a href="?tab=reviews&del_review=' . $ri . '" aria-label="حذف المراجعة" data-confirm="حذف المراجعة؟" data-ui="u-9aa26154 d2p"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-trash></use></svg></a></td>';
        echo '</tr>';
    }
    echo '</table></div>';
}

// =========================================================================
// 12. TAB: NOTIFICATIONS (الإشعارات)
// =========================================================================
elseif ($tab === 'notifications') {
    // ===== بث أحدث العروض والأخبار: إشعار داخل الموقع لكل الحسابات + إيميل للمشتركين =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['broadcast_offers'])) {
        if (!csrf_ok()) {
            echo '<div class="adm-box" data-ui="u-9381a74a d15 d5b">⚠️ انتهت صلاحية النموذج — حدّث الصفحة وأعد المحاولة.</div>';
        } else {
            $bc_text = mb_substr(trim((string)($_POST['bc_text'] ?? '')), 0, 300);
            $bc_link = mb_substr(trim((string)($_POST['bc_link'] ?? '')), 0, 200);
            $bc_mail = !empty($_POST['bc_mail']);
            if ($bc_text === '') {
                echo '<div class="adm-box" data-ui="u-9381a74a d15 d5b">اكتب نص العرض أو الخبر أولاً.</div>';
            } else {
                notify('all', $bc_text, $bc_link);
                $bc_sent = 0;
                if ($bc_mail) {
                    $bc_mails = [];
                    foreach ((array)jload('newsletter.json', []) as $nl_row) {
                        if (is_array($nl_row) && !empty($nl_row['email'])) $bc_mails[strtolower((string)$nl_row['email'])] = 1;
                    }
                    foreach ((array)users() as $bu) {
                        if (!is_array($bu) || empty($bu['email'])) continue;
                        if (($bu['role'] ?? '') === 'admin') continue;
                        if (array_key_exists('offers', $bu) && empty($bu['offers'])) continue;
                        $bc_mails[strtolower((string)$bu['email'])] = 1;
                    }
                    $bc_body = BRAND . "\n\n" . $bc_text . ($bc_link !== '' ? "\n\nالتفاصيل: " . $bc_link : "") . "\n\n— لإيقاف استعراض العروض راجع صفحة «حسابي».";
                    foreach (array_slice(array_keys($bc_mails), 0, 60) as $bc_to) {
                        if (@mail($bc_to, 'أحدث العروض والأخبار من ' . BRAND, $bc_body, 'From: no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))) $bc_sent++;
                    }
                }
                log_audit('offers_broadcast', '-', ['chars' => mb_strlen($bc_text), 'mails' => $bc_sent]);
                echo '<div class="adm-box" data-ui="u-9381a74a d15 d5b"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> تم البث: وصل إشعار لكل الحسابات' . ($bc_mail ? (' — ومراسلات البريد: ' . (int)$bc_sent) : '') . '.</div>';
            }
        }
    }
    $ns = jload('notifications.json', []);
    echo '<div class="adm-box"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-bell></use></svg> الإشعارات (' . count($ns) . ')</h2>';
    echo '<form method="post" class="adm-box"><input type="hidden" name="broadcast_offers" value="1">' . csrf_field();
    echo '<h2>بث عرض أو خبر جديد</h2><p class="mut">يظهر كإشعار داخل الموقع لكل الحسابات (عملاء ومسوّقين)، ويوصل بالبريد للمشتركين في النشرة ولحسابات العملاء اللي معاهم خيار العروض مفعّل.</p>';
    echo '<label>نص العرض/الخبر<br><textarea name="bc_text" rows="2" placeholder="مثال: خصم ١٥٪ على السماعات اللاسلكية لفترة محدودة — الدفع عند الاستلام"></textarea></label>';
    echo '<label>رابط (اختياري)<br><input type="text" name="bc_link" placeholder="index.php?cat=all#products" dir="ltr"></label>';
    echo '<label style="display:inline-flex;gap:6px;align-items:center"><input type="checkbox" name="bc_mail" value="1"> إرسال بالبريد أيضاً (حتى ٦٠ مشترك)</label> ';
    echo '<button class="btn pri" data-confirm="سيصل البث لكل الحسابات المفعّلة. تأكيد؟">بث العروض والأخبار</button></form>';
    echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>المستلم</th><th>الرسالة</th><th>التاريخ</th><th>حذف</th></tr>';
    foreach (array_reverse($ns, true) as $ni => $n) {
        echo '<tr><td>' . esc($n['for'] ?? '') . '</td><td>' . esc($n['text'] ?? '') . '</td><td dir="ltr">' . esc(substr($n['at'] ?? '', 0, 16)) . '</td><td><a aria-label="حذف الإشعار" data-confirm="سيُحذف هذا الإشعار. لا يمكن التراجع من الواجهة. تأكيد الحذف؟" href="?tab=notifications&del_notif=' . $ni . '" data-ui="u-9aa26154 d2p"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-trash></use></svg></a></td></tr>';
    }
    echo '</table></div>';
}

// =========================================================================
// 13. TAB: PRODUCT REQUESTS (طلبات توفير المنتجات بالاسم والصورة + شات)
// =========================================================================
elseif ($tab === 'requests') {
    $st_badges = [
        'pending'     => ['⏳ قيد المراجعة', 'var(--amber-100)', 'var(--amber-600)'],
        'sourcing'    => [' جاري التوفير', '#dbeafe', 'var(--electro-blue)'],
        'available'   => [' تم التوفير', '#dcfce7', 'var(--tone-15803d)'],
        'unavailable' => [' غير متوفر', '#fee2e2', 'var(--red-700)'],
    ];
    $rf = $_GET['st'] ?? '';
    $list_r = array_reverse($product_requests, true);
    if ($rf !== '') {
        $list_r = array_filter($list_r, function ($r) use ($rf) { return ($r['status'] ?? 'pending') === $rf; });
    }
    echo '<div class="kpis" data-ui="u-25724057 d0">';
    echo '<div class="kpi" data-ui="u-97b96627 d5"><b>' . count($product_requests) . '</b><span>إجمالي طلبات توفير المنتجات</span></div>';

    echo '<div class="kpi" data-ui="u-970e92a7 d2x"><b>' . count($open_prod_reqs) . '</b><span>طلبات مفتوحة تحتاج متابعة</span></div>';
    echo '<div class="kpi" data-ui="u-bc691366 d6"><b>' . count(array_filter($product_requests, function ($r) { return ($r['status'] ?? '') === 'sourcing'; })) . '</b><span>جاري توفيرها حالياً </span></div>';
    echo '<div class="kpi" data-ui="u-3824e45f d4"><b>' . count(array_filter($product_requests, function ($r) { return ($r['status'] ?? '') === 'available'; })) . '</b><span>تم توفيرها بنجاح </span></div>';
    echo '</div>';

    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-camera></use></svg> طلبات توفير المنتجات من المسوقين (بالاسم والصورة)</span>';
    echo '<a href="product_requests.php?ctx=marketer" class="btn sm" data-ui="u-6a2602f0 d4m d3v"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chat></use></svg> فتح الشات المباشر</a></h2>';

    echo '<div data-ui="u-2f8e8373 dh di dj d1r">';
    echo '<a class="btn sm ' . ($rf === '' ? '' : 'outline') . '" href="?tab=requests">الكل (' . count($product_requests) . ')</a>';
    foreach ($st_badges as $sk => $sb) {
        $c = count(array_filter($product_requests, function ($r) use ($sk) { return ($r['status'] ?? 'pending') === $sk; }));
        echo '<a class="btn sm ' . ($rf === $sk ? '' : 'outline') . '" href="?tab=requests&st=' . $sk . '">' . wf_svg_icon('clock',16) . esc(wf_ui_plain($sb[0])) . ' (' . $c . ')</a>';
    }
    echo '</div>';

    if ($list_r) {
        echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>صورة المنتج</th><th>اسم المنتج المطلوب</th><th>المسوق صاحب الطلب</th><th>السعر المتوقع</th><th>التفاصيل</th><th>الحالة</th><th>عدد الرسائل</th><th>آخر تحديث</th><th>دخول الشات</th></tr>';
        foreach ($list_r as $rid => $r) {
            $sb = $st_badges[$r['status'] ?? 'pending'] ?? $st_badges['pending'];
            $img_u = !empty($r['product_image']) ? asset_url($r['product_image']) : '';
            $unr = (int)($r['unread_admin'] ?? 0);
            echo '<tr>';
            echo '<td>' . ($img_u ? '<img alt="صورة المنتج" src="' . esc($img_u) . '" data-ui="u-d7178018 d4n d4o d2t d24 d3j" onclick="window.open(this.src,\'_blank\')">' : '') . '</td>';
            echo '<td><b>' . esc($r['product_name'] ?? '') . '</b><br><span dir="ltr" class="mut">#' . esc($rid) . '</span></td>';
            echo '<td><b>' . esc($r['user_name'] ?? '') . '</b><br><span dir="ltr" class="mut">' . esc($r['user_email'] ?? '') . '</span>' . (!empty($r['user_ref']) ? '<br><span class="badge blue">' . esc($r['user_ref']) . '</span>' : '') . '</td>';
            echo '<td>' . (!empty($r['expected_price']) ? '<b data-ui="u-61a4e8ed d1x">' . esc($r['expected_price']) . ' ج.م</b>' : '—') . '</td>';
            echo '<td data-ui="u-5bb593c0 d4p">' . wf_det_text($r['notes'] ?? '', 70, 'تفاصيل طلب التوفير #' . $rid, 'التفاصيل الكاملة') . '</td>';
            echo '<td><span style="padding:3px 10px;border-radius:99px;font-size:12px;font-weight:800;background:' . $sb[1] . ';color:' . $sb[2] . '">' . wf_svg_icon('clock',16) . esc(wf_ui_plain($sb[0])) . '</span></td>';
            echo '<td><b>' . count($r['messages'] ?? []) . '</b> رسالة' . ($unr > 0 ? ' <span class="badge neutral">' . $unr . ' جديد</span>' : '') . '</td>';
            echo '<td dir="ltr">' . esc(substr($r['updated_at'] ?? '', 0, 16)) . '</td>';
            echo '<td><a href="product_requests.php?ctx=marketer&amp;req_id=' . urlencode($rid) . '" class="btn sm" data-ui="u-616576cb d4q d3x"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chat></use></svg> فتح الشات</a></td>';
            echo '</tr>';
        }
        echo '</table></div>';
    } else {
        echo '<p class="mut">لا توجد طلبات توفير منتجات حتى الآن. يمكن للمسوقين إرسال الطلبات من صفحة "طلب منتج" بالاسم والصورة.</p>';
    }
    echo '</div>';
}

// =========================================================================
// 14. TAB: CHATS (إدارة الشات المباشر مع المسوقين)
// =========================================================================
elseif ($tab === 'chats') {
    $sorted_sup = $support_chats;
    uasort($sorted_sup, function ($a, $b) { return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''); });
    $total_unread = 0;
    foreach ($sorted_sup as $th) $total_unread += (int)($th['unread_admin'] ?? 0);

    echo '<div class="kpis" data-ui="u-25724057 d0">';
    echo '<div class="kpi" data-ui="u-bc691366 d6"><b>' . count($support_chats) . '</b><span>إجمالي محادثات الدعم</span></div>';
    echo '<div class="kpi" data-ui="u-970e92a7 d2x"><b>' . $total_unread . '</b><span>رسائل غير مقروءة من المسوقين </span></div>';
    echo '<div class="kpi" data-ui="u-3824e45f d4"><b>' . count($product_requests) . '</b><span>شاتات طلبات المنتجات (بالصورة)</span></div>';
    echo '</div>';

    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chat></use></svg> محادثات الشات المباشر مع المسوقين</span><a href="support.php" class="btn sm" data-ui="u-6a2602f0 d4m d3v">فتح الشات ↗</a></h2>';
    if ($sorted_sup) {
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>المسوق</th><th>كود الإحالة</th><th>آخر رسالة</th><th>عدد الرسائل</th><th>غير مقروء</th><th>آخر نشاط</th><th>دخول المحادثة</th></tr>';
        foreach ($sorted_sup as $tk => $th) {
            $msgs = $th['messages'] ?? [];
            $last = end($msgs);
            $unr = (int)($th['unread_admin'] ?? 0);
            echo '<tr>';
            echo '<td><b>' . esc($th['user_name'] ?? $tk) . '</b><br><span dir="ltr" class="mut">' . esc($th['user_email'] ?? '') . '</span></td>';
            echo '<td>' . (!empty($th['user_ref']) ? '<span class="badge blue">' . esc($th['user_ref']) . '</span>' : '—') . '</td>';
            echo '<td data-ui="u-0f90dee3 d4r">' . esc(mb_substr($last['text'] ?? (!empty($last['image']) ? ' صورة مرفقة' : ''), 0, 60)) . '</td>';
            echo '<td><b>' . count($msgs) . '</b></td>';
            echo '<td>' . ($unr > 0 ? '<span class="badge neutral">' . $unr . '</span>' : '—') . '</td>';
            echo '<td dir="ltr">' . esc(substr($th['updated_at'] ?? '', 0, 16)) . '</td>';
            echo '<td><a href="support.php?thread=' . urlencode($tk) . '" class="btn sm" data-ui="u-616576cb d4q d3x"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chat></use></svg> رد الآن</a></td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="mut">لا توجد محادثات بعد.</p>';
    }
    echo '</div>';
}

// =========================================================================
// 16. TAB: COMMISSIONS (العمولات والمدفوعات: pending → confirmed → paid)
// =========================================================================
elseif ($tab === 'commissions') {
    $comm_rows = [];
    foreach ($marketers as $u) {
        $mo = array_filter($orders, function ($o) use ($u) { return ($o['ref'] ?? '') === ($u['ref_code'] ?? ''); });
        $mc = array_sum(array_map(function ($o) { return (float)($o['m_earn'] ?? 0); }, $mo));
        if ($mc <= 0 && (float)($u['balance'] ?? 0) <= 0) continue;
        $st_comm = $u['comm_status'] ?? 'pending';
        $comm_rows[] = [
            'name' => $u['name'] ?? '', 'email' => $u['email'] ?? '', 'ref' => $u['ref_code'] ?? '',
            'orders' => count($mo), 'total' => $mc, 'balance' => (float)($u['balance'] ?? 0),
            'pending_bal' => (float)($u['pending_balance'] ?? 0), 'status' => $st_comm,
            'custom_pct' => (float)($u['custom_comm_pct'] ?? 0),
        ];
    }
    usort($comm_rows, function ($a, $b) { return $b['total'] <=> $a['total']; });

    $tot_pending = array_sum(array_map(function ($r) { return $r['status'] === 'pending' ? $r['total'] : 0; }, $comm_rows));
    $tot_conf = array_sum(array_map(function ($r) { return $r['status'] === 'confirmed' ? $r['total'] : 0; }, $comm_rows));
    $tot_paid = array_sum(array_map(function ($r) { return $r['status'] === 'paid' ? $r['total'] : 0; }, $comm_rows));

    echo '<div class="kpis" data-ui="u-25724057 d0">';
    echo '<div class="kpi" data-ui="u-97b96627 d5"><b>' . number_format($tot_pending, 2) . ' ج.م</b><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-clock></use></svg> عمولات معلقة (Pending)</span></div>';
    echo '<div class="kpi" data-ui="u-bc691366 d6"><b>' . number_format($tot_conf, 2) . ' ج.م</b><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> عمولات مؤكدة (Confirmed)</span></div>';
    echo '<div class="kpi" data-ui="u-3824e45f d4"><b>' . number_format($tot_paid, 2) . ' ج.م</b><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> عمولات مدفوعة (Paid)</span></div>';
    echo '<div class="kpi" data-ui="u-85fe13fb d2q"><b>' . number_format($tot_pending + $tot_conf + $tot_paid, 2) . ' ج.م</b><span>إجمالي العمولات</span></div>';
    echo '</div>';

    echo '<div class="adm-box"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> إدارة عمولات المسوقين (الحالة والدفع)</h2>';
    if ($comm_rows) {
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>المسوق</th><th>كود الإحالة</th><th>الطلبات</th><th>إجمالي العمولة</th><th>الرصيد المتاح</th><th>حالة العمولة</th><th>تغيير الحالة</th></tr>';
        foreach ($comm_rows as $cr) {
            echo '<tr>';
            echo '<td><b>' . esc($cr['name']) . '</b><br><span dir="ltr" class="mut">' . esc($cr['email']) . '</span></td>';
            echo '<td><span class="badge blue">' . esc($cr['ref']) . '</span></td>';
            echo '<td><b>' . $cr['orders'] . '</b></td>';
            echo '<td><b data-ui="u-61a4e8ed d1x">' . number_format($cr['total'], 2) . ' ج.م</b></td>';
            echo '<td><b>' . number_format($cr['balance'], 2) . ' ج.م</b></td>';
            $cst_badges = ['pending' => ['⏳ معلقة', 'var(--amber-100)', 'var(--amber-600)'], 'confirmed' => [' مؤكدة', '#dbeafe', 'var(--electro-blue)'], 'paid' => [' مدفوعة', '#dcfce7', 'var(--tone-15803d)']];
            $cb = $cst_badges[$cr['status']] ?? $cst_badges['pending'];
            echo '<td><span style="padding:3px 10px;border-radius:99px;font-size:12px;font-weight:800;background:' . $cb[1] . ';color:' . $cb[2] . '">' . wf_svg_icon('clock',16) . esc(wf_ui_plain($cb[0])) . '</span></td>';
            echo '<td><form data-confirm="سيتم تسجيل حالة العمولة المختارة لهذا المسوق. تأكيد التغيير؟" method="post" data-ui="u-fd0a8ea7 d3e di d1p d19"><input type="hidden" name="comm_email" value="' . esc($cr['email']) . '"><select name="comm_new_status" data-ui="u-3173ae86 d39 d1u dt d1v do dm"><option value="pending"' . ($cr['status'] === 'pending' ? ' selected' : '') . '> معلقة</option><option value="confirmed"' . ($cr['status'] === 'confirmed' ? ' selected' : '') . '> مؤكدة</option><option value="paid"' . ($cr['status'] === 'paid' ? ' selected' : '') . '> مدفوعة</option></select><button class="btn sm">حفظ</button></form></td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="mut">لا توجد عمولات مسجلة للمسوقين حتى الآن.</p>';
    }
    echo '</div>';

    // طلبات السحب مع تسجيل رقم التحويل
    $po_all = jload('payouts.json', []);
    if ($po_all) {
        // إحصائيات سريعة حسب وسيلة السحب
        $by_method = [];
        foreach ($po_all as $pp) {
            $mk = $pp['method'] ?? 'other';
            if (!isset($by_method[$mk])) $by_method[$mk] = ['n' => 0, 'sum' => 0, 'pending' => 0];
            $by_method[$mk]['n']++;
            $by_method[$mk]['sum'] += (float)($pp['amount'] ?? 0);
            if (($pp['status'] ?? '') === 'قيد المراجعة') $by_method[$mk]['pending'] += (float)($pp['amount'] ?? 0);
        }
        echo '<div class="adm-box" data-ui="u-d7ab73d7 dl"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> ملخص السحوبات حسب الوسيلة</h2>';
        echo '<div data-ui="u-e762abd9 d21 d4t d1q">';
        foreach ($by_method as $mk => $mv) {
            echo '<div data-ui="u-e0e8310e dt da dg df d4u">';
            echo '<div data-ui="u-fb0dc1fd d4v">' . payout_method_icon($mk) . '</div>';
            echo '<div data-ui="u-b1f95570 d4w d1 d4x">' . esc(wf_ui_plain(payout_method_label($mk))) . '</div>';
            echo '<div data-ui="u-f5904cca d26 d1 d4y d3i">' . number_format($mv['sum'], 2) . ' ج.م</div>';
            echo '<div data-ui="u-7acc46dc do dn">' . $mv['n'] . ' طلب';
            if ($mv['pending'] > 0) echo ' • <b data-ui="u-f1fe78ea d3r">' . number_format($mv['pending'], 2) . ' معلقة</b>';
            echo '</div></div>';
        }
        echo '</div></div>';

        echo '<div class="adm-box" data-ui="u-d7ab73d7 dl"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> كل طلبات سحب الأرباح (تسجيل رقم التحويل)</h2>';
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>المسوق</th><th>المبلغ</th><th>وسيلة السحب</th><th>بيانات الحساب</th><th>ملاحظات</th><th>الحالة</th><th>رقم التحويل</th><th>التاريخ</th><th>إجراء</th></tr>';
        foreach (array_reverse($po_all, true) as $pi => $p) {
            $st = $p['status'] ?? 'قيد المراجعة';
            $stc = $st === 'موافق عليه' ? ['#dcfce7','var(--tone-15803d)'] : ($st === 'مرفوض' ? ['#fee2e2','var(--red-700)'] : ['var(--amber-100)','var(--amber-600)']);
            echo '<tr>';
            echo '<td><b>' . esc($p['name'] ?: $p['user'] ?? '') . '</b><br><span dir="ltr" class="mut">' . esc($p['user'] ?? '') . '</span></td>';
            echo '<td><b data-ui="u-5d7ebccb du">' . number_format((float)($p['amount'] ?? 0), 2) . ' ج.م</b></td>';
            echo '<td><span data-ui="u-f85aae59 d4z d36 do dc d50 d3s">' . esc(payout_method_icon($p['method'] ?? '') . ' ' . payout_method_label($p['method'] ?? '')) . '</span></td>';
            echo '<td dir="ltr"><b>' . esc($p['account_number'] ?? '—') . '</b></td>';
            echo '<td data-ui="u-1839f8cc d51 do">' . wf_det_text($p['notes'] ?? '', 40, 'ملاحظات طلب السحب — ' . (string)($p['name'] ?: ($p['user'] ?? '')), 'الملاحظات') . '</td>';
            echo '<td><span style="padding:3px 9px;border-radius:99px;font-size:12px;font-weight:800;background:' . $stc[0] . ';color:' . $stc[1] . '">' . esc($st) . '</span>'
               . ($st === 'مرفوض' && trim((string)($p['reject_reason'] ?? '')) !== '' ? '<br>' . wf_details_btn('سبب رفض طلب السحب — ' . (string)($p['name'] ?: ($p['user'] ?? '')), [['المبلغ', number_format((float)($p['amount'] ?? 0), 2) . ' ج.م'], ['السبب', (string)($p['reject_reason'] ?? '')], ['وقت القرار', substr((string)($p['processed_at'] ?? ''), 0, 16)]], 'سبب الرفض', 'wf-det-danger') : '')
               . '</td>';
            echo '<td dir="ltr">' . esc($p['transfer_ref'] ?? '—') . '</td>';
            echo '<td dir="ltr">' . esc(substr($p['at'] ?? '', 0, 16)) . '</td>';
            echo '<td>';
            if ($st === 'قيد المراجعة') {
                echo '<form data-confirm="سيُعتمد طلب السحب ويُسجل رقم التحويل الذي أدخلته. تأكد من المبلغ والحساب ومرجع التحويل قبل المتابعة." method="post" data-ui="u-769bdaf4 d3e d4g d19 d1p">'
                   . '<input type="hidden" name="payout_idx" value="' . (int)$pi . '">'
                   . '<input name="payout_transfer" placeholder="رقم التحويل..." data-ui="u-c3ef82dd d52 d39 dt d1u do">'
                   . '<button class="btn sm" data-ui="u-803b951b d4e d3v d4i do"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> اعتماد</button></form> ';
                echo '<form method="post" data-ui="u-dd31d1d6 d3a d19" data-confirm="رفض طلب السحب ورجوع المبلغ لرصيد المسوق؟">' . csrf_field() . '<input type="hidden" name="payout_reject" value="' . (int)$pi . '"><input name="payout_reject_reason" maxlength="300" placeholder="سبب الرفض (يظهر للمسوق)" data-ui="u-452d5a05 d3f d59 do dt d2w"><button class="btn sm outline" data-ui="u-21e1823e d4i do d53"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-close></use></svg> رفض</button></form>';
            } else {
                echo '<span class="mut">—</span>';
            }
            echo '</td></tr>';
        }
        echo '</table></div>';
    }
}

// =========================================================================
// 17. TAB: AUDIT LOG (سجل النشاط الحساس)
// =========================================================================
elseif ($tab === 'payments') {
    $f = $_GET['f'] ?? 'proof_uploaded';
    echo '<div class="adm-box"><h2><span><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> مراجعة مدفوعات السوشيال والرقمي</span></h2><div data-ui="u-e898721c dh di dj d54">';
    foreach (['proof_uploaded' => '⏳ إيصالات بانتظار المراجعة', 'awaiting' => ' لم يدفع بعد', 'confirmed' => ' مؤكدة', 'rejected' => ' مرفوضة', 'all' => 'الكل'] as $fk => $fl) echo '<a class="btn sm' . ($f === $fk ? ' pri' : ' outline') . '" href="?tab=payments&f=' . $fk . '">' . $fl . '</a>';
    echo '</div>';
    if (!pay_channels()) echo '<div data-ui="u-4edc7f2c d8 d9 d24 d3d d54 du"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-warning></use></svg> لسه محددتش حسابات الدفع — روح <a href="?tab=settings"><b>الإعدادات</b></a> واكتب انستا باي / أرقام المحافظ عشان صفحة الدفع تظهر للعميل.</div>';
    $rows = [];
    foreach ($orders as $i => $o) { if (!in_array($o['plat'] ?? '', ['social', 'g2g'], true) && empty($o['payment_status'])) continue; $ps = $o['payment_status'] ?? 'cod'; if ($f !== 'all' && $ps !== $f) continue; $rows[$i] = $o; }
    if (!$rows) echo '<p class="mut">لا توجد طلبات في هذه الفئة.</p>';
    else {
        echo '<div data-ui="u-1ae67359 d13"><table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-41d6c862 d4w"><tr><th>الطلب</th><th>العميل</th><th>الخدمة</th><th>المبلغ</th><th>الرابط/البيانات</th><th>الإيصال</th><th>حوّل من</th><th>الحالة</th><th>إجراء</th></tr>';
        foreach (array_reverse($rows, true) as $i => $o) {
            $ps = $o['payment_status'] ?? 'cod'; $pay = $o['payment'] ?? [];
            $badge = ['proof_uploaded' => ['var(--amber-100)', '⏳ بانتظار المراجعة'], 'awaiting' => ['var(--slate-200)', ' لم يدفع'], 'confirmed' => ['#dcfce7', ' مؤكد'], 'rejected' => ['#fee2e2', ' مرفوض'], 'cod' => ['var(--slate-200)', 'عند التواصل']][$ps] ?? ['var(--slate-200)', $ps];
            echo '<tr><td dir="ltr" data-ui="u-c1d6ed15 dc do">' . esc($o['order_no'] ?? '#' . $i) . '<br><span class="mut">' . esc(substr($o['at'] ?? '', 0, 16)) . '</span></td>';
            echo '<td>' . esc($o['cname'] ?? '') . '<br><a dir="ltr" href="' . esc(wa_link($o['cphone'] ?? '')) . '" target="_blank" data-ui="u-5d985ec3 do">' . esc($o['cphone'] ?? '') . ' </a></td>';
            echo '<td>' . esc(mb_substr($o['pname'] ?? '', 0, 45)) . '<br><span class="mut">' . esc(order_variant_label($o)) . ' × ' . (int)($o['qty'] ?? 1) . '</span></td>';
            echo '<td><b>' . number_format((float)($o['total'] ?? 0), 2) . '</b></td>';
            echo '<td dir="ltr" data-ui="u-5ddbf195 d55 d56 do">' . wf_det_text($o['link'] ?? $o['notes'] ?? '', 60, 'رابط/بيانات خدمة الطلب ' . ($o['order_no'] ?? ''), 'البيانات كاملة') . '</td>';
            echo '<td>' . (!empty($pay['proof']) ? '<a href="' . esc(asset_url($pay['proof'])) . '" target="_blank"><img alt="إيصال تحويل الطلب" loading="lazy" width="64" height="64" src="' . esc(asset_url($pay['proof'])) . '" data-ui="u-87746886 d57 d58 d2t d1u dt"></a>' : '<span class="mut">—</span>') . '</td>';
            echo '<td data-ui="u-5d985ec3 do">' . esc(pay_channels()[$pay['channel'] ?? '']['label'] ?? ($pay['channel'] ?? '')) . '<br><span dir="ltr">' . esc($pay['sender'] ?? '') . '</span></td>';
            echo '<td><span style="background:' . $badge[0] . ';padding:3px 8px;border-radius:99px;font-size:12px;font-weight:800;white-space:nowrap">' . $badge[1] . '</span>' . (!empty($o['hevo_order']) ? '<br><span class="mut" dir="ltr">#' . esc($o['hevo_order']) . '</span>' : '') . '</td>';
            echo '<td data-ui="u-176669be d2m">';
            if ($ps === 'proof_uploaded') {
                echo '<form data-confirm="سيتم تسجيل قرار مراجعة الإيصال؛ تأكيد الدفع قد يبدأ التنفيذ لدى المصدر. راجع المبلغ والإيصال قبل المتابعة." method="post" data-ui="u-dd31d1d6 d3a d19">' . csrf_field() . '<input type="hidden" name="pay_confirm_idx" value="' . $i . '"><input type="hidden" name="pay_action" value="confirm"><button class="btn sm" data-ui="u-95d4f982 d4e d3v do" data-confirm="تأكدت من الإيصال والمبلغ؟ تأكيد الدفع وبدء التنفيذ؟"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> تأكيد بعد المراجعة</button></form> ';
                echo '<form data-confirm="سيتم تسجيل قرار مراجعة الإيصال؛ تأكيد الدفع قد يبدأ التنفيذ لدى المصدر. راجع المبلغ والإيصال قبل المتابعة." method="post" data-ui="u-dd31d1d6 d3a d19">' . csrf_field() . '<input type="hidden" name="pay_confirm_idx" value="' . $i . '"><input type="hidden" name="pay_action" value="reject"><input name="pay_reason" placeholder="سبب الرفض" data-ui="u-452d5a05 d3f d59 do dt d2w"> <button class="btn sm outline" data-ui="u-1f1a952d d53 do"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-close></use></svg> رفض</button></form>';
            } elseif ($ps === 'confirmed') {
                echo '<span class="mut" data-ui="u-5d985ec3 do">' . esc(substr($o['payment_confirmed_at'] ?? '', 0, 16)) . '</span>';
            } elseif ($ps === 'awaiting') {
                echo '<span class="mut" data-ui="u-5d985ec3 do">بانتظار الدفع والإيصال</span>';
            } elseif ($ps === 'rejected') {
                echo '<span class="mut" data-ui="u-5d985ec3 do">بانتظار إعادة رفع الإيصال</span>';
                // سبب رفض الإيصال كان بيتسجل وما بيظهرش في أي مكان — بقى ظاهر هنا وفي ديالوج
                if (!empty($o['payment_reject_reason'])) echo '<br>' . wf_det_text($o['payment_reject_reason'], 30, 'سبب رفض إيصال الطلب ' . ($o['order_no'] ?? ''), 'سبب الرفض');
            } else {
                echo '<span class="mut" data-ui="u-5d985ec3 do">لا يوجد إيصال للمراجعة</span>';
            }
            echo '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
}
elseif ($tab === 'audit') {
    echo '<div class="adm-box"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> سجل النشاط الحساس (Audit Log)</h2>';
    echo '<p class="mut" data-ui="u-27610b64 do d1r">يتم تسجيل كل تعديل حساس تلقائياً: تغيير عمولة، اعتماد سحب بأموال، تغيير حالة مسوق، أو معالجة تنبيه غش.</p>';
    if ($audit_logs) {
        echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true" data-ui="u-5d985ec3 do"><tr><th>التاريخ والوقت</th><th>المسؤول</th><th>الدور</th><th>العملية</th><th>الهدف</th><th>التفاصيل</th><th>IP</th></tr>';
        foreach (array_reverse($audit_logs) as $lg) {
            echo '<tr>';
            echo '<td dir="ltr">' . esc(substr($lg['at'] ?? '', 0, 19)) . '</td>';
            echo '<td dir="ltr"><b>' . esc($lg['actor_email'] ?? '') . '</b></td>';
            echo '<td><span class="badge">' . esc($lg['actor_role'] ?? 'admin') . '</span></td>';
            echo '<td dir="ltr">' . esc($lg['action'] ?? '') . '</td>';
            echo '<td dir="ltr">' . esc($lg['target'] ?? '') . '</td>';
            echo '<td data-ui="u-0b090b68 d5a">' . wf_det_text($lg['details'] ?? '', 90, 'تفاصيل العملية: ' . (string)($lg['action'] ?? ''), 'التفاصيل الكاملة') . '</td>';
            echo '<td dir="ltr" class="mut">' . esc($lg['ip'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="mut">لا توجد عمليات مسجلة بعد.</p>';
    }
    echo '</div>';
}

elseif ($tab === 'noon' && function_exists('noon_stats_for')) {
    // ======================== 🟡 أفلييت نون (أكواد الكوبون) ========================
    $nmsg = ''; $nerr = false;
    $n_admin = (string)(current_user()['email'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nact = (string)($_POST['noon_act'] ?? '');
        if ($nact === 'product_approve' || $nact === 'product_reject') {
            $nr = noon_product_decide((string)($_POST['product_id'] ?? ''), $nact === 'product_approve', (string)($_POST['category'] ?? ''), (string)($_POST['pname'] ?? ''), (string)($_POST['price_aed'] ?? ''), (string)($_POST['mk_pct'] ?? ''), (string)($_POST['note'] ?? ''), $n_admin);
            if (!empty($nr['ok'])) {
                $pr = $nr['rec'];
                log_audit('noon_product_' . ($nact === 'product_approve' ? 'approve' : 'reject'), (string)$pr['id'], ['mk' => $pr['email'], 'pct' => $pr['mk_pct']]);
                if ($pr['status'] === 'approved') {
                    $pc = noon_product_calc($pr);
                    notify_user($pr['email'], '🛒 اتعتمد المنتج ' . $pr['pname'] . ': هتاخد ' . number_format($pc['egp'], 2) . ' ج.م على كل بيعة', 'dashboard/noon.php?tab=products');
                    $nmsg = '✅ اتعتمد المنتج';
                } else {
                    notify_user($pr['email'], '⛔ طلب المنتج اترفض' . ($pr['reject_note'] !== '' ? ': ' . $pr['reject_note'] : ''), 'dashboard/noon.php?tab=products');
                    $nmsg = '⛔ اترفض المنتج';
                }
            } else { $nmsg = '⚠️ ' . $nr['error']; $nerr = true; }
        }
        if ($nact === 'approve' || $nact === 'reject') {
            $nr = noon_code_decide((string)($_POST['code_id'] ?? ''), $nact === 'approve', (string)($_POST['share'] ?? ''), (string)($_POST['note'] ?? ''), $n_admin);
            if (!empty($nr['ok'])) {
                $nrc = $nr['rec'];
                log_audit('noon_code_' . $nact, (string)$nrc['id'], ['code' => $nrc['code'], 'mk' => $nrc['email'], 'share' => $nrc['share']]);
                if ($nact === 'approve') {
                    $nmsg = '✅ اتعتمد الكود ' . $nrc['code'] . ' ونسبة المسوق ' . $nrc['share'] . '%';
                    notify_user($nrc['email'], '✅ اتعتمد كود نون بتاعك: ' . $nrc['code'], 'dashboard/noon.php');
                } else {
                    $nmsg = '⛔ اتوقف/اترفض الكود ' . $nrc['code'];
                    notify_user($nrc['email'], '⛔ كود نون بتاعك اتوقف' . ($nrc['reject_note'] !== '' ? ': ' . $nrc['reject_note'] : ''), 'dashboard/noon.php');
                }
            } else { $nmsg = '⚠️ ' . $nr['error']; $nerr = true; }
        } elseif ($nact === 'appeal_approve' || $nact === 'appeal_reject') {
            $nr = noon_appeal_decide((string)($_POST['code_id'] ?? ''), (string)($_POST['appeal_id'] ?? ''), $nact === 'appeal_approve', (string)($_POST['share'] ?? ''), (string)($_POST['note'] ?? ''), $n_admin);
            $nrc = $nr['rec'] ?? null;
            if (!empty($nr['ok']) && $nrc) {
                log_audit('noon_appeal_' . ($nact === 'appeal_approve' ? 'approve' : 'reject'), (string)($_POST['appeal_id'] ?? ''), ['code' => $nrc['code'], 'mk' => $nrc['email'], 'share' => $nrc['share']]);
                if ($nact === 'appeal_approve') {
                    $nmsg = '✅ اتعدّلت نسبة الكود ' . $nrc['code'] . ' إلى ' . $nrc['share'] . '% (للبيعات الجاية بس)';
                    notify_user($nrc['email'], '📈 اتعدّل تظلمك على كود نون ' . $nrc['code'] . ' — الإدارة وافقت', 'dashboard/noon.php');
                } else {
                    $nmsg = '⛔ اترفض تظلم الكود ' . $nrc['code'];
                    notify_user($nrc['email'], '⛔ التظلم على كود نون ' . $nrc['code'] . ' اترفض' . ((string)($nr['appeal']['admin_note'] ?? '') !== '' ? ': ' . $nr['appeal']['admin_note'] : ''), 'dashboard/noon.php');
                }
            } else { $nmsg = '⚠️ ' . ($nr['error'] ?? 'تعذر تنفيذ القرار'); $nerr = true; }
        } elseif ($nact === 'code_delete') {
            $nr = noon_code_admin_delete((string)($_POST['code_id'] ?? ''), (string)($_POST['note'] ?? ''), $n_admin);
            $nerr = empty($nr['ok']);
            if (!$nerr) {
                log_audit('noon_code_delete', (string)($_POST['code_id'] ?? ''), ['code' => $nr['rec']['code'], 'mk' => $nr['rec']['email']]);
                $nmsg = '🗑️ اتحذف الكود ' . $nr['rec']['code'] . ' والمسوق اتبلغ';
            } else {
                $nmsg = '⚠️ ' . $nr['error'];
            }
        } elseif ($nact === 'share') {
            $nr = noon_code_set_share((string)($_POST['code_id'] ?? ''), (string)($_POST['share'] ?? ''));
            $nerr = empty($nr['ok']);
            $nmsg = $nerr ? '⚠️ ' . $nr['error'] : '✅ اتحدّثت النسبة (للبيعات الجاية بس)';
        } elseif ($nact === 'sale_add') {
            $nr = noon_sale_add((string)($_POST['code_id'] ?? ''), (string)($_POST['order_no'] ?? ''), (string)($_POST['sale'] ?? '0'), (string)($_POST['noon_comm'] ?? '0'), (string)($_POST['at'] ?? ''), $n_admin, (string)($_POST['product_id'] ?? ''), (int)($_POST['qty'] ?? 1));
            if (!empty($nr['ok'])) {
                log_audit('noon_sale_add', (string)$nr['rec']['id'], ['code' => $nr['rec']['code'], 'order' => $nr['rec']['order_no'], 'mk' => $nr['rec']['mk_egp']]);
                $nmsg = '✅ اتسجلت البيعة — عمولة المسوق ' . number_format((float)$nr['rec']['mk_egp'], 2) . ' ج.م (معلقة)';
            } else { $nmsg = '⚠️ ' . $nr['error']; $nerr = true; }
        } elseif ($nact === 'sale_confirm') {
            $nr = noon_sale_confirm((string)($_POST['sale_id'] ?? ''));
            if (!empty($nr['ok'])) {
                log_audit('noon_sale_confirm', (string)($_POST['sale_id'] ?? ''), ['mk' => $nr['rec']['mk_egp']]);
                $nmsg = '✅ اتأكدت العمولة وانتقلت لرصيد المسوق المتاح';
            } else { $nmsg = '⚠️ ' . $nr['error']; $nerr = true; }
        } elseif ($nact === 'sale_reverse') {
            $nr = noon_sale_reverse((string)($_POST['sale_id'] ?? ''), (string)($_POST['reason'] ?? ''));
            if (!empty($nr['ok'])) {
                log_audit('noon_sale_reverse', (string)($_POST['sale_id'] ?? ''), ['mk' => $nr['rec']['mk_egp']]);
                $nmsg = '↩️ اتلغت البيعة وخصمت عمولتها من المسوق';
            } else { $nmsg = '⚠️ ' . $nr['error']; $nerr = true; }
        }
    }

    $n_codes = noon_codes_all();
    $n_pend = []; $n_appr = [];
    foreach ($n_codes as $nc) {
        if (($nc['status'] ?? '') === 'pending') $n_pend[] = $nc;
        elseif (($nc['status'] ?? '') === 'approved') $n_appr[] = $nc;
    }
    $n_sales = noon_sales_all();
    usort($n_sales, function ($a, $b) { return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')); });
    $n_uses_all = 0; $n_profit_all = 0.0; $n_noon_all = 0.0;
    foreach ($n_sales as $ns) {
        if (($ns['state'] ?? '') === 'reversed') continue;
        $n_uses_all++;
        $n_profit_all += (float)($ns['mk_egp'] ?? 0);
        $n_noon_all += (float)($ns['noon_comm'] ?? 0);
    }
    $n_state_lbl = ['pending' => 'معلقة', 'confirmed' => 'مؤكدة', 'reversed' => 'ملغاة'];

    echo '<div class="card"><h2>🟡 أفلييت نون — كود الكوبون</h2>';
    if ($nmsg !== '') echo '<div class="alert' . ($nerr ? ' warning' : '') . '">' . esc($nmsg) . '</div>';
    echo '<div class="kpis">'
       . '<div class="kpi"><b>' . count($n_pend) . '</b><span>أكواد بانتظار الموافقة</span></div>'
       . '<div class="kpi"><b>' . count($n_appr) . '</b><span>أكواد معتمدة</span></div>'
       . '<div class="kpi"><b>' . number_format($n_uses_all) . '</b><span>إجمالي الاستخدامات</span></div>'
       . '<div class="kpi"><b>' . number_format($n_noon_all, 2) . ' ج.م</b><span>إجمالي عمولة نون (ربح الكوبونات)</span></div>'
       . '<div class="kpi"><b>' . number_format($n_profit_all, 2) . ' ج.م</b><span>إجمالي عمولة المسوقين</span></div>'
       . '</div></div>';

    // ---------- 0) منتجات نون: المسوق بيبعت لينك المنتج، والإدارة بتحدد الفئة والسعر والنسبة ----------
    $n_prod_pend = noon_products_pending();
    $n_cats = noon_product_categories();
    $n_cat_opts = '<option value="">— اختار الفئة —</option>';
    foreach ($n_cats as $cslug => $cdef) {
        $n_cat_opts .= '<option value="' . esc($cslug) . '">' . esc($cdef[0]) . ' — ' . esc(rtrim(rtrim(number_format((float)$cdef[1], 2, '.', ''), '0'), '.')) . '%</option>';
    }
    echo '<div class="card"><h2>🛒 منتجات نون — طلبات المسوقين (' . count($n_prod_pend) . ')</h2>';
    echo '<p class="mut" style="font-size:13px">نسبة المسوق لازم تكون ≤ نسبة الفئة. الربح على كل بيعة = السعر × النسبة، والحد الأقصى 50 د.إ للعنصر (بيتحول للجنيه بسعر الصرف في الإعدادات).</p>';
    if (!$n_prod_pend) echo '<p class="mut">مفيش منتجات بانتظار المراجعة.</p>';
    foreach ($n_prod_pend as $np) {
        $npid = esc((string)$np['id']);
        $mt = (isset($np['meta']) && is_array($np['meta'])) ? $np['meta'] : [];
        $mt_cur = strtoupper((string)($mt['currency'] ?? ''));
        $mt_guess = (string)($mt['cat_guess'] ?? '');
        $opts = $mt_guess !== '' ? str_replace('<option value="' . esc($mt_guess) . '">', '<option value="' . esc($mt_guess) . '" selected>', $n_cat_opts) : $n_cat_opts;
        $pre_name = (string)($mt['title'] ?? '');
        $pre_price = (!empty($mt['price']) && ($mt_cur === '' || $mt_cur === 'AED')) ? (string)$mt['price'] : '';
        $mt_imgs = (isset($mt['images']) && is_array($mt['images'])) ? $mt['images'] : [];
        $img_html = '';
        foreach ($mt_imgs as $iu) {
            $img_html .= '<a href="' . esc((string)$iu) . '" target="_blank" rel="noopener"><img src="' . esc((string)$iu) . '" alt="" loading="lazy" style="width:72px;height:72px;object-fit:contain;border:1px solid #e2e8f0;border-radius:8px;background:#fff"></a>';
        }
        echo '<div class="noon-prod" style="border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:10px">'
           . '<div><b>' . esc((string)($np['name'] !== '' ? $np['name'] : $np['email'])) . '</b> — ' . esc((string)$np['email'])
           . ' — <span class="mut">' . esc(substr((string)$np['requested_at'], 0, 16)) . '</span></div>'
           . '<div dir="ltr" style="word-break:break-all;font-size:13px"><a href="' . esc((string)$np['url']) . '" target="_blank" rel="noopener">' . esc((string)$np['url']) . '</a></div>';
        $mt_crumbs = (isset($mt['crumbs']) && is_array($mt['crumbs'])) ? $mt['crumbs'] : [];
        $mt_cur_lbl = ($mt_cur === '' || $mt_cur === 'AED') ? 'د.إ' : $mt_cur;
        echo '<div style="border:1px dashed #cbd5e1;border-radius:10px;padding:8px 10px;margin:8px 0;font-size:13px;line-height:1.9;background:#f8fafc">'
           . '<b>📥 اللي جه من صفحة نون:</b><br>'
           . 'الاسم: ' . ($pre_name !== '' ? esc($pre_name) : '—') . '<br>'
           . 'السعر: ' . ($pre_price !== '' ? esc($pre_price) . ' ' . esc($mt_cur_lbl) : (!empty($mt['price']) ? esc(number_format((float)$mt['price'], 2)) . ' ' . esc($mt_cur_lbl) . ' (مش بالدرهم، حوّله)' : '—')) . '<br>'
           . 'الكاتيجوري في نون: ' . ($mt_crumbs ? esc(implode(' › ', $mt_crumbs)) : '—') . '<br>'
           . 'الفئة (تلقائي من نون): ' . ($mt_guess !== '' ? esc(noon_product_cat_label($mt_guess)) : 'مش متعرفة، اختار من القائمة') . '</div>';
        if (($np['fetch'] ?? '') === 'ok') {
            echo '<div style="font-size:13px;margin-top:6px;color:#166534">✅ اتسحبت بيانات المنتج من الصفحة (الاسم والسعر والصور والوصف). راجعها واعتمد.</div>';
        } else {
            echo '<div style="font-size:13px;margin-top:6px;color:#b45309">⚠️ مقدرناش نسحب بيانات الصفحة. اكتب الاسم والسعر يدوي.</div>';
        }
        if ($mt_cur !== '' && $mt_cur !== 'AED') {
            echo '<div style="font-size:13px;color:#b45309">⚠️ السعر في الصفحة بـ ' . esc($mt_cur) . '. حوّله للدرهم الإماراتي قبل الاعتماد.</div>';
        }
        if ($img_html !== '') echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0">' . $img_html . '</div>';
        if (!empty($mt['desc'])) {
            echo '<details style="font-size:13px;margin:6px 0"><summary>التفاصيل والوصف</summary><p style="margin:6px 0">' . esc((string)$mt['desc']) . '</p></details>';
        }
        if (($np['note'] ?? '') !== '') echo '<div class="mut" style="font-size:13px">ملاحظة المسوق: ' . esc((string)$np['note']) . '</div>';
        echo '<form method="post" class="row-actions" style="margin-top:8px">' . csrf_field()
           . '<input type="hidden" name="noon_act" value="product_approve"><input type="hidden" name="product_id" value="' . $npid . '">'
           . '<label>الفئة <select name="category">' . $opts . '</select></label> '
           . '<label>اسم المنتج <input type="text" name="pname" maxlength="120" value="' . esc($pre_name) . '" placeholder="من نون تلقائي"></label> '
           . '<label>السعر (د.إ) <input type="number" name="price_aed" min="0.01" step="0.01" style="width:100px" value="' . esc($pre_price) . '" placeholder="من نون تلقائي"></label> '
           . '<label>نسبة المسوق % <input type="number" name="mk_pct" min="0" max="100" step="0.01" required style="width:90px"></label> '
           . '<button class="btn sm" type="submit">✅ اعتماد</button></form>'
           . '<form method="post" class="row-actions" style="margin-top:6px" onsubmit="return confirm(\'ترفض المنتج؟\')">' . csrf_field()
           . '<input type="hidden" name="noon_act" value="product_reject"><input type="hidden" name="product_id" value="' . $npid . '">'
           . '<input type="text" name="note" maxlength="300" placeholder="سبب الرفض (اختياري)"> <button class="btn sm outl" type="submit">⛔ رفض</button></form>'
           . '</div>';
    }
    $n_prod_ok = array_values(array_filter(noon_products_all(), function ($x) { return ($x['status'] ?? '') === 'approved'; }));
    if ($n_prod_ok) {
        echo '<h3 style="margin:14px 0 8px;font-size:15px">المنتجات المعتمدة (' . count($n_prod_ok) . ')</h3>';
        echo '<div class="table-wrap"><table class="data-table rtable"><thead><tr><th>المسوق</th><th>المنتج</th><th>الفئة</th><th>السعر د.إ</th><th>نسبة المسوق</th><th>على كل بيعة</th></tr></thead><tbody>';
        foreach ($n_prod_ok as $np) {
            $pc = noon_product_calc($np);
            echo '<tr><td data-label="المسوق">' . esc((string)($np['name'] !== '' ? $np['name'] : $np['email'])) . '</td>'
               . '<td data-label="المنتج">' . esc((string)$np['pname']) . '</td>'
               . '<td data-label="الفئة">' . esc(noon_product_cat_label((string)$np['category'])) . '</td>'
               . '<td data-label="السعر">' . number_format($pc['price'], 2) . '</td>'
               . '<td data-label="نسبة المسوق">' . esc(rtrim(rtrim(number_format((float)$pc['pct'], 2, '.', ''), '0'), '.')) . '%</td>'
               . '<td data-label="على كل بيعة">' . number_format($pc['aed'], 2) . ' د.إ (≈ ' . number_format($pc['egp'], 2) . ' ج.م)' . ($pc['capped'] ? ' <span class="mut">(مقفول عند 50)</span>' : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // ---------- 0b) طلبات العملاء (من مسوقي نون) ----------
    echo cust_admin_html('noon');

    // ---------- 1) طلبات الأكواد ----------
    echo '<div class="card"><h2>📨 طلبات الأكواد (' . count($n_pend) . ')</h2>';
    if (!$n_pend) {
        echo '<p class="mut">مفيش طلبات أكواد بانتظار الموافقة.</p>';
    } else {
        echo '<div class="table-wrap"><table class="data-table rtable"><thead><tr><th>المسوق</th><th>الكود</th><th>تاريخ الطلب</th><th>ملاحظة المسوق</th><th>القرار</th></tr></thead><tbody>';
        foreach ($n_pend as $nc) {
            $n_cid = esc((string)($nc['id'] ?? ''));
            echo '<tr><td data-label="المسوق">' . esc((string)($nc['name'] ?? '')) . '<br><small dir="ltr">' . esc((string)($nc['email'] ?? '')) . '</small></td>'
               . '<td data-label="الكود" dir="ltr"><b>' . esc((string)($nc['code'] ?? '')) . '</b></td>'
               . '<td data-label="تاريخ الطلب">' . esc(substr((string)($nc['requested_at'] ?? ''), 0, 16)) . '</td>'
               . '<td data-label="ملاحظة">' . esc((string)($nc['note'] ?? '')) . '</td>'
               . '<td data-label="القرار">'
               . '<form method="post" class="row-actions">' . csrf_field() . '<input type="hidden" name="noon_act" value="approve"><input type="hidden" name="code_id" value="' . $n_cid . '">'
               . '<label>نسبة المسوق % <input type="number" name="share" min="0" max="100" step="0.5" value="' . esc((string)noon_default_share()) . '" style="width:90px"></label> '
               . '<button class="btn sm" type="submit">✅ اعتماد</button></form>'
               . '<form method="post" class="row-actions">' . csrf_field() . '<input type="hidden" name="noon_act" value="reject"><input type="hidden" name="code_id" value="' . $n_cid . '">'
               . '<input type="text" name="note" maxlength="300" placeholder="سبب الرفض (اختياري)"> <button class="btn sm outline" type="submit">⛔ رفض</button></form>'
               . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // ---------- 1ب) تظلمات النسبة (زي أمازون: الإدارة بتحدد النسبة الجديدة) ----------
    $n_appeals = noon_appeals_pending();
    echo '<div class="card"><h2>📈 تظلمات النسبة (' . count($n_appeals) . ')</h2>';
    if (!$n_appeals) {
        echo '<p class="mut">مفيش تظلمات على النسبة بانتظار الرد.</p>';
    } else {
        echo '<div class="table-wrap"><table class="data-table rtable"><thead><tr><th>المسوق</th><th>الكود</th><th>النسبة الحالية</th><th>سبب التظلم</th><th>تاريخ التظلم</th><th>القرار</th></tr></thead><tbody>';
        foreach ($n_appeals as $na) {
            $nc = $na['code']; $nap = $na['appeal'];
            $ncid = esc((string)($nc['id'] ?? '')); $naid = esc((string)($nap['id'] ?? ''));
            $cur_sh = is_numeric($nc['share'] ?? null) ? (float)$nc['share'] : noon_default_share();
            echo '<tr><td data-label="المسوق">' . esc((string)($nc['name'] ?? '')) . '<br><small dir="ltr">' . esc((string)($nc['email'] ?? '')) . '</small></td>'
               . '<td data-label="الكود" dir="ltr"><b>' . esc((string)($nc['code'] ?? '')) . '</b></td>'
               . '<td data-label="النسبة الحالية">' . esc(rtrim(rtrim(number_format($cur_sh, 2, '.', ''), '0'), '.')) . '%</td>'
               . '<td data-label="سبب التظلم">' . esc((string)($nap['note'] ?? '')) . '</td>'
               . '<td data-label="تاريخ التظلم">' . esc(substr((string)($nap['at'] ?? ''), 0, 16)) . '</td>'
               . '<td data-label="القرار">'
               . '<form method="post" class="row-actions">' . csrf_field() . '<input type="hidden" name="noon_act" value="appeal_approve"><input type="hidden" name="code_id" value="' . $ncid . '"><input type="hidden" name="appeal_id" value="' . $naid . '">'
               . '<label>النسبة الجديدة % <input type="number" name="share" min="0" max="100" step="0.5" value="' . esc((string)$cur_sh) . '" style="width:90px"></label> '
               . '<button class="btn sm" type="submit">✅ قبول</button></form>'
               . '<form method="post" class="row-actions">' . csrf_field() . '<input type="hidden" name="noon_act" value="appeal_reject"><input type="hidden" name="code_id" value="' . $ncid . '"><input type="hidden" name="appeal_id" value="' . $naid . '">'
               . '<input type="text" name="note" maxlength="300" placeholder="سبب الرفض (اختياري)"> <button class="btn sm outline" type="submit">⛔ رفض</button></form>'
               . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // ---------- 2) الأكواد المعتمدة + الاستخدامات والأرباح ----------
    echo '<div class="card"><h2>✅ الأكواد المعتمدة (' . count($n_appr) . ')</h2>';
    if (!$n_appr) {
        echo '<p class="mut">مفيش أكواد معتمدة لسه.</p>';
    } else {
        echo '<div class="table-wrap"><table class="data-table rtable"><thead><tr><th>المسوق</th><th>الكود</th><th>النسبة %</th><th>الاستخدامات</th><th>مبيعات الكود</th><th>عمولة نون (إجمالي)</th><th>ربح المسوق</th><th>معلق / متاح</th><th>آخر بيعة</th><th>إيقاف</th><th>حذف</th></tr></thead><tbody>';
        foreach ($n_appr as $nc) {
            $n_cid = esc((string)($nc['id'] ?? ''));
            $nst = noon_stats_for_code((string)($nc['id'] ?? ''));
            echo '<tr><td data-label="المسوق">' . esc((string)($nc['name'] ?? '')) . '<br><small dir="ltr">' . esc((string)($nc['email'] ?? '')) . '</small></td>'
               . '<td data-label="الكود" dir="ltr"><b>' . esc((string)($nc['code'] ?? '')) . '</b></td>'
               . '<td data-label="النسبة"><form method="post" class="row-actions">' . csrf_field() . '<input type="hidden" name="noon_act" value="share"><input type="hidden" name="code_id" value="' . $n_cid . '">'
               . '<input type="number" name="share" min="0" max="100" step="0.5" value="' . esc((string)($nc['share'] ?? noon_default_share())) . '" style="width:80px"> <button class="btn sm outline" type="submit">حفظ</button></form></td>'
               . '<td data-label="الاستخدامات">' . number_format($nst['uses']) . '</td>'
               . '<td data-label="مبيعات الكود">' . number_format($nst['sales'], 2) . '</td>'
               . '<td data-label="عمولة نون (إجمالي)">' . number_format($nst['noon'], 2) . '</td>'
               . '<td data-label="ربح المسوق">' . number_format($nst['profit'], 2) . '</td>'
               . '<td data-label="معلق / متاح">' . number_format($nst['pending'], 2) . ' / ' . number_format($nst['confirmed'], 2) . '</td>'
               . '<td data-label="آخر بيعة">' . esc((string)$nst['last_at']) . '</td>'
               . '<td data-label="إيقاف"><form method="post" class="row-actions" onsubmit="return confirm(\'إيقاف الكود؟ البيعات الجاية بيه مش هتتسجل\')">' . csrf_field() . '<input type="hidden" name="noon_act" value="reject"><input type="hidden" name="code_id" value="' . $n_cid . '">'
               . '<input type="text" name="note" maxlength="300" placeholder="سبب الإيقاف"> <button class="btn sm outline" type="submit">إيقاف</button></form></td>'
               . '<td data-label="حذف"><form method="post" class="row-actions" onsubmit="return confirm(\'حذف الكود نهائياً؟ المسوق هيتبلغ والكود مش هيشتغل تاني\')">' . csrf_field() . '<input type="hidden" name="noon_act" value="code_delete"><input type="hidden" name="code_id" value="' . $n_cid . '">'
               . '<input type="text" name="note" maxlength="300" placeholder="سبب الحذف"> <button class="btn sm outline" type="submit" style="color:#b91c1c">🗑️ حذف</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // ---------- 2ب) الأكواد الموقوفة والمرفوضة (للتاريخ) ----------
    $n_stopped = array_values(array_filter($n_codes, function ($c) { return in_array((string)($c['status'] ?? ''), ['stopped', 'rejected', 'deleted'], true); }));
    echo '<div class="card"><h2>⛔ الأكواد الموقوفة والمرفوضة (' . count($n_stopped) . ')</h2>';
    if (!$n_stopped) {
        echo '<p class="mut">مفيش أكواد موقوفة أو مرفوضة.</p>';
    } else {
        echo '<div class="table-wrap"><table class="data-table rtable"><thead><tr><th>المسوق</th><th>الكود</th><th>الحالة</th><th>السبب</th><th>وقت القرار</th><th>بواسطة</th></tr></thead><tbody>';
        foreach ($n_stopped as $nc) {
            $slbl = ((string)($nc['status'] ?? '') === 'stopped') ? 'موقوف' : (((string)($nc['status'] ?? '') === 'deleted') ? '🗑️ محذوف' : 'مرفوض');
            echo '<tr><td data-label="المسوق">' . esc((string)($nc['name'] ?? '')) . '<br><small dir="ltr">' . esc((string)($nc['email'] ?? '')) . '</small></td>'
               . '<td data-label="الكود" dir="ltr"><b>' . esc((string)($nc['code'] ?? '')) . '</b></td>'
               . '<td data-label="الحالة">' . $slbl . '</td>'
               . '<td data-label="السبب">' . wf_det_text($nc['reject_note'] ?? '', 30, 'سبب ' . $slbl . ' الكود ' . (string)($nc['code'] ?? ''), 'السبب كامل') . '</td>'
               . '<td data-label="وقت القرار">' . esc(substr((string)($nc['decided_at'] ?? ''), 0, 16)) . '</td>'
               . '<td data-label="بواسطة" dir="ltr">' . esc((string)($nc['decided_by'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // ---------- 3) تسجيل بيعة بالكود (يدوي) ----------
    echo '<div class="card"><h2>➕ تسجيل بيعة بكود نون</h2>';
    if (!$n_appr) {
        echo '<p class="mut">اعتمد كود الأول، وبعدين تقدر تسجل بيعات بيه.</p>';
    } else {
        echo '<form method="post" class="row-actions">' . csrf_field() . '<input type="hidden" name="noon_act" value="sale_add">'
           . '<label>الكود <select name="code_id" required>';
        foreach ($n_appr as $nc) {
            echo '<option value="' . esc((string)($nc['id'] ?? '')) . '">' . esc((string)($nc['code'] ?? '') . ' — ' . ((string)($nc['name'] ?? '') !== '' ? (string)$nc['name'] : (string)($nc['email'] ?? ''))) . '</option>';
        }
        echo '</select></label> '
           . '<label>رقم الأوردر على نون <input type="text" name="order_no" required maxlength="60" dir="ltr"></label> '
           . '<label>قيمة البيع (ج.م) <input type="number" name="sale" min="0" step="0.01"></label> '
           . '<label>المنتج (اختياري) <select name="product_id"><option value="">— بدون منتج (العمولة يدوي) —</option>' . noon_sale_product_options() . '</select></label> '
           . '<label>الكمية <input type="number" name="qty" min="1" max="999" value="1" style="width:80px"></label> '
           . '<label>عمولة نون (ج.م) <input type="number" name="noon_comm" min="0" step="0.01"></label> <span class="mut" style="font-size:12px">(لو المنتج مختار، العمولة بتتحسب من المنتج)</span> '
           . '<label>تاريخ البيعة <input type="date" name="at" value="' . esc(date('Y-m-d')) . '"></label> '
           . '<button class="btn" type="submit">تسجيل البيعة</button></form>'
           . '<p class="mut">عمولة المسوق = عمولة نون × نسبته، وبتتضاف لرصيده المعلق لحد ما تأكدها من الجدول اللي تحت.</p>';
    }
    echo '</div>';

    // ---------- 4) آخر البيعات (تأكيد / إلغاء) ----------
    echo '<div class="card"><h2>🧾 آخر البيعات</h2>';
    $n_show = array_slice($n_sales, 0, 150);
    if (!$n_show) {
        echo '<p class="mut">لسه مفيش بيعات مسجلة.</p>';
    } else {
        echo '<div class="table-wrap"><table class="data-table rtable"><thead><tr><th>التاريخ</th><th>الكود</th><th>المسوق</th><th>الأوردر</th><th>قيمة البيع</th><th>عمولة نون</th><th>نسبة</th><th>ربح المسوق</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>';
        foreach ($n_show as $ns) {
            $n_sid = esc((string)($ns['id'] ?? ''));
            $n_state = (string)($ns['state'] ?? 'pending');
            echo '<tr><td data-label="التاريخ">' . esc((string)($ns['at'] ?? '')) . '</td>'
               . '<td data-label="الكود" dir="ltr">' . esc((string)($ns['code'] ?? '')) . '</td>'
               . '<td data-label="المسوق" dir="ltr">' . esc((string)($ns['email'] ?? '')) . '</td>'
               . '<td data-label="الأوردر" dir="ltr">' . esc((string)($ns['order_no'] ?? '')) . '</td>'
               . '<td data-label="قيمة البيع">' . number_format((float)($ns['sale'] ?? 0), 2) . '</td>'
               . '<td data-label="عمولة نون">' . number_format((float)($ns['noon_comm'] ?? 0), 2) . '</td>'
               . '<td data-label="نسبة">' . esc((string)($ns['share'] ?? '')) . '%</td>'
               . '<td data-label="ربح المسوق">' . number_format((float)($ns['mk_egp'] ?? 0), 2) . '</td>'
               . '<td data-label="الحالة">' . esc($n_state_lbl[$n_state] ?? $n_state) . '</td>'
               . '<td data-label="إجراء">';
            if ($n_state === 'pending') {
                echo '<form method="post" class="row-actions" style="display:inline">' . csrf_field() . '<input type="hidden" name="noon_act" value="sale_confirm"><input type="hidden" name="sale_id" value="' . $n_sid . '"><button class="btn sm" type="submit">تأكيد</button></form> ';
            }
            if ($n_state !== 'reversed') {
                echo '<form method="post" class="row-actions" style="display:inline" onsubmit="return confirm(\'إلغاء البيعة وخصم عمولتها من المسوق؟\')">' . csrf_field() . '<input type="hidden" name="noon_act" value="sale_reverse"><input type="hidden" name="sale_id" value="' . $n_sid . '"><input type="text" name="reason" maxlength="200" placeholder="سبب الإلغاء"> <button class="btn sm outline" type="submit">إلغاء</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

elseif ($tab === 'amazon' && function_exists('amz_stats')) {
    $asec = (string)($_GET['sec'] ?? 'overview');
    if (!in_array($asec, ['overview', 'requests', 'links', 'conversions', 'tags', 'settings'], true)) $asec = 'overview';
    echo cust_admin_html('amazon');
    $amz_msg = '';
    $amz_err = false;

    // ======================== إجراءات ========================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = (string)($_POST['amz_act'] ?? '');
        $lid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['link_id'] ?? ''));

        // ---- حفظ الإعدادات ----
        if ($act === 'settings') {
            $save = [];
            $save['amz_enabled'] = isset($_POST['amz_enabled']) ? '1' : '0';
            $share = (isset($_POST['amz_mk_share']) && is_numeric($_POST['amz_mk_share'])) ? max(0, min(100, (float)$_POST['amz_mk_share'])) : 50;
            $save['amz_mk_share'] = (string)$share;
            foreach (amz_marketplaces() as $k => $m) {
                $save['amz_tag_' . $k] = mb_substr(trim((string)($_POST['amz_tag_' . $k] ?? '')), 0, 60);
            }
            foreach (amz_categories() as $slug => $c) {
                $v = trim((string)($_POST['amz_rate_' . $slug] ?? ''));
                $save['amz_rate_' . $slug] = ($v !== '' && is_numeric($v)) ? (string)max(0, min(100, (float)$v)) : '';
            }
            foreach (amz_fx_defaults() as $cur => $d) {
                if ($cur === 'EGP') continue;
                $v = trim((string)($_POST['amz_fx_' . $cur] ?? ''));
                $save['amz_fx_' . $cur] = ($v !== '' && is_numeric($v) && (float)$v > 0) ? (string)round((float)$v, 4) : '';
            }
            jupdate('settings.json', function (&$stt) use ($save) {
                if (!is_array($stt)) $stt = [];
                foreach ($save as $kk => $vv) $stt[$kk] = $vv;
                return true;
            }, []);
            log_audit('amazon_settings', '-', array_keys($save));
            $amz_msg = '✅ تم حفظ إعدادات أمازون';
        }

        // ---- تصفير بيانات أمازون: الإحصائيات تبدأ جديدة من الآن ----
        if ($act === 'reset_stats') {
            if (function_exists('amz_reset_stats')) {
                amz_reset_stats();
                log_audit('amazon_reset_stats', '-', []);
                $amz_msg = '✅ تم تصفير بيانات أمازون — الإحصائيات تبدأ جديدة من الآن';
            } else {
                $amz_err = true;
                $amz_msg = 'تعذر تنفيذ التصفير حالياً.';
            }
        }

        // ---- حذف رابط ----
        if ($act === 'link_status' && $lid !== '') {
            $l = amz_link($lid);
            if ($l && (string)($_POST['do'] ?? '') === 'delete') {
                amz_link_delete($lid);
                log_audit('amazon_link_delete', $lid, ['asin' => $l['asin'] ?? '']);
                $mk_d = find_marketer_by_code((string)($l['ref'] ?? ''));
                if ($mk_d && !empty($mk_d['email'])) {
                    $dt = (string)($l['title'] ?? '') !== '' ? (string)$l['title'] : (string)($l['asin'] ?? '');
                    notify_user((string)$mk_d['email'], '🗑️ الإدارة حذفت الرابط ' . $dt . ' من الروابط بتاعتك', 'dashboard/amazon.php?tab=links');
                }
                $amz_msg = '🗑️ تم حذف الرابط';
            }
        }

        // ---- تعديل رابط: السعر + نسبة المسوق + القسم + Tracking ID + الحالة ----
        if ($act === 'link_save' && $lid !== '') {
            $res = amz_admin_link_save($lid, [
                'cat'    => (string)($_POST['cat'] ?? ''),
                'rate'   => (string)($_POST['rate'] ?? ''),
                'price'  => (string)($_POST['price'] ?? ''),
                'mk_pct' => (string)($_POST['mk_pct'] ?? ''),
                'tag'    => (string)($_POST['tag'] ?? ''),
                'status' => (string)($_POST['status'] ?? ''),
            ]);
            if (!empty($res['ok'])) {
                $amz_msg = '✅ تم حفظ إعدادات الرابط' . (!empty($res['no_tag']) ? ' • ⚠️ المسوق ده لسه من غير Tracking ID' : '');
            } else {
                $amz_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الحفظ');
                $amz_err = true;
            }
        }

        // ---- طلبات المنتجات: موافقة (مع التاج والسعر والنسبة) أو رفض ----
        if ($act === 'req_action') {
            $rid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['req_id'] ?? ''));
            $do  = (string)($_POST['do'] ?? '');
            if ($do === 'approve') {
                $res = amz_approve_request($rid, [
                    'tag'        => (string)($_POST['tag'] ?? ''),
                    'price'      => (string)($_POST['price'] ?? ''),
                    'mk_pct'     => (string)($_POST['mk_pct'] ?? ''),
                    'cat'        => (string)($_POST['cat'] ?? ''),
                    'admin_note' => (string)($_POST['admin_note'] ?? ''),
                ]);
                if (!empty($res['ok'])) {
                    $ps = $res['per_sale'] ?? null;
                    $amz_msg = '✅ تمت الموافقة — الرابط ظهر للمسوق في «الروابط المعتمدة»'
                             . (($ps && !empty($ps['ok'])) ? ' • هياخد ' . number_format((float)$ps['amount'], 2) . ' ' . amz_cur_label($ps['currency']) . ' على كل بيعة' : '')
                             . (!empty($res['no_tag']) ? ' • ⚠️ من غير Tracking ID (هيستخدم التاج العام)' : '');
                } else {
                    $amz_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الموافقة');
                    $amz_err = true;
                }
            } elseif ($do === 'reject') {
                $res = amz_reject_request($rid, (string)($_POST['admin_note'] ?? ''));
                if (!empty($res['ok'])) {
                    $amz_msg = '⛔ تم رفض الطلب';
                } else {
                    $amz_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الرفض');
                    $amz_err = true;
                }
            }
        }

        // ---- تظلم على نسبة المسوق: موافقة (بنسبة تحددها الإدارة) أو رفض ----
        if ($act === 'pct_action') {
            $rid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['req_id'] ?? ''));
            $do  = (string)($_POST['do'] ?? '');
            $anote = is_string($_POST['admin_note'] ?? null) ? $_POST['admin_note'] : '';
            if ($do === 'approve') {
                $res = amz_approve_pct_request($rid, [
                    'mk_pct'     => is_string($_POST['mk_pct'] ?? null) ? $_POST['mk_pct'] : '',
                    'admin_note' => $anote,
                ]);
                if (!empty($res['ok'])) {
                    $amz_msg = '✅ اتوافق على التظلم — النسبة بقت ' . amz_pct_txt($res['to'] ?? null) . '% (على البيعات الجاية)';
                } else {
                    $amz_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الموافقة');
                    $amz_err = true;
                }
            } elseif ($do === 'reject') {
                $res = amz_reject_pct_request($rid, $anote);
                if (!empty($res['ok'])) {
                    $amz_msg = '⛔ اترفض التظلم — النسبة فضلت زي ما هي';
                } else {
                    $amz_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الرفض');
                    $amz_err = true;
                }
            }
        }

        // ---- تسجيل بيعة يدوياً ----
        if ($act === 'add_conv' && $lid !== '') {
            $value = (float)($_POST['value'] ?? 0);
            $qty   = max(1, (int)($_POST['qty'] ?? 1));
            $comm  = (isset($_POST['comm']) && trim((string)$_POST['comm']) !== '' && is_numeric($_POST['comm'])) ? (float)$_POST['comm'] : null;
            $mk_e  = (isset($_POST['mk_egp']) && trim((string)$_POST['mk_egp']) !== '' && is_numeric($_POST['mk_egp'])) ? (float)$_POST['mk_egp'] : null;
            $status = ((string)($_POST['conv_status'] ?? 'pending') === 'confirmed') ? 'confirmed' : 'pending';
            $res = amz_add_conversion($lid, $value, $qty, [
                'amz_comm' => $comm,
                'mk_egp'   => $mk_e,
                'status'   => $status,
                'source'   => 'admin',
                'note'     => (string)($_POST['note'] ?? ''),
            ]);
            if (!empty($res['ok'])) {
                $c = $res['conv'];
                $amz_msg = '✅ تم تسجيل البيعة — أرباح المسوق ' . number_format($c['mk_egp'], 2) . ' ج.م • نصيب الموقع ' . number_format($c['site_egp'], 2) . ' ج.م';
                if ((float)$c['site_egp'] < 0) $amz_msg .= ' • ⚠️ نصيب الموقع بالسالب: أرباح المسوق أكبر من عمولة أمازون، راجع الأرقام';
                log_audit('amazon_conv_add', (string)$c['id'], ['link' => $lid, 'gross' => $c['gross'], 'mk' => $c['mk_egp'], 'site' => $c['site_egp']]);
            } else {
                $amz_msg = '❌ ' . (string)($res['error'] ?? 'تعذر تسجيل البيعة');
                $amz_err = true;
            }
        }

        // ---- تأكيد / إلغاء بيعة ----
        if ($act === 'conv_action') {
            $cid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['conv_id'] ?? ''));
            $do  = (string)($_POST['do'] ?? '');
            if ($do === 'confirm') {
                $c = amz_confirm_conversion($cid);
                if ($c) { log_audit('amazon_conv_confirm', $cid, ['mk' => $c['mk_egp']]); $amz_msg = '✅ تم تأكيد أرباح المسوق وانتقلت لرصيده المتاح'; }
            } elseif ($do === 'reverse') {
                $c = amz_reverse_conversion($cid, (string)($_POST['reason'] ?? 'إلغاء من الإدارة'));
                if ($c) { log_audit('amazon_conv_reverse', $cid, ['mk' => $c['mk_egp']]); $amz_msg = '↩️ تم إلغاء البيعة وخصم أرباحها من المسوق'; }
            }
        }

        // ---- تعديل أرباح المسوق على بيعة (معلقة أو مؤكدة) ----
        if ($act === 'conv_edit') {
            $cid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['conv_id'] ?? ''));
            $val = trim((string)($_POST['mk_egp'] ?? ''));
            if ($val === '' || !is_numeric($val) || (float)$val < 0) {
                $amz_msg = '❌ اكتب مبلغ صحيح (0 أو أكبر)';
                $amz_err = true;
            } else {
                $res = amz_set_conv_earn($cid, (float)$val, 'تعديل الإدارة للعمولة');
                if (!empty($res['ok'])) {
                    $amz_msg = '✅ اتحفظ مبلغ أرباح المسوق على البيعة — الرصيد والكروت اتحدّثوا';
                } else {
                    $amz_msg = '❌ ' . (string)($res['error'] ?? 'تعذر التعديل');
                    $amz_err = true;
                }
            }
        }

        // ---- استيراد تقرير أمازون CSV ----
        if ($act === 'import') {
            $text = '';
            if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file((string)$_FILES['csv_file']['tmp_name'])) {
                $text = (string)file_get_contents((string)$_FILES['csv_file']['tmp_name']);
            } elseif (trim((string)($_POST['csv_text'] ?? '')) !== '') {
                $text = (string)$_POST['csv_text'];
            }
            if (trim($text) === '') {
                $amz_msg = '❌ مفيش ملف أو نص — ارفع ملف CSV أو الصق محتواه';
                $amz_err = true;
            } else {
                $rep = amz_import_csv($text, ['status' => ((string)($_POST['import_status'] ?? 'pending') === 'confirmed' ? 'confirmed' : 'pending')]);
                if (empty($rep['ok'])) {
                    $amz_msg = '❌ ' . (string)($rep['error'] ?? 'فشل الاستيراد');
                    $amz_err = true;
                } else {
                    $GLOBALS['_AMZ_IMPORT_REPORT'] = $rep;
                    $amz_msg = '✅ تم استيراد ' . (int)$rep['imported'] . ' بيعة (من ' . (int)$rep['rows'] . ' صف) — عمولة أمازون الإجمالية ' . number_format((float)$rep['total_comm'], 2)
                             . ' • أرباح المسوقين ' . number_format((float)$rep['total_mk'], 2) . ' ج.م • نصيب الموقع ' . number_format((float)$rep['total_site'], 2) . ' ج.م'
                             . (count($rep['unmatched']) ? ' • ⚠️ ' . count($rep['unmatched']) . ' صف غير مطابق (مش مضاف لسه)' : '');
                    log_audit('amazon_import', '-', ['imported' => $rep['imported'], 'rows' => $rep['rows'], 'unmatched' => count($rep['unmatched'])]);
                }
            }
        }

        // ---- Tracking ID ونسبة (الأرشيف) لكل مسوق ----
        if ($act === 'mk_tags') {
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $u = find_user($email);
            if ($u) {
                $tags = [];
                foreach (amz_marketplaces() as $k => $m) {
                    $v = trim((string)($_POST['tag_' . $k] ?? ''));
                    if ($v !== '') $tags[$k] = mb_substr($v, 0, 60);
                }
                $share = trim((string)($_POST['share'] ?? ''));
                update_user($email, function (&$row) use ($tags, $share) {
                    $row['amazon_tags'] = $tags;
                    if ($share !== '' && is_numeric($share)) $row['amazon_share'] = max(0, min(100, (float)$share));
                    elseif (isset($row['amazon_share'])) unset($row['amazon_share']);
                });
                log_audit('amazon_mk_tags', $email, $tags);
                $amz_msg = '✅ تم حفظ تاجات المسوق';
            }
        }
    }

    // ======================== بيانات العرض ========================
    $amz_st   = amz_stats();
    $amz_all  = amz_links_all();
    $amz_ord  = amz_orders_all();
    $amz_reqs = amz_reqs_all();
    $am_money = amz_money_by_link();
    uasort($amz_all, function ($a, $b) { return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')); });
    usort($amz_ord, function ($a, $b) { return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')); });
    $am_reqs_pending = array_filter($amz_reqs, function ($r) { return (string)($r['status'] ?? '') === 'pending' && in_array((string)($r['type'] ?? ''), ['approval', 'pct'], true); });
    $am_req_n = count($am_reqs_pending);
    $am_approved_n = count(array_filter($amz_all, function ($l) { return (string)($l['status'] ?? 'approved') === 'approved'; }));

    if ($amz_msg) {
        echo '<div style="padding:14px 18px;background:' . ($amz_err ? '#fee2e2;border:1px solid #fecaca;color:#991b1b' : '#dcfce7;border:1px solid #86efac;color:#166534')
           . ';border-radius:12px;margin-bottom:16px;font-weight:800">' . esc($amz_msg) . '</div>';
    }

    echo '<div class="adm-box" style="background:linear-gradient(135deg,#0f172a,#232f3e);color:#fff;border:none">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px">';
    echo '<div><h3 style="color:#ff9900;margin:0;font-size:18px">🛍️ أفلييت أمازون — طلبات المنتجات والعمولات</h3>'
       . '<div style="font-size:12px;color:#cbd5e1;margin-top:4px">المسوق بيبعت طلب منتج → الإدارة بتضيف الـ Tracking ID وتحدد السعر والنسبة وتوافق → النقرات بتتسجل تلقائياً، والأرباح بتحددها الإدارة.</div></div>';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap">'
       . '<a class="btn sm" style="background:#ff9900;color:#111" href="?tab=amazon&sec=requests">📨 طلبات المنتجات (' . $am_req_n . ')</a>'
       . '<a class="btn sm outline" style="color:#fff;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.35)" href="?tab=amazon&sec=conversions">🧾 المبيعات والأرباح</a>'
       . '<a class="btn sm outline" style="color:#fff;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.35)" href="?tab=amazon&sec=settings">⚙️ الإعدادات والتاجات</a>'
       . '</div></div>';
    echo '<div class="adm-main-counters">';
    foreach ([
        ['📨', 'طلبات بانتظار موافقتك', number_format($am_req_n), $am_req_n > 0 ? 'محتاجة مراجعة' : 'مفيش طلبات مفتوحة'],
        ['🔗', 'الروابط المعتمدة', number_format($am_approved_n), number_format(count($amz_all) - $am_approved_n) . ' مش معتمدة'],
        ['👆', 'النقرات', number_format($amz_st['clicks']), number_format($amz_st['uniq']) . ' زائر فريد'],
        ['🛒', 'البيعات', number_format($amz_st['orders']), 'قيمة: ' . number_format($amz_st['gross'], 2)],
        ['🤝', 'عمولة المسوقين', number_format($amz_st['mk_egp'], 2) . ' ج.م', number_format($amz_st['confirmed_conv']) . ' مؤكدة • ' . number_format($amz_st['pending_conv']) . ' معلقة'],
        ['💰', 'عمولة الموقع', number_format($amz_st['site_egp'], 2) . ' ج.م', $amz_st['site_egp'] < 0 ? '⚠️ الموقع بيدفع الفرق (نسبة المسوق أعلى من عمولة أمازون)' : 'من إجمالي عمولة أمازون ' . number_format($amz_st['amz_comm'], 2)],
    ] as $k) {
        echo '<div class="adm-big-card" style="background:rgba(255,255,255,.08)"><div class="ic">' . $k[0] . '</div><div class="num">' . esc($k[2]) . '</div><div class="lbl">' . esc($k[1]) . '</div><div class="sub">' . esc($k[3]) . '</div></div>';
    }
    echo '</div></div>';

    $asecs = ['overview' => '📊 نظرة عامة', 'requests' => '📨 طلبات المنتجات (' . $am_req_n . ')', 'links' => '🔗 الروابط (' . count($amz_all) . ')',
              'conversions' => '🧾 المبيعات والأرباح (' . count($amz_ord) . ')', 'tags' => '🏷️ تاجات المسوقين', 'settings' => '⚙️ الإعدادات'];
    echo '<div class="adm-tabs">';
    foreach ($asecs as $k => $l) echo '<a href="?tab=amazon&sec=' . $k . '"' . ($asec === $k ? ' class="on"' : '') . '>' . esc($l) . '</a>';
    echo '</div>';

    // ------------------ نظرة عامة ------------------
    if ($asec === 'overview') {
        echo '<div class="adm-grid-2">';
        echo '<div class="adm-box"><h3>🔥 أفضل الروابط المعتمدة (بالنقرات)</h3>';
        $top = array_filter($amz_all, function ($l) { return (string)($l['status'] ?? 'approved') === 'approved'; });
        usort($top, function ($a, $b) { return (int)($b['clicks'] ?? 0) - (int)($a['clicks'] ?? 0); });
        $top = array_slice($top, 0, 10);
        if (!$top) echo '<p class="mut">لسه مفيش روابط معتمدة.</p>';
        else {
            echo '<table style="font-size:12.5px"><tr><th>المنتج</th><th>المسوق</th><th>نقرات</th><th>بيعات</th><th>معلقة</th><th>مؤكدة</th></tr>';
            foreach ($top as $l) {
                $mku = find_marketer_by_code((string)($l['ref'] ?? ''));
                $mm  = $am_money[(string)($l['id'] ?? '')] ?? ['sales' => 0, 'pending' => 0, 'confirmed' => 0];
                echo '<tr><td>' . esc(mb_substr((string)($l['title'] ?? ''), 0, 28) ?: (string)($l['asin'] ?? '')) . '</td>'
                   . '<td>' . esc($mku ? (string)($mku['name'] ?? $l['ref']) : (string)($l['ref'] ?? '')) . '</td>'
                   . '<td>' . number_format((int)($l['clicks'] ?? 0)) . '</td>'
                   . '<td>' . number_format((int)$mm['sales']) . '</td>'
                   . '<td dir="ltr" style="color:#b45309">' . number_format((float)$mm['pending'], 2) . '</td>'
                   . '<td dir="ltr" style="color:#15803d">' . number_format((float)$mm['confirmed'], 2) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        echo '<div class="adm-box"><h3>🏆 أرباح المسوقين من أمازون</h3>';
        $bym = [];
        foreach ($amz_ord as $o) {
            if ((string)($o['status'] ?? '') === 'reversed') continue;
            $r = (string)($o['ref'] ?? '');
            if (!isset($bym[$r])) $bym[$r] = ['mk' => 0, 'site' => 0, 'orders' => 0];
            $bym[$r]['mk'] += (float)($o['mk_egp'] ?? 0);
            $bym[$r]['site'] += (float)($o['site_egp'] ?? 0);
            $bym[$r]['orders']++;
        }
        uasort($bym, function ($a, $b) { return $b['mk'] - $a['mk']; });
        if (!$bym) echo '<p class="mut">لا توجد مبيعات مسجلة بعد.</p>';
        else {
            echo '<table style="font-size:12.5px"><tr><th>المسوق</th><th>بيعات</th><th>أرباحه</th><th>نصيب الموقع</th></tr>';
            foreach (array_slice($bym, 0, 10, true) as $r => $v) {
                $mku = find_marketer_by_code($r);
                echo '<tr><td>' . esc($mku ? (string)($mku['name'] ?? $r) : $r) . '</td><td>' . number_format($v['orders']) . '</td>'
                   . '<td dir="ltr" style="color:#16a34a;font-weight:800">' . number_format($v['mk'], 2) . '</td>'
                   . '<td dir="ltr">' . number_format($v['site'], 2) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div></div>';

        if ($am_req_n > 0) {
            echo '<div class="adm-box" style="border-color:#fde68a;background:#fffbeb"><h3>⏳ طلبات منتجات بانتظار موافقتك (' . $am_req_n . ')</h3>';
            echo '<table style="font-size:12.5px"><tr><th>المسوق</th><th>المنتج</th><th>القسم</th><th>التاريخ</th><th>إجراء</th></tr>';
            $shown = 0;
            foreach (array_reverse($amz_reqs, true) as $rid => $r) {
                if ((string)($r['status'] ?? '') !== 'pending' || !in_array((string)($r['type'] ?? ''), ['approval', 'pct'], true)) continue;
                if (++$shown > 8) break;
                $mku = find_marketer_by_code((string)($r['ref'] ?? ''));
                echo '<tr><td>' . esc($mku ? (string)($mku['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</td>'
                   . '<td>' . ((string)($r['type'] ?? '') === 'pct' ? '📈 تظلم نسبة: ' : '') . esc(mb_substr((string)(($r['title'] ?? '') !== '' ? $r['title'] : ($r['asin'] ?? '')), 0, 40)) . '</td>'
                   . '<td>' . esc(amz_cat_label((string)($r['cat'] ?? 'other'))) . '</td>'
                   . '<td dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</td>'
                   . '<td><a class="btn sm" href="?tab=amazon&sec=requests">مراجعة ←</a></td></tr>';
            }
            echo '</table></div>';
        }
    }

    // ------------------ طلبات المنتجات (منظمة) ------------------
    elseif ($asec === 'requests') {
        $am_by  = ['pending' => [], 'approved' => [], 'rejected' => []];   // طلبات المنتجات
        $am_pct = ['pending' => [], 'approved' => [], 'rejected' => []];   // تظلمات النسبة
        foreach (array_reverse($amz_reqs, true) as $rid => $r) {
            $rt = (string)($r['type'] ?? '');
            if ($rt !== 'approval' && $rt !== 'pct') continue;
            $s = (string)($r['status'] ?? 'pending');
            if (!isset($am_by[$s])) $s = 'pending';
            if ($rt === 'pct') $am_pct[$s][$rid] = $r; else $am_by[$s][$rid] = $r;
        }

        $am_cats = amz_categories();

        // ⏳ بانتظار المراجعة — بطاقة لكل طلب: بياناته + نموذج الموافقة (مش جدول، عشان عداد الجداول العام ميحسبش صفوف النموذج)
        echo '<div class="adm-box" style="border-color:#fde68a"><h3>⏳ بانتظار مراجعتك (' . count($am_by['pending']) . ')</h3>';
        if (!$am_by['pending']) {
            echo '<p class="mut">مفيش طلبات منتجات جديدة 🎉</p>';
        } else {
            echo '<p class="mut" style="font-size:12px;line-height:1.8;margin-bottom:12px">حدد <b>سعر البيع</b> و<b>نسبة المسوق</b> من سعر البيع، ولو عنده <b>Tracking ID</b> خاص حطه، وبعدين وافق.</p>';
            foreach ($am_by['pending'] as $rid => $r) {
                $lid2      = (string)($r['link_id'] ?? '');
                $L2        = $lid2 !== '' ? ($amz_all[$lid2] ?? null) : null;
                $mku       = find_marketer_by_code((string)($r['ref'] ?? ''));
                $mkt2      = (string)($L2['mkt'] ?? 'com');
                $cur2      = amz_currency($mkt2);
                $tag_now   = ($mku && isset($mku['amazon_tags'][$mkt2])) ? (string)$mku['amazon_tags'][$mkt2] : '';
                $price_now = $L2 ? (float)($L2['price'] ?? 0) : (float)($r['price_hint'] ?? 0);
                $pct_now   = $L2 ? amz_mk_pct($L2) : null;
                $cat_now   = (string)($L2['cat'] ?? ($r['cat'] ?? 'other'));
                $rate_now  = amz_rate($cat_now);
                $pname     = (string)(($r['title'] ?? '') !== '' ? $r['title'] : ('منتج ' . ($r['asin'] ?? '')));

                echo '<div style="border:1px solid #fde68a;border-radius:14px;background:#fff;padding:14px 16px;margin-bottom:12px">';
                echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">'
                   . '<div><b>' . esc($mku ? (string)($mku['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</b> '
                   . '<span class="mut" dir="ltr" style="font-size:11px">' . esc((string)($r['ref'] ?? '')) . '</span>'
                   . ($mku ? '<br><span class="mut" dir="ltr" style="font-size:11px">' . esc((string)($mku['email'] ?? '')) . '</span>' : '') . '</div>'
                   . '<div dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</div>'
                   . '</div>';
                echo '<div style="margin-top:10px;font-size:13px"><b>' . esc(mb_substr($pname, 0, 80)) . '</b> '
                   . '<span dir="ltr" class="mut" style="font-size:11px">' . esc((string)($r['asin'] ?? '')) . '</span> • ' . esc(amz_mkt_label($mkt2)) . ' • ' . esc(amz_cat_label($cat_now))
                   . ((float)($r['price_hint'] ?? 0) > 0 ? ' • سعر المسوق المتوقع: <b>' . esc(number_format((float)$r['price_hint'], 2)) . '</b>' : '')
                   . ($L2 ? ' • <a target="_blank" rel="nofollow noopener" href="' . esc(amz_out_url($L2)) . '">🔎 فتح على أمازون</a>' : ' • <span style="color:#b91c1c">⚠️ المسوق سحب الرابط</span>')
                   . '</div>';
                if ((string)($r['note'] ?? '') !== '') {
                    echo '<div class="mut" style="font-size:12px;margin-top:6px">💬 ملاحظة المسوق: ' . esc(mb_substr((string)$r['note'], 0, 200)) . '</div>';
                }

                echo '<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;padding-top:12px;border-top:1px dashed #fde68a">'
                   . '<input type="hidden" name="amz_act" value="req_action"><input type="hidden" name="req_id" value="' . esc((string)$rid) . '">'
                   . '<label style="font-size:11px;font-weight:800">Tracking ID<br><input name="tag" dir="ltr" value="' . esc($tag_now) . '" placeholder="' . esc(amz_tag($mkt2) ?: 'yourtag-20') . '" style="width:150px;padding:7px;border:1px solid var(--slate-200);border-radius:8px;font-size:12px"></label>'
                   . '<label style="font-size:11px;font-weight:800">سعر البيع (' . esc(amz_cur_label($cur2)) . ')<br><input name="price" dir="ltr" value="' . esc($price_now > 0 ? rtrim(rtrim(number_format($price_now, 2, '.', ''), '0'), '.') : '') . '" placeholder="300" style="width:110px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<label style="font-size:11px;font-weight:800">نسبة المسوق %<br><input name="mk_pct" dir="ltr" value="' . esc($pct_now !== null ? rtrim(rtrim(number_format($pct_now, 2, '.', ''), '0'), '.') : '') . '" placeholder="10" style="width:96px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<label style="font-size:11px;font-weight:800">القسم<br><select name="cat" style="padding:7px;border:1px solid var(--slate-200);border-radius:8px;width:230px">';
                foreach (amz_categories() as $slug => $c) {
                    echo '<option value="' . esc($slug) . '"' . ($cat_now === $slug ? ' selected' : '') . '>' . esc($c[0]) . '</option>';
                }
                echo '</select></label>'
                   . '<label style="font-size:11px;font-weight:800">ملاحظتك للمسوق<br><input name="admin_note" placeholder="اختياري" style="width:200px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<div style="display:flex;gap:6px">'
                   . '<button class="btn sm" style="background:#16a34a;color:#fff" name="do" value="approve">✅ موافقة</button>'
                   . '<button class="btn sm outline" name="do" value="reject" style="color:#b91c1c">⛔ رفض</button>'
                   . '</div>'
                   . '<div style="flex-basis:100%;font-size:11px;color:#b45309">عمولة أمازون للقسم ده: <b dir="ltr">' . esc(rtrim(rtrim(number_format($rate_now, 2), '0'), '.')) . '%</b> — لو نسبة المسوق أعلى منها، نصيب الموقع بيطلع بالسالب (الموقع بيدفع الفرق).</div>'
                   . '</form>';
                echo '</div>';
            }
        }
        echo '</div>';

        // 📈 تظلمات النسبة: المسوق طلب نسبة أعلى على رابط اتعتمد — الإدارة بتحدد النسبة الجديدة وتوافق، أو ترفض
        echo '<div class="adm-box" style="border-color:#bfdbfe"><h3>📈 تظلمات النسبة (' . count($am_pct['pending']) . ')</h3>';
        if (!$am_pct['pending']) {
            echo '<p class="mut">مفيش تظلمات على النسبة 👌</p>';
        } else {
            echo '<p class="mut" style="font-size:12px;line-height:1.8;margin-bottom:12px">المسوق طلب نسبة أعلى على رابط اتعتمد. حدد <b>النسبة الجديدة</b> (تقدر تديله أقل من اللي طلبه) ووافق، أو ارفض. <b>التعديل بيسري على البيعات الجاية بس</b>.</p>';
            foreach ($am_pct['pending'] as $rid => $r) {
                $lidp  = (string)($r['link_id'] ?? '');
                $Lp    = $lidp !== '' ? ($amz_all[$lidp] ?? null) : null;
                $mkp   = find_marketer_by_code((string)($r['ref'] ?? ''));
                $mktp  = (string)($Lp['mkt'] ?? 'com');
                $catp  = (string)($Lp['cat'] ?? ($r['cat'] ?? 'other'));
                $ratep = amz_rate($catp);
                $curp  = $Lp ? amz_mk_pct($Lp) : null;
                $ptt   = (string)(($r['title'] ?? '') !== '' ? $r['title'] : ('منتج ' . ($r['asin'] ?? '')));
                echo '<div style="border:1px solid #bfdbfe;border-radius:14px;background:#fff;padding:14px 16px;margin-bottom:12px">';
                echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap"><div><b>' . esc($mkp ? (string)($mkp['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</b> '
                   . '<span class="mut" dir="ltr" style="font-size:11px">' . esc((string)($r['ref'] ?? '')) . '</span>'
                   . ($mkp ? '<br><span class="mut" dir="ltr" style="font-size:11px">' . esc((string)($mkp['email'] ?? '')) . '</span>' : '') . '</div>'
                   . '<div dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</div></div>';
                echo '<div style="margin-top:10px;font-size:13px"><b>' . esc(mb_substr($ptt, 0, 80)) . '</b> '
                   . '<span dir="ltr" class="mut" style="font-size:11px">' . esc((string)($r['asin'] ?? '')) . '</span> • ' . esc(amz_mkt_label($mktp)) . ' • ' . esc(amz_cat_label($catp))
                   . ($Lp ? ' • <a target="_blank" rel="nofollow noopener" href="' . esc(amz_out_url($Lp)) . '">🔎 فتح على أمازون</a>' : ' • <span style="color:#b91c1c">⚠️ الرابط اتشال</span>')
                   . '</div>';
                echo '<div style="margin-top:8px;font-size:13px">نسبته الحالية: <b dir="ltr">' . esc(amz_pct_txt($curp)) . '%</b></div>';
                if ((string)($r['note'] ?? '') !== '') {
                    echo '<div class="mut" style="font-size:12px;margin-top:6px">💬 السبب: ' . esc(mb_substr((string)$r['note'], 0, 300)) . '</div>';
                }
                echo '<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;padding-top:12px;border-top:1px dashed #bfdbfe">'
                   . '<input type="hidden" name="amz_act" value="pct_action"><input type="hidden" name="req_id" value="' . esc((string)$rid) . '">'
                   . '<label style="font-size:11px;font-weight:800">النسبة الجديدة %<br><input name="mk_pct" type="number" step="0.01" min="0.01" max="100" dir="ltr" required style="width:110px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<label style="font-size:11px;font-weight:800">ملاحظتك للمسوق<br><input name="admin_note" placeholder="اختياري" style="width:220px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<div style="display:flex;gap:6px"><button class="btn sm" style="background:#16a34a;color:#fff" name="do" value="approve">✅ موافقة</button>'
                   . '<button class="btn sm outline" name="do" value="reject" style="color:#b91c1c">⛔ رفض</button></div>'
                   . '<div style="flex-basis:100%;font-size:11px;color:#b45309">عمولة أمازون للقسم ده: <b dir="ltr">' . esc(amz_pct_txt($ratep)) . '%</b> — لو نسبة المسوق أعلى منها، نصيب الموقع بيطلع بالسالب.</div>'
                   . '</form>';
                echo '</div>';
            }
        }
        echo '</div>';

        // ✅ المعتمدة
        echo '<div class="adm-box"><h3>✅ معتمدة (' . count($am_by['approved']) . ')</h3>';
        if (!$am_by['approved']) echo '<p class="mut">لسه مفيش طلبات معتمدة.</p>';
        else {
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>المسوق</th><th>المنتج</th><th>سعر البيع</th><th>نسبة المسوق</th><th>يكسب على كل بيعة</th><th>Tracking ID</th><th>الموافقة</th></tr>';
            foreach ($am_by['approved'] as $rid => $r) {
                $L3 = (string)($r['link_id'] ?? '') !== '' ? ($amz_all[(string)$r['link_id']] ?? null) : null;
                $mku = find_marketer_by_code((string)($r['ref'] ?? ''));
                $mkt3 = (string)($L3['mkt'] ?? 'com');
                $ps3 = $L3 ? amz_per_sale($L3) : ['ok' => false];
                $tag3 = ($mku && isset($mku['amazon_tags'][$mkt3])) ? (string)$mku['amazon_tags'][$mkt3] : '';
                echo '<tr><td>' . esc($mku ? (string)($mku['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</td>'
                   . '<td>' . esc(mb_substr((string)(($r['title'] ?? '') !== '' ? $r['title'] : ($r['asin'] ?? '')), 0, 34)) . '</td>'
                   . '<td dir="ltr">' . ($L3 ? esc(amz_money((float)($L3['price'] ?? 0), amz_currency($mkt3))) : '—') . '</td>'
                   . '<td dir="ltr">' . (!empty($ps3['ok']) ? esc(rtrim(rtrim(number_format((float)$ps3['pct'], 2), '0'), '.')) . '%' : '—') . '</td>'
                   . '<td dir="ltr"><b style="color:#16a34a">' . (!empty($ps3['ok']) ? esc(number_format((float)$ps3['amount'], 2)) . ' ' . esc(amz_cur_label(amz_currency($mkt3))) : '—') . '</b></td>'
                   . '<td dir="ltr">' . ($tag3 !== '' ? esc($tag3) : '<span style="color:#b45309">— (التاج العام)</span>') . '</td>'
                   . '<td dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['approved_at'] ?? $r['at'] ?? ''), 0, 16)) . '</td></tr>';
            }
            echo '</table></div>';
        }
        echo '</div>';

        // ⛔ المرفوضة
        if ($am_by['rejected']) {
            echo '<div class="adm-box"><h3>⛔ مرفوضة (' . count($am_by['rejected']) . ')</h3>';
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>المسوق</th><th>المنتج</th><th>ملاحظتك</th><th>التاريخ</th></tr>';
            foreach ($am_by['rejected'] as $rid => $r) {
                $mku = find_marketer_by_code((string)($r['ref'] ?? ''));
                echo '<tr><td>' . esc($mku ? (string)($mku['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</td>'
                   . '<td>' . esc(mb_substr((string)(($r['title'] ?? '') !== '' ? $r['title'] : ($r['asin'] ?? '')), 0, 40)) . '</td>'
                   . '<td class="mut" style="font-size:11.5px">' . esc(mb_substr((string)($r['admin_note'] ?? ''), 0, 120)) . '</td>'
                   . '<td dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</td></tr>';
            }
            echo '</table></div></div>';
        }
        // 📈 تظلمات النسبة اللي اتعالجت (للمتابعة)
        $pct_done = $am_pct['approved'] + $am_pct['rejected'];
        if ($pct_done) {
            echo '<div class="adm-box"><h3>📈 تظلمات نسبة اتعالجت (' . count($pct_done) . ')</h3>';
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>المسوق</th><th>المنتج</th><th>من</th><th>إلى</th><th>القرار</th><th>ملاحظتك</th><th>التاريخ</th></tr>';
            foreach ($pct_done as $rid => $r) {
                $mkd  = find_marketer_by_code((string)($r['ref'] ?? ''));
                $isA  = (string)($r['status'] ?? '') === 'approved';
                echo '<tr><td>' . esc($mkd ? (string)($mkd['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</td>'
                   . '<td>' . esc(mb_substr((string)(($r['title'] ?? '') !== '' ? $r['title'] : ($r['asin'] ?? '')), 0, 34)) . '</td>'
                   . '<td dir="ltr">' . esc(amz_pct_txt($r['cur_pct'] ?? null)) . '%</td>'
                   . '<td dir="ltr"><b>' . ($isA ? (esc(amz_pct_txt($r['applied_pct'] ?? null)) . '%') : '—') . '</b></td>'
                   . '<td>' . ($isA ? '<span style="color:#16a34a;font-weight:800">✅ اتوافق</span>' : '<span style="color:#b91c1c;font-weight:800">⛔ اترفض</span>') . '</td>'
                   . '<td class="mut" style="font-size:11.5px">' . esc(mb_substr((string)($r['admin_note'] ?? ''), 0, 120)) . '</td>'
                   . '<td dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</td></tr>';
            }
            echo '</table></div></div>';
        }
    }

    // ------------------ الروابط ------------------
    elseif ($asec === 'links') {
        $fref = trim((string)($_GET['ref'] ?? ''));
        $fst  = trim((string)($_GET['st'] ?? ''));
        $rows = $amz_all;
        if ($fref !== '') $rows = array_filter($rows, function ($l) use ($fref) { return (string)($l['ref'] ?? '') === $fref; });
        if ($fst !== '')  $rows = array_filter($rows, function ($l) use ($fst) { return (string)($l['status'] ?? 'approved') === $fst; });
        $stl_map = ['approved' => '✅ معتمد', 'pending' => '⏳ بانتظار الموافقة', 'rejected' => '⛔ مرفوض'];
        echo '<div class="adm-box"><form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px">'
           . '<input type="hidden" name="tab" value="amazon"><input type="hidden" name="sec" value="links">'
           . '<label style="font-size:12px;font-weight:800">كود المسوق<br><input name="ref" value="' . esc($fref) . '" dir="ltr" style="padding:8px;border:1px solid var(--slate-200);border-radius:8px"></label>'
           . '<label style="font-size:12px;font-weight:800">الحالة<br><select name="st" style="padding:8px;border:1px solid var(--slate-200);border-radius:8px"><option value="">الكل</option>';
        foreach ($stl_map as $kk => $ll) echo '<option value="' . esc($kk) . '"' . ($fst === $kk ? ' selected' : '') . '>' . esc($ll) . '</option>';
        echo '</select></label><button class="btn sm">🔍 فلتر</button></form>';
        if (!$rows) {
            echo '<p class="mut">لا توجد روابط مطابقة.</p></div>';
        } else {
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>المسوق</th><th>المنتج</th><th>المتجر</th><th>القسم</th><th>سعر البيع</th><th>نسبة المسوق</th><th>نقرات</th><th>بيعات</th><th>أرباح المسوق معلقة</th><th>أرباح المسوق مؤكدة</th><th>الحالة</th><th>إجراءات</th></tr>';
            foreach ($rows as $lid3 => $l) {
                $mku = find_marketer_by_code((string)($l['ref'] ?? ''));
                $stx = (string)($l['status'] ?? 'approved');
                $mkt4 = (string)($l['mkt'] ?? 'com');
                $mm = $am_money[(string)$lid3] ?? ['sales' => 0, 'pending' => 0, 'confirmed' => 0];
                $tag4 = ($mku && isset($mku['amazon_tags'][$mkt4])) ? (string)$mku['amazon_tags'][$mkt4] : '';
                $pct4 = amz_mk_pct($l);
                echo '<tr>';
                echo '<td>' . esc($mku ? (string)($mku['name'] ?? $l['ref']) : (string)($l['ref'] ?? '')) . '<br><span class="mut" dir="ltr" style="font-size:10.5px">' . esc((string)($l['ref'] ?? '')) . '</span></td>';
                echo '<td style="max-width:180px">' . esc(mb_substr((string)($l['title'] ?? ''), 0, 26) ?: (string)($l['asin'] ?? '')) . '<br><span class="mut" dir="ltr" style="font-size:10.5px">' . esc((string)($l['asin'] ?? '')) . '</span></td>';
                echo '<td>' . esc(amz_mkt_label($mkt4)) . '</td>';
                echo '<td>' . esc(amz_cat_label((string)($l['cat'] ?? 'other'))) . '</td>';
                echo '<td dir="ltr">' . (((float)($l['price'] ?? 0)) > 0 ? esc(amz_money((float)$l['price'], amz_currency($mkt4))) : '<span class="mut">—</span>') . '</td>';
                echo '<td dir="ltr">' . ($pct4 !== null ? '<b>' . esc(rtrim(rtrim(number_format($pct4, 2), '0'), '.')) . '%</b>' : '<span style="color:#b45309">لم تُحدد</span>') . '</td>';
                echo '<td>' . number_format((int)($l['clicks'] ?? 0)) . '</td>';
                echo '<td>' . number_format((int)$mm['sales']) . '</td>';
                echo '<td dir="ltr" style="color:#b45309">' . number_format((float)$mm['pending'], 2) . '</td>';
                echo '<td dir="ltr" style="color:#15803d;font-weight:800">' . number_format((float)$mm['confirmed'], 2) . '</td>';
                echo '<td>' . esc($stl_map[$stx] ?? $stx) . (!empty($l['no_tag']) ? '<br><span style="color:#b91c1c;font-size:10.5px">⚠️ بدون Tracking ID</span>' : '') . '</td>';
                echo '<td><div style="display:flex;gap:4px;flex-wrap:wrap">';
                echo '<button type="button" class="btn sm outline" style="padding:4px 8px;font-size:11px" onclick="document.getElementById(\'amzed_' . esc((string)$lid3) . '\').style.display=\'table-row\'" aria-label="تعديل الرابط">⚙️</button>';
                echo '<a class="btn sm outline" style="padding:4px 8px;font-size:11px" target="_blank" rel="nofollow noopener" href="' . esc(amz_out_url($l)) . '" aria-label="فتح على أمازون">🔎</a>';
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'حذف الرابط؟\')"><input type="hidden" name="amz_act" value="link_status"><input type="hidden" name="link_id" value="' . esc((string)$lid3) . '"><button class="btn sm outline" style="padding:4px 8px;font-size:11px;color:#b91c1c" name="do" value="delete" aria-label="حذف الرابط">🗑️</button></form>';
                echo '</div></td></tr>';
                // نموذج التعديل: السعر + نسبة المسوق + القسم + Tracking ID + الحالة
                echo '<tr id="amzed_' . esc((string)$lid3) . '" style="display:none;background:#f8fafc"><td colspan="12">';
                echo '<form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">';
                echo '<input type="hidden" name="amz_act" value="link_save"><input type="hidden" name="link_id" value="' . esc((string)$lid3) . '">';
                echo '<label style="font-size:11px;font-weight:800">Tracking ID<br><input name="tag" dir="ltr" value="' . esc($tag4) . '" placeholder="' . esc(amz_tag($mkt4) ?: 'yourtag-20') . '" style="width:130px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>';
                echo '<label style="font-size:11px;font-weight:800">سعر البيع (' . esc(amz_cur_label(amz_currency($mkt4))) . ')<br><input name="price" dir="ltr" value="' . esc((float)($l['price'] ?? 0) > 0 ? rtrim(rtrim(number_format((float)$l['price'], 2, '.', ''), '0'), '.') : '') . '" style="width:90px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>';
                echo '<label style="font-size:11px;font-weight:800">نسبة المسوق %<br><input name="mk_pct" dir="ltr" value="' . esc($pct4 !== null ? rtrim(rtrim(number_format($pct4, 2, '.', ''), '0'), '.') : '') . '" style="width:72px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>';
                echo '<label style="font-size:11px;font-weight:800">القسم<br><select name="cat" style="padding:7px;border:1px solid var(--slate-200);border-radius:8px;max-width:220px">';
                foreach (amz_categories() as $slug => $c) echo '<option value="' . esc($slug) . '"' . ((string)($l['cat'] ?? '') === $slug ? ' selected' : '') . '>' . esc($c[0]) . '</option>';
                echo '</select></label>';
                echo '<label style="font-size:11px;font-weight:800">عمولة أمازون % (للحساب)<br><input name="rate" dir="ltr" value="' . esc((string)($l['rate'] ?? '')) . '" style="width:80px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>';
                echo '<label style="font-size:11px;font-weight:800">الحالة<br><select name="status" style="padding:7px;border:1px solid var(--slate-200);border-radius:8px">';
                foreach ($stl_map as $kk => $ll) echo '<option value="' . esc($kk) . '"' . ($stx === $kk ? ' selected' : '') . '>' . esc($ll) . '</option>';
                echo '</select></label>';
                echo '<button class="btn sm">💾 حفظ</button></form>';
                echo '<div style="font-size:11px;margin-top:6px" class="mut" dir="ltr">track: ' . esc(amz_track_url((string)$lid3)) . '</div>';
                echo '</td></tr>';
            }
            echo '</table></div></div>';
        }
    }

    // ------------------ المبيعات والأرباح ------------------
    elseif ($asec === 'conversions') {
        // نموذج تسجيل بيعة يدوياً
        echo '<div class="adm-box"><h3>➕ تسجيل بيعة يدوياً</h3>';
        echo '<form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">';
        echo '<input type="hidden" name="amz_act" value="add_conv">';
        echo '<label style="font-size:12px;font-weight:800">الرابط<br><select name="link_id" style="padding:9px;border:1px solid var(--slate-200);border-radius:9px;max-width:300px">';
        foreach ($amz_all as $lid => $l) {
            $mku = find_marketer_by_code((string)($l['ref'] ?? ''));
            echo '<option value="' . esc((string)$lid) . '">' . esc(($mku ? (string)($mku['name'] ?? $l['ref']) : (string)($l['ref'] ?? '')) . ' — ' . (mb_substr((string)($l['title'] ?? ''), 0, 24) ?: (string)($l['asin'] ?? ''))) . '</option>';
        }
        echo '</select></label>';
        echo '<label style="font-size:12px;font-weight:800">المبلغ (ج.م)<br><input name="value" placeholder="300" style="width:100px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<label style="font-size:12px;font-weight:800">الكمية<br><input name="qty" value="1" style="width:60px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<label style="font-size:12px;font-weight:800">أرباح المسوق (ج.م) — اتركها فاضية = من السعر × النسبة<br><input name="mk_egp" placeholder="30" style="width:150px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<label style="font-size:12px;font-weight:800">عمولة أمازون الفعلية % (اختياري، فاضية = نسبة القسم)<br><input name="comm" placeholder="5" style="width:110px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<label style="font-size:12px;font-weight:800">الحالة<br><select name="conv_status" style="padding:9px;border:1px solid var(--slate-200);border-radius:9px"><option value="pending">معلقة</option><option value="confirmed">مؤكدة (تتحول لرصيد المسوق)</option></select></label>';
        echo '<label style="font-size:12px;font-weight:800">ملاحظة<br><input name="note" style="width:130px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<button class="btn pri">💾 تسجيل البيعة</button></form></div>';

        if (!empty($GLOBALS['_AMZ_IMPORT_REPORT']) && !empty($GLOBALS['_AMZ_IMPORT_REPORT']['unmatched'])) {
            $rep = $GLOBALS['_AMZ_IMPORT_REPORT'];
            echo '<div class="adm-box" style="border-color:#fde68a;background:#fffbeb"><h3>⚠️ صفوف لم تُطابق (' . count($rep['unmatched']) . ')</h3>';
            echo '<table style="font-size:12px"><tr><th>ASIN</th><th>Tracking ID</th><th>التاريخ</th><th>الإيراد</th><th>العمولة</th></tr>';
            foreach (array_slice($rep['unmatched'], 0, 40) as $u) {
                echo '<tr><td dir="ltr">' . esc((string)($u['asin'] ?? '')) . '</td><td dir="ltr">' . esc((string)($u['tag'] ?? '')) . '</td>'
                   . '<td dir="ltr">' . esc((string)($u['date'] ?? '')) . '</td><td>' . esc(number_format((float)($u['price'] ?? 0), 2)) . '</td>'
                   . '<td>' . esc(number_format((float)($u['comm'] ?? 0), 2)) . '</td></tr>';
            }
            echo '</table><p class="mut" style="font-size:11.5px">الصفوف دي معندهاش رابط معتمد أو تاج معروف — وافق على الرابط للمسوق أو سلّمه تاجه واستورد الملف تاني.</p></div>';
        }

        echo '<div class="adm-box"><h3>🧾 كل البيعات (' . count($amz_ord) . ') — عدّل أرباح المسوق من هنا</h3>';
        echo '<p class="mut" style="font-size:12px;margin-bottom:10px">النقرات بتتسجل تلقائياً. أرباح المسوق (معلقة أو مؤكدة) بتحددها الإدارة — عدّل المبلغ واضغط 💾 وهيتحدّث رصيد المسوق والكارت.</p>';
        if (!$amz_ord) echo '<p class="mut">لا توجد بيعات بعد.</p>';
        else {
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>التاريخ</th><th>المسوق</th><th>المنتج</th><th>الكمية</th><th>الطلب</th><th>عمولة أمازون</th><th>أرباح المسوق (ج.م)</th><th>نصيب الموقع</th><th>المصدر</th><th>الحالة</th><th>إجراء</th></tr>';
            foreach ($amz_ord as $o) {
                $mku = find_marketer_by_code((string)($o['ref'] ?? ''));
                $os = (string)($o['status'] ?? 'pending');
                $cid_o = (string)($o['id'] ?? '');
                echo '<tr' . ($os === 'reversed' ? ' style="opacity:.6"' : '') . '>';
                echo '<td dir="ltr" style="font-size:11px">' . esc(substr((string)($o['at'] ?? ''), 0, 16)) . '</td>';
                echo '<td>' . esc($mku ? (string)($mku['name'] ?? $o['ref']) : (string)($o['ref'] ?? '')) . '</td>';
                echo '<td style="max-width:150px">' . esc(mb_substr((string)($o['title'] ?? ''), 0, 22) ?: (string)($o['asin'] ?? ''))
                   . '<br><span class="mut" dir="ltr" style="font-size:10.5px">' . esc((string)($o['asin'] ?? '')) . '</span></td>';
                echo '<td>' . number_format((int)($o['qty'] ?? 1)) . '</td>';
                echo '<td dir="ltr">' . esc(number_format((float)($o['gross'] ?? 0), 2)) . ' ' . esc((string)($o['currency'] ?? '')) . '</td>';
                echo '<td dir="ltr">' . esc(number_format((float)($o['amz_comm'] ?? 0), 2)) . '</td>';
                echo '<td dir="ltr">';
                if ($os === 'reversed') {
                    echo '<span class="mut">' . esc(number_format((float)($o['mk_egp'] ?? 0), 2)) . '</span>';
                } else {
                    echo '<form method="post" style="display:flex;gap:4px;align-items:center"><input type="hidden" name="amz_act" value="conv_edit"><input type="hidden" name="conv_id" value="' . esc($cid_o) . '">'
                       . '<input name="mk_egp" dir="ltr" value="' . esc(number_format((float)($o['mk_egp'] ?? 0), 2, '.', '')) . '" style="width:78px;padding:5px;border:1px solid var(--slate-200);border-radius:7px;font-weight:800;color:#15803d">'
                       . '<button class="btn sm outline" style="padding:4px 8px;font-size:11px" title="حفظ" aria-label="حفظ">💾</button></form>';
                }
                echo '</td>';
                $am_site = (float)($o['site_egp'] ?? 0);
                if ($am_site < 0) {
                    echo '<td dir="ltr" style="color:#b91c1c;font-weight:800">' . esc(number_format($am_site, 2)) . '<br><span style="font-size:10px;font-weight:700">⚠️ الموقع بيدفع الفرق</span></td>';
                } else {
                    echo '<td dir="ltr" class="mut">' . esc(number_format($am_site, 2)) . '</td>';
                }
                echo '<td>' . esc((string)($o['source'] ?? '')) . '</td>';
                echo '<td>' . ($os === 'confirmed' ? '✅ مؤكدة' : ($os === 'reversed' ? '↩️ ملغية' : '⏳ معلقة')) . '</td>';
                echo '<td>';
                if ($os === 'pending') {
                    echo '<form method="post" style="display:flex;gap:4px"><input type="hidden" name="amz_act" value="conv_action"><input type="hidden" name="conv_id" value="' . esc($cid_o) . '">'
                       . '<button class="btn sm" style="background:#16a34a;color:#fff;padding:4px 8px;font-size:11px" name="do" value="confirm">تأكيد</button>'
                       . '<button class="btn sm outline" style="padding:4px 8px;font-size:11px;color:#b91c1c" name="do" value="reverse" onclick="this.form.reason.value=\'إلغاء من الإدارة\'">إلغاء</button>'
                       . '<input type="hidden" name="reason" value=""></form>';
                } elseif ($os === 'confirmed') {
                    echo '<form method="post" style="display:inline"><input type="hidden" name="amz_act" value="conv_action"><input type="hidden" name="conv_id" value="' . esc($cid_o) . '">'
                       . '<input type="hidden" name="reason" value="إلغاء بعد التأكيد">'
                       . '<button class="btn sm outline" style="padding:4px 8px;font-size:11px;color:#b91c1c" name="do" value="reverse">↩️ إلغاء</button></form>';
                } else { echo '—'; }
                echo '</td></tr>';
            }
            echo '</table></div>';
        }
        echo '</div>';
    }

    // ------------------ تاجات المسوقين ------------------
    elseif ($asec === 'tags') {
        echo '<div class="adm-box"><h3>🏷️ تاج (Tracking ID) لكل مسوق</h3>';
        echo '<p class="mut" style="font-size:12px;line-height:1.9;margin-bottom:14px">'
           . '<b>لأدق تتبع:</b> أنشئ Tracking ID مستقل لكل مسوق داخل حساب أمازون أسوشيتس (Account Settings → Manage your Tracking IDs) وسجله هنا. '
           . 'تقرير أرباح أمازون بيحتوي على عمود Tracking ID → الاستيراد بيطابق كل بيعة على مسوقها تلقائياً. '
           . 'لو المسوق مالهوش تاج، بيستخدم التاج العام للموقع وتتم المطابقة برقم المنتج (ASIN).</p>';
        $mks = array_values(array_filter(users(), function ($u) { return !empty($u['ref_code']); }));
        if (!$mks) echo '<p class="mut">لا يوجد مسوقين.</p>';
        else {
            echo '<table style="font-size:12px"><tr><th>المسوق</th><th>الكود</th><th>🇺🇸 com</th><th>🇸🇦 sa</th><th>🇦🇪 ae</th><th>نصيب قديم %</th><th>حفظ</th></tr>';
            foreach ($mks as $u) {
                $tags = isset($u['amazon_tags']) && is_array($u['amazon_tags']) ? $u['amazon_tags'] : [];
                echo '<tr><form method="post">';
                echo '<input type="hidden" name="amz_act" value="mk_tags"><input type="hidden" name="email" value="' . esc((string)($u['email'] ?? '')) . '">';
                echo '<td>' . esc((string)($u['name'] ?? '')) . '<br><span class="mut" style="font-size:10.5px">' . esc((string)($u['email'] ?? '')) . '</span></td>';
                echo '<td dir="ltr">' . esc((string)($u['ref_code'] ?? '')) . '</td>';
                foreach (['com', 'sa', 'ae'] as $k) {
                    echo '<td><input name="tag_' . $k . '" value="' . esc((string)($tags[$k] ?? '')) . '" dir="ltr" placeholder="' . esc(amz_tag($k) ?: 'تاج عام') . '" style="width:130px;padding:6px;border:1px solid var(--slate-200);border-radius:8px;font-size:11px"></td>';
                }
                echo '<td><input name="share" value="' . esc(isset($u['amazon_share']) ? (string)$u['amazon_share'] : '') . '" placeholder="' . esc(number_format(amz_share_default(), 0)) . '" style="width:70px;padding:6px;border:1px solid var(--slate-200);border-radius:8px"></td>';
                echo '<td><button class="btn sm">💾</button></td>';
                echo '</form></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    // ------------------ الإعدادات ------------------
    elseif ($asec === 'settings') {
        // تصفير الإحصائيات: زر واضح بتأكيد — البيانات ترجع جديدة من صفر
        echo '<div class="adm-box" style="margin-bottom:12px"><h3>🧹 تصفير بيانات أمازون</h3>'
           . '<p class="mut" style="font-size:12.5px">بيمسح سجل النقرات وسجل التحويلات ويصفّر عدّادات كل رابط (نقرات/فريد/طلبات/أرباح) — الإحصائيات تبدأ جديدة من لحظة التصفير. الروابط المعتمدة والإعدادات وطلبات المسوقين لا تُلمس.</p>'
           . '<form method="post" data-confirm="سيتم تصفير كل إحصائيات أمازون (سجل النقرات والتحويلات وعدّادات الروابط) ولن يمكن التراجع. هل تريد المتابعة؟" style="display:inline"><input type="hidden" name="amz_act" value="reset_stats"><button class="btn" style="background:#b91c1c;border-color:#b91c1c;color:#fff">تصفير الإحصائيات والبدء من جديد</button></form></div>';
        echo '<form method="post"><input type="hidden" name="amz_act" value="settings">';
        echo '<div class="adm-box"><h3>⚙️ ضبط النظام</h3><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">';
        $on = amz_enabled();
        echo '<label style="font-size:13px;font-weight:800;display:flex;gap:8px;align-items:center"><input type="checkbox" name="amz_enabled" value="1"' . ($on ? ' checked' : '') . '> تفعيل نظام أفلييت أمازون للمسوقين</label>';
        echo '<label style="font-size:12.5px;font-weight:800">نصيب المسوق الافتراضي من عمولة أمازون (%) — للروابط القديمة بس<br><input name="amz_mk_share" value="' . esc(number_format(amz_share_default(), 2)) . '" style="width:100%;padding:9px;border:1px solid var(--slate-200);border-radius:9px;margin-top:4px"></label>';
        echo '</div></div>';

        echo '<div class="adm-box"><h3>🏷️ التاج العام (Partner Tag) لكل متجر أمازون</h3>';
        echo '<p class="mut" style="font-size:12px;margin-bottom:12px">التاج ده هو رقم حسابك في برنامج أمازون أسوشيتس (مثال: <span dir="ltr">wafarnahalk-20</span>). بدونه مفيش عمولة هتتحسب. ده التاج اللي بيتستخدم لو المسوق مالوش Tracking ID خاص.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">';
        foreach (amz_marketplaces() as $k => $m) {
            echo '<label style="font-size:12px;font-weight:800">' . esc($m['flag'] . ' ' . $m['label']) . '<br>'
               . '<input name="amz_tag_' . esc($k) . '" dir="ltr" value="' . esc(amz_tag($k)) . '" placeholder="yourtag-20" style="width:100%;padding:9px;border:1px solid var(--slate-200);border-radius:9px;margin-top:4px"></label>';
        }
        echo '</div></div>';

        echo '<div class="adm-box"><h3>📊 نسبة عمولة أمازون لكل قسم</h3>';
        echo '<p class="mut" style="font-size:12px;margin-bottom:12px">دي النسبة اللي <b>أمازون بتدفعها للموقع</b> (الافتراضي حسب سياسة أمازون 2026 — عدّلها من هنا في أي وقت). بتتستخدم لحساب نصيب الموقع.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px">';
        foreach (amz_categories() as $slug => $c) {
            echo '<label style="font-size:12px;font-weight:800">' . esc($c[0]) . '<br>'
               . '<input name="amz_rate_' . esc($slug) . '" value="' . esc(number_format(amz_rate($slug), 2)) . '" style="width:100%;padding:9px;border:1px solid var(--slate-200);border-radius:9px;margin-top:4px"></label>';
        }
        echo '</div></div>';

        echo '<div class="adm-box"><h3>💱 أسعار العملات (تحويل العمولة للجنيه)</h3>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">';
        foreach (amz_fx_defaults() as $cur => $d) {
            if ($cur === 'EGP') continue;
            echo '<label style="font-size:12px;font-weight:800">1 ' . esc($cur) . ' = كام ج.م<br>'
               . '<input name="amz_fx_' . esc($cur) . '" value="' . esc(number_format(amz_fx($cur), 2)) . '" style="width:100%;padding:9px;border:1px solid var(--slate-200);border-radius:9px;margin-top:4px"></label>';
        }
        echo '</div></div>';

        echo '<button class="btn pri" style="padding:12px 28px">💾 حفظ كل إعدادات أمازون</button></form>';

        echo '<div class="adm-box" style="margin-top:14px;font-size:12.5px;line-height:1.9"><h3>📖 ملاحظات مهمة</h3><ul style="padding-right:18px">'
           . '<li>المسوق بيشوف المبلغ اللي هياخده على كل بيعة = <b>سعر البيع × نسبته</b> (مثال: 300 × 10% = 30 ج.م).</li>'
           . '<li>النقرات بتتسجل تلقائياً. أرباح المسوق (معلقة / مؤكدة) بتتحدد من <b>تبويب المبيعات والأرباح</b> — تقدر تعدّلها في أي وقت، والرصيد بيتعدل بالفرق.</li>'
           . '<li>نصيب الموقع = عمولة أمازون − أرباح المسوق. الاتنين مسجلين في كل بيعة وفي إجماليات التقرير.</li>'
           . '<li>العمولة بتتسجل <b>معلقة</b> للمسوق، وبتتحول <b>متاحة للسحب</b> لما تأكدها من هنا (بعد ما أمازون تدفع فعلاً).</li>'
           . '<li>أمازون تمنع وضع روابط الأفلييت داخل إيميل أو PDF أو إعلانات مدفوعة تخفي الرابط — استخدم الرابط المباشر في الإعلانات الممولة.</li>'
           . '</ul></div>';
    }
}

// =========================================================================
// 18. TAB: SETTINGS (إعدادات الموقع)
// =========================================================================
elseif ($tab === 'jumia' && function_exists('jmi_stats')) {
    $asec = (string)($_GET['sec'] ?? 'overview');
    if (!in_array($asec, ['overview', 'requests', 'links', 'conversions', 'settings'], true)) $asec = 'overview';
    echo cust_admin_html('jumia');
    $jmi_msg = '';
    $jmi_err = false;

    // ======================== إجراءات ========================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = (string)($_POST['jmi_act'] ?? '');
        $lid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['link_id'] ?? ''));

        // ---- حفظ الإعدادات ----
        if ($act === 'settings') {
            $save = [];
            $save['jmi_enabled'] = isset($_POST['jmi_enabled']) ? '1' : '0';
            foreach (jmi_categories() as $slug => $c) {
                $v = trim((string)($_POST['jmi_rate_' . $slug] ?? ''));
                $save['jmi_rate_' . $slug] = ($v !== '' && is_numeric($v)) ? (string)max(0, min(100, (float)$v)) : '';
            }
            jupdate('settings.json', function (&$stt) use ($save) {
                if (!is_array($stt)) $stt = [];
                foreach ($save as $kk => $vv) $stt[$kk] = $vv;
                return true;
            }, []);
            log_audit('jumia_settings', '-', array_keys($save));
            $jmi_msg = '✅ تم حفظ إعدادات جوميا';
        }

        // ---- حذف رابط ----
        if ($act === 'link_status' && $lid !== '') {
            $l = jmi_link($lid);
            if ($l && (string)($_POST['do'] ?? '') === 'delete') {
                jmi_link_delete($lid);
                log_audit('jumia_link_delete', $lid, ['pid' => $l['pid'] ?? '']);
                $mk_d = find_marketer_by_code((string)($l['ref'] ?? ''));
                if ($mk_d && !empty($mk_d['email'])) {
                    $dt = (string)($l['title'] ?? '') !== '' ? (string)$l['title'] : (string)($l['pid'] ?? '');
                    notify_user((string)$mk_d['email'], '🗑️ الإدارة حذفت الرابط ' . $dt . ' من الروابط بتاعتك', 'dashboard/jumia.php?tab=links');
                }
                $jmi_msg = '🗑️ تم حذف الرابط';
            }
        }

        // ---- تعديل رابط: السعر + نسبة المسوق + القسم + لينك التتبع + الحالة ----
        if ($act === 'link_save' && $lid !== '') {
            $res = jmi_admin_link_save($lid, [
                'cat'    => (string)($_POST['cat'] ?? ''),
                'rate'   => (string)($_POST['rate'] ?? ''),
                'price'  => (string)($_POST['price'] ?? ''),
                'mk_pct' => (string)($_POST['mk_pct'] ?? ''),
                'aff_url' => (string)($_POST['aff_url'] ?? ''),
                'status' => (string)($_POST['status'] ?? ''),
            ]);
            if (!empty($res['ok'])) {
                $jmi_msg = '✅ تم حفظ إعدادات الرابط' . (!empty($res['no_aff']) ? ' • ⚠️ المسوق ده لسه من غير لينك التتبع' : '');
            } else {
                $jmi_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الحفظ');
                $jmi_err = true;
            }
        }

        // ---- طلبات المنتجات: موافقة (مع لينك التتبع والسعر والنسبة) أو رفض ----
        if ($act === 'req_action') {
            $rid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['req_id'] ?? ''));
            $do  = (string)($_POST['do'] ?? '');
            if ($do === 'approve') {
                $res = jmi_approve_request($rid, [
                    'aff_url'    => (string)($_POST['aff_url'] ?? ''),
                    'price'      => (string)($_POST['price'] ?? ''),
                    'mk_pct'     => (string)($_POST['mk_pct'] ?? ''),
                    'cat'        => (string)($_POST['cat'] ?? ''),
                    'admin_note' => (string)($_POST['admin_note'] ?? ''),
                ]);
                if (!empty($res['ok'])) {
                    $ps = $res['per_sale'] ?? null;
                    $jmi_msg = '✅ تمت الموافقة — الرابط ظهر للمسوق في «الروابط المعتمدة»'
                             . (($ps && !empty($ps['ok'])) ? ' • هياخد ' . number_format((float)$ps['amount'], 2) . ' ' . jmi_cur_label($ps['currency']) . ' على كل بيعة' : '')
                             . (!empty($res['no_aff']) ? ' • ⚠️ من غير لينك تتبع (المسوق مش هيكسب لحد ما تضيفه)' : '');
                } else {
                    $jmi_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الموافقة');
                    $jmi_err = true;
                }
            } elseif ($do === 'reject') {
                $res = jmi_reject_request($rid, (string)($_POST['admin_note'] ?? ''));
                if (!empty($res['ok'])) {
                    $jmi_msg = '⛔ تم رفض الطلب';
                } else {
                    $jmi_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الرفض');
                    $jmi_err = true;
                }
            }
        }

        // ---- تظلم على نسبة المسوق: موافقة (بنسبة تحددها الإدارة) أو رفض ----
        if ($act === 'pct_action') {
            $rid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['req_id'] ?? ''));
            $do  = (string)($_POST['do'] ?? '');
            $anote = is_string($_POST['admin_note'] ?? null) ? $_POST['admin_note'] : '';
            if ($do === 'approve') {
                $res = jmi_approve_pct_request($rid, [
                    'mk_pct'     => is_string($_POST['mk_pct'] ?? null) ? $_POST['mk_pct'] : '',
                    'admin_note' => $anote,
                ]);
                if (!empty($res['ok'])) {
                    $jmi_msg = '✅ اتوافق على التظلم — النسبة بقت ' . jmi_pct_txt($res['to'] ?? null) . '% (على البيعات الجاية)';
                } else {
                    $jmi_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الموافقة');
                    $jmi_err = true;
                }
            } elseif ($do === 'reject') {
                $res = jmi_reject_pct_request($rid, $anote);
                if (!empty($res['ok'])) {
                    $jmi_msg = '⛔ اترفض التظلم — النسبة فضلت زي ما هي';
                } else {
                    $jmi_msg = '❌ ' . (string)($res['error'] ?? 'تعذر الرفض');
                    $jmi_err = true;
                }
            }
        }

        // ---- تسجيل بيعة يدوياً ----
        if ($act === 'add_conv' && $lid !== '') {
            $value = (float)($_POST['value'] ?? 0);
            $qty   = max(1, (int)($_POST['qty'] ?? 1));
            $comm  = (isset($_POST['comm']) && trim((string)$_POST['comm']) !== '' && is_numeric($_POST['comm'])) ? (float)$_POST['comm'] : null;
            $mk_e  = (isset($_POST['mk_egp']) && trim((string)$_POST['mk_egp']) !== '' && is_numeric($_POST['mk_egp'])) ? (float)$_POST['mk_egp'] : null;
            $status = ((string)($_POST['conv_status'] ?? 'pending') === 'confirmed') ? 'confirmed' : 'pending';
            $res = jmi_add_conversion($lid, $value, $qty, [
                'jmi_comm' => $comm,
                'mk_egp'   => $mk_e,
                'status'   => $status,
                'source'   => 'admin',
                'note'     => (string)($_POST['note'] ?? ''),
            ]);
            if (!empty($res['ok'])) {
                $c = $res['conv'];
                $jmi_msg = '✅ تم تسجيل البيعة — أرباح المسوق ' . number_format($c['mk_egp'], 2) . ' ج.م • نصيب الموقع ' . number_format($c['site_egp'], 2) . ' ج.م';
                if ((float)$c['site_egp'] < 0) $jmi_msg .= ' • ⚠️ نصيب الموقع بالسالب: أرباح المسوق أكبر من عمولة جوميا، راجع الأرقام';
                log_audit('jumia_conv_add', (string)$c['id'], ['link' => $lid, 'gross' => $c['gross'], 'mk' => $c['mk_egp'], 'site' => $c['site_egp']]);
            } else {
                $jmi_msg = '❌ ' . (string)($res['error'] ?? 'تعذر تسجيل البيعة');
                $jmi_err = true;
            }
        }

        // ---- تأكيد / إلغاء بيعة ----
        if ($act === 'conv_action') {
            $cid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['conv_id'] ?? ''));
            $do  = (string)($_POST['do'] ?? '');
            if ($do === 'confirm') {
                $c = jmi_confirm_conversion($cid);
                if ($c) { log_audit('jumia_conv_confirm', $cid, ['mk' => $c['mk_egp']]); $jmi_msg = '✅ تم تأكيد أرباح المسوق وانتقلت لرصيده المتاح'; }
            } elseif ($do === 'reverse') {
                $c = jmi_reverse_conversion($cid, (string)($_POST['reason'] ?? 'إلغاء من الإدارة'));
                if ($c) { log_audit('jumia_conv_reverse', $cid, ['mk' => $c['mk_egp']]); $jmi_msg = '↩️ تم إلغاء البيعة وخصم أرباحها من المسوق'; }
            }
        }

        // ---- تعديل أرباح المسوق على بيعة (معلقة أو مؤكدة) ----
        if ($act === 'conv_edit') {
            $cid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['conv_id'] ?? ''));
            $val = trim((string)($_POST['mk_egp'] ?? ''));
            if ($val === '' || !is_numeric($val) || (float)$val < 0) {
                $jmi_msg = '❌ اكتب مبلغ صحيح (0 أو أكبر)';
                $jmi_err = true;
            } else {
                $res = jmi_set_conv_earn($cid, (float)$val, 'تعديل الإدارة للعمولة');
                if (!empty($res['ok'])) {
                    $jmi_msg = '✅ اتحفظ مبلغ أرباح المسوق على البيعة — الرصيد والكروت اتحدّثوا';
                } else {
                    $jmi_msg = '❌ ' . (string)($res['error'] ?? 'تعذر التعديل');
                    $jmi_err = true;
                }
            }
        }

        // ---- استيراد تقرير جوميا CSV ----
        if ($act === 'import') {
            $text = '';
            if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file((string)$_FILES['csv_file']['tmp_name'])) {
                $text = (string)file_get_contents((string)$_FILES['csv_file']['tmp_name']);
            } elseif (trim((string)($_POST['csv_text'] ?? '')) !== '') {
                $text = (string)$_POST['csv_text'];
            }
            if (trim($text) === '') {
                $jmi_msg = '❌ مفيش ملف أو نص — ارفع ملف CSV أو الصق محتواه';
                $jmi_err = true;
            } else {
                $rep = jmi_import_csv($text, ['status' => ((string)($_POST['import_status'] ?? 'pending') === 'confirmed' ? 'confirmed' : 'pending')]);
                if (empty($rep['ok'])) {
                    $jmi_msg = '❌ ' . (string)($rep['error'] ?? 'فشل الاستيراد');
                    $jmi_err = true;
                } else {
                    $GLOBALS['_JMI_IMPORT_REPORT'] = $rep;
                    $jmi_msg = '✅ تم استيراد ' . (int)$rep['imported'] . ' بيعة (من ' . (int)$rep['rows'] . ' صف) — عمولة جوميا الإجمالية ' . number_format((float)$rep['total_comm'], 2)
                             . ' • أرباح المسوقين ' . number_format((float)$rep['total_mk'], 2) . ' ج.م • نصيب الموقع ' . number_format((float)$rep['total_site'], 2) . ' ج.م'
                             . (count($rep['unmatched']) ? ' • ⚠️ ' . count($rep['unmatched']) . ' صف غير مطابق (مش مضاف لسه)' : '');
                    log_audit('jumia_import', '-', ['imported' => $rep['imported'], 'rows' => $rep['rows'], 'unmatched' => count($rep['unmatched'])]);
                }
            }
        }

    }

    // ======================== بيانات العرض ========================
    $jmi_st   = jmi_stats();
    $jmi_all  = jmi_links_all();
    $jmi_ord  = jmi_orders_all();
    $jmi_reqs = jmi_reqs_all();
    $am_money = jmi_money_by_link();
    uasort($jmi_all, function ($a, $b) { return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')); });
    usort($jmi_ord, function ($a, $b) { return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')); });
    $am_reqs_pending = array_filter($jmi_reqs, function ($r) { return (string)($r['status'] ?? '') === 'pending' && in_array((string)($r['type'] ?? ''), ['approval', 'pct'], true); });
    $am_req_n = count($am_reqs_pending);
    $am_approved_n = count(array_filter($jmi_all, function ($l) { return (string)($l['status'] ?? 'approved') === 'approved'; }));

    if ($jmi_msg) {
        echo '<div style="padding:14px 18px;background:' . ($jmi_err ? '#fee2e2;border:1px solid #fecaca;color:#991b1b' : '#dcfce7;border:1px solid #86efac;color:#166534')
           . ';border-radius:12px;margin-bottom:16px;font-weight:800">' . esc($jmi_msg) . '</div>';
    }

    echo '<div class="adm-box" style="background:linear-gradient(135deg,#0f172a,#232f3e);color:#fff;border:none">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px">';
    echo '<div><h3 style="color:#ff9900;margin:0;font-size:18px">🛍️ أفلييت جوميا — طلبات المنتجات والعمولات</h3>'
       . '<div style="font-size:12px;color:#cbd5e1;margin-top:4px">المسوق بيبعت طلب منتج → الإدارة بتلصق لينك التتبع وتحدد السعر والنسبة وتوافق → النقرات بتتسجل تلقائياً، والأرباح بتحددها الإدارة.</div></div>';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap">'
       . '<a class="btn sm" style="background:#ff9900;color:#111" href="?tab=jumia&sec=requests">📨 طلبات المنتجات (' . $am_req_n . ')</a>'
       . '<a class="btn sm outline" style="color:#fff;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.35)" href="?tab=jumia&sec=conversions">🧾 المبيعات والأرباح</a>'
       . '<a class="btn sm outline" style="color:#fff;background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.35)" href="?tab=jumia&sec=settings">⚙️ الإعدادات</a>'
       . '</div></div>';
    echo '<div class="adm-main-counters">';
    foreach ([
        ['📨', 'طلبات بانتظار موافقتك', number_format($am_req_n), $am_req_n > 0 ? 'محتاجة مراجعة' : 'مفيش طلبات مفتوحة'],
        ['🔗', 'الروابط المعتمدة', number_format($am_approved_n), number_format(count($jmi_all) - $am_approved_n) . ' مش معتمدة'],
        ['👆', 'النقرات', number_format($jmi_st['clicks']), number_format($jmi_st['uniq']) . ' زائر فريد'],
        ['🛒', 'البيعات', number_format($jmi_st['orders']), 'قيمة: ' . number_format($jmi_st['gross'], 2)],
        ['🤝', 'عمولة المسوقين', number_format($jmi_st['mk_egp'], 2) . ' ج.م', number_format($jmi_st['confirmed_conv']) . ' مؤكدة • ' . number_format($jmi_st['pending_conv']) . ' معلقة'],
        ['💰', 'عمولة الموقع', number_format($jmi_st['site_egp'], 2) . ' ج.م', $jmi_st['site_egp'] < 0 ? '⚠️ الموقع بيدفع الفرق (نسبة المسوق أعلى من عمولة جوميا)' : 'من إجمالي عمولة جوميا ' . number_format($jmi_st['jmi_comm'], 2)],
    ] as $k) {
        echo '<div class="adm-big-card" style="background:rgba(255,255,255,.08)"><div class="ic">' . $k[0] . '</div><div class="num">' . esc($k[2]) . '</div><div class="lbl">' . esc($k[1]) . '</div><div class="sub">' . esc($k[3]) . '</div></div>';
    }
    echo '</div></div>';

    $asecs = ['overview' => '📊 نظرة عامة', 'requests' => '📨 طلبات المنتجات (' . $am_req_n . ')', 'links' => '🔗 الروابط (' . count($jmi_all) . ')',
              'conversions' => '🧾 المبيعات والأرباح (' . count($jmi_ord) . ')', 'settings' => '⚙️ الإعدادات'];
    echo '<div class="adm-tabs">';
    foreach ($asecs as $k => $l) echo '<a href="?tab=jumia&sec=' . $k . '"' . ($asec === $k ? ' class="on"' : '') . '>' . esc($l) . '</a>';
    echo '</div>';

    // ------------------ نظرة عامة ------------------
    if ($asec === 'overview') {
        echo '<div class="adm-grid-2">';
        echo '<div class="adm-box"><h3>🔥 أفضل الروابط المعتمدة (بالنقرات)</h3>';
        $top = array_filter($jmi_all, function ($l) { return (string)($l['status'] ?? 'approved') === 'approved'; });
        usort($top, function ($a, $b) { return (int)($b['clicks'] ?? 0) - (int)($a['clicks'] ?? 0); });
        $top = array_slice($top, 0, 10);
        if (!$top) echo '<p class="mut">لسه مفيش روابط معتمدة.</p>';
        else {
            echo '<table style="font-size:12.5px"><tr><th>المنتج</th><th>المسوق</th><th>نقرات</th><th>بيعات</th><th>معلقة</th><th>مؤكدة</th></tr>';
            foreach ($top as $l) {
                $mku = find_marketer_by_code((string)($l['ref'] ?? ''));
                $mm  = $am_money[(string)($l['id'] ?? '')] ?? ['sales' => 0, 'pending' => 0, 'confirmed' => 0];
                echo '<tr><td>' . esc(mb_substr((string)($l['title'] ?? ''), 0, 28) ?: (string)($l['pid'] ?? '')) . '</td>'
                   . '<td>' . esc($mku ? (string)($mku['name'] ?? $l['ref']) : (string)($l['ref'] ?? '')) . '</td>'
                   . '<td>' . number_format((int)($l['clicks'] ?? 0)) . '</td>'
                   . '<td>' . number_format((int)$mm['sales']) . '</td>'
                   . '<td dir="ltr" style="color:#b45309">' . number_format((float)$mm['pending'], 2) . '</td>'
                   . '<td dir="ltr" style="color:#15803d">' . number_format((float)$mm['confirmed'], 2) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        echo '<div class="adm-box"><h3>🏆 أرباح المسوقين من جوميا</h3>';
        $bym = [];
        foreach ($jmi_ord as $o) {
            if ((string)($o['status'] ?? '') === 'reversed') continue;
            $r = (string)($o['ref'] ?? '');
            if (!isset($bym[$r])) $bym[$r] = ['mk' => 0, 'site' => 0, 'orders' => 0];
            $bym[$r]['mk'] += (float)($o['mk_egp'] ?? 0);
            $bym[$r]['site'] += (float)($o['site_egp'] ?? 0);
            $bym[$r]['orders']++;
        }
        uasort($bym, function ($a, $b) { return $b['mk'] - $a['mk']; });
        if (!$bym) echo '<p class="mut">لا توجد مبيعات مسجلة بعد.</p>';
        else {
            echo '<table style="font-size:12.5px"><tr><th>المسوق</th><th>بيعات</th><th>أرباحه</th><th>نصيب الموقع</th></tr>';
            foreach (array_slice($bym, 0, 10, true) as $r => $v) {
                $mku = find_marketer_by_code($r);
                echo '<tr><td>' . esc($mku ? (string)($mku['name'] ?? $r) : $r) . '</td><td>' . number_format($v['orders']) . '</td>'
                   . '<td dir="ltr" style="color:#16a34a;font-weight:800">' . number_format($v['mk'], 2) . '</td>'
                   . '<td dir="ltr">' . number_format($v['site'], 2) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div></div>';

        if ($am_req_n > 0) {
            echo '<div class="adm-box" style="border-color:#fde68a;background:#fffbeb"><h3>⏳ طلبات منتجات بانتظار موافقتك (' . $am_req_n . ')</h3>';
            echo '<table style="font-size:12.5px"><tr><th>المسوق</th><th>المنتج</th><th>القسم</th><th>التاريخ</th><th>إجراء</th></tr>';
            $shown = 0;
            foreach (array_reverse($jmi_reqs, true) as $rid => $r) {
                if ((string)($r['status'] ?? '') !== 'pending' || !in_array((string)($r['type'] ?? ''), ['approval', 'pct'], true)) continue;
                if (++$shown > 8) break;
                $mku = find_marketer_by_code((string)($r['ref'] ?? ''));
                echo '<tr><td>' . esc($mku ? (string)($mku['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</td>'
                   . '<td>' . ((string)($r['type'] ?? '') === 'pct' ? '📈 تظلم نسبة: ' : '') . esc(mb_substr((string)(($r['title'] ?? '') !== '' ? $r['title'] : ($r['pid'] ?? '')), 0, 40)) . '</td>'
                   . '<td>' . esc(jmi_cat_label((string)($r['cat'] ?? 'other'))) . '</td>'
                   . '<td dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</td>'
                   . '<td><a class="btn sm" href="?tab=jumia&sec=requests">مراجعة ←</a></td></tr>';
            }
            echo '</table></div>';
        }
    }

    // ------------------ طلبات المنتجات (منظمة) ------------------
    elseif ($asec === 'requests') {
        $am_by  = ['pending' => [], 'approved' => [], 'rejected' => []];   // طلبات المنتجات
        $am_pct = ['pending' => [], 'approved' => [], 'rejected' => []];   // تظلمات النسبة
        foreach (array_reverse($jmi_reqs, true) as $rid => $r) {
            $rt = (string)($r['type'] ?? '');
            if ($rt !== 'approval' && $rt !== 'pct') continue;
            $s = (string)($r['status'] ?? 'pending');
            if (!isset($am_by[$s])) $s = 'pending';
            if ($rt === 'pct') $am_pct[$s][$rid] = $r; else $am_by[$s][$rid] = $r;
        }

        $am_cats = jmi_categories();

        // ⏳ بانتظار المراجعة — بطاقة لكل طلب: بياناته + نموذج الموافقة (مش جدول، عشان عداد الجداول العام ميحسبش صفوف النموذج)
        echo '<div class="adm-box" style="border-color:#fde68a"><h3>⏳ بانتظار مراجعتك (' . count($am_by['pending']) . ')</h3>';
        if (!$am_by['pending']) {
            echo '<p class="mut">مفيش طلبات منتجات جديدة 🎉</p>';
        } else {
            echo '<p class="mut" style="font-size:12px;line-height:1.8;margin-bottom:12px">حدد <b>سعر البيع</b> و<b>نسبة المسوق</b> من سعر البيع، والصق <b>لينك التتبع</b> من جوميا لو معاك، وبعدين وافق.</p>';
            foreach ($am_by['pending'] as $rid => $r) {
                $lid2      = (string)($r['link_id'] ?? '');
                $L2        = $lid2 !== '' ? ($jmi_all[$lid2] ?? null) : null;
                $mku       = find_marketer_by_code((string)($r['ref'] ?? ''));
                $mkt2      = 'eg';
                $cur2      = 'EGP';
                $tag_now   = (string)($L2['aff_url'] ?? '');
                $price_now = $L2 ? (float)($L2['price'] ?? 0) : (float)($r['price_hint'] ?? 0);
                $pct_now   = $L2 ? jmi_mk_pct($L2) : null;
                $cat_now   = (string)($L2['cat'] ?? ($r['cat'] ?? 'other'));
                $rate_now  = jmi_rate($cat_now);
                $pname     = (string)(($r['title'] ?? '') !== '' ? $r['title'] : ('منتج ' . ($r['pid'] ?? '')));

                echo '<div style="border:1px solid #fde68a;border-radius:14px;background:#fff;padding:14px 16px;margin-bottom:12px">';
                echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">'
                   . '<div><b>' . esc($mku ? (string)($mku['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</b> '
                   . '<span class="mut" dir="ltr" style="font-size:11px">' . esc((string)($r['ref'] ?? '')) . '</span>'
                   . ($mku ? '<br><span class="mut" dir="ltr" style="font-size:11px">' . esc((string)($mku['email'] ?? '')) . '</span>' : '') . '</div>'
                   . '<div dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</div>'
                   . '</div>';
                echo '<div style="margin-top:10px;font-size:13px"><b>' . esc(mb_substr($pname, 0, 80)) . '</b> '
                   . '<span dir="ltr" class="mut" style="font-size:11px">' . esc((string)($r['pid'] ?? '')) . '</span> • ' . esc(jmi_mkt_label($mkt2)) . ' • ' . esc(jmi_cat_label($cat_now))
                   . ((float)($r['price_hint'] ?? 0) > 0 ? ' • سعر المسوق المتوقع: <b>' . esc(number_format((float)$r['price_hint'], 2)) . '</b>' : '')
                   . ($L2 ? ' • <a target="_blank" rel="nofollow noopener" href="' . esc(jmi_out_url($L2)) . '">🔎 فتح على جوميا</a>' : ' • <span style="color:#b91c1c">⚠️ المسوق سحب الرابط</span>')
                   . '</div>';
                if ((string)($r['note'] ?? '') !== '') {
                    echo '<div class="mut" style="font-size:12px;margin-top:6px">💬 ملاحظة المسوق: ' . esc(mb_substr((string)$r['note'], 0, 200)) . '</div>';
                }

                echo '<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;padding-top:12px;border-top:1px dashed #fde68a">'
                   . '<input type="hidden" name="jmi_act" value="req_action"><input type="hidden" name="req_id" value="' . esc((string)$rid) . '">'
                   . '<label style="font-size:11px;font-weight:800">لينك التتبع من جوميا<br><input name="aff_url" dir="ltr" value="' . esc($tag_now) . '" placeholder="https://..." style="width:150px;padding:7px;border:1px solid var(--slate-200);border-radius:8px;font-size:12px"></label>'
                   . '<label style="font-size:11px;font-weight:800">سعر البيع (' . esc(jmi_cur_label($cur2)) . ')<br><input name="price" dir="ltr" value="' . esc($price_now > 0 ? rtrim(rtrim(number_format($price_now, 2, '.', ''), '0'), '.') : '') . '" placeholder="300" style="width:110px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<label style="font-size:11px;font-weight:800">نسبة المسوق %<br><input name="mk_pct" dir="ltr" value="' . esc($pct_now !== null ? rtrim(rtrim(number_format($pct_now, 2, '.', ''), '0'), '.') : '') . '" placeholder="10" style="width:96px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<label style="font-size:11px;font-weight:800">القسم<br><select name="cat" style="padding:7px;border:1px solid var(--slate-200);border-radius:8px;width:230px">';
                foreach (jmi_categories() as $slug => $c) {
                    echo '<option value="' . esc($slug) . '"' . ($cat_now === $slug ? ' selected' : '') . '>' . esc($c[0]) . '</option>';
                }
                echo '</select></label>'
                   . '<label style="font-size:11px;font-weight:800">ملاحظتك للمسوق<br><input name="admin_note" placeholder="اختياري" style="width:200px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<div style="display:flex;gap:6px">'
                   . '<button class="btn sm" style="background:#16a34a;color:#fff" name="do" value="approve">✅ موافقة</button>'
                   . '<button class="btn sm outline" name="do" value="reject" style="color:#b91c1c">⛔ رفض</button>'
                   . '</div>'
                   . '<div style="flex-basis:100%;font-size:11px;color:#b45309">عمولة جوميا للقسم ده: <b dir="ltr">' . esc(rtrim(rtrim(number_format($rate_now, 2), '0'), '.')) . '%</b> — لو نسبة المسوق أعلى منها، نصيب الموقع بيطلع بالسالب (الموقع بيدفع الفرق).</div>'
                   . '</form>';
                echo '</div>';
            }
        }
        echo '</div>';

        // 📈 تظلمات النسبة: المسوق طلب نسبة أعلى على رابط اتعتمد — الإدارة بتحدد النسبة الجديدة وتوافق، أو ترفض
        echo '<div class="adm-box" style="border-color:#bfdbfe"><h3>📈 تظلمات النسبة (' . count($am_pct['pending']) . ')</h3>';
        if (!$am_pct['pending']) {
            echo '<p class="mut">مفيش تظلمات على النسبة 👌</p>';
        } else {
            echo '<p class="mut" style="font-size:12px;line-height:1.8;margin-bottom:12px">المسوق طلب نسبة أعلى على رابط اتعتمد. حدد <b>النسبة الجديدة</b> (تقدر تديله أقل من اللي طلبه) ووافق، أو ارفض. <b>التعديل بيسري على البيعات الجاية بس</b>.</p>';
            foreach ($am_pct['pending'] as $rid => $r) {
                $lidp  = (string)($r['link_id'] ?? '');
                $Lp    = $lidp !== '' ? ($jmi_all[$lidp] ?? null) : null;
                $mkp   = find_marketer_by_code((string)($r['ref'] ?? ''));
                $mktp  = 'eg';
                $catp  = (string)($Lp['cat'] ?? ($r['cat'] ?? 'other'));
                $ratep = jmi_rate($catp);
                $curp  = $Lp ? jmi_mk_pct($Lp) : null;
                $ptt   = (string)(($r['title'] ?? '') !== '' ? $r['title'] : ('منتج ' . ($r['pid'] ?? '')));
                echo '<div style="border:1px solid #bfdbfe;border-radius:14px;background:#fff;padding:14px 16px;margin-bottom:12px">';
                echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap"><div><b>' . esc($mkp ? (string)($mkp['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</b> '
                   . '<span class="mut" dir="ltr" style="font-size:11px">' . esc((string)($r['ref'] ?? '')) . '</span>'
                   . ($mkp ? '<br><span class="mut" dir="ltr" style="font-size:11px">' . esc((string)($mkp['email'] ?? '')) . '</span>' : '') . '</div>'
                   . '<div dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</div></div>';
                echo '<div style="margin-top:10px;font-size:13px"><b>' . esc(mb_substr($ptt, 0, 80)) . '</b> '
                   . '<span dir="ltr" class="mut" style="font-size:11px">' . esc((string)($r['pid'] ?? '')) . '</span> • ' . esc(jmi_mkt_label($mktp)) . ' • ' . esc(jmi_cat_label($catp))
                   . ($Lp ? ' • <a target="_blank" rel="nofollow noopener" href="' . esc(jmi_out_url($Lp)) . '">🔎 فتح على جوميا</a>' : ' • <span style="color:#b91c1c">⚠️ الرابط اتشال</span>')
                   . '</div>';
                echo '<div style="margin-top:8px;font-size:13px">نسبته الحالية: <b dir="ltr">' . esc(jmi_pct_txt($curp)) . '%</b></div>';
                if ((string)($r['note'] ?? '') !== '') {
                    echo '<div class="mut" style="font-size:12px;margin-top:6px">💬 السبب: ' . esc(mb_substr((string)$r['note'], 0, 300)) . '</div>';
                }
                echo '<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;padding-top:12px;border-top:1px dashed #bfdbfe">'
                   . '<input type="hidden" name="jmi_act" value="pct_action"><input type="hidden" name="req_id" value="' . esc((string)$rid) . '">'
                   . '<label style="font-size:11px;font-weight:800">النسبة الجديدة %<br><input name="mk_pct" type="number" step="0.01" min="0.01" max="100" dir="ltr" required style="width:110px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<label style="font-size:11px;font-weight:800">ملاحظتك للمسوق<br><input name="admin_note" placeholder="اختياري" style="width:220px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>'
                   . '<div style="display:flex;gap:6px"><button class="btn sm" style="background:#16a34a;color:#fff" name="do" value="approve">✅ موافقة</button>'
                   . '<button class="btn sm outline" name="do" value="reject" style="color:#b91c1c">⛔ رفض</button></div>'
                   . '<div style="flex-basis:100%;font-size:11px;color:#b45309">عمولة جوميا للقسم ده: <b dir="ltr">' . esc(jmi_pct_txt($ratep)) . '%</b> — لو نسبة المسوق أعلى منها، نصيب الموقع بيطلع بالسالب.</div>'
                   . '</form>';
                echo '</div>';
            }
        }
        echo '</div>';

        // ✅ المعتمدة
        echo '<div class="adm-box"><h3>✅ معتمدة (' . count($am_by['approved']) . ')</h3>';
        if (!$am_by['approved']) echo '<p class="mut">لسه مفيش طلبات معتمدة.</p>';
        else {
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>المسوق</th><th>المنتج</th><th>سعر البيع</th><th>نسبة المسوق</th><th>يكسب على كل بيعة</th><th>لينك التتبع</th><th>الموافقة</th></tr>';
            foreach ($am_by['approved'] as $rid => $r) {
                $L3 = (string)($r['link_id'] ?? '') !== '' ? ($jmi_all[(string)$r['link_id']] ?? null) : null;
                $mku = find_marketer_by_code((string)($r['ref'] ?? ''));
                $mkt3 = 'eg';
                $ps3 = $L3 ? jmi_per_sale($L3) : ['ok' => false];
                $tag3 = '';
                echo '<tr><td>' . esc($mku ? (string)($mku['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</td>'
                   . '<td>' . esc(mb_substr((string)(($r['title'] ?? '') !== '' ? $r['title'] : ($r['pid'] ?? '')), 0, 34)) . '</td>'
                   . '<td dir="ltr">' . ($L3 ? esc(jmi_money((float)($L3['price'] ?? 0), 'EGP')) : '—') . '</td>'
                   . '<td dir="ltr">' . (!empty($ps3['ok']) ? esc(rtrim(rtrim(number_format((float)$ps3['pct'], 2), '0'), '.')) . '%' : '—') . '</td>'
                   . '<td dir="ltr"><b style="color:#16a34a">' . (!empty($ps3['ok']) ? esc(number_format((float)$ps3['amount'], 2)) . ' ' . esc(jmi_cur_label('EGP')) : '—') . '</b></td>'
                   . '<td dir="ltr">' . ($tag3 !== '' ? esc($tag3) : '<span style="color:#b45309">— (من غير لينك)</span>') . '</td>'
                   . '<td dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['approved_at'] ?? $r['at'] ?? ''), 0, 16)) . '</td></tr>';
            }
            echo '</table></div>';
        }
        echo '</div>';

        // ⛔ المرفوضة
        if ($am_by['rejected']) {
            echo '<div class="adm-box"><h3>⛔ مرفوضة (' . count($am_by['rejected']) . ')</h3>';
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>المسوق</th><th>المنتج</th><th>ملاحظتك</th><th>التاريخ</th></tr>';
            foreach ($am_by['rejected'] as $rid => $r) {
                $mku = find_marketer_by_code((string)($r['ref'] ?? ''));
                echo '<tr><td>' . esc($mku ? (string)($mku['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</td>'
                   . '<td>' . esc(mb_substr((string)(($r['title'] ?? '') !== '' ? $r['title'] : ($r['pid'] ?? '')), 0, 40)) . '</td>'
                   . '<td class="mut" style="font-size:11.5px">' . esc(mb_substr((string)($r['admin_note'] ?? ''), 0, 120)) . '</td>'
                   . '<td dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</td></tr>';
            }
            echo '</table></div></div>';
        }
        // 📈 تظلمات النسبة اللي اتعالجت (للمتابعة)
        $pct_done = $am_pct['approved'] + $am_pct['rejected'];
        if ($pct_done) {
            echo '<div class="adm-box"><h3>📈 تظلمات نسبة اتعالجت (' . count($pct_done) . ')</h3>';
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>المسوق</th><th>المنتج</th><th>من</th><th>إلى</th><th>القرار</th><th>ملاحظتك</th><th>التاريخ</th></tr>';
            foreach ($pct_done as $rid => $r) {
                $mkd  = find_marketer_by_code((string)($r['ref'] ?? ''));
                $isA  = (string)($r['status'] ?? '') === 'approved';
                echo '<tr><td>' . esc($mkd ? (string)($mkd['name'] ?? $r['ref']) : (string)($r['ref'] ?? '')) . '</td>'
                   . '<td>' . esc(mb_substr((string)(($r['title'] ?? '') !== '' ? $r['title'] : ($r['pid'] ?? '')), 0, 34)) . '</td>'
                   . '<td dir="ltr">' . esc(jmi_pct_txt($r['cur_pct'] ?? null)) . '%</td>'
                   . '<td dir="ltr"><b>' . ($isA ? (esc(jmi_pct_txt($r['applied_pct'] ?? null)) . '%') : '—') . '</b></td>'
                   . '<td>' . ($isA ? '<span style="color:#16a34a;font-weight:800">✅ اتوافق</span>' : '<span style="color:#b91c1c;font-weight:800">⛔ اترفض</span>') . '</td>'
                   . '<td class="mut" style="font-size:11.5px">' . esc(mb_substr((string)($r['admin_note'] ?? ''), 0, 120)) . '</td>'
                   . '<td dir="ltr" class="mut" style="font-size:11px">' . esc(substr((string)($r['at'] ?? ''), 0, 16)) . '</td></tr>';
            }
            echo '</table></div></div>';
        }
    }

    // ------------------ الروابط ------------------
    elseif ($asec === 'links') {
        $fref = trim((string)($_GET['ref'] ?? ''));
        $fst  = trim((string)($_GET['st'] ?? ''));
        $rows = $jmi_all;
        if ($fref !== '') $rows = array_filter($rows, function ($l) use ($fref) { return (string)($l['ref'] ?? '') === $fref; });
        if ($fst !== '')  $rows = array_filter($rows, function ($l) use ($fst) { return (string)($l['status'] ?? 'approved') === $fst; });
        $stl_map = ['approved' => '✅ معتمد', 'pending' => '⏳ بانتظار الموافقة', 'rejected' => '⛔ مرفوض'];
        echo '<div class="adm-box"><form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px">'
           . '<input type="hidden" name="tab" value="jumia"><input type="hidden" name="sec" value="links">'
           . '<label style="font-size:12px;font-weight:800">كود المسوق<br><input name="ref" value="' . esc($fref) . '" dir="ltr" style="padding:8px;border:1px solid var(--slate-200);border-radius:8px"></label>'
           . '<label style="font-size:12px;font-weight:800">الحالة<br><select name="st" style="padding:8px;border:1px solid var(--slate-200);border-radius:8px"><option value="">الكل</option>';
        foreach ($stl_map as $kk => $ll) echo '<option value="' . esc($kk) . '"' . ($fst === $kk ? ' selected' : '') . '>' . esc($ll) . '</option>';
        echo '</select></label><button class="btn sm">🔍 فلتر</button></form>';
        if (!$rows) {
            echo '<p class="mut">لا توجد روابط مطابقة.</p></div>';
        } else {
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>المسوق</th><th>المنتج</th><th>المتجر</th><th>القسم</th><th>سعر البيع</th><th>نسبة المسوق</th><th>نقرات</th><th>بيعات</th><th>أرباح المسوق معلقة</th><th>أرباح المسوق مؤكدة</th><th>الحالة</th><th>إجراءات</th></tr>';
            foreach ($rows as $lid3 => $l) {
                $mku = find_marketer_by_code((string)($l['ref'] ?? ''));
                $stx = (string)($l['status'] ?? 'approved');
                $mkt4 = 'eg';
                $mm = $am_money[(string)$lid3] ?? ['sales' => 0, 'pending' => 0, 'confirmed' => 0];
                $tag4 = (string)($l['aff_url'] ?? '');
                $pct4 = jmi_mk_pct($l);
                echo '<tr>';
                echo '<td>' . esc($mku ? (string)($mku['name'] ?? $l['ref']) : (string)($l['ref'] ?? '')) . '<br><span class="mut" dir="ltr" style="font-size:10.5px">' . esc((string)($l['ref'] ?? '')) . '</span></td>';
                echo '<td style="max-width:180px">' . esc(mb_substr((string)($l['title'] ?? ''), 0, 26) ?: (string)($l['pid'] ?? '')) . '<br><span class="mut" dir="ltr" style="font-size:10.5px">' . esc((string)($l['pid'] ?? '')) . '</span></td>';
                echo '<td>' . esc(jmi_mkt_label($mkt4)) . '</td>';
                echo '<td>' . esc(jmi_cat_label((string)($l['cat'] ?? 'other'))) . '</td>';
                echo '<td dir="ltr">' . (((float)($l['price'] ?? 0)) > 0 ? esc(jmi_money((float)$l['price'], 'EGP')) : '<span class="mut">—</span>') . '</td>';
                echo '<td dir="ltr">' . ($pct4 !== null ? '<b>' . esc(rtrim(rtrim(number_format($pct4, 2), '0'), '.')) . '%</b>' : '<span style="color:#b45309">لم تُحدد</span>') . '</td>';
                echo '<td>' . number_format((int)($l['clicks'] ?? 0)) . '</td>';
                echo '<td>' . number_format((int)$mm['sales']) . '</td>';
                echo '<td dir="ltr" style="color:#b45309">' . number_format((float)$mm['pending'], 2) . '</td>';
                echo '<td dir="ltr" style="color:#15803d;font-weight:800">' . number_format((float)$mm['confirmed'], 2) . '</td>';
                echo '<td>' . esc($stl_map[$stx] ?? $stx) . (!empty($l['no_aff']) ? '<br><span style="color:#b91c1c;font-size:10.5px">⚠️ بدون لينك تتبع</span>' : '') . '</td>';
                echo '<td><div style="display:flex;gap:4px;flex-wrap:wrap">';
                echo '<button type="button" class="btn sm outline" style="padding:4px 8px;font-size:11px" onclick="document.getElementById(\'amzed_' . esc((string)$lid3) . '\').style.display=\'table-row\'" aria-label="تعديل الرابط">⚙️</button>';
                echo '<a class="btn sm outline" style="padding:4px 8px;font-size:11px" target="_blank" rel="nofollow noopener" href="' . esc(jmi_out_url($l)) . '" aria-label="فتح على جوميا">🔎</a>';
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'حذف الرابط؟\')"><input type="hidden" name="jmi_act" value="link_status"><input type="hidden" name="link_id" value="' . esc((string)$lid3) . '"><button class="btn sm outline" style="padding:4px 8px;font-size:11px;color:#b91c1c" name="do" value="delete" aria-label="حذف الرابط">🗑️</button></form>';
                echo '</div></td></tr>';
                // نموذج التعديل: السعر + نسبة المسوق + القسم + لينك التتبع + الحالة
                echo '<tr id="amzed_' . esc((string)$lid3) . '" style="display:none;background:#f8fafc"><td colspan="12">';
                echo '<form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">';
                echo '<input type="hidden" name="jmi_act" value="link_save"><input type="hidden" name="link_id" value="' . esc((string)$lid3) . '">';
                echo '<label style="font-size:11px;font-weight:800">لينك التتبع من جوميا<br><input name="aff_url" dir="ltr" value="' . esc($tag4) . '" placeholder="https://..." style="width:130px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>';
                echo '<label style="font-size:11px;font-weight:800">سعر البيع (' . esc(jmi_cur_label('EGP')) . ')<br><input name="price" dir="ltr" value="' . esc((float)($l['price'] ?? 0) > 0 ? rtrim(rtrim(number_format((float)$l['price'], 2, '.', ''), '0'), '.') : '') . '" style="width:90px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>';
                echo '<label style="font-size:11px;font-weight:800">نسبة المسوق %<br><input name="mk_pct" dir="ltr" value="' . esc($pct4 !== null ? rtrim(rtrim(number_format($pct4, 2, '.', ''), '0'), '.') : '') . '" style="width:72px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>';
                echo '<label style="font-size:11px;font-weight:800">القسم<br><select name="cat" style="padding:7px;border:1px solid var(--slate-200);border-radius:8px;max-width:220px">';
                foreach (jmi_categories() as $slug => $c) echo '<option value="' . esc($slug) . '"' . ((string)($l['cat'] ?? '') === $slug ? ' selected' : '') . '>' . esc($c[0]) . '</option>';
                echo '</select></label>';
                echo '<label style="font-size:11px;font-weight:800">عمولة جوميا % (للحساب)<br><input name="rate" dir="ltr" value="' . esc((string)($l['rate'] ?? '')) . '" style="width:80px;padding:7px;border:1px solid var(--slate-200);border-radius:8px"></label>';
                echo '<label style="font-size:11px;font-weight:800">الحالة<br><select name="status" style="padding:7px;border:1px solid var(--slate-200);border-radius:8px">';
                foreach ($stl_map as $kk => $ll) echo '<option value="' . esc($kk) . '"' . ($stx === $kk ? ' selected' : '') . '>' . esc($ll) . '</option>';
                echo '</select></label>';
                echo '<button class="btn sm">💾 حفظ</button></form>';
                echo '<div style="font-size:11px;margin-top:6px" class="mut" dir="ltr">track: ' . esc(jmi_track_url((string)$lid3)) . '</div>';
                echo '</td></tr>';
            }
            echo '</table></div></div>';
        }
    }

    // ------------------ المبيعات والأرباح ------------------
    elseif ($asec === 'conversions') {
        // نموذج تسجيل بيعة يدوياً
        echo '<div class="adm-box"><h3>➕ تسجيل بيعة يدوياً</h3>';
        echo '<form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">';
        echo '<input type="hidden" name="jmi_act" value="add_conv">';
        echo '<label style="font-size:12px;font-weight:800">الرابط<br><select name="link_id" style="padding:9px;border:1px solid var(--slate-200);border-radius:9px;max-width:300px">';
        foreach ($jmi_all as $lid => $l) {
            $mku = find_marketer_by_code((string)($l['ref'] ?? ''));
            echo '<option value="' . esc((string)$lid) . '">' . esc(($mku ? (string)($mku['name'] ?? $l['ref']) : (string)($l['ref'] ?? '')) . ' — ' . (mb_substr((string)($l['title'] ?? ''), 0, 24) ?: (string)($l['pid'] ?? ''))) . '</option>';
        }
        echo '</select></label>';
        echo '<label style="font-size:12px;font-weight:800">المبلغ (ج.م)<br><input name="value" placeholder="300" style="width:100px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<label style="font-size:12px;font-weight:800">الكمية<br><input name="qty" value="1" style="width:60px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<label style="font-size:12px;font-weight:800">أرباح المسوق (ج.م) — اتركها فاضية = من السعر × النسبة<br><input name="mk_egp" placeholder="30" style="width:150px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<label style="font-size:12px;font-weight:800">عمولة جوميا الفعلية % (اختياري، فاضية = نسبة القسم)<br><input name="comm" placeholder="5" style="width:110px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<label style="font-size:12px;font-weight:800">الحالة<br><select name="conv_status" style="padding:9px;border:1px solid var(--slate-200);border-radius:9px"><option value="pending">معلقة</option><option value="confirmed">مؤكدة (تتحول لرصيد المسوق)</option></select></label>';
        echo '<label style="font-size:12px;font-weight:800">ملاحظة<br><input name="note" style="width:130px;padding:9px;border:1px solid var(--slate-200);border-radius:9px"></label>';
        echo '<button class="btn pri">💾 تسجيل البيعة</button></form></div>';

        if (!empty($GLOBALS['_JMI_IMPORT_REPORT']) && !empty($GLOBALS['_JMI_IMPORT_REPORT']['unmatched'])) {
            $rep = $GLOBALS['_JMI_IMPORT_REPORT'];
            echo '<div class="adm-box" style="border-color:#fde68a;background:#fffbeb"><h3>⚠️ صفوف لم تُطابق (' . count($rep['unmatched']) . ')</h3>';
            echo '<table style="font-size:12px"><tr><th>رقم المنتج</th><th>لينك التتبع</th><th>التاريخ</th><th>الإيراد</th><th>العمولة</th></tr>';
            foreach (array_slice($rep['unmatched'], 0, 40) as $u) {
                echo '<tr><td dir="ltr">' . esc((string)($u['pid'] ?? '')) . '</td><td dir="ltr">' . esc((string)($u['tag'] ?? '')) . '</td>'
                   . '<td dir="ltr">' . esc((string)($u['date'] ?? '')) . '</td><td>' . esc(number_format((float)($u['price'] ?? 0), 2)) . '</td>'
                   . '<td>' . esc(number_format((float)($u['comm'] ?? 0), 2)) . '</td></tr>';
            }
            echo '</table><p class="mut" style="font-size:11.5px">الصفوف دي معندهاش رابط معتمد على الرقم ده — وافق على الرابط للمسوق واستورد الملف تاني.</p></div>';
        }

        echo '<div class="adm-box"><h3>🧾 كل البيعات (' . count($jmi_ord) . ') — عدّل أرباح المسوق من هنا</h3>';
        echo '<p class="mut" style="font-size:12px;margin-bottom:10px">النقرات بتتسجل تلقائياً. أرباح المسوق (معلقة أو مؤكدة) بتحددها الإدارة — عدّل المبلغ واضغط 💾 وهيتحدّث رصيد المسوق والكارت.</p>';
        if (!$jmi_ord) echo '<p class="mut">لا توجد بيعات بعد.</p>';
        else {
            echo '<div style="overflow-x:auto"><table style="font-size:12px"><tr><th>التاريخ</th><th>المسوق</th><th>المنتج</th><th>الكمية</th><th>الطلب</th><th>عمولة جوميا</th><th>أرباح المسوق (ج.م)</th><th>نصيب الموقع</th><th>المصدر</th><th>الحالة</th><th>إجراء</th></tr>';
            foreach ($jmi_ord as $o) {
                $mku = find_marketer_by_code((string)($o['ref'] ?? ''));
                $os = (string)($o['status'] ?? 'pending');
                $cid_o = (string)($o['id'] ?? '');
                echo '<tr' . ($os === 'reversed' ? ' style="opacity:.6"' : '') . '>';
                echo '<td dir="ltr" style="font-size:11px">' . esc(substr((string)($o['at'] ?? ''), 0, 16)) . '</td>';
                echo '<td>' . esc($mku ? (string)($mku['name'] ?? $o['ref']) : (string)($o['ref'] ?? '')) . '</td>';
                echo '<td style="max-width:150px">' . esc(mb_substr((string)($o['title'] ?? ''), 0, 22) ?: (string)($o['pid'] ?? ''))
                   . '<br><span class="mut" dir="ltr" style="font-size:10.5px">' . esc((string)($o['pid'] ?? '')) . '</span></td>';
                echo '<td>' . number_format((int)($o['qty'] ?? 1)) . '</td>';
                echo '<td dir="ltr">' . esc(number_format((float)($o['gross'] ?? 0), 2)) . ' ' . esc((string)($o['currency'] ?? '')) . '</td>';
                echo '<td dir="ltr">' . esc(number_format((float)($o['jmi_comm'] ?? 0), 2)) . '</td>';
                echo '<td dir="ltr">';
                if ($os === 'reversed') {
                    echo '<span class="mut">' . esc(number_format((float)($o['mk_egp'] ?? 0), 2)) . '</span>';
                } else {
                    echo '<form method="post" style="display:flex;gap:4px;align-items:center"><input type="hidden" name="jmi_act" value="conv_edit"><input type="hidden" name="conv_id" value="' . esc($cid_o) . '">'
                       . '<input name="mk_egp" dir="ltr" value="' . esc(number_format((float)($o['mk_egp'] ?? 0), 2, '.', '')) . '" style="width:78px;padding:5px;border:1px solid var(--slate-200);border-radius:7px;font-weight:800;color:#15803d">'
                       . '<button class="btn sm outline" style="padding:4px 8px;font-size:11px" title="حفظ" aria-label="حفظ">💾</button></form>';
                }
                echo '</td>';
                $am_site = (float)($o['site_egp'] ?? 0);
                if ($am_site < 0) {
                    echo '<td dir="ltr" style="color:#b91c1c;font-weight:800">' . esc(number_format($am_site, 2)) . '<br><span style="font-size:10px;font-weight:700">⚠️ الموقع بيدفع الفرق</span></td>';
                } else {
                    echo '<td dir="ltr" class="mut">' . esc(number_format($am_site, 2)) . '</td>';
                }
                echo '<td>' . esc((string)($o['source'] ?? '')) . '</td>';
                echo '<td>' . ($os === 'confirmed' ? '✅ مؤكدة' : ($os === 'reversed' ? '↩️ ملغية' : '⏳ معلقة')) . '</td>';
                echo '<td>';
                if ($os === 'pending') {
                    echo '<form method="post" style="display:flex;gap:4px"><input type="hidden" name="jmi_act" value="conv_action"><input type="hidden" name="conv_id" value="' . esc($cid_o) . '">'
                       . '<button class="btn sm" style="background:#16a34a;color:#fff;padding:4px 8px;font-size:11px" name="do" value="confirm">تأكيد</button>'
                       . '<button class="btn sm outline" style="padding:4px 8px;font-size:11px;color:#b91c1c" name="do" value="reverse" onclick="this.form.reason.value=\'إلغاء من الإدارة\'">إلغاء</button>'
                       . '<input type="hidden" name="reason" value=""></form>';
                } elseif ($os === 'confirmed') {
                    echo '<form method="post" style="display:inline"><input type="hidden" name="jmi_act" value="conv_action"><input type="hidden" name="conv_id" value="' . esc($cid_o) . '">'
                       . '<input type="hidden" name="reason" value="إلغاء بعد التأكيد">'
                       . '<button class="btn sm outline" style="padding:4px 8px;font-size:11px;color:#b91c1c" name="do" value="reverse">↩️ إلغاء</button></form>';
                } else { echo '—'; }
                echo '</td></tr>';
            }
            echo '</table></div>';
        }
        echo '</div>';
    }

    // ------------------ روابط المسوقين ------------------
    elseif ($asec === 'settings') {
        echo '<form method="post"><input type="hidden" name="jmi_act" value="settings">';
        echo '<div class="adm-box"><h3>⚙️ ضبط النظام</h3><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">';
        $on = jmi_enabled();
        echo '<label style="font-size:13px;font-weight:800;display:flex;gap:8px;align-items:center"><input type="checkbox" name="jmi_enabled" value="1"' . ($on ? ' checked' : '') . '> تفعيل نظام أفلييت جوميا للمسوقين</label>';
        echo '</div></div>';


        echo '<div class="adm-box"><h3>📊 نسبة عمولة جوميا لكل قسم (اللي جوميا بتدفعها للموقع)</h3>';
        echo '<p class="mut" style="font-size:12px;margin-bottom:12px">دي النسبة اللي <b>جوميا بتدفعها للموقع</b> (الافتراضي حسب سياسة جوميا 2026 — عدّلها من هنا في أي وقت). بتتستخدم لحساب نصيب الموقع.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px">';
        foreach (jmi_categories() as $slug => $c) {
            echo '<label style="font-size:12px;font-weight:800">' . esc($c[0]) . '<br>'
               . '<input name="jmi_rate_' . esc($slug) . '" value="' . esc(number_format(jmi_rate($slug), 2)) . '" style="width:100%;padding:9px;border:1px solid var(--slate-200);border-radius:9px;margin-top:4px"></label>';
        }
        echo '</div></div>';


        echo '<button class="btn pri" style="padding:12px 28px">💾 حفظ كل إعدادات جوميا</button></form>';

        echo '<div class="adm-box" style="margin-top:14px;font-size:12.5px;line-height:1.9"><h3>📖 ملاحظات مهمة</h3><ul style="padding-right:18px">'
           . '<li>المسوق بيشوف المبلغ اللي هياخده على كل بيعة = <b>سعر البيع × نسبته</b> (مثال: 300 × 10% = 30 ج.م).</li>'
           . '<li>الإدارة بتلصق <b>لينك التتبع</b> من لوحة جوميا الأفلييت على كل منتج عند الموافقة (أو بعدين من تبويب الروابط). من غير اللينك ده الرابط بيفتح صفحة المنتج العادية من غير تتبع.</li>'
           . '<li>النقرات بتتسجل تلقائياً. أرباح المسوق (معلقة / مؤكدة) بتتحدد من <b>تبويب المبيعات والأرباح</b> — تقدر تعدّلها في أي وقت.</li>'
           . '<li>نصيب الموقع = عمولة جوميا (نسبة القسم × قيمة البيع) − أرباح المسوق. الاتنين مسجلين في كل بيعة وفي إجماليات التقرير.</li>'
           . '<li>العمولة بتتسجل <b>معلقة</b> للمسوق، وبتتحول <b>متاحة للسحب</b> لما تأكدها من هنا (بعد ما جوميا تدفع فعلاً).</li>'
           . '</ul></div>';
    }
}

elseif ($tab === 'settings') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        $allowed = []; foreach (site_settings_schema() as $grp) foreach ($grp as $k => $d) $allowed[$k] = 1;
        jupdate('settings.json', function (&$st) use ($allowed) {
            if (!is_array($st)) $st = [];
            foreach ($allowed as $k => $_) if (isset($_POST[$k])) $st[$k] = mb_substr(trim((string)$_POST[$k]), 0, 500);
            return true;
        }, []);
        log_audit('settings_update', '-', ['keys' => array_keys($allowed)]);
        echo '<div class="adm-box" data-ui="u-9381a74a d15 d5b"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> تم حفظ الإعدادات</div>';
        $GLOBALS['_st_cache'] = null;
    }
    $st = jload('settings.json', []); if (!is_array($st)) $st = [];
    echo '<div class="adm-box"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-settings></use></svg> إعدادات المتجر والنظام</h2><table class="data-table rtable" data-ui-table="pending" aria-busy="true">';
    echo '<tr><td data-ui="u-6f21dd9e dc d5c">اسم المتجر</td><td>' . esc(BRAND) . '</td></tr>';
    echo '<tr><td data-ui="u-00703584 dc">إصدار النظام</td><td>' . esc(SITE_VER) . '</td></tr>';
    echo '<tr><td data-ui="u-00703584 dc">عمولة إحالة الفريق — تحتاج قراراً</td><td>تعارض قيد المراجعة (لن تظهر النسبة للمسوقين): النص القديم <b>' . esc(REF_TEAM_LABEL) . '</b> — تُحسب فعلياً: <b>' . (REF_TEAM_RATE * 100) . '%</b></td></tr>';
    $upd = jload('catalog_update.json', []);
    echo '<tr><td data-ui="u-00703584 dc">آخر تحديث أوتوماتيك للكتالوج</td><td>' . ($upd ? esc($upd['at'] ?? '') . ' — ' . esc(json_encode($upd['summary'] ?? [], JSON_UNESCAPED_UNICODE)) : '<span class="mut">لم يعمل بعد (GitHub Actions)</span>') . '</td></tr>';
    echo '</table></div>';
    echo '<div class="adm-box" data-ui="u-0afaf1e6 d50 d5d du d5e"><b>إعداد العمولة:</b> عند ترك النسبة فارغة تُستخدم عمولة المنتج الثابتة من المصدر، وإذا لم تكن موجودة يُستخدم هامش المنصة الاحتياطي. يمكن تعديل النسبة للمنصات الأخرى من هنا. TimeHand وVendoor غير قابلين للتعديل؛ يستخدمان عمولة المصدر الثابتة (ولا تتجاوزها نسبة العمولة المخصصة للمسوق)، أو هامشاً احتياطياً ثابتاً عند غيابها.</div>';
    echo '<form data-confirm="سيتم حفظ إعدادات النظام والعمولات الظاهرة في هذه الاستمارة. راجع القيم قبل تطبيقها على العمليات القادمة." method="post">' . csrf_field() . '<input type="hidden" name="save_settings" value="1">';
    foreach (site_settings_schema() as $gname => $fields) {
        echo '<details class="settings-accordion"><summary>' . esc(wf_ui_plain($gname)) . '</summary><div class="form-grid">';
        foreach ($fields as $k => $d) {
            $v = (string)($st[$k] ?? '');
            echo '<label data-ui="u-183a314b d27 d4w dc">' . esc($d[0]) . '<br>';
            if ($d[1] === 'textarea') echo '<textarea name="' . $k . '" rows="2" data-ui="u-f3160252 dx d5f dt d1u d1v d3i">' . esc($v) . '</textarea>';
            else echo '<input type="' . ($d[1] === 'password' ? 'password' : 'text') . '" name="' . $k . '" value="' . esc($v) . '" dir="ltr" data-ui="u-f3160252 dx d5f dt d1u d1v d3i">';
            echo '</label>';
        }
        echo '</div></details>';
    }
    echo '<div class="settings-save"><button class="btn pri">حفظ كل الإعدادات</button></div></form>';
    echo '<div class="adm-box" data-ui="u-3529f42b dq du d5g"><h2><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-file></use></svg> منين أجيب الأكواد دي؟</h2><ul data-ui="u-e88fa5d6 d5h">'
       . '<li><b>Google Analytics 4:</b> analytics.google.com → Admin → Data Streams → Web → انسخ <b>Measurement ID</b> (يبدأ بـ G-).</li>'
       . '<li><b>Meta Pixel:</b> business.facebook.com → Events Manager → Connect Data Sources → Web → Pixel → انسخ <b>Pixel ID</b> (15-16 رقم).</li>'
       . '<li><b>TikTok Pixel:</b> ads.tiktok.com → Assets → Events → Web Events → Manual Setup → انسخ <b>Pixel ID</b> (يبدأ بـ C).</li>'
       . '<li><b>WhatsApp Cloud API:</b> developers.facebook.com → Create App (Business) → Add WhatsApp → من صفحة API Setup انسخ <b>Phone Number ID</b> و <b>Access Token</b> (اعمل System User token دائم من Business Settings). أول 1000 محادثة خدمة شهرياً مجانية.</li>'
       . '</ul></div>';
}

layout_end();
