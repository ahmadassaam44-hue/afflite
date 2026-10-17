<?php
// ============================================================================
// حسابات المستخدمين على Supabase
//  - بيانات الدخول (الإيميل + كلمة السر المشفّرة) في Supabase Auth
//  - بيانات الحساب (الاسم، الدور، الرصيد، الموبايل، المحافظة ...) في جدول public.profiles
//    (عمود data من نوع jsonb). الجدول محمي بـ RLS من غير سياسات، فالمفتاح العام (anon) مش بيوصله.
//  - كل القراءة والكتابة من السيرفر بتتم بمفتاح الخدمة SB_SERVICE (في secrets.php بس، مش في Git)
// الواجهة القديمة (users / find_user / update_user) متحافظ عليها في config.php عشان باقي الكود.
// ============================================================================

if (!defined('SB_SERVICE')) define('SB_SERVICE', '');

function usr_sb_ready() {
    return (defined('SB_URL') && SB_URL !== '') && SB_SERVICE !== '';
}

// طلب HTTP للـ Supabase: curl، ومعاه fallback لـ stream لو curl مش متاح على الاستضافة
function usr_http($method, $url, array $headers, $body = null, $timeout = 8) {
    $headers[] = 'Content-Type: application/json';
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE);
    $code = 0; $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ];
        if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = $payload;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => (string)$payload,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    if ($resp === false) {
        error_log('usr_http: no response for ' . $method . ' ' . strtok($url, '?'));
        return [0, null];
    }
    $data = json_decode((string)$resp, true);
    return [$code, $data];
}

function usr_svc_headers() { return ['apikey: ' . SB_SERVICE, 'Authorization: Bearer ' . SB_SERVICE]; }
function usr_anon_headers() { return ['apikey: ' . SB_KEY, 'Authorization: Bearer ' . SB_KEY]; }

function usr_cache_reset() { $GLOBALS['_USR_ROWS'] = null; $GLOBALS['_USR_ONE'] = []; }

function usr_query($query) {
    [$code, $rows] = usr_http('GET', SB_URL . '/rest/v1/profiles?' . $query, usr_svc_headers());
    if ($code !== 200 || !is_array($rows)) {
        error_log('usr_query: HTTP ' . $code);
        return null;
    }
    return $rows;
}

// كل الصفوف (بتتقرا مرة واحدة في الطلب، وبتتمسح بعد أي كتابة)
function usr_all_rows() {
    if (isset($GLOBALS['_USR_ROWS']) && is_array($GLOBALS['_USR_ROWS'])) return $GLOBALS['_USR_ROWS'];
    $all = []; $off = 0; $page = 1000;
    while (true) {
        $rows = usr_query('select=id,email,data,updated_at&order=email.asc&limit=' . $page . '&offset=' . $off);
        if ($rows === null) return [];          // فشل: منخزنش نتيجة فاضية في الكاش
        $all = array_merge($all, $rows);
        if (count($rows) < $page) break;
        $off += $page;
    }
    $GLOBALS['_USR_ROWS'] = $all;
    return $all;
}

// قراءة صف واحد من غير كاش (بتتستخدم قبل الكتابة عشان نقرأ أحدث نسخة)
function usr_find_row_fresh($email) {
    $email = strtolower(trim((string)$email));
    if ($email === '') return null;
    $rows = usr_query('select=id,email,data,updated_at&email=eq.' . rawurlencode($email) . '&limit=1');
    return ($rows[0] ?? null) ?: null;
}
// نفس الطلب بيسأل عن نفس المستخدم أكتر من مرة: بنخزن النتيجة لحد ما يحصل كتابة
function usr_find_row($email) {
    $key = strtolower(trim((string)$email));
    if ($key === '') return null;
    if (!isset($GLOBALS['_USR_ONE'])) $GLOBALS['_USR_ONE'] = [];
    if (array_key_exists($key, $GLOBALS['_USR_ONE'])) return $GLOBALS['_USR_ONE'][$key];
    $row = usr_find_row_fresh($key);
    if ($row !== null) $GLOBALS['_USR_ONE'][$key] = $row;   // ما نخزنش الفشل (null) عشان نعيد المحاولة
    return $row;
}

