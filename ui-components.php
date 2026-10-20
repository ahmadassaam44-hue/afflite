<?php
/** Shared presentation helpers only. No requests, permissions, persistence or pricing. */
function wf_icon($name, $size = 20) {
    $name = preg_replace('/[^a-z0-9-]/', '', (string)$name);
    return '<svg class="wf-ico" width="' . (int)$size . '" height="' . (int)$size . '" aria-hidden="true" focusable="false"><use href="#icon-' . esc($name) . '"></use></svg>';
}
function wf_ui_plain($text) {
    // Strip legacy decorative emoji from system labels, not from saved data.
    return trim(preg_replace('/[\x{1F000}-\x{1FAFF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{21A9}\x{21AA}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]/u', '', (string)$text));
}
function wf_team_rate_display() {
    // TODO(product-owner): resolve REF_TEAM_RATE=0.04 vs REF_TEAM_LABEL='15%'.
    // Do not publish either percentage until approved; monetary calculations are untouched.
    return null;
}
function wf_order_badge($status) {
    $key = status_key($status);
    $tone = in_array($key, ['cancelled', 'returned'], true) ? 'red' : ($key === 'delivered' ? 'green' : (in_array($key, ['confirmed', 'shipped'], true) ? 'blue' : 'yellow'));
    $icon = $tone === 'red' ? 'close' : ($key === 'delivered' ? 'check' : ($key === 'shipped' ? 'truck' : 'clock'));
    return '<span class="badge ' . $tone . '" data-status="' . esc($key) . '">' . wf_icon($icon, 16) . esc(wf_ui_plain(status_label($status))) . '</span>';
}
function wf_order_timeline($status) {
    $key = status_key($status);
    if (in_array($key, ['cancelled', 'returned'], true)) return '<div class="alert danger">' . wf_order_badge($status) . '</div>';
    $steps = array_intersect_key(order_status_list(), array_flip(['pending', 'confirmed', 'shipped', 'delivered']));
    $keys = array_keys($steps);
    $position = array_search($key, $keys, true);
    $out = '<ol class="status-timeline" aria-label="مراحل الطلب">';
    foreach ($steps as $step => $label) {
        $i = array_search($step, $keys, true);
        $done = $position !== false && $i <= $position;
        $out .= '<li class="' . ($done ? 'done' : '') . '"' . ($key === $step ? ' aria-current="step"' : '') . '><span class="timeline-dot">' . ($done ? wf_icon('check', 14) : (string)($i + 1)) . '</span><span>' . esc(wf_ui_plain($label)) . '</span></li>';
    }
    return $out . '</ol>';
}
function wf_ui_sprite() {
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    $paths = [
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'collapse' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M9 3v18m8-13-4 4 4 4"/>',
        'home' => '<path d="m3 10 9-7 9 7v10H3zm6 10v-7h6v7"/>',
        'store' => '<path d="m3 9 2-5h14l2 5M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M5 12v8h14v-8m-9 8v-6h4v6"/>',
        'chart' => '<path d="M4 3v17h17M8 16v-4m5 4V8m5 8V5"/>',
        'box' => '<path d="m12 2 9 5v10l-9 5-9-5V7zm-9 5 9 5 9-5m-9 5v10M7.5 4.5l9 5"/>',
        'orders' => '<path d="M14 2H5v20h14V7zm0 0v5h5M8 12h8m-8 4h8"/>',
        'truck' => '<path d="M2 4h13v13H2zm13 5h4l3 4v4h-7"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>',
        'wallet' => '<rect x="3" y="5" width="19" height="16" rx="2"/><path d="M3 7V4l15-2v3m4 7h-7v5h7m-4-2.5h.01"/>',
        'users' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-3a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v3m1-18a4 4 0 0 1 0 8m3 3a4 4 0 0 1 3 4v3"/>',
        'user' => '<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a7 7 0 0 1 7-7h2a7 7 0 0 1 7 7v3"/>',
        'link' => '<path d="m10 13 4-4m-6 6-1 1a3 3 0 0 1-4-4l4-4a3 3 0 0 1 4 0m2 5a3 3 0 0 0 4 0l4-4a3 3 0 0 0-4-4l-1 1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="m9 3 1-1h4l1 3 3 1 3 2-1 3v3l1 3-3 2-3 1-1 3h-4l-1-3-3-1-3-2 1-3v-3L3 8l3-2 3-1z"/>',
        'calc' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 11h2m4 0h2m-8 4h2m4 0h2m-8 4h8"/>',
        'chat' => '<path d="M21 3H3v14h4l-4 4h6l4-4h8zM7 8h10m-10 4h6"/>',
        'shield' => '<path d="m12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6zm-4 10 3 3 5-6"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 6 3 9 3 9H3s3-3 3-9m4 13h4"/>',
        'search' => '<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
        'camera' => '<path d="M3 6h4l2-3h6l2 3h4v15H3z"/><circle cx="12" cy="13" r="4"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="2"/><path d="m3 17 6-5 4 3 4-6 4 7"/>',
        'logout' => '<path d="M9 3H3v18h6m5-14 5 5-5 5m-6-5h11"/>',
        'cart' => '<path d="M1 2h4l3 14h12l3-10H6"/><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/>',
        'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M4 16H3V3h13v1"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'check' => '<path d="m4 12 5 5L20 6"/>',
        'plus' => '<path d="M12 4v16M4 12h16"/>',
        'minus' => '<path d="M4 12h16"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/>',
        'warning' => '<path d="m12 3 10 18H2zm0 6v5m0 3h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
        'phone' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/>',
        'whatsapp' => '<path d="M21 11.6a8.6 8.6 0 0 1-12.7 7.6L3.4 20.4l1.2-4.8A8.6 8.6 0 1 1 21 11.6z"/><path d="M9.1 8.3c-.6.5-.8 1.5-.3 2.6a8.4 8.4 0 0 0 4.4 4.4c1.1.5 2.1.3 2.6-.3l.3-.4a.7.7 0 0 0-.3-1l-1.5-.6a.7.7 0 0 0-.8.3l-.3.4a6 6 0 0 1-2.5-2.5l.4-.3a.7.7 0 0 0 .3-.8l-.6-1.5a.7.7 0 0 0-1-.3z"/>',
        'bank' => '<path d="m2 8 10-6 10 6H2zm1 13h18M5 11v7m7-7v7m7-7v7"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
        'star' => '<path d="m12 3 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12"/><circle cx="12" cy="12" r="3"/>',
        'trash' => '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'edit' => '<path d="m3 17 1 4 4-1L21 7l-4-4zm11-11 4 4"/>',
        'arrow-right' => '<path d="m9 5 7 7-7 7M3 12h13"/>',
        'arrow-left' => '<path d="m15 5-7 7 7 7M21 12H8"/>',
        'refresh' => '<path d="M20 8a8 8 0 0 0-14-4L3 7m0-5v5h5m-4 9a8 8 0 0 0 14 4l3-3m0 5v-5h-5"/>',
        'file' => '<path d="M14 2H5v20h14V7zm0 0v5h5M8 12h8m-8 4h6"/>',
        'pin' => '<path d="m8 3 9 9-3 3-3-1-4 4-1-5-3-3zm2 11-7 7"/>',
        'game' => '<path d="M7 6h10a4 4 0 0 1 4 4l1 8-2 2-6-5h-4l-6 5-2-2 1-8a4 4 0 0 1 4-4M5 10h6m-3-3v6m8-3h.01M18 12h.01"/>',
        'megaphone' => '<path d="M3 10h5l11-6v16L8 14H3zm5 4 3 8H7l-2-8M22 9v6"/>',
        'map' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 5 10 8L22 5"/>',
        'lock' => '<rect x="4" y="10" width="16" height="12" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4"/>',
        'save' => '<path d="M3 3h15l3 3v15H3zm4 0v6h10V3M7 21v-8h10v8"/>',
        'share' => '<circle cx="18" cy="4" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="20" r="3"/><path d="m9 10 6-4m-6 8 6 4"/>',
        'print' => '<path d="M6 9V2h12v7M6 18H2V9h20v9h-4M6 14h12v8H6zM18 12h.01"/>',
    ];
    echo '<svg class="wf-svg-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">';
    foreach ($paths as $name => $path) echo '<symbol id="icon-' . esc($name) . '" viewBox="0 0 24 24">' . $path . '</symbol>';
    echo '</svg>';
}

