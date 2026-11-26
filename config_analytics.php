<?php
// ============================================================================
// config_analytics.php — جزء من config.php (السطور 6006–6680 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// =========================================================================
// نظام الإحصائيات وتتبع الزوار والسلة واللاڤ (Real-Time Analytics Engine)
// =========================================================================
function visitor_id() {
    if (!empty($_SESSION['wf_vid'])) return $_SESSION['wf_vid'];
    if (!empty($_COOKIE['wf_vid']) && preg_match('/^[a-zA-Z0-9_\-]{8,40}$/', (string)$_COOKIE['wf_vid'])) {
        $_SESSION['wf_vid'] = (string)$_COOKIE['wf_vid'];
        return $_SESSION['wf_vid'];
    }
    $vid = 'v_' . substr(md5(uniqid((string)mt_rand(), true) . ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
    $_SESSION['wf_vid'] = $vid;
    if (!headers_sent()) {
        @setcookie('wf_vid', $vid, time() + 365 * 86400, '/', '', false, true);
    }
    return $vid;
}

function wf_client_ip() {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    return substr($ip, 0, 45);
}

// مفتاح الزائر اليومي حسب الـ IP: نفس الشخص (نفس الشبكة) لو دخل 100 مرة في اليوم
// يتحسب مرة واحدة، وبيتحسب من جديد تاني يوم. لو الـ IP مش متاح بنرجع لمعرّف الزائر.
function wf_daily_visitor_key() {
    $ip = function_exists('wf_client_ip') ? wf_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '') return 'ip_' . $ip;
    return function_exists('visitor_id') ? visitor_id() : '';
}

function visitor_meta() {
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) $dev = 'تابلت 📲';
    elseif (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) $dev = 'موبايل 📱';
    else $dev = 'كمبيوتر 💻';

    if (strpos($ua, 'fbav') !== false || strpos($ua, 'fban') !== false) $br = 'Facebook App';
    elseif (strpos($ua, 'instagram') !== false) $br = 'Instagram App';
    elseif (strpos($ua, 'edg') !== false) $br = 'Edge';
    elseif (strpos($ua, 'chrome') !== false) $br = 'Chrome';
    elseif (strpos($ua, 'safari') !== false) $br = 'Safari';
    elseif (strpos($ua, 'firefox') !== false) $br = 'Firefox';
    else $br = 'متصفح آخر';

    $ref_h = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!empty($_GET['ref']) || !empty($_SESSION['aff_ref'])) $src = 'رابط مسوق 📣';
    elseif ($ref_h === '' || ($host !== '' && strpos($ref_h, $host) !== false)) $src = 'دخول مباشر 🌐';
    elseif (strpos($ref_h, 'facebook') !== false || strpos($ref_h, 'fb.com') !== false) $src = 'فيسبوك 📘';
    elseif (strpos($ref_h, 'instagram') !== false) $src = 'انستجرام 📸';
    elseif (strpos($ref_h, 'tiktok') !== false) $src = 'تيك توك 🎵';
    elseif (strpos($ref_h, 'google') !== false) $src = 'بحث جوجل 🔍';
    elseif (strpos($ref_h, 't.me') !== false || strpos($ref_h, 'telegram') !== false) $src = 'تيليجرام ✈️';
    elseif (strpos($ref_h, 'wa.me') !== false || strpos($ref_h, 'whatsapp') !== false) $src = 'واتساب 💬';
    else $src = 'رابط خارجي 🔗';

    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $page_map = [
        'index.php' => 'الرئيسية 🏠',
        'product.php' => 'صفحة منتج 📦',
        'products.php' => 'تصفح المنتجات 🛍️',
        'quick_order.php' => 'طلب رقمي/سوشيال ⚡',
        'cart.php' => 'سلة التسوق 🛒',
        'checkout_cart.php' => 'إتمام الشراء 💳',
        'orders.php' => 'الطلبات 🧾',
        'marketer.php' => 'لوحة المسوق 📣',
        'support.php' => 'شات الدعم 💬',
        'product_requests.php' => 'طلب منتج بالصورة 📸',
        'balance.php' => 'الرصيد 💰',
        'shipping.php' => 'الشحن 🚚',
        'login.php' => 'تسجيل الدخول 🔐',
        'register.php' => 'تسجيل مسوق ✨',
    ];
    $page_lbl = $page_map[$script] ?? $script;
    return ['device' => $dev, 'browser' => $br, 'source' => $src, 'page' => $page_lbl, 'script' => $script];
}

