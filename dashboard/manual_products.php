<?php $PUBLIC_PAGE = true; require 'layout.php';
// صفحة المنتجات اليدوية: للأدمن بس
if (!is_admin_user()) {
    header('Location: ../index.php'); exit;
}

$FIELDS = ['name', 'price', 'country', 'ship_fee', 'commission', 'category', 'stock', 'sku', 'description', 'colors', 'sizes', 'material', 'weight', 'dimensions', 'faqs'];
$msgs = []; $errs = [];
$status = manual_db_status();
$cats = array_values(array_filter(wf_category_canonical(), function ($c) { return !in_array($c, ['منتجات رقمية', 'سوشيال ميديا'], true); }));

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$form = null;   // قيم الفورم اللي هتتعرض (للتعديل أو بعد خطأ في الإرسال)
$form_mode = 'add';
if ($status === '' && $edit_id > 0) {
    $form = manual_product_form_values($edit_id);
    if ($form) $form_mode = 'update';
    else { $errs[] = 'المنتج ده مش موجود'; $edit_id = 0; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $status === '') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'update') {
        $id = 0; $old_images = [];
        $ok = true;
        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $stored = manual_product_form_values($id);
            if (!$stored) { $errs[] = 'المنتج ده مش موجود'; $ok = false; }
            else $old_images = $stored['images'];
        }
        if ($ok) {
            $remove = array_map('strval', (array)($_POST['images_remove'] ?? []));
            $keep = array_values(array_filter($old_images, function ($u) use ($remove) { return !in_array($u, $remove, true); }));
            [$uploaded, $upload_errs] = manual_save_uploads($_FILES['images_files'] ?? null);
            foreach ($upload_errs as $e) $errs[] = $e;
            $_POST['images'] = array_merge($keep, manual_split_list((string)($_POST['images_text'] ?? ''), "\n|"), $uploaded);
            [$clean, $err] = manual_validate_row($_POST);
            if ($clean === null) {
                $errs[] = $err;
                // نرجّع اللي اتكتب عشان ميتمسحش
                $form = array_intersect_key($_POST, array_flip($FIELDS));
                $form['images'] = $_POST['images'];
                $form_mode = $action === 'update' ? 'update' : 'add';
                $form['id'] = $id;
            } elseif ($action === 'add') {
                $newid = manual_product_insert($clean);
                if ($newid) $msgs[] = 'اتضاف المنتج (رقم ' . $newid . ')';
                else $errs[] = 'حصلت مشكلة في الحفظ';
            } else {
                manual_product_update($id, $clean);
                $msgs[] = 'اتحفظ التعديل على المنتج #' . $id;
                $form = manual_product_form_values($id);
                $form_mode = 'update';
            }
        }
    } elseif ($action === 'csv') {
        $f = $_FILES['csv_file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errs[] = 'اختار ملف CSV الأول';
        } else {
            [$rows, $perr] = manual_parse_csv($f['tmp_name']);
            $ok_count = 0;
            foreach ($rows as $r) { if (manual_product_insert($r)) $ok_count++; }
            if ($ok_count) $msgs[] = 'اتضاف ' . $ok_count . ' منتج من الملف';
            foreach ($perr as $e) $errs[] = $e;
            if (!$ok_count && !$perr) $errs[] = 'الملف مفيهوش منتجات';
        }
    } elseif ($action === 'index_vf') {
        $r = manual_index_batch(10, 4.0);
        $msgs[] = 'اتحسبت ملامح ' . $r['ok'] . ' منتج، وفشل ' . $r['fail'] . '. اللي لسه مستنية: ' . manual_index_pending_count();
    } elseif ($action === 'delete') {
        manual_product_delete($_POST['id'] ?? '') ? $msgs[] = 'اتشال المنتج من الموقع' : $errs[] = 'المنتج ده مش موجود';
    } elseif ($action === 'bulk_delete') {
        $bulk_ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $bulk_ok = 0;
        foreach ($bulk_ids as $bulk_id) {
            if (manual_product_delete((int)$bulk_id)) $bulk_ok++;
        }
        if ($bulk_ok) $msgs[] = 'اتشال ' . $bulk_ok . ' منتج من الموقع';
        else $errs[] = 'مفيش منتجات محددة صالحة للحذف';
    }
}