/* ==========================================================================
   شرائط موحّدة (Topbar · Header · Bottom nav · Catalog toolbar · Tabs)
   عرض فقط: لا طلبات، ولا صلاحيات، ولا تسعير، ولا حفظ بيانات.
   نفس الشريط بنفس الترتيب ونفس الأزرار في كل الصفحات العامة.
   ========================================================================== */

/* ==========================================================================
   تواصل واتساب على الموقع الأساسي (زر عائم + رابط في الشريط العلوي)
   عرض فقط: الرقم من إعدادات الموقع، ولو فاضي بياخد رقم الموبايل كبديل.
   ========================================================================== */

/** رقم واتساب الدعم (من إعدادات الموقع ← رقم الموبايل كبديل) — أرقام فقط، وفااضي لو مفيش. */
function wf_support_wa_number() {
    $n = '';
    if (function_exists('site_setting')) {
        $n = trim((string)site_setting('whatsapp'));
        if ($n === '') $n = trim((string)site_setting('phone', '01202954952'));
    }
    $n = preg_replace('/\D+/', '', (string)$n);
    return strlen($n) >= 10 ? $n : '';
}

/** رابط wa.me لخدمة عملاء الموقع — فاضي لو مفيش رقم متسجّل. */
function wf_support_wa_href($text = '') {
    $n = wf_support_wa_number();
    if ($n === '') return '';
    if ($text === '') {
        $text = 'السلام عليكم، عندي استفسار' . (defined('BRAND') ? ' عن موقع ' . BRAND : '') . ' — ممكن مساعدة؟';
    }
    if (function_exists('wa_link')) return wa_link($n, $text);
    return 'https://wa.me/' . (strpos($n, '0') === 0 ? '2' . $n : $n) . '?text=' . rawurlencode($text);
}

/** شعار واتساب (معبّأ) للزر العائم — بيحتاج fill أبيض بعيداً عن أيقونات السبرايت. */
function wf_whatsapp_glyph($size = 26) {
    $s = (int)$size;
    return '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" aria-hidden="true" focusable="false" style="fill:#fff;stroke:none;display:block;flex:none">'
         . '<path d="M17.472 14.383c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/>'
         . '</svg>';
}

/** الزر العائم لتواصل واتساب — بيظهر مرة واحدة في الصفحة، ومتشابه مع زر صفحات الهبوط. */
function wf_whatsapp_float_html($args = []) {
    static $shown = false;
    if ($shown) return '';
    $href = (string)($args['href'] ?? '');
    if ($href === '') $href = wf_support_wa_href((string)($args['text'] ?? ''));
    if ($href === '') return '';
    $shown = true;
    $label = trim(wf_ui_plain((string)($args['label'] ?? 'تواصل مع خدمة العملاء')));
    if ($label === '') $label = 'تواصل عبر واتساب';
    return '<a class="wa-float wa-float--site" href="' . esc($href) . '" target="_blank" rel="noopener"'
         . ' aria-label="' . esc($label . ' على واتساب') . '" title="' . esc($label . ' على واتساب') . '">'
         . wf_whatsapp_glyph(26) . '<span class="wa-float-txt">' . esc($label) . '</span></a>';
}