function track_site_visit() {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['admin.php', 'admin_shipping.php'], true)) return;
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua !== '' && preg_match('/bot|crawl|spider|slurp|curl|python|wget/i', $ua)) return;

    $vid = visitor_id();
    $now = time();
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    // منع تكرار الكتابة في نفس الثانية لنفس الرابط
    if (($_SESSION['_last_pv_uri'] ?? '') === $uri && ($now - (int)($_SESSION['_last_pv_ts'] ?? 0)) < 5) {
        return;
    }
    $_SESSION['_last_pv_uri'] = $uri;
    $_SESSION['_last_pv_ts'] = $now;

    // حدث موحّد (يغذّي الإحصائيات والـ Funnel)
    if (function_exists('wf_event')) {
        wf_event('page_view', ['page' => mb_substr((string)($_SERVER['SCRIPT_NAME'] ?? ''), 0, 80)]);
    }

    $meta = visitor_meta();
    $cu = current_user();
    $today = date('Y-m-d');

    $an = jload('analytics.json', []);
    if (!is_array($an)) $an = [];
    if (!isset($an['total_pageviews'])) $an['total_pageviews'] = 0;
    if (!isset($an['visitors']) || !is_array($an['visitors'])) $an['visitors'] = [];
    if (!isset($an['daily']) || !is_array($an['daily'])) $an['daily'] = [];
    if (!isset($an['pages']) || !is_array($an['pages'])) $an['pages'] = [];
    if (!isset($an['sources']) || !is_array($an['sources'])) $an['sources'] = [];
    if (!isset($an['devices']) || !is_array($an['devices'])) $an['devices'] = [];

    $an['total_pageviews'] = (int)$an['total_pageviews'] + 1;
    $an['pages'][$meta['page']] = (int)($an['pages'][$meta['page']] ?? 0) + 1;

    $is_new_visitor = !isset($an['visitors'][$vid]);
    if ($is_new_visitor) {
        $an['sources'][$meta['source']] = (int)($an['sources'][$meta['source']] ?? 0) + 1;
        $an['devices'][$meta['device']] = (int)($an['devices'][$meta['device']] ?? 0) + 1;
        $an['visitors'][$vid] = [
            'first' => date('c', $now),
            'last' => date('c', $now),
            'last_ts' => $now,
            'hits' => 1,
            'user' => $cu['email'] ?? '',
            'name' => $cu['name'] ?? '',
            'device' => $meta['device'],
            'browser' => $meta['browser'],
            'source' => $meta['source'],
            'last_page' => $meta['page'],
            'last_uri' => mb_substr($uri, 0, 120),
            'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 15),
        ];
    } else {
        $an['visitors'][$vid]['last'] = date('c', $now);
        $an['visitors'][$vid]['last_ts'] = $now;
        $an['visitors'][$vid]['hits'] = (int)($an['visitors'][$vid]['hits'] ?? 1) + 1;
        $an['visitors'][$vid]['last_page'] = $meta['page'];
        $an['visitors'][$vid]['last_uri'] = mb_substr($uri, 0, 120);
        if (!empty($cu['email'])) $an['visitors'][$vid]['user'] = $cu['email'];
        if (!empty($cu['name'])) $an['visitors'][$vid]['name'] = $cu['name'];
    }

    // حفظ آخر 3000 زائر بحد أقصى للحفاظ على الأداء
    if (count($an['visitors']) > 3000) {
        $an['visitors'] = array_slice($an['visitors'], -3000, null, true);
    }

    if (!isset($an['daily'][$today])) {
        $an['daily'][$today] = ['views' => 0, 'uv' => [], 'carts' => 0, 'loves' => 0, 'orders' => 0];
    }
    $an['daily'][$today]['views'] = (int)($an['daily'][$today]['views'] ?? 0) + 1;
    if (!isset($an['daily'][$today]['uv']) || !is_array($an['daily'][$today]['uv'])) $an['daily'][$today]['uv'] = [];
    $an['daily'][$today]['uv'][wf_daily_visitor_key()] = 1;
    if (count($an['daily']) > 60) {
        $an['daily'] = array_slice($an['daily'], -60, null, true);
    }

    jsave('analytics.json', $an);
}

