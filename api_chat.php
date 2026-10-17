<?php
/**
 * api_chat.php — محرك الشات الفوري (لدعم المسوقين المباشر + شات طلبات توفير المنتجات بالاسم والصورة)
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$cu = current_user();
$vid = function_exists('visitor_id') ? visitor_id() : session_id();
$is_admin = is_admin_user();

$user_key = !empty($cu['email']) ? strtolower(trim($cu['email'])) : ('guest_' . substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $vid), 0, 24));
$user_name = !empty($cu['name']) ? $cu['name'] : ('مسوق / زائر #' . substr($vid, 0, 6));
$user_role = $cu['role'] ?? 'marketer';
$user_ref  = $cu['ref_code'] ?? '';

$channel = $_REQUEST['channel'] ?? 'support'; // 'support' | 'product_request'
$action  = $_REQUEST['action'] ?? 'poll';

// -------------------------------------------------------------------------
// 1. قناة الشات المباشر للدعم الفني (Support Live Chat)
// -------------------------------------------------------------------------
if ($channel === 'support') {
    $chats = jload('support_chats.json', []);
    if (!is_array($chats)) $chats = [];

    $target_thread = trim((string)($_REQUEST['thread_id'] ?? ''));
    if ($target_thread === '' || (!$is_admin && $target_thread !== $user_key)) {
        $target_thread = $user_key;
    }

    if ($action === 'list_threads' && $is_admin) {
        $list = [];
        foreach ($chats as $tk => $th) {
            $msgs = $th['messages'] ?? [];
            $last = end($msgs);
            $list[] = [
                'thread_id' => $tk,
                'user_name' => $th['user_name'] ?? $tk,
                'user_email' => $th['user_email'] ?? $tk,
                'user_ref' => $th['user_ref'] ?? '',
                'unread_admin' => (int)($th['unread_admin'] ?? 0),
                'updated_at' => $th['updated_at'] ?? ($last['at'] ?? ''),
                'last_text' => $last['text'] ?? ($last['image'] ? '📷 صورة مرفقة' : ''),
                'messages_count' => count($msgs),
            ];
        }
        usort($list, function ($a, $b) {
            return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        });
        echo json_encode(['ok' => true, 'threads' => $list], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'send') {
        $text = trim((string)($_POST['text'] ?? ''));
        $img_path = save_uploaded_image('image', 'sup');
        if ($text === '' && $img_path === '') {
            echo json_encode(['ok' => false, 'error' => 'الرسالة فارغة'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $as_admin = $is_admin && (!empty($_POST['as_admin']) || $target_thread !== $user_key);
        if (!isset($chats[$target_thread])) {
            $chats[$target_thread] = [
                'thread_id' => $target_thread,
                'user_name' => $user_name,
                'user_email' => $cu['email'] ?? $target_thread,
                'user_ref' => $user_ref,
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'unread_admin' => 0,
                'unread_user' => 0,
                'messages' => [
                    [
                        'id' => uniqid('m_'),
                        'sender' => 'admin',
                        'sender_name' => 'فريق دعم ' . BRAND,
                        'text' => 'أهلاً بك يا ' . $user_name . ' في الشات المباشر مع الإدارة 👋 اكتب استفسارك أو أرسل صورة وسنرد عليك فوراً.',
                        'image' => '',
                        'at' => date('c')
                    ]
                ]
            ];
        }
        $msg = [
            'id' => uniqid('m_'),
            'sender' => $as_admin ? 'admin' : 'user',
            'sender_name' => $as_admin ? 'الإدارة (' . BRAND . ')' : $user_name,
            'text' => $text,
            'image' => $img_path,
            'at' => date('c')
        ];
        $chats[$target_thread]['messages'][] = $msg;
        $chats[$target_thread]['updated_at'] = date('c');
        if ($as_admin) {
            $chats[$target_thread]['unread_user'] = (int)($chats[$target_thread]['unread_user'] ?? 0) + 1;
            $chats[$target_thread]['unread_admin'] = 0;
            if (!empty($chats[$target_thread]['user_email']) && strpos($chats[$target_thread]['user_email'], '@') !== false) {
                notify_user($chats[$target_thread]['user_email'], '💬 رد جديد من الإدارة في شات الدعم: ' . mb_substr($text ?: 'صورة مرفقة', 0, 60), 'support.php');
            }
        } else {
            $chats[$target_thread]['user_name'] = $user_name;
            if (!empty($cu['email'])) $chats[$target_thread]['user_email'] = $cu['email'];
            if ($user_ref) $chats[$target_thread]['user_ref'] = $user_ref;
            $chats[$target_thread]['unread_admin'] = (int)($chats[$target_thread]['unread_admin'] ?? 0) + 1;
            $chats[$target_thread]['unread_user'] = 0;
            // كل رسالة يرسلها العميل/المسوق في شات الدعم توصل للأدمن فوراً (إشعار داخلي + تليجرام)
            notify('admin', '💬 رسالة دعم جديدة من ' . $user_name . ($user_ref ? ' (' . $user_ref . ')' : '')
                . ' — ' . mb_substr($text !== '' ? $text : 'صورة مرفقة', 0, 90)
                . (!empty($cu['email']) ? ' — ' . $cu['email'] : ''), 'dashboard/admin.php?tab=chats');
        }
        jsave('support_chats.json', $chats);
        echo json_encode(['ok' => true, 'message' => $msg, 'thread' => $chats[$target_thread]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Poll messages
    if (!isset($chats[$target_thread])) {
        $chats[$target_thread] = [
            'thread_id' => $target_thread,
            'user_name' => $user_name,
            'user_email' => $cu['email'] ?? $target_thread,
            'user_ref' => $user_ref,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'unread_admin' => 0,
            'unread_user' => 0,
            'messages' => [
                [
                    'id' => uniqid('m_'),
                    'sender' => 'admin',
                    'sender_name' => 'فريق دعم ' . BRAND,
                    'text' => 'أهلاً بك يا ' . $user_name . ' في الشات المباشر مع الإدارة 👋 كيف يمكننا مساعدتك اليوم؟',
                    'image' => '',
                    'at' => date('c')
                ]
            ]
        ];
        jsave('support_chats.json', $chats);
    } else {
        $as_admin_view = $is_admin && !empty($_GET['as_admin']);
        if ($as_admin_view && !empty($chats[$target_thread]['unread_admin'])) {
            $chats[$target_thread]['unread_admin'] = 0;
            jsave('support_chats.json', $chats);
        } elseif (!$as_admin_view && !empty($chats[$target_thread]['unread_user'])) {
            $chats[$target_thread]['unread_user'] = 0;
            jsave('support_chats.json', $chats);
        }
    }
    echo json_encode(['ok' => true, 'thread' => $chats[$target_thread]], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// 2. قناة طلبات توفير المنتجات بالاسم والصورة + شات الطلب (Product Requests Chat)
// -------------------------------------------------------------------------
if ($channel === 'product_request') {
    $reqs = jload('product_requests.json', []);
    if (!is_array($reqs)) $reqs = [];

    if ($action === 'create') {
        $pname = trim((string)($_POST['product_name'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $expected_price = trim((string)($_POST['expected_price'] ?? ''));
        $img_path = save_uploaded_image('product_image', 'req');
        $img_url_input = trim((string)($_POST['image_url'] ?? ''));
        if ($img_path === '' && $img_url_input !== '') {
            $img_path = $img_url_input;
        }
        if ($pname === '') {
            echo json_encode(['ok' => false, 'error' => 'يرجى كتابة اسم المنتج المطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $req_id = 'REQ-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $initial_msg_text = "📦 طلب توفير منتج جديد: " . $pname;
        if ($expected_price !== '') $initial_msg_text .= "\n💰 السعر المتوقع: " . $expected_price . " ج.م";
        if ($notes !== '') $initial_msg_text .= "\n📝 تفاصيل إضافية: " . $notes;

        $new_req = [
            'id' => $req_id,
            'user_key' => $user_key,
            'user_name' => $user_name,
            'user_email' => $cu['email'] ?? '',
            'user_ref' => $user_ref,
            'product_name' => $pname,
            'product_image' => $img_path,
            'expected_price' => $expected_price,
            'notes' => $notes,
            'status' => 'pending', // pending | sourcing | available | unavailable
            'product_link' => '',
            'unread_admin' => 1,
            'unread_user' => 0,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'messages' => [
                [
                    'id' => uniqid('rm_'),
                    'sender' => 'user',
                    'sender_name' => $user_name,
                    'text' => $initial_msg_text,
                    'image' => $img_path,
                    'at' => date('c')
                ],
                [
                    'id' => uniqid('rm_'),
                    'sender' => 'admin',
                    'sender_name' => 'إدارة ' . BRAND,
                    'text' => 'تم استلام طلب توفير المنتج (' . $pname . ') بنجاح ✅ يمكنك متابعة حالة توفير المنتج والتحدث معنا مباشرة في هذا الشات.',
                    'image' => '',
                    'at' => date('c')
                ]
            ]
        ];
        $reqs[$req_id] = $new_req;
        jsave('product_requests.json', $reqs);
        $admin_line = '📸 طلب توفير منتج جديد (' . $pname . ')';
        if ($expected_price !== '') $admin_line .= ' — السعر المتوقع: ' . $expected_price . ' ج.م';
        if ($notes !== '') $admin_line .= ' — تفاصيل: ' . mb_substr($notes, 0, 90);
        $admin_line .= ' — من: ' . $user_name . ($user_ref ? ' (' . $user_ref . ')' : '')
            . (!empty($cu['email']) ? ' — ' . $cu['email'] : '');
        if ($img_path !== '') $admin_line .= ' — صورة مرفقة';
        notify('admin', $admin_line, 'dashboard/admin.php?tab=requests');
        echo json_encode(['ok' => true, 'request' => $new_req], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $req_id = trim((string)($_REQUEST['req_id'] ?? ''));

    if ($action === 'send' && $req_id !== '' && isset($reqs[$req_id])) {
        $text = trim((string)($_POST['text'] ?? ''));
        $img_path = save_uploaded_image('image', 'reqchat');
        $new_status = trim((string)($_POST['status'] ?? ''));
        $product_link = trim((string)($_POST['product_link'] ?? ''));
        $as_admin = $is_admin && (!empty($_POST['as_admin']) || $reqs[$req_id]['user_key'] !== $user_key);

        if ($as_admin && $new_status !== '' && in_array($new_status, ['pending', 'sourcing', 'available', 'unavailable'], true)) {
            $reqs[$req_id]['status'] = $new_status;
        }
        if ($as_admin && $product_link !== '') {
            $reqs[$req_id]['product_link'] = $product_link;
        }

        if ($text !== '' || $img_path !== '') {
            $msg = [
                'id' => uniqid('rm_'),
                'sender' => $as_admin ? 'admin' : 'user',
                'sender_name' => $as_admin ? 'إدارة ' . BRAND : $user_name,
                'text' => $text,
                'image' => $img_path,
                'at' => date('c')
            ];
            $reqs[$req_id]['messages'][] = $msg;
            $reqs[$req_id]['updated_at'] = date('c');
            if ($as_admin) {
                $reqs[$req_id]['unread_user'] = (int)($reqs[$req_id]['unread_user'] ?? 0) + 1;
                $reqs[$req_id]['unread_admin'] = 0;
                if (!empty($reqs[$req_id]['user_email']) && strpos($reqs[$req_id]['user_email'], '@') !== false) {
                    notify_user($reqs[$req_id]['user_email'], '📦 رد جديد من الإدارة بخصوص طلب المنتج (' . $reqs[$req_id]['product_name'] . ')', 'product_requests.php?req_id=' . urlencode($req_id));
                }
            } else {
                $reqs[$req_id]['unread_admin'] = (int)($reqs[$req_id]['unread_admin'] ?? 0) + 1;
                $reqs[$req_id]['unread_user'] = 0;
                notify('admin', '📦 رد جديد على طلب توفير المنتج (' . ($reqs[$req_id]['product_name'] ?? '') . ') من '
                    . $user_name . ' — ' . mb_substr($text !== '' ? $text : 'صورة مرفقة', 0, 90)
                    + ($img_path !== '' ? ' (صورة)' : ''), 'dashboard/admin.php?tab=requests');
            }
        }
        jsave('product_requests.json', $reqs);
        echo json_encode(['ok' => true, 'request' => $reqs[$req_id]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'poll' && $req_id !== '' && isset($reqs[$req_id])) {
        $as_admin_view = $is_admin && !empty($_GET['as_admin']);
        if ($as_admin_view && !empty($reqs[$req_id]['unread_admin'])) {
            $reqs[$req_id]['unread_admin'] = 0;
            jsave('product_requests.json', $reqs);
        } elseif (!$as_admin_view && !empty($reqs[$req_id]['unread_user'])) {
            $reqs[$req_id]['unread_user'] = 0;
            jsave('product_requests.json', $reqs);
        }
        echo json_encode(['ok' => true, 'request' => $reqs[$req_id]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // List requests
    $list = [];
    foreach ($reqs as $rid => $r) {
        if (!$is_admin && ($r['user_key'] ?? '') !== $user_key && (empty($cu['email']) || strtolower($r['user_email'] ?? '') !== strtolower($cu['email']))) {
            continue;
        }
        $list[] = $r;
    }
    usort($list, function ($a, $b) {
        return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
    });
    echo json_encode(['ok' => true, 'requests' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'قناة غير معروفة'], JSON_UNESCAPED_UNICODE);
