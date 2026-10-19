<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($ua !== '' && preg_match('/bot|crawl|spider|slurp|headless|phantom|lighthouse|ptst/i', $ua)) {
    echo json_encode(['ok' => false, 'bot' => true]);
    exit;
}

// حماية من السبام: 90 حدث كحد أقصى لكل IP في الدقيقة
if (rate_limit_check('events', 90, 60) > 0) {
    echo json_encode(['ok' => false, 'rate_limited' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$req = json_decode($raw ?: '{}', true);
if (!is_array($req)) $req = [];

$type = trim((string)($req['type'] ?? $_POST['type'] ?? ''));
$vid = trim((string)($req['visitor_id'] ?? $_POST['visitor_id'] ?? visitor_id()));
if (!preg_match('/^[a-zA-Z0-9_\-]{6,64}$/', $vid)) $vid = visitor_id();
$_SESSION['wf_vid'] = $vid;
if (!headers_sent()) {
    @setcookie('wf_vid', $vid, time() + 365 * 86400, '/', '', false, true);
}

$cu = current_user();
$user_id = $cu['email'] ?? '';
$product_id = trim((string)($req['product_id'] ?? $_POST['product_id'] ?? ''));
$marketer_id = trim((string)($req['marketer_id'] ?? $_POST['marketer_id'] ?? active_ref()));
$referrer = mb_substr(trim((string)($req['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? ''))), 0, 180);
$utm_source = mb_substr(trim((string)($req['utm_source'] ?? '')), 0, 60);
$meta = visitor_meta();
$now = time();
$today = date('Y-m-d');
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 32);

// 1. تحديث التواجد اللحظي (Realtime Presence) وزوار الموقع
$an = jload('analytics.json', []);
if (!is_array($an)) $an = [];
if (!isset($an['visitors']) || !is_array($an['visitors'])) $an['visitors'] = [];
if (!isset($an['daily']) || !is_array($an['daily'])) $an['daily'] = [];
if (!isset($an['pages']) || !is_array($an['pages'])) $an['pages'] = [];
if (!isset($an['sources']) || !is_array($an['sources'])) $an['sources'] = [];
if (!isset($an['devices']) || !is_array($an['devices'])) $an['devices'] = [];

$is_new_v = !isset($an['visitors'][$vid]);
if ($is_new_v) {
    $src_lbl = $utm_source !== '' ? $utm_source : $meta['source'];
    $an['sources'][$src_lbl] = (int)($an['sources'][$src_lbl] ?? 0) + 1;
    $an['devices'][$meta['device']] = (int)($an['devices'][$meta['device']] ?? 0) + 1;
    $an['visitors'][$vid] = [
        'first' => date('c', $now),
        'last' => date('c', $now),
        'last_ts' => $now,
        'hits' => 1,
        'user' => $user_id,
        'name' => $cu['name'] ?? '',
        'device' => $meta['device'],
        'browser' => $meta['browser'],
        'source' => $src_lbl,
        'last_page' => $req['page_title'] ?? $meta['page'],
        'last_uri' => mb_substr((string)($req['uri'] ?? ''), 0, 120),
        'ip' => $ip,
    ];
} else {
    $an['visitors'][$vid]['last'] = date('c', $now);
    $an['visitors'][$vid]['last_ts'] = $now;
    if ($type === 'page_view' || $type === 'product_view') {
        $an['visitors'][$vid]['hits'] = (int)($an['visitors'][$vid]['hits'] ?? 1) + 1;
    }
    if (!empty($req['page_title'])) $an['visitors'][$vid]['last_page'] = mb_substr((string)$req['page_title'], 0, 60);
    if (!empty($req['uri'])) $an['visitors'][$vid]['last_uri'] = mb_substr((string)$req['uri'], 0, 120);
    if ($user_id !== '') $an['visitors'][$vid]['user'] = $user_id;
    if (!empty($cu['name'])) $an['visitors'][$vid]['name'] = $cu['name'];
}

if (!isset($an['daily'][$today])) {
    $an['daily'][$today] = ['views' => 0, 'prod_views' => 0, 'uv' => [], 'carts' => 0, 'loves' => 0, 'checkouts' => 0, 'orders' => 0];
}
if (!isset($an['daily'][$today]['uv']) || !is_array($an['daily'][$today]['uv'])) $an['daily'][$today]['uv'] = [];
// الزائر اليومي الفريد بالـ IP: نفس الشخص (نفس الشبكة) = مرة واحدة في اليوم، ويتحسب من جديد تاني يوم
$day_key = function_exists('wf_daily_visitor_key') ? wf_daily_visitor_key() : $vid;
$an['daily'][$today]['uv'][$day_key] = 1;

if ($type === 'page_view') {
    $an['total_pageviews'] = (int)($an['total_pageviews'] ?? 0) + 1;
    $an['daily'][$today]['views'] = (int)($an['daily'][$today]['views'] ?? 0) + 1;
} elseif ($type === 'product_view') {
    $an['daily'][$today]['prod_views'] = (int)($an['daily'][$today]['prod_views'] ?? 0) + 1;
} elseif ($type === 'add_to_cart') {
    $an['daily'][$today]['carts'] = (int)($an['daily'][$today]['carts'] ?? 0) + 1;
} elseif ($type === 'like') {
    $an['daily'][$today]['loves'] = (int)($an['daily'][$today]['loves'] ?? 0) + 1;
} elseif ($type === 'checkout') {
    $an['daily'][$today]['checkouts'] = (int)($an['daily'][$today]['checkouts'] ?? 0) + 1;
} elseif ($type === 'order') {
    $an['daily'][$today]['orders'] = (int)($an['daily'][$today]['orders'] ?? 0) + 1;
}

jsave('analytics.json', $an);

// 2. تحديث عدادات المنتجات الجاهزة (product_counters.json) لتجنب COUNT(*) الثقيل
if ($product_id !== '' && in_array($type, ['product_view', 'add_to_cart', 'like', 'unlike', 'order'], true)) {
    $pc = jload('product_counters.json', []);
    if (!is_array($pc)) $pc = [];
    if (!isset($pc[$product_id])) {
        $pc[$product_id] = ['views' => 0, 'likes' => 0, 'carts' => 0, 'orders' => 0];
    }
    if ($type === 'product_view') $pc[$product_id]['views']++;
    elseif ($type === 'add_to_cart') $pc[$product_id]['carts']++;
    elseif ($type === 'like') $pc[$product_id]['likes']++;
    elseif ($type === 'unlike') $pc[$product_id]['likes'] = max(0, $pc[$product_id]['likes'] - 1);
    elseif ($type === 'order') $pc[$product_id]['orders']++;
    jsave('product_counters.json', $pc);
}

// 3. عدّاد نقرات المسوق (بدون أي تنبيهات — عدّ عادي)
if ($marketer_id !== '' && in_array($type, ['page_view', 'product_view', 'aff_click'], true)) {
    $mc = jload('marketer_clicks.json', []);
    if (!is_array($mc)) $mc = [];
    if (!isset($mc[$marketer_id])) {
        $mc[$marketer_id] = ['total_clicks' => 0, 'unique_visitors' => [], 'recent_ips' => []];
    }
    $mc[$marketer_id]['total_clicks'] = (int)($mc[$marketer_id]['total_clicks'] ?? 0) + 1;
    $mc[$marketer_id]['unique_visitors'][$vid] = $now;
    jsave('marketer_clicks.json', $mc);
}

// 4. حفظ الحدث في جدول events المحلي (آخر 3000 حدث)
if ($type !== 'presence_ping' && $type !== '') {
    $evs = jload('events.json', []);
    if (!is_array($evs)) $evs = [];
    $evs[] = [
        'id' => uniqid('ev_'),
        'type' => $type,
        'product_id' => $product_id,
        'marketer_id' => $marketer_id,
        'visitor_id' => $vid,
        'user_id' => $user_id,
        'referrer' => $referrer,
        'utm_source' => $utm_source !== '' ? $utm_source : $meta['source'],
        'device' => $meta['device'],
        'country' => 'EG',
        'created_at' => date('c', $now)
    ];
    jsave('events.json', array_slice($evs, -3000));
}

echo json_encode(['ok' => true, 'visitor_id' => $vid]);