function track_cart_add($plat, $id, $pname, $qty = 1, $price = 0, $variant = '', $img = '') {
    $cu = current_user();
    $vid = visitor_id();
    $meta = visitor_meta();
    $clean_name = aff_clean($pname, true);
    $cart_log = jload('cart_log.json', []);
    if (!is_array($cart_log)) $cart_log = [];
    $cart_log[] = [
        'vid' => $vid,
        'user' => !empty($cu['email']) ? $cu['email'] : $vid,
        'name' => !empty($cu['name']) ? $cu['name'] : 'زائر',
        'plat' => $plat,
        'id' => (string)$id,
        'pname' => mb_substr($clean_name !== '' ? $clean_name : $pname, 0, 120),
        'qty' => max(1, (int)$qty),
        'price' => (float)$price,
        'variant' => mb_substr((string)$variant, 0, 80),
        'img' => (string)$img,
        'device' => $meta['device'],
        'at' => date('c')
    ];
    jsave('cart_log.json', array_slice($cart_log, -5000));

    $today = date('Y-m-d');
    $an = jload('analytics.json', []);
    if (is_array($an)) {
        if (!isset($an['daily'][$today])) $an['daily'][$today] = ['views' => 0, 'uv' => [], 'carts' => 0, 'loves' => 0, 'orders' => 0];
        $an['daily'][$today]['carts'] = (int)($an['daily'][$today]['carts'] ?? 0) + 1;
        jsave('analytics.json', $an);
    }
    // حدث موحّد في جدول events (يغذّي الـ Funnel: زيارة ← منتج ← سلة ← أوردر)
    if (function_exists('wf_event')) {
        wf_event('add_to_cart', [
            'product_id' => (string)$id,
            'plat'       => (string)$plat,
            'ref'        => active_ref(),
            'qty'        => max(1, (int)$qty),
            'value'      => round((float)$price, 2),
            'name'       => mb_substr($clean_name !== '' ? $clean_name : $pname, 0, 120),
        ]);
    }
}

// ============================================================================
//  مخزن الأحداث الموحّد (Events Store) — أساس كل الإحصائيات
//  كل حدث: type · visitor_id · user · ref · product · path · device · country · at
// ============================================================================
// حماية من تضخّم ملف الأحداث: الملف الكبير بيفضل يتقرا ويتكتب مع كل زيارة،
// وده اللي بيوقّع الصفحات على الاستضافة المشتركة (ذاكرة/وقت) بخطأ «تعذر تحميل الصفحة مؤقتًا».
function wf_events_guard() {
    static $done = false;
    if ($done) return;
    $done = true;
    $p = DATA_DIR . '/wf_events.json';
    if (!is_file($p)) return;
    if ((int)@filesize($p) < 3500000) return;
    // الملف كبر أوي لدرجة إن فكه بيستهلك ذاكرة كبيرة: نأرشفه (يُحفظ في data/ المحمي) ونبدأ مخزن نظيف
    @rename($p, DATA_DIR . '/wf_events_archive_' . date('Ymd-His') . '.json');
}

