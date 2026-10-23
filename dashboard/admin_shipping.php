<?php
require_once __DIR__ . '/../config.php';
require_admin_or_die();
require 'layout.php';

// مفتاح الجدول في الفورم: المنصة، ولو المنصة ليها أكتر من جدول (منتجات يدوية لكل دولة) بنضيف الدولة
// عشان رسوم السعودية ما تتلخبطش مع رسوم الإمارات.
function ship_table_key($entry) {
    $plat = (string)($entry['platform'] ?? '');
    $country = function_exists('wf_match_country') ? wf_match_country($entry['country'] ?? '', '') : trim((string)($entry['country'] ?? ''));
    return $country !== '' ? $plat . '@' . $country : $plat;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fee'])) {
    $ok = false;
    if (SB_SERVICE) {
        $ok = true;
        foreach ($_POST['fee'] as $key => $regs) {
            // المفتاح: platform أو platform@country
            [$sb_plat, $sb_country] = array_pad(explode('@', (string)$key, 2), 2, '');
            foreach ($regs as $region => $fee) {
                $filter = 'platform=eq.' . urlencode($sb_plat) . '&region=eq.' . urlencode($region);
                if ($sb_country !== '') $filter .= '&country=eq.' . urlencode($sb_country);
                $r = sb_admin('PATCH', 'shipping', $filter, ['fee' => max(0, (float)$fee)]);
                if ($r === null) { $ok = false; break 2; }
            }
        }
        @unlink(DATA_DIR . '/cache_shipping.json');
    }
    // نسخة محلية دائماً
    $sh = jload('shipping.json', []);
    foreach ($sh as &$p) {
        $key = ship_table_key($p);
        if (!isset($_POST['fee'][$key]) || !is_array($_POST['fee'][$key])) continue;
        foreach ($p['regions'] as &$rg) {
            if (isset($_POST['fee'][$key][$rg['region']])) $rg['fee'] = max(0, (float)$_POST['fee'][$key][$rg['region']]);
        }
        unset($rg);
    }
    unset($p);
    jsave('shipping.json', $sh);
    @unlink(DATA_DIR . '/cache_shipping.json');
    $msg = $ok ? 'تم الحفظ محليًا وعلى Supabase ✅ الموقع محدّث فورًا' : (SB_SERVICE ? 'تعذر الكتابة على Supabase — حُفظ محليًا والموقع محدّث فورًا' : 'حُفظ محليًا والموقع محدّث فورًا — مفيش نسخة Supabase لأن SB_SERVICE مش مضبوط');
}
layout_top('إدارة الشحن ', 'shipping');
if ($msg) echo '<div role="status" data-save-feedback class="card" data-ui="u-9e82dcff d54">' . esc($msg) . '</div>';
// الوضع الفعلي للتحديث المباشر: محلي / مرآة Supabase / قراءة مباشرة من Supabase
$ship_live_mode = (SB_SERVICE !== '') ? ((defined('USE_SUPABASE') && USE_SUPABASE) ? 'supabase' : 'mirror') : 'local';
$ship_mode_txt = [
    'supabase' => 'الرسوم بتتحفظ في <b>data/shipping.json</b> و Supabase، والموقع بيقرأ من Supabase مباشرة — التحديث المباشر فعّال.',
    'mirror'   => 'الرسوم بتتحفظ في <b>data/shipping.json</b> و Supabase (نسخة بتتزامن مع كل حفظ)، والموقع بيقرأ من الملف المحلي فورًا — الأسرع (أقل من 30ms).',
    'local'    => 'الرسوم بتتحفظ في <b>data/shipping.json</b> بس، والموقع بيقرأها فورًا.',
][$ship_live_mode];
$ship_setup_txt = [
    'supabase' => 'التحديث المباشر شغّال — مفيش خطوة إضافية مطلوبة.',
    'mirror'   => 'لو عايز الموقع يقرأ من Supabase مباشرة: ضيف <b>USE_SUPABASE</b> = true جنب <b>SB_SERVICE</b> في ملف secrets.php بإعدادات الخادم.',
    'local'    => 'لإعداد نسخة Supabase اللي بتتزامن مع كل حفظ: ضيف <b>SB_SERVICE</b> (مفتاح service_role من Supabase → Project Settings → API) في ملف secrets.php بإعدادات الخادم.',
][$ship_live_mode];
echo '<div class="card"><p class="mut">' . $ship_mode_txt . '</p><p class="mut">' . $ship_setup_txt . '</p>'
   . '<p class="mut">المنتجات اليدوية: <b>مصر</b> بتستخدم جدول <b>تايم هاند</b> بالجنيه (مفيش جدول منفصل)، وكل دولة خليجية ليها جدول مناطقها بعملة الدولة — الرسوم بتاعتها إنت اللي بتحددها هنا. منتجات <b>مخزون</b> ليها جدول لكل دولة بنفس المناطق، والرسوم مزروعة بسعر الدولة الموحّد (25 ر.س · 25 د.إ · 3 د.أ · 2.5 ر.ع) وتقدر تعدّل أي منطقة — ومفيش أي تحويل عملات في أي مكان.</p><br><form method="post">';
foreach (site_shipping() as $p) {
    $key = ship_table_key($p);
    $country = function_exists('wf_match_country') ? wf_match_country($p['country'] ?? '', '') : trim((string)($p['country'] ?? ''));
    $cur = $country !== '' ? wf_country_currency($country) : 'ج.م';
    $title = $p['name'] ?? ($country !== '' ? 'جدول شحن — ' . $country : (string)($p['platform'] ?? ''));
    // تنبيه: جدول كل رسومه لسه صفر = العميل هيشوف شحن مجاني لحد ما تتملى الأرقام
    $all_zero = true;
    foreach ((array)($p['regions'] ?? []) as $rg) { if ((float)($rg['fee'] ?? 0) > 0) { $all_zero = false; break; } }
    echo '<h3><svg class=wf-ico aria-hidden=true focusable=false><use href=#icon-truck></use></svg> ' . esc($title)
       . ($country !== '' ? ' <span class="mut">(' . esc($country) . ' — الرسوم بـ' . esc($cur) . ')</span>' : '') . '</h3>';
    if ($all_zero) echo '<p class="mut">⚠️ كل الرسوم لسه <b>صفر</b> — العميل هيشوف شحن مجاني للدولة دي لحد ما تحط الأرقام.</p>';
    echo '<table class="data-table rtable" data-ui-table="pending" aria-busy="true"><tr><th>' . esc(wf_ship_region_label($country)) . '</th><th>الرسوم</th><th>العملة</th></tr>';
    foreach ($p['regions'] as $rg) {
        $row_cur = trim((string)($rg['currency'] ?? '')) !== '' && function_exists('wf_ui_currency_label') ? wf_ui_currency_label($rg['currency']) : $cur;
        echo '<tr><td>' . esc($rg['region']) . '</td><td><input name="fee[' . esc($key) . '][' . esc($rg['region']) . ']" type="number" step="0.5" min="0" value="' . esc($rg['fee'] ?? 0) . '" aria-label="رسوم الشحن إلى ' . esc($rg['region']) . ' بـ' . esc($row_cur) . '" data-ui="u-d16c01ab d63 d64 d65 d66"></td><td class="mut">' . esc($row_cur) . '</td></tr>';
    }
    echo '</table><br>';
}
echo '<button class="btn pri"> حفظ الرسوم</button></form></div>';
layout_end();
