<?php
// A presentation-only safety net. Keep fatal errors private and logged; never retry a POST.
// This is an output-buffer callback, not a shutdown handler, and does not intercept normal responses.
// تسجيل آخر الأخطاء الفادحة في ملف صغير (يظهر للمدير فقط) — أفضل جهد بدون تعطيل الصفحة
function wf_ui_log_fatal($msg, $file, $line) {
    static $busy = false;
    if ($busy) return;
    $busy = true;
    $dir = __DIR__ . '/data';
    if (!is_dir($dir) || !is_writable($dir)) { $busy = false; return; }
    $path = $dir . '/wf_errors.json';
    if (is_file($path) && (int)@filesize($path) > 400000) @unlink($path);
    $row = [
        'at'   => date('c'),
        'msg'  => substr(preg_replace('/\s+/', ' ', (string)$msg), 0, 300),
        'file' => basename((string)$file) . ':' . (int)$line,
        'uri'  => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 180),
    ];
    $fp = @fopen($path, 'c+b');
    if (!$fp) { $busy = false; return; }
    if (@flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $d = json_decode($raw ?: '', true);
        if (!is_array($d)) $d = [];
        $d[] = $row;
        if (count($d) > 30) $d = array_slice($d, -30);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        @flock($fp, LOCK_UN);
    }
    @fclose($fp);
    $busy = false;
}

function wf_ui_runtime_start() {
    static $started = false;
    if ($started || PHP_SAPI === 'cli') return;
    $started = true;
    // Keep a small response budget for fatal errors when PHP can still flush output buffers.
    // OOM can discard buffers entirely; the catalogue lookup fix prevents the reproduced OOM.
    $reserve = str_repeat(' ', 262144);
    ob_start(function ($buffer, $phase) use (&$reserve) {
        if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) return $buffer;
        // Free this before even reading error_get_last(), which can itself allocate during OOM.
        $reserve = null;
        $last = error_get_last();
        if (!$last || !in_array($last['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return $buffer;
        }
        // تسجيل الخطأ الفادح (بدون أي تفاصيل للزائر) حتى يعرف المدير سبب صفحة الخطأ
        wf_ui_log_fatal((string)($last['message'] ?? ''), (string)($last['file'] ?? ''), (int)($last['line'] ?? 0));
        $content_type = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) $content_type = strtolower($header);
        }
        $is_json = strpos($content_type, 'application/json') !== false
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || !empty($_POST['ajax_order']) || !empty($_POST['ajax']) || !empty($_GET['ajax']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Cache-Control: no-store, private');
            if ($is_json) header('Content-Type: application/json; charset=utf-8');
            elseif ($content_type === '' || strpos($content_type, 'text/html') !== false) header('Content-Type: text/html; charset=utf-8');
        }
        if ($is_json) {
            return '{"ok":false,"error":"تعذر إكمال الطلب الآن. حاول مرة أخرى بعد قليل."}';
        }
        // Preserve non-HTML downloads/API responses rather than corrupting them with markup.
        if ($content_type !== '' && strpos($content_type, 'text/html') === false) return $buffer;
        $pre = strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/dashboard/') !== false ? '../' : '';
        return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تعذر تحميل الصفحة</title><style>body{margin:0;background:#f8fafc;color:#0f172a;font:18px/1.8 Tahoma,Arial,sans-serif}main{box-sizing:border-box;max-width:640px;margin:12vh auto;padding:32px;background:white;border:1px solid #cbd5e1;border-radius:16px}h1{font-size:26px}a{display:inline-flex;align-items:center;min-height:44px;margin:8px 0 8px 12px;padding:6px 18px;border:1px solid #0f172a;border-radius:8px;color:#0f172a;text-decoration:none}a:focus-visible{outline:3px solid #2563eb;outline-offset:3px}@media(max-width:700px){main{margin:32px 16px;padding:24px}}</style></head><body><main role="main"><h1>تعذر تحميل الصفحة مؤقتًا</h1><p>حدث خطأ أثناء التحميل. يمكنك الرجوع للمتجر أو مراجعة طلباتك.</p><nav aria-label="خطوات المتابعة"><a href="' . $pre . 'index.php">العودة للمتجر</a><a href="' . $pre . 'dashboard/orders.php">مراجعة طلباتي</a></nav></main></body></html>';
    });
}
