<?php
// ===== منتجات يدوية (إضافة/تعديل من لوحة الأدمن أو ملف CSV) — بتتخزن في MySQL =====
// إعدادات الاتصال بتتحط في config.local.php على السيرفر (مش في Git):
//   define('MANUAL_DB_HOST', 'sqlXXX.infinityfree.com');
//   define('MANUAL_DB_NAME', 'if0_xxxxx_db');
//   define('MANUAL_DB_USER', 'if0_xxxxx');
//   define('MANUAL_DB_PASS', '...');
// لو الإعدادات مش موجودة الدوال بترجع فاضي والموقع بيكمل عادي.

define('MANUAL_MAX_IMAGES', 12);
define('MANUAL_MAX_UPLOAD', 4 * 1024 * 1024);

function manual_db() {
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    $pdo = null;
    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) require_once $local;
    if (!defined('MANUAL_DB_NAME') || MANUAL_DB_NAME === '' || !extension_loaded('pdo_mysql')) return $pdo;
    try {
        $dsn = 'mysql:host=' . MANUAL_DB_HOST . ';dbname=' . MANUAL_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, MANUAL_DB_USER, MANUAL_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS manual_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            category VARCHAR(100) NOT NULL,
            stock INT NOT NULL DEFAULT 999999,
            image_url VARCHAR(500) NOT NULL DEFAULT '',
            description TEXT,
            details LONGTEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (active)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // جدول اتعمل قبل كده من غير عمود التفاصيل: نضيفه لو مش موجود
        $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manual_products' AND COLUMN_NAME = 'details'")->fetchColumn();
        if (!(int)$has) $pdo->exec("ALTER TABLE manual_products ADD COLUMN details LONGTEXT NULL");
    } catch (Throwable $e) {
        error_log('manual_db: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

function manual_db_status() {
    if (!is_file(__DIR__ . '/config.local.php')) return 'ملف config.local.php مش موجود على السيرفر';
    if (!manual_db()) return 'مش قادر يتصل بقاعدة البيانات — اتأكد من بيانات config.local.php';
    return '';
}

// ---------- تحويل الصف لشكل سجل المنتج اللي باقي المنصات بتستخدمه ----------
function manual_decode_details($r) {
    $d = json_decode((string)($r['details'] ?? ''), true);
    return is_array($d) ? $d : [];
}

function manual_row_images($r, $d) {
    $imgs = array_values(array_filter(array_map('trim', (array)($d['images'] ?? []))));
    if (!$imgs && trim((string)$r['image_url']) !== '') $imgs = [trim((string)$r['image_url'])];
    return $imgs;
}

function manual_row_to_product($r) {
    $d = manual_decode_details($r);
    $price = (float)$r['price'];
    $desc = (string)($r['description'] ?? '');
    // الدولة (ومنّها العملة): المنتجات القديمة من غير دولة في التفاصيل = مصر / ج.م
    $country = wf_match_country($d['country'] ?? '', 'مصر');
    $currency = wf_country_currency($country);
    // سعر الشحن الخاص بالمنتج (لو المالك حدده) — بيُستخدم بدل جدول مناطق الدولة
    $ship_fee = (isset($d['ship_fee']) && is_numeric($d['ship_fee'])) ? round((float)$d['ship_fee'], 2) : null;
    $extra = ['manual_id' => (int)$r['id'], 'colors' => array_values((array)($d['colors'] ?? []))];
    foreach (['material', 'weight', 'dimensions'] as $k) {
        if (!empty($d[$k])) $extra[$k] = (string)$d[$k];
    }
    if (!empty($d['faqs']) && is_array($d['faqs'])) $extra['faqs'] = $d['faqs'];
    return [
        'id' => 'manual-' . (int)$r['id'],
        'orig_id' => (string)(int)$r['id'],
        'name' => (string)$r['name'],
        'price' => $price,
        'old_price' => 0,
        'discount' => 0.0,
        'price_text' => rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.') . ' ' . $currency,
        'currency' => $currency,
        'country' => $country,
        'ship_fee' => $ship_fee,
        'category' => (string)$r['category'],
        'stock' => (int)$r['stock'],
        'images' => manual_row_images($r, $d),
        'desc' => $desc,
        'sku' => (string)($d['sku'] ?? ''),
        'sizes' => array_values((array)($d['sizes'] ?? [])),
        'extra' => $extra,
    ];
}

function manual_products_all() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $pdo = manual_db();
    if (!$pdo) return $cache;
    try {
        $st = $pdo->query('SELECT * FROM manual_products WHERE active = 1 ORDER BY id DESC');
        foreach ($st->fetchAll() as $r) $cache[] = manual_row_to_product($r);
    } catch (Throwable $e) {
        error_log('manual_products_all: ' . $e->getMessage());
    }
    return $cache;
}

function manual_product_find($id) {
    if (!preg_match('/^manual-(\d+)$/', trim((string)$id), $m)) return null;
    $r = manual_product_row((int)$m[1]);
    return $r ? manual_row_to_product($r) : null;
}

function manual_product_row($id) {
    $pdo = manual_db();
    if (!$pdo) return null;
    try {
        $st = $pdo->prepare('SELECT * FROM manual_products WHERE id = ? AND active = 1 LIMIT 1');
        $st->execute([(int)$id]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) {
        error_log('manual_product_row: ' . $e->getMessage());
        return null;
    }
}

// قيم الفورم لمنتج موجود (للتعديل)
function manual_product_form_values($id) {
    $r = manual_product_row($id);
    if (!$r) return null;
    $d = manual_decode_details($r);
    $faq_lines = [];
    foreach ((array)($d['faqs'] ?? []) as $f) {
        if (is_array($f)) $faq_lines[] = trim(($f['q'] ?? '') . ' :: ' . ($f['a'] ?? ''));
    }
    return [
        'id' => (int)$r['id'],
        'name' => (string)$r['name'],
        'price' => (string)(float)$r['price'],
        'country' => wf_match_country($d['country'] ?? '', 'مصر'),
        'ship_fee' => (isset($d['ship_fee']) && is_numeric($d['ship_fee'])) ? (string)(float)$d['ship_fee'] : '',
        'category' => (string)$r['category'],
        'stock' => (string)(int)$r['stock'],
        'sku' => (string)($d['sku'] ?? ''),
        'description' => (string)($r['description'] ?? ''),
        'colors' => implode(' | ', (array)($d['colors'] ?? [])),
        'sizes' => implode(' | ', (array)($d['sizes'] ?? [])),
        'material' => (string)($d['material'] ?? ''),
        'weight' => (string)($d['weight'] ?? ''),
        'dimensions' => (string)($d['dimensions'] ?? ''),
        'faqs' => implode("\n", $faq_lines),
        'images' => manual_row_images($r, $d),
    ];
}

function manual_product_list($limit = 200) {
    $pdo = manual_db();
    if (!$pdo) return [];
    try {
        $st = $pdo->prepare('SELECT * FROM manual_products WHERE active = 1 ORDER BY id DESC LIMIT ?');
        $st->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('manual_product_list: ' . $e->getMessage());
        return [];
    }
}

function manual_product_delete($id) {
    if (!preg_match('/^\d+$/', (string)$id)) return false;
    $pdo = manual_db();
    if (!$pdo) return false;
    $st = $pdo->prepare('UPDATE manual_products SET active = 0 WHERE id = ?');
    $st->execute([(int)$id]);
    return $st->rowCount() > 0;
}

function manual_details_json($c) {
    $d = [
        'images' => $c['images'],
        'colors' => $c['colors'],
        'sizes' => $c['sizes'],
        'sku' => $c['sku'],
        'material' => $c['material'],
        'weight' => $c['weight'],
        'dimensions' => $c['dimensions'],
        'faqs' => $c['faqs'],
        // دولة المنتج (ومنها العملة في manual_row_to_product) — الافتراضي مصر
        'country' => wf_match_country($c['country'] ?? '', 'مصر'),
        // سعر شحن ثابت للمنتج نفسه بعملة دولته — null = الرسوم من جدول مناطق الدولة
        'ship_fee' => (isset($c['ship_fee']) && is_numeric($c['ship_fee'])) ? round((float)$c['ship_fee'], 2) : null,
    ];
    // ملامح البحث بالصورة (h/t) — بتتحسب مرة واحدة وبتتحدّث لما الصورة الرئيسية تتغير
    if (!empty($c['vf']) && is_array($c['vf'])) $d['vf'] = $c['vf'];
    return json_encode($d, JSON_UNESCAPED_UNICODE);
}

function manual_product_insert($c) {
    $pdo = manual_db();
    if (!$pdo) return 0;
    manual_ensure_vf($c, '', null);
    $st = $pdo->prepare('INSERT INTO manual_products (name, price, category, stock, image_url, description, details) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$c['name'], $c['price'], $c['category'], $c['stock'], $c['images'][0] ?? '', $c['description'], manual_details_json($c)]);
    // إشعار لكل المسوقين: منتج يدوي جديد نزل في الكتالوج
    if (function_exists('wf_notify_marketers')) wf_notify_marketers('🆕 منتج يدوي جديد: ' . mb_substr((string)$c['name'], 0, 60), 'dashboard/marketer.php?mtab=browse');
    return (int)$pdo->lastInsertId();
}

function manual_product_update($id, $c) {
    $pdo = manual_db();
    if (!$pdo) return false;
    $old = manual_product_row((int)$id);
    $old_d = $old ? manual_decode_details($old) : [];
    manual_ensure_vf($c, (string)($old_d['images'][0] ?? ''), $old_d['vf'] ?? null);
    $st = $pdo->prepare('UPDATE manual_products SET name = ?, price = ?, category = ?, stock = ?, image_url = ?, description = ?, details = ? WHERE id = ? AND active = 1');
    $st->execute([$c['name'], $c['price'], $c['category'], $c['stock'], $c['images'][0] ?? '', $c['description'], manual_details_json($c), (int)$id]);
    return true;
}

// ---------- تحليل القيم ----------
// تقسيم قايمة: بيقبل | وسطر جديد و، و,
function manual_split_list($s, $seps = "|\n\r،,") {
    if (is_array($s)) $parts = $s;
    else $parts = preg_split('/[' . preg_quote($seps, '/') . ']/u', (string)$s);
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '' && mb_strlen($p) <= 150 && !in_array($p, $out, true)) $out[] = $p;
    }
    return array_slice($out, 0, 30);
}

// الأسئلة الشائعة: كل سطر (أو كل جزء مفصول بـ |) بالشكل: السؤال :: الجواب
function manual_parse_faqs($s) {
    $out = [];
    foreach (preg_split('/[\n|]/u', (string)$s) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '::') === false) continue;
        [$q, $a] = array_map('trim', explode('::', $line, 2));
        if ($q !== '' && $a !== '') $out[] = ['q' => mb_substr($q, 0, 200), 'a' => mb_substr($a, 0, 600)];
        if (count($out) >= 10) break;
    }
    return $out;
}

