<?php
// ============================================================================
// config_catalog.php — جزء من config.php (السطور 1511–2590 قبل التقسيم).
// يُحمَّل من config.php فقط، وبالترتيب الأصلي. لا تستدعِه مباشرة من الويب ولا
// تغيّر ترتيب التحميل: كود التشغيل (الجلسة/CSRF/تتبع الإحالة) يعتمد عليه.
// ============================================================================
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
        // سعر الأدمن = السعر النهائي بالظبط (من غير هامش/خصم فوقه) — عشان الحفظ المتكرر مايزحزحش السعر
        $row['_price_override'] = true;
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
    // عمولة ثابتة لكل قطعة للمنتج اليدوي (بتتكتب من فورم «تعديل كل التفاصيل» في تبويب منتجات الأدمن) —
    // بتندمج في extra.manual_commission فيقراها product_commission_unit زي عمولة جدول المنتجات اليدوية بالظبط
    if (array_key_exists('manual_commission', $override)) {
        if (!isset($row['extra']) || !is_array($row['extra'])) $row['extra'] = [];
        $row['extra']['manual_commission'] = round(max(0, (float)$override['manual_commission']), 2);
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
    if ($key === 'mnfean' && false) {
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
        $all = platform_products_lite($key);
        // ترتيب ascending بختم added_at (اللي من غير ختم يفضلوا الأول بترتيب الملف) — فالآخر يبقى الأحدث فعلًا
        usort($all, function ($a, $b) {
            return strcmp((string)($a['added_at'] ?? ''), (string)($b['added_at'] ?? ''));
        });
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
    if ($key === 'mnfean' && false) return $cache[$key] = platform_products_lite($key);
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
    return ['timehand', 'safqa', 'vendoor', 'angazny', 'm5zoon', 'shopmart', 'g2g', 'social', 'mnfean', 'manual'];
}

// نص ملف الكتالوج الخام لمنصة واحدة (مرة واحدة في الطلب — نص فقط، بدون فك كتالوج)

// ===== Cezma: كتالوج مقسم لشظايا (data/cezma_shards/lite_cezma_XX.json) =====
function cezma_shard_files() {
    return [];

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
    return [];

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
    return [];

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
    return null;

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
    return [];

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
    return null;

    if (!is_string($filename)) return null;
    $pattern = $kind === 'search'
        ? '/^search_[0-9a-f]\\.json$/D'
        : '/^products_[0-9a-f]{12}_[0-9]{4}\\.json$/D';
    if (!preg_match($pattern, $filename)) return null;
    return DATA_DIR . '/zz_cezma_index/' . $filename;
}

function cezma_search_index_read_json($filename, $manifest, $kind = 'product') {
    return null;

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
    return false;

    if (!is_array($value)) return false;
    $expected_key = 0;
    foreach ($value as $key => $_item) {
        if ($key !== $expected_key++) return false;
    }
    return true;
}

function cezma_search_query_trigrams($query) {
    return [];

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
    return [];

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
            $row = normalize_product_record($row, 'mnfean');
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
    return apply_product_overrides($products, 'mnfean');
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
    foreach (platform_products_lite('mnfean') as $product) {
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
    return [];

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
            $row = normalize_product_record($row, 'mnfean');
            $row['category'] = wf_cezma_site_category($row['category'] ?? '', $row['name'] ?? '');
            if (!cezma_filter_available_rows([$row])) continue;
            $newest_first[] = $row;
        }
    }

    // المنتجات المختومة بـ added_at (الجديد اليومي) تتقدم على ترتيب ذيول الشظايا
    usort($newest_first, function ($a, $b) {
        return strcmp((string)($b['added_at'] ?? ''), (string)($a['added_at'] ?? ''));
    });
    $tail = array_reverse(array_slice($newest_first, 0, $limit));
    return apply_product_overrides($tail, 'mnfean');
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
    return false;

    $id = preg_replace('/^cezma-/', '', trim((string)$id));
    return $id !== '' && isset(cezma_unavailable_ids()[$id]);
}
function cezma_mark_unavailable_id($id) {
    return;

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
    return false;

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
    return $all ?? [];

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
    return $row ?? null;

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
    if ($plat === 'mnfean') {
        // mnfean uses generic lite_mnfean.json / mnfean.json handling below
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
    if ($plat === 'cezma' && false) {
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
                if ($pl === 'mnfean' && false) {
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
function mix_by_platform($items, $order = ['timehand', 'safqa', 'vendoor', 'angazny', 'm5zoon', 'shopmart', 'mnfean', 'manual']) {
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
        $plats = ['timehand', 'safqa', 'vendoor', 'angazny', 'shopmart', 'mnfean', 'manual'];
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
        if ($pk === 'mnfean' && false && ($q !== '' || ($cat !== '' && $cat !== 'all' && !in_array($cat, ['المنتجات المميزة', 'أحدث المنتجات', 'الأكثر مبيعاً', 'الأكثر مشاهدة'], true)))) {
            $cezma_indexed = cezma_indexed_products($cat, $q);
        }
        $use_home_window = ($source_limit !== null && $q === '' && $pk !== 'manual' && ((int)$source_limit + 100) <= 900);
        if ($cezma_indexed !== null) {
            $arr = $cezma_indexed;
        } elseif ($pk === 'mnfean' && false && ($source_limit !== null ||
            // المتجر الافتراضي و«المنتجات المميزة» بلا فلاتر: نافذة محدودة من الأحدث بدل تطييع 43 ألف منتج في كل طلب.
            // «أحدث المنتجات» و«الأكثر مبيعاً/مشاهدة» تظل بالمسار الكامل لأنها صارت مرتبة على الكتالوج كاملاً.
            ($q === '' && in_array($cat, ['', 'all', 'المنتجات المميزة'], true)))) {
            $arr = $use_home_window
                ? wf_home_platform_window('mnfean', (int)$source_limit + 100)
                : (platform_products_lite('mnfean') !== null ? max(1, (int)$source_limit + 100) : 3000);
        } else {
            $arr = $use_home_window
                ? wf_home_platform_window($pk, (int)$source_limit + 100)
                : platform_products_lite($pk);
        }
        if ($source_limit !== null && $pk !== 'mnfean' && count($arr) > (int)$source_limit + 100) {
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
            // الأحدث فعلًا: ترتيب تنازلي بختم added_at (اللي بينزل جديد كل يوم) —
            // والمنتجات القديمة من غير ختم تيجي بعدها بترتيب الملف مقلوب (usort مستقر في PHP8)
            $arr = array_reverse($arr);
            usort($arr, function ($a, $b) {
                return strcmp((string)($b['added_at'] ?? ''), (string)($a['added_at'] ?? ''));
            });
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
