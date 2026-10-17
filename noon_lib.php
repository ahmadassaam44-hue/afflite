<?php
// ============================================================================
//  🟡 أفلييت نون (كوبون خصم بتاع المسوق)
//  - المسوق بيبعت الكود بتاعه من نون (كوبون) بدل اللينك → الإدارة توافق عليه وتحدد نسبته
//  - الكود مفيهوش لينك، فمفيش نقرات. الاستخدامات = عدد البيعات بالكود (من غير الملغي)
//  - الإدارة بتسجل كل بيعة بالكود يدوي: رقم الأوردر + قيمة البيع + عمولة نون
//  - عمولة المسوق = عمولة نون × نسبته → بتتسجل في رصيده المعلق (commission_credit_pending)
//    وبتتأكد (commission_release) أو تتلغى (commission_reverse) من الإدارة
//  - التظلم على النسبة زي أمازون: المسوق بيتظلم بسبب، والإدارة بتحدد النسبة الجديدة أو بترفض
//  - إيقاف الكود: من الإدارة أو من المسوق. الكود اللي اتوقف بيفضل محجوز (مش بيتعمل لمسوق تاني)
//  ملفات البيانات: data/noon_codes.json (الأكواد + التظلمات) و data/noon_sales.json (البيعات)
// ============================================================================

// القراءة بدون ذاكرة مؤقتة: الصفحة بتكتب وتقرأ في نفس الطلب أحياناً
function noon_codes_all() { $r = jload('noon_codes.json', [], false); return is_array($r) ? array_values($r) : []; }
function noon_sales_all() { $r = jload('noon_sales.json', [], false); return is_array($r) ? array_values($r) : []; }

// نسبة المسوق الافتراضية (من الإعدادات، والافتراضي 50%). كل كود معتمد ممكن يتغير نسبته لوحده.
function noon_default_share() {
    $v = site_setting('noon_mk_share', '');
    return ($v !== '' && is_numeric($v)) ? max(0, min(100, (float)$v)) : 50.0;
}

// الكود: حروف إنجليزية وأرقام و _ - . بين 3 و 40 حرف، بحروف كبيرة
function noon_norm_code($raw) {
    $c = strtoupper(trim((string)$raw));
    return preg_match('/^[A-Z0-9_.\\-]{3,40}$/', $c) ? $c : '';
}

// أحدث كود للمسوق (أي حالة)
function noon_code_for($email) {
    $found = null;
    foreach (noon_codes_all() as $c) {
        if (strcasecmp((string)($c['email'] ?? ''), (string)$email) !== 0) continue;
        if ($found === null || (string)($c['requested_at'] ?? '') >= (string)($found['requested_at'] ?? '')) $found = $c;
    }
    return $found;
}

// كل أكواد المسوق (الأحدث الأول)، بتشمل المعلق والمعتمد والموقوف والمرفوض
function noon_codes_for($email) {
    $rows = array_values(array_filter(noon_codes_all(), function ($c) use ($email) {
        return strcasecmp((string)($c['email'] ?? ''), (string)$email) === 0;
    }));
    usort($rows, function ($a, $b) { return strcmp((string)($b['requested_at'] ?? ''), (string)($a['requested_at'] ?? '')); });
    return $rows;
}

// الكود النشط للمسوق (قيد المراجعة أو معتمد)
function noon_code_active_for($email) {
    foreach (noon_codes_all() as $c) {
        if (strcasecmp((string)($c['email'] ?? ''), (string)$email) === 0 && in_array($c['status'] ?? '', ['pending', 'approved'], true)) return $c;
    }
    return null;
}

// المسوق بيبعت كود نون بتاعه للإدارة
function noon_code_request($email, $raw_code, $note = '') {
    $email = strtolower(trim((string)$email));
    $mk = $email !== '' ? find_user($email) : null;
    if (!$mk) return ['ok' => false, 'error' => 'الحساب مش موجود'];
    if (($mk['status'] ?? '') === 'suspended') return ['ok' => false, 'error' => 'حسابك موقوف حالياً'];
    $code = noon_norm_code($raw_code);
    if ($code === '') return ['ok' => false, 'error' => 'الكود لازم يكون من 3 لـ 40 حرف أو رقم إنجليزي من غير مسافات'];
    $rec = [
        'id'           => 'nc' . bin2hex(random_bytes(4)),
        'email'        => $email,
        'name'         => (string)($mk['name'] ?? ''),
        'code'         => $code,
        'status'       => 'pending',
        'share'        => null,
        'note'         => mb_substr(trim((string)$note), 0, 300),
        'requested_at' => date('c'),
        'decided_at'   => '',
        'decided_by'   => '',
        'reject_note'  => '',
        'appeals'      => [],
    ];
    $err = '';
    $ok = jupdate('noon_codes.json', function (&$d) use ($rec, $code, $email, &$err) {
        foreach ($d as $c) {
            $cs = (string)($c['status'] ?? '');
            $live = in_array($cs, ['pending', 'approved'], true);
            if ($live && strcasecmp((string)($c['email'] ?? ''), $email) === 0) { $err = 'عندك كود قيد المراجعة أو معتمد بالفعل'; return false; }
            if (strcasecmp((string)($c['code'] ?? ''), $code) !== 0) continue;
            if ($live) { $err = 'الكود ده مستخدم بالفعل'; return false; }
            if (in_array($cs, ['stopped', 'deleted'], true) && strcasecmp((string)($c['email'] ?? ''), $email) !== 0) { $err = 'الكود ده اتسجل قبل كده لمسوق تاني'; return false; }
        }
        $d[] = $rec;
        return true;
    });
    if ($ok !== true) return ['ok' => false, 'error' => $err !== '' ? $err : 'ما اتسجلش الكود، جرب تاني'];
    return ['ok' => true, 'rec' => $rec];
}

