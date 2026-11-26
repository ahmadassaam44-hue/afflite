<?php
// ============================================================================
// config_admin.php — جزء من config.php (السطور 1337–1510 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// ============================================================================
// حساب أدمن حقيقي (role = admin) + تحقق من صلاحيات الإدارة
// ============================================================================
function is_admin_user() {
    $u = current_user();
    if (!$u || empty($u['email'])) return false;
    // Re-check the persistent user record so stale session flags/roles cannot grant access.
    $fresh = find_user($u['email']);
    return is_array($fresh) && (($fresh['role'] ?? '') === 'admin');
}
function require_admin_or_die() {
    if (is_admin_user()) return;
    $u = current_user();
    $role = wf_user_role($u);   // حساب العميل اتلغى: أي حساب مش أدمن = مسوق
    if (!$u) header('Location: ../login.php');
    elseif ($role === 'marketer') header('Location: marketer.php');
    else header('Location: ./');
    exit;
}
// تسجيل نشاط حساس (audit) — موجود كدالة log_audit بالأسفل
function admin_actor() {
    $u = current_user();
    return $u ? ($u['email'] ?? 'unknown') : 'system';
}

// ============================================================================
// كشف البوتات وزواحف السوشيال (لعدم احتسابها زيارات أو كليكات أفلييت)
// ============================================================================
function is_bot_request() {
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return true; // مفيش User-Agent = غالباً سكربت
    if (preg_match('/bot|crawl|spider|slurp|headless|phantom|lighthouse|ptst|curl|wget|python|scrapy|ahrefs|semrush|facebookexternalhit|whatsapp|telegram|preview|monitor|pingdom|uptimerobot|dataprovider|scan|fetcher|validator/i', $ua)) {
        return true;
    }
    return false;
}

// ============================================================================
// وسائل السحب المتاحة للمسوقين (محافظ إلكترونية / انستا باي / بايبال)
// ============================================================================
function payout_methods() {
    return [
        'wallet_vodafone' => ['label' => '📱 فودافون كاش',        'group' => 'محافظ إلكترونية', 'hint' => 'رقم الموبايل (01xxxxxxxxx)',      'pattern' => 'phone', 'min' => 50,  'icon' => '📱'],
        'wallet_orange'   => ['label' => '🟠 أورانج كاش',          'group' => 'محافظ إلكترونية', 'hint' => 'رقم الموبايل (01xxxxxxxxx)',      'pattern' => 'phone', 'min' => 50,  'icon' => '🟠'],
        'wallet_etisalat' => ['label' => '🟢 اتصالات كاش',         'group' => 'محافظ إلكترونية', 'hint' => 'رقم الموبايل (01xxxxxxxxx)',      'pattern' => 'phone', 'min' => 50,  'icon' => '🟢'],
        'wallet_we'       => ['label' => '🔵 وي باي (WE Pay)',     'group' => 'محافظ إلكترونية', 'hint' => 'رقم الموبايل (01xxxxxxxxx)',      'pattern' => 'phone', 'min' => 50,  'icon' => '🔵'],
        'instapay'        => ['label' => '🏦 انستا باي InstaPay',  'group' => 'تحويل بنكي فوري', 'hint' => 'اسم المستخدم في انستا باي (مثال: name@instapay)', 'pattern' => 'instapay', 'min' => 100, 'icon' => '🏦'],
        'bank'            => ['label' => '💳 تحويل بنكي',          'group' => 'تحويل بنكي فوري', 'hint' => 'رقم الحساب / IBAN',              'pattern' => 'iban',  'min' => 200, 'icon' => '💳'],
        'paypal'          => ['label' => '🌍 بايبال PayPal',       'group' => 'دولي',            'hint' => 'إيميل حساب بايبال',              'pattern' => 'email', 'min' => 300, 'icon' => '🌍'],
    ];
}
// التحقق من صحة بيانات السحب حسب الوسيلة
function validate_payout_account($method, $account) {
    $methods = payout_methods();
    if (!isset($methods[$method])) return 'وسيلة السحب غير صحيحة';
    $account = trim((string)$account);
    if ($account === '') return 'لازم تدخل بيانات الحساب / رقم المحفظة';
    switch ($methods[$method]['pattern']) {
        case 'phone':
            $d = preg_replace('/\D+/', '', $account);
            if (!preg_match('/^(002)?01[0125][0-9]{8}$/', $d)) return 'رقم موبايل مصري غير صحيح (لازم 01xxxxxxxxx)';
            break;
        case 'instapay':
            if (!preg_match('/^[a-zA-Z0-9._-]{3,40}@instapay$/i', $account)) return 'صيغة انستا باي غير صحيحة (مثال: yourname@instapay)';
            break;
        case 'iban':
            $d = preg_replace('/[^A-Za-z0-9]/', '', $account);
            if (mb_strlen($d) < 10 || mb_strlen($d) > 34) return 'رقم الحساب / IBAN غير صحيح';
            break;
        case 'email':
            if (!valid_email($account)) return 'إيميل بايبال غير صحيح';
            break;
    }
    return '';
}
function payout_method_label($m) {
    $ms = payout_methods();
    return $ms[$m]['label'] ?? ($m ?: '—');
}
function payout_method_icon($m) {
    $ms = payout_methods();
    return $ms[$m]['icon'] ?? '💳';
}

