<?php
// ============================================================================
//  🛍️ نواة نظام أفلييت أمازون (Amazon Associates)
//  - المسوق يبعت طلب منتج من أمازون (لينك) → الإدارة توافق وتحدد Tracking ID والسعر والنسبة
//  - بعد الموافقة بس يظهر رابط التسويق (تتبع + مباشر) وقيمة العمولة على كل بيعة
//  - النقرات بتتسجل تلقائياً، والمبيعات والأرباح (معلقة/مؤكدة) بتتحدد من الإدارة
//  - كل تحويل (بيعة) بيتسجل عمولة للمسوق + نصيب للموقع
//  ملاحظة: كل الإعدادات (التاجات / النسب / الموافقات) بتتدار من لوحة الإدارة.
// ============================================================================
if (!defined('DATA_DIR')) return;   // لا يُحمّل إلا من داخل config.php

define('AMZ_LINKS_F',  'amazon_links.json');    // كل روابط المسوقين
define('AMZ_CLICKS_F', 'amazon_clicks.json');   // سجل النقرات
define('AMZ_ORDERS_F', 'amazon_orders.json');   // سجل التحويلات (البيعات)
define('AMZ_REQS_F',   'amazon_requests.json'); // طلبات المسوقين للإدارة

// ============================================================================
//  1) المتاجر (Marketplaces) المدعومة
// ============================================================================
function amz_marketplaces() {
    return [
        'com' => ['domain' => 'www.amazon.com',     'label' => 'أمازون أمريكا',   'currency' => 'USD', 'flag' => '🇺🇸'],
        'sa'  => ['domain' => 'www.amazon.sa',      'label' => 'أمازون السعودية', 'currency' => 'SAR', 'flag' => '🇸🇦'],
        'ae'  => ['domain' => 'www.amazon.ae',      'label' => 'أمازون الإمارات', 'currency' => 'AED', 'flag' => '🇦🇪'],
        'eg'  => ['domain' => 'www.amazon.eg',      'label' => 'أمازون مصر',      'currency' => 'EGP', 'flag' => '🇪🇬'],
        'uk'  => ['domain' => 'www.amazon.co.uk',   'label' => 'أمازون بريطانيا', 'currency' => 'GBP', 'flag' => '🇬🇧'],
        'de'  => ['domain' => 'www.amazon.de',      'label' => 'أمازون ألمانيا',  'currency' => 'EUR', 'flag' => '🇩🇪'],
        'fr'  => ['domain' => 'www.amazon.fr',      'label' => 'أمازون فرنسا',    'currency' => 'EUR', 'flag' => '🇫🇷'],
        'it'  => ['domain' => 'www.amazon.it',      'label' => 'أمازون إيطاليا',  'currency' => 'EUR', 'flag' => '🇮🇹'],
        'es'  => ['domain' => 'www.amazon.es',      'label' => 'أمازون إسبانيا',  'currency' => 'EUR', 'flag' => '🇪🇸'],
        'ca'  => ['domain' => 'www.amazon.ca',      'label' => 'أمازون كندا',     'currency' => 'CAD', 'flag' => '🇨🇦'],
        'au'  => ['domain' => 'www.amazon.com.au',  'label' => 'أمازون أستراليا', 'currency' => 'AUD', 'flag' => '🇦🇺'],
        'in'  => ['domain' => 'www.amazon.in',      'label' => 'أمازون الهند',    'currency' => 'INR', 'flag' => '🇮🇳'],
        'jp'  => ['domain' => 'www.amazon.co.jp',   'label' => 'أمازون اليابان',  'currency' => 'JPY', 'flag' => '🇯🇵'],
        'mx'  => ['domain' => 'www.amazon.com.mx',  'label' => 'أمازون المكسيك',  'currency' => 'MXN', 'flag' => '🇲🇽'],
        'br'  => ['domain' => 'www.amazon.com.br',  'label' => 'أمازون البرازيل', 'currency' => 'BRL', 'flag' => '🇧🇷'],
    ];
}
function amz_mkt($key) {
    $m = amz_marketplaces();
    return isset($m[$key]) ? $m[$key] : $m['com'];
}
function amz_mkt_label($key) { $m = amz_mkt($key); return $m['flag'] . ' ' . $m['label']; }
function amz_currency($key)  { $m = amz_mkt($key); return $m['currency']; }

// ============================================================================
//  2) تحليل لينك أمازون: المتجر + ASIN
// ============================================================================
function amz_is_short_link($url) {
    return (bool)preg_match('~^(https?://)?(www\.)?(amzn\.to|amzn\.eu|amzn\.in|a\.co)/~i', trim((string)$url));
}
function amz_is_amazon_url($url) {
    $u = trim((string)$url);
    if ($u === '') return false;
    if (amz_is_short_link($u)) return true;
    $host = '';
    $p = parse_url((strpos($u, '://') === false ? 'https://' : '') . $u);
    if (is_array($p) && !empty($p['host'])) $host = strtolower((string)$p['host']);
    return (bool)preg_match('~(^|\.)amazon\.[a-z.]+$~i', $host);
}
function amz_detect_marketplace($url) {
    $u = trim((string)$url);
    $host = '';
    $p = parse_url((strpos($u, '://') === false ? 'https://' : '') . $u);
    if (is_array($p) && !empty($p['host'])) $host = preg_replace('/^www\./', '', strtolower((string)$p['host']));
    if ($host === '') return '';
    foreach (amz_marketplaces() as $k => $m) {
        if (preg_replace('/^www\./', '', strtolower($m['domain'])) === $host) return $k;
    }
    if (preg_match('~amazon\.([a-z.]+)$~i', $host, $mm)) {
        $tld = strtolower($mm[1]);
        $map = ['com.mx' => 'mx', 'com.au' => 'au', 'com.br' => 'br', 'co.uk' => 'uk', 'co.jp' => 'jp',
                'com' => 'com', 'sa' => 'sa', 'ae' => 'ae', 'eg' => 'eg', 'ca' => 'ca', 'in' => 'in',
                'de' => 'de', 'fr' => 'fr', 'it' => 'it', 'es' => 'es', 'se' => 'com', 'nl' => 'com', 'pl' => 'com', 'sg' => 'com'];
        if (isset($map[$tld])) return $map[$tld];
    }
    return '';
}
function amz_extract_asin($url) {
    $u = trim((string)$url);
    if ($u === '') return '';
    if (preg_match('/^[A-Z0-9]{10}$/i', $u)) return strtoupper($u);      // لصق الـ ASIN مباشرة
    $path = ''; $query = '';
    $p = parse_url($u);
    if (is_array($p)) { $path = (string)($p['path'] ?? ''); $query = (string)($p['query'] ?? ''); }
    $hay = $path . '?' . $query;
    $pats = [
        '~/(?:dp|gp/product|gp/aw/d|product-reviews|product|ASIN|asin)/([A-Z0-9]{10})~i',
        '~[?&](?:asin|ASIN|keywords_asin)=([A-Z0-9]{10})~',
        '~/([A-Z0-9]{10})(?:[/?&#]|$)~',
    ];
    foreach ($pats as $re) {
        if (preg_match($re, $hay, $m)) return strtoupper($m[1]);
    }
    return '';
}
function amz_clean_url($mkt, $asin) {
    $m = amz_mkt($mkt);
    return 'https://' . $m['domain'] . '/dp/' . rawurlencode(strtoupper((string)$asin));
}
// يرجّع اللينك زي ما المستخدم لاصقه لكن بدون بارامترات تتبع غريبة
function amz_normalize_input($url) {
    $u = trim((string)$url);
    if ($u !== '' && strpos($u, '://') === false) $u = 'https://' . ltrim($u, '/');
    $p = parse_url($u);
    if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) return '';
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return '';
    return $u;
}

// ============================================================================
//  3) أقسام أمازون ونسب العمولة (النسبة اللي أمازون بتدفعها للموقع)
// ============================================================================
function amz_categories() {
    return [
        'games'       => ['🎮 ألعاب رقمية (Amazon Games)', 20],
        'lux_beauty'  => ['💎 تجميل فاخر Luxury Beauty', 10],
        'media'       => ['🎵 موسيقى وفيديو وهاند ميد', 5],
        'books'       => ['📚 كتب ومطبوعات', 4.5],
        'kitchen'     => ['🍳 مطبخ وأدوات منزلية', 4.5],
        'auto'        => ['🚗 سيارات ومستلزماتها', 4.5],
        'fashion'     => ['👗 أزياء وساعات ومجوهرات وأحذية', 4],
        'home'        => ['🏠 أثاث ومنزل وحديقة', 3],
        'pets'        => ['🐾 مستلزمات الحيوانات', 3],
        'sports'      => ['⚽ رياضة وأوتدور', 3],
        'beauty'      => ['💄 تجميل وعناية شخصية', 3],
        'baby'        => ['🍼 مستلزمات الأطفال', 3],
        'tools'       => ['🔧 أدوات ومعدات DIY', 3],
        'office'      => ['🖇️ مكتبيات', 3],
        'grocery'     => ['🛒 بقالة وأطعمة', 3],
        'pc'          => ['💻 كمبيوتر ومكوناته', 2.5],
        'electronics' => ['📱 إلكترونيات وجوالات وتلفزيونات', 2],
        'video_games' => ['🕹️ ألعاب فيديو وأجهزة ألعاب', 2],
        'fresh'       => ['🥦 أمازون فريش وطازج', 1],
        'other'       => ['📦 باقي المنتجات (افتراضي)', 4],
    ];
}
function amz_cat_label($slug) {
    $c = amz_categories();
    return isset($c[$slug]) ? $c[$slug][0] : $c['other'][0];
}
// النسبة الفعلية بعد تعديل الإدارة (الإعداد: amz_rate_<slug>)
function amz_rate($slug) {
    $c = amz_categories();
    $def = isset($c[$slug]) ? (float)$c[$slug][1] : (float)$c['other'][1];
    $v = site_setting('amz_rate_' . preg_replace('/[^a-z0-9_]/i', '', (string)$slug), '');
    if ($v !== '' && is_numeric($v)) return max(0, min(100, (float)$v));
    return $def;
}

// ============================================================================
//  4) أسعار العملات (تحويل عمولة أمازون للجنيه المصري)
// ============================================================================
function amz_fx_defaults() {
    return ['USD' => 52.30, 'SAR' => 13.94, 'AED' => 14.24, 'EGP' => 1, 'GBP' => 70.00,
            'EUR' => 61.00, 'CAD' => 38.00, 'AUD' => 34.00, 'INR' => 0.59, 'JPY' => 0.35,
            'MXN' => 2.80, 'BRL' => 9.60];
}
function amz_fx($cur) {
    $cur = strtoupper(trim((string)$cur));
    if ($cur === '' ) $cur = 'USD';
    if ($cur === 'EGP') return 1.0;
    $d = amz_fx_defaults();
    $v = site_setting('amz_fx_' . preg_replace('/[^A-Z]/', '', $cur), '');
    if ($v !== '' && is_numeric($v) && (float)$v > 0) return (float)$v;
    return isset($d[$cur]) ? (float)$d[$cur] : 1.0;
}
function amz_money($amount, $currency = 'EGP') {
    $sym = ['USD' => '$', 'SAR' => 'ر.س', 'AED' => 'د.إ', 'EGP' => 'ج.م', 'GBP' => '£', 'EUR' => '€',
            'CAD' => 'C$', 'AUD' => 'A$', 'INR' => '₹', 'JPY' => '¥', 'MXN' => 'MX$', 'BRL' => 'R$'];
    $s = isset($sym[$currency]) ? $sym[$currency] : '';
    return number_format((float)$amount, 2) . ($s !== '' ? ' ' . $s : '');
}

// اسم العملة للعرض: الجنيه بيتكتب «ج.م» زي باقي الموقع
function amz_cur_label($cur) {
    $c = strtoupper(trim((string)$cur));
    return $c === 'EGP' ? 'ج.م' : $c;
}