function manual_img_src($u) {
    return preg_match('#^https?://#i', $u) ? $u : '../' . $u;
}

function manual_form_html($v, $mode, $cats) {
    $g = function ($k) use ($v) { return esc($v[$k] ?? ''); };
    $sel = (string)($v['category'] ?? '');
    $currencies = wf_country_currencies();
    $country = wf_match_country($v['country'] ?? '', 'مصر');
    $imgs = (array)($v['images'] ?? []);
    echo '<form method="post" enctype="multipart/form-data">' . csrf_field();
    echo '<input type="hidden" name="action" value="' . ($mode === 'update' ? 'update' : 'add') . '">';
    if ($mode === 'update') echo '<input type="hidden" name="id" value="' . (int)($v['id'] ?? 0) . '">';
    echo '<div class="mp-grid">';
    echo '<label>الاسم *<input name="name" required maxlength="255" value="' . $g('name') . '"></label>';
    // الدولة بتحدد عملة السعر (مصر=ج.م، السعودية=ر.س، الإمارات=د.إ، الأردن=د.أ، عُمان=ر.ع)
    echo '<label>الدولة *<select name="country" id="mpCountry" required onchange="mpSyncCurrency()">';
    foreach (wf_country_list() as $c) {
        echo '<option value="' . esc($c) . '"' . ($c === $country ? ' selected' : '') . '>' . esc($c) . ' — ' . esc($currencies[$c]) . '</option>';
    }
    echo '</select></label>';
    echo '<label>السعر (<span class="mp-cur">' . esc(wf_country_currency($country)) . '</span>) *<input name="price" required inputmode="decimal" value="' . $g('price') . '"></label>';
    // سعر الشحن: رقم واحد ثابت للمنتج كله بعملة دولته، وبيتقدم على جدول مناطق الدولة.
    // فاضي = الرسوم تتحسب حسب منطقة العميل من «إعدادات الشحن» — ومفيش أي تحويل عملات.
    echo '<label>سعر الشحن (<span class="mp-cur">' . esc(wf_country_currency($country)) . '</span>) — اختياري<input name="ship_fee" inputmode="decimal" placeholder="فاضي = حسب منطقة العميل" value="' . $g('ship_fee') . '"></label>';
    // العمولة الثابتة للمسوق: جنيه مصري لكل قطعة، للمنتجات المصرية فقط (رصيد المسوقين بالجنيه)
    echo '<label>عمولة المسوق لكل قطعة (ج.م) — اختياري<input name="commission" inputmode="decimal" placeholder="0 = بدون عمولة أساسية" value="' . $g('commission') . '"></label>';
    echo '<label>القسم *<select name="category" required><option value="">— اختار —</option>';
    foreach ($cats as $c) echo '<option value="' . esc($c) . '"' . ($c === $sel ? ' selected' : '') . '>' . esc($c) . '</option>';
    echo '</select></label>';
    echo '<label>الكمية (اختياري)<input name="stock" inputmode="numeric" value="' . $g('stock') . '"></label>';
    echo '<label>كود المنتج SKU (اختياري)<input name="sku" value="' . $g('sku') . '"></label>';
    echo '<label>الخامة (اختياري)<input name="material" value="' . $g('material') . '"></label>';
    echo '<label>الوزن (اختياري)<input name="weight" value="' . $g('weight') . '"></label>';
    echo '<label>الأبعاد (اختياري)<input name="dimensions" value="' . $g('dimensions') . '"></label>';
    echo '<label>الألوان (اختياري)<input name="colors" placeholder="أحمر | أزرق | أسود" value="' . $g('colors') . '"></label>';
    echo '<label>المقاسات (اختياري)<input name="sizes" placeholder="S | M | L | XL" value="' . $g('sizes') . '"></label>';
    echo '</div>';
    echo '<p class="mut" style="margin:2px 0 0">سعر الشحن اختياري: لو سبته فاضي، الرسوم هتتحسب حسب منطقة العميل من <a href="admin_shipping.php">إعدادات الشحن</a>. ولو دخلت رقم، هو ده الشحن لكل المناطق بعملة دولة المنتج (مفيش تحويل عملات).</p>';
    echo '<p class="mut" style="margin:2px 0 0">عمولة المسوق اختيارية (مبلغ ثابت بالجنيه عن كل قطعة، للمنتجات المصرية فقط): بتتسجل للمسوق على كل أوركر تلقائيًا من غير ما سعر العميل يتغير، وفوقها العمولة الإضافية اللي هو بيضيفها بنفسه. فاضي أو صفر = زي ما كانت، بدون عمولة أساسية.</p>';
    echo '<label>الوصف التفصيلي (سطر لكل فكرة)<textarea name="description" rows="6">' . $g('description') . '</textarea></label>';
    echo '<label>الأسئلة الشائعة (سؤال :: جواب، كل سؤال في سطر)<textarea name="faqs" rows="4" placeholder="هل المنتج أصلي؟ :: أيوه، أصلي 100%">' . $g('faqs') . '</textarea></label>';

    echo '<h4>الصور</h4>';
    if ($imgs) {
        echo '<p class="mut">الصور الحالية (علّم «امسح» على اللي عايز تشيله):</p><div class="mp-imgs">';
        foreach ($imgs as $u) {
            echo '<label class="mp-img"><img src="' . esc(manual_img_src($u)) . '" alt=""><span><input type="checkbox" name="images_remove[]" value="' . esc($u) . '"> امسح</span></label>';
        }
        echo '</div>';
    }
    echo '<label>صور من الكمبيوتر (تقدر تختار أكتر من صورة، JPG/PNG/WEBP، لحد 4MB للصورة)<input type="file" name="images_files[]" accept="image/*" multiple></label>';
    echo '<label>روابط صور (سطر لكل رابط https://...)<textarea name="images_text" rows="3" dir="ltr" placeholder="https://example.com/1.jpg"></textarea></label>';
    echo '<p class="mut">السعر بيتسجّل وبيتعرض بعملة الدولة المختارة (مفيش تحويل عملات). المنتج هيظهر في فلتر الدولة دي على الموقع، وجوه «كل الدول».</p>';
    echo '<p><button class="btn" type="submit">' . ($mode === 'update' ? 'حفظ التعديل' : 'إضافة المنتج') . '</button>';
    if ($mode === 'update') echo ' <a class="btn outline" href="manual_products.php">إلغاء</a>';
    echo '</p></form>';
    // العملة اللي جنب خانة السعر بتتبدّل أوتوماتيك مع اختيار الدولة
    echo '<script>var mpCurrencies=' . json_encode($currencies, JSON_UNESCAPED_UNICODE) . ';'
        . 'function mpSyncCurrency(){var s=document.getElementById("mpCountry");if(!s)return;var c=mpCurrencies[s.value]||"ج.م";'
        . 'document.querySelectorAll(".mp-cur").forEach(function(e){e.textContent=c;});}'
        . 'document.addEventListener("DOMContentLoaded",mpSyncCurrency);</script>';
}