// تعطيل انتظار Supabase البطيء والاعتماد على الفهرس المحلي السريع (أقل من 30ms)
// الافتراضي محلي — وتقدر تفعّل القراءة المباشرة من Supabase بتعريف USE_SUPABASE = true في secrets.php
if (!defined('USE_SUPABASE')) define('USE_SUPABASE', false);

// استنتاج المنصة من رقم المنتج بدون إظهار اسم الأفلييت في الرابط
function resolve_plat($id, $plat_hint = '') {
    $id = trim((string)$id);
    $plat_hint = trim((string)$plat_hint);
    if ($plat_hint !== '' && in_array($plat_hint, ['timehand', 'safqa', 'vendoor', 'angazny', 'm5zoon', 'shopmart', 'mnfean', 'g2g', 'social'], true)) {
        return $plat_hint;
    }
    if ($id === '') return 'vendoor';
    $idx = jload('id_index.json', []);
    if (isset($idx[$id])) return $idx[$id];
    $dec = rawurldecode($id);
    if (isset($idx[$dec])) return $idx[$dec];
    if (strpos($id, 'dg-') === 0) return 'g2g';
    if (strpos($id, 'hevo-') === 0) return 'social';
    if (preg_match('/-(AE|SA|JO|OM)$/i', $id)) return 'm5zoon';
    return 'vendoor';
}

// الرابط الأساسي للموقع مع دعم HTTPS خلف البروكسي / Cloudflare / InfinityFree
function site_base_url() {
    $forwarded_proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $cf_visitor = strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''));
    $is_https = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '')
             || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
             || ($forwarded_proto === 'https')
             || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on')
             || (strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https')
             || (strpos($cf_visitor, 'https') !== false);
    $scheme = $is_https ? 'https' : 'http';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $root = (strpos($script, '/dashboard/') !== false) ? rtrim(str_replace('\\', '/', dirname(dirname($script))), '/') : rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($root === '/' || $root === '.') $root = '';
    return isset($_SERVER['HTTP_HOST']) ? ($scheme . '://' . $_SERVER['HTTP_HOST'] . $root) : '';
}

// توليد رابط صفحة الهبوط مباشرة (نسبي للأزرار أو كامل للنسخ والمشاركة)
function landing_page_url($id, $ref = '', $extra_comm = 0, $plat = '', $relative = false) {
    $id = trim((string)$id);
    // بدون اسم المنصة في رابط العميل — السيرفر يستنتجها من فهرس المنتجات
    $qs = ['id' => $id];
    if ($ref !== '') $qs['ref'] = (string)$ref;
    // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة يتحط في الرابط
    if ((float)$extra_comm > 0 && !(function_exists('wf_is_digital_plat') && wf_is_digital_plat($plat))) $qs['comm'] = (float)$extra_comm;
    $query = http_build_query($qs);
    if ($relative) {
        $in_dash = (strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/dashboard/') !== false);
        return ($in_dash ? '../landing.php?' : 'landing.php?') . $query;
    }
    $base = site_base_url();
    return ($base !== '' ? $base . '/landing.php?' : 'landing.php?') . $query;
}

// توليد رابط منتج نظيف بدون اسم منصة الأفلييت (مثال: product.php?id=6056)
function prod_url($id_or_p, $in_dash = null, $ref = '') {
    $id = is_array($id_or_p) ? ($id_or_p['id'] ?? '') : $id_or_p;
    if ($in_dash === null) {
        $in_dash = (strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/dashboard/') !== false);
    }
    $prefix = $in_dash ? 'product.php' : 'dashboard/product.php';
    $u = $prefix . '?id=' . urlencode((string)$id);
    if ($ref !== '') $u .= '&ref=' . urlencode((string)$ref);
    return $u;
}

// توليد رابط أفلييت نظيف للمسوق (يفتح صفحة الهبوط للمنتج أو المتجر العام)
function aff_link($id = '', $code = '', $extra_comm = 0, $plat = '') {
    if ($id !== '') {
        return landing_page_url($id, $code, $extra_comm, $plat, false);
    }
    $base = site_base_url();
    return $base . '/index.php' . ($code !== '' ? '?ref=' . urlencode((string)$code) : '');
}

// رابط المنتج على الموقع الأساسي (زي صفحة الهبوط، بس بيفتح صفحة المنتج الكاملة في الموقع).
// بيمر على go.php عشان الضغطة تتسجل للمسوق، وبيوصل لصفحة المنتج بالسعر بعد العمولة (?comm=).
function main_site_product_url($id, $ref = '', $extra_comm = 0) {
    $qs = ['id' => trim((string)$id), 'lp' => '0'];
    if ($ref !== '') $qs['ref'] = (string)$ref;
    if ((float)$extra_comm > 0) $qs['comm'] = (float)$extra_comm;
    $base = site_base_url();
    return ($base !== '' ? $base . '/go.php?' : 'go.php?') . http_build_query($qs);
}

function stats() { return function_exists('site_stats') ? site_stats() : jload('stats.json', []); }

// قراءة سريعة للمنتجات في صفحات القوائم والفلاتر (باستخدام lite_*.json)
