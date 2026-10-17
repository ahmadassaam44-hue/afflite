<?php
// ============================================================================
//  🛍️ رابط التتبع القصير لأفلييت جوميا: jmi.php?a=azXXXXXXXX&sub=اسم_الحملة
//  - يحتسب النقرة للمسوق (مع فلترة البوتات وتكرار نفس الزائر خلال 24 ساعة)
//  - يربط الزائر بالمسوق 30 يوم (لو اشتري من المتجر بعدين)
//  - يحوّله فوراً على جوميا بلينك التتبع بتاع الموقع
// ============================================================================
require_once __DIR__ . '/config.php';

$id  = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['a'] ?? ''));
$sub = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['sub'] ?? '')), 0, 30);

$link = ($id !== '') ? jmi_link($id) : null;
if (!$link || !jmi_enabled()) {
    header('Location: index.php');
    exit;
}

$target = jmi_out_url($link, $sub);

// الكروت الوهمية والزواحف: تحويل مباشر بدون احتساب نقرة
if (is_bot_request()) {
    header('Location: ' . $target, true, 302);
    exit;
}

// حد أقصى للنقرات لكل IP في الساعة (ضد نفخ الأرقام)
$blocked = (rate_limit_check('jmi_click_' . $id, 150, 3600) > 0);
if (!$blocked) {
    jmi_record_click($id, $sub);
}

// ربط الزائر بكود المسوق — أي شراء من المتجر خلال 30 يوم يُنسب له
$ref = (string)($link['ref'] ?? '');
if ($ref !== '' && find_marketer_by_code($ref)) {
    $_SESSION['aff_ref'] = $ref;
    if (!headers_sent()) {
        @setcookie('aff_ref', $ref, time() + 30 * 86400, '/');
    }
}

// ?raw=1 → معاينة اللينك النهائي بدون تحويل (مفيد للاختبار)
if (!empty($_GET['preview']) && function_exists('is_admin_user') && is_admin_user()) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "رقم المنتج: " . ($link['pid'] ?? '') . "\n";
    echo "MKT : " . ($link['mkt'] ?? '') . "\n";
    echo "AFF : " . jmi_aff_url_for($link) . "\n";
    echo "URL : " . $target . "\n";
    exit;
}

header('Location: ' . $target, true, 302);
exit;
