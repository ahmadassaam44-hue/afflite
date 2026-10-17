<?php
// ============================================================================
//  🛍️ نواة نظام أفلييت جوميا (Jumia Associates)
//  - المسوق يبعت طلب منتج من جوميا (لينك) → الإدارة توافق وتحدد لينك التتبع والسعر والنسبة
//  - بعد الموافقة بس يظهر رابط التسويق (تتبع + مباشر) وقيمة العمولة على كل بيعة
//  - النقرات بتتسجل تلقائياً، والمبيعات والأرباح (معلقة/مؤكدة) بتتحدد من الإدارة
//  - كل تحويل (بيعة) بيتسجل عمولة للمسوق + نصيب للموقع
//  ملاحظة: كل الإعدادات (لينك التتبعات / النسب / الموافقات) بتتدار من لوحة الإدارة.
// ============================================================================
if (!defined('DATA_DIR')) return;   // لا يُحمّل إلا من داخل config.php

define('JMI_LINKS_F',  'jumia_links.json');    // كل روابط المسوقين
define('JMI_CLICKS_F', 'jumia_clicks.json');   // سجل النقرات
define('JMI_ORDERS_F', 'jumia_orders.json');   // سجل التحويلات (البيعات)
define('JMI_REQS_F',   'jumia_requests.json'); // طلبات المسوقين للإدارة

// ============================================================================
//  1) السوق المدعوم: جوميا مصر
// ============================================================================
// يرجّع اللينك زي ما المستخدم لاصقه لكن بدون بارامترات تتبع غريبة
function jmi_normalize_input($url) {
    $u = trim((string)$url);
    if ($u !== '' && strpos($u, '://') === false) $u = 'https://' . ltrim($u, '/');
    $p = parse_url($u);
    if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) return '';
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return '';
    return $u;
}

// ============================================================================
//  3) أقسام جوميا ونسب العمولة (النسبة اللي جوميا بتدفعها للموقع)
// ============================================================================
function jmi_cat_label($slug) {
    $c = jmi_categories();
    return isset($c[$slug]) ? $c[$slug][0] : $c['other'][0];
}
// النسبة الفعلية بعد تعديل الإدارة (الإعداد: jmi_rate_<slug>)
function jmi_rate($slug) {
    $c = jmi_categories();
    $def = isset($c[$slug]) ? (float)$c[$slug][1] : (float)$c['other'][1];
    $v = site_setting('jmi_rate_' . preg_replace('/[^a-z0-9_]/i', '', (string)$slug), '');
    if ($v !== '' && is_numeric($v)) return max(0, min(100, (float)$v));
    return $def;
}

// ============================================================================
//  4) أسعار العملات (تحويل عمولة جوميا للجنيه المصري)
// ============================================================================
function jmi_fx_defaults() {
    return ['USD' => 52.30, 'SAR' => 13.94, 'AED' => 14.24, 'EGP' => 1, 'GBP' => 70.00,
            'EUR' => 61.00, 'CAD' => 38.00, 'AUD' => 34.00, 'INR' => 0.59, 'JPY' => 0.35,
            'MXN' => 2.80, 'BRL' => 9.60];
}
function jmi_fx($cur) {
    $cur = strtoupper(trim((string)$cur));
    if ($cur === '' ) $cur = 'USD';
    if ($cur === 'EGP') return 1.0;
    $d = jmi_fx_defaults();
    $v = site_setting('jmi_fx_' . preg_replace('/[^A-Z]/', '', $cur), '');
    if ($v !== '' && is_numeric($v) && (float)$v > 0) return (float)$v;
    return isset($d[$cur]) ? (float)$d[$cur] : 1.0;
}
function jmi_money($amount, $currency = 'EGP') {
    $sym = ['USD' => '$', 'SAR' => 'ر.س', 'AED' => 'د.إ', 'EGP' => 'ج.م', 'GBP' => '£', 'EUR' => '€',
            'CAD' => 'C$', 'AUD' => 'A$', 'INR' => '₹', 'JPY' => '¥', 'MXN' => 'MX$', 'BRL' => 'R$'];
    $s = isset($sym[$currency]) ? $sym[$currency] : '';
    return number_format((float)$amount, 2) . ($s !== '' ? ' ' . $s : '');
}

// اسم العملة للعرض: الجنيه بيتكتب «ج.م» زي باقي الموقع
function jmi_cur_label($cur) {
    $c = strtoupper(trim((string)$cur));
    return $c === 'EGP' ? 'ج.م' : $c;
}

// ============================================================================
//  5) نصيب المسوق من عمولة جوميا (%)
// ============================================================================
function jmi_share_default() {
    $v = site_setting('jmi_mk_share', '');
    return ($v !== '' && is_numeric($v)) ? max(0, min(100, (float)$v)) : 50.0;
}
function jmi_share_for($link) {
    if (is_array($link) && isset($link['share']) && is_numeric($link['share']) && (float)$link['share'] >= 0) {
        return max(0, min(100, (float)$link['share']));
    }
    $ref = is_array($link) ? trim((string)($link['ref'] ?? '')) : '';
    if ($ref !== '' && function_exists('find_marketer_by_code')) {
        $mk = find_marketer_by_code($ref);
        if ($mk && isset($mk['jumia_share']) && $mk['jumia_share'] !== '' && is_numeric($mk['jumia_share'])) {
            return max(0, min(100, (float)$mk['jumia_share']));
        }
    }
    return jmi_share_default();
}

// نسبة المسوق من سعر البيع — الإدارة بتحددها لكل رابط عند الموافقة (null = لسه مش محددة)
function jmi_mk_pct($link) {
    if (!is_array($link) || !isset($link['mk_pct']) || !is_numeric($link['mk_pct'])) return null;
    return max(0, min(100, (float)$link['mk_pct']));
}
// عرض النسبة بدون أصفار زايدة (10.00 → 10 ، 4.50 → 4.5) — للعرض بس
function jmi_pct_txt($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
}
// المبلغ اللي المسوق هياخده على كل بيعة = سعر المنتج (اللي حددته الإدارة) × نسبة المسوق
function jmi_per_sale($link) {
    $cur   = strtoupper((string)($link['currency'] ?? jmi_currency($link['mkt'] ?? 'com')));
    $pct   = jmi_mk_pct($link);
    $price = max(0, (float)($link['price'] ?? 0));
    $fx    = jmi_fx($cur);
    $out = ['ok' => false, 'currency' => $cur, 'price' => round($price, 2), 'pct' => $pct, 'amount' => 0.0, 'egp' => 0.0, 'fx' => $fx];
    if ($pct === null || $price <= 0) return $out;
    $out['amount'] = round($price * $pct / 100, 2);
    $out['egp']    = round($out['amount'] * $fx, 2);
    $out['ok']     = true;
    return $out;
}

// ============================================================================
//  6) لينك التتبع: كل منتج ليه لينك تتبع من جوميا بتلصقه الإدارة عند الموافقة (مفيش تاجات لكل مسوق)
// ============================================================================
// مسوّق من لينك التتبع (مطابقة تقرير جوميا)
// ============================================================================
//  7) الروابط: بناء / قراءة / حفظ
// ============================================================================
function jmi_site_base() {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $root = (strpos($script, '/dashboard/') !== false) ? rtrim(dirname(dirname($script)), '/') : rtrim(dirname($script), '/');
    if ($root === '/') $root = '';
    return isset($_SERVER['HTTP_HOST']) ? ($scheme . '://' . $_SERVER['HTTP_HOST'] . $root) : '';
}
function jmi_track_url($link_id, $sub = '') {
    $u = jmi_site_base() . '/jmi.php?a=' . urlencode((string)$link_id);
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$sub);
    if ($s !== '') $u .= '&sub=' . urlencode(substr($s, 0, 30));
    return $u;
}
function jmi_links_all() {
    $l = jload(JMI_LINKS_F, []);
    return is_array($l) ? $l : [];
}
function jmi_link($id) {
    $l = jmi_links_all();
    return (isset($l[$id]) && is_array($l[$id])) ? $l[$id] : null;
}
function jmi_links_for($ref) {
    $ref = trim((string)$ref);
    $out = [];
    foreach (jmi_links_all() as $id => $l) {
        if (is_array($l) && (string)($l['ref'] ?? '') === $ref) $out[$id] = $l;
    }
    return $out;
}
function jmi_link_save($id, $data) {
    jupdate(JMI_LINKS_F, function (&$l) use ($id, $data) {
        if (!is_array($l)) $l = [];
        $cur = (isset($l[$id]) && is_array($l[$id])) ? $l[$id] : [];
        foreach ($data as $k => $v) $cur[$k] = $v;
        $cur['updated'] = date('c');
        $l[$id] = $cur;
        return true;
    }, []);
}
function jmi_link_delete($id) {
    $ok = jupdate(JMI_LINKS_F, function (&$l) use ($id) {
        if (!is_array($l)) return false;
        if (!isset($l[$id])) return false;
        unset($l[$id]);
        return true;
    }, []);
    // أي طلب مفتوح على الرابط ده بيتقفل (عشان الإدارة متعتمدش رابط اتمسح)
    jupdate(JMI_REQS_F, function (&$r) use ($id) {
        if (!is_array($r)) return false;
        $changed = false;
        foreach ($r as $k => $q) {
            if (is_array($q) && (string)($q['link_id'] ?? '') === (string)$id && (string)($q['status'] ?? '') === 'pending') {
                $r[$k]['status'] = 'rejected';
                $r[$k]['admin_note'] = 'المسوق سحب الطلب';
                $changed = true;
            }
        }
        return $changed;
    }, []);
    return $ok;
}

