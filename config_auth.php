<?php
// ============================================================================
// config_auth.php — جزء من config.php (السطور 6681–6932 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// ============================================================================
// توفير حساب أدمن حقيقي تلقائياً (مرة واحدة فقط عند أول تحميل بعد الرفع)
// الحساب بيُقرأ من secrets.php — لا توجد كلمة مرور مكتوبة في الكود
// ============================================================================
if (!defined('ADMIN_EMAIL')) define('ADMIN_EMAIL', 'admin@wafirinhalk.com');

function ensure_admin_account() {
    // الـ flag بيقول إن حساب الأدمن اتعمل على Supabase (الإصدار 2).
    // الـ flag القديم (من نظام الملفات قبل Supabase) مش بيكفي، عشان الحساب ما اتعملش على Supabase أصلاً.
    $flag = DATA_DIR . '/_admin_ok.json';
    if (file_exists($flag)) {
        $fl = json_decode((string)@file_get_contents($flag), true);
        if (is_array($fl) && (int)($fl['v'] ?? 0) >= 2) return;
    }
    if (ADMIN_PASS === '' || strpos((string)ADMIN_PASS, 'CHANGE_ME_NOW_') === 0) return;
    if (!usr_sb_ready()) return;

    $email = strtolower((string)ADMIN_EMAIL);
    $found = false;
    if (usr_find_row($email)) {
        // الحساب موجود: نحدّث كلمة السر من secrets.php ونتأكد من الدور
        if (usr_set_password($email, (string)ADMIN_PASS)) {
            update_user($email, function (&$u) { $u['role'] = 'admin'; });
            $found = true;
        }
    } else {
        $found = usr_create_account($email, (string)ADMIN_PASS, [
            'name'            => 'مدير الموقع',
            'role'            => 'admin',
            'at'              => date('c'),
            'balance'         => 0,
            'pending_balance' => 0,
            'status'          => 'approved',
            'ref_code'        => '',
            'margin_pct'      => 15,
            'custom_comm_pct' => 0,
        ]);
    }

    if ($found) {
        @file_put_contents($flag, json_encode(['v' => 2, 'at' => date('c'), 'email' => $email], JSON_UNESCAPED_UNICODE));
        log_audit('admin_account_provisioned', $email, ['by' => 'auto']);
    }
}
ensure_admin_account();

// ============================================================================
// «تذكرني» — دخول تلقائي بكوكي دائم (30 يوم) من غير ما يطلب الإيميل والباسورد تاني
//  - الكوكي: wf_remember = email|token — والتوكن نفسه بيتخزن كـ sha256 في بروفايل
//    المستخدم (جدول profiles على Supabase) فما حدش يقدر يستخرجه من قاعدة البيانات.
//  - التحقق بيقارن hash_equals على الهاش — وكوكي httponly/samesite=Lax/secure على HTTPS.
//  - الخروج (logout.php) بي مسح الكوكي + التوكن من البروفايل.
// ============================================================================
function remember_cookie_secure() {
    return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}
