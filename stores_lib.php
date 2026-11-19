<?php
// ============================================================================
// 🏬 المتاجر المتعددة (Multi-store)
// كل «تاجر» ليه متجر كامل بنفس تصميم الموقع: براندنج خاص + منتجاته + طلباته.
// التخزين:
//   data/stores.json                       → سجل المتاجر (slug => بيانات المتجر)
//   data/stores/<slug>/products.json        → منتجات التاجر
//   assets/stores/<slug>/<file>             → صور منتجات التاجر
//   orders.json                             → الطلبات (كل طلب فيه store + vendor)
// ملاحظة: الملف ده بيتحمّل من آخر config.php — لا تنقله لفوق (نفس سبب مشكلة docs_is_remote).
// ============================================================================

if (!defined('WFV_STORE_DIR')) define('WFV_STORE_DIR', 'stores');
if (!defined('WFV_MAX_IMAGES')) define('WFV_MAX_IMAGES', 6);
if (!defined('WFV_MAX_UPLOAD')) define('WFV_MAX_UPLOAD', 3 * 1024 * 1024); // 3 ميجا للصورة
if (!defined('WFV_DEFAULT_SHIP')) define('WFV_DEFAULT_SHIP', 80);

function wfv_reserved_slugs() {
    return ['dashboard', 'admin', 'login', 'logout', 'api', 'assets', 'data', 'vendor', 'store', 'stores',
        'index', 'www', 'mail', 'smtp', 'ftp', 'static', 'cdn', 'app', 'test', 'support', 'help'];
}

function wfv_slug_ok($slug) {
    $slug = strtolower(trim((string)$slug));
    if (strlen($slug) < 3 || strlen($slug) > 24) return false;
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])$/', $slug)) return false;
    return !in_array($slug, wfv_reserved_slugs(), true);
}

function wfv_stores_all() {
    $all = jload('stores.json', []);
    if (!is_array($all)) return [];
    // ترتيب ثابت حسب تاريخ الإنشاء
    uasort($all, function ($a, $b) {
        return strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
    });
    return $all;
}

function wfv_store($slug) {
    $slug = strtolower(trim((string)$slug));
    if ($slug === '') return null;
    $all = wfv_stores_all();
    if (!isset($all[$slug]) || !is_array($all[$slug])) return null;
    return wfv_store_defaults($all[$slug], $slug);
}

function wfv_store_defaults(array $st, $slug) {
    $def = [
        'slug'         => $slug,
        'name'         => 'متجر ' . $slug,
        'tagline'      => '',
        'about'        => '',
        'accent'       => '#10b981',
        'logo'         => '',
        'banner'       => '',
        'phone'        => '',
        'whatsapp'     => '',
        'owner_email'  => '',
        'ship_fee'     => WFV_DEFAULT_SHIP,
        'enabled'      => 1,
        'announce'     => '',
        'created_at'   => date('c'),
        'updated_at'   => '',
    ];
    foreach ($def as $k => $v) if (!array_key_exists($k, $st)) $st[$k] = $v;
    $st['slug'] = $slug;
    $st['enabled'] = (int)!empty($st['enabled']);
    $st['ship_fee'] = (float)$st['ship_fee'];
    return $st;
}

function wfv_store_save($slug, array $data) {
    $slug = strtolower(trim((string)$slug));
    if (!wfv_slug_ok($slug)) return false;
    $data['slug'] = $slug;
    $data['updated_at'] = date('c');
    if (empty($data['created_at'])) $data['created_at'] = date('c');
    $ok = jupdate('stores.json', function (&$all) use ($slug, $data) {
        if (!is_array($all)) $all = [];
        $prev = isset($all[$slug]) && is_array($all[$slug]) ? $all[$slug] : [];
        $all[$slug] = array_merge($prev, $data);
        return true;
    }, []);
    return (bool)$ok;
}

