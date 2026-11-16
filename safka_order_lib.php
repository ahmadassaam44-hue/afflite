<?php
// ============================================================================
//  ربط الطلبات بمنصة صفقة (Safka Public API)
//  ---------------------------------------------------------------
//  أول ما العميل يبعت الطلب على الموقع، الطلب بيتسجل عند صفقة في نفس اللحظة
//  (POST /api/v1/public/orders) عشان صفقة تشحنه من غير أي إدخال يدوي.
//
//  قواعد ثابتة:
//  • المفتاح سرّي وبيتحفظ على السيرفر بس (secrets / safka_key.php أو إعدادات الأدمن).
//  • كل النداءات من السيرفر — صفقة بترفض أي Origin غير safka-eg.com.
//  • الطلب عندنا بيتحفظ الأول دايمًا، وبعد كده بيتحاول يتبعت. لو صفقة وقعت أو
//    المفتاح باظ: الطلب بيفضل «محلي فقط» مع سبب واضح، وإعادة الإرسال من لوحة الأدمن.
//  • مفيش أي بيانات من المتصفح بتتحسب في الإرسال: العنوان/المحافظة/السعر من السيرفر.
// ============================================================================
if (!defined('SAFKA_API_BASE')) define('SAFKA_API_BASE', 'https://api.safka-eg.com/api/v1/public');
if (!defined('SAFKA_PRICE_TTL')) define('SAFKA_PRICE_TTL', 43200);   // كاش قائمة الشحن: 12 ساعة
if (!defined('SAFKA_PROD_TTL'))  define('SAFKA_PROD_TTL', 21600);    // كاش بيانات المنتج: 6 ساعات

// ---------------------------------------------------------------------------
//  المفتاح والتشغيل
// ---------------------------------------------------------------------------
function safka_api_key() {
    if (defined('SAFKA_API_KEY') && trim((string)SAFKA_API_KEY) !== '') return trim((string)SAFKA_API_KEY);
    if (function_exists('site_setting')) return trim((string)site_setting('safqa_api_key', ''));
    return '';
}
// شغّال؟ (مفتاح موجود + الإعداد مش متوقف). القيمة 'dry' = تجهيز الطلب وتسجيله بدون إرسال.
function safka_mode() {
    if (safka_api_key() === '') return 'off';
    $flag = function_exists('site_setting') ? strtolower(trim((string)site_setting('safqa_auto_order', ''))) : '';
    if ($flag === '0' || $flag === 'off' || $flag === 'no') return 'off';
    if ($flag === 'dry' || $flag === 'test') return 'dry';
    return 'live';
}
function safka_can_push() { return safka_mode() !== 'off'; }

// ---------------------------------------------------------------------------
//  HTTP
// ---------------------------------------------------------------------------
function safka_http($method, $path, $payload = null, $timeout = 15) {
    $key = safka_api_key();
    if ($key === '') return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'مفتاح API صفقة غير مضبوط'];
    $url = SAFKA_API_BASE . $path;
    $headers = ['api-safka-key: ' . $key, 'Accept: application/json'];
    $method = strtoupper($method) === 'POST' ? 'POST' : 'GET';
    $json = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
    if ($json !== null) $headers[] = 'Content-Type: application/json';

    $connect_timeout = (int)(defined('SAFKA_CONNECT_TIMEOUT') ? SAFKA_CONNECT_TIMEOUT : 6);
    $raw = false; $status = 0; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connect_timeout,
            CURLOPT_TIMEOUT        => max(5, (int)$timeout),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Wafirinhalk/1.0 (+php)',
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = (string)$json;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            // بعض الاستضافات المشتركة عندها مشكلة في شهادة SSL جوه cURL — بنعيد المحاولة
            // عن طريق stream (بيستخدم مخزن الشهادات بتاع النظام) قبل ما نستسلم.
            $retry = safka_http_stream($url, $method, $headers, $json, $timeout);
            if ($retry['ok'] || $retry['status'] > 0) {
                safka_log(['what' => 'curl_ssl_fallback', 'path' => $path, 'curl_error' => mb_substr($err, 0, 120)]);
                return $retry;
            }
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'تعذّر الاتصال بصفقة: ' . $err];
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $method === 'POST' ? (string)$json : '',
            'timeout'       => max(5, (int)$timeout),
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) $status = (int)$m[1];
        if ($raw === false) return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'تعذّر الاتصال بصفقة'];
    }

    $body = json_decode((string)$raw, true);
    if (!is_array($body)) $body = (string)$raw;
    $ok = $status >= 200 && $status < 300;
    $error = '';
    if (!$ok) {
        if (is_array($body)) {
            if (!empty($body['errors'][0]['msg'])) $error = (string)$body['errors'][0]['msg'];
            elseif (!empty($body['message'])) $error = (string)$body['message'];
            elseif (!empty($body['error'])) $error = is_array($body['error']) ? json_encode($body['error'], JSON_UNESCAPED_UNICODE) : (string)$body['error'];
        }
        if ($error === '') $error = 'صفقة ردّت بكود ' . $status;
    }
    return ['ok' => $ok, 'status' => $status, 'body' => $body, 'error' => $error];
}

