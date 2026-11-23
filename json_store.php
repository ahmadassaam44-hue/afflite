<?php
// ============================================================================
// تخزين JSON المحلي الذرّي — فُصل من config.php (خطوة أولى في تقسيم الملف الكبير)
// يُحمَّل من config.php فقط؛ لا تستدعيه مباشرة من الويب.
// يعتمد على: DATA_DIR (يُعرَّف في config.php قبل التحميل)، وwfp()، ودوال docs_* في
// supabase_docs.php (تُحمَّل لاحقاً لكنها تُستدعى وقت التشغيل فقط).
// ============================================================================

// ============================================================================
// قراءة/كتابة JSON آمنة مع قفل ملف (flock) لمنع تلاشي البيانات في الطلبات المتزامنة
// ============================================================================
$GLOBALS['_JMEM'] = [];
$GLOBALS['_JLOAD_MS'] = [];

// توكن قياس الأداء يُعرَّف في secrets.php على السيرفر فقط (define('WF_PERF_TOKEN', '...')).
// لا يوجد توكن افتراضي داخل Git: بدون تعريفه تكون نقطة ?perf= مقفولة تماماً.
(function () {
    if (!defined('WF_PERF_TOKEN') || WF_PERF_TOKEN === '') return;
    if (($_GET['perf'] ?? '') !== WF_PERF_TOKEN) return;
    $GLOBALS['_WFP_TIMES'] = [];
    $GLOBALS['_WFP_T0'] = microtime(true);
    $GLOBALS['_WFP'] = [];
})();
function wfp($label) {
    if (!isset($GLOBALS['_WFP'])) { return; }
    $GLOBALS['_WFP'][] = sprintf('%s t=%.3f mem=%.1fM', $label, microtime(true) - $GLOBALS['_WFP_T0'], memory_get_usage(true) / 1048576);
}
register_shutdown_function(function () {
    if (empty($GLOBALS['_WFP']) || !isset($GLOBALS['_WFP_T0'])) return;
    $t = sprintf("%.3fs", microtime(true) - $GLOBALS['_WFP_T0']);
    error_log('WFPERF uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' total=' . $t . ' | ' . implode(' | ', $GLOBALS['_WFP']));
    echo "\n<!--WFPERF total=" . htmlspecialchars($t) . "\n" . htmlspecialchars(implode("\n", $GLOBALS['_WFP'])) . "\n-->\n";
});

function jload($f, $def = [], $memoize = true) {
    if (isset($GLOBALS['_JMEM'][$f])) return $GLOBALS['_JMEM'][$f];
    if (docs_is_remote($f)) return docs_jload($f, $def, $memoize);
    $t0 = microtime(true);
    $p = DATA_DIR . '/' . $f;
    if (!file_exists($p)) return $def;
    $fp = @fopen($p, 'rb');
    if (!$fp) return $def;
    @flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
    $d = json_decode($raw ?: '', true);
    $res = is_array($d) ? $d : $def;
    $GLOBALS['_JLOAD_MS'][$f] = [round((microtime(true) - $t0) * 1000), strlen((string)$raw), count(is_array($res) ? $res : [])];
    if ($GLOBALS['_JLOAD_MS'][$f][0] > 80) wfp('SLOWFILE ' . $f . ' ' . $GLOBALS['_JLOAD_MS'][$f][0] . 'ms ' . round(strlen((string)$raw) / 1024) . 'KB');
    if ($memoize) $GLOBALS['_JMEM'][$f] = $res;
    return $res;
}

// كتابة ذرّية: المحتوى يُكتب لملف مؤقت ثم يُستبدل بالاسم النهائي عبر rename
// (عملية ذرّية على لينكس)، فلا يرى أي قارئ ملفاً نصف مكتوب لو العملية اتقتلت
// في منتصف الكتابة (حدود الاستضافة المشتركة: وقت التنفيذ/الذاكرة).
function jatomic_write($p, $json) {
    $tmp = $p . '.tmp.' . getmypid();
    $fp = @fopen($tmp, 'wb');
    if (!$fp) return false;
    $ok = true;
    $len = strlen($json);
    $w = @fwrite($fp, $json);
    if ($w === false || $w < $len) $ok = false;
    if ($ok && @fflush($fp) === false) $ok = false;
    @fclose($fp);
    if (!$ok || !@rename($tmp, $p)) { @unlink($tmp); return false; }
    return true;
}

// تنظيف نادر لملفات مؤقتة قديمة خلّفتها عملية اتقتلت قبل الـ rename
function jatomic_gc($dir, $force = false) {
    if (!$force && mt_rand(1, 200) !== 1) return;
    foreach ((array)@glob($dir . '/*.tmp.*') as $t) {
        if ((time() - (int)@filemtime($t)) > 3600) @unlink($t);
    }
}

// قفل جانبي لكل ملف بيانات: يثبّت تسلسل قراءة-تعديل-كتابة jupdate رغم الـ rename
function jlock_acquire($p) {
    $lp = @fopen($p . '.lock', 'c+b');
    if (!$lp) return false;
    if (!@flock($lp, LOCK_EX)) { @fclose($lp); return false; }
    return $lp;
}
function jlock_release($lp) {
    if ($lp) { @flock($lp, LOCK_UN); @fclose($lp); }
}

function jsave($f, $data) {
    if (docs_is_remote($f)) return docs_jsave($f, $data);
    $p = DATA_DIR . '/' . $f;
    jatomic_gc(DATA_DIR);
    $lp = jlock_acquire($p);
    $ok = jatomic_write($p, json_encode($data, JSON_UNESCAPED_UNICODE));
    jlock_release($lp);
    if ($ok) $GLOBALS['_JMEM'][$f] = $data;
    return $ok;
}

// قراءة-تعديل-كتابة تحت قفل واحد — يمنع ضياع الرصيد/الطلبات.
// لو القفل أو الكتابة اتعذّرا نفشل بوضوح بدل الكتابة بدون قفل (السلوك القديم
// كان يكتب مكشوفاً لو fopen فشل).
function jupdate($f, $fn, $def = []) {
    if (docs_is_remote($f)) return docs_jupdate($f, $fn, $def);
    $p = DATA_DIR . '/' . $f;
    jatomic_gc(DATA_DIR);
    $lp = jlock_acquire($p);
    if (!$lp) { error_log('jupdate ' . $f . ': lock unavailable'); return false; }
    $d = $def;
    if (is_file($p)) {
        $raw = @file_get_contents($p);
        $dec = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
        if (is_array($dec)) $d = $dec;
    }
    $r = $fn($d);
    if ($r !== false) {
        if (!jatomic_write($p, json_encode($d, JSON_UNESCAPED_UNICODE))) {
            jlock_release($lp);
            error_log('jupdate ' . $f . ': atomic write failed');
            return false;
        }
    }
    jlock_release($lp);
    $GLOBALS['_JMEM'][$f] = $d;
    return $r;
}