function wf_event($type, $extra = []) {
    $type = trim((string)$type);
    if ($type === '') return false;
    if (function_exists('is_bot_request') && is_bot_request()) return false;
    wf_events_guard();

    $vid  = function_exists('visitor_id') ? visitor_id() : session_id();
    $meta = function_exists('visitor_meta') ? visitor_meta() : ['device' => '', 'country' => ''];
    $cu   = function_exists('current_user') ? current_user() : null;
    $day  = date('Y-m-d');

    $row = array_merge([
        'type'       => $type,
        'vid'        => $vid,
        'sid'        => substr(session_id(), 0, 24),
        'user'       => $cu['email'] ?? '',
        'ref'        => function_exists('active_ref') ? active_ref() : '',
        'path'       => mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 200),
        'referrer'   => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 200),
        'ip'         => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'ua'         => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
        'device'     => $meta['device'] ?? '',
        'country'    => $meta['country'] ?? '',
        'day'        => $day,
        'at'         => date('c'),
    ], is_array($extra) ? $extra : []);

    // احتساب فريد لكل (يوم، زائر، نوع، منتج)
    $is_new_visitor_today = false;
    $is_unique = true;
    // الزائر اليومي بيتحسب بالـ IP (مرة واحدة في اليوم لكل شبكة) بدل معرّف الكوكيز
    $dkey = function_exists('wf_daily_visitor_key') ? wf_daily_visitor_key() : (string)($row['vid'] ?? '');

    jupdate('wf_events.json', function (&$ev) use ($row, $dkey, &$is_new_visitor_today, &$is_unique) {
        if (!is_array($ev)) $ev = ['rows' => [], 'uniq' => [], 'visitors' => []];
        if (!isset($ev['rows']) || !is_array($ev['rows'])) $ev['rows'] = [];
        if (!isset($ev['uniq']) || !is_array($ev['uniq'])) $ev['uniq'] = [];
        if (!isset($ev['visitors']) || !is_array($ev['visitors'])) $ev['visitors'] = [];

        $ukey = $row['day'] . '|' . $row['vid'] . '|' . $row['type'] . '|' . (string)($row['product_id'] ?? '');
        if (isset($ev['uniq'][$ukey])) $is_unique = false;
        else $ev['uniq'][$ukey] = 1;

        if (!isset($ev['visitors'][$row['day']])) $ev['visitors'][$row['day']] = [];
        if (!isset($ev['visitors'][$row['day']][$dkey])) {
            $ev['visitors'][$row['day']][$dkey] = ['first' => $row['at'], 'last' => $row['at'], 'hits' => 0];
            $is_new_visitor_today = true;
        }
        $ev['visitors'][$row['day']][$dkey]['last'] = $row['at'];
        $ev['visitors'][$row['day']][$dkey]['hits']++;

        $ev['rows'][] = $row;
        // سقف أقل: كل حدث بيعيد قراءة/كتابة الملف كله، فالحجم الكبير بيبطّل الموقع ويوقّعه
        if (count($ev['rows']) > 6000) $ev['rows'] = array_slice($ev['rows'], -3000);
        // تنظيف الفهارس القديمة (نحتفظ بآخر أيام قليلة بس)
        if (count($ev['uniq']) > 6000) {
            $wf_cut_day = date('Y-m-d', time() - 4 * 86400);
            $ev['uniq'] = array_filter($ev['uniq'], function ($k) use ($wf_cut_day) { return strncmp((string)$k, $wf_cut_day, 10) >= 0; });
        }
        if (count($ev['visitors']) > 45) $ev['visitors'] = array_slice($ev['visitors'], -30, null, true);
        return true;
    }, ['rows' => [], 'uniq' => [], 'visitors' => []]);

    // عدّاد لكل منتج
    if (!empty($row['product_id']) && function_exists('jupdate')) {
        jupdate('product_counters.json', function (&$pc) use ($row, $type, $is_unique) {
            if (!is_array($pc)) $pc = [];
            $k = (string)$row['product_id'];
            if (!isset($pc[$k])) $pc[$k] = ['views' => 0, 'carts' => 0, 'likes' => 0, 'orders' => 0, 'uv' => []];
            if ($type === 'product_view')                       $pc[$k]['views']++;
            if ($type === 'add_to_cart')                        $pc[$k]['carts']++;
            if ($type === 'like')                               $pc[$k]['likes']++;
            if ($type === 'order')                              $pc[$k]['orders']++;
            if ($is_unique && $type === 'product_view') {
                if (!isset($pc[$k]['uv']) || !is_array($pc[$k]['uv'])) $pc[$k]['uv'] = [];
                $pc[$k]['uv'][$row['vid']] = 1;
            }
            return true;
        }, []);
    }
    return true;
}

// إحصائيات سريعة من مخزن الأحداث
function wf_stats($days = 30) {
    wf_events_guard();
    $ev = jload('wf_events.json', []);
    $rows = (isset($ev['rows']) && is_array($ev['rows'])) ? $ev['rows'] : [];
    $visitors = (isset($ev['visitors']) && is_array($ev['visitors'])) ? $ev['visitors'] : [];

    $cut = date('Y-m-d', time() - ((int)$days * 86400));
    $daily = [];

    // زوّار فريدون لكل يوم
    foreach ($visitors as $d => $v) {
        if ($d < $cut) continue;
        $daily[$d]['uv'] = is_array($v) ? count($v) : 0;
    }
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $d = (string)($r['day'] ?? substr((string)($r['at'] ?? ''), 0, 10));
        if ($d === '' || $d < $cut) continue;
        if (!isset($daily[$d])) $daily[$d] = ['uv' => 0];
        foreach (['views','product_views','carts','loves','orders','revenue'] as $k) {
            if (!isset($daily[$d][$k])) $daily[$d][$k] = 0;
        }
        $t = (string)($r['type'] ?? '');
        if ($t === 'page_view')    $daily[$d]['views']++;
        if ($t === 'product_view') $daily[$d]['product_views']++;
        if ($t === 'add_to_cart')  $daily[$d]['carts']++;
        if ($t === 'like')         $daily[$d]['loves']++;
        if ($t === 'order') {
            $daily[$d]['orders']++;
            $daily[$d]['revenue'] += (float)($r['value'] ?? 0);
        }
    }
    ksort($daily);
    $daily = array_slice($daily, -max(1, (int)$days), null, true);

    $totals = ['views' => 0, 'uv' => 0, 'product_views' => 0, 'carts' => 0, 'loves' => 0, 'orders' => 0, 'revenue' => 0];
    foreach ($daily as $r) {
        foreach ($totals as $k => $v) $totals[$k] += (float)($r[$k] ?? 0);
    }
    return ['days' => $daily, 'totals' => $totals];
}
// ============================================================================
//  مصدر الزيارة (متجر / صفحة هبوط) + إحصاءات روابط المسوق: كام زائر وكام طلب
//  قراءة فقط: لا تغيير في الأسعار أو العمولات أو الأرصدة.
// ============================================================================
function wf_traffic_src($src = '') {
    $clean = preg_replace('/[^a-z_]/', '', strtolower((string)$src));
    if ($clean !== '' && empty($_SESSION['wf_src'])) $_SESSION['wf_src'] = $clean; // أول لمسة للزائر
    return (string)($_SESSION['wf_src'] ?? '');
}