// نداء عبر stream (بديل cURL) — بيرجّع نفس شكل الرد
function safka_http_stream($url, $method, $headers, $json, $timeout) {
    if (!ini_get('allow_url_fopen')) return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'allow_url_fopen مقفول'];
    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'content'       => $method === 'POST' ? (string)$json : '',
        'timeout'       => max(5, (int)$timeout),
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) $status = (int)$m[1];
    if ($raw === false) return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'تعذّر الاتصال بصفقة'];
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) $body = (string)$raw;
    $ok = $status >= 200 && $status < 300;
    $error = '';
    if (!$ok) {
        if (is_array($body)) {
            if (!empty($body['errors'][0]['msg'])) $error = (string)$body['errors'][0]['msg'];
            elseif (!empty($body['message'])) $error = (string)$body['message'];
        }
        if ($error === '') $error = 'صفقة ردّت بكود ' . $status;
    }
    return ['ok' => $ok, 'status' => $status, 'body' => $body, 'error' => $error];
}

// ---------------------------------------------------------------------------
//  تطبيع العربي للمطابقة (محافظة/مدينة)
// ---------------------------------------------------------------------------
function safka_norm_ar($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $s);
    $s = str_replace(['ة'], 'ه', $s);
    $s = str_replace(['ى'], 'ي', $s);
    $s = preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $s);     // تشكيل
    $s = preg_replace('/^\s*(محافظة|محافظه|governorate)\s*/u', '', $s);
    $s = preg_replace('/\s*(city|مدينة|مركز)\s*$/u', '', $s);
    $s = preg_replace('/[\s\-_\.\x{060C}\x{061B}]+/u', '', $s);
    return mb_strtolower($s, 'UTF-8');
}
// أقرب تطابق: مطابقة تامة → احتواء → نسبة تشابه
function safka_best_match($needle, $haystack, $min_ratio = 0.72) {
    $n = safka_norm_ar($needle);
    if ($n === '' || !is_array($haystack) || !$haystack) return null;
    $exact = null; $contains = null; $best = null; $best_pct = 0;
    foreach ($haystack as $row) {
        $label = (string)($row['label'] ?? '');
        $h = safka_norm_ar($label);
        if ($h === '') continue;
        if ($h === $n) { $exact = $row; }
        elseif ($contains === null && ($h !== '' && (strpos($h, $n) !== false || strpos($n, $h) !== false))) $contains = $row;
        else {
            similar_text($n, $h, $pct);
            if ($pct > $best_pct) { $best_pct = $pct; $best = $row; }
        }
    }
    if ($exact) return $exact;
    if ($contains) return $contains;
    if ($best && $best_pct >= $min_ratio * 100) return $best;
    return null;
}