function manual_valid_image_ref($u) {
    return (bool)preg_match('#^https?://#i', $u) || strpos($u, 'assets/manual/') === 0;
}

// التحقق من بيانات منتج واحد. بترجع [بيانات_نظيفة, ''] أو [null, رسالة]
function manual_validate_row($in) {
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') return [null, 'الاسم مطلوب'];
    if (mb_strlen($name) > 255) return [null, 'الاسم طويل جداً'];
    $price_raw = str_replace([',', '٬', ' '], '', (string)($in['price'] ?? ''));
    if (!is_numeric($price_raw) || (float)$price_raw <= 0) return [null, 'السعر لازم يكون رقم أكبر من صفر (' . $name . ')'];
    $cat_in = trim((string)($in['category'] ?? ''));
    $cat = in_array($cat_in, wf_category_canonical(), true) ? $cat_in : wf_normalize_category($cat_in, '', false);
    if ($cat === '' || in_array($cat, ['منتجات رقمية', 'سوشيال ميديا'], true)) return [null, 'القسم مش معروف: ' . $cat_in];
    // الدولة: منها العملة (مصر=ج.م، السعودية=ر.س، الإمارات=د.إ، الأردن=د.أ، عُمان=ر.ع).
    // فاضية = مصر (زي المنتجات القديمة)، وأي قيمة تانية مش معروفة = خطأ صريح عشان السعر مايتسجلش بعملة غلط.
    $country_in = trim((string)($in['country'] ?? ''));
    $country = wf_match_country($country_in, '');
    if ($country_in !== '' && $country === '') {
        return [null, 'الدولة مش معروفة: ' . $country_in . ' — المسموح: ' . implode('، ', wf_country_list()) . ' (' . $name . ')'];
    }
    if ($country === '') $country = 'مصر';
    $stock_raw = trim((string)($in['stock'] ?? ''));
    $stock = ($stock_raw === '') ? 999999 : (int)$stock_raw;
    if ($stock < 0) return [null, 'الكمية مينفعش تكون سالبة'];
    // سعر الشحن: اختياري. فاضي = الرسوم حسب منطقة العميل من جدول الدولة (إعدادات الشحن)،
    // وصفر مُدخل صراحةً = شحن مجاني. العملة هي عملة دولة المنتج — مفيش أي تحويل.
    $ship_raw = str_replace([',', '٬', ' '], '', trim((string)($in['ship_fee'] ?? '')));
    $ship_fee = null;
    if ($ship_raw !== '') {
        if (!is_numeric($ship_raw) || (float)$ship_raw < 0) return [null, 'سعر الشحن لازم يكون رقم صفر أو أكبر (' . $name . ')'];
        $ship_fee = round((float)$ship_raw, 2);
        if ($ship_fee > 2000) return [null, 'سعر الشحن كبير جداً (أقصى 2000) (' . $name . ')'];
    }

    $images = [];
    foreach (manual_split_list($in['images'] ?? '', "|\n\r") as $u) {
        if (!manual_valid_image_ref($u)) return [null, 'رابط الصورة لازم يبدأ بـ http:// أو https:// : ' . $u];
        $images[] = $u;
    }
    if (count($images) > MANUAL_MAX_IMAGES) return [null, 'أقصى عدد صور ' . MANUAL_MAX_IMAGES];

    $desc = trim((string)($in['description'] ?? ''));
    return [[
        'name' => $name,
        'price' => round((float)$price_raw, 2),
        'category' => $cat,
        'country' => $country,
        'ship_fee' => $ship_fee,
        'stock' => $stock,
        'images' => $images,
        'description' => mb_substr($desc, 0, 5000),
        'sku' => mb_substr(trim((string)($in['sku'] ?? '')), 0, 60),
        'colors' => manual_split_list($in['colors'] ?? ''),
        'sizes' => manual_split_list($in['sizes'] ?? ''),
        'material' => mb_substr(trim((string)($in['material'] ?? '')), 0, 200),
        'weight' => mb_substr(trim((string)($in['weight'] ?? '')), 0, 100),
        'dimensions' => mb_substr(trim((string)($in['dimensions'] ?? '')), 0, 200),
        'faqs' => manual_parse_faqs($in['faqs'] ?? ''),
    ], ''];
}

