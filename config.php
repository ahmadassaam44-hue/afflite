<?php
// ============================================================================
// تحميل الأسرار من ملف منفصل (مستبعد من Git) — مع قيم احتياطية للطوارئ فقط
// ============================================================================
if (file_exists(__DIR__ . '/secrets.php')) require_once __DIR__ . '/secrets.php';
if (!defined('ADMIN_PASS'))    define('ADMIN_PASS', 'CHANGE_ME_NOW_' . bin2hex(random_bytes(6)));
if (!defined('HEVO_API_KEY'))  define('HEVO_API_KEY', '');
if (!defined('SB_SERVICE'))    define('SB_SERVICE', '');
// إرسال الإيميلات عن طريق Supabase Edge Function (send-mail). المفتاح السري في secrets.php بس
if (!defined('MAIL_FN_URL'))    define('MAIL_FN_URL', 'https://hbhuxhphlpgwqynstytw.supabase.co/functions/v1/send-mail');
if (!defined('MAIL_FN_SECRET')) define('MAIL_FN_SECRET', '');
// تسجيل الدخول بجوجل: يتفعل (true) بعد ما تتضبط بيانات جوجل في Supabase (GitHub Secrets)
if (!defined('GOOGLE_LOGIN_ON')) define('GOOGLE_LOGIN_ON', false);

define('BRAND', 'وفرنهالك');
define('SITE_VER', 'v61');
define('DEAL_PCT', 10);
define('DEAL_ENDS', '2026-12-31 23:59:59');
// خصم المنصات: فحصنا كل المصادر الستة — مفيش أي حقل خصم (discount/old_price=0 في كل منتج).
// → الخصم العام (DEAL_PCT) ملغى: مفيش خصم من المنصة مفيش خصم عندنا.
// لو المنصة بعتت خصم مستقبلًا (old_price > price أو discount > 0) هيتبان تلقائيًا في product.php
// من غير ما نفعّل الخصم العام.
function deal_active() { return false; }
function deal_price($sale) { return $sale; }
// خصم حقيقي من بيانات المنصة (مش الوهمي): يرجّع [السعر القديم, النسبة] أو null
function src_discount($p, $plat = '') {
    // الرقمي والسوشيال ميديا: مافيشش خصومات/عمولات متعرضة — السعر سعر المصدر
    $plat = trim((string)$plat) !== '' ? (string)$plat : (string)($p['_plat'] ?? '');
    if (wf_plat_no_commission($plat)) return null;
    $old = (float)($p['old_price'] ?? $p['compare_price'] ?? 0);
    $disc = (float)($p['discount'] ?? 0);
    $cur = is_numeric($p['price'] ?? null) ? (float)$p['price'] : 0;
    if ($old > 0 && $cur > 0 && $old > $cur) {
        return ['old' => $old, 'pct' => round((1 - $cur / $old) * 100)];
    }
    if ($disc > 0 && $cur > 0) {
        return ['old' => round($cur / (1 - min($disc, 90) / 100), 2), 'pct' => min($disc, 90)];
    }
    return null;
}
// ===== سعر البيع في المتجر = سعر المنتج بعد خصم العمولة المتوقعة =====
function ledger_sale($p) {
    return base_price($p, 'ledger');
}
function m5zoon_sale($p) {
    return base_price($p, 'm5zoon');
}
// مخزون: استخراج رقم من نص مثل "55 درهم" أو "1,250.5 ر.س"
function mz_num($v) {
    $s = preg_replace('/[^0-9,.+\-]/u', '', trim((string)$v));
    if ($s === '' || $s === null) return 0;
    $comma = strrpos($s, ','); $dot = strrpos($s, '.');
    if ($comma !== false && $dot !== false) {
        if ($dot > $comma) $s = str_replace(',', '', $s); // 1,234.50
        else $s = str_replace('.', '', str_replace(',', '.', $s)); // 1.234,50
    } elseif ($comma !== false) {
        $parts = explode(',', $s);
        $tail = end($parts);
        if (count($parts) === 2 && strlen($tail) > 0 && strlen($tail) <= 2) $s = str_replace(',', '.', $s);
        elseif (count($parts) > 2 && strlen($tail) > 0 && strlen($tail) <= 2) { array_pop($parts); $s = implode('', $parts) . '.' . $tail; }
        else $s = str_replace(',', '', $s);
    }
    return is_numeric($s) ? (float)$s : 0;
}
// مخزون: وحدة العملة زي ما هي مكتوبة في المصدر — Supabase بيرجع currency = NULL خلافاً لـ JSON
function mz_src_currency($p) {
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    foreach ([$ex['cost'] ?? '', $ex['suggested'] ?? '', $ex['profit'] ?? '', $p['price_text'] ?? ''] as $v) {
        $v = trim((string)$v);
        if (preg_match('/^[\d.,]+\s*(.+)$/u', $v, $m) && trim($m[1]) !== '') {
            $u = trim($m[1]);
            if (mb_strlen($u) <= 10) return $u;
        }
    }
    return '';
}
// مخزون: المربعات الثلاثة زي المصدر — تكلفة / بيع مقترح / ربح (بالعملة المحلية)
function mz_price_boxes($p, $cur_override = '') {
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $cur = trim((string)$cur_override);
    if ($cur === '' || $cur === 'ج.م' || $cur === 'د.إ' || $cur === 'ر.س' || $cur === 'د.أ' || $cur === 'ر.ع') {
        $src = mz_src_currency($p); // المصدر أصدق من خريطة الدولة (مثال: "77 درهم")
        if ($src !== '') $cur = $src;
    }
    if ($cur === '') $cur = trim((string)($p['currency'] ?? ''));
    if (mb_strlen($cur) > 10) $cur = '';
    $cost = mz_num($ex['cost'] ?? '');
    $sugg = mz_num($ex['suggested'] ?? '');
    if ($sugg <= 0) $sugg = mz_num($p['price'] ?? '');
    $profit = mz_num($ex['profit'] ?? '');
    if ($profit <= 0) $profit = mz_num($p['commission'] ?? '');
    if ($profit <= 0 && $sugg > $cost) $profit = round($sugg - $cost, 2);
    if ($cost > $sugg && $sugg > 0) { $t = $cost; $cost = $sugg; $sugg = $t; } // حماية من مصدر مقلوب
    $fmt = function ($v) use ($cur) {
        $s = (abs($v - round($v)) > 0.001) ? number_format((float)$v, 2) : number_format((float)$v);
        return $cur !== '' ? $s . ' ' . $cur : $s;
    };
    $out = [];
    if ($cost > 0) $out[] = ['v' => $fmt($cost), 'l' => 'سعر التكلفة', 'c' => '#1e293b', 'bg' => '#f8fafc', 'bd' => '#e2e8f0'];
    if ($sugg > 0) $out[] = ['v' => $fmt($sugg), 'l' => 'سعر بيع مقترح', 'c' => '#dc2626', 'bg' => '#fef2f2', 'bd' => '#fecaca'];
    if ($profit > 0) $out[] = ['v' => $fmt($profit), 'l' => 'الربح', 'c' => '#16a34a', 'bg' => '#f0fdf4', 'bd' => '#bbf7d0'];
    return $out;
}
// مخزون: كود المنتج (#5191)
function mz_prod_code($p) {
    $oid = trim((string)($p['orig_id'] ?? ''));
    if ($oid === '' && preg_match('/(\d+)/', (string)($p['id'] ?? ''), $m)) $oid = $m[1];
    return $oid;
}
// مخزون: نص SKU نظيف (بدون بادئة "SKU:")
function mz_sku_text($p) {
    return trim(preg_replace('/^\s*sku\s*[:：]\s*/iu', '', trim((string)($p['sku'] ?? ''))));
}
// مخزون: الوصف المسطح ملوث بنصوص الواجهة (اطلب الآن / ملفات المنتج / جدول مسطح) — نقطع الذيل
function mz_clean_prose($s) {
    $s = trim(preg_replace('/[ \t]+/u', ' ', (string)$s));
    $s = preg_replace('/\s*(?:اطلب الآن|أطلب الآن|اضف الى العربة|أضف إلى العربة|ملفات المنتج|وصف سريع|المميزات|كلمات إعلانية)\s*.*$/u', '', $s);
    $s = trim(preg_replace('/\s+\d{1,3}(\s+\d{1,3}){3,}\s*$/u', '', $s)); // جدول مسطح من الأرقام
    // الكود وSKU ومربعات الأسعار بتظهر تحت العنوان — تتشال من النص
    $s = preg_replace('/كود\s*المنتج\s*[:：]?\s*#?\s*\d+/u', ' ', $s);
    $s = preg_replace('/\bSKU\s*[:：]?\s*[A-Za-z0-9_\-]+/iu', ' ', $s);
    $s = preg_replace('/[\d.,]+\s*(?:درهم|د\.إ|ر\.س|د\.أ|ر\.ع|ج\.م|جنيه|ريال|قرش)\s*(?:سعر\s*(?:التكلفة|بيع\s*مقترح|البيع)?|الربح|العمولة|العموله)?/u', ' ', $s);
    $s = preg_replace('/\s*(?:سعر\s*التكلفة|سعر\s*بيع\s*مقترح|سعر\s*البيع|الربح|العمولة|العموله)\s*[:：]?\s*[\d.,]*\s*/u', ' ', $s);
    $s = preg_replace('/\s*[|•·]+\s*/u', ' ', $s);
    $s = preg_replace('/\s{2,}/u', ' ', $s);
    // شيل بادئات/نهايات التل والرموز الفاضية (النص المسطح في المصدر بيبدأ بـ …)
    $s = preg_replace('/^[\s\.\x{2026}\x{2022}\-–—•·,:،؛]+/u', '', $s);
    $s = preg_replace('/[\s\.\x{2026}\-–—•·,:،؛]+$/u', '', $s);
    return trim($s);
}
// تنظيف سطر مميزات من علامات الاقتباس الزائدة
function mz_strip_quotes($s) {
    $s = trim(preg_replace('/[ \t]+/u', ' ', (string)$s));
    $s = preg_replace('/^[\x{201C}\x{201D}"\x{2018}\x{2019}\x{2022}\-*\s]+/u', '', $s);
    $s = preg_replace('/[\x{201C}\x{201D}"\x{2018}\x{2019}]+$/u', '', $s);
    return trim($s);
}
// تصنيف عنوان قسم (مميزات / كلمات إعلانية / وصف تسويقي / وصف سريع)
function mz_kind($t) {
    $n = function_exists('vd_norm') ? vd_norm(strip_tags((string)$t)) : mb_strtolower(trim(strip_tags((string)$t)));
    if ($n === '') return '';
    if (preg_match('/مميزات|مزايا|الخصائص/u', $n)) return 'feat';
    if (preg_match('/كلمات?\s*(اعلاني|اعلانات)|كلمات\s*دعائية/u', $n)) return 'ad';
    if (preg_match('/وصف\s*تسويقي/u', $n)) return 'mkt';
    if (preg_match('/وصف\s*سريع|نبذه|نبذه تعريفية/u', $n)) return 'desc';
    return 'other';
}
// تنظيف نص من وسوم + entities + تطبيع مسافات
function mz_txt($h) {
    $s = strip_tags((string)$h);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace("\xc2\xa0", ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}
// مخزون: تفكيك description_html لأقسام مرتبة (Regex Walk — بدون DOM عشان الاستضافة المشتركة)
function mz_desc_sections($html) {
    $html = preg_replace('/<!--.*?-->/s', '', trim((string)$html));
    if ($html === '' || mz_txt($html) === '') return [];
    $out = []; $mode = ''; $head = '';
    if (!preg_match_all('/<(h[1-6]|ul|ol|p|div|blockquote|table|tr)\b[^>]*>(.*?)<\/\1>/is', $html, $ms, PREG_SET_ORDER)) return [];
    foreach ($ms as $m) {
        $tag = strtolower($m[1]); $inner = $m[2];
        if ($tag === 'ul' || $tag === 'ol') {
            if (!preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $inner, $ls)) continue;
            foreach ($ls[1] as $li) {
                $t = mz_strip_quotes(mz_txt($li));
                if ($t === '') continue;
                $out[] = ['t' => ($mode === '' ? 'para' : $mode), 'h' => $head, 'b' => $t];
            }
            continue;
        }
        if ($tag === 'table' || $tag === 'tr') {
            $t = mz_txt($inner);
            if ($t !== '') $out[] = ['t' => 'para', 'h' => $head, 'b' => $t];
            continue;
        }
        $t = mz_txt($inner);
        if ($t === '') continue;
        $tChk = trim(preg_replace('/[\s:.,،…;؛]+$/u', '', (string)$t));
        $kind = mz_kind($t);
        $knownHead = ($kind !== 'other' && $kind !== '' && mb_strlen($t) <= 60);
        $isHead = in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
            || $knownHead
            || (preg_match('/^\s*<(strong|b)\b/i', $inner) && mb_strlen($tChk) <= 60 && !preg_match('/[.،,]$/u', $tChk));
        if ($isHead) {
            if ($kind === 'feat') { $mode = 'feat'; $head = 'المميزات'; continue; }
            if ($kind === 'ad') { $mode = 'ad'; $head = 'كلمات إعلانية'; continue; }
            if ($kind === 'mkt') { $mode = 'mkt'; $head = 'الوصف التسويقي'; continue; }
            if ($kind === 'desc') { $mode = 'desc'; $head = ''; continue; }
            // داخل قسم مميزات/كلمات: العناوين الطويلة تكون بنودًا من القسم مش عنوان جديد (مصدر 4134)
            if (($mode === 'feat' || $mode === 'ad') && (mb_strlen($t) > 22 || $tag === 'h3' || $tag === 'h4')) {
                $out[] = ['t' => $mode, 'h' => $head, 'b' => $t];
                continue;
            }
            $mode = 'para'; $head = $t;
            $out[] = ['t' => 'head', 'h' => '', 'b' => $t];
            continue;
        }
        $out[] = ['t' => ($mode === '' ? 'para' : $mode), 'h' => $head, 'b' => $t];
    }
    return $out;
}
// مخزون: كل بيانات التفاصيل جاهزة للعرض — نص / عناوين / مميزات / كلمات إعلانية
function mz_desc_data($p) {
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $secs = mz_desc_sections($ex['description_html'] ?? '');
    $prose = []; $heads = []; $feat = []; $ad = [];
    foreach ($secs as $s) {
        $b = mz_strip_quotes($s['b']);
        if ($b === '') continue;
        if ($s['t'] === 'head') { if (!in_array($b, $heads, true)) $heads[] = $b; continue; }
        if ($s['t'] === 'feat') { if (!in_array($b, $feat, true)) $feat[] = $b; continue; }
        if ($s['t'] === 'ad') { if (!in_array($b, $ad, true)) $ad[] = $b; continue; }
        $prose[] = ['h' => ($s['t'] === 'mkt') ? 'الوصف التسويقي' : $s['h'], 'b' => $b];
    }
    $flat = trim((string)(($ex['quick_desc'] ?? '') !== '' ? $ex['quick_desc'] : ($p['desc'] ?? '')));
    $flat = mz_clean_prose($flat);
    if (!$feat) {
        // المميزات غالبًا جُمل بين علامات اقتباس داخل النص المسطح (زي مثال الغلاية)
        $re = '/[\x{201C}"]\s*([^"\x{201D}]{8,}?)\s*[\x{201D}"]/u';
        if (preg_match_all($re, $flat, $mm)) {
            foreach ($mm[1] as $q) { $q = mz_strip_quotes($q); if (mb_strlen($q) >= 8 && !in_array($q, $feat, true)) $feat[] = $q; }
        }
    }
    if (!$feat && trim((string)($ex['features'] ?? '')) !== '') {
        foreach (preg_split('/\r?\n|(?<=\.)\s+(?=\p{Lu}|\p{Arabic})/u', (string)$ex['features']) as $fl) {
            $fl = mz_strip_quotes($fl);
            if (mb_strlen($fl) >= 8 && !in_array($fl, $feat, true)) $feat[] = $fl;
        }
    }
    // تنظيف النص: شيل القصير جدًا واللي مكرر مع المميزات/العناوين
    $cleanProse = [];
    foreach ($prose as $pr) {
        $b = trim($pr['b']);
        $b = preg_replace('/^[\s\.\x{2026}\x{2022}\-–—•·]+/u', '', $b);
        $b = trim($b);
        if (mb_strlen($b) < 8) continue;
        if (in_array($b, $feat, true) || in_array($b, $heads, true)) continue;
        if (!preg_match('/\p{Arabic}|\p{Latin}/u', $b)) continue; // فواصل/نقاط فقط
        $cleanProse[] = ['h' => $pr['h'], 'b' => $b];
    }
    $prose = $cleanProse;
    if (!$prose) {
        $t = $flat;
        $t = preg_replace('/[\x{201C}"][^"\x{201D}]{8,}[\x{201D}"]?/u', ' ', $t);
        $t = preg_replace('/\s*(?:كلمات?\s*إ?عل?انية|المميزات|مميزات|وصف\s*سريع\s*[….]*)\s*[:：]?\s*$/u', '', $t);
        $t = trim(preg_replace('/\s{2,}/u', ' ', $t));
        if ($t !== '') $prose[] = ['h' => '', 'b' => $t];
    }
    $adProse = '';
    if (!$ad && trim((string)($ex['ad_words'] ?? '')) !== '') {
        $raw = trim(preg_replace('/\s+/u', ' ', (string)$ex['ad_words']));
        $parts = preg_split('/[\r\n,،|؛;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_values(array_filter(array_map('trim', $parts), function ($x) { return $x !== ''; }));
        if (count($parts) >= 2) {
            foreach ($parts as $x) if (mb_strlen($x) <= 60 && !in_array($x, $ad, true)) $ad[] = $x;
        } elseif (mb_strlen($raw) > 70) {
            $adProse = $raw; // كتلة مسطحة بدون فواصل — ما تنقسمش لشيبات غلط
        } else {
            $ad[] = $raw;
        }
    }
    $ad = array_values(array_slice($ad, 0, 24));
    return ['prose' => $prose, 'heads' => $heads, 'feat' => array_slice($feat, 0, 40), 'ad' => $ad, 'ad_prose' => $adProse];
}

// M5zoon: استخراج Base Title + Color من اسم المنتج وتطبيع الألوان (لربط الأشقاء)
function mz_get_variant_info($name) {
    $clean = preg_replace('/\s+/u', ' ', trim($name));
    $base = null; $color = null;

    // Pattern 1: "لون ..." or "باللون ..."
    if (preg_match('/^(.*?)\s+(?:لون|باللون)\s+(.+)$/u', $clean, $m)) {
        $base  = $m[1];
        $color = preg_replace('/\s*\+\s*البوكس.*$/u', '', $m[2]);
        $color = preg_replace('/\s*-\s*كوبي.*$/u', '', $color);
        $color = trim(preg_replace('/[\.\s]+$/u', '', $color));
    }
    // Pattern 2: Watch "... مينا ..."
    if ($base === null && preg_match('/^(.*?)\s+((?:سيلفر|فضي|ذهبي|جولد|اسود|أسود|ابيض|أبيض|كحلي|كافيه|هافاني)?\s*(?:في|و)?\s*(?:سيلفر|فضي|ذهبي|جولد)?\s*مينا\s+\S+(?:\s+\S+)?(?:\s*\d+mm)?)(?:\s*\+.*|\s*-.*)?$/u', $clean, $m) && mb_strlen($m[1]) > 3) {
        $base  = $m[1];
        $color = preg_replace('/\s*\+\s*البوكس.*$/u', '', $m[2]);
        $color = trim(preg_replace('/[\.\s]+$/u', '', $color));
    }
    // Pattern 3: "مينا <لون> ..."
    if ($base === null && preg_match('/^مينا\s+(\S+)\s+(.+)$/u', $clean, $m)) {
        $color = 'مينا ' . trim($m[1]);
        $base  = trim($m[2]);
    }
    // Pattern 4: color*color (e.g. أسود*أصفر)
    if ($base === null && preg_match('/^(.*?)\s+(\S+[*]\S+)$/u', $clean, $m) && mb_strlen($m[1]) > 3) {
        $base  = trim($m[1]);
        $color = trim($m[2]);
    }

    if ($base === null) return null;

    $base  = preg_replace('/\s+/u', ' ', trim($base));
    $color = mz_normalize_color($color);
    return ['base' => $base, 'color' => $color];
}

// تطبيع أسماء الألوان (جولد→ذهبي، سيلفر→فضي، إلخ)
function mz_normalize_color($col) {
    $c = str_replace(['*', '/'], ' + ', $col);
    $c = trim($c);
    $map = [
        'جولد' => 'ذهبي', 'سيلفر' => 'فضي', 'بلاك' => 'أسود', 'وايت' => 'أبيض',
        'اسود' => 'أسود', 'ابيض' => 'أبيض', 'احمر' => 'أحمر', 'ازرق' => 'أزرق',
        'اخضر' => 'أخضر', 'اصفر' => 'أصفر', 'رمادى' => 'رمادي', 'رصاصى' => 'رصاصي',
        'دهبي' => 'ذهبي', 'بنى' => 'بني',
    ];
    $parts = preg_split('/(\s+\+\s+|\s+)/u', $c, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $tok) {
        if (isset($map[$tok])) $parts[$i] = $map[$tok];
    }
    return trim(implode('', $parts));
}

// M5zoon: بناء خريطة الأشقاء من مصفوفة المنتجات (تُستخدم مرة واحدة وتُخزّن static)
function mz_build_siblings_map($products) {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach ($products as $p) {
        $info = mz_get_variant_info($p['name'] ?? '');
        if (!$info) continue;
        $country = $p['country'] ?? 'الإمارات';
        $key = $country . '::' . $info['base'];
        if (!isset($map[$key])) $map[$key] = [];
        $map[$key][] = [
            'id'    => $p['id'],
            'color' => $info['color'],
            'price' => $p['price'] ?? null,
            'img'   => !empty($p['images']) ? $p['images'][0] : '',
        ];
    }
    foreach ($map as $k => $v) {
        if (count($v) <= 1) unset($map[$k]);
    }
    return $map;
}

// M5zoon: إرجاع الأشقاء لمنتج معين (أو null لو مفيش)
function mz_get_siblings($product, $all_products) {
    $info = mz_get_variant_info($product['name'] ?? '');
    if (!$info) return null;
    $country = $product['country'] ?? 'الإمارات';
    $key = $country . '::' . $info['base'];
    $map = mz_build_siblings_map($all_products);
    if (empty($map[$key])) return null;
    $siblings = $map[$key];
    foreach ($siblings as &$s) {
        $s['current'] = ($s['id'] === ($product['id'] ?? ''));
    }
    return $siblings;
}
// السعر المعروض للعميل = سعر المنتج بدون العمولة (السعر الأساسي الصافي)
function display_price($p, $plat) {
    return store_price($p, $plat);
}
define('PRICE_MARGIN_PCT', 0);
// رابط الإحالة: يُعرض للمسوقين 15% ويُحسب فعلياً على 4%
define('REF_TEAM_RATE', 0.04);
define('REF_TEAM_LABEL', '15%');

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
// الحالات التي تُعتبر "نهائية إيجابية" (تُحرَّر فيها العمولة)
function status_releases_commission($k) { return in_array(status_key($k), ['delivered'], true); }
// الحالات التي تُلغي العمولة
function status_reverses_commission($k) { return in_array(status_key($k), ['cancelled', 'returned'], true); }
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

// ============================================================================
//  سجل حركات الرصيد (Ledger) لكل مسوق
// ============================================================================
function active_sub() {
    $v = $_SESSION['aff_sub'] ?? ($_COOKIE['aff_sub'] ?? '');
    return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$v), 0, 40);
}
// إشعار تليجرام للأدمن (اختياري): ضع TG_BOT_TOKEN و TG_CHAT_ID في secrets.php
function tg_notify($text) {
    if (!defined('TG_BOT_TOKEN') || !defined('TG_CHAT_ID') || TG_BOT_TOKEN === '' || TG_CHAT_ID === '') return false;
    $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage';
    $body = http_build_query(['chat_id' => TG_CHAT_ID, 'text' => mb_substr($text, 0, 3500), 'disable_web_page_preview' => 1]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 4]);
        @curl_exec($ch); curl_close($ch); return true;
    }
    @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body, 'timeout' => 4]]));
    return true;
}