// ---------------------------------------------------------------------------
//  قائمة أسعار الشحن (المحافظات + المدن) — فيها معرّفات صفقة
// ---------------------------------------------------------------------------
function safka_price_list($fresh = false) {
    $cached = jload('safka_price_list.json', [], false);
    $at = (int)($cached['at'] ?? 0);
    $rows = (isset($cached['rows']) && is_array($cached['rows'])) ? $cached['rows'] : [];
    if (!$fresh && $rows && (time() - $at) < SAFKA_PRICE_TTL) return $rows;
    if (safka_api_key() === '') return $rows;

    $all = [];
    for ($page = 1; $page <= 10; $page++) {
        $res = safka_http('GET', '/price-list?page=' . $page . '&size=100', null, 15);
        if (!$res['ok']) {
            safka_log(['what' => 'price_list_error', 'error' => $res['error']]);
            return $rows;   // نرجّع الكاش القديم بدل ما نكسر الطلب
        }
        $body = is_array($res['body']) ? $res['body'] : [];
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
        $all = array_merge($all, $data);
        $pages = (int)($body['pages'] ?? 1);
        if ($page >= $pages || !$data) break;
    }
    $clean = [];
    foreach ($all as $row) {
        if (!is_array($row)) continue;
        if (isset($row['is_active']) && $row['is_active'] === false) continue;
        if (empty($row['_id'])) continue;
        $cities = [];
        foreach ((array)($row['cities'] ?? []) as $c) {
            if (!is_array($c) || !isset($c['id'])) continue;
            $cities[] = ['id' => (string)$c['id'], 'label' => (string)($c['city_name_ar'] ?? ''), 'en' => (string)($c['city_name_en'] ?? '')];
        }
        $clean[] = [
            'id'     => (string)$row['_id'],
            'number' => (string)($row['governorate'] ?? ''),
            'label'  => (string)($row['governorateNameAr'] ?? ($row['governorateName'] ?? '')),
            'en'     => (string)($row['governorateName'] ?? ''),
            'price'  => (float)($row['price'] ?? 0),
            'cities' => $cities,
        ];
    }
    if ($clean) {
        jupdate('safka_price_list.json', function (&$st) use ($clean) {
            $st = ['at' => time(), 'rows' => $clean];
            return true;
        }, ['at' => 0, 'rows' => []]);
        return $clean;
    }
    return $rows;
}
function safka_find_governorate($city_name, $rows = null) {
    if ($rows === null) $rows = safka_price_list();
    if (!$rows) return null;
    $labels = [];
    foreach ($rows as $r) { $labels[] = ['label' => $r['label'], 'row' => $r]; if (($r['en'] ?? '') !== '') $labels[] = ['label' => $r['en'], 'row' => $r]; }
    $hit = safka_best_match($city_name, $labels);
    return $hit['row'] ?? null;
}
function safka_find_city($gov_row, $district) {
    if (!$gov_row || empty($gov_row['cities']) || trim((string)$district) === '') return null;
    $labels = [];
    foreach ($gov_row['cities'] as $c) { $labels[] = ['label' => $c['label'], 'row' => $c]; if (($c['en'] ?? '') !== '') $labels[] = ['label' => $c['en'], 'row' => $c]; }
    $hit = safka_best_match($district, $labels);
    return $hit['row'] ?? null;
}

// ---------------------------------------------------------------------------
//  منتج صفقة (عشان نعرف معرّف الخاصية/اللون اللي العميل اختاره)
// ---------------------------------------------------------------------------
function safka_product($pid) {
    $pid = trim((string)$pid);
    if ($pid === '') return null;
    $cache = jload('safka_products.json', [], false);
    $hit = $cache[$pid] ?? null;
    if (is_array($hit) && (time() - (int)($hit['at'] ?? 0)) < SAFKA_PROD_TTL) return $hit['product'] ?? null;
    if (safka_api_key() === '') return is_array($hit) ? ($hit['product'] ?? null) : null;
    $res = safka_http('GET', '/product/' . rawurlencode($pid), null, 12);
    if (!$res['ok'] || !is_array($res['body'])) {
        safka_log(['what' => 'product_error', 'id' => $pid, 'error' => $res['error']]);
        return is_array($hit) ? ($hit['product'] ?? null) : null;
    }
    $prod = $res['body']['product'] ?? $res['body'];
    if (!is_array($prod) || empty($prod['_id'])) return is_array($hit) ? ($hit['product'] ?? null) : null;
    jupdate('safka_products.json', function (&$c) use ($pid, $prod) {
        if (!is_array($c)) $c = [];
        $c[$pid] = ['at' => time(), 'product' => $prod];
        if (count($c) > 300) { $c = array_slice($c, -300, null, true); }   // كاش محدود
        return true;
    }, []);
    return $prod;
}
// اختيار الخاصية: بالمقاس/اللون اللي العميل اختاره، وإلا الخاصية الوحيدة
function safka_pick_property($prod, $order) {
    $props = (isset($prod['properties']) && is_array($prod['properties'])) ? $prod['properties'] : [];
    $props = array_values(array_filter($props, function ($p) { return is_array($p) && !empty($p['_id']); }));
    if (!$props) return null;
    $wanted = [];
    foreach (['color', 'size'] as $k) {
        $v = trim((string)($order[$k] ?? ''));
        if ($v !== '') $wanted[] = $v;
    }
    $variant = (string)($order['variant'] ?? '');
    if ($variant !== '') {
        foreach (preg_split('/[|,\-\/]+/u', $variant) as $piece) {
            $piece = trim(preg_replace('/^\s*(اللون|المقاس|لون|مقاس)\s*[:：]?\s*/u', '', (string)$piece));
            if ($piece !== '') $wanted[] = $piece;
        }
    }
    $labels = [];
    foreach ($props as $p) {
        $labels[] = ['label' => (string)($p['key'] ?? ($p['name'] ?? '')), 'row' => $p];
    }
    foreach ($wanted as $w) {
        $hit = safka_best_match($w, $labels, 0.8);
        if ($hit) return $hit['row'];
    }
    return count($props) === 1 ? $props[0] : $props[0];   // افتراضي: أول خاصية
}