// رفع صور من الكمبيوتر (متعددة). بترجع [مسارات_نسبية, أخطاء]
function manual_save_uploads($files) {
    $paths = []; $errors = [];
    if (!$files || !isset($files['name']) || !is_array($files['name'])) return [$paths, $errors];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $dir = __DIR__ . '/assets/manual';
    foreach ($files['name'] as $i => $fname) {
        $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $tmp = $files['tmp_name'][$i] ?? '';
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $errors[] = 'فشل رفع الصورة: ' . $fname; continue; }
        if (($files['size'][$i] ?? 0) > MANUAL_MAX_UPLOAD) { $errors[] = 'الصورة أكبر من 4MB: ' . $fname; continue; }
        $info = @getimagesize($tmp);
        $ext = is_array($info) && isset($allowed[$info['mime'] ?? '']) ? $allowed[$info['mime']] : '';
        if ($ext === '') { $errors[] = 'الملف مش صورة مدعومة (jpg/png/webp/gif): ' . $fname; continue; }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $errors[] = 'مقدرش أعمل مجلد الصور assets/manual'; break; }
        $name = 'm' . date('ymdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) { $errors[] = 'مقدرش أحفظ الصورة: ' . $fname; continue; }
        $paths[] = 'assets/manual/' . $name;
    }
    return [$paths, $errors];
}