// ============================================================================
//  8) إنشاء رابط جديد من لينك جوميا
// ============================================================================
// ============================================================================
//  9) طلبات المسوقين للإدارة (موافقة / زيادة نسبة)
// ============================================================================
function jmi_reqs_all() {
    $r = jload(JMI_REQS_F, []);
    return is_array($r) ? $r : [];
}
function jmi_req_create($type, $ref, $link_id, $data = []) {
    $id = 'azr' . substr(md5(uniqid('', true) . $ref . $link_id), 0, 10);
    $req = [
        'id'         => $id,
        'type'       => (string)$type,                 // approval = طلب منتج | pct = تظلم على نسبة المسوق (طلب زيادتها)
        'ref'        => trim((string)$ref),
        'link_id'    => (string)$link_id,
        'pid'       => (string)($data['pid'] ?? ''),
        'title'      => mb_substr((string)($data['title'] ?? ''), 0, 180),
        'cat'        => (string)($data['cat'] ?? ''),
        'price_hint' => (isset($data['price']) && is_numeric($data['price'])) ? round(max(0, (float)$data['price']), 2) : 0,
        'note'       => mb_substr((string)($data['note'] ?? ''), 0, 400),
        'cur_pct'    => isset($data['cur_pct']) ? round((float)$data['cur_pct'], 2) : null,  // تظلم النسبة: نسبته وقت التقديم
        'status'     => 'pending',                     // pending | approved | rejected
        'admin_note' => '',
        'at'         => date('c'),
    ];
    jupdate(JMI_REQS_F, function (&$r) use ($id, $req) {
        if (!is_array($r)) $r = [];
        $r[$id] = $req;
        return true;
    }, []);
    if (function_exists('notify')) {
        $mk = find_marketer_by_code($req['ref']);
        $nm = $mk ? ($mk['name'] ?? $req['ref']) : $req['ref'];
        $line = '📨 طلب منتج من جوميا — ' . $nm . ' (' . $req['ref'] . ')'
              . "\n" . ($req['title'] !== '' ? $req['title'] : ('رقم المنتج: ' . $req['pid']))
              . ($req['price_hint'] > 0 ? "\nالسعر اللي كتبه المسوق: " . $req['price_hint'] : '')
              . ($req['note'] !== '' ? "\nملاحظة: " . mb_substr($req['note'], 0, 120) : '');
        if ($req['type'] === 'pct') {
            $line = '📈 تظلم على نسبة المسوق — ' . $nm . ' (' . $req['ref'] . ')'
                  . "\n" . ($req['title'] !== '' ? $req['title'] : ('رقم المنتج: ' . $req['pid']))
                  . "\nالنسبة الحالية: " . jmi_pct_txt($req['cur_pct']) . '%'
                  . ($req['note'] !== '' ? "\nالسبب: " . mb_substr($req['note'], 0, 160) : '');
        }
        notify('admin', $line, 'dashboard/admin.php?tab=jumia&sec=requests');
    }
    return $id;
}
function jmi_req_update($id, $data) {
    jupdate(JMI_REQS_F, function (&$r) use ($id, $data) {
        if (!is_array($r) || !isset($r[$id])) return false;
        foreach ($data as $k => $v) $r[$id][$k] = $v;
        return true;
    }, []);
}

// ============================================================================
//  9-ب) موافقة الإدارة على طلبات المنتجات
//  الإدارة بتحدد: لينك التتبع + سعر البيع + نسبة المسوق + القسم
//  بعد الموافقة بس الرابط بيظهر للمسوق في «الروابط المعتمدة»
// ============================================================================
function jmi_reject_request($rid, $note = '') {
    $reqs = jmi_reqs_all();
    if (!isset($reqs[$rid]) || !is_array($reqs[$rid])) return ['ok' => false, 'error' => 'الطلب ده مش موجود'];
    $r = $reqs[$rid];
    if ((string)($r['status'] ?? '') !== 'pending') return ['ok' => false, 'error' => 'الطلب ده اتعالج قبل كده'];
    $note = mb_substr(trim((string)$note), 0, 300);
    jmi_req_update($rid, ['status' => 'rejected', 'admin_note' => $note]);
    $lid = (string)($r['link_id'] ?? '');
    if ($lid !== '' && jmi_link($lid)) jmi_link_save($lid, ['status' => 'rejected']);
    $mk = find_marketer_by_code((string)($r['ref'] ?? ''));
    if ($mk && !empty($mk['email'])) {
        notify_user((string)$mk['email'], 'ℹ️ الإدارة مرجعتش طلب المنتج ده' . ($note !== '' ? ' — ' . $note : ''), 'dashboard/jumia.php?tab=links');
    }
    log_audit('jumia_req_reject', $rid, ['ref' => $r['ref'] ?? '']);
    return ['ok' => true];
}
// تعديل الإدارة لرابط: السعر + نسبة المسوق + القسم + لينك التتبع + الحالة
// ============================================================================
//  9-ج) تظلم المسوق على النسبة (طلب زيادة نسبة المسوق على رابط معتمد)
//  المسوق بيطلب نسبة أعلى من اللي الإدارة حددتها على الرابط، والإدارة توافق (وتحدد النسبة) أو ترفض.
//  الرفض مابيقفلش الرابط، وأي تغيير في النسبة بيسري على البيعات الجاية بس
//  (عمولة كل بيعة بتتحسب وقت تسجيلها في jmi_add_conversion).
// ============================================================================
function jmi_pct_open_for_link($link_id) {
    foreach (jmi_reqs_all() as $r) {
        if (is_array($r) && (string)($r['type'] ?? '') === 'pct' && (string)($r['status'] ?? '') === 'pending' && (string)($r['link_id'] ?? '') === (string)$link_id) return $r;
    }
    return null;
}
// تظلم المسوق على نسبته: بيتقدم بسبب التظلم بس — الإدارة هي اللي بتحدد النسبة الجديدة وقت الموافقة
function jmi_pct_appeal_create($ref, $link_id, $reason) {
    $ref     = trim((string)$ref);
    $link_id = (string)$link_id;
    $l       = $link_id !== '' ? jmi_link($link_id) : null;
    if (!$l || (string)($l['ref'] ?? '') !== $ref) return ['ok' => false, 'error' => 'الرابط ده مش موجود أو مش بتاعك'];
    if ((string)($l['status'] ?? '') !== 'approved') return ['ok' => false, 'error' => 'التظلم على النسبة بيتقدم على الروابط المعتمدة بس'];
    $cur = jmi_mk_pct($l);
    if ($cur === null || $cur <= 0) return ['ok' => false, 'error' => 'الإدارة لسه ماحددتش نسبتك على الرابط ده'];
    if ($cur >= 100) return ['ok' => false, 'error' => 'وصلت للحد الأقصى (100%)'];
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 5) return ['ok' => false, 'error' => 'اكتب سبب التظلم (5 حروف على الأقل)'];
    if (jmi_pct_open_for_link($link_id)) return ['ok' => false, 'error' => 'عندك تظلم بانتظار الإدارة على الرابط ده بالفعل'];
    $id = jmi_req_create('pct', $ref, $link_id, [
        'title' => (string)($l['title'] ?? ''), 'pid' => (string)($l['pid'] ?? ''), 'cat' => (string)($l['cat'] ?? ''),
        'note'  => $reason, 'cur_pct' => $cur,
    ]);
    log_audit('jumia_pct_appeal', $id, ['link' => $link_id, 'ref' => $ref, 'from' => $cur]);
    return ['ok' => true, 'id' => $id];
}
// موافقة الإدارة على التظلم: بتحدد النسبة الجديدة وتتطبق على الرابط
function jmi_approve_pct_request($rid, $in = []) {
    $reqs = jmi_reqs_all();
    if (!isset($reqs[$rid]) || !is_array($reqs[$rid]) || (string)($reqs[$rid]['type'] ?? '') !== 'pct') return ['ok' => false, 'error' => 'التظلم ده مش موجود'];
    $r = $reqs[$rid];
    if ((string)($r['status'] ?? '') !== 'pending') return ['ok' => false, 'error' => 'التظلم ده اتعالج قبل كده'];
    $lid = (string)($r['link_id'] ?? '');
    $l   = $lid !== '' ? jmi_link($lid) : null;
    if (!$l || (string)($l['status'] ?? '') !== 'approved') {
        jmi_req_update($rid, ['status' => 'rejected', 'admin_note' => 'الرابط اتشال أو مبقاش معتمد قبل المراجعة']);
        return ['ok' => false, 'error' => 'الرابط ده اتشال أو مبقاش معتمد — التظلم اتقفل تلقائياً'];
    }
    $new = (isset($in['mk_pct']) && trim((string)$in['mk_pct']) !== '' && is_numeric($in['mk_pct']))
         ? round((float)$in['mk_pct'], 2) : 0.0;
    if ($new <= 0 || $new > 100) return ['ok' => false, 'error' => 'النسبة الجديدة لازم تكون أكبر من صفر ومش أكتر من 100'];
    $from = jmi_mk_pct($l);
    $note = mb_substr(trim((string)($in['admin_note'] ?? '')), 0, 300);
    jmi_link_save($lid, ['mk_pct' => $new]);
    jmi_req_update($rid, ['status' => 'approved', 'admin_note' => $note, 'approved_at' => date('c'), 'applied_pct' => $new]);
    $after = jmi_link($lid) ?: $l;
    $ps    = jmi_per_sale($after);
    $title = (string)($after['title'] ?? '') !== '' ? (string)$after['title'] : (string)($after['pid'] ?? '');
    $mk    = find_marketer_by_code((string)($r['ref'] ?? ''));
    if ($mk && !empty($mk['email'])) {
        notify_user((string)$mk['email'], '✅ الإدارة وافقت على تظلمك على ' . $title
            . ($ps['ok'] ? ' — هتاخد ' . number_format((float)$ps['amount'], 2) . ' ' . jmi_cur_label((string)$ps['currency']) . ' على كل بيعة جاية' : ''),
            'dashboard/jumia.php?tab=links');
    }
    log_audit('jumia_pct_approve', $rid, ['link' => $lid, 'ref' => $r['ref'] ?? '', 'from' => $from, 'to' => $new]);
    return ['ok' => true, 'from' => $from, 'to' => $new, 'per_sale' => $ps];
}
// رفض التظلم: الرابط نفسه مابيتقفلش ونسبته بتفضل زي ما هي
function jmi_reject_pct_request($rid, $note = '') {
    $reqs = jmi_reqs_all();
    if (!isset($reqs[$rid]) || !is_array($reqs[$rid]) || (string)($reqs[$rid]['type'] ?? '') !== 'pct') return ['ok' => false, 'error' => 'التظلم ده مش موجود'];
    $r = $reqs[$rid];
    if ((string)($r['status'] ?? '') !== 'pending') return ['ok' => false, 'error' => 'التظلم ده اتعالج قبل كده'];
    $note = mb_substr(trim((string)$note), 0, 300);
    jmi_req_update($rid, ['status' => 'rejected', 'admin_note' => $note]);
    $lid = (string)($r['link_id'] ?? '');
    $l   = $lid !== '' ? jmi_link($lid) : null;
    $title = $l ? ((string)($l['title'] ?? '') !== '' ? (string)$l['title'] : (string)($l['pid'] ?? '')) : (string)($r['pid'] ?? '');
    $mk = find_marketer_by_code((string)($r['ref'] ?? ''));
    if ($mk && !empty($mk['email'])) {
        notify_user((string)$mk['email'], 'ℹ️ الإدارة مرجعتش تظلمك على ' . $title . ($note !== '' ? ' — ' . $note : ''), 'dashboard/jumia.php?tab=links');
    }
    log_audit('jumia_pct_reject', $rid, ['ref' => $r['ref'] ?? '', 'link' => $lid]);
    return ['ok' => true];
}

