<?php
require_once __DIR__ . '/config.php';

// تنظيف جلسة آمن: تفريغ البيانات + حذف الكوكي + تدمير الجلسة
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    @setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
}
@session_destroy();

// منع الكاش للصفحات المحمية بعد الخروج
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: index.php');
exit;
