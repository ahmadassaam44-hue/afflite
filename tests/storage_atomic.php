<?php
// ============================================================================
// اختبار تخزين JSON الذرّي (jsave / jupdate / jload / jatomic_write)
// يشتغل على نسخة مؤقتة من config.php والمكتبات المطلوبة، فلا يلمس data/ الحقيقية.
// التشغيل من جذر الريبو:  php tests/storage_atomic.php
// ============================================================================

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/afflite_storage_test_' . getmypid();
@mkdir($tmp . '/data', 0777, true);

// انسخ الملفات اللي config.php بيحتاجها عشان DATA_DIR يتحسب داخل المجلد المؤقت
foreach (['config.php', 'json_store.php', 'supabase.php', 'supabase_users.php', 'supabase_docs.php',
          'hevo_api.php', 'customer_req_lib.php', 'manual_products_lib.php'] as $f) {
    if (!copy($root . '/' . $f, $tmp . '/' . $f)) {
        fwrite(defined('STDERR') ? STDERR : fopen('php://output', 'w'), "FAIL: cannot copy $f\n");
        exit(1);
    }
}

require $tmp . '/config.php';

$failures = 0;
function check($label, $cond) {
    global $failures;
    if ($cond) { echo "ok - $label\n"; }
    else { $failures++; echo "FAIL - $label\n"; }
}

check('jatomic_write exists', function_exists('jatomic_write'));
check('DATA_DIR points at the temp copy', strpos(DATA_DIR, $tmp) === 0);

// 1) حفظ وقراءة (مع نص عربي) + إعادة قراءة من القرص نفسه
$doc = ['name' => 'طلب تجريبي', 'n' => 1, 'items' => ['أ', 'ب']];
check('jsave returns true', jsave('t1.json', $doc) === true);
unset($GLOBALS['_JMEM']['t1.json']);
$back = jload('t1.json', []);
check('jload roundtrip matches', $back == $doc);

// 2) 200 تعديل متتالي عبر jupdate — القيمة النهائية لازم تبقى 200 بالظبط
for ($i = 0; $i < 200; $i++) {
    $r = jupdate('counter.json', function (&$d) {
        $d['n'] = (int)($d['n'] ?? 0) + 1;
        return true;
    });
    if ($r !== true) break;
}
unset($GLOBALS['_JMEM']['counter.json']);
$cnt = jload('counter.json', []);
check('200 sequential jupdate increments land exactly', ($cnt['n'] ?? null) === 200);
check('counter file is valid JSON on disk', is_array(json_decode((string)file_get_contents(DATA_DIR . '/counter.json'), true)));

// 3) رجوع false من دالة التعديل = ممنوع إنشاء/تغيير الملف
$r = jupdate('nope.json', function (&$d) { return false; });
check('jupdate returning false aborts the write', $r === false && !file_exists(DATA_DIR . '/nope.json'));

// 4) ملف مكسور على القرص: القراءة ترجع الافتراضي والتعديل يبدأ منه
file_put_contents(DATA_DIR . '/broken.json', '{"x": 1,');
check('jload returns default for invalid JSON', jload('broken.json', ['d' => 1]) === ['d' => 1]);
jupdate('broken.json', function (&$d) { $d['fixed'] = true; return true; }, []);
unset($GLOBALS['_JMEM']['broken.json']);
$bf = jload('broken.json', []);
check('jupdate recovers a broken file from the default', ($bf['fixed'] ?? null) === true);

// 5) مفيش ملفات مؤقتة متسابة بعد الكتابات
$leftovers = glob(DATA_DIR . '/*.tmp.*');
check('no leftover .tmp files after writes', empty($leftovers));

// 6) تنظيف الملفات المؤقتة القديمة
$stale = DATA_DIR . '/stale.tmp.99999';
file_put_contents($stale, '{}');
touch($stale, time() - 7200);
jatomic_gc(DATA_DIR, true);
check('jatomic_gc removes stale tmp files', !file_exists($stale));

// 7) تزامن حقيقي: 4 عمليات أبناء × 50 زيادة على نفس الملف = 200 بالظبط
if (function_exists('pcntl_fork')) {
    jsave('conc.json', ['n' => 0]);
    $kids = [];
    for ($c = 0; $c < 4; $c++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            for ($i = 0; $i < 50; $i++) {
                jupdate('conc.json', function (&$d) { $d['n'] = (int)($d['n'] ?? 0) + 1; return true; });
            }
            exit(0);
        }
        $kids[] = $pid;
    }
    foreach ($kids as $pid) { pcntl_waitpid($pid, $status); }
    unset($GLOBALS['_JMEM']['conc.json']);
    $final = jload('conc.json', []);
    check('4 concurrent writers x 50 increments = exactly 200 (no lost updates)', ($final['n'] ?? null) === 200);
} else {
    echo "skip - pcntl unavailable, concurrency test skipped\n";
}

// تنظيف المجلد المؤقت
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($rii as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
@rmdir($tmp);

if ($failures > 0) { fwrite(defined('STDERR') ? STDERR : fopen('php://output', 'w'), "storage_atomic: $failures failure(s)\n"); exit(1); }
echo "storage_atomic: all checks passed\n";