// يستخرج (plat#, id) من مسار صفحة (صفحة هبوط أو منتج) — بدون أي طلب شبكة
function wf_link_key_from_uri($uri) {
    $uri = (string)$uri;
    if ($uri === '') return ['', ''];
    $q = [];
    $parts = parse_url($uri);
    $path = (string)($parts['path'] ?? '');
    if (!empty($parts['query'])) parse_str($parts['query'], $q);
    $id = (string)($q['id'] ?? '');
    if ($id === '') return ['', ''];
    $plat = (string)($q['plat'] ?? '');
    if ($plat === '') $plat = function_exists('resolve_plat') ? resolve_plat($id) : '';
    return [$plat, $id];
}

/**
 * إحصاءات روابط المسوق: زيارات + طلبات لكل منتج/صفحة هبوط، ومجمّعة حسب القسم،
 * وزوار المتجر وطلباته. المصادر: سجل الأحداث + سجل النقرات + الطلبات المسجلة.
 */
function wf_marketer_link_stats($code, $days = 0) {
    $code = trim((string)$code);
    // 'clients' لازم يتعمل فاضية: من غيره count($store['clients']) بيرمي Fatal على tabs الروابط وصفحات الهبوط
    $empty_store = ['key' => 'store', 'visitors' => [], 'clients' => [], 'views' => 0, 'orders' => 0, 'qty' => 0, 'earn' => 0.0, 'last' => ''];
    $out = [
        'links'   => [],
        'landing' => ['pages' => 0, 'visitors' => 0, 'views' => 0, 'orders' => 0, 'qty' => 0, 'clients' => 0, 'earn' => 0.0],
        'store'   => array_merge($empty_store, ['visitors' => 0, 'clients' => 0]),
        'clicks'  => ['total' => 0, 'unique' => 0],
        'orders'  => ['total' => 0, 'qty' => 0, 'earn' => 0.0, 'attributed' => 0],
        'visits'  => ['visitors' => 0, 'views' => 0],
        'meta'    => ['ms' => 0, 'truncated' => false, 'rows' => 0, 'error' => ''],
    ];
    if ($code === '') return $out;

    // 🛡 الاستضافة المشتركة: التبويبات دي بتتندّى أكتر من مرة في نفس الطلب → نتيجة الطلب الواحد بتتخزن في الذاكرة
    static $wf_ls_memo = [];
    $wf_ls_key = $code . '|' . (int)$days;
    if (isset($wf_ls_memo[$wf_ls_key])) return $wf_ls_memo[$wf_ls_key];
    $wf_ls_t0 = microtime(true);
    $wf_ls_dead = false;
    // ميزانية زمن/ميموري: لو السجل كبير جدًا، نوقف التجميع ونقول للمستخدم بدل ما السيرفر يقتل الصفحة
    $wf_ls_budget = static function () use (&$wf_ls_dead, $wf_ls_t0) {
        if ($wf_ls_dead) return true;
        if ((microtime(true) - $wf_ls_t0) > 1.8 || memory_get_usage(true) > (240 * 1048576)) $wf_ls_dead = true;
        return $wf_ls_dead;
    };

    wf_events_guard();
    $since = $days > 0 ? time() - ((int)$days * 86400) : 0;
    $links = [];
    $store = $empty_store;
    $all_vids = [];
    $total_views = 0;

    $seed = function ($key) use (&$links) {
        if (!isset($links[$key])) {
            $links[$key] = ['key' => $key, 'visitors' => [], 'views' => 0, 'orders' => 0, 'qty' => 0, 'earn' => 0.0, 'last' => '', 'urls' => [], 'clients' => []];
        }
        return $key;
    };
    $touch = function ($key, $vid, $ts, $view = true) use (&$links, $seed) {
        $seed($key);
        if ($vid !== '') $links[$key]['visitors'][$vid] = max((int)($links[$key]['visitors'][$vid] ?? 0), (int)$ts);
        if ($view) $links[$key]['views']++;
        if ($ts > strtotime($links[$key]['last'] ?: '2000-01-01')) $links[$key]['last'] = date('c', (int)$ts);
    };

    // 1) سجل الأحداث الموحّد (اضغطات الصفحة + مشاهدات المنتج/المتجر/الهبوط + الطلبات)
    $we = jload('wf_events.json', []);
    $rows = (isset($we['rows']) && is_array($we['rows'])) ? array_slice($we['rows'], -6000) : [];
    foreach ($rows as $row) {
        if ($wf_ls_budget()) break;
        if (!is_array($row) || (string)($row['ref'] ?? '') !== $code) continue;
        $ts = strtotime((string)($row['at'] ?? '')) ?: 0;
        if ($since && $ts && $ts < $since) continue;
        $type = (string)($row['type'] ?? '');
        $vid = (string)($row['vid'] ?? '');
        if ($type === 'store_view') {
            $store['views']++;
            if ($vid !== '') $store['visitors'][$vid] = $ts;
            if ($ts > strtotime($store['last'] ?: '2000-01-01')) $store['last'] = date('c', $ts);
            continue;
        }
        if (in_array($type, ['landing_view', 'product_view', 'page_view'], true)) {
            $key = '';
            if ($type === 'landing_view') {
                $plat = (string)($row['plat'] ?? '');
                $pid = (string)($row['product_id'] ?? '');
                if ($pid === '') { list($plat, $pid) = wf_link_key_from_uri((string)($row['path'] ?? '')); }
                if ($pid !== '') $key = ($plat !== '' ? $plat : 'physical') . '#' . $pid;
            } elseif ($type === 'product_view') {
                $plat = (string)($row['plat'] ?? '');
                $pid = (string)($row['product_id'] ?? '');
                if ($pid === '' && !empty($row['path'])) { list($plat, $pid) = wf_link_key_from_uri((string)$row['path']); }
                if ($pid !== '') $key = ($plat !== '' ? $plat : 'physical') . '#' . $pid;
            }
            if ($key !== '') $touch($key, $vid, $ts, true);
        }
    }

    // 2) أحداث الزيارات من متعقّب الصفحات (يشمل زيارات قبل تشغيل حدث الهبوط/المتجر)
    foreach (array_slice((array)jload('events.json', []), -3000) as $ev) {
        if ($wf_ls_budget()) break;
        if (!is_array($ev) || (string)($ev['marketer_id'] ?? '') !== $code) continue;
        $ts = strtotime((string)($ev['created_at'] ?? '')) ?: 0;
        if ($since && $ts && $ts < $since) continue;
        $type = (string)($ev['type'] ?? '');
        if (!in_array($type, ['page_view', 'product_view'], true)) continue;
        $uri = (string)($ev['uri'] ?? '');
        $vid = (string)($ev['visitor_id'] ?? '');
        if ($uri !== '' && strpos($uri, 'store.php') !== false) {
            $store['views']++;
            if ($vid !== '') $store['visitors'][$vid] = $ts;
            if ($ts > strtotime($store['last'] ?: '2000-01-01')) $store['last'] = date('c', $ts);
            continue;
        }
        list($plat, $pid) = wf_link_key_from_uri($uri);
        if ($pid !== '') $touch(($plat !== '' ? $plat : 'physical') . '#' . $pid, $vid, $ts, true);
    }

    // 3) الطلبات المسجلة (كام واحد طلب فعلاً من كل رابط)
    $source_totals = [];
    foreach ((array)jload('orders.json', []) as $o) {
        if ($wf_ls_budget()) break;
        if (!is_array($o) || (string)($o['ref'] ?? '') !== $code) continue;
        $ts = strtotime((string)($o['at'] ?? '')) ?: 0;
        if ($since && $ts && $ts < $since) continue;
        $plat = (string)($o['plat'] ?? '');
        $pid = (string)($o['id'] ?? '');
        $key = ($pid !== '' ? (($plat !== '' ? $plat : 'physical') . '#' . $pid) : '');
        $src = (string)($o['src'] ?? '');
        // الفصل: طلب المتجر يُحسب للمتجر فقط، وطلب صفحة الهبوط يُحسب لصفحة الهبوط فقط
        $is_store_order = ($src === 'store');
        $client = preg_replace('/\D+/', '', (string)($o['cphone'] ?? ($o['customer']['phone'] ?? '')));
        $source_totals[$src !== '' ? $src : 'unknown'] = ($source_totals[$src !== '' ? $src : 'unknown'] ?? 0) + 1;
        if ($src !== '') $out['orders']['attributed']++;
        $qty = max(1, (int)($o['qty'] ?? 1));
        $earn = (float)($o['m_earn'] ?? 0);
        $out['orders']['total']++;
        $out['orders']['qty'] += $qty;
        $out['orders']['earn'] += $earn;
        if (!$is_store_order && $key !== '') {
            $seed($key);
            $links[$key]['orders']++;
            $links[$key]['qty'] += $qty;
            $links[$key]['earn'] += $earn;
            if ($client !== '') $links[$key]['clients'][$client] = 1;
            if ($ts > strtotime($links[$key]['last'] ?: '2000-01-01')) $links[$key]['last'] = date('c', $ts);
        }
        if ($is_store_order) {
            $store['orders']++;
            $store['qty'] += $qty;
            $store['earn'] += $earn;
            if ($client !== '') $store['clients'][$client] = 1;
        }
    }

    // 4) النقرات على الروابط (كام واحد ضغط الرابط)
    $clicks_total = 0;
    $click_vids = [];
    foreach ((array)jload('clicks.json', []) as $c) {
        if ($wf_ls_budget()) break;
        if (!is_array($c) || (string)($c['ref'] ?? '') !== $code) continue;
        $ts = strtotime((string)($c['at'] ?? '')) ?: 0;
        if ($since && $ts && $ts < $since) continue;
        $clicks_total++;
        $click_vids[(string)($c['ip'] ?? '')] = 1;
    }
    $mc = jload('marketer_clicks.json', []);
    $unique_clicks = 0;
    if (is_array($mc) && isset($mc[$code]) && is_array($mc[$code])) {
        $unique_clicks = is_array($mc[$code]['unique_visitors'] ?? null) ? count($mc[$code]['unique_visitors']) : 0;
        if ($clicks_total === 0) $clicks_total = (int)($mc[$code]['total_clicks'] ?? 0);
    }
    if ($unique_clicks === 0) $unique_clicks = count($click_vids);

    // ترتيب: الأكثر طلبات ثم الأكثر زواراً ثم الأكثر مشاهدة
    $landing_tot = ['pages' => 0, 'visitors' => 0, 'views' => 0, 'orders' => 0, 'qty' => 0, 'clients' => 0, 'earn' => 0.0];
    foreach ($links as $key => $row) {
        $links[$key]['visitors_count'] = count($row['visitors']);
        unset($links[$key]['visitors']);
        $links[$key]['clients_count'] = count($row['clients']);
        unset($links[$key]['clients']);
        $all_vids[$key] = $links[$key]['visitors_count'];
        $total_views += (int)$links[$key]['views'];
        if ((int)$links[$key]['views'] > 0 || (int)$links[$key]['orders'] > 0) $landing_tot['pages']++;
        $landing_tot['visitors'] += (int)$links[$key]['visitors_count'];
        $landing_tot['views']    += (int)$links[$key]['views'];
        $landing_tot['orders']   += (int)$links[$key]['orders'];
        $landing_tot['qty']      += (int)$links[$key]['qty'];
        $landing_tot['clients']  += (int)$links[$key]['clients_count'];
        $landing_tot['earn']     += (float)$links[$key]['earn'];
    }
    $landing_tot['earn'] = round($landing_tot['earn'], 2);
    uasort($links, function ($a, $b) {
        return [$b['orders'], $b['visitors_count'], $b['views']] <=> [$a['orders'], $a['visitors_count'], $a['views']];
    });

    $out['links'] = $links;
    $out['landing'] = $landing_tot;
    $out['store'] = ['key' => 'store', 'visitors' => count((array)($store['visitors'] ?? [])), 'clients' => count((array)($store['clients'] ?? [])), 'views' => (int)($store['views'] ?? 0), 'orders' => (int)($store['orders'] ?? 0), 'qty' => (int)($store['qty'] ?? 0), 'earn' => round((float)($store['earn'] ?? 0), 2), 'last' => (string)($store['last'] ?? '')];
    $out['clicks'] = ['total' => $clicks_total, 'unique' => $unique_clicks];
    $out['visits'] = ['visitors' => array_sum($all_vids), 'views' => $total_views];
    $out['sources'] = $source_totals;
    $out['meta'] = ['ms' => (int)round((microtime(true) - $wf_ls_t0) * 1000), 'truncated' => $wf_ls_dead, 'rows' => count($links), 'error' => ''];
    $wf_ls_memo[$wf_ls_key] = $out;
    return $out;
}

