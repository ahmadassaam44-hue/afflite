<?php
$PUBLIC_PAGE = true;
require 'layout.php';

$cu = current_user();
$vid = function_exists('visitor_id') ? visitor_id() : session_id();
$is_admin = is_admin_user();
$user_key = !empty($cu['email']) ? strtolower(trim($cu['email'])) : ('guest_' . substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $vid), 0, 24));

// أدوات المسوقين: «طلب توفير منتج» يُفتح من سياق لوحة المسوقين فقط — عملاء الموقع الأساسي يُوجَّهون للدعم وطلب المنتج من الكتالوج
if (!$is_admin && ($_GET['ctx'] ?? '') !== 'marketer') {
    header('Location: support.php?ctx=customer');
    exit;
}

$reqs = jload('product_requests.json', []);
if (!is_array($reqs)) $reqs = [];

// Handle direct form POST fallback if JS disabled
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'new_request') {
    $pname = trim((string)($_POST['product_name'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $expected_price = trim((string)($_POST['expected_price'] ?? ''));
    $img_path = save_uploaded_image('product_image', 'req');
    $img_url_input = trim((string)($_POST['image_url'] ?? ''));
    if ($img_path === '' && $img_url_input !== '') $img_path = $img_url_input;
    if ($pname !== '') {
        $req_id = 'REQ-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $uname = !empty($cu['name']) ? $cu['name'] : ('مسوق #' . substr($vid, 0, 6));
        $init_txt = "📦 طلب توفير منتج: " . $pname;
        if ($expected_price !== '') $init_txt .= "\n💰 السعر المتوقع: " . $expected_price . " ج.م";
        if ($notes !== '') $init_txt .= "\n📝 تفاصيل: " . $notes;
        $reqs[$req_id] = [
            'id' => $req_id,
            'user_key' => $user_key,
            'user_name' => $uname,
            'user_email' => $cu['email'] ?? '',
            'user_ref' => $cu['ref_code'] ?? '',
            'product_name' => $pname,
            'product_image' => $img_path,
            'expected_price' => $expected_price,
            'notes' => $notes,
            'status' => 'pending',
            'product_link' => '',
            'unread_admin' => 1,
            'unread_user' => 0,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'messages' => [
                ['id' => uniqid('rm_'), 'sender' => 'user', 'sender_name' => $uname, 'text' => $init_txt, 'image' => $img_path, 'at' => date('c')],
                ['id' => uniqid('rm_'), 'sender' => 'admin', 'sender_name' => 'إدارة ' . BRAND, 'text' => 'تم استلام طلب توفير المنتج (' . $pname . ') بنجاح ✅ سنتواصل معك هنا في الشات فور توفيره.', 'image' => '', 'at' => date('c')]
            ]
        ];
        jsave('product_requests.json', $reqs);
        $admin_line = '📸 طلب توفير منتج جديد (' . $pname . ')';
        if ($expected_price !== '') $admin_line .= ' — السعر المتوقع: ' . $expected_price . ' ج.م';
        if ($notes !== '') $admin_line .= ' — تفاصيل: ' . mb_substr($notes, 0, 90);
        $admin_line .= ' — من: ' . $uname . (!empty($cu['email']) ? ' — ' . $cu['email'] : '');
        if ($img_path !== '') $admin_line .= ' — صورة مرفقة';
        notify('admin', $admin_line, 'dashboard/admin.php?tab=requests');
        header('Location: product_requests.php?req_id=' . urlencode($req_id));
        exit;
    }
}

$my_reqs = [];
foreach ($reqs as $rid => $r) {
    if ($is_admin || ($r['user_key'] ?? '') === $user_key || (!empty($cu['email']) && strtolower($r['user_email'] ?? '') === strtolower($cu['email']))) {
        $my_reqs[$rid] = $r;
    }
}
uasort($my_reqs, function ($a, $b) {
    return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
});

$active_id = trim((string)($_GET['req_id'] ?? ''));
if ($active_id === '' || !isset($my_reqs[$active_id])) {
    $active_id = $my_reqs ? array_key_first($my_reqs) : '';
}
$active_req = $active_id ? $my_reqs[$active_id] : null;

$status_badges = [
    'pending'     => ['⏳ قيد المراجعة', '#fef3c7', '#b45309'],
    'sourcing'    => ['🔄 جاري توفير المنتج', '#dbeafe', '#1d4ed8'],
    'available'   => ['✅ تم توفير المنتج بالمتجر', '#dcfce7', '#15803d'],
    'unavailable' => ['❌ غير متوفر حالياً', '#fee2e2', '#b91c1c'],
];

layout_top('طلبات توفير المنتجات (شات مباشر مع الإدارة)', 'product_requests');
?>


<div class="card" data-ui="u-4203bf3e d3u d3v d3w d6m dh d1o d1p dj d2c">
    <div>
        <h2 data-ui="u-b7233671 d3x d2 d18"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-camera></use></svg> اطلب توفير أي منتج غير موجود بالمتجر</h2>
        <p data-ui="u-0c538337 du d6d d19">لو معاك منتج شغال وعليه طلب ومش لاقيه في الموقع، ارفع صورته واسمه هنا وهيتفتح شات مباشر بينك وبين الإدارة لتوفيره لك بأعلى عمولة وأقل سعر جملة!</p>
    </div>
    <div data-ui="u-3ecc8ff6 dh d1s">
        <a href="marketer.php" class="btn sm" data-ui="u-c872664c d6r d2k"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-megaphone></use></svg> لوحة المسوق</a>
        <a href="support.php" class="btn sm outline" data-ui="u-f10665d6 d3v d95"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-chat></use></svg> شات الدعم الفني</a>
    </div>
</div>

<div class="pr-grid">
    <!-- العمود الأيمن: نموذج طلب منتج جديد + قائمة الطلبات -->
    <div data-ui="u-dbb42676 dh d5n d1w">
        <div class="pr-card">
            <div class="pr-head">
                <b data-ui="u-458b41ed dz"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-plus></use></svg> تقديم طلب منتج جديد</b>
                <span data-ui="u-82284668 do d6r d2k d35 d36 d1">بالاسم والصورة</span>
            </div>
            <form method="post" enctype="multipart/form-data" data-ui="u-c2524ce4 d7">
                <input type="hidden" name="form_action" value="new_request">
                <label data-ui="u-303701ca do dc d27 d45">اسم المنتج المطلوب *</label>
                <input name="product_name" required placeholder="مثال: كوتشي نايك إير جوردن أبيض / خلاط محمول..." data-ui="u-9ce9e9cd d11">

                <label data-ui="u-303701ca do dc d27 d45"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-camera></use></svg> صورة المنتج (ملف صورة من جهازك أو الموبايل)</label>
                <input type="file" name="product_image" accept="image/*" data-ui="u-d5688da9 d2d d25 ds d96 d24 dx">

                <label data-ui="u-a3379959 do d1a d27 d45">أو ضع رابط صورة المنتج (اختياري):</label>
                <input name="image_url" placeholder="https://..." data-ui="u-3d772fb2 d11 do">

                <label data-ui="u-303701ca do dc d27 d45"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-wallet></use></svg> السعر المتوقع أو المستهدف (اختياري)</label>
                <input name="expected_price" placeholder="مثال: 350 ج.م" data-ui="u-9ce9e9cd d11">

                <label data-ui="u-303701ca do dc d27 d45"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-edit></use></svg> تفاصيل إضافية (مقاسات / ألوان / كمية متوقعة)</label>
                <textarea name="notes" rows="2" placeholder="اكتب أي تفاصيل تساعد الإدارة على توفير المنتج لك بسرعة..." data-ui="u-9e82dcff d54"></textarea>

                <button type="submit" class="btn" data-ui="u-81653470 dx dw dg d12"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-arrow-left></use></svg> إرسال الطلب وفتح الشات مع الإدارة</button>
            </form>
        </div>

        <div class="pr-card">
            <div class="pr-head" data-ui="u-1139fef6 d97">
                <b data-ui="u-55e19728 d12"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-copy></use></svg> طلبات المنتجات (<?php echo count($my_reqs); ?>)</b>
                <?php if ($is_admin): ?><span data-ui="u-1496fbec do d4e d3v d6v d36">وضع الإدارة</span><?php endif; ?>
            </div>
            <div data-ui="u-83281aba d98 d99">
                <?php if (!$my_reqs): ?>
                    <div data-ui="u-d3390107 d9a df d1a du">لا توجد طلبات منتجات حتى الآن. ارفع أول منتج تريده من النموذج أعلاه!</div>
                <?php else: foreach ($my_reqs as $rid => $r):
                    $st_info = $status_badges[$r['status'] ?? 'pending'] ?? $status_badges['pending'];
                    $is_on = ($rid === $active_id);
                    $unread = $is_admin ? (int)($r['unread_admin'] ?? 0) : (int)($r['unread_user'] ?? 0);
                    $thumb = !empty($r['product_image']) ? asset_url($r['product_image']) : '';
                ?>
                    <a href="?req_id=<?php echo urlencode($rid); ?>" class="pr-req-item <?php echo $is_on ? 'active' : ''; ?>">
                        <?php if ($thumb): ?>
                            <img src="<?php echo esc($thumb); ?>" class="pr-req-thumb" alt="<?php echo esc($r['product_name'] ?? 'المنتج المطلوب'); ?>" width="54" height="54" loading="lazy">
                        <?php else: ?>
                            <div class="pr-req-thumb"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg></div>
                        <?php endif; ?>
                        <div data-ui="u-d2b411f5 d2i d2j">
                            <div data-ui="u-a0d077e5 dh d1o d1p di">
                                <b data-ui="u-cb453961 dd d2k d2m d2n d2o"><?php echo esc($r['product_name']); ?></b>
                                <?php if ($unread > 0): ?>
                                    <span data-ui="u-487acd6e d4f d3v do d1 d8r d36"><?php echo $unread; ?> جديد</span>
                                <?php endif; ?>
                            </div>
                            <div data-ui="u-b37efb54 do d1a d9b"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-user></use></svg> <?php echo esc($r['user_name'] ?? 'مسوق'); ?> • #<?php echo esc($rid); ?></div>
                            <span style="display:inline-block;padding:2px 8px;border-radius:99px;font-size:12px;font-weight:800;background:<?php echo $st_info[1]; ?>;color:<?php echo $st_info[2]; ?>"><?php echo $st_info[0]; ?></span>
                        </div>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- العمود الأيسر: شات مباشر حول المنتج المطلوب -->
    <div class="pr-card">
        <?php if (!$active_req): ?>
            <div data-ui="u-a58136d4 d9c df d1a">
                <div data-ui="u-57f30efd d9d d54"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-camera></use></svg></div>
                <h3 data-ui="u-20fb4aa3 d2k d2">اختر طلباً أو أرسل طلب منتج جديد</h3>
                <p data-ui="u-5d7ebccb du">بمجرد إرسال اسم وصورة المنتج المطلوب، سيفتح هنا شات حي ومباشر بينك وبين الإدارة لمتابعة توفيره خطوة بخطوة.</p>
            </div>
        <?php else:
            $st_info = $status_badges[$active_req['status'] ?? 'pending'] ?? $status_badges['pending'];
            $pimg = !empty($active_req['product_image']) ? asset_url($active_req['product_image']) : '';
        ?>
            <!-- هيدر تفاصيل المنتج المطلوب -->
            <div data-ui="u-9e61e1b8 d9e d4u d9f dh d1o d1p dj d3z">
                <div data-ui="u-c9507a58 dh d2c d1p">
                    <?php if ($pimg): ?>
                        <img src="<?php echo esc($pimg); ?>" alt="<?php echo esc($active_req['product_name']); ?>" width="64" height="64" onclick="window.open(this.src,'_blank')" data-ui="u-e9351970 d57 d58 d42 d2t d9g d3j" title="اضغط لتكبير صورة المنتج">
                    <?php else: ?>
                        <div class="pr-req-thumb" data-ui="u-30a99458 d57 d58"><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-box></use></svg></div>
                    <?php endif; ?>
                    <div>
                        <div data-ui="u-9fdc4e9e dh d1p d1s dj">
                            <h3 data-ui="u-146a4df4 d19 d9h d2k"><?php echo esc($active_req['product_name']); ?></h3>
                            <span id="prStatusBadge" style="padding:3px 10px;border-radius:99px;font-size:12px;font-weight:800;background:<?php echo $st_info[1]; ?>;color:<?php echo $st_info[2]; ?>"><?php echo $st_info[0]; ?></span>
                        </div>
                        <div data-ui="u-d1bebff8 do d1a d3i">
                            كود الطلب: <b><?php echo esc($active_req['id']); ?></b>
                            • المسوق: <b><?php echo esc($active_req['user_name']); ?></b>
                            <?php if (!empty($active_req['expected_price'])): ?> • السعر المطلوب: <b data-ui="u-61a4e8ed d1x"><?php echo esc($active_req['expected_price']); ?> ج.م</b><?php endif; ?>
                        </div>
                        <?php if (!empty($active_req['product_link'])): ?>
                            <div data-ui="u-ded3e017 d49"><a href="<?php echo esc($active_req['product_link']); ?>" class="btn sm green" data-ui="u-b047800c d9i do"> افتح صفحة المنتج في المتجر</a></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($is_admin): ?>
                <!-- أدوات الإدارة السريعة لتحديث حالة الطلب ورابط المنتج -->
                <div data-ui="u-4296194f dh di d1p dj ds d38 da dt">
                    <select id="adminReqStatus" data-ui="u-c1782673 d6x do d1u d64 d92">
                        <option value="pending" <?php echo ($active_req['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>⏳ قيد المراجعة</option>
                        <option value="sourcing" <?php echo ($active_req['status'] ?? '') === 'sourcing' ? 'selected' : ''; ?>> جاري توفير المنتج</option>
                        <option value="available" <?php echo ($active_req['status'] ?? '') === 'available' ? 'selected' : ''; ?>> تم توفير المنتج</option>
                        <option value="unavailable" <?php echo ($active_req['status'] ?? '') === 'unavailable' ? 'selected' : ''; ?>> غير متوفر حالياً</option>
                    </select>
                    <input id="adminReqLink" value="<?php echo esc($active_req['product_link'] ?? ''); ?>" placeholder="رابط المنتج بعد إضافته..." data-ui="u-cfeabdd9 d6x do d9j d19">
                    <button type="button" onclick="updateReqStatusAdmin()" class="btn sm" data-ui="u-8091c990 d9k do">حفظ الحالة</button>
                </div>
                <?php endif; ?>
            </div>

            <!-- صندوق الرسائل الحي -->
            <div id="prChatBox" class="pr-chat-box"></div>

            <!-- شريط إرسال رسالة أو صورة داخل الشات -->
            <form id="prChatForm" class="pr-input-bar" onsubmit="return sendPrMessage(event)">
                <label title="إرفاق صورة في الشات" data-ui="u-c7afeb22 d3j d9l da d8q d64 d9h d3e d1p d4g d19">
                    <svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-camera></use></svg> <span data-ui="u-a46e9808 do dc d9m">صورة</span>
                    <input type="file" id="prChatImage" name="image" aria-label="إرفاق صورة لطلب المنتج" accept="image/*" class="sr-only" onchange="document.getElementById('prImgName').textContent = this.files[0] ? this.files[0].name : ''">
                </label>
                <span id="prImgName" data-ui="u-ddd04b06 do d1d dm d9n d2n d2o d2m"></span>
                <input type="text" id="prChatText" name="text" aria-label="رسالتك" placeholder="اكتب رسالتك هنا للإدارة بخصوص هذا المنتج..." data-ui="u-3e026942 d2i d19 d36 d9o">
                <button type="submit" class="btn" data-ui="u-625506c4 d36 d9p">إرسال </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($active_req): ?>
<div hidden id="wfRequestState" data-state="<?php echo esc(json_encode(['req_id' => $active_req['id'], 'as_admin' => $is_admin ? 1 : 0, 'messages' => $active_req['messages'] ?? []], JSON_UNESCAPED_UNICODE)); ?>"></div>
<?php endif; ?>
<?php layout_end(); ?>