// صف Supabase → شكل المستخدم اللي الكود بيتعامل معاه (نفس المفاتيح القديمة + email)
function usr_row_to_user($row) {
    $u = is_array($row['data'] ?? null) ? $row['data'] : [];
    $u['email'] = (string)($row['email'] ?? '');
    $u['_id'] = (string)($row['id'] ?? '');
    $u['_rev'] = (string)($row['updated_at'] ?? '');
    return $u;
}

// اللي بيتخزن في data بس (من غير الحقول اللي جاية من الصف نفسه)
function usr_clean_data(array $u) {
    unset($u['email'], $u['_id'], $u['_rev'], $u['pass']);
    return $u;
}

// ---- الواجهة القديمة اللي باقي الكود بيستخدمها ----

function users_list() {
    return array_map('usr_row_to_user', usr_all_rows());
}

function find_user_row($email) {
    $r = usr_find_row($email);
    return $r ? usr_row_to_user($r) : null;
}

// تعديل حساب بشكل آمن: قراءة → تعديل → كتابة بشرط إن الصف متغيرش (تحكم في التزامن)
function update_user_row($email, $fn) {
    for ($try = 0; $try < 5; $try++) {
        $row = usr_find_row_fresh($email);
        if (!$row) return false;
        $u = usr_row_to_user($row);
        $fn($u);
        $data = usr_clean_data($u);
        $url = SB_URL . '/rest/v1/profiles?id=eq.' . rawurlencode((string)$row['id'])
             . '&updated_at=eq.' . rawurlencode((string)$row['updated_at']);
        [$code, $res] = usr_http('PATCH', $url,
            array_merge(usr_svc_headers(), ['Prefer: return=representation']),
            ['data' => (object)$data]);
        if ($code !== 200) { error_log('update_user: HTTP ' . $code); return false; }
        if (is_array($res) && count($res) === 1) { usr_cache_reset(); return true; }
        // الصف اتغير من طلب تاني في نفس اللحظة: نعيد القراءة ونجرب تاني
        usleep(50000 * ($try + 1));
    }
    error_log('update_user: gave up after retries');
    return false;
}

// ---- Supabase Auth ----

// تسجيل دخول: بيرجع بيانات الحساب لو الإيميل وكلمة السر صح، وإلا null
function auth_login_profile($email, $pass) {
    $email = strtolower(trim((string)$email));
    if ($email === '' || (string)$pass === '') return null;
    [$code, $res] = usr_http('POST', SB_URL . '/auth/v1/token?grant_type=password',
        usr_anon_headers(), ['email' => $email, 'password' => (string)$pass]);
    if ($code !== 200 || empty($res['user']['id'])) return null;
    $row = usr_find_row($email);
    return $row ? usr_row_to_user($row) : null;
}

// إنشاء حساب كامل (Auth + profile). بيرجع true لو نجح.
// الإيميل بيتعلّم إنه متأكد من غير رسالة تأكيد (email_confirm).
function usr_create_account($email, $pass, array $profile) {
    $email = strtolower(trim((string)$email));
    if (!usr_sb_ready()) return false;
    [$code, $res] = usr_http('POST', SB_URL . '/auth/v1/admin/users', usr_svc_headers(),
        ['email' => $email, 'password' => (string)$pass, 'email_confirm' => true]);
    if (!in_array($code, [200, 201], true) || empty($res['id'])) {
        error_log('usr_create_account: auth HTTP ' . $code);
        return false;
    }
    $id = (string)$res['id'];
    [$pcode, $pres] = usr_http('POST', SB_URL . '/rest/v1/profiles',
        array_merge(usr_svc_headers(), ['Prefer: return=representation']),
        ['id' => $id, 'email' => $email, 'data' => (object)usr_clean_data($profile)]);
    if (!in_array($pcode, [200, 201], true)) {
        error_log('usr_create_account: profile HTTP ' . $pcode);
        // تراجع: نمسح حساب الـ Auth عشان ماينفضلش حساب من غير بيانات
        usr_http('DELETE', SB_URL . '/auth/v1/admin/users/' . rawurlencode($id), usr_svc_headers());
        return false;
    }
    usr_cache_reset();
    return true;
}

