<?php
// ============================================================================
// config_orders.php — جزء من config.php (السطور 370–605 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// ============================================================================
//  نظام الطلبات والعمولات — حالات ثابتة بالإنجليزية (تُترجم في العرض فقط)
// ============================================================================
define('MAX_MARKUP_PCT', 30);          // أقصى هامش يقدر المسوق يضيفه فوق سعر السيرفر

function order_status_list() {
    return [
        'pending'    => 'قيد التأكيد',
        'confirmed'  => 'مؤكد',
        'shipped'    => 'مشحون',
        'delivered'  => 'تم التوصيل',
        'no_answer'  => 'لا يرد',
        'cancelled'  => 'ملغي',
        'returned'   => 'مرتجع',
    ];
}
function status_label($key) {
    $l = order_status_list();
    return $l[$key] ?? ($key ?: 'قيد التأكيد');
}
// تحويل أي حالة قديمة (عربي) للحالة الإنجليزية المعيارية
function status_key($v) {
    static $map = null;
    if ($map === null) {
        $map = array_flip(order_status_list());
        $map['قيد التنفيذ'] = 'confirmed';
        $map['قيد المراجعة'] = 'pending';
        $map['تم التسليم']   = 'delivered';
        $map['مرفوض']        = 'cancelled';
        $map['مكتمل']        = 'delivered';
    }
    $v = trim((string)$v);
    if ($v === '') return 'pending';
    if (isset($map[$v])) return $map[$v];
    $k = strtolower($v);
    return isset(order_status_list()[$k]) ? $k : 'pending';
}
// العمولة المحسوبة للطلب: الملغي/المرتجع/لا يرد = كأنه ما حصلش (صفر) — الرقم اللي المسوق بيشوفه هو اللي يتحسب له
function wf_order_commission($o) {
    if (!is_array($o)) return 0.0;
    if (status_reverses_commission($o['status'] ?? '')) return 0.0;
    return (float)($o['m_earn'] ?? 0);
}
// تسويات زيادة السحب المتنازل عنها بقرار صاحب الموقع (واتساب 2026-11-25: «اعملها كأنه أخدهم ٧٧٠٥»): محمد جمال 56 ج.م
function wf_overdraw_waivers() { return ['am0475688@gmail.com' => 56.0]; }
// العمولة المستحقة فعلاً: الطلبات المسلّمة فقط (مش المعلقة ولا الملغية)
function wf_order_earned($o) {
    if (!is_array($o) || status_key($o['status'] ?? '') !== 'delivered') return 0.0;
    return (float)($o['m_earn'] ?? 0);
}
// تفصيل عمولة مسوق من طلباته: معلقة (لسه ماتسلمتش) + مؤكدة (اتسلمت) + المسحوب بموافقة الأدمن
function wf_marketer_comm_totals($orders, $email = '') {
    $t = ['pending' => 0.0, 'delivered' => 0.0, 'total' => 0.0, 'withdrawn' => 0.0, 'remaining' => 0.0];
    foreach ((array)$orders as $o) {
        $e = wf_order_commission($o);
        if ($e <= 0) continue;
        if (status_key($o['status'] ?? '') === 'delivered') $t['delivered'] += $e; else $t['pending'] += $e;
    }
    if ($email !== '') {
        foreach ((array)jload('payouts.json', []) as $po) {
            if (strcasecmp((string)($po['email'] ?? $po['user'] ?? ''), (string)$email) === 0 && in_array($po['status'] ?? '', ['موافق عليه', 'approved', 'paid'], true)) $t['withdrawn'] += (float)($po['amount'] ?? 0);
        }
    }
    $t['total'] = round($t['delivered'], 2); // الإجمالي = المستحق (المسلّم) فقط؛ المعلق منفصل وغير داخل فيه
    $t['remaining'] = round(max(0, $t['delivered'] - $t['withdrawn']), 2);
    $t['overdrawn'] = round(max(0, $t['withdrawn'] - $t['delivered']), 2);
    // تسوية بقرار صاحب الموقع: زيادة سحب متنازل عنها (مش دين ولا خصم من مستقبل). الحد الأقصى للمبلغ المتنازل عنه ثابت؛ أي زيادة فوقه تفضل تنبيه.
    $t['waived'] = 0.0;
    $wv = wf_overdraw_waivers();
    $ek = strtolower(trim((string)$email));
    if ($ek !== '' && isset($wv[$ek]) && $t['overdrawn'] > 0) { $t['waived'] = round(min($t['overdrawn'], (float)$wv[$ek]), 2); $t['overdrawn'] = round($t['overdrawn'] - $t['waived'], 2); }
    foreach (['pending','delivered','withdrawn'] as $k) $t[$k] = round($t[$k], 2);
    return $t;
}
// الحالات التي تُعتبر "نهائية إيجابية" (تُحرَّر فيها العمولة)
function status_releases_commission($k) { return in_array(status_key($k), ['delivered'], true); }
// الحالات التي تُلغي العمولة
function status_reverses_commission($k) { return in_array(status_key($k), ['cancelled', 'returned', 'no_answer'], true); }
// الحالات التي يسمح فيها العميل بالإلغاء
function status_customer_cancellable($k) { return in_array(status_key($k), ['pending', 'confirmed'], true); }