// ============================================================================
//  10) النقرات
// ============================================================================
function jmi_record_click($link_id, $sub = '') {
    $link = jmi_link($link_id);
    if (!$link) return false;
    if (($link['status'] ?? 'approved') !== 'approved') return false;
    $vid = function_exists('visitor_id') ? visitor_id() : session_id();
    $ip  = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ck  = 'amzc_' . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$link_id);
    $already = !empty($_COOKIE[$ck]);
    if (!$already && !headers_sent()) {
        @setcookie($ck, '1', time() + 86400, '/', '', false, true);
    }
    $now = date('c');
    jupdate(JMI_LINKS_F, function (&$l) use ($link_id, $already, $now) {
        if (!isset($l[$link_id]) || !is_array($l[$link_id])) return false;
        $l[$link_id]['clicks'] = (int)($l[$link_id]['clicks'] ?? 0) + 1;
        if (!$already) $l[$link_id]['uniq'] = (int)($l[$link_id]['uniq'] ?? 0) + 1;
        $l[$link_id]['last_click'] = $now;
        return true;
    }, []);
    jupdate(JMI_CLICKS_F, function (&$c) use ($link_id, $link, $sub, $vid, $ip, $already, $now) {
        if (!is_array($c)) $c = [];
        $c[] = [
            'id'      => $link_id,
            'ref'     => (string)($link['ref'] ?? ''),
            'mkt'     => (string)($link['mkt'] ?? ''),
            'pid'    => (string)($link['pid'] ?? ''),
            'sub'     => substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$sub), 0, 30),
            'vid'     => substr((string)$vid, 0, 40),
            'ip'      => $ip,
            'ua'      => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            'from'    => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 200),
            'uniq'    => $already ? 0 : 1,
            'at'      => $now,
        ];
        if (count($c) > 20000) $c = array_slice($c, -10000);
        return true;
    }, []);
    return true;
}
function jmi_clicks_for($ref, $limit = 500) {
    $ref = trim((string)$ref);
    $all = jload(JMI_CLICKS_F, []);
    if (!is_array($all)) return [];
    $rows = array_values(array_filter($all, function ($c) use ($ref) { return (string)($c['ref'] ?? '') === $ref; }));
    return array_slice(array_reverse($rows), 0, $limit);
}

// ============================================================================
//  11) حساب العمولة (جوميا → الموقع → المسوق)
// ============================================================================
function jmi_calc($link, $value, $qty = 1, $comm_override = null, $mk_egp_override = null) {
    $value = max(0, (float)$value);
    $qty   = max(1, (int)$qty);
    $cur   = strtoupper((string)($link['currency'] ?? jmi_currency($link['mkt'] ?? 'com')));
    $rate  = (isset($link['rate']) && is_numeric($link['rate'])) ? max(0, min(100, (float)$link['rate'])) : jmi_rate($link['cat'] ?? 'other');
    $gross = round($value * $qty, 2);
    // عمولة جوميا اللي الإدارة كتبتها = نسبة % من إجمالي البيع (مش مبلغ). مثال: 5 على 1479 = 73.95
    $jmi_comm = ($comm_override !== null && is_numeric($comm_override) && (float)$comm_override >= 0)
        ? round($gross * (float)$comm_override / 100, 2)
        : round($gross * $rate / 100, 2);
    $eff_rate = ($gross > 0) ? round($jmi_comm / $gross * 100, 3) : $rate;
    $fx = jmi_fx($cur);
    $pct = jmi_mk_pct($link);
    if ($pct !== null) {
        // نظام الإدارة: نسبة المسوق من سعر البيع (مثال: 10% من 300 = 30)
        $mk_amt = round($gross * $pct / 100, 2);
        $share  = ($jmi_comm > 0) ? round(min(100, $mk_amt / $jmi_comm * 100), 2) : 0;
    } else {
        // الروابط القديمة (قبل نظام نسبة السعر): نسبة المسوق من عمولة جوميا
        $share  = jmi_share_for($link);
        $mk_amt = round($jmi_comm * $share / 100, 2);
    }
    $mk_egp = round($mk_amt * $fx, 2);
    if ($mk_egp_override !== null && is_numeric($mk_egp_override) && (float)$mk_egp_override >= 0) {
        // الإدارة كتبت عمولة المسوق بالجنيه يدوياً (بتغلب على الحساب)
        $mk_egp = round((float)$mk_egp_override, 2);
        $mk_amt = ($fx > 0) ? round($mk_egp / $fx, 2) : $mk_egp;
    }
    $jmi_egp  = round($jmi_comm * $fx, 2);
    $site_egp = round($jmi_egp - $mk_egp, 2);
    $site_amt = round($jmi_comm - $mk_amt, 2);
    return [
        'currency' => $cur, 'fx' => $fx, 'qty' => $qty, 'value' => $value, 'gross' => $gross,
        'rate' => $rate, 'eff_rate' => $eff_rate, 'share' => $share, 'mk_pct' => $pct,
        'jmi_comm' => $jmi_comm, 'mk_amt' => $mk_amt, 'site_amt' => $site_amt,
        'mk_egp' => $mk_egp, 'site_egp' => $site_egp, 'jmi_egp' => $jmi_egp,
    ];
}

// ============================================================================
//  12) التحويلات (البيعات) — العمولة للموقع + للمسوق
// ============================================================================
function jmi_orders_all() {
    $o = jload(JMI_ORDERS_F, []);
    return is_array($o) ? $o : [];
}
function jmi_orders_for($ref) {
    $ref = trim((string)$ref);
    $out = [];
    foreach (jmi_orders_all() as $o) {
        if (is_array($o) && (string)($o['ref'] ?? '') === $ref) $out[] = $o;
    }
    return $out;
}
function jmi_order($cid) {
    foreach (jmi_orders_all() as $o) {
        if (is_array($o) && (string)($o['id'] ?? '') === (string)$cid) return $o;
    }
    return null;
}
function jmi_add_conversion($link_id, $value, $qty = 1, $opt = []) {
    $link = jmi_link($link_id);
    if (!$link) return ['ok' => false, 'error' => 'الرابط غير موجود'];
    $override    = (isset($opt['jmi_comm']) && is_numeric($opt['jmi_comm'])) ? (float)$opt['jmi_comm'] : null;
    if ($override !== null && ($override < 0 || $override > 100)) {
        return ['ok' => false, 'error' => 'عمولة جوميا بالنسبة % لازم تكون من 0 لـ 100 (اكتب 5 لو 5%، مش المبلغ بالجنيه)'];
    }
    $mk_override = (isset($opt['mk_egp']) && is_numeric($opt['mk_egp']) && (float)$opt['mk_egp'] >= 0) ? (float)$opt['mk_egp'] : null;
    $calc = jmi_calc($link, $value, $qty, $override, $mk_override);
    if ($calc['jmi_comm'] <= 0 && $calc['gross'] <= 0 && $mk_override === null) {
        return ['ok' => false, 'error' => 'المبلغ أو العمولة لازم تكون أكبر من صفر'];
    }
    $status = ((string)($opt['status'] ?? 'pending') === 'confirmed') ? 'confirmed' : 'pending';
    $ref  = (string)($link['ref'] ?? '');
    $mk   = $ref !== '' ? find_marketer_by_code($ref) : null;
    $email= $mk ? (string)($mk['email'] ?? '') : (string)($link['email'] ?? '');
    $cid  = 'azc' . substr(md5(uniqid('', true) . $link_id . microtime(true)), 0, 10);
    $title = (string)($link['title'] ?? '');
    $conv = [
        'id'        => $cid,
        'link_id'   => (string)$link_id,
        'ref'       => $ref,
        'email'     => $email,
        'mkt'       => (string)($link['mkt'] ?? ''),
        'pid'      => (string)($link['pid'] ?? ''),
        'title'     => $title,
        'cat'       => (string)($link['cat'] ?? 'other'),
        'qty'       => (int)$calc['qty'],
        'value'     => (float)$calc['value'],
        'currency'  => $calc['currency'],
        'fx'        => $calc['fx'],
        'gross'     => $calc['gross'],
        'rate'      => $calc['eff_rate'],
        'share'     => $calc['share'],
        'mk_pct'    => $calc['mk_pct'],
        'jmi_comm'  => $calc['jmi_comm'],
        'mk_amt'    => $calc['mk_amt'],
        'site_amt'  => $calc['site_amt'],
        'mk_egp'    => $calc['mk_egp'],
        'site_egp'  => $calc['site_egp'],
        'source'    => (string)($opt['source'] ?? 'manual'),
        'ext_key'   => (string)($opt['ext_key'] ?? ''),
        'note'      => mb_substr((string)($opt['note'] ?? ''), 0, 250),
        'status'    => $status,                       // pending | confirmed | reversed
        'at'        => (string)($opt['at'] ?? date('c')),
    ];
    jupdate(JMI_ORDERS_F, function (&$o) use ($conv) {
        if (!is_array($o)) $o = [];
        $o[] = $conv;
        if (count($o) > 20000) $o = array_slice($o, -10000);
        return true;
    }, []);
    jupdate(JMI_LINKS_F, function (&$l) use ($link_id, $conv) {
        if (!isset($l[$link_id]) || !is_array($l[$link_id])) return false;
        $l[$link_id]['orders']    = (int)($l[$link_id]['orders'] ?? 0) + 1;
        $l[$link_id]['qty']       = (int)($l[$link_id]['qty'] ?? 0) + (int)$conv['qty'];
        $l[$link_id]['gross']     = round((float)($l[$link_id]['gross'] ?? 0) + (float)$conv['gross'], 2);
        $l[$link_id]['mk_earn']   = round((float)($l[$link_id]['mk_earn'] ?? 0) + (float)$conv['mk_egp'], 2);
        $l[$link_id]['site_earn'] = round((float)($l[$link_id]['site_earn'] ?? 0) + (float)$conv['site_egp'], 2);
        $l[$link_id]['updated']   = date('c');
        return true;
    }, []);
    if ($email !== '' && ($conv['mk_egp'] > 0)) {
        $label = '🛍️ عمولة جوميا: ' . ($conv['title'] !== '' ? $conv['title'] : $conv['pid']);
        commission_credit_pending($email, $conv['mk_egp'], null, $label);
        if ($status === 'confirmed') {
            commission_release($email, $conv['mk_egp'], null, 'تأكيد عمولة جوميا (' . $conv['pid'] . ')');
        } elseif (function_exists('notify_user')) {
            notify_user($email, '🛍️ مبيعة جديدة من لينك جوميا (' . $conv['pid'] . ') — عمولتك ' . number_format($conv['mk_egp'], 2) . ' ج.م (معلقة لحين تأكيد الإدارة)', 'dashboard/jumia.php?tab=conversions');
        }
    }
    return ['ok' => true, 'id' => $cid, 'conv' => $conv];
}
function jmi_confirm_conversion($cid) {
    $found = null;
    jupdate(JMI_ORDERS_F, function (&$o) use ($cid, &$found) {
        if (!is_array($o)) return false;
        foreach ($o as $i => $c) {
            if (is_array($c) && (string)($c['id'] ?? '') === (string)$cid && (string)($c['status'] ?? '') === 'pending') {
                $o[$i]['status'] = 'confirmed';
                $o[$i]['confirmed_at'] = date('c');
                $found = $o[$i];
                return true;
            }
        }
        return false;
    }, []);
    if ($found && !empty($found['email']) && (float)($found['mk_egp'] ?? 0) > 0) {
        commission_release((string)$found['email'], (float)$found['mk_egp'], null, 'تأكيد عمولة جوميا (' . ($found['pid'] ?? '') . ')');
    }
    return $found;
}
function jmi_reverse_conversion($cid, $reason = 'إلغاء/إرجاع من جوميا') {
    $found = null;
    $prev  = '';
    jupdate(JMI_ORDERS_F, function (&$o) use ($cid, $reason, &$found, &$prev) {
        if (!is_array($o)) return false;
        foreach ($o as $i => $c) {
            if (is_array($c) && (string)($c['id'] ?? '') === (string)$cid && (string)($c['status'] ?? '') !== 'reversed') {
                $prev = (string)$c['status'];
                $o[$i]['status'] = 'reversed';
                $o[$i]['reversed_at'] = date('c');
                $o[$i]['note'] = mb_substr((string)$reason, 0, 200);
                $found = $o[$i];
                return true;
            }
        }
        return false;
    }, []);
    if ($found) {
        $mk_egp = round((float)($found['mk_egp'] ?? 0), 2);
        if (!empty($found['email']) && $mk_egp > 0) {
            // المعلقة بتتخصم من المعلق، والمؤكدة بتتخصم من الرصيد المتاح
            $state = ($prev === 'confirmed') ? 'confirmed' : 'pending';
            jmi_adjust_balance((string)$found['email'], -$mk_egp, $state, 'إلغاء عمولة جوميا (' . ($found['pid'] ?? '') . ')');
            notify_user((string)$found['email'], '↩️ اتلغت عمولة بيعة (' . ($found['pid'] ?? '') . ') — ' . number_format($mk_egp, 2) . ' ج.م', 'dashboard/jumia.php?tab=conversions');
        }
        jupdate(JMI_LINKS_F, function (&$l) use ($found) {
            $lid = (string)($found['link_id'] ?? '');
            if (!isset($l[$lid]) || !is_array($l[$lid])) return false;
            $l[$lid]['orders']    = max(0, (int)($l[$lid]['orders'] ?? 0) - 1);
            $l[$lid]['qty']       = max(0, (int)($l[$lid]['qty'] ?? 0) - (int)($found['qty'] ?? 0));
            $l[$lid]['gross']     = max(0, round((float)($l[$lid]['gross'] ?? 0) - (float)($found['gross'] ?? 0), 2));
            $l[$lid]['mk_earn']   = max(0, round((float)($l[$lid]['mk_earn'] ?? 0) - (float)($found['mk_egp'] ?? 0), 2));
            $l[$lid]['site_earn'] = max(0, round((float)($l[$lid]['site_earn'] ?? 0) - (float)($found['site_egp'] ?? 0), 2));
            return true;
        }, []);
    }
    return $found;
}

