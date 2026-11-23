<?php
// ============================================================================
// اختبارات منطق الفلوس: حالات الطلب اللي بتحرر/تلغي العمولة، حساب العمولة،
// تقريب الأسعار، سعر المنتجات الرقمية بالدولار، والتحقق من حسابات السحب.
// كل الدوال المختبرة نقية (لا تحتاج Supabase ولا شبكة) — تشتغل على نسخة مؤقتة
// من الكود حتى لا تلمس data/ الحقيقية.
// التشغيل من جذر الريبو:  php tests/money_flows.php
// ============================================================================

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/afflite_money_test_' . getmypid();
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
// مقارنة رقمية متسامحة مع فرق int/float (دوال كتير بترجع 0 انت لو القيمة صفر)
function feq($a, $b) { return abs((float)$a - (float)$b) < 0.0001; }
function check($label, $cond) {
    global $failures;
    if ($cond) { echo "ok - $label\n"; }
    else { $failures++; echo "FAIL - $label\n"; }
}

// ── 1) حالات الطلب: البوابة اللي بتحرر أو تلغي العمولة ─────────────────────
check('status_key maps Arabic delivered', status_key('تم التسليم') === 'delivered');
check('status_key maps Arabic completed', status_key('مكتمل') === 'delivered');
check('status_key maps Arabic rejected', status_key('مرفوض') === 'cancelled');
check('status_key empty is pending', status_key('') === 'pending');
check('status_key passes through english', status_key('delivered') === 'delivered');
check('commission releases only on delivered', status_releases_commission('تم التسليم') === true
    && status_releases_commission('pending') === false
    && status_releases_commission('confirmed') === false
    && status_releases_commission('cancelled') === false);
check('commission reverses on cancelled/returned/no_answer', status_reverses_commission('cancelled') === true
    && status_reverses_commission('returned') === true
    && status_reverses_commission('no_answer') === true
    && status_reverses_commission('delivered') === false
    && status_reverses_commission('pending') === false);
check('customer can cancel only pending/confirmed', status_customer_cancellable('pending') === true
    && status_customer_cancellable('confirmed') === true
    && status_customer_cancellable('delivered') === false);

// ── 2) تحليل أرقام الأسعار من نصوص الموردين ──────────────────────────────────
check('mz_num plain amount', mz_num('55 درهم') === 55.0);
check('mz_num 1,234.50 format', mz_num('1,234.50') === 1234.5);
check('mz_num 1.234,50 format', mz_num('1.234,50') === 1234.5);
check('mz_num empty/garbage is zero', feq(mz_num(''), 0) && feq(mz_num('abc'), 0));

// ── 3) عمولة المنتج من بيانات المصدر ─────────────────────────────────────────
check('commission from commission field', source_product_commission(['commission' => '25']) === 25.0);
check('commission falls back to profit text', source_product_commission(['commission' => '', 'extra' => ['profit' => '30 جنيه']]) === 30.0);
check('commission never negative', feq(source_product_commission(['commission' => '-5']), 0.0));
check('commission zero when absent', feq(source_product_commission([]), 0.0));

// ── 4) حساب عمولة المنصة ─────────────────────────────────────────────────────
$p_fixed = ['id' => 'x1', 'price' => 100, 'commission' => '20', 'extra' => []];
check('digital/social/manual platforms take no commission',
    platform_commission_amount($p_fixed, 'g2g', 100) === 0.0
    && platform_commission_amount($p_fixed, 'social', 100) === 0.0
    && platform_commission_amount($p_fixed, 'manual', 100) === 0.0);
check('fixed product commission honored', platform_commission_amount($p_fixed, 'vendoor', 100) === 20.0);
check('fixed commission capped by gross', platform_commission_amount(['id' => 'x2', 'price' => 100, 'commission' => '500', 'extra' => []], 'vendoor', 100) === 100.0);
check('variant fixed commission capped by gross', platform_commission_amount(['id' => 'x3', 'price' => 100, 'extra' => []], 'vendoor', 100, 150) === 100.0);
check('variant fixed commission used when smaller', platform_commission_amount(['id' => 'x4', 'price' => 100, 'extra' => []], 'vendoor', 100, 15) === 15.0);
// سعر صفر بيتحول لسعر المنتج نفسه — الصفر الحقيقي بيحصل لما مفيش أي سعر متاح
check('zero gross with no product price means zero commission', feq(platform_commission_amount(['id' => 'x9', 'extra' => []], 'vendoor', 0), 0));
check('no commission data means zero', platform_commission_amount(['id' => 'x5', 'price' => 100, 'extra' => []], 'vendoor', 100) === 0);