/**
 * نسخة آمنة لإحصاءات الروابط: على الاستضافة المشتركة أي انحراف في ملفات السجل
 * لازم يعرض تنبيه بصفحة سليمة، مش صفحة «تعذر تحميل الصفحة مؤقتًا».
 */
function wf_marketer_link_stats_safe($code, $days = 0, &$cats = null) {
    $cats = [];
    $zero = function () {
        $z = wf_marketer_link_stats('', 0);
        $z['links'] = [];
        $z['meta'] = ['ms' => 0, 'truncated' => false, 'rows' => 0, 'error' => ''];
        return $z;
    };
    try {
        $stats = wf_marketer_link_stats($code, $days);
        if (!is_array($stats)) $stats = $zero();
        try {
            $cats = wf_marketer_links_by_category($code, $stats, $days);
            if (!is_array($cats)) $cats = [];
        } catch (Throwable $e) { $cats = []; }
        return $stats;
    } catch (Throwable $e) {
        if (function_exists('wf_ui_log_fatal')) wf_ui_log_fatal('marketer link stats: ' . $e->getMessage(), __FILE__, (int)__LINE__);
        $stats = $zero();
        $stats['meta']['error'] = 'السجل الكبير مسمحلناش نكمل الإحصاءات دلوقتي';
        return $stats;
    }
}

