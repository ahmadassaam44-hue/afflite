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
          'hevo_api.php', 'customer_req_lib.php', 'manual_products_lib.php',
          'config_pricing.php', 'config_orders.php', 'config_ledger.php', 'config_security.php', 'config_admin.php', 'config_catalog.php', 'config_geo.php', 'config_users.php', 'config_order_ops.php', 'config_affiliate.php', 'config_storefront.php', 'config_checkout.php', 'config_analytics.php', 'config_auth.php'] as $f) {
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

// ── 9) إعادة تسليم أوردر بعد عكسه: التكملة في حدود الاستحقاق فقط، بلا تكرار ولا رصيد سالب ──
seed_profile('mk-3', 'm3@test.local', ['name' => 'مسوق ثالث', 'role' => 'marketer', 'ref_code' => 'MK-T3', 'balance' => 50, 'pending_balance' => 0]);
seed_profile('mk-4', 'm4@test.local', ['name' => 'مسوق رابع', 'role' => 'marketer', 'ref_code' => 'MK-T4', 'balance' => 0, 'pending_balance' => 0]);
jsave('orders.json', [
    0 => ['ref' => 'MK-T3', 'status' => 'delivered', 'm_earn' => 100],
    1 => ['ref' => 'MK-T3', 'status' => 'delivered', 'm_earn' => 50],
    2 => ['ref' => 'MK-T4', 'status' => 'delivered', 'm_earn' => 100],
]);
jsave('payouts.json', [['email' => 'm4@test.local', 'amount' => 100, 'status' => 'موافق عليه']]);
check('plain release without shortfall flag does nothing when pending is empty', commission_release('m3@test.local', 100, 0, 'x') === false);
check('re-delivery tops up balance from delivered entitlement', commission_release('m3@test.local', 100, 0, 'x', true) === true);
$u = find_user('m3@test.local');
check('m3 balance is 150 after top-up', feq($u['balance'], 150) && feq($u['pending_balance'], 0));
check('second re-delivery call does not credit again', commission_release('m3@test.local', 100, 0, 'x', true) === false);
check('top-up refused when already withdrawn in full (no double pay)', commission_release('m4@test.local', 100, 2, 'x', true) === false);
$u = find_user('m4@test.local');
check('m4 balance untouched', feq($u['balance'], 0));

// ── 10) التسوية لمرة واحدة: معاينة بلا تعديل، تنفيذ مرة واحدة، تخطّي لو القيمة اتغيرت ──
seed_profile('rc-1', 'kokytoto000@gmail.com', ['name' => 'c', 'role' => 'marketer', 'balance' => 0, 'pending_balance' => 0]);
seed_profile('rc-2', 'mervatmena145@gmail.com', ['name' => 'k', 'role' => 'marketer', 'balance' => 0, 'pending_balance' => 0]);
seed_profile('rc-3', 'sslmy5689@gmail.com', ['name' => 'f', 'role' => 'marketer', 'balance' => 0, 'pending_balance' => 55]);
seed_profile('rc-4', 'ahmadassaam44@gmail.com', ['name' => 'a', 'role' => 'marketer', 'balance' => 90, 'pending_balance' => 0]);
jsave('payouts.json', []);
$pv = wf_recon_run(false);
check('recon preview marks all four ready', count($pv) === 4 && count(array_filter($pv, function ($r) { return $r['status'] === 'ready'; })) === 4);
$u = find_user('kokytoto000@gmail.com');
check('recon preview changes nothing', feq($u['balance'], 0));
$ap = wf_recon_run(true);
check('recon applied to all four', count(array_filter($ap, function ($r) { return $r['status'] === 'applied'; })) === 4);
check('Carolyn 520', feq(find_user('kokytoto000@gmail.com')['balance'], 520));
check('Kirmina 370', feq(find_user('mervatmena145@gmail.com')['balance'], 370));
$f = find_user('sslmy5689@gmail.com');
check('Fatma 15 and pending 55 untouched', feq($f['balance'], 15) && feq($f['pending_balance'], 55));
check('Ahmed 40', feq(find_user('ahmadassaam44@gmail.com')['balance'], 40));
$ap2 = wf_recon_run(true);
check('recon second run is a no-op', count(array_filter($ap2, function ($r) { return $r['status'] === 'done'; })) === 4 && feq(find_user('kokytoto000@gmail.com')['balance'], 520));
check('recon leaves one adjust row per marketer', count(balance_ledger_for('kokytoto000@gmail.com')) === 1 && balance_ledger_for('kokytoto000@gmail.com')[0]['type'] === 'adjust');

// ── 11) دورة كاملة لأوردرين معلقين: تسليم/إرجاع/إعادة تسليم/تبديلات بدون لمس معلق أوردر تاني ──
seed_profile('mk-5', 'm5@test.local', ['name' => 'مسوق خامس', 'role' => 'marketer', 'ref_code' => 'MK-T5', 'balance' => 0, 'pending_balance' => 0]);
jsave('payouts.json', []);
$set = function ($a, $b, $c = 'cancelled') { jsave('orders.json', [10 => ['ref' => 'MK-T5', 'status' => $a, 'm_earn' => 100], 11 => ['ref' => 'MK-T5', 'status' => $b, 'm_earn' => 50], 12 => ['ref' => 'MK-T5', 'status' => $c, 'm_earn' => 30]]); };
$st = function () { $u = find_user('m5@test.local'); return [round((float)$u['balance'], 2), round((float)$u['pending_balance'], 2)]; };
$set('pending', 'pending');
commission_credit_pending('m5@test.local', 100, 10); commission_credit_pending('m5@test.local', 50, 11);
check('two pending orders: pending 150', $st() === [0.0, 150.0]);
check('deliver A moves its own 100', commission_release('m5@test.local', 100, 10, 'x', true) && $st() === [100.0, 50.0]);
$set('delivered', 'pending');
check('return delivered A takes from balance, B pending untouched', commission_reverse('m5@test.local', 100, 10, 'x', true) && $st() === [0.0, 50.0]);
check('double reversal refused by caller guard: balance already 0 => no negative and no pending drain', commission_reverse('m5@test.local', 100, 10, 'x', true) === true && $st() === [0.0, 50.0]);
$set('confirmed', 'pending');
check('returned->confirmed->delivered A: tops up 100, B pending still 50', commission_release('m5@test.local', 100, 10, 'x', true) && $st() === [100.0, 50.0]);
$set('delivered', 'pending');
check('repeat delivered call credits nothing more', commission_release('m5@test.local', 100, 10, 'x', true) === false && $st() === [100.0, 50.0]);
check('delivered->shipped revert takes 100 from balance only', commission_reverse('m5@test.local', 100, 10, 'x', true) && $st() === [0.0, 50.0]);
$set('shipped', 'pending');
check('shipped->delivered again restores 100, B pending intact', commission_release('m5@test.local', 100, 10, 'x', true) && $st() === [100.0, 50.0]);
$set('delivered', 'pending');
check('deliver B moves its own 50', commission_release('m5@test.local', 50, 11, 'x', true) && $st() === [150.0, 0.0]);
$set('delivered', 'delivered');
check('return B after delivery: balance 100', commission_reverse('m5@test.local', 50, 11, 'x', true) && $st() === [100.0, 0.0]);
$set('delivered', 'returned');
commission_credit_pending('m5@test.local', 30, 12);
check('cancel pending order C drains its pending only', commission_reverse('m5@test.local', 30, 12, 'x') && $st() === [100.0, 0.0]);
$set('delivered', 'returned', 'confirmed');
check('cancelled->confirmed->delivered C tops up 30', commission_release('m5@test.local', 30, 12, 'x', true) && $st() === [130.0, 0.0]);
check('balance never exceeds delivered minus payouts here (A100 + C30)', $st()[0] <= 130.0);

// ── 12) بعد السحب: إرجاع أوردر مسلَّم لا يخلّي رصيد سالب وإعادة التسليم لا تدفع مرتين ──
seed_profile('mk-6', 'm6@test.local', ['name' => 'مسوق سادس', 'role' => 'marketer', 'ref_code' => 'MK-T6', 'balance' => 100, 'pending_balance' => 40]);
jsave('payouts.json', [['email' => 'm6@test.local', 'amount' => 100, 'status' => 'موافق عليه']]);
jsave('orders.json', [20 => ['ref' => 'MK-T6', 'status' => 'delivered', 'm_earn' => 100], 21 => ['ref' => 'MK-T6', 'status' => 'pending', 'm_earn' => 40]]);
// الرصيد المخزّن 100 لسه فيه (محاكاة): السحب اتسجّل لكن الرصيد لم يُخصم في الاختبار؛ المهم عدم تعويض زائد
check('return after payout: no negative, other pending untouched', commission_reverse('m6@test.local', 100, 20, 'x', true) === true);
$u = find_user('m6@test.local');
check('m6 balance not negative and pending 40 intact', (float)$u['balance'] >= 0 && feq($u['pending_balance'], 40));
jsave('orders.json', [20 => ['ref' => 'MK-T6', 'status' => 'confirmed', 'm_earn' => 100], 21 => ['ref' => 'MK-T6', 'status' => 'pending', 'm_earn' => 40]]);
check('redelivery after full payout credits nothing (no double pay)', commission_release('m6@test.local', 100, 20, 'x', true) === false);
$u = find_user('m6@test.local');
check('m6 still not credited, pending 40 intact', feq($u['balance'], 0) && feq($u['pending_balance'], 40));

// تنظيف
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($rii as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
@rmdir($tmp);

if ($failures > 0) {
    fwrite(defined('STDERR') ? STDERR : fopen('php://output', 'w'), "commission_lifecycle: $failures failure(s)\n");
    exit(1);
}
echo "commission_lifecycle: all checks passed (against localhost mock only)\n";