/** روابط الشريط العلوي: [ 'href' => , 'label' => , 'class' => , 'html' => , 'icon' => ] */
function wf_topbar_links_html($links) {
    $out = '';
    foreach ((array)$links as $link) {
        if (empty($link['href'])) continue;
        $label = (string)($link['label'] ?? '');
        $icon  = !empty($link['icon']) ? wf_icon($link['icon'], 16) : '';
        $inner = isset($link['html']) ? (string)$link['html'] : $icon . ($label !== '' ? ' ' . esc($label) : '');
        if (trim(strip_tags($inner)) === '') continue;
        $cls = trim((string)($link['class'] ?? ''));
        $out .= '<a href="' . esc($link['href']) . '"' . ($cls !== '' ? ' class="' . esc($cls) . '"' : '')
             .  (!empty($link['title']) ? ' title="' . esc($link['title']) . '"' : '') . '>' . $inner . '</a>';
    }
    return $out;
}

/** الشريط العلوي الصغير (تواصل + روابط سريعة) — موحّد في كل الصفحات العامة. */
function wf_store_topbar($args = []) {
    $phone = trim((string)($args['phone'] ?? ''));
    $note  = trim((string)($args['note'] ?? 'الدفع عند الاستلام وتوصيل سريع لجميع المحافظات'));
    $html  = '<div class="topbar">';
    $html .= '<div class="topbar-right">';
    if ($phone !== '') {
        $html .= '<a href="tel:' . esc(preg_replace('/[^0-9+]/', '', $phone)) . '" aria-label="اتصل بنا">' . esc($phone) . '</a>';
    }
    // رابط واتساب الدعم — 'wa' => false بيمنعه (صفحات المتاجر ليها تواصلها الخاص)
    $wa = array_key_exists('wa', $args) ? $args['wa'] : false;
    if ($wa !== false && function_exists('wf_support_wa_href')) {
        $wa_href = is_array($wa) ? (string)($wa['href'] ?? '') : '';
        if ($wa_href === '') $wa_href = wf_support_wa_href(is_array($wa) ? (string)($wa['text'] ?? '') : '');
        if ($wa_href !== '') {
            $wa_label = wf_ui_plain((string)(is_array($wa) && !empty($wa['label']) ? $wa['label'] : 'واتساب خدمة العملاء'));
            $html .= '<a href="' . esc($wa_href) . '" target="_blank" rel="noopener" class="topbar-wa" aria-label="' . esc($wa_label) . '" title="' . esc($wa_label) . '">'
                  .  wf_icon('whatsapp', 15) . '<span>' . esc($wa_label) . '</span></a>';
        }
    }
    if ($note !== '') $html .= '<span class="hidden sm:inline">• ' . esc($note) . '</span>';
    $html .= '</div><div class="topbar-left">' . wf_topbar_links_html($args['links'] ?? []) . '</div></div>';
    return $html;
}

/**
 * روابط الشريط العلوي الموحّدة لكل صفحات الموقع الأساسي.
 * الحساب العادي (عميل) ياخد نفس روابط الزائر + «خروج» — بدون أدوات المسوق (مفيش حسابي/إشعارات في الشريط).
 */
function wf_site_topbar_links($args = []) {
    $pre   = (string)($args['prefix'] ?? '');
    $dash  = (string)($args['dash_prefix'] ?? ($pre . 'dashboard/'));
    $user  = array_key_exists('user', $args) ? $args['user'] : (function_exists('current_user') ? current_user() : null);
    $is_mk = function_exists('wf_is_marketer_account') ? wf_is_marketer_account($user) : (bool)$user;
    $code  = trim((string)($args['ref_code'] ?? ($user['ref_code'] ?? '')));
    $links = [];
    if (empty($args['hide_tracking'])) {
        $links[] = ['href' => $dash . 'orders.php', 'label' => 'تتبع طلبك', 'icon' => 'truck', 'class' => 'hidden sm:flex'];
    }
    if ($user) {
        if ($is_mk) {
            $links[] = ['href' => $dash . 'marketer.php', 'label' => 'لوحة المسوق', 'icon' => 'megaphone'];
            if ($code !== '') $links[] = ['href' => $pre . 'store.php?store=' . urlencode($code), 'label' => 'المتجر', 'icon' => 'store'];
        }
        // «حسابي» و«الإشعارات» للمسوق بس — العميل مابيشوفهومش في الشريط العلوي
        if ($is_mk) $links[] = ['href' => $dash . 'notifications.php', 'label' => 'الإشعارات', 'icon' => 'bell'];
        $links[] = ['href' => $pre . 'logout.php', 'label' => 'خروج', 'icon' => 'logout'];
    } else {
        $links[] = ['href' => $pre . 'login.php', 'label' => 'تسجيل الدخول', 'icon' => 'user'];
        $links[] = ['href' => $pre . 'affiliate-register.php', 'label' => 'برنامج المسوقين', 'class' => 'hidden sm:inline'];
        $links[] = ['href' => $pre . 'register.php', 'label' => 'تسجيل مسوق', 'class' => 'badge yellow'];
    }
    return $links;
}

/** الشريط العلوي للموقع الأساسي — بيظهر في كل صفحات الموقع، مش الرئيسية بس. */
function wf_site_topbar($args = []) {
    if (!array_key_exists('wa', $args)) $args['wa'] = true; // الموقع الأساسي: زر واتساب خدمة العملاء في الشريط العلوي
    if (!isset($args['phone'])) {
        $args['phone'] = function_exists('site_setting') ? (site_setting('phone', '01202954952') ?: '01202954952') : '01202954952';
    }
    if (!isset($args['links'])) $args['links'] = wf_site_topbar_links($args);
    return wf_store_topbar($args);
}

/**
 * شريط مزايا حساب العميل على الموقع الأساسي:
 * نفس شكل الموقع للزائر + اختصارات (تتبع الشحنة، التقييم والتعليقات، العروض والأخبار).
 * ما بيظهرش للزائر ولا لصاحب حساب مسوق (عنده لوحة خاصة بيه).
 */
