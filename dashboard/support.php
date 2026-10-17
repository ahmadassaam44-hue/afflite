<?php
$PUBLIC_PAGE = true;
require 'layout.php';

$cu = current_user();
$vid = function_exists('visitor_id') ? visitor_id() : session_id();
$is_admin = is_admin_user();
$user_key = !empty($cu['email']) ? strtolower(trim($cu['email'])) : ('guest_' . substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $vid), 0, 24));
// فصل دعم الموقع الأساسي (العملاء) عن دعم/أدوات المسوقين
$is_mk_ctx = $is_admin || (($_GET['ctx'] ?? '') === 'marketer');   // سياق المسوقين يُفتح من لوحة المسوقين فقط
$user_name = !empty($cu['name']) ? $cu['name'] : (($is_mk_ctx ? 'مسوق #' : 'عميل #') . substr($vid, 0, 6));

$chats = jload('support_chats.json', []);
if (!is_array($chats)) $chats = [];

// ترحيل أي تذاكر قديمة من tickets.json إلى الشات المباشر حتى لا تضيع أي رسالة سابقة
$old_tickets = jload('tickets.json', []);
if (is_array($old_tickets) && $old_tickets) {
    $migrated = false;
    foreach ($old_tickets as $tk) {
        $uk = strtolower(trim((string)($tk['user'] ?? '')));
        if ($uk === '') continue;
        if (!isset($chats[$uk])) {
            $chats[$uk] = [
                'thread_id' => $uk,
                'user_name' => $uk,
                'user_email' => $uk,
                'user_ref' => '',
                'created_at' => $tk['at'] ?? date('c'),
                'updated_at' => $tk['at'] ?? date('c'),
                'unread_admin' => 1,
                'unread_user' => 0,
                'messages' => []
            ];
        }
        $already = false;
        foreach ($chats[$uk]['messages'] as $em) {
            if (($em['text'] ?? '') === ($tk['msg'] ?? '') && ($em['at'] ?? '') === ($tk['at'] ?? '')) {
                $already = true;
                break;
            }
        }
        if (!$already && !empty($tk['msg'])) {
            $chats[$uk]['messages'][] = [
                'id' => uniqid('m_'),
                'sender' => 'user',
                'sender_name' => $uk,
                'text' => $tk['msg'],
                'image' => '',
                'at' => $tk['at'] ?? date('c')
            ];
            if (!empty($tk['reply'])) {
                $chats[$uk]['messages'][] = [
                    'id' => uniqid('m_'),
                    'sender' => 'admin',
                    'sender_name' => 'الإدارة (' . BRAND . ')',
                    'text' => $tk['reply'],
                    'image' => '',
                    'at' => $tk['reply_at'] ?? date('c')
                ];
            }
            $migrated = true;
        }
    }
    if ($migrated) jsave('support_chats.json', $chats);
}

$active_thread = trim((string)($_GET['thread'] ?? ''));
$sup_prefill = mb_substr(trim((string)($_GET['prefill'] ?? '')), 0, 900); // تفاصيل منتج جاية من «السعر عالي؟»
if (!$is_admin || $active_thread === '') {
    $active_thread = $is_admin && !empty($chats) ? array_key_first($chats) : $user_key;
}
if (!isset($chats[$active_thread])) {
    $chats[$active_thread] = [
        'thread_id' => $active_thread,
        'user_name' => $user_name,
        'user_email' => $cu['email'] ?? $active_thread,
        'user_ref' => $cu['ref_code'] ?? '',
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'unread_admin' => 0,
        'unread_user' => 0,
        'messages' => [
            [
                'id' => uniqid('m_'),
                'sender' => 'admin',
                'sender_name' => 'فريق دعم ' . BRAND,
                'text' => 'أهلاً بك يا ' . $user_name . ' في الشات المباشر مع الإدارة 👋 اكتب رسالتك أو أرفق صورة وسنرد عليك في الحال!',
                'image' => '',
                'at' => date('c')
            ]
        ]
    ];
    jsave('support_chats.json', $chats);
}