// تجميع روابط المسوق حسب قسم/تصنيف المنتج (كاتيجوري) — عرض فقط
function wf_marketer_links_by_category($code, $stats = null, $days = 0) {
    if ($stats === null) $stats = wf_marketer_link_stats($code, $days);
    $groups = [];
    $wf_pc = [];   // بحث المنتج لكل رابط مرة واحدة في النداء (تجنب مسح الكتالوج لكل سطر)
    foreach ((array)($stats['links'] ?? []) as $key => $row) {
        $plat = ''; $pid = (string)$key;
        if (strpos((string)$key, '#') !== false) list($plat, $pid) = explode('#', (string)$key, 2);
        $wf_ck = $plat . '#' . $pid;
        if ($pid === '') $product = null;
        else {
            if (!array_key_exists($wf_ck, $wf_pc)) $wf_pc[$wf_ck] = find_product_by_id($pid, $plat);
            $product = $wf_pc[$wf_ck];
        }
        $cat = $product ? wf_normalize_category($product['category'] ?? '', 'غير مصنّف') : 'غير مصنّف';
        if ($cat === '') $cat = 'غير مصنّف';
        if (!isset($groups[$cat])) $groups[$cat] = ['category' => $cat, 'links' => 0, 'visitors' => 0, 'views' => 0, 'orders' => 0, 'qty' => 0, 'earn' => 0.0];
        $groups[$cat]['links']++;
        $groups[$cat]['visitors'] += (int)($row['visitors_count'] ?? 0);
        $groups[$cat]['views'] += (int)($row['views'] ?? 0);
        $groups[$cat]['orders'] += (int)($row['orders'] ?? 0);
        $groups[$cat]['qty'] += (int)($row['qty'] ?? 0);
        $groups[$cat]['earn'] += (float)($row['earn'] ?? 0);
    }
    uasort($groups, function ($a, $b) {
        return [$b['orders'], $b['visitors']] <=> [$a['orders'], $a['visitors']];
    });
    return $groups;
}