// الإدارة بتوافق على الكود (مع نسبة المسوق) أو بتوقفه/بترفضه
function noon_code_decide($id, $approve, $share = null, $note = '', $by = '') {
    $res = ['ok' => false, 'error' => 'الكود مش موجود'];
    jupdate('noon_codes.json', function (&$d) use ($id, $approve, $share, $note, $by, &$res) {
        foreach ($d as $i => $c) {
            if ((string)($c['id'] ?? '') !== (string)$id) continue;
            $cs = (string)($c['status'] ?? '');
            if ($approve) {
                if ($cs !== 'pending') { $res = ['ok' => false, 'error' => 'الكود ده اتعالج قبل كده']; return false; }
                foreach ($d as $o) {
                    if ((string)($o['id'] ?? '') === (string)$c['id'] || strcasecmp((string)($o['code'] ?? ''), (string)$c['code']) !== 0) continue;
                    if (($o['status'] ?? '') === 'approved') { $res = ['ok' => false, 'error' => 'الكود ده معتمد لمسوق تاني']; return false; }
                    if (in_array((string)($o['status'] ?? ''), ['stopped', 'deleted'], true) && strcasecmp((string)($o['email'] ?? ''), (string)($c['email'] ?? '')) !== 0) {
                        $res = ['ok' => false, 'error' => 'الكود ده اتوقف لمسوق تاني قبل كده']; return false;
                    }
                }
                $d[$i]['status'] = 'approved';
                $d[$i]['share']  = ($share !== null && $share !== '' && is_numeric($share)) ? max(0, min(100, (float)$share)) : noon_default_share();
                $d[$i]['reject_note'] = '';
            } else {
                // الكود المعتمد بيتوقف (وبيفضل محجوز للمسوق)، والطلب قيد المراجعة بيترفض
                $d[$i]['status'] = $cs === 'approved' ? 'stopped' : 'rejected';
                $d[$i]['reject_note'] = mb_substr(trim((string)$note), 0, 300);
                noon_close_appeals($d[$i], 'اتقفل لأن الكود اتوقف', (string)$by);
            }
            $d[$i]['decided_at'] = date('c');
            $d[$i]['decided_by'] = (string)$by;
            $res = ['ok' => true, 'rec' => $d[$i]];
            return true;
        }
        return false;
    });
    return $res;
}

// تغيير نسبة المسوق على كود معتمد (بيأثر على البيعات الجديدة بس)
function noon_code_set_share($id, $share) {
    if (!is_numeric($share)) return ['ok' => false, 'error' => 'النسبة لازم تكون رقم'];
    $share = max(0, min(100, (float)$share));
    $res = ['ok' => false, 'error' => 'الكود مش معتمد'];
    jupdate('noon_codes.json', function (&$d) use ($id, $share, &$res) {
        foreach ($d as $i => $c) {
            if ((string)($c['id'] ?? '') !== (string)$id) continue;
            if (($c['status'] ?? '') !== 'approved') return false;
            $d[$i]['share'] = $share;
            $res = ['ok' => true];
            return true;
        }
        return false;
    });
    return $res;
}

// المسوق بيوقف كوده بنفسه (زي حذف الرابط في أمازون): المعتمد بيتوقف، والطلب المعلق بيتسحب
function noon_code_stop_by_marketer($email, $id) {
    $email = strtolower(trim((string)$email));
    $res = ['ok' => false, 'error' => 'الكود مش موجود أو مش بتاعك'];
    jupdate('noon_codes.json', function (&$d) use ($email, $id, &$res) {
        foreach ($d as $i => $c) {
            if ((string)($c['id'] ?? '') !== (string)$id || strcasecmp((string)($c['email'] ?? ''), $email) !== 0) continue;
            $cs = (string)($c['status'] ?? '');
            if (!in_array($cs, ['pending', 'approved'], true)) { $res = ['ok' => false, 'error' => 'الكود ده مش نشط']; return false; }
            $d[$i]['status'] = $cs === 'approved' ? 'stopped' : 'rejected';
            $d[$i]['reject_note'] = $cs === 'approved' ? 'المسوق أوقفه بنفسه' : 'المسوق سحب الطلب';
            $d[$i]['decided_at'] = date('c');
            $d[$i]['decided_by'] = $email;
            noon_close_appeals($d[$i], 'اتقفل لأن الكود اتوقف', $email);
            $res = ['ok' => true, 'rec' => $d[$i]];
            return true;
        }
        return false;
    });
    return $res;
}

// الإدارة بتحذف كود نون (حذف ناعم: الحالة deleted، والمسوق بيتبلغ، والبيعات المسجلة بيه بتفضل للتاريخ)
function noon_code_admin_delete($id, $note, $by) {
    $res = ['ok' => false, 'error' => 'الكود مش موجود'];
    $note = mb_substr(trim((string)$note), 0, 300);
    jupdate('noon_codes.json', function (&$d) use ($id, $note, $by, &$res) {
        foreach ($d as $i => $c) {
            if ((string)($c['id'] ?? '') !== (string)$id) continue;
            if (($c['status'] ?? '') === 'deleted') { $res = ['ok' => false, 'error' => 'الكود ده اتحذف قبل كده']; return false; }
            $d[$i]['status'] = 'deleted';
            $d[$i]['reject_note'] = $note !== '' ? $note : 'الإدارة حذفت الكود';
            $d[$i]['decided_at'] = date('c');
            $d[$i]['decided_by'] = (string)$by;
            noon_close_appeals($d[$i], 'اتقفل لأن الكود اتحذف', (string)$by);
            $res = ['ok' => true, 'rec' => $d[$i]];
            return true;
        }
        return false;
    });
    if (!empty($res['ok'])) {
        $r = $res['rec'];
        notify_user((string)$r['email'], '🗑️ الإدارة حذفت كود نون بتاعك: ' . $r['code'] . ' — ' . $r['reject_note'], 'dashboard/noon.php');
    }
    return $res;
}

// قفل التظلمات المعلقة على الكود (لما الكود يتوقف)
function noon_close_appeals(&$rec, $why, $by = '') {
    foreach ((array)($rec['appeals'] ?? []) as $k => $ap) {
        if (($ap['status'] ?? '') !== 'pending') continue;
        $rec['appeals'][$k]['status'] = 'rejected';
        $rec['appeals'][$k]['admin_note'] = $why;
        $rec['appeals'][$k]['decided_at'] = date('c');
        $rec['appeals'][$k]['decided_by'] = $by;
    }
}

// التظلم على النسبة (زي أمازون): المسوق بيبعت السبب بس، والإدارة هي اللي بتحدد النسبة الجديدة
function noon_appeal_create($email, $code_id, $reason) {
    $email = strtolower(trim((string)$email));
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 5) return ['ok' => false, 'error' => 'اكتب سبب التظلم (5 حروف على الأقل)'];
    $res = ['ok' => false, 'error' => 'الكود مش موجود أو مش بتاعك'];
    jupdate('noon_codes.json', function (&$d) use ($email, $code_id, $reason, &$res) {
        foreach ($d as $i => $c) {
            if ((string)($c['id'] ?? '') !== (string)$code_id || strcasecmp((string)($c['email'] ?? ''), $email) !== 0) continue;
            if (($c['status'] ?? '') !== 'approved') { $res = ['ok' => false, 'error' => 'التظلم بيتقدم على الكود المعتمد بس']; return false; }
            $cur = is_numeric($c['share'] ?? null) ? (float)$c['share'] : noon_default_share();
            if ($cur >= 100) { $res = ['ok' => false, 'error' => 'وصلت للحد الأقصى (100%)']; return false; }
            foreach ((array)($c['appeals'] ?? []) as $ap) {
                if (($ap['status'] ?? '') === 'pending') { $res = ['ok' => false, 'error' => 'عندك تظلم بانتظار الإدارة على الكود ده بالفعل']; return false; }
            }
            $ap = [
                'id'         => 'na' . bin2hex(random_bytes(4)),
                'at'         => date('c'),
                'note'       => mb_substr($reason, 0, 300),
                'old_share'  => $cur,
                'new_share'  => null,
                'status'     => 'pending',
                'decided_at' => '',
                'decided_by' => '',
                'admin_note' => '',
            ];
            $d[$i]['appeals'] = array_merge((array)($c['appeals'] ?? []), [$ap]);
            $res = ['ok' => true, 'id' => $ap['id'], 'code' => (string)$c['code']];
            return true;
        }
        return false;
    });
    if (!empty($res['ok'])) {
        notify('admin', '📈 تظلم على نسبة كود نون ' . $res['code'] . ' (' . $email . ')', 'dashboard/admin.php?tab=noon');
    }
    return $res;
}