function wfv_store_dir($slug, $create = false) {
    $slug = strtolower(trim((string)$slug));
    if (!wfv_slug_ok($slug)) return '';
    $dir = DATA_DIR . '/' . WFV_STORE_DIR . '/' . $slug;
    if ($create && !is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function wfv_store_assets_dir($slug, $create = false) {
    $slug = strtolower(trim((string)$slug));
    if (!wfv_slug_ok($slug)) return '';
    $dir = dirname(DATA_DIR) . '/assets/stores/' . $slug;
    if ($create && !is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

// ------------------------------- المنتجات ----------------------------------
function wfv_products($slug) {
    if (wfv_store_dir($slug) === '') return [];
    $rows = jload(WFV_STORE_DIR . '/' . $slug . '/products.json', []);
    return is_array($rows) ? array_values($rows) : [];
}

function wfv_products_live($slug) {
    return array_values(array_filter(wfv_products($slug), function ($p) {
        return !empty($p['active']) && (int)($p['stock'] ?? 1) !== 0;
    }));
}

function wfv_product($slug, $id) {
    $id = (string)$id;
    foreach (wfv_products($slug) as $p) {
        if ((string)($p['id'] ?? '') === $id) return $p;
    }
    return null;
}

function wfv_next_product_id($slug) {
    $max = 0;
    foreach (wfv_products($slug) as $p) {
        if (preg_match('/-(\d+)$/', (string)($p['id'] ?? ''), $m)) $max = max($max, (int)$m[1]);
    }
    return $max + 1;
}

function wfv_product_save($slug, array $in, &$err = '') {
    $err = '';
    if (wfv_store_dir($slug, true) === '') { $err = 'اسم المتجر غير صالح'; return false; }
    $id = trim((string)($in['id'] ?? ''));
    $name = trim((string)($in['name'] ?? ''));
    $price = (float)($in['price'] ?? 0);
    if (mb_strlen($name) < 3) { $err = 'اكتب اسم منتج صحيح (3 حروف على الأقل)'; return false; }
    if ($price <= 0) { $err = 'السعر لازم يكون أكبر من صفر'; return false; }
    $images = isset($in['images']) && is_array($in['images']) ? array_values(array_filter(array_map('strval', $in['images']))) : [];
    $record = [
        'id'          => $id !== '' ? $id : ('v' . substr(md5($slug . microtime(true)), 0, 6) . '-' . wfv_next_product_id($slug)),
        'name'        => mb_substr($name, 0, 140),
        'price'       => round($price, 2),
        'old_price'   => (float)($in['old_price'] ?? 0),
        'category'    => mb_substr(trim((string)($in['category'] ?? '')), 0, 40),
        'desc'        => mb_substr(trim((string)($in['desc'] ?? '')), 0, 2000),
        'sizes'       => wfv_split_list($in['sizes'] ?? ''),
        'colors'      => wfv_split_list($in['colors'] ?? ''),
        'images'      => array_slice($images, 0, WFV_MAX_IMAGES),
        'stock'       => empty($in['stock']) ? 0 : 1,
        'active'      => empty($in['active']) ? 0 : 1,
        'ship_fee'    => isset($in['ship_fee']) && $in['ship_fee'] !== '' ? (float)$in['ship_fee'] : null,
        'updated_at'  => date('c'),
    ];
    $ok = jupdate(WFV_STORE_DIR . '/' . $slug . '/products.json', function (&$rows) use ($record) {
        if (!is_array($rows)) $rows = [];
        $found = false;
        foreach ($rows as $i => $r) {
            if ((string)($r['id'] ?? '') === (string)$record['id']) {
                $record['created_at'] = $r['created_at'] ?? date('c');
                $rows[$i] = $record;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $record['created_at'] = date('c');
            $rows[] = $record;
        }
        return true;
    }, []);
    if (!$ok) { $err = 'مش قدرنا نحفظ المنتج — جرّب تاني'; return false; }
    return $record['id'];
}

function wfv_product_delete($slug, $id) {
    $id = (string)$id;
    $removed = false;
    jupdate(WFV_STORE_DIR . '/' . $slug . '/products.json', function (&$rows) use ($id, &$removed) {
        if (!is_array($rows)) $rows = [];
        $out = [];
        foreach ($rows as $r) {
            if ((string)($r['id'] ?? '') === $id) { $removed = true; continue; }
            $out[] = $r;
        }
        $rows = $out;
        return true;
    }, []);
    return $removed;
}

function wfv_split_list($v) {
    if (is_array($v)) $parts = $v;
    else $parts = preg_split('/[,،\n]+/u', (string)$v) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '' && !in_array($p, $out, true)) $out[] = mb_substr($p, 0, 30);
    }
    return array_slice($out, 0, 20);
}

// ------------------------------- الصور -------------------------------------
function wfv_upload_image($slug, $field, &$err = '') {
    $err = '';
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    $f = $_FILES[$field];
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) { $err = 'فشل رفع الصورة (خطأ ' . (int)$f['error'] . ')'; return ''; }
    if ((int)$f['size'] > WFV_MAX_UPLOAD) { $err = 'الصورة أكبر من 3 ميجا'; return ''; }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || empty($info[2])) { $err = 'الملف مش صورة صحيحة'; return ''; }
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!isset($map[$info[2]])) { $err = 'صيغة الصورة غير مدعومة (jpg / png / webp)'; return ''; }
    $dir = wfv_store_assets_dir($slug, true);
    if ($dir === '') { $err = 'مسار المتجر غير صالح'; return ''; }
    $name = 'p' . date('ymd') . '-' . bin2hex(random_bytes(5)) . '.' . $map[$info[2]];
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { $err = 'مش قدرنا نحفظ الصورة على السيرفر'; return ''; }
    return 'assets/stores/' . $slug . '/' . $name;
}