layout_top($is_mk_ctx ? 'شات المسوقين المباشر مع الإدارة' : 'دعم العملاء — الشات المباشر مع الإدارة', 'support');
$sup_wa = (!$is_mk_ctx && function_exists('wf_support_wa_href')) ? wf_support_wa_href('السلام عليكم، عندي استفسار من موقع ' . BRAND . ' — ممكن مساعدة؟') : '';
?>


<div class="sup-wrap <?php echo $is_admin ? 'is-admin' : ''; ?>">
    <?php if ($is_admin): ?>
    <!-- قائمة محادثات المسوقين للإدارة -->
    <div class="sup-card">
        <div class="sup-header" data-ui="u-b6e31f22 d4q">
            <b><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-users></use></svg> محادثات المسوقين (<?php echo count($chats); ?>)</b>
            <span data-ui="u-0ea23469 do d6r d2k dc3 d36 d1">إدارة الشات</span>
        </div>
        <div data-ui="u-315102dc dc4 d99">
            <?php
            $sorted_chats = $chats;
            uasort($sorted_chats, function ($a, $b) { return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''); });
            foreach ($sorted_chats as $tk => $th):
                $msgs = $th['messages'] ?? [];
                $last = end($msgs);
                $unr = (int)($th['unread_admin'] ?? 0);
                $on = ($tk === $active_thread);
            ?>
            <a href="?thread=<?php echo urlencode($tk); ?>" class="sup-thread-item <?php echo $on ? 'active' : ''; ?>">
                <div data-ui="u-dbda0656 d2j d2i">
                    <div data-ui="u-0664c8c0 dh d1p di">
                        <b data-ui="u-593ac865 dd d2k"><?php echo esc($th['user_name'] ?? $tk); ?></b>
                        <?php if (!empty($th['user_ref'])): ?><span data-ui="u-d593e069 do d50 d3s dc5 d2w dc"><?php echo esc($th['user_ref']); ?></span><?php endif; ?>
                    </div>
                    <div data-ui="u-87d5dea5 do d1a d2m d2n d2o dc6">
                        <?php echo esc($last['text'] ?? (!empty($last['image']) ? ' صورة مرفقة' : '')); ?>
                    </div>
                </div>
                <?php if ($unr > 0): ?>
                    <span data-ui="u-e352c375 d4f d3v do d1 d6v d36"><?php echo $unr; ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- نافذة الشات المباشر -->
    <div class="sup-card">
        <div class="sup-header">
            <div data-ui="u-70bc9bf2 dh d1p d3z">
                <div data-ui="u-170cd518 d5v d5w dbo d6r d2k dh d1p dw d18 d1"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chat></use></svg></div>
                <div>
                    <div data-ui="u-5fddd367 dh d1p d1s">
                        <b data-ui="u-36c621e1 d26">
                            <?php echo $is_admin ? ('محادثة مع: ' . esc($chats[$active_thread]['user_name'] ?? $active_thread)) : ($is_mk_ctx ? 'دعم المسوقين المباشر — إدارة ' : 'دعم عملاء الموقع — إدارة ') . BRAND; ?>
                        </b>
                        <span data-ui="u-495d6811 d3e d1p d4g do dc7 dv dc3 d36 dc">
                             <?php echo $is_mk_ctx ? 'محادثة المسوق' : 'محادثة العميل'; ?>
                        </span>
                    </div>
                    <div data-ui="u-402d49f2 do dn d4x"><?php echo $is_mk_ctx ? 'محادثة مع فريق دعم المسوقين — يمكنك إرسال رسائل وصور' : 'استفسار عن طلب أو منتج من الموقع — فريق خدمة العملاء يرد هنا'; ?></div>
                </div>
            </div>
            <div data-ui="u-3ecc8ff6 dh d1s">
                <?php if ($is_mk_ctx): ?>
                <a href="product_requests.php?ctx=marketer" class="btn sm" data-ui="u-a74c8e2a d6r d2k dc"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-camera></use></svg> طلب توفير منتج بالصورة</a>
                <?php else: ?>
                <a href="orders.php" class="btn sm outline" data-ui="u-a74c8e2a d6r d2k dc"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg> تابع طلباتك</a>
                <a href="../index.php" class="btn sm outline" data-ui="u-a74c8e2a d6r d2k dc"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-store></use></svg> تسوق من الموقع</a>
                <?php if ($sup_wa !== ''): ?><a href="<?php echo esc($sup_wa); ?>" target="_blank" rel="noopener" class="btn sm outline" data-ui="u-a74c8e2a d6r d2k dc"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-whatsapp></use></svg> واتساب خدمة العملاء</a><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="supMessagesBox" class="sup-messages"></div>

        <!-- أزرار رسائل سريعة -->
        <div class="sup-quick-bar">
            <?php if (!$is_admin): ?>
                <button type="button" class="sup-chip" onclick="fillSupQuick('أريد متابعة حالة شحن الأوردر رقم: ')"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-truck></use></svg> متابعة شحنة / أوردر</button>
                <?php if ($is_mk_ctx): ?>
                <button type="button" class="sup-chip" onclick="fillSupQuick('السلام عليكم، عندي استفسار بخصوص عمولة طلب: ')"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> استفسار عن عمولة</button>
                <button type="button" class="sup-chip" onclick="fillSupQuick('قمت بطلب سحب أرباح وأود تأكيد التحويل: ')"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> سحب الأرباح</button>
                <button type="button" class="sup-chip" onclick="location.href='product_requests.php?ctx=marketer'"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-camera></use></svg> طلب منتج غير موجود بالصورة</button>
                <?php else: ?>
                <button type="button" class="sup-chip" onclick="fillSupQuick('استفسار عن منتج من الموقع: ')"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-search></use></svg> استفسار عن منتج</button>
                <button type="button" class="sup-chip" onclick="fillSupQuick('عندي مشكلة في الطلب رقم: ')"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-info></use></svg> مشكلة في طلب</button>
                <button type="button" class="sup-chip" onclick="fillSupQuick('سؤال عن الاستبدال أو الاسترجاع: ')"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-refresh></use></svg> استبدال / استرجاع</button>
                <?php endif; ?>
            <?php else: ?>
                <button type="button" class="sup-chip" onclick="fillSupQuick('أهلاً بك يا فندم، جاري مراجعة طلبك الآن وسنوافيك بالرد فوراً <svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg>')"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-check></use></svg> جاري المراجعة</button>
                <button type="button" class="sup-chip" onclick="fillSupQuick('تم تأكيد العمولة وإضافتها لرصيدك بنجاح <svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg>')"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> تم تأكيد العمولة</button>
                <button type="button" class="sup-chip" onclick="fillSupQuick('تم شحن الطلب مع شركة الشحن وجاري التوصيل للعميل <svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-truck></use></svg>')"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-truck></use></svg> تم شحن الأوردر</button>
            <?php endif; ?>
        </div>

        <form id="supChatForm" class="sup-input-bar" onsubmit="return sendSupMessage(event)">
            <label title="إرفاق صورة أو سكرين شوت" data-ui="u-a840850a d3j d9l da d8q d64 d9h d3e d1p d4a d19">
                <svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-camera></use></svg> <span data-ui="u-a46e9808 do dc d9m">صورة</span>
                <input type="file" id="supChatImage" name="image" aria-label="إرفاق صورة في المحادثة" accept="image/*" class="sr-only" onchange="document.getElementById('supImgLabel').textContent = this.files[0] ? this.files[0].name : ''">
            </label>
            <span id="supImgLabel" data-ui="u-ddd04b06 do d1d dm d9n d2n d2o d2m"></span>
            <input type="text" id="supChatText" name="text" aria-label="رسالتك" placeholder="اكتب رسالتك هنا واضغط إرسال..." value="<?php echo esc($sup_prefill); ?>" data-ui="u-3e026942 d2i d19 d36 d9o">
            <button type="submit" class="btn" data-ui="u-6955fc1b d36 dc8">إرسال </button>
        </form>
    </div>
</div>

<div hidden id="wfSupportState" data-state="<?php echo esc(json_encode(['thread' => $active_thread, 'as_admin' => (($is_admin && $active_thread !== $user_key) || ($is_admin && isset($_GET['thread']))) ? 1 : 0, 'messages' => $chats[$active_thread]['messages'] ?? []], JSON_UNESCAPED_UNICODE)); ?>"></div>
<?php layout_end(); ?>