// ============================================================================
//  12-ب) أرصدة المسوق + تعديل الإدارة للأرباح (معلقة / مؤكدة) + أرقام الكروت
// ============================================================================
// يعدّل رصيد المسوق بالفرق بس: معلّق (pending_balance) أو متاح للسحب (balance)
function jmi_adjust_balance($email, $delta, $state = 'pending', $reason = '') {
    $delta = round((float)$delta, 2);
    if ($delta == 0 || (string)$email === '') return false;
    $state = ($state === 'confirmed') ? 'confirmed' : 'pending';
    update_user((string)$email, function (&$u) use ($delta, $state) {
        if ($state === 'pending') {
            $u['pending_balance'] = round(max(0, (float)($u['pending_balance'] ?? 0) + $delta), 2);
        } else {
            $u['balance'] = round(max(0, (float)($u['balance'] ?? 0) + $delta), 2);
        }
    });
    balance_ledger_add((string)$email, 'adjust', $delta, $reason !== '' ? $reason : 'تعديل عمولة جوميا', ['state' => $state]);
    return true;
}
// تعديل الإدارة لأرباح المسوق على بيعة (معلقة أو مؤكدة): الرابط والرصيد بيتعدلوا بالفرق بس
function jmi_set_conv_earn($cid, $new_mk_egp, $reason = 'تعديل الإدارة لعمولة جوميا') {
    $cur = jmi_order($cid);
    if (!$cur || (string)($cur['status'] ?? '') === 'reversed') return ['ok' => false, 'error' => 'البيعة دي مش موجودة أو اتلغت'];
    $new = round(max(0, (float)$new_mk_egp), 2);
    $old = round((float)($cur['mk_egp'] ?? 0), 2);
    $fx  = (float)($cur['fx'] ?? 0);
    if ($fx <= 0) $fx = jmi_fx((string)($cur['currency'] ?? 'USD'));
    $jmi_egp  = round((float)($cur['jmi_comm'] ?? 0) * $fx, 2);
    $new_site = round($jmi_egp - $new, 2);
    $delta      = round($new - $old, 2);
    $site_delta = round($new_site - (float)($cur['site_egp'] ?? 0), 2);
    $lid   = (string)($cur['link_id'] ?? '');
    $found = null;
    jupdate(JMI_ORDERS_F, function (&$o) use ($cid, $new, $new_site, $fx, &$found) {
        if (!is_array($o)) return false;
        foreach ($o as $i => $c) {
            if (is_array($c) && (string)($c['id'] ?? '') === (string)$cid && (string)($c['status'] ?? '') !== 'reversed') {
                $o[$i]['mk_egp']    = $new;
                $o[$i]['mk_amt']    = round($new / $fx, 2);
                $o[$i]['site_egp']  = $new_site;
                $o[$i]['site_amt']  = round($new_site / $fx, 2);
                $o[$i]['edited_at'] = date('c');
                $found = $o[$i];
                return true;
            }
        }
        return false;
    }, []);
    if (!$found) return ['ok' => false, 'error' => 'تعذر تعديل البيعة'];
    if ($lid !== '') {
        jupdate(JMI_LINKS_F, function (&$l) use ($lid, $delta, $site_delta) {
            if (!isset($l[$lid]) || !is_array($l[$lid])) return false;
            $l[$lid]['mk_earn']   = round(max(0, (float)($l[$lid]['mk_earn'] ?? 0) + $delta), 2);
            $l[$lid]['site_earn'] = round(max(0, (float)($l[$lid]['site_earn'] ?? 0) + $site_delta), 2);
            $l[$lid]['updated']   = date('c');
            return true;
        }, []);
    }
    $email = (string)($found['email'] ?? '');
    $state = ((string)($found['status'] ?? '') === 'confirmed') ? 'confirmed' : 'pending';
    if ($email !== '' && $delta != 0) {
        jmi_adjust_balance($email, $delta, $state, $reason . ' (' . ($found['pid'] ?? '') . ')');
        notify_user($email, '✏️ الإدارة عدّلت عمولتك على بيعة (' . ($found['pid'] ?? '') . ') إلى ' . number_format($new, 2)
            . ' ج.م (' . ($state === 'confirmed' ? 'مؤكدة' : 'معلقة') . ')', 'dashboard/jumia.php?tab=conversions');
    }
    log_audit('jumia_conv_edit', $cid, ['old' => $old, 'new' => $new, 'state' => $state]);
    return ['ok' => true, 'delta' => $delta, 'conv' => $found];
}
// أرقام كل رابط للكروت: عدد البيعات + أرباح المسوق المعلقة والمؤكدة (من السجل، بدون الملغية)
function jmi_money_by_link() {
    $map = [];
    foreach (jmi_orders_all() as $o) {
        if (!is_array($o)) continue;
        $st  = (string)($o['status'] ?? '');
        $lid = (string)($o['link_id'] ?? '');
        if ($st === 'reversed' || $lid === '') continue;
        if (!isset($map[$lid])) $map[$lid] = ['sales' => 0, 'qty' => 0, 'pending' => 0.0, 'confirmed' => 0.0];
        $map[$lid]['sales']++;
        $map[$lid]['qty'] += (int)($o['qty'] ?? 1);
        if ($st === 'confirmed') $map[$lid]['confirmed'] += (float)($o['mk_egp'] ?? 0);
        else                     $map[$lid]['pending']   += (float)($o['mk_egp'] ?? 0);
    }
    foreach ($map as $k => $m) {
        $map[$k]['pending']   = round($m['pending'], 2);
        $map[$k]['confirmed'] = round($m['confirmed'], 2);
    }
    return $map;
}

// ============================================================================
//  13) إحصائيات
// ============================================================================
function jmi_stats() {
    $links  = jmi_links_all();
    $orders = jmi_orders_all();
    $s = ['links' => 0, 'pending_links' => 0, 'clicks' => 0, 'uniq' => 0, 'orders' => 0,
          'gross' => 0, 'jmi_comm' => 0, 'site_egp' => 0, 'mk_egp' => 0, 'pending_conv' => 0, 'confirmed_conv' => 0];
    foreach ($links as $l) {
        if (!is_array($l)) continue;
        $s['links']++;
        $s['clicks'] += (int)($l['clicks'] ?? 0);
        $s['uniq']   += (int)($l['uniq'] ?? 0);
        if ((string)($l['status'] ?? '') === 'pending') $s['pending_links']++;
    }
    foreach ($orders as $o) {
        if (!is_array($o) || (string)($o['status'] ?? '') === 'reversed') continue;
        $s['orders']++;
        $s['gross']    += (float)($o['gross'] ?? 0);
        $s['jmi_comm'] += (float)($o['jmi_comm'] ?? 0);
        $s['site_egp'] += (float)($o['site_egp'] ?? 0);
        $s['mk_egp']   += (float)($o['mk_egp'] ?? 0);
        if ((string)($o['status'] ?? '') === 'confirmed') $s['confirmed_conv']++; else $s['pending_conv']++;
    }
    $s['gross'] = round($s['gross'], 2);
    $s['jmi_comm'] = round($s['jmi_comm'], 2);
    $s['site_egp'] = round($s['site_egp'], 2);
    $s['mk_egp'] = round($s['mk_egp'], 2);
    return $s;
}
function jmi_stats_for($ref) {
    $ref = trim((string)$ref);
    $s = ['links' => 0, 'clicks' => 0, 'uniq' => 0, 'orders' => 0, 'gross' => 0, 'mk_egp' => 0, 'site_egp' => 0, 'pending_egp' => 0, 'confirmed_egp' => 0];
    foreach (jmi_links_for($ref) as $l) {
        $s['links']++;
        $s['clicks'] += (int)($l['clicks'] ?? 0);
        $s['uniq']   += (int)($l['uniq'] ?? 0);
    }
    foreach (jmi_orders_for($ref) as $o) {
        if ((string)($o['status'] ?? '') === 'reversed') continue;
        $s['orders']++;
        $s['gross'] += (float)($o['gross'] ?? 0);
        $s['mk_egp'] += (float)($o['mk_egp'] ?? 0);
        $s['site_egp'] += (float)($o['site_egp'] ?? 0);
        if ((string)($o['status'] ?? '') === 'confirmed') $s['confirmed_egp'] += (float)($o['mk_egp'] ?? 0);
        else $s['pending_egp'] += (float)($o['mk_egp'] ?? 0);
    }
    $s['gross'] = round($s['gross'], 2);
    $s['mk_egp'] = round($s['mk_egp'], 2);
    $s['site_egp'] = round($s['site_egp'], 2);
    $s['pending_egp'] = round($s['pending_egp'], 2);
    $s['confirmed_egp'] = round($s['confirmed_egp'], 2);
    return $s;
}