// ============================================================================
//  5) نصيب المسوق من عمولة أمازون (%)
// ============================================================================
function amz_share_default() {
    $v = site_setting('amz_mk_share', '');
    return ($v !== '' && is_numeric($v)) ? max(0, min(100, (float)$v)) : 50.0;
}
function amz_share_for($link) {
    if (is_array($link) && isset($link['share']) && is_numeric($link['share']) && (float)$link['share'] >= 0) {
        return max(0, min(100, (float)$link['share']));
    }
    $ref = is_array($link) ? trim((string)($link['ref'] ?? '')) : '';
    if ($ref !== '' && function_exists('find_marketer_by_code')) {
        $mk = find_marketer_by_code($ref);
        if ($mk && isset($mk['amazon_share']) && $mk['amazon_share'] !== '' && is_numeric($mk['amazon_share'])) {
            return max(0, min(100, (float)$mk['amazon_share']));
        }
    }
    return amz_share_default();
}

// نسبة المسوق من سعر البيع — الإدارة بتحددها لكل رابط عند الموافقة (null = لسه مش محددة)
function amz_mk_pct($link) {
    if (!is_array($link) || !isset($link['mk_pct']) || !is_numeric($link['mk_pct'])) return null;
    return max(0, min(100, (float)$link['mk_pct']));
}
// عرض النسبة بدون أصفار زايدة (10.00 → 10 ، 4.50 → 4.5) — للعرض بس
function amz_pct_txt($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
}
// المبلغ اللي المسوق هياخده على كل بيعة = سعر المنتج (اللي حددته الإدارة) × نسبة المسوق
function amz_per_sale($link) {
    $cur   = strtoupper((string)($link['currency'] ?? amz_currency($link['mkt'] ?? 'com')));
    $pct   = amz_mk_pct($link);
    $price = max(0, (float)($link['price'] ?? 0));
    $fx    = amz_fx($cur);
    $out = ['ok' => false, 'currency' => $cur, 'price' => round($price, 2), 'pct' => $pct, 'amount' => 0.0, 'egp' => 0.0, 'fx' => $fx];
    if ($pct === null || $price <= 0) return $out;
    $out['amount'] = round($price * $pct / 100, 2);
    $out['egp']    = round($out['amount'] * $fx, 2);
    $out['ok']     = true;
    return $out;
}

// ============================================================================
//  6) التاج (Partner Tag): تاج عام للمتجر + تاج خاص لكل مسوق (Tracking ID)
// ============================================================================
function amz_tag($mkt) { return trim((string)site_setting('amz_tag_' . preg_replace('/[^a-z0-9_]/i', '', (string)$mkt), '')); }
function amz_mk_tag($ref, $mkt) {
    $ref = trim((string)$ref);
    if ($ref === '' || !function_exists('find_marketer_by_code')) return amz_tag($mkt);
    $mk = find_marketer_by_code($ref);
    if (!$mk) return amz_tag($mkt);
    $tags = isset($mk['amazon_tags']) && is_array($mk['amazon_tags']) ? $mk['amazon_tags'] : [];
    if (!empty($tags[$mkt])) return trim((string)$tags[$mkt]);
    if (!empty($tags['all']))  return trim((string)$tags['all']);
    return amz_tag($mkt);
}
function amz_tag_for($link) {
    return amz_mk_tag((string)($link['ref'] ?? ''), (string)($link['mkt'] ?? 'com'));
}
// مسوّق من التاج (مطابقة تقرير أمازون)
function amz_ref_by_tag($tag) {
    $tag = strtolower(trim((string)$tag));
    if ($tag === '') return '';
    foreach (users() as $u) {
        $tags = isset($u['amazon_tags']) && is_array($u['amazon_tags']) ? $u['amazon_tags'] : [];
        foreach ($tags as $t) {
            if (strtolower(trim((string)$t)) === $tag && !empty($u['ref_code'])) return (string)$u['ref_code'];
        }
    }
    return '';
}

// ============================================================================
//  7) الروابط: بناء / قراءة / حفظ
// ============================================================================
function amz_out_url($link, $sub = '') {
    $url = amz_clean_url((string)($link['mkt'] ?? 'com'), (string)($link['asin'] ?? ''));
    $q = [];
    $tag = amz_tag_for($link);
    if ($tag !== '') $q['tag'] = $tag;
    $asc = (string)($link['id'] ?? '');
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$sub);
    if ($s !== '') $asc .= '-' . $s;
    if ($asc !== '') $q['ascsubtag'] = substr($asc, 0, 50);
    return $url . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}
function amz_site_base() {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $root = (strpos($script, '/dashboard/') !== false) ? rtrim(dirname(dirname($script)), '/') : rtrim(dirname($script), '/');
    if ($root === '/') $root = '';
    return isset($_SERVER['HTTP_HOST']) ? ($scheme . '://' . $_SERVER['HTTP_HOST'] . $root) : '';
}
function amz_track_url($link_id, $sub = '') {
    $u = amz_site_base() . '/amz.php?a=' . urlencode((string)$link_id);
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$sub);
    if ($s !== '') $u .= '&sub=' . urlencode(substr($s, 0, 30));
    return $u;
}
function amz_links_all() {
    $l = jload(AMZ_LINKS_F, []);
    return is_array($l) ? $l : [];
}
function amz_link($id) {
    $l = amz_links_all();
    return (isset($l[$id]) && is_array($l[$id])) ? $l[$id] : null;
}
function amz_links_for($ref) {
    $ref = trim((string)$ref);
    $out = [];
    foreach (amz_links_all() as $id => $l) {
        if (is_array($l) && (string)($l['ref'] ?? '') === $ref) $out[$id] = $l;
    }
    return $out;
}
function amz_link_save($id, $data) {
    jupdate(AMZ_LINKS_F, function (&$l) use ($id, $data) {
        if (!is_array($l)) $l = [];
        $cur = (isset($l[$id]) && is_array($l[$id])) ? $l[$id] : [];
        foreach ($data as $k => $v) $cur[$k] = $v;
        $cur['updated'] = date('c');
        $l[$id] = $cur;
        return true;
    }, []);
}
function amz_link_delete($id) {
    $ok = jupdate(AMZ_LINKS_F, function (&$l) use ($id) {
        if (!is_array($l)) return false;
        if (!isset($l[$id])) return false;
        unset($l[$id]);
        return true;
    }, []);
    // أي طلب مفتوح على الرابط ده بيتقفل (عشان الإدارة متعتمدش رابط اتمسح)
    jupdate(AMZ_REQS_F, function (&$r) use ($id) {
        if (!is_array($r)) return false;
        $changed = false;
        foreach ($r as $k => $q) {
            if (is_array($q) && (string)($q['link_id'] ?? '') === (string)$id && (string)($q['status'] ?? '') === 'pending') {
                $r[$k]['status'] = 'rejected';
                $r[$k]['admin_note'] = 'المسوق سحب الطلب';
                $changed = true;
            }
        }
        return $changed;
    }, []);
    return $ok;
}

// ============================================================================
//  8) إنشاء رابط جديد من لينك أمازون
// ============================================================================
function amz_create_link($ref, $url, $opts = []) {
    $ref = trim((string)$ref);
    $mk  = function_exists('find_marketer_by_code') ? find_marketer_by_code($ref) : null;
    if ($ref === '' || !$mk) return ['ok' => false, 'error' => 'كود المسوق غير صحيح أو غير موجود'];
    if (($mk['status'] ?? 'approved') === 'suspended') return ['ok' => false, 'error' => 'حسابك موقوف — تواصل مع الإدارة'];

    $url = trim((string)$url);
    if ($url === '') return ['ok' => false, 'error' => 'اكتب أو الصق لينك المنتج من أمازون'];
    if (amz_is_short_link($url)) {
        return ['ok' => false, 'error' => 'اللينك ده مختصر (amzn.to / a.co) — افتحه في المتصفح واستنسخ اللينك الكامل للمنتج'];
    }
    $mkt = amz_detect_marketplace($url);
    if ($mkt === '') {
        return ['ok' => false, 'error' => 'اللينك ده مش من متجر أمازون — استنسخ لينك صفحة المنتج من amazon.com أو amazon.sa أو amazon.ae'];
    }
    $asin = amz_extract_asin($url);
    if ($asin === '') {
        return ['ok' => false, 'error' => 'مش قادر أقرأ رقم المنتج (ASIN) من اللينك — افتح صفحة المنتج واستنسخ اللينك اللي فيه /dp/'];
    }
    $norm = amz_normalize_input($url);
    if ($norm !== '') $url = $norm;

    $cats  = amz_categories();
    $cat   = trim((string)($opts['cat'] ?? ''));
    if (!isset($cats[$cat])) $cat = 'other';
    $price = (isset($opts['price']) && is_numeric($opts['price'])) ? round(max(0, (float)$opts['price']), 2) : 0;
    $title = mb_substr(trim((string)($opts['title'] ?? '')), 0, 180);
    $img   = mb_substr(trim((string)($opts['img'] ?? '')), 0, 400);
    $note  = mb_substr(trim((string)($opts['note'] ?? '')), 0, 300);
    // بيانات «اجلب بيانات المنتج» (اختيارية — بتتنظف هنا عشان متتقبلش أي حاجة من الفورم)
    $desc   = amz_clean_text((string)($opts['desc'] ?? ''), 6000);
    $specs  = amz_clean_specs($opts['specs'] ?? []);
    $images = amz_clean_images($opts['images'] ?? []);
    $videos = amz_clean_videos($opts['videos'] ?? []);

    // نفس المسوق + نفس المنتج + نفس المتجر → مش بنعمل طلب مكرر
    foreach (amz_links_for($ref) as $old_id => $old) {
        if ((string)($old['asin'] ?? '') !== $asin || (string)($old['mkt'] ?? '') !== $mkt) continue;
        $old_st = (string)($old['status'] ?? 'approved');
        if ($old_st === 'approved' || $old_st === 'pending') {
            return ['ok' => true, 'id' => $old_id, 'link' => $old, 'existing' => true, 'status' => $old_st];
        }
        // اترفض قبل كده → بنرفع طلب جديد على نفس الرابط
        amz_link_save($old_id, [
            'url_orig' => mb_substr($url, 0, 500),
            'title'    => $title,
            'img'      => $img !== '' ? $img : (string)($old['img'] ?? ''),
            'cat'      => $cat,
            'price'    => $price,
            'note'     => $note,
            'desc'     => $desc !== '' ? $desc : (string)($old['desc'] ?? ''),
            'specs'    => $specs ? $specs : (is_array($old['specs'] ?? null) ? $old['specs'] : []),
            'images'   => $images ? $images : (is_array($old['images'] ?? null) ? $old['images'] : []),
            'videos'   => $videos ? $videos : amz_media_list($old, 'vid'),
            'status'   => 'pending',
        ]);
        amz_req_create('approval', $ref, $old_id, ['asin' => $asin, 'title' => $title, 'cat' => $cat, 'price' => $price, 'note' => $note]);
        return ['ok' => true, 'id' => $old_id, 'link' => amz_link($old_id) ?: $old, 'existing' => false, 'status' => 'pending', 'resubmitted' => true];
    }

    $id = 'az' . substr(md5($ref . '|' . $asin . '|' . $mkt . '|' . microtime(true) . '|' . mt_rand()), 0, 10);
    $link = [
        'id'        => $id,
        'ref'       => $ref,
        'email'     => (string)($mk['email'] ?? ''),
        'mkt'       => $mkt,
        'asin'      => $asin,
        'currency'  => amz_currency($mkt),
        'url_orig'  => mb_substr($url, 0, 500),
        'url_clean' => amz_clean_url($mkt, $asin),
        'title'     => $title,
        'img'       => $img,
        'cat'       => $cat,
        'rate'      => amz_rate($cat),
        'price'     => $price,                // سعر مبدئي من المسوق — الإدارة بتحدد السعر النهائي عند الموافقة
        'note'      => $note,
        'desc'      => $desc,
        'specs'     => $specs,
        'images'    => $images,
        'videos'    => $videos,
        'status'    => 'pending',             // ⏳ مش بيظهر للمسوق أي رابط أو ربح لحد موافقة الإدارة
        'clicks'    => 0,
        'uniq'      => 0,
        'orders'    => 0,
        'qty'       => 0,
        'gross'     => 0,
        'site_earn' => 0,
        'mk_earn'   => 0,
        'at'        => date('c'),
        'updated'   => date('c'),
        'no_tag'    => (amz_mk_tag($ref, $mkt) === '') ? 1 : 0,
    ];
    amz_link_save($id, $link);
    amz_req_create('approval', $ref, $id, ['asin' => $asin, 'title' => $title, 'cat' => $cat, 'price' => $price, 'note' => $note]);
    return ['ok' => true, 'id' => $id, 'link' => $link, 'existing' => false, 'status' => 'pending'];
}

