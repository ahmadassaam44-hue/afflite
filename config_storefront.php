<?php
// ============================================================================
// config_storefront.php — جزء من config.php (السطور 4197–5002 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
// ===== ألوان هوية المتجر: نص متباين + لون مقروء دايماً =====
// المسوّق ممكن يختار لون فاتح جدًا (أبيض مثلاً) فالنص الأبيض ما يبانش؛ الدالتين دول
// بيحسبوا أنسب لون للنص فوق اللون ده، وبيغمّقوا اللون لو مستخدم كنص على خلفية بيضاء.
function wf_color_luminance($hex) {
    $h = ltrim(strtolower(trim((string)$hex)), '#');
    if (preg_match('/^[0-9a-f]{3}$/', $h)) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    if (!preg_match('/^[0-9a-f]{6}$/', $h)) return null;
    $ch = function ($pair) {
        $v = hexdec($pair) / 255;
        return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * $ch(substr($h, 0, 2)) + 0.7152 * $ch(substr($h, 2, 2)) + 0.0722 * $ch(substr($h, 4, 2));
}

function wf_contrast_fg($hex, $dark = '#0f172a', $light = '#ffffff') {
    $l = wf_color_luminance($hex);
    if ($l === null) return $light;
    $c_light = 1.05 / ($l + 0.05);      // تباين النص الأبيض
    $c_dark  = ($l + 0.05) / 0.05;      // تباين النص الكحلي
    if ($c_light >= 3.0) return $light; // الأبيض واضح: نحافظ على شكل الهوية
    if ($c_dark >= 3.0) return $dark;   // لون فاتح: النص يتحول كحلي مقروء
    return ($c_light >= $c_dark) ? $light : $dark;
}
function wf_theme_ink($hex, $fallback = '#0f172a') {
    $h = ltrim(strtolower(trim((string)$hex)), '#');
    if (preg_match('/^[0-9a-f]{3}$/', $h)) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    $l = wf_color_luminance($h);
    if ($l === null) return $fallback;
    if (1.05 / ($l + 0.05) < 3.0) { // فاتح جدًا للنص على أبيض (أبيض/أصفر): نغمّقه مع الحفاظ على الهوية
        $out = '';
        foreach ([0, 2, 4] as $i) {
            $c = max(0, (int)round(hexdec(substr($h, $i, 2)) * 0.45));
            $out .= str_pad(dechex($c), 2, '0', STR_PAD_LEFT);
        }
        return '#' . $out;
    }
    return '#' . $h;
}

// هل المنتج رقمي/سوشيال (بلا شحن ولا عنوان)؟
function wf_is_digital_plat($plat) {
    return in_array(strtolower(trim((string)$plat)), ['g2g', 'social', 'smm', 'hevo'], true);
}
// ===== الرقمي والسوشيال ميديا: لا عمولة إطلاقاً =====
// المنطقتين دول بيتحسبوا من سعر المصدر بالظبط: الرقمي = سعر المنتج بالدولار × 56 ج.م،
// والسوشيال ميديا = سعر المصدر بالظبط (+10 قروش للخدمات الأرخص من 10 قروش). مفيش عمولة بتتخصم من السعر، ومفيش نسبة
// عمولة ليهم في الإعدادات، ومفيش شارة «توفير/عمولة» بتطبع في الواجهة.
function wf_plat_no_commission($plat) {
    return in_array(strtolower(trim((string)$plat)), ['g2g', 'social', 'smm', 'hevo', 'manual'], true);
}
function wf_is_social_plat($plat) {
    return in_array(strtolower(trim((string)$plat)), ['social', 'smm', 'hevo'], true);
}
// سعر الدولار للمنتج الرقمي (ج.م) — من الإعدادات، والافتراضي 56
function wf_digital_usd_egp() {
    $v = function_exists('site_setting') ? trim((string)site_setting('usd_egp', '')) : '';
    $f = ($v !== '' && is_numeric($v)) ? (float)$v : 0;
    return $f > 0 ? $f : (float)DIGITAL_USD_EGP;
}
// الإضافة على سعر خدمة السوشيال ميديا (ج.م) — بتتحسب بس على الخدمات اللي سعرها أقل منها
// (حد أدنى صغير للخدمات الرخيصة جدًا)، مش على كل طلب. الافتراضي 10 قروش.
function wf_social_price_addon() {
    $v = function_exists('site_setting') ? trim((string)site_setting('social_price_addon', '')) : '';
    if ($v === '') return (float)SOCIAL_PRICE_ADDON;
    return is_numeric($v) ? max(0.0, round((float)$v, 2)) : (float)SOCIAL_PRICE_ADDON;
}
// سعر المصدر للمنتج الرقمي بالـ EGP: أقل باقة متاحة بالدولار × سعر الدولار. 0 لو مافيشش دولار
function wf_digital_source_price($p, $plat = 'g2g') {
    if (!is_array($p)) return 0.0;
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $vs = isset($ex['variants_detailed']) && is_array($ex['variants_detailed']) ? $ex['variants_detailed'] : [];
    $rate = wf_digital_usd_egp();
    $usd = 0.0; $cheapest = 0.0;
    // نفس منطق الكتالوج: العرض الرئيسي (أول عرض متاح) هو اللي بيحدد سعر المنتج — مش أرخص عرض
    foreach ($vs as $v) {
        if (!is_array($v)) continue;
        $sold_out = array_key_exists('stock', $v) && (int)$v['stock'] === 0;
        $u = isset($v['unit_price_usd']) && is_numeric($v['unit_price_usd']) ? (float)$v['unit_price_usd'] : 0;
        if ($u <= 0) continue;
        if ($usd <= 0 && !$sold_out) { $usd = $u; break; }
        if ($cheapest <= 0 || $u < $cheapest) $cheapest = $u;
    }
    if ($usd <= 0) $usd = $cheapest;
    if ($usd <= 0 && isset($p['unit_price_usd']) && is_numeric($p['unit_price_usd'])) $usd = (float)$p['unit_price_usd'];
    if ($usd <= 0) return 0.0;
    return round($usd * $rate, 2);
}

// رابط صفحة طلب العميل: المنتجات الرقمية والسوشيال تُطلب من quick_order.php، والتقليدية من landing.php
function wf_client_order_url($id, $plat = '', $ref = '', $extra_comm = 0, $relative = false) {
    $id = trim((string)$id);
    $plat = trim((string)$plat) ?: resolve_plat($id);
    $in_dash = (strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/dashboard/') !== false);
    if (wf_is_digital_plat($plat)) {
        $qs = ['id' => $id];
        if ($plat !== '') $qs['plat'] = $plat;
        if ($ref !== '') $qs['ref'] = (string)$ref;
        $query = http_build_query($qs);
        if ($relative) return ($in_dash ? 'quick_order.php?' : 'dashboard/quick_order.php?') . $query;
        $base = site_base_url();
        return ($base !== '' ? $base . '/dashboard/quick_order.php?' : 'dashboard/quick_order.php?') . $query;
    }
    return landing_page_url($id, $ref, $extra_comm, $plat, $relative);
}

function store_price_from_gross($p, $plat, $gross, $variant_fixed = null) {
    $gross = max(0, (float)$gross);
    if ($gross <= 0) return 0;
    // الرقمي والسوشيال ميديا: السعر = سعر المصدر بدون أي عمولة
    // (الرقمي محسوب بالدولار × 56 ج.م، والسوشيال ميديا عليها إضافة بس للخدمات الأرخص من 10 قروش)
    if (function_exists('wf_plat_no_commission') && wf_plat_no_commission($plat)) {
        if (function_exists('wf_is_social_plat') && wf_is_social_plat($plat)) {
            // سعر المصدر بالظبط (تقريب نص الجنيه زي ما هو)، والإضافة بتيجي جوّه social_norm_price
            // للخدمات اللي سعرها أقل من الإضافة (10 قروش) بس.
            return round(social_norm_price($gross), 2);
        }
        // الرقمي: سعر المصدر (الدولار × سعر الدولار) مقرّب لأقرب 5 أو 0 زي باقي أسعار الموقع
        return wf_round_5_or_0($gross);
    }
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    // الخدمة 6489 لها سعر معلن ثابت 10 ج.م للوحدة حسب الإعداد المعتمد.
    if ($plat === 'social' && ((string)($ex['service_id'] ?? '') === '6489' || (string)($p['id'] ?? '') === 'hevo-6489')) return social_norm_price($gross);
    $net = max(0.01, round($gross - platform_commission_amount($p, $plat, $gross, $variant_fixed), 2));
    if ($plat === 'social') return wf_round_price(social_norm_price($net));
    if ($plat === 'g2g') return wf_round_price(round_half_or_one($net));
    return wf_price_uplift($plat, wf_round_price($net));
}
function store_price($p, $plat) {
    // سعر محدّد من الأدمن (override) = السعر النهائي للعميل بالظبط —
    // مايتطبقش عليه هامش الربح ولا تخفيض الـ12% تاني (وإلا كل حفظ كان بيزحزح السعر ~12%).
    if (!empty($p['_price_override']) && is_numeric($p['price'] ?? null)) return round(max(0, (float)$p['price']), 2);
    // في منصات الأفلييت الفيزيكال، حقل price المخزن هو السعر الصافي بدون عمولة (التكلفة بدون عمولة المنصة).
    if (in_array((string)$plat, ['vendoor', 'timehand', 'safqa', 'angazny', 'm5zoon', 'shopmart'], true)) {
        $stored = base_price($p, $plat);
        $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
        $fixed = source_product_commission($p);
        $gross_src = 0.0;
        if ($plat === 'vendoor') $gross_src = mz_num($ex['purchasing'] ?? '');
        elseif ($plat === 'timehand' || $plat === 'angazny' || $plat === 'shopmart') $gross_src = mz_num($ex['orig_suggested'] ?? '');
        // إذا كان السعر المخزن يساوي السعر الإجمالي (شامل العمولة) ولم تُخصم منه العمولة بعد، نخصم العمولة الثابتة مرة واحدة
        if ($gross_src > 0 && $fixed > 0 && $gross_src > $fixed && abs($stored - $gross_src) < 0.01) {
            return wf_price_uplift($plat, wf_round_price(max(0.01, round($gross_src - $fixed, 2))));
        }
        if ($stored > 0) return wf_price_uplift($plat, wf_round_price(round($stored, 2)));
    }
    return store_price_from_gross($p, $plat, raw_list_price($p, $plat));
}
function store_price_from_saved($p, $plat, $saved_price) {
    $saved = max(0, (float)$saved_price);
    if (in_array((string)$plat, ['vendoor', 'timehand', 'safqa', 'm5zoon', 'angazny', 'shopmart'], true)) {
        $fixed = source_product_commission($p);
        if ($fixed > 0) $saved += $fixed;
    }
    return store_price_from_gross($p, $plat, $saved);
}
// العمولة الأساسية صفر لكل المنصات — المسوق يربح فقط العمولة التي يضيفها بنفسه فوق السعر الأساسي.
// الاستثناء: المنتجات اليدوية ليها عمولة ثابتة لكل قطعة يحددها الأدمن (مصرية/بالجنيه، مخزنة في
// extra.manual_commission) بتتدفع للمسوق على كل قطعة من غير ما سعر العميل يتغير، وفوقها العمولة الإضافية.
function product_commission_unit($p, $plat, $unit_price = null, $ref = '') {
    if ($ref !== '' && ($mk = find_marketer_by_code($ref))) {
        if (($mk['status'] ?? 'approved') === 'suspended') return 0.0;
    }
    $list = store_price($p, $plat);
    $sale = ($unit_price !== null && (float)$unit_price > 0) ? (float)$unit_price : $list;
    $markup = ($sale > $list) ? max(0.0, round($sale - $list, 2)) : 0.0;
    if ((string)$plat === 'manual') {
        $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
        $fixed = round((float)($ex['manual_commission'] ?? 0), 2);
        return round(max(0.0, $fixed) + $markup, 2);
    }
    if ($sale > $list) {
        return $markup;
    }
    return 0.0;
}
function credit_affiliate_amount($ref, $amount, $order_idx = null, $reason = 'عمولة أوردر جديد') {
    $ref = active_ref($ref);
    if ($ref === '') return ['ref' => '', 'm_earn' => 0, 'comm_status' => 'none'];
    $mk = find_marketer_by_code($ref);
    if (!$mk) return ['ref' => '', 'm_earn' => 0, 'comm_status' => 'none'];
    $mk_status = $mk['status'] ?? 'approved';
    if ($mk_status === 'suspended') return ['ref' => $ref, 'm_earn' => 0, 'comm_status' => 'suspended'];
    $amount = max(0, round((float)$amount, 2));
    if ($amount > 0) {
        commission_credit_pending($mk['email'], $amount, $order_idx, $reason);
        if (!empty($mk['referred_by']) && ($par = find_marketer_by_code($mk['referred_by'])) && ($par['status'] ?? 'approved') === 'approved') {
            $joined = strtotime($mk['at'] ?? 'now');
            if ($joined !== false && (time() - $joined) < 243 * 86400) {
                $rb = round($amount * REF_TEAM_RATE, 2);
                if ($rb > 0) commission_credit_pending($par['email'], $rb, $order_idx, 'عمولة فريق (إحالة)');
            }
        }
    }
    return ['ref' => $ref, 'm_earn' => $amount, 'comm_status' => $amount > 0 ? 'pending' : 'no_commission'];
}
// تسجيل عمولة المسوق؛ يحسب العمولة الأساسية + أي عمولة إضافية (Markup) حددها المسوق على المتجر أو صفحة الهبوط.
function credit_affiliate_order($ref, $p, $plat, $qty, $unit_price = null, $explicit_extra_comm = null, $honor_requested = false) {
    $ref = active_ref($ref);
    if ($ref === '') return ['ref' => '', 'm_earn' => 0, 'comm_status' => 'none'];
    $qty = max(1, min(999, (int)$qty));
    $line = server_order_line((string)($p['id'] ?? ''), $plat, $qty, $unit_price, $ref, $explicit_extra_comm, $honor_requested);
    if ($line) { $unit_price = $line['unit_price']; $plat = $line['plat']; $p = $line['product']; }
    else {
        $unit_price = max(0, round((float)$unit_price, 2));
    }
    $sale_comm = product_commission_unit($p, $plat, $unit_price, $ref);
    $m_earn = round($sale_comm * $qty, 2);
    $reason = 'عمولة أوردر جديد';
    if (!empty($line['honor_requested'])) {
        // المسوق طلب لعميل بسعر متفق عليه: الفرق بين السعر الأصلي والسعر المتفق عليه عمولة المسوق
        $m_earn = round(max($m_earn, (float)($line['agreed_diff'] ?? 0)), 2);
        $reason = 'عمولة أوردر — فرق سعر الاتفاق مع العميل';
    }
    $order_idx = $GLOBALS['_WF_ORDER_IDX'] ?? null;
    $aff = credit_affiliate_amount($ref, $m_earn, $order_idx, $reason);
    if (($aff['comm_status'] ?? '') === 'pending' && $m_earn > 0) {
        // بونص التارجت: يُسجَّل كمعلق ويُمنح مرة واحدة لكل مستوى.
        $tiers = [500 => 500, 750 => 1000, 1000 => 1500];
        $cnt = 0;
        foreach (jload('orders.json', []) as $oo) if (($oo['ref'] ?? '') === $ref) $cnt++;
        $cnt++;
        $mk = find_marketer_by_code($ref);
        $claimed = $mk['bonuses'] ?? [];
        foreach ($tiers as $t => $bonus) {
            if ($cnt >= $t && !in_array($t, $claimed, true)) {
                update_user($mk['email'], function (&$u) use ($t) { $b = $u['bonuses'] ?? []; $b[] = $t; $u['bonuses'] = $b; });
                commission_credit_pending($mk['email'], $bonus, $order_idx, 'بونص تارجت ' . $t . ' أوردر');
                $claimed[] = $t;
            }
        }
    }
    $aff['unit_price'] = $unit_price;
    $aff['base_price'] = $line['base_price'] ?? store_price($p, $plat);
    return $aff;
}
function sale_price_ref($p, $plat, $ref = '') {
    return marketer_effective_price($p, $plat, $ref);
}
// ===== السعر العادي المعروض للعميل (نفس اللي في الرئيسية — بدون إضافة عمولة أو هامش) =====
function price_num($v) {
    if (preg_match('/\d[\d,.]*/u', (string)$v, $m)) return mz_num($m[0]);
    return 0;
}
function list_price($p, $plat = '') {
    return store_price($p, $plat);
}
// ===== صور التفاصيل المحلية (assets/detail) — خريطة plat#id => [صور] =====
function local_detail_map($plat) {
    static $cache = [];
    if (isset($cache[$plat])) return $cache[$plat];
    $out = [];
    $didx = jload('detail_index.json', []);
    if ($didx) {
        $pref = $plat . '#';
        $plen = strlen($pref);
        foreach ($didx as $k => $imgs) {
            if (strpos((string)$k, $pref) === 0 && is_array($imgs) && $imgs) {
                $out[substr((string)$k, $plen)] = array_values(array_unique($imgs));
            }
        }
        return $cache[$plat] = $out;
    }
    foreach (jload($plat . '.json', []) as $x) {
        if (!is_array($x)) continue;
        $imgs = [];
        foreach ((array)($x['images'] ?? []) as $u) {
            if (is_string($u) && strpos($u, 'assets/detail/') === 0) $imgs[] = $u;
        }
        foreach ((array)(($x['extra'] ?? [])['detail_images'] ?? []) as $u) {
            if (is_string($u) && strpos($u, 'assets/detail/') === 0) $imgs[] = $u;
        }
        if ($imgs) $out[(string)($x['id'] ?? '')] = array_values(array_unique($imgs));
    }
    return $cache[$plat] = $out;
}
// دمج صور التفاصيل المحلية مع أي منتج ناقصها (منتجات Supabase مثلًا)
function with_detail_imgs($p, $plat = '') {
    if (!is_array($p)) return $p;
    $plat = $plat !== '' ? $plat : ($p['_plat'] ?? '');
    if ($plat === '' || !in_array($plat, ['vendoor', 'safqa', 'timehand', 'angazny', 'ledger', 'm5zoon', 'shopmart'], true)) return $p;
    $d = local_detail_map($plat)[(string)($p['id'] ?? '')] ?? [];
    if (!$d) return $p;
    $have = [];
    foreach ((array)($p['images'] ?? []) as $u) if (is_string($u) && strpos($u, 'assets/detail/') === 0) $have[] = $u;
    if (!$have) $p['images'] = array_values(array_unique(array_merge((array)($p['images'] ?? []), $d)));
    if (!isset($p['extra']) || !is_array($p['extra'])) $p['extra'] = [];
    if (empty($p['extra']['detail_images'])) $p['extra']['detail_images'] = $d;
    return $p;
}
// روابط الصور المقبولة في فورمات الأدمن: خارجي http(s)، مسار يبدأ بـ /، أو صور التفاصيل المحلية (assets/detail/...)
// اللي بتتحط في الملفات نفسها — رفضها كان بيفشّل حفظ أي تعديل حتى لو الأدمن مالمسش الصور.
function wf_image_url_allowed($u) {
    $u = trim((string)$u);
    if ($u === '' || strlen($u) > 600) return false;
    if (preg_match('~^(https?://|/)~i', $u)) return true;
    return strpos($u, 'assets/') === 0;
}
// مسار أصول محلي (assets/...) يتحوّل صح سواء الصفحة في الجذر أو داخل dashboard
function asset_url($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    if (strpos($u, 'data:') === 0) return $u;
    if (preg_match('~^(https?:)?//~i', $u)) {
        return strpos($u, ' ') !== false ? str_replace(' ', '%20', $u) : $u;
    }
    if (strpos($u, 'assets/') !== 0) return $u;
    $in_dash = (strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/dashboard/') !== false);
    return $in_dash ? '../' . $u : $u;
}
// صورة بديلة موحّدة (لوجو الموقع) للخدمات اللي مالهاش صور حقيقية زي خدمات السوشيال ميديا
function wf_product_placeholder($plat = '') {
    return asset_url('assets/icon-512.png');
}
function wf_img_is_placeholder($url) {
    $u = strtolower(trim((string)$url));
    return $u === '' || strpos($u, 'placehold.co') !== false || strpos($u, 'placeholder') !== false;
}
function product_first_image($p, $plat = '') {
    $plat = (string)($plat !== '' ? $plat : ($p['_plat'] ?? ''));
    $imgs = clean_product_images($p['images'] ?? []);
    if (!$imgs && !empty($p['extra']['detail_images']) && is_array($p['extra']['detail_images'])) {
        $imgs = clean_product_images($p['extra']['detail_images']);
    }
    if ($imgs) {
        // صور placehold.co القديمة (خدمات السوشيال/الرقمي) تُستبدل بلوجو الموقع
        $real = array_values(array_filter($imgs, function ($u) { return !wf_img_is_placeholder($u); }));
        if ($real) return asset_url($real[0]);
        return wf_product_placeholder($plat);
    }
    return wf_product_placeholder($plat);
}
function inline_css() { $p = __DIR__ . '/assets/style.css'; if (file_exists($p)) echo file_get_contents($p); }
// تحويل كود اللون hex أو الاسم الإنجليزي/الملتصق إلى اسم لون عربي نظيف
function color_name($c) {
    $c = trim((string)$c);
    if ($c === '') return '';
    $c = preg_replace('/^messages\./iu', '', $c);
    $c = preg_replace('/^Choose-/iu', '', $c);
    $c = preg_replace('/[\x{0640}]+/u', '', $c);
    if (preg_match('/[\*\+]|(?<=[\p{L}])X(?=[\p{L}])/u', $c)) {
        $parts = preg_split('/\s*(?:[\*\+]|(?<=[\p{L}])X(?=[\p{L}]))\s*/u', $c);
        $clean_parts = [];
        foreach ($parts as $pt) {
            $cn = color_name($pt);
            if ($cn !== '') $clean_parts[] = $cn;
        }
        return implode(' × ', $clean_parts);
    }
    static $map = [
        'beige' => 'بيج', 'biege' => 'بيج', 'burgundy' => 'برجندي', 'برجاندي' => 'برجندي',
        'babyblue' => 'بيبي بلو', 'بيبيبلو' => 'بيبي بلو', 'بيبي.بلو' => 'بيبي بلو',
        'black' => 'أسود', 'اسود' => 'أسود', '(أسود)' => 'أسود',
        'blue' => 'أزرق', 'ازرق' => 'أزرق',
        'brown' => 'بني', 'بنى' => 'بني',
        'darkgray' => 'رمادي غامق', 'رماديغامق' => 'رمادي غامق', 'رمادي.غامق' => 'رمادي غامق',
        'رماديفاتح' => 'رمادي فاتح', 'رمادي.فاتح' => 'رمادي فاتح',
        'darkgreen' => 'أخضر داكن', 'darkred' => 'أحمر داكن',
        'grey' => 'رمادي', 'gray' => 'رمادي', 'رمادى' => 'رمادي',
        'green' => 'أخضر', 'اخضر' => 'أخضر',
        'havan' => 'هافان', 'mixid' => 'مشكل', 'mix color' => 'مشكل',
        'navyblue' => 'كحلي', 'offwhite' => 'أوف وايت', 'اوف.وايت' => 'أوف وايت', 'اوفوايت' => 'أوف وايت', 'أوفوايت' => 'أوف وايت', 'off' => 'أوف وايت',
        'orange' => 'برتقالي', 'اورانج' => 'برتقالي', 'اورنج' => 'برتقالي',
        'pink' => 'بينك', 'بيك' => 'بينك',
        'petrolblue' => 'بترولي', 'petroleum' => 'بترولي',
        'purple' => 'بنفسجي', 'بيربل' => 'بنفسجي',
        'red' => 'أحمر', 'احمر' => 'أحمر',
        'rose' => 'روز', 'roze' => 'روز', 'روز.فاتح' => 'روز فاتح', 'روزبودر' => 'روز بودر',
        'white' => 'أبيض', 'ابيض' => 'أبيض', 'أببض' => 'أبيض',
        'yellow' => 'أصفر', 'اصفر' => 'أصفر',
        'زيتيفاتح' => 'زيتي فاتح', 'بنيغامق' => 'بني غامق', 'ازرقغامق' => 'أزرق غامق',
        'منتجرين' => 'منت جرين', 'منت.جرين' => 'منت جرين',
        'ستيلبلو' => 'ستيل بلو', 'فانتابلاك' => 'أسود فاحم', 'دهبي' => 'ذهبي',
    ];
    $cl = mb_strtolower($c);
    if (in_array($cl, ['choose', 'اختر', 'يختار', 'default', 'null', 'none'], true)) return '';
    if (isset($map[$cl])) return $map[$cl];
    if (!preg_match('/^#?([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $c)) return $c;
    $h = ltrim($c, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    $r = hexdec(substr($h, 0, 2)); $g = hexdec(substr($h, 2, 2)); $b = hexdec(substr($h, 4, 2));
    $mx = max($r, $g, $b); $mn = min($r, $g, $b);
    if ($mx - $mn < 26) {
        $avg = ($r + $g + $b) / 3;
        if ($avg < 45) return 'أسود';
        if ($avg < 110) return 'رمادي غامق';
        if ($avg < 175) return 'رمادي';
        if ($avg < 235) return 'رمادي فاتح';
        return 'أبيض';
    }
    static $pal = [
        ['كحلي', 10, 40, 90], ['أزرق', 40, 80, 170], ['أزرق فاتح', 125, 178, 217], ['سماوي', 170, 205, 240],
        ['أزرق لامع', 63, 109, 255], ['أخضر داكن', 25, 80, 40], ['أخضر', 60, 150, 90], ['أخضر فاتح', 150, 215, 165],
        ['أصفر', 247, 225, 20], ['أصفر فاتح', 250, 230, 120], ['ذهبي', 192, 147, 35],
        ['بني', 120, 70, 15], ['عسلي', 200, 165, 105], ['بيج', 240, 215, 175], ['كريمي', 252, 240, 215],
        ['أحمر', 235, 30, 30], ['نبيتي', 100, 15, 25], ['خمري', 150, 55, 30],
        ['وردي', 244, 130, 177], ['وردي فاتح', 255, 205, 230], ['وردي غامق', 229, 7, 155],
        ['برتقالي', 235, 110, 55], ['بنفسجي', 150, 90, 190], ['تركوازي', 60, 200, 190],
    ];
    $best = 'رمادي'; $bd = PHP_INT_MAX;
    foreach ($pal as $e) {
        $d = ($r - $e[1]) * ($r - $e[1]) * 3 + ($g - $e[2]) * ($g - $e[2]) * 4 + ($b - $e[3]) * ($b - $e[3]) * 2;
        if ($d < $bd) { $bd = $d; $best = $e[0]; }
    }
    return $best;
}
// هل القيمة اسم لون حقيقي؟ (hex أو اسم لون معروف) — لفلترة تسميات صفقة الزائفة مثل "مستورد" و"Free size"
function is_color_label($v) {
    $v = trim((string)$v);
    if ($v === '') return false;
    $vl = mb_strtolower($v);
    if (strpos($vl, 'مستورد') !== false || strpos($vl, 'free size') !== false || strpos($vl, 'freesize') !== false || strpos($vl, 'one size') !== false || strpos($vl, 'باندل') !== false || in_array($vl, ['choose', 'اختر', 'يختار', 'default', 'null'], true)) {
        return false;
    }
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) return true;
    if (strpos($v, '×') !== false || strpos($v, '*') !== false || strpos($v, '+') !== false) return true;
    $n = trim(mb_strtolower(color_name($v)));
    $n = preg_replace('/^لون\s*/u', '', $n);
    $n = trim(preg_replace('/\s+(فاتح|غامق|داكن|فاتحه|غامقه|فاحم|بودر)$/u', '', $n));
    static $list = [
        'أبيض', 'ابيض', 'أسود', 'اسود', 'رمادي', 'رمادى', 'رصاصي', 'رصاصى', 'بيج', 'كحلي',
        'سماوي', 'سماوى', 'أزرق', 'ازرق', 'أخضر', 'اخضر', 'أصفر', 'اصفر', 'أحمر', 'احمر',
        'برتقالي', 'وردي', 'نبيتي', 'خوخي', 'تركوازي', 'تركواز', 'لبنى', 'لبني', 'موف', 'بنفسجي', 'بني',
        'برجندي', 'ذهبي', 'فضي', 'دهبي', 'فيروزي', 'زيتوني', 'كريمي', 'كشمير', 'سلموني',
        'بترولي', 'ليلكي', 'عسلي', 'نيلي', 'زهري', 'خمري', 'فوشي', 'فوشيا', 'مارون', 'زيتي', 'أوف وايت',
        'روز', 'منت جرين', 'منت', 'بيبي بلو', 'ستيل بلو', 'كافيه', 'هافان', 'جملي', 'طوبي', 'بصلي',
        'تلجي', 'فسفوري', 'كيوي', 'نحاسي', 'مشكل', 'اورانج', 'بينك', 'سيمون', 'انديجو', 'بيبسي',
        'white', 'black', 'gray', 'grey', 'blue', 'red', 'green', 'yellow', 'beige', 'brown',
        'silver', 'gold', 'pink', 'purple', 'navy', 'khaki', 'olive', 'cream', 'mint', 'turquoise',
        'cyan', 'orange', 'maroon', 'ivory', 'tan', 'bronze', 'copper', 'violet', 'magenta', 'lime',
        'teal', 'indigo', 'crimson', 'coral', 'peach', 'lavender',
    ];
    return in_array($n, $list, true) || vd_color_ok($v);
}

// استخراج قائمة ألوان نظيفة وموحدة لأي منتج على أي منصة
function clean_product_colors($p, $plat = '') {
    if (!is_array($p)) return [];
    $plat = $plat ?: ($p['_plat'] ?? resolve_plat($p['id'] ?? ''));
    if ($plat === 'm5zoon' || $plat === 'g2g' || $plat === 'social') return [];
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $raw = [];
    foreach ((array)($ex['colors'] ?? []) as $c) {
        if (is_string($c) && trim($c) !== '') $raw[] = trim($c);
    }
    if (empty($raw) && !empty($ex['variants_detailed']) && is_array($ex['variants_detailed'])) {
        foreach ($ex['variants_detailed'] as $vd) {
            $vc = trim((string)($vd['color'] ?? ''));
            if ($vc !== '') $raw[] = $vc;
        }
    }
    $has_non_hex = false;
    foreach ($raw as $rc) {
        if ($rc !== '' && $rc[0] !== '#') { $has_non_hex = true; break; }
    }
    if (!$has_non_hex && count($raw) === 1 && $raw[0][0] === '#') {
        return [];
    }
    $out = [];
    foreach ($raw as $rc) {
        if ($has_non_hex && $rc[0] === '#') continue;
        if ($plat === 'safqa' && !is_color_label($rc)) continue;
        if ($plat === 'vendoor') {
            if (!vd_color_ok($rc)) continue;
            $cn = color_name(vd_color_show($rc));
        } elseif ($plat === 'angazny') {
            list($am, $ac) = ag_split($rc);
            $cn = color_name($ac !== '' ? $ac : $rc);
        } else {
            if (!is_color_label($rc)) continue;
            $cn = color_name($rc);
        }
        $cn = trim((string)$cn);
        if ($cn !== '' && !in_array($cn, $out, true)) {
            $out[] = $cn;
        }
    }
    return $out;
}

// استخراج قائمة مقاسات نظيفة وموحدة لأي منتج
function clean_product_sizes($p, $plat = '') {
    if (!is_array($p)) return [];
    $plat = $plat ?: ($p['_plat'] ?? resolve_plat($p['id'] ?? ''));
    if ($plat === 'm5zoon') return [];
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $raw = (array)($p['sizes'] ?? []);
    if (in_array($plat, ['safqa', 'vendoor', 'angazny', 'timehand', 'shopmart'], true) && !empty($ex['variants_detailed']) && is_array($ex['variants_detailed'])) {
        foreach ($ex['variants_detailed'] as $vd) {
            $sz = trim((string)($vd['size'] ?? ''));
            if ($sz !== '') $raw[] = $sz;
        }
    }
    $out = [];
    foreach ($raw as $s) {
        $s = trim(preg_replace('/^\.+|\.+$/u', '', trim((string)$s)));
        if ($s === '' || in_array(mb_strtolower($s), ['اختر', 'يختار', 'choose', 'select', 'default', 'null'], true)) continue;
        if (!in_array($s, $out, true)) $out[] = $s;
    }
    return $out;
}
// تطبيع نص عربي للمقارنة (تشكيل + همزات + حروف فارسية)
function vd_norm($s) {
    $s = mb_strtolower(trim((string)$s));
    $s = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $s);
    $s = str_replace(['چ', 'پ', 'گ'], ['ج', 'ب', 'ك'], $s);
    $s = str_replace('ى', 'ي', $s);
    $s = str_replace('ة', 'ه', $s);
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
    return trim($s);
}
// فيندور: هل القيمة لون حقيقي؟ (تسميات مثل "بني 41"/"أبيض M" تُقبل بعد حذف لاحقة المقاس، والأكواد والعطور/الباقات تُرفض)
function vd_color_ok($v) {
    $v = trim((string)$v);
    if ($v === '') return false;
    $vl = mb_strtolower($v);
    if (strpos($vl, 'مستورد') !== false || strpos($vl, 'free size') !== false || strpos($vl, 'freesize') !== false || strpos($vl, 'one size') !== false || strpos($vl, 'باندل') !== false) {
        return false;
    }
    if (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $v)) return true;
    $n = vd_norm($v);
    $n = preg_replace('/^لون\s*/u', '', $n);
    $n = trim(preg_replace('/\s+(مقاس\s*)?(xxl|xl|xxs|xs|[lms]|\d{1,2})$/u', '', $n));
    if ($n === '') return false;
    static $w = ['ابيض', 'اسود', 'احمر', 'ازرق', 'اخضر', 'اصفر', 'برتقالي', 'وردي', 'كحلي', 'سماوي', 'رمادي',
        'رصاصي', 'بيج', 'بني', 'كريمي', 'ذهبي', 'فضي', 'سلفر', 'سيلفر', 'دهبي', 'جولد', 'نبيتي', 'لبنى', 'موف',
        'بنفسجي', 'برجندي', 'كشمير', 'هافان', 'جملي', 'بترولي', 'زيتي', 'فيروزي', 'تركواز', 'جنزاري', 'بينك',
        'فوشيا', 'كموني', 'كافيه', 'بيبسي', 'بيبي', 'بلو', 'منجاوي', 'صحراوي', 'كاكاو', 'زهري', 'سيمون',
        'لافندر', 'روز', 'طوبي', 'جينز', 'كيوي', 'حديدي', 'بطيخي', 'لموني', 'رملي', 'منت', 'اوف وايت', 'برونز',
        'كاكي', 'زيتوني', 'عسلي', 'ليلكي', 'مارون', 'بستشيو', 'فيراني', 'بيستاج', 'سموكي', 'نيلي', 'خمري',
        'زتوني', 'اورانج', 'اببض'];
    foreach ($w as $x) if (mb_strpos($n, $x) !== false) return true;
    static $en = ['white', 'black', 'gray', 'grey', 'blue', 'red', 'green', 'yellow', 'beige', 'brown', 'silver',
        'gold', 'pink', 'purple', 'navy', 'olive', 'cream', 'mint', 'turquoise', 'orange', 'ivory', 'bronze',
        'violet', 'lime', 'teal', 'lavender', 'peach', 'coral'];
    foreach ($en as $x) if (preg_match('/\b' . $x . '\b/', $n)) return true;
    return false;
}
// فيندور: عرض اسم اللون بدون لاحقة المقاس ("بني 41" ← "بني")
function vd_color_show($v) {
    $v = trim((string)$v);
    if ($v === '' || $v[0] === '#') return $v;
    return trim(preg_replace('/\s+(مقاس\s*)?(XXL|XL|XXS|XS|L|M|S|\d{1,2})$/u', '', $v));
}
// تحويل HTML جدول المقاسات إلى جدول مصمم (داكن بنمط حسب الوزن / فاتح بالقياسات)
function vd_size_chart_html($html) {
    $html = trim((string)$html);
    if ($html === '') return '';
    $frag = stripos($html, '<table') !== false
        ? preg_replace('/^.*?<table/isu', '<table', $html)
        : '<table><tr><td>' . $html . '</td></tr></table>';
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><html><head><meta charset="utf-8"></head><body dir="rtl">' . $frag . '</body></html>');
    libxml_clear_errors();
    $rows = [];
    foreach ($doc->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $cn) {
            if ($cn->nodeType === XML_ELEMENT_NODE && ($cn->nodeName === 'td' || $cn->nodeName === 'th')) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $cn->textContent));
            }
        }
        if ($cells) $rows[] = $cells;
    }
    if (!$rows) return '';
    if (count($rows) === 1 && count($rows[0]) === 1) {
        $txt = $rows[0][0];
        if ($txt === '') return '';
        return '<div data-ui="u-d711ffa8 udb udc udd ude udf udg udh udi">' . esc($txt) . '</div>';
    }
    $flat = implode(' ', call_user_func_array('array_merge', $rows));
    $isKg = mb_strpos($flat, 'كجم') !== false || mb_strpos($flat, 'كيلو') !== false;
    $isCm = mb_strpos($flat, 'سم') !== false;
    $nCols = 0;
    foreach ($rows as $r) $nCols = max($nCols, count($r));
    $out = '';
    if ($isKg && $nCols === 2) {
        $out .= '<div data-ui="u-94164b98 udb udj udk udl udm">';
        $out .= '<div data-ui="u-5e0d7e41 udn"><span data-ui="u-ddd566ba udo udp udq udr uds udt udu udv">SIZE GUIDE BY WEIGHT</span>';
        $out .= '<div data-ui="u-72e8e102 udq udw udu udx">دليل المقاسات حسب الوزن</div>';
        $out .= '<div data-ui="u-b2dbda49 udy udt udz">اختر مقاسك المناسب بناءً على وزن الجسم (كيلوجرام)</div></div>';
        $out .= '<div data-ui="u-4b9e29d9 ue0">';
        $out .= '<div data-ui="u-07221e6b ue1 ue2 ue3 ue4 ue5"><span data-ui="u-b826e54a udy udt ue6">نطاق الوزن المناسب (Weight)</span><span data-ui="u-b826e54a udy udt ue6">المقاس (Size)</span></div>';
        foreach ($rows as $r) {
            if (count($r) < 2) {
                if (count($r) === 1 && preg_match('/^(\S+)\s+(.+)$/u', $r[0], $mm)) $r = [$mm[1], $mm[2]];
                else continue;
            }
            $out .= '<div data-ui="u-10a26c78 ue1 ue2 ue7 ue8 ue9 uea ueb"><span data-ui="u-087e1d82 uec ue6 uda">' . esc($r[1]) . '</span><span data-ui="u-dc16d89b ued udu uee">' . esc($r[0]) . '</span></div>';
        }
        $out .= '</div></div>';
        return $out;
    }
    $head0 = vd_norm($rows[0][0] ?? '');
    $hasHead = mb_strpos($head0, 'مقاس') !== false || mb_strpos($head0, 'size') !== false || mb_strpos($head0, 'ميزه') !== false;
    $body = $hasHead ? array_slice($rows, 1) : $rows;
    $out .= '<div data-ui="u-d3daa8ea udb uef ueg ude udm">';
    $out .= '<div data-ui="u-5989bff1 udn ueh"><div data-ui="u-f9638ff2 uei udu uej">SIZE CHART • جدول المقاسات</div>';
    if ($isCm) $out .= '<div data-ui="u-81c3049b udt uek udz">جميع القياسات بوحدة السنتيمتر (cm)</div>';
    $out .= '</div><div data-ui="u-103cc5b0 uel"><table data-ui="u-e5d48c3a uem udg uen">';
    if ($hasHead) {
        $out .= '<tr data-ui="u-ac5b2ab5 udk udq">';
        foreach ($rows[0] as $h) $out .= '<th data-ui="u-44449182 ueo ue6 udn">' . esc($h) . '</th>';
        $out .= '</tr>';
    }
    foreach ($body as $i => $r) {
        $out .= '<tr style="background:' . ($i % 2 ? '#f8fafc' : '#fff') . '">';
        foreach ($r as $j => $c) {
            if ($j === 0) $out .= '<td data-ui="u-a4d7ccb7 ueo udn uep udu uej">' . esc($c) . '</td>';
            else $out .= '<td data-ui="u-dd07832a ueo udn ueq uer">' . esc($c) . '</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</table></div></div>';
    return $out;
}
// ليدجر: فصل "لون + كود" (الكود في الآخر أو الأول، بمسافات متفاوتة) — الكود: حروف+أرقام أو رقم+حروف
function ld_split($v) {
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
    if ($v === '') return ['', ''];
    // الكود في الآخر: "... ALEX01" / "... CG01" / "... 41A" (لازم يبدأ بحرف أو يحتوي حروف+أرقام)
    if (preg_match('/^(.*?)[\s_]+([A-Za-z]{1,}[0-9][A-Za-z0-9]*|[0-9]+[A-Za-z]+[A-Za-z0-9]*)\s*$/u', $v, $m)) {
        $col = trim($m[1]); $code = trim($m[2]);
        if ($col !== '' && preg_match('/[A-Za-z0-9]/u', $code)) return [$col, $code];
    }
    // الكود في الأول: "AF-1 مينت جريين" / "AF-2 اسود" (يبدأ بحروف فيها شرطة/أرقام)
    if (preg_match('/^([A-Za-z]+(?:-[A-Za-z0-9]+)+|[A-Za-z]{2,}[0-9][A-Za-z0-9\-]*)\s+(.+)$/u', $v, $m)) {
        $code = trim($m[1]); $col = trim($m[2]);
        if ($col !== '') return [$col, $code];
    }
    return [$v, ''];
}
// ليدجر: مفتاح تجميع موحّد (تطبيع مسافات مزدوجة + trim) عشان "ابيض  CG01" و"ابيض CG01" يبقوا لون واحد
function ld_key($v) {
    return trim(preg_replace('/\s+/u', ' ', (string)$v));
}
// تنظيف اسم/وصف من آثار الأفيليتس (عرض فقط — المصدر untouched)
// بتشيل: أسماء المنصات (عربي+إنجليزي) + روابطها + كلمات الأفيليت + (مخزن XXX)/(كود XXX) من الأسماء
function aff_clean($s, $is_name = false) {
    $s = (string)$s;
    // روابط المنصات
    $s = preg_replace('~https?://[^\s<>"\']*(?:ven-door|angazny|ledgerandlogic|time-hand|m5zoon|safka-eg|safka|aff-shopmart|shopmart)[^\s<>"\']*~iu', '', $s);
    // أسماء المنصات (عربي/إنجليزي) — بحدود مش حرف/رقم عشان ما نقطعش كلمات زي "مخزونة"
    static $plat_names = [
        'تايم هاند', 'تايم-هاند', 'تايمهاند', 'timehand', 'time hand', 'time-hand',
        'فيندور', 'فين دور', 'فين-دور', 'vendoor', 'ven-door', 'ven door',
        'ليدجر', 'ledger', 'ledgerandlogic',
        'أنجزني', 'انجزني', 'angazny',
        'مخزون', 'm5zoon', 'm5 zone', 'm5-zone',
        'صفقة', 'safqa', 'safka',
        'شوب مارت', 'شوب-مارت', 'شوبمارت', 'أف شوب مارت', 'اف شوب مارت', 'shopmart', 'shop mart', 'shop-mart',
    ];
    foreach ($plat_names as $n) {
        $p = preg_quote($n, '/');
        $s = preg_replace('/(?<![\p{L}\p{N}])' . $p . '(?![\p{L}\p{N}])/iu', ' ', $s);
    }
    // كلمات الأفيليت وأكواد المخازن وأرقام التواصل وأسماء مسؤولي التعاقدات
    $s = preg_replace('/(?<![\p{L}\p{N}])(?:affiliate|أفلييت|افيليت|التسويق بالعمولة|عمولة المسوق)(?![\p{L}\p{N}])/iu', ' ', $s);
    $s = preg_replace('/\s*[\(\[\{]\s*(?:مخزن|محزن|مخز|كود|code)[^\)\]\}]*[\)\]\}]\s*/iu', ' ', $s);
    $s = preg_replace('/\s+(?:مخزن|محزن)\s+[A-Za-z0-9\-]{1,12}(?=\s|$)/u', ' ', $s);
    $s = preg_replace('/(?:📞\s*)?(?:للمتابعة|للتواصل)\s+مع\s+(?:مسؤل|مسئول|مسؤول)[^\n\r.،!]*?(?:01[0125]\d{8})?/u', ' ', $s);
    $s = preg_replace('/(?:عبد\s*الرحمن\s*(?:فوزي|قرطام)|أ?حمد\s*حلمي)\s*[:：\-]?\s*(?:01[0125]\d{8})?/u', ' ', $s);
    $s = preg_replace('/(?:\+?2)?01[0125]\d{8}/u', ' ', $s);
    $s = str_replace("\xEF\xBF\xBD", '', $s);
    if ($is_name) {
        $s = preg_replace('/\(\s*\)/u', ' ', $s);
        $s = preg_replace('/\[\s*\]/u', ' ', $s);
        $s = preg_replace('/^[\s?؟\-–—:،؛,.]+|[\s\-–—:،؛,.]+$/u', '', $s);
        $s = preg_replace('/\s{2,}/u', ' ', $s);
    }
    return trim((string)preg_replace('/^[\s\-–—:،؛,.]+|[\s\-–—:،؛,.]+$/u', '', $s));
}
// تنظيف HTML وصف من روابط/أسماء الأفيليتس (عرض فقط)
function aff_clean_html($html) {
    $html = (string)$html;
    $html = preg_replace('~<a\b[^>]*href="https?://[^"]*(?:ven-door|angazny|ledgerandlogic|time-hand|m5zoon|safka-eg|safka|aff-shopmart|shopmart)[^"]*"[^>]*>.*?</a>~isu', '', $html);
    $html = preg_replace('~https?://[^\s<>"\']*(?:ven-door|angazny|ledgerandlogic|time-hand|m5zoon|safka-eg|safka|aff-shopmart|shopmart)[^\s<>"\']*~iu', '', $html);
    return $html;
}

// دولة المنتج (بتفرق في شحن المنتجات اليدوية) — الافتراضي مصر زي باقي الموقع
function wf_product_country($p) {
    if (!is_array($p)) $p = [];
    return wf_match_country($p['country'] ?? '', 'مصر');
}
// رسوم شحن ثابتة حدّدها المالك للمنتج نفسه (بعملة دولة المنتج) — بتتقدم على جدول مناطق الدولة.
// null = المنتج مالوش سعر شحن خاص ⇒ الرسوم من جدول الدولة/المنطقة زي الأول.
// الصفر المُدخل صراحةً معناه «شحن مجاني» — وده غير الفاضي (مش مُحدد).
function wf_product_shipping($p) {
    if (!is_array($p)) return null;
    $v = $p['ship_fee'] ?? ($p['extra']['ship_fee'] ?? null);
    if ($v === null || $v === '') return null;
    if (is_array($v) || !is_numeric($v)) return null;
    $f = round((float)$v, 2);
    if ($f < 0) return null;
    return min(2000.0, $f);   // نفس سقف رسوم الشحن المستخدم في الطلبات
}
// عملة رسوم الشحن: الجنيه لمصر، وعملة الدولة نفسها للخليج (مفيش تحويل عملات)
function wf_ship_currency($plat = '', $country = '') {
    $cn = wf_match_country($country, '');
    return ($cn !== '' && $cn !== 'مصر') ? wf_country_currency($cn) : 'ج.م';
}
// اسم حقل المنطقة: «المحافظة» لمصر، و«المنطقة / المدينة» لدول الخليج
function wf_ship_region_label($country = '') {
    $cn = wf_match_country($country, '');
    return ($cn !== '' && $cn !== 'مصر') ? 'المنطقة / المدينة' : 'المحافظة';
}
// تحويل بيانات الشحن إلى خريطة (المنطقة => التكلفة بعملة الدولة)
// $country بتفرق مع المنتجات اليدوية: مصر = جدول تايم هاند بالجنيه، وكل دولة خليجية = جدول
// مناطقها بعملة الدولة (والرسوم بيدخلها المالك من لوحة إدارة الشحن).
function site_ship_map($plat = '', $country = '') {
    $out = [];
    $cn = function_exists('wf_match_country') ? wf_match_country($country, '') : trim((string)$country);
    // دولة غير مصر: ممنوع الرجوع لأي جدول مصري (محافظات/أسعار بالجنيه) — أحسن من رسوم غلط
    $foreign = ($cn !== '' && $cn !== 'مصر');
    $lookup_plat = trim((string)$plat) !== '' ? trim((string)$plat) : ($foreign ? 'manual' : 'vendoor');
    if (function_exists('site_ship_regions')) {
        foreach ((array)site_ship_regions($lookup_plat, $cn) as $r) {
            if (is_array($r) && !empty($r['region'])) {
                $out[trim((string)$r['region'])] = max(0, (float)($r['fee'] ?? 0));
            }
        }
    }
    if (!$out && !$foreign && function_exists('site_shipping')) {
        foreach ((array)site_shipping() as $k => $v) {
            if (is_string($k) && is_numeric($v)) {
                $out[$k] = max(0, (float)$v);
            } elseif (is_array($v) && !empty($v['regions']) && is_array($v['regions'])) {
                foreach ($v['regions'] as $r) {
                    if (is_array($r) && !empty($r['region']) && !isset($out[$r['region']])) {
                        $out[trim((string)$r['region'])] = max(0, (float)($r['fee'] ?? 0));
                    }
                }
            }
        }
    }
    if (!$out && !$foreign) {
        // احتياطي آخر: يطابق أسعار الشحن الحقيقية لمحافظات مصر الـ27 (تُحدَّث من بيانات شوب مارت).
        $out = [
            'القاهرة' => 70, 'الجيزة' => 70, 'الإسكندرية' => 75, 'القليوبية' => 75,
            'الدقهلية' => 80, 'الشرقية' => 80, 'الغربية' => 80, 'المنوفية' => 80,
            'البحيرة' => 80, 'كفر الشيخ' => 80, 'دمياط' => 80, 'بورسعيد' => 80,
            'الإسماعيلية' => 80, 'السويس' => 80, 'البحر الأحمر' => 85, 'الفيوم' => 85,
            'بني سويف' => 85, 'المنيا' => 85, 'أسيوط' => 90, 'سوهاج' => 90,
            'قنا' => 90, 'الأقصر' => 90, 'أسوان' => 95, 'مطروح' => 95,
            'الوادي الجديد' => 95, 'شمال سيناء' => 95, 'جنوب سيناء' => 95
        ];
    }
    return $out;
}

// Keep colour and size separately without losing legacy variant / offer descriptions.
// This metadata never participates in pricing, shipping or affiliate calculations.
function order_variant_selection($input) {
    $read = function ($key, $limit) use ($input) {
        $value = $input[$key] ?? '';
        return is_scalar($value) ? mb_substr(trim((string)$value), 0, $limit) : '';
    };
    $variant = $read('variant', 120);
    $color = $read('color', 80);
    $size = $read('size', 80);
    // Older orders/forms already send the labelled, combined variant field.
    foreach (['color' => 'اللون', 'size' => 'المقاس'] as $key => $label) {
        if ($$key === '' && preg_match('/(?:^|\|)\s*' . $label . '\s*:\s*([^|]+)/u', $variant, $match)) {
            $$key = mb_substr(trim($match[1]), 0, 80);
        }
    }
    if ($variant === '') {
        $parts = [];
        if ($color !== '') $parts[] = 'اللون: ' . $color;
        if ($size !== '') $parts[] = 'المقاس: ' . $size;
        $variant = mb_substr(implode(' | ', $parts), 0, 120);
    }
    return ['variant' => $variant, 'color' => $color, 'size' => $size];
}
function order_variant_label($order) {
    $selection = order_variant_selection($order);
    $parts = [];
    if ($selection['color'] !== '') $parts[] = 'اللون: ' . $selection['color'];
    if ($selection['size'] !== '') $parts[] = 'المقاس: ' . $selection['size'];
    if (!$parts) return $selection['variant'];
    // A model/package may share colour and size with another option. Keep its
    // legacy description visible, without repeating the colour/size tuple.
    $other = [];
    foreach ((preg_split('/\s*(?:\/|\|)\s*/u', $selection['variant']) ?: []) as $part) {
        $part = trim($part);
        if ($part === '' || preg_match('/^(?:اللون|المقاس)\s*:/u', $part)) continue;
        if ($part === $selection['size'] || color_name($part) === $selection['color']) continue;
        $other[] = $part;
    }
    if ($other) $parts[] = 'الخيار: ' . implode(' / ', $other);
    return implode(' | ', $parts);
}