// ---------------------------------------------------------------------------
//  بناء الطلب وإرساله
// ---------------------------------------------------------------------------
function safka_build_payload($order, $gov_row, $city_row = null, $prop_id = '') {
    // 📋 المطلوب من المالك: البيانات الأساسية بس تتسجل عند صفقة —
    // الاسم + الموبايل + الموبايل البديل (لو موجود) + العنوان + الملاحظات.
    // مفيش صفحات (page_name / page_id) ولا عمولة ولا مدينة ولا أي تفاصيل إضافية.
    // اللي فاضل بعد كده هو الحد الأدنى اللي صفقة بترفض إنشاء الطلب من غيره:
    // المحافظة (معرّف من قائمة شحن صفقة) + المنتج/الكمية/الخاصية + الإجمالي (للتحصيل).
    $payload = [
        'client_name'    => mb_substr(trim((string)($order['cname'] ?? '')), 0, 100),
        'client_phone1'  => preg_replace('/\D+/', '', (string)($order['cphone'] ?? '')),
        'client_address' => mb_substr(trim((string)($order['address'] ?? '')), 0, 300),
    ];
    $phone2 = preg_replace('/\D+/', '', (string)($order['cphone2'] ?? ''));
    if ($phone2 !== '') $payload['client_phone2'] = $phone2;
    $notes = trim((string)($order['notes'] ?? ''));
    if ($notes !== '') $payload['note'] = mb_substr($notes, 0, 300);
    $payload['shipping_governorate'] = (string)($gov_row['id'] ?? '');
    $payload['items'] = [[
        'qty'      => (string)max(1, (int)($order['qty'] ?? 1)),
        'product'  => (string)($order['id'] ?? ''),
        'property' => (string)$prop_id,
    ]];
    $payload['total'] = (string)round((float)($order['total'] ?? 0), 2);
    return $payload;
}

// إرسال طلب واحد لصفقة + تحديث سجله في orders.json. بيرجّع مصفوفة النتيجة.
function safka_push_order($order_no, $order = null, $force = false) {
    $order_no = trim((string)$order_no);
    if ($order_no === '') return ['ok' => false, 'state' => 'skipped', 'error' => 'رقم الطلب مش موجود'];
    if ($order === null) {
        foreach ((array)jload('orders.json', [], false) as $idx => $row) {
            if ((string)($row['order_no'] ?? '') === $order_no) { $order = $row; break; }
        }
    }
    if (!is_array($order)) return ['ok' => false, 'state' => 'skipped', 'error' => 'الطلب مش موجود'];
    if ((string)($order['plat'] ?? '') !== 'safqa') {
        return ['ok' => false, 'state' => 'skipped', 'error' => 'الطلب مش من منصة صفقة'];
    }
    if (!$force && !safka_can_push()) return ['ok' => false, 'state' => 'off', 'error' => 'الربط متوقف'];
    if (!$force && (($order['safka']['state'] ?? '') === 'sent')) {
        return ['ok' => true, 'state' => 'sent', 'serial' => (string)($order['safka']['serial'] ?? ''), 'error' => ''];
    }

    $gov = safka_find_governorate((string)($order['city'] ?? ''));
    if (!$gov) {
        $res = ['ok' => false, 'state' => 'failed', 'error' => 'محافظة العميل («' . (string)($order['city'] ?? '') . '») مش متطابقة مع قائمة شحن صفقة'];
        safka_save_state($order_no, $res + ['at' => date('c')]);
        return $res;
    }
    $city_row = safka_find_city($gov, (string)($order['district'] ?? ''));
    $prod = safka_product((string)($order['id'] ?? ''));
    $prop = $prod ? safka_pick_property($prod, $order) : null;
    $payload = safka_build_payload($order, $gov, $city_row, $prop['_id'] ?? '');
    $meta = jload('safka_meta.json', [], false);
    $gov_key = (string)($meta['gov_key'] ?? 'id');   // 'id' أو 'number' حسب اللي صفقة بتقبله

    if (safka_mode() === 'dry' && !$force) {
        $res = ['ok' => true, 'state' => 'dry', 'error' => '', 'payload' => $payload];
        safka_save_state($order_no, $res + ['at' => date('c')]);
        safka_log(['what' => 'dry_run', 'order_no' => $order_no, 'payload' => $payload]);
        return $res;
    }

    $res = safka_http('POST', '/orders', $payload, 20);
    // لو صفقة رفضت معرّف المحافظة: نجرّب الرقم بدل الـ _id ونسجّل اللي بيشتغل
    if (!$res['ok'] && $gov_key === 'id' && !empty($gov['number'])) {
        $payload2 = $payload;
        $payload2['shipping_governorate'] = (string)$gov['number'];
        $res2 = safka_http('POST', '/orders', $payload2, 20);
        if ($res2['ok']) {
            jupdate('safka_meta.json', function (&$m) { $m['gov_key'] = 'number'; return true; }, []);
            $res = $res2; $payload = $payload2;
        }
    }

    $out = ['ok' => (bool)$res['ok'], 'state' => $res['ok'] ? 'sent' : 'failed', 'error' => (string)$res['error'], 'payload' => $payload];
    if ($res['ok'] && is_array($res['body'])) {
        $d = $res['body']['data'] ?? $res['body'];
        $out['serial'] = (string)($d['serial_number'] ?? '');
        $out['id']     = (string)($d['_id'] ?? '');
        $out['status'] = (string)($d['status'] ?? '');
    }
    safka_save_state($order_no, $out + ['at' => date('c')]);
    $log_row = [
        'what'     => $res['ok'] ? 'order_sent' : 'order_failed',
        'order_no' => $order_no,
        'serial'   => $out['serial'] ?? '',
        'error'    => $out['error'],
    ];
    // الـ payload بيتسجل في حالات الفشل بس (وبنسخة مخفي عنها بيانات العميل) عشان التشخيص
    if (!$res['ok']) {
        $log_row['payload'] = $payload;
        $log_row['response'] = is_array($res['body']) ? $res['body'] : mb_substr((string)$res['body'], 0, 800);
    }
    safka_log($log_row);
    return $out;
}