// ============================================================================
//  إعدادات الموقع القابلة للتعديل من لوحة الأدمن — data/settings.json
// ============================================================================
function site_settings() {
    static $st = null;
    if ($st === null) { $st = jload('settings.json', []); if (!is_array($st)) $st = []; }
    return $st;
}
function site_setting($k, $def = '') { $st = site_settings(); return isset($st[$k]) && $st[$k] !== '' ? $st[$k] : $def; }
// منتجات الكاروسيل الرئيسي: الأدمن بيختارها من إعداد hero_products (لحد 5 منتجات).
// بترجع نفس شكل الشرائح (image / link / title) + السعر، فالعرض بيشتغل بنفس الكود.
function hero_product_slides() {
    $raw = (string)site_setting('hero_products', '');
    if (trim($raw) === '') return [];
    $ids = preg_split('/[\s,،]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach (array_slice(array_values(array_unique($ids)), 0, 5) as $id) {
        $p = find_product_by_id($id);
        if (!$p) continue;
        $plat = $p['_plat'] ?? resolve_plat($p['id'] ?? '');
        $img = product_first_image($p, $plat) ?: wf_product_placeholder($plat);
        $price = (float)display_price($p, $plat);
        // العملة من المنتج نفسه (منتج يدوي سعودي = ر.س، إماراتي = د.إ …) — عرض فقط ومن غير أي تحويل
        $cur = trim((string)($p['currency'] ?? ''));
        if ($cur === '' && $plat === 'm5zoon' && function_exists('mz_src_currency')) $cur = mz_src_currency($p);
        if ($cur === '') $cur = wf_country_currency($p['country'] ?? 'مصر');
        if (function_exists('wf_ui_currency_label')) $cur = wf_ui_currency_label($cur);
        $out[] = [
            'link'  => 'dashboard/product.php?id=' . urlencode((string)$p['id']),
            'image' => $img,
            'title' => (string)($p['name'] ?? ''),
            'price' => $price > 0 ? number_format($price, 0) . ' ' . $cur : '',
        ];
    }
    return $out;
}
function site_settings_schema() {
    return [
        'الدفع الإلكتروني (السوشيال والرقمي)' => [
            'pay_instapay'  => ['عنوان انستا باي (مثال: name@instapay)', 'text'],
            'pay_vodafone'  => ['رقم فودافون كاش', 'text'],
            'pay_orange'    => ['رقم أورانج كاش', 'text'],
            'pay_etisalat'  => ['رقم اتصالات كاش', 'text'],
            'pay_we'        => ['رقم وي باي', 'text'],
            'pay_bank'      => ['حساب بنكي / IBAN (اختياري)', 'text'],
            'pay_name'      => ['اسم صاحب الحساب (يظهر للعميل)', 'text'],
            'pay_note'      => ['ملاحظة تظهر في صفحة الدفع', 'textarea'],
        ],
        'عمولات المنصات' => [
            'commission_safqa_pct'  => ['نسبة عمولة صفقة % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
            'commission_angazny_pct'=> ['نسبة عمولة أنجزني % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
            'commission_m5zoon_pct' => ['نسبة عمولة مخزون % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
            'commission_shopmart_pct' => ['نسبة عمولة Shopmart % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
            // الرقمي والسوشيال ميديا اتشالوا من نسب العمولة: سعرهم = سعر المصدر (شوف DIGITAL_USD_EGP)
            'commission_ledger_pct' => ['نسبة العمولة الاحتياطية % (اتركها فارغة لاستخدام العمولة الثابتة لكل منتج)', 'text'],
        ],
        'الرقمي والسوشيال ميديا (بلا عمولة)' => [
            'usd_egp'            => ['سعر الدولار اللي بيتحسب به المنتج الرقمي (ج.م لكل دولار) — تركه فاضي = 56', 'text'],
            'social_price_addon' => ['إضافة الخدمات الصغيرة بس: أي مبلغ سوشيال ميديا أقل من المبلغ ده (ج.م) بيتضاف عليه، والأكبر منه بيفضل سعر المصدر — تركه فاضي = 0.10', 'text'],
        ],
        'التواصل' => [
            'whatsapp'      => ['رقم واتساب الدعم (بصيغة دولية 2010xxxxxxx)', 'text'],
            'wa_cloud_token'=> ['WhatsApp Cloud API — Access Token (اختياري)', 'password'],
            'wa_phone_id'   => ['WhatsApp Cloud API — Phone Number ID (اختياري)', 'text'],
            'wa_admin_to'   => ['رقم الأدمن لاستقبال إشعارات واتساب', 'text'],
        ],
        'الصفحة الرئيسية' => [
            'hero_products' => ['منتجات الكاروسيل الرئيسي (من 1 لـ 5): أرقام المنتجات مفصولة بفاصلة — انسخها من رابط صفحة المنتج بعد id=', 'text'],
        ],
        'التحليلات والبكسل' => [
            'ga4_id'        => ['Google Analytics 4 — Measurement ID (G-XXXXXXX)', 'text'],
            'meta_pixel'    => ['Meta (Facebook) Pixel ID (أرقام فقط)', 'text'],
            'tiktok_pixel'  => ['TikTok Pixel ID (CXXXXXXX)', 'text'],
            'gtm_id'        => ['Google Tag Manager (GTM-XXXXX) — اختياري', 'text'],
        ],
    ];
}
// وسائل الدفع المفعّلة (اللي الأدمن حط لها بيانات)
function pay_channels() {
    $out = [];
    $map = ['pay_instapay' => ['🏦', 'انستا باي InstaPay'], 'pay_vodafone' => ['📱', 'فودافون كاش'], 'pay_orange' => ['🟠', 'أورانج كاش'],
            'pay_etisalat' => ['🟢', 'اتصالات كاش'], 'pay_we' => ['🔵', 'وي باي'], 'pay_bank' => ['💳', 'تحويل بنكي']];
    foreach ($map as $k => $m) { $v = trim((string)site_setting($k)); if ($v !== '') $out[$k] = ['icon' => $m[0], 'label' => $m[1], 'account' => $v]; }
    return $out;
}
// أكواد التتبع (GA4 / Meta / TikTok / GTM) — تُطبع في <head>
function tracking_pixels_html() {
    $h = '';
    if ($g = trim((string)site_setting('gtm_id'))) $h .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc($g) . "');</script>";
    if ($ga = trim((string)site_setting('ga4_id'))) $h .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc($ga) . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","' . esc($ga) . '");</script>';
    if ($fb = preg_replace('/\D/', '', (string)site_setting('meta_pixel'))) $h .= "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . $fb . "');fbq('track','PageView');</script><noscript><img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://www.facebook.com/tr?id=" . $fb . "&ev=PageView&noscript=1\"/></noscript>";
    if ($tt = trim((string)site_setting('tiktok_pixel'))) $h .= "<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement('script');o.type='text/javascript',o.async=!0,o.src=i+'?sdkid='+e+'&lib='+t;var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load('" . esc($tt) . "');ttq.page();}(window,document,'ttq');</script>";
    return $h;
}
// حدث تحويل (شراء/سلة/ليد) لكل البكسلات دفعة واحدة — يُطبع كـ <script>
function tracking_event_js($event, $value = 0, $currency = 'EGP', $extra = []) {
    $v = round((float)$value, 2); $x = json_encode($extra, JSON_UNESCAPED_UNICODE);
    $map = ['purchase' => ['Purchase', 'purchase', 'CompletePayment'], 'add_to_cart' => ['AddToCart', 'add_to_cart', 'AddToCart'], 'lead' => ['Lead', 'generate_lead', 'SubmitForm'], 'view' => ['ViewContent', 'view_item', 'ViewContent'], 'checkout' => ['InitiateCheckout', 'begin_checkout', 'InitiateCheckout']];
    if (!isset($map[$event])) return '';
    list($fb, $ga, $tt) = $map[$event];
    return "<script>try{if(window.fbq)fbq('track','$fb',Object.assign({value:$v,currency:'$currency'},$x));if(window.gtag)gtag('event','$ga',Object.assign({value:$v,currency:'$currency'},$x));if(window.ttq)ttq.track('$tt',Object.assign({value:$v,currency:'$currency'},$x));}catch(e){}</script>";
}
// إشعار واتساب عبر Meta Cloud API (لو مفعّل من الإعدادات)
function wa_notify($to, $text) {
    $tok = trim((string)site_setting('wa_cloud_token')); $pid = trim((string)site_setting('wa_phone_id'));
    $to = preg_replace('/\D/', '', (string)$to);
    if ($tok === '' || $pid === '' || $to === '' || !function_exists('curl_init')) return false;
    if (strpos($to, '0') === 0) $to = '2' . $to;      // 010... → 2010...
    $ch = curl_init('https://graph.facebook.com/v19.0/' . $pid . '/messages');
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => mb_substr($text, 0, 1000)]], JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch); curl_close($ch);
    return $r !== false;
}
function wa_link($phone, $text = '') {
    $p = preg_replace('/\D/', '', (string)$phone); if (strpos($p, '0') === 0) $p = '2' . $p;
    return 'https://wa.me/' . $p . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

function balance_ledger_add($email, $type, $amount, $reason = '', $meta = []) {
    if ($amount == 0) return;
    jupdate('balance_ledger.json', function (&$lg) use ($email, $type, $amount, $reason, $meta) {
        if (!is_array($lg)) $lg = [];
        $lg[] = array_merge([
            'email'   => $email,
            'type'    => $type,          // commission | release | reversal | payout | payout_refund | adjust | referral
            'amount'  => round((float)$amount, 2),
            'reason'  => mb_substr((string)$reason, 0, 250),
            'by'      => admin_actor(),
            'at'      => date('c'),
        ], is_array($meta) ? $meta : []);
        if (count($lg) > 20000) $lg = array_slice($lg, -10000);
        return true;
    }, []);
}
function balance_ledger_for($email, $limit = 200) {
    $all = jload('balance_ledger.json', []);
    if (!is_array($all)) return [];
    $rows = array_values(array_filter($all, function ($r) use ($email) {
        return strcasecmp((string)($r['email'] ?? ''), (string)$email) === 0;
    }));
    return array_slice(array_reverse($rows), 0, $limit);
}

// ============================================================================
//  دورة حياة العمولة: pending → confirmed → paid
// ============================================================================
function commission_credit_pending($mk_email, $amount, $order_idx, $reason = '') {
    $amount = round((float)$amount, 2);
    if ($amount <= 0 || $mk_email === '') return false;
    $mk = find_user($mk_email);
    if (!$mk) return false;
    update_user($mk_email, function (&$u) use ($amount) {
        $u['pending_balance'] = round((float)($u['pending_balance'] ?? 0) + $amount, 2);
        // ❌ لا تُضاف للرصيد المتاح هنا — تُنقل عند التسليم فقط
    });
    balance_ledger_add($mk_email, 'commission', $amount, $reason ?: 'عمولة معلقة',
                       ['order_idx' => $order_idx, 'state' => 'pending']);
    return true;
}
function commission_release($mk_email, $amount, $order_idx, $reason = 'تأكيد تسليم الأوردر') {
    $amount = round((float)$amount, 2);
    if ($amount <= 0 || $mk_email === '') return false;
    $mk = find_user($mk_email);
    if (!$mk) return false;
    $move = min($amount, (float)($mk['pending_balance'] ?? 0));
    if ($move <= 0) return false;
    update_user($mk_email, function (&$u) use ($move) {
        $u['pending_balance'] = round(max(0, (float)($u['pending_balance'] ?? 0) - $move), 2);
        $u['balance']         = round((float)($u['balance'] ?? 0) + $move, 2);
    });
    balance_ledger_add($mk_email, 'release', $move, $reason, ['order_idx' => $order_idx, 'state' => 'confirmed']);
    notify_user($mk_email, '✅ تم تأكيد عمولة ' . number_format($move, 2) . ' ج.م وأصبحت قابلة للسحب', 'dashboard/balance.php');
    return true;
}
function commission_reverse($mk_email, $amount, $order_idx, $reason = 'إلغاء/إرجاع الأوردر') {
    $amount = round((float)$amount, 2);
    if ($amount <= 0 || $mk_email === '') return false;
    $mk = find_user($mk_email);
    if (!$mk) return false;
    // خصم من المعلق أولاً، ثم من المتاح
    $from_pending = min($amount, (float)($mk['pending_balance'] ?? 0));
    $from_balance = round($amount - $from_pending, 2);
    update_user($mk_email, function (&$u) use ($from_pending, $from_balance) {
        $u['pending_balance'] = round(max(0, (float)($u['pending_balance'] ?? 0) - $from_pending), 2);
        $u['balance']         = round(max(0, (float)($u['balance'] ?? 0) - $from_balance), 2);
    });
    balance_ledger_add($mk_email, 'reversal', -$amount, $reason, ['order_idx' => $order_idx, 'state' => 'reversed']);
    notify_user($mk_email, '↩️ تم سحب عمولة ' . number_format($amount, 2) . ' ج.م (' . $reason . ')', 'dashboard/balance.php');
    return true;
}
// عمولة الفريق (إحالة) المرتبطة بأوردر: بنقرأ السجل ونجمع لكل مسوق (من غير ما نكرر أي عملية):
//   credited = اللي اتسجل للفريق، undone = اللي اتعكس، released = اللي اتأكد قبل كده
function team_ledger_for_order($order_idx) {
    $idx = (string)(int)$order_idx;
    $rows = [];
    foreach ((array)jload('balance_ledger.json', [], false) as $r) {
        if (!is_array($r) || !isset($r['order_idx']) || (string)(int)$r['order_idx'] !== $idx) continue;
        $type = (string)($r['type'] ?? '');
        $rs   = (string)($r['reason'] ?? '');
        $email = (string)($r['email'] ?? '');
        if ($email === '') continue;
        $key = strtolower($email);
        if (!isset($rows[$key])) $rows[$key] = ['email' => $email, 'credited' => 0.0, 'undone' => 0.0, 'released' => 0.0];
        $amt = (float)($r['amount'] ?? 0);
        if ($type === 'commission' && $rs === 'عمولة فريق (إحالة)') $rows[$key]['credited'] += $amt;
        elseif ($type === 'reversal' && strpos($rs, 'إلغاء عمولة فريق (إحالة)') === 0) $rows[$key]['undone'] += -$amt; // العكس مسجل بالسالب
        elseif ($type === 'release' && strpos($rs, 'تأكيد عمولة فريق (إحالة)') === 0) $rows[$key]['released'] += $amt;
    }
    return $rows;
}
// تأكيد عمولة الفريق عند تسليم الأوردر (زي عمولة المسوق): من المعلق للمتاح. الاستدعاء المكرر ماينقلش مرتين.
function team_commission_release_for_order($order_idx, $reason = 'تأكيد تسليم الأوردر') {
    if ($order_idx === null || $order_idx === '') return 0.0;
    $total = 0.0;
    foreach (team_ledger_for_order($order_idx) as $row) {
        // اللي لسه مش متأكد ولسه مش متعكس
        $rem = round($row['credited'] - $row['undone'] - $row['released'], 2);
        if ($rem <= 0) continue;
        if (commission_release($row['email'], $rem, $order_idx, 'تأكيد عمولة فريق (إحالة) — ' . $reason)) $total += $rem;
    }
    return round($total, 2);
}
// عكس عمولة الفريق المرتبطة بأوردر (إلغاء/إرجاع): بيعكس اللي اتسجل ولسه مش متعكس، سواء كان معلق أو اتأكد.
function team_commission_reverse_for_order($order_idx, $reason = 'إلغاء/إرجاع الأوردر') {
    if ($order_idx === null || $order_idx === '') return 0.0;
    $total = 0.0;
    foreach (team_ledger_for_order($order_idx) as $row) {
        $amt = round($row['credited'] - $row['undone'], 2);
        if ($amt <= 0) continue;
        if (commission_reverse($row['email'], $amt, $order_idx, 'إلغاء عمولة فريق (إحالة) — ' . $reason)) $total += $amt;
    }
    return round($total, 2);
}
$MARGINS = ['safqa' => 0, 'vendoor' => 0, 'timehand' => 0, 'm5zoon' => 0, 'angazny' => 0, 'shopmart' => 0, 'g2g' => 0, 'ledger' => 0, 'social' => 0, 'cezma' => 0];
// أسماء المنصات للعرض

function social_plat($name) {
    $n = trim((string)$name);
    static $map = [
        'انستجرام' => ['انستجرام', 'instagram'],
        'فيسبوك' => ['فيسبوك', 'فيس بوك', 'facebook'],
        'تيك توك' => ['تيك توك', 'tiktok', 'تيكتوك'],
        'يوتيوب' => ['يوتيوب', 'يوتوب', 'youtube'],
        'تليجرام' => ['تليجرام', 'تيليجرام', 'telegram'],
        'سناب شات' => ['سناب', 'snapchat', 'snap'],
        'كواي' => ['كواي', 'kwai'],
        'كيك' => ['كيك', 'kick'],
        'سبوتيفاي' => ['سبوتيفاي', 'spotify'],
        'ساوند كلاود' => ['ساوند', 'soundcloud', 'sound'],
        'تويتر / إكس' => ['تويتر', 'twitter', 'إكس'],
        'روبلوكس' => ['روبلوكس', 'roblox'],
        'VK' => ['vk', 'فكونتاكتي'],
        'جوجل' => ['جوجل', 'google', 'خرائط'],
    ];
    foreach ($map as $ar => $keys) {
        foreach ($keys as $k) {
            if (mb_stripos($n, $k) !== false) return $ar;
        }
    }
    return 'أخرى';
}
$PLATFORM_NAMES = ['manual' => 'منتجات يدوية', 'g2g' => '🎮 رقمي', 'social' => '📣 سوشيال ميديا', 'm5zoon' => 'M5zoon', 'vendoor' => 'Vendoor', 'safqa' => 'Safqa', 'timehand' => 'Time Hand', 'angazny' => 'Angazny', 'shopmart' => 'Shopmart', 'cezma' => 'Cezma'];
function platform_name($plat, $remote = []) {
    global $PLATFORM_NAMES;
    if (isset($remote[$plat]['name'])) return $remote[$plat]['name'];
    return $PLATFORM_NAMES[$plat] ?? $plat;
}
// تقريب سعر البيع لأقرب رقم صحيح ينتهي بـ 5 أو 0 (لأعلى): 57 → 60 و 22 → 25
// الأسعار الصغيرة (أقل من 10 ج.م — مثل أسعار خدمات السوشيال لكل 1000) تبقى كما هي بدون تقريب.
// تقريب أسعار العرض لأقرب «5 أو 0» حسب قيمة السعر:
//   - 10 ج.م وأكتر: لأقرب رقم ينتهي بـ 0 أو 5 (وما ينزلش أقل من السعر المحسوب أبداً)
//   - من 1 لأقل من 10: لأقرب نص جنيه (ينتهي بـ .0 أو .5)
//   - أقل من جنيه: قروش زي ما هو (مافيش تقريب يخسّر)
// الدالة دي هي المرجع لكل أسعار العروض: المنتجات التقليدية + المنتجات الرقمية.
function wf_round_5_or_0($v) {
    $v = (float)$v;
    if ($v <= 0) return 0.0;
    if ($v >= 10) return (float)(ceil(round($v, 4) / 5) * 5);
    if ($v >= 1)  return (float)(ceil(round($v, 4) * 2) / 2);
    return round($v, 2);
}
function wf_round_price($v) {
    return wf_round_5_or_0($v);
}
// زيادة 2% على أسعار المنتجات الفيزيكال بس (vendoor/timehand/safqa/angazny/m5zoon/shopmart/cezma).
// الرقمي والسوشيال ميديا مش متأثرين. التقريب بيفضل زي تقريب الموقع (لأقرب 5 أو 0).
define('PHYS_PRICE_UPLIFT', 0.02);
function wf_is_physical_plat($plat) {
    return in_array(strtolower(trim((string)$plat)), ['vendoor', 'timehand', 'safqa', 'angazny', 'm5zoon', 'shopmart', 'cezma'], true);
}
function wf_price_uplift($plat, $v) {
    $v = (float)$v;
    if ($v <= 0 || !wf_is_physical_plat($plat)) return $v;
    return wf_round_price(round($v * (1 + PHYS_PRICE_UPLIFT), 2));
}
function round_half_or_one($v) {
    $v = (float)$v;
    if ($v <= 0) return 0;
    if ($v < 1.0) return round($v, 2);
    return round($v * 2) / 2;
}
// إضافة السوشيال ميديا (10 قروش افتراضيًا): بقت للخدمات الأرخص من الإضافة نفسها بس —
// أي مبلغ أقل من 10 قروش بيتضاف عليه، وأي مبلغ 10 قروش أو أكتر بيفضل سعر المصدر زي ما هو
// من غير أي إضافة على كل طلب. (المبلغ الإضافي قابل للتعديل من الإعدادات)
function social_norm_price($v) {
    $v = max(0, (float)$v);
    if ($v <= 0) return 0;
    static $addon = null;
    if ($addon === null) {
        $addon = function_exists('wf_social_price_addon') ? (float)wf_social_price_addon() : (float)SOCIAL_PRICE_ADDON;
    }
    if ($addon > 0 && $v < $addon) $v += $addon;
    return round_half_or_one($v);
}
function base_price($p, $plat) {
    // الرقمي والسوشيال ميديا: مافيشش «سعر صافي بعد العمولة» — السعر الأساسي = سعر المصدر نفسه
    if (wf_plat_no_commission($plat)) {
        $g = raw_list_price($p, $plat);
        return $g > 0 ? store_price_from_gross($p, $plat, $g) : 0;
    }
    $v = $p['price'] ?? 0;
    if (is_numeric($v) && $v > 0) {
        return $plat === 'social' ? social_norm_price($v) : ($plat === 'g2g' ? round_half_or_one($v) : (float)$v);
    }
    if ($plat === 'm5zoon') {
        if (preg_match('/([\d.,]+)/', (string)($p['extra']['cost'] ?? ''), $m)) return (float)str_replace(',', '', $m[1]);
    }
    if (preg_match('/([\d.,]+)/', (string)($p['price_text'] ?? ''), $m)) {
        $parsed = (float)str_replace(',', '', $m[1]);
        return $plat === 'social' ? social_norm_price($parsed) : ($plat === 'g2g' ? round_half_or_one($parsed) : $parsed);
    }
    return 0;
}
function sale_price($p, $plat) {
    return base_price($p, $plat);
}
define('DATA_DIR', __DIR__ . '/data');

// 🛍️ نظام أفلييت أمازون (Amazon Associates) — نواة منفصلة
if (file_exists(__DIR__ . '/amazon_lib.php')) require_once __DIR__ . '/amazon_lib.php';
// 🟧 نظام أفلييت جوميا (مصر) — نسخة مطابقة لأمازون بتفاصيل جوميا
if (file_exists(__DIR__ . '/jumia_lib.php')) require_once __DIR__ . '/jumia_lib.php';
if (file_exists(__DIR__ . '/noon_lib.php')) require_once __DIR__ . '/noon_lib.php';
require_once __DIR__ . '/customer_req_lib.php';

// ============================================================================
// جلسة آمنة: كوكي HttpOnly + Secure + SameSite=Lax، واسم جلسة مخصص
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    $forwarded_proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $cf_visitor = strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''));
    $is_https = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '')
             || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
             || ($forwarded_proto === 'https')
             || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on')
             || (strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https')
             || (strpos($cf_visitor, 'https') !== false);
    // منع تسريب أي معلومات خطأ للزائر (مسارات الملفات إلخ)
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
    if (!is_dir(DATA_DIR . '/logs')) @mkdir(DATA_DIR . '/logs', 0755, true);
    $__logf = DATA_DIR . '/logs/php_errors.log';
    if (is_dir(DATA_DIR . '/logs')) @ini_set('error_log', $__logf);
    @error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    session_name('WAFSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}
// صفحات النماذج/التوكنات لا تُخزّن مؤقتاً حتى لا يُعاد نموذج بتوكن جلسة قديمة أو مشتركة.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}
// تجديد معرّف الجلسة بعد الدخول (منع Session Fixation)
function session_harden() {
    if (session_status() === PHP_SESSION_ACTIVE) @session_regenerate_id(true);
}
// تنظيف جلسة قديمة وتثبيت IP/UA لتقليل سرقة الجلسة
function session_bind() {
    $sig = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120));
    if (empty($_SESSION['_bind'])) { $_SESSION['_bind'] = $sig; return true; }
    return hash_equals((string)$_SESSION['_bind'], $sig);
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ============================================================================
// قراءة/كتابة JSON آمنة مع قفل ملف (flock) لمنع تلاشي البيانات في الطلبات المتزامنة
// ============================================================================
$GLOBALS['_JMEM'] = [];
$GLOBALS['_JLOAD_MS'] = [];
if (!function_exists('wfp')) {
    function wfp($label) {}
}

// ===== زمن العناصر (تشخيص مؤقت): لا يعمل إلا مع ?perf=<WF_PERF_TOKEN> في تحقق session =====
if (!defined('WF_PERF_TOKEN')) define('WF_PERF_TOKEN', 'perft0k17');
(function () {
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

function jsave($f, $data) {
    if (docs_is_remote($f)) return docs_jsave($f, $data);
    $GLOBALS['_JMEM'][$f] = $data;
    $p = DATA_DIR . '/' . $f;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $fp = @fopen($p, 'c+b');
    if (!$fp) return false;
    if (@flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
        @flock($fp, LOCK_UN);
    }
    @fclose($fp);
    return true;
}

// قراءة-تعديل-كتابة داخل قفل واحد (LOCK_EX) — يمنع ضياع الرصيد/الطلبات
function jupdate($f, $fn, $def = []) {
    if (docs_is_remote($f)) return docs_jupdate($f, $fn, $def);
    $p = DATA_DIR . '/' . $f;
    if (!file_exists($p)) @file_put_contents($p, json_encode($def, JSON_UNESCAPED_UNICODE));
    $fp = @fopen($p, 'c+b');
    if (!$fp) { // fallback بدون قفل
        $d = jload($f, $def); $r = $fn($d); jsave($f, $d); return $r;
    }
    @flock($fp, LOCK_EX);
    rewind($fp);
    $raw = stream_get_contents($fp);
    $d = json_decode($raw ?: '', true);
    if (!is_array($d)) $d = $def;
    $r = $fn($d);
    if ($r !== false) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
        fflush($fp);
    }
    @flock($fp, LOCK_UN);
    @fclose($fp);
    $GLOBALS['_JMEM'][$f] = $d;
    return $r;
}

// ============================================================================
// حماية CSRF — توليد توكن + حقنه تلقائياً في كل فورم POST + تحقق تلقائي
// ============================================================================
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . esc(csrf_token()) . '">';
}
function csrf_ok() {
    $tok = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sess = (string)($_SESSION['csrf_token'] ?? '');
    return $sess !== '' && $tok !== '' && hash_equals($sess, $tok);
}
// ملفات معفاة (واجهات JSON تُستدعى بـ fetch من نفس الأصل + صفحات الضيف)
function csrf_exempt() {
    $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return in_array($s, ['api_events.php', 'api_image_search.php', 'go.php'], true);
}
// تحقق تلقائي من كل طلب POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_exempt() && !csrf_ok()) {
    $is_ajax = (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
            || (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false);
    if (!headers_sent()) http_response_code(403);
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'CSRF token غير صالح — حدّث الصفحة وحاول تاني'], JSON_UNESCAPED_UNICODE);
    } else {
        // Presentation only: preserve the exact 403/CSRF decision above.
        require_once __DIR__ . '/ui-components.php';
        $ui_asset_pre = strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/dashboard/') !== false ? '../' : '';
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>طلب مرفوض | ' . esc(BRAND) . '</title><link rel="stylesheet" href="' . $ui_asset_pre . 'assets/style.css?v=20261010-eo-fields"></head><body><a class="skip-link" href="#csrfErrorContent">تخطي إلى الرسالة</a><main class="auth" id="csrfErrorContent">';
        wf_ui_sprite();
        echo '<div class="alert danger">' . wf_icon('shield') . '<div><h1>طلب مرفوض (CSRF)</h1><p>التوكن الأمني غير صالح أو انتهت صلاحيته. ارجع للصفحة، حدّثها وحاول مرة أخرى.</p></div></div><a class="btn outline" href="javascript:history.back()">رجوع</a></main></body></html>';
    }
    exit;
}
// حقن التوكن تلقائياً داخل كل <form method="post"> عند إخراج الصفحة عبر ob_start callback آمن
if (PHP_SAPI !== 'cli' && !csrf_exempt()) {
    // Keep pages working during a rolling FTP upload before a new helper has arrived.
    if (is_readable(__DIR__ . '/ui-runtime.php')) {
        require_once __DIR__ . '/ui-runtime.php';
        wf_ui_runtime_start();
    }
    $__csrf_tok = csrf_token();
    @ob_start(function ($buf) use ($__csrf_tok) {
        if (!is_string($buf) || $buf === '' || stripos($buf, '<form') === false) {
            return $buf;
        }
        $tok = !empty($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : (string)$__csrf_tok;
        if ($tok === '') return $buf;
        $field = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($tok, ENT_QUOTES, 'UTF-8') . '">';
        $out = @preg_replace_callback('~<form\b[^>]*>~i', function ($m) use ($field) {
            $tag = $m[0];
            if (stripos($tag, 'method') !== false && !preg_match('~method\s*=\s*["\']?post~i', $tag)) return $tag;
            if (stripos($tag, 'csrf_token') !== false) return $tag;
            return $tag . $field;
        }, $buf);
        return (is_string($out) && $out !== '') ? $out : $buf;
    });
}

// ============================================================================
// تحديد معدل المحاولات (Rate Limiting) — تسجيل الدخول / الأدمن / الاستمارات
// ============================================================================
function rate_limit_key($bucket) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $bucket) . '_' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
}
function rate_limit_check($bucket, $max = 5, $window = 900) {
    $k = rate_limit_key($bucket);
    return jupdate('ratelimit.json', function (&$d) use ($k, $max, $window) {
        $now = time();
        if (!is_array($d)) $d = [];
        $d = array_filter($d, function ($v) use ($now) { return is_array($v) && ($now - (int)($v['t'] ?? 0)) < 86400; });
        $rec = $d[$k] ?? ['n' => 0, 't' => 0];
        if (($now - (int)$rec['t']) > $window) { $rec = ['n' => 0, 't' => $now]; }
        if ((int)$rec['n'] >= $max) {
            $wait = max(1, $window - ($now - (int)$rec['t']));
            $d[$k] = $rec;
            return $wait;
        }
        $rec['n'] = (int)$rec['n'] + 1;
        $d[$k] = $rec;
        return 0;
    });
}
function rate_limit_reset($bucket) {
    $k = rate_limit_key($bucket);
    jupdate('ratelimit.json', function (&$d) use ($k) {
        if (!is_array($d)) $d = [];
        unset($d[$k]);
        return true;
    });
}
function fmt_wait($sec) {
    $m = (int)floor($sec / 60); $s = $sec % 60;
    return $m > 0 ? ($m . ' دقيقة و' . $s . ' ثانية') : ($s . ' ثانية');
}

// ============================================================================
// تحقق من قوة كلمة المرور + صحة الإيميل + تسجيل روابط الاستعادة للإدارة
// ============================================================================
function weak_password_reason($pass) {
    $pass = (string)$pass;
    if (mb_strlen($pass) < 8) return 'كلمة المرور لازم 8 أحرف على الأقل';
    if (mb_strlen($pass) > 200) return 'كلمة المرور طويلة جداً';
    if (!preg_match('/[A-Za-z\x{0600}-\x{06FF}]/u', $pass)) return 'كلمة المرور لازم تحتوي حرف واحد على الأقل';
    if (!preg_match('/[0-9]/u', $pass)) return 'كلمة المرور لازم تحتوي رقم واحد على الأقل';
    $weak = ['12345678','123456789','password','1234567890','qwerty123','abc12345','11111111','00000000','iloveyou','admin123'];
    if (in_array(strtolower($pass), $weak, true)) return 'كلمة المرور دي سهلة جداً — اختار غيرها';
    return '';
}

function valid_email($email) {
    $email = trim((string)$email);
    if (mb_strlen($email) > 190) return false;
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

// حفظ رابط الاستعادة في ملف خاص (احتياطي لو الإيميل مش شغال على الاستضافة)
function log_reset_link($email, $link) {
    $rl = jload('reset_links.json', []);
    if (!is_array($rl)) $rl = [];
    $rl[] = [
        'email' => $email,
        'link'  => $link,
        'ip'    => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'exp'   => time() + 3600,
        'at'    => date('c'),
    ];
    jsave('reset_links.json', array_slice($rl, -50));
}

// إرسال إيميل عن طريق Supabase Edge Function (Resend). بيرجع true لو الإيميل اتقبل للإرسال.
// ملاحظة: تفاصيل الفشل بتتسجل في لوج السيرفر بس، ومتظهرش للمستخدم.
function wf_send_mail($to, $subject, $text) {
    if (!defined('MAIL_FN_SECRET') || MAIL_FN_SECRET === '') {
        error_log('wf_send_mail: MAIL_FN_SECRET is not configured (secrets.php)');
        return false;
    }
    $body = json_encode(['to' => (string)$to, 'subject' => (string)$subject, 'text' => (string)$text], JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json', 'x-mail-secret: ' . MAIL_FN_SECRET];
    // Supabase Edge Functions بتطلب مفتاح المشروع (anon) في الهيدر افتراضياً قبل ما توصل للكود
    if (defined('SB_KEY') && SB_KEY !== '') { $headers[] = 'apikey: ' . SB_KEY; $headers[] = 'Authorization: Bearer ' . SB_KEY; }
    $code = 0; $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init(MAIL_FN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents(MAIL_FN_URL, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    if ($resp === false || $code !== 200) {
        error_log('wf_send_mail failed: HTTP ' . $code);
        return false;
    }
    return true;
}

// ============================================================================
// حساب أدمن حقيقي (role = admin) + تحقق من صلاحيات الإدارة
// ============================================================================
function is_admin_user() {
    $u = current_user();
    if (!$u || empty($u['email'])) return false;
    // Re-check the persistent user record so stale session flags/roles cannot grant access.
    $fresh = find_user($u['email']);
    return is_array($fresh) && (($fresh['role'] ?? '') === 'admin');
}
function require_admin_or_die() {
    if (is_admin_user()) return;
    $u = current_user();
    $role = wf_user_role($u);   // حساب العميل اتلغى: أي حساب مش أدمن = مسوق
    if (!$u) header('Location: ../login.php');
    elseif ($role === 'marketer') header('Location: marketer.php');
    else header('Location: ./');
    exit;
}
// تسجيل نشاط حساس (audit) — موجود كدالة log_audit بالأسفل
function admin_actor() {
    $u = current_user();
    return $u ? ($u['email'] ?? 'unknown') : 'system';
}

// ============================================================================
// كشف البوتات وزواحف السوشيال (لعدم احتسابها زيارات أو كليكات أفلييت)
// ============================================================================
function is_bot_request() {
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return true; // مفيش User-Agent = غالباً سكربت
    if (preg_match('/bot|crawl|spider|slurp|headless|phantom|lighthouse|ptst|curl|wget|python|scrapy|ahrefs|semrush|facebookexternalhit|whatsapp|telegram|preview|monitor|pingdom|uptimerobot|dataprovider|scan|fetcher|validator/i', $ua)) {
        return true;
    }
    return false;
}

// ============================================================================
// وسائل السحب المتاحة للمسوقين (محافظ إلكترونية / انستا باي / بايبال)
// ============================================================================
function payout_methods() {
    return [
        'wallet_vodafone' => ['label' => '📱 فودافون كاش',        'group' => 'محافظ إلكترونية', 'hint' => 'رقم الموبايل (01xxxxxxxxx)',      'pattern' => 'phone', 'min' => 50,  'icon' => '📱'],
        'wallet_orange'   => ['label' => '🟠 أورانج كاش',          'group' => 'محافظ إلكترونية', 'hint' => 'رقم الموبايل (01xxxxxxxxx)',      'pattern' => 'phone', 'min' => 50,  'icon' => '🟠'],
        'wallet_etisalat' => ['label' => '🟢 اتصالات كاش',         'group' => 'محافظ إلكترونية', 'hint' => 'رقم الموبايل (01xxxxxxxxx)',      'pattern' => 'phone', 'min' => 50,  'icon' => '🟢'],
        'wallet_we'       => ['label' => '🔵 وي باي (WE Pay)',     'group' => 'محافظ إلكترونية', 'hint' => 'رقم الموبايل (01xxxxxxxxx)',      'pattern' => 'phone', 'min' => 50,  'icon' => '🔵'],
        'instapay'        => ['label' => '🏦 انستا باي InstaPay',  'group' => 'تحويل بنكي فوري', 'hint' => 'اسم المستخدم في انستا باي (مثال: name@instapay)', 'pattern' => 'instapay', 'min' => 100, 'icon' => '🏦'],
        'bank'            => ['label' => '💳 تحويل بنكي',          'group' => 'تحويل بنكي فوري', 'hint' => 'رقم الحساب / IBAN',              'pattern' => 'iban',  'min' => 200, 'icon' => '💳'],
        'paypal'          => ['label' => '🌍 بايبال PayPal',       'group' => 'دولي',            'hint' => 'إيميل حساب بايبال',              'pattern' => 'email', 'min' => 300, 'icon' => '🌍'],
    ];
}
// التحقق من صحة بيانات السحب حسب الوسيلة
function validate_payout_account($method, $account) {
    $methods = payout_methods();
    if (!isset($methods[$method])) return 'وسيلة السحب غير صحيحة';
    $account = trim((string)$account);
    if ($account === '') return 'لازم تدخل بيانات الحساب / رقم المحفظة';
    switch ($methods[$method]['pattern']) {
        case 'phone':
            $d = preg_replace('/\D+/', '', $account);
            if (!preg_match('/^(002)?01[0125][0-9]{8}$/', $d)) return 'رقم موبايل مصري غير صحيح (لازم 01xxxxxxxxx)';
            break;
        case 'instapay':
            if (!preg_match('/^[a-zA-Z0-9._-]{3,40}@instapay$/i', $account)) return 'صيغة انستا باي غير صحيحة (مثال: yourname@instapay)';
            break;
        case 'iban':
            $d = preg_replace('/[^A-Za-z0-9]/', '', $account);
            if (mb_strlen($d) < 10 || mb_strlen($d) > 34) return 'رقم الحساب / IBAN غير صحيح';
            break;
        case 'email':
            if (!valid_email($account)) return 'إيميل بايبال غير صحيح';
            break;
    }
    return '';
}
function payout_method_label($m) {
    $ms = payout_methods();
    return $ms[$m]['label'] ?? ($m ?: '—');
}
function payout_method_icon($m) {
    $ms = payout_methods();
    return $ms[$m]['icon'] ?? '💳';
}

// تعطيل انتظار Supabase البطيء والاعتماد على الفهرس المحلي السريع (أقل من 30ms)
define('USE_SUPABASE', false);

// استنتاج المنصة من رقم المنتج بدون إظهار اسم الأفلييت في الرابط
function resolve_plat($id, $plat_hint = '') {
    $id = trim((string)$id);
    $plat_hint = trim((string)$plat_hint);
    if ($plat_hint !== '' && in_array($plat_hint, ['timehand', 'safqa', 'vendoor', 'angazny', 'm5zoon', 'shopmart', 'cezma', 'g2g', 'social'], true)) {
        return $plat_hint;
    }
    if ($id === '') return 'vendoor';
    $idx = jload('id_index.json', []);
    if (isset($idx[$id])) return $idx[$id];
    $dec = rawurldecode($id);
    if (isset($idx[$dec])) return $idx[$dec];
    if (strpos($id, 'dg-') === 0) return 'g2g';
    if (strpos($id, 'hevo-') === 0) return 'social';
    if (preg_match('/-(AE|SA|JO|OM)$/i', $id)) return 'm5zoon';
    return 'vendoor';
}

// الرابط الأساسي للموقع مع دعم HTTPS خلف البروكسي / Cloudflare / InfinityFree
function site_base_url() {
    $forwarded_proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $cf_visitor = strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''));
    $is_https = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '')
             || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
             || ($forwarded_proto === 'https')
             || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on')
             || (strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https')
             || (strpos($cf_visitor, 'https') !== false);
    $scheme = $is_https ? 'https' : 'http';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $root = (strpos($script, '/dashboard/') !== false) ? rtrim(str_replace('\\', '/', dirname(dirname($script))), '/') : rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($root === '/' || $root === '.') $root = '';
    return isset($_SERVER['HTTP_HOST']) ? ($scheme . '://' . $_SERVER['HTTP_HOST'] . $root) : '';
}

// توليد رابط صفحة الهبوط مباشرة (نسبي للأزرار أو كامل للنسخ والمشاركة)
function landing_page_url($id, $ref = '', $extra_comm = 0, $plat = '', $relative = false) {
    $id = trim((string)$id);
    // بدون اسم المنصة في رابط العميل — السيرفر يستنتجها من فهرس المنتجات
    $qs = ['id' => $id];
    if ($ref !== '') $qs['ref'] = (string)$ref;
    // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة يتحط في الرابط
    if ((float)$extra_comm > 0 && !(function_exists('wf_is_digital_plat') && wf_is_digital_plat($plat))) $qs['comm'] = (float)$extra_comm;
    $query = http_build_query($qs);
    if ($relative) {
        $in_dash = (strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/dashboard/') !== false);
        return ($in_dash ? '../landing.php?' : 'landing.php?') . $query;
    }
    $base = site_base_url();
    return ($base !== '' ? $base . '/landing.php?' : 'landing.php?') . $query;
}

// توليد رابط منتج نظيف بدون اسم منصة الأفلييت (مثال: product.php?id=6056)
function prod_url($id_or_p, $in_dash = null, $ref = '') {
    $id = is_array($id_or_p) ? ($id_or_p['id'] ?? '') : $id_or_p;
    if ($in_dash === null) {
        $in_dash = (strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/dashboard/') !== false);
    }
    $prefix = $in_dash ? 'product.php' : 'dashboard/product.php';
    $u = $prefix . '?id=' . urlencode((string)$id);
    if ($ref !== '') $u .= '&ref=' . urlencode((string)$ref);
    return $u;
}

// توليد رابط أفلييت نظيف للمسوق (يفتح صفحة الهبوط للمنتج أو المتجر العام)
function aff_link($id = '', $code = '', $extra_comm = 0, $plat = '') {
    if ($id !== '') {
        return landing_page_url($id, $code, $extra_comm, $plat, false);
    }
    $base = site_base_url();
    return $base . '/index.php' . ($code !== '' ? '?ref=' . urlencode((string)$code) : '');
}

// رابط المنتج على الموقع الأساسي (زي صفحة الهبوط، بس بيفتح صفحة المنتج الكاملة في الموقع).
// بيمر على go.php عشان الضغطة تتسجل للمسوق، وبيوصل لصفحة المنتج بالسعر بعد العمولة (?comm=).
function main_site_product_url($id, $ref = '', $extra_comm = 0) {
    $qs = ['id' => trim((string)$id), 'lp' => '0'];
    if ($ref !== '') $qs['ref'] = (string)$ref;
    if ((float)$extra_comm > 0) $qs['comm'] = (float)$extra_comm;
    $base = site_base_url();
    return ($base !== '' ? $base . '/go.php?' : 'go.php?') . http_build_query($qs);
}

function stats() { return function_exists('site_stats') ? site_stats() : jload('stats.json', []); }

// قراءة سريعة للمنتجات في صفحات القوائم والفلاتر (باستخدام lite_*.json)
// ===== تحكم الأدمن في المنتجات (إخفاء / تثبيت / تعديل عنوان أو صورة) — data/product_overrides.json =====
function product_overrides() {
    if (!isset($GLOBALS['_WF_PRODUCT_OVERRIDES']) || !is_array($GLOBALS['_WF_PRODUCT_OVERRIDES'])) {
        $ov = jload('product_overrides.json', []);
        $GLOBALS['_WF_PRODUCT_OVERRIDES'] = is_array($ov) ? $ov : [];
    }
    return $GLOBALS['_WF_PRODUCT_OVERRIDES'];
}
function apply_product_override_record($row, $key, $show_hidden = false) {
    if (!is_array($row)) return null;
    $override = product_overrides()[$key . '#' . (string)($row['id'] ?? '')] ?? [];
    if (!empty($override['deleted'])) return null;
    if (!empty($override['hidden'])) {
        if (!$show_hidden) return null;
        $row['_hidden'] = true;
    }
    if (array_key_exists('title', $override)) $row['name'] = $override['title'];
    if (array_key_exists('name', $override)) $row['name'] = $override['name'];
    if (array_key_exists('category', $override)) $row['category'] = $override['category'];
    if (array_key_exists('description', $override)) $row['desc'] = $override['description'];
    if (array_key_exists('sku', $override)) $row['sku'] = $override['sku'];
    if (array_key_exists('price', $override)) {
        $row['price'] = $override['price'];
        $row['price_text'] = $override['price'] . ' ' . ($row['currency'] ?? 'جنيه');
    }
    if (array_key_exists('images', $override) && is_array($override['images'])) $row['images'] = $override['images'];
    elseif (!empty($override['image'])) $row['images'] = array_values(array_unique(array_merge([$override['image']], (array)($row['images'] ?? []))));
    if (array_key_exists('sizes', $override)) $row['sizes'] = (array)$override['sizes'];
    if (array_key_exists('colors', $override)) {
        if (!isset($row['extra']) || !is_array($row['extra'])) $row['extra'] = [];
        $row['extra']['colors'] = (array)$override['colors'];
    }
    if (array_key_exists('features', $override)) {
        if (!isset($row['extra']) || !is_array($row['extra'])) $row['extra'] = [];
        $row['extra']['features'] = (string)$override['features'];
    }
    if (array_key_exists('specifications', $override)) {
        if (!isset($row['extra']) || !is_array($row['extra'])) $row['extra'] = [];
        $row['extra']['specifications'] = (string)$override['specifications'];
    }
    if (array_key_exists('variants', $override)) {
        if (!isset($row['extra']) || !is_array($row['extra'])) $row['extra'] = [];
        $row['extra']['variants_detailed'] = (array)$override['variants'];
    }
    if (!empty($override['pinned'])) $row['_pinned'] = true;
    return $row;
}
function apply_product_overrides($all, $key, $show_hidden = false) {
    $out = []; $pinned = [];
    foreach ($all as $row) {
        $row = apply_product_override_record($row, $key, $show_hidden);
        if (!is_array($row)) continue;
        if (!empty($row['_pinned'])) { $pinned[] = $row; continue; }
        $out[] = $row;
    }
    return array_merge($pinned, $out);
}
function clean_product_images($imgs) {
    if (!is_array($imgs)) return [];
    $out = [];
    foreach ($imgs as $u) {
        if (!is_string($u)) continue;
        $u = trim($u);
        if ($u === '' || $u === '/' || $u === '#' || strlen($u) < 5) continue;
        $ul = strtolower($u);
        if (strpos($ul, 'place-holder') !== false || strpos($ul, 'image-place-holder') !== false || strpos($ul, 'no-image') !== false || substr($ul, -12) === '/default.png' || substr($ul, -8) === '/def.png' || strpos($ul, '/product/meta/') !== false || strpos($ul, '/product/thumbnail/') !== false) continue;
        if (strpos($u, ' ') !== false && preg_match('~^https?://~i', $u)) $u = str_replace(' ', '%20', $u);
        if (!in_array($u, $out, true)) $out[] = $u;
    }
    return $out;
}
function product_fallback_svg($name = 'منتج', $cat = '') {
    $label = mb_substr(trim((string)($cat ?: $name ?: 'منتج')), 0, 22);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#f8fafc"/><stop offset="100%" stop-color="#e2e8f0"/></linearGradient></defs><rect width="400" height="400" fill="url(#g)"/><circle cx="200" cy="175" r="54" fill="#fff" stroke="#cbd5e1" stroke-width="2"/><text x="200" y="192" text-anchor="middle" font-size="44">🛍️</text><text x="200" y="275" text-anchor="middle" fill="#334155" font-family="Cairo,sans-serif" font-weight="800" font-size="18">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</text></svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}
function normalize_product_record($x, $key = '') {
    if (!is_array($x)) return $x;
    if ($key !== '') $x['_plat'] = $key;
    $pl = $x['_plat'] ?? $key;
    if ($pl === 'vendoor') $x = with_detail_imgs($x, $pl);
    $imgs = clean_product_images($x['images'] ?? []);
    if (!$imgs && !empty($x['extra']['detail_images']) && is_array($x['extra']['detail_images'])) {
        $imgs = clean_product_images($x['extra']['detail_images']);
    }
    $x['images'] = $imgs;
    if (!empty($x['name'])) {
        $cn = aff_clean($x['name'], true);
        if ($cn !== '') $x['name'] = $cn;
    }
    return $x;
}
require_once __DIR__ . '/manual_products_lib.php';
function platform_products_lite($key) {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    if ($key === 'manual') {
        $all = manual_products_all();
        foreach ($all as &$x) $x = normalize_product_record($x, 'manual');
        unset($x);
        return $cache[$key] = apply_product_overrides($all, 'manual');
    }
    if ($key === 'cezma') {
        $all = cezma_all_products();
        foreach ($all as &$x) {
            $x = normalize_product_record($x, $key);
            // توحيد قسم سيزما مع أقسام الموقع (الموضة والجمال → ملابس/أحذية/شنط…)
            $x['category'] = wf_cezma_site_category($x['category'] ?? '', $x['name'] ?? '');
        }
        unset($x);
        return $cache[$key] = apply_product_overrides($all, $key);
    }
    $lite = DATA_DIR . '/lite_' . $key . '.json';
    $all = file_exists($lite) ? jload('lite_' . $key . '.json', []) : jload($key . '.json', []);
    if (!$all) $all = jload($key . '.json', []);
    foreach ($all as &$x) {
        $x = normalize_product_record($x, $key);
    }
    unset($x);
    return $cache[$key] = apply_product_overrides($all, $key);
}

// نافذة أحدث المنتجات لكل منصة للصفحة الرئيسية (كاش قصير 10 دقايق).
// الهدف: الرئيسية متقرأش الكتالوج كامل كل زيارة — بنحفظ آخر 900 منتج لكل منصة
// في ملف صغير، فالطلبات اللي بعده بتقرأ ملف صغير بدل تحميل/تجهيز الكتالوج.
function wf_home_platform_window($pk, $limit) {
    $limit = max(1, min(900, (int)$limit));
    static $memo = [];
    $key = (string)$pk;
    if (isset($memo[$key])) return array_slice($memo[$key], -$limit);

    $dir = DATA_DIR . '/cache';
    $file = $dir . '/home_window_' . preg_replace('/[^a-z0-9_]/', '', $key) . '.json';
    $ttl = 600;
    $items = null;
    if (is_file($file) && (time() - (int)@filemtime($file)) < $ttl) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && isset($c['items']) && is_array($c['items']) && $c['items']) $items = $c['items'];
    }
    if ($items === null) {
        $all = ($key === 'cezma') ? cezma_recent_products(900) : platform_products_lite($key);
        if (count($all) > 900) $all = array_slice($all, -900);
        $items = array_values($all);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) {
            $payload = json_encode(['at' => time(), 'items' => $items], JSON_UNESCAPED_UNICODE);
            if ($payload !== false) @file_put_contents($file, $payload, LOCK_EX);
        }
    }
    $memo[$key] = $items;
    return array_slice($items, -$limit);
}

function platform_products($key, $lite = false) {
    if ($lite) return platform_products_lite($key);
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    if ($key === 'cezma') return $cache[$key] = platform_products_lite($key);
    $all = jload($key . '.json', []);
    if (!$all) $all = jload('lite_' . $key . '.json', []);
    foreach ($all as &$x) {
        $x = normalize_product_record($x, $key);
    }
    unset($x);
    return $cache[$key] = apply_product_overrides($all, $key);
}

// ============================================================================
// بحث المنتج الواحد: قراءة نص الملف + استخراج السجل المطلوب فقط
// كان البحث القديم يفك تشفير الكتالوج بالكامل (حتى 8 ميجا) لكل بحث — على استضافة
// مشتركة ده بيستهلك الذاكرة/الوقت ويطلّع صفحة «تعذر تحميل الصفحة مؤقتًا».
// الطريقة الجديدة: نقرا نص الملف مرة واحدة لكل منصة ونقطّع سجل واحد بس ونفكه.
// ============================================================================
function wf_catalog_plats() {
    return ['timehand', 'safqa', 'vendoor', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social', 'cezma', 'manual'];
}

// نص ملف الكتالوج الخام لمنصة واحدة (مرة واحدة في الطلب — نص فقط، بدون فك كتالوج)

// ===== Cezma: كتالوج مقسم لشظايا (data/cezma_shards/lite_cezma_XX.json) =====
function cezma_shard_files() {
    static $files = null;
    if ($files !== null) return $files;
    $files = glob(DATA_DIR . '/cezma_shards/lite_cezma_*.json') ?: [];
    sort($files);
    return $files;
}
// عدد الشظايا المحمّلة في الطلب الواحد (شظية = 6000 منتج ≈ 3 ميجا)
if (!defined('WF_CEZMA_POOL_SHARDS')) define('WF_CEZMA_POOL_SHARDS', 17);
// كتالوج سيزما (منتجات 2026 بس — 2025 اتحذفت) أكبر من أي كتالوج تاني (43 ألف منتج في 8 شظايا ≈ 20 ميجا)، وتحميله
// كله في كل طلب على استضافة مشتركة (memory_limit 256M) بيموّت الصفحة بخطأ ذاكرة.
// فبنحمّل عدد محدود من الشظايا في الطلب الواحد مع تبديل ساعي ثابت:
//   • كل الطلبات جوه الساعة الواحدة بتشوف نفس المنتجات → القوائم والترقيم متسقين.
//   • مع تغيّر الساعة بتتغيّر الشظايا → الكتالوج كله بياخد فرصته في الظهور.
// بحث المنتج الواحد بالـ ID مش متأثر: wf_catalog_find_record بيقرأ الشظايا كلها.
function cezma_pool_files() {
    static $pool = null;
    if ($pool !== null) return $pool;
    $files = cezma_shard_files();
    $n = count($files);
    if ($n === 0) return $pool = [];
    $take = max(1, min($n, (int)WF_CEZMA_POOL_SHARDS));
    $start = (int)(floor(time() / 3600)) % $n;
    $out = [];
    for ($i = 0; $i < $take; $i++) $out[] = $files[($start + $i) % $n];
    return $pool = $out;
}
function cezma_all_products() {
    static $all = null;
    if ($all !== null) return $all;
    $all = [];
    foreach (cezma_pool_files() as $f) {
        $rows = json_decode((string)@file_get_contents($f), true);
        if (is_array($rows)) $all = array_merge($all, $rows);
    }
    return $all = cezma_filter_available_rows($all);
}

// فهرس مشتق من الشظايا يبنيه updater؛ لا نستخدمه إلا إذا طابق توقيع المصدر الحالي.
function cezma_search_index_manifest() {
    static $loaded = false, $manifest = null;
    if ($loaded) return $manifest;
    $loaded = true;
    $m = jload('zz_cezma_index/zz_manifest.json', null);
    if (!is_array($m) || (int)($m['format'] ?? 0) !== 1) return null;
    $counts = jload('catalog_counts.json', []);
    if (!is_array($counts)) return null;
    $expected = $counts['cezma_index_signature'] ?? '';
    $actual = $m['signature'] ?? '';
    if (!is_string($expected) || !is_string($actual)
        || !preg_match('/^[a-f0-9]{64}$/D', $expected) || !preg_match('/^[a-f0-9]{64}$/D', $actual)
        || !hash_equals($expected, $actual)) return null;
    if (!is_array($m['categories'] ?? null) || !is_array($m['category_counts'] ?? null)
        || !is_array($m['product_chunks'] ?? null) || !is_array($m['search_files'] ?? null)
        || !is_array($m['country_category_counts'] ?? null) || !is_array($m['files'] ?? null)
        || !is_array($m['file_sha256'] ?? null) || !is_int($m['product_count'] ?? null)
        || !is_bool($m['has_subcategories'] ?? null)) return null;

    $product_count = $m['product_count'];
    $category_total = 0;
    $assigned_chunks = [];
    foreach ($m['category_counts'] as $category => $count) {
        if (!is_int($count) || $count < 0 || !array_key_exists($category, $m['categories'])
            || !is_array($m['categories'][$category])) return null;
        $category_total += $count;
        if (($count === 0) !== empty($m['categories'][$category])) return null;
        foreach ($m['categories'][$category] as $chunk_id) {
            if (!is_int($chunk_id) || $chunk_id < 0 || !array_key_exists($chunk_id, $m['product_chunks'])
                || isset($assigned_chunks[$chunk_id])) return null;
            $assigned_chunks[$chunk_id] = true;
        }
    }
    if (count($m['categories']) !== count($m['category_counts']) || $category_total !== $product_count
        || count($assigned_chunks) !== count($m['product_chunks'])) return null;
    for ($chunk_id = 0; $chunk_id < count($m['product_chunks']); $chunk_id++) {
        if (!isset($assigned_chunks[$chunk_id])) return null;
    }

    $country_total = 0;
    $country_category_totals = [];
    foreach ($m['country_category_counts'] as $country_counts) {
        if (!is_array($country_counts)) return null;
        foreach ($country_counts as $category => $count) {
            if (!is_int($count) || $count < 0 || !array_key_exists($category, $m['category_counts'])) return null;
            $country_total += $count;
            $country_category_totals[$category] = ($country_category_totals[$category] ?? 0) + $count;
        }
    }
    if ($country_total !== $product_count) return null;
    foreach ($m['category_counts'] as $category => $count) {
        if (($country_category_totals[$category] ?? 0) !== $count) return null;
    }

    $file_set = [];
    foreach ($m['files'] as $filename) {
        if (!is_string($filename) || isset($file_set[$filename])
            || (cezma_search_index_path($filename, 'product') === null && cezma_search_index_path($filename, 'search') === null)) return null;
        $checksum = $m['file_sha256'][$filename] ?? '';
        if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/D', $checksum)) return null;
        $file_set[$filename] = true;
    }
    if (count($m['file_sha256']) !== count($file_set)
        || count($file_set) !== count($m['product_chunks']) + count($m['search_files'])) return null;
    $product_file_set = [];
    foreach ($m['product_chunks'] as $filename) {
        if (cezma_search_index_path($filename, 'product') === null || !isset($file_set[$filename])
            || isset($product_file_set[$filename])) return null;
        $product_file_set[$filename] = true;
    }
    if (count($product_file_set) !== count($m['product_chunks']) || count($m['search_files']) !== 16) return null;
    $search_file_set = [];
    foreach (str_split('0123456789abcdef') as $bucket) {
        $filename = $m['search_files'][$bucket] ?? null;
        if (!is_string($filename) || $filename !== 'search_' . $bucket . '.json'
            || cezma_search_index_path($filename, 'search') === null || !isset($file_set[$filename])) return null;
        $search_file_set[$filename] = true;
    }
    if (count($search_file_set) !== 16 || count($file_set) !== count($product_file_set) + count($search_file_set)) return null;
    return $manifest = $m;
}

function cezma_index_override_flags() {
    static $flags = null;
    if ($flags !== null) return $flags;
    $flags = ['title' => false, 'hidden' => false];
    foreach (product_overrides() as $key => $override) {
        if (strpos((string)$key, 'cezma#') !== 0 || !is_array($override)) continue;
        if (!empty($override['title'])) $flags['title'] = true;
        if (!empty($override['hidden'])) $flags['hidden'] = true;
        if ($flags['title'] && $flags['hidden']) break;
    }
    return $flags;
}

function cezma_search_index_path($filename, $kind = 'product') {
    if (!is_string($filename)) return null;
    $pattern = $kind === 'search'
        ? '/^search_[0-9a-f]\\.json$/D'
        : '/^products_[0-9a-f]{12}_[0-9]{4}\\.json$/D';
    if (!preg_match($pattern, $filename)) return null;
    return DATA_DIR . '/zz_cezma_index/' . $filename;
}

function cezma_search_index_read_json($filename, $manifest, $kind = 'product') {
    $path = cezma_search_index_path($filename, $kind);
    if ($path === null) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw)) return null;
    $expected = $manifest['file_sha256'][$filename] ?? '';
    if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected)
        || !hash_equals($expected, hash('sha256', $raw))) return null;
    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
}