function remember_issue($email) {
    $email = strtolower(trim((string)$email));
    if ($email === '' || !function_exists('update_user')) return false;
    $tok  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $tok);
    update_user($email, function (&$u) use ($hash) { $u['remember_token'] = $hash; });
    $val = rawurlencode($email) . '|' . $tok;
    if (!headers_sent()) {
        setcookie('wf_remember', $val, [
            'expires'  => time() + 30 * 86400,
            'path'     => '/',
            'secure'   => remember_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE['wf_remember'] = $val;
    return true;
}
function remember_clear($email = '') {
    if (isset($_COOKIE['wf_remember']) && !headers_sent()) {
        setcookie('wf_remember', '', ['expires' => time() - 86400, 'path' => '/', 'secure' => remember_cookie_secure(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    unset($_COOKIE['wf_remember']);
    // مسح التوكن من البروفايل كمان عشان أي نسخة قديمة من الكوكي تبطل فورًا
    $email = strtolower(trim((string)$email));
    if ($email !== '' && function_exists('update_user')) {
        update_user($email, function (&$u) { unset($u['remember_token']); });
    }
}
function remember_login() {
    if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['user'])) return;
    if (empty($_COOKIE['wf_remember']) || strpos((string)$_COOKIE['wf_remember'], '|') === false) return;
    [$b64email, $tok] = explode('|', (string)$_COOKIE['wf_remember'], 2);
    $email = strtolower(trim(rawurldecode((string)$b64email)));
    $tok   = trim((string)$tok);
    if ($email === '' || strpos($email, '@') === false || $tok === '') { remember_clear(); return; }
    $u = function_exists('find_user') ? find_user($email) : null;
    if (!$u || empty($u['remember_token']) || !hash_equals((string)$u['remember_token'], hash('sha256', $tok))) {
        remember_clear();
        return;
    }
    // دخول تلقائي: نفس خطوات login.php (تجديد معرّف الجلسة + تثبيتها + منع صلاحيات قديمة)
    session_harden();
    unset($_SESSION['is_admin'], $_SESSION['admin_role'], $_SESSION['admin_gate'], $_SESSION['admin_seen_at'], $_SESSION['admin_impersonator']);
    $_SESSION['user'] = [
        'name'  => (string)($u['name'] ?? $email),
        'email' => $email,
        'role'  => wf_user_role($u),
    ];
    if (!empty($u['ref_code'])) $_SESSION['user']['ref_code'] = (string)$u['ref_code'];
    session_bind();
}

// ============================================================================
//  دخول الإدارة بحساب مستخدم مسجل (Impersonation) + الرجوع الآمن لحساب الأدمن
//  - لا يلمس كلمة سر المستخدم ولا كوكي «تذكرني» الخاص به.
//  - يحتفظ بهوية الأدمن الحقيقية في الجلسة ويتحقق من صلاحيته عند الرجوع.
// ============================================================================
function wf_impersonator_info() {
    if (empty($_SESSION['admin_impersonator']) || !is_array($_SESSION['admin_impersonator'])) return null;
    $imp = $_SESSION['admin_impersonator'];
    if (empty($imp['email'])) return null;
    return $imp;
}
function wf_impersonator_admin() {
    $imp = wf_impersonator_info();
    if (!$imp) return null;
    $adm = function_exists('find_user') ? find_user($imp['email']) : null;
    if (!is_array($adm) || ($adm['role'] ?? '') !== 'admin') {
        unset($_SESSION['admin_impersonator']);
        return null;
    }
    return $adm;
}
function wf_is_impersonating() {
    $cu = current_user();
    if (!$cu || empty($cu['email'])) return false;
    $adm = wf_impersonator_admin();
    if (!$adm) return false;
    return strcasecmp((string)$adm['email'], (string)$cu['email']) !== 0;
}
function wf_impersonate_start($target_email, $return_tab = 'users') {
    $target_email = strtolower(trim((string)$target_email));
    if ($target_email === '') return ['ok' => false, 'error' => 'البريد الإلكتروني مطلوب'];
    $adm = wf_impersonator_admin();
    if (!$adm) {
        if (!is_admin_user()) return ['ok' => false, 'error' => 'غير مصرح بهذا الإجراء'];
        $cu = current_user();
        $adm = $cu ? find_user($cu['email'] ?? '') : null;
    }
    if (!is_array($adm) || ($adm['role'] ?? '') !== 'admin') {
        return ['ok' => false, 'error' => 'غير مصرح بهذا الإجراء'];
    }
    $tgt = find_user($target_email);
    if (!is_array($tgt) || empty($tgt['email'])) {
        return ['ok' => false, 'error' => 'المستخدم غير موجود'];
    }
    $ret = in_array($return_tab, ['users', 'marketers'], true) ? $return_tab : 'users';
    if (strcasecmp((string)$adm['email'], (string)$tgt['email']) === 0) {
        if (wf_is_impersonating()) return wf_impersonate_stop();
        return ['ok' => false, 'error' => 'أنت مسجل بالفعل بهذا الحساب'];
    }
    $tgt_role = wf_user_role($tgt);
    $_SESSION['user'] = [
        'name'  => (string)($adm['name'] ?? $adm['email']),
        'email' => (string)$adm['email'],
        'role'  => 'admin',
    ];
    $_SESSION['admin_role'] = 'super_admin';
    log_audit('admin_impersonate_start', (string)$tgt['email'], [
        'admin' => (string)$adm['email'],
        'name'  => (string)($tgt['name'] ?? ''),
        'role'  => $tgt_role,
    ]);
    session_harden();
    unset(
        $_SESSION['is_admin'],
        $_SESSION['admin_role'],
        $_SESSION['admin_gate'],
        $_SESSION['admin_seen_at'],
        $_SESSION['aff_custom_comm'],
        $_SESSION['aff_ref'],
        $_SESSION['amz_pf'],
        $_SESSION['jmi_pf']
    );
    $_SESSION['admin_impersonator'] = [
        'email'      => (string)$adm['email'],
        'name'       => (string)($adm['name'] ?? 'الإدارة'),
        'started_at' => time(),
        'return_tab' => $ret,
    ];
    $_SESSION['user'] = [
        'name'  => (string)($tgt['name'] ?? $tgt['email']),
        'email' => (string)$tgt['email'],
        'role'  => $tgt_role,
    ];
    if (!empty($tgt['ref_code'])) {
        $_SESSION['user']['ref_code'] = (string)$tgt['ref_code'];
    }
    session_bind();
    $dest = ($tgt_role === 'admin') ? 'admin.php' : 'marketer.php';
    return ['ok' => true, 'dest' => $dest, 'user' => $tgt];
}
function wf_impersonate_stop() {
    $imp = wf_impersonator_info();
    $adm = wf_impersonator_admin();
    if (!$imp || !$adm) {
        unset($_SESSION['admin_impersonator']);
        return ['ok' => false, 'error' => 'لا توجد جلسة دخول بحساب مستخدم نشطة'];
    }
    $prev_email = (string)(current_user()['email'] ?? '-');
    $ret = in_array($imp['return_tab'] ?? '', ['users', 'marketers'], true) ? $imp['return_tab'] : 'users';
    session_harden();
    unset(
        $_SESSION['admin_impersonator'],
        $_SESSION['aff_custom_comm'],
        $_SESSION['aff_ref'],
        $_SESSION['amz_pf'],
        $_SESSION['jmi_pf']
    );
    if (isset($_COOKIE['aff_ref']) && !headers_sent()) {
        @setcookie('aff_ref', '', time() - 86400, '/');
    }
    unset($_COOKIE['aff_ref']);
    $_SESSION['user'] = [
        'name'  => (string)($adm['name'] ?? $adm['email']),
        'email' => (string)$adm['email'],
        'role'  => 'admin',
    ];
    if (!empty($adm['ref_code'])) {
        $_SESSION['user']['ref_code'] = (string)$adm['ref_code'];
    }
    $_SESSION['admin_role'] = 'super_admin';
    session_bind();
    log_audit('admin_impersonate_end', $prev_email, [
        'admin' => (string)$adm['email'],
    ]);
    return ['ok' => true, 'dest' => 'admin.php?tab=' . $ret];
}
function wf_impersonation_banner_state($dash_pre = null) {
    if (!wf_is_impersonating()) return null;
    if ($dash_pre === null) {
        $sn = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dash_pre = (strpos($sn, '/dashboard/') !== false) ? '' : 'dashboard/';
    }
    $cu  = current_user();
    $imp = wf_impersonator_info();
    return [
        'user_name'  => (string)($cu['name'] ?? ($cu['email'] ?? '')),
        'user_email' => (string)($cu['email'] ?? ''),
        'admin_name' => (string)($imp['name'] ?? 'الأدمن'),
        'action'     => $dash_pre . 'admin.php',
        'csrf'       => csrf_token(),
    ];
}
