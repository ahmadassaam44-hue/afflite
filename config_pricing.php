<?php
// ============================================================================
// config_pricing.php — جزء من config.php (السطور 25–369 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
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
        else $s = str_replace(',', '.', str_replace('.', '', $s)); // 1.234,50: احذف نقاط الآلاف الأول وبعدين حوّل الفاصلة لنقطة
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