// الإدارة بتوافق على التظلم (وبتحدد النسبة الجديدة) أو بترفضه
function noon_appeal_decide($code_id, $appeal_id, $approve, $new_share = '', $note = '', $by = '') {
    $res = ['ok' => false, 'error' => 'التظلم ده مش موجود'];
    jupdate('noon_codes.json', function (&$d) use ($code_id, $appeal_id, $approve, $new_share, $note, $by, &$res) {
        foreach ($d as $i => $c) {
            if ((string)($c['id'] ?? '') !== (string)$code_id) continue;
            foreach ((array)($c['appeals'] ?? []) as $k => $ap) {
                if ((string)($ap['id'] ?? '') !== (string)$appeal_id) continue;
                if (($ap['status'] ?? '') !== 'pending') { $res = ['ok' => false, 'error' => 'التظلم ده اتعالج قبل كده']; return false; }
                $now = date('c');
                if (!$approve) {
                    $d[$i]['appeals'][$k]['status'] = 'rejected';
                    $d[$i]['appeals'][$k]['admin_note'] = mb_substr(trim((string)$note), 0, 300);
                    $res = ['ok' => true];
                } elseif (($c['status'] ?? '') !== 'approved') {
                    // الكود مبقاش معتمد: التظلم بيتقفل تلقائياً
                    $d[$i]['appeals'][$k]['status'] = 'rejected';
                    $d[$i]['appeals'][$k]['admin_note'] = 'الكود مبقاش معتمد';
                    $res = ['ok' => false, 'error' => 'الكود مبقاش معتمد — التظلم اتقفل تلقائياً'];
                } elseif (!is_numeric($new_share)) {
                    $res = ['ok' => false, 'error' => 'النسبة الجديدة لازم تكون رقم'];
                    return false;
                } else {
                    $ns = max(0, min(100, (float)$new_share));
                    $d[$i]['share'] = $ns;
                    $d[$i]['appeals'][$k]['status'] = 'approved';
                    $d[$i]['appeals'][$k]['new_share'] = $ns;
                    $d[$i]['appeals'][$k]['admin_note'] = mb_substr(trim((string)$note), 0, 300);
                    $res = ['ok' => true];
                }
                $d[$i]['appeals'][$k]['decided_at'] = $now;
                $d[$i]['appeals'][$k]['decided_by'] = (string)$by;
                $res['rec'] = $d[$i];
                $res['appeal'] = $d[$i]['appeals'][$k];
                return true;
            }
            $res = ['ok' => false, 'error' => 'التظلم ده مش موجود'];
            return false;
        }
        return false;
    });
    return $res;
}

// كل التظلمات المعلقة (للإدارة)
function noon_appeals_pending() {
    $out = [];
    foreach (noon_codes_all() as $c) {
        foreach ((array)($c['appeals'] ?? []) as $ap) {
            if (($ap['status'] ?? '') === 'pending') $out[] = ['code' => $c, 'appeal' => $ap];
        }
    }
    usort($out, function ($a, $b) { return strcmp((string)($a['appeal']['at'] ?? ''), (string)($b['appeal']['at'] ?? '')); });
    return $out;
}

// تأكيد بيعة: العمولة بتنتقل من المعلق للمتاح
function noon_sale_confirm($sid) {
    $rec = null;
    jupdate('noon_sales.json', function (&$d) use ($sid, &$rec) {
        foreach ($d as $i => $s) {
            if ((string)($s['id'] ?? '') !== (string)$sid) continue;
            if (($s['state'] ?? '') !== 'pending') return false;
            $d[$i]['state'] = 'confirmed';
            $d[$i]['confirmed_at'] = date('c');
            $rec = $d[$i];
            return true;
        }
        return false;
    });
    if (!$rec) return ['ok' => false, 'error' => 'البيعة مش موجودة أو متأكدة قبل كده'];
    if ((float)$rec['mk_egp'] > 0) {
        commission_release($rec['email'], (float)$rec['mk_egp'], null, 'تأكيد عمولة نون (كود ' . $rec['code'] . ')');
    }
    return ['ok' => true, 'rec' => $rec];
}