function wf_customer_perks_strip($args = []) {
    $user = $args['user'] ?? null;
    if (!is_array($user) || empty($user['email'])) return '';
    $is_mk = array_key_exists('is_marketer', $args) ? !empty($args['is_marketer']) : (function_exists('wf_is_marketer_account') && wf_is_marketer_account($user));
    if ($is_mk) return '';
    $pre  = (string)($args['prefix'] ?? '');
    $dash = (string)($args['dash_prefix'] ?? ($pre . 'dashboard/'));
    $c    = is_array($args['counts'] ?? null) ? $args['counts'] : [];
    $new_reg = !empty($args['new_reg']);
    $name = trim((string)($user['name'] ?? 'عميلنا'));
    $items = [
        ['href' => $dash . 'orders.php', 'icon' => 'truck', 'label' => 'تتبع الشحنات',
         'note' => isset($c['orders_active']) && $c['orders_active'] > 0 ? ($c['orders_active'] === 1 ? 'شحنة واحدة قيد التوصيل' : $c['orders_active'] . ' شحنات قيد التوصيل') : 'تابع حالة طلبك خطوة بخطوة'],
        ['href' => $dash . 'index.php#myReviews', 'icon' => 'star', 'label' => 'تقييماتي وتعليقاتي',
         'note' => isset($c['reviews']) && $c['reviews'] > 0 ? $c['reviews'] . ' تقييم مسجّل' : 'قيّم المنتجات واكتب رأيك بعد الاستلام'],
        ['href' => $dash . 'notifications.php', 'icon' => 'bell', 'label' => 'أحدث العروض والأخبار',
         'note' => isset($c['unread']) && $c['unread'] > 0 ? $c['unread'] . ' جديد' : 'تنبيهات العروض وتحديثات الأسعار'],
    ];
    $html  = '<div class="section"><div class="card" style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between">';
    $html .= '<div style="min-inline-size:0"><b style="font-size:var(--fs-16)">' . ($new_reg ? 'أهلاً بيك في ' : 'أهلاً ') . esc($name) . ' 👋</b>';
    $html .= '<p class="mut" style="margin:2px 0 0;font-size:var(--fs-12)">'
          .  ($new_reg ? 'حسابك اتفعّل — الموقع قدامك بنفس شكل الزائر، ومعاك التقييم والتعليقات وتتبع الشحنة ووصول أحدث العروض والأخبار.'
                       : 'مميزات حسابك: التقييم والتعليقات • تتبع الشحنة • وصول أحدث العروض والأخبار') . '</p></div>';
    $html .= '<div style="display:flex;flex-wrap:wrap;gap:8px">';
    foreach ($items as $it) {
        $html .= '<a class="btn sm outline" href="' . esc($it['href']) . '" title="' . esc($it['note']) . '">'
              .  wf_icon($it['icon'], 16) . esc($it['label']) . '</a>';
    }
    $html .= '</div></div></div>';
    return $html;
}


/** الهيدر الموحّد: شعار + بحث (بحقل واحد فقط) + كاميرا + كتالوج + حساب + سلة. */
function wf_store_header($args = []) {
    $brand   = (string)($args['brand'] ?? (defined('BRAND') ? BRAND : ''));
    $home    = (string)($args['home'] ?? 'index.php');
    $action  = (string)($args['search_action'] ?? 'index.php');
    $value   = (string)($args['search_value'] ?? '');
    $holder  = (string)($args['search_placeholder'] ?? 'ابحث عن منتج بالاسم أو القسم...');
    $hidden  = is_array($args['hidden'] ?? null) ? $args['hidden'] : [];
    $cart    = (string)($args['cart_href'] ?? 'dashboard/cart.php');
    $cnt     = (int)($args['cart_count'] ?? 0);
    $cart_t  = array_key_exists('cart_total', $args) && $args['cart_total'] !== null ? (float)$args['cart_total'] : null;
    $cur     = (string)($args['cart_currency'] ?? 'ج.م');
    $account = is_array($args['account'] ?? null) ? $args['account'] : null;
    // مفيش «كتالوج» في الموقع الأساسي: الافتراضي بلا رابط، واللي عايزه يربطه صراحةً
    $catalog = array_key_exists('catalog_href', $args) ? (string)$args['catalog_href'] : '';
    $visual  = array_key_exists('visual_search', $args) ? (bool)$args['visual_search'] : true;
    $visual_action = (string)($args['visual_action'] ?? 'api_image_search.php');
    $extra   = (string)($args['extra'] ?? '');
    $logo_svg = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fed701" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';

    $html  = '<header class="header"><div class="header-inner">';
    $html .= '<a href="' . esc($home) . '" class="logo">' . $logo_svg . '<span>' . esc($brand) . '</span></a>';
    $html .= '<div class="search-wrap" id="mainSearchWrap"><div class="search-glow">';
    $html .= '<form method="get" action="' . esc($action) . '" role="search">';
    $html .= '<input name="q" value="' . esc($value) . '" aria-label="ابحث عن منتج" placeholder="' . esc($holder) . '">';
    foreach ($hidden as $hk => $hv) {
        if ((string)$hv === '') continue;
        $html .= '<input type="hidden" name="' . esc($hk) . '" value="' . esc($hv) . '">';
    }
    if ($visual) {
        $html .= '<button type="button" class="cam-btn" data-visual-endpoint="' . esc($visual_action) . '" onclick="window.wfOpenVisualSearch && window.wfOpenVisualSearch()" aria-label="بحث بالصورة" title="ابحث بالصورة — ارفع صورة منتج ونطلعه لك">' . wf_icon('camera', 18) . '</button>';
    }
    $html .= '<button type="submit" aria-label="بحث">' . wf_icon('search', 18) . '<span class="hidden sm:inline">بحث</span></button>';
    $html .= '</form></div></div>';
    $html .= '<div class="header-icons">' . $extra;
    if ($catalog !== '') {
        $html .= '<a href="' . esc($catalog) . '" class="icon-btn hidden sm:flex" aria-label="كتالوج المنتجات" title="الكتالوج">' . wf_icon('box', 20) . '</a>';
    }
    if ($account) {
        $html .= '<a class="icon-btn" href="' . esc((string)($account['href'] ?? 'login.php')) . '" aria-label="' . esc((string)($account['label'] ?? 'حسابي')) . '">'
              .  '<span class="avatar">' . esc(mb_substr((string)($account['initial'] ?? 'ح'), 0, 1)) . '</span>'
              .  '<span class="text-right hidden md:block"><span class="acct-cap">' . esc((string)($account['caption'] ?? 'حسابي')) . '</span><span class="acct-name">' . esc((string)($account['label'] ?? 'حسابي')) . '</span></span></a>';
    }
    $html .= '<a href="' . esc($cart) . '" class="cart-wrap" aria-label="عربة التسوق (' . $cnt . ' منتجات)">'
          .  '<span class="cart-icon-wrap">' . wf_icon('cart', 22) . '<span class="cart-count">' . $cnt . '</span></span>'
          .  '<span class="cart-text"><span class="acct-cap">عربة التسوق</span><span class="acct-name">' . ($cart_t !== null ? wf_ui_money($cart_t) . ' ' . esc($cur) : 'عرض السلة') . '</span></span></a>';
    $html .= '</div></div></header>';
    return $html;
}

