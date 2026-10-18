<?php require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ui-components.php';
if (empty($PUBLIC_PAGE)) require_login();
$user = current_user();
if ($user && !empty($user['email'])) {
    if (($user['role'] ?? '') !== 'admin') {
        $fresh_user = ensure_user_affiliate($user['email']);
    } else {
        $fresh_user = find_user($user['email']);
    }
    if ($fresh_user) {
        // الدور من مصدر واحد (المستخدم القديم بلا دور = مسوّق) حتى يتطابق شكل الشرائط مع أدوات اللوحة
        $user['role'] = function_exists('wf_user_role') ? wf_user_role($fresh_user) : ($fresh_user['role'] ?? 'marketer');
        $user['ref_code'] = $fresh_user['ref_code'] ?? '';
    }
}
$st = stats();

function wf_svg_icon($id, $size = 18) {
    return wf_icon($id, $size);
}

function layout_top($title, $active = 'home') {
    global $user, $NO_SIDEBAR, $mtab, $tab;
    $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $in_dash = (strpos($script_name, '/dashboard/') !== false);
    $pre = $in_dash ? '../' : '';
    $dash_pre = $in_dash ? '' : 'dashboard/';
    $script_base = basename($script_name);
    // نوع الزائر: زائر / مشتري / مسوق / مدير — يحدد شكل الشريط العلوي والسفلي
    $layout_role = !$user ? 'guest' : (($user['role'] ?? '') === 'admin' ? 'admin' : (($user['role'] ?? '') === 'marketer' ? 'marketer' : 'customer'));
    // صفحات واجهة المتجر: نفس التول بار (topbar + header + navbar + شريط سفلي) المستخدم في الرئيسية — ظهر في كل صفحات الموقع مش الرئيسية بس
    $storefront_pages = ['products.php', 'product.php', 'quick_order.php', 'cart.php', 'checkout_cart.php', 'orders.php', 'support.php', 'notifications.php', 'pay.php', 'calculator.php', 'pages.php', 'product_requests.php', 'shipping.php', 'index.php', 'balance.php'];
    // صفحات حساب المسوق/الإدارة تفضل بشكل اللوحة (لوحة المسوق، الرصيد والسحب، الكتالوج الداخلي، الحاسبة)
    $panel_pages = ['index.php', 'balance.php', 'products.php', 'calculator.php'];
    $storefront_view = in_array($script_base, $storefront_pages, true)
        && ($layout_role === 'guest' || $layout_role === 'customer'
            || ($layout_role === 'marketer' && !in_array($script_base, $panel_pages, true)));
    // صفحات المتجر العامة داخل مجلد اللوحة (تفاصيل المنتج / الطلب السريع / السلة / التتبع / حساب العميل): نفس شرائط المتجر
    $public_chrome = $storefront_view || !empty($GLOBALS['WF_PUBLIC_CHROME']);
    $hide_side = $public_chrome || !empty($NO_SIDEBAR) || !$user || in_array($script_base, ['product.php', 'cart.php', 'checkout_cart.php'], true);
    // سياق مسوق (رابط فيه ref/store): العميل بيتعامل مع المسوق صاحب الرابط — مفيش روابط المتجر الرئيسي/تتبع الطلب/برنامج المسوقين
    $mk_ctx_ref = trim((string)($_GET['ref'] ?? ($_GET['store'] ?? '')));
    if ($mk_ctx_ref === '' && !empty($_SESSION['aff_ref'])) $mk_ctx_ref = trim((string)$_SESSION['aff_ref']);
    $mk_ctx = ($mk_ctx_ref !== '');
    $GLOBALS['WF_MK_CTX'] = $mk_ctx; // الفوتر بيتنادى من دالة تانية (layout_end) فبنمرر السياق من خلال الجلوبال

    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="var(--electro-yellow)">';
    echo '<title>' . esc(wf_ui_plain($title)) . ' | ' . esc(BRAND) . '</title>';
    echo tracking_pixels_html();
    $og = $GLOBALS['OG'] ?? [];
    $og_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    $og_url = preg_replace('/([?&])ref=[^&]*/', '$1', $og_url); $og_url = rtrim(str_replace('?&', '?', $og_url), '?&');
    $og_t = $og['title'] ?? ($title . ' | ' . BRAND);
    $og_d = $og['desc'] ?? ('تسوق ' . $title . ' بأفضل سعر مع ' . BRAND . ' — الدفع عند الاستلام وتوصيل لكل مصر');
    echo '<meta name="description" content="' . esc(mb_substr($og_d, 0, 160)) . '">';
    // Canonicalize public product URLs without referral/tracking parameters.
    if (!empty($GLOBALS['WF_PRODUCT_DETAIL']) && !empty($_GET['id'])) {
        $canonical = 'https://wafirinhalk.com/dashboard/product.php?id=' . rawurlencode((string)$_GET['id']);
        echo '<link rel="canonical" href="' . esc($canonical) . '">';
    }
    // Keep account, checkout, and utility screens out of search results.
    $noindex_pages = ['cart.php', 'checkout_cart.php', 'quick_order.php', 'orders.php', 'pay.php', 'notifications.php', 'balance.php', 'support.php', 'login.php', 'register.php'];
    if (empty($GLOBALS['WF_PRODUCT_DETAIL']) && in_array($script_base, $noindex_pages, true)) {
        echo '<meta name="robots" content="noindex,follow">';
    }
    if (!empty($GLOBALS['SEO_PRODUCT']) && is_array($GLOBALS['SEO_PRODUCT'])) {
        echo '<script type="application/ld+json">' . json_encode($GLOBALS['SEO_PRODUCT'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    }
    echo '<meta property="og:type" content="' . (isset($og['image']) ? 'product' : 'website') . '"><meta property="og:site_name" content="' . BRAND . '">';
    echo '<meta property="og:title" content="' . esc($og_t) . '"><meta property="og:description" content="' . esc(mb_substr($og_d, 0, 200)) . '"><meta property="og:url" content="' . esc($og_url) . '">';
    if (!empty($og['image'])) echo '<meta property="og:image" content="' . esc($og['image']) . '"><meta name="twitter:card" content="summary_large_image"><meta property="twitter:image" content="' . esc($og['image']) . '">';
    else echo '<meta name="twitter:card" content="summary">';
    if (!empty($og['price'])) echo '<meta property="product:price:amount" content="' . esc($og['price']) . '"><meta property="product:price:currency" content="EGP">';
    echo '<link rel="manifest" href="' . $pre . 'manifest.json">';
    echo '<link rel="preload" href="' . $pre . 'assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="' . $pre . 'assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>';
    echo '<link rel="stylesheet" href="' . $pre . 'assets/style.css?v=20261013-row-fix"></head><body class="' . ($public_chrome ? 'has-mob-nav' : 'is-dash') . (!empty($GLOBALS['WF_PRODUCT_DETAIL']) ? ' is-product' : '') . '">';

    echo '<a href="#dashMainContent" class="skip-link">تخطي إلى المحتوى الرئيسي</a>';
    wf_ui_sprite();

    $notifs = $user ? array_reverse(my_notifications($user['email'])) : [];
    $unread = 0;
    foreach ($notifs as $nn) if (empty($nn['read'])) $unread++;
    $cart_cnt = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? count($_SESSION['cart']) : 0;

    $GLOBALS['_LAYOUT_HIDE_SIDE'] = $hide_side;
    $GLOBALS['_LAYOUT_PUBLIC_CHROME'] = $public_chrome;
    $GLOBALS['_LAYOUT_ACTIVE'] = $active;
    $GLOBALS['_LAYOUT_ROLE'] = ($user['role'] ?? '') === 'admin' ? 'admin' : (($user['role'] ?? '') === 'marketer' ? 'marketer' : 'customer');
    $role = $GLOBALS['_LAYOUT_ROLE'];
    echo '<div class="dash' . ($hide_side ? ' dash-public' : '') . '" data-role="' . esc($role) . '">';

    $code = $user['ref_code'] ?? '';
    $my_orders_cnt = 0;
    if ($code !== '') {
        foreach (jload('orders.json', []) as $_o) {
            if (($_o['ref'] ?? '') === $code) $my_orders_cnt++;
        }
    }
    $cur_mtab = $active === 'marketer' ? ($mtab ?? 'performance') : 'performance';
    $cur_admin_tab = $active === 'admin' ? ($tab ?? 'overview') : 'overview';
    /* TODO: Confirm REF_TEAM_RATE (0.04) vs REF_TEAM_LABEL ('15%') in config.php before showing any percentage in sidebar */
    $mtabs = [
        'performance' => ['chart', 'إحصائياتي والأداء'],
        'pages'       => ['link', 'صفحات الهبوط'],
        'browse'      => ['box', 'كتالوج المنتجات والعمولات'],
        'orders'      => ['orders', 'طلباتي ومبيعاتي (' . $my_orders_cnt . ')'],
        'earnings'    => ['wallet', 'المحفظة وسحب الأرباح'],
        'team'        => ['users', 'فريقي والإحالات'],
        'links'       => ['link', 'الروابط والنقرات'],
        'settings'    => ['settings', 'بياناتي وإعداداتي'],
    ];
    $m_extra = [
        'calculator' => ['calc', 'حاسبة الأرباح'],
        'support'    => ['chat', 'الدعم المباشر'],
    ];
    if (function_exists('is_admin_user') && is_admin_user()) {
        $m_extra['admin_shipping'] = ['truck', 'إعدادات الشحن'];
        $m_extra['admin']          = ['shield', 'لوحة الإدارة'];
    }

    if (!$hide_side) {
        echo '<div id="dashSideMask" class="side-mask" data-drawer-close></div>';
        echo '<aside class="side" id="dashSide" aria-label="القائمة الجانبية" tabindex="-1">';
        echo '<div class="side-brand"><a href="' . $pre . 'index.php">' . wf_svg_icon('store', 24) . '<span class="logo-txt">' . esc(BRAND) . '</span></a><button type="button" class="side-close-btn" data-drawer-close aria-label="إغلاق القائمة">' . wf_svg_icon('close') . '</button></div>';
        echo '<nav aria-label="وجهات لوحة التحكم">';
        if ($role === 'admin') {
            echo '<p class="side-sec-title">الإدارة والمتابعة</p>';
            foreach (['overview'=>['chart','نظرة عامة'], 'orders'=>['orders','الطلبات'], 'products'=>['box','المنتجات'], 'users'=>['users','المستخدمون'], 'marketers'=>['megaphone','المسوّقون'], 'commissions'=>['wallet','العمولات والسحب'], 'payments'=>['bank','مراجعة المدفوعات'], 'requests'=>['camera','طلبات توفير المنتجات'], 'amazon'=>['cart','أفلييت أمازون'], 'jumia'=>['store','أفلييت جوميا'], 'noon'=>['megaphone','أفلييت نون'], 'chats'=>['chat','المحادثات'], 'notifications'=>['bell','الإشعارات'], 'fraud'=>['shield','تنبيهات المراجعة'], 'audit'=>['file','سجل الإجراءات'], 'settings'=>['settings','الإعدادات']] as $k => $pair) {
                $is_on = $active === 'admin' && $cur_admin_tab === $k;
                echo '<a href="' . $dash_pre . 'admin.php?tab=' . esc($k) . '" title="' . esc($pair[1]) . '"' . ($is_on ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon($pair[0]) . '<span class="t">' . esc($pair[1]) . '</span></a>';
            }
        } elseif ($role === 'marketer') {
            // Store tools follow the marketing/store section, before account tools.
            echo '<p class="side-sec-title">التسويق والمتجر</p>';
            foreach ($mtabs as $k => $pair) {
                if (in_array($k, ['pages', 'browse'], true)) continue; // بيتعرضوا في «أدوات المتجر»
                $is_on = $active === 'marketer' && $cur_mtab === $k;
                echo '<a href="' . $dash_pre . 'marketer.php?mtab=' . esc($k) . '" title="' . esc($pair[1]) . '"' . ($is_on ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon($pair[0]) . '<span class="t">' . esc($pair[1]) . '</span></a>';
            }
            echo '<p class="side-sec-title">أدوات المتجر</p>';
            $store_on = $active === 'marketer' && $cur_mtab === 'store';
            echo '<a href="' . $dash_pre . 'marketer.php?mtab=store" title="إدارة متجري"' . ($store_on ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon('settings') . '<span class="t">إدارة متجري</span></a>';
            foreach (['pages', 'browse'] as $k) {
                $pair = $mtabs[$k];
                $is_on = $active === 'marketer' && $cur_mtab === $k;
                echo '<a href="' . $dash_pre . 'marketer.php?mtab=' . esc($k) . '" title="' . esc($pair[1]) . '"' . ($is_on ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon($pair[0]) . '<span class="t">' . esc($pair[1]) . '</span></a>';
            }
            if (function_exists('amz_stats')) {
                echo '<a href="' . $dash_pre . 'amazon.php" title="أفلييت أمازون"' . ($active === 'amazon' ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon('cart') . '<span class="t">أفلييت أمازون</span></a>';
            }
            if (function_exists('jmi_stats')) {
                echo '<a href="' . $dash_pre . 'jumia.php" title="أفلييت جوميا"' . ($active === 'jumia' ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon('cart') . '<span class="t">أفلييت جوميا</span></a>';
            }
            if (function_exists('noon_stats_for')) {
                echo '<a href="' . $dash_pre . 'noon.php" title="أفلييت نون"' . ($active === 'noon' ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon('megaphone') . '<span class="t">أفلييت نون</span></a>';
            }
            echo '<a href="' . $dash_pre . 'product_requests.php?ctx=marketer" title="طلب توفير منتج"' . ($active === 'product_requests' ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon('camera') . '<span class="t">طلب توفير منتج</span></a>';
        } else {
            echo '<p class="side-sec-title">حسابي ومشترياتي</p>';
            foreach (['home'=>['home','نظرة عامة','./'], 'orders'=>['orders','طلباتي','orders.php'], 'cart'=>['cart','سلة التسوق','cart.php'], 'notifications'=>['bell','العروض والأخبار','notifications.php'], 'shipping'=>['truck','الشحن','shipping.php']] as $k => $pair) {
                $is_on = ($active === $k);
                echo '<a href="' . $dash_pre . $pair[2] . '" title="' . esc($pair[1]) . '"' . ($is_on ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon($pair[0]) . '<span class="t">' . esc($pair[1]) . '</span></a>';
            }
            // العميل يقدر يحوّل حسابه لمسوق من هنا
            echo '<a href="' . $pre . 'marketer_complete.php" title="كن مسوقاً بالعمولة"' . ($active === 'become_marketer' ? ' class="on" aria-current="page"' : '') . '>' . wf_svg_icon('megaphone') . '<span class="t">كن مسوقاً بالعمولة</span></a>';
        }
        echo '<p class="side-sec-title">الحساب والأدوات</p>';
        foreach ($m_extra as $k => $pair) {
            if ($k === 'admin') continue; // already grouped above
            $is_on = ($k === $active);
            $href = $k === 'home' ? ($dash_pre ?: './') : ($dash_pre . $k . '.php');
            // فصل الدعم: دعم المسوقين داخل سياق لوحة المسوقين، ودعم العملاء افتراضيًا
            if ($k === 'support' && ($GLOBALS['_LAYOUT_ROLE'] ?? '') === 'marketer') $href .= '?ctx=marketer';
            echo '<a href="' . esc($href) . '"' . ($is_on ? ' class="on" aria-current="page"' : '') . ' title="' . esc($pair[1]) . '">' . wf_svg_icon($pair[0]) . '<span class="t">' . esc($pair[1]) . '</span></a>';
        }
        echo '<a href="' . $pre . 'logout.php" class="side-logout" title="تسجيل الخروج">' . wf_svg_icon('logout') . '<span class="t">تسجيل الخروج</span></a></nav></aside>';
    }

    if ($public_chrome) {
        // ===== نفس الشريط العلوي والهيدر والنافبار المستخدمين في كل صفحات المتجر =====
        $pcart = wf_cart_summary();
        $pc_home = $pre . 'index.php';
        $pc_ref = $mk_ctx_ref;
        $pc_mk = $mk_ctx;
        // مفيش «كتالوج» في صفحات الموقع الأساسي: أيقونة الكتالوج وزر جميع الأقسام اتشالوا
        $pc_catalog = '';
        $pc_support = $dash_pre . 'support.php?ctx=' . (($layout_role === 'marketer') ? 'marketer' : 'customer') . ($pc_mk ? '&store=' . urlencode($pc_ref) : '');
        $pc_links = [];
        if (!$pc_mk) $pc_links[] = ['href' => $dash_pre . 'orders.php', 'label' => 'تتبع طلبك', 'icon' => 'truck'];
        if ($user) {
            if ($role === 'marketer') {
                $pc_links[] = ['href' => $dash_pre . 'marketer.php', 'label' => 'لوحة المسوق', 'icon' => 'megaphone'];
                if (!empty($user['ref_code'])) $pc_links[] = ['href' => $pre . 'store.php?store=' . urlencode((string)$user['ref_code']), 'label' => 'متجري', 'icon' => 'store'];
            }
            // «حسابي» و«الإشعارات» للمسوق بس — العميل مابيشوفهومش في الشريط العلوي
            if ($role === 'marketer') $pc_links[] = ['href' => $dash_pre . 'notifications.php', 'label' => 'الإشعارات', 'icon' => 'bell'];
            $pc_links[] = ['href' => $pre . 'logout.php', 'label' => 'خروج', 'icon' => 'logout'];
        } else {
            if (!$pc_mk) $pc_links[] = ['href' => $pc_home, 'label' => 'المتجر الرئيسي', 'icon' => 'store'];
            $pc_links[] = ['href' => $pre . 'login.php', 'label' => 'تسجيل الدخول', 'icon' => 'user'];
            if (!$pc_mk) $pc_links[] = ['href' => $pre . 'affiliate-register.php', 'label' => 'برنامج المسوقين', 'class' => 'hidden sm:inline'];
        }
        echo '<div class="dash-stage">';
        // الشريط العلوي (الهاتف / واتساب / روابط) مش بيظهر للعميل المسجّل في صفحات لوحة الحساب
        if ($layout_role !== 'customer') echo wf_store_topbar(['phone' => (function_exists('site_setting') ? (site_setting('phone', '01202954952') ?: '01202954952') : '01202954952'), 'wa' => !$pc_mk, 'links' => $pc_links]);
        echo wf_store_header([
            'brand' => BRAND,
            'home' => $pc_home,
            'search_action' => $pre . 'index.php',
            'visual_action' => $pre . 'api_image_search.php',
            'cart_href' => $dash_pre . 'cart.php',
            'cart_count' => $pcart['count'],
            'cart_total' => $pcart['total'],
            'catalog_href' => $pc_catalog,
            'account' => $user ? [
                'href' => $dash_pre . ($role === 'admin' ? 'admin.php' : ($role === 'marketer' ? 'marketer.php' : 'index.php')),
                'initial' => mb_substr((string)$user['name'], 0, 1),
                'caption' => $role === 'admin' ? 'لوحة الإدارة' : ($role === 'marketer' ? 'لوحة المسوق' : 'حسابي'),
                'label' => mb_substr((string)$user['name'], 0, 15),
            ] : null,
        ]);
        echo wf_store_navbar(wf_storefront_nav_items([
            'prefix' => $pre,
            'support_href' => $pc_support,
            'support_label' => $layout_role === 'marketer' ? 'شات المسوقين' : 'دعم العملاء',
        ]), ['label' => 'تنقل المتجر']);
        echo '<main class="main" id="dashMainContent" tabindex="-1">';
        if (empty($GLOBALS['WF_PRODUCT_DETAIL'])) {
            echo '<div class="page-head"><div><nav class="dash-breadcrumb" aria-label="مسار الصفحة"><a href="' . $pc_home . '">المتجر</a><span aria-hidden="true">/</span><span>' . esc(wf_ui_plain($title)) . '</span></nav><h1>' . esc(wf_ui_plain($title)) . '</h1></div></div>';
        }
        return;
    }
    echo '<div class="dash-stage"><header class="topbar-dash">';
    echo '<div class="dash-top-start">';
    if (!$hide_side) {
        echo '<button type="button" id="dashDrawerBtn" class="icon-button side-toggle-btn" data-drawer-open aria-label="فتح القائمة الجانبية" aria-controls="dashSide" aria-expanded="false">' . wf_svg_icon('menu') . '</button>';
        echo '<button type="button" id="dashCollapseBtn" class="icon-button side-collapse-btn" aria-label="طي القائمة الجانبية" aria-controls="dashSide" aria-expanded="true">' . wf_svg_icon('collapse') . '</button>';
    }
    echo '<a href="' . $pre . 'index.php" class="dash-wordmark">' . esc(BRAND) . '</a><span class="dash-top-title">' . esc(wf_ui_plain($title)) . '</span></div>';
    echo '<div class="dash-top-actions">';
    echo '<form method="get" action="' . $dash_pre . 'products.php" class="dash-quick-search"><label class="sr-only" for="dashQuickSearch">بحث سريع في الكتالوج</label><input type="search" id="dashQuickSearch" name="q" placeholder="بحث في الكتالوج"><button type="submit" class="icon-button" aria-label="بحث">' . wf_svg_icon('search') . '</button></form>';
    echo '<button type="button" data-visual-search data-visual-endpoint="' . esc($pre . 'api_image_search.php') . '" onclick="window.wfOpenVisualSearch && window.wfOpenVisualSearch()" class="icon-button" aria-label="بحث بالصورة" title="ابحث بالصورة">' . wf_svg_icon('camera') . '</button>';
    if ($user) {
        echo '<div class="notif-wrap"><button type="button" id="notifToggleBtn" class="icon-button" aria-label="الإشعارات: ' . (int)$unread . ' غير مقروء" aria-controls="notifDropdown" aria-expanded="false">' . wf_svg_icon('bell');
        if ($unread) echo '<span class="notification-count">' . (int)$unread . '</span>';
        echo '</button><section id="notifDropdown" class="notif-drop" aria-label="قائمة الإشعارات"><div class="dropdown-head"><b>الإشعارات</b><a href="' . $dash_pre . 'notifications.php?read=1">تعليم الكل كمقروء</a></div><div class="dropdown-items">';
        if (!$notifs) echo '<p class="empty-note">لا توجد إشعارات جديدة.</p>';
        foreach (array_slice($notifs, 0, 8) as $nf) {
            $nhref = !empty($nf['link']) ? ($pre . ltrim($nf['link'], '/')) : ($dash_pre . 'notifications.php');
            echo '<a href="' . esc($nhref) . '" class="notif-item ' . (empty($nf['read']) ? 'unread' : '') . '"><span class="badge ' . (empty($nf['read']) ? 'blue' : 'neutral') . '">' . (empty($nf['read']) ? 'غير مقروء' : 'مقروء') . '</span><p>' . esc(wf_ui_plain($nf['text'] ?? '')) . '</p><time dir="ltr">' . esc(substr($nf['at'] ?? '', 0, 16)) . '</time></a>';
        }
        echo '</div><a class="dropdown-foot" href="' . $dash_pre . 'notifications.php">كل الإشعارات</a></section></div>';
        echo '<details class="user-dropdown"><summary aria-label="قائمة حساب ' . esc($user['name'] ?? '') . '"><span class="user-avatar">' . esc(mb_substr($user['name'] ?? 'م', 0, 1)) . '</span><span class="user-label">' . esc($user['name'] ?? 'حسابي') . '</span></summary><div class="user-menu"><b>' . esc($user['name'] ?? '') . '</b><span dir="ltr" class="mut">' . esc($user['email'] ?? '') . '</span><a href="' . $dash_pre . ($role === 'admin' ? 'admin.php?tab=settings' : 'marketer.php?mtab=settings') . '">' . wf_svg_icon('user') . 'بيانات الحساب</a><a href="' . $pre . 'logout.php">' . wf_svg_icon('logout') . 'تسجيل الخروج</a></div></details>';
    } else {
        echo '<a class="icon-button" aria-label="سلة التسوق، ' . (int)$cart_cnt . ' منتجات" href="' . $dash_pre . 'cart.php">' . wf_svg_icon('cart') . '</a>';
    }
    echo '</div></header><main class="main" id="dashMainContent" tabindex="-1">';
    if (empty($GLOBALS['WF_PRODUCT_DETAIL'])) echo '<div class="page-head"><div><nav class="dash-breadcrumb" aria-label="مسار الصفحة"><a href="' . $pre . 'index.php">المتجر</a><span aria-hidden="true">/</span><span>' . esc(wf_ui_plain($title)) . '</span></nav><h1>' . esc(wf_ui_plain($title)) . '</h1></div><div class="page-actions"><a class="btn outline" href="' . $pre . 'index.php">' . wf_svg_icon('arrow-right') . 'العودة للمتجر</a></div></div>';

}

function layout_end() {
    global $user;
    if (function_exists('render_easyorder_dialog_html')) render_easyorder_dialog_html(); // خانة «سعر الاتفاق مع العميل» اتشالت من كل الصفحات
    $ref = function_exists('active_ref') ? active_ref() : '';
    $pid = isset($_GET['id']) ? (string)$_GET['id'] : '';
    $meta = json_encode(['ref_code' => $ref, 'product_id' => $pid], JSON_UNESCAPED_UNICODE);
    echo '<div hidden data-track-meta="' . esc($meta) . '"></div>';
    $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $in_dash = (strpos($script_name, '/dashboard/') !== false);
    $pre = $in_dash ? '../' : '';
    $dash_pre = $in_dash ? '' : 'dashboard/';

    echo '<footer class="dash-footer"><nav aria-label="روابط قانونية"><a href="' . $pre . 'pages.php?p=privacy">الخصوصية</a><a href="' . $pre . 'pages.php?p=terms">الشروط</a><a href="' . $pre . 'pages.php?p=returns">الاسترجاع</a>' . (!empty($GLOBALS['WF_MK_CTX']) ? '' : '<a href="' . $dash_pre . 'orders.php">تتبع طلبك</a>') . '</nav><span>© ' . date('Y') . ' ' . esc(BRAND) . '</span></footer>';

    if (!empty($GLOBALS['_LAYOUT_PUBLIC_CHROME'])) {
        // نفس شريط التنقل السفلي المستخدم في صفحات المتجر
        $pcart_end = wf_cart_summary();
        echo wf_bottom_nav('shop', [
            'cart_count' => $pcart_end['count'],
            'logged_in' => (bool)$user,
            'prefix' => $pre,
            'account_href' => $dash_pre . (($GLOBALS['_LAYOUT_ROLE'] ?? '') === 'admin' ? 'admin.php' : ''),
        ]);
        // زر واتساب خدمة العملاء على صفحات الموقع الأساسي (من غير سياق رابط المسوق)
        // صفحات الدفع والشراء والدردشة مستثناة: الزر العائم ممكن يغطي زر «تأكيد الطلب» أو صندوق الرسالة
        $wa_float_skip_pages = ['cart.php', 'checkout_cart.php', 'pay.php', 'quick_order.php', 'support.php', 'product_requests.php', 'balance.php'];
        if (empty($GLOBALS['WF_MK_CTX']) && !in_array(basename($script_name), $wa_float_skip_pages, true) && function_exists('wf_whatsapp_float_html')) {
            $wa_txt = '';
            if (!empty($GLOBALS['WF_PRODUCT_DETAIL']) && !empty($GLOBALS['OG']['title'])) {
                $wa_prod = preg_replace('/\s*—.*$/us', '', (string)$GLOBALS['OG']['title']); // شيل السعر اللي واط اسم المنتج
                $wa_prod = trim((string)preg_replace('/\s*\|.*$/us', '', $wa_prod));
                if ($wa_prod !== '') $wa_txt = 'السلام عليكم، عندي استفسار عن المنتج: ' . mb_substr($wa_prod, 0, 90);
            }
            echo wf_whatsapp_float_html(['text' => $wa_txt]);
        }
    } elseif (basename($script_name) !== 'product.php') {
        $customer_flow = !$user || in_array(basename($script_name), ['orders.php', 'cart.php', 'checkout_cart.php', 'pay.php'], true);
        $is_adm = !$customer_flow && ($GLOBALS['_LAYOUT_ROLE'] ?? '') === 'admin';
        echo '<nav class="dash-bottom-nav" aria-label="التنقل السفلي للوحة التحكم">';
        if ($is_adm) {
            echo '<a href="' . $dash_pre . 'admin.php?tab=overview">' . wf_svg_icon('chart', 18) . '<span>نظرة عامة</span></a>';
            echo '<a href="' . $dash_pre . 'admin.php?tab=orders">' . wf_svg_icon('orders', 18) . '<span>الطلبات</span></a>';
            echo '<a href="' . $dash_pre . 'admin.php?tab=products">' . wf_svg_icon('box', 18) . '<span>المنتجات</span></a>';
            echo '<a href="' . $dash_pre . 'admin.php?tab=commissions">' . wf_svg_icon('wallet', 18) . '<span>العمولات</span></a>';
            echo '<a href="' . $dash_pre . 'admin.php?tab=settings">' . wf_svg_icon('settings', 18) . '<span>الإعدادات</span></a>';
        } elseif (!$customer_flow && ($GLOBALS['_LAYOUT_ROLE'] ?? '') === 'marketer') {
            echo '<a href="' . $dash_pre . 'marketer.php?mtab=performance">' . wf_svg_icon('chart', 18) . '<span>الأداء</span></a>';
            echo '<a href="' . $dash_pre . 'marketer.php?mtab=browse">' . wf_svg_icon('box', 18) . '<span>الكتالوج</span></a>';
            echo '<a href="' . $dash_pre . 'marketer.php?mtab=store">' . wf_svg_icon('store', 18) . '<span>متجري</span></a>';
            echo '<a href="' . $dash_pre . 'marketer.php?mtab=orders">' . wf_svg_icon('orders', 18) . '<span>طلباتي</span></a>';
            echo '<a href="' . $dash_pre . 'marketer.php?mtab=earnings">' . wf_svg_icon('wallet', 18) . '<span>المحفظة</span></a>';
        }
        if ($customer_flow || (!$is_adm && ($GLOBALS['_LAYOUT_ROLE'] ?? '') !== 'marketer')) {
            foreach (['orders.php'=>['orders','طلباتي'], 'cart.php'=>['cart','السلة'], 'support.php'=>['chat','الدعم']] as $url => $item) {
                // دعم العملاء من شريط الجوال يفتح سياق العملاء صراحةً
                $href = $dash_pre . $url . ($url === 'support.php' ? '?ctx=customer' : '');
                echo '<a href="' . $href . '">' . wf_svg_icon($item[0]) . '<span>' . esc($item[1]) . '</span></a>';
            }
        }
        echo '</nav>';
    }

    echo '<div hidden id="wfSessionBootstrap" data-csrf="' . esc(csrf_token()) . '"></div>';
    echo '<script src="' . $pre . 'assets/ui.js?v=20261018-visfeat" defer></script>';
    echo '<script src="' . $pre . 'assets/tracker.js?v=20261018-visfeat" defer></script>';
    echo '</main></div></div></body></html>';
}

function kpi_row($st) {
    // Inventory/media totals are intentionally not displayed. Keep the existing UI hook.
}

function pcard($p, $plat = '') {
    $plat = $p['_plat'] ?? resolve_plat($p['id'] ?? '', $plat);
    $img = product_first_image($p, $plat);
    $base_store = list_price($p, $plat) ?: display_price($p, $plat);
    $cu_w = current_user();
    static $my_aff_code = null;
    if ($my_aff_code === null) {
        if ($cu_w && !empty($cu_w['email'])) {
            $me_a = ensure_user_affiliate($cu_w['email']);
            $my_aff_code = $me_a['ref_code'] ?? '';
        } else {
            $my_aff_code = '';
        }
    }
    $ref_cur = active_ref() ?: $my_aff_code;
    $extra_c = $ref_cur ? marketer_store_product_comm($ref_cur, $plat, (string)($p['id'] ?? '')) : 0.0;
    $price = round($base_store + $extra_c, 2);
    $platform_labels = ['g2g' => 'منتج رقمي', 'social' => 'خدمة سوشيال'];
    $plat_label = $platform_labels[$plat] ?? '';
    $link = 'product.php?id=' . urlencode((string)$p['id']) . ($ref_cur ? '&ref=' . urlencode($ref_cur) : '');
    $cname = aff_clean($p['name'] ?? '', true);
    static $my_w = null;
    if ($my_w === null) {
        $w_all = jload('wishlist.json', []);
        $wk_u = !empty($cu_w['email']) ? $cu_w['email'] : ('guest_' . (function_exists('visitor_id') ? visitor_id() : session_id()));
        $my_w = is_array($w_all[$wk_u] ?? null) ? $w_all[$wk_u] : [];
    }
    $is_loved = in_array($plat . '#' . $p['id'], $my_w, true);
    echo '<article class="pcard">';
    if ($plat_label !== '') {
        echo '<div class="pcard-badges"><span class="pcard-badge">' . esc($plat_label) . '</span></div>';
    }
    echo '<a role="button" data-wishlist href="product.php?id=' . urlencode((string)$p['id']) . '&wish=1" aria-label="تبديل حفظ المنتج في المفضلة" aria-pressed="' . ($is_loved ? 'true' : 'false') . '" class="pcard-wish ' . ($is_loved ? 'is-loved' : '') . '">' . wf_svg_icon('heart') . '</a>';
    echo '<div class="pcard-img">';
    echo $img ? '<a href="' . esc($link) . '"><img loading="lazy" decoding="async" width="260" height="260" alt="' . esc($cname) . '" referrerpolicy="no-referrer" src="' . esc(asset_url($img)) . '" onerror="this.onerror=null;this.src=\'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22200%22><rect width=%22200%22 height=%22200%22 fill=%22%23f1f5f9%22/><text x=%22100%22 y=%22105%22 text-anchor=%22middle%22 fill=%22%2394a3b8%22 font-size=%2214%22>صورة</text></svg>\'"></a>' : '';
    echo '</div>';
    echo '<div class="pcard-cat">' . esc($p['category'] ?? 'منتجات') . '</div>';
    echo '<h3 class="pcard-title"><a href="' . esc($link) . '">' . esc(mb_substr($cname, 0, 70)) . '</a></h3>';
    echo wf_product_media_link($p, $link, $plat);
    $cols_p = clean_product_colors($p, $plat);
    $sizes_p = clean_product_sizes($p, $plat);
    if ($cols_p || $sizes_p) {
        echo '<div data-ui="u-ae554c64 dh dj d4g d3k">';
        if ($cols_p) echo '<span class="badge yellow" data-ui="u-91ea6cf8 do d6v">الألوان: ' . esc(implode(' • ', array_slice($cols_p, 0, 2))) . (count($cols_p) > 2 ? ' +' . (count($cols_p) - 2) : '') . '</span>';
        if ($sizes_p) echo '<span class="badge blue" data-ui="u-91ea6cf8 do d6v">المقاسات: ' . esc(implode('/', array_slice($sizes_p, 0, 3))) . '</span>';
        echo '</div>';
    }
    if ($cu_w && !wf_is_digital_plat($plat)) {
        // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة — الكارت مايعرضش «عمولتك»
        $u_comm = round((float)$extra_c, 2);
        $lp_href = '../landing.php?id=' . urlencode((string)$p['id']) . ($ref_cur ? '&ref=' . urlencode($ref_cur) : '') . ($u_comm > 0 ? '&comm=' . $u_comm : '');
        echo '<div data-ui="u-1f8c4581 d6w d6x d15 d6y d1u do dc d6z dh d1o d1p"><span>عمولتك: ' . ($u_comm > 0 ? '+' . wf_ui_money($u_comm) . ' ج.م' : '0 ج.م (أضف عمولتك)') . '</span><a href="' . esc($lp_href) . '" target="_blank" data-ui="u-afc29f7f d1d d70 do">صفحة الهبوط</a></div>';
    }
    echo '<div class="pcard-footer"><div class="pcard-price">' . number_format((float)$price) . ' <small>' . esc(wf_ui_currency($p)) . '</small></div><a href="' . esc($link) . '" class="pcard-cart" aria-label="عرض التفاصيل والطلب">اطلب الآن</a></div>';
    echo '</article>';
}