// تسجيل بيعة بالكود (يدوي من الإدارة): بتتضاف لرصيد المسوق المعلق
function noon_sale_add($code_id, $order_no, $sale, $noon_comm, $at_date = '', $by = '', $product_id = '', $qty = 1) {
    $order_no = trim((string)$order_no);
    if ($order_no === '' || mb_strlen($order_no) > 60) return ['ok' => false, 'error' => 'رقم الأوردر مطلوب (60 حرف كحد أقصى)'];
    $sale_v = round((float)$sale, 2);
    $comm_v = round((float)$noon_comm, 2);
    if ($sale_v < 0 || $comm_v < 0) return ['ok' => false, 'error' => 'قيمة البيع والعمولة لازم تكون صفر أو أكبر'];
    $cr = null;
    foreach (noon_codes_all() as $c) { if ((string)($c['id'] ?? '') === (string)$code_id) { $cr = $c; break; } }
    if (!$cr || ($cr['status'] ?? '') !== 'approved') return ['ok' => false, 'error' => 'الكود ده مش معتمد أو مش موجود'];
    $prod = null; $q = 1; $calc = null;
    if ((string)$product_id !== '') {
        // بيعة لمنتج معتمد: عمولة المسوق ثابتة على كل قطعة (من المنتج)، والكمية بتتضرب فيها
        foreach (noon_products_all() as $p) { if ((string)($p['id'] ?? '') === (string)$product_id) { $prod = $p; break; } }
        if (!$prod || ($prod['status'] ?? '') !== 'approved') return ['ok' => false, 'error' => 'المنتج ده مش معتمد أو مش موجود'];
        if (strcasecmp((string)$prod['email'], (string)$cr['email']) !== 0) return ['ok' => false, 'error' => 'المنتج ده مش بتاع المسوق صاحب الكود'];
        $q = max(1, min(999, (int)$qty));
        $calc = noon_product_calc($prod);
        if (empty($calc['ok'])) return ['ok' => false, 'error' => 'المنتج ده ناقص بيانات (السعر أو النسبة)'];
        $fx   = noon_product_fx_aed();
        $rate = (float)noon_product_cat_rate((string)$prod['category']);
        $comm_v = round((float)$prod['price_aed'] * $fx * $q * $rate / 100, 2);
        if ($sale_v <= 0) $sale_v = round((float)$prod['price_aed'] * $fx * $q, 2);
        $share  = (float)$calc['pct'];
        $mk_egp = round((float)$calc['egp'] * $q, 2);
    } else {
        if ($comm_v <= 0) return ['ok' => false, 'error' => 'عمولة نون لازم تكون أكبر من صفر'];
        $share  = is_numeric($cr['share'] ?? null) ? (float)$cr['share'] : noon_default_share();
        $mk_egp = round($comm_v * $share / 100, 2);
    }
    $at = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$at_date) ? (string)$at_date : date('Y-m-d');
    $rec = [
        'id'         => 'ns' . bin2hex(random_bytes(4)),
        'code_id'    => (string)$cr['id'],
        'code'       => (string)$cr['code'],
        'email'      => (string)$cr['email'],
        'order_no'   => $order_no,
        'sale'       => $sale_v,
        'noon_comm'  => $comm_v,
        'share'      => $share,
        'mk_egp'     => $mk_egp,
        'state'      => 'pending',
        'at'         => $at,
        'created_at' => date('c'),
        'by'         => (string)$by,
        'confirmed_at' => '',
        'reversed_at'  => '',
        'reverse_note' => '',
        'product_id'   => $prod ? (string)$prod['id'] : '',
        'product_name' => $prod ? (string)$prod['pname'] : '',
        'qty'          => $q,
        'mk_source'    => $prod ? 'product' : 'code',
    ];
    $dup = false;
    $ok = jupdate('noon_sales.json', function (&$d) use ($rec, $cr, $order_no, &$dup) {
        foreach ($d as $s) {
            if (strcasecmp((string)($s['code'] ?? ''), (string)$cr['code']) === 0
                && strcasecmp(trim((string)($s['order_no'] ?? '')), $order_no) === 0
                && ($s['state'] ?? '') !== 'reversed') { $dup = true; return false; }
        }
        $d[] = $rec;
        return true;
    });
    if ($dup) return ['ok' => false, 'error' => 'الأوردر ده متسجل قبل كده بنفس الكود'];
    if ($ok !== true) return ['ok' => false, 'error' => 'ما اتسجلتش البيعة، جرب تاني'];
    if ($mk_egp > 0) {
        commission_credit_pending($rec['email'], $mk_egp, null, 'عمولة نون (كود ' . $rec['code'] . ') — أوردر ' . $order_no);
    }
    if (function_exists('notify_user')) {
        notify_user($rec['email'], '🟡 بيعة جديدة بكود نون: عمولتك ' . number_format($mk_egp, 2) . ' ج.م (معلقة لحد التأكيد)', 'dashboard/noon.php');
    }
    return ['ok' => true, 'rec' => $rec];
}

// إلغاء بيعة (مرتجع/ملغية): العمولة بتتخصم من المعلق الأول ثم المتاح
function noon_sale_reverse($sid, $reason = '') {
    $rec = null;
    jupdate('noon_sales.json', function (&$d) use ($sid, $reason, &$rec) {
        foreach ($d as $i => $s) {
            if ((string)($s['id'] ?? '') !== (string)$sid) continue;
            if (($s['state'] ?? '') === 'reversed') return false;
            $rec = $s;
            $d[$i]['state'] = 'reversed';
            $d[$i]['reversed_at'] = date('c');
            $d[$i]['reverse_note'] = mb_substr(trim((string)$reason), 0, 200);
            return true;
        }
        return false;
    });
    if (!$rec) return ['ok' => false, 'error' => 'البيعة مش موجودة أو اتلغت قبل كده'];
    if ((float)$rec['mk_egp'] > 0) {
        $why = trim((string)$reason) !== '' ? ' — ' . trim((string)$reason) : '';
        commission_reverse($rec['email'], (float)$rec['mk_egp'], null, 'إلغاء عمولة نون (كود ' . $rec['code'] . ') — أوردر ' . $rec['order_no'] . $why);
    }
    return ['ok' => true, 'rec' => $rec];
}

// إحصائيات المسوق (كل أكواده): الاستخدامات وقيمة البيعات والربح (المعلق والمتاح) من غير الملغي
function noon_stats_for($email) {
    $st = ['uses' => 0, 'sales' => 0.0, 'profit' => 0.0, 'pending' => 0.0, 'confirmed' => 0.0, 'last_at' => ''];
    foreach (noon_sales_all() as $s) {
        if (strcasecmp((string)($s['email'] ?? ''), (string)$email) !== 0) continue;
        if (($s['state'] ?? '') === 'reversed') continue;
        $m = (float)($s['mk_egp'] ?? 0);
        $st['uses']++;
        $st['sales'] += (float)($s['sale'] ?? 0);
        $st['profit'] += $m;
        if (($s['state'] ?? '') === 'confirmed') $st['confirmed'] += $m; else $st['pending'] += $m;
        if ((string)($s['at'] ?? '') > $st['last_at']) $st['last_at'] = (string)$s['at'];
    }
    $st['sales'] = round($st['sales'], 2);
    $st['profit'] = round($st['profit'], 2);
    $st['pending'] = round($st['pending'], 2);
    $st['confirmed'] = round($st['confirmed'], 2);
    return $st;
}

// إحصائيات كود واحد (للإدارة والمسوق)
function noon_stats_for_code($code_id) {
    $st = ['uses' => 0, 'sales' => 0.0, 'noon' => 0.0, 'profit' => 0.0, 'pending' => 0.0, 'confirmed' => 0.0, 'last_at' => ''];
    foreach (noon_sales_all() as $s) {
        if ((string)($s['code_id'] ?? '') !== (string)$code_id) continue;
        if (($s['state'] ?? '') === 'reversed') continue;
        $m = (float)($s['mk_egp'] ?? 0);
        $st['uses']++;
        $st['sales'] += (float)($s['sale'] ?? 0);
        $st['noon'] += (float)($s['noon_comm'] ?? 0);
        $st['profit'] += $m;
        if (($s['state'] ?? '') === 'confirmed') $st['confirmed'] += $m; else $st['pending'] += $m;
        if ((string)($s['at'] ?? '') > $st['last_at']) $st['last_at'] = (string)$s['at'];
    }
    $st['sales'] = round($st['sales'], 2);
    $st['noon'] = round($st['noon'], 2);
    $st['profit'] = round($st['profit'], 2);
    $st['pending'] = round($st['pending'], 2);
    $st['confirmed'] = round($st['confirmed'], 2);
    return $st;
}

