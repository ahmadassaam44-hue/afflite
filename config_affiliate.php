<?php
// ============================================================================
// config_affiliate.php — جزء من config.php (السطور 3577–4196 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// ============================================================================
//  نوع الحساب: «مسوق» (marketer) أو «أدمن» فقط — حساب العميل اتلغى
//  أي حساب مش أدمن بيتعامل كمسوق (حتى الحسابات القديمة اللي دورها buyer/customer).
// ============================================================================
function wf_user_role($u = null) {
    static $memo = [];
    if (!is_array($u)) $u = current_user();
    if (!$u || empty($u['email'])) return '';
    $key = strtolower(trim((string)$u['email']));
    if (isset($memo[$key])) return $memo[$key];
    $role = strtolower(trim((string)($u['role'] ?? '')));
    // الدور الحقيقي بيتقرأ من ملف المستخدمين (الجلسة ممكن تكون قديمة)
    $fresh = find_user($u['email']);
    $db_role = is_array($fresh) ? strtolower(trim((string)($fresh['role'] ?? ''))) : '';
    if ($role === '') $role = $db_role;
    if ($role === '') $role = 'marketer';
    // حساب العميل اتلغى: buyer/customer = مسوق
    if (in_array($role, ['buyer', 'customer'], true)) $role = 'marketer';
    if (!in_array($role, ['marketer', 'admin'], true)) $role = 'marketer';
    $memo[$key] = $role;
    return $role;
}
function wf_is_marketer_account($u = null) {
    if (!is_array($u)) $u = current_user();
    if (!$u || empty($u['email'])) return false;
    return in_array(wf_user_role($u), ['marketer', 'admin'], true);
}
function wf_current_is_marketer() { return wf_is_marketer_account(current_user()); }
// مزايا حساب العميل على الموقع الأساسي (تتبّع + تقييم/تعليقات + العروض والأخبار)
function wf_customer_perks() {
    return [
        'rating'  => ['star', 'التقييم والتعليقات', 'قيّم أي منتج واكتب تعليقك بعد الشراء — تعليقك بيظهر لباقي العملاء.'],
        'track'   => ['truck', 'تتبع الشحنة', 'تابع حالة طلبك خطوة بخطوة من التأكيد لحد التوصيل من صفحة «طلباتي».'],
        'offers'  => ['bell', 'أحدث العروض والأخبار', 'هيوصلك إشعار داخل الموقع على كل عرض جديد أو تحديث سعر، بالإضافة للنشرة البريدية.'],
    ];
}

function ensure_user_affiliate($email = '') {
    $cu = current_user();
    if ($email === '' && $cu && !empty($cu['email'])) $email = $cu['email'];
    if ($email === '' || $email === 'admin@wafirinhalk') return null;
    $u = find_user($email);
    if (!$u) return null;
    // حساب العميل العادي ما بقاش يتحوّل تلقائياً لمسوّق: أدوات الأفلييت للمسوقين فقط
    if (!wf_is_marketer_account($u)) return null;
    $need_save = false;
    if (empty($u['ref_code'])) {
        $u['ref_code'] = mk_code($u['name'] ?? 'MK');
        $need_save = true;
    }
    if ($need_save) {
        $rc = $u['ref_code'];
        update_user($email, function (&$row) use ($rc) {
            if (empty($row['ref_code'])) $row['ref_code'] = $rc;
        });
        if (!empty($_SESSION['user']) && strcasecmp($_SESSION['user']['email'] ?? '', $email) === 0) {
            $_SESSION['user']['ref_code'] = $rc;
        }
    }
    return $u;
}

// ============================================================================
//  تشخيص فشل جلب بيانات منتج من موقع خارجي (جوميا / نون)
//  بيرجّع سبب مفهوم يتعرض للمسوق والإدارة بدل رسالة عامة. فاضي = مفيش فشل.
// ============================================================================
function wf_fetch_fail_reason($html, $code, $curl_err, $parsed_ok, $site) {
    if ($parsed_ok) return '';
    if (!function_exists('curl_init')) return 'السيرفر مش شغال عليه cURL (الاستضافة مقفلاه)';
    if ($html === false || $html === null || $html === '') {
        return $curl_err !== ''
            ? 'السيرفر مقدرش يوصل لـ' . $site . ' (' . $curl_err . ')'
            : 'السيرفر مقدرش يوصل لـ' . $site . ' أو الصفحة رجعت فاضية';
    }
    $code = (int)$code;
    $h = (string)$html;
    if ($code === 403 || $code === 429 || $code === 503
        || preg_match('~cf-chl|Just a moment|captcha|Access Denied|Attention Required|_incapsula_|px-captcha~i', $h)) {
        return $site . ' حمت الصفحة وطلبت تحقق (غالباً بتمنع السيرفر) — HTTP ' . $code;
    }
    if ($code >= 400) return $site . ' ردت بخطأ HTTP ' . $code;
    return 'الصفحة اتجابت بس مافيهاش بيانات المنتج (غالباً بتتحمّل بالمتصفح)';
}

