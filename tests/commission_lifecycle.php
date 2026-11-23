<?php
// ============================================================================
// اختبار دورة حياة العمولة الكاملة (معلّق → متاح → عكس) وعمولة الفريق،
// ضد مخدم Supabase وهمي محلي (tests/mock_supabase.cjs) — بلا أي اتصال حقيقي.
//
// تصميم fail-closed:
//   - SB_URL يُفرض على 127.0.0.1 قبل تحميل الكود، ويُتحقق منه بعد التحميل.
//   - مفتاح الخدمة قيمة وهمية صريحة؛ لو تسرب أي طلب لأي مكان آخر سيرفض.
//   - لو المخدم الوهمي مش شغال، فحص الحيوية بيفشل والاختبار يقف فورًا.
//   أي خطأ في الإعداد = فشل الاختبار، مش اتصال بالإنتاج.
//
// ما يثبته الاختبار: منطق الكود وحسابات الفلوس وبروتوكول التزامن (rev).
// ما لا يثبته: سياسات RLS الحقيقية أو سلوك Supabase الفعلي — ده مفصول وموثق.
//
// التشغيل (بعد تشغيل المخدم الوهمي):  php tests/commission_lifecycle.php
// ============================================================================

error_reporting(E_ALL & ~E_DEPRECATED);

// ── fail-closed: وجّه الطبقة للمخدم الوهمي قبل تحميل أي كود ──────────────────
define('SB_URL', getenv('AFFLITE_MOCK_SB_URL') ?: 'http://127.0.0.1:18099');
define('SB_SERVICE', 'mock-service-key-not-a-real-credential');

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/afflite_comm_test_' . getmypid();
@mkdir($tmp . '/data', 0777, true);
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
function feq($a, $b) { return abs((float)$a - (float)$b) < 0.0001; }
function bail($msg) {
    fwrite(defined('STDERR') ? STDERR : fopen('php://output', 'w'), "ABORT (fail-closed): $msg\n");
    exit(1);
}

// ── fail-closed: تحقق بعد التحميل إن الوجهة localhost بجد — لو حارس SB_URL
// اتكسر يومًا والثابت بقى يشاور على الإنتاج، الاختبار يقف هنا قبل أي طلب ────
$host = (string)parse_url(SB_URL, PHP_URL_HOST);
if (!in_array($host, ['127.0.0.1', 'localhost'], true)) bail('SB_URL host is "' . $host . '", not localhost');
if (SB_SERVICE !== 'mock-service-key-not-a-real-credential') bail('SB_SERVICE is not the dummy value');

// ── fail-closed: المخدم الوهمي لازم يكون حي وبيخدم الطبقة فعلاً ─────────────
$alive = docs_fetch('orders.json');
if (empty($alive['ok'])) bail('mock server unreachable or not answering through the docs transport');

// بذر مسوّقين اثنين عبر نفس مسار HTTP اللي الكود بيستخدمه
function seed_profile($id, $email, array $data) {
    [$c, $r] = usr_http('POST', SB_URL . '/rest/v1/profiles',
        array_merge(usr_svc_headers(), ['Prefer: return=representation']),
        ['id' => $id, 'email' => $email, 'data' => (object)$data]);
    if (!in_array($c, [200, 201], true)) bail('seed profile failed: HTTP ' . $c);
}
seed_profile('mk-1', 'm1@test.local', ['name' => 'مسوق أول', 'role' => 'marketer', 'balance' => 0, 'pending_balance' => 0]);
seed_profile('mk-2', 'm2@test.local', ['name' => 'مسوق تاني', 'role' => 'marketer', 'balance' => 0, 'pending_balance' => 0]);

// ── 1) القراءة عبر مسار الكود الحقيقي ────────────────────────────────────────
$u = find_user('m1@test.local');
check('seeded marketer readable via find_user', is_array($u) && feq($u['balance'] ?? -1, 0) && feq($u['pending_balance'] ?? -1, 0));
check('unknown user returns null', find_user('ghost@test.local') === null);