function cezma_search_index_is_list($value) {
    if (!is_array($value)) return false;
    $expected_key = 0;
    foreach ($value as $key => $_item) {
        if ($key !== $expected_key++) return false;
    }
    return true;
}

function cezma_search_query_trigrams($query) {
    $chars = preg_split('//u', mb_strtolower((string)$query, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars) || count($chars) < 3) return null;
    $grams = [];
    for ($i = 0; $i <= count($chars) - 3; $i++) {
        $grams[] = $chars[$i] . $chars[$i + 1] . $chars[$i + 2];
    }
    return array_values(array_unique($grams));
}

// يرجع null لو الفهرس ناقص/قديم (فيكمل المسار الأصلي)، وقائمة فارغة لو لا توجد نتائج.
function cezma_indexed_products($cat, $q) {
    $manifest = cezma_search_index_manifest();
    if ($manifest === null) return null;

    $special_cats = ['المنتجات المميزة', 'أحدث المنتجات', 'الأكثر مبيعاً', 'الأكثر مشاهدة'];
    $category_filter = $cat !== '' && $cat !== 'all' && !in_array($cat, $special_cats, true);
    // subcategory has its own legacy matching rule; until it has a dedicated posting map, use the full path.
    if ($category_filter && !empty($manifest['has_subcategories'])) return null;
    $candidate_files = null;
    if ($category_filter) {
        if (!array_key_exists($cat, $manifest['categories'])) return [];
        $chunk_ids = $manifest['categories'][$cat];
        if (!is_array($chunk_ids)) return null;
        $candidate_files = [];
        foreach ($chunk_ids as $chunk_id) {
            if (!is_int($chunk_id) || !array_key_exists($chunk_id, $manifest['product_chunks'])) return null;
            $filename = $manifest['product_chunks'][$chunk_id];
            if (cezma_search_index_path($filename, 'product') === null) return null;
            $candidate_files[$chunk_id] = true;
        }
        if (!$candidate_files) return [];
    }

    if ($q !== '') {
        // العناوين المعدّلة يدوياً لا تظهر في الفهرس؛ ارجع للمسار الكامل كي لا تفوت نتيجة.
        if (!empty(cezma_index_override_flags()['title'])) return null;
        $grams = cezma_search_query_trigrams($q);
        if ($grams === null) return null; // كلمات قصيرة جداً تحتاج فحصاً كاملاً لضمان عدم تفويت نتيجة.
        $bucket_maps = [];
        foreach ($grams as $gram) {
            $bucket = substr(md5($gram), 0, 1);
            if (!array_key_exists($bucket, $bucket_maps)) {
                $filename = $manifest['search_files'][$bucket] ?? '';
                $bucket_maps[$bucket] = cezma_search_index_read_json($filename, $manifest, 'search');
                if (!is_array($bucket_maps[$bucket])) return null;
            }
            $posting = $bucket_maps[$bucket][$gram] ?? null;
            if ($posting === null) return [];
            if (!is_array($posting)) return null;
            $posting_set = [];
            foreach ($posting as $chunk_id) {
                if (!is_int($chunk_id) || !array_key_exists($chunk_id, $manifest['product_chunks'])) return null;
                $filename = $manifest['product_chunks'][$chunk_id];
                if (cezma_search_index_path($filename, 'product') === null) return null;
                $posting_set[$chunk_id] = true;
            }
            $candidate_files = $candidate_files === null
                ? $posting_set
                : array_intersect_key($candidate_files, $posting_set);
            if (!$candidate_files) return [];
        }
    } elseif (!$category_filter) {
        return null;
    }

    $products = [];
    $loaded_category_count = 0;
    foreach (array_keys($candidate_files ?? []) as $chunk_id) {
        if (!is_int($chunk_id) || !array_key_exists($chunk_id, $manifest['product_chunks'])) return null;
        $filename = $manifest['product_chunks'][$chunk_id];
        $rows = cezma_search_index_read_json($filename, $manifest, 'product');
        if (!cezma_search_index_is_list($rows)) return null;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['__wf_cezma_order'])) return null;
            if ($category_filter) {
                $row_category = wf_cezma_site_category($row['category'] ?? '', $row['name'] ?? '');
                if ($row_category !== $cat) return null;
                $loaded_category_count++;
            }
            $order = (int)$row['__wf_cezma_order'];
            unset($row['__wf_cezma_order']);
            $row = normalize_product_record($row, 'cezma');
            $row['category'] = wf_cezma_site_category($row['category'] ?? '', $row['name'] ?? '');
            if (!$row || !cezma_filter_available_rows([$row])) continue;
            // تطبيق المطابقة الدقيقة فورًا يقلّل الذاكرة في البحث واسع المرشحين؛ وتُعاد الفلاتر لاحقًا كحارس.
            if ($q !== '' && mb_stripos(($row['name'] ?? '') . ' ' . ($row['sku'] ?? ''), $q) === false) continue;
            $row['__wf_cezma_order'] = $order;
            $products[] = $row;
        }
        unset($rows);
    }
    if ($category_filter && $loaded_category_count !== (int)($manifest['category_counts'][$cat] ?? -1)) return null;
    usort($products, function ($a, $b) {
        return (int)$a['__wf_cezma_order'] <=> (int)$b['__wf_cezma_order'];
    });
    foreach ($products as &$product) {
        unset($product['__wf_cezma_order']);
    }
    unset($product);
    return apply_product_overrides($products, 'cezma');
}

function cezma_search_index_category_counts($country = 'all') {
    $manifest = cezma_search_index_manifest();
    if ($manifest !== null && empty(cezma_index_override_flags()['hidden'])) {
        $counts = $country === 'all'
            ? ($manifest['category_counts'] ?? null)
            : ($manifest['country_category_counts'][$country] ?? []);
        if (is_array($counts)) {
            $out = [];
            foreach ($counts as $raw_cat => $count) {
                $category = wf_normalize_category($raw_cat);
                if ($category === '' || in_array($category, ['المنتجات الرقمية', 'سوشيال ميديا', 'منتجات رقمية'], true)) continue;
                $out[$category] = ($out[$category] ?? 0) + (int)$count;
            }
            return $out;
        }
    }

    // Missing/stale index or hidden overrides: use the original full Cezma scan.
    $out = [];
    foreach (platform_products_lite('cezma') as $product) {
        if ($country !== 'all' && ($product['country'] ?? 'مصر') !== $country) continue;
        $category = wf_normalize_category($product['category'] ?? '');
        if ($category === '' || in_array($category, ['المنتجات الرقمية', 'سوشيال ميديا', 'منتجات رقمية'], true)) continue;
        $out[$category] = ($out[$category] ?? 0) + 1;
    }
    return $out;
}

// الصفحة الرئيسية تحتاج فقط أول مجموعة من أحدث منتجات سيزما، لا الكتالوج كله.
// نقرأ الشظايا من النهاية ونحتفظ بصفوفها مؤقتًا داخل الطلب؛ صفحات الترقيم تطلب
// نافذة أكبر تدريجيًا، بينما البحث والتصفية الدقيقة يستمران باستخدام المسار الكامل.
function cezma_recent_products($limit) {
    $limit = max(0, min(100000, (int)$limit));
    if ($limit === 0) return [];

    static $files = null, $file_pos = 0, $rows = [], $row_pos = null, $newest_first = [];
    if ($files === null) $files = array_reverse(cezma_shard_files());

    while (count($newest_first) < $limit && $file_pos < count($files)) {
        if ($row_pos === null) {
            $rows = json_decode((string)@file_get_contents($files[$file_pos]), true);
            if (!is_array($rows)) $rows = [];
            $row_pos = count($rows) - 1;
        }
        if ($row_pos < 0) {
            $rows = [];
            $row_pos = null;
            $file_pos++;
            continue;
        }
        while ($row_pos >= 0 && count($newest_first) < $limit) {
            $row = $rows[$row_pos--];
            if (!is_array($row)) continue;
            $row = normalize_product_record($row, 'cezma');
            $row['category'] = wf_cezma_site_category($row['category'] ?? '', $row['name'] ?? '');
            if (!cezma_filter_available_rows([$row])) continue;
            $newest_first[] = $row;
        }
    }

    $tail = array_reverse(array_slice($newest_first, 0, $limit));
    return apply_product_overrides($tail, 'cezma');
}
// IDs discovered from live unavailable-product reports; kept hidden even if the source feed still lists them.
function cezma_unavailable_ids() {
    if (isset($GLOBALS['_CEZMA_UNAVAILABLE_IDS']) && is_array($GLOBALS['_CEZMA_UNAVAILABLE_IDS'])) return $GLOBALS['_CEZMA_UNAVAILABLE_IDS'];
    $ids = ['337112' => true, '337114' => true];
    $saved = jload('cezma_unavailable.json', []);
    if (is_array($saved)) {
        foreach ($saved as $key => $value) {
            $id = is_int($key) ? (string)$key : (string)$key;
            if (is_int($value) || ctype_digit((string)$value)) $id = (string)$value;
            $id = preg_replace('/^cezma-/', '', trim($id));
            if ($id !== '' && ctype_digit($id)) $ids[$id] = true;
        }
    }
    $GLOBALS['_CEZMA_UNAVAILABLE_IDS'] = $ids;
    return $ids;
}
function cezma_is_unavailable_id($id) {
    $id = preg_replace('/^cezma-/', '', trim((string)$id));
    return $id !== '' && isset(cezma_unavailable_ids()[$id]);
}
function cezma_mark_unavailable_id($id) {
    $id = preg_replace('/^cezma-/', '', trim((string)$id));
    if ($id === '' || !ctype_digit($id)) return;
    jupdate('cezma_unavailable.json', function (&$ids) use ($id) {
        if (!is_array($ids)) $ids = [];
        $ids[$id] = date('c');
        return true;
    }, []);
    unset($GLOBALS['_CEZMA_UNAVAILABLE_IDS']);
}
function cezma_contains_unavailable_notice($value) {
    $needle = preg_replace('/\\s+/u', '', 'هذا المنتج غير متوفر في الوقت الحالي');
    if (is_string($value)) {
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace('ـ', '', $text);
        return mb_stripos(preg_replace('/\\s+/u', '', $text), $needle) !== false;
    }
    if (is_array($value)) foreach ($value as $child) if (cezma_contains_unavailable_notice($child)) return true;
    return false;
}
function cezma_filter_available_rows($rows) {
    if (!is_array($rows)) return [];
    $ids = cezma_unavailable_ids();
    return array_values(array_filter($rows, function ($row) use ($ids) {
        if (!is_array($row)) return false;
        $id = preg_replace('/^cezma-/', '', (string)($row['extra']['cezma_id'] ?? $row['orig_id'] ?? $row['id'] ?? ''));
        return !isset($ids[$id]) && !cezma_contains_unavailable_notice($row);
    }));
}
function cezma_collect_image_urls($value, &$out) {
    if (is_string($value)) {
        $url = trim($value);
        if (preg_match('~^https?://~i', $url) && !in_array($url, $out, true)) $out[] = $url;
        return;
    }
    if (!is_array($value)) return;
    foreach (['url', 'image', 'image_url', 'src', 'path', 'original', 'full', 'thumbnail', 'large'] as $key) {
        if (isset($value[$key])) cezma_collect_image_urls($value[$key], $out);
    }
    foreach ($value as $key => $child) if (!in_array($key, ['url', 'image', 'image_url', 'src', 'path', 'original', 'full', 'thumbnail', 'large'], true)) cezma_collect_image_urls($child, $out);
}
function cezma_variant_text($value) {
    if (is_string($value) || is_numeric($value)) return trim((string)$value);
    if (!is_array($value)) return '';
    foreach (['name_ar', 'value_ar', 'label_ar', 'name', 'value', 'label', 'title'] as $key) {
        if (isset($value[$key]) && (is_string($value[$key]) || is_numeric($value[$key]))) return trim((string)$value[$key]);
    }
    return '';
}
function cezma_extract_variant_options($product) {
    $colors = []; $sizes = []; $variants = [];
    $add = function ($label, $value) use (&$colors, &$sizes) {
        $label = mb_strtolower((string)$label); $value = trim((string)$value);
        if ($value === '' || $value === '—') return;
        if (preg_match('/لون|ألوان|الوان|color|colour/u', $label) && !in_array($value, $colors, true)) $colors[] = $value;
        if (preg_match('/مقاس|مقاسات|حجم|size/u', $label) && !in_array($value, $sizes, true)) $sizes[] = $value;
    };
    foreach ((array)($product['attributes'] ?? []) as $attribute) {
        if (!is_array($attribute)) continue;
        $parent = cezma_variant_text($attribute['parent'] ?? $attribute['attribute'] ?? []);
        if ($parent === '') $parent = cezma_variant_text($attribute['parent_name_ar'] ?? $attribute['label_ar'] ?? $attribute['key'] ?? '');
        $values = $attribute['values'] ?? $attribute['options'] ?? null;
        if ($parent === '' && is_array($values)) $parent = cezma_variant_text($attribute['name_ar'] ?? $attribute['name'] ?? $attribute['label'] ?? '');
        if (is_array($values)) {
            foreach ($values as $option) $add($parent, cezma_variant_text($option));
        } else {
            $value = cezma_variant_text($attribute['value'] ?? $attribute['value_ar'] ?? $attribute['name_ar'] ?? $attribute['label'] ?? '');
            $add($parent, $value);
        }
    }
    foreach (['colors', 'color_options', 'available_colors'] as $key) foreach ((array)($product[$key] ?? []) as $value) {
        $v = cezma_variant_text($value); if ($v !== '' && !in_array($v, $colors, true)) $colors[] = $v;
    }
    foreach (['sizes', 'size_options', 'available_sizes'] as $key) foreach ((array)($product[$key] ?? []) as $value) {
        $v = cezma_variant_text($value); if ($v !== '' && !in_array($v, $sizes, true)) $sizes[] = $v;
    }
    foreach (['variants', 'variations', 'product_variants', 'variant_options'] as $key) {
        foreach ((array)($product[$key] ?? []) as $variant) {
            if (!is_array($variant)) continue;
            $color = cezma_variant_text($variant['color'] ?? $variant['colour'] ?? $variant['color_name'] ?? $variant['color_ar'] ?? '');
            $size = cezma_variant_text($variant['size'] ?? $variant['size_name'] ?? $variant['size_ar'] ?? '');
            if ($color !== '' && !in_array($color, $colors, true)) $colors[] = $color;
            if ($size !== '' && !in_array($size, $sizes, true)) $sizes[] = $size;
            $image_urls = []; cezma_collect_image_urls($variant, $image_urls);
            if ($color !== '' || $size !== '') $variants[] = ['color' => $color, 'size' => $size, 'price' => $variant['price'] ?? '', 'image' => $image_urls[0] ?? ''];
        }
    }
    return ['colors' => array_values(array_unique($colors)), 'sizes' => array_values(array_unique($sizes)), 'variants' => $variants];
}

// إغناء منتج سيزما بالتفاصيل الكاملة (كل الصور + الوصف + المواصفات) من API سيزما،
// مع كاش على الاستضافة data/cezma_details/<id>.json عشان تتجاب مرة واحدة بس.
function cezma_enrich_record($row) {
    $cid = (int)($row['extra']['cezma_id'] ?? $row['orig_id'] ?? 0);
    if ($cid <= 0) return $row;
    if (cezma_is_unavailable_id($cid)) return null;

    $cache_dir = DATA_DIR . '/cezma_details';
    $cache_file = $cache_dir . '/' . $cid . '.json';
    $det = null;
    if (is_file($cache_file)) $det = json_decode((string)@file_get_contents($cache_file), true);
    if (is_array($det) && cezma_contains_unavailable_notice($det)) {
        cezma_mark_unavailable_id($cid);
        return null;
    }
    // Refresh old detail-cache records once so cached items gain the full image and variant metadata.
    if (!is_array($det) || (int)($det['schema_version'] ?? 0) < 2) {
        $ch = curl_init('https://api.cezma.cloud/api/products/' . $cid);
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Platform: web', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = $raw ? json_decode($raw, true) : null;
        if (is_array($j) && cezma_contains_unavailable_notice($j)) {
            cezma_mark_unavailable_id($cid);
            return null;
        }
        $prod = $j['data']['product'] ?? (isset($j['data']['id']) ? $j['data'] : null);
        if (is_array($prod)) {
            if (cezma_contains_unavailable_notice($prod)) {
                cezma_mark_unavailable_id($cid);
                return null;
            }
            $imgs = [];
            foreach (['images', 'product_images', 'gallery', 'photos', 'image', 'variants', 'variations', 'product_variants'] as $image_field) {
                if (isset($prod[$image_field])) cezma_collect_image_urls($prod[$image_field], $imgs);
            }
            $imgs = clean_product_images($imgs);
            $feats = []; $colors = []; $sizes = [];
            foreach (($prod['attributes'] ?? []) as $at) {
                if (!is_array($at)) continue;
                $pn = cezma_variant_text($at['parent'] ?? $at['attribute'] ?? []);
                $nm = cezma_variant_text($at['value'] ?? $at['value_ar'] ?? $at['name_ar'] ?? $at['label'] ?? '');
                if ($nm !== '') $feats[] = ($pn !== '' ? $pn . ': ' : '') . $nm;
            }
            $options = cezma_extract_variant_options($prod);
            $uname = $prod['store']['username'] ?? '';
            $det = ['schema_version' => 2, 'desc' => (string)($prod['description_ar'] ?? ''), 'images' => $imgs,
                'features' => implode("\n", array_values(array_unique($feats))), 'colors' => $options['colors'],
                'sizes' => $options['sizes'], 'variants' => $options['variants'],
                'seller' => (string)($prod['store']['name'] ?? ''),
                'source_url' => $uname !== '' ? 'https://cezma.com/products/' . $uname . '/' . $cid : '',
                'date' => (string)($prod['date'] ?? '')];
            if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
            @file_put_contents($cache_file, json_encode($det, JSON_UNESCAPED_UNICODE));
        } elseif ($code === 404) {
            // A true 404 is an unavailable product; transient network/5xx failures are not.
            cezma_mark_unavailable_id($cid);
            return null;
        }
    }
    if (is_array($det)) {
        if (cezma_contains_unavailable_notice($det)) {
            cezma_mark_unavailable_id($cid);
            return null;
        }
        if (!empty($det['desc'])) $row['desc'] = $det['desc'];
        if (!empty($det['images'])) $row['images'] = clean_product_images(array_merge((array)($row['images'] ?? []), (array)$det['images']));
        if (!empty($det['seller'])) $row['seller'] = $det['seller'];
        if (!empty($det['sizes'])) $row['sizes'] = array_values(array_unique(array_merge((array)($row['sizes'] ?? []), (array)$det['sizes'])));
        $row['extra']['features'] = $det['features'] ?? '';
        $row['extra']['colors'] = array_values(array_unique(array_merge((array)($row['extra']['colors'] ?? []), (array)($det['colors'] ?? []))));
        if (!empty($det['variants'])) $row['extra']['variants_detailed'] = $det['variants'];
        $row['extra']['source_url'] = $det['source_url'] ?? '';
        $row['extra']['added_at'] = $det['date'] ?? '';
    }
    return $row;
}

function wf_catalog_raw($plat) {
    static $cache = [];
    $plat = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$plat));
    if ($plat === '') return '';
    if (array_key_exists($plat, $cache)) return $cache[$plat];
    $raw = '';
    if ($plat === 'cezma') {
        $sh = cezma_shard_files();
        return $cache[$plat] = $sh ? (string)@file_get_contents($sh[0]) : '';
    }
    foreach ([$plat . '.json', 'lite_' . $plat . '.json'] as $fn) {
        $path = DATA_DIR . '/' . $fn;
        if (is_readable($path) && (int)@filesize($path) > 4) { $raw = (string)@file_get_contents($path); break; }
    }
    return $cache[$plat] = $raw;
}

// قص كائن JSON واحد يبدأ من الموضع $start (متوازن الأقواس مع احترام النصوص)
function wf_json_object_slice($raw, $start) {
    $len = strlen($raw);
    if ($start < 0 || $start >= $len || $raw[$start] !== '{') return '';
    $depth = 0; $in_str = false; $esc = false;
    for ($i = $start; $i < $len; $i++) {
        $ch = $raw[$i];
        if ($in_str) {
            if ($esc) { $esc = false; continue; }
            if ($ch === '\\') { $esc = true; continue; }
            if ($ch === '"') $in_str = false;
            continue;
        }
        if ($ch === '"') { $in_str = true; continue; }
        if ($ch === '{' || $ch === '[') { $depth++; continue; }
        if ($ch === '}' || $ch === ']') { $depth--; if ($depth <= 0) return substr($raw, $start, $i - $start + 1); }
    }
    return '';
}

// استخراج سجل منتج واحد من نص كتالوج منصة (بالـ id أو بالـ orig_id)
function wf_catalog_find_record($plat, $id) {
    if ($plat === 'manual') return manual_product_find($id);
    if ($plat === 'cezma') {
        // Fast path: id-hash buckets (data/cezma_buckets/b_<md5(id)[:2]>.json).
        // One small read per lookup instead of scanning all shards (slow host disk).
        $cands = [];
        $v = trim((string)$id);
        if ($v !== '') $cands[] = $v;
        if (preg_match('/^(?:cezma-)?(\d+)$/', $v, $mm)) {
            $cands[] = 'cezma-' . $mm[1];
            $cands[] = $mm[1];
        }
        $cands = array_values(array_unique(array_filter($cands, function ($x) { return $x !== ''; })));
        foreach ($cands as $c) {
            $rel = 'cezma_buckets/b_' . md5($c) . '.json';
            if (!is_readable(DATA_DIR . '/' . $rel)) continue;
            $bdata = jload($rel, [], false);
            if (isset($bdata[$c]) && is_array($bdata[$c])) {
                unset($GLOBALS['__cezma_raw_override']);
                return $bdata[$c];
            }
        }
        // Fallback: scan shards (per-request raw-shard cache) for records not in buckets.
        static $shard_cache = [];
        foreach (cezma_shard_files() as $f) {
            if (!array_key_exists($f, $shard_cache)) {
                $shard_cache[$f] = (string)@file_get_contents($f);
            }
            $GLOBALS['__cezma_raw_override'] = $shard_cache[$f];
            $r = wf_catalog_find_record_raw($plat, $id);
            if ($r !== null) { unset($GLOBALS['__cezma_raw_override']); return $r; }
        }
        unset($GLOBALS['__cezma_raw_override']);
        return null;
    }
    return wf_catalog_find_record_raw($plat, $id);
}
function wf_catalog_find_record_raw($plat, $id) {
    $raw = $GLOBALS['__cezma_raw_override'] ?? wf_catalog_raw($plat);
    $id = trim((string)$id);
    if ($raw === '' || $id === '') return null;
    $needle = '"' . $id . '"';
    $rlen = strlen($raw);
    $pos = 0;
    while (($pos = strpos($raw, $needle, $pos)) !== false) {
        $head = substr($raw, max(0, $pos - 24), $pos - max(0, $pos - 24));
        if (preg_match('/\{\s*"id"\s*:\s*$/', trim($head))) {
            $seg = wf_json_object_slice($raw, (int)strrpos($raw, '{', $pos - $rlen));
            if ($seg !== '') {
                $row = json_decode($seg, true);
                if (is_array($row) && trim((string)($row['id'] ?? '')) === $id) return $row;
                if (is_array($row) && trim((string)($row['orig_id'] ?? '')) === $id) return $row;
            }
        }
        $pos += strlen($needle);
    }
    // مطابقة المعرّف الأصلي (orig_id) لو مختلف عن id
    $pos = 0;
    while (($pos = strpos($raw, $needle, $pos)) !== false) {
        $head = substr($raw, max(0, $pos - 40), $pos - max(0, $pos - 40));
        if (preg_match('/"orig_id"\s*:\s*$/', trim($head))) {
            $start = (int)strrpos($raw, '{', $pos - $rlen);
            $seg = wf_json_object_slice($raw, $start);
            if ($seg !== '') {
                $row = json_decode($seg, true);
                if (is_array($row) && trim((string)($row['orig_id'] ?? '')) === $id) return $row;
            }
        }
        $pos += strlen($needle);
    }
    return null;
}

// بحث سريع وموثوق عن منتج واحد بالـ ID — ذاكرة محصورة (سجل واحد) وبدون فك الكتالوج كله
function find_product_by_id($id, $plat_hint = '') {
    static $memo = [];
    $id = trim((string)$id);
    if ($id === '') return null;
    $id_dec = trim(rawurldecode($id));
    $mkey = $id_dec . '|' . trim((string)$plat_hint);
    if (array_key_exists($mkey, $memo)) return $memo[$mkey];

    $plat = resolve_plat($id_dec !== '' ? $id_dec : $id, $plat_hint);
    $tries = array_values(array_unique(array_filter([$id_dec, $id], function ($v) { return $v !== ''; })));

    // ترتيب المنصات: المنصة المستنتجة/المُمرَّرة → المنصة المعروفة من الفهرس → باقي المنصات
    $order = [];
    if ($plat !== '') $order[] = $plat;
    $idx = jload('id_index.json', []);
    foreach ($tries as $try) {
        $by = trim((string)($idx[$try] ?? ''));
        if ($by !== '' && !in_array($by, $order, true)) $order[] = $by;
    }
    foreach (wf_catalog_plats() as $pl) if (!in_array($pl, $order, true)) $order[] = $pl;

    foreach ($order as $pl) {
        foreach ($tries as $try) {
            $row = wf_catalog_find_record($pl, $try);
            if (is_array($row)) {
                if ($pl === 'cezma') {
                    $row = cezma_enrich_record($row);
                    if (!is_array($row)) return $memo[$mkey] = null;
                    // نفس توحيد الأقسام اللي بيحصل في قوائم المنتجات (صفحة المنتج بتقرا السجل الخام)
                    $row['category'] = wf_cezma_site_category($row['category'] ?? '', $row['name'] ?? '');
                }
                $row = normalize_product_record(with_detail_imgs($row, $pl), $pl);
                $row = apply_product_override_record($row, $pl);
                if (!is_array($row)) return $memo[$mkey] = null;
                return $memo[$mkey] = $row;
            }
        }
    }
    return $memo[$mkey] = null;
}