// بيعات المسوق (الأحدث أولاً)، بتشمل الملغي عشان يظهر للمسوق
function noon_sales_for($email, $limit = 100) {
    $rows = array_values(array_filter(noon_sales_all(), function ($s) use ($email) {
        return strcasecmp((string)($s['email'] ?? ''), (string)$email) === 0;
    }));
    usort($rows, function ($a, $b) { return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')); });
    return array_slice($rows, 0, max(1, (int)$limit));
}

// ============================================================================
//  🛒 منتجات نون: المسوق بيبعت لينك المنتج اللي هيشتغل عليه، والإدارة بتحدد نسبته
//  - مفيش لينك نون بيتولّد من الموقع (لينكات نون بتتصدر من برنامج نون نفسه)
//  - الموقع بيوضح للمسوق قد إيه هياخد على كل بيعة، والكود اللي هيسوق بيه (الكود المعتمد)
//  - النطاق: نون الإمارات والسعودية بس (لينكات أمازون ونون مصر بيترفضوا)
//  - نسبة المسوق من سعر المنتج ≤ نسبة الفئة في الجدول، والمبلغ بيتحدد بحد أقصى 50 درهم إماراتي
//  ملف البيانات: data/noon_products.json
// ============================================================================

// جدول نسب الفئات (من برنامج نون): slug => [الاسم, النسبة %]
function noon_product_categories() {
    return [
        'apparel' => ['Apparel', 10], 'bags_luggage' => ['Bags & Luggage', 10], 'footwear' => ['Footwear', 10],
        'jewelry' => ['Jewelry', 10], 'stationery_office' => ['Stationery & Office Supplies', 9],
        'eyewear' => ['Eyewear', 9], 'watches' => ['Watches', 9], 'books_media' => ['Books & Media', 9],
        'sports_outdoor' => ['Sports & Outdoor', 8], 'hair_personal_care' => ['Hair & Personal Care', 8],
        'automotive' => ['Automotive', 8], 'colour_cosmetics' => ['Colour Cosmetics', 8], 'fragrance' => ['Fragrance', 8],
        'health_nutrition' => ['Health & Nutrition', 8], 'toys' => ['Toys', 8], 'baby_nonconsumables' => ['Baby Nonconsumables', 8],
        'baby_consumables' => ['Baby Consumables', 8], 'electronic_personal_care' => ['Electronic Personal Care', 8],
        'small_appliances' => ['Small Appliances', 8], 'furniture' => ['Furniture', 6], 'kitchen_dining' => ['Kitchen & Dining', 6],
        'home_improvement' => ['Home Improvement', 6], 'home_decor' => ['Home Decor', 6], 'bath_bedding' => ['Bath & Bedding', 6],
        'grocery' => ['Grocery', 5], 'large_appliances' => ['Large Appliances', 5], 'laptop_accessories' => ['Laptop Accessories', 4],
        'mobile_accessories' => ['Mobile Accessories', 4], 'vg_accessories' => ['VG Accessories', 4], 'audio_video' => ['Audio & Video', 3],
        'headsets_headphones' => ['Headsets & Headphones', 3], 'wearables' => ['Wearables', 3], 'camera' => ['Camera', 3],
        'laptops' => ['Laptops', 3], 'vg_consoles' => ['VG Consoles', 3], 'mobiles' => ['Mobiles', 2],
    ];
}
function noon_product_cat_label($slug) { $c = noon_product_categories(); return isset($c[$slug]) ? $c[$slug][0] : '—'; }
function noon_product_cat_rate($slug) { $c = noon_product_categories(); return isset($c[$slug]) ? (float)$c[$slug][1] : null; }
// الحد الأقصى للعمولة على العنصر الواحد (درهم إماراتي)
function noon_product_cap_aed() { return 50.0; }
// سعر الدرهم بالجنيه (من الإعدادات، والافتراضي زي أمازون)
function noon_product_fx_aed() {
    $v = site_setting('noon_fx_aed', '');
    return ($v !== '' && is_numeric($v) && (float)$v > 0) ? (float)$v : 14.24;
}

function noon_products_all() { $r = jload('noon_products.json', [], false); return is_array($r) ? array_values($r) : []; }
function noon_products_for($email) {
    $rows = array_values(array_filter(noon_products_all(), function ($p) use ($email) {
        return strcasecmp((string)($p['email'] ?? ''), (string)$email) === 0;
    }));
    usort($rows, function ($a, $b) { return strcmp((string)($b['requested_at'] ?? ''), (string)($a['requested_at'] ?? '')); });
    return $rows;
}
function noon_products_pending() {
    $rows = array_values(array_filter(noon_products_all(), function ($p) { return ($p['status'] ?? '') === 'pending'; }));
    usort($rows, function ($a, $b) { return strcmp((string)($a['requested_at'] ?? ''), (string)($b['requested_at'] ?? '')); });
    return $rows;
}

// تحليل لينك المنتج: نون الإمارات أو السعودية بس، ولازم يكون صفحة منتج (/p/)
function noon_product_parse_url($raw) {
    $u = trim((string)$raw);
    if ($u === '') return ['ok' => false, 'error' => 'حط لينك المنتج'];
    if (strpos($u, '://') === false) $u = 'https://' . $u;
    $p = parse_url($u);
    $host = strtolower((string)($p['host'] ?? ''));
    $host = preg_replace('/^(www\.|m\.)/', '', $host);
    $path = (string)($p['path'] ?? '');
    if ($host === 'amazon.eg' || preg_match('/(^|\.)amazon\./', $host)) {
        return ['ok' => false, 'error' => 'ده لينك أمازون. الصفحة دي بتقبل لينكات نون (الإمارات والسعودية) بس'];
    }
    if (!in_array($host, ['noon.com', 'noon.ae', 'noon.sa'], true)) {
        return ['ok' => false, 'error' => 'اللينك لازم يكون من نون (noon.com أو noon.ae أو noon.sa)'];
    }
    if (preg_match('~^/(egypt|eg)(-|/)~i', $path)) {
        return ['ok' => false, 'error' => 'لينكات نون مصر مش مدعومة هنا. الروابط بتشتغل على نون الإمارات والسعودية بس'];
    }
    $country = '';
    if ($host === 'noon.ae' || preg_match('~^/uae-(en|ar)/~i', $path)) $country = 'ae';
    elseif ($host === 'noon.sa' || preg_match('~^/saudi-(en|ar)/~i', $path)) $country = 'sa';
    if ($country === '') return ['ok' => false, 'error' => 'اللينك لازم يكون لمنتج على نون الإمارات أو السعودية'];
    if (strpos($path, '/p/') === false) return ['ok' => false, 'error' => 'اللينك لازم يكون لصفحة منتج (فيه /p/ في الرابط)'];
    $clean = 'https://www.' . $host . rtrim($path, '/') . '/';
    return ['ok' => true, 'url' => $clean, 'country' => $country];
}

// حساب ربح المسوق على بيعة واحدة: سعر المنتج × نسبته، والحد الأقصى 50 درهم، وبعدين بالجنيه
function noon_product_calc($p) {
    $pct   = is_numeric($p['mk_pct'] ?? null) ? (float)$p['mk_pct'] : null;
    $price = max(0, (float)($p['price_aed'] ?? 0));
    $fx    = noon_product_fx_aed();
    $out = ['ok' => false, 'pct' => $pct, 'price' => round($price, 2), 'raw_aed' => 0.0, 'aed' => 0.0, 'egp' => 0.0, 'capped' => false, 'fx' => $fx, 'cap' => noon_product_cap_aed()];
    if ($pct === null || $price <= 0) return $out;
    $raw = round($price * $pct / 100, 2);
    $aed = min($raw, noon_product_cap_aed());
    $out['raw_aed'] = $raw;
    $out['aed']     = round($aed, 2);
    $out['egp']     = round($aed * $fx, 2);
    $out['capped']  = $raw > noon_product_cap_aed();
    $out['ok']      = true;
    return $out;
}

// عملة سعر المنتج على نون حسب الدولة: السعودية = ريال، الإمارات (والافتراضي) = درهم
function noon_product_cur_label($p) {
    return strtolower((string)($p['country'] ?? '')) === 'sa' ? 'ر.س' : 'د.إ';
}

// طلب منتج من المسوق (بيفضل قيد المراجعة لحد ما الإدارة تحدد الفئة والسعر والنسبة)
function noon_product_request($email, $raw_url, $note = '') {
    $email = strtolower(trim((string)$email));
    $mk = $email !== '' ? find_user($email) : null;
    if (!$mk) return ['ok' => false, 'error' => 'الحساب مش موجود'];
    if (($mk['status'] ?? '') === 'suspended') return ['ok' => false, 'error' => 'حسابك موقوف حالياً'];
    $pu = noon_product_parse_url($raw_url);
    if (empty($pu['ok'])) return ['ok' => false, 'error' => (string)$pu['error']];
    $meta = noon_fetch_product_meta($pu['url']);
    $rec = [
        'id'           => 'np' . bin2hex(random_bytes(4)),
        'email'        => $email,
        'name'         => (string)($mk['name'] ?? ''),
        'url'          => $pu['url'],
        'country'      => $pu['country'],
        'status'       => 'pending',
        'pname'        => '',
        'category'     => '',
        'price_aed'    => null,
        'mk_pct'       => null,
        'note'         => mb_substr(trim((string)$note), 0, 300),
        'requested_at' => date('c'),
        'decided_at'   => '',
        'decided_by'   => '',
        'reject_note'  => '',
        'meta'         => $meta,
        'fetch'        => $meta ? 'ok' : 'failed',
    ];
    $err = '';
    $ok = jupdate('noon_products.json', function (&$d) use ($rec, $email, &$err) {
        foreach ((array)$d as $p) {
            if (strcasecmp((string)($p['email'] ?? ''), $email) !== 0) continue;
            if (($p['url'] ?? '') !== $rec['url']) continue;
            if (in_array((string)($p['status'] ?? ''), ['pending', 'approved'], true)) { $err = 'المنتج ده متسجل قبل كده'; return false; }
        }
        $d[] = $rec;
        return true;
    }, []);
    if ($ok !== true) return ['ok' => false, 'error' => $err !== '' ? $err : 'ما اتسجلش المنتج، جرب تاني'];
    return ['ok' => true, 'rec' => $rec];
}

// قرار الإدارة على المنتج: اعتماد (بالفئة والاسم والسعر والنسبة) أو رفض
function noon_product_decide($id, $approve, $category = '', $pname = '', $price_aed = '', $mk_pct = '', $note = '', $by = '') {
    $rec = null; $err = '';
    // البيانات اللي اتسحبت من نون بتتعتمد تلقائي؛ الإدارة تقدر تغيّرها بس مش لازم
    $pm = [];
    foreach (noon_products_all() as $pp) {
        if ((string)($pp['id'] ?? '') === (string)$id) { $pm = (isset($pp['meta']) && is_array($pp['meta'])) ? $pp['meta'] : []; break; }
    }
    if ((string)$category === '') $category = (string)($pm['cat_guess'] ?? '');
    $cat_rate = noon_product_cat_rate((string)$category);
    $pname = trim((string)$pname) !== '' ? mb_substr(trim((string)$pname), 0, 120) : mb_substr(trim((string)($pm['title'] ?? '')), 0, 120);
    $price = is_numeric($price_aed) ? round((float)$price_aed, 2) : 0;
    if ($price <= 0 && !empty($pm['price']) && (strtoupper((string)($pm['currency'] ?? '')) === '' || strtoupper((string)$pm['currency']) === 'AED')) {
        $price = round((float)$pm['price'], 2);
    }
    $pct   = is_numeric($mk_pct) ? round((float)$mk_pct, 2) : -1;
    if ($approve) {
        if ($cat_rate === null) $err = 'اختار الفئة';
        elseif ($pname === '') $err = 'اكتب اسم المنتج';
        elseif ($price <= 0) $err = 'سعر المنتج لازم يكون أكبر من صفر';
        elseif ($pct < 0 || $pct > $cat_rate) $err = 'نسبة المسوق لازم تكون بين 0 و ' . rtrim(rtrim(number_format($cat_rate, 2, '.', ''), '0'), '.') . '% (نسبة الفئة)';
        if ($err !== '') return ['ok' => false, 'error' => $err];
    }
    $ok = jupdate('noon_products.json', function (&$d) use ($id, $approve, $category, $pname, $price, $pct, $note, $by, &$rec, &$err) {
        foreach ((array)$d as $i => $p) {
            if ((string)($p['id'] ?? '') !== (string)$id) continue;
            if (($p['status'] ?? '') !== 'pending') { $err = 'الطلب ده اتقرر قبل كده'; return false; }
            $d[$i]['status']     = $approve ? 'approved' : 'rejected';
            $d[$i]['decided_at'] = date('c');
            $d[$i]['decided_by'] = (string)$by;
            if ($approve) {
                $d[$i]['category']  = (string)$category;
                $d[$i]['pname']     = $pname;
                $d[$i]['price_aed'] = $price;
                $d[$i]['mk_pct']    = $pct;
                $d[$i]['note']      = mb_substr(trim((string)$note), 0, 300) ?: ($d[$i]['note'] ?? '');
            } else {
                $d[$i]['reject_note'] = mb_substr(trim((string)$note), 0, 300);
            }
            $rec = $d[$i];
            return true;
        }
        $err = 'الطلب مش موجود';
        return false;
    }, []);
    if ($ok !== true || !$rec) return ['ok' => false, 'error' => $err !== '' ? $err : 'تعذر حفظ القرار'];
    return ['ok' => true, 'rec' => $rec];
}

// ---------- سحب بيانات المنتج من صفحة نون (الاسم والسعر والصور والوصف والفئة) ----------
// بنقرأ بيانات الصفحة القياسية (Open Graph و JSON-LD). لو مقدرناش نسحب، الإدارة بتكتب البيانات يدوي.
function noon_fetch_product_meta($url) {
    $GLOBALS['noon_fetch_reason'] = '';
    $u = (string)$url;
    if (!preg_match('~^https://(www\.)?noon\.(com|ae|sa)/~i', $u)) return [];
    if (!function_exists('curl_init')) return noon_fetch_fail('', 0, '', $u);
    $ch = curl_init($u);
    if (!$ch) return noon_fetch_fail(false, 0, 'curl_init فشل', $u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8', 'Accept-Language: ar,en;q=0.8'],
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = (string)curl_error($ch);
    curl_close($ch);
    $meta = (is_string($html) && $html !== '') ? noon_parse_product_html($html) : [];
    if (!empty($meta)) return $meta;
    return noon_fetch_fail($html, $code, $cerr, $u);
}
// بيسجل سبب الفشل (للمسوق + سجل الإدارة + لوج السيرفر) ويرجّع []
function noon_fetch_fail($html, $code, $cerr, $url) {
    $reason = wf_fetch_fail_reason($html, $code, $cerr, false, 'نون');
    $GLOBALS['noon_fetch_reason'] = $reason;
    error_log('[noon fetch] ' . $reason . ' | HTTP ' . (int)$code . ' | ' . mb_substr((string)$url, 0, 200));
    return [];
}

function noon_ld_nodes($j) {
    $out = [];
    if (isset($j['@graph']) && is_array($j['@graph'])) {
        foreach ($j['@graph'] as $n) { if (is_array($n)) $out[] = $n; }
        return $out;
    }
    if (isset($j[0])) {
        foreach ($j as $n) { if (is_array($n)) $out[] = $n; }
        return $out;
    }
    $out[] = $j;
    return $out;
}

// تخمين الفئة من اسم الفئة في الـ breadcrumbs (أطول كلمة مفتاحية الأول)
function noon_guess_category($hay) {
    static $map = [
        'laptop accessories' => 'laptop_accessories', 'laptop' => 'laptops', 'laptops' => 'laptops',
        'mobile accessories' => 'mobile_accessories', 'phone accessories' => 'mobile_accessories',
        'mobiles' => 'mobiles', 'smartphone' => 'mobiles', 'mobile' => 'mobiles', 'phone' => 'mobiles',
        'tablet' => 'laptops', 'headphone' => 'headsets_headphones', 'headset' => 'headsets_headphones',
        'earbud' => 'headsets_headphones', 'earphone' => 'headsets_headphones', 'wearable' => 'wearables',
        'smartwatch' => 'wearables', 'watch' => 'watches', 'camera' => 'camera', 'speaker' => 'audio_video',
        'soundbar' => 'audio_video', 'television' => 'audio_video', 'tv' => 'audio_video', 'audio' => 'audio_video',
        'gaming accessories' => 'vg_accessories', 'video game' => 'vg_accessories', 'gaming console' => 'vg_consoles',
        'console' => 'vg_consoles', 'perfume' => 'fragrance', 'fragrance' => 'fragrance', 'makeup' => 'colour_cosmetics',
        'cosmetic' => 'colour_cosmetics', 'hair' => 'hair_personal_care', 'personal care' => 'hair_personal_care',
        'shoe' => 'footwear', 'footwear' => 'footwear', 'sneaker' => 'footwear', 'handbag' => 'bags_luggage',
        'luggage' => 'bags_luggage', 'bag' => 'bags_luggage', 'jewel' => 'jewelry', 'fashion' => 'apparel',
        'apparel' => 'apparel', 'clothing' => 'apparel', 'book' => 'books_media', 'stationery' => 'stationery_office',
        'office' => 'stationery_office', 'toy' => 'toys', 'baby' => 'baby_consumables', 'kitchen' => 'kitchen_dining',
        'home decor' => 'home_decor', 'decor' => 'home_decor', 'furniture' => 'furniture', 'bedding' => 'bath_bedding',
        'bath' => 'bath_bedding', 'grocery' => 'grocery', 'large appliance' => 'large_appliances',
        'small appliance' => 'small_appliances', 'appliance' => 'large_appliances', 'sport' => 'sports_outdoor',
        'outdoor' => 'sports_outdoor', 'automotive' => 'automotive', 'health' => 'health_nutrition',
        'nutrition' => 'health_nutrition', 'eyewear' => 'eyewear', 'sunglass' => 'eyewear', 'glasses' => 'eyewear',
        'home improvement' => 'home_improvement',
    ];
    $hay = mb_strtolower((string)$hay);
    $keys = array_keys($map);
    usort($keys, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });
    foreach ($keys as $k) { if ($k !== '' && mb_strpos($hay, $k) !== false) return $map[$k]; }
    return '';
}

function noon_parse_product_html($html) {
    $html = (string)$html;
    if ($html === '') return [];
    $meta = function ($key) use ($html) {
        $vals = [];
        if (preg_match_all('~<meta\b[^>]*>~i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('~(?:property|name)\s*=\s*["\']' . preg_quote($key, '~') . '["\']~i', $tag)) continue;
                if (preg_match('~content\s*=\s*(["\'])(.*?)\1~is', $tag, $c)) $vals[] = trim(html_entity_decode($c[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        return array_values(array_filter($vals, 'strlen'));
    };
    $t = $meta('og:title');
    $title = isset($t[0]) ? $t[0] : '';
    $d = $meta('og:description');
    if (!$d) $d = $meta('description');
    $desc = isset($d[0]) ? $d[0] : '';
    $images = $meta('og:image');
    $pr = $meta('product:price:amount');
    $price = isset($pr[0]) ? $pr[0] : '';
    $cu = $meta('product:price:currency');
    $currency = strtoupper(isset($cu[0]) ? $cu[0] : '');
    $crumbs = [];
    $cat_raw = '';
    $specs_raw = [];
    if (preg_match_all('~<script[^>]+type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $ls)) {
        foreach ($ls[1] as $js) {
            $j = json_decode(trim($js), true);
            if (!is_array($j)) continue;
            foreach (noon_ld_nodes($j) as $n) {
                $types = isset($n['@type']) ? (array)$n['@type'] : [];
                if (in_array('Product', $types, true)) {
                    if ($title === '' && !empty($n['name'])) $title = (string)$n['name'];
                    if ($desc === '' && !empty($n['description'])) $desc = (string)$n['description'];
                    if (!empty($n['image'])) {
                        foreach ((array)$n['image'] as $im) {
                            $iu = is_array($im) ? (isset($im['url']) ? $im['url'] : '') : $im;
                            if (is_string($iu) && $iu !== '') $images[] = $iu;
                        }
                    }
                    if (!empty($n['category']) && is_string($n['category'])) $cat_raw = $n['category'];
                    if (!empty($n['brand'])) {
                        $bn = is_array($n['brand']) ? (isset($n['brand']['name']) ? $n['brand']['name'] : '') : $n['brand'];
                        if (is_string($bn) && $bn !== '') $specs_raw[] = ['k' => 'العلامة التجارية', 'v' => $bn];
                    }
                    if (!empty($n['additionalProperty']) && is_array($n['additionalProperty'])) {
                        foreach ($n['additionalProperty'] as $ap) {
                            if (is_array($ap) && !empty($ap['name']) && isset($ap['value']) && is_scalar($ap['value'])) {
                                $specs_raw[] = ['k' => (string)$ap['name'], 'v' => (string)$ap['value']];
                            }
                        }
                    }
                    foreach (['color' => 'اللون', 'size' => 'المقاس', 'material' => 'الخامة', 'model' => 'الموديل', 'sku' => 'SKU', 'gtin13' => 'الباركود'] as $sk => $slbl) {
                        if (!empty($n[$sk]) && is_scalar($n[$sk])) $specs_raw[] = ['k' => $slbl, 'v' => (string)$n[$sk]];
                    }
                    $offers = isset($n['offers']) ? $n['offers'] : null;
                    $offer_list = (is_array($offers) && isset($offers[0])) ? $offers : [$offers];
                    foreach ($offer_list as $of) {
                        if (!is_array($of)) continue;
                        $pv = isset($of['price']) ? $of['price'] : (isset($of['lowPrice']) ? $of['lowPrice'] : '');
                        if ($price === '' && $pv !== '' && is_numeric(str_replace(',', '', (string)$pv))) $price = (string)$pv;
                        if ($currency === '' && !empty($of['priceCurrency'])) $currency = strtoupper((string)$of['priceCurrency']);
                    }
                }
                if (in_array('BreadcrumbList', $types, true) && !empty($n['itemListElement'])) {
                    foreach ((array)$n['itemListElement'] as $li) {
                        if (!is_array($li)) continue;
                        $nm = isset($li['name']) ? $li['name'] : (isset($li['item']['name']) ? $li['item']['name'] : '');
                        if (is_string($nm) && $nm !== '') $crumbs[] = $nm;
                    }
                }
            }
        }
    }
    $imgs = [];
    foreach ($images as $iu) {
        $iu = trim((string)$iu);
        if ($iu !== '' && preg_match('~^https?://~i', $iu) && !in_array($iu, $imgs, true)) $imgs[] = $iu;
    }
    $title = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$title)), 0, 180);
    $desc  = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$desc))), 0, 1500);
    $pnum  = str_replace(',', '', (string)$price);
    $price_f = is_numeric($pnum) ? round((float)$pnum, 2) : 0.0;
    if ($title === '' && $price_f <= 0 && !$imgs) return [];
    return [
        'title'     => $title,
        'price'     => $price_f,
        'currency'  => $currency,
        'img'       => $imgs ? $imgs[0] : '',
        'images'    => array_slice($imgs, 0, 8),
        'desc'      => $desc,
        'specs'     => noon_clean_specs($specs_raw),
        'cat_guess' => noon_guess_category(implode(' ', $crumbs) . ' ' . $cat_raw),
        'crumbs'    => array_slice(array_values(array_unique($crumbs)), 0, 6),
    ];
}