// عدّادات مزايا حساب العميل (عرض فقط — لا حسابات مالية ولا تسعير)
function wf_customer_perk_counts($email = '') {
    $email = strtolower(trim((string)$email));
    $out = ['orders' => 0, 'orders_active' => 0, 'reviews' => 0, 'wishlist' => 0, 'unread' => 0];
    if ($email === '') return $out;
    foreach ((array)jload('orders.json', []) as $o) {
        if (!is_array($o) || strcasecmp((string)($o['user'] ?? ''), $email) !== 0) continue;
        $out['orders']++;
        $k = function_exists('status_key') ? status_key((string)($o['status'] ?? '')) : '';
        if (!in_array($k, ['delivered', 'cancelled', 'returned'], true)) $out['orders_active']++;
    }
    foreach ((array)jload('reviews.json', []) as $r) {
        if (is_array($r) && strcasecmp((string)($r['user_email'] ?? ''), $email) === 0) $out['reviews']++;
    }
    $wl = jload('wishlist.json', []);
    if (isset($wl[$email]) && is_array($wl[$email])) $out['wishlist'] = count($wl[$email]);
    foreach (my_notifications($email) as $n) if (empty($n['read'])) $out['unread']++;
    return $out;
}

// تقييمات وتعليقات العميل على المنتجات (من reviews.json) — عرض في «حسابي»
function wf_customer_reviews($email = '', $limit = 10) {
    $email = strtolower(trim((string)$email));
    if ($email === '') return [];
    $out = [];
    $all = (array)jload('reviews.json', []);
    foreach (array_reverse($all, true) as $r) {
        if (!is_array($r) || strcasecmp((string)($r['user_email'] ?? ''), $email) !== 0) continue;
        $uid = (string)($r['product_uid'] ?? '');
        $plat = (string)($r['plat'] ?? '');
        $pid = $uid;
        if (strpos($uid, '#') !== false) { [$plat, $pid] = explode('#', $uid, 2); }
        $name = (string)($r['product_name'] ?? '');
        if ($name === '' && $pid !== '' && function_exists('find_product_by_id')) {
            $pr = find_product_by_id($pid, $plat);
            if (is_array($pr)) $name = (string)($pr['name'] ?? '');
        }
        $out[] = [
            'uid' => $uid, 'plat' => $plat, 'id' => $pid,
            'name' => $name !== '' ? $name : $pid,
            'rating' => max(1, min(5, (int)($r['rating'] ?? 0))),
            'comment' => (string)($r['comment'] ?? ''),
            'at' => (string)($r['created_at'] ?? ($r['at'] ?? '')),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

// ============================================================================
//  محافظات ومدن مصر (من بيانات شحن شوب مارت) + مناطق ومدن دول الخليج
//  (data/shipping_cities_gulf.json) — تُستخدم في تسجيل المسوقين (مصر بس) وفي
//  قوائم المنطقة/المدينة بنافذة الطلب وصفحة الهبوط (حسب دولة المنتج).
// ============================================================================
// $country = 'مصر' (الافتراضي) بيرجع نفس خريطة المحافظات/المدن القديمة بالظبط.
function wf_ship_cities_map($country = 'مصر') {
    static $cache = [];
    $cn = wf_match_country($country, 'مصر');
    if (isset($cache[$cn])) return $cache[$cn];
    $map = [];
    // أسماء المناطق/المدن بتتنضّف وتتشال منها المكررات
    $collect = function ($rows) {
        $names = [];
        foreach ((array)$rows as $c) {
            $n = trim((string)(is_array($c) ? ($c['name'] ?? '') : $c));
            if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
        }
        return $names;
    };
    if ($cn === 'مصر') {
        $raw = jload('shipping_cities_shopmart.json', [], false);
        $govs = is_array($raw['governorates'] ?? null) ? $raw['governorates'] : [];
        foreach ($govs as $gov => $cities) {
            $gov = trim((string)$gov);
            if ($gov === '' || !is_array($cities)) continue;
            $names = $collect($cities);
            if ($names) $map[$gov] = $names;
        }
        if (!$map) {
            foreach (array_keys(function_exists('site_ship_map') ? site_ship_map() : []) as $gov) {
                $gov = trim((string)$gov);
                if ($gov !== '') $map[$gov] = [];
            }
        }
    } else {
        $raw = jload('shipping_cities_gulf.json', [], false);
        $list = (is_array($raw['countries'] ?? null) && is_array($raw['countries'][$cn] ?? null)) ? $raw['countries'][$cn] : [];
        foreach ($list as $region => $cities) {
            $region = trim((string)$region);
            if ($region === '') continue;
            $map[$region] = $collect($cities);
        }
        // أي منطقة في جدول الدولة مالهاش مدن ⇒ قائمة فاضية (الواجهة بتحوّل الحقل لنص حر)
        foreach (array_keys(function_exists('site_ship_map') ? site_ship_map('manual', $cn) : []) as $region) {
            $region = trim((string)$region);
            if ($region !== '' && !isset($map[$region])) $map[$region] = [];
        }
    }
    return $cache[$cn] = $map;
}
// محافظات مصر (لصفحات التسجيل/استكمال بيانات المسوق) — من غير دول الخليج
function wf_ship_governorates() { return array_keys(wf_ship_cities_map()); }
function wf_ship_cities($gov = '', $country = 'مصر') {
    $map = wf_ship_cities_map($country);
    $gov = trim((string)$gov);
    return $gov !== '' && !empty($map[$gov]) ? $map[$gov] : [];
}
// مصر + دول الخليج في خريطة واحدة للـ JS (wfCityState): المفتاح اسم المنطقة، وأسماء المناطق
// مش بتتعارض بين مصر والخليج — ولو اتعارضت مرة، قائمة مصر هي اللي بتكسب (زي السلوك القديم).
function wf_ship_cities_map_all() {
    static $all = null;
    if ($all !== null) return $all;
    $all = wf_ship_cities_map('مصر');
    foreach (wf_country_list() as $cn) {
        if ($cn === 'مصر') continue;
        foreach (wf_ship_cities_map($cn) as $region => $cities) {
            if (!isset($all[$region])) $all[$region] = $cities;
        }
    }
    return $all;
}

// ============================================================================
//  وصول أحدث العروض والأخبار لأصحاب الحسابات (بريد + إشعار داخل الموقع)
// ============================================================================
function wf_offers_subscribe($email, $name = '', $source = 'site') {
    $email = strtolower(trim((string)$email));
    if ($email === '' || !function_exists('valid_email') || !valid_email($email)) return false;
    $nl = jload('newsletter.json', []);
    if (!is_array($nl)) $nl = [];
    foreach ($nl as $row) {
        if (strcasecmp((string)($row['email'] ?? ''), $email) === 0) return false;
    }
    $nl[] = ['email' => mb_substr($email, 0, 120), 'name' => mb_substr(trim((string)$name), 0, 60), 'at' => date('c'), 'via' => $source];
    jsave('newsletter.json', $nl);
    return true;
}
// أحدث العروض والأخبار التي تصل للعميل: إشعارات الموقع العامة + تنبيهات الأسعار
function wf_offers_news_items($email = '', $limit = 6) {
    $items = [];
    $notifs = jload('notifications.json', []);
    if (!is_array($notifs)) $notifs = [];
    foreach (array_reverse($notifs, true) as $n) {
        if (!is_array($n)) continue;
        $for = (string)($n['for'] ?? '');
        if ($for !== 'all' && ($email === '' || strcasecmp($for, $email) !== 0)) continue;
        $items[] = [
            'title' => '', 'text' => wf_ui_plain_offers((string)($n['text'] ?? '')),
            'at' => (string)($n['at'] ?? ''), 'link' => (string)($n['link'] ?? ''),
        ];
        if (count($items) >= $limit) break;
    }
    return $items;
}
function wf_ui_plain_offers($text) {
    return trim(preg_replace('/\s*\n\s*/u', ' • ', (string)$text));
}
function update_user($email, $fn) {
    // الحسابات على Supabase (جدول profiles) — قراءة/تعديل/كتابة بشرط عدم التغيير
    return update_user_row($email, $fn);
}
function mk_code($name) { $c = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $name)); $c = substr($c ?: 'MK', 0, 5); return 'MK-' . $c . '-' . rand(10, 99); }

// ===== إدارة متاجر المسوقين والعمولات المخصصة =====
function marketer_stores_all() {
    $all = jload('marketer_stores.json', []);
    return is_array($all) ? $all : [];
}
// أقسام صفحة المتجر التي يمكن لصاحب المتجر تشغيلها/إيقافها (زي محرّر المتجر في المنصات)
function marketer_store_sections_default() {
    return ['announcement' => 1, 'chips' => 1, 'collections' => 1, 'featured' => 1, 'trust' => 1, 'faq' => 1, 'contact' => 1, 'footer' => 1];
}
function marketer_store_slug_from_title($title, $fallback = '') {
    $t = trim((string)$title);
    if (function_exists('wf_slugify')) return (string)wf_slugify($t !== '' ? $t : $fallback, 40);
    return preg_replace('/[^a-z0-9]+/', '-', strtolower((string)preg_replace('/\s+/u', '-', $t)));
}
// كود المتجر: نبحث بالكود أولاً ثم بالرابط المختصر (slug)
function marketer_store_by_slug($slug) {
    $slug = strtolower(trim((string)$slug));
    if ($slug === '') return null;
    foreach (marketer_stores_all() as $code => $st) {
        if (!empty($st['slug']) && strtolower(trim((string)$st['slug'])) === $slug) {
            return ['ref_code' => (string)$code, 'store' => $st];
        }
    }
    return null;
}
function marketer_store_slug_taken($slug, $except_code = '') {
    $slug = strtolower(trim((string)$slug));
    if ($slug === '') return true;
    $except_code = trim((string)$except_code);
    foreach (marketer_stores_all() as $code => $st) {
        if ($except_code !== '' && strcasecmp((string)$code, $except_code) === 0) continue;
        if (!empty($st['slug']) && strtolower(trim((string)$st['slug'])) === $slug) return true;
    }
    foreach (users() as $u) {
        if (!empty($u['ref_code']) && strcasecmp((string)$u['ref_code'], $slug) === 0) return true;
    }
    return false;
}
function marketer_store_get($ref_code) {
    $ref_code = trim((string)$ref_code);
    $sections = marketer_store_sections_default();
    if ($ref_code === '') return ['title' => '', 'bio' => '', 'whatsapp' => '', 'theme' => '#10b981', 'banner' => '', 'products' => [],
        'slug' => '', 'mode' => 'light', 'announcement_text' => '', 'announcement_color' => '#4B5E43',
        'social' => ['facebook' => '', 'instagram' => '', 'tiktok' => ''], 'sections' => $sections,
        'faq' => [], 'contact' => ['phone' => '', 'email' => '', 'address' => '']];
    $all = marketer_stores_all();
    $mk = find_marketer_by_code($ref_code);
    $def_title = $mk ? ('متجر ' . ($mk['name'] ?? $ref_code)) : ('متجر ' . $ref_code);
    $st = $all[$ref_code] ?? [];
    $st_sections = isset($st['sections']) && is_array($st['sections']) ? $st['sections'] : [];
    foreach ($sections as $k => $d) $sections[$k] = isset($st_sections[$k]) ? (int)!empty($st_sections[$k]) : $d;
    $social = isset($st['social']) && is_array($st['social']) ? $st['social'] : [];
    $contact = isset($st['contact']) && is_array($st['contact']) ? $st['contact'] : [];
    $faq = [];
    foreach ((array)($st['faq'] ?? []) as $qa) {
        if (!is_array($qa)) continue;
        $q2 = mb_substr(trim((string)($qa['q'] ?? '')), 0, 160);
        $a2 = mb_substr(trim((string)($qa['a'] ?? '')), 0, 400);
        if ($q2 !== '' && $a2 !== '') $faq[] = ['q' => $q2, 'a' => $a2];
    }
    return [
        'ref_code' => $ref_code,
        'title'    => trim((string)($st['title'] ?? '')) ?: $def_title,
        'bio'      => trim((string)($st['bio'] ?? '')) ?: 'أفضل المنتجات الأصلية بأسرع شحن لباب البيت والدفع عند الاستلام',
        'whatsapp' => trim((string)($st['whatsapp'] ?? ($mk['whatsapp'] ?? ($mk['phone'] ?? '')))),
        'pixel_fb' => trim((string)($st['pixel_fb'] ?? '')),
        'pixel_tt' => trim((string)($st['pixel_tt'] ?? '')),
        'theme'    => trim((string)($st['theme'] ?? '#059669')) ?: '#059669',
        'slug'     => strtolower(trim((string)($st['slug'] ?? ''))),
        'mode'     => (trim((string)($st['mode'] ?? 'light')) === 'dark') ? 'dark' : 'light',
        'logo'     => trim((string)($st['logo'] ?? '')),
        'announcement_text'  => mb_substr(trim((string)($st['announcement_text'] ?? '')), 0, 160),
        'announcement_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($st['announcement_color'] ?? '')) ? (string)$st['announcement_color'] : '#4B5E43',
        'social'   => [
            'facebook'  => trim((string)($social['facebook'] ?? '')),
            'instagram' => trim((string)($social['instagram'] ?? '')),
            'tiktok'    => trim((string)($social['tiktok'] ?? '')),
        ],
        'sections' => $sections,
        'faq'      => $faq,
        'contact'  => [
            'phone'   => trim((string)($contact['phone'] ?? ($mk['phone'] ?? ''))),
            'email'   => trim((string)($contact['email'] ?? ($mk['email'] ?? ''))),
            'address' => mb_substr(trim((string)($contact['address'] ?? '')), 0, 160),
        ],
        'products' => isset($st['products']) && is_array($st['products']) ? $st['products'] : [],
    ];
}
// حفظ إعدادات المتجر الجاية من فورم (يستخدمه تبويب المتجر ومحرّر المتجر داخل صفحة المتجر)
function marketer_store_save_from_post($ref_code, $post) {
    $ref_code = trim((string)$ref_code);
    if ($ref_code === '') return ['ok' => false, 'slug' => ''];
    $st_cur = marketer_store_get($ref_code);
    $slug_in = strtolower(trim((string)($post['store_slug'] ?? '')));
    $slug_in = preg_replace('/[^a-z0-9-]+/', '-', $slug_in);
    if (!is_string($slug_in)) $slug_in = '';
    $slug_in = trim(preg_replace('/-+/', '-', $slug_in), '-');
    if ($slug_in === '' || mb_strlen($slug_in) < 3) $slug_in = $st_cur['slug'] ?: strtolower($ref_code);
    $warn = false;
    if (marketer_store_slug_taken($slug_in, $ref_code)) {
        $slug_in = $st_cur['slug'] ?: strtolower($ref_code);
        $warn = true;
    }
    $sections_in = [];
    foreach (marketer_store_sections_default() as $sec_k => $sec_d) {
        $sections_in[$sec_k] = !empty($post['sections'][$sec_k]) ? 1 : 0;
    }
    $faq_in = [];
    $faq_q = isset($post['faq_q']) && is_array($post['faq_q']) ? $post['faq_q'] : [];
    $faq_a = isset($post['faq_a']) && is_array($post['faq_a']) ? $post['faq_a'] : [];
    foreach ($faq_q as $i => $fq) {
        $fq = mb_substr(trim((string)$fq), 0, 160);
        $fa = mb_substr(trim((string)($faq_a[$i] ?? '')), 0, 400);
        if ($fq !== '' && $fa !== '') $faq_in[] = ['q' => $fq, 'a' => $fa];
        if (count($faq_in) >= 4) break;
    }
    $hex = function ($v, $def) {
        $v = trim((string)$v);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $def;
    };
    marketer_store_save($ref_code, [
        'title'    => mb_substr(trim((string)($post['store_title'] ?? '')), 0, 80),
        'bio'      => mb_substr(trim((string)($post['store_bio'] ?? '')), 0, 220),
        'whatsapp' => mb_substr(trim((string)($post['store_whatsapp'] ?? '')), 0, 30),
        'pixel_fb' => mb_substr(trim((string)($post['pixel_fb'] ?? '')), 0, 60),
        'pixel_tt' => mb_substr(trim((string)($post['pixel_tt'] ?? '')), 0, 60),
        'theme'    => $hex($post['store_theme'] ?? '', '#059669'),
        'slug'     => $slug_in,
        'mode'     => (($post['store_mode'] ?? 'light') === 'dark') ? 'dark' : 'light',
        'announcement_text'  => mb_substr(trim((string)($post['announcement_text'] ?? '')), 0, 160),
        'announcement_color' => $hex($post['announcement_color'] ?? '', '#4B5E43'),
        'social'   => [
            'facebook'  => mb_substr(trim((string)($post['social_facebook'] ?? '')), 0, 120),
            'instagram' => mb_substr(trim((string)($post['social_instagram'] ?? '')), 0, 120),
            'tiktok'    => mb_substr(trim((string)($post['social_tiktok'] ?? '')), 0, 120),
        ],
        'sections' => $sections_in,
        'faq'      => $faq_in,
        'contact'  => [
            'phone'   => mb_substr(trim((string)($post['contact_phone'] ?? '')), 0, 30),
            'email'   => mb_substr(trim((string)($post['contact_email'] ?? '')), 0, 80),
            'address' => mb_substr(trim((string)($post['contact_address'] ?? '')), 0, 160),
        ],
        'products' => $st_cur['products'] ?? [],
    ]);
    return ['ok' => true, 'slug' => $slug_in, 'warn' => $warn];
}
function marketer_store_save($ref_code, $data) {
    $ref_code = trim((string)$ref_code);
    if ($ref_code === '') return false;
    return jupdate('marketer_stores.json', function (&$all) use ($ref_code, $data) {
        if (!is_array($all)) $all = [];
        $cur = isset($all[$ref_code]) && is_array($all[$ref_code]) ? $all[$ref_code] : [];
        foreach ($data as $k => $v) $cur[$k] = $v;
        $cur['updated_at'] = date('c');
        $all[$ref_code] = $cur;
        return true;
    });
}
function marketer_store_product_comm($ref_code, $plat, $pid) {
    $ref_code = trim((string)$ref_code);
    if ($ref_code === '') return 0.0;
    if (!MARKETER_EXTRA_COMM_ON) return 0.0;
    // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة — السعر سعر المصدر بالظبط،
    // وأي قيمة قديمة محفوظة في متجر المسوق أو الجلسة أو رابط ?comm بتتجاهل.
    if (function_exists('wf_is_digital_plat') && wf_is_digital_plat($plat)) return 0.0;
    // فحص عمولة ممررة في الجلسة أو الرابط أو المتجر لهذا المنتج
    $key = $plat . '#' . $pid;
    if (isset($_POST['extra_comm']) && is_numeric($_POST['extra_comm']) && (float)$_POST['extra_comm'] >= 0) {
        return min((float)MAX_MARKUP_AMOUNT, round((float)$_POST['extra_comm'], 2));
    }
    if (isset($_GET['comm']) && is_numeric($_GET['comm']) && (float)$_GET['comm'] >= 0) {
        $gc = min((float)MAX_MARKUP_AMOUNT, round((float)$_GET['comm'], 2));
        $_SESSION['aff_custom_comm'][$ref_code . '#' . $key] = $gc;
        return max(0.0, $gc);
    }
    $st = marketer_store_get($ref_code);
    if (isset($st['products'][$key]['extra_comm']) && is_numeric($st['products'][$key]['extra_comm'])) {
        return max(0.0, min((float)MAX_MARKUP_AMOUNT, round((float)$st['products'][$key]['extra_comm'], 2)));
    }
    // عمولة الرابط (من تبويب الروابط) للمنتجات اللي مش في متجر المسوق
    $lc_map = is_array($st['link_comm'] ?? null) ? $st['link_comm'] : [];
    if (isset($lc_map[$key]) && is_numeric($lc_map[$key])) return max(0.0, min((float)MAX_MARKUP_AMOUNT, round((float)$lc_map[$key], 2)));
    if (isset($_SESSION['aff_custom_comm'][$ref_code . '#' . $key])) {
        return max(0.0, (float)$_SESSION['aff_custom_comm'][$ref_code . '#' . $key]);
    }
    return 0.0;
}
function marketer_effective_price($p, $plat, $ref_code = '', $explicit_extra_comm = null) {
    $base = store_price($p, $plat);
    $pid = (string)($p['id'] ?? '');
    $extra = 0.0;
    if (!MARKETER_EXTRA_COMM_ON) $explicit_extra_comm = null;
    // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة — حتى لو جاية صريحة من فورم أو رابط
    if (function_exists('wf_is_digital_plat') && wf_is_digital_plat($plat)) {
        $explicit_extra_comm = null;
        $ref_code = '';
    }
    if ($explicit_extra_comm !== null && is_numeric($explicit_extra_comm)) {
        $extra = max(0.0, min((float)MAX_MARKUP_AMOUNT, round((float)$explicit_extra_comm, 2)));
    } elseif ($ref_code !== '') {
        $extra = marketer_store_product_comm($ref_code, $plat, $pid);
    }
    $eff = round($base + $extra, 2);
    // الرقمي: نفس تقريب العروض (5 أو 0) عشان سعر المسوق = سعر الموقع. السوشيال ميديا: سعر المصدر بالظبط.
    return wf_is_social_plat($plat) ? $eff : wf_round_price($eff);
}

// ===== نظام أفلييت مثل أمازون (Amazon Associates Style Tracking) =====
// يحفظ كود المسوق في الـ Session + Cookie لمدة 30 يوم عند زيارة أي رابط يحتوي على ?ref=CODE أو ?store=CODE
(function () {
    $cu = current_user();
    if ($cu && !empty($cu['email']) && ($cu['role'] ?? '') !== 'admin') {
        ensure_user_affiliate($cu['email']);
    }
    $in_ref = trim((string)($_GET['ref'] ?? ($_GET['store'] ?? ($_GET['mk'] ?? ''))));
    if ($in_ref !== '' && find_marketer_by_code($in_ref)) {
        $_SESSION['aff_ref'] = $in_ref;
        if (!headers_sent()) {
            @setcookie('aff_ref', $in_ref, time() + 30 * 86400, '/');
        }
        $pid = trim((string)($_GET['id'] ?? ''));
        $pl = $pid !== '' ? resolve_plat($pid, $_GET['plat'] ?? '') : '';
        if ($pid !== '' && isset($_GET['comm']) && is_numeric($_GET['comm']) && (float)$_GET['comm'] >= 0
            && !(function_exists('wf_is_digital_plat') && wf_is_digital_plat($pl))) {
            $_SESSION['aff_custom_comm'][$in_ref . '#' . $pl . '#' . $pid] = min((float)MAX_MARKUP_AMOUNT, round((float)$_GET['comm'], 2));
        }
        $ck_key = $in_ref . '#' . ($pid ?: 'store');
        if (empty($_SESSION['_aff_clicked'][$ck_key])) {
            $_SESSION['_aff_clicked'][$ck_key] = 1;
            $clicks = jload('clicks.json', []);
            $clicks[] = [
                'ref' => $in_ref,
                'plat' => $pl,
                'id' => $pid ?: 'الصفحة الرئيسية',
                'type' => $pid ? 'منتج' : 'متجر',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'at' => date('c')
            ];
            if (count($clicks) > 5000) $clicks = array_slice($clicks, -5000);
            jsave('clicks.json', $clicks);
        }
    }
})();

// إرجاع كود المسوق النشط للزائر الحالي (من الرابط أو الجلسة أو الكوكيز أو حساب المسوق نفسه)
function active_ref($explicit = '') {
    $candidates = [
        trim((string)$explicit),
        trim((string)($_GET['ref'] ?? '')),
        trim((string)($_GET['store'] ?? '')),
        trim((string)($_POST['ref'] ?? '')),
        trim((string)($_SESSION['aff_ref'] ?? '')),
        trim((string)($_COOKIE['aff_ref'] ?? '')),
    ];
    foreach ($candidates as $c) {
        if ($c !== '' && find_marketer_by_code($c)) return $c;
    }
    $cu = current_user();
    if ($cu && !empty($cu['email']) && ($cu['role'] ?? '') !== 'admin') {
        $me = ensure_user_affiliate($cu['email']);
        if (!empty($me['ref_code'])) return $me['ref_code'];
    }
    return '';
}

function log_audit($action, $target, $details = []) {
    $cu = current_user();
    $logs = jload('audit_logs.json', []);
    if (!is_array($logs)) $logs = [];
    $logs[] = [
        'id' => uniqid('aud_'),
        'actor_email' => $cu['email'] ?? 'admin@wafirinhalk',
        'actor_role' => $_SESSION['admin_role'] ?? ($cu['role'] ?? 'super_admin'),
        'action' => (string)$action,
        'target' => (string)$target,
        'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : (string)$details,
        'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 32),
        'at' => date('c')
    ];
    jsave('audit_logs.json', array_slice($logs, -1000));
}

function save_uploaded_image($file_key, $prefix = 'img') {
    if (empty($_FILES[$file_key]) || ($_FILES[$file_key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }
    $tmp = $_FILES[$file_key]['tmp_name'];
    $orig = $_FILES[$file_key]['name'] ?? '';
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $ext = 'jpg';
    }
    // تحقق MIME حقيقي: نرفض أي ملف مش صورة فعلاً (حتى لو امتداده .jpg)
    // ونحدد الامتداد من نوع الصورة الفعلي مش من اسم الملف المرسل.
    if (($sz = @getimagesize($tmp)) === false) return '';
    $mime_ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (empty($mime_ext[$sz['mime'] ?? ''])) return '';
    $ext = $mime_ext[$sz['mime']];
    if ((int)($_FILES[$file_key]['size'] ?? 0) > 4 * 1024 * 1024) return '';
    $dir = __DIR__ . '/assets/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = $prefix . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    $dest = $dir . '/' . $fname;
    if (@move_uploaded_file($tmp, $dest)) {
        return 'assets/uploads/' . $fname;
    }
    return '';
}

// عمولة ثابتة من سجل المنتج لها أولوية؛ إعدادات النسبة اختيارية للمنصات الأخرى فقط.
// الرقمي والسوشيال ميديا: لا عمولة إطلاقاً — أي نسبة قديمة متسجلة في الإعدادات بتتجاهل.
function platform_commission_pct_override($plat) {
    if (function_exists('wf_plat_no_commission') && wf_plat_no_commission($plat)) return null;
    if (in_array((string)$plat, ['vendoor', 'timehand'], true)) return null;
    $v = site_setting('commission_' . preg_replace('/[^a-z0-9_]/i', '', (string)$plat) . '_pct', '');
    if ($v === '' || !is_numeric($v)) return null;
    return max(0, min(100, (float)$v));
}
function source_product_commission($p) {
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $comm = mz_num($p['commission'] ?? '');
    if ($comm <= 0 && !empty($ex['source_commission_original'])) $comm = mz_num($ex['source_commission_original']);
    if ($comm <= 0 && !empty($ex['profit'])) $comm = mz_num($ex['profit']);
    return max(0, round($comm, 2));
}
function raw_list_price($p, $plat = '') {
    $v = $p['price'] ?? null;
    $stored = (is_numeric($v) && (float)$v > 0) ? (float)$v : price_num($p['price_text'] ?? '');
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    // الرقمي: سعر المصدر = سعر المنتج بالدولار × 56 ج.م (المخزن في الكتالوج) — أدق من سعر الملف
    if (wf_plat_no_commission($plat) && (string)$plat === 'g2g') {
        $egp = wf_digital_source_price($p, $plat);
        if ($egp > 0) return $egp;
    }
    // These catalog feeds store `price` as the post-commission cost. Recover the source gross
    // before applying the commission once in store_price_from_gross().
    $gross_source = null;
    if ($plat === 'vendoor') $gross_source = mz_num($ex['purchasing'] ?? '');
    elseif ($plat === 'timehand') $gross_source = mz_num($ex['orig_suggested'] ?? '');
    elseif ($plat === 'm5zoon') $gross_source = mz_num($ex['suggested'] ?? '');
    elseif ($plat === 'angazny') $gross_source = mz_num($ex['orig_suggested'] ?? '');
    elseif ($plat === 'shopmart') $gross_source = mz_num($ex['orig_suggested'] ?? '');
    elseif ($plat === 'safqa') {
        // sale_price is already net; notes/suggested can be stale, so reconstruct from current sale + fixed commission.
        $fixed = source_product_commission($p);
        if ($fixed > 0 && $stored > 0) $gross_source = round($stored + $fixed, 2);
        else $gross_source = $stored;
    }
    if ($gross_source !== null && $gross_source > 0) return $gross_source;
    if (in_array($plat, ['vendoor', 'timehand', 'safqa', 'm5zoon', 'angazny', 'shopmart'], true) && $stored > 0) {
        $fixed = source_product_commission($p);
        if ($fixed > 0) return round($stored + $fixed, 2);
    }
    return $stored;
}
function platform_commission_amount($p, $plat, $gross_price = null, $variant_fixed = null) {
    global $MARGINS;
    // الرقمي والسوشيال ميديا: لا عمولة — أي عمولة مخزنة في بيانات المصدر بتتجاهل
    if (function_exists('wf_plat_no_commission') && wf_plat_no_commission($plat)) return 0.0;
    $gross = ($gross_price !== null && (float)$gross_price > 0) ? (float)$gross_price : raw_list_price($p, $plat);
    if ($gross <= 0) return 0;
    $pct_override = platform_commission_pct_override($plat);
    if ($pct_override !== null) return round($gross * $pct_override / 100, 2);
    if ($variant_fixed !== null && is_numeric($variant_fixed) && (float)$variant_fixed > 0) {
        return round(min($gross, (float)$variant_fixed), 2);
    }
    $fixed = source_product_commission($p);
    if ($fixed > 0) return round(min($gross, $fixed), 2);
    $pct = (float)($MARGINS[$plat] ?? 0);
    if ($pct > 0) return round($gross * ($pct / 100), 2);
    return 0;
}