// تغيير كلمة السر لحساب موجود بالإيميل
// إرسال كود استعادة كلمة السر (6 أرقام): Supabase بيبعت الإيميل بالكود (قالب الإيميل متضبط في الـ workflow)
function usr_recover($email) {
    $email = strtolower(trim((string)$email));
    if (!usr_sb_ready() || $email === '') return false;
    [$code, $res] = usr_http('POST', SB_URL . '/auth/v1/recover', usr_anon_headers(), ['email' => $email]);
    if ($code !== 200) {
        error_log('usr_recover: HTTP ' . $code . ' ' . substr((string)json_encode($res, JSON_UNESCAPED_UNICODE), 0, 300));
        return false;
    }
    return true;
}
// التحقق من الكود: لو صح بيرجع true (والكود بيتستهلك). Supabase بيرجع access_token لو الكود صح
function usr_verify_recovery_code($email, $token) {
    $email = strtolower(trim((string)$email));
    $token = preg_replace('/\D/', '', (string)$token);
    if (!usr_sb_ready() || $email === '' || strlen($token) !== 6) return 'invalid_input';
    [$code, $res] = usr_http('POST', SB_URL . '/auth/v1/verify', usr_anon_headers(),
        ['type' => 'recovery', 'email' => $email, 'token' => $token]);
    if ($code === 200 && !empty($res['access_token'])) return true;
    // بنرجع سبب الفشل (مثلاً otp_expired) عشان الصفحة تقول للمستخدم الصح، والتفاصيل في لوج السيرفر
    $reason = (string)($res['error_code'] ?? $res['error'] ?? $res['msg'] ?? $code);
    error_log('usr_verify_recovery_code: HTTP ' . $code . ' reason=' . substr($reason, 0, 120));
    return $reason;
}
// بيانات صاحب توكن الجلسة (من تسجيل جوجل مثلاً): id + email + الاسم
function usr_user_from_token($access_token) {
    $t = (string)$access_token;
    if (!usr_sb_ready() || $t === '' || strlen($t) > 4000) return null;
    [$code, $res] = usr_http('GET', SB_URL . '/auth/v1/user', ['apikey: ' . SB_KEY, 'Authorization: Bearer ' . $t]);
    if ($code !== 200 || empty($res['id']) || empty($res['email'])) return null;
    $meta = is_array($res['user_metadata'] ?? null) ? $res['user_metadata'] : [];
    $name = trim((string)($meta['full_name'] ?? $meta['name'] ?? ''));
    return ['id' => (string)$res['id'], 'email' => strtolower((string)$res['email']), 'name' => $name];
}
// بروفايل لحساب Auth موجود (زي حساب جوجل) بنفس الـ id
function usr_profile_create_for_id($id, $email, array $profile) {
    [$pcode] = usr_http('POST', SB_URL . '/rest/v1/profiles',
        array_merge(usr_svc_headers(), ['Prefer: return=representation']),
        ['id' => (string)$id, 'email' => strtolower(trim((string)$email)), 'data' => (object)usr_clean_data($profile)]);
    if (!in_array($pcode, [200, 201], true)) {
        error_log('usr_profile_create_for_id: HTTP ' . $pcode);
        return false;
    }
    usr_cache_reset();
    return true;
}
function usr_set_password($email, $pass) {
    $row = usr_find_row($email);
    if (!$row) return false;
    [$code] = usr_http('PUT', SB_URL . '/auth/v1/admin/users/' . rawurlencode((string)$row['id']),
        usr_svc_headers(), ['password' => (string)$pass]);
    return $code === 200;
}

// حذف حساب كامل (Auth، والجدول بيتمسح معاه بالـ cascade)
function usr_delete_account($email) {
    $row = usr_find_row($email);
    if (!$row) return false;
    [$code] = usr_http('DELETE', SB_URL . '/auth/v1/admin/users/' . rawurlencode((string)$row['id']), usr_svc_headers());
    usr_cache_reset();
    return in_array($code, [200, 204], true);
}