// ============================================================================
//  🔴 حماية الأسعار — السيرفر هو المصدر الوحيد للسعر
//  مع دعم العمولة الإضافية التي يحددها المسوق لمنتجات متجره أو صفحة الهبوط
// ============================================================================
define('MAX_MARKUP_AMOUNT', 5000);     // أقصى عمولة إضافية بالجنيه يمكن للمسوق إضافتها للقطعة
// مفتاح عمولة المسوق المضافة (على القطعة): شغّال — المسوق بيحدد عمولته من تبويب الروابط أو متجره،
// والسعر اللي بيظهر في صفحة الهبوط ورابط المسوق بيبقى السعر بعد العمولة.
if (!defined('MARKETER_EXTRA_COMM_ON')) define('MARKETER_EXTRA_COMM_ON', true);

// عرض الكمية: خصم ثابت لكل قطعة بعد الأولى (قطعتين = 70، 3 قطع = 140 ... )
define('LP_BUNDLE_DISCOUNT', 70);
// الرقمي والسوشيال ميديا: مافيشش عمولة خالص — الرقمي بيتحسب بالدولار على السعر ده،
// والسوشيال ميديا: سعر المصدر بالظبط، والإضافة الثابتة بسيطة بقت للخدمات الأرخص منها بس
// (أي خدمة أقل من 10 قروش بيتضاف عليها 10 قروش) — الاتنين قابلين للتعديل من الإعدادات.
define('DIGITAL_USD_EGP', 56.0);
define('SOCIAL_PRICE_ADDON', 0.10);
// عملة المنتج (من المنتج نفسه أو من دولته) — المصدر الوحيد لقرار «هل الخصم بالجنيه ينطبق؟»
// نفس ترتيب wf_ui_currency: عملة مخزّنة ⇒ عملة الدولة ⇒ ومصدر m5zoon بيسبق الاتنين.
function wf_product_currency($p, $plat = '') {
    if (!is_array($p)) $p = [];
    $plat = trim((string)($plat !== '' ? $plat : ($p['_plat'] ?? $p['plat'] ?? '')));
    $cur = trim((string)($p['currency'] ?? ''));
    if ($cur === '') $cur = wf_country_currency($p['country'] ?? 'مصر');
    if ($plat === 'm5zoon' && function_exists('mz_src_currency')) {
        $src = trim((string)mz_src_currency($p));
        if ($src !== '') $cur = $src;
    }
    return $cur;
}
// الكتابات اللي بتعني الجنيه المصري (فاضية = مجهول = معاملة الجنيه، لأن أغلب الكتالوج مصري)
function wf_egp_currency_labels() {
    return ['', 'ج.م', 'جنيه', 'جنيه مصري', 'EGP', 'LE', 'E£'];
}
function wf_is_egp_currency($currency) {
    $c = trim((string)$currency);
    foreach (wf_egp_currency_labels() as $label) {
        if (strcasecmp($c, trim((string)$label)) === 0) return true;
    }
    return false;
}
// المنتجات الرقمية وخدمات السوشيال ميديا خارج خصم الكمية (سعرها ثابت وتنفيذها آلي)
// وكمان أي منتج سعره مش بالجنيه: الخصم مبلغ ثابت بالجنيه (LP_BUNDLE_DISCOUNT) والموقع
// ما بيحوّلش عملات، فتطبيقه على سعر بالريال/الدرهم يبقى خصم أكبر بكتير من المقصود.
// $currency: عملة المنتج أو المنتج نفسه (array) — null/'' = مجهول = جنيه.
function wf_bulk_discount_plat_ok($plat = '', $currency = null) {
    if (function_exists('wf_is_digital_plat') && wf_is_digital_plat($plat)) return false;
    return wf_bulk_currency_ok($currency);
}
function wf_bulk_currency_ok($currency = null) {
    if ($currency === null) return true;
    if (is_array($currency)) $currency = wf_product_currency($currency);
    return wf_is_egp_currency($currency);
}
// قيمة الخصم لإجمالي كمية معيّنة — 70 ج.م × عدد القطع الزائدة عن القطعة الأولى (للجنيه فقط)
function wf_bulk_discount($qty, $plat = '', $currency = null) {
    if (!wf_bulk_discount_plat_ok($plat, $currency)) return 0.0;
    $q = max(1, min(999, (int)$qty));
    return $q >= 2 ? round((float)LP_BUNDLE_DISCOUNT * ($q - 1), 2) : 0.0;
}
// القيم اللي بتستخدمها الواجهة للحساب الحيّ (عرض فقط — السيرفر هو المرجع)
function wf_bulk_discount_state($plat = '', $currency = null) {
    $plat = trim((string)$plat);
    return [
        'per_piece' => (float)LP_BUNDLE_DISCOUNT,
        'max_qty'   => 99,
        'applies'   => ($plat === '' && $currency === null) ? null : wf_bulk_discount_plat_ok($plat, $currency),
        'label'     => 'خصم ' . (int)LP_BUNDLE_DISCOUNT . ' ج.م لكل قطعة إضافية بعد القطعة الأولى',
        // نفس predicate اللي بيقولده wf_is_digital_plat — بتستخدمه الواجهة عشان متطبّقش الخصم
        'digital'   => ['g2g', 'social', 'smm', 'hevo'],
        // الخصم بالجنيه بس: الواجهة بتقارن عملة المنتج اللي اتفتح بيه نافذة الطلب
        'egp_only'  => true,
        'egp'       => array_values(array_filter(wf_egp_currency_labels(), function ($l) { return $l !== ''; })),
        // عملة المنتج لو الصفحة عندها منتج محدد (صفحة المنتج / صفحة الهبوط)
        'currency'  => $currency === null ? '' : (is_array($currency) ? wf_product_currency($currency) : trim((string)$currency)),
    ];
}
// إجمالي الكمية بعد الخصم — المصدر الوحيد للسعر على السيرفر
function wf_bundle_total($unit, $qty, $plat = '', $currency = null) {
    $unit = max(0, round((float)$unit, 2));
    $qty = max(1, min(999, (int)$qty));
    return max(0, round($unit * $qty - wf_bulk_discount($qty, $plat, $currency), 2));
}
// الخصم بقى عام لكل الطلبات (مش صفحة الهبوط بس) للمنتجات التقليدية — الرقمی والسوشيال
// وأي منتج سعره مش بالجنيه مستثنيان
function wf_landing_bundle_discount($qty, $from_landing = false, $plat = '', $currency = null) {
    return wf_bulk_discount($qty, $plat, $currency);
}

