<?php
// ============================================================================
// config_security.php — جزء من config.php (السطور 1091–1336 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// ============================================================================
// جلسة آمنة: كوكي HttpOnly + Secure + SameSite=Lax، واسم جلسة مخصص
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    $forwarded_proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $cf_visitor = strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''));
    $is_https = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '')
             || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
             || ($forwarded_proto === 'https')
             || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on')
             || (strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https')
             || (strpos($cf_visitor, 'https') !== false);
    // منع تسريب أي معلومات خطأ للزائر (مسارات الملفات إلخ)
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
    if (!is_dir(DATA_DIR . '/logs')) @mkdir(DATA_DIR . '/logs', 0755, true);
    $__logf = DATA_DIR . '/logs/php_errors.log';
    if (is_dir(DATA_DIR . '/logs')) @ini_set('error_log', $__logf);
    @error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    session_name('WAFSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}
// طلبات الـ AJAX (fetch): أي تحذير PHP مطبوع كان بيكسر رد الـ JSON فيظهر للمستخدم
// «تعذر تأكيد استجابة الخادم» رغم إن الطلب اتسجل — نخفي العرض ونكتفي باللوج.
if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
}
// صفحات النماذج/التوكنات لا تُخزّن مؤقتاً حتى لا يُعاد نموذج بتوكن جلسة قديمة أو مشتركة.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}
// تجديد معرّف الجلسة بعد الدخول (منع Session Fixation)
function session_harden() {
    if (session_status() === PHP_SESSION_ACTIVE) @session_regenerate_id(true);
}
// تنظيف جلسة قديمة وتثبيت IP/UA لتقليل سرقة الجلسة
function session_bind() {
    $sig = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120));
    if (empty($_SESSION['_bind'])) { $_SESSION['_bind'] = $sig; return true; }
    return hash_equals((string)$_SESSION['_bind'], $sig);
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// طبقة تخزين JSON فُصلت إلى json_store.php (تحميل مشروط برسالة واضحة لو الملف ناقص
// أثناء رفع جزئي، بدل خطأ فادح غامض).
if (is_readable(__DIR__ . "/json_store.php")) {
    require_once __DIR__ . "/json_store.php";
} else {
    throw new RuntimeException("json_store.php missing: storage layer unavailable");
}

// ============================================================================
// حماية CSRF — توليد توكن + حقنه تلقائياً في كل فورم POST + تحقق تلقائي
// ============================================================================
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . esc(csrf_token()) . '">';
}
function csrf_ok() {
    $tok = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sess = (string)($_SESSION['csrf_token'] ?? '');
    return $sess !== '' && $tok !== '' && hash_equals($sess, $tok);
}
// ملفات معفاة (واجهات JSON تُستدعى بـ fetch من نفس الأصل + صفحات الضيف)
function csrf_exempt() {
    $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return in_array($s, ['api_events.php', 'api_image_search.php', 'go.php'], true);
}