// قراءة ملف CSV (UTF-8 أو ويندوز عربي من Excel). بترجع [صفوف_صحيحة, أخطاء]
// الأعمدة: name,price,country,ship_fee,category,stock,sku,description,colors,sizes,material,weight,dimensions,images,faqs
// (الترتيب مش مهم — الأعمدة بتتقرا بالاسم، وعمود country اختياري وافتراضيه مصر،
//  وship_fee اختياري: فاضي = الرسوم من جدول مناطق الدولة، وصفر = شحن مجاني)
// داخل الخلية: القيم المتعددة مفصولة بـ | (والصور كمان بسطر جديد)، والأسئلة: السؤال::الجواب|السؤال::الجواب
function manual_parse_csv($path, $max_rows = 2000) {
    $raw = (string)@file_get_contents($path);
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1256');
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return [[], ['الملف فاضي']]; }
    $cols = array_map(function ($h) { return strtolower(trim((string)$h)); }, $header);
    if (!in_array('name', $cols, true) || !in_array('price', $cols, true)) {
        fclose($fh);
        return [[], ['أول صف لازم يكون فيه العناوين: name,price,country,ship_fee,category,stock,sku,description,colors,sizes,material,weight,dimensions,images,faqs']];
    }
    $rows = []; $errors = []; $line = 1;
    while (($cells = fgetcsv($fh)) !== false) {
        $line++;
        if (count($cells) === 1 && trim((string)$cells[0]) === '') continue;
        if (count($rows) + count($errors) >= $max_rows) { $errors[] = 'وقفت عند ' . $max_rows . ' صف'; break; }
        $in = [];
        foreach ($cols as $i => $c) $in[$c] = $cells[$i] ?? '';
        [$clean, $err] = manual_validate_row($in);
        if ($clean === null) $errors[] = 'سطر ' . $line . ': ' . $err;
        else $rows[] = $clean;
    }
    fclose($fh);
    return [$rows, $errors];
}


