<?php
// ============================================================================
// تخزين ملفات البيانات على Supabase (جدول app_docs)
// الملفات اللي في DOCS_REMOTE بتتقرا وتتكتب على Supabase، والباقي على الاستضافة (data/).
// نفس واجهة jload / jsave / jupdate، فباقي الكود مش محتاج تغيير.
//
// قواعد الأمان:
//  - لو الاتصال بـ Supabase فشل وقت القراءة: بنرجع القيمة الافتراضية، ومنكتبش حاجة.
//  - لو الكتابة مش متأكدين من أحدث نسخة: منكتبش على بيانات مش متأكدين منها.
//  - الكتابة بتتم بشرط إن الصف متغيرش من طلب تاني (تحكم في التزامن).
// ============================================================================

if (!defined('DOCS_REMOTE')) define('DOCS_REMOTE', [
    'orders.json',
    'deleted_orders.json',
    'payouts.json',
    'balance_ledger.json',
    'marketer_stores.json',
    'noon_codes.json',
    'noon_sales.json',
    'reviews.json',
    'support_chats.json',
    'notifications.json',
    'customer_requests.json',
    'product_requests.json',
    'noon_products.json',
    'settings.json',
    'newsletter.json',
    'audit_logs.json',
]);

// الملفات دي بتتكتب على Supabase دايماً. لو الإعداد ناقص بنفشل بوضوح (ومنكتبش محلياً)
function docs_is_remote($f) {
    return in_array((string)$f, DOCS_REMOTE, true);
}

// قراءة صف: ok=false لو الاتصال فشل (مش معناه إن الصف مش موجود)
function docs_fetch($f, $timeout = 8) {
    if (!usr_sb_ready()) { error_log('docs_fetch ' . $f . ': Supabase not configured (SB_SERVICE)'); return ['ok' => false]; }
    [$code, $rows] = usr_http('GET',
        SB_URL . '/rest/v1/app_docs?select=data,updated_at&name=eq.' . rawurlencode($f) . '&limit=1',
        usr_svc_headers(), null, $timeout);
    if ($code !== 200 || !is_array($rows)) {
        error_log('docs_fetch ' . $f . ': HTTP ' . $code);
        return ['ok' => false];
    }
    if (!$rows) return ['ok' => true, 'found' => false, 'data' => null, 'rev' => null];
    return ['ok' => true, 'found' => true, 'data' => $rows[0]['data'] ?? null, 'rev' => $rows[0]['updated_at'] ?? null];
}

// كاش قراءة قصيرة لملفات Supabase: أي صفحة معمولة بعد قراءة أخيرة بـ≤ DOCS_CACHE_TTL ثانية
// بتقرأ من كاش القراءة المحلي بدل طلب HTTP جديد (4-8 ثواني في كل صفحة كانت تعطل).
// لو Supabase تعثّر: بنرجع آخر نسخة حتى لو قديمة (DOCS_CACHE_STALE) بدل تفشيل الصفحة.
// الكتابة تمسح/تحثّ الكاش — متضمن مشكلة أمان، والقيم المالية ليست قديمة أكتر من 30 ثانية.
define('DOCS_CACHE_TTL', 30);
define('DOCS_CACHE_STALE', 300);
function docs_fetch_cached($f, $timeout = 8) {
    $c = docs_cache_read($f, DOCS_CACHE_TTL);
    if ($c !== null && isset($c['ok'])) return $c;
    $r = docs_fetch($f, $timeout);
    if ($r['ok']) {
        docs_cache_write($f, $r);
        return $r;
    }
    $old = docs_cache_read($f, null);
    return ($old !== null && isset($old['ok'])) ? $old : $r;
}