// ============================================================================
//  9) طلبات المسوقين للإدارة (موافقة / زيادة نسبة)
// ============================================================================
function amz_reqs_all() {
    $r = jload(AMZ_REQS_F, []);
    return is_array($r) ? $r : [];
}
function amz_req_create($type, $ref, $link_id, $data = []) {
    $id = 'azr' . substr(md5(uniqid('', true) . $ref . $link_id), 0, 10);
    $req = [
        'id'         => $id,
        'type'       => (string)$type,                 // approval = طلب منتج | pct = تظلم على نسبة المسوق (طلب زيادتها)
        'ref'        => trim((string)$ref),
        'link_id'    => (string)$link_id,
        'asin'       => (string)($data['asin'] ?? ''),
        'title'      => mb_substr((string)($data['title'] ?? ''), 0, 180),
        'cat'        => (string)($data['cat'] ?? ''),
        'price_hint' => (isset($data['price']) && is_numeric($data['price'])) ? round(max(0, (float)$data['price']), 2) : 0,
        'note'       => mb_substr((string)($data['note'] ?? ''), 0, 400),
        'cur_pct'    => isset($data['cur_pct']) ? round((float)$data['cur_pct'], 2) : null,  // تظلم النسبة: نسبته وقت التقديم
        'status'     => 'pending',                     // pending | approved | rejected
        'admin_note' => '',
        'at'         => date('c'),
    ];
    jupdate(AMZ_REQS_F, function (&$r) use ($id, $req) {
        if (!is_array($r)) $r = [];
        $r[$id] = $req;
        return true;
    }, []);
    if (function_exists('notify')) {
        $mk = find_marketer_by_code($req['ref']);
        $nm = $mk ? ($mk['name'] ?? $req['ref']) : $req['ref'];
        $line = '📨 طلب منتج من أمازون — ' . $nm . ' (' . $req['ref'] . ')'
              . "\n" . ($req['title'] !== '' ? $req['title'] : ('ASIN: ' . $req['asin']))
              . ($req['price_hint'] > 0 ? "\nالسعر اللي كتبه المسوق: " . $req['price_hint'] : '')
              . ($req['note'] !== '' ? "\nملاحظة: " . mb_substr($req['note'], 0, 120) : '');
        if ($req['type'] === 'pct') {
            $line = '📈 تظلم على نسبة المسوق — ' . $nm . ' (' . $req['ref'] . ')'
                  . "\n" . ($req['title'] !== '' ? $req['title'] : ('ASIN: ' . $req['asin']))
                  . "\nالنسبة الحالية: " . amz_pct_txt($req['cur_pct']) . '%'
                  . ($req['note'] !== '' ? "\nالسبب: " . mb_substr($req['note'], 0, 160) : '');
        }
        notify('admin', $line, 'dashboard/admin.php?tab=amazon&sec=requests');
    }
    return $id;
}
function amz_req_update($id, $data) {
    jupdate(AMZ_REQS_F, function (&$r) use ($id, $data) {
        if (!is_array($r) || !isset($r[$id])) return false;
        foreach ($data as $k => $v) $r[$id][$k] = $v;
        return true;
    }, []);
}

// ============================================================================
//  9-ب) موافقة الإدارة على طلبات المنتجات
//  الإدارة بتحدد: Tracking ID (تاج المسوق لأمازون) + سعر البيع + نسبة المسوق + القسم
//  بعد الموافقة بس الرابط بيظهر للمسوق في «الروابط المعتمدة»
// ============================================================================
function amz_set_marketer_tag($email, $mkt, $tag) {
    update_user((string)$email, function (&$u) use ($mkt, $tag) {
        $tags = isset($u['amazon_tags']) && is_array($u['amazon_tags']) ? $u['amazon_tags'] : [];
        $tags[(string)$mkt] = (string)$tag;
        $u['amazon_tags'] = $tags;
    });
}
function amz_approve_request($rid, $in = []) {
    $reqs = amz_reqs_all();
    if (!isset($reqs[$rid]) || !is_array($reqs[$rid])) return ['ok' => false, 'error' => 'الطلب ده مش موجود'];
    $r = $reqs[$rid];
    if ((string)($r['status'] ?? '') !== 'pending') return ['ok' => false, 'error' => 'الطلب ده اتعالج قبل كده'];
    $lid = (string)($r['link_id'] ?? '');
    $l   = $lid !== '' ? amz_link($lid) : null;
    if (!$l) {
        amz_req_update($rid, ['status' => 'rejected', 'admin_note' => 'المسوق سحب الطلب قبل المراجعة']);
        return ['ok' => false, 'error' => 'المسوق سحب الطلب ده قبل المراجعة — اتقفل تلقائياً'];
    }
    $price = (isset($in['price']) && is_numeric($in['price'])) ? round(max(0, (float)$in['price']), 2) : 0;
    $pct   = (isset($in['mk_pct']) && is_numeric($in['mk_pct'])) ? max(0, min(100, (float)$in['mk_pct'])) : null;
    if ($price <= 0) return ['ok' => false, 'error' => 'لازم تحدد سعر المنتج اللي هيتباع بيه'];
    if ($pct === null || $pct <= 0) return ['ok' => false, 'error' => 'لازم تحدد نسبة عمولة المسوق (أكبر من صفر)'];

    $cats = amz_categories();
    $cat  = (string)($in['cat'] ?? ($l['cat'] ?? 'other'));
    if (!isset($cats[$cat])) $cat = isset($cats[(string)($l['cat'] ?? '')]) ? (string)$l['cat'] : 'other';
    $ref  = (string)($l['ref'] ?? '');
    $mkt  = (string)($l['mkt'] ?? 'com');
    $mk   = find_marketer_by_code($ref);
    $tag  = mb_substr(trim((string)($in['tag'] ?? '')), 0, 60);
    if ($tag !== '' && $mk && !empty($mk['email'])) amz_set_marketer_tag((string)$mk['email'], $mkt, $tag);

    $upd = [
        'status'      => 'approved',
        'price'       => $price,
        'mk_pct'      => $pct,
        'cat'         => $cat,
        'rate'        => amz_rate($cat),
        'approved_at' => date('c'),
        'no_tag'      => (amz_mk_tag($ref, $mkt) === '') ? 1 : 0,
    ];
    amz_link_save($lid, $upd);
    $note = mb_substr(trim((string)($in['admin_note'] ?? '')), 0, 300);
    amz_req_update($rid, ['status' => 'approved', 'admin_note' => $note, 'approved_at' => date('c')]);

    $after = amz_link($lid) ?: $l;
    $ps = amz_per_sale($after);
    if ($mk && !empty($mk['email'])) {
        notify_user((string)$mk['email'], '✅ اتوافق على منتجك: ' . ($after['title'] !== '' ? $after['title'] : (string)$after['asin'])
            . ' — رابط التسويق بقى في «الروابط المعتمدة»'
            . ($ps['ok'] ? ' — هتاخد ' . number_format($ps['amount'], 2) . ' ' . amz_cur_label($ps['currency']) . ' على كل بيعة' : ''),
            'dashboard/amazon.php?tab=links');
    }
    log_audit('amazon_req_approve', $rid, ['link' => $lid, 'ref' => $ref, 'price' => $price, 'mk_pct' => $pct, 'tag' => $tag !== '']);
    return ['ok' => true, 'no_tag' => !empty($upd['no_tag']), 'per_sale' => $ps];
}
function amz_reject_request($rid, $note = '') {
    $reqs = amz_reqs_all();
    if (!isset($reqs[$rid]) || !is_array($reqs[$rid])) return ['ok' => false, 'error' => 'الطلب ده مش موجود'];
    $r = $reqs[$rid];
    if ((string)($r['status'] ?? '') !== 'pending') return ['ok' => false, 'error' => 'الطلب ده اتعالج قبل كده'];
    $note = mb_substr(trim((string)$note), 0, 300);
    amz_req_update($rid, ['status' => 'rejected', 'admin_note' => $note]);
    $lid = (string)($r['link_id'] ?? '');
    if ($lid !== '' && amz_link($lid)) amz_link_save($lid, ['status' => 'rejected']);
    $mk = find_marketer_by_code((string)($r['ref'] ?? ''));
    if ($mk && !empty($mk['email'])) {
        notify_user((string)$mk['email'], 'ℹ️ الإدارة مرجعتش طلب المنتج ده' . ($note !== '' ? ' — ' . $note : ''), 'dashboard/amazon.php?tab=links');
    }
    log_audit('amazon_req_reject', $rid, ['ref' => $r['ref'] ?? '']);
    return ['ok' => true];
}
// تعديل الإدارة لرابط: السعر + نسبة المسوق + القسم + Tracking ID + الحالة
function amz_admin_link_save($lid, $in = []) {
    $l = amz_link($lid);
    if (!$l) return ['ok' => false, 'error' => 'الرابط ده مش موجود'];
    $cats = amz_categories();
    $cat  = (string)($in['cat'] ?? ($l['cat'] ?? 'other'));
    if (!isset($cats[$cat])) $cat = isset($cats[(string)($l['cat'] ?? '')]) ? (string)$l['cat'] : 'other';
    $rate = (isset($in['rate']) && trim((string)$in['rate']) !== '' && is_numeric($in['rate'])) ? max(0, min(100, (float)$in['rate'])) : amz_rate($cat);
    $price = (isset($in['price']) && trim((string)$in['price']) !== '' && is_numeric($in['price'])) ? round(max(0, (float)$in['price']), 2) : (float)($l['price'] ?? 0);
    $pct  = (isset($in['mk_pct']) && trim((string)$in['mk_pct']) !== '' && is_numeric($in['mk_pct'])) ? max(0, min(100, (float)$in['mk_pct'])) : amz_mk_pct($l);
    $st   = (string)($in['status'] ?? ($l['status'] ?? 'approved'));
    if (!in_array($st, ['approved', 'pending', 'rejected'], true)) $st = (string)($l['status'] ?? 'approved');
    if ($st === 'approved' && ($price <= 0 || $pct === null || $pct <= 0)) {
        return ['ok' => false, 'error' => 'عشان الرابط يتعتمد لازم يكون ليه سعر ونسبة مسوق أكبر من صفر'];
    }
    $ref = (string)($l['ref'] ?? '');
    $mkt = (string)($l['mkt'] ?? 'com');
    $mk  = find_marketer_by_code($ref);
    $tag = mb_substr(trim((string)($in['tag'] ?? '')), 0, 60);
    if ($tag !== '' && $mk && !empty($mk['email'])) amz_set_marketer_tag((string)$mk['email'], $mkt, $tag);

    $upd = ['cat' => $cat, 'rate' => $rate, 'price' => $price, 'mk_pct' => $pct, 'status' => $st,
            'no_tag' => (amz_mk_tag($ref, $mkt) === '') ? 1 : 0];
    if ($st === 'approved' && empty($l['approved_at'])) $upd['approved_at'] = date('c');
    amz_link_save($lid, $upd);
    if ((string)($l['status'] ?? 'approved') !== $st && $mk && !empty($mk['email'])) {
        $msg = $st === 'approved' ? '✅ الرابط بتاعك اتفعّل — ظهر في «الروابط المعتمدة»'
             : ($st === 'rejected' ? '⛔ الإدارة وقفت الرابط ده' : '⏳ الرابط رجع لمراجعة الإدارة');
        notify_user((string)$mk['email'], $msg, 'dashboard/amazon.php?tab=links');
    }
    log_audit('amazon_link_edit', $lid, ['cat' => $cat, 'rate' => $rate, 'price' => $price, 'mk_pct' => $pct, 'status' => $st]);
    return ['ok' => true, 'no_tag' => !empty($upd['no_tag'])];
}