// ===================== ملامح البحث بالصورة (نفس منطق assets/tracker.js و updater/visual_features.py) =====================

// جيب بايتات الصورة: من assets/manual (على السيرفر) أو من رابط خارجي
function manual_image_bytes($src) {
    $src = trim((string)$src);
    if ($src === '') return null;
    if (strpos($src, 'assets/manual/') === 0) {
        $p = __DIR__ . '/' . $src;
        if (!is_file($p) || filesize($p) > MANUAL_MAX_UPLOAD) return null;
        $b = @file_get_contents($p);
        return ($b === false || $b === '') ? null : $b;
    }
    if (!preg_match('#^https?://#i', $src)) return null;
    $url = str_replace(' ', '%20', $src);
    $ua = 'Mozilla/5.0 (compatible; afflite-visual/1.0)';
    if (ini_get('allow_url_fopen') && function_exists('stream_context_create')) {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'follow_location' => 1, 'header' => "User-Agent: $ua\r\n"]]);
        $b = @file_get_contents($url, false, $ctx, 0, MANUAL_MAX_UPLOAD);
        if ($b !== false && $b !== '') return $b;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 4, CURLOPT_USERAGENT => $ua]);
        $b = curl_exec($ch);
        curl_close($ch);
        if (is_string($b) && $b !== '' && strlen($b) <= MANUAL_MAX_UPLOAD) return $b;
    }
    return null;
}

