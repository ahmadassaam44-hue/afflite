<?php
// ============================================================================
//  👤 طلبات العملاء (مسوقي نون وأمازون)
//  المسوق بيختار منتج (من منتجاته المعتمدة) ويبعت بيانات العميل، ويختار:
//    - order: «اطلب المنتج ده للعميل» (الإدارة بتطلبه للعميل)
//    - lead : «ابعت بيانات العميل للإدارة للمتابعة»
//  الإدارة بتشوف الطلبات وتعلّم عليها «تم» أو «ترفض» (مع ملاحظة للمسوق).
//  ملف البيانات: data/customer_requests.json
// ============================================================================

function cust_reqs_all() { $r = jload('customer_requests.json', [], false); return is_array($r) ? array_values($r) : []; }

function cust_reqs_for($email, $source = '') {
    $rows = array_values(array_filter(cust_reqs_all(), function ($r) use ($email, $source) {
        if (strcasecmp((string)($r['email'] ?? ''), (string)$email) !== 0) return false;
        return $source === '' || (string)($r['source'] ?? '') === $source;
    }));
    usort($rows, function ($a, $b) { return strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')); });
    return $rows;
}

function cust_reqs_pending($source = '') {
    $rows = array_values(array_filter(cust_reqs_all(), function ($r) use ($source) {
        if (($r['status'] ?? '') !== 'pending') return false;
        return $source === '' || (string)($r['source'] ?? '') === $source;
    }));
    usort($rows, function ($a, $b) { return strcmp((string)($a['at'] ?? ''), (string)($b['at'] ?? '')); });
    return $rows;
}

// كود الدولة للموبايل (القائمة المعتمدة، من غير تكرار). الافتراضي مصر +20
function cust_phone_codes() {
    $raw = '+93+358+355+213+1+376+244+1+1+54+374+297+61+43+994+1+973+880+1+375+32+501+229+1+975+591+387+267+55+246+673+359+226+257+855+237+1+238+1+236+235+56+86+61+61+57+269+242+243+682+506+225+385+53+357+420+45+253+1+1+593+20+503+240+291+372+251+500+298+679+358+33+594+689+241+220+995+49+233+350+30+299+1+590+1+502+44+224+245+592+509+39+504+852+36+354+91+62+98+964+353+44+972+39+1+81+44+962+7+254+686+82+965+996+856+371+961+266+231+218+423+370+352+853+389+261+265+60+960+223+356+692+596+222+230+262+52+691+373+377+976+382+1+212+258+95+264+674+977+31+687+64+505+227+234+683+672+850+1+47+968+92+680+970+507+675+595+51+63+48+351+1+974+262+40+7+250+290+1+1+508+1+685+378+239+966+221+381+248+232+65+421+386+677+252+27+34+94+249+597+47+268+46+41+963+886+992+255+66+670+228+690+676+1+216+90+993+1+688+256+380+971+44+1+598+998+678+58+84+1+1+681+212+967+260+263+20';
    return array_values(array_unique(preg_split('/\+/', $raw, -1, PREG_SPLIT_NO_EMPTY)));
}

// يجمّع بيانات فورم العميل من الـ POST (مشترك بين نون وأمازون)
function cust_post_data() {
    $p = $_POST;
    return [
        'mode'       => (string)($p['mode'] ?? ''),
        'first'      => (string)($p['cust_first'] ?? ''),
        'last'       => (string)($p['cust_last'] ?? ''),
        'phone_code' => (string)($p['cust_phone_code'] ?? ''),
        'phone'      => (string)($p['cust_phone'] ?? ''),
        'apt'        => (string)($p['cust_apt'] ?? ''),
        'building'   => (string)($p['cust_building'] ?? ''),
        'street'     => (string)($p['cust_street'] ?? ''),
        'nickname'   => (string)($p['cust_nickname'] ?? ''),
        'directions' => (string)($p['cust_directions'] ?? ''),
        'qty'        => (int)($p['qty'] ?? 1),
        'note'       => (string)($p['note'] ?? ''),
    ];
}

// إنشاء طلب عميل. $data: mode, first, last, phone_code, phone, street, building, city, district, governorate, landmark, qty, note
function cust_req_create($source, $email, $product_label, $product_ref, $data = []) {
    $source = in_array($source, ['amazon', 'jumia'], true) ? $source : 'noon';
    $email  = strtolower(trim((string)$email));
    $mk = $email !== '' ? find_user($email) : null;
    if (!$mk) return ['ok' => false, 'error' => 'الحساب مش موجود'];
    if (($mk['status'] ?? '') === 'suspended') return ['ok' => false, 'error' => 'حسابك موقوف حالياً'];
    // أمازون: طلب المنتج للعميل بيتعمل من لينك المسوق (العميل بيكمل الطلب بنفسه)، فمفيش طلب "اطلب للعميل" من الإدارة
    if ((string)($data['mode'] ?? '') === 'order') {
        return ['ok' => false, 'error' => 'الطلب للعميل بيتعمل بنفسك من الإرشادات، وابعت بيانات العميل للإدارة للمتابعة'];
    }
    $first = mb_substr(trim((string)($data['first'] ?? '')), 0, 40);
    $last  = mb_substr(trim((string)($data['last'] ?? '')), 0, 40);
    if (mb_strlen($first) < 2 || mb_strlen($last) < 2) return ['ok' => false, 'error' => 'اكتب الاسم الأول واسم العيلة للعميل'];
    $name = $first . ' ' . $last;
    $code = preg_replace('/\D/', '', (string)($data['phone_code'] ?? ''));
    if (!in_array($code, cust_phone_codes(), true)) $code = '20';
    $num = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
    if ($code !== '' && strpos($num, $code) === 0 && strlen($num) - strlen($code) >= 8) $num = substr($num, strlen($code));
    if (strpos($num, '0') === 0) $num = substr($num, 1);   // الصفر اللي قدام الرقم المحلي بيتشال مع كود الدولة
    if (strlen($num) < 6 || strlen($num) > 12) return ['ok' => false, 'error' => 'رقم موبايل العميل مش صحيح'];
    $phone = '+' . $code . $num;
    $apt     = mb_substr(trim((string)($data['apt'] ?? '')), 0, 40);
    $bldg    = mb_substr(trim((string)($data['building'] ?? '')), 0, 80);
    $street  = mb_substr(trim((string)($data['street'] ?? '')), 0, 120);
    $nick    = mb_substr(trim((string)($data['nickname'] ?? '')), 0, 60);
    $dirs    = mb_substr(trim((string)($data['directions'] ?? '')), 0, 200);
    $city    = '';   // الحقل اتشال من الفورم؛ بيتسجل فاضي
    if ($apt === '') return ['ok' => false, 'error' => 'اكتب رقم الشقة/الطابق أو رقم الفيلا'];
    if ($bldg === '') return ['ok' => false, 'error' => 'اكتب اسم المبنى/المجمّع'];
    $mode = (string)($data['mode'] ?? '') === 'order' ? 'order' : 'lead';
    $address = implode('، ', array_filter([
        'شقة/طابق/فيلا: ' . $apt,
        'المبنى/المجمّع: ' . $bldg,
        $street !== '' ? 'الشارع/نقطة الاستدلال: ' . $street : '',
        $nick !== '' ? 'اسم مستعار للعنوان: ' . $nick : '',
        $dirs !== '' ? 'الاتجاهات: ' . $dirs : '',
    ], function ($x) { return $x !== ''; }));
    $qty = max(1, min(20, (int)($data['qty'] ?? 1)));
    $qty = max(1, min(20, (int)($data['qty'] ?? 1)));
    $rec = [
        'id'          => 'cr' . bin2hex(random_bytes(4)),
        'source'      => $source,
        'email'       => $email,
        'name'        => (string)($mk['name'] ?? ''),
        'ref'         => (string)($mk['ref_code'] ?? ''),
        'product'     => mb_substr(trim((string)$product_label), 0, 200),
        'product_ref' => (string)$product_ref,
        'mode'        => $mode,
        'cust_name'   => $name,
        'cust_phone'  => $phone,
        'cust_city'   => $city,
        'cust_address'=> $address,
        'qty'         => $qty,
        'note'        => mb_substr(trim((string)($data['note'] ?? '')), 0, 300),
        'status'      => 'pending',
        'admin_note'  => '',
        'at'          => date('c'),
        'decided_at'  => '',
        'decided_by'  => '',
    ];
    jupdate('customer_requests.json', function (&$d) use ($rec) {
        if (!is_array($d)) $d = [];
        $d[] = $rec;
        return true;
    }, []);
    if (function_exists('notify')) {
        $src_lbl = $source === 'amazon' ? 'أمازون' : ($source === 'jumia' ? 'جوميا' : 'نون');
        $mode_lbl = $mode === 'order' ? 'اطلب المنتج للعميل' : 'بيانات للمتابعة';
        notify('admin', '👤 طلب عميل من ' . $src_lbl . ' (' . $mode_lbl . ') — ' . ($rec['name'] !== '' ? $rec['name'] : $email)
            . ($rec['product'] !== '' ? "\n" . $rec['product'] : ''), 'dashboard/admin.php?tab=' . $source);
    }
    return ['ok' => true, 'rec' => $rec];
}

// قرار الإدارة: done (تم) أو rejected (ترفض) — مرة واحدة بس
function cust_req_decide($id, $status, $note = '', $by = '') {
    $status = $status === 'rejected' ? 'rejected' : 'done';
    $rec = null; $err = '';
    jupdate('customer_requests.json', function (&$d) use ($id, $status, $note, $by, &$rec, &$err) {
        foreach ((array)$d as $i => $r) {
            if ((string)($r['id'] ?? '') !== (string)$id) continue;
            if (($r['status'] ?? '') !== 'pending') { $err = 'الطلب ده اتقرر قبل كده'; return false; }
            $d[$i]['status']     = $status;
            $d[$i]['admin_note'] = mb_substr(trim((string)$note), 0, 300);
            $d[$i]['decided_at'] = date('c');
            $d[$i]['decided_by'] = (string)$by;
            $rec = $d[$i];
            return true;
        }
        $err = 'الطلب مش موجود';
        return false;
    }, []);
    if (!$rec) return ['ok' => false, 'error' => $err !== '' ? $err : 'تعذر حفظ القرار'];
    if (function_exists('notify_user')) {
        $link = $rec['source'] === 'amazon' ? 'dashboard/amazon.php?tab=customers' : ($rec['source'] === 'jumia' ? 'dashboard/jumia.php?tab=customers' : 'dashboard/noon.php?tab=customers');
        $txt  = $status === 'done'
            ? '✅ طلب العميل ' . $rec['cust_name'] . ' اتنفذ' . ($rec['admin_note'] !== '' ? ': ' . $rec['admin_note'] : '')
            : '⛔ طلب العميل ' . $rec['cust_name'] . ' اترفض' . ($rec['admin_note'] !== '' ? ': ' . $rec['admin_note'] : '');
        notify_user($rec['email'], $txt, $link);
    }
    return ['ok' => true, 'rec' => $rec];
}

// ---------- عرض: فورم المسوق + طلباته ----------
// $products: [['id' => ..., 'label' => ...], ...] (منتجات معتمدة للمسوق)
function cust_marketer_html($source, $email, $products, $tab_url, $noon_code = '') {
    $mine = cust_reqs_for($email, $source);
    $stat = ['pending' => ['⏳ بانتظار الإدارة', 'y'], 'done' => ['✅ اتنفذ', 'g'], 'rejected' => ['⛔ اترفض', 'r']];
    $h  = '<div class="noon-box">';
    $h .= '<h3 style="margin:0 0 6px;font-size:17px;font-weight:900">👤 طلب للعميل اللي هتبيعله</h3>';
    $h .= '<p class="mut" style="font-size:13px;margin:0 0 10px">اختار: تطلب المنتج للعميل بنفسك بالإرشادات، ولا تبعت بياناته للإدارة للمتابعة.</p>';
    if (!$products) {
        $h .= '<div class="noon-notice">لازم يتعتمد منتج الأول. بعد كده هيظهر هنا عشان تطلب للعميل.</div>';
    } else {
        // ---- الطريقة 1: المسوق بيطلب المنتج للعميل بنفسه (بالإرشادات) ----
        $h .= '<div style="border:2px solid #16a34a;border-radius:14px;padding:12px;margin-bottom:14px;background:#f0fdf4">';
        $h .= '<div style="font-size:17px;font-weight:900;margin-bottom:8px">🛒 اطلب المنتج ده للعميل</div>';
        if ($source === 'amazon' || $source === 'jumia') {
            $h .= '<p style="font-size:13px;margin:0 0 10px">ابعت اللينك ده للعميل. هو يدخل منه ويكمل الطلب عادي على ' . ($source === 'jumia' ? 'جوميا' : 'أمازون') . '، والطلب بيتسجل من لينكك.</p>';
            foreach ($products as $p) {
                $pid = (string)$p['id'];
                $idk = preg_replace('/[^a-zA-Z0-9]/', '', $pid);
                $trk = $source === 'jumia' ? (function_exists('jmi_track_url') ? jmi_track_url($pid) : '') : (function_exists('amz_track_url') ? amz_track_url($pid) : '');
                $h .= '<div style="margin-bottom:10px"><div style="font-size:13px;font-weight:700;margin-bottom:4px">' . esc((string)$p['label']) . '</div>'
                    . '<div class="row-actions"><input readonly dir="ltr" id="cr_lnk_' . esc($idk) . '" value="' . esc($trk) . '" style="flex:1;min-width:220px">'
                    . ' <button type="button" class="btn sm" onclick="navigator.clipboard.writeText(document.getElementById(\'cr_lnk_' . esc($idk) . '\').value);this.textContent=\'✅ نسخ\'">📋 نسخ</button></div></div>';
            }
            $h .= '<div style="font-size:18px;font-weight:900;margin:14px 0 2px;color:#15803d">👉 أو اطلب المنتج انت بنفسك من نفس اللينك على ' . ($source === 'jumia' ? 'جوميا' : 'أمازون') . '</div>';
        } else {
            $h .= '<p style="font-size:13px;margin:0 0 8px">اطلب المنتج انت من نون، وادخل كود نون بتاعك وقت الطلب:</p>';
            if ($noon_code !== '') {
                $h .= '<div class="row-actions"><input readonly dir="ltr" id="cr_ncode" value="' . esc($noon_code) . '" style="width:160px">'
                    . ' <button type="button" class="btn sm" onclick="navigator.clipboard.writeText(document.getElementById(\'cr_ncode\').value);this.textContent=\'✅ نسخ\'">📋 نسخ</button></div>';
            } else {
                $h .= '<div class="mut" style="font-size:13px">لازم يتعتمد كود نون بتاعك الأول عشان تقدر تطلب بيه.</div>';
            }
            $h .= '<div class="mut" style="font-size:13px;margin-top:8px">الكود بيدي خصم 10% لو أول مرة يطلب، و5% لو طلب قبل كده.</div>';
        }
        $h .= '</div>';

        // ---- الطريقة 2: ابعت بيانات العميل للإدارة للمتابعة ----
        $codes_opts = '';
        foreach (cust_phone_codes() as $cc) $codes_opts .= '<option value="' . esc($cc) . '"' . ($cc === '20' ? ' selected' : '') . '>+' . esc($cc) . '</option>';
        $h .= '<div style="font-size:17px;font-weight:900;margin:6px 0 8px">👤 ابعت بيانات العميل للإدارة للمتابعة</div>';
        $h .= '<form method="post" class="row-actions">' . csrf_field()
            . '<input type="hidden" name="act" value="cust_request"><input type="hidden" name="mode" value="lead">'
            . '<label class="field-label" for="cr_prod_' . esc($source) . '">المنتج</label>'
            . '<select id="cr_prod_' . esc($source) . '" name="product_ref" required>';
        foreach ($products as $p) $h .= '<option value="' . esc((string)$p['id']) . '">' . esc((string)$p['label']) . '</option>';
        $h .= '</select>'
            . '<label class="field-label">رقم الشقة والطابق/ رقم الفيلا *</label><input name="cust_apt" type="text" required maxlength="40">'
            . '<label class="field-label">اسم المبنى / المجمّع *</label><input name="cust_building" type="text" required maxlength="80">'
            . '<label class="field-label">اسم الشارع / نقطة الاستدلال (اختياري)</label><input name="cust_street" type="text" maxlength="120">'
            . '<label class="field-label">اسم مستعار للعنوان (اختياري)</label><input name="cust_nickname" type="text" maxlength="60">'
            . '<label class="field-label">الاتجاهات للوصول لعنوانك (اختياري)</label><input name="cust_directions" type="text" maxlength="200">'
            . '<div style="font-weight:800;margin-top:6px">تفاصيل المستلم</div>'
            . '<label class="field-label">الاسم الأول *</label><input name="cust_first" type="text" required maxlength="40">'
            . '<label class="field-label">اسم العائلة *</label><input name="cust_last" type="text" required maxlength="40">'
            . '<label class="field-label">رقم الجوال *</label>'
            . '<div style="display:flex;gap:6px;flex-wrap:wrap"><select name="cust_phone_code" dir="ltr" aria-label="كود الدولة" style="width:110px">' . $codes_opts . '</select>'
            . '<input name="cust_phone" type="tel" required maxlength="20" dir="ltr" inputmode="tel" style="flex:1;min-width:160px"></div>'
            . '<div class="mut" style="font-size:12px;margin-top:-4px">يمكن استخدامها للمساعدة في التوصيل</div>'
            . '<label class="field-label">الكمية</label><input name="qty" type="number" min="1" max="20" value="1" style="width:100px">'
            . '<label class="field-label">ملاحظة (اختياري)</label><input name="note" type="text" maxlength="300">'
            . '<button class="btn" type="submit">📨 ابعت للإدارة</button></form>';
    }
    $h .= '</div>';
    $h .= '<div class="noon-box" style="margin-top:14px"><h3 style="margin:0 0 10px;font-size:16px;font-weight:900">📋 طلبات العملاء بتاعتك (' . count($mine) . ')</h3>';
    if (!$mine) $h .= '<p class="mut">لسه ماعندكش طلبات عملاء.</p>';
    foreach ($mine as $r) {
        [$lbl, $cls] = $stat[(string)($r['status'] ?? 'pending')] ?? [(string)$r['status'], ''];
        $h .= '<div style="border:1px solid #e2e8f0;border-radius:12px;padding:10px;margin-bottom:8px;background:#fff">'
            . '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><b>' . esc((string)$r['cust_name']) . ' — ' . esc((string)$r['product']) . '</b>'
            . '<span class="noon-tag ' . esc($cls) . '">' . esc($lbl) . '</span></div>'
            . '<div class="mut" style="font-size:12px;margin-top:4px">' . esc(($r['mode'] === 'order' ? 'طلب منتج' : 'بيانات للمتابعة')) . ' · الكمية ' . (int)$r['qty'] . ' · ' . esc(substr((string)$r['at'], 0, 16)) . '</div>';
        if (($r['status'] ?? '') !== 'pending' && (string)($r['admin_note'] ?? '') !== '') $h .= '<div class="mut" style="font-size:13px;margin-top:4px">ملاحظة الإدارة: ' . esc((string)$r['admin_note']) . '</div>';
        $h .= '</div>';
    }
    $h .= '</div>';
    return $h;
}

// ---------- عرض: كارت الإدارة (الطلبات المعلقة) ----------
function cust_admin_html($source) {
    $pend = cust_reqs_pending($source);
    $h = '<div class="card"><h2>👤 طلبات العملاء — ' . ($source === 'amazon' ? 'أمازون' : ($source === 'jumia' ? 'جوميا' : 'نون')) . ' (' . count($pend) . ')</h2>';
    if (!$pend) $h .= '<p class="mut">مفيش طلبات عملاء بانتظار الإدارة.</p>';
    foreach ($pend as $r) {
        $mk = find_user((string)$r['email']);
        $mk_name = $mk ? (string)($mk['name'] ?? $r['email']) : (string)$r['email'];
        $rid = esc((string)$r['id']);
        $h .= '<div style="border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:10px">'
            . '<div><b>' . esc((string)$r['cust_name']) . '</b> — <span dir="ltr">' . esc((string)$r['cust_phone']) . '</span>'
            . ($r['cust_city'] !== '' ? ' — ' . esc((string)$r['cust_city']) : '') . '</div>'
            . ($r['cust_address'] !== '' ? '<div style="font-size:13px">📍 ' . esc((string)$r['cust_address']) . '</div>' : '')
            . '<div style="font-size:13px">📦 ' . esc((string)$r['product']) . ' · الكمية ' . (int)$r['qty'] . ' · ' . ($r['mode'] === 'order' ? '<b>اطلب المنتج للعميل</b>' : '<b>بيانات للمتابعة</b>') . '</div>'
            . '<div class="mut" style="font-size:12px">المسوق: ' . esc($mk_name) . ' (' . esc((string)$r['email']) . ') · ' . esc(substr((string)$r['at'], 0, 16)) . '</div>'
            . ($r['note'] !== '' ? '<div class="mut" style="font-size:13px">ملاحظة المسوق: ' . esc((string)$r['note']) . '</div>' : '')
            . '<form method="post" class="row-actions" style="margin-top:8px">' . csrf_field()
            . '<input type="hidden" name="cust_act" value="done"><input type="hidden" name="cust_id" value="' . $rid . '">'
            . '<input type="text" name="note" maxlength="300" placeholder="ملاحظة للمسوق (اختياري)"> <button class="btn sm" type="submit">✅ تم</button></form>'
            . '<form method="post" class="row-actions" style="margin-top:6px" onsubmit="return confirm(\'ترفض الطلب؟\')">' . csrf_field()
            . '<input type="hidden" name="cust_act" value="rejected"><input type="hidden" name="cust_id" value="' . $rid . '">'
            . '<input type="text" name="note" maxlength="300" placeholder="سبب الرفض"> <button class="btn sm outline" type="submit">⛔ رفض</button></form>'
            . '</div>';
    }
    $h .= '</div>';
    return $h;
}