// ============================================================================
//  14) استيراد تقرير أرباح جوميا (CSV)
// ============================================================================
function jmi_num_csv($v) {
    $s = trim((string)$v);
    $s = str_replace(["$", '٬', ','], '', $s);
    $neg = (substr($s, 0, 1) === '(' && substr($s, -1) === ')');
    $s = str_replace(['(', ')'], '', $s);
    if (!is_numeric($s)) return 0.0;
    $n = (float)$s;
    return $neg ? -$n : $n;
}
function jmi_import_csv($text, $opt = []) {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', (string)$text);
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $hi = -1;
    foreach ($lines as $i => $ln) {
        if (trim($ln) === '') continue;
        if (strpos($ln, ',') !== false || strpos($ln, "\t") !== false) { $hi = (int)$i; break; }
    }
    if ($hi < 0) return ['ok' => false, 'error' => 'الملف فاضي أو مش بصيغة CSV صحيحة'];
    $delim = (substr_count($lines[$hi], "\t") > substr_count($lines[$hi], ',')) ? "\t" : ',';
    $cols = array_map(function ($x) { return strtolower(trim(trim((string)$x), "\"' ")); }, str_getcsv($lines[$hi], $delim));
    $find = function ($keys) use ($cols) {
        foreach ($keys as $k) {
            foreach ($cols as $i => $c) { if ($c !== '' && strpos($c, $k) !== false) return (int)$i; }
        }
        return -1;
    };
    $i_date  = $find(['date', 'تاريخ']);
    $i_pid  = $find(['pid', 'sku', 'product id']);
    $i_tag   = $find(['tracking id', 'tracking', 'store id', 'associate', 'tag']);
    $i_qty   = $find(['shipped items', 'ordered items', 'items shipped', 'items ordered', 'items', 'quantity', 'units', 'وحدات', 'عدد']);
    $i_price = $find(['shipped revenue', 'ordered revenue', 'revenue', 'price', 'المبيعات', 'سعر']);
    $i_comm  = $find(['earnings', 'commission', 'عمولة', 'أرباح']);
    if ($i_comm < 0 && $i_price < 0) return ['ok' => false, 'error' => 'مش لاقي أعمدة الأرباح/العمولة في الملف — حمّل تقرير Earnings من جوميا'];

    $existing = [];
    foreach (jmi_orders_all() as $o) { if (!empty($o['ext_key'])) $existing[(string)$o['ext_key']] = 1; }

    $links_by_pid = [];
    foreach (jmi_links_all() as $id => $l) {
        if (!is_array($l)) continue;
        $a = strtoupper((string)($l['pid'] ?? ''));
        if ($a === '') continue;
        if (!isset($links_by_pid[$a])) $links_by_pid[$a] = [];
        $links_by_pid[$a][$id] = $l;
    }

    $report = ['rows' => 0, 'imported' => 0, 'skipped' => 0, 'unmatched' => [], 'total_comm' => 0, 'total_mk' => 0, 'total_site' => 0, 'errors' => []];
    for ($i = $hi + 1; $i < count($lines); $i++) {
        $ln = trim($lines[$i]);
        if ($ln === '' || strpos($ln, $delim) === false) continue;
        $row = str_getcsv($ln, $delim);
        $report['rows']++;
        $pid = $i_pid >= 0 ? strtoupper(trim((string)($row[$i_pid] ?? ''))) : '';
        $tag  = $i_tag >= 0 ? trim((string)($row[$i_tag] ?? '')) : '';
        $date = $i_date >= 0 ? trim((string)($row[$i_date] ?? '')) : '';
        $qty  = $i_qty >= 0 ? (int)jmi_num_csv($row[$i_qty] ?? 1) : 1;
        if ($qty < 1) $qty = 1;
        $price = $i_price >= 0 ? jmi_num_csv($row[$i_price] ?? 0) : 0;
        $comm  = $i_comm >= 0 ? jmi_num_csv($row[$i_comm] ?? 0) : 0;
        if ($comm <= 0 && $price > 0) $comm = 0;      // هيُحسب من النسبة
        if ($comm <= 0 && $price <= 0) { $report['skipped']++; continue; }
        $key = md5($date . '|' . $pid . '|' . $qty . '|' . $price . '|' . $comm);
        if (isset($existing[$key])) { $report['skipped']++; continue; }

        $target = null;
        $ref_by_tag = '';
        if ($pid !== '' && isset($links_by_pid[$pid])) {
            foreach ($links_by_pid[$pid] as $lid => $l) {
                if ((string)($l['status'] ?? 'approved') !== 'approved') continue;   // بس الروابط المعتمدة بتستقبل بيعات
                if ($ref_by_tag !== '' && (string)($l['ref'] ?? '') === $ref_by_tag) { $target = $lid; break; }
                if ($target === null) $target = $lid;
            }
        } elseif ($ref_by_tag !== '') {
            foreach (jmi_links_all() as $lid => $l) {
                if ((string)($l['status'] ?? 'approved') !== 'approved') continue;
                if ((string)($l['ref'] ?? '') === $ref_by_tag) { $target = $lid; break; }
            }
        }
        if ($target === null) {
            $report['unmatched'][] = ['pid' => $pid, 'tag' => $tag, 'date' => $date, 'price' => $price, 'comm' => $comm];
            continue;
        }
        $res = jmi_add_conversion($target, max(0, $price / max(1, $qty)), $qty, [
            'jmi_comm' => $comm,
            'source'   => 'csv',
            'ext_key'  => $key,
            'note'     => 'استيراد تقرير جوميا' . ($date !== '' ? ' (' . $date . ')' : ''),
            'status'   => (string)($opt['status'] ?? 'pending'),
        ]);
        if (!empty($res['ok'])) {
            $existing[$key] = 1;
            $report['imported']++;
            $report['total_comm'] += $comm;
            $report['total_mk']   += (float)($res['conv']['mk_egp'] ?? 0);
            $report['total_site'] += (float)($res['conv']['site_egp'] ?? 0);
        } else {
            $report['skipped']++;
        }
    }
    $report['total_comm'] = round($report['total_comm'], 2);
    $report['total_mk'] = round($report['total_mk'], 2);
    $report['total_site'] = round($report['total_site'], 2);
    return array_merge(['ok' => true], $report);
}

// ============================================================================
//  15) (اختياري) جلب بيانات المنتج من صفحة جوميا
//      العنوان والسعر وكل الصور والفيديوهات والوصف الكامل والمواصفات والقسم من مسار التصنيف.
//      ملاحظة: جوميا ممكن ترفض الطلب من السيرفر، حينها المسوق يكتبها يدوياً.
//      تحليل الصفحة في jmi_parse_product_html() دالة نقية (من غير اتصال شبكة) عشان نقدر نختبرها.
// ============================================================================
// تحليل HTML صفحة المنتج → مصفوفة فيها الحقول اللي اتقرت بس
// فك الـ escape اللي بيتحط في JSON الصفحة (\/ و \u002F و \u0026) عشان الروابط تبقى سليمة
function jmi_unescape_media($html) {
    return str_replace(['\\/', '\\u002F', '\\u002f', '\\u0026', '&amp;'], ['/', '/', '/', '&', '&'], (string)$html);
}

// الرابط الأصلي للصورة في جوميا (من غير أحجام الـ CDN زي ._AC_SL1500_) عشان نحمّلها بأعلى جودة
// يرجّع نص المصفوفة JSON اللي بتبدأ عند $start (بيتخطى الأقواس اللي جوه النصوص)
// مدخلات الـ gallery (colorImages → initial) من JSON الصفحة. بترجع [] لو مش لاقيها أو مش قابلة للقراءة
// نهاية الصفحة اللي فيها المنتج نفسه: قبل قسم التقييمات (عشان فيديوهات العملاء متدخلش)
// جزء من الصفحة من بداية قسم معين لحد أول قسم تاني بيبدأ بعده (أو لحد الحد الأقصى من البايتات)
// الأقسام اللي بتقفل قسم A+ (عشان صور وفيديوهات «منتجات ممكن تعجبك» متدخلش)
// صور المنتج: كل صور الـ gallery (hiRes ثم large لكل variant) + صور A+ بعد كده، من غير تكرار
// سطور متكررة بتتشال (مقارنة من غير علامة النقطة)، والسطر الفاضي بيفضل للفقرات
function jmi_dedupe_lines($s) {
    $out = []; $seen = [];
    foreach (explode("\n", (string)$s) as $line) {
        $line = trim($line);
        if ($line === '') { $out[] = ''; continue; }
        $k = mb_strtolower((string)preg_replace('~^•\s*~u', '', $line));
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $line;
    }
    return implode("\n", $out);
}

// الوصف الكامل: نقاط About this item + فقرة الوصف (productDescription) + نص قسم A+. من غير تكرار، و6000 حرف كحد أقصى
// نص قسم معين من الصفحة (بعد تنظيفه من الكود والوسوم)
// صفوف جداول التفاصيل: Product Overview (td/td) + Product information (th/td) + Product details (نقاط)
// فيديوهات المنتج (mp4 من سيرفرات جوميا): كل الروابط قبل قسم التقييمات، وفيديوهات المنتج (vse-vms) الأول
// مسار التصنيف (Breadcrumb) بالترتيب من العام للخاص
// القسم تلقائياً من مسار التصنيف (من أعمق قسم للأعم). لو مفيش تطابق بيرجع '' والمسوق يختار بنفسه
// هل الرابط صورة/فيديو من سيرفرات جوميا؟ (https بس، من غير بورت ولا بيانات دخول)
// تنظيف نص عام: من غير حروف تحكم، مسافات مرتبة، وبطول أقصى
function jmi_clean_text($s, $max = 2000) {
    $s = mb_scrub((string)$s, 'UTF-8');
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    $s = (string)preg_replace('/[ \t]+/', ' ', $s);
    $s = (string)preg_replace('/ *\n */', "\n", $s);
    $s = (string)preg_replace('/\n{3,}/', "\n\n", $s);
    return mb_substr(trim($s), 0, $max);
}

// تحويل جزء HTML لنص (بيشيل السكريبت والستايل والتعليقات والوسوم والـ entities والعلامات الاتجاهية المخفية)
function jmi_html_text($fragment) {
    $t = mb_scrub((string)$fragment, 'UTF-8');
    $t = (string)preg_replace('~<!--.*?-->~s', ' ', $t);
    $t = (string)preg_replace('~<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>~is', ' ', $t);
    $t = (string)preg_replace('~<br\s*/?>~i', "\n", $t);
    $t = (string)preg_replace('~</(?:p|div|li|h[1-6]|tr|ul|ol|table|section)\s*>~i', "\n", $t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string)preg_replace('~[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]~u', '', $t);
    $t = str_replace("\xC2\xA0", ' ', $t);
    return jmi_clean_text($t, 20000);
}