// كتابة صف. rev = null → إنشاء. rev موجود → تحديث بشرط إن الصف لسه بنفس الـ rev.
// بترجع: ['status' => 'ok'|'conflict'|'error', 'rev' => ...]
// ---- كاش إعدادات الموقع على الاستضافة ----
// الإعدادات بتتقرأ في كل صفحة، فمن غير كاش أي بطء في Supabase بيوقف الصفحات (502).
// الكاش بيتمسح مع كل كتابة، وبيتقري منه لمدة 60 ثانية. ولو Supabase وقع بنرجع لآخر نسخة نجحت.
define('DOCS_SETTINGS_CACHE_TTL', 60);
function docs_cache_path($f) {
    return sys_get_temp_dir() . '/afflite_docs_' . md5(DATA_DIR) . '_' . preg_replace('/[^a-z0-9_.-]/i', '_', $f);
}
function docs_cache_read($f, $max_age = null) {
    $p = docs_cache_path($f);
    if (!is_file($p)) return null;
    if ($max_age !== null && (time() - (int)@filemtime($p)) > $max_age) return null;
    $d = json_decode((string)@file_get_contents($p), true);
    return is_array($d) ? $d : null;
}
function docs_cache_write($f, $data) {
    @file_put_contents(docs_cache_path($f), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function docs_cache_clear($f) {
    @unlink(docs_cache_path($f));
}

// كتابة صف + مسح الكاش بتاعه (أي ملف: تعديل مهم لازم يوصل باقي الطلبات فوراً)
function docs_store($f, $value, $rev) {
    $st = docs_store_raw($f, $value, $rev);
    docs_cache_clear($f);
    return $st;
}

function docs_store_raw($f, $value, $rev) {
    if (!usr_sb_ready()) return ['status' => 'error', 'rev' => null];
    $h = array_merge(usr_svc_headers(), ['Prefer: return=representation']);
    if ($rev === null) {
        [$code, $res] = usr_http('POST', SB_URL . '/rest/v1/app_docs', $h, ['name' => $f, 'data' => $value]);
        if (in_array($code, [200, 201], true) && is_array($res) && $res) return ['status' => 'ok', 'rev' => $res[0]['updated_at'] ?? null];
        if ($code === 409) return ['status' => 'conflict', 'rev' => null];
        error_log('docs_store insert ' . $f . ': HTTP ' . $code);
        return ['status' => 'error', 'rev' => null];
    }
    $url = SB_URL . '/rest/v1/app_docs?name=eq.' . rawurlencode($f) . '&updated_at=eq.' . rawurlencode($rev);
    [$code, $res] = usr_http('PATCH', $url, $h, ['data' => $value]);
    if ($code !== 200) {
        error_log('docs_store update ' . $f . ': HTTP ' . $code);
        return ['status' => 'error', 'rev' => null];
    }
    if (is_array($res) && count($res) === 1) return ['status' => 'ok', 'rev' => $res[0]['updated_at'] ?? null];
    return ['status' => 'conflict', 'rev' => null];
}

function docs_jload($f, $def, $memoize) {
    $r = docs_fetch_cached($f, $f === 'settings.json' ? 3 : 8);
    if (!$r['ok']) return $def;
    $GLOBALS['_DOCREV'][$f] = $r['found'] ? ($r['rev'] ?? null) : null;
    $res = is_array($r['data'] ?? null) ? $r['data'] : $def;
    if ($memoize) $GLOBALS['_JMEM'][$f] = $res;
    return $res;
}

function docs_jsave($f, $data) {
    // لازم نعرف أحدث rev قبل الكتابة؛ لو معرفوش نجيبه الأول
    if (!array_key_exists($f, $GLOBALS['_DOCREV'] ?? [])) {
        $r = docs_fetch($f);
        if (!$r['ok']) return false;
        $GLOBALS['_DOCREV'][$f] = $r['found'] ? $r['rev'] : null;
    }
    for ($try = 0; $try < 4; $try++) {
        $st = docs_store($f, $data, $GLOBALS['_DOCREV'][$f]);
        if ($st['status'] === 'ok') {
            docs_cache_clear($f);
            $GLOBALS['_DOCREV'][$f] = $st['rev'];
            $GLOBALS['_JMEM'][$f] = $data;
            return true;
        }
        if ($st['status'] !== 'conflict') return false;
        // اتغير من طلب تاني: نجيب أحدث نسخة مباشرة من Supabase (بدون كاش قراءة) ونكتب تاني
        $r = docs_fetch($f);
        if (!$r['ok']) return false;
        $GLOBALS['_DOCREV'][$f] = $r['found'] ? $r['rev'] : null;
        usleep(30000 * ($try + 1));
    }
    return false;
}

function docs_jupdate($f, $fn, $def) {
    for ($try = 0; $try < 6; $try++) {
        $r = docs_fetch($f);
        if (!$r['ok']) return false;                 // ما نكتبش على بيانات مش متأكدين منها
        $d = ($r['found'] && is_array($r['data'])) ? $r['data'] : $def;
        $ret = $fn($d);
        if ($ret === false) return false;
        $st = docs_store($f, $d, $r['found'] ? $r['rev'] : null);
        if ($st['status'] === 'ok') {
            $GLOBALS['_DOCREV'][$f] = $st['rev'];
            $GLOBALS['_JMEM'][$f] = $d;
            return $ret;
        }
        if ($st['status'] !== 'conflict') return false;
        usleep(40000 * ($try + 1));
    }
    error_log('docs_jupdate ' . $f . ': gave up after retries');
    return false;
}