// ============================================================================
//  9-ج) تظلم المسوق على النسبة (طلب زيادة نسبة المسوق على رابط معتمد)
//  المسوق بيطلب نسبة أعلى من اللي الإدارة حددتها على الرابط، والإدارة توافق (وتحدد النسبة) أو ترفض.
//  الرفض مابيقفلش الرابط، وأي تغيير في النسبة بيسري على البيعات الجاية بس
//  (عمولة كل بيعة بتتحسب وقت تسجيلها في amz_add_conversion).
// ============================================================================
function amz_pct_open_for_link($link_id) {
    foreach (amz_reqs_all() as $r) {
        if (is_array($r) && (string)($r['type'] ?? '') === 'pct' && (string)($r['status'] ?? '') === 'pending' && (string)($r['link_id'] ?? '') === (string)$link_id) return $r;
    }
    return null;
}
// تظلم المسوق على نسبته: بيتقدم بسبب التظلم بس — الإدارة هي اللي بتحدد النسبة الجديدة وقت الموافقة
function amz_pct_appeal_create($ref, $link_id, $reason) {
    $ref     = trim((string)$ref);
    $link_id = (string)$link_id;
    $l       = $link_id !== '' ? amz_link($link_id) : null;
    if (!$l || (string)($l['ref'] ?? '') !== $ref) return ['ok' => false, 'error' => 'الرابط ده مش موجود أو مش بتاعك'];
    if ((string)($l['status'] ?? '') !== 'approved') return ['ok' => false, 'error' => 'التظلم على النسبة بيتقدم على الروابط المعتمدة بس'];
    $cur = amz_mk_pct($l);
    if ($cur === null || $cur <= 0) return ['ok' => false, 'error' => 'الإدارة لسه ماحددتش نسبتك على الرابط ده'];
    if ($cur >= 100) return ['ok' => false, 'error' => 'وصلت للحد الأقصى (100%)'];
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 5) return ['ok' => false, 'error' => 'اكتب سبب التظلم (5 حروف على الأقل)'];
    if (amz_pct_open_for_link($link_id)) return ['ok' => false, 'error' => 'عندك تظلم بانتظار الإدارة على الرابط ده بالفعل'];
    $id = amz_req_create('pct', $ref, $link_id, [
        'title' => (string)($l['title'] ?? ''), 'asin' => (string)($l['asin'] ?? ''), 'cat' => (string)($l['cat'] ?? ''),
        'note'  => $reason, 'cur_pct' => $cur,
    ]);
    log_audit('amazon_pct_appeal', $id, ['link' => $link_id, 'ref' => $ref, 'from' => $cur]);
    return ['ok' => true, 'id' => $id];
}
// موافقة الإدارة على التظلم: بتحدد النسبة الجديدة وتتطبق على الرابط
function amz_approve_pct_request($rid, $in = []) {
    $reqs = amz_reqs_all();
    if (!isset($reqs[$rid]) || !is_array($reqs[$rid]) || (string)($reqs[$rid]['type'] ?? '') !== 'pct') return ['ok' => false, 'error' => 'التظلم ده مش موجود'];
    $r = $reqs[$rid];
    if ((string)($r['status'] ?? '') !== 'pending') return ['ok' => false, 'error' => 'التظلم ده اتعالج قبل كده'];
    $lid = (string)($r['link_id'] ?? '');
    $l   = $lid !== '' ? amz_link($lid) : null;
    if (!$l || (string)($l['status'] ?? '') !== 'approved') {
        amz_req_update($rid, ['status' => 'rejected', 'admin_note' => 'الرابط اتشال أو مبقاش معتمد قبل المراجعة']);
        return ['ok' => false, 'error' => 'الرابط ده اتشال أو مبقاش معتمد — التظلم اتقفل تلقائياً'];
    }
    $new = (isset($in['mk_pct']) && trim((string)$in['mk_pct']) !== '' && is_numeric($in['mk_pct']))
         ? round((float)$in['mk_pct'], 2) : 0.0;
    if ($new <= 0 || $new > 100) return ['ok' => false, 'error' => 'النسبة الجديدة لازم تكون أكبر من صفر ومش أكتر من 100'];
    $from = amz_mk_pct($l);
    $note = mb_substr(trim((string)($in['admin_note'] ?? '')), 0, 300);
    amz_link_save($lid, ['mk_pct' => $new]);
    amz_req_update($rid, ['status' => 'approved', 'admin_note' => $note, 'approved_at' => date('c'), 'applied_pct' => $new]);
    $after = amz_link($lid) ?: $l;
    $ps    = amz_per_sale($after);
    $title = (string)($after['title'] ?? '') !== '' ? (string)$after['title'] : (string)($after['asin'] ?? '');
    $mk    = find_marketer_by_code((string)($r['ref'] ?? ''));
    if ($mk && !empty($mk['email'])) {
        notify_user((string)$mk['email'], '✅ الإدارة وافقت على تظلمك على ' . $title
            . ($ps['ok'] ? ' — هتاخد ' . number_format((float)$ps['amount'], 2) . ' ' . amz_cur_label((string)$ps['currency']) . ' على كل بيعة جاية' : ''),
            'dashboard/amazon.php?tab=links');
    }
    log_audit('amazon_pct_approve', $rid, ['link' => $lid, 'ref' => $r['ref'] ?? '', 'from' => $from, 'to' => $new]);
    return ['ok' => true, 'from' => $from, 'to' => $new, 'per_sale' => $ps];
}
// رفض التظلم: الرابط نفسه مابيتقفلش ونسبته بتفضل زي ما هي
function amz_reject_pct_request($rid, $note = '') {
    $reqs = amz_reqs_all();
    if (!isset($reqs[$rid]) || !is_array($reqs[$rid]) || (string)($reqs[$rid]['type'] ?? '') !== 'pct') return ['ok' => false, 'error' => 'التظلم ده مش موجود'];
    $r = $reqs[$rid];
    if ((string)($r['status'] ?? '') !== 'pending') return ['ok' => false, 'error' => 'التظلم ده اتعالج قبل كده'];
    $note = mb_substr(trim((string)$note), 0, 300);
    amz_req_update($rid, ['status' => 'rejected', 'admin_note' => $note]);
    $lid = (string)($r['link_id'] ?? '');
    $l   = $lid !== '' ? amz_link($lid) : null;
    $title = $l ? ((string)($l['title'] ?? '') !== '' ? (string)$l['title'] : (string)($l['asin'] ?? '')) : (string)($r['asin'] ?? '');
    $mk = find_marketer_by_code((string)($r['ref'] ?? ''));
    if ($mk && !empty($mk['email'])) {
        notify_user((string)$mk['email'], 'ℹ️ الإدارة مرجعتش تظلمك على ' . $title . ($note !== '' ? ' — ' . $note : ''), 'dashboard/amazon.php?tab=links');
    }
    log_audit('amazon_pct_reject', $rid, ['ref' => $r['ref'] ?? '', 'link' => $lid]);
    return ['ok' => true];
}

// ============================================================================
//  10) النقرات
// ============================================================================
function amz_record_click($link_id, $sub = '') {
    $link = amz_link($link_id);
    if (!$link) return false;
    if (($link['status'] ?? 'approved') !== 'approved') return false;
    $vid = function_exists('visitor_id') ? visitor_id() : session_id();
    $ip  = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ck  = 'amzc_' . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$link_id);
    $already = !empty($_COOKIE[$ck]);
    if (!$already && !headers_sent()) {
        @setcookie($ck, '1', time() + 86400, '/', '', false, true);
    }
    $now = date('c');
    jupdate(AMZ_LINKS_F, function (&$l) use ($link_id, $already, $now) {
        if (!isset($l[$link_id]) || !is_array($l[$link_id])) return false;
        $l[$link_id]['clicks'] = (int)($l[$link_id]['clicks'] ?? 0) + 1;
        if (!$already) $l[$link_id]['uniq'] = (int)($l[$link_id]['uniq'] ?? 0) + 1;
        $l[$link_id]['last_click'] = $now;
        return true;
    }, []);
    jupdate(AMZ_CLICKS_F, function (&$c) use ($link_id, $link, $sub, $vid, $ip, $already, $now) {
        if (!is_array($c)) $c = [];
        $c[] = [
            'id'      => $link_id,
            'ref'     => (string)($link['ref'] ?? ''),
            'mkt'     => (string)($link['mkt'] ?? ''),
            'asin'    => (string)($link['asin'] ?? ''),
            'sub'     => substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$sub), 0, 30),
            'vid'     => substr((string)$vid, 0, 40),
            'ip'      => $ip,
            'ua'      => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            'from'    => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 200),
            'uniq'    => $already ? 0 : 1,
            'at'      => $now,
        ];
        if (count($c) > 20000) $c = array_slice($c, -10000);
        return true;
    }, []);
    return true;
}
function amz_clicks_for($ref, $limit = 500) {
    $ref = trim((string)$ref);
    $all = jload(AMZ_CLICKS_F, []);
    if (!is_array($all)) return [];
    $rows = array_values(array_filter($all, function ($c) use ($ref) { return (string)($c['ref'] ?? '') === $ref; }));
    return array_slice(array_reverse($rows), 0, $limit);
}

// ============================================================================
//  11) حساب العمولة (أمازون → الموقع → المسوق)
// ============================================================================
function amz_calc($link, $value, $qty = 1, $comm_override = null, $mk_egp_override = null) {
    $value = max(0, (float)$value);
    $qty   = max(1, (int)$qty);
    $cur   = strtoupper((string)($link['currency'] ?? amz_currency($link['mkt'] ?? 'com')));
    $rate  = (isset($link['rate']) && is_numeric($link['rate'])) ? max(0, min(100, (float)$link['rate'])) : amz_rate($link['cat'] ?? 'other');
    $gross = round($value * $qty, 2);
    // عمولة أمازون اللي الإدارة كتبتها = نسبة % من إجمالي البيع (مش مبلغ). مثال: 5 على 1479 = 73.95
    $amz_comm = ($comm_override !== null && is_numeric($comm_override) && (float)$comm_override >= 0)
        ? round($gross * (float)$comm_override / 100, 2)
        : round($gross * $rate / 100, 2);
    $eff_rate = ($gross > 0) ? round($amz_comm / $gross * 100, 3) : $rate;
    $fx = amz_fx($cur);
    $pct = amz_mk_pct($link);
    if ($pct !== null) {
        // نظام الإدارة: نسبة المسوق من سعر البيع (مثال: 10% من 300 = 30)
        $mk_amt = round($gross * $pct / 100, 2);
        $share  = ($amz_comm > 0) ? round(min(100, $mk_amt / $amz_comm * 100), 2) : 0;
    } else {
        // الروابط القديمة (قبل نظام نسبة السعر): نسبة المسوق من عمولة أمازون
        $share  = amz_share_for($link);
        $mk_amt = round($amz_comm * $share / 100, 2);
    }
    $mk_egp = round($mk_amt * $fx, 2);
    if ($mk_egp_override !== null && is_numeric($mk_egp_override) && (float)$mk_egp_override >= 0) {
        // الإدارة كتبت عمولة المسوق بالجنيه يدوياً (بتغلب على الحساب)
        $mk_egp = round((float)$mk_egp_override, 2);
        $mk_amt = ($fx > 0) ? round($mk_egp / $fx, 2) : $mk_egp;
    }
    $amz_egp  = round($amz_comm * $fx, 2);
    $site_egp = round($amz_egp - $mk_egp, 2);
    $site_amt = round($amz_comm - $mk_amt, 2);
    return [
        'currency' => $cur, 'fx' => $fx, 'qty' => $qty, 'value' => $value, 'gross' => $gross,
        'rate' => $rate, 'eff_rate' => $eff_rate, 'share' => $share, 'mk_pct' => $pct,
        'amz_comm' => $amz_comm, 'mk_amt' => $mk_amt, 'site_amt' => $site_amt,
        'mk_egp' => $mk_egp, 'site_egp' => $site_egp, 'amz_egp' => $amz_egp,
    ];
}