/** شريط التنقل السفلي الموحّد للموبايل (يظهر في كل الصفحات العامة). */
/** أقسام الموقع بالتبويب: منتجات (فيزيكال) / رقمي / سوشيال / صفحات — التجميعات للفيزيكال بس، والرقمي والسوشيال كل تبويب فيه أقسامه */
function wf_site_section_tabs($pre = '') {
    static $memo = [];
    if (isset($memo[$pre])) return $memo[$pre];
    $idx = $pre . 'index.php?';
    $colls = [
        ['المنتجات المميزة', 'col=featured'],
        ['أحدث المنتجات', 'col=new'],
        ['الأكثر مبيعاً', 'col=best'],
        ['الأكثر مشاهدة', 'col=viewed'],
    ];
    $build = function ($mode, $all_label, $title, $label, $with_colls = false) use ($idx, $colls) {
        $collections = [[$all_label, $idx . 'mode=' . $mode . '&cat=all#products']];
        if ($with_colls) {
            foreach ($colls as $c) {
                $collections[] = [$c[0], $idx . 'mode=' . $mode . '&cat=all&' . $c[1] . '#products'];
            }
        }
        $cats = [];
        foreach (array_keys((array)categories_by_mode($mode, 'all')) as $cn) {
            $cats[] = [(string)$cn, $idx . 'mode=' . $mode . '&cat=' . urlencode((string)$cn) . '#products'];
        }
        return ['label' => $label, 'title' => $title, 'collections' => $collections, 'cats' => $cats];
    };
    $tabs = [
        'physical' => $build('physical', 'كل المنتجات', 'منتجات فيزيكال', 'منتجات', true),
        'digital'  => $build('digital', 'كل المنتجات الرقمية', 'منتجات رقمية', 'رقمي'),
        'social'   => $build('social', 'كل خدمات السوشيال', 'سوشيال ميديا', 'سوشيال'),
        'pages'    => [
            'label' => 'صفحات',
            'title' => 'صفحات الموقع',
            'links' => [
                ['برنامج التسويق بالعمولة', $pre . 'affiliate-register.php'],
                ['برامج أمازون', $pre . 'affiliate-program.php?p=amazon'],
                ['برامج نون', $pre . 'affiliate-program.php?p=noon'],
                ['برامج جوميا', $pre . 'affiliate-program.php?p=jumia'],
                ['تتبع طلبك', $pre . 'dashboard/orders.php'],
                ['عربة التسوق', $pre . 'dashboard/cart.php'],
                ['الدعم والمساعدة', $pre . 'dashboard/support.php?ctx=customer'],
                ['سياسة الاسترجاع والاستبدال', $pre . 'pages.php?p=returns'],
                ['الشروط والأحكام', $pre . 'pages.php?p=terms'],
                ['سياسة الخصوصية', $pre . 'pages.php?p=privacy'],
            ],
        ],
    ];
    return $memo[$pre] = $tabs;
}

function wf_bottom_nav($active = 'home', $args = []) {
    $pre   = (string)($args['prefix'] ?? '');
    $shop_href = (string)($args['shop_href'] ?? '');
    // صفحات المتجر/الهبوط بتحدد بيتها بنفسها وبتحجب الأدوات اللي تخص مسار الشراء الأساسي
    $home_href = (string)($args['home_href'] ?? 'index.php');
    $hide  = array_filter(array_map('strval', (array)($args['hide'] ?? [])));
    $cnt   = (int)($args['cart_count'] ?? 0);
    $user  = !empty($args['logged_in']);
    $visual = array_key_exists('visual_search', $args) ? (bool)$args['visual_search'] : true;
    $items = [
        'home'    => [$home_href !== '' ? $home_href : 'index.php', 'home', 'الرئيسية'],
        'shop'    => ['sections.php', 'box', 'الأقسام'],
        'cart'    => ['dashboard/cart.php', 'cart', 'السلة' . ($cnt ? ' (' . $cnt . ')' : '')],
        'support' => ['dashboard/support.php', 'chat', 'الدعم'],
    ];
    foreach ($hide as $hide_key) unset($items[$hide_key]);
    $account_href = (string)($args['account_href'] ?? 'dashboard/');
    if ($user) $items['account'] = [$account_href, 'user', 'حسابي'];
    $html = '<nav class="mob-bottom-nav" aria-label="التنقل السفلي للموبايل">';
    foreach ($items as $key => $item) {
        $on = ($key === $active);
        $html .= '<a href="' . esc($pre . $item[0]) . '"' . ($on ? ' class="is-active" aria-current="page"' : '') . '>' . wf_icon($item[1], 18) . '<span>' . esc($item[2]) . '</span></a>';
        if ($key === 'shop' && $visual) {
            $html .= '<button type="button" onclick="window.wfOpenVisualSearch && window.wfOpenVisualSearch()" aria-label="بحث بالصورة">' . wf_icon('camera', 18) . '<span>بحث بالصورة</span></button>';
        }
    }
    return $html . '</nav>';
}