// تنظيف المواصفات (مصفوفة أو JSON من الفورم)
function jmi_clean_specs($in) {
    if (is_string($in)) $in = json_decode($in, true);
    if (!is_array($in)) return [];
    $out = []; $seen = [];
    foreach ($in as $row) {
        if (!is_array($row)) continue;
        $k = trim(str_replace("\n", ' ', jmi_clean_text((string)($row['k'] ?? ''), 200)));
        $v = trim(str_replace("\n", ' ', jmi_clean_text((string)($row['v'] ?? ''), 400)));
        if ($k === '' || $v === '') continue;
        $kl = mb_strtolower($k);
        if (isset($seen[$kl])) continue;
        $seen[$kl] = true;
        $out[] = ['k' => mb_substr($k, 0, 80), 'v' => mb_substr($v, 0, 300)];
        if (count($out) >= 60) break;
    }
    return $out;
}

// تنظيف قايمة صور جوميا: من غير تكرار، و60 صورة كحد أقصى
function jmi_clean_images($in) {
    if (is_string($in)) $in = json_decode($in, true);
    if (!is_array($in)) return [];
    $out = []; $seen = [];
    foreach ($in as $u) {
        if (!is_string($u)) continue;
        $u = trim($u);
        if (!jmi_media_url_ok($u)) continue;
        $key = jmi_media_key($u);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $u;
        if (count($out) >= 60) break;
    }
    return $out;
}

// مفتاح الصورة = رقم الصورة في جوميا (من غير مقاس الـ CDN)
function jmi_media_key($u) {
    $base  = basename((string)parse_url((string)$u, PHP_URL_PATH));
    $parts = explode('.', $base);
    return (string)($parts[0] !== '' ? $parts[0] : $base);
}

// تنظيف رابط فيديو واحد (mp4 من سيرفرات جوميا بس)
function jmi_clean_video($in) {
    $u = is_string($in) ? trim($in) : '';
    if ($u === '') return '';
    $path = (string)parse_url($u, PHP_URL_PATH);
    if (!preg_match('~\.mp4$~i', $path)) return '';
    return jmi_media_url_ok($u) ? $u : '';
}

// تنظيف قايمة الفيديوهات: JSON أو مصفوفة (أو رابط واحد من النسخة القديمة). mp4 من جوميا بس، من غير تكرار، و10 فيديوهات كحد أقصى
function jmi_clean_videos($in) {
    if (is_string($in)) {
        $in = trim($in);
        if ($in === '') return [];
        $dec = ($in[0] === '[') ? json_decode($in, true) : null;
        $in  = is_array($dec) ? $dec : [$in];
    }
    if (!is_array($in)) return [];
    $out = []; $seen = [];
    foreach ($in as $u) {
        $u = jmi_clean_video($u);
        if ($u === '' || isset($seen[$u])) continue;
        $seen[$u] = true;
        $out[] = $u;
        if (count($out) >= 10) break;
    }
    return $out;
}

// قايمة الوسائط اللي تتحمل من بيانات محفوظة: الصور من images (أو img)، والفيديوهات من videos (أو video في الروابط القديمة)
function jmi_media_list($d, $kind) {
    if (!is_array($d)) return [];
    if ($kind === 'vid') {
        $vids = jmi_clean_videos($d['videos'] ?? []);
        if (!$vids && !empty($d['video'])) $vids = jmi_clean_videos((string)$d['video']);
        return $vids;
    }
    $imgs = jmi_clean_images($d['images'] ?? []);
    if (!$imgs && !empty($d['img'])) $imgs = jmi_clean_images([(string)$d['img']]);
    return $imgs;
}

// اسم الملف اللي هيتحمل: رقم المنتج + رقم الصورة (أو الفيديو) من غير أي حروف غريبة
function jmi_dl_name($pid, $kind, $idx, $url) {
    $a = (string)preg_replace('/[^A-Za-z0-9]/', '', (string)$pid);
    if ($a === '') $a = 'product';
    if ($kind === 'vid') return $a . ((int)$idx > 0 ? '-video-' . ((int)$idx + 1) : '-video') . '.mp4';
    $ext = strtolower(pathinfo((string)parse_url((string)$url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
    return $a . '-' . ((int)$idx + 1) . '.' . $ext;
}

// بث الصورة/الفيديو للمتصفح كملف تحميل (السيرفر بيجيبه من جوميا، عشان المتصفح مش بيحمّل من روابط خارجية مباشرة)
// الرابط لازم يعدي jmi_media_url_ok، ومفيش تحويلات (redirects) ولا ملفات تانية
function jmi_stream_media($url, $filename) {
    if (!function_exists('curl_init') || !jmi_media_url_ok($url)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'الملف ده مش متاح للتحميل';
        return;
    }
    $ch = curl_init($url);
    if (!$ch) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'تعذر تحميل الملف الآن — جرّب تاني بعد شوية';
        return;
    }
    if (function_exists('set_time_limit')) @set_time_limit(180);
    $code = 0; $ctype = ''; $clen = 0; $sent = false;
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => 0,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 150,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$code, &$ctype, &$clen) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $code = (int)$m[1];
            } elseif (stripos($line, 'Content-Type:') === 0) {
                $ctype = trim(substr($line, 13));
            } elseif (stripos($line, 'Content-Length:') === 0) {
                $clen = (int)trim(substr($line, 15));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function ($c, $data) use (&$code, &$ctype, &$clen, &$sent, $filename) {
            if (!$sent) {
                if ($code !== 200) return 0;   // رد غلط من جوميا → نوقف ومنبعتش أي حاجة
                $sent = true;
                while (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: ' . ($ctype !== '' ? $ctype : 'application/octet-stream'));
                if ($clen > 0) header('Content-Length: ' . $clen);
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store');
            }
            echo $data;
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
    if (!$sent) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'تعذر تحميل الملف من جوميا الآن — جرّب تاني بعد شوية';
    }
}

// ============================================================================
//  16) هل النظام مفعّل؟
// ============================================================================
function jmi_enabled() { return site_setting('jmi_enabled', '1') !== '0'; }

// ============================================================================
//  🟧 تعديلات جوميا (مصر) فوق نسخة أفلييت أمازون — الدوال دي بتحل محل دوال أمازون
//  - سوق واحد: جوميا مصر (EGP) بدل متاجر أمازون كلها
//  - رقم المنتج (pid) بيتقرأ من لينك جوميا (-123456.html أو ?sku=)
//  - رابط التتبع (aff_url): الإدارة بتلصق لينك جوميا الأفلييت بتاعها عند الموافقة (بدل تاجات أمازون)
//  - الأقسام = أقسام الموقع الفيزيكال، ونسبة جوميا لكل قسم بتتدخل من الإعدادات
// ============================================================================

function jmi_marketplaces() {
    return ['eg' => ['domain' => 'www.jumia.com.eg', 'label' => 'جوميا مصر', 'currency' => 'EGP', 'flag' => '🇪🇬']];
}
function jmi_mkt($key) { $m = jmi_marketplaces(); return $m['eg']; }
function jmi_mkt_label($key) { return '🇪🇬 جوميا مصر'; }
function jmi_currency($key) { return 'EGP'; }

function jmi_is_short_link($url) { return false; }

function jmi_url_host($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    $p = parse_url((strpos($u, '://') === false ? 'https://' : '') . $u);
    return (is_array($p) && !empty($p['host'])) ? strtolower((string)$p['host']) : '';
}
function jmi_is_jumia_url($url) {
    $host = jmi_url_host($url);
    return $host !== '' && (bool)preg_match('~(^|\.)jumia\.com\.eg$~i', $host);
}
function jmi_detect_marketplace($url) { return jmi_is_jumia_url($url) ? 'eg' : ''; }

// رقم المنتج من لينك جوميا: الكود اللي قبل .html (مثال: /اسم-المنتج-123ABC.html) أو ?sku= / ?id=
function jmi_extract_pid($url) {
    $u = trim((string)$url);
    if ($u === '') return '';
    if (preg_match('/^[A-Za-z0-9]{4,}$/', $u)) return strtoupper($u);   // لصق الكود مباشرة
    $p = parse_url((strpos($u, '://') === false ? 'https://' : '') . $u);
    $path  = is_array($p) ? (string)($p['path'] ?? '') : '';
    $query = is_array($p) ? (string)($p['query'] ?? '') : '';
    if (preg_match('~-([A-Za-z0-9]{4,})\.html$~i', $path, $m)) return strtoupper($m[1]);
    if (preg_match('~(?:^|&)(?:sku|id|product_id)=([A-Za-z0-9]{4,})~i', $query, $m)) return strtoupper($m[1]);
    return '';
}

// اللينك النظيف للمنتج: من غير بارامترات (utm وغيره) — بيتحفظ كمرجع
function jmi_clean_product_url($url) {
    $p = parse_url((strpos((string)$url, '://') === false ? 'https://' : '') . trim((string)$url));
    if (!is_array($p) || empty($p['host'])) return '';
    return 'https://' . strtolower((string)$p['host']) . (string)($p['path'] ?? '');
}

// ============================================================================
//  الأقسام: أقسام الموقع الفيزيكال (من categories_by_mode) — نسبة جوميا لكل قسم من الإعدادات (افتراضي 0 لحد ما الإدارة تحددها)
// ============================================================================
function jmi_cat_slug($name) { return 'c_' . substr(md5((string)$name), 0, 8); }
function jmi_categories() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    // نسب جوميا الافتراضية (من جدول جوميا الرسمي). الإدارة تقدر تعدّلها من الإعدادات أو من كل منتج.
    // أقسام مالهاش صف في الجدول (العناية والتجميل، مجوهرات) بتفضل 0 لحد ما الإدارة تحددها.
    $defaults = [
        'ملابس رجالي' => 7, 'ملابس حريمي' => 7, 'أحذية' => 7, 'الرياضة واللياقة' => 7,
        'إكسسوارات' => 4, 'مستلزمات السيارات' => 4, 'إلكترونيات' => 4, 'أطفال وألعاب' => 4,
        'ساعات' => 4, 'شنط ومحافظ' => 4,
        'المنزل والمطبخ' => 2, 'عدد وأدوات' => 2,
    ];
    if (function_exists('categories_by_mode')) {
        foreach (array_keys((array)categories_by_mode('physical')) as $n) {
            $n = trim((string)$n);
            if ($n !== '') $cache[jmi_cat_slug($n)] = [$n, (float)($defaults[$n] ?? 0)];
        }
    }
    $cache['other'] = ['📦 باقي المنتجات (افتراضي)', 0];
    return $cache;
}
function jmi_cat_name_for_slug($slug) {
    $c = jmi_categories();
    return isset($c[$slug]) ? (string)$c[$slug][0] : '';
}

// ============================================================================
//  التتبع: مفيش تاجات لكل مسوق — الرابط المعتمد هو لينك جوميا اللي الإدارة لصقته
// ============================================================================
function jmi_clean_aff_url($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    if (strpos($u, '://') === false) $u = 'https://' . ltrim($u, '/');
    if (mb_strlen($u) > 800) return '';
    $p = parse_url($u);
    if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) return '';
    if (!in_array(strtolower((string)$p['scheme']), ['http', 'https'], true)) return '';
    return $u;
}
function jmi_aff_url_for($link) {
    return is_array($link) ? trim((string)($link['aff_url'] ?? '')) : '';
}
function jmi_out_url($link, $sub = '') {
    $aff = jmi_aff_url_for($link);
    if ($aff !== '') return $aff;
    $u = is_array($link) ? trim((string)($link['url_clean'] ?? '')) : '';
    return $u !== '' ? $u : 'https://www.jumia.com.eg/';
}