// ── 5) تقريب أسعار الموقع (لأقرب 5 أو 0، والنصوص الصغيرة لأقرب نص) ──────────
check('round_5_or_0 zero', wf_round_5_or_0(0) === 0.0);
check('round_5_or_0 sub-pound keeps piasters', wf_round_5_or_0(0.3) === 0.3);
check('round_5_or_0 9.1 rounds up to 9.5', wf_round_5_or_0(9.1) === 9.5);
check('round_5_or_0 12 rounds up to 15', wf_round_5_or_0(12) === 15.0);
check('round_5_or_0 exact 10 stays', wf_round_5_or_0(10) === 10.0);

// ── 6) إضافة السوشيال ميديا (للأرخص من 10 قروش بس) ───────────────────────────
check('social addon applies below 0.10', social_norm_price(0.05) === 0.15);
check('social addon not applied at/above 0.10', social_norm_price(0.5) === 0.5);
check('social price rounds to half', social_norm_price(1.3) === 1.5);

// ── 7) سعر المنتج الرقمي: أول عرض متاح بالدولار × 56 ─────────────────────────
check('digital price uses first in-stock variant', wf_digital_source_price([
    'extra' => ['variants_detailed' => [
        ['unit_price_usd' => 2, 'stock' => 0],
        ['unit_price_usd' => 3, 'stock' => 5],
    ]],
]) === 168.0);
check('digital price falls back to cheapest when all sold out', wf_digital_source_price([
    'extra' => ['variants_detailed' => [['unit_price_usd' => 2, 'stock' => 0]]],
]) === 112.0);
check('digital price falls back to product-level usd', wf_digital_source_price(['unit_price_usd' => 1.5]) === 84.0);
check('digital price zero without usd', wf_digital_source_price([]) === 0.0);

// ── 8) سعر العميل للفيزيكال: هامش 2% ثم تخفيض 12% ثم تقريب 5/0 ──────────────
// منتج vendoor مخزن سعره الصافي 100 → 100 × 1.02 × 0.88 = 89.76 → أقرب 5 للأعلى = 90
check('physical store price pipeline (uplift+discount+round)', store_price(['id' => 'p1', 'price' => 100, 'extra' => []], 'vendoor') === 90.0);

// ── 9) عمولة المسوق للمنتج اليدوي: ثابتة + أي زيادة فوق سعر الأساس ──────────
$p_manual = ['id' => 'm1', 'price' => 100, 'extra' => ['manual_commission' => 15]];
check('manual commission fixed part', product_commission_unit($p_manual, 'manual') === 15.0);
check('manual commission fixed + marketer markup', product_commission_unit($p_manual, 'manual', 130) === 45.0);

// ── 10) التحقق من حسابات السحب ────────────────────────────────────────────────
check('vodafone cash accepts 01x numbers', validate_payout_account('wallet_vodafone', '01012345678') === '');
check('vodafone cash rejects short numbers', validate_payout_account('wallet_vodafone', '012345') !== '');
check('vodafone cash rejects foreign prefix', validate_payout_account('wallet_vodafone', '02012345678') !== '');
check('instapay accepts user@instapay', validate_payout_account('instapay', 'name@instapay') === '');
check('instapay rejects other domains', validate_payout_account('instapay', 'name@gmail.com') !== '');
check('bank accepts long account/IBAN', validate_payout_account('bank', 'EG380003000000000000123456789') === '');
check('bank rejects tiny account', validate_payout_account('bank', '123') !== '');
check('paypal accepts email', validate_payout_account('paypal', 'user@example.com') === '');
check('paypal rejects non-email', validate_payout_account('paypal', 'not-an-email') !== '');
check('unknown method rejected', validate_payout_account('bogus', 'x') !== '');
check('empty account rejected', validate_payout_account('wallet_vodafone', '') !== '');

// تنظيف المجلد المؤقت
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($rii as $file) { $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
@rmdir($tmp);

if ($failures > 0) { fwrite(defined('STDERR') ? STDERR : fopen('php://output', 'w'), "money_flows: $failures failure(s)\n"); exit(1); }
echo "money_flows: all checks passed\n";