// ============================================================================
//  12) التحويلات (البيعات) — العمولة للموقع + للمسوق
// ============================================================================
function amz_orders_all() {
    $o = jload(AMZ_ORDERS_F, []);
    return is_array($o) ? $o : [];
}
function amz_orders_for($ref) {
    $ref = trim((string)$ref);
    $out = [];
    foreach (amz_orders_all() as $o) {
        if (is_array($o) && (string)($o['ref'] ?? '') === $ref) $out[] = $o;
    }
    return $out;
}
function amz_order($cid) {
    foreach (amz_orders_all() as $o) {
        if (is_array($o) && (string)($o['id'] ?? '') === (string)$cid) return $o;
    }
    return null;
}
function amz_add_conversion($link_id, $value, $qty = 1, $opt = []) {
    $link = amz_link($link_id);
    if (!$link) return ['ok' => false, 'error' => 'الرابط غير موجود'];
    $override    = (isset($opt['amz_comm']) && is_numeric($opt['amz_comm'])) ? (float)$opt['amz_comm'] : null;
    if ($override !== null && ($override < 0 || $override > 100)) {
        return ['ok' => false, 'error' => 'عمولة أمازون بالنسبة % لازم تكون من 0 لـ 100 (اكتب 5 لو 5%، مش المبلغ بالجنيه)'];
    }
    $mk_override = (isset($opt['mk_egp']) && is_numeric($opt['mk_egp']) && (float)$opt['mk_egp'] >= 0) ? (float)$opt['mk_egp'] : null;
    $calc = amz_calc($link, $value, $qty, $override, $mk_override);
    if ($calc['amz_comm'] <= 0 && $calc['gross'] <= 0 && $mk_override === null) {
        return ['ok' => false, 'error' => 'المبلغ أو العمولة لازم تكون أكبر من صفر'];
    }
    $status = ((string)($opt['status'] ?? 'pending') === 'confirmed') ? 'confirmed' : 'pending';
    $ref  = (string)($link['ref'] ?? '');
    $mk   = $ref !== '' ? find_marketer_by_code($ref) : null;
    $email= $mk ? (string)($mk['email'] ?? '') : (string)($link['email'] ?? '');
    $cid  = 'azc' . substr(md5(uniqid('', true) . $link_id . microtime(true)), 0, 10);
    $title = (string)($link['title'] ?? '');
    $conv = [
        'id'        => $cid,
        'link_id'   => (string)$link_id,
        'ref'       => $ref,
        'email'     => $email,
        'mkt'       => (string)($link['mkt'] ?? ''),
        'asin'      => (string)($link['asin'] ?? ''),
        'title'     => $title,
        'cat'       => (string)($link['cat'] ?? 'other'),
        'qty'       => (int)$calc['qty'],
        'value'     => (float)$calc['value'],
        'currency'  => $calc['currency'],
        'fx'        => $calc['fx'],
        'gross'     => $calc['gross'],
        'rate'      => $calc['eff_rate'],
        'share'     => $calc['share'],
        'mk_pct'    => $calc['mk_pct'],
        'amz_comm'  => $calc['amz_comm'],
        'mk_amt'    => $calc['mk_amt'],
        'site_amt'  => $calc['site_amt'],
        'mk_egp'    => $calc['mk_egp'],
        'site_egp'  => $calc['site_egp'],
        'source'    => (string)($opt['source'] ?? 'manual'),
        'ext_key'   => (string)($opt['ext_key'] ?? ''),
        'note'      => mb_substr((string)($opt['note'] ?? ''), 0, 250),
        'status'    => $status,                       // pending | confirmed | reversed
        'at'        => (string)($opt['at'] ?? date('c')),
    ];
    jupdate(AMZ_ORDERS_F, function (&$o) use ($conv) {
        if (!is_array($o)) $o = [];
        $o[] = $conv;
        if (count($o) > 20000) $o = array_slice($o, -10000);
        return true;
    }, []);
    jupdate(AMZ_LINKS_F, function (&$l) use ($link_id, $conv) {
        if (!isset($l[$link_id]) || !is_array($l[$link_id])) return false;
        $l[$link_id]['orders']    = (int)($l[$link_id]['orders'] ?? 0) + 1;
        $l[$link_id]['qty']       = (int)($l[$link_id]['qty'] ?? 0) + (int)$conv['qty'];
        $l[$link_id]['gross']     = round((float)($l[$link_id]['gross'] ?? 0) + (float)$conv['gross'], 2);
        $l[$link_id]['mk_earn']   = round((float)($l[$link_id]['mk_earn'] ?? 0) + (float)$conv['mk_egp'], 2);
        $l[$link_id]['site_earn'] = round((float)($l[$link_id]['site_earn'] ?? 0) + (float)$conv['site_egp'], 2);
        $l[$link_id]['updated']   = date('c');
        return true;
    }, []);
    if ($email !== '' && ($conv['mk_egp'] > 0)) {
        $label = '🛍️ عمولة أمازون: ' . ($conv['title'] !== '' ? $conv['title'] : $conv['asin']);
        commission_credit_pending($email, $conv['mk_egp'], null, $label);
        if ($status === 'confirmed') {
            commission_release($email, $conv['mk_egp'], null, 'تأكيد عمولة أمازون (' . $conv['asin'] . ')');
        } elseif (function_exists('notify_user')) {
            notify_user($email, '🛍️ مبيعة جديدة من لينك أمازون (' . $conv['asin'] . ') — عمولتك ' . number_format($conv['mk_egp'], 2) . ' ج.م (معلقة لحين تأكيد الإدارة)', 'dashboard/amazon.php?tab=conversions');
        }
    }
    return ['ok' => true, 'id' => $cid, 'conv' => $conv];
}
function amz_confirm_conversion($cid) {
    $found = null;
    jupdate(AMZ_ORDERS_F, function (&$o) use ($cid, &$found) {
        if (!is_array($o)) return false;
        foreach ($o as $i => $c) {
            if (is_array($c) && (string)($c['id'] ?? '') === (string)$cid && (string)($c['status'] ?? '') === 'pending') {
                $o[$i]['status'] = 'confirmed';
                $o[$i]['confirmed_at'] = date('c');
                $found = $o[$i];
                return true;
            }
        }
        return false;
    }, []);
    if ($found && !empty($found['email']) && (float)($found['mk_egp'] ?? 0) > 0) {
        commission_release((string)$found['email'], (float)$found['mk_egp'], null, 'تأكيد عمولة أمازون (' . ($found['asin'] ?? '') . ')');
    }
    return $found;
}
function amz_reverse_conversion($cid, $reason = 'إلغاء/إرجاع من أمازون') {
    $found = null;
    $prev  = '';
    jupdate(AMZ_ORDERS_F, function (&$o) use ($cid, $reason, &$found, &$prev) {
        if (!is_array($o)) return false;
        foreach ($o as $i => $c) {
            if (is_array($c) && (string)($c['id'] ?? '') === (string)$cid && (string)($c['status'] ?? '') !== 'reversed') {
                $prev = (string)$c['status'];
                $o[$i]['status'] = 'reversed';
                $o[$i]['reversed_at'] = date('c');
                $o[$i]['note'] = mb_substr((string)$reason, 0, 200);
                $found = $o[$i];
                return true;
            }
        }
        return false;
    }, []);
    if ($found) {
        $mk_egp = round((float)($found['mk_egp'] ?? 0), 2);
        if (!empty($found['email']) && $mk_egp > 0) {
            // المعلقة بتتخصم من المعلق، والمؤكدة بتتخصم من الرصيد المتاح
            $state = ($prev === 'confirmed') ? 'confirmed' : 'pending';
            amz_adjust_balance((string)$found['email'], -$mk_egp, $state, 'إلغاء عمولة أمازون (' . ($found['asin'] ?? '') . ')');
            notify_user((string)$found['email'], '↩️ اتلغت عمولة بيعة (' . ($found['asin'] ?? '') . ') — ' . number_format($mk_egp, 2) . ' ج.م', 'dashboard/amazon.php?tab=conversions');
        }
        jupdate(AMZ_LINKS_F, function (&$l) use ($found) {
            $lid = (string)($found['link_id'] ?? '');
            if (!isset($l[$lid]) || !is_array($l[$lid])) return false;
            $l[$lid]['orders']    = max(0, (int)($l[$lid]['orders'] ?? 0) - 1);
            $l[$lid]['qty']       = max(0, (int)($l[$lid]['qty'] ?? 0) - (int)($found['qty'] ?? 0));
            $l[$lid]['gross']     = max(0, round((float)($l[$lid]['gross'] ?? 0) - (float)($found['gross'] ?? 0), 2));
            $l[$lid]['mk_earn']   = max(0, round((float)($l[$lid]['mk_earn'] ?? 0) - (float)($found['mk_egp'] ?? 0), 2));
            $l[$lid]['site_earn'] = max(0, round((float)($l[$lid]['site_earn'] ?? 0) - (float)($found['site_egp'] ?? 0), 2));
            return true;
        }, []);
    }
    return $found;
}

// ============================================================================
//  12-ب) أرصدة المسوق + تعديل الإدارة للأرباح (معلقة / مؤكدة) + أرقام الكروت
// ============================================================================
// يعدّل رصيد المسوق بالفرق بس: معلّق (pending_balance) أو متاح للسحب (balance)
function amz_adjust_balance($email, $delta, $state = 'pending', $reason = '') {
    $delta = round((float)$delta, 2);
    if ($delta == 0 || (string)$email === '') return false;
    $state = ($state === 'confirmed') ? 'confirmed' : 'pending';
    update_user((string)$email, function (&$u) use ($delta, $state) {
        if ($state === 'pending') {
            $u['pending_balance'] = round(max(0, (float)($u['pending_balance'] ?? 0) + $delta), 2);
        } else {
            $u['balance'] = round(max(0, (float)($u['balance'] ?? 0) + $delta), 2);
        }
    });
    balance_ledger_add((string)$email, 'adjust', $delta, $reason !== '' ? $reason : 'تعديل عمولة أمازون', ['state' => $state]);
    return true;
}
// تعديل الإدارة لأرباح المسوق على بيعة (معلقة أو مؤكدة): الرابط والرصيد بيتعدلوا بالفرق بس
function amz_set_conv_earn($cid, $new_mk_egp, $reason = 'تعديل الإدارة للعمولة') {
    $cur = amz_order($cid);
    if (!$cur || (string)($cur['status'] ?? '') === 'reversed') return ['ok' => false, 'error' => 'البيعة دي مش موجودة أو اتلغت'];
    $new = round(max(0, (float)$new_mk_egp), 2);
    $old = round((float)($cur['mk_egp'] ?? 0), 2);
    $fx  = (float)($cur['fx'] ?? 0);
    if ($fx <= 0) $fx = amz_fx((string)($cur['currency'] ?? 'USD'));
    $amz_egp  = round((float)($cur['amz_comm'] ?? 0) * $fx, 2);
    $new_site = round($amz_egp - $new, 2);
    $delta      = round($new - $old, 2);
    $site_delta = round($new_site - (float)($cur['site_egp'] ?? 0), 2);
    $lid   = (string)($cur['link_id'] ?? '');
    $found = null;
    jupdate(AMZ_ORDERS_F, function (&$o) use ($cid, $new, $new_site, $fx, &$found) {
        if (!is_array($o)) return false;
        foreach ($o as $i => $c) {
            if (is_array($c) && (string)($c['id'] ?? '') === (string)$cid && (string)($c['status'] ?? '') !== 'reversed') {
                $o[$i]['mk_egp']    = $new;
                $o[$i]['mk_amt']    = round($new / $fx, 2);
                $o[$i]['site_egp']  = $new_site;
                $o[$i]['site_amt']  = round($new_site / $fx, 2);
                $o[$i]['edited_at'] = date('c');
                $found = $o[$i];
                return true;
            }
        }
        return false;
    }, []);
    if (!$found) return ['ok' => false, 'error' => 'تعذر تعديل البيعة'];
    if ($lid !== '') {
        jupdate(AMZ_LINKS_F, function (&$l) use ($lid, $delta, $site_delta) {
            if (!isset($l[$lid]) || !is_array($l[$lid])) return false;
            $l[$lid]['mk_earn']   = round(max(0, (float)($l[$lid]['mk_earn'] ?? 0) + $delta), 2);
            $l[$lid]['site_earn'] = round(max(0, (float)($l[$lid]['site_earn'] ?? 0) + $site_delta), 2);
            $l[$lid]['updated']   = date('c');
            return true;
        }, []);
    }
    $email = (string)($found['email'] ?? '');
    $state = ((string)($found['status'] ?? '') === 'confirmed') ? 'confirmed' : 'pending';
    if ($email !== '' && $delta != 0) {
        amz_adjust_balance($email, $delta, $state, $reason . ' (' . ($found['asin'] ?? '') . ')');
        notify_user($email, '✏️ الإدارة عدّلت عمولتك على بيعة (' . ($found['asin'] ?? '') . ') إلى ' . number_format($new, 2)
            . ' ج.م (' . ($state === 'confirmed' ? 'مؤكدة' : 'معلقة') . ')', 'dashboard/amazon.php?tab=conversions');
    }
    log_audit('amazon_conv_edit', $cid, ['old' => $old, 'new' => $new, 'state' => $state]);
    return ['ok' => true, 'delta' => $delta, 'conv' => $found];
}
// أرقام كل رابط للكروت: عدد البيعات + أرباح المسوق المعلقة والمؤكدة (من السجل، بدون الملغية)
function amz_money_by_link() {
    $map = [];
    foreach (amz_orders_all() as $o) {
        if (!is_array($o)) continue;
        $st  = (string)($o['status'] ?? '');
        $lid = (string)($o['link_id'] ?? '');
        if ($st === 'reversed' || $lid === '') continue;
        if (!isset($map[$lid])) $map[$lid] = ['sales' => 0, 'qty' => 0, 'pending' => 0.0, 'confirmed' => 0.0];
        $map[$lid]['sales']++;
        $map[$lid]['qty'] += (int)($o['qty'] ?? 1);
        if ($st === 'confirmed') $map[$lid]['confirmed'] += (float)($o['mk_egp'] ?? 0);
        else                     $map[$lid]['pending']   += (float)($o['mk_egp'] ?? 0);
    }
    foreach ($map as $k => $m) {
        $map[$k]['pending']   = round($m['pending'], 2);
        $map[$k]['confirmed'] = round($m['confirmed'], 2);
    }
    return $map;
}