// ============================================================================
//  إنشاء رابط من لينك منتج جوميا (المسوق بيلصق اللينك)
// ============================================================================
function jmi_create_link($ref, $url, $opts = []) {
    $ref = trim((string)$ref);
    $mk  = function_exists('find_marketer_by_code') ? find_marketer_by_code($ref) : null;
    if ($ref === '' || !$mk) return ['ok' => false, 'error' => 'كود المسوق غير صحيح أو غير موجود'];
    if (($mk['status'] ?? 'approved') === 'suspended') return ['ok' => false, 'error' => 'حسابك موقوف — تواصل مع الإدارة'];

    $url = trim((string)$url);
    if ($url === '') return ['ok' => false, 'error' => 'اكتب أو الصق لينك المنتج من جوميا'];
    $mkt = jmi_detect_marketplace($url);
    if ($mkt === '') return ['ok' => false, 'error' => 'اللينك ده مش من جوميا مصر — استنسخ لينك صفحة المنتج من jumia.com.eg'];
    $pid = jmi_extract_pid($url);
    if ($pid === '') return ['ok' => false, 'error' => 'مش قادر أقرأ رقم المنتج من اللينك — افتح صفحة المنتج واستنسخ اللينك كامل'];
    $norm = jmi_normalize_input($url);
    if ($norm !== '') $url = $norm;

    $cats = jmi_categories();
    $cat  = trim((string)($opts['cat'] ?? ''));
    if (!isset($cats[$cat])) $cat = 'other';
    $price = (isset($opts['price']) && is_numeric($opts['price'])) ? round(max(0, (float)$opts['price']), 2) : 0;
    $title = mb_substr(trim((string)($opts['title'] ?? '')), 0, 180);
    $img   = mb_substr(trim((string)($opts['img'] ?? '')), 0, 400);
    $note  = mb_substr(trim((string)($opts['note'] ?? '')), 0, 300);
    $desc   = jmi_clean_text((string)($opts['desc'] ?? ''), 6000);
    $specs  = jmi_clean_specs($opts['specs'] ?? []);
    $images = jmi_clean_images($opts['images'] ?? []);
    $videos = jmi_clean_videos($opts['videos'] ?? []);

    // نفس المسوق + نفس المنتج → مش بنعمل طلب مكرر
    foreach (jmi_links_for($ref) as $old_id => $old) {
        if ((string)($old['pid'] ?? '') !== $pid || (string)($old['mkt'] ?? '') !== $mkt) continue;
        $old_st = (string)($old['status'] ?? 'approved');
        if ($old_st === 'approved' || $old_st === 'pending') {
            return ['ok' => true, 'id' => $old_id, 'link' => $old, 'existing' => true, 'status' => $old_st];
        }
        jmi_link_save($old_id, [
            'url_orig' => mb_substr($url, 0, 500),
            'url_clean' => jmi_clean_product_url($url),
            'title'    => $title,
            'img'      => $img !== '' ? $img : (string)($old['img'] ?? ''),
            'cat'      => $cat,
            'price'    => $price,
            'note'     => $note,
            'desc'     => $desc !== '' ? $desc : (string)($old['desc'] ?? ''),
            'specs'    => $specs ? $specs : (is_array($old['specs'] ?? null) ? $old['specs'] : []),
            'images'   => $images ? $images : (is_array($old['images'] ?? null) ? $old['images'] : []),
            'videos'   => $videos ? $videos : jmi_media_list($old, 'vid'),
            'status'   => 'pending',
        ]);
        jmi_req_create('approval', $ref, $old_id, ['pid' => $pid, 'title' => $title, 'cat' => $cat, 'price' => $price, 'note' => $note]);
        return ['ok' => true, 'id' => $old_id, 'link' => jmi_link($old_id) ?: $old, 'existing' => false, 'status' => 'pending', 'resubmitted' => true];
    }

    $id = 'jm' . substr(md5($ref . '|' . $pid . '|' . $mkt . '|' . microtime(true) . '|' . mt_rand()), 0, 10);
    $link = [
        'id'        => $id,
        'ref'       => $ref,
        'email'     => (string)($mk['email'] ?? ''),
        'mkt'       => $mkt,
        'pid'       => $pid,
        'currency'  => 'EGP',
        'url_orig'  => mb_substr($url, 0, 500),
        'url_clean' => jmi_clean_product_url($url),
        'aff_url'   => '',                    // لينك التتبع بتاع جوميا — الإدارة بتلصقه عند الموافقة
        'no_aff'    => 1,
        'title'     => $title,
        'img'       => $img,
        'cat'       => $cat,
        'rate'      => jmi_rate($cat),
        'price'     => $price,                // سعر مبدئي من المسوق — الإدارة بتحدد السعر النهائي عند الموافقة
        'note'      => $note,
        'desc'      => $desc,
        'specs'     => $specs,
        'images'    => $images,
        'videos'    => $videos,
        'status'    => 'pending',             // ⏳ مش بيظهر للمسوق أي رابط أو ربح لحد موافقة الإدارة
        'clicks'    => 0,
        'uniq'      => 0,
        'orders'    => 0,
        'qty'       => 0,
        'gross'     => 0,
        'site_earn' => 0,
        'mk_earn'   => 0,
        'at'        => date('c'),
        'updated'   => date('c'),
    ];
    jmi_link_save($id, $link);
    jmi_req_create('approval', $ref, $id, ['pid' => $pid, 'title' => $title, 'cat' => $cat, 'price' => $price, 'note' => $note]);
    return ['ok' => true, 'id' => $id, 'link' => $link, 'existing' => false, 'status' => 'pending'];
}

// ============================================================================
//  موافقة الإدارة على طلب المنتج: سعر + نسبة المسوق + القسم + لينك التتبع (اختياري وقت الموافقة)
// ============================================================================
function jmi_approve_request($rid, $in = []) {
    $reqs = jmi_reqs_all();
    if (!isset($reqs[$rid]) || !is_array($reqs[$rid])) return ['ok' => false, 'error' => 'الطلب ده مش موجود'];
    $r = $reqs[$rid];
    if ((string)($r['status'] ?? '') !== 'pending') return ['ok' => false, 'error' => 'الطلب ده اتعالج قبل كده'];
    $lid = (string)($r['link_id'] ?? '');
    $l   = $lid !== '' ? jmi_link($lid) : null;
    if (!$l) {
        jmi_req_update($rid, ['status' => 'rejected', 'admin_note' => 'المسوق سحب الطلب قبل المراجعة']);
        return ['ok' => false, 'error' => 'المسوق سحب الطلب ده قبل المراجعة — اتقفل تلقائياً'];
    }
    $price = (isset($in['price']) && is_numeric($in['price'])) ? round(max(0, (float)$in['price']), 2) : 0;
    $pct   = (isset($in['mk_pct']) && is_numeric($in['mk_pct'])) ? max(0, min(100, (float)$in['mk_pct'])) : null;
    if ($price <= 0) return ['ok' => false, 'error' => 'لازم تحدد سعر المنتج اللي هيتباع بيه'];
    if ($pct === null || $pct <= 0) return ['ok' => false, 'error' => 'لازم تحدد نسبة عمولة المسوق (أكبر من صفر)'];
    $raw = trim((string)($in['aff_url'] ?? ''));
    $aff = jmi_clean_aff_url($raw);
    if ($raw !== '' && $aff === '') return ['ok' => false, 'error' => 'لينك التتبع اللي لصقته مش صحيح'];

    $cats = jmi_categories();
    $cat  = (string)($in['cat'] ?? ($l['cat'] ?? 'other'));
    if (!isset($cats[$cat])) $cat = isset($cats[(string)($l['cat'] ?? '')]) ? (string)$l['cat'] : 'other';
    $ref  = (string)($l['ref'] ?? '');
    $mkt  = (string)($l['mkt'] ?? 'eg');
    $mk   = find_marketer_by_code($ref);

    $upd = [
        'status'      => 'approved',
        'price'       => $price,
        'mk_pct'      => $pct,
        'cat'         => $cat,
        'rate'        => jmi_rate($cat),
        'aff_url'     => $aff !== '' ? $aff : (string)($l['aff_url'] ?? ''),
        'approved_at' => date('c'),
    ];
    $upd['no_aff'] = (trim((string)$upd['aff_url']) === '') ? 1 : 0;
    jmi_link_save($lid, $upd);
    $note = mb_substr(trim((string)($in['admin_note'] ?? '')), 0, 300);
    jmi_req_update($rid, ['status' => 'approved', 'admin_note' => $note, 'approved_at' => date('c')]);

    $after = jmi_link($lid) ?: $l;
    $ps = jmi_per_sale($after);
    if ($mk && !empty($mk['email'])) {
        notify_user((string)$mk['email'], '✅ اتوافق على منتجك: ' . ($after['title'] !== '' ? $after['title'] : (string)$after['pid'])
            . ' — رابط التسويق بقى في «الروابط المعتمدة»'
            . ($ps['ok'] ? ' — هتاخد ' . number_format($ps['amount'], 2) . ' ' . jmi_cur_label((string)$ps['currency']) . ' على كل بيعة' : ''),
            'dashboard/jumia.php?tab=links');
    }
    log_audit('jumia_req_approve', $rid, ['link' => $lid, 'ref' => $ref, 'price' => $price, 'mk_pct' => $pct, 'aff' => $aff !== '']);
    return ['ok' => true, 'no_aff' => !empty($upd['no_aff']), 'per_sale' => $ps];
}

