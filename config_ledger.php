<?php
// ============================================================================
// config_ledger.php — جزء من config.php (السطور 606–1090 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// ============================================================================
//  سجل حركات الرصيد (Ledger) لكل مسوق
// ============================================================================
function active_sub() {
    $v = $_SESSION['aff_sub'] ?? ($_COOKIE['aff_sub'] ?? '');
    return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$v), 0, 40);
}
// إشعار تليجرام للأدمن (اختياري): ضع TG_BOT_TOKEN و TG_CHAT_ID في secrets.php
function tg_notify($text) {
    if (!defined('TG_BOT_TOKEN') || !defined('TG_CHAT_ID') || TG_BOT_TOKEN === '' || TG_CHAT_ID === '') return false;
    $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage';
    $body = http_build_query(['chat_id' => TG_CHAT_ID, 'text' => mb_substr($text, 0, 3500), 'disable_web_page_preview' => 1]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 4]);
        @curl_exec($ch); curl_close($ch); return true;
    }
    @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body, 'timeout' => 4]]));
    return true;
}

// ============================================================================
//  إعدادات الموقع القابلة للتعديل من لوحة الأدمن — data/settings.json
// ============================================================================
function site_settings() {
    static $st = null;
    if ($st === null) { $st = jload('settings.json', []); if (!is_array($st)) $st = []; }
    return $st;
}
function site_setting($k, $def = '') { $st = site_settings(); return isset($st[$k]) && $st[$k] !== '' ? $st[$k] : $def; }
// استخراج معرف فيديو يوتيوب (11 خانة) من أي صيغة: youtu.be / watch?v= / embed / shorts / معرف خام — وأي حاجة تانية ترجع فاضي
function wf_youtube_id($url) {
    $u = trim((string)$url);
    if ($u === '') return '';
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', $u)) return $u;
    if (preg_match('~^(?:https?://)?(?:www\.)?(?:youtu\.be/|youtube\.com/(?:watch\?(?:[^\s]*?&)?v=|embed/|shorts/))([A-Za-z0-9_-]{11})~', $u, $m)) return $m[1];
    return '';
}
// منتجات الكاروسيل الرئيسي: الأدمن بيختارها من إعداد hero_products (لحد 5 منتجات).
// بترجع نفس شكل الشرائح (image / link / title) + السعر، فالعرض بيشتغل بنفس الكود.
function hero_product_slides() {
    $raw = (string)site_setting('hero_products', '');
    if (trim($raw) === '') return [];
    $ids = preg_split('/[\s,،]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach (array_slice(array_values(array_unique($ids)), 0, 5) as $id) {
        $p = find_product_by_id($id);
        if (!$p) continue;
        $plat = $p['_plat'] ?? resolve_plat($p['id'] ?? '');
        $img = product_first_image($p, $plat) ?: wf_product_placeholder($plat);
        $price = (float)display_price($p, $plat);
        // العملة من المنتج نفسه (منتج يدوي سعودي = ر.س، إماراتي = د.إ …) — عرض فقط ومن غير أي تحويل
        $cur = trim((string)($p['currency'] ?? ''));
        if ($cur === '' && $plat === 'm5zoon' && function_exists('mz_src_currency')) $cur = mz_src_currency($p);
        if ($cur === '') $cur = wf_country_currency($p['country'] ?? 'مصر');
        if (function_exists('wf_ui_currency_label')) $cur = wf_ui_currency_label($cur);
        $out[] = [
            'link'  => 'dashboard/product.php?id=' . urlencode((string)$p['id']),
            'image' => $img,
            'title' => (string)($p['name'] ?? ''),
            'price' => $price > 0 ? number_format($price, 0) . ' ' . $cur : '',
        ];
    }
    return $out;
}
function site_settings_schema() {
    return [
        'الدفع الإلكتروني (السوشيال والرقمي)' => [
            'pay_instapay'  => ['عنوان انستا باي (مثال: name@instapay)', 'text'],
            'pay_vodafone'  => ['رقم فودافون كاش', 'text'],
            'pay_orange'    => ['رقم أورانج كاش', 'text'],
            'pay_etisalat'  => ['رقم اتصالات كاش', 'text'],
            'pay_we'        => ['رقم وي باي', 'text'],
            'pay_bank'      => ['حساب بنكي / IBAN (اختياري)', 'text'],
            'pay_name'      => ['اسم صاحب الحساب (يظهر للعميل)', 'text'],
            'pay_note'      => ['ملاحظة تظهر في صفحة الدفع', 'textarea'],
        ],
        'عمولات المنصات' => [
            'commission_safqa_pct'  => ['نسبة عمولة صفقة % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
            'commission_angazny_pct'=> ['نسبة عمولة أنجزني % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
            'commission_m5zoon_pct' => ['نسبة عمولة مخزون % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
            'commission_shopmart_pct' => ['نسبة عمولة Shopmart % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
            // الرقمي والسوشيال ميديا اتشالوا من نسب العمولة: سعرهم = سعر المصدر (شوف DIGITAL_USD_EGP)
            'commission_ledger_pct' => ['نسبة العمولة الاحتياطية % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
        ],
        'الرقمي والسوشيال ميديا (بلا عمولة)' => [
            'usd_egp'            => ['سعر الدولار اللي بيتحسب به المنتج الرقمي (ج.م لكل دولار) — تركه فاضي = 56', 'text'],
            'social_price_addon' => ['إضافة الخدمات الصغيرة بس: أي مبلغ سوشيال ميديا أقل من المبلغ ده (ج.م) بيتضاف عليه، والأكبر منه بيفضل سعر المصدر — تركه فاضي = 0.10', 'text'],
        ],
        'التواصل' => [
            'whatsapp'      => ['رقم واتساب الدعم (بصيغة دولية 2010xxxxxxx)', 'text'],
            'wa_cloud_token'=> ['WhatsApp Cloud API — Access Token (اختياري)', 'password'],
            'wa_phone_id'   => ['WhatsApp Cloud API — Phone Number ID (اختياري)', 'text'],
            'wa_admin_to'   => ['رقم الأدمن لاستقبال إشعارات واتساب', 'text'],
        ],
        'الصفحة الرئيسية' => [
            'hero_products' => ['منتجات الكاروسيل الرئيسي (من 1 لـ 5): أرقام المنتجات مفصولة بفاصلة — انسخها من رابط صفحة المنتج بعد id=', 'text'],
            'hero_video' => ['فيديو شرح المنصة (رابط يوتيوب): بيظهر مضمّن بدل منتجات الكاروسيل الرئيسي — امسحه وسيبه فاضي ترجع المنتجات', 'text'],
        ],
        'التحليلات والبكسل' => [
            'ga4_id'        => ['Google Analytics 4 — Measurement ID (G-XXXXXXX)', 'text'],
            'meta_pixel'    => ['Meta (Facebook) Pixel ID (أرقام فقط)', 'text'],
            'tiktok_pixel'  => ['TikTok Pixel ID (CXXXXXXX)', 'text'],
            'gtm_id'        => ['Google Tag Manager (GTM-XXXXX) — اختياري', 'text'],
        ],
    ];
}
// وسائل الدفع المفعّلة (اللي الأدمن حط لها بيانات)
function pay_channels() {
    $out = [];
    $map = ['pay_instapay' => ['🏦', 'انستا باي InstaPay'], 'pay_vodafone' => ['📱', 'فودافون كاش'], 'pay_orange' => ['🟠', 'أورانج كاش'],
            'pay_etisalat' => ['🟢', 'اتصالات كاش'], 'pay_we' => ['🔵', 'وي باي'], 'pay_bank' => ['💳', 'تحويل بنكي']];
    foreach ($map as $k => $m) { $v = trim((string)site_setting($k)); if ($v !== '') $out[$k] = ['icon' => $m[0], 'label' => $m[1], 'account' => $v]; }
    return $out;
}
// أكواد التتبع (GA4 / Meta / TikTok / GTM) — تُطبع في <head>
function tracking_pixels_html() {
    $h = '';
    if ($g = trim((string)site_setting('gtm_id'))) $h .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc($g) . "');</script>";
    if ($ga = trim((string)site_setting('ga4_id'))) $h .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc($ga) . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","' . esc($ga) . '");</script>';
    if ($fb = preg_replace('/\D/', '', (string)site_setting('meta_pixel'))) $h .= "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . $fb . "');fbq('track','PageView');</script><noscript><img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://www.facebook.com/tr?id=" . $fb . "&ev=PageView&noscript=1\"/></noscript>";
    if ($tt = trim((string)site_setting('tiktok_pixel'))) $h .= "<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement('script');o.type='text/javascript',o.async=!0,o.src=i+'?sdkid='+e+'&lib='+t;var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load('" . esc($tt) . "');ttq.page();}(window,document,'ttq');</script>";
    return $h;
}
// حدث تحويل (شراء/سلة/ليد) لكل البكسلات دفعة واحدة — يُطبع كـ <script>
function tracking_event_js($event, $value = 0, $currency = 'EGP', $extra = []) {
    $v = round((float)$value, 2); $x = json_encode($extra, JSON_UNESCAPED_UNICODE);
    $map = ['purchase' => ['Purchase', 'purchase', 'CompletePayment'], 'add_to_cart' => ['AddToCart', 'add_to_cart', 'AddToCart'], 'lead' => ['Lead', 'generate_lead', 'SubmitForm'], 'view' => ['ViewContent', 'view_item', 'ViewContent'], 'checkout' => ['InitiateCheckout', 'begin_checkout', 'InitiateCheckout']];
    if (!isset($map[$event])) return '';
    list($fb, $ga, $tt) = $map[$event];
    return "<script>try{if(window.fbq)fbq('track','$fb',Object.assign({value:$v,currency:'$currency'},$x));if(window.gtag)gtag('event','$ga',Object.assign({value:$v,currency:'$currency'},$x));if(window.ttq)ttq.track('$tt',Object.assign({value:$v,currency:'$currency'},$x));}catch(e){}</script>";
}
// إشعار واتساب عبر Meta Cloud API (لو مفعّل من الإعدادات)
function wa_notify($to, $text) {
    $tok = trim((string)site_setting('wa_cloud_token')); $pid = trim((string)site_setting('wa_phone_id'));
    $to = preg_replace('/\D/', '', (string)$to);
    if ($tok === '' || $pid === '' || $to === '' || !function_exists('curl_init')) return false;
    if (strpos($to, '0') === 0) $to = '2' . $to;      // 010... → 2010...
    $ch = curl_init('https://graph.facebook.com/v19.0/' . $pid . '/messages');
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => mb_substr($text, 0, 1000)]], JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch); curl_close($ch);
    return $r !== false;
}
function wa_link($phone, $text = '') {
    $p = preg_replace('/\D/', '', (string)$phone); if (strpos($p, '0') === 0) $p = '2' . $p;
    return 'https://wa.me/' . $p . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

function balance_ledger_add($email, $type, $amount, $reason = '', $meta = []) {
    if ($amount == 0) return;
    jupdate('balance_ledger.json', function (&$lg) use ($email, $type, $amount, $reason, $meta) {
        if (!is_array($lg)) $lg = [];
        $lg[] = array_merge([
            'email'   => $email,
            'type'    => $type,          // commission | release | reversal | payout | payout_refund | adjust | referral
            'amount'  => round((float)$amount, 2),
            'reason'  => mb_substr((string)$reason, 0, 250),
            'by'      => admin_actor(),
            'at'      => date('c'),
        ], is_array($meta) ? $meta : []);
        if (count($lg) > 20000) $lg = array_slice($lg, -10000);
        return true;
    }, []);
}
function balance_ledger_for($email, $limit = 200) {
    $all = jload('balance_ledger.json', []);
    if (!is_array($all)) return [];
    $rows = array_values(array_filter($all, function ($r) use ($email) {
        return strcasecmp((string)($r['email'] ?? ''), (string)$email) === 0;
    }));
    return array_slice(array_reverse($rows), 0, $limit);
}

// ============================================================================
//  دورة حياة العمولة: pending → confirmed → paid
// ============================================================================
function commission_credit_pending($mk_email, $amount, $order_idx, $reason = '') {
    $amount = round((float)$amount, 2);
    if ($amount <= 0 || $mk_email === '') return false;
    $mk = find_user($mk_email);
    if (!$mk) return false;
    update_user($mk_email, function (&$u) use ($amount) {
        $u['pending_balance'] = round((float)($u['pending_balance'] ?? 0) + $amount, 2);
        // ❌ لا تُضاف للرصيد المتاح هنا — تُنقل عند التسليم فقط
    });
    balance_ledger_add($mk_email, 'commission', $amount, $reason ?: 'عمولة معلقة',
                       ['order_idx' => $order_idx, 'state' => 'pending']);
    return true;
}
// أقصى مبلغ يُضاف للمتاح عند تسليم أوردر بعد ما عمولته اتعكست قبل كده (المعلق مابقاش فيه المبلغ):
// المسموح = (عمولة الطلبات المسلّمة شاملة الأوردر ده) − (السحوبات غير المرفوضة) − (الرصيد المتاح الحالي).
// لو الرصيد فيه أصلاً اللي يغطي الاستحقاق (أو سُحب أكتر منه) الناتج صفر؛ مفيش تعويض تلقائي ولا رصيد سالب.
function wf_release_headroom($mk_email, $order_idx, $amount, $balance_after_move) {
    $mk = find_user($mk_email);
    if (!$mk || empty($mk['ref_code'])) return 0.0;
    $delivered = (float)$amount;
    foreach ((array)jload('orders.json', [], false) as $i => $o) {
        if (!is_array($o) || (string)$i === (string)(int)$order_idx) continue;
        if (strcasecmp((string)($o['ref'] ?? ''), (string)$mk['ref_code']) !== 0) continue;
        $delivered += wf_order_earned($o);
    }
    $taken = 0.0;
    foreach ((array)jload('payouts.json', [], false) as $po) {
        if (!is_array($po)) continue;
        if (strcasecmp((string)($po['email'] ?? $po['user'] ?? ''), (string)$mk_email) !== 0) continue;
        if (trim((string)($po['status'] ?? 'قيد المراجعة')) === 'مرفوض') continue;
        $taken += (float)($po['amount'] ?? 0);
    }
    return round(max(0, $delivered - $taken - (float)$balance_after_move), 2);
}
// كام من المعلق الحالي بيخص الأوردر ده فعلاً (من السجل): معلق اتسجّل − اللي اتحرر منه − اللي اتعكس منه.
// ده بيمنع إن تسليم أوردر اتعكس قبل كده يستهلك معلق أوردر تاني. أوردر مالوش أي سجل (قبل السجل) = كل المبلغ (السلوك القديم).
function wf_order_pending_held($mk_email, $order_idx, $fallback) {
    $idx = (string)(int)$order_idx; $seen = false; $held = 0.0;
    foreach ((array)jload('balance_ledger.json', [], false) as $r) {
        if (!is_array($r) || !isset($r['order_idx']) || (string)(int)$r['order_idx'] !== $idx) continue;
        if (strcasecmp((string)($r['email'] ?? ''), (string)$mk_email) !== 0) continue;
        $type = (string)($r['type'] ?? ''); $amt = (float)($r['amount'] ?? 0);
        if ($type === 'commission') { $held += $amt; $seen = true; }
        elseif ($type === 'release')  { $held -= isset($r['from_pending']) ? (float)$r['from_pending'] : $amt; $seen = true; }
        elseif ($type === 'reversal') { $held -= isset($r['from_pending']) ? (float)$r['from_pending'] : -$amt; $seen = true; }
        if ($held < 0) $held = 0.0;
    }
    return $seen ? round($held, 2) : round((float)$fallback, 2);
}
// $order_shortfall = true لتأكيد تسليم أوردر: بنحرّر من المعلق بس الجزء بتاع الأوردر ده، ولو ناقص (عمولة اتعكست قبل كده
// ثم الأوردر اتسلّم تاني) بنكمّل الفرق للمتاح في حدود استحقاق الطلبات المسلّمة فقط.
function commission_release($mk_email, $amount, $order_idx, $reason = 'تأكيد تسليم الأوردر', $order_shortfall = false) {
    $amount = round((float)$amount, 2);
    if ($amount <= 0 || $mk_email === '') return false;
    $mk = find_user($mk_email);
    if (!$mk) return false;
    $avail_pending = (float)($mk['pending_balance'] ?? 0);
    $own = ($order_shortfall && $order_idx !== null && $order_idx !== '') ? wf_order_pending_held($mk_email, $order_idx, $amount) : $amount;
    $move = round(min($amount, $avail_pending, $own), 2);
    $extra = 0.0;
    if ($order_shortfall && $order_idx !== null && $order_idx !== '' && $amount > $move) {
        $extra = round(min($amount - $move, wf_release_headroom($mk_email, $order_idx, $amount, (float)($mk['balance'] ?? 0) + $move)), 2);
    }
    $total = round($move + $extra, 2);
    if ($total <= 0) return false;
    update_user($mk_email, function (&$u) use ($move, $total) {
        $u['pending_balance'] = round(max(0, (float)($u['pending_balance'] ?? 0) - $move), 2);
        $u['balance']         = round((float)($u['balance'] ?? 0) + $total, 2);
    });
    balance_ledger_add($mk_email, 'release', $total, $reason . ($extra > 0 ? ' (منها ' . number_format($extra, 2) . ' استكمال بعد عكس سابق)' : ''), ['order_idx' => $order_idx, 'state' => 'confirmed', 'from_pending' => $move]);
    notify_user($mk_email, '✅ تم تأكيد عمولة ' . number_format($total, 2) . ' ج.م وأصبحت قابلة للسحب', 'dashboard/balance.php');
    return true;
}
// $from_balance_only = true لعكس أوردر كان مسلَّم (عمولته في المتاح): بنخصم من المتاح فقط (لحد ما فيه) ولا نلمس المعلق بتاع أوردرات تانية.
function commission_reverse($mk_email, $amount, $order_idx, $reason = 'إلغاء/إرجاع الأوردر', $from_balance_only = false) {
    $amount = round((float)$amount, 2);
    if ($amount <= 0 || $mk_email === '') return false;
    $mk = find_user($mk_email);
    if (!$mk) return false;
    if ($from_balance_only) {
        $from_pending = 0.0;
        $from_balance = round(min($amount, max(0, (float)($mk['balance'] ?? 0))), 2);
        $logged = $from_balance;
        if ($logged < $amount) $reason .= ' (المخصوم فعلاً ' . number_format($logged, 2) . ' من ' . number_format($amount, 2) . ' — الباقي كان اتسحب)';
        if ($logged <= 0) return true;
    } else {
        // خصم من المعلق أولاً، ثم من المتاح
        $from_pending = min($amount, (float)($mk['pending_balance'] ?? 0));
        $from_balance = round($amount - $from_pending, 2);
        $logged = $amount;
    }
    update_user($mk_email, function (&$u) use ($from_pending, $from_balance) {
        $u['pending_balance'] = round(max(0, (float)($u['pending_balance'] ?? 0) - $from_pending), 2);
        $u['balance']         = round(max(0, (float)($u['balance'] ?? 0) - $from_balance), 2);
    });
    balance_ledger_add($mk_email, 'reversal', -$logged, $reason, ['order_idx' => $order_idx, 'state' => 'reversed', 'from_pending' => round($from_pending, 2)]);
    notify_user($mk_email, '↩️ تم سحب عمولة ' . number_format($logged, 2) . ' ج.م (' . $reason . ')', 'dashboard/balance.php');
    return true;
}
// ===== تسوية لمرة واحدة (بموافقة صاحب الموقع على واتساب 2026-11-25) =====
// الرصيد المتاح المخزّن يرجع لاستحقاق الطلبات المسلّمة بعد خصم السحوبات. كل صف بقيمة متوقعة حالية؛ لو القيمة الحالية
// اختلفت (حد غيّرها) بنتخطى الصف ومنعدّلوش. كل صف بيتنفذ مرة واحدة (علامة recon في السجل) ومعاه سطر adjust ظاهر في السجل.
function wf_recon_targets() {
    return [
        ['email' => 'kokytoto000@gmail.com',      'name' => 'كارولين شاكر',  'expect_balance' => 0.0,  'expect_pending' => null, 'delta' => 520.0],
        ['email' => 'mervatmena145@gmail.com',    'name' => 'كيرمينا كيرلس', 'expect_balance' => 0.0,  'expect_pending' => null, 'delta' => 370.0],
        ['email' => 'sslmy5689@gmail.com',        'name' => 'فاطمة السيد',   'expect_balance' => 0.0,  'expect_pending' => 55.0, 'delta' => 15.0],
        ['email' => 'ahmadassaam44@gmail.com',    'name' => 'Ahmed Essam',   'expect_balance' => 90.0, 'expect_pending' => null, 'delta' => -50.0],
    ];
}
function wf_recon_done($email) {
    foreach ((array)jload('balance_ledger.json', [], false) as $r) {
        if (is_array($r) && ($r['recon'] ?? '') === 'wf-2026-11-25' && strcasecmp((string)($r['email'] ?? ''), (string)$email) === 0) return true;
    }
    return false;
}
// $apply=false: معاينة فقط (بدون أي تعديل). يرجع صف لكل مسوق فيه الحالة: ready | done | mismatch | pending_payout | missing
function wf_recon_run($apply = false) {
    $out = [];
    $payouts = (array)jload('payouts.json', [], false);
    foreach (wf_recon_targets() as $t) {
        $row = $t + ['status' => '', 'balance' => null, 'pending' => null];
        $u = find_user($t['email']);
        if (!$u) { $row['status'] = 'missing'; $out[] = $row; continue; }
        $row['balance'] = round((float)($u['balance'] ?? 0), 2);
        $row['pending'] = round((float)($u['pending_balance'] ?? 0), 2);
        if (wf_recon_done($t['email'])) { $row['status'] = 'done'; $out[] = $row; continue; }
        $open = false;
        foreach ($payouts as $po) {
            if (is_array($po) && strcasecmp((string)($po['email'] ?? $po['user'] ?? ''), $t['email']) === 0 && trim((string)($po['status'] ?? 'قيد المراجعة')) === 'قيد المراجعة') $open = true;
        }
        if ($open) { $row['status'] = 'pending_payout'; $out[] = $row; continue; }
        if (abs($row['balance'] - $t['expect_balance']) > 0.004 || ($t['expect_pending'] !== null && abs($row['pending'] - $t['expect_pending']) > 0.004)) {
            $row['status'] = 'mismatch'; $out[] = $row; continue;
        }
        $row['status'] = 'ready';
        if ($apply) {
            update_user($t['email'], function (&$x) use ($t) { $x['balance'] = round((float)($x['balance'] ?? 0) + $t['delta'], 2); });
            balance_ledger_add($t['email'], 'adjust', $t['delta'], 'تسوية الرصيد لاستحقاق الطلبات المسلّمة بقرار صاحب الموقع (2026-11-25)', ['state' => 'confirmed', 'recon' => 'wf-2026-11-25']);
            $row['status'] = 'applied';
            $u2 = find_user($t['email']); $row['balance'] = round((float)($u2['balance'] ?? 0), 2);
        }
        $out[] = $row;
    }
    return $out;
}
// عمولة الفريق (إحالة) المرتبطة بأوردر: بنقرأ السجل ونجمع لكل مسوق (من غير ما نكرر أي عملية):
//   credited = اللي اتسجل للفريق، undone = اللي اتعكس، released = اللي اتأكد قبل كده
function team_ledger_for_order($order_idx) {
    $idx = (string)(int)$order_idx;
    $rows = [];
    foreach ((array)jload('balance_ledger.json', [], false) as $r) {
        if (!is_array($r) || !isset($r['order_idx']) || (string)(int)$r['order_idx'] !== $idx) continue;
        $type = (string)($r['type'] ?? '');
        $rs   = (string)($r['reason'] ?? '');
        $email = (string)($r['email'] ?? '');
        if ($email === '') continue;
        $key = strtolower($email);
        if (!isset($rows[$key])) $rows[$key] = ['email' => $email, 'credited' => 0.0, 'undone' => 0.0, 'released' => 0.0];
        $amt = (float)($r['amount'] ?? 0);
        if ($type === 'commission' && $rs === 'عمولة فريق (إحالة)') $rows[$key]['credited'] += $amt;
        elseif ($type === 'reversal' && strpos($rs, 'إلغاء عمولة فريق (إحالة)') === 0) $rows[$key]['undone'] += -$amt; // العكس مسجل بالسالب
        elseif ($type === 'release' && strpos($rs, 'تأكيد عمولة فريق (إحالة)') === 0) $rows[$key]['released'] += $amt;
    }
    return $rows;
}
// تأكيد عمولة الفريق عند تسليم الأوردر (زي عمولة المسوق): من المعلق للمتاح. الاستدعاء المكرر ماينقلش مرتين.
function team_commission_release_for_order($order_idx, $reason = 'تأكيد تسليم الأوردر') {
    if ($order_idx === null || $order_idx === '') return 0.0;
    $total = 0.0;
    foreach (team_ledger_for_order($order_idx) as $row) {
        // اللي لسه مش متأكد ولسه مش متعكس
        $rem = round($row['credited'] - $row['undone'] - $row['released'], 2);
        if ($rem <= 0) continue;
        if (commission_release($row['email'], $rem, $order_idx, 'تأكيد عمولة فريق (إحالة) — ' . $reason)) $total += $rem;
    }
    return round($total, 2);
}
// عكس عمولة الفريق المرتبطة بأوردر (إلغاء/إرجاع): بيعكس اللي اتسجل ولسه مش متعكس، سواء كان معلق أو اتأكد.
function team_commission_reverse_for_order($order_idx, $reason = 'إلغاء/إرجاع الأوردر') {
    if ($order_idx === null || $order_idx === '') return 0.0;
    $total = 0.0;
    foreach (team_ledger_for_order($order_idx) as $row) {
        $amt = round($row['credited'] - $row['undone'], 2);
        if ($amt <= 0) continue;
        if (commission_reverse($row['email'], $amt, $order_idx, 'إلغاء عمولة فريق (إحالة) — ' . $reason)) $total += $amt;
    }
    return round($total, 2);
}
$MARGINS = ['safqa' => 0, 'vendoor' => 0, 'timehand' => 0, 'm5zoon' => 0, 'angazny' => 0, 'shopmart' => 0, 'g2g' => 0, 'ledger' => 0, 'social' => 0, 'mnfean' => 0];
// أسماء المنصات للعرض

function social_plat($name) {
    $n = trim((string)$name);
    static $map = [
        'انستجرام' => ['انستجرام', 'instagram'],
        'فيسبوك' => ['فيسبوك', 'فيس بوك', 'facebook'],
        'تيك توك' => ['تيك توك', 'tiktok', 'تيكتوك'],
        'يوتيوب' => ['يوتيوب', 'يوتوب', 'youtube'],
        'تليجرام' => ['تليجرام', 'تيليجرام', 'telegram'],
        'سناب شات' => ['سناب', 'snapchat', 'snap'],
        'كواي' => ['كواي', 'kwai'],
        'كيك' => ['كيك', 'kick'],
        'سبوتيفاي' => ['سبوتيفاي', 'spotify'],
        'ساوند كلاود' => ['ساوند', 'soundcloud', 'sound'],
        'تويتر / إكس' => ['تويتر', 'twitter', 'إكس'],
        'روبلوكس' => ['روبلوكس', 'roblox'],
        'VK' => ['vk', 'فكونتاكتي'],
        'جوجل' => ['جوجل', 'google', 'خرائط'],
    ];
    foreach ($map as $ar => $keys) {
        foreach ($keys as $k) {
            if (mb_stripos($n, $k) !== false) return $ar;
        }
    }
    return 'أخرى';
}
$PLATFORM_NAMES = ['manual' => 'منتجات يدوية', 'g2g' => '🎮 رقمي', 'social' => '📣 سوشيال ميديا', 'm5zoon' => 'M5zoon', 'vendoor' => 'Vendoor', 'safqa' => 'Safqa', 'timehand' => 'Time Hand', 'angazny' => 'Angazny', 'shopmart' => 'Shopmart', 'mnfean' => 'Mnfean'];
function platform_name($plat, $remote = []) {
    global $PLATFORM_NAMES;
    if (isset($remote[$plat]['name'])) return $remote[$plat]['name'];
    return $PLATFORM_NAMES[$plat] ?? $plat;
}
// تقريب سعر البيع لأقرب رقم صحيح ينتهي بـ 5 أو 0 (لأعلى): 57 → 60 و 22 → 25
// الأسعار الصغيرة (أقل من 10 ج.م — مثل أسعار خدمات السوشيال لكل 1000) تبقى كما هي بدون تقريب.
// تقريب أسعار العرض لأقرب «5 أو 0» حسب قيمة السعر:
//   - 10 ج.م وأكتر: لأقرب رقم ينتهي بـ 0 أو 5 (وما ينزلش أقل من السعر المحسوب أبداً)
//   - من 1 لأقل من 10: لأقرب نص جنيه (ينتهي بـ .0 أو .5)
//   - أقل من جنيه: قروش زي ما هو (مافيش تقريب يخسّر)
// الدالة دي هي المرجع لكل أسعار العروض: المنتجات التقليدية + المنتجات الرقمية.
function wf_round_5_or_0($v) {
    $v = (float)$v;
    if ($v <= 0) return 0.0;
    if ($v >= 10) return (float)(ceil(round($v, 4) / 5) * 5);
    if ($v >= 1)  return (float)(ceil(round($v, 4) * 2) / 2);
    return round($v, 2);
}
function wf_round_price($v) {
    return wf_round_5_or_0($v);
}
// زيادة 2% على أسعار المنتجات الفيزيكال بس (vendoor/timehand/safqa/angazny/m5zoon/shopmart/mnfean).
// الرقمي والسوشيال ميديا مش متأثرين. التقريب بيفضل زي تقريب الموقع (لأقرب 5 أو 0).
define('PHYS_PRICE_UPLIFT', 0.02);
// قرار الإدارة الدائم (2026-10-21): تخفيض 12% على كل أسعار البيع الفيزيائية
// (المنصات في wf_is_physical_plat — اليدوي والرقمي g2g/social/smm/hevo خارج القاعدة).
// بيُطبَّق في wf_price_uplift وقت القراءة، فملفات المصدر بتفضل زي ما هي وكل تحديث cron بياخده تلقائي.
if (!defined('PRICE_GLOBAL_DISCOUNT')) define('PRICE_GLOBAL_DISCOUNT', 0.12);
if (!defined('PRICE_RAISE_PCT')) define('PRICE_RAISE_PCT', 0.10);
if (!defined('PRICE_RAISE_FLAT')) define('PRICE_RAISE_FLAT', 5);
function wf_is_physical_plat($plat) {
    return in_array(strtolower(trim((string)$plat)), ['vendoor', 'timehand', 'safqa', 'angazny', 'm5zoon', 'shopmart', 'mnfean'], true);
}
function wf_price_uplift($plat, $v) {
    $v = (float)$v;
    if ($v <= 0 || !wf_is_physical_plat($plat)) return $v;
    $v = round($v * (1 + PHYS_PRICE_UPLIFT), 2);
    // التخفيض الدائم 12% بعد هامش الربح — السعر النهائي ≈ السعر القديم × 0.88 (بتقريب 5/0 المعتاد)
    $v = round($v * (1 - PRICE_GLOBAL_DISCOUNT), 2);
    $v = wf_round_price($v);
    // طلب صاحب الموقع (2026-11-28): +10% على الأسعار الفيزيكال الحالية، تقريب لأقرب 5 أو 0، وبعدها +5 ج.م.
    // المنتجات اللي سعرها متحدد يدويًا (override) بتتسحب قبل الدالة دي ومش بتتأثر.
    return wf_round_price($v * (1 + PRICE_RAISE_PCT)) + PRICE_RAISE_FLAT;
}
function round_half_or_one($v) {
    $v = (float)$v;
    if ($v <= 0) return 0;
    if ($v < 1.0) return round($v, 2);
    return round($v * 2) / 2;
}
// إضافة السوشيال ميديا (10 قروش افتراضيًا): بقت للخدمات الأرخص من الإضافة نفسها بس —
// أي مبلغ أقل من 10 قروش بيتضاف عليه، وأي مبلغ 10 قروش أو أكتر بيفضل سعر المصدر زي ما هو
// من غير أي إضافة على كل طلب. (المبلغ الإضافي قابل للتعديل من الإعدادات)
function social_norm_price($v) {
    $v = max(0, (float)$v);
    if ($v <= 0) return 0;
    static $addon = null;
    if ($addon === null) {
        $addon = function_exists('wf_social_price_addon') ? (float)wf_social_price_addon() : (float)SOCIAL_PRICE_ADDON;
    }
    if ($addon > 0 && $v < $addon) $v += $addon;
    return round_half_or_one($v);
}
function base_price($p, $plat) {
    // الرقمي والسوشيال ميديا: مافيشش «سعر صافي بعد العمولة» — السعر الأساسي = سعر المصدر نفسه
    if (wf_plat_no_commission($plat)) {
        $g = raw_list_price($p, $plat);
        return $g > 0 ? store_price_from_gross($p, $plat, $g) : 0;
    }
    $v = $p['price'] ?? 0;
    if (is_numeric($v) && $v > 0) {
        return $plat === 'social' ? social_norm_price($v) : ($plat === 'g2g' ? round_half_or_one($v) : (float)$v);
    }
    if ($plat === 'm5zoon') {
        if (preg_match('/([\d.,]+)/', (string)($p['extra']['cost'] ?? ''), $m)) return (float)str_replace(',', '', $m[1]);
    }
    if (preg_match('/([\d.,]+)/', (string)($p['price_text'] ?? ''), $m)) {
        $parsed = (float)str_replace(',', '', $m[1]);
        return $plat === 'social' ? social_norm_price($parsed) : ($plat === 'g2g' ? round_half_or_one($parsed) : $parsed);
    }
    return 0;
}
function sale_price($p, $plat) {
    return base_price($p, $plat);
}
define('DATA_DIR', __DIR__ . '/data');

// 🛍️ نظام أفلييت أمازون (Amazon Associates) — نواة منفصلة
if (file_exists(__DIR__ . '/amazon_lib.php')) require_once __DIR__ . '/amazon_lib.php';
// 🟧 نظام أفلييت جوميا (مصر) — نسخة مطابقة لأمازون بتفاصيل جوميا
if (file_exists(__DIR__ . '/jumia_lib.php')) require_once __DIR__ . '/jumia_lib.php';
if (file_exists(__DIR__ . '/noon_lib.php')) require_once __DIR__ . '/noon_lib.php';
require_once __DIR__ . '/customer_req_lib.php';