// نفس الحساب بتاع visual_features.py (v3): شبكة 64×64، خلفية بيضا (>244) أو
// خلفية موحدة مكتشفة من الحواف (رمادي/بيج/خشبي) تتجاهل، هستوجرام 64 خانة
// (4 مستويات لكل قناة) متطبّع لـ 255، وشبكة رمادية 8×8.
function manual_visual_features_from_string($bin) {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) return null;
    $src = @imagecreatefromstring($bin);
    if (!$src) return null;
    $G = 64; $C = 8; $CELL = 8;
    $dst = imagecreatetruecolor($G, $G);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $G - 1, $G - 1, $white);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $G, $G, imagesx($src), imagesy($src));
    imagedestroy($src);
    // كشف خلفية موحدة من حواف الإطار (وسيط كل قناة + مدى الربع الربيعي)
    $rs = []; $gs = []; $bs = [];
    for ($x = 0; $x < $G; $x++) {
        foreach ([0, $G - 1] as $yy) {
            $c = imagecolorat($dst, $x, $yy);
            $rs[] = ($c >> 16) & 255; $gs[] = ($c >> 8) & 255; $bs[] = $c & 255;
            $c = imagecolorat($dst, $yy, $x);
            $rs[] = ($c >> 16) & 255; $gs[] = ($c >> 8) & 255; $bs[] = $c & 255;
        }
    }
    sort($rs); sort($gs); sort($bs);
    $mr = $rs[intdiv(count($rs), 2)]; $mg = $gs[intdiv(count($gs), 2)]; $mb = $bs[intdiv(count($bs), 2)];
    $q1r = $rs[intdiv(count($rs), 4)]; $q1g = $gs[intdiv(count($gs), 4)]; $q1b = $bs[intdiv(count($bs), 4)];
    $bg = (max($mr - $q1r, $mg - $q1g, $mb - $q1b) < 18) ? [$mr, $mg, $mb] : null;
    $hist = array_fill(0, 64, 0);
    $gs_i = array_fill(0, $C * $C, 0.0);
    $total = 0;
    for ($y = 0; $y < $G; $y++) {
        for ($x = 0; $x < $G; $x++) {
            $c = imagecolorat($dst, $x, $y);
            $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
            $cell = intdiv($y, $CELL) * $C + intdiv($x, $CELL);
            $is_bg = ($r > 244 && $g > 244 && $b > 244);
            if ($bg && !$is_bg && abs($r - $bg[0]) + abs($g - $bg[1]) + abs($b - $bg[2]) < 120) $is_bg = true;
            if ($is_bg) { $gs_i[$cell] += 255; continue; }
            $gs_i[$cell] += 0.299 * $r + 0.587 * $g + 0.114 * $b;
            $hist[($r >> 6) * 16 + ($g >> 6) * 4 + ($b >> 6)]++;
            $total++;
        }
    }
    imagedestroy($dst);
    $h = [];
    foreach ($hist as $cnt) $h[] = $total ? (int)floor($cnt * 255 / $total + 0.5) : 0;
    $t = [];
    foreach ($gs_i as $sum) $t[] = (int)floor($sum / ($CELL * $CELL) + 0.5);
    return ['h' => $h, 't' => $t];
}

function manual_compute_vf($src) {
    $bin = manual_image_bytes($src);
    if ($bin === null) return null;
    return manual_visual_features_from_string($bin);
}