// نُقلت wf_asset_v إلى هنا من قسم الأصول (كانت سطر 4488) لأن صفحة رفض
// CSRF (أعلاه في هذا الملف) تستدعيها وقت التحميل، قبل تحميل قسم الأصول.
// إصدار تلقائي للأصول الثابتة: رقم تعديل الملف نفسه — أي نشر جديد بيغيّر الرابط فورًا
// فالمتصفحات من الجلسات القديمة بتسحب CSS/JS الجديد من أول تحميل صفحة (من غير bumps يدوية).
function wf_asset_v($name) {
    static $memo = [];
    $name = basename((string)$name);
    if (!isset($memo[$name])) {
        $f = __DIR__ . '/assets/' . $name;
        $m = is_file($f) ? (int)@filemtime($f) : 0;
        $memo[$name] = (string)($m > 0 ? $m : 20261021);
    }
    return $memo[$name];
}
// تحقق تلقائي من كل طلب POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_exempt() && !csrf_ok()) {
    $is_ajax = (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
            || (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false);
    if (!headers_sent()) http_response_code(403);
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'CSRF token غير صالح — حدّث الصفحة وحاول تاني'], JSON_UNESCAPED_UNICODE);
    } else {
        // Presentation only: preserve the exact 403/CSRF decision above.
        require_once __DIR__ . '/ui-components.php';
        $ui_asset_pre = strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/dashboard/') !== false ? '../' : '';
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>طلب مرفوض | ' . esc(BRAND) . '</title><link rel="stylesheet" href="' . $ui_asset_pre . 'assets/style.css?v=' . wf_asset_v('style.css') . '"></head><body><a class="skip-link" href="#csrfErrorContent">تخطي إلى الرسالة</a><main class="auth" id="csrfErrorContent">';
        wf_ui_sprite();
        echo '<div class="alert danger">' . wf_icon('shield') . '<div><h1>طلب مرفوض (CSRF)</h1><p>التوكن الأمني غير صالح أو انتهت صلاحيته. ارجع للصفحة، حدّثها وحاول مرة أخرى.</p></div></div><a class="btn outline" href="javascript:history.back()">رجوع</a></main></body></html>';
    }
    exit;
}
// حقن التوكن تلقائياً داخل كل <form method="post"> عند إخراج الصفحة عبر ob_start callback آمن
if (PHP_SAPI !== 'cli' && !csrf_exempt()) {
    // Keep pages working during a rolling FTP upload before a new helper has arrived.
    if (is_readable(__DIR__ . '/ui-runtime.php')) {
        require_once __DIR__ . '/ui-runtime.php';
        wf_ui_runtime_start();
    }
    $__csrf_tok = csrf_token();
    @ob_start(function ($buf) use ($__csrf_tok) {
        if (!is_string($buf) || $buf === '' || stripos($buf, '<form') === false) {
            return $buf;
        }
        $tok = !empty($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : (string)$__csrf_tok;
        if ($tok === '') return $buf;
        $field = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($tok, ENT_QUOTES, 'UTF-8') . '">';
        $out = @preg_replace_callback('~<form\b[^>]*>~i', function ($m) use ($field) {
            $tag = $m[0];
            if (stripos($tag, 'method') !== false && !preg_match('~method\s*=\s*["\']?post~i', $tag)) return $tag;
            if (stripos($tag, 'csrf_token') !== false) return $tag;
            return $tag . $field;
        }, $buf);
        return (is_string($out) && $out !== '') ? $out : $buf;
    });
}

// ============================================================================
// تحديد معدل المحاولات (Rate Limiting) — تسجيل الدخول / الأدمن / الاستمارات
// ============================================================================
function rate_limit_key($bucket) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $bucket) . '_' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
}
function rate_limit_check($bucket, $max = 5, $window = 900) {
    $k = rate_limit_key($bucket);
    return jupdate('ratelimit.json', function (&$d) use ($k, $max, $window) {
        $now = time();
        if (!is_array($d)) $d = [];
        $d = array_filter($d, function ($v) use ($now) { return is_array($v) && ($now - (int)($v['t'] ?? 0)) < 86400; });
        $rec = $d[$k] ?? ['n' => 0, 't' => 0];
        if (($now - (int)$rec['t']) > $window) { $rec = ['n' => 0, 't' => $now]; }
        if ((int)$rec['n'] >= $max) {
            $wait = max(1, $window - ($now - (int)$rec['t']));
            $d[$k] = $rec;
            return $wait;
        }
        $rec['n'] = (int)$rec['n'] + 1;
        $d[$k] = $rec;
        return 0;
    });
}
function rate_limit_reset($bucket) {
    $k = rate_limit_key($bucket);
    jupdate('ratelimit.json', function (&$d) use ($k) {
        if (!is_array($d)) $d = [];
        unset($d[$k]);
        return true;
    });
}
function fmt_wait($sec) {
    $m = (int)floor($sec / 60); $s = $sec % 60;
    return $m > 0 ? ($m . ' دقيقة و' . $s . ' ثانية') : ($s . ' ثانية');
}

// ============================================================================
// تحقق من قوة كلمة المرور + صحة الإيميل + تسجيل روابط الاستعادة للإدارة
// ============================================================================
function weak_password_reason($pass) {
    $pass = (string)$pass;
    if (mb_strlen($pass) < 8) return 'كلمة المرور لازم 8 أحرف على الأقل';
    if (mb_strlen($pass) > 200) return 'كلمة المرور طويلة جداً';
    if (!preg_match('/[A-Za-z\x{0600}-\x{06FF}]/u', $pass)) return 'كلمة المرور لازم تحتوي حرف واحد على الأقل';
    if (!preg_match('/[0-9]/u', $pass)) return 'كلمة المرور لازم تحتوي رقم واحد على الأقل';
    $weak = ['12345678','123456789','password','1234567890','qwerty123','abc12345','11111111','00000000','iloveyou','admin123'];
    if (in_array(strtolower($pass), $weak, true)) return 'كلمة المرور دي سهلة جداً — اختار غيرها';
    return '';
}

function valid_email($email) {
    $email = trim((string)$email);
    if (mb_strlen($email) > 190) return false;
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

// حفظ رابط الاستعادة في ملف خاص (احتياطي لو الإيميل مش شغال على الاستضافة)
function log_reset_link($email, $link) {
    $rl = jload('reset_links.json', []);
    if (!is_array($rl)) $rl = [];
    $rl[] = [
        'email' => $email,
        'link'  => $link,
        'ip'    => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'exp'   => time() + 3600,
        'at'    => date('c'),
    ];
    jsave('reset_links.json', array_slice($rl, -50));
}

// إرسال إيميل عن طريق Supabase Edge Function (Resend). بيرجع true لو الإيميل اتقبل للإرسال.
// ملاحظة: تفاصيل الفشل بتتسجل في لوج السيرفر بس، ومتظهرش للمستخدم.
function wf_send_mail($to, $subject, $text) {
    if (!defined('MAIL_FN_SECRET') || MAIL_FN_SECRET === '') {
        error_log('wf_send_mail: MAIL_FN_SECRET is not configured (secrets.php)');
        return false;
    }
    $body = json_encode(['to' => (string)$to, 'subject' => (string)$subject, 'text' => (string)$text], JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json', 'x-mail-secret: ' . MAIL_FN_SECRET];
    // Supabase Edge Functions بتطلب مفتاح المشروع (anon) في الهيدر افتراضياً قبل ما توصل للكود
    if (defined('SB_KEY') && SB_KEY !== '') { $headers[] = 'apikey: ' . SB_KEY; $headers[] = 'Authorization: Bearer ' . SB_KEY; }
    $code = 0; $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init(MAIL_FN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents(MAIL_FN_URL, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    if ($resp === false || $code !== 200) {
        error_log('wf_send_mail failed: HTTP ' . $code);
        return false;
    }
    return true;
}
