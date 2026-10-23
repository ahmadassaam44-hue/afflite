<?php
// ===== طبقة البيانات الموحدة (تعمل محلياً بسرعة فائقة + دعم اختياري لـ Supabase) =====
define('SB_URL', 'https://hbhuxhphlpgwqynstytw.supabase.co');
define('SB_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImhiaHV4aHBobHBnd3F5bnN0eXR3Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzY4OTA3NTAsImV4cCI6MjA5MjQ2Njc1MH0.THAVZoJvoZYAqDUFCk3JCuRXq2-gPa0gvtRbzrZHkqg');
define('SB_CACHE_TTL', 3600);
if (!defined('SB_SERVICE')) define('SB_SERVICE', '');

function sb_admin($method, $table, $query = '', $body = null) {
    if (!SB_SERVICE) return null;
    $url = SB_URL . '/rest/v1/' . $table . ($query ? '?' . $query : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SB_SERVICE,
            'Authorization: Bearer ' . SB_SERVICE,
            'Content-Type: application/json',
            'Prefer: resolution=merge-duplicates,return=minimal'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code < 400 ? true : null;
}

function sb_shipping() {
    $c = sb_cache_get('shipping');
    if (is_array($c)) return $c;
    if (!defined('USE_SUPABASE') || !USE_SUPABASE) return null;
    $r = sb_req('GET', 'shipping', 'select=platform,country,region,fee,currency,notes&order=platform.asc,country.asc,region.asc&limit=1000');
    if (!is_array($r) || !is_array($r['data'])) return null;
    // تجميع بمنصة+دولة: جداول الدول (يدوي الخليج/مخزون) لازم تفضل منفصلة — خلطها كان بيبوّد رسوم دولة لدولة تانية
    $groups = [];
    foreach ($r['data'] as $row) {
        $pl = (string)($row['platform'] ?? '');
        $co = (string)($row['country'] ?? '');
        $groups[$pl . '@' . $co][] = [
            'region' => (string)($row['region'] ?? ''),
            'fee' => $row['fee'] ?? 0,
            'currency' => (string)($row['currency'] ?? ''),
            'notes' => (string)($row['notes'] ?? '')
        ];
    }
    $out = [];
    foreach ($groups as $ck => $regs) {
        [$pl, $co] = explode('@', $ck, 2);
        $e = ['platform' => $pl, 'regions' => $regs];
        if ($co !== '') $e['country'] = $co;
        $out[] = $e;
    }
    sb_cache_set('shipping', $out);
    return $out;
}

function site_shipping() {
    $r = sb_shipping();
    if (is_array($r) && $r) {
        $names = ['m5zoon' => 'M5zoon', 'vendoor' => 'Vendoor', 'safqa' => 'Safqa', 'timehand' => 'Time Hand', 'angazny' => 'Angazny', 'shopmart' => 'Shopmart', 'g2g' => 'G2G'];
        $out = [];
        foreach ($r as $e) {
            $pl = (string)($e['platform'] ?? '');
            $o = ['platform' => $pl, 'name' => $names[$pl] ?? $pl, 'source' => 'Supabase', 'regions' => (array)($e['regions'] ?? [])];
            if (!empty($e['country'])) $o['country'] = (string)$e['country'];
            $out[] = $o;
        }
        return $out;
    }
    return jload('shipping.json', []);
}

// جدول مناطق/رسوم الشحن لمنصة معيّنة — ولو المنصة بتشتغل في أكتر من دولة (المنتجات اليدوية)
// بيتحدّد الجدول بالدولة: مصر = جدول تايم هاند، وكل دولة خليجية = جدول مناطقها اللي المالك بيدخل رسومه.
// منصات ليها جدول شحن لكل دولة (مش جدول واحد لكل الدول): المنتجات اليدوية ومخزون.
// أي منصة تانية (vendoor/timehand/safqa/…) ليها جدول واحد بمحافظات مصر بالجنيه زي الأول.
function site_ship_country_plats() {
    return ['manual', 'm5zoon'];
}
function site_ship_regions($plat, $country = '') {
    $plat = strtolower(trim((string)$plat));
    // Cezma بيستخدم نفس جدول شحن TimeHand بالكامل (نفس أسعار المحافظات ونفس الملاحظات).
    $lookup = ($plat === 'cezma') ? 'timehand' : $plat;
    $cn = function_exists('wf_match_country') ? wf_match_country($country, '') : trim((string)$country);
    $per_country = in_array($lookup, site_ship_country_plats(), true);
    // منتج دولته مصر (أو من غير دولة محددة) = نفس جدول تايم هاند بمحافظات مصر بالجنيه،
    // حتى لو كان يدوي أو من مخزون — مفيش محافظات مصرية بتظهر لمنتج دولته خليجية.
    if ($per_country && ($cn === '' || $cn === 'مصر')) $lookup = 'timehand';
    $all = site_shipping();
    $legacy = null;   // جدول المنصة القديم من غير دولة (احتياطي للتوافق مع بيانات أقدم)
    foreach ($all as $p) {
        $is_match = (($p['platform'] ?? '') === $lookup) || ($lookup === 'safqa' && ($p['platform'] ?? '') === 'safka');
        if (!$is_match) continue;
        if ($lookup !== $plat || !$per_country) return $p['regions'];
        // أكتر من جدول لنفس المنصة (جدول لكل دولة): لازم الدولة تتطابق — مش بنخمن رسوم دولة تانية
        $pc = function_exists('wf_match_country') ? wf_match_country($p['country'] ?? '', '') : trim((string)($p['country'] ?? ''));
        if ($pc !== '' && $pc === $cn) return $p['regions'];
        if ($pc === '' && $legacy === null) $legacy = $p['regions'];
    }
    if ($lookup === $plat && $per_country) {
        if ($legacy !== null) return $legacy;   // بيانات قديمة: جدول واحد للمنصة من غير دولة
        // دولة مالهاش جدول: مفيش مناطق — أحسن من رسوم دولة تانية أو محافظات مصرية غلط
        error_log('site_ship_regions: مفيش جدول شحن لـ"' . $plat . '" في دولة "' . $cn . '"');
        return [];
    }
    return $all ? $all[0]['regions'] : [];
}

function sb_req($method, $table, $query = '', $body = null) {
    if (!defined('USE_SUPABASE') || !USE_SUPABASE) return null;
    $flag = DATA_DIR . '/cache_sb_down.json';
    if (file_exists($flag) && (time() - filemtime($flag)) < 600) return null;
    $url = SB_URL . '/rest/v1/' . $table . ($query ? '?' . $query : '');
    $ch = curl_init($url);
    $h = ['apikey: ' . SB_KEY, 'Authorization: Bearer ' . SB_KEY, 'Content-Type: application/json'];
    if (strtoupper($method) === 'GET') $h[] = 'Prefer: count=exact';
    else $h[] = 'Prefer: resolution=merge-duplicates,return=representation';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HEADER => true
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($code >= 400 || $raw === false) { @file_put_contents($flag, '1'); return null; }
    if (file_exists($flag)) @unlink($flag);
    $out = json_decode(substr($raw, $hsize), true);
    $total = null;
    if (preg_match('/Content-Range:\s*\d+-\d+\/(\d+)/i', substr($raw, 0, $hsize), $m)) $total = (int)$m[1];
    return ['data' => $out, 'total' => $total];
}

function sb_cache_get($k) {
    $p = DATA_DIR . '/cache_' . preg_replace('/[^a-z0-9_]/i', '_', $k) . '.json';
    if (file_exists($p) && (time() - filemtime($p)) < SB_CACHE_TTL) {
        return jload('cache_' . preg_replace('/[^a-z0-9_]/i', '_', $k) . '.json', null);
    }
    return null;
}

function sb_cache_set($k, $v) {
    jsave('cache_' . preg_replace('/[^a-z0-9_]/i', '_', $k) . '.json', $v);
}

function sb_json($v) {
    if (is_array($v)) return $v;
    $d = json_decode($v ?? '[]', true);
    return is_array($d) ? $d : [];
}

function sb_row_to_product($r) {
    $p = [
        'id' => $r['ext_id'] ?? '',
        'name' => $r['name'] ?? '',
        'sku' => $r['sku'] ?? '',
        'price' => $r['price_num'] ?? 0,
        'price_text' => $r['price_text'] ?? '',
        'commission' => ($r['commission_num'] ?? null) ?: null,
        'stock' => $r['stock_num'] ?? null,
        'images' => sb_json($r['images'] ?? []),
        'videos' => sb_json($r['videos'] ?? []),
        'files' => sb_json($r['files'] ?? []),
        'desc' => $r['description'] ?? '',
        'seller' => $r['seller'] ?? '',
        'sizes' => sb_json($r['sizes'] ?? []),
        'url' => $r['source_url'] ?? '',
        'country' => $r['country'] ?? 'مصر',
        'category' => $r['category'] ?? '',
        'extra' => array_merge(
            is_array($r['extra'] ?? null) ? $r['extra'] : (json_decode((string)($r['extra'] ?? '{}'), true) ?: []),
            ['colors' => sb_json($r['colors'] ?? [])]
        )
    ];
    if (!empty($r['platform'])) $p = with_detail_imgs($p, $r['platform']);
    return $p;
}

function site_stats() {
    return jload('stats.json', ['platforms' => [], 'total_products' => 0, 'total_images' => 0, 'total_videos' => 0, 'total_files' => 0]);
}

// منتجات ذات صلة: لو فيزيكال نرجع منتج من كل أفلييت في نفس التصنيف بالتناوب
function sb_related($plat, $cat, $exclude, $n = 8) {
    if (in_array($plat, ['g2g', 'social'], true)) {
        $all = platform_products_lite($plat);
        $out = [];
        foreach ($all as $p) {
            if ((string)$p['id'] === (string)$exclude) continue;
            if ($cat && ($p['subcategory'] ?? '') !== $cat && ($p['category'] ?? '') !== $cat) continue;
            $p['_plat'] = $plat;
            $out[] = $p;
            if (count($out) >= $n) break;
        }
        return $out;
    }
    if ($plat === 'm5zoon') {
        $all = platform_products_lite('m5zoon');
        $out = [];
        foreach ($all as $p) {
            if ((string)$p['id'] === (string)$exclude) continue;
            if ($cat && ($p['category'] ?? '') !== $cat) continue;
            $p['_plat'] = 'm5zoon';
            $out[] = $p;
            if (count($out) >= $n) break;
        }
        return $out;
    }
    // للمنتجات الفيزيكال: نجمع من كل المنصات في نفس التصنيف بالتناوب
    $items = physical_collection('all', 'مصر', $cat ?: 'all');
    $out = [];
    foreach ($items as $p) {
        if ((string)($p['id'] ?? '') === (string)$exclude) continue;
        $out[] = $p;
        if (count($out) >= $n) break;
    }
    return $out;
}

function sb_reviews($uid) {
    $f = 'reviews_' . preg_replace('/[^a-z0-9]/i', '_', $uid) . '.json';
    $local = jload($f, []);
    if ($local) return $local;
    $all_revs = jload('reviews.json', []);
    return array_values(array_filter($all_revs, function ($r) use ($uid) {
        return ($r['product_uid'] ?? '') === $uid;
    }));
}

function sb_add_review($uid, $name, $rating, $comment, $email = '') {
    $entry = [
        'product_uid' => $uid,
        'user_name' => mb_substr($name, 0, 60),
        'user_email' => mb_substr(strtolower(trim((string)$email)), 0, 120),
        'rating' => max(1, min(5, (int)$rating)),
        'comment' => mb_substr($comment, 0, 1000),
        'created_at' => date('c'),
        'at' => date('c')
    ];
    $f = 'reviews_' . preg_replace('/[^a-z0-9]/i', '_', $uid) . '.json';
    $rs = jload($f, []);
    $rs[] = $entry;
    jsave($f, $rs);

    $all = jload('reviews.json', []);
    $all[] = $entry;
    jsave('reviews.json', $all);
    return true;
}

function sb_categories($mode = 'physical', $country = 'all') {
    return categories_by_mode($mode, $country);
}

function sb_platform_products($key, $search = '', $page = 1, $per = 24, $country = '', $cat = '') {
    $page = max(1, (int)$page);
    $per = max(1, (int)$per);
    if ($key === 'g2g') {
        $all = platform_products_lite('g2g');
        if ($search !== '') {
            $all = array_values(array_filter($all, function ($x) use ($search) {
                return mb_stripos($x['name'] ?? '', $search) !== false;
            }));
        }
        if ($cat && $cat !== 'all' && $cat !== 'المنتجات الرقمية') {
            $all = array_values(array_filter($all, function ($x) use ($cat) {
                return ($x['subcategory'] ?? '') === $cat || ($x['category'] ?? '') === $cat;
            }));
        }
        $total = count($all);
        $items = array_slice($all, ($page - 1) * $per, $per);
        return [$items, $total];
    }
    if ($key === 'social') {
        $all = platform_products_lite('social');
        if ($search !== '') {
            $all = array_values(array_filter($all, function ($x) use ($search) {
                return mb_stripos($x['name'] ?? '', $search) !== false;
            }));
        }
        if ($cat && $cat !== 'all' && $cat !== 'سوشيال ميديا') {
            $all = array_values(array_filter($all, function ($x) use ($cat) {
                $sp = $x['_soc_plat'] ?? social_plat($x['name'] ?? '');
                return $sp === $cat || ($x['subcategory'] ?? '') === $cat || ($x['category'] ?? '') === $cat;
            }));
        }
        $total = count($all);
        $items = array_slice($all, ($page - 1) * $per, $per);
        return [$items, $total];
    }
    $c_arg = ($country === '' ? 'all' : $country);
    $cat_arg = ($cat === '' ? 'all' : $cat);
    $p_arg = ($key === '' ? 'all' : $key);
    $all = physical_collection('all', $c_arg, $cat_arg, $search, $p_arg);
    $total = count($all);
    $items = array_slice($all, ($page - 1) * $per, $per);
    return [$items, $total];
}

function sb_track_view($uid) {
    $views = jload('views.json', []);
    if (!is_array($views)) $views = [];
    $views[$uid] = ($views[$uid] ?? 0) + 1;
    jsave('views.json', $views);
    return $views[$uid];
}

function sb_top_viewed($n = 12) {
    $views = jload('views.json', []);
    if (!is_array($views)) return [];
    arsort($views);
    $out = [];
    foreach (array_slice($views, 0, $n, true) as $uid => $v) $out[] = ['uid' => $uid, 'views' => $v];
    return $out;
}

function sb_find_product($plat, $id) {
    return find_product_by_id($id, $plat);
}