// قبل الحفظ: نحسب الملامح لو الصورة الرئيسية اتغيرت (أو لسه مفيش)
function manual_ensure_vf(&$c, $prev_src, $prev_vf) {
    $src = (string)($c['images'][0] ?? '');
    if ($src === '') { unset($c['vf']); return; }
    if ($src === $prev_src && is_array($prev_vf) && isset($prev_vf['h'], $prev_vf['t'])) {
        $c['vf'] = $prev_vf;
        return;
    }
    $vf = manual_compute_vf($src);
    if ($vf) $c['vf'] = $vf; else unset($c['vf']);
}

// عدد المنتجات اليدوية: الإجمالي والمفهرس للبحث
function manual_index_counts() {
    $pdo = manual_db();
    if (!$pdo) return [0, 0];
    try {
        $r = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(details LIKE '%\"vf\":{%'), 0) AS indexed FROM manual_products WHERE active = 1")->fetch();
        return [(int)$r['total'], (int)$r['indexed']];
    } catch (Throwable $e) {
        error_log('manual_index_counts: ' . $e->getMessage());
        return [0, 0];
    }
}

// منتجات جاهزة للبحث بالصورة (ملامحها محسوبة): بتتدمج مع فهرس البحث
function manual_visual_items() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $pdo = manual_db();
    if (!$pdo) return $cache;
    try {
        $st = $pdo->query("SELECT * FROM manual_products WHERE active = 1 AND details LIKE '%\"vf\":{%' ORDER BY id DESC");
        foreach ($st->fetchAll() as $r) {
            $d = manual_decode_details($r);
            $vf = $d['vf'] ?? null;
            if (!is_array($vf) || !isset($vf['h'], $vf['t']) || count($vf['h']) !== 64 || count($vf['t']) !== 64) continue;
            $imgs = manual_row_images($r, $d);
            if (!$imgs) continue;
            $cache[] = [
                'id' => 'manual-' . (int)$r['id'],
                'plat' => 'manual',
                'name' => (string)$r['name'],
                'cat' => (string)$r['category'],
                'img' => $imgs[0],
                'price' => (float)$r['price'],
                'h' => array_map('intval', $vf['h']),
                't' => array_map('intval', $vf['t']),
            ];
        }
    } catch (Throwable $e) {
        error_log('manual_visual_items: ' . $e->getMessage());
    }
    return $cache;
}

// تشغيل دفعة لحساب ملامح المنتجات اللي لسه ماتحسبتش (بحد زمني عشان السيرفر المجاني)
function manual_index_batch($max = 10, $budget = 4.0) {
    $res = ['ok' => 0, 'fail' => 0];
    $pdo = manual_db();
    if (!$pdo) return $res;
    $start = microtime(true);
    try {
        $st = $pdo->prepare("SELECT * FROM manual_products WHERE active = 1 AND (details IS NULL OR details NOT LIKE ?) ORDER BY id ASC LIMIT ?");
        $st->bindValue(1, '%"vf":%');
        $st->bindValue(2, (int)$max, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('manual_index_batch: ' . $e->getMessage());
        return $res;
    }
    foreach ($rows as $r) {
        if (microtime(true) - $start > $budget) break;
        $d = manual_decode_details($r);
        $imgs = manual_row_images($r, $d);
        $vf = $imgs ? manual_compute_vf($imgs[0]) : null;
        $d['vf'] = $vf ?: 'fail';
        try {
            $up = $pdo->prepare('UPDATE manual_products SET details = ? WHERE id = ?');
            $up->execute([json_encode($d, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
        } catch (Throwable $e) {
            error_log('manual_index_batch update: ' . $e->getMessage());
        }
        if ($vf) $res['ok']++; else $res['fail']++;
    }
    return $res;
}

function manual_index_pending_count() {
    $pdo = manual_db();
    if (!$pdo) return 0;
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM manual_products WHERE active = 1 AND (details IS NULL OR details NOT LIKE '%\"vf\":%')")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