// عدد الزوّاد المتواجدين الآن (آخر 5 دقائق)
function wf_online_now($minutes = 5) {
    $f = DATA_DIR . '/presence.json';
    if (!file_exists($f)) return 0;
    $pr = jload('presence.json', []);
    if (!is_array($pr)) return 0;
    $cut = time() - ($minutes * 60);
    $n = 0;
    foreach ($pr as $v) if (is_array($v) && (int)($v['ts'] ?? 0) >= $cut) $n++;
    return $n;
}

function track_love_event($plat, $id, $pname = '', $img = '', $price = 0, $added = true) {
    if (function_exists('wf_event')) {
        wf_event($added ? 'like' : 'unlike', ['product_id' => (string)$id, 'plat' => (string)$plat,
                 'name' => mb_substr((string)$pname, 0, 120), 'value' => round((float)$price, 2)]);
    }
    $cu = current_user();
    $vid = visitor_id();
    $meta = visitor_meta();
    $clean_name = aff_clean($pname, true);
    $love_log = jload('love_log.json', []);
    if (!is_array($love_log)) $love_log = [];
    $love_log[] = [
        'vid' => $vid,
        'user' => !empty($cu['email']) ? $cu['email'] : $vid,
        'name' => !empty($cu['name']) ? $cu['name'] : 'زائر',
        'plat' => $plat,
        'id' => (string)$id,
        'pname' => mb_substr($clean_name !== '' ? $clean_name : $pname, 0, 120),
        'img' => (string)$img,
        'price' => (float)$price,
        'action' => $added ? 'love' : 'unlove',
        'device' => $meta['device'],
        'at' => date('c')
    ];
    jsave('love_log.json', array_slice($love_log, -2000));

    if ($added) {
        $today = date('Y-m-d');
        $an = jload('analytics.json', []);
        if (is_array($an)) {
            if (!isset($an['daily'][$today])) $an['daily'][$today] = ['views' => 0, 'uv' => [], 'carts' => 0, 'loves' => 0, 'orders' => 0];
            $an['daily'][$today]['loves'] = (int)($an['daily'][$today]['loves'] ?? 0) + 1;
            jsave('analytics.json', $an);
        }
    }
}

track_site_visit();