// خلط المنتجات بالتناوب (Round-Robin): منتج من تايم هاند → صفقة → فيندور → ليدجر → أنجزني
function mix_by_platform($items, $order = ['timehand', 'safqa', 'vendoor', 'angazny', 'm5zoon', 'shopmart', 'cezma', 'manual']) {
    $groups = [];
    foreach ($order as $ok) $groups[$ok] = [];
    foreach ($items as $it) {
        $pl = $it['_plat'] ?? '_';
        $groups[$pl][] = $it;
    }
    $out = [];
    $i = 0;
    $more = true;
    while ($more) {
        $more = false;
        foreach ($groups as $arr) {
            if (isset($arr[$i])) {
                $out[] = $arr[$i];
                $more = true;
            }
        }
        $i++;
    }
    return $out;
}

// بناء القوائم والأقسام الخاصة (كل المنتجات / أحدث المنتجات / الأكثر مبيعاً / الأكثر مشاهدة / المنتجات المميزة)
// بحيث يحتوي كل قسم على منتج من كل أفلييت بجانب الآخر بالتناوب
function physical_collection($type = 'all', $country = 'all', $cat = 'all', $q = '', $plat_filter = 'all', $shuffle_seed = null, $source_limit = null) {
    wfp('physCOL start ' . $type . '/' . $country . '/' . $cat . '/' . substr((string)$q, 0, 20));
    $gulf = wf_gulf_countries();
    if ($plat_filter !== '' && $plat_filter !== 'all') {
        $plats = [$plat_filter];
    } elseif (in_array($country, $gulf, true)) {
        // الخليج: مخزون + المنتجات اليدوية (المنتج اليدوي بيحدد دولته وعملته بنفسه)
        $plats = ['m5zoon', 'manual'];
    } else {
        // الترتيب المطلوب بالضبط: تايم هاند → صفقة → فيندور → ليدجر → أنجزني → شوب مارت → سيزما
        $plats = ['timehand', 'safqa', 'vendoor', 'angazny', 'shopmart', 'cezma', 'manual'];
    }
    $views = jload('views.json', []);
    static $soldmap_memo = null;
    if ($soldmap_memo === null) {
        $soldmap_memo = [];
        foreach (jload('orders.json', []) as $o) {
            $k = ($o['plat'] ?? '') . '#' . ($o['id'] ?? '');
            $soldmap_memo[$k] = ($soldmap_memo[$k] ?? 0) + ($o['qty'] ?? 1);
        }
    }
    $soldmap = $soldmap_memo;

    $by_plat = [];
    foreach ($plats as $pk) {
        // البحث والتصنيفات تستخدم فهرس سيزما عند صلاحيته، وإلا فالمسار الكامل الأصلي.
        // الصفحة الرئيسية تظل تقرأ نافذة أحدث فقط لتقليل الذاكرة.
        $cezma_indexed = null;
        if ($pk === 'cezma' && ($q !== '' || ($cat !== '' && $cat !== 'all' && !in_array($cat, ['المنتجات المميزة', 'أحدث المنتجات', 'الأكثر مبيعاً', 'الأكثر مشاهدة'], true)))) {
            $cezma_indexed = cezma_indexed_products($cat, $q);
        }
        $use_home_window = ($source_limit !== null && $q === '' && $pk !== 'manual' && ((int)$source_limit + 100) <= 900);
        if ($cezma_indexed !== null) {
            $arr = $cezma_indexed;
        } elseif ($pk === 'cezma' && ($source_limit !== null ||
            // المتجر الافتراضي و«المنتجات المميزة» بلا فلاتر: نافذة محدودة من الأحدث بدل تطييع 43 ألف منتج في كل طلب.
            // «أحدث المنتجات» و«الأكثر مبيعاً/مشاهدة» تظل بالمسار الكامل لأنها صارت مرتبة على الكتالوج كاملاً.
            ($q === '' && in_array($cat, ['', 'all', 'المنتجات المميزة'], true)))) {
            $arr = $use_home_window
                ? wf_home_platform_window('cezma', (int)$source_limit + 100)
                : cezma_recent_products($source_limit !== null ? max(1, (int)$source_limit + 100) : 3000);
        } else {
            $arr = $use_home_window
                ? wf_home_platform_window($pk, (int)$source_limit + 100)
                : platform_products_lite($pk);
        }
        if ($source_limit !== null && $pk !== 'cezma' && count($arr) > (int)$source_limit + 100) {
            // الأقسام الأفقية ولوحة المنتجات الافتراضية تحتاج مرشحين حديثين فقط؛
            // نحدّ القائمة قبل الفلاتر والفرز المكلفين بدل فرز الكتالوج كاملًا.
            $arr = array_slice($arr, -((int)$source_limit + 100));
        }
        if ($country !== 'all') {
            $arr = array_values(array_filter($arr, function ($x) use ($country) {
                return ($x['country'] ?? 'مصر') === $country;
            }));
        }
        if ($cat !== 'all' && $cat !== '' && !in_array($cat, ['المنتجات المميزة', 'أحدث المنتجات', 'الأكثر مبيعاً', 'الأكثر مشاهدة'], true)) {
            // مقارنة بالقسم الموحّد (نفس اللي في الرابط) عشان تظهر منتجات كل المنصات جنب بعضها
            $arr = array_values(array_filter($arr, function ($x) use ($cat) {
                return wf_normalize_category($x['category'] ?? '') === $cat
                    || wf_normalize_category($x['subcategory'] ?? '', '', false) === $cat;
            }));
        }
        if ($q !== '') {
            $arr = array_values(array_filter($arr, function ($x) use ($q) {
                return mb_stripos(($x['name'] ?? '') . ' ' . ($x['sku'] ?? ''), $q) !== false;
            }));
        } else {
            // استبعاد المنتجات التي لا تملك أي صورة صالحة من العرض العام في المتجر
            $arr = array_values(array_filter($arr, function ($x) {
                return !empty($x['images']);
            }));
        }
        $n = count($arr);
        if ($n === 0) continue;

        if ($type === 'new' || $cat === 'أحدث المنتجات') {
            // أحدث المنتجات من كل منصة
            $arr = array_reverse($arr);
        } elseif ($type === 'best' || $cat === 'الأكثر مبيعاً') {
            // الأكثر مبيعاً من كل منصة (مع تدوير لضمان تنوع المنتجات عن بداية القائمة)
            $off = max(1, (int)floor($n * 0.2));
            $rotated = array_merge(array_slice($arr, $off), array_slice($arr, 0, $off));
            usort($rotated, function ($a, $b) use ($soldmap, $pk) {
                $sa = $soldmap[$pk . '#' . ($a['id'] ?? '')] ?? 0;
                $sb = $soldmap[$pk . '#' . ($b['id'] ?? '')] ?? 0;
                if ($sa !== $sb) return $sb <=> $sa;
                return 0;
            });
            $arr = $rotated;
        } elseif ($type === 'viewed' || $cat === 'الأكثر مشاهدة') {
            // الأكثر مشاهدة من كل منصة
            $off = max(1, (int)floor($n * 0.4));
            $rotated = array_merge(array_slice($arr, $off), array_slice($arr, 0, $off));
            usort($rotated, function ($a, $b) use ($views, $pk) {
                $va = $views[$pk . '#' . ($a['id'] ?? '')] ?? 0;
                $vb = $views[$pk . '#' . ($b['id'] ?? '')] ?? 0;
                if ($va !== $vb) return $vb <=> $va;
                return 0;
            });
            $arr = $rotated;
        } elseif ($type === 'featured' || $cat === 'المنتجات المميزة') {
            // المنتجات المميزة من كل منصة (الأعلى عمولة وتقييم وصور)
            $off = max(1, (int)floor($n * 0.6));
            $rotated = array_merge(array_slice($arr, $off), array_slice($arr, 0, $off));
            usort($rotated, function ($a, $b) {
                $ca = mz_num($a['commission'] ?? 0);
                $cb = mz_num($b['commission'] ?? 0);
                if ($ca !== $cb) return $cb <=> $ca;
                return count($b['images'] ?? []) <=> count($a['images'] ?? []);
            });
            $arr = $rotated;
        }
        // تنويع كل زيارة: نخلط أول 40 منتج (الأحدث/الأفضل) من كل أفلييت بنفس البذرة للزيارة دي
        if ($shuffle_seed !== null && count($arr) > 1) {
            // الكتالوج بيضيف الجديد في الآخر، فنقلب الترتيب الافتراضي عشان أول المنتجات تكون الأحدث
            if ($type === 'all') $arr = array_reverse($arr);
            $pool = min(40, count($arr));
            $top = array_slice($arr, 0, $pool);
            mt_srand((int)$shuffle_seed + (int)crc32((string)$pk));
            shuffle($top);
            $arr = array_merge($top, array_slice($arr, $pool));
        }
        if ($source_limit !== null) $arr = array_slice($arr, 0, max(1, (int)$source_limit));
        // منتجات اليدوي على الرئيسية: متظهرش في كل زيارة — تقريبًا زيارة من كل 3، وبحد أقصى منتجين.
        // الصفوف الأفقية وصفحات «التالي» من غير منتجات يدوي، لكن الأقسام والبحث وأحدث المنتجات بتعرضهم عادي.
        if ($pk === 'manual' && $source_limit !== null && $shuffle_seed !== null) {
            $home_page = isset($GLOBALS['wf_home_page']) ? (int)$GLOBALS['wf_home_page'] : 1;
            $home_show = ($type === 'all' && $home_page <= 1 && (((int)$shuffle_seed % 3) === 0));
            if ($home_show) {
                $arr = array_slice($arr, 0, 1 + ((int)floor($shuffle_seed / 3) % 2));
            } else {
                $arr = [];
            }
            if ($type === 'all') $GLOBALS['wf_home_manual_shown'] = count($arr);
        }
        $by_plat[$pk] = $arr;
        wfp('physCOL plat ' . $pk . ': ' . count($arr));
    }

    // دمج بالتناوب (منتج من كل منصة جنب بعضه)
    $flat = [];
    foreach ($by_plat as $pk => $arr) {
        foreach ($arr as $it) $flat[] = $it;
    }
    $out = mix_by_platform($flat, $plats);
    wfp('physCOL end: ' . count($out));
    return $out;
}

// ===== الدول والعملات: قايمة واحدة لكل الموقع =====
// نفس الدول اللي في فلاتر الدولة (الرئيسية / الكتالوج / لوحة المسوّق)، وكل دولة بعملتها المحلية.
// المنتجات اليدوية بتختار الدولة من القايمة دي، والسعر بيتسجّل وبيتعرض بعملة الدولة نفسها
// (مفيش تحويل عملات — نفس قاعدة باقي المنصات: المبلغ بعملة منتجه).
function wf_country_currencies() {
    static $map = null;
    // الترتيب ده هو نفس ترتيب تبويبات الدول في الرئيسية والكتالوج ولوحة المسوّق
    if ($map === null) $map = ['مصر' => 'ج.م', 'الإمارات' => 'د.إ', 'السعودية' => 'ر.س', 'الأردن' => 'د.أ', 'عُمان' => 'ر.ع'];
    return $map;
}
function wf_country_list() {
    return array_keys(wf_country_currencies());
}
// دول الخليج/المصادر العربية (كانت مكتوبة باليد في أكتر من مكان)
function wf_gulf_countries() {
    static $gulf = null;
    if ($gulf === null) $gulf = ['الإمارات', 'السعودية', 'الأردن', 'عُمان'];
    return $gulf;
}
function wf_is_gulf_country($country) {
    return in_array(wf_match_country($country, ''), wf_gulf_countries(), true);
}
// مفتاح مقارنة: من غير تشكيل ولا تطويل، أ/إ/آ = ا، ة = ه، ى = ي، ومسافة واحدة
function wf_country_key($value) {
    $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}\x{0670}]/u', '', (string)$value);
    $s = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $s);
    $s = str_replace(['ة', 'ى', 'ؤ'], ['ه', 'ي', 'و'], $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return mb_strtolower($s, 'UTF-8');
}
// تطبيع اسم الدولة: يقبل الاسم الرسمي، اسم شائع/مختصر/إنجليزي، أو اسم العملة (ريال/درهم/دينار).
// بيرجع اسم الدولة من القايمة الموحّدة، أو $fallback لو القيمة مش معروفة.
function wf_match_country($value, $fallback = '') {
    $raw = trim((string)$value);
    if ($raw === '') return (string)$fallback;
    // رموز العملة بالنقط لازم تتطابق على النص الأصلي: بعد توحيد الحروف (إ/أ => ا) بيصبح
    // «د.إ» (الإمارات) و«د.أ» (الأردن) نفس المفتاح — فبنقارنها قبل أي توحيد.
    $compact = preg_replace('/\s+/u', '', $raw);
    foreach (wf_country_currencies() as $cn => $cur) {
        if ($raw === $cur || $compact === $cur) return $cn;
    }
    static $aliases = null;
    if ($aliases === null) {
        $aliases = [];
        $table = [
            'مصر' => ['مصر', 'مصريه', 'جمهوريه مصر العربيه', 'مصر العربيه', 'egypt', 'eg', 'egp', 'جنيه', 'جنيه مصري'],
            'السعودية' => ['السعوديه', 'سعودي', 'المملكه العربيه السعوديه', 'ksa', 'saudi', 'saudi arabia', 'sa', 'sar', 'ريال', 'ريال سعودي'],
            'الإمارات' => ['الامارات', 'امارات', 'الامارات العربيه المتحده', 'uae', 'emirates', 'dubai', 'ae', 'aed', 'درهم', 'درهم اماراتي'],
            'الأردن' => ['الاردن', 'اردن', 'المملكه الاردنيه الهاشميه', 'jordan', 'jo', 'jod', 'دينار', 'دينار اردني'],
            'عُمان' => ['عمان', 'سلطنه عمان', 'oman', 'om', 'omr', 'ريال عماني'],
        ];
        foreach ($table as $country => $names) {
            foreach ($names as $name) $aliases[wf_country_key($name)] = $country;
        }
    }
    $key = wf_country_key($raw);
    return isset($aliases[$key]) ? $aliases[$key] : (string)$fallback;
}
// عملة الدولة (ج.م / ر.س / د.إ / د.أ / ر.ع) — من اسم الدولة أو من اسم العملة نفسه
function wf_country_currency($country, $fallback = 'ج.م') {
    $map = wf_country_currencies();
    $c = wf_match_country($country, '');
    return ($c !== '' && isset($map[$c])) ? $map[$c] : (string)$fallback;
}