// تحديث حالة الربط جوه سجل الطلب (orders.json) — بيتنادى بعد الحفظ مباشرة
function safka_save_state($order_no, $state) {
    $order_no = trim((string)$order_no);
    if ($order_no === '') return false;
    $state['error'] = mb_substr((string)($state['error'] ?? ''), 0, 300);
    unset($state['payload'], $state['response']);
    $ok = jupdate('orders.json', function (&$orders) use ($order_no, $state) {
        if (!is_array($orders)) return false;
        foreach ($orders as &$o) {
            if ((string)($o['order_no'] ?? '') === $order_no) {
                $o['safka'] = $state;
                $o['safka_state'] = (string)($state['state'] ?? '');
                if (!empty($state['serial'])) $o['safka_serial'] = (string)$state['serial'];
                return true;
            }
        }
        unset($o);
        return false;
    }, []);
    $GLOBALS['_JMEM']['orders.json'] = null;
    unset($GLOBALS['_JMEM']['orders.json']);
    return (bool)$ok;
}

// إخفاء بيانات العميل جوه السجل: بنسيب شكل الطلب للتشخيص من غير الاسم والتليفون كامل
function safka_redact_payload($payload) {
    if (!is_array($payload)) return $payload;
    $p = $payload;
    if (isset($p['client_name']))   $p['client_name']   = mb_substr((string)$p['client_name'], 0, 2) . '***';
    if (isset($p['client_phone1'])) $p['client_phone1'] = '****' . substr((string)$p['client_phone1'], -3);
    if (isset($p['client_phone2'])) $p['client_phone2'] = '****';
    if (isset($p['client_address'])) $p['client_address'] = mb_substr((string)$p['client_address'], 0, 12) . '…';
    return $p;
}

// سجل تشخيص محدود (آخر 200 عملية) — data/safka_log.json — للإدارة فقط (المجلد محمي بالكامل)
function safka_log($entry) {
    if (is_array($entry) && array_key_exists('payload', $entry)) $entry['payload'] = safka_redact_payload($entry['payload']);
    if (!is_array($entry)) return;
    $entry['at'] = date('c');
    jupdate('safka_log.json', function (&$log) use ($entry) {
        if (!is_array($log)) $log = [];
        $log[] = $entry;
        if (count($log) > 200) $log = array_slice($log, -200);
        return true;
    }, []);
}

// فحص سريع للاتصال (بيستخدمه زر الاختبار في الإعدادات) — من غير إنشاء أي طلب
function safka_selftest() {
    $key = safka_api_key();
    if ($key === '') return ['ok' => false, 'error' => 'مفتاح API مش مضبوط'];
    $res = safka_http('GET', '/price-list?page=1&size=1', null, 12);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'status' => $res['status']];
    $rows = safka_price_list(true);
    return ['ok' => true, 'governorates' => count($rows), 'mode' => safka_mode()];
}