layout_top('المنتجات اليدوية', 'home');
echo '<style>.mp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.mp-grid label,.card label{display:block;margin:8px 0}.mp-grid input,.mp-grid select,.card input,.card textarea,.card select{width:100%;box-sizing:border-box}.mp-imgs{display:flex;flex-wrap:wrap;gap:10px}.mp-img{display:block;width:110px;margin:0}.mp-img img{width:110px;height:110px;object-fit:cover;border-radius:8px;background:#f1f5f9}.mp-msg{padding:10px 12px;border-radius:8px;margin:8px 0}.mp-ok{background:#ecfdf5;color:#065f46}.mp-err{background:#fef2f2;color:#991b1b}.mp-cur{font-weight:700}</style>';

foreach ($msgs as $m) echo '<div class="card mp-msg mp-ok">' . esc($m) . '</div>';
foreach ($errs as $e) echo '<div class="card mp-msg mp-err">' . esc($e) . '</div>';

if ($status !== '') {
    echo '<div class="card"><p class="mp-msg mp-err">' . esc($status) . '</p>';
    echo '<p class="mut">لما قاعدة البيانات تتجهز الصفحة دي هتشتغل. الإعدادات اللازمة: MANUAL_DB_HOST و MANUAL_DB_NAME و MANUAL_DB_USER و MANUAL_DB_PASS.</p></div>';
} else {
    echo '<div class="card"><h3>' . ($form_mode === 'update' ? 'تعديل المنتج #' . (int)($form['id'] ?? $edit_id) : 'إضافة منتج جديد') . '</h3>';
    manual_form_html($form ?: [], $form_mode, $cats);
    echo '</div>';

    echo '<div class="card"><h3>رفع ملف Excel / CSV</h3>';
    echo '<p class="mut">الصف الأول لازم يكون العناوين: name,price,country,ship_fee,commission,category,stock,sku,description,colors,sizes,material,weight,dimensions,images,faqs (الترتيب مش مهم — الأعمدة بتتقرا بالاسم)<br>';
    echo 'القيم المتعددة (الألوان، المقاسات، الصور) بتتفصل بـ | . الأسئلة: السؤال::الجواب|السؤال::الجواب . القسم لازم يكون اسم قسم من أقسام الموقع.<br>';
    echo '<b>country</b> اختياري (الافتراضي مصر): ' . esc(implode(' / ', wf_country_list())) . ' — والعملة بتتحدد منها أوتوماتيك (' . esc(implode(' / ', array_values(wf_country_currencies()))) . ') والسعر بيتسجّل بالعملة دي من غير تحويل.<br>';
    echo '<b>ship_fee</b> اختياري (شحن ثابت بعملة الدولة، فاضي = حسب المنطقة، صفر = شحن مجاني). و<b>commission</b> اختياري: عمولة ثابتة بالجنيه للمسوق عن كل قطعة — للمنتجات المصرية فقط، وأقل من السعر، وفاضي/صفر = بدون عمولة أساسية.<br>';
    echo 'في Excel: احفظ الملف كـ «CSV (Comma delimited)».</p>';
    echo '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="csv">';
    echo '<input type="file" name="csv_file" accept=".csv,text/csv" required> <button class="btn" type="submit">رفع</button></form></div>';

    [$vt, $vi] = manual_index_counts();
    echo '<div class="card"><h3>البحث بالصورة</h3>';
    echo '<p class="mut">المنتجات اليدوية المفهرسة للبحث بالصورة: <b>' . (int)$vi . '</b> من <b>' . (int)$vt . '</b>. المنتج بيتفهرس تلقائي لما تحفظه بصورة من الكمبيوتر أو برابط. اللي اترفع من CSV أو فشل يتفهرس بالزرار ده (10 منتجات كل مرة).</p>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="index_vf"><button class="btn" type="submit">احسب ملامح الصور</button></form></div>';

    $list = manual_product_list(200);
    echo '<div class="card"><h3>المنتجات اليدوية (' . count($list) . ')</h3>';
    if (!$list) echo '<p class="mut">مفيش منتجات يدوية لسه</p>';
    if ($list) {
        // ملخّص بالدولة عشان الأدمن يشوف أنهي منتجات بالسعر المحلي (ج.م / ر.س / د.إ / د.أ / ر.ع)
        $by_country = [];
        foreach ($list as $r) {
            $c = wf_match_country((manual_decode_details($r))['country'] ?? '', 'مصر');
            $by_country[$c] = ($by_country[$c] ?? 0) + 1;
        }
        $country_bits = [];
        foreach (wf_country_list() as $cn) {
            if (!empty($by_country[$cn])) $country_bits[] = $cn . ' (' . wf_country_currency($cn) . '): ' . (int)$by_country[$cn];
        }
        echo '<p class="mut">حسب الدولة — ' . esc(implode(' • ', $country_bits)) . '</p>';
    }
    if ($list) {
        echo '<form id="mpBulkForm" method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0">' . csrf_field()
            . '<input type="hidden" name="action" value="bulk_delete">'
            . '<label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" onchange="document.querySelectorAll(\'.mp-pick\').forEach(function(c){c.checked=this.checked;},this)"> تحديد الكل</label>'
            . '<button class="btn sm outline" type="submit" style="color:#b91c1c" onclick="return confirm(\'حذف المنتجات المحددة نهائيًا؟\')">🗑 حذف المحدد</button>'
            . '</form>';
    }
    foreach ($list as $r) {
        $d = manual_decode_details($r);
        $imgs = manual_row_images($r, $d);
        $row_country = wf_match_country($d['country'] ?? '', 'مصر');
        $row_cur = wf_country_currency($row_country);
        echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid #e2e8f0">';
        echo '<input type="checkbox" class="mp-pick" form="mpBulkForm" name="ids[]" value="' . (int)$r['id'] . '" style="width:18px;height:18px" aria-label="تحديد المنتج">';
        echo $imgs ? '<img src="' . esc(manual_img_src($imgs[0])) . '" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px">' : '<span style="width:56px;height:56px;background:#f1f5f9;border-radius:6px;display:inline-block"></span>';
        // شحن المنتج (لو المالك حدده) بيظهر في القائمة عشان يعرف أنهي منتجات ليها سعر ثابت
        $row_ship = (isset($d['ship_fee']) && is_numeric($d['ship_fee'])) ? (' — شحن ' . rtrim(rtrim(number_format((float)$d['ship_fee'], 2, '.', ''), '0'), '.') . ' ' . $row_cur) : '';
        // عمولة المسوق الثابتة (مصرية بالجنيه بس) — تظهر في السطر لما تكون أكبر من صفر
        $row_comm = ($row_country === 'مصر' && isset($d['commission']) && is_numeric($d['commission']) && (float)$d['commission'] > 0)
            ? (' — عمولة ' . rtrim(rtrim(number_format((float)$d['commission'], 2, '.', ''), '0'), '.') . ' ج.م/قطعة') : '';
        echo '<div style="flex:1;min-width:200px"><strong>' . esc($r['name']) . '</strong><br><span class="mut">' . esc($r['price']) . ' ' . esc($row_cur) . esc($row_ship) . esc($row_comm) . ' — ' . esc($row_country) . ' — ' . esc($r['category']) . ' — ' . count($imgs) . ' صورة</span></div>';
        // التفاصيل الإضافية (وصف/ألوان/مقاسات/خامة/وزن/أبعاد/SKU/أسئلة) في ديالوج بدل ما تتحشر في السطر
        $mp_rows = [];
        $mp_push = function ($l, $v) use (&$mp_rows) { $v = trim((string)$v); if ($v !== '') $mp_rows[] = [$l, $v]; };
        $mp_push('السعر', $r['price'] . ' ' . $row_cur);
        $mp_push('الدولة', $row_country);
        $mp_push('القسم', (string)($r['category'] ?? ''));
        $mp_push('الكمية', (string)($d['stock'] ?? ''));
        $mp_push('كود SKU', (string)($d['sku'] ?? ''));
        if (isset($d['ship_fee']) && is_numeric($d['ship_fee'])) $mp_push('سعر الشحن', rtrim(rtrim(number_format((float)$d['ship_fee'], 2, '.', ''), '0'), '.') . ' ' . $row_cur);
        if ($row_country === 'مصر' && isset($d['commission']) && is_numeric($d['commission']) && (float)$d['commission'] > 0) $mp_push('عمولة المسوق لكل قطعة', rtrim(rtrim(number_format((float)$d['commission'], 2, '.', ''), '0'), '.') . ' ج.م');
        $mp_push('الخامة', (string)($d['material'] ?? ''));
        $mp_push('الوزن', (string)($d['weight'] ?? ''));
        $mp_push('الأبعاد', (string)($d['dimensions'] ?? ''));
        $mp_push('الألوان', is_array($d['colors'] ?? null) ? implode(' | ', (array)$d['colors']) : (string)($d['colors'] ?? ''));
        $mp_push('المقاسات', is_array($d['sizes'] ?? null) ? implode(' | ', (array)$d['sizes']) : (string)($d['sizes'] ?? ''));
        $mp_push('الوصف التفصيلي', (string)($d['description'] ?? ''));
        $mp_push('أسئلة شائعة', is_array($d['faqs'] ?? null) ? implode("\n", (array)$d['faqs']) : (string)($d['faqs'] ?? ''));
        $mp_push('عدد الصور', count($imgs) . ' صورة');
        echo wf_details_btn('تفاصيل المنتج #' . (int)$r['id'] . ' — ' . (string)$r['name'], $mp_rows) . ' ';
        echo '<a class="btn sm outline" href="?edit=' . (int)$r['id'] . '">تعديل</a> ';
        echo '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sm outline" type="submit">حذف</button></form>';
        echo '</div>';
    }
    echo '</div>';
}
layout_end();