// ============================================================================
//  13) إحصائيات
// ============================================================================
// تصفير بيانات أمازون: سجل النقرات + سجل التحويلات + عدّادات كل رابط — الإحصائيات تبدأ جديدة من لحظة التصفير.
// الروابط المعتمدة والإعدادات وطلبات المسوقين لا تُلمس (عمليات تشغيلية مش إحصائيات).
function amz_reset_stats() {
    $now = date('c');
    jupdate(AMZ_CLICKS_F, function (&$c) { $c = []; return true; }, []);
    jupdate(AMZ_ORDERS_F, function (&$o) { $o = []; return true; }, []);
    jupdate(AMZ_LINKS_F, function (&$l) use ($now) {
        if (!is_array($l)) return false;
        foreach ($l as $k => $v) {
            if (!is_array($v)) continue;
            $l[$k]['clicks'] = 0;
            $l[$k]['uniq'] = 0;
            $l[$k]['orders'] = 0;
            $l[$k]['qty'] = 0;
            $l[$k]['gross'] = 0;
            $l[$k]['site_earn'] = 0;
            $l[$k]['mk_earn'] = 0;
            $l[$k]['last_click'] = '';
            $l[$k]['updated'] = $now;
        }
        return true;
    }, []);
    return true;
}
function amz_stats() {
    $links  = amz_links_all();
    $orders = amz_orders_all();
    $s = ['links' => 0, 'pending_links' => 0, 'clicks' => 0, 'uniq' => 0, 'orders' => 0,
          'gross' => 0, 'amz_comm' => 0, 'site_egp' => 0, 'mk_egp' => 0, 'pending_conv' => 0, 'confirmed_conv' => 0];
    foreach ($links as $l) {
        if (!is_array($l)) continue;
        $s['links']++;
        $s['clicks'] += (int)($l['clicks'] ?? 0);
        $s['uniq']   += (int)($l['uniq'] ?? 0);
        if ((string)($l['status'] ?? '') === 'pending') $s['pending_links']++;
    }
    foreach ($orders as $o) {
        if (!is_array($o) || (string)($o['status'] ?? '') === 'reversed') continue;
        $s['orders']++;
        $s['gross']    += (float)($o['gross'] ?? 0);
        $s['amz_comm'] += (float)($o['amz_comm'] ?? 0);
        $s['site_egp'] += (float)($o['site_egp'] ?? 0);
        $s['mk_egp']   += (float)($o['mk_egp'] ?? 0);
        if ((string)($o['status'] ?? '') === 'confirmed') $s['confirmed_conv']++; else $s['pending_conv']++;
    }
    $s['gross'] = round($s['gross'], 2);
    $s['amz_comm'] = round($s['amz_comm'], 2);
    $s['site_egp'] = round($s['site_egp'], 2);
    $s['mk_egp'] = round($s['mk_egp'], 2);
    return $s;
}
function amz_stats_for($ref) {
    $ref = trim((string)$ref);
    $s = ['links' => 0, 'clicks' => 0, 'uniq' => 0, 'orders' => 0, 'gross' => 0, 'mk_egp' => 0, 'site_egp' => 0, 'pending_egp' => 0, 'confirmed_egp' => 0];
    foreach (amz_links_for($ref) as $l) {
        $s['links']++;
        $s['clicks'] += (int)($l['clicks'] ?? 0);
        $s['uniq']   += (int)($l['uniq'] ?? 0);
    }
    foreach (amz_orders_for($ref) as $o) {
        if ((string)($o['status'] ?? '') === 'reversed') continue;
        $s['orders']++;
        $s['gross'] += (float)($o['gross'] ?? 0);
        $s['mk_egp'] += (float)($o['mk_egp'] ?? 0);
        $s['site_egp'] += (float)($o['site_egp'] ?? 0);
        if ((string)($o['status'] ?? '') === 'confirmed') $s['confirmed_egp'] += (float)($o['mk_egp'] ?? 0);
        else $s['pending_egp'] += (float)($o['mk_egp'] ?? 0);
    }
    $s['gross'] = round($s['gross'], 2);
    $s['mk_egp'] = round($s['mk_egp'], 2);
    $s['site_egp'] = round($s['site_egp'], 2);
    $s['pending_egp'] = round($s['pending_egp'], 2);
    $s['confirmed_egp'] = round($s['confirmed_egp'], 2);
    return $s;
}

// ============================================================================
//  14) استيراد تقرير أرباح أمازون (CSV)
// ============================================================================
function amz_num_csv($v) {
    $s = trim((string)$v);
    $s = str_replace(["$", '٬', ','], '', $s);
    $neg = (substr($s, 0, 1) === '(' && substr($s, -1) === ')');
    $s = str_replace(['(', ')'], '', $s);
    if (!is_numeric($s)) return 0.0;
    $n = (float)$s;
    return $neg ? -$n : $n;
}
function amz_import_csv($text, $opt = []) {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', (string)$text);
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $hi = -1;
    foreach ($lines as $i => $ln) {
        if (trim($ln) === '') continue;
        if (strpos($ln, ',') !== false || strpos($ln, "\t") !== false) { $hi = (int)$i; break; }
    }
    if ($hi < 0) return ['ok' => false, 'error' => 'الملف فاضي أو مش بصيغة CSV صحيحة'];
    $delim = (substr_count($lines[$hi], "\t") > substr_count($lines[$hi], ',')) ? "\t" : ',';
    $cols = array_map(function ($x) { return strtolower(trim(trim((string)$x), "\"' ")); }, str_getcsv($lines[$hi], $delim));
    $find = function ($keys) use ($cols) {
        foreach ($keys as $k) {
            foreach ($cols as $i => $c) { if ($c !== '' && strpos($c, $k) !== false) return (int)$i; }
        }
        return -1;
    };
    $i_date  = $find(['date', 'تاريخ']);
    $i_asin  = $find(['asin', 'sku', 'product id']);
    $i_tag   = $find(['tracking id', 'tracking', 'store id', 'associate', 'tag']);
    $i_qty   = $find(['shipped items', 'ordered items', 'items shipped', 'items ordered', 'items', 'quantity', 'units', 'وحدات', 'عدد']);
    $i_price = $find(['shipped revenue', 'ordered revenue', 'revenue', 'price', 'المبيعات', 'سعر']);
    $i_comm  = $find(['earnings', 'commission', 'عمولة', 'أرباح']);
    if ($i_comm < 0 && $i_price < 0) return ['ok' => false, 'error' => 'مش لاقي أعمدة الأرباح/العمولة في الملف — حمّل تقرير Earnings من أمازون'];

    $existing = [];
    foreach (amz_orders_all() as $o) { if (!empty($o['ext_key'])) $existing[(string)$o['ext_key']] = 1; }

    $links_by_asin = [];
    foreach (amz_links_all() as $id => $l) {
        if (!is_array($l)) continue;
        $a = strtoupper((string)($l['asin'] ?? ''));
        if ($a === '') continue;
        if (!isset($links_by_asin[$a])) $links_by_asin[$a] = [];
        $links_by_asin[$a][$id] = $l;
    }

    $report = ['rows' => 0, 'imported' => 0, 'skipped' => 0, 'unmatched' => [], 'total_comm' => 0, 'total_mk' => 0, 'total_site' => 0, 'errors' => []];
    for ($i = $hi + 1; $i < count($lines); $i++) {
        $ln = trim($lines[$i]);
        if ($ln === '' || strpos($ln, $delim) === false) continue;
        $row = str_getcsv($ln, $delim);
        $report['rows']++;
        $asin = $i_asin >= 0 ? strtoupper(trim((string)($row[$i_asin] ?? ''))) : '';
        $tag  = $i_tag >= 0 ? trim((string)($row[$i_tag] ?? '')) : '';
        $date = $i_date >= 0 ? trim((string)($row[$i_date] ?? '')) : '';
        $qty  = $i_qty >= 0 ? (int)amz_num_csv($row[$i_qty] ?? 1) : 1;
        if ($qty < 1) $qty = 1;
        $price = $i_price >= 0 ? amz_num_csv($row[$i_price] ?? 0) : 0;
        $comm  = $i_comm >= 0 ? amz_num_csv($row[$i_comm] ?? 0) : 0;
        if ($comm <= 0 && $price > 0) $comm = 0;      // هيُحسب من النسبة
        if ($comm <= 0 && $price <= 0) { $report['skipped']++; continue; }
        $key = md5($date . '|' . $asin . '|' . $qty . '|' . $price . '|' . $comm);
        if (isset($existing[$key])) { $report['skipped']++; continue; }

        $target = null;
        $ref_by_tag = amz_ref_by_tag($tag);
        if ($asin !== '' && isset($links_by_asin[$asin])) {
            foreach ($links_by_asin[$asin] as $lid => $l) {
                if ((string)($l['status'] ?? 'approved') !== 'approved') continue;   // بس الروابط المعتمدة بتستقبل بيعات
                if ($ref_by_tag !== '' && (string)($l['ref'] ?? '') === $ref_by_tag) { $target = $lid; break; }
                if ($target === null) $target = $lid;
            }
        } elseif ($ref_by_tag !== '') {
            foreach (amz_links_all() as $lid => $l) {
                if ((string)($l['status'] ?? 'approved') !== 'approved') continue;
                if ((string)($l['ref'] ?? '') === $ref_by_tag) { $target = $lid; break; }
            }
        }
        if ($target === null) {
            $report['unmatched'][] = ['asin' => $asin, 'tag' => $tag, 'date' => $date, 'price' => $price, 'comm' => $comm];
            continue;
        }
        $res = amz_add_conversion($target, max(0, $price / max(1, $qty)), $qty, [
            'amz_comm' => $comm,
            'source'   => 'csv',
            'ext_key'  => $key,
            'note'     => 'استيراد تقرير أمازون' . ($date !== '' ? ' (' . $date . ')' : ''),
            'status'   => (string)($opt['status'] ?? 'pending'),
        ]);
        if (!empty($res['ok'])) {
            $existing[$key] = 1;
            $report['imported']++;
            $report['total_comm'] += $comm;
            $report['total_mk']   += (float)($res['conv']['mk_egp'] ?? 0);
            $report['total_site'] += (float)($res['conv']['site_egp'] ?? 0);
        } else {
            $report['skipped']++;
        }
    }
    $report['total_comm'] = round($report['total_comm'], 2);
    $report['total_mk'] = round($report['total_mk'], 2);
    $report['total_site'] = round($report['total_site'], 2);
    return array_merge(['ok' => true], $report);
}