// خيارات المنتجات المعتمدة لفورم تسجيل البيعة في الإدارة (المسوق — المنتج — سعر البيعة)
function noon_sale_product_options() {
    $out = '';
    foreach (noon_products_all() as $p) {
        if (($p['status'] ?? '') !== 'approved') continue;
        $pc = noon_product_calc($p);
        $who = (string)($p['name'] ?? '') !== '' ? (string)$p['name'] : (string)($p['email'] ?? '');
        $out .= '<option value="' . esc((string)$p['id']) . '">' . esc($who . ' — ' . (string)($p['pname'] ?? '') . ' — ' . number_format((float)$pc['egp'], 2) . ' ج.م للبيعة') . '</option>';
    }
    return $out;
}


// تنضيف قايمة المواصفات اللي اتسحبت من نون (بدون تكرار، وبحد أقصى)
function noon_clean_specs($rows) {
    $out = []; $seen = [];
    foreach ((array)$rows as $r) {
        $k = trim(mb_substr((string)($r['k'] ?? ''), 0, 80));
        $v = trim(mb_substr((string)($r['v'] ?? ''), 0, 300));
        if ($k === '' || $v === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = ['k' => $k, 'v' => $v];
        if (count($out) >= 30) break;
    }
    return $out;
}

// لوحة «الوصف والتفاصيل والصور» للمسوق (زي أمازون): وصف + جدول مواصفات + صور
function noon_product_panel_html($meta) {
    $meta  = is_array($meta) ? $meta : [];
    $desc  = trim((string)($meta['desc'] ?? ''));
    $specs = is_array($meta['specs'] ?? null) ? $meta['specs'] : [];
    $imgs  = [];
    foreach ((array)($meta['images'] ?? []) as $iu) {
        if (is_string($iu) && preg_match('~^https?://~i', $iu)) $imgs[] = $iu;
    }
    if ($desc === '' && !$specs && !$imgs) {
        return '<div class="mut" style="font-size:12px;margin-top:8px">نون مرجعتش وصف ولا تفاصيل ولا صور للمنتج ده.</div>';
    }
    $counts = [];
    if ($desc !== '') $counts[] = 'الوصف';
    if ($specs) $counts[] = count($specs) . ' تفصيلة';
    if ($imgs) $counts[] = count($imgs) . ' صورة';
    $h = '<details style="margin-top:8px"><summary style="font-size:13px;font-weight:800;cursor:pointer">📦 ' . esc(implode(' · ', $counts)) . '</summary>';
    if ($desc !== '') {
        $h .= '<div style="font-size:13px;line-height:1.9;margin:8px 0;white-space:pre-line" dir="auto">' . esc($desc) . '</div>';
    }
    if ($specs) {
        $h .= '<table style="width:100%;font-size:13px;border-collapse:collapse;margin:8px 0">';
        foreach ($specs as $row) {
            $h .= '<tr><th dir="auto" style="text-align:start;background:#f1f5f9;padding:6px 8px;border:1px solid #e2e8f0;width:38%">' . esc((string)($row['k'] ?? '')) . '</th>'
                . '<td dir="auto" style="padding:6px 8px;border:1px solid #e2e8f0">' . esc((string)($row['v'] ?? '')) . '</td></tr>';
        }
        $h .= '</table>';
    }
    if ($imgs) {
        $h .= '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">';
        foreach ($imgs as $iu) {
            $h .= '<a href="' . esc($iu) . '" target="_blank" rel="noopener"><img src="' . esc($iu) . '" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:96px;height:96px;object-fit:contain;border:1px solid #e2e8f0;border-radius:10px;background:#fff"></a>';
        }
        $h .= '</div>';
    }
    return $h . '</details>';
}