/** شريط أدوات الكتالوج: عنوان + عدّاد + ترتيب + مسح الفلاتر (نفس الشكل في كل الصفحات). */
function wf_catalog_toolbar($args = []) {
    $title  = (string)($args['title'] ?? '');
    $meta   = (string)($args['meta'] ?? '');
    $sort_id = (string)($args['sort_id'] ?? 'catalogSortSelect');
    $options = is_array($args['sort_options'] ?? null) ? $args['sort_options'] : [];
    $current = (string)($args['sort_current'] ?? '');
    $clear   = (string)($args['clear_url'] ?? '');
    $hidden  = is_array($args['hidden'] ?? null) ? $args['hidden'] : [];
    $form_action = (string)($args['form_action'] ?? '');

    $html = '<div class="catalog-toolbar">';
    $html .= '<div class="catalog-toolbar-meta">' . ($title !== '' ? '<h2>' . esc($title) . '</h2>' : '');
    if ($meta !== '') $html .= '<span class="catalog-toolbar-count">' . $meta . '</span>';
    $html .= '</div>';
    $html .= '<div class="catalog-toolbar-actions">';
    if ($clear !== '') $html .= '<a href="' . esc($clear) . '" class="btn sm outline">' . wf_icon('refresh', 16) . 'مسح الفلاتر</a>';
    if ($options) {
        $html .= '<label for="' . esc($sort_id) . '" class="field-label">ترتيب حسب:</label>';
        if ($form_action !== '') {
            $html .= '<form method="get" action="' . esc($form_action) . '" class="catalog-sort-form">';
            foreach ($hidden as $hk => $hv) $html .= '<input type="hidden" name="' . esc($hk) . '" value="' . esc($hv) . '">';
            $html .= '<select id="' . esc($sort_id) . '" name="' . esc((string)($args['sort_name'] ?? 'sort')) . '" aria-label="ترتيب المنتجات" onchange="this.form.submit()">';
            foreach ($options as $ok => $olabel) $html .= '<option value="' . esc($ok) . '"' . ((string)$current === (string)$ok ? ' selected' : '') . '>' . esc($olabel) . '</option>';
            $html .= '</select><noscript><button class="btn sm">تطبيق</button></noscript></form>';
        } else {
            // روابط مباشرة: المفتاح = قيمة الترتيب، والقيمة = ['label' => , 'url' => ]
            $html .= '<select id="' . esc($sort_id) . '" aria-label="ترتيب المنتجات" onchange="if(this.value)location.href=this.value">';
            foreach ($options as $ok => $opt) {
                $url = is_array($opt) ? (string)($opt['url'] ?? '') : (string)$opt;
                $lbl = is_array($opt) ? (string)($opt['label'] ?? $ok) : (string)$opt;
                $html .= '<option value="' . esc($url) . '"' . ((string)$current === (string)$ok ? ' selected' : '') . '>' . esc($lbl) . '</option>';
            }
            $html .= '</select>';
        }
    }
    $html .= '</div></div>';
    return $html;
}

/** شريط التبويبات الموحّد: [[ 'href' => , 'label' => , 'icon' => , 'on' => bool ]] */
function wf_tabs($items, $args = []) {
    $label = (string)($args['label'] ?? 'أقسام الصفحة');
    $class = trim('tabs ' . (string)($args['class'] ?? ''));
    $html = '<nav class="' . esc($class) . '" aria-label="' . esc($label) . '">';
    foreach ((array)$items as $item) {
        if (empty($item['href']) || !isset($item['label'])) continue;
        $on = !empty($item['on']);
        $html .= '<a href="' . esc($item['href']) . '"' . ($on ? ' class="on" aria-current="page"' : '')
              .  (!empty($item['title']) ? ' title="' . esc($item['title']) . '"' : '') . '>'
              .  (!empty($item['icon']) ? wf_icon($item['icon'], 16) : '') . esc($item['label']) . '</a>';
    }
    return $html . '</nav>';
}

/** شريط التنقل الأفقي الموحّد (Navbar) مع دعم شريحة تمهيدية (مثل مبدّل نوع المنتجات). */
function wf_store_navbar($items, $args = []) {
    $label = (string)($args['label'] ?? 'التنقل الرئيسي');
    $lead  = (string)($args['lead'] ?? '');
    $html  = '<nav class="navbar" aria-label="' . esc($label) . '"><div class="navbar-inner">' . $lead;
    foreach ((array)$items as $item) {
        if (!isset($item['label'])) continue;
        if (empty($item['href']) && empty($item['onclick'])) continue;
        $on = !empty($item['on']);
        $cls = 'nav-link' . ($on ? ' on' : '') . (!empty($item['class']) ? ' ' . $item['class'] : '');
        $style = !empty($item['color']) ? ' style="color:' . esc($item['color']) . ';font-weight:' . esc((string)($item['weight'] ?? '800')) . '"' : '';
        $inner = (!empty($item['icon']) ? wf_icon($item['icon'], 16) : '') . esc($item['label']);
        if (!empty($item['onclick'])) {
            $html .= '<button type="button" class="' . esc($cls) . '"' . $style . ' onclick="' . esc($item['onclick']) . '"'
                  .  (!empty($item['title']) ? ' title="' . esc($item['title']) . '"' : '') . '>' . $inner . '</button>';
            continue;
        }
        $html .= '<a class="' . esc($cls) . '" href="' . esc($item['href']) . '"' . $style
              .  ($on ? ' aria-current="page"' : '') . (!empty($item['title']) ? ' title="' . esc($item['title']) . '"' : '') . '>'
              .  $inner . '</a>';
    }
    return $html . '</div></nav>';
}