// إرجاع التصنيفات الخاصة بكل نوع منفصلاً (فيزيكال فقط / رقمي فقط / سوشيال فقط)
// ===== توحيد أسماء الأقسام (الكاتيجوري) في كل الصفحات =====
// الهدف: لا تتكرر نفس الباقة بصيغتين (مثال: «منتجات رقمية» و«المنتجات الرقمية») ولا تظهر أقسام مكتوبة بطرق مختلفة.
function wf_category_canonical() {
    static $canon = null;
    if ($canon === null) {
        $canon = ['منتجات رقمية', 'سوشيال ميديا', 'أحذية', 'ملابس رجالي', 'ملابس حريمي', 'إكسسوارات',
            'المنزل والمطبخ', 'العناية والتجميل', 'إلكترونيات', 'ساعات', 'أطفال وألعاب', 'عدد وأدوات',
            'مستلزمات السيارات', 'الرياضة واللياقة', 'شنط ومحافظ'];
    }
    return $canon;
}
function wf_category_synonyms() {
    static $syn = null;
    if ($syn !== null) return $syn;
    $syn = [
        'المنتجات الرقمية' => 'منتجات رقمية', 'منتجات رقميه' => 'منتجات رقمية', 'منتج رقمي' => 'منتجات رقمية',
        'رقميات' => 'منتجات رقمية', 'خدمات رقمية' => 'منتجات رقمية',
        'سوشال ميديا' => 'سوشيال ميديا', 'سوشيال' => 'سوشيال ميديا', 'خدمات سوشيال ميديا' => 'سوشيال ميديا',
        'سوشيال ميديا وخدمات' => 'سوشيال ميديا', 'منتجات سوشيال' => 'سوشيال ميديا',
        'احذية' => 'أحذية', 'أحذيه' => 'أحذية', 'جزم' => 'أحذية', 'احذيه' => 'أحذية', 'أحذية رجالي' => 'أحذية', 'أحذية حريمي' => 'أحذية',
        'ملابس رجال' => 'ملابس رجالي', 'ملابس رجالية' => 'ملابس رجالي', 'رجالي' => 'ملابس رجالي', 'ملابس رجالى' => 'ملابس رجالي',
        'ملابس نسائية' => 'ملابس حريمي', 'ملابس نسائى' => 'ملابس حريمي', 'ملابس حريمى' => 'ملابس حريمي', 'نسائي' => 'ملابس حريمي', 'حريمي' => 'ملابس حريمي', 'ملابس بناتي' => 'ملابس حريمي',
        'اكسسوارات' => 'إكسسوارات', 'إكسسوار' => 'إكسسوارات', 'اكسسوار' => 'إكسسوارات', 'مجوهرات' => 'إكسسوارات',
        'اجهزة' => 'إلكترونيات', 'الالكترونيات' => 'إلكترونيات', 'الكترونيات' => 'إلكترونيات', 'إلكترونيات وموبايلات' => 'إلكترونيات', 'موبايلات' => 'إلكترونيات', 'هواتف' => 'إلكترونيات', 'إلكترونيات وكمبيوتر' => 'إلكترونيات',
        'المنزل' => 'المنزل والمطبخ', 'مطبخ' => 'المنزل والمطبخ', 'أدوات منزلية' => 'المنزل والمطبخ', 'المنزل و المطبخ' => 'المنزل والمطبخ', 'ديكور' => 'المنزل والمطبخ', 'مستلزمات المنزل' => 'المنزل والمطبخ',
        'العناية' => 'العناية والتجميل', 'التجميل' => 'العناية والتجميل', 'العناية الشخصية' => 'العناية والتجميل', 'عطور' => 'العناية والتجميل', 'عناية وتجميل' => 'العناية والتجميل',
        'اطفال' => 'أطفال وألعاب', 'ألعاب' => 'أطفال وألعاب', 'مستلزمات أطفال' => 'أطفال وألعاب', 'أطفال' => 'أطفال وألعاب',
        'أدوات' => 'عدد وأدوات', 'عدة وأدوات' => 'عدد وأدوات', 'عدد و أدوات' => 'عدد وأدوات', 'معدات' => 'عدد وأدوات',
        'سيارات' => 'مستلزمات السيارات', 'السيارات' => 'مستلزمات السيارات', 'مستلزمات سيارات' => 'مستلزمات السيارات',
        'رياضة' => 'الرياضة واللياقة', 'لياقة' => 'الرياضة واللياقة', 'رياضة ولياقة' => 'الرياضة واللياقة',
        'شنط' => 'شنط ومحافظ', 'حقائب' => 'شنط ومحافظ', 'محافظ' => 'شنط ومحافظ', 'شنط وحقائب' => 'شنط ومحافظ',
        'ساعة' => 'ساعات', 'ساعات ذكية' => 'ساعات', 'ساعات رجالي' => 'ساعات', 'ساعات حريمي' => 'ساعات',
    ];
    // أقسام سيزما الرئيسية (الموضة والجمال / سوبر ماركت / الكمبيوتر...) بتتوحّد مع أقسام الموقع
    foreach (wf_cezma_bucket_map() as $czk => $czv) {
        if ($czv !== '') $syn[$czk] = $czv;
    }
    return $syn;
}
// ===== سيزما: توحيد أقسام الكتالوج مع أقسام الموقع =====
// أقسام سيزما الرئيسية مالهاش مقابل مباشر في الموقع، فبنترجمها لأقسام الموقع الموحّدة.
// «الموضة والجمال» لوحدها ~48% من كتالوج سيزما (ملابس/أحذية/شنط/ساعات/عطور...) ومالهاش
// قسم واحد مقابل، فبنفصّلها بالاسم على أقسام الموقع (wf_cezma_name_rules).
// ملاحظة: نفس الخريطة والقواعد (بنفس الترتيب) موجودة في المحدّث updater/update_catalog.py
// (CEZMA_CAT_MAP / CEZMA_CAT_RULES) — أي تعديل هنا لازم يتعمل هناك كمان.
function wf_cezma_bucket_map() {
    static $map = null;
    if ($map !== null) return $map;
    $map = [
        'الموضة والجمال' => '',                      // متعدد الأقسام → يُفصَّل بالاسم
        'ادوات ومستلزمات المنزل والحديقة' => 'المنزل والمطبخ',
        'أجهزة منزلية' => 'المنزل والمطبخ',
        'اثاث و ديكور' => 'المنزل والمطبخ',
        'الاضاءة والمصابيح' => 'المنزل والمطبخ',
        'سوبر ماركت' => 'المنزل والمطبخ',
        'مستلزمات الأطفال' => 'أطفال وألعاب',
        'مكتبات و أدوات مدرسية' => 'أطفال وألعاب',
        'كتب وروايات' => 'أطفال وألعاب',
        'اجهزة ومستلزمات طبية' => 'العناية والتجميل',
        'موبايل و تابلت و ملحقاتها' => 'إلكترونيات',
        'أجهزة الكترونية' => 'إلكترونيات',
        'الكمبيوتر' => 'إلكترونيات',
        'الكاميرات واكسسواراتها' => 'إلكترونيات',
        'الهدايا و المشغولات اليدوية ومستلزماتها' => 'إكسسوارات',
        'الرياضة والتنزة' => 'الرياضة واللياقة',
        'المعدات واللوازم الكهربائية' => 'عدد وأدوات',
    ];
    return $map;
}
// قسم الباقي من «الموضة والجمال» (منتجات مفيش كلمة مفتاحية تحدد قسمها)
function wf_cezma_fashion_fallback() { return 'ملابس حريمي'; }
// قواعد تفصيل «الموضة والجمال» — أول قسم يتطابق مع اسم المنتج هو اللي يكسب
function wf_cezma_name_rules() {
    static $rules = null;
    if ($rules !== null) return $rules;
    $rules = [
        ['ملابس رجالي', ['الرجالي', 'الرجالى', 'رجالي', 'رجالى', ]],
        ['إكسسوارات', ['نظارات', 'نظارة', 'نظاره', 'نضارات', 'نضارة', 'نضاره', ]],
        ['أحذية', ['بوط', 'بوت', 'sambaa', 'samba', 'حذاء', 'احذيه', 'احذية', 'كوتش', 'كوتشي', 'سنيكر', 'صندل', 'شبشب', 'سليبر', 'كروكس', 'شوز', 'هاف', 'جزمة', 'جزمه', 'ستارز', 'ستارس', 'ترتان', 'ترتاتي', 'ترتين', 'boot', 'boots', 'shoes', 'shoe', 'sneaker', 'sandal', 'slipper', 'crocs']],
        ['شنط ومحافظ', ['شنطة', 'شنطه', 'شنط', 'حقيبه', 'حقائب', 'محفظة', 'محفظه', 'باك باك', 'ظهر', 'bag', 'bags', 'wallet', 'backpack', 'handbag', 'tote', 'purse']],
        ['ساعات', ['ساعة', 'ساعه', 'ساعات', 'واتش', 'watch', 'watches', 'wrist']],
        ['مستلزمات السيارات', ['سيارة', 'سياره', 'عربية', 'عربيه', 'كاوتش', 'منفاخ', 'وايبر', 'شماسة', 'دشكام', 'محرك', 'car', 'cars', 'tire', 'tyre', 'wiper', 'dashcam']],
        ['ملابس حريمي', ['دفايه', 'عبايات', 'سوت', 'الحريمي', 'الحريمى', 'لانجريهات', 'لانجري', 'لانجيري', 'شروال', 'مايوه', 'بوركيني', 'بيجامه', 'بيجامات', 'مخمريه', 'بلوز', 'بلاوز', 'كاردي', 'مشد', 'ميني روب', 'جلبيه', 'تريكو', 'اسكيرت', 'دفايه', 'فرنسي', 'خمار', 'ملحفه', 'ملحفة', 'مروحه', 'حريمي', 'حريمى', 'نسائي', 'نسائى', 'فستان', 'فساتين', 'عباية', 'عبايه', 'اسدال', 'بلوزة', 'بلوزه', 'جيبة', 'جيبه', 'لانجيري', 'طرحة', 'طرحه', 'حجاب', 'نقاب', 'تونيك', 'تونك', 'بيجامة', 'بجامة', 'دريس', 'كشمير', 'بلوفر', 'بلوفرات', 'جامبسوت', 'ليجن', 'قفطان', 'dress', 'dresses', 'woman', 'women', 'ladies', 'skirt', 'blouse', 'abaya', 'lingerie', 'hijab', 'nightgown']],
        ['ملابس رجالي', ['رجالي', 'رجالى', 'تيشيرت', 'تيشرت', 'تشيرت', 'قميص', 'قمصان', 'بنطلون', 'بنطال', 'جاكيت', 'بدلة', 'بدله', 'ترنج', 'ترينج', 'شورت', 'بوكسر', 'جلابية', 'جلابيه', 'سويت شيرت', 'سويتشرت', 'سويتشيرت', 'هودي', 'فانلة', 'بالطو', 'جينز', 'كارديجان', 'بدي', 'shirt', 'tshirt', 't-shirt', 'pants', 'trouser', 'trousers', 'jacket', 'men', 'mens', 'hoodie', 'jeans', 'sweatshirt', 'polo', 'suit']],
        ['العناية والتجميل', ['ميكاب', 'ميكب', 'كوبي', 'كوبى', 'عطر', 'عطور', 'بارفان', 'برفان', 'برفيوم', 'كولونيا', 'بودي', 'body', 'ميست', 'مزيل', 'ازاله عرق', 'رول اون', 'ايشادو', 'بلاشر', 'بلشر', 'ايلاينر', 'ليب', 'شفاه', 'مكياج', 'مقشر', 'سكراب', 'ماسك', 'تونر', 'سيروم', 'بودره', 'صبغه', 'باديكير', 'مانيكير', 'اظافر', 'الاظافر', 'شامبو', 'بلسم', 'ازاله الشعر', 'لازاله الشعر', 'حلاقه', 'ديرما', 'ليفه', 'سبراي', 'توكه', 'كونتور', 'اويل', 'كريم', 'لوشن', 'للبشره', 'البشره', 'للشعر', 'الشعر', 'الوجه', 'شاور جل', 'جل استحمام', 'جل شاور', 'بخاخ', 'خافي عيوب', 'شفايف', 'زيوت', 'delina', 'emilia', 'parfum', 'perfume', 'eau de', 'edp', 'edt', 'oil', 'dry oil', 'ميكاب', 'ميكب', 'كوبي', 'كوبى', 'كريم', 'سيرم', 'سيروم', 'شامبو', 'بلسم', 'عطر', 'برفان', 'برفن', 'برفيوم', 'اوديبرفان', 'اودوبرفان', 'مكياج', 'روج', 'ماسكارا', 'مشط', 'فرشاة اسنان', 'معجون', 'حلاقة', 'حلاقه', 'ماسك', 'مكواة شعر', 'مكواه شعر', 'استشوار', 'سشوار', 'مجفف شعر', 'بشرة', 'بشره', 'صابون', 'غسول', 'لوشن', 'مرطب', 'تفتيح', 'رموش', 'حواجب', 'اظافر', 'كحل', 'جلوس', 'اسكراب', 'بادكير', 'بخور', 'مسك', 'شعر', 'زيت', 'عناية', 'منظف', 'معقم', 'كولاجين', 'مزيل العرق', 'بدي سبلاش', 'اسبلاش', 'تجميل', 'حناء', 'حنه', 'perfume', 'parfum', 'cream', 'serum', 'shampoo', 'makeup', 'mascara', 'skincare', 'beauty', 'razor', 'body splash', 'hair']],
        ['أطفال وألعاب', ['اطفال', 'طفل', 'بيبي', 'رضاعة', 'رضاعه', 'حفاض', 'بازل', 'مكعبات', 'دمية', 'عروسة', 'لعبة', 'لعبه', 'العاب', 'استرولر', 'kids', 'baby', 'toy', 'toys', 'child']],
        ['الرياضة واللياقة', ['دمبل', 'رياضة', 'رياضه', 'لياقة', 'لياقه', 'جيم', 'مشاية', 'دراجة', 'دراجه', 'ترامبولين', 'تمرين', 'يوجا', 'تخييم', 'سباحه', 'كاراتيه', 'sport', 'sports', 'fitness', 'gym', 'yoga', 'dumbbell', 'bike', 'cycle', 'camping', 'swimming']],
        ['إلكترونيات', ['موبايل', 'تابلت', 'شاحن', 'سماعة', 'سماعه', 'كابل', 'باور بانك', 'كاميرا', 'بلوتوث', 'ميكروفون', 'ماوس', 'كيبورد', 'لابتوب', 'راوتر', 'فلاشة', 'فلاشه', 'شاشة', 'شاشه', 'تلفزيون', 'بروجكتر', 'ريموت', 'mobile', 'charger', 'cable', 'camera', 'bluetooth', 'headphone', 'headphones', 'phone', 'tablet', 'mouse', 'keyboard', 'laptop', 'router', 'speaker', 'usb', 'power bank']],
        ['إكسسوارات', ['ولاعه', 'ولاعة', 'سجائر', 'سجاير', 'سلسه', 'اساور', 'أساور', 'سوار', 'النظارات', 'النظارة', 'حرف', 'استانلس', 'ولاعه', 'ولاعة', 'سجائر', 'سجاير', 'نظارة', 'نظاره', 'سلسلة', 'سلسله', 'انسيال', 'خاتم', 'اسورة', 'اسوره', 'حلق', 'قبعة', 'قبعه', 'كاب', 'حزام', 'اكسسوار', 'عقد', 'شال', 'كوفية', 'ميدالية', 'سبحه', 'سبحة', 'كوليه', 'بروش', 'sunglass', 'sunglasses', 'glasses', 'necklace', 'ring', 'bracelet', 'earring', 'cap', 'hat', 'belt', 'jewelry', 'jewellery', 'keychain']],
        ['عدد وأدوات', ['مفك', 'مفاتيح', 'شنيور', 'منشار', 'دريل', 'كماشة', 'كماشه', 'زرجينة', 'شاكوش', 'بنسة', 'لحام', 'مسامير', 'عدة', 'معدات', 'ادوات', 'drill', 'wrench', 'screwdriver', 'hammer', 'saw', 'tool', 'tools']],
        ['المنزل والمطبخ', ['مطبخ', 'حلة', 'حله', 'طاسة', 'طاسه', 'قطاعة', 'قطاعه', 'مبشرة', 'مبشره', 'خلاط', 'عصارة', 'عصاره', 'مفرمة', 'مفرمه', 'كوب', 'سرير', 'مرتبة', 'مرتبه', 'سجادة', 'سجاده', 'ستارة', 'ستاره', 'وسادة', 'وساده', 'بطانية', 'منشفة', 'منشفه', 'ممسحة', 'ممسحه', 'مكنسة', 'مكنسه', 'لمبة', 'لمبه', 'مصباح', 'اضاءة', 'ديكور', 'منظم', 'ترمس', 'صينية', 'صينيه', 'دفاية', 'دفايه', 'مروحة', 'مروحه', 'غسالة', 'غساله', 'ثلاجة', 'ثلاجه', 'سخان', 'تكييف', 'فرن كهربا', 'فرن غاز', 'ميكروويف', 'طقم مطبخ', 'اواني', 'ملعقة', 'ملعقه', 'كوباية', 'خزانة', 'بوتاجاز', 'ملاءه', 'kitchen', 'home', 'curtain', 'pillow', 'blanket', 'towel', 'lamp', 'bed', 'mattress', 'sofa', 'chair', 'table', 'fridge', 'blender', 'pan', 'pot', 'cup', 'mug', 'organizer', 'decor', 'mop', 'vacuum']],
    ];
    return $rules;
}
// مفتاح مطابقة عربي/إنجليزي موحّد (بدون تشكيل وبتوحيد الهمزات والياء والتاء المربوطة)
function wf_cezma_ar_key($text) {
    $t = (string)$text;
    $t = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $t);
    if ($t === null) $t = (string)$text;
    $t = wf_category_plain($t);
    $t = str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ئ', 'ؤ'], ['ا', 'ا', 'ا', 'ي', 'ه', 'ي', 'و'], $t);
    return mb_strtolower($t, 'UTF-8');
}
// تجهيز القواعد مرة واحدة كتعبيرات نمطية (عربي: من بداية الكلمة مع السماح بلواحقها / إنجليزي: الكلمة كاملة)
function wf_cezma_name_patterns() {
    static $pats = null;
    if ($pats !== null) return $pats;
    $word = '[\x{0621}-\x{064A}\x{0660}-\x{0669}A-Za-z0-9]';
    $pats = [];
    foreach (wf_cezma_name_rules() as $rule) {
        $cat = $rule[0]; $list = [];
        foreach ($rule[1] as $kw) {
            $kk = wf_cezma_ar_key($kw);
            if ($kk === '') continue;
            $tail = preg_match('/^[a-z0-9]/', $kk) ? '(?![A-Za-z0-9])' : '';
            $re = '/(?<!' . $word . ')' . preg_quote($kk, '/') . $tail . '/u';
            if (@preg_match($re, '') === false) continue; // تعبير مش مدعوم في البيئة دي → نتخطاه
            $list[] = $re;
        }
        $pats[] = [$cat, $list];
    }
    return $pats;
}
// تخمين قسم الموقع من اسم منتج سيزما (لقسم «الموضة والجمال» المختلط)
function wf_cezma_name_category($name) {
    $n = wf_cezma_ar_key($name);
    if ($n === '') return '';
    foreach (wf_cezma_name_patterns() as $rule) {
        foreach ($rule[1] as $re) {
            if (preg_match($re, $n)) return $rule[0];
        }
    }
    return '';
}
// قسم سيزما الرئيسي (+ اسم المنتج) → قسم الموقع الموحّد. بترجّع '' لو القسم فاضي.
// «ستارز / ترتان» موديلات أحذية (شباشب) ممكن متخزن قسّمها «المنزل والمطبخ» بالغلط — قاعدة قاطعة في الجهتين
// (نفس قاعدة repair_cezma_home_category في updater/update_catalog.py)
function wf_shoe_model_tokens($name) {
    $n = wf_cezma_ar_key($name);
    if ($n === '') return false;
    static $shoe_re = null;
    if ($shoe_re === null) $shoe_re = '/(?<![\x{0621}-\x{064A}\x{0660}-\x{0669}A-Za-z0-9])(?:استارز|ستارز|استارس|ستارس|ترتاتي|ترتين|ترتان)/u';
    return preg_match($shoe_re, $n) === 1;
}
function wf_cezma_site_category($raw, $name = '') {
    // علامة قاطعة في كل الحالات: أي منتج اسمه فيه «ستارز/ترتان» موديل أحذية (شباشب)
    if ($name !== '' && wf_shoe_model_tokens($name)) return 'أحذية';
    $clean = wf_category_plain($raw);
    if ($clean === '') return '';
    if (in_array($clean, wf_category_canonical(), true)) {
        return $clean; // قسم موقع أصلاً
    }
    $map = wf_cezma_bucket_map();
    if (array_key_exists($clean, $map)) {
        if ($map[$clean] === 'المنزل والمطبخ') {
            // «المنزل والمطبخ» قسم عام: الكلمات القاطعة في الاسم (زي ستارز/ترتان = أحذية) بتكسب
            $nc = wf_cezma_name_category($name);
            if ($nc !== '' && $nc !== 'المنزل والمطبخ') return $nc;
        }
        return $map[$clean] !== '' ? $map[$clean] : (wf_cezma_name_category($name) ?: wf_cezma_fashion_fallback());
    }
    // اسم مش من أقسام سيزما ومش من أقسام الموقع: توحيد بالأسماء المعروفة بس (بدون مطابقة تقريبية)
    return wf_normalize_category($clean, '', false);
}
// تنظيف نص القسم: شيل الإيموجي وعلامات اتجاه النص (RTL/LTR) والرموز من الأطراف + توحيد المسافات
function wf_category_plain($name) {
    $raw = trim((string)$name);
    if ($raw === '') return '';
    // إزالة الإيموجي وعلامات اتجاه النص والرموز والمسافات المكررة
    // (علامات الاتجاه بتطلع جوه أسماء أقسام بعض المصادر زي سيزما فتخلي الاسم ميتعرفش)
    $clean = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2190}-\x{2BFF}\x{2600}-\x{27BF}\x{FE0F}\x{200B}-\x{200F}\x{2060}-\x{2069}\x{FEFF}\x{061C}\x{202A}-\x{202E}]/u', '', $raw);
    if ($clean === null) $clean = $raw; // بيئات بلا دعم يونيكود للتعبيرات الكاملة
    $clean = preg_replace('/\s+/u', ' ', $clean);
    if ($clean === null) $clean = $raw;
    // trim() العادي بيقطع بايتات وسط الحروف العربية (لأن قائمة الرموز فيه بايتات مشتركة مع UTF-8)
    // فبنشيل الرموز من الأطراف بتعبير نمطي آمن للعربية بدل trim
    $clean = preg_replace('/^[\s.,،\-—_|\/()\[\]{}«»"\']+|[\s.,،\-—_|\/()\[\]{}«»"\']+$/u', '', $clean);
    if ($clean === null) $clean = $raw;
    return trim($clean, " \t\n\r\0\x0B"); // مسافات ASCII فقط — آمنة على العربي
}
function wf_normalize_category($name, $fallback = '', $fuzzy = true) {
    // $fuzzy = false للأقسام الفرعية (subcategory): تطبيع الأسماء المعروفة فقط بدون مطابقة تقريبية
    // حتى لا يتحول «ألعاب وشحن وبطاقات» إلى قسم «أطفال وألعاب».
    $clean = wf_category_plain($name);
    if ($clean === '') return $fallback;
    $canon = wf_category_canonical();
    if (in_array($clean, $canon, true)) return $clean;
    $syn = wf_category_synonyms();
    if (isset($syn[$clean])) return $syn[$clean];
    if (!$fuzzy) return $clean;
    // مطابقة تقريبية على الكلمات المفتاحية الأطول أولاً
    $keys = array_keys($syn);
    usort($keys, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
    foreach ($keys as $k) {
        if (mb_strlen($k) < 3) continue;
        if (mb_stripos($clean, $k) !== false) return $syn[$k];
    }
    foreach ($canon as $c) {
        if (mb_strlen($c) >= 3 && mb_stripos($clean, $c) !== false) return $c;
    }
    return $clean;
}

function categories_by_mode($mode = 'physical', $country = 'all', $include_cezma = true) {
    if ($mode === 'digital') {
        $cats = [];
        foreach (platform_products_lite('g2g') as $p) {
            $sc = wf_normalize_category($p['subcategory'] ?? '', 'برامج وتطبيقات', false);
            if ($sc !== '') $cats[$sc] = ($cats[$sc] ?? 0) + 1;
        }
        arsort($cats);
        return $cats;
    }
    if ($mode === 'social') {
        $cats = [];
        foreach (platform_products_lite('social') as $p) {
            $sp = $p['_soc_plat'] ?? social_plat($p['name'] ?? '');
            if ($sp !== '') $cats[$sp] = ($cats[$sp] ?? 0) + 1;
        }
        arsort($cats);
        return $cats;
    }
    // فيزيكال فقط (بدون أي تصنيف رقمي أو سوشيال)
    $gulf = wf_gulf_countries();
    $plats = in_array($country, $gulf, true)
        ? ['m5zoon']
        : ['timehand', 'safqa', 'vendoor', 'angazny', 'shopmart'];
    if (!in_array($country, $gulf, true) && $include_cezma) $plats[] = 'cezma';
    // المنتجات اليدوية لكل الدول: كل منتج بياخد دولته (وعملتها) من لوحة الأدمن
    $plats[] = 'manual';
    $cats = [];
    foreach ($plats as $pk) {
        if ($pk === 'cezma') {
            foreach (cezma_search_index_category_counts($country) as $category => $count) {
                $cats[$category] = ($cats[$category] ?? 0) + (int)$count;
            }
            continue;
        }
        foreach (platform_products_lite($pk) as $p) {
            if ($country !== 'all' && ($p['country'] ?? 'مصر') !== $country) continue;
            $c = wf_normalize_category($p['category'] ?? '');
            if ($c === '' || in_array($c, ['المنتجات الرقمية', 'سوشيال ميديا', 'منتجات رقمية'], true)) continue;
            $cats[$c] = ($cats[$c] ?? 0) + 1;
        }
    }
    arsort($cats);
    return $cats;
}

function all_products() {
    $out = physical_collection('all', 'all');
    foreach (platform_products_lite('g2g') as $x) $out[] = $x;
    return $out;
}
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/supabase_users.php';
require_once __DIR__ . '/supabase_docs.php';
require_once __DIR__ . '/hevo_api.php';
function current_user() { return isset($_SESSION['user']) ? $_SESSION['user'] : null; }
function require_login() { if (!current_user()) { header('Location: ../login.php'); exit; } }
function users() { return users_list(); }
function notify($for, $text, $link = '') { if ($for === 'admin') { tg_notify('🔔 ' . BRAND . "\n" . $text); if (function_exists('wa_notify') && site_setting('wa_admin_to')) wa_notify(site_setting('wa_admin_to'), '🔔 ' . BRAND . "\n" . $text); } $n = jload('notifications.json', []); $n[] = ['for' => $for, 'text' => mb_substr($text, 0, 300), 'link' => $link, 'at' => date('c'), 'read' => false]; jsave('notifications.json', array_slice($n, -500)); }
// إرسال إشعار لمستخدم محدد بالإيميل (يُستخدم في الشات وطلبات المنتجات)
function notify_user($email, $text, $link = '') {
    $email = strtolower(trim((string)$email));
    if ($email === '' || strpos($email, '@') === false) return false;
    notify($email, $text, $link);
    return true;
}
function my_notifications($email) { return array_values(array_filter(jload('notifications.json', []), function ($n) use ($email) { return $n['for'] === 'all' || $n['for'] === $email; })); }
function admin_notifications() { return array_values(array_filter(jload('notifications.json', []), function ($n) { return $n['for'] === 'admin' || $n['for'] === 'all'; })); }
function find_user($email) { return find_user_row($email); }
function find_marketer_by_code($code) {
    static $by_code = [];
    $code = trim((string)$code);
    if ($code === '') return null;
    if (array_key_exists($code, $by_code)) return $by_code[$code];
    $found = null;
    foreach (users() as $u) {
        if (!empty($u['ref_code']) && strcasecmp((string)$u['ref_code'], $code) === 0) { $found = $u; break; }
    }
    return $by_code[$code] = $found;
}

// ============================================================================
//  نوع الحساب: «مسوق» (marketer) أو «أدمن» فقط — حساب العميل اتلغى
//  أي حساب مش أدمن بيتعامل كمسوق (حتى الحسابات القديمة اللي دورها buyer/customer).
// ============================================================================
function wf_user_role($u = null) {
    static $memo = [];
    if (!is_array($u)) $u = current_user();
    if (!$u || empty($u['email'])) return '';
    $key = strtolower(trim((string)$u['email']));
    if (isset($memo[$key])) return $memo[$key];
    $role = strtolower(trim((string)($u['role'] ?? '')));
    // الدور الحقيقي بيتقرأ من ملف المستخدمين (الجلسة ممكن تكون قديمة)
    $fresh = find_user($u['email']);
    $db_role = is_array($fresh) ? strtolower(trim((string)($fresh['role'] ?? ''))) : '';
    if ($role === '') $role = $db_role;
    if ($role === '') $role = 'marketer';
    // حساب العميل اتلغى: buyer/customer = مسوق
    if (in_array($role, ['buyer', 'customer'], true)) $role = 'marketer';
    if (!in_array($role, ['marketer', 'admin'], true)) $role = 'marketer';
    $memo[$key] = $role;
    return $role;
}
function wf_is_marketer_account($u = null) {
    if (!is_array($u)) $u = current_user();
    if (!$u || empty($u['email'])) return false;
    return in_array(wf_user_role($u), ['marketer', 'admin'], true);
}
function wf_current_is_marketer() { return wf_is_marketer_account(current_user()); }
// مزايا حساب العميل على الموقع الأساسي (تتبّع + تقييم/تعليقات + العروض والأخبار)
function wf_customer_perks() {
    return [
        'rating'  => ['star', 'التقييم والتعليقات', 'قيّم أي منتج واكتب تعليقك بعد الشراء — تعليقك بيظهر لباقي العملاء.'],
        'track'   => ['truck', 'تتبع الشحنة', 'تابع حالة طلبك خطوة بخطوة من التأكيد لحد التوصيل من صفحة «طلباتي».'],
        'offers'  => ['bell', 'أحدث العروض والأخبار', 'هيوصلك إشعار داخل الموقع على كل عرض جديد أو تحديث سعر، بالإضافة للنشرة البريدية.'],
    ];
}

function ensure_user_affiliate($email = '') {
    $cu = current_user();
    if ($email === '' && $cu && !empty($cu['email'])) $email = $cu['email'];
    if ($email === '' || $email === 'admin@wafirinhalk') return null;
    $u = find_user($email);
    if (!$u) return null;
    // حساب العميل العادي ما بقاش يتحوّل تلقائياً لمسوّق: أدوات الأفلييت للمسوقين فقط
    if (!wf_is_marketer_account($u)) return null;
    $need_save = false;
    if (empty($u['ref_code'])) {
        $u['ref_code'] = mk_code($u['name'] ?? 'MK');
        $need_save = true;
    }
    if ($need_save) {
        $rc = $u['ref_code'];
        update_user($email, function (&$row) use ($rc) {
            if (empty($row['ref_code'])) $row['ref_code'] = $rc;
        });
        if (!empty($_SESSION['user']) && strcasecmp($_SESSION['user']['email'] ?? '', $email) === 0) {
            $_SESSION['user']['ref_code'] = $rc;
        }
    }
    return $u;
}

// ============================================================================
//  تشخيص فشل جلب بيانات منتج من موقع خارجي (جوميا / نون)
//  بيرجّع سبب مفهوم يتعرض للمسوق والإدارة بدل رسالة عامة. فاضي = مفيش فشل.
// ============================================================================
function wf_fetch_fail_reason($html, $code, $curl_err, $parsed_ok, $site) {
    if ($parsed_ok) return '';
    if (!function_exists('curl_init')) return 'السيرفر مش شغال عليه cURL (الاستضافة مقفلاه)';
    if ($html === false || $html === null || $html === '') {
        return $curl_err !== ''
            ? 'السيرفر مقدرش يوصل لـ' . $site . ' (' . $curl_err . ')'
            : 'السيرفر مقدرش يوصل لـ' . $site . ' أو الصفحة رجعت فاضية';
    }
    $code = (int)$code;
    $h = (string)$html;
    if ($code === 403 || $code === 429 || $code === 503
        || preg_match('~cf-chl|Just a moment|captcha|Access Denied|Attention Required|_incapsula_|px-captcha~i', $h)) {
        return $site . ' حمت الصفحة وطلبت تحقق (غالباً بتمنع السيرفر) — HTTP ' . $code;
    }
    if ($code >= 400) return $site . ' ردت بخطأ HTTP ' . $code;
    return 'الصفحة اتجابت بس مافيهاش بيانات المنتج (غالباً بتتحمّل بالمتصفح)';
}

// عدّادات مزايا حساب العميل (عرض فقط — لا حسابات مالية ولا تسعير)
function wf_customer_perk_counts($email = '') {
    $email = strtolower(trim((string)$email));
    $out = ['orders' => 0, 'orders_active' => 0, 'reviews' => 0, 'wishlist' => 0, 'unread' => 0];
    if ($email === '') return $out;
    foreach ((array)jload('orders.json', []) as $o) {
        if (!is_array($o) || strcasecmp((string)($o['user'] ?? ''), $email) !== 0) continue;
        $out['orders']++;
        $k = function_exists('status_key') ? status_key((string)($o['status'] ?? '')) : '';
        if (!in_array($k, ['delivered', 'cancelled', 'returned'], true)) $out['orders_active']++;
    }
    foreach ((array)jload('reviews.json', []) as $r) {
        if (is_array($r) && strcasecmp((string)($r['user_email'] ?? ''), $email) === 0) $out['reviews']++;
    }
    $wl = jload('wishlist.json', []);
    if (isset($wl[$email]) && is_array($wl[$email])) $out['wishlist'] = count($wl[$email]);
    foreach (my_notifications($email) as $n) if (empty($n['read'])) $out['unread']++;
    return $out;
}

// تقييمات وتعليقات العميل على المنتجات (من reviews.json) — عرض في «حسابي»
function wf_customer_reviews($email = '', $limit = 10) {
    $email = strtolower(trim((string)$email));
    if ($email === '') return [];
    $out = [];
    $all = (array)jload('reviews.json', []);
    foreach (array_reverse($all, true) as $r) {
        if (!is_array($r) || strcasecmp((string)($r['user_email'] ?? ''), $email) !== 0) continue;
        $uid = (string)($r['product_uid'] ?? '');
        $plat = (string)($r['plat'] ?? '');
        $pid = $uid;
        if (strpos($uid, '#') !== false) { [$plat, $pid] = explode('#', $uid, 2); }
        $name = (string)($r['product_name'] ?? '');
        if ($name === '' && $pid !== '' && function_exists('find_product_by_id')) {
            $pr = find_product_by_id($pid, $plat);
            if (is_array($pr)) $name = (string)($pr['name'] ?? '');
        }
        $out[] = [
            'uid' => $uid, 'plat' => $plat, 'id' => $pid,
            'name' => $name !== '' ? $name : $pid,
            'rating' => max(1, min(5, (int)($r['rating'] ?? 0))),
            'comment' => (string)($r['comment'] ?? ''),
            'at' => (string)($r['created_at'] ?? ($r['at'] ?? '')),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

// ============================================================================
//  محافظات ومدن مصر (من بيانات شحن شوب مارت) + مناطق ومدن دول الخليج
//  (data/shipping_cities_gulf.json) — تُستخدم في تسجيل المسوقين (مصر بس) وفي
//  قوائم المنطقة/المدينة بنافذة الطلب وصفحة الهبوط (حسب دولة المنتج).
// ============================================================================
// $country = 'مصر' (الافتراضي) بيرجع نفس خريطة المحافظات/المدن القديمة بالظبط.
function wf_ship_cities_map($country = 'مصر') {
    static $cache = [];
    $cn = wf_match_country($country, 'مصر');
    if (isset($cache[$cn])) return $cache[$cn];
    $map = [];
    // أسماء المناطق/المدن بتتنضّف وتتشال منها المكررات
    $collect = function ($rows) {
        $names = [];
        foreach ((array)$rows as $c) {
            $n = trim((string)(is_array($c) ? ($c['name'] ?? '') : $c));
            if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
        }
        return $names;
    };
    if ($cn === 'مصر') {
        $raw = jload('shipping_cities_shopmart.json', [], false);
        $govs = is_array($raw['governorates'] ?? null) ? $raw['governorates'] : [];
        foreach ($govs as $gov => $cities) {
            $gov = trim((string)$gov);
            if ($gov === '' || !is_array($cities)) continue;
            $names = $collect($cities);
            if ($names) $map[$gov] = $names;
        }
        if (!$map) {
            foreach (array_keys(function_exists('site_ship_map') ? site_ship_map() : []) as $gov) {
                $gov = trim((string)$gov);
                if ($gov !== '') $map[$gov] = [];
            }
        }
    } else {
        $raw = jload('shipping_cities_gulf.json', [], false);
        $list = (is_array($raw['countries'] ?? null) && is_array($raw['countries'][$cn] ?? null)) ? $raw['countries'][$cn] : [];
        foreach ($list as $region => $cities) {
            $region = trim((string)$region);
            if ($region === '') continue;
            $map[$region] = $collect($cities);
        }
        // أي منطقة في جدول الدولة مالهاش مدن ⇒ قائمة فاضية (الواجهة بتحوّل الحقل لنص حر)
        foreach (array_keys(function_exists('site_ship_map') ? site_ship_map('manual', $cn) : []) as $region) {
            $region = trim((string)$region);
            if ($region !== '' && !isset($map[$region])) $map[$region] = [];
        }
    }
    return $cache[$cn] = $map;
}
// محافظات مصر (لصفحات التسجيل/استكمال بيانات المسوق) — من غير دول الخليج
function wf_ship_governorates() { return array_keys(wf_ship_cities_map()); }
function wf_ship_cities($gov = '', $country = 'مصر') {
    $map = wf_ship_cities_map($country);
    $gov = trim((string)$gov);
    return $gov !== '' && !empty($map[$gov]) ? $map[$gov] : [];
}
// مصر + دول الخليج في خريطة واحدة للـ JS (wfCityState): المفتاح اسم المنطقة، وأسماء المناطق
// مش بتتعارض بين مصر والخليج — ولو اتعارضت مرة، قائمة مصر هي اللي بتكسب (زي السلوك القديم).
function wf_ship_cities_map_all() {
    static $all = null;
    if ($all !== null) return $all;
    $all = wf_ship_cities_map('مصر');
    foreach (wf_country_list() as $cn) {
        if ($cn === 'مصر') continue;
        foreach (wf_ship_cities_map($cn) as $region => $cities) {
            if (!isset($all[$region])) $all[$region] = $cities;
        }
    }
    return $all;
}

// ============================================================================
//  وصول أحدث العروض والأخبار لأصحاب الحسابات (بريد + إشعار داخل الموقع)
// ============================================================================
function wf_offers_subscribe($email, $name = '', $source = 'site') {
    $email = strtolower(trim((string)$email));
    if ($email === '' || !function_exists('valid_email') || !valid_email($email)) return false;
    $nl = jload('newsletter.json', []);
    if (!is_array($nl)) $nl = [];
    foreach ($nl as $row) {
        if (strcasecmp((string)($row['email'] ?? ''), $email) === 0) return false;
    }
    $nl[] = ['email' => mb_substr($email, 0, 120), 'name' => mb_substr(trim((string)$name), 0, 60), 'at' => date('c'), 'via' => $source];
    jsave('newsletter.json', $nl);
    return true;
}
// أحدث العروض والأخبار التي تصل للعميل: إشعارات الموقع العامة + تنبيهات الأسعار
function wf_offers_news_items($email = '', $limit = 6) {
    $items = [];
    $notifs = jload('notifications.json', []);
    if (!is_array($notifs)) $notifs = [];
    foreach (array_reverse($notifs, true) as $n) {
        if (!is_array($n)) continue;
        $for = (string)($n['for'] ?? '');
        if ($for !== 'all' && ($email === '' || strcasecmp($for, $email) !== 0)) continue;
        $items[] = [
            'title' => '', 'text' => wf_ui_plain_offers((string)($n['text'] ?? '')),
            'at' => (string)($n['at'] ?? ''), 'link' => (string)($n['link'] ?? ''),
        ];
        if (count($items) >= $limit) break;
    }
    return $items;
}
function wf_ui_plain_offers($text) {
    return trim(preg_replace('/\s*\n\s*/u', ' • ', (string)$text));
}
function update_user($email, $fn) {
    // الحسابات على Supabase (جدول profiles) — قراءة/تعديل/كتابة بشرط عدم التغيير
    return update_user_row($email, $fn);
}
function mk_code($name) { $c = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $name)); $c = substr($c ?: 'MK', 0, 5); return 'MK-' . $c . '-' . rand(10, 99); }

// ===== إدارة متاجر المسوقين والعمولات المخصصة =====
function marketer_stores_all() {
    $all = jload('marketer_stores.json', []);
    return is_array($all) ? $all : [];
}
// أقسام صفحة المتجر التي يمكن لصاحب المتجر تشغيلها/إيقافها (زي محرّر المتجر في المنصات)
function marketer_store_sections_default() {
    return ['announcement' => 1, 'chips' => 1, 'collections' => 1, 'featured' => 1, 'trust' => 1, 'faq' => 1, 'contact' => 1, 'footer' => 1];
}
function marketer_store_slug_from_title($title, $fallback = '') {
    $t = trim((string)$title);
    if (function_exists('wf_slugify')) return (string)wf_slugify($t !== '' ? $t : $fallback, 40);
    return preg_replace('/[^a-z0-9]+/', '-', strtolower((string)preg_replace('/\s+/u', '-', $t)));
}
// كود المتجر: نبحث بالكود أولاً ثم بالرابط المختصر (slug)
function marketer_store_by_slug($slug) {
    $slug = strtolower(trim((string)$slug));
    if ($slug === '') return null;
    foreach (marketer_stores_all() as $code => $st) {
        if (!empty($st['slug']) && strtolower(trim((string)$st['slug'])) === $slug) {
            return ['ref_code' => (string)$code, 'store' => $st];
        }
    }
    return null;
}
function marketer_store_slug_taken($slug, $except_code = '') {
    $slug = strtolower(trim((string)$slug));
    if ($slug === '') return true;
    $except_code = trim((string)$except_code);
    foreach (marketer_stores_all() as $code => $st) {
        if ($except_code !== '' && strcasecmp((string)$code, $except_code) === 0) continue;
        if (!empty($st['slug']) && strtolower(trim((string)$st['slug'])) === $slug) return true;
    }
    foreach (users() as $u) {
        if (!empty($u['ref_code']) && strcasecmp((string)$u['ref_code'], $slug) === 0) return true;
    }
    return false;
}
function marketer_store_get($ref_code) {
    $ref_code = trim((string)$ref_code);
    $sections = marketer_store_sections_default();
    if ($ref_code === '') return ['title' => '', 'bio' => '', 'whatsapp' => '', 'theme' => '#10b981', 'banner' => '', 'products' => [],
        'slug' => '', 'mode' => 'light', 'announcement_text' => '', 'announcement_color' => '#4B5E43',
        'social' => ['facebook' => '', 'instagram' => '', 'tiktok' => ''], 'sections' => $sections,
        'faq' => [], 'contact' => ['phone' => '', 'email' => '', 'address' => '']];
    $all = marketer_stores_all();
    $mk = find_marketer_by_code($ref_code);
    $def_title = $mk ? ('متجر ' . ($mk['name'] ?? $ref_code)) : ('متجر ' . $ref_code);
    $st = $all[$ref_code] ?? [];
    $st_sections = isset($st['sections']) && is_array($st['sections']) ? $st['sections'] : [];
    foreach ($sections as $k => $d) $sections[$k] = isset($st_sections[$k]) ? (int)!empty($st_sections[$k]) : $d;
    $social = isset($st['social']) && is_array($st['social']) ? $st['social'] : [];
    $contact = isset($st['contact']) && is_array($st['contact']) ? $st['contact'] : [];
    $faq = [];
    foreach ((array)($st['faq'] ?? []) as $qa) {
        if (!is_array($qa)) continue;
        $q2 = mb_substr(trim((string)($qa['q'] ?? '')), 0, 160);
        $a2 = mb_substr(trim((string)($qa['a'] ?? '')), 0, 400);
        if ($q2 !== '' && $a2 !== '') $faq[] = ['q' => $q2, 'a' => $a2];
    }
    return [
        'ref_code' => $ref_code,
        'title'    => trim((string)($st['title'] ?? '')) ?: $def_title,
        'bio'      => trim((string)($st['bio'] ?? '')) ?: 'أفضل المنتجات الأصلية بأسرع شحن لباب البيت والدفع عند الاستلام',
        'whatsapp' => trim((string)($st['whatsapp'] ?? ($mk['whatsapp'] ?? ($mk['phone'] ?? '')))),
        'pixel_fb' => trim((string)($st['pixel_fb'] ?? '')),
        'pixel_tt' => trim((string)($st['pixel_tt'] ?? '')),
        'theme'    => trim((string)($st['theme'] ?? '#059669')) ?: '#059669',
        'slug'     => strtolower(trim((string)($st['slug'] ?? ''))),
        'mode'     => (trim((string)($st['mode'] ?? 'light')) === 'dark') ? 'dark' : 'light',
        'logo'     => trim((string)($st['logo'] ?? '')),
        'announcement_text'  => mb_substr(trim((string)($st['announcement_text'] ?? '')), 0, 160),
        'announcement_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($st['announcement_color'] ?? '')) ? (string)$st['announcement_color'] : '#4B5E43',
        'social'   => [
            'facebook'  => trim((string)($social['facebook'] ?? '')),
            'instagram' => trim((string)($social['instagram'] ?? '')),
            'tiktok'    => trim((string)($social['tiktok'] ?? '')),
        ],
        'sections' => $sections,
        'faq'      => $faq,
        'contact'  => [
            'phone'   => trim((string)($contact['phone'] ?? ($mk['phone'] ?? ''))),
            'email'   => trim((string)($contact['email'] ?? ($mk['email'] ?? ''))),
            'address' => mb_substr(trim((string)($contact['address'] ?? '')), 0, 160),
        ],
        'products' => isset($st['products']) && is_array($st['products']) ? $st['products'] : [],
    ];
}
// حفظ إعدادات المتجر الجاية من فورم (يستخدمه تبويب المتجر ومحرّر المتجر داخل صفحة المتجر)
function marketer_store_save_from_post($ref_code, $post) {
    $ref_code = trim((string)$ref_code);
    if ($ref_code === '') return ['ok' => false, 'slug' => ''];
    $st_cur = marketer_store_get($ref_code);
    $slug_in = strtolower(trim((string)($post['store_slug'] ?? '')));
    $slug_in = preg_replace('/[^a-z0-9-]+/', '-', $slug_in);
    if (!is_string($slug_in)) $slug_in = '';
    $slug_in = trim(preg_replace('/-+/', '-', $slug_in), '-');
    if ($slug_in === '' || mb_strlen($slug_in) < 3) $slug_in = $st_cur['slug'] ?: strtolower($ref_code);
    $warn = false;
    if (marketer_store_slug_taken($slug_in, $ref_code)) {
        $slug_in = $st_cur['slug'] ?: strtolower($ref_code);
        $warn = true;
    }
    $sections_in = [];
    foreach (marketer_store_sections_default() as $sec_k => $sec_d) {
        $sections_in[$sec_k] = !empty($post['sections'][$sec_k]) ? 1 : 0;
    }
    $faq_in = [];
    $faq_q = isset($post['faq_q']) && is_array($post['faq_q']) ? $post['faq_q'] : [];
    $faq_a = isset($post['faq_a']) && is_array($post['faq_a']) ? $post['faq_a'] : [];
    foreach ($faq_q as $i => $fq) {
        $fq = mb_substr(trim((string)$fq), 0, 160);
        $fa = mb_substr(trim((string)($faq_a[$i] ?? '')), 0, 400);
        if ($fq !== '' && $fa !== '') $faq_in[] = ['q' => $fq, 'a' => $fa];
        if (count($faq_in) >= 4) break;
    }
    $hex = function ($v, $def) {
        $v = trim((string)$v);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $def;
    };
    marketer_store_save($ref_code, [
        'title'    => mb_substr(trim((string)($post['store_title'] ?? '')), 0, 80),
        'bio'      => mb_substr(trim((string)($post['store_bio'] ?? '')), 0, 220),
        'whatsapp' => mb_substr(trim((string)($post['store_whatsapp'] ?? '')), 0, 30),
        'pixel_fb' => mb_substr(trim((string)($post['pixel_fb'] ?? '')), 0, 60),
        'pixel_tt' => mb_substr(trim((string)($post['pixel_tt'] ?? '')), 0, 60),
        'theme'    => $hex($post['store_theme'] ?? '', '#059669'),
        'slug'     => $slug_in,
        'mode'     => (($post['store_mode'] ?? 'light') === 'dark') ? 'dark' : 'light',
        'announcement_text'  => mb_substr(trim((string)($post['announcement_text'] ?? '')), 0, 160),
        'announcement_color' => $hex($post['announcement_color'] ?? '', '#4B5E43'),
        'social'   => [
            'facebook'  => mb_substr(trim((string)($post['social_facebook'] ?? '')), 0, 120),
            'instagram' => mb_substr(trim((string)($post['social_instagram'] ?? '')), 0, 120),
            'tiktok'    => mb_substr(trim((string)($post['social_tiktok'] ?? '')), 0, 120),
        ],
        'sections' => $sections_in,
        'faq'      => $faq_in,
        'contact'  => [
            'phone'   => mb_substr(trim((string)($post['contact_phone'] ?? '')), 0, 30),
            'email'   => mb_substr(trim((string)($post['contact_email'] ?? '')), 0, 80),
            'address' => mb_substr(trim((string)($post['contact_address'] ?? '')), 0, 160),
        ],
        'products' => $st_cur['products'] ?? [],
    ]);
    return ['ok' => true, 'slug' => $slug_in, 'warn' => $warn];
}
function marketer_store_save($ref_code, $data) {
    $ref_code = trim((string)$ref_code);
    if ($ref_code === '') return false;
    return jupdate('marketer_stores.json', function (&$all) use ($ref_code, $data) {
        if (!is_array($all)) $all = [];
        $cur = isset($all[$ref_code]) && is_array($all[$ref_code]) ? $all[$ref_code] : [];
        foreach ($data as $k => $v) $cur[$k] = $v;
        $cur['updated_at'] = date('c');
        $all[$ref_code] = $cur;
        return true;
    });
}
function marketer_store_product_comm($ref_code, $plat, $pid) {
    $ref_code = trim((string)$ref_code);
    if ($ref_code === '') return 0.0;
    if (!MARKETER_EXTRA_COMM_ON) return 0.0;
    // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة — السعر سعر المصدر بالظبط،
    // وأي قيمة قديمة محفوظة في متجر المسوق أو الجلسة أو رابط ?comm بتتجاهل.
    if (function_exists('wf_is_digital_plat') && wf_is_digital_plat($plat)) return 0.0;
    // فحص عمولة ممررة في الجلسة أو الرابط أو المتجر لهذا المنتج
    $key = $plat . '#' . $pid;
    if (isset($_POST['extra_comm']) && is_numeric($_POST['extra_comm']) && (float)$_POST['extra_comm'] >= 0) {
        return min((float)MAX_MARKUP_AMOUNT, round((float)$_POST['extra_comm'], 2));
    }
    if (isset($_GET['comm']) && is_numeric($_GET['comm']) && (float)$_GET['comm'] >= 0) {
        $gc = min((float)MAX_MARKUP_AMOUNT, round((float)$_GET['comm'], 2));
        $_SESSION['aff_custom_comm'][$ref_code . '#' . $key] = $gc;
        return max(0.0, $gc);
    }
    $st = marketer_store_get($ref_code);
    if (isset($st['products'][$key]['extra_comm']) && is_numeric($st['products'][$key]['extra_comm'])) {
        return max(0.0, min((float)MAX_MARKUP_AMOUNT, round((float)$st['products'][$key]['extra_comm'], 2)));
    }
    // عمولة الرابط (من تبويب الروابط) للمنتجات اللي مش في متجر المسوق
    $lc_map = is_array($st['link_comm'] ?? null) ? $st['link_comm'] : [];
    if (isset($lc_map[$key]) && is_numeric($lc_map[$key])) return max(0.0, min((float)MAX_MARKUP_AMOUNT, round((float)$lc_map[$key], 2)));
    if (isset($_SESSION['aff_custom_comm'][$ref_code . '#' . $key])) {
        return max(0.0, (float)$_SESSION['aff_custom_comm'][$ref_code . '#' . $key]);
    }
    return 0.0;
}
function marketer_effective_price($p, $plat, $ref_code = '', $explicit_extra_comm = null) {
    $base = store_price($p, $plat);
    $pid = (string)($p['id'] ?? '');
    $extra = 0.0;
    if (!MARKETER_EXTRA_COMM_ON) $explicit_extra_comm = null;
    // الرقمي والسوشيال ميديا: مافيشش عمولة مضافة — حتى لو جاية صريحة من فورم أو رابط
    if (function_exists('wf_is_digital_plat') && wf_is_digital_plat($plat)) {
        $explicit_extra_comm = null;
        $ref_code = '';
    }
    if ($explicit_extra_comm !== null && is_numeric($explicit_extra_comm)) {
        $extra = max(0.0, min((float)MAX_MARKUP_AMOUNT, round((float)$explicit_extra_comm, 2)));
    } elseif ($ref_code !== '') {
        $extra = marketer_store_product_comm($ref_code, $plat, $pid);
    }
    $eff = round($base + $extra, 2);
    // الرقمي: نفس تقريب العروض (5 أو 0) عشان سعر المسوق = سعر الموقع. السوشيال ميديا: سعر المصدر بالظبط.
    return wf_is_social_plat($plat) ? $eff : wf_round_price($eff);
}

// ===== نظام أفلييت مثل أمازون (Amazon Associates Style Tracking) =====
// يحفظ كود المسوق في الـ Session + Cookie لمدة 30 يوم عند زيارة أي رابط يحتوي على ?ref=CODE أو ?store=CODE
(function () {
    $cu = current_user();
    if ($cu && !empty($cu['email']) && ($cu['role'] ?? '') !== 'admin') {
        ensure_user_affiliate($cu['email']);
    }
    $in_ref = trim((string)($_GET['ref'] ?? ($_GET['store'] ?? ($_GET['mk'] ?? ''))));
    if ($in_ref !== '' && find_marketer_by_code($in_ref)) {
        $_SESSION['aff_ref'] = $in_ref;
        if (!headers_sent()) {
            @setcookie('aff_ref', $in_ref, time() + 30 * 86400, '/');
        }
        $pid = trim((string)($_GET['id'] ?? ''));
        $pl = $pid !== '' ? resolve_plat($pid, $_GET['plat'] ?? '') : '';
        if ($pid !== '' && isset($_GET['comm']) && is_numeric($_GET['comm']) && (float)$_GET['comm'] >= 0
            && !(function_exists('wf_is_digital_plat') && wf_is_digital_plat($pl))) {
            $_SESSION['aff_custom_comm'][$in_ref . '#' . $pl . '#' . $pid] = min((float)MAX_MARKUP_AMOUNT, round((float)$_GET['comm'], 2));
        }
        $ck_key = $in_ref . '#' . ($pid ?: 'store');
        if (empty($_SESSION['_aff_clicked'][$ck_key])) {
            $_SESSION['_aff_clicked'][$ck_key] = 1;
            $clicks = jload('clicks.json', []);
            $clicks[] = [
                'ref' => $in_ref,
                'plat' => $pl,
                'id' => $pid ?: 'الصفحة الرئيسية',
                'type' => $pid ? 'منتج' : 'متجر',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'at' => date('c')
            ];
            if (count($clicks) > 5000) $clicks = array_slice($clicks, -5000);
            jsave('clicks.json', $clicks);
        }
    }
})();

// إرجاع كود المسوق النشط للزائر الحالي (من الرابط أو الجلسة أو الكوكيز أو حساب المسوق نفسه)
function active_ref($explicit = '') {
    $candidates = [
        trim((string)$explicit),
        trim((string)($_GET['ref'] ?? '')),
        trim((string)($_GET['store'] ?? '')),
        trim((string)($_POST['ref'] ?? '')),
        trim((string)($_SESSION['aff_ref'] ?? '')),
        trim((string)($_COOKIE['aff_ref'] ?? '')),
    ];
    foreach ($candidates as $c) {
        if ($c !== '' && find_marketer_by_code($c)) return $c;
    }
    $cu = current_user();
    if ($cu && !empty($cu['email']) && ($cu['role'] ?? '') !== 'admin') {
        $me = ensure_user_affiliate($cu['email']);
        if (!empty($me['ref_code'])) return $me['ref_code'];
    }
    return '';
}

function log_audit($action, $target, $details = []) {
    $cu = current_user();
    $logs = jload('audit_logs.json', []);
    if (!is_array($logs)) $logs = [];
    $logs[] = [
        'id' => uniqid('aud_'),
        'actor_email' => $cu['email'] ?? 'admin@wafirinhalk',
        'actor_role' => $_SESSION['admin_role'] ?? ($cu['role'] ?? 'super_admin'),
        'action' => (string)$action,
        'target' => (string)$target,
        'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : (string)$details,
        'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 32),
        'at' => date('c')
    ];
    jsave('audit_logs.json', array_slice($logs, -1000));
}

function save_uploaded_image($file_key, $prefix = 'img') {
    if (empty($_FILES[$file_key]) || ($_FILES[$file_key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }
    $tmp = $_FILES[$file_key]['tmp_name'];
    $orig = $_FILES[$file_key]['name'] ?? '';
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $ext = 'jpg';
    }
    $dir = __DIR__ . '/assets/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = $prefix . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    $dest = $dir . '/' . $fname;
    if (@move_uploaded_file($tmp, $dest)) {
        return 'assets/uploads/' . $fname;
    }
    return '';
}

// عمولة ثابتة من سجل المنتج لها أولوية؛ إعدادات النسبة اختيارية للمنصات الأخرى فقط.
// الرقمي والسوشيال ميديا: لا عمولة إطلاقاً — أي نسبة قديمة متسجلة في الإعدادات بتتجاهل.
function platform_commission_pct_override($plat) {
    if (function_exists('wf_plat_no_commission') && wf_plat_no_commission($plat)) return null;
    if (in_array((string)$plat, ['vendoor', 'timehand'], true)) return null;
    $v = site_setting('commission_' . preg_replace('/[^a-z0-9_]/i', '', (string)$plat) . '_pct', '');
    if ($v === '' || !is_numeric($v)) return null;
    return max(0, min(100, (float)$v));
}
function source_product_commission($p) {
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $comm = mz_num($p['commission'] ?? '');
    if ($comm <= 0 && !empty($ex['source_commission_original'])) $comm = mz_num($ex['source_commission_original']);
    if ($comm <= 0 && !empty($ex['profit'])) $comm = mz_num($ex['profit']);
    return max(0, round($comm, 2));
}
function raw_list_price($p, $plat = '') {
    $v = $p['price'] ?? null;
    $stored = (is_numeric($v) && (float)$v > 0) ? (float)$v : price_num($p['price_text'] ?? '');
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    // الرقمي: سعر المصدر = سعر المنتج بالدولار × 56 ج.م (المخزن في الكتالوج) — أدق من سعر الملف
    if (wf_plat_no_commission($plat) && (string)$plat === 'g2g') {
        $egp = wf_digital_source_price($p, $plat);
        if ($egp > 0) return $egp;
    }
    // These catalog feeds store `price` as the post-commission cost. Recover the source gross
    // before applying the commission once in store_price_from_gross().
    $gross_source = null;
    if ($plat === 'vendoor') $gross_source = mz_num($ex['purchasing'] ?? '');
    elseif ($plat === 'timehand') $gross_source = mz_num($ex['orig_suggested'] ?? '');
    elseif ($plat === 'm5zoon') $gross_source = mz_num($ex['suggested'] ?? '');
    elseif ($plat === 'angazny') $gross_source = mz_num($ex['orig_suggested'] ?? '');
    elseif ($plat === 'shopmart') $gross_source = mz_num($ex['orig_suggested'] ?? '');
    elseif ($plat === 'safqa') {
        // sale_price is already net; notes/suggested can be stale, so reconstruct from current sale + fixed commission.
        $fixed = source_product_commission($p);
        if ($fixed > 0 && $stored > 0) $gross_source = round($stored + $fixed, 2);
        else $gross_source = $stored;
    }
    if ($gross_source !== null && $gross_source > 0) return $gross_source;
    if (in_array($plat, ['vendoor', 'timehand', 'safqa', 'm5zoon', 'angazny', 'shopmart'], true) && $stored > 0) {
        $fixed = source_product_commission($p);
        if ($fixed > 0) return round($stored + $fixed, 2);
    }
    return $stored;
}
function platform_commission_amount($p, $plat, $gross_price = null, $variant_fixed = null) {
    global $MARGINS;
    // الرقمي والسوشيال ميديا: لا عمولة — أي عمولة مخزنة في بيانات المصدر بتتجاهل
    if (function_exists('wf_plat_no_commission') && wf_plat_no_commission($plat)) return 0.0;
    $gross = ($gross_price !== null && (float)$gross_price > 0) ? (float)$gross_price : raw_list_price($p, $plat);
    if ($gross <= 0) return 0;
    $pct_override = platform_commission_pct_override($plat);
    if ($pct_override !== null) return round($gross * $pct_override / 100, 2);
    if ($variant_fixed !== null && is_numeric($variant_fixed) && (float)$variant_fixed > 0) {
        return round(min($gross, (float)$variant_fixed), 2);
    }
    $fixed = source_product_commission($p);
    if ($fixed > 0) return round(min($gross, $fixed), 2);
    $pct = (float)($MARGINS[$plat] ?? 0);
    if ($pct > 0) return round($gross * ($pct / 100), 2);
    return 0;
}
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
// العمولة الأساسية صفر — المسوق يربح فقط العمولة التي يضيفها بنفسه فوق السعر الأساسي للمنتج.
function product_commission_unit($p, $plat, $unit_price = null, $ref = '') {
    if ($ref !== '' && ($mk = find_marketer_by_code($ref))) {
        if (($mk['status'] ?? 'approved') === 'suspended') return 0.0;
    }
    $list = store_price($p, $plat);
    $sale = ($unit_price !== null && (float)$unit_price > 0) ? (float)$unit_price : $list;
    if ($sale > $list) {
        return max(0.0, round($sale - $list, 2));
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

// ===== نافذة إتمام الطلب الفوري للعميل =====
// خانة «سعر الاتفاق مع العميل» اتشالت من الموقع كله: سعر العميل دايمًا هو سعر الرابط المحسوب على السيرفر.
function render_easyorder_dialog_html($default_ref = '', $default_plat = '', $default_currency = null, $default_country = '') {
    require_once __DIR__ . '/ui-components.php';
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    $in_dash = (strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/dashboard/') !== false);
    $orders_url = $in_dash ? 'orders.php' : 'dashboard/orders.php';
    // Display the existing Egyptian supplier rates, not one shared Vendoor table.
    // Non-EGP shipping/accounting behaviour is unchanged and still shows the currency warning.
    $ship_by_platform = [];
    foreach (['vendoor', 'angazny', 'timehand', 'safqa', 'shopmart', 'cezma'] as $ship_platform) {
        $ship_by_platform[$ship_platform] = site_ship_map($ship_platform);
    }
    $ship_by_platform['safka'] = $ship_by_platform['safqa'];
    // المنتجات اليدوية: مصر بتستخدم جدول تايم هاند بالجنيه، وكل دولة خليجية ليها جدول مناطقها
    // بعملة الدولة (الرسوم بيدخلها المالك من dashboard/admin_shipping.php) — مفيش تحويل عملات.
    $ship_currency_by_key = [];
    $ship_by_platform['manual'] = site_ship_map('manual', 'مصر');
    $ship_currency_by_key['manual'] = wf_ship_currency('manual', 'مصر');
    foreach (wf_gulf_countries() as $ship_country) {
        $ship_key = 'manual:' . $ship_country;
        $ship_by_platform[$ship_key] = site_ship_map('manual', $ship_country);
        $ship_currency_by_key[$ship_key] = wf_ship_currency('manual', $ship_country);
    }
    foreach (array_keys($ship_by_platform) as $ship_key) {
        if (!isset($ship_currency_by_key[$ship_key])) $ship_currency_by_key[$ship_key] = 'ج.م';
    }
    // أول ما يفتح المتصفح الصفحة، قائمة المناطق تظهر بأسعار المنصة/الدولة نفسها (بدل جدول عام)
    $default_plat = trim((string)$default_plat);
    $default_country = wf_match_country($default_country, '');
    $default_key = ($default_plat === 'manual' && $default_country !== '' && $default_country !== 'مصر')
        ? 'manual:' . $default_country
        : $default_plat;
    $ship_rates = ($default_key !== '' && !empty($ship_by_platform[$default_key]))
        ? $ship_by_platform[$default_key]
        : site_ship_map();
    $ship_currency = $ship_currency_by_key[$default_key] ?? 'ج.م';
    $region_label = wf_ship_region_label($default_country);
    $ship_by_platform = ['default' => $ship_rates] + $ship_by_platform;
    $ship_currency_by_key = ['default' => $ship_currency] + $ship_currency_by_key;
    $ref_val = active_ref($default_ref);
    $csrf_val = csrf_token();
    ?>
    
    <?php wf_ui_sprite(); ?>
    <script type="application/json" id="wfShippingState"><?php echo json_encode($ship_by_platform, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?></script>
    <script type="application/json" id="wfShippingCurrency"><?php echo json_encode($ship_currency_by_key, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?></script>
    <?php // الخصم بالجنيه بس: لو الصفحة عندها منتج بعملة تانية (ر.س/د.إ/…) الـ state بيقول للواجهة متطبّقوش
    $bulk_state = function_exists('wf_bulk_discount_state') ? wf_bulk_discount_state($default_plat, $default_currency) : ['per_piece' => 0, 'max_qty' => 99]; ?>
    <script type="application/json" id="wfCityState"><?php echo json_encode(wf_ship_cities_map_all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?></script>
    <script type="application/json" id="wfBulkState"><?php echo json_encode($bulk_state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?></script>
    <div id="eoCheckoutDialog" class="eo-modal-backdrop" onclick="if(event.target===this)closeEasyOrderModal()">
      <div id="wfLeadModal" class="eo-modal-card" role="dialog" aria-modal="true" aria-labelledby="eoDialogTitle">
        <div class="eo-modal-head">
          <h3 id="eoDialogTitle">إتمام الطلب السريع — الدفع عند الاستلام</h3>
          <button type="button" class="eo-modal-close" onclick="closeEasyOrderModal()" aria-label="إغلاق نافذة الطلب"></button>
        </div>
        <div class="eo-modal-body">
          <div id="eoFormView">
            <div class="eo-prod-summary">
              <img id="eoProdImg" src="" alt="صورة المنتج" width="68" height="68">
              <div class="eo-prod-info">
                <div id="eoProdTitle" class="eo-prod-title shop-lead-name"></div>
                <div data-ui="u-91247d92 ue1 ue7 ue2 ues uet ueu">
                  <div class="eo-prod-price"><span id="eoUnitPriceTxt">0</span> <span data-eo-currency>ج.م</span></div>
                  <div class="eo-qty-box">
                    <button type="button" onclick="eoStepQty(-1)" aria-label="تقليل الكمية">−</button>
                    <input type="number" id="eoQtyInput" value="1" min="1" max="99" aria-label="الكمية" onchange="eoRecalc()" oninput="eoRecalc()">
                    <button type="button" onclick="eoStepQty(1)" aria-label="زيادة الكمية">+</button>
                  </div>
                </div>
              </div>
            </div>
            <div class="alert info" id="eoBulkHint"<?php echo ($default_plat !== '' && function_exists('wf_bulk_discount_plat_ok') && wf_bulk_discount_plat_ok($default_plat, $default_currency)) ? '' : ' hidden'; ?> data-per-piece="<?php echo (float)($bulk_state['per_piece'] ?? 0); ?>">🎁 <?php echo esc($bulk_state['label'] ?? ('خصم ' . (int)LP_BUNDLE_DISCOUNT . ' ج.م لكل قطعة إضافية بعد القطعة الأولى')); ?> — بيتحسب أوتوماتيك في الإجمالي (ما بيطبّقش على المنتجات الرقمية أو خدمات السوشيال ميديا).</div>

            <form id="eoOrderForm" method="post" action="<?php echo esc($orders_url); ?>" onsubmit="return submitEasyOrderForm(event)">
              <input type="hidden" name="csrf_token" value="<?php echo esc($csrf_val); ?>">
              <input type="hidden" name="ajax_order" value="1">
              <input type="hidden" name="id" id="eoInputId" value="">
              <input type="hidden" name="plat" id="eoInputPlat" value="">
              <input type="hidden" name="pname" id="eoInputPname" value="">
              <input type="hidden" name="ref" id="eoInputRef" value="<?php echo esc($ref_val); ?>">
                            <input type="hidden" name="extra_comm" id="eoInputExtraComm" value="0">
              <input type="hidden" name="sale_price" id="eoInputSalePrice" value="0">
              <input type="hidden" name="unit_price" id="eoInputUnitPrice" value="0">
              <input type="hidden" name="qty" id="eoInputQty" value="1">
              <input type="hidden" name="bundle_offer" id="eoInputBundle" value="">
              <input type="hidden" name="variant" id="eoInputVariant" value="">

              <div id="eoVariantFields" class="eo-field-grid" data-ui="u-9489ae20 uev uew">
                <div class="eo-field" id="eoColorWrap" data-ui="u-5590912d uev">
                  <label><svg class=wf-ico aria-hidden=true><use href=#icon-image></use></svg> اختر اللون</label>
                  <select id="eoColorSelect" name="color" onchange="eoSyncVariant()"></select>
                </div>
                <div class="eo-field" id="eoSizeWrap" data-ui="u-5590912d uev">
                  <label><svg class=wf-ico aria-hidden=true><use href=#icon-info></use></svg> اختر المقاس</label>
                  <select id="eoSizeSelect" name="size" onchange="eoSyncVariant()"></select>
                </div>
              </div>

              <div class="eo-field">
                <label><svg class=wf-ico aria-hidden=true><use href=#icon-user></use></svg> الاسم بالكامل <span data-ui="u-ce5bc718 uex">*</span></label>
                <input type="text" name="name" id="eoBuyerName" required placeholder="اكتب اسمك الثلاثي لاستلام الشحنة">
              </div>

              <div class="eo-field-grid">
                <div class="eo-field">
                  <label><svg class=wf-ico aria-hidden=true><use href=#icon-phone></use></svg> رقم الموبايل الأساسي <span data-ui="u-ce5bc718 uex">*</span></label>
                  <input type="tel" name="phone" id="eoBuyerPhone" required pattern="01[0125][0-9]{8}" maxlength="11" inputmode="numeric" placeholder="01xxxxxxxxx">
                </div>
                <div class="eo-field">
                  <label><svg class=wf-ico aria-hidden=true><use href=#icon-phone></use></svg> رقم بديل / واتساب (اختياري)</label>
                  <input type="tel" name="phone2" id="eoBuyerPhone2" maxlength="15" inputmode="numeric" placeholder="رقم آخر للتأكيد">
                </div>
              </div>

              <div class="eo-field-grid">
                <div class="eo-field">
                  <label id="eoBuyerCityLbl" for="eoBuyerCity"><svg class=wf-ico aria-hidden=true><use href=#icon-truck></use></svg> <span class="eo-region-word"><?php echo esc($region_label); ?></span> <span data-ui="u-ce5bc718 uex">*</span></label>
                  <select name="city" id="eoBuyerCity" required onchange="eoRecalc();eoFillDistricts()" data-ship-currency="<?php echo esc($ship_currency); ?>">
                    <option value="" data-ship="0">-- اختر <?php echo esc($region_label); ?> --</option>
                    <?php foreach ($ship_rates as $gov => $cost): ?>
                      <option value="<?php echo esc($gov); ?>" data-ship="<?php echo (float)$cost; ?>"><?php echo esc($gov); ?> (الشحن: <?php echo (float)$cost; ?> <?php echo esc($ship_currency); ?>)</option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="eo-field">
                  <label id="eoBuyerDistrictLbl" for="eoBuyerDistrictSel"><svg class=wf-ico aria-hidden=true><use href=#icon-map></use></svg> المدينة / المركز <span data-ui="u-ce5bc718 uex">*</span></label>
                  <select name="district" id="eoBuyerDistrictSel" data-ui-field="1" disabled><option value="">-- اختر المحافظة أولاً --</option></select>
                  <input type="text" name="district" id="eoBuyerDistrict" data-ui-field="1" aria-labelledby="eoBuyerDistrictLbl" placeholder="اكتب اسم المدينة أو المركز أو الحي" hidden disabled>
                </div>
              </div>

              <div class="eo-field">
                <label><svg class=wf-ico aria-hidden=true><use href=#icon-map></use></svg> العنوان بالتفصيل <span data-ui="u-ce5bc718 uex">*</span></label>
                <input type="text" name="address" id="eoBuyerAddress" required placeholder="اسم الشارع - رقم العقار - علامة مميزة">
              </div>

              <div class="eo-field">
                <label><svg class=wf-ico aria-hidden=true><use href=#icon-edit></use></svg> ملاحظات إضافية للمندوب (اختياري)</label>
                <input type="text" name="notes" id="eoBuyerNotes" placeholder="مثال: الاتصال قبل الوصول بنصف ساعة">
              </div>

              <div class="eo-invoice">
                <div class="eo-inv-row"><span>قيمة المنتجات (<span id="eoInvQty">1</span> قطعة):</span><span><b id="eoInvSub">0</b> <span data-eo-currency>ج.م</span></span></div>
                <div class="eo-inv-row"><span>مصاريف الشحن والتوصيل:</span><span id="eoInvShip">اختر المحافظة</span></div>
                <div class="eo-inv-row" id="eoInvDiscRow" hidden><span>خصم الكمية (<?php echo (int)LP_BUNDLE_DISCOUNT; ?> ج.م لكل قطعة إضافية):</span><span><b id="eoInvDisc">0</b>- <span data-eo-currency>ج.م</span></span></div>
                <div class="eo-inv-row total"><span>الإجمالي المطلوب عند الاستلام:</span><span><b id="eoInvTotal">0</b> <span data-eo-total-currency>ج.م</span></span></div>
              </div>

              <div id="eoCurrencyNotice" class="alert warning" hidden>عملة المنتج تختلف عن رسوم الشحن المصرية. لا يتم تحويل العملات في الواجهة؛ راجع الإدارة قبل الاعتماد المالي.</div>
              <div id="eoErrorMsg" data-ui="u-39395ec1 uev uey ud7 uez uf0 ue9 uf1 ue6 ue5"></div>

              <button type="submit" id="eoSubmitBtn" class="eo-submit-btn">
                <span><svg class=wf-ico aria-hidden=true><use href=#icon-check></use></svg> تأكيد الطلب الآن — الدفع عند الاستلام</span>
              </button>

              <div class="eo-trust-bar">
                <span><svg class=wf-ico aria-hidden=true><use href=#icon-truck></use></svg> شحن سريع لباب البيت</span>
                <span><svg class=wf-ico aria-hidden=true><use href=#icon-shield></use></svg> معاينة قبل الاستلام</span>
                <span><svg class=wf-ico aria-hidden=true><use href=#icon-wallet></use></svg> الدفع كاش عند الاستلام</span>
              </div>
            </form>
          </div>

          <div id="eoSuccessView" data-ui="u-8119510f uev udn uf2">
            <div data-ui="u-c3dd74c3 uf3 uf4 uf5 uf6 uf7 uf8 ue1 ue7 uf9 ufa"></div>
            <h3 data-ui="u-b4c137c4 ufb udu uej ufc">تم تسجيل طلبك بنجاح! </h3>
            <p data-ui="u-aa59976d ufd uek ufe">سيتواصل معك فريق خدمة العملاء قريباً لتأكيد الطلب وموعد التوصيل.</p>
            <div id="eoSuccessSummary" data-ui="u-9898669a udd uff ufg udc ufh udg udh ufi"></div>
            <div data-ui="u-9c9ee36b ue1 ufj uf9 uet">
              <button type="button" class="eo-submit-btn" data-ui="u-44db8333 ufk ufl" onclick="closeEasyOrderModal()">متابعة التسوق</button>
              <a href="<?php echo esc($orders_url); ?>" data-ui="u-6bd08c30 ufm udf uep uej ue6 udg ufn ufo ue7"><svg class=wf-ico aria-hidden=true><use href=#icon-box></use></svg> متابعة طلباتي</a>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <?php
}

// أنجزني: تنظيف عرض اللون ("ابيض * اسود" ← "ابيض + اسود")
function ag_color_show($v) {
    $v = trim((string)$v);
    $v = preg_replace('/\s+/u', ' ', $v);
    $v = preg_replace('/\s*\*\s*/u', ' + ', $v);
    return trim((string)preg_replace('/^[\s\-–—:*]+|[\s\-–—:*]+$/u', '', $v));
}
// أنجزني: فصل "الموديل / اللون" من التسميات المركبة ("ساده ابيض" ← ساده | ابيض)
function ag_split($label) {
    $label = trim((string)$label);
    if ($label === '') return ['', ''];
    static $models = ['أندر ارمور', 'اندر ارمور', 'أندرارمور', 'أندر', 'اندر', 'ساده', 'أديداس', 'اديداس', 'ايديز',
        'نايكي', 'نايك', 'جوردن', 'نقش', 'عــــربي', 'عربي', 'Liner', 'FF', 'LV', 'Versace', 'X'];
    foreach ($models as $m) {
        if (mb_stripos($label, $m) === 0) {
            $rest = preg_replace('/^[\s\-–—:،]+/u', '', mb_substr($label, mb_strlen($m)));
            $rest = trim($rest);
            if ($rest !== '') return [$m, ag_color_show($rest)];
        }
    }
    return ['', ag_color_show($label)];
}
// أنجزني: استخراج نص "وصف الإعلان" من الوصف (بدون الجدول المسطح المكرر وبلا footer "نوع العرض")
function ag_ad_text($desc) {
    $s = trim(str_replace(["\xEF\xBB\xBF", "\xC2\xA0", '&nbsp;'], ['', ' ', ' '], (string)$desc));
    if ($s === '') return '';
    $i = mb_strpos($s, 'نوع العرض');
    if ($i !== false) $s = trim(mb_substr($s, 0, $i));
    if (preg_match('/^:\s*bulk\b/iu', $s)) return '';
    $s = trim((string)preg_replace('/^تفاصيل المنتج\s*وصف الإعلان\s*(?:عروض\s*)?/u', '', $s));
    if (preg_match('/^(?:اللون\s+المقاس\s+%\s+المخزون|المقاس\s+اللون\s+%\s+المخزون|اللون\s+%\s+المخزون|المقاس\s+%\s+المخزون|Model\s+%\s+المخزون|اللون\s+المقاس\s+المخزون|المقاس\s+المخزون|اللون\s+المخزون|Model\s+المخزون|default\s+المخزون)\s+/iu', $s, $m_hdr)) {
        $hdr = $m_hdr[0];
        $s = trim((string)substr($s, strlen($hdr)));
        if (strpos($hdr, '%') !== false) {
            if (preg_match_all('/-?\d+%\s+-?\d+\b/u', $s, $mm, PREG_OFFSET_CAPTURE)) {
                $last = end($mm[0]);
                $byteEnd = $last[1] + strlen($last[0]);
                $s = trim((string)substr($s, $byteEnd));
            }
        } else {
            if (preg_match_all('/(?:متاح|مخزون\s+منخفض|غير\s+متاح)\b/u', $s, $mm, PREG_OFFSET_CAPTURE)) {
                $last = end($mm[0]);
                $byteEnd = $last[1] + strlen($last[0]);
                $s = trim((string)substr($s, $byteEnd));
            }
        }
    }
    $s = trim((string)preg_replace('/^[\s.\-–—:،]+/u', '', $s));
    if (mb_strtolower($s) === 'null') return '';
    $s = trim((string)preg_replace('/\s+\d+(?:\s+\d+){3,}\s*$/u', '', $s));
    $s = trim((string)preg_replace('/[ \t]+/u', ' ', $s));
    return $s;
}
// تفكيك وصف صفقة إلى كتل مبنية: عنوان / عنوان فرعي / نقطة / بند / سطر (تسمية:قيمة) / فقرة / دعوة للطلب / فاصل
function sf_blocks($desc, $plat = 'safqa') {
    $desc = trim(html_entity_decode(str_replace('&nbsp;', ' ', (string)$desc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($desc === '') return [];
    $parts = preg_split('/\r?\n+| {2,}/u', $desc);
    $blocks = [];
    foreach ($parts as $b) {
        $b = trim(preg_replace('/[ \t]+/u', ' ', $b));
        if ($b !== '') $blocks[] = $b;
    }
    $out = []; $state = 0; $first = true; $titleText = null;
    $itemMax = $plat === 'vendoor' ? 130 : 45;
    foreach ($blocks as $b) {
        $b_clean = trim(preg_replace('/^[\p{Extended_Pictographic}\x{2B50}\x{2705}\x{2728}\s•\-–—:：]+|[\s:：.،,]+$/u', '', $b));
        if (preg_match('/^(?:ال\s*|المقاس\s*)?size\s*(?:chart|chqrt|shart|sharp)(?:\s+ال\S+)?$/iu', $b_clean)) continue;
        if (preg_match('/^(?:ال)?(?:مقاس(?:ات)?|تلبيس)\s+بالوزن(?:\s+تقريبي)?$/u', $b_clean)) continue;
        if (preg_match('/^(?:ل{0,2}لينك\s+(?:ال)?(?:ميديا|وسائط)|رابط\s+الوسائط|ميديا\s+إضافية|الميديا\s+الرئيسي[ةه]|link\s*media)/iu', $b_clean)) continue;
        if (preg_match('/(?:01[0125]\d{8}|wa\.me|للمتابعة\s+مع|للتواصل\s+مع|مسؤل\s+التعاقدات|مسئول\s+التعاقدات|مسؤل\s+المتابع|مسئول\s+المتابع|مسؤول\s+المتابع|مسؤول\s+متابع|مسؤول\s+القسم|مدير\s+الحساب\s+المسؤول|التواصل\s+واتس|التواصل\s+عن\s+طريق\s+واتس|رابط\s+واتساب|أرسل\s+رسالة\s+الآن\s+عبر\s+واتساب|عبد\s*الرحمن\s*(?:فوزي|قرطام)|أ?حمد\s*حلمي|مصنع\s+عمران)/iu', $b)) continue;
        if (preg_match('/(?:نصيح[ةه]\s+للمسوق|نصائح\s+.*للمسوق|ملحوظ[ةه]\s+للمسوق|ملاحظ[ةه]\s+للمسوق|تنبيه\s+للمسوق|تنبيه\s+للمبيعات|مواصفات\s+الطلب\s+للمسوق|سلاح\s+الإقناع\s+للمسوق|زاوية\s+البيع\s+السرية|زوايا\s+تسويقية|writing\s*angles|closing\s*tips|sales\s*tips|handling\s*objections|targeting\s*angles|نقطة\s+قوة\s+بيعية|المسوق\s+الناجح|ركز(?:وا)?\s+(?:جداً\s+)?في\s+الإعلان|خاص\s+بالمسوقين|اوردرات\s+العيد\s+للمسوقين|الزاوية\s+الأولى|الزاوية\s+الثانية)/iu', $b)) continue;
        $l = mb_strlen($b);
        if (preg_match('/^[-–—._]{1,6}$/u', $b)) { $out[] = ['t' => 'sep']; $state = 0; $first = false; continue; }
        if ($plat === 'vendoor') {
            if ($l <= 30 && preg_match('/^(?:ل{0,2}لينك\s+ال(?:ميديا|وسائط)|رابط\s+الوسائط)$/u', $b)) continue;
            if (preg_match('/^ضمان\s+/u', $b)) { $out[] = ['t' => 'warranty', 'b' => $b]; continue; }
            if (mb_strpos($b, 'للمتابعة مع') !== false || preg_match('/01\d{9}/', $b)) continue;
            if ($titleText !== null && $b === $titleText) { $out[] = ['t' => 'para', 'b' => $b]; $state = 0; continue; }
        }
        if ($first) {
            $first = false;
            if ($l <= 120 && !preg_match('/\.$/u', $b) && ($l <= 65 || preg_match('/^[\p{Extended_Pictographic}\x{2B50}\x{2705}\x{2728}]/u', $b))) {
                $out[] = ['t' => 'title', 'b' => $b];
                $titleText = $b;
                continue;
            }
        }
        if (preg_match('/^👇/u', $b)) { $out[] = ['t' => 'cta', 'b' => $b]; $state = 0; continue; }
        if ($l <= 110 && preg_match('/^[\p{Extended_Pictographic}\x{2B50}\x{2705}\x{2728}]/u', $b) && preg_match('/[.!؟?،:]$/u', $b)) {
            $out[] = ['t' => 'bullet', 'b' => $b];
            $state = 0;
            continue;
        }
        if ($l <= 90 && preg_match('/^(.{2,30}?)\s*[:：]\s*(\S.*)$/u', $b, $mm)) {
            $out[] = ['t' => 'kv', 'a' => trim($mm[1]), 'b' => trim($mm[2])];
            $state = 0;
            continue;
        }
        if ($state === 1 && $l <= $itemMax) { $out[] = ['t' => 'item', 'b' => $b]; continue; }
        if ($l <= 25 && !preg_match('/[.،,]$/u', $b)) { $out[] = ['t' => 'head', 'b' => $b]; $state = 1; continue; }
        $out[] = ['t' => 'para', 'b' => $b];
        $state = 0;
    }
    return $out;
}
// تقسيم النص إلى فقرات قصيرة (جمل متجمعة حتى ~130 حرف + حدود الإيموجي)
function th_paras($txt) {
    $txt = trim(preg_replace('/\s+/u', ' ', (string)$txt));
    if ($txt === '') return [];
    $chunks = preg_split('/(?<=[\s.])(?=\p{Extended_Pictographic})/u', $txt, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chunks) $chunks = [$txt];
    $paras = []; $cur = '';
    foreach ($chunks as $ch) {
        foreach (preg_split('/(?<=[.!؟])\s+/u', trim($ch), -1, PREG_SPLIT_NO_EMPTY) as $s) {
            $s = trim($s);
            if ($s === '') continue;
            if ($cur === '') $cur = $s;
            elseif (mb_strlen($cur) + 1 + mb_strlen($s) <= 130) $cur .= ' ' . $s;
            else { $paras[] = $cur; $cur = $s; }
        }
    }
    if ($cur !== '') $paras[] = $cur;
    if (count($paras) > 1 && mb_strlen($paras[count($paras) - 1]) < 45) {
        $last = array_pop($paras);
        $paras[count($paras) - 1] = trim($paras[count($paras) - 1] . ' ' . $last);
    }
    return $paras;
}
// تقطيع قيمة الألوان إلى شرائح (تتوقف عند أول كلمة غير لون)
function th_color_chips($v) {
    $v = (string)$v;
    $v = preg_replace('/^\s*(?:لون|لونان|لونين|الون)\s+(?:واحد|واحده|واحدة)?\s*(?:فقط)?\s*/u', '', $v);
    $v = preg_replace('/^\s*[\d]+\s*(?:قطعة|قطعتين|ألوان|الوان|لون|لونان)?\s*\(?/u', '', $v);
    $v = preg_replace('/^\s*(?:\p{Extended_Pictographic}|\x{FE0F}|\x{200D}|\s)+/u', '', $v);
    $x = preg_match('/\p{Extended_Pictographic}/u', $v, $m, PREG_OFFSET_CAPTURE);
    if ($x && $m[0][1] > 0) $v = substr($v, 0, $m[0][1]);
    $v = preg_replace('/\s*الوصف\s+التسويق.*$/u', '', $v);
    $v = str_replace(['*', '/', '،', '+', '(', ')'], ' ', $v);
    $stop = ['جدول', 'المقاسات', 'مقاسات', 'مقاس', 'المقاس', 'المتوفره', 'المتوفرة', 'المتاحه', 'المتاح', 'المتاحة', 'المتوفر', 'متوفره', 'متوفرة', 'متوفر', 'متوفرن', 'علي', 'على', 'حسب', 'الوزن', 'وزن', 'كجم', 'كغ', 'سم', 'بوكس', 'قطعة', 'قطع', 'شامل', 'سلسلة', 'انسيال', 'خاتم', 'حلق', 'مميزات', 'مميز', 'فوائد', 'يضيف', 'لمسة', 'أنيقة', 'انيقة', 'وفخمة', 'فخمة', 'تصميم', 'مناسب', 'مناسبة', 'سهل', 'سهلة', 'للاستخدام', 'اليومي', 'اليومية', 'المناسبات', 'اطلالة', 'إطلالة', 'يناسب', 'ناعمة', 'عملية', 'راحة', 'شياكة', 'عالية', 'ممتاز', 'ممتازة', 'ساعة', 'سوار', 'طقم', 'لون', 'ألوان', 'الوان', 'الالوان', 'اللون', 'لونين', 'الوانين', 'من', 'إلى', 'الي', 'الى', 'و', 'في', 'مع', 'أو', 'او'];
    $out = [];
    foreach ((array)preg_split('/\s+/u', trim($v), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $t = preg_replace('/^[\s.:\-–—“”‘’«»"\'،؛ـ：]+|[\s.:\-–—“”‘’«»"\'،؛ـ：]+$/u', '', (string)$t);
        if ($t === '' || preg_match('/^[\p{S}\p{C}]+$/u', $t)) continue;
        if (in_array($t, $stop, true)) break;
        if (preg_match('/\d/u', $t)) break;
        if (mb_strlen($t) < 2 || mb_strlen($t) > 14) break;
        if (in_array($t, $out, true)) continue;
        $out[] = $t;
        if (count($out) >= 10) break;
    }
    return $out;
}
// مصدر كلمات اللون: ما بعد "الألوان ..." في التسمية + القيمة
function th_color_src($lab, $val) {
    $rest = '';
    if (preg_match('/لوان/u', (string)$lab)) $rest = (string)preg_replace('/^.*?لوان\s*(?:المتوفرة|المتوفره|المتاحه|المتاح|المتاحة|المتوفر)?\s*/u', '', (string)$lab);
    return trim($rest . ' ' . (string)$val);
}
function th_clean_val($v) {
    $v = (string)$v;
    $v = preg_replace('/\s*و\s*بعد\s+الفحص.*$/u', '', $v);
    $v = preg_replace('/\s*(?:⚠️\s*|\x{FE0F})?تنويه\s+(?:هام|هم|هما).*$/u', '', $v);
    $v = preg_replace('/\s*و?\s*بمجرد\s+اعتماد.*$/u', '', $v);
    $v = preg_split('/•/u', $v)[0];
    return trim(preg_replace('/\s+/u', ' ', (string)$v));
}
function th_colons($s) {
    $out = [];
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        if ($s[$i] === ':') $out[] = $i;
        elseif (substr($s, $i, 3) === "\xEF\xBC\x9A") $out[] = $i;
    }
    return $out;
}
function th_colon($s, $from, $maxlen = 8) {
    $end = min(strlen($s), $from + $maxlen);
    for ($i = $from; $i < $end; $i++) {
        if ($s[$i] === ':') return $i;
        if (substr($s, $i, 3) === "\xEF\xBC\x9A") return $i;
    }
    return false;
}
function th_junk_re() { return '/^[\s\d.\-*:"“”\'’«»،,▪■◆●·•\x{25AA}\x{25A0}\x{25C6}\x{25CF}\x{2022}\x{FE0F}\x{200D}\x{26A0}]*$/u'; }
// تقطيع نص إلى صفوف [تسمية, قيمة] + مقاطع نص حر (يبدأ من التجزئة على • وأسطر جديدة)
function th_pairs($txt) {
    $rows = [];
    $txt = (string)$txt;
    $junk = th_junk_re();
    $known = ['تصنيف المنتج', 'التغليف', 'الخامه', 'الخامة', 'الماتريال', 'التقفيل', 'جودة المنتج بشكل عام', 'جوده المنتج بشكل عام', 'اسم المنتج', 'الألوان المتوفرة', 'الألوان المتوفره', 'الوان المتوفره', 'الوان المتوفرة', 'الالوان المتوفره', 'المقاسات المتوفرة', 'مقاسات متوفره', 'المقاسات المتاحه', 'المقاسات المتاحة', 'خامة المنتج', 'خامه المنتج', 'المقاسات'];
    foreach ((array)preg_split('/[•\n]+/u', $txt) as $raw) {
        $seg = trim(preg_replace('/\s+/u', ' ', (string)$raw));
        if ($seg === '' || preg_match($junk, $seg)) continue;
        $cols = th_colons($seg);
        if (count($cols) === 0) { $rows[] = ['', $seg]; continue; }
        if (count($cols) === 1) {
            $lab = trim(substr($seg, 0, $cols[0]));
            $val = trim(substr($seg, $cols[0] + 1));
            if ($lab !== '' && mb_strlen($lab) <= 45 && !preg_match($junk, $lab)) $rows[] = [$lab, $val];
            else $rows[] = ['', $seg];
            continue;
        }
        $anch = [];
        foreach ($known as $k) {
            $off = 0; $kl = strlen($k);
            while (($x = strpos($seg, $k, $off)) !== false) {
                $off = $x + 1;
                if ($x > 0 && !preg_match('/[\s"“”\'’«»]$/u', substr($seg, 0, $x))) continue;
                $c = th_colon($seg, $x + $kl, 8);
                if ($c === false) continue;
                $anch[] = [$x, $c];
            }
        }
        $c0 = $cols[0];
        if ($c0 <= 90) {
            $covered = false;
            foreach ($anch as $a) if ($a[0] <= 25 && $a[1] <= $c0) $covered = true;
            $lab0 = trim(substr($seg, 0, $c0));
            if (!$covered && $lab0 !== '' && mb_strlen($lab0) <= 45 && !preg_match($junk, $lab0)) $anch[] = [0, $c0];
        }
        if (!$anch) { $rows[] = ['', $seg]; continue; }
        usort($anch, function ($a, $b) { return $a[0] - $b[0]; });
        $clean = [];
        foreach ($anch as $a) { if ($clean && $a[0] <= $clean[count($clean) - 1][1]) continue; $clean[] = $a; }
        $cursor = 0; $n = count($clean);
        for ($i = 0; $i < $n; $i++) {
            $aStart = $clean[$i][0]; $aCol = $clean[$i][1];
            $before = trim(substr($seg, $cursor, $aStart - $cursor));
            if ($before !== '' && !preg_match($junk, $before)) $rows[] = ['', $before];
            $vEnd = ($i + 1 < $n) ? $clean[$i + 1][0] : strlen($seg);
            $lab = trim(substr($seg, $aStart, $aCol - $aStart));
            $val = trim(substr($seg, $aCol + 1, $vEnd - $aCol - 1));
            if ($lab !== '') $rows[] = [$lab, $val];
            $cursor = $vEnd;
        }
        $tail = trim(substr($seg, $cursor));
        if ($tail !== '' && !preg_match($junk, $tail)) $rows[] = ['', $tail];
    }
    return $rows;
}
function th_section($desc, $texts, $emoji) {
    foreach ($texts as $t) { $x = strpos($desc, $t); if ($x !== false) return $x; }
    return strpos($desc, $emoji);
}
function th_trim_end($s) {
    $s = (string)$s;
    $s = preg_replace('/[\s\x{2500}-\x{257F}\-–—]*\d(?:\x{FE0F})?\x{20E3}\s*$/u', '', $s);
    $s = preg_replace('/[\s،,\x{2500}-\x{257F}\-–—]+$/u', '', $s);
    return trim($s);
}
function th_qr_rows($ex) {
    $qr = is_array($ex['quality_report'] ?? null) ? $ex['quality_report'] : [];
    $rows = [];
    if (is_numeric($qr['rating'] ?? null) && (float)$qr['rating'] > 0) $rows[] = ['⭐ التقييم', (string)$qr['rating']];
    foreach (['category' => 'تصنيف المنتج', 'packaging' => 'التغليف', 'material' => 'الخامة', 'finishing' => 'التقفيل', 'overall' => 'جودة المنتج بشكل عام'] as $k => $lab) {
        $v = th_clean_val((string)($qr[$k] ?? ''));
        if ($v !== '') $rows[] = [$lab, mb_substr($v, 0, 90)];
    }
    return $rows;
}
// استخراج نسبة التسليم من نص وإزالته
function th_deliv_from($txt, &$out) {
    if ($out !== '' || !is_string($txt) || $txt === '') return $txt;
    $pats = [
        '/(?:نسبة|نسبه)\s+التسليم[^\d]{0,70}(\d+)(?!\d)\s*%?\s*[^0-9]{0,15}(\d+)(?!\d)\s*%?/u',
        '/(?:نسبة|نسبه)\s+التسليم[^\d]{0,70}(\d+)(?!\d)\s*%?/u',
    ];
    foreach ($pats as $i => $pat) {
        if (preg_match($pat, $txt, $dm, PREG_OFFSET_CAPTURE)) {
            $out = ($i === 0) ? ('%' . $dm[1][0] . ' إلى %' . $dm[2][0]) : ($dm[1][0] . '%');
            $start = $dm[0][1];
            $w = strpos($txt, 'بعد الفحص');
            if ($w !== false && $w < $start) $start = $w;
            if (preg_match('/(?:[\s\p{Extended_Pictographic}\x{FE0F}\x{200D}])+$/u', substr($txt, 0, $start), $mm)) $start = max(0, $start - strlen($mm[0]));
            return substr($txt, 0, $start) . substr($txt, $dm[0][1] + strlen($dm[0][0]));
        }
    }
    return $txt;
}
// استخراج التنويه (آخر النص) وإزالته
function th_warn_from($txt, &$out) {
    if ($out !== '' || !is_string($txt) || $txt === '') return $txt;
    $pats = [
        '/(?:⚠\s?\x{FE0F}?\s*)?تنويه(?:\s+(?:هام|هم|هما))?\s*[:：]?\s*(.+)$/su',
        '/⚠\s?\x{FE0F}?\s*ملاحظة(?:\s+(?:هامة|هم))?\s*[:：]?\s*(.+)$/su',
        '/\s*بمجرد\s+اعتماد.+$/su',
    ];
    foreach ($pats as $pat) {
        if (preg_match($pat, $txt, $wm, PREG_OFFSET_CAPTURE)) {
            $out = preg_replace('/^[\x{FE0F}\x{200D}]+/u', '', trim($wm[count($wm) - 1][0]));
            $start = $wm[0][1];
            if (preg_match('/(?:[\s\p{Extended_Pictographic}\x{FE0F}\x{200D}])+$/u', substr($txt, 0, $start), $mm)) $start = max(0, $start - strlen($mm[0]));
            return substr($txt, 0, $start);
        }
    }
    return $txt;
}
// تحليل وصف تايم هاند إلى: مقدمة / جودة / تسليم / تنويه / تفاصيل / تسويقي
function th_parse($desc, $ex) {
    $r = ['marked' => false, 'intro' => '', 'q' => [], 'deliv' => '', 'warn' => '', 'det' => [], 'mkt' => ''];
    $desc = (string)$desc;
    $ex = is_array($ex) ? $ex : [];
    $hq = th_section($desc, ['تقييم جودة المنتج', 'تقيم جوده المنتج'], '1️⃣');
    $hd = th_section($desc, ['تفاصيل المنتج'], '2️⃣');
    $hm = th_section($desc, ['الوصف التسويقي'], '3️⃣');
    if ($hd !== false && $hq !== false && $hd < $hq) $hq = false;
    if ($hm !== false && $hd !== false && $hm < $hd) $hm = false;
    if ($hm !== false && $hq !== false && $hm < $hq) $hm = false;
    if ($hq === false && $hd === false) {
        $md = trim((string)($ex['marketing_desc'] ?? ''));
        $det = $desc;
        if ($md !== '' && strpos($desc, $md) !== false) $det = trim(substr($desc, 0, strpos($desc, $md)));
        $det = th_deliv_from($det, $r['deliv']);
        $det = th_warn_from($det, $r['warn']);
        foreach (preg_split('/\n/u', $det) as $ln) {
            $ln = trim(preg_replace('/\s+/u', ' ', (string)$ln));
            $ln = preg_replace('/\d\x{FE0F}?\x{20E3}/u', ' ', $ln);
            $ln = trim(preg_replace('/\s+/u', ' ', (string)$ln));
            if ($ln !== '' && !preg_match(th_junk_re(), $ln)) $r['det'][] = ['', $ln];
        }
        $qrn = 0;
        foreach (['category', 'packaging', 'material', 'finishing', 'overall'] as $k) if (trim((string)($ex['quality_report'][$k] ?? '')) !== '') $qrn++;
        if ($qrn >= 2) $r['q'] = null;
    } else {
        $r['marked'] = true;
        $first = $hq !== false ? $hq : $hd;
        $intro = th_trim_end(preg_replace('/[\x{2500}-\x{257F}]{2,}/u', ' ', (string)substr($desc, 0, $first)));
        $intro = th_deliv_from($intro, $r['deliv']);
        $intro = th_warn_from($intro, $r['warn']);
        $r['intro'] = th_trim_end($intro);
        $qsec = '';
        if ($hq !== false) {
            $qend = strlen($desc);
            if ($hd !== false && $hd > $hq) $qend = $hd;
            elseif ($hm !== false && $hm > $hq) $qend = $hm;
            $qsec = (string)substr($desc, $hq, $qend - $hq);
            $qsec = preg_replace('/(?:1️⃣\s*)?(?:تقييم\s+جودة|تقيم\s+جوده)\s+المنتج\s*(?:-\s*\d+)?/u', '', $qsec, 1);
            $qsec = preg_replace('/^\s*[-–—\s]*1️⃣\s*/u', '', $qsec);
            $qsec = preg_replace('/[\x{2500}-\x{257F}]{2,}/u', ' ', $qsec);
            $qsec = th_trim_end($qsec);
            $qsec = th_deliv_from($qsec, $r['deliv']);
            $qsec = th_warn_from($qsec, $r['warn']);
            $qsec = preg_replace('/\s+و$/u', '', $qsec);
            foreach (th_pairs($qsec) as $row) {
                if ($row[0] === '') continue;
                if (preg_match('/تقييم|تقيم|تنويه/u', $row[0])) continue;
                if (preg_match('/\p{Extended_Pictographic}/u', $row[0]) || mb_strlen($row[0]) > 40) continue;
                $val = th_clean_val($row[1]);
                if (mb_strlen($val) > 90) {
                    $t = mb_substr($val, 0, 90);
                    $sp = mb_strrpos($t, ' ');
                    $val = ($sp !== false && $sp > 50) ? mb_substr($t, 0, $sp) : $t;
                }
                if ($val !== '') $r['q'][] = [$row[0], $val];
            }
        }
        if ($hd !== false) {
            $dend = ($hm !== false && $hm > $hd) ? $hm : strlen($desc);
            $ds = (string)substr($desc, $hd, $dend - $hd);
            foreach (['1️⃣ تقييم', 'تقييم جودة المنتج', 'تقيم جوده المنتج'] as $cutn) {
                $ci = strpos($ds, $cutn);
                if ($ci !== false) { $ds = substr($ds, 0, $ci); break; }
            }
            $ds = preg_replace('/(?:2️⃣\s*)?تفاصيل\s+المنتج/u', '', $ds, 1);
            $ds = preg_replace('/^\s*[-–—\s]*2️⃣\s*/u', '', $ds);
            $ds = preg_replace('/[\x{2500}-\x{257F}]{2,}/u', "\n", $ds);
            $ds = th_trim_end($ds);
            foreach (th_pairs($ds) as $row) {
                $lab = trim(preg_replace('/\s+/u', ' ', preg_replace('/\d\x{FE0F}?\x{20E3}/u', ' ', (string)$row[0])));
                $val = trim(preg_replace('/\s+/u', ' ', preg_replace('/\d\x{FE0F}?\x{20E3}/u', ' ', (string)$row[1])));
                if ($lab === '' && $val === '') continue;
                if (strpos($lab . ' ' . $val, 'اسم المنتج') !== false) {
                    $src = ($lab === '') ? $val : ($lab . ' ' . $val);
                    $v = preg_replace('/[\\\\\/]*\s*اسم\s+المنتج\s*[:：]?\s*/u', ' ', $src);
                    $v = trim(preg_replace('/^[\s:：\/\\\\]+|[\s:：\/\\\\]+$/u', '', (string)$v));
                    if ($v !== '') $r['det'][] = ['اسم المنتج', $v];
                    continue;
                }
                if ($lab !== '' && (preg_match('/\p{Extended_Pictographic}/u', $lab) || mb_strlen($lab) > 40)) {
                    $r['det'][] = ['', $lab . ': ' . $val];
                    continue;
                }
                if ($lab !== '' && preg_match('/أ?لوان|الوان/u', $lab)) {
                    $chips = th_color_chips(th_color_src($lab, $val));
                    if ($chips) {
                        $r['det'][] = [$lab, implode(' ', $chips)];
                    } else {
                        if (mb_strlen($val) > 90) {
                            $t = mb_substr($val, 0, 90);
                            $sp = mb_strrpos($t, ' ');
                            $val = ($sp !== false && $sp > 50) ? mb_substr($t, 0, $sp) : $t;
                        }
                        $r['det'][] = [$lab, $val];
                    }
                    continue;
                }
                if ($lab === '' && preg_match(th_junk_re(), $val)) continue;
                $r['det'][] = [$lab, $val];
            }
        }
        if ($hm !== false) {
            $ms = (string)substr($desc, $hm);
            $h2 = strpos($ms, 'الوصف التسويقي', 1);
            if ($h2 !== false) {
                $mid = preg_replace('/\s*[-–—]*\s*(?:3️⃣\s*)?الوصف\s+التسويق[\p{L}]*\s*(?:للسوشيال\s*\.?\s*ميديا)?/u', '', (string)substr($ms, 0, $h2));
                $mid = preg_replace('/[\s\x{2500}-\x{257F}\-–—\d\x{FE0F}\x{20E3}\x{200D}]+/u', '', $mid);
                if ($mid === '') $ms = (string)substr($ms, $h2);
                else $ms = (string)substr($ms, 0, $h2);
            }
            $ms = preg_replace('/\s*[-–—]*\s*(?:3️⃣\s*)?الوصف\s+التسويق[\p{L}]*\s*(?:للسوشيال\s*\.?\s*ميديا)?/u', '', $ms);
            $ms = preg_replace('/^\s*[-–—\s]*(?:\d\x{FE0F}?\x{20E3}\s*)?/u', '', $ms);
            $r['mkt'] = th_trim_end(preg_replace('/[\x{2500}-\x{257F}]{2,}/u', ' ', $ms));
        }
    }
    if ($r['q'] === null || (empty($r['q']) && $r['marked'])) $r['q'] = th_qr_rows($ex);
    if ($r['deliv'] === '' && trim((string)($ex['delivery_rate'] ?? '')) !== '') $r['deliv'] = trim((string)$ex['delivery_rate']);
    if ($r['mkt'] === '') {
        $md = trim((string)($ex['marketing_desc'] ?? ''));
        if ($md !== '') {
            $md = preg_replace('/\s*[-–—]*\s*(?:3️⃣\s*)?الوصف\s+التسويق[\p{L}]*\s*(?:للسوشيال\s*\.?\s*ميديا)?/u', '', $md);
            $md = preg_replace('/^\s*[-–—\s]*(?:\d\x{FE0F}?\x{20E3}\s*)?/u', '', $md);
            $r['mkt'] = th_trim_end(preg_replace('/[\x{2500}-\x{257F}]{2,}/u', ' ', $md));
        }
    }
    if ($r['mkt'] !== '' && preg_match('/^\s*[-–—"\'’]*\s*(?:2️⃣\s*)?تفاصيل\s+المنتج/u', $r['mkt'])) {
        if (!$r['det']) $r['det'][] = ['', $r['mkt']];
        $r['mkt'] = '';
    }
    if ($r['mkt'] !== '' && preg_match('/(?:الألوان|الوان|الالوان)\s+المتوفر|جدول\s+المقاسات|(?:خامة|خامه|الماتريال)\s+المنتج/u', mb_substr($r['mkt'], 0, 60))) {
        if (!$r['det']) $r['det'][] = ['', $r['mkt']];
        $r['mkt'] = '';
    }
    return $r;
}

// استخراج ملخص تسويقي نظيف للمنتج (للإعلانات، الكروت، وبطاقات المتجر بدون أي نصوص مخزون أو تكلفة)
// شيل روابط Google Drive من أي نص معروض للعميل (الميديا بتتجهز من لوحة المسوق)
function wf_strip_drive_links($text) {
    $text = (string)$text;
    if ($text === '' || stripos($text, 'drive.google') === false) return $text;
    $text = preg_replace('~https?://(?:drive|docs)\.google\.com/\S+~iu', '', $text);
    $text = preg_replace('~drive\.google\.com/\S*~iu', '', $text);
    $text = preg_replace('/[ \t]{2,}/u', ' ', $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    return trim($text);
}

function product_marketing_summary($p, $plat = '') {
    return wf_strip_drive_links(product_marketing_summary_raw($p, $plat));
}

function product_marketing_summary_raw($p, $plat = '') {
    if (!is_array($p)) return '';
    $plat = $plat ?: ($p['_plat'] ?? resolve_plat($p['id'] ?? ''));
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $dname = aff_clean($p['name'] ?? '', true);
    $raw_desc = aff_clean(html_entity_decode(str_replace('&nbsp;', ' ', (string)($p['desc'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($plat === 'timehand') {
        $th = th_parse($raw_desc, $ex);
        $parts = [];
        if (!empty($th['mkt'])) {
            $parts[] = $th['mkt'];
        }
        if (!empty($th['det'])) {
            foreach ($th['det'] as $dr) {
                $line = trim(($dr[0] !== '' ? $dr[0] . ': ' : '') . $dr[1]);
                if ($line !== '' && $line !== $dname) $parts[] = '• ' . $line;
            }
        }
        if ($parts) return implode("\n", $parts);
    } elseif ($plat === 'angazny') {
        $ag = ag_ad_text($raw_desc);
        if ($ag === '' && !empty($ex['marketing_desc'])) $ag = ag_ad_text((string)$ex['marketing_desc']);
        if ($ag !== '') return $ag;
    } elseif ($plat === 'm5zoon') {
        $mz = mz_desc_data($p, $ex, $dname);
        $parts = [];
        foreach ($mz['prose'] ?? [] as $pr) {
            if (!empty($pr['b'])) $parts[] = $pr['b'];
        }
        foreach ($mz['feat'] ?? [] as $ft) {
            $parts[] = '✅ ' . $ft;
        }
        if ($parts) return implode("\n", $parts);
        $clean_mz = mz_clean_prose($raw_desc);
        if ($clean_mz !== '') return $clean_mz;
    } else {
        $blks = sf_blocks($raw_desc, $plat);
        if ($blks) {
            $parts = [];
            foreach ($blks as $b) {
                if (($b['t'] ?? '') === 'sep') continue;
                if (($b['t'] ?? '') === 'kv') $parts[] = '• ' . $b['a'] . ': ' . $b['b'];
                elseif (!empty($b['b'])) $parts[] = $b['b'];
            }
            if ($parts) return implode("\n", $parts);
        }
    }
    return $raw_desc !== '' ? $raw_desc : $dname;
}

// عرض تفاصيل ومواصفات المنتج بشكل منظم وموحد في الموقع الرئيسي وصفحة الهبوط والمتجر
function render_product_details_html($p, $plat = '', $opts = []) {
    if (!is_array($p)) return '';
    $plat = $plat ?: ($p['_plat'] ?? resolve_plat($p['id'] ?? ''));
    $ex = isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [];
    $is_landing = !empty($opts['is_landing']);
    $in_root = !empty($opts['in_root']);
    $dname = aff_clean($p['name'] ?? '', true) ?: ($p['name'] ?? 'منتج');
    $raw_desc = aff_clean(html_entity_decode(str_replace('&nbsp;', ' ', (string)($p['desc'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (!empty($ex['description_html'])) {
        $ex['description_html'] = aff_clean_html($ex['description_html']);
    }

    $colors_clean = clean_product_colors($p, $plat);
    $sizes_clean = clean_product_sizes($p, $plat);
    $cat_name = wf_normalize_category($p['category'] ?? '');
    $country_name = trim((string)($p['country'] ?? 'مصر'));
    $sku_val = trim((string)($p['sku'] ?? ''));
    if ($sku_val === '' && $plat === 'm5zoon' && function_exists('mz_prod_code')) {
        $sku_val = mz_prod_code($p);
    }

    $fix_img = function ($u) use ($in_root) {
        $u = trim((string)$u);
        if ($u === '') return '';
        if (preg_match('~^https?://|^data:~i', $u)) return $u;
        $u = ltrim(str_replace('../', '', asset_url($u)), '/');
        return $in_root ? $u : ('../' . $u);
    };

    ob_start();
    $is_dup_line = function ($txt) use ($dname) {
        $t = trim(preg_replace('/^[\s•\-–—✅✨🔥🎨📏📦🛡️⭐]+/u', '', (string)$txt));
        if ($t === '' || $t === $dname) return true;
        if (preg_match('/^(?:اسم\s+المنتج|الألوان\s+المتاحة|الالوان\s+المتاحة|الألوان\s+المتوفره?|المقاسات\s+المتاحة|المقاسات\s+المتوفره?)\s*[:：]/u', $t)) return true;
        return false;
    };
    ?>
    <div class="wf-prod-details" data-ui="u-bbc63b79 ue1 ufp ufq ufr udi">

        <!-- 1. الوصف التفصيلي والمميزات (مرتبة تحت بعض بدون تكرار) -->
        <?php if ($plat === 'timehand'):
            $th = th_parse($raw_desc, $ex);
            $thHas = ($th['intro'] !== '' || !empty($th['q']) || !empty($th['det']) || $th['mkt'] !== '');
        ?>
            <?php if ($thHas): ?>
                <?php if ($th['intro'] !== '' && !$is_dup_line($th['intro'])): ?>
                <div data-ui="u-18526c3b ufs udd ude ufg uda udh uer ue1 ufp uft">
                    <?php foreach (th_paras($th['intro']) as $pp): if ($is_dup_line($pp)) continue; ?>
                    <p data-ui="u-b1c192b6 ufu"><?php echo esc($pp); ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($th['mkt'])):
                    $mktBlocks = sf_blocks($th['mkt'], 'safqa');
                ?>
                <div data-ui="u-4bce1c57 ufv ufw ufx ufg ue1 ufp ues">
                    <h4 data-ui="u-ab49a913 udu ufy uee ufz">✨ الوصف والمميزات</h4>
                    <?php if ($mktBlocks): foreach ($mktBlocks as $mb):
                        if (!empty($mb['b']) && $is_dup_line($mb['b'])) continue;
                    ?>
                        <?php if ($mb['t'] === 'bullet' || $mb['t'] === 'item'): ?>
                            <div data-ui="u-587c503d ug0 ueg ufx ug1 ue9 ufd ueq udi"><?php echo esc($mb['b']); ?></div>
                        <?php elseif ($mb['t'] === 'kv'): if ($is_dup_line($mb['a'] . ': ' . $mb['b'])) continue; ?>
                            <div data-ui="u-fc888cb4 ug0 ueg ude ue9 ufd ue1 ufp ug2"><b data-ui="u-6b87f6a7 ufz"><?php echo esc($mb['a']); ?></b><span data-ui="u-0cbb32a0 udi ueq"><?php echo esc($mb['b']); ?></span></div>
                        <?php elseif (!empty($mb['b'])): ?>
                            <p data-ui="u-88cefbc7 ufu uda udh udi ug3"><?php echo esc($mb['b']); ?></p>
                        <?php endif; ?>
                    <?php endforeach; else: ?>
                        <?php foreach (th_paras($th['mkt']) as $mp): if ($is_dup_line($mp)) continue; ?>
                            <p data-ui="u-88cefbc7 ufu uda udh udi ug3"><?php echo esc($mp); ?></p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($th['det'])): ?>
                <div data-ui="u-5c9de8d6 ufv udd ude ufg ue1 ufp ues">
                    <h4 data-ui="u-2cdd8e73 udu ufy uee uej">📋 تفاصيل المنتج</h4>
                    <?php foreach ($th['det'] as $dr):
                        $dl = trim((string)$dr[0]);
                        $dv = trim((string)$dr[1]);
                        if ($dl === 'اسم المنتج' || ($dl !== '' && preg_match('/أ?لوان|الوان|مقاس/u', $dl))) continue;
                        if ($dl === '' && $is_dup_line($dv)) continue;
                    ?>
                        <?php if ($dl !== ''): ?>
                        <div data-ui="u-2fc48133 ueg ude ue9 ug0 ue1 ue2 ufj ue7 ufd">
                            <span data-ui="u-b9561a7b udy ueq"><?php echo esc($dl); ?></span>
                            <b data-ui="u-543b2bfb uej ug4"><?php echo esc($dv); ?></b>
                        </div>
                        <?php else: ?>
                            <?php foreach (th_paras($dv) as $pp): if ($is_dup_line($pp)) continue; ?>
                            <div data-ui="u-cbb533ed ueg ude ue9 ug0 ufd ug5 uer"><?php echo esc($pp); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($th['q'])): ?>
                <div data-ui="u-0c485645 ufv uf6 ug6 ufg ue1 ufp ues">
                    <h4 data-ui="u-4c5c067e udu ufy uee ug7">🛡️ مواصفات الجودة</h4>
                    <?php foreach ($th['q'] as $qr): ?>
                    <div data-ui="u-f441b74b ueg ug6 ue9 ug0 ue1 ue2 ues ue7 ufd">
                        <span data-ui="u-048f1631 uek ueq"><?php echo esc($qr[0]); ?></span>
                        <b data-ui="u-cef22b7a ug7"><?php echo esc($qr[1]); ?></b>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div data-ui="u-787df983 ufv udd ude ufg udh uda"><?php echo nl2br(esc($raw_desc ?: $dname)); ?></div>
            <?php endif; ?>

        <?php elseif ($plat === 'm5zoon'):
            $mzD = mz_desc_data($p, $ex, $dname);
            $mzProse = $mzD['prose'] ?? [];
            $mzFeat = $mzD['feat'] ?? [];
            if (!$mzProse && !$mzFeat) {
                $cleanMz = mz_clean_prose($raw_desc);
                if ($cleanMz !== '') $mzProse = [['h' => '', 'b' => $cleanMz]];
            }
        ?>
            <?php if ($mzProse): ?>
            <div data-ui="u-5c9de8d6 ufv udd ude ufg ue1 ufp ues">
                <h4 data-ui="u-2cdd8e73 udu ufy uee uej">📝 وصف المنتج</h4>
                <?php $mzLastH = null; foreach ($mzProse as $mzp): ?>
                    <?php if ($mzp['h'] !== '' && $mzp['h'] !== $mzLastH && !$is_dup_line($mzp['h'])): $mzLastH = $mzp['h']; ?>
                    <h5 data-ui="u-ce45b0e7 udu ug8 uda ufz"><?php echo esc($mzLastH); ?></h5>
                    <?php endif; ?>
                    <?php foreach (th_paras($mzp['b']) as $mzpp): if ($is_dup_line($mzpp)) continue; ?>
                    <p data-ui="u-57ce491f uda udh uer ufu"><?php echo esc($mzpp); ?></p>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($mzFeat): ?>
            <div data-ui="u-0c485645 ufv uf6 ug6 ufg ue1 ufp ues">
                <h4 data-ui="u-4c5c067e udu ufy uee ug7">✅ مميزات المنتج</h4>
                <?php foreach ($mzFeat as $mzf): if ($is_dup_line($mzf)) continue; ?>
                <div data-ui="u-29e370b6 ufd ug5 udi ug0 ueg ug6 ug9 ue9 ueq"><?php echo esc($mzf); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (trim((string)($ex['dimensions'] ?? '')) !== ''): ?>
            <div data-ui="u-b91a8af0 ufs uga ugb udf">
                <h4 data-ui="u-2ff489cc ue6 ufy ufd ugc">📐 الأبعاد والقياسات</h4>
                <div data-ui="u-03872f9d ufd ugd udi"><?php echo nl2br(esc($ex['dimensions'])); ?></div>
            </div>
            <?php endif; ?>

        <?php else:
            $src_text = $raw_desc;
            if ($plat === 'angazny') {
                $ag_t = ag_ad_text($raw_desc);
                if ($ag_t === '' && !empty($ex['marketing_desc'])) $ag_t = ag_ad_text((string)$ex['marketing_desc']);
                if ($ag_t !== '') $src_text = $ag_t;
            }
            $blocks = sf_blocks($src_text, $plat === 'vendoor' ? 'vendoor' : 'safqa');
        ?>
            <?php if ($blocks): ?>
            <div data-ui="u-e09bf5ad ue1 ufp ues">
                <?php foreach ($blocks as $b):
                    if (!empty($b['b']) && $is_dup_line($b['b'])) continue;
                ?>
                    <?php if ($b['t'] === 'title'): ?>
                        <div data-ui="u-c076a543 uea ufw ufx udf udu uge ufz"><?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'head'): ?>
                        <h4 data-ui="u-c4a586e9 udu ug8 uge uej"><?php echo esc($b['b']); ?></h4>
                    <?php elseif ($b['t'] === 'bullet'): ?>
                        <div data-ui="u-ae04ae78 ug0 uf6 ug6 ug9 ue9 ufd ug5 udi ueq"><?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'item'): ?>
                        <div data-ui="u-625c78f5 ug0 udd ude ugf ue9 ufd ug5 uer ug3"><?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'kv'): if (preg_match('/أ?لوان|الوان|مقاس|اسم المنتج/u', (string)$b['a'])) continue; ?>
                        <div data-ui="u-4ac67933 ue1 ue2 ugg ugh ug0 udd ude ue9 ufd"><span data-ui="u-b9561a7b udy ueq"><?php echo esc($b['a']); ?></span><b data-ui="u-543b2bfb uej ug4"><?php echo esc($b['b']); ?></b></div>
                    <?php elseif ($b['t'] === 'warranty'): ?>
                        <div data-ui="u-3d032e80 ug0 uf6 ugi ue9 udg ue6 ugj">🛡️ <?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'cta'): ?>
                        <div data-ui="u-c95ee1a6 uea uga ugb ue9 ufd ue6 ugc udn"><?php echo esc($b['b']); ?></div>
                    <?php elseif ($b['t'] === 'sep'): ?>
                    <?php else: ?>
                        <div data-ui="u-c73100d9 uda ugk uer ug0 udd ude ue9">
                            <?php foreach (th_paras($b['b']) as $pp): if ($is_dup_line($pp)) continue; ?>
                            <p data-ui="u-21445313 ugl"><?php echo esc($pp); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php elseif (!empty($ex['description_html'])): ?>
                <div class="product-description-body" data-ui="u-5fca13d5 udh uer uda"><?php echo $ex['description_html']; ?></div>
            <?php else: ?>
                <div data-ui="u-68a89660 ufv udd ude ufg udh uda uer"><?php echo nl2br(esc($src_text ?: $dname)); ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- 2. المواصفات الأساسية مرتبة تحت بعض بدون تكرار -->
        <div data-ui="u-d5ccb671 ue1 ufp ues ueg ude ufg ufv">
            <h4 data-ui="u-2cdd8e73 udu ufy uee uej">📋 المواصفات</h4>
            <?php if ($cat_name !== ''): ?>
            <div data-ui="u-cfe2e3f5 ue1 ue2 ue7 ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">القسم</span>
                <b data-ui="u-f73bb9d7 uej"><?php echo esc($cat_name); ?></b>
            </div>
            <?php endif; ?>
            <?php if ($sku_val !== '' && !$is_landing): ?>
            <div data-ui="u-cfe2e3f5 ue1 ue2 ue7 ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">كود المنتج</span>
                <b dir="ltr" data-ui="u-f48fa36b uej ugm"><?php echo esc($sku_val); ?></b>
            </div>
            <?php endif; ?>
            <?php if (!empty($ex['material'])): ?>
            <div data-ui="u-cfe2e3f5 ue1 ue2 ue7 ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">الخامة / المادة</span>
                <b data-ui="u-f73bb9d7 uej"><?php echo esc($ex['material']); ?></b>
            </div>
            <?php endif; ?>
            <?php if (!empty($ex['weight'])): ?>
            <div data-ui="u-cfe2e3f5 ue1 ue2 ue7 ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">الوزن</span>
                <b data-ui="u-f73bb9d7 uej"><?php echo esc($ex['weight']); ?></b>
            </div>
            <?php endif; ?>
            <?php if (!empty($colors_clean)): ?>
            <div data-ui="u-cd2d7a9d ue1 ufp uft ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">🎨 الألوان المتاحة</span>
                <div data-ui="u-e7351c2c ue1 uft uet">
                    <?php foreach (array_slice($colors_clean, 0, 20) as $cln): ?>
                    <span data-ui="u-f507e374 ueg ugn uej ugo uds uf1 ue6"><?php echo esc($cln); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($sizes_clean)): ?>
            <div data-ui="u-cd2d7a9d ue1 ufp uft ug0 udd ude ue9 ufd">
                <span data-ui="u-b9561a7b udy ueq">📏 المقاسات المتاحة</span>
                <div data-ui="u-e7351c2c ue1 uft uet">
                    <?php foreach (array_slice($sizes_clean, 0, 25) as $szn): ?>
                    <span data-ui="u-409df7bf uga ugb ugc ugo ugp uf1 ue6"><?php echo esc($szn); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 3. جدول المقاسات وصور التفاصيل مرتبة تحت بعض -->
        <?php
        $scHtml = !empty($ex['size_chart']) ? vd_size_chart_html((string)$ex['size_chart']) : '';
        $detImgsRaw = array_values(array_filter((array)($ex['detail_images'] ?? []), function ($u) use ($p) {
            return is_string($u) && trim($u) !== '' && !in_array($u, (array)($p['images'] ?? []), true);
        }));
        ?>
        <?php if ($scHtml !== '' || !empty($detImgsRaw)): ?>
        <div data-ui="u-a2a3da72 ufv udd ude ufg ue1 ufp ugg">
            <h4 data-ui="u-188bfe09 udu ufu uee uej">📏 جدول المقاسات وصور التوضيح</h4>
            <?php if ($scHtml !== ''): ?>
            <div><?php echo $scHtml; ?></div>
            <?php endif; ?>
            <?php if (!empty($detImgsRaw)): ?>
            <div data-ui="u-703b0517 ue1 ufp ugg">
                <?php foreach (array_slice($detImgsRaw, 0, 8) as $dim):
                    $dimUrl = $fix_img($dim);
                ?>
                <img loading="lazy" referrerpolicy="no-referrer" src="<?php echo esc($dimUrl); ?>" onclick="if(typeof openImgModal==='function')openImgModal(this.src)" onerror="this.style.display='none'" data-ui="u-309cfb72 uem ugq ugr ueg udf ude ugs ugt">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 4. الأسئلة الشائعة مرتبة تحت بعض -->
        <?php if (!empty($ex['faqs']) && is_array($ex['faqs'])): ?>
        <div data-ui="u-3408f2d0 udd ude ufg ufv ue1 ufp ufj">
            <h4 data-ui="u-188bfe09 udu ufu uee uej">❓ أسئلة شائعة</h4>
            <?php foreach (array_slice($ex['faqs'], 0, 6) as $f): ?>
            <div data-ui="u-7bc496cd ugu ueg ude ue9">
                <b data-ui="u-f42ba091 ufd uej ugv uew"><?php echo is_array($f) ? esc($f['q'] ?? $f['question'] ?? '') : ''; ?></b>
                <p data-ui="u-33e0306e ufu udg uek ugw"><?php echo is_array($f) ? esc(mb_substr($f['a'] ?? $f['answer'] ?? '', 0, 350)) : esc(mb_substr((string)$f, 0, 250)); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($ex['specifications']) || !empty($ex['features'])): ?>
        <div class="wf-admin-product-specs" style="margin:14px 0;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff">
            <?php if (!empty($ex['features'])): ?><h4>المميزات</h4><div><?php echo nl2br(esc((string)$ex['features'])); ?></div><?php endif; ?>
            <?php if (!empty($ex['specifications'])): ?><h4>المواصفات</h4><div><?php echo nl2br(esc((string)$ex['specifications'])); ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (empty($opts['hide_media']) && function_exists('wf_product_media_html')) echo wf_product_media_html($p, $in_root, $plat); ?>
    </div>
    <?php
    return ob_get_clean();
}

// =========================================================================
// نظام الإحصائيات وتتبع الزوار والسلة واللاڤ (Real-Time Analytics Engine)
// =========================================================================
function visitor_id() {
    if (!empty($_SESSION['wf_vid'])) return $_SESSION['wf_vid'];
    if (!empty($_COOKIE['wf_vid']) && preg_match('/^[a-zA-Z0-9_\-]{8,40}$/', (string)$_COOKIE['wf_vid'])) {
        $_SESSION['wf_vid'] = (string)$_COOKIE['wf_vid'];
        return $_SESSION['wf_vid'];
    }
    $vid = 'v_' . substr(md5(uniqid((string)mt_rand(), true) . ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
    $_SESSION['wf_vid'] = $vid;
    if (!headers_sent()) {
        @setcookie('wf_vid', $vid, time() + 365 * 86400, '/', '', false, true);
    }
    return $vid;
}

function wf_client_ip() {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    return substr($ip, 0, 45);
}

// مفتاح الزائر اليومي حسب الـ IP: نفس الشخص (نفس الشبكة) لو دخل 100 مرة في اليوم
// يتحسب مرة واحدة، وبيتحسب من جديد تاني يوم. لو الـ IP مش متاح بنرجع لمعرّف الزائر.
function wf_daily_visitor_key() {
    $ip = function_exists('wf_client_ip') ? wf_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '') return 'ip_' . $ip;
    return function_exists('visitor_id') ? visitor_id() : '';
}

function visitor_meta() {
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) $dev = 'تابلت 📲';
    elseif (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) $dev = 'موبايل 📱';
    else $dev = 'كمبيوتر 💻';

    if (strpos($ua, 'fbav') !== false || strpos($ua, 'fban') !== false) $br = 'Facebook App';
    elseif (strpos($ua, 'instagram') !== false) $br = 'Instagram App';
    elseif (strpos($ua, 'edg') !== false) $br = 'Edge';
    elseif (strpos($ua, 'chrome') !== false) $br = 'Chrome';
    elseif (strpos($ua, 'safari') !== false) $br = 'Safari';
    elseif (strpos($ua, 'firefox') !== false) $br = 'Firefox';
    else $br = 'متصفح آخر';

    $ref_h = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!empty($_GET['ref']) || !empty($_SESSION['aff_ref'])) $src = 'رابط مسوق 📣';
    elseif ($ref_h === '' || ($host !== '' && strpos($ref_h, $host) !== false)) $src = 'دخول مباشر 🌐';
    elseif (strpos($ref_h, 'facebook') !== false || strpos($ref_h, 'fb.com') !== false) $src = 'فيسبوك 📘';
    elseif (strpos($ref_h, 'instagram') !== false) $src = 'انستجرام 📸';
    elseif (strpos($ref_h, 'tiktok') !== false) $src = 'تيك توك 🎵';
    elseif (strpos($ref_h, 'google') !== false) $src = 'بحث جوجل 🔍';
    elseif (strpos($ref_h, 't.me') !== false || strpos($ref_h, 'telegram') !== false) $src = 'تيليجرام ✈️';
    elseif (strpos($ref_h, 'wa.me') !== false || strpos($ref_h, 'whatsapp') !== false) $src = 'واتساب 💬';
    else $src = 'رابط خارجي 🔗';

    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $page_map = [
        'index.php' => 'الرئيسية 🏠',
        'product.php' => 'صفحة منتج 📦',
        'products.php' => 'تصفح المنتجات 🛍️',
        'quick_order.php' => 'طلب رقمي/سوشيال ⚡',
        'cart.php' => 'سلة التسوق 🛒',
        'checkout_cart.php' => 'إتمام الشراء 💳',
        'orders.php' => 'الطلبات 🧾',
        'marketer.php' => 'لوحة المسوق 📣',
        'support.php' => 'شات الدعم 💬',
        'product_requests.php' => 'طلب منتج بالصورة 📸',
        'balance.php' => 'الرصيد 💰',
        'shipping.php' => 'الشحن 🚚',
        'login.php' => 'تسجيل الدخول 🔐',
        'register.php' => 'تسجيل مسوق ✨',
    ];
    $page_lbl = $page_map[$script] ?? $script;
    return ['device' => $dev, 'browser' => $br, 'source' => $src, 'page' => $page_lbl, 'script' => $script];
}

function track_site_visit() {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['admin.php', 'admin_shipping.php'], true)) return;
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua !== '' && preg_match('/bot|crawl|spider|slurp|curl|python|wget/i', $ua)) return;

    $vid = visitor_id();
    $now = time();
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    // منع تكرار الكتابة في نفس الثانية لنفس الرابط
    if (($_SESSION['_last_pv_uri'] ?? '') === $uri && ($now - (int)($_SESSION['_last_pv_ts'] ?? 0)) < 5) {
        return;
    }
    $_SESSION['_last_pv_uri'] = $uri;
    $_SESSION['_last_pv_ts'] = $now;

    // حدث موحّد (يغذّي الإحصائيات والـ Funnel)
    if (function_exists('wf_event')) {
        wf_event('page_view', ['page' => mb_substr((string)($_SERVER['SCRIPT_NAME'] ?? ''), 0, 80)]);
    }

    $meta = visitor_meta();
    $cu = current_user();
    $today = date('Y-m-d');

    $an = jload('analytics.json', []);
    if (!is_array($an)) $an = [];
    if (!isset($an['total_pageviews'])) $an['total_pageviews'] = 0;
    if (!isset($an['visitors']) || !is_array($an['visitors'])) $an['visitors'] = [];
    if (!isset($an['daily']) || !is_array($an['daily'])) $an['daily'] = [];
    if (!isset($an['pages']) || !is_array($an['pages'])) $an['pages'] = [];
    if (!isset($an['sources']) || !is_array($an['sources'])) $an['sources'] = [];
    if (!isset($an['devices']) || !is_array($an['devices'])) $an['devices'] = [];

    $an['total_pageviews'] = (int)$an['total_pageviews'] + 1;
    $an['pages'][$meta['page']] = (int)($an['pages'][$meta['page']] ?? 0) + 1;

    $is_new_visitor = !isset($an['visitors'][$vid]);
    if ($is_new_visitor) {
        $an['sources'][$meta['source']] = (int)($an['sources'][$meta['source']] ?? 0) + 1;
        $an['devices'][$meta['device']] = (int)($an['devices'][$meta['device']] ?? 0) + 1;
        $an['visitors'][$vid] = [
            'first' => date('c', $now),
            'last' => date('c', $now),
            'last_ts' => $now,
            'hits' => 1,
            'user' => $cu['email'] ?? '',
            'name' => $cu['name'] ?? '',
            'device' => $meta['device'],
            'browser' => $meta['browser'],
            'source' => $meta['source'],
            'last_page' => $meta['page'],
            'last_uri' => mb_substr($uri, 0, 120),
            'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 15),
        ];
    } else {
        $an['visitors'][$vid]['last'] = date('c', $now);
        $an['visitors'][$vid]['last_ts'] = $now;
        $an['visitors'][$vid]['hits'] = (int)($an['visitors'][$vid]['hits'] ?? 1) + 1;
        $an['visitors'][$vid]['last_page'] = $meta['page'];
        $an['visitors'][$vid]['last_uri'] = mb_substr($uri, 0, 120);
        if (!empty($cu['email'])) $an['visitors'][$vid]['user'] = $cu['email'];
        if (!empty($cu['name'])) $an['visitors'][$vid]['name'] = $cu['name'];
    }

    // حفظ آخر 3000 زائر بحد أقصى للحفاظ على الأداء
    if (count($an['visitors']) > 3000) {
        $an['visitors'] = array_slice($an['visitors'], -3000, null, true);
    }

    if (!isset($an['daily'][$today])) {
        $an['daily'][$today] = ['views' => 0, 'uv' => [], 'carts' => 0, 'loves' => 0, 'orders' => 0];
    }
    $an['daily'][$today]['views'] = (int)($an['daily'][$today]['views'] ?? 0) + 1;
    if (!isset($an['daily'][$today]['uv']) || !is_array($an['daily'][$today]['uv'])) $an['daily'][$today]['uv'] = [];
    $an['daily'][$today]['uv'][wf_daily_visitor_key()] = 1;
    if (count($an['daily']) > 60) {
        $an['daily'] = array_slice($an['daily'], -60, null, true);
    }

    jsave('analytics.json', $an);
}

function track_cart_add($plat, $id, $pname, $qty = 1, $price = 0, $variant = '', $img = '') {
    $cu = current_user();
    $vid = visitor_id();
    $meta = visitor_meta();
    $clean_name = aff_clean($pname, true);
    $cart_log = jload('cart_log.json', []);
    if (!is_array($cart_log)) $cart_log = [];
    $cart_log[] = [
        'vid' => $vid,
        'user' => !empty($cu['email']) ? $cu['email'] : $vid,
        'name' => !empty($cu['name']) ? $cu['name'] : 'زائر',
        'plat' => $plat,
        'id' => (string)$id,
        'pname' => mb_substr($clean_name !== '' ? $clean_name : $pname, 0, 120),
        'qty' => max(1, (int)$qty),
        'price' => (float)$price,
        'variant' => mb_substr((string)$variant, 0, 80),
        'img' => (string)$img,
        'device' => $meta['device'],
        'at' => date('c')
    ];
    jsave('cart_log.json', array_slice($cart_log, -5000));

    $today = date('Y-m-d');
    $an = jload('analytics.json', []);
    if (is_array($an)) {
        if (!isset($an['daily'][$today])) $an['daily'][$today] = ['views' => 0, 'uv' => [], 'carts' => 0, 'loves' => 0, 'orders' => 0];
        $an['daily'][$today]['carts'] = (int)($an['daily'][$today]['carts'] ?? 0) + 1;
        jsave('analytics.json', $an);
    }
    // حدث موحّد في جدول events (يغذّي الـ Funnel: زيارة ← منتج ← سلة ← أوردر)
    if (function_exists('wf_event')) {
        wf_event('add_to_cart', [
            'product_id' => (string)$id,
            'plat'       => (string)$plat,
            'ref'        => active_ref(),
            'qty'        => max(1, (int)$qty),
            'value'      => round((float)$price, 2),
            'name'       => mb_substr($clean_name !== '' ? $clean_name : $pname, 0, 120),
        ]);
    }
}

// ============================================================================
//  مخزن الأحداث الموحّد (Events Store) — أساس كل الإحصائيات
//  كل حدث: type · visitor_id · user · ref · product · path · device · country · at
// ============================================================================
// حماية من تضخّم ملف الأحداث: الملف الكبير بيفضل يتقرا ويتكتب مع كل زيارة،
// وده اللي بيوقّع الصفحات على الاستضافة المشتركة (ذاكرة/وقت) بخطأ «تعذر تحميل الصفحة مؤقتًا».
function wf_events_guard() {
    static $done = false;
    if ($done) return;
    $done = true;
    $p = DATA_DIR . '/wf_events.json';
    if (!is_file($p)) return;
    if ((int)@filesize($p) < 3500000) return;
    // الملف كبر أوي لدرجة إن فكه بيستهلك ذاكرة كبيرة: نأرشفه (يُحفظ في data/ المحمي) ونبدأ مخزن نظيف
    @rename($p, DATA_DIR . '/wf_events_archive_' . date('Ymd-His') . '.json');
}

function wf_event($type, $extra = []) {
    $type = trim((string)$type);
    if ($type === '') return false;
    if (function_exists('is_bot_request') && is_bot_request()) return false;
    wf_events_guard();

    $vid  = function_exists('visitor_id') ? visitor_id() : session_id();
    $meta = function_exists('visitor_meta') ? visitor_meta() : ['device' => '', 'country' => ''];
    $cu   = function_exists('current_user') ? current_user() : null;
    $day  = date('Y-m-d');

    $row = array_merge([
        'type'       => $type,
        'vid'        => $vid,
        'sid'        => substr(session_id(), 0, 24),
        'user'       => $cu['email'] ?? '',
        'ref'        => function_exists('active_ref') ? active_ref() : '',
        'path'       => mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 200),
        'referrer'   => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 200),
        'ip'         => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'ua'         => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
        'device'     => $meta['device'] ?? '',
        'country'    => $meta['country'] ?? '',
        'day'        => $day,
        'at'         => date('c'),
    ], is_array($extra) ? $extra : []);

    // احتساب فريد لكل (يوم، زائر، نوع، منتج)
    $is_new_visitor_today = false;
    $is_unique = true;
    // الزائر اليومي بيتحسب بالـ IP (مرة واحدة في اليوم لكل شبكة) بدل معرّف الكوكيز
    $dkey = function_exists('wf_daily_visitor_key') ? wf_daily_visitor_key() : (string)($row['vid'] ?? '');

    jupdate('wf_events.json', function (&$ev) use ($row, $dkey, &$is_new_visitor_today, &$is_unique) {
        if (!is_array($ev)) $ev = ['rows' => [], 'uniq' => [], 'visitors' => []];
        if (!isset($ev['rows']) || !is_array($ev['rows'])) $ev['rows'] = [];
        if (!isset($ev['uniq']) || !is_array($ev['uniq'])) $ev['uniq'] = [];
        if (!isset($ev['visitors']) || !is_array($ev['visitors'])) $ev['visitors'] = [];

        $ukey = $row['day'] . '|' . $row['vid'] . '|' . $row['type'] . '|' . (string)($row['product_id'] ?? '');
        if (isset($ev['uniq'][$ukey])) $is_unique = false;
        else $ev['uniq'][$ukey] = 1;

        if (!isset($ev['visitors'][$row['day']])) $ev['visitors'][$row['day']] = [];
        if (!isset($ev['visitors'][$row['day']][$dkey])) {
            $ev['visitors'][$row['day']][$dkey] = ['first' => $row['at'], 'last' => $row['at'], 'hits' => 0];
            $is_new_visitor_today = true;
        }
        $ev['visitors'][$row['day']][$dkey]['last'] = $row['at'];
        $ev['visitors'][$row['day']][$dkey]['hits']++;

        $ev['rows'][] = $row;
        // سقف أقل: كل حدث بيعيد قراءة/كتابة الملف كله، فالحجم الكبير بيبطّل الموقع ويوقّعه
        if (count($ev['rows']) > 6000) $ev['rows'] = array_slice($ev['rows'], -3000);
        // تنظيف الفهارس القديمة (نحتفظ بآخر أيام قليلة بس)
        if (count($ev['uniq']) > 6000) {
            $wf_cut_day = date('Y-m-d', time() - 4 * 86400);
            $ev['uniq'] = array_filter($ev['uniq'], function ($k) use ($wf_cut_day) { return strncmp((string)$k, $wf_cut_day, 10) >= 0; });
        }
        if (count($ev['visitors']) > 45) $ev['visitors'] = array_slice($ev['visitors'], -30, null, true);
        return true;
    }, ['rows' => [], 'uniq' => [], 'visitors' => []]);

    // عدّاد لكل منتج
    if (!empty($row['product_id']) && function_exists('jupdate')) {
        jupdate('product_counters.json', function (&$pc) use ($row, $type, $is_unique) {
            if (!is_array($pc)) $pc = [];
            $k = (string)$row['product_id'];
            if (!isset($pc[$k])) $pc[$k] = ['views' => 0, 'carts' => 0, 'likes' => 0, 'orders' => 0, 'uv' => []];
            if ($type === 'product_view')                       $pc[$k]['views']++;
            if ($type === 'add_to_cart')                        $pc[$k]['carts']++;
            if ($type === 'like')                               $pc[$k]['likes']++;
            if ($type === 'order')                              $pc[$k]['orders']++;
            if ($is_unique && $type === 'product_view') {
                if (!isset($pc[$k]['uv']) || !is_array($pc[$k]['uv'])) $pc[$k]['uv'] = [];
                $pc[$k]['uv'][$row['vid']] = 1;
            }
            return true;
        }, []);
    }
    return true;
}

// إحصائيات سريعة من مخزن الأحداث
function wf_stats($days = 30) {
    wf_events_guard();
    $ev = jload('wf_events.json', []);
    $rows = (isset($ev['rows']) && is_array($ev['rows'])) ? $ev['rows'] : [];
    $visitors = (isset($ev['visitors']) && is_array($ev['visitors'])) ? $ev['visitors'] : [];

    $cut = date('Y-m-d', time() - ((int)$days * 86400));
    $daily = [];

    // زوّار فريدون لكل يوم
    foreach ($visitors as $d => $v) {
        if ($d < $cut) continue;
        $daily[$d]['uv'] = is_array($v) ? count($v) : 0;
    }
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $d = (string)($r['day'] ?? substr((string)($r['at'] ?? ''), 0, 10));
        if ($d === '' || $d < $cut) continue;
        if (!isset($daily[$d])) $daily[$d] = ['uv' => 0];
        foreach (['views','product_views','carts','loves','orders','revenue'] as $k) {
            if (!isset($daily[$d][$k])) $daily[$d][$k] = 0;
        }
        $t = (string)($r['type'] ?? '');
        if ($t === 'page_view')    $daily[$d]['views']++;
        if ($t === 'product_view') $daily[$d]['product_views']++;
        if ($t === 'add_to_cart')  $daily[$d]['carts']++;
        if ($t === 'like')         $daily[$d]['loves']++;
        if ($t === 'order') {
            $daily[$d]['orders']++;
            $daily[$d]['revenue'] += (float)($r['value'] ?? 0);
        }
    }
    ksort($daily);
    $daily = array_slice($daily, -max(1, (int)$days), null, true);

    $totals = ['views' => 0, 'uv' => 0, 'product_views' => 0, 'carts' => 0, 'loves' => 0, 'orders' => 0, 'revenue' => 0];
    foreach ($daily as $r) {
        foreach ($totals as $k => $v) $totals[$k] += (float)($r[$k] ?? 0);
    }
    return ['days' => $daily, 'totals' => $totals];
}
// ============================================================================
//  مصدر الزيارة (متجر / صفحة هبوط) + إحصاءات روابط المسوق: كام زائر وكام طلب
//  قراءة فقط: لا تغيير في الأسعار أو العمولات أو الأرصدة.
// ============================================================================
function wf_traffic_src($src = '') {
    $clean = preg_replace('/[^a-z_]/', '', strtolower((string)$src));
    if ($clean !== '' && empty($_SESSION['wf_src'])) $_SESSION['wf_src'] = $clean; // أول لمسة للزائر
    return (string)($_SESSION['wf_src'] ?? '');
}

// يستخرج (plat#, id) من مسار صفحة (صفحة هبوط أو منتج) — بدون أي طلب شبكة
function wf_link_key_from_uri($uri) {
    $uri = (string)$uri;
    if ($uri === '') return ['', ''];
    $q = [];
    $parts = parse_url($uri);
    $path = (string)($parts['path'] ?? '');
    if (!empty($parts['query'])) parse_str($parts['query'], $q);
    $id = (string)($q['id'] ?? '');
    if ($id === '') return ['', ''];
    $plat = (string)($q['plat'] ?? '');
    if ($plat === '') $plat = function_exists('resolve_plat') ? resolve_plat($id) : '';
    return [$plat, $id];
}

/**
 * إحصاءات روابط المسوق: زيارات + طلبات لكل منتج/صفحة هبوط، ومجمّعة حسب القسم،
 * وزوار المتجر وطلباته. المصادر: سجل الأحداث + سجل النقرات + الطلبات المسجلة.
 */
function wf_marketer_link_stats($code, $days = 0) {
    $code = trim((string)$code);
    // 'clients' لازم يتعمل فاضية: من غيره count($store['clients']) بيرمي Fatal على tabs الروابط وصفحات الهبوط
    $empty_store = ['key' => 'store', 'visitors' => [], 'clients' => [], 'views' => 0, 'orders' => 0, 'qty' => 0, 'earn' => 0.0, 'last' => ''];
    $out = [
        'links'   => [],
        'landing' => ['pages' => 0, 'visitors' => 0, 'views' => 0, 'orders' => 0, 'qty' => 0, 'clients' => 0, 'earn' => 0.0],
        'store'   => array_merge($empty_store, ['visitors' => 0, 'clients' => 0]),
        'clicks'  => ['total' => 0, 'unique' => 0],
        'orders'  => ['total' => 0, 'qty' => 0, 'earn' => 0.0, 'attributed' => 0],
        'visits'  => ['visitors' => 0, 'views' => 0],
        'meta'    => ['ms' => 0, 'truncated' => false, 'rows' => 0, 'error' => ''],
    ];
    if ($code === '') return $out;

    // 🛡 الاستضافة المشتركة: التبويبات دي بتتندّى أكتر من مرة في نفس الطلب → نتيجة الطلب الواحد بتتخزن في الذاكرة
    static $wf_ls_memo = [];
    $wf_ls_key = $code . '|' . (int)$days;
    if (isset($wf_ls_memo[$wf_ls_key])) return $wf_ls_memo[$wf_ls_key];
    $wf_ls_t0 = microtime(true);
    $wf_ls_dead = false;
    // ميزانية زمن/ميموري: لو السجل كبير جدًا، نوقف التجميع ونقول للمستخدم بدل ما السيرفر يقتل الصفحة
    $wf_ls_budget = static function () use (&$wf_ls_dead, $wf_ls_t0) {
        if ($wf_ls_dead) return true;
        if ((microtime(true) - $wf_ls_t0) > 1.8 || memory_get_usage(true) > (240 * 1048576)) $wf_ls_dead = true;
        return $wf_ls_dead;
    };

    wf_events_guard();
    $since = $days > 0 ? time() - ((int)$days * 86400) : 0;
    $links = [];
    $store = $empty_store;
    $all_vids = [];
    $total_views = 0;

    $seed = function ($key) use (&$links) {
        if (!isset($links[$key])) {
            $links[$key] = ['key' => $key, 'visitors' => [], 'views' => 0, 'orders' => 0, 'qty' => 0, 'earn' => 0.0, 'last' => '', 'urls' => [], 'clients' => []];
        }
        return $key;
    };
    $touch = function ($key, $vid, $ts, $view = true) use (&$links, $seed) {
        $seed($key);
        if ($vid !== '') $links[$key]['visitors'][$vid] = max((int)($links[$key]['visitors'][$vid] ?? 0), (int)$ts);
        if ($view) $links[$key]['views']++;
        if ($ts > strtotime($links[$key]['last'] ?: '2000-01-01')) $links[$key]['last'] = date('c', (int)$ts);
    };

    // 1) سجل الأحداث الموحّد (اضغطات الصفحة + مشاهدات المنتج/المتجر/الهبوط + الطلبات)
    $we = jload('wf_events.json', []);
    $rows = (isset($we['rows']) && is_array($we['rows'])) ? array_slice($we['rows'], -6000) : [];
    foreach ($rows as $row) {
        if ($wf_ls_budget()) break;
        if (!is_array($row) || (string)($row['ref'] ?? '') !== $code) continue;
        $ts = strtotime((string)($row['at'] ?? '')) ?: 0;
        if ($since && $ts && $ts < $since) continue;
        $type = (string)($row['type'] ?? '');
        $vid = (string)($row['vid'] ?? '');
        if ($type === 'store_view') {
            $store['views']++;
            if ($vid !== '') $store['visitors'][$vid] = $ts;
            if ($ts > strtotime($store['last'] ?: '2000-01-01')) $store['last'] = date('c', $ts);
            continue;
        }
        if (in_array($type, ['landing_view', 'product_view', 'page_view'], true)) {
            $key = '';
            if ($type === 'landing_view') {
                $plat = (string)($row['plat'] ?? '');
                $pid = (string)($row['product_id'] ?? '');
                if ($pid === '') { list($plat, $pid) = wf_link_key_from_uri((string)($row['path'] ?? '')); }
                if ($pid !== '') $key = ($plat !== '' ? $plat : 'physical') . '#' . $pid;
            } elseif ($type === 'product_view') {
                $plat = (string)($row['plat'] ?? '');
                $pid = (string)($row['product_id'] ?? '');
                if ($pid === '' && !empty($row['path'])) { list($plat, $pid) = wf_link_key_from_uri((string)$row['path']); }
                if ($pid !== '') $key = ($plat !== '' ? $plat : 'physical') . '#' . $pid;
            }
            if ($key !== '') $touch($key, $vid, $ts, true);
        }
    }

    // 2) أحداث الزيارات من متعقّب الصفحات (يشمل زيارات قبل تشغيل حدث الهبوط/المتجر)
    foreach (array_slice((array)jload('events.json', []), -3000) as $ev) {
        if ($wf_ls_budget()) break;
        if (!is_array($ev) || (string)($ev['marketer_id'] ?? '') !== $code) continue;
        $ts = strtotime((string)($ev['created_at'] ?? '')) ?: 0;
        if ($since && $ts && $ts < $since) continue;
        $type = (string)($ev['type'] ?? '');
        if (!in_array($type, ['page_view', 'product_view'], true)) continue;
        $uri = (string)($ev['uri'] ?? '');
        $vid = (string)($ev['visitor_id'] ?? '');
        if ($uri !== '' && strpos($uri, 'store.php') !== false) {
            $store['views']++;
            if ($vid !== '') $store['visitors'][$vid] = $ts;
            if ($ts > strtotime($store['last'] ?: '2000-01-01')) $store['last'] = date('c', $ts);
            continue;
        }
        list($plat, $pid) = wf_link_key_from_uri($uri);
        if ($pid !== '') $touch(($plat !== '' ? $plat : 'physical') . '#' . $pid, $vid, $ts, true);
    }

    // 3) الطلبات المسجلة (كام واحد طلب فعلاً من كل رابط)
    $source_totals = [];
    foreach ((array)jload('orders.json', []) as $o) {
        if ($wf_ls_budget()) break;
        if (!is_array($o) || (string)($o['ref'] ?? '') !== $code) continue;
        $ts = strtotime((string)($o['at'] ?? '')) ?: 0;
        if ($since && $ts && $ts < $since) continue;
        $plat = (string)($o['plat'] ?? '');
        $pid = (string)($o['id'] ?? '');
        $key = ($pid !== '' ? (($plat !== '' ? $plat : 'physical') . '#' . $pid) : '');
        $src = (string)($o['src'] ?? '');
        // الفصل: طلب المتجر يُحسب للمتجر فقط، وطلب صفحة الهبوط يُحسب لصفحة الهبوط فقط
        $is_store_order = ($src === 'store');
        $client = preg_replace('/\D+/', '', (string)($o['cphone'] ?? ($o['customer']['phone'] ?? '')));
        $source_totals[$src !== '' ? $src : 'unknown'] = ($source_totals[$src !== '' ? $src : 'unknown'] ?? 0) + 1;
        if ($src !== '') $out['orders']['attributed']++;
        $qty = max(1, (int)($o['qty'] ?? 1));
        $earn = (float)($o['m_earn'] ?? 0);
        $out['orders']['total']++;
        $out['orders']['qty'] += $qty;
        $out['orders']['earn'] += $earn;
        if (!$is_store_order && $key !== '') {
            $seed($key);
            $links[$key]['orders']++;
            $links[$key]['qty'] += $qty;
            $links[$key]['earn'] += $earn;
            if ($client !== '') $links[$key]['clients'][$client] = 1;
            if ($ts > strtotime($links[$key]['last'] ?: '2000-01-01')) $links[$key]['last'] = date('c', $ts);
        }
        if ($is_store_order) {
            $store['orders']++;
            $store['qty'] += $qty;
            $store['earn'] += $earn;
            if ($client !== '') $store['clients'][$client] = 1;
        }
    }

    // 4) النقرات على الروابط (كام واحد ضغط الرابط)
    $clicks_total = 0;
    $click_vids = [];
    foreach ((array)jload('clicks.json', []) as $c) {
        if ($wf_ls_budget()) break;
        if (!is_array($c) || (string)($c['ref'] ?? '') !== $code) continue;
        $ts = strtotime((string)($c['at'] ?? '')) ?: 0;
        if ($since && $ts && $ts < $since) continue;
        $clicks_total++;
        $click_vids[(string)($c['ip'] ?? '')] = 1;
    }
    $mc = jload('marketer_clicks.json', []);
    $unique_clicks = 0;
    if (is_array($mc) && isset($mc[$code]) && is_array($mc[$code])) {
        $unique_clicks = is_array($mc[$code]['unique_visitors'] ?? null) ? count($mc[$code]['unique_visitors']) : 0;
        if ($clicks_total === 0) $clicks_total = (int)($mc[$code]['total_clicks'] ?? 0);
    }
    if ($unique_clicks === 0) $unique_clicks = count($click_vids);

    // ترتيب: الأكثر طلبات ثم الأكثر زواراً ثم الأكثر مشاهدة
    $landing_tot = ['pages' => 0, 'visitors' => 0, 'views' => 0, 'orders' => 0, 'qty' => 0, 'clients' => 0, 'earn' => 0.0];
    foreach ($links as $key => $row) {
        $links[$key]['visitors_count'] = count($row['visitors']);
        unset($links[$key]['visitors']);
        $links[$key]['clients_count'] = count($row['clients']);
        unset($links[$key]['clients']);
        $all_vids[$key] = $links[$key]['visitors_count'];
        $total_views += (int)$links[$key]['views'];
        if ((int)$links[$key]['views'] > 0 || (int)$links[$key]['orders'] > 0) $landing_tot['pages']++;
        $landing_tot['visitors'] += (int)$links[$key]['visitors_count'];
        $landing_tot['views']    += (int)$links[$key]['views'];
        $landing_tot['orders']   += (int)$links[$key]['orders'];
        $landing_tot['qty']      += (int)$links[$key]['qty'];
        $landing_tot['clients']  += (int)$links[$key]['clients_count'];
        $landing_tot['earn']     += (float)$links[$key]['earn'];
    }
    $landing_tot['earn'] = round($landing_tot['earn'], 2);
    uasort($links, function ($a, $b) {
        return [$b['orders'], $b['visitors_count'], $b['views']] <=> [$a['orders'], $a['visitors_count'], $a['views']];
    });

    $out['links'] = $links;
    $out['landing'] = $landing_tot;
    $out['store'] = ['key' => 'store', 'visitors' => count((array)($store['visitors'] ?? [])), 'clients' => count((array)($store['clients'] ?? [])), 'views' => (int)($store['views'] ?? 0), 'orders' => (int)($store['orders'] ?? 0), 'qty' => (int)($store['qty'] ?? 0), 'earn' => round((float)($store['earn'] ?? 0), 2), 'last' => (string)($store['last'] ?? '')];
    $out['clicks'] = ['total' => $clicks_total, 'unique' => $unique_clicks];
    $out['visits'] = ['visitors' => array_sum($all_vids), 'views' => $total_views];
    $out['sources'] = $source_totals;
    $out['meta'] = ['ms' => (int)round((microtime(true) - $wf_ls_t0) * 1000), 'truncated' => $wf_ls_dead, 'rows' => count($links), 'error' => ''];
    $wf_ls_memo[$wf_ls_key] = $out;
    return $out;
}

/**
 * نسخة آمنة لإحصاءات الروابط: على الاستضافة المشتركة أي انحراف في ملفات السجل
 * لازم يعرض تنبيه بصفحة سليمة، مش صفحة «تعذر تحميل الصفحة مؤقتًا».
 */
function wf_marketer_link_stats_safe($code, $days = 0, &$cats = null) {
    $cats = [];
    $zero = function () {
        $z = wf_marketer_link_stats('', 0);
        $z['links'] = [];
        $z['meta'] = ['ms' => 0, 'truncated' => false, 'rows' => 0, 'error' => ''];
        return $z;
    };
    try {
        $stats = wf_marketer_link_stats($code, $days);
        if (!is_array($stats)) $stats = $zero();
        try {
            $cats = wf_marketer_links_by_category($code, $stats, $days);
            if (!is_array($cats)) $cats = [];
        } catch (Throwable $e) { $cats = []; }
        return $stats;
    } catch (Throwable $e) {
        if (function_exists('wf_ui_log_fatal')) wf_ui_log_fatal('marketer link stats: ' . $e->getMessage(), __FILE__, (int)__LINE__);
        $stats = $zero();
        $stats['meta']['error'] = 'السجل الكبير مسمحلناش نكمل الإحصاءات دلوقتي';
        return $stats;
    }
}

// تجميع روابط المسوق حسب قسم/تصنيف المنتج (كاتيجوري) — عرض فقط
function wf_marketer_links_by_category($code, $stats = null, $days = 0) {
    if ($stats === null) $stats = wf_marketer_link_stats($code, $days);
    $groups = [];
    $wf_pc = [];   // بحث المنتج لكل رابط مرة واحدة في النداء (تجنب مسح الكتالوج لكل سطر)
    foreach ((array)($stats['links'] ?? []) as $key => $row) {
        $plat = ''; $pid = (string)$key;
        if (strpos((string)$key, '#') !== false) list($plat, $pid) = explode('#', (string)$key, 2);
        $wf_ck = $plat . '#' . $pid;
        if ($pid === '') $product = null;
        else {
            if (!array_key_exists($wf_ck, $wf_pc)) $wf_pc[$wf_ck] = find_product_by_id($pid, $plat);
            $product = $wf_pc[$wf_ck];
        }
        $cat = $product ? wf_normalize_category($product['category'] ?? '', 'غير مصنّف') : 'غير مصنّف';
        if ($cat === '') $cat = 'غير مصنّف';
        if (!isset($groups[$cat])) $groups[$cat] = ['category' => $cat, 'links' => 0, 'visitors' => 0, 'views' => 0, 'orders' => 0, 'qty' => 0, 'earn' => 0.0];
        $groups[$cat]['links']++;
        $groups[$cat]['visitors'] += (int)($row['visitors_count'] ?? 0);
        $groups[$cat]['views'] += (int)($row['views'] ?? 0);
        $groups[$cat]['orders'] += (int)($row['orders'] ?? 0);
        $groups[$cat]['qty'] += (int)($row['qty'] ?? 0);
        $groups[$cat]['earn'] += (float)($row['earn'] ?? 0);
    }
    uasort($groups, function ($a, $b) {
        return [$b['orders'], $b['visitors']] <=> [$a['orders'], $a['visitors']];
    });
    return $groups;
}

// عدد الزوّاد المتواجدين الآن (آخر 5 دقائق)
function wf_online_now($minutes = 5) {
    $f = DATA_DIR . '/presence.json';
    if (!file_exists($f)) return 0;
    $pr = jload('presence.json', []);
    if (!is_array($pr)) return 0;
    $cut = time() - ($minutes * 60);
    $n = 0;
    foreach ($pr as $v) if (is_array($v) && (int)($v['ts'] ?? 0) >= $cut) $n++;
    return $n;
}

function track_love_event($plat, $id, $pname = '', $img = '', $price = 0, $added = true) {
    if (function_exists('wf_event')) {
        wf_event($added ? 'like' : 'unlike', ['product_id' => (string)$id, 'plat' => (string)$plat,
                 'name' => mb_substr((string)$pname, 0, 120), 'value' => round((float)$price, 2)]);
    }
    $cu = current_user();
    $vid = visitor_id();
    $meta = visitor_meta();
    $clean_name = aff_clean($pname, true);
    $love_log = jload('love_log.json', []);
    if (!is_array($love_log)) $love_log = [];
    $love_log[] = [
        'vid' => $vid,
        'user' => !empty($cu['email']) ? $cu['email'] : $vid,
        'name' => !empty($cu['name']) ? $cu['name'] : 'زائر',
        'plat' => $plat,
        'id' => (string)$id,
        'pname' => mb_substr($clean_name !== '' ? $clean_name : $pname, 0, 120),
        'img' => (string)$img,
        'price' => (float)$price,
        'action' => $added ? 'love' : 'unlove',
        'device' => $meta['device'],
        'at' => date('c')
    ];
    jsave('love_log.json', array_slice($love_log, -2000));

    if ($added) {
        $today = date('Y-m-d');
        $an = jload('analytics.json', []);
        if (is_array($an)) {
            if (!isset($an['daily'][$today])) $an['daily'][$today] = ['views' => 0, 'uv' => [], 'carts' => 0, 'loves' => 0, 'orders' => 0];
            $an['daily'][$today]['loves'] = (int)($an['daily'][$today]['loves'] ?? 0) + 1;
            jsave('analytics.json', $an);
        }
    }
}

track_site_visit();


// ============================================================================
// توفير حساب أدمن حقيقي تلقائياً (مرة واحدة فقط عند أول تحميل بعد الرفع)
// الحساب بيُقرأ من secrets.php — لا توجد كلمة مرور مكتوبة في الكود
// ============================================================================
if (!defined('ADMIN_EMAIL')) define('ADMIN_EMAIL', 'admin@wafirinhalk.com');

function ensure_admin_account() {
    // الـ flag بيقول إن حساب الأدمن اتعمل على Supabase (الإصدار 2).
    // الـ flag القديم (من نظام الملفات قبل Supabase) مش بيكفي، عشان الحساب ما اتعملش على Supabase أصلاً.
    $flag = DATA_DIR . '/_admin_ok.json';
    if (file_exists($flag)) {
        $fl = json_decode((string)@file_get_contents($flag), true);
        if (is_array($fl) && (int)($fl['v'] ?? 0) >= 2) return;
    }
    if (ADMIN_PASS === '' || strpos((string)ADMIN_PASS, 'CHANGE_ME_NOW_') === 0) return;
    if (!usr_sb_ready()) return;

    $email = strtolower((string)ADMIN_EMAIL);
    $found = false;
    if (usr_find_row($email)) {
        // الحساب موجود: نحدّث كلمة السر من secrets.php ونتأكد من الدور
        if (usr_set_password($email, (string)ADMIN_PASS)) {
            update_user($email, function (&$u) { $u['role'] = 'admin'; });
            $found = true;
        }
    } else {
        $found = usr_create_account($email, (string)ADMIN_PASS, [
            'name'            => 'مدير الموقع',
            'role'            => 'admin',
            'at'              => date('c'),
            'balance'         => 0,
            'pending_balance' => 0,
            'status'          => 'approved',
            'ref_code'        => '',
            'margin_pct'      => 15,
            'custom_comm_pct' => 0,
        ]);
    }

    if ($found) {
        @file_put_contents($flag, json_encode(['v' => 2, 'at' => date('c'), 'email' => $email], JSON_UNESCAPED_UNICODE));
        log_audit('admin_account_provisioned', $email, ['by' => 'auto']);
    }
}
ensure_admin_account();