// ============================================================================
//  15) (اختياري) جلب بيانات المنتج من صفحة أمازون
//      العنوان والسعر وكل الصور والفيديوهات والوصف الكامل والمواصفات والقسم من مسار التصنيف.
//      ملاحظة: أمازون ممكن ترفض الطلب من السيرفر، حينها المسوق يكتبها يدوياً.
//      تحليل الصفحة في amz_parse_product_html() دالة نقية (من غير اتصال شبكة) عشان نقدر نختبرها.
// ============================================================================
function amz_fetch_meta($url) {
    $url = amz_normalize_input($url);
    if ($url === '') return [];
    if (!function_exists('curl_init')) return [];
    if (!amz_is_amazon_url($url)) return [];
    $ch = curl_init($url);
    if (!$ch) return [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!is_string($html) || $html === '') return [];
    return amz_parse_product_html($html);
}

// تحليل HTML صفحة المنتج → مصفوفة فيها الحقول اللي اتقرت بس
function amz_parse_product_html($html) {
    $html = (string)$html;
    $out  = [];
    if ($html === '') return $out;
    if (preg_match('~<span[^>]*id="productTitle"[^>]*>(.*?)</span>~is', $html, $m)) {
        $t = amz_html_text($m[1]);
        if ($t !== '') $out['title'] = mb_substr($t, 0, 180);
    }
    if (preg_match('~<span[^>]*class="[^"]*a-price-whole"[^>]*>(.*?)</span>~is', $html, $m)) {
        $p = (float)preg_replace('/[^0-9.]/', '', $m[1]);
        if ($p > 0) $out['price'] = round($p, 2);
    }
    $images = amz_parse_images($html);
    if ($images) { $out['images'] = $images; $out['img'] = $images[0]; }
    $desc = amz_parse_desc($html);
    if ($desc !== '') $out['desc'] = $desc;
    $specs = amz_parse_specs($html);
    if ($specs) $out['specs'] = $specs;
    $videos = amz_parse_videos($html);
    if ($videos) $out['videos'] = $videos;
    $cat = amz_guess_category(amz_parse_crumbs($html));
    if ($cat !== '') $out['cat'] = $cat;
    return $out;
}

// فك الـ escape اللي بيتحط في JSON الصفحة (\/ و \u002F و \u0026) عشان الروابط تبقى سليمة
function amz_unescape_media($html) {
    return str_replace(['\\/', '\\u002F', '\\u002f', '\\u0026', '&amp;'], ['/', '/', '/', '&', '&'], (string)$html);
}

// الرابط الأصلي للصورة في أمازون (من غير أحجام الـ CDN زي ._AC_SL1500_) عشان نحمّلها بأعلى جودة
function amz_img_canon($u) {
    $u = trim((string)$u);
    if (preg_match('~^(https://[^/?#]+/images/I/)([^/?#.]+)(?:\.[^/?#]*)?\.(jpe?g|png|webp|gif)$~i', $u, $m)) {
        return $m[1] . $m[2] . '.' . strtolower($m[3]);
    }
    return $u;
}

// يرجّع نص المصفوفة JSON اللي بتبدأ عند $start (بيتخطى الأقواس اللي جوه النصوص)
function amz_json_array_at($h, $start) {
    $len = min(strlen($h), $start + 300000);
    $depth = 0; $inStr = false;
    for ($i = $start; $i < $len; $i++) {
        $c = $h[$i];
        if ($inStr) {
            if ($c === '\\') $i++;
            elseif ($c === '"') $inStr = false;
            continue;
        }
        if ($c === '"') {
            $inStr = true;
        } elseif ($c === '[') {
            $depth++;
        } elseif ($c === ']') {
            $depth--;
            if ($depth === 0) return substr($h, $start, $i - $start + 1);
        }
    }
    return '';
}

// مدخلات الـ gallery (colorImages → initial) من JSON الصفحة. بترجع [] لو مش لاقيها أو مش قابلة للقراءة
function amz_color_images_entries($h) {
    $pos = strpos($h, 'colorImages');
    if ($pos === false) return [];
    $ipos = strpos($h, 'initial', $pos);
    if ($ipos === false || $ipos - $pos > 2000) return [];
    $start = strpos($h, '[', $ipos);
    if ($start === false) return [];
    $json = amz_json_array_at($h, $start);
    $arr  = $json !== '' ? json_decode($json, true) : null;
    if (!is_array($arr)) return [];
    return array_values(array_filter($arr, 'is_array'));
}

// نهاية الصفحة اللي فيها المنتج نفسه: قبل قسم التقييمات (عشان فيديوهات العملاء متدخلش)
function amz_reviews_cut($h) {
    $cut = strlen($h);
    foreach (['id="customerReviews', 'id="reviewsMedley'] as $mk) {
        $p = strpos($h, $mk);
        if ($p !== false && $p < $cut) $cut = $p;
    }
    return $cut;
}

// جزء من الصفحة من بداية قسم معين لحد أول قسم تاني بيبدأ بعده (أو لحد الحد الأقصى من البايتات)
function amz_section_chunk($h, $marker, $stops, $max) {
    $mpos = strpos($h, $marker);
    if ($mpos === false) return '';
    // نبدأ من أول الوسم نفسه (<div …>) عشان ميفضلش منه أي attributes في النص
    $from = max(0, $mpos - 300);
    $lt   = strrpos(substr($h, $from, $mpos - $from), '<');
    $pos  = $lt !== false ? $from + $lt : $mpos;
    $end  = min(strlen($h), $pos + (int)$max);
    foreach ($stops as $s) {
        $q = strpos($h, $s, $mpos + strlen($marker));
        if ($q !== false && $q < $end) $end = $q;
    }
    return substr($h, $pos, $end - $pos);
}

// الأقسام اللي بتقفل قسم A+ (عشان صور وفيديوهات «منتجات ممكن تعجبك» متدخلش)
function amz_aplus_stops() {
    return ['id="productDetails', 'id="detailBullets', 'id="important-information', 'id="customerReviews', 'id="reviewsMedley',
            'id="HLCXComparisonWidget', 'id="sims-', 'id="similarities', 'id="sponsoredProducts', 'id="cm-cr', 'id="cm_cr'];
}

// صور المنتج: كل صور الـ gallery (hiRes ثم large لكل variant) + صور A+ بعد كده، من غير تكرار
function amz_parse_images($html) {
    $h = amz_unescape_media($html);
    $cands = [];
    $entries = amz_color_images_entries($h);
    if ($entries) {
        foreach ($entries as $e) {
            $pick = [];
            foreach (['hiRes', 'large'] as $k) {
                if (!empty($e[$k]) && is_string($e[$k])) $pick[] = $e[$k];
            }
            if (!$pick && !empty($e['main']) && is_array($e['main'])) {
                foreach (array_keys($e['main']) as $u) if (is_string($u)) $pick[] = $u;
            }
            foreach ($pick as $u) $cands[] = amz_img_canon($u);
        }
    } else {
        // JSON مش قابل للقراءة → نقرا الروابط بالـ regex من أول جزء الـ gallery
        $p = strpos($h, 'colorImages');
        if ($p !== false && preg_match_all('~"(?:hiRes|large)"\s*:\s*"(https:[^"]+)"~i', substr($h, $p, 40000), $mm)) {
            foreach ($mm[1] as $u) $cands[] = amz_img_canon($u);
        }
    }
    if (!$cands) {
        // مفيش gallery → صور الثمبنيل (altImages)
        $alt = amz_section_chunk($h, 'id="altImages"', [], 30000);
        if ($alt !== '' && preg_match_all('~https://[^\s"\x27<>\\\\]+~i', $alt, $mm)) {
            foreach ($mm[0] as $u) if (strpos($u, '/images/I/') !== false) $cands[] = amz_img_canon($u);
        }
    }
    // صور A+ (محتوى المنتج المتقدم) — من جوه قسم الـ A+ نفسه بس
    $aplus = amz_section_chunk($h, 'id="aplus', amz_aplus_stops(), 200000);
    if ($aplus !== '' && preg_match_all('~https://[^\s"\x27<>\\\\]+~i', $aplus, $mm)) {
        foreach ($mm[0] as $u) {
            if (preg_match('~/images/S/[^?#]+\.(?:jpe?g|png|webp)$~i', $u)) $cands[] = $u;
        }
    }
    if (!$cands && preg_match('~<img[^>]*id="landingImage"[^>]*(?:data-old-hires|src)="(https:[^"]+)"~i', $h, $m)) {
        $cands = [amz_img_canon($m[1])];
    }
    return amz_clean_images($cands);
}

// سطور متكررة بتتشال (مقارنة من غير علامة النقطة)، والسطر الفاضي بيفضل للفقرات
function amz_dedupe_lines($s) {
    $out = []; $seen = [];
    foreach (explode("\n", (string)$s) as $line) {
        $line = trim($line);
        if ($line === '') { $out[] = ''; continue; }
        $k = mb_strtolower((string)preg_replace('~^•\s*~u', '', $line));
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $line;
    }
    return implode("\n", $out);
}

// الوصف الكامل: نقاط About this item + فقرة الوصف (productDescription) + نص قسم A+. من غير تكرار، و6000 حرف كحد أقصى
function amz_parse_desc($html) {
    $h = (string)$html;
    $parts = [];
    // 1) نقاط "About this item": كل <li> لوحده
    $pos = strpos($h, 'id="feature-bullets"');
    if ($pos !== false) {
        $end   = strpos($h, '</ul>', $pos);
        $chunk = substr($h, $pos, ($end !== false ? $end - $pos : 8000));
        $bul   = [];
        foreach (array_slice(preg_split('~(?=<li[\s>])~i', $chunk), 1) as $li) {
            $t = amz_html_text($li);
            if (mb_strlen($t) < 3) continue;
            $bul[] = '• ' . $t;
            if (count($bul) >= 25) break;
        }
        if ($bul) $parts[] = implode("\n", $bul);
    }
    // 2) فقرة الوصف
    $t = amz_section_text($h, 'id="productDescription"', ['id="aplus', 'id="productDetails', 'id="detailBullets', 'id="important-information', 'id="customerReviews', 'id="reviewsMedley', 'id="HLCXComparisonWidget'], 30000);
    if ($t !== '') $parts[] = $t;
    // 3) نص قسم A+ (المحتوى المتقدم)
    $t = amz_section_text($h, 'id="aplus', amz_aplus_stops(), 200000);
    if ($t !== '') $parts[] = $t;
    return amz_clean_text(amz_dedupe_lines(implode("\n\n", $parts)), 6000);
}

// نص قسم معين من الصفحة (بعد تنظيفه من الكود والوسوم)
function amz_section_text($h, $marker, $stops, $max) {
    return amz_html_text(amz_section_chunk($h, $marker, $stops, $max));
}

// صفوف جداول التفاصيل: Product Overview (td/td) + Product information (th/td) + Product details (نقاط)
function amz_parse_specs($html) {
    $h = (string)$html;
    $rows = [];
    $tables = [
        'productOverview_feature_div'            => 20000,
        'productDetails_db_sections'             => 60000,
        'productDetails_techSpec_section_1'      => 30000,
        'productDetails_techSpec_section_2'      => 30000,
        'productDetails_detailBullets_sections1' => 30000,
        'prodDetails'                            => 30000,
    ];
    foreach ($tables as $sec => $len) {
        $pos = strpos($h, 'id="' . $sec . '"');
        if ($pos === false) continue;
        $chunk = substr($h, $pos, $len);
        if (preg_match_all('~<tr[^>]*>\s*<t[hd][^>]*>(.*?)</t[hd]>\s*<td[^>]*>(.*?)</td>\s*</tr>~is', $chunk, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) $rows[] = [$m[1], $m[2]];
        }
    }
    foreach (['detailBullets_feature_div', 'detailBulletsWrapper_feature_div'] as $sec) {
        $pos = strpos($h, 'id="' . $sec . '"');
        if ($pos === false) continue;
        $chunk = substr($h, $pos, 30000);
        if (preg_match_all('~<span class="a-text-bold">(.*?)</span>\s*<span[^>]*>(.*?)</span>~is', $chunk, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) $rows[] = [$m[1], $m[2]];
        }
    }
    $out = []; $seen = [];
    foreach ($rows as $r) {
        $k = trim(amz_html_text($r[0]), " :\t\n\r\0\x0B");
        $v = trim(str_replace("\n", ' ', amz_html_text($r[1])));
        if ($k === '' || $v === '') continue;
        $kl = mb_strtolower($k);
        if (strpos($kl, 'customer review') !== false || strpos($kl, 'best sellers rank') !== false) continue;
        if (isset($seen[$kl])) continue;
        $seen[$kl] = true;
        $out[] = ['k' => $k, 'v' => $v];
        if (count($out) >= 60) break;
    }
    return amz_clean_specs($out);
}