/** عناصر النافبار الموحّد لكل صفحات المتجر: بحث بالصورة + المميزة + الأكثر مبيعاً + وصل حديثاً + الدعم. */
function wf_storefront_nav_items($args = []) {
    $pre     = (string)($args['prefix'] ?? '');
    $active  = (string)($args['active'] ?? '');
    $support = (string)($args['support_href'] ?? ($pre . 'dashboard/support.php?ctx=customer'));
    $s_label = (string)($args['support_label'] ?? 'دعم العملاء');
    $featured = (string)($args['featured_href'] ?? ($pre . 'index.php?mode=physical&col=featured&cat=all&page=1#products'));
    $best     = (string)($args['best_href'] ?? ($pre . 'index.php?mode=physical&col=best&cat=all&page=1#products'));
    $new      = (string)($args['new_href'] ?? ($pre . 'index.php?mode=physical&col=new&cat=all&page=1#products'));
    return [
        ['label' => 'بحث بالصورة', 'onclick' => 'window.wfOpenVisualSearch && window.wfOpenVisualSearch()', 'icon' => 'camera'],
        ['href' => $featured, 'label' => 'المميزة', 'on' => ($active === 'featured')],
        ['href' => $best, 'label' => 'الأكثر مبيعاً', 'on' => ($active === 'best')],
        ['href' => $new, 'label' => 'وصل حديثاً', 'on' => ($active === 'new')],
        ['href' => $support, 'label' => $s_label, 'icon' => 'chat', 'class' => 'ml-auto'],
    ];
}

/** زر «جميع الأقسام» التمهيدي المستخدم قبل عناصر النافبار الموحّد. */
function wf_storefront_nav_lead($args = []) {
    $pre = (string)($args['prefix'] ?? '');
    $href = trim((string)($args['catalog_href'] ?? ''));
    if ($href === '') $href = $pre . 'index.php#products';
    return '<a class="cat-btn" href="' . esc($href) . '">' . wf_icon('menu', 16) . 'جميع الأقسام</a>';
}