// ── 2) عمولة معلقة: تدخل pending_balance مش balance ──────────────────────────
check('credit pending succeeds', commission_credit_pending('m1@test.local', 100, 7) === true);
$u = find_user('m1@test.local');
check('pending credited to pending_balance only', feq($u['pending_balance'], 100) && feq($u['balance'], 0));
$lg = balance_ledger_for('m1@test.local');
check('ledger has pending commission row', count($lg) === 1 && $lg[0]['type'] === 'commission' && feq($lg[0]['amount'], 100) && ($lg[0]['state'] ?? '') === 'pending' && (int)$lg[0]['order_idx'] === 7);
check('credit with zero/negative amount refused', commission_credit_pending('m1@test.local', 0, 7) === false && commission_credit_pending('m1@test.local', -5, 7) === false);
check('credit for unknown user refused', commission_credit_pending('ghost@test.local', 10, 7) === false);

// ── 3) التسليم: التحويل من المعلق للمتاح ─────────────────────────────────────
check('release succeeds', commission_release('m1@test.local', 100, 7) === true);
$u = find_user('m1@test.local');
check('release moves pending to balance', feq($u['pending_balance'], 0) && feq($u['balance'], 100));
check('double release refused (nothing pending)', commission_release('m1@test.local', 100, 7) === false);
$u = find_user('m1@test.local');
check('balance unchanged after refused release', feq($u['balance'], 100));

// ── 4) العكس بعد التحرير: يخصم من المتاح ─────────────────────────────────────
check('reverse after release succeeds', commission_reverse('m1@test.local', 40, 7) === true);
$u = find_user('m1@test.local');
check('reverse deducts from balance when no pending', feq($u['balance'], 60) && feq($u['pending_balance'], 0));

// ── 5) العكس الجزئي: من المعلق أولاً ثم المتاح ───────────────────────────────
check('second pending credit', commission_credit_pending('m1@test.local', 50, 8) === true);
check('partial reverse drains pending first', commission_reverse('m1@test.local', 20, 8) === true);
$u = find_user('m1@test.local');
check('partial reverse leaves pending 30 and balance 60', feq($u['pending_balance'], 30) && feq($u['balance'], 60));
check('reverse bigger than total clamps at zero', commission_reverse('m1@test.local', 1000, 8) === true);
$u = find_user('m1@test.local');
check('balance never goes negative', feq($u['pending_balance'], 0) && feq($u['balance'], 0));

// ── 6) عمولة الفريق (إحالة): نفس دورة الأوردر عبر سجل الفريق ────────────────
check('team pending credit', commission_credit_pending('m2@test.local', 25, 9, 'عمولة فريق (إحالة)') === true);
$rows = team_ledger_for_order(9);
check('team ledger sees credited 25 for order 9', count($rows) === 1 && feq($rows['m2@test.local']['credited'] ?? -1, 25));
check('team release moves 25', feq(team_commission_release_for_order(9), 25));
$u = find_user('m2@test.local');
check('team release landed in balance', feq($u['balance'], 25) && feq($u['pending_balance'], 0));
check('team release is idempotent', feq(team_commission_release_for_order(9), 0));

// ── 7) عكس عمولة الفريق بالكامل ──────────────────────────────────────────────
check('team pending credit order 10', commission_credit_pending('m2@test.local', 10, 10, 'عمولة فريق (إحالة)') === true);
check('team reverse pulls back 10', feq(team_commission_reverse_for_order(10), 10));
$u = find_user('m2@test.local');
check('team reverse leaves pending 0, balance untouched', feq($u['pending_balance'], 0) && feq($u['balance'], 25));
check('team reverse is idempotent', feq(team_commission_reverse_for_order(10), 0));

// ── 8) سجل المراجعة الكامل لم1: ترتيب العمليات والمبالغ ─────────────────────
$lg = balance_ledger_for('m1@test.local', 50);
$types = array_map(function ($r) { return $r['type'] . ':' . $r['amount']; }, array_reverse($lg));
check('m1 ledger trail is complete and ordered', $types === [
    'commission:100', 'release:100', 'reversal:-40', 'commission:50', 'reversal:-20', 'reversal:-1000',
]);

// تنظيف
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($rii as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
@rmdir($tmp);

if ($failures > 0) {
    fwrite(defined('STDERR') ? STDERR : fopen('php://output', 'w'), "commission_lifecycle: $failures failure(s)\n");
    exit(1);
}
echo "commission_lifecycle: all checks passed (against localhost mock only)\n";