// فيديوهات المنتج (mp4 من سيرفرات أمازون): كل الروابط قبل قسم التقييمات، وفيديوهات المنتج (vse-vms) الأول
function amz_parse_videos($html) {
    $h = amz_unescape_media($html);
    $h = substr($h, 0, amz_reviews_cut($h));
    if (!preg_match_all('~https://[a-z0-9.\-]*(?:media-amazon|ssl-images-amazon)\.com/[^\s"\x27<>\\\\]+?\.mp4~i', $h, $m)) return [];
    $first = []; $rest = [];
    foreach ($m[0] as $u) {
        if (!amz_media_url_ok($u)) continue;
        if (strpos($u, 'vse-vms') !== false) $first[] = $u; else $rest[] = $u;
    }
    return amz_clean_videos(array_merge($first, $rest));
}

// مسار التصنيف (Breadcrumb) بالترتيب من العام للخاص
function amz_parse_crumbs($html) {
    $h = (string)$html;
    $pos = strpos($h, 'id="wayfinding-breadcrumbs_feature_div"');
    if ($pos === false) return [];
    $end   = strpos($h, '</ul>', $pos);
    $chunk = substr($h, $pos, ($end !== false ? $end - $pos : 4000));
    $out = [];
    if (preg_match_all('~<a[^>]*>(.*?)</a>~is', $chunk, $mm)) {
        foreach ($mm[1] as $t) {
            $t = amz_html_text($t);
            if ($t !== '') $out[] = $t;
        }
    }
    return $out;
}

// القسم تلقائياً من مسار التصنيف (من أعمق قسم للأعم). لو مفيش تطابق بيرجع '' والمسوق يختار بنفسه
function amz_guess_category($crumbs) {
    if (!is_array($crumbs) || !$crumbs) return '';
    $rules = [
        'games'       => ['amazon games'],
        'video_games' => ['video game', 'gaming console', 'nintendo', 'playstation', 'xbox'],
        'lux_beauty'  => ['luxury beauty'],
        'media'       => ['music', 'movies', 'handmade'],
        'books'       => ['books', 'kindle'],
        'kitchen'     => ['kitchen'],
        'auto'        => ['automotive', 'motorcycle', 'vehicle'],
        'fashion'     => ['fashion', 'clothing', 'shoes', 'watches', 'jewelry', 'handbags', 'luggage'],
        'pets'        => ['pet supplies', 'pets', 'pet food'],
        'sports'      => ['sports', 'outdoors', 'fitness'],
        'beauty'      => ['beauty', 'personal care'],
        'baby'        => ['baby'],
        'tools'       => ['tools', 'home improvement', 'industrial', 'hardware'],
        'office'      => ['office', 'stationery'],
        'grocery'     => ['grocery', 'gourmet'],
        'fresh'       => ['amazon fresh'],
        'pc'          => ['computer', 'laptop', 'tablet', 'monitor', 'keyboard'],
        'electronics' => ['electronics', 'cell phone', 'phone', 'headphone', 'camera', 'television', 'audio', 'smart home'],
        'home'        => ['furniture', 'garden', 'home decor', 'bedding'],
    ];
    for ($i = count($crumbs) - 1; $i >= 0; $i--) {
        $c = mb_strtolower((string)$crumbs[$i]);
        if ($c === '') continue;
        foreach ($rules as $slug => $kws) {
            foreach ($kws as $kw) {
                if (preg_match('~\b' . preg_quote($kw, '~') . '~u', $c)) return $slug;
            }
        }
    }
    return '';
}

// هل الرابط صورة/فيديو من سيرفرات أمازون؟ (https بس، من غير بورت ولا بيانات دخول)
function amz_media_url_ok($url) {
    $url = trim((string)$url);
    if ($url === '' || strlen($url) > 600) return false;
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
    if (isset($p['port']) || isset($p['user']) || isset($p['pass'])) return false;
    $h = strtolower((string)($p['host'] ?? ''));
    foreach (['media-amazon.com', 'ssl-images-amazon.com', 'images-amazon.com'] as $suf) {
        if ($h === $suf || substr($h, -(strlen($suf) + 1)) === '.' . $suf) return true;
    }
    return false;
}

// تنظيف نص عام: من غير حروف تحكم، مسافات مرتبة، وبطول أقصى
function amz_clean_text($s, $max = 2000) {
    $s = mb_scrub((string)$s, 'UTF-8');
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    $s = (string)preg_replace('/[ \t]+/', ' ', $s);
    $s = (string)preg_replace('/ *\n */', "\n", $s);
    $s = (string)preg_replace('/\n{3,}/', "\n\n", $s);
    return mb_substr(trim($s), 0, $max);
}

// تحويل جزء HTML لنص (بيشيل السكريبت والستايل والتعليقات والوسوم والـ entities والعلامات الاتجاهية المخفية)
function amz_html_text($fragment) {
    $t = mb_scrub((string)$fragment, 'UTF-8');
    $t = (string)preg_replace('~<!--.*?-->~s', ' ', $t);
    $t = (string)preg_replace('~<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>~is', ' ', $t);
    $t = (string)preg_replace('~<br\s*/?>~i', "\n", $t);
    $t = (string)preg_replace('~</(?:p|div|li|h[1-6]|tr|ul|ol|table|section)\s*>~i', "\n", $t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string)preg_replace('~[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]~u', '', $t);
    $t = str_replace("\xC2\xA0", ' ', $t);
    return amz_clean_text($t, 20000);
}

// تنظيف المواصفات (مصفوفة أو JSON من الفورم)
function amz_clean_specs($in) {
    if (is_string($in)) $in = json_decode($in, true);
    if (!is_array($in)) return [];
    $out = []; $seen = [];
    foreach ($in as $row) {
        if (!is_array($row)) continue;
        $k = trim(str_replace("\n", ' ', amz_clean_text((string)($row['k'] ?? ''), 200)));
        $v = trim(str_replace("\n", ' ', amz_clean_text((string)($row['v'] ?? ''), 400)));
        if ($k === '' || $v === '') continue;
        $kl = mb_strtolower($k);
        if (isset($seen[$kl])) continue;
        $seen[$kl] = true;
        $out[] = ['k' => mb_substr($k, 0, 80), 'v' => mb_substr($v, 0, 300)];
        if (count($out) >= 60) break;
    }
    return $out;
}

// تنظيف قايمة صور أمازون: من غير تكرار، و60 صورة كحد أقصى
function amz_clean_images($in) {
    if (is_string($in)) $in = json_decode($in, true);
    if (!is_array($in)) return [];
    $out = []; $seen = [];
    foreach ($in as $u) {
        if (!is_string($u)) continue;
        $u = trim($u);
        if (!amz_media_url_ok($u)) continue;
        $key = amz_media_key($u);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $u;
        if (count($out) >= 60) break;
    }
    return $out;
}

// مفتاح الصورة = رقم الصورة في أمازون (من غير مقاس الـ CDN)
function amz_media_key($u) {
    $base  = basename((string)parse_url((string)$u, PHP_URL_PATH));
    $parts = explode('.', $base);
    return (string)($parts[0] !== '' ? $parts[0] : $base);
}

// تنظيف رابط فيديو واحد (mp4 من سيرفرات أمازون بس)
function amz_clean_video($in) {
    $u = is_string($in) ? trim($in) : '';
    if ($u === '') return '';
    $path = (string)parse_url($u, PHP_URL_PATH);
    if (!preg_match('~\.mp4$~i', $path)) return '';
    return amz_media_url_ok($u) ? $u : '';
}

// تنظيف قايمة الفيديوهات: JSON أو مصفوفة (أو رابط واحد من النسخة القديمة). mp4 من أمازون بس، من غير تكرار، و10 فيديوهات كحد أقصى
function amz_clean_videos($in) {
    if (is_string($in)) {
        $in = trim($in);
        if ($in === '') return [];
        $dec = ($in[0] === '[') ? json_decode($in, true) : null;
        $in  = is_array($dec) ? $dec : [$in];
    }
    if (!is_array($in)) return [];
    $out = []; $seen = [];
    foreach ($in as $u) {
        $u = amz_clean_video($u);
        if ($u === '' || isset($seen[$u])) continue;
        $seen[$u] = true;
        $out[] = $u;
        if (count($out) >= 10) break;
    }
    return $out;
}

// قايمة الوسائط اللي تتحمل من بيانات محفوظة: الصور من images (أو img)، والفيديوهات من videos (أو video في الروابط القديمة)
function amz_media_list($d, $kind) {
    if (!is_array($d)) return [];
    if ($kind === 'vid') {
        $vids = amz_clean_videos($d['videos'] ?? []);
        if (!$vids && !empty($d['video'])) $vids = amz_clean_videos((string)$d['video']);
        return $vids;
    }
    $imgs = amz_clean_images($d['images'] ?? []);
    if (!$imgs && !empty($d['img'])) $imgs = amz_clean_images([(string)$d['img']]);
    return $imgs;
}

// اسم الملف اللي هيتحمل: ASIN + رقم الصورة (أو الفيديو) من غير أي حروف غريبة
function amz_dl_name($asin, $kind, $idx, $url) {
    $a = (string)preg_replace('/[^A-Za-z0-9]/', '', (string)$asin);
    if ($a === '') $a = 'product';
    if ($kind === 'vid') return $a . ((int)$idx > 0 ? '-video-' . ((int)$idx + 1) : '-video') . '.mp4';
    $ext = strtolower(pathinfo((string)parse_url((string)$url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
    return $a . '-' . ((int)$idx + 1) . '.' . $ext;
}

// بث الصورة/الفيديو للمتصفح كملف تحميل (السيرفر بيجيبه من أمازون، عشان المتصفح مش بيحمّل من روابط خارجية مباشرة)
// الرابط لازم يعدي amz_media_url_ok، ومفيش تحويلات (redirects) ولا ملفات تانية
function amz_stream_media($url, $filename) {
    if (!function_exists('curl_init') || !amz_media_url_ok($url)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'الملف ده مش متاح للتحميل';
        return;
    }
    $ch = curl_init($url);
    if (!$ch) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'تعذر تحميل الملف الآن — جرّب تاني بعد شوية';
        return;
    }
    if (function_exists('set_time_limit')) @set_time_limit(180);
    $code = 0; $ctype = ''; $clen = 0; $sent = false;
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => 0,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 150,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$code, &$ctype, &$clen) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $code = (int)$m[1];
            } elseif (stripos($line, 'Content-Type:') === 0) {
                $ctype = trim(substr($line, 13));
            } elseif (stripos($line, 'Content-Length:') === 0) {
                $clen = (int)trim(substr($line, 15));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function ($c, $data) use (&$code, &$ctype, &$clen, &$sent, $filename) {
            if (!$sent) {
                if ($code !== 200) return 0;   // رد غلط من أمازون → نوقف ومنبعتش أي حاجة
                $sent = true;
                while (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: ' . ($ctype !== '' ? $ctype : 'application/octet-stream'));
                if ($clen > 0) header('Content-Length: ' . $clen);
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store');
            }
            echo $data;
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
    if (!$sent) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'تعذر تحميل الملف من أمازون الآن — جرّب تاني بعد شوية';
    }
}

// ============================================================================
//  16) هل النظام مفعّل؟
// ============================================================================
function amz_enabled() { return site_setting('amz_enabled', '1') !== '0'; }