/** عدّاد السلة من الجلسة (عرض فقط). */
function wf_cart_summary() {
    $count = 0; $total = 0.0;
    foreach (($_SESSION['cart'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $qty = max(1, (int)($item['qty'] ?? 1));
        $count += $qty;
        $total += $qty * (float)($item['price'] ?? 0);
    }
    return ['count' => $count, 'total' => $total];
}

/** Currency labels only: do not convert money or modify persisted cart/order records. */
function wf_ui_currency($row) {
    if (!empty($row['currency'])) return wf_ui_currency_label((string)$row['currency']);
    static $currency_cache = [];
    $key = (string)($row['plat'] ?? $row['_plat'] ?? '') . '#' . (string)($row['id'] ?? '');
    if (!isset($currency_cache[$key])) {
        $product = !empty($row['id']) && function_exists('find_product_by_id') ? find_product_by_id((string)$row['id'], (string)($row['plat'] ?? $row['_plat'] ?? '')) : null;
        // اشتقاق العملة من مصدر واحد في config.php (عملة مخزّنة ⇒ عملة الدولة ⇒ مصدر m5zoon):
        // مصر=ج.م، السعودية=ر.س، الإمارات=د.إ، الأردن=د.أ، عُمان=ر.ع — وده نفس الأساس اللي
        // بيقرّر بيه السيرفر هل خصم الكمية (بالجنيه) ينطبق على المنتج ولا لأ.
        $currency_cache[$key] = function_exists('wf_product_currency')
            ? wf_product_currency($product ?: $row, (string)($product['_plat'] ?? $row['plat'] ?? $row['_plat'] ?? ''))
            : ($product['currency'] ?? (function_exists('wf_country_currency') ? wf_country_currency($product['country'] ?? 'مصر') : 'ج.م'));
    }
    return wf_ui_currency_label($currency_cache[$key]);
}
function wf_ui_currencies($rows) {
    $currencies = [];
    foreach ($rows as $row) $currencies[wf_ui_currency($row)] = true;
    return array_keys($currencies);
}
function wf_ui_currency_label($currency) {
    $aliases = ['EGP'=>'ج.م', 'جنيه'=>'ج.م', 'جنيه مصري'=>'ج.م', 'درهم'=>'د.إ', 'AED'=>'د.إ', 'SAR'=>'ر.س', 'ريال سعودي'=>'ر.س', 'JOD'=>'د.أ', 'OMR'=>'ر.ع'];
    return $aliases[trim((string)$currency)] ?? trim((string)$currency);
}
function wf_ui_money($amount) {
    $value = (float)$amount;
    // Formatting only; do not round/reprice persisted values or commission calculations.
    return number_format($value, abs($value - round($value)) > 0.00001 ? 2 : 0);
}

// Source media only: no uploads, Drive API, generated folders or persistence.
function wf_product_drive_links($product) {
    $extra = is_array($product['extra'] ?? null) ? $product['extra'] : [];
    $sources = [$product['files'] ?? [], $extra['media_links'] ?? [], $extra['media_url'] ?? '', $extra['catalog'] ?? '', $product['desc'] ?? '', $extra['marketing_desc'] ?? '', $extra['description_html'] ?? ''];
    $links = [];
    $collect = static function ($value) use (&$collect, &$links) {
        if (is_array($value)) { foreach ($value as $item) $collect($item); return; }
        if (!is_string($value)) return;
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match_all('~https?://[^\s<>"\']+~iu', $value, $matches)) return;
        foreach ($matches[0] as $url) {
            $url = rtrim($url, '.,;)]}');
            $parts = parse_url($url);
            if (!$parts || !in_array(strtolower($parts['host'] ?? ''), ['drive.google.com', 'docs.google.com'], true) || isset($parts['user']) || isset($parts['pass'])) continue;
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            $links[$url] = true;
        }
    };
    foreach ($sources as $source) $collect($source);
    return array_keys($links);
}
function wf_product_media_plat($product, $plat = '') {
    $plat = trim((string)$plat);
    if ($plat !== '') return $plat; // المصدر الأكيد: البلاتفورم اللي الصفحة عارفاها
    if (!is_array($product)) return '';
    $plat = (string)($product['_plat'] ?? ($product['platform'] ?? ($product['plat'] ?? '')));
    if ($plat === '' && !empty($product['id']) && function_exists('resolve_plat')) {
        $plat = (string)resolve_plat($product['id']);
    }
    return $plat;
}
/** هل بلوك الصور والفيديوهات ممنوع في المنتج ده؟ (رقمي فقط — السوشيال ميديا صورها تظهر) */
function wf_product_media_hidden($product, $plat = '') {
    if (!function_exists('wf_is_digital_plat')) return false;
    $wf_plat = wf_product_media_plat($product, $plat);
    // خدمات السوشيال ميديا ليها صور/فيديوهات توضيحية للخدمة → تُعرض في التفاصيل.
    if ($wf_plat === 'social' || strpos($wf_plat, 'smm') === 0 || strpos($wf_plat, 'hevo') === 0) return false;
    // المنتجات الرقمية (كود/حساب/g2g) ملف تسليم مش صور منتج → تفضل مستترة.
    return wf_is_digital_plat($wf_plat);
}
/** المسوق والأدمن بس بيشوفوا صور وفيديوهات المنتج وجدول المتغيرات؛ الزائر والعميل العادي لأ */
function wf_product_extras_visible() {
    static $visible = null;
    if ($visible === null) $visible = wf_current_is_marketer() || is_admin_user();
    return $visible;
}
/** لينك «صور وفيديوهات المنتج» المختصر — بيرجع فاضي للمنتجات الرقمية ولغير المسوق/الأدمن */
function wf_product_media_link($product, $href, $plat = '') {
    if (wf_product_media_hidden($product, $plat) || !wf_product_extras_visible()) return '';
    return '<a class="product-media-link" href="' . esc($href . '#productMedia') . '">' . wf_icon('image', 16) . 'صور وفيديوهات المنتج</a>';
}
function wf_product_media_html($product, $in_root = false, $plat = '') {
    // المنتجات الرقمية: مفيش بلوك صور وفيديوهات (لا عنوان ولا تلميح ولا ملفات)
    if (wf_product_media_hidden($product, $plat)) return '';
    // الزائر والعميل العادي ماينفعش يشوفوا صور/فيديوهات المنتج
    if (!wf_product_extras_visible()) return '';
    // خدمات السوشيال ميديا: صور توضيحية من المُورّد → تُعرض كصور فعلية، من غير بلوك «ملفات Drive» ولا تنبيهات المصدر
    $wf_plat = wf_product_media_plat($product, $plat);
    if ($wf_plat === 'social' || strpos($wf_plat, 'smm') === 0 || strpos($wf_plat, 'hevo') === 0) {
        $wf_imgs = array_slice(clean_product_images(is_array($product) ? ($product['images'] ?? []) : []), 0, 6);
        $wf_imgs = array_values(array_filter($wf_imgs, static function ($u) { return is_string($u) && trim($u) !== ''; }));
        if (!$wf_imgs) return '';
        $wf_nm = (string)(is_array($product) ? ($product['name'] ?? '') : '');
        $wf_alt = mb_substr(function_exists('aff_clean') ? aff_clean($wf_nm) : trim($wf_nm), 0, 80);
        $html = '<section class="product-media" data-product-media><h3>' . wf_icon('image', 18) . 'صور الخدمة</h3><div class="gal-thumbs">';
        foreach ($wf_imgs as $wi => $wu) {
            $wu = trim((string)$wu);
            $html .= '<a href="' . esc($wu) . '" target="_blank" rel="noopener noreferrer" aria-label="تكبير صورة الخدمة ' . ($wi + 1) . '"><img src="' . esc($wu) . '" alt="' . esc($wf_alt !== '' ? $wf_alt : 'صورة الخدمة') . '" loading="lazy" onerror="this.onerror=null;this.closest(\'a\').remove()"></a>';
        }
        return $html . '</div></section>';
    }
    $drive = wf_product_drive_links($product);
    $images = clean_product_images($product['images'] ?? []);
    $videos = is_array($product['videos'] ?? null) ? $product['videos'] : [];
    $fix = static function ($url) use ($in_root) {
        if (!is_string($url)) return '';
        $url = trim($url);
        if (preg_match('~^https?://~i', $url)) return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
        if ($url === '' || preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $url)) return '';
        $url = ltrim(str_replace('../', '', asset_url($url)), '/');
        return $in_root ? $url : '../' . $url;
    };
    $html = '<section class="product-media" data-product-media><h3>' . wf_icon('image', 18) . 'صور وفيديوهات المنتج</h3>';
    if ($drive) {
        $html .= '<div class="product-drive-actions">';
        foreach ($drive as $i=>$link) $html .= '<a class="btn outline" data-drive-link href="' . esc($link) . '" target="_blank" rel="noopener noreferrer">' . wf_icon('link', 18) . ($i ? 'Google Drive — ملفات إضافية' : 'Google Drive — ملفات المنتج') . '</a>';
        $html .= '</div>';
    } else {
        $html .= '<p class="field-hint">المصدر لم يوفر رابط Google Drive لهذا المنتج؛ الملفات المتاحة موضّحة أدناه.</p>';
    }
    $files = '';
    foreach (array_slice($images, 0, 24) as $i=>$image) {
        $url = $fix($image);
        if ($url !== '') $files .= '<li><a href="' . esc($url) . '" target="_blank" rel="noopener noreferrer">' . wf_icon('image', 16) . 'فتح صورة المنتج ' . ($i+1) . '</a></li>';
    }
    foreach (array_slice($videos, 0, 12) as $i=>$video) {
        $url = $fix($video);
        if ($url !== '') $files .= '<li><a href="' . esc($url) . '" target="_blank" rel="noopener noreferrer">' . wf_icon('video', 16) . 'فتح فيديو المنتج ' . ($i+1) . '</a></li>';
    }
    $html .= $files ? '<details class="product-media-files"><summary>عرض ملفات الصور والفيديوهات</summary><ul>' . $files . '</ul></details>' : '<p class="field-hint">لا توجد صور أو فيديوهات مباشرة إضافية من المصدر.</p>';
    return $html . '</section>';
}