function server_order_line($pid, $plat, $qty = 1, $requested_price = null, $ref = '', $explicit_extra_comm = null, $honor_requested = false) {
    $p = find_product_by_id($pid, $plat);
    if (!$p) return null;
    $plat = $p['_plat'] ?? resolve_plat($pid, $plat);
    $ref = active_ref($ref);

    // سعر البيع الأساسي = سعر المصدر ناقص العمولة المتوقعة (السيرفر هو المرجع).
    $base = store_price($p, $plat);
    $base = max(0, round((float)$base, 2));
    if ($base <= 0) return null;

    $qty = max(1, min(999, (int)$qty));

    // حد سعر البيع: السعر الأساسي هو السعر بدون العمولة، ويضاف إليه فقط عمولة المسوق المحددة
    // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة ولا سعر اتفاق — السعر = سعر المصدر بالظبط
    $wf_no_markup = function_exists('wf_is_digital_plat') && wf_is_digital_plat($plat);
    $min_sale = $base;
    $max_sale = $wf_no_markup ? $base : round(max($base * 3, $base + (float)MAX_MARKUP_AMOUNT), 2);
    if ($max_sale < $min_sale) $max_sale = $min_sale;

    $mk_extra = 0.0;
    if (!$wf_no_markup && MARKETER_EXTRA_COMM_ON) {
        if ($explicit_extra_comm !== null && is_numeric($explicit_extra_comm) && (float)$explicit_extra_comm >= 0) {
            $mk_extra = min((float)MAX_MARKUP_AMOUNT, round((float)$explicit_extra_comm, 2));
        } elseif ($ref !== '' && function_exists('marketer_store_product_comm')) {
            $mk_extra = marketer_store_product_comm($ref, $plat, (string)($p['id'] ?? $pid));
        }
    }

    $default_sale = round($min_sale + $mk_extra, 2);
    $sale = $default_sale;
    if (!$wf_no_markup && $requested_price !== null && (float)$requested_price > 0) {
        $req = round((float)$requested_price, 2);
        // سعر الاتفاق مع العميل (المسوق بيكتبه من لوحة الافلييت): يُحترم كما هو داخل الحدود المسموحة
        $floor = $honor_requested ? $min_sale : $default_sale;
        $sale = max($floor, min($req, $max_sale));
    }
    // تقريب سعر البيع لأقرب 5 أو 0 (نفس سعر العرض في الموقع) مع الالتزام بحدود البيع
    // الرقمي: نفس تقريب العروض (5 أو 0). السوشيال ميديا: السعر = سعر المصدر (والـ10 قروش
    // للخدمات الأرخص من 10 قروش بس) من غير أي تقريب إضافي.
    $sale = wf_is_social_plat($plat) ? round($sale, 2) : wf_round_price($sale);
    if ($sale > $max_sale) $sale = $max_sale;
    if ($sale < $min_sale) $sale = $min_sale;
    $markup = round(max(0, $sale - $base), 2);

    $unit_comm = product_commission_unit($p, $plat, $sale, $ref);

    return [
        'product'    => $p,
        'plat'       => $plat,
        'pid'        => (string)($p['id'] ?? $pid),
        'name'       => aff_clean((string)($p['name'] ?? ''), true),
        'base_price' => $base,
        'unit_price' => $sale,
        'min_sale'   => $min_sale,
        'max_sale'   => $max_sale,
        'markup'     => $markup,
        'qty'        => $qty,
        'total'      => round($sale * $qty, 2),
        'unit_comm'  => $unit_comm,
        'commission' => round($unit_comm * $qty, 2),
        // فرق سعر الاتفاق مع العميل — بيتحسب عمولة المسوق بالكامل
        'agreed_diff'     => round(max(0, $sale - $base) * $qty, 2),
        'honor_requested' => (bool)$honor_requested,
    ];
}
