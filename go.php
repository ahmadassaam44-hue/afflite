<?php require_once 'config.php';

// ============================================================================
// حماية كليكات الأفلييت: منع تضخيم الأرقام (Bot filter + Cookie dedup + IP limit)
// ============================================================================
$ref = active_ref($_GET['ref'] ?? '');
$id  = trim((string)($_GET['id'] ?? ''));
$plat = trim((string)($_GET['plat'] ?? ''));
// sub-ID / حملة: يسمح للمسوق يعرف أنهي بوست/قناة جابت المبيعات (?sub=fb_post1)
$sub = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['sub'] ?? ($_GET['utm_campaign'] ?? '')));
$sub = substr($sub, 0, 40);
if ($sub !== '') { $_SESSION['aff_sub'] = $sub; @setcookie('aff_sub', $sub, time() + 30 * 86400, '/', '', false, true); }

// 1) تحقق إن كود المسوق حقيقي (منع أي ref وهمي)
if ($ref !== '' && !find_marketer_by_code($ref)) $ref = '';

// 2) تجاهل البوتات وزواحف السوشيال من احتساب النقرات مع توجيهها لنفس صفحة الهبوط للمعاينة
$is_bot = is_bot_request();

// 3) حد أقصى للنقرات لكل IP في الساعة (ضد نفخ الأرقام)
$ip_blocked = $is_bot || (rate_limit_check('aff_click_' . $ref, 120, 3600) > 0);

// 4) كوكي 24 ساعة: نفس الزائر يُحتسَب مرة واحدة فقط لكل كود مسوق
$cookie_name = 'wf_ref_' . preg_replace('/[^A-Za-z0-9_]/', '', $ref);
$already = !empty($_COOKIE[$cookie_name]);
if (!$is_bot && !$already && $ref !== '') {
    @setcookie($cookie_name, '1', time() + 86400, '/', '', false, true);
}

$should_log = (!$is_bot && $ref !== '' && !$already && !$ip_blocked);
if ($should_log) {
    $clicks = jupdate('clicks.json', function (&$c) use ($ref) {
        if (!is_array($c)) $c = [];
        $c[] = [
            'ref'  => $ref,
            'sub'  => $sub,
            'ip'   => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'ua'   => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            'uri'  => mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 200),
            'at'   => date('c'),
        ];
        if (count($c) > 5000) $c = array_slice($c, -5000);
        return true;
    });

    // عدّاد نقرات المسوق للوحة التحليلات
    jupdate('marketer_clicks.json', function (&$mc) use ($ref) {
        if (!is_array($mc)) $mc = [];
        if (!isset($mc[$ref])) $mc[$ref] = ['total_clicks' => 0, 'unique_visitors' => [], 'recent_ips' => []];
        $mc[$ref]['total_clicks'] = (int)($mc[$ref]['total_clicks'] ?? 0) + 1;
        $vid = function_exists('visitor_id') ? visitor_id() : session_id();
        $mc[$ref]['unique_visitors'][$vid] = time();
        if (count($mc[$ref]['unique_visitors']) > 20000) {
            $mc[$ref]['unique_visitors'] = array_slice($mc[$ref]['unique_visitors'], -10000, null, true);
        }
        return true;
    });
}

$comm_param = (isset($_GET['comm']) && is_numeric($_GET['comm']) && (float)$_GET['comm'] >= 0) ? (float)$_GET['comm'] : null;
if ($id !== '') {
    $resolved_plat = resolve_plat($id, $plat);
    $target = (isset($_GET['lp']) && (string)$_GET['lp'] === '0') ? 'dashboard/product.php' : 'landing.php';
    $u = $target . '?id=' . urlencode($id);
    if ($ref !== '') $u .= '&ref=' . urlencode($ref);
    if ($comm_param !== null) $u .= '&comm=' . urlencode((string)$comm_param);
    header('Location: ' . $u);
    exit;
}
if (!empty($_GET['store']) && $ref !== '') {
    header('Location: store.php?store=' . urlencode($ref));
    exit;
}
header('Location: index.php' . ($ref !== '' ? '?ref=' . urlencode($ref) : ''));
exit;