// تعديل الإدارة لرابط: السعر + نسبة المسوق + القسم + لينك التتبع + الحالة
function jmi_admin_link_save($lid, $in = []) {
    $l = jmi_link($lid);
    if (!$l) return ['ok' => false, 'error' => 'الرابط ده مش موجود'];
    $cats = jmi_categories();
    $cat  = (string)($in['cat'] ?? ($l['cat'] ?? 'other'));
    if (!isset($cats[$cat])) $cat = isset($cats[(string)($l['cat'] ?? '')]) ? (string)$l['cat'] : 'other';
    $rate = (isset($in['rate']) && trim((string)$in['rate']) !== '' && is_numeric($in['rate'])) ? max(0, min(100, (float)$in['rate'])) : jmi_rate($cat);
    $price = (isset($in['price']) && trim((string)$in['price']) !== '' && is_numeric($in['price'])) ? round(max(0, (float)$in['price']), 2) : (float)($l['price'] ?? 0);
    $pct  = (isset($in['mk_pct']) && trim((string)$in['mk_pct']) !== '' && is_numeric($in['mk_pct'])) ? max(0, min(100, (float)$in['mk_pct'])) : jmi_mk_pct($l);
    $st   = (string)($in['status'] ?? ($l['status'] ?? 'approved'));
    if (!in_array($st, ['approved', 'pending', 'rejected'], true)) $st = (string)($l['status'] ?? 'approved');
    if ($st === 'approved' && ($price <= 0 || $pct === null || $pct <= 0)) {
        return ['ok' => false, 'error' => 'عشان الرابط يتعتمد لازم يكون ليه سعر ونسبة مسوق أكبر من صفر'];
    }
    $aff = (string)($l['aff_url'] ?? '');
    if (array_key_exists('aff_url', $in)) {
        $raw = trim((string)$in['aff_url']);
        $new = jmi_clean_aff_url($raw);
        if ($raw !== '' && $new === '') return ['ok' => false, 'error' => 'لينك التتبع اللي لصقته مش صحيح'];
        $aff = $new;
    }
    $ref = (string)($l['ref'] ?? '');
    $mk  = find_marketer_by_code($ref);

    $upd = ['cat' => $cat, 'rate' => $rate, 'price' => $price, 'mk_pct' => $pct, 'status' => $st,
            'aff_url' => $aff, 'no_aff' => ($aff === '') ? 1 : 0];
    if ($st === 'approved' && empty($l['approved_at'])) $upd['approved_at'] = date('c');
    jmi_link_save($lid, $upd);
    if ((string)($l['status'] ?? 'approved') !== $st && $mk && !empty($mk['email'])) {
        $msg = $st === 'approved' ? '✅ الرابط بتاعك اتفعّل — ظهر في «الروابط المعتمدة»'
             : ($st === 'rejected' ? '⛔ الإدارة وقفت الرابط ده' : '⏳ الرابط رجع لمراجعة الإدارة');
        notify_user((string)$mk['email'], $msg, 'dashboard/jumia.php?tab=links');
    }
    log_audit('jumia_link_edit', $lid, ['cat' => $cat, 'rate' => $rate, 'price' => $price, 'mk_pct' => $pct, 'status' => $st]);
    return ['ok' => true, 'no_aff' => ($aff === '')];
}

// ============================================================================
//  جلب بيانات المنتج من صفحة جوميا (JSON-LD ثم Open Graph) — لو جوميا رفضت الطلب من السيرفر، المسوق بيكتب يدوياً
// ============================================================================
function jmi_fetch_meta($url) {
    $GLOBALS['jmi_fetch_reason'] = '';
    $url = jmi_normalize_input($url);
    if ($url === '' || !jmi_is_jumia_url($url)) return [];
    if (!function_exists('curl_init')) return jmi_fetch_fail('', 0, '', false, $url);
    $ch = curl_init($url);
    if (!$ch) return jmi_fetch_fail(false, 0, 'curl_init فشل', true, $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: ar,en;q=0.8'],
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = (string)curl_error($ch);
    curl_close($ch);
    $meta = (is_string($html) && $html !== '') ? jmi_parse_product_html($html) : [];
    if (!empty($meta)) return $meta;
    return jmi_fetch_fail($html, $code, $cerr, false, $url);
}
// بيسجل سبب الفشل (للمسوق + سجل الإدارة + لوج السيرفر) ويرجّع []
function jmi_fetch_fail($html, $code, $cerr, $has_curl, $url) {
    $reason = wf_fetch_fail_reason($html, $code, $cerr, false, 'جوميا');
    $GLOBALS['jmi_fetch_reason'] = $reason;
    error_log('[jumia fetch] ' . $reason . ' | HTTP ' . (int)$code . ' | ' . mb_substr((string)$url, 0, 200));
    return [];
}

// قراءة meta (og:title / product:price:amount ...) من الـ HTML
function jmi_meta_content($html, $prop) {
    $q = preg_quote($prop, '~');
    if (preg_match('~<meta[^>]+(?:property|name)=["\']' . $q . '["\'][^>]*content=["\']([^"\']*)["\']~i', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    if (preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . $q . '["\']~i', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    return '';
}

// كل عقد JSON-LD: المنتج + مسار التصنيف (BreadcrumbList)
function jmi_ld_nodes($html) {
    $res = ['product' => [], 'crumbs' => []];
    if (!preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', (string)$html, $ms)) return $res;
    foreach ($ms[1] as $raw) {
        $j = json_decode(trim((string)$raw), true);
        if (!is_array($j)) continue;
        $list = isset($j['@graph']) && is_array($j['@graph']) ? $j['@graph'] : (isset($j[0]) ? $j : [$j]);
        foreach ($list as $n) {
            if (!is_array($n)) continue;
            $t = $n['@type'] ?? '';
            $types = is_array($t) ? $t : [$t];
            if (in_array('Product', $types, true) && !$res['product']) $res['product'] = $n;
            if (in_array('BreadcrumbList', $types, true) && !$res['crumbs']) {
                foreach ((array)($n['itemListElement'] ?? []) as $it) {
                    if (is_array($it) && isset($it['name'])) $res['crumbs'][] = (string)$it['name'];
                }
            }
        }
    }
    return $res;
}

// تحليل HTML صفحة المنتج → مصفوفة فيها الحقول اللي اتقرت بس (دالة نقية — من غير اتصال شبكة)
function jmi_parse_product_html($html) {
    $html = (string)$html;
    $out  = [];
    if ($html === '') return $out;
    $ld   = jmi_ld_nodes($html);
    $prod = $ld['product'];

    $title = trim((string)($prod['name'] ?? ''));
    if ($title === '') $title = jmi_meta_content($html, 'og:title');
    $title = trim(str_replace(["\r", "\n"], ' ', $title));
    if ($title !== '') $out['title'] = mb_substr($title, 0, 180);

    $off = $prod['offers'] ?? null;
    if (is_array($off) && isset($off[0]) && is_array($off[0])) $off = $off[0];
    $p = 0.0;
    if (is_array($off)) $p = (float)preg_replace('/[^0-9.]/', '', (string)($off['price'] ?? ($off['lowPrice'] ?? '')));
    if ($p <= 0) $p = (float)preg_replace('/[^0-9.]/', '', jmi_meta_content($html, 'product:price:amount'));
    if ($p > 0) $out['price'] = round($p, 2);

    // الصور: JSON-LD ثم og:image (مكررة بتتشال في التنظيف)
    $imgs = [];
    $ldimg = $prod['image'] ?? [];
    foreach ((array)(is_array($ldimg) && isset($ldimg['url']) ? [$ldimg] : $ldimg) as $im) {
        if (is_string($im)) $imgs[] = $im;
        elseif (is_array($im) && isset($im['url'])) $imgs[] = (string)$im['url'];
    }
    if (preg_match_all('~<meta[^>]+property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']~i', $html, $mm)) {
        foreach ($mm[1] as $u) $imgs[] = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
    }
    $images = jmi_clean_images(array_map('stripslashes', $imgs));
    if ($images) { $out['images'] = $images; $out['img'] = $images[0]; }

    $desc = trim((string)($prod['description'] ?? ''));
    if ($desc === '') $desc = jmi_meta_content($html, 'og:description');
    if ($desc === '') $desc = jmi_meta_content($html, 'description');
    $desc = jmi_clean_text($desc, 6000);
    if ($desc !== '') $out['desc'] = $desc;

    $specs = [];
    foreach ((array)($prod['additionalProperty'] ?? []) as $pp) {
        if (is_array($pp) && isset($pp['name'], $pp['value'])) $specs[] = ['k' => (string)$pp['name'], 'v' => (string)$pp['value']];
    }
    $specs = jmi_clean_specs($specs);
    if ($specs) $out['specs'] = $specs;

    $vids = [];
    if (is_array($prod['video'] ?? null)) {
        $vn = $prod['video'];
        $vids[] = (string)($vn['contentUrl'] ?? ($vn['embedUrl'] ?? ''));
    }
    if (preg_match_all('~<source[^>]+src=["\']([^"\']+\.mp4[^"\']*)["\']~i', $html, $vm)) {
        foreach ($vm[1] as $v) $vids[] = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
    }
    $videos = jmi_clean_videos($vids);
    if ($videos) $out['videos'] = $videos;

    $cat = jmi_guess_category($ld['crumbs']);
    if ($cat !== '') $out['cat'] = $cat;
    return $out;
}

// تحويل مسار التصنيف لقسم من أقسام الموقع (بالكلمات المفتاحية)
function jmi_guess_category($crumbs) {
    if (!is_array($crumbs) || !$crumbs) return '';
    $hay = mb_strtolower(implode(' | ', array_map('strval', $crumbs)));
    $rules = [
        'أحذية'               => '/shoe|footwear|sneaker|sandal|boot|حذاء|أحذية|جزمة/u',
        'شنط ومحافظ'          => '/bag|backpack|wallet|purse|handbag|شنطة|شنط|محفظة|محافظ/u',
        'ساعات'               => '/watch|ساعة|ساعات/u',
        'ملابس حريمي'         => '/women|woman|ladies|lady|girls|حريمي|نسائي|بناتي/u',
        'ملابس رجالي'         => '/\bmen\b|men\'s|man\b|رجالي|رجالى/u',
        'إكسسوارات'           => '/accessor|jewel|jewelry|إكسسوار|اكسسوار|مجوهرات/u',
        'العناية والتجميل'    => '/beauty|health|perfume|fragrance|skin|hair|makeup|عطر|تجميل|عناية/u',
        'المنزل والمطبخ'      => '/home|kitchen|appliance|furniture|منزل|مطبخ/u',
        'إلكترونيات'          => '/electronic|phone|mobile|laptop|tablet|tv|audio|camera|computer|إلكترون|موبايل|جوال/u',
        'أطفال وألعاب'        => '/kid|baby|toy|child|أطفال|العاب|ألعاب/u',
        'الرياضة واللياقة'    => '/sport|fitness|gym|رياض/u',
        'مستلزمات السيارات'   => '/automotive|car |vehicle|سيارات|سيارة/u',
        'عدد وأدوات'          => '/tool|عدد|أدوات/u',
    ];
    foreach ($rules as $name => $re) {
        if (preg_match($re, $hay)) {
            $slug = jmi_cat_slug($name);
            if (jmi_cat_name_for_slug($slug) !== '') return $slug;
        }
    }
    return '';
}

// الصور والفيديوهات: من hosts جوميا بس (https، من غير بورت أو بيانات دخول)
function jmi_media_url_ok($url) {
    $url = trim((string)$url);
    if ($url === '' || strlen($url) > 600) return false;
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
    if (isset($p['port']) || isset($p['user']) || isset($p['pass'])) return false;
    $h = strtolower((string)($p['host'] ?? ''));
    foreach (['jumia.is', 'jumia.com.eg', 'jumia.com'] as $suf) {
        if ($h === $suf || substr($h, -(strlen($suf) + 1)) === '.' . $suf) return true;
    }
    return false;
}