function wfv_delete_image($slug, $url) {
    $url = (string)$url;
    if (strpos($url, 'assets/stores/' . $slug . '/') !== 0) return false;
    $path = dirname(DATA_DIR) . '/' . $url;
    return is_file($path) ? @unlink($path) : false;
}

// ------------------------------- الطلبات -----------------------------------
function wfv_orders_all() {
    $os = jload('orders.json', []);
    return is_array($os) ? $os : [];
}

function wfv_store_orders($slug) {
    $slug = strtolower(trim((string)$slug));
    $out = [];
    foreach (wfv_orders_all() as $o) {
        if (!is_array($o)) continue;
        if (strtolower((string)($o['store'] ?? '')) === $slug) $out[] = $o;
    }
    usort($out, function ($a, $b) { return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')); });
    return $out;
}

function wfv_store_stats($slug) {
    $st = ['orders' => 0, 'pending' => 0, 'delivered' => 0, 'revenue' => 0.0, 'delivered_revenue' => 0.0, 'items' => 0];
    foreach (wfv_store_orders($slug) as $o) {
        $st['orders']++;
        $total = (float)($o['total'] ?? 0);
        $st['revenue'] += $total;
        $key = function_exists('status_key') ? status_key($o['status'] ?? '') : strtolower((string)($o['status'] ?? ''));
        if (in_array($key, ['pending', 'new'], true)) $st['pending']++;
        if (in_array($key, ['delivered', 'completed', 'done'], true)) { $st['delivered']++; $st['delivered_revenue'] += $total; }
    }
    $st['items'] = count(wfv_products($slug));
    return $st;
}

// إنشاء طلب متجر: نفس شكل صف orders.json مع store + vendor
function wfv_add_order($slug, array $in, &$err = '') {
    $err = '';
    $store = wfv_store($slug);
    if (!$store) { $err = 'المتجر غير موجود'; return ''; }
    $pid = trim((string)($in['id'] ?? ''));
    $product = $pid !== '' ? wfv_product($slug, $pid) : null;
    if (!$product) { $err = 'المنتج غير موجود'; return ''; }
    if (empty($product['active']) || (int)($product['stock'] ?? 1) === 0) { $err = 'المنتج غير متاح حاليًا'; return ''; }

    $cname = trim((string)($in['cname'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($in['cphone'] ?? ''));
    $phone2 = preg_replace('/\D+/', '', (string)($in['cphone2'] ?? ''));
    $city = trim((string)($in['city'] ?? ''));
    $address = trim((string)($in['address'] ?? ''));
    if (mb_strlen($cname) < 3) { $err = 'اكتب اسمك بالكامل'; return ''; }
    if (strlen($phone) < 10) { $err = 'رقم الموبايل غير صحيح'; return ''; }
    if ($city === '') { $err = 'اختر المحافظة'; return ''; }
    if (mb_strlen($address) < 6) { $err = 'اكتب العنوان بالتفصيل'; return ''; }

    $qty = max(1, min(20, (int)($in['qty'] ?? 1)));
    $unit = (float)$product['price'];
    $ship = isset($product['ship_fee']) && $product['ship_fee'] !== null ? (float)$product['ship_fee'] : (float)$store['ship_fee'];
    if ($ship <= 0 && function_exists('site_ship_map')) {
        $map = site_ship_map();
        if (isset($map[$city])) $ship = (float)$map[$city];
    }
    $total = round($unit * $qty + $ship, 2);
    $ord_no = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 5));
    $variant = trim((string)($in['size'] ?? ''));
    $color = trim((string)($in['color'] ?? ''));
    $notes = trim((string)($in['notes'] ?? ''));
    if ($phone2 !== '') $notes = ($notes !== '' ? $notes . ' | ' : '') . 'هاتف بديل: ' . $phone2;

    $row = [
        'order_no'    => $ord_no,
        'user'        => 'store:' . $slug,
        'plat'        => 'manual',            // نفس مسار المنتجات اليدوية (مفيش إرسال لمصدر خارجي)
        'store'       => $slug,
        'store_name'  => (string)$store['name'],
        'vendor'      => (string)$store['owner_email'],
        'id'          => (string)$product['id'],
        'pname'       => mb_substr((string)$product['name'], 0, 120),
        'ref'         => '',
        'm_earn'      => 0,
        'comm_state'  => 'none',
        'sub'         => function_exists('active_sub') ? active_sub() : 0,
        'base_price'  => $unit,
        'markup'      => 0,
        'extra_comm'  => 0,
        'qty'         => $qty,
        'unit'        => $unit,
        'ship'        => $ship,
        'total'       => $total,
        'variant'     => $variant,
        'color'       => $color,
        'size'        => $variant,
        'cname'       => mb_substr($cname, 0, 100),
        'cphone'      => $phone,
        'cphone2'     => $phone2,
        'city'        => $city,
        'district'    => '',
        'address'     => mb_substr($address, 0, 300),
        'notes'       => mb_substr($notes, 0, 500),
        'at'          => date('c'),
        'src'         => 'store:' . $slug,
        'discount'    => 0,
        'status'      => 'pending',
    ];
    $ok = jupdate('orders.json', function (&$os) use ($row) {
        if (!is_array($os)) $os = [];
        $os[] = $row;
        return true;
    }, []);
    if (!$ok) { $err = 'مش قدرنا نسجّل الطلب — جرّب تاني'; return ''; }
    if (function_exists('log_audit')) {
        @log_audit('store_order', $ord_no, ['store' => $slug, 'total' => $total]);
    }
    return $ord_no;
}

// ------------------------------- العرض -------------------------------------
function wfv_store_of_user($email) {
    $email = strtolower(trim((string)$email));
    if ($email === '') return null;
    foreach (wfv_stores_all() as $slug => $st) {
        if (strtolower(trim((string)($st['owner_email'] ?? ''))) === $email) return wfv_store_defaults($st, (string)$slug);
    }
    return null;
}

function wfv_theme_style($store) {
    $accent = (string)($store['accent'] ?? '#10b981');
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) $accent = '#10b981';
    return '<style>:root{--wf-store-accent:' . $accent . '}'
        . '.wfv-hero{background:linear-gradient(135deg,' . $accent . '22,' . $accent . '05);border:1px solid ' . $accent . '33}'
        . '.wfv-chip{display:inline-block;padding:7px 14px;border-radius:999px;border:1px solid #e2e8f0;background:#fff;font-size:13px;font-weight:700;text-decoration:none;color:#0f172a}'
        . '.wfv-chip.on{background:' . $accent . ';border-color:' . $accent . ';color:#fff}'
        . '.wfv-logo{width:64px;height:64px;border-radius:18px;object-fit:cover;background:#fff;border:1px solid #e2e8f0}'
        . '.wfv-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 22px;border-radius:12px;'
        . 'background:' . $accent . ';color:#fff;font-weight:800;text-decoration:none;border:0;cursor:pointer;font-family:inherit;font-size:15px}'
        . '.wfv-field{width:100%;padding:12px 14px;border:1px solid #dbe3ec;border-radius:12px;font-family:inherit;font-size:15px;background:#fff}'
        . '.wfv-field:focus{outline:2px solid ' . $accent . '55;border-color:' . $accent . '}'
        . '.wfv-label{display:block;font-weight:700;font-size:13px;margin:0 0 6px;color:#334155}'
        . '.wfv-row{display:grid;gap:14px;grid-template-columns:1fr 1fr}'
        . '@media(max-width:640px){.wfv-row{grid-template-columns:1fr}}'
        . '</style>';
}

function wfv_card($store, array $p) {
    $slug = (string)$store['slug'];
    $img = !empty($p['images'][0]) ? (string)$p['images'][0] : '';
    $link = 'vendor_product.php?store=' . urlencode($slug) . '&id=' . urlencode((string)$p['id']);
    $out = '<article class="pcard">';
    $out .= '<div class="pcard-img"><a href="' . esc($link) . '">';
    $out .= $img !== '' ? '<img src="' . esc($img) . '" alt="' . esc((string)$p['name']) . '" loading="lazy">'
        : '<span class="pcard-placeholder" role="img" aria-label="صورة المنتج غير متاحة">' . wf_icon('box', 44) . '</span>';
    $out .= '</a></div><div class="pcard-cat">' . esc((string)($p['category'] ?? '')) . '</div>';
    $out .= '<h3 class="pcard-title"><a href="' . esc($link) . '">' . esc((string)$p['name']) . '</a></h3>';
    if (!empty($p['sizes']) && is_array($p['sizes'])) {
        $out .= '<div class="mut" style="font-size:12px">' . esc(implode(' • ', array_slice($p['sizes'], 0, 5))) . '</div>';
    }
    $price = (float)($p['price'] ?? 0);
    $old = (float)($p['old_price'] ?? 0);
    $out .= '<div class="pcard-footer"><div class="pcard-price">' . number_format($price, 0) . ' ج.م';
    if ($old > $price) $out .= ' <small style="text-decoration:line-through;color:#94a3b8">' . number_format($old, 0) . '</small>';
    $out .= '</div><a href="' . esc($link) . '" class="pcard-cart" aria-label="اطلب الآن ' . esc((string)$p['name']) . '">اطلب الآن</a></div>';
    $out .= '</article>';
    return $out;
}
