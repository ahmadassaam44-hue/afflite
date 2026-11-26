<?php
// ============================================================================
// config_users.php — جزء من config.php (السطور 2911–3036 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/supabase_users.php';
require_once __DIR__ . '/supabase_docs.php';
require_once __DIR__ . '/hevo_api.php';
function current_user() { return isset($_SESSION['user']) ? $_SESSION['user'] : null; }
function require_login() { if (!current_user()) { header('Location: ../login.php'); exit; } }
function users() { return users_list(); }
// ===== إشعارات الطلب المؤجلة: الرد على المتصفح الأول ثم التليجرام/واتساب =====
// أكبر سبب عملي لـ«تعذر قراءة رد الخادم»: cURL التليجرام/الواتساب كان بيشتغل قبل
// رد الـ JSON — الاستضافة لو قطعت الطلب للتأخير بيرجع للمتصفح صفحة خطأ بدل الـ JSON
// مع إن الطلب اتسجل فعلًا. دلوقتي الرد بيتبعت الأول والإشعارات بتتنفذ بعده.
function wf_order_notify_queue($for, $text, $link = '') {
    $GLOBALS['_WF_ORDER_NOTIFY_Q'][] = ['for' => (string)$for, 'text' => (string)$text, 'link' => (string)$link];
}
function wf_order_notify_flush() {
    if (!empty($GLOBALS['_WF_ORDER_NOTIFY_Q_SENT'])) return;
    $GLOBALS['_WF_ORDER_NOTIFY_Q_SENT'] = true;
    foreach (($GLOBALS['_WF_ORDER_NOTIFY_Q'] ?? []) as $q19n) {
        try {
            if (($q19n['for'] ?? '') === 'admin') notify('admin', (string)($q19n['text'] ?? ''), (string)($q19n['link'] ?? ''));
            else notify_user((string)($q19n['for'] ?? ''), (string)($q19n['text'] ?? ''), (string)($q19n['link'] ?? ''));
        } catch (Throwable $e19n) {}
    }
    $GLOBALS['_WF_ORDER_NOTIFY_Q'] = [];
}
// إيجاد طلب بتوكن الإرسال (client_token) — للتأكيد «هل طلبي اتسجل؟» ولإعادة المحاولة من غير تكرار
function wf_find_order_by_token($token) {
    $token = mb_substr(trim((string)$token), 0, 64);
    if ($token === '') return null;
    $os = jload('orders.json', []);
    if (!is_array($os)) return null;
    foreach (array_reverse($os) as $o) {
        $t = is_array($o) ? (string)($o['client_token'] ?? '') : '';
        if ($t !== '' && hash_equals($t, $token)) return $o;
    }
    return null;
}
// دفع رسالة من الإدارة لشات الدعم الخاص بأي مستخدم (بيدخل عليها المسوق من لوحته بسياق ctx=marketer)
// بتستخدم عشان ملاحظات الإدارة على الطلبات توصل للمسوق في الدعم نفسه مش بس في إشعار عابر.
function wf_support_admin_message($email, $text, $sender_name = null) {
    $email = strtolower(trim((string)$email));
    $text  = trim((string)$text);
    if ($email === '' || $text === '') return false;
    return jupdate('support_chats.json', function (&$chats) use ($email, $text, $sender_name) {
        if (!is_array($chats)) $chats = [];
        if (!isset($chats[$email]) || !is_array($chats[$email])) {
            $chats[$email] = [
                'thread_id' => $email, 'user_name' => $email, 'user_email' => $email, 'user_ref' => '',
                'created_at' => date('c'), 'updated_at' => date('c'),
                'unread_admin' => 0, 'unread_user' => 0, 'messages' => [],
            ];
        }
        if (!isset($chats[$email]['messages']) || !is_array($chats[$email]['messages'])) $chats[$email]['messages'] = [];
        $chats[$email]['messages'][] = [
            'id' => uniqid('m_'), 'sender' => 'admin',
            'sender_name' => $sender_name !== null ? (string)$sender_name : 'الإدارة',
            'text' => mb_substr($text, 0, 2000), 'image' => '', 'at' => date('c'),
        ];
        $chats[$email]['updated_at']  = date('c');
        $chats[$email]['unread_user'] = (int)($chats[$email]['unread_user'] ?? 0) + 1;
        return true;
    }, []);
}
// كل طلبات مسوق معيّن بكود الإحالة (غير حساس لحالة الأحرف) — صفحة المسوق بالإدارة بتعرضهم كلهم بتفاصيلهم
function wf_orders_by_ref($orders, $ref) {
    $ref = trim((string)$ref);
    if (!is_array($orders) || $ref === '') return [];
    return array_values(array_filter($orders, function ($o) use ($ref) {
        return is_array($o) && strcasecmp(trim((string)($o['ref'] ?? '')), $ref) === 0;
    }));
}
function notify($for, $text, $link = '') { if ($for === 'admin') { tg_notify('🔔 ' . BRAND . "\n" . $text); if (function_exists('wa_notify') && site_setting('wa_admin_to')) wa_notify(site_setting('wa_admin_to'), '🔔 ' . BRAND . "\n" . $text); } $n = jload('notifications.json', []); $n[] = ['for' => $for, 'text' => mb_substr($text, 0, 300), 'link' => $link, 'at' => date('c'), 'read' => false]; jsave('notifications.json', array_slice($n, -500)); }
// إرسال إشعار لمستخدم محدد بالإيميل (يُستخدم في الشات وطلبات المنتجات)
function notify_user($email, $text, $link = '') {
    $email = strtolower(trim((string)$email));
    if ($email === '' || strpos($email, '@') === false) return false;
    notify($email, $text, $link);
    return true;
}
function my_notifications($email, $role = null) {
    $email = strtolower(trim((string)$email));
    if ($role === null && $email !== '') { $uu = find_user($email); $role = $uu ? wf_user_role($uu) : null; }
    $is_mk = ($role === 'marketer');
    return array_values(array_filter(jload('notifications.json', []), function ($n) use ($email, $is_mk) {
        $for = (string)($n['for'] ?? '');
        return $for === 'all' || $for === $email || ($for === 'marketers' && $is_mk);
    }));
}
function admin_notifications() { return array_values(array_filter(jload('notifications.json', []), function ($n) { return $n['for'] === 'admin' || $n['for'] === 'all'; })); }
// إشعار واحد يوصل لكل حسابات المسوقين (منتج جديد مثلاً) — من غير تفنص لكل إيميل
function wf_notify_marketers($text, $link = '') { notify('marketers', $text, $link); return true; }
// عدّادات القايمه الجانبية للأدمن: كل اللي مستني فعل من الإدارة
function wf_admin_pending_counts() {
    $c = ['orders' => 0, 'marketers' => 0, 'commissions' => 0, 'requests' => 0, 'chats' => 0];
    foreach ((array)jload('orders.json', []) as $o) if (status_key($o['status'] ?? '') === 'pending') $c['orders']++;
    $week = time() - 7 * 86400;
    foreach ((array)users() as $u) {
        if (wf_user_role($u) !== 'marketer') continue;
        $ts = strtotime((string)($u['at'] ?? ''));
        if ($ts && $ts >= $week) $c['marketers']++;
    }
    foreach ((array)jload('payouts.json', []) as $pr) if (trim((string)($pr['status'] ?? 'قيد المراجعة')) === 'قيد المراجعة') $c['commissions']++;
    foreach ((array)jload('product_requests.json', []) as $rq) if (trim((string)($rq['status'] ?? '')) === 'pending') $c['requests']++;
    foreach ((array)jload('support_chats.json', []) as $th) {
        $msgs = (array)($th['messages'] ?? []);
        $last = end($msgs);
        if ($last && (string)($last['sender'] ?? '') !== 'admin') $c['chats']++;
    }
    return $c;
}
// هل من حق المسوق ده يعدّل/يلغي الطلب ده؟ (طلبه بالرف بتاعه + لسه قبل الشحن)
// إلغاء/إرجاع المسوق: مسموح على أي حالة لسه مش منتهية — حتى بعد الشحن والتسليم
function wf_marketer_can_cancel_order($o, $u) {
    if (!is_array($o) || !is_array($u)) return false;
    $ref = trim((string)($u['ref_code'] ?? ''));
    if ($ref === '' || strcasecmp(trim((string)($o['ref'] ?? '')), $ref) !== 0) return false;
    if (in_array(status_key($o['status'] ?? ''), ['cancelled', 'returned', 'no_answer'], true)) return false;
    return trim((string)($o['order_no'] ?? '')) !== '';
}
function wf_marketer_can_edit_order($o, $u) {
    if (!is_array($o) || !is_array($u)) return false;
    $ref = trim((string)($u['ref_code'] ?? ''));
    if ($ref === '' || strcasecmp(trim((string)($o['ref'] ?? '')), $ref) !== 0) return false;
    if (!status_customer_cancellable($o['status'] ?? '')) return false;
    return trim((string)($o['order_no'] ?? '')) !== '';
}
