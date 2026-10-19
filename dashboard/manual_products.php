<?php $PUBLIC_PAGE = true; require 'layout.php';
// صفحة المنتجات اليدوية: للأدمن بس
if (!is_admin_user()) {
    header('Location: ../index.php'); exit;
}

$FIELDS = ['name', 'price', 'category', 'stock', 'sku', 'description', 'colors', 'sizes', 'material', 'weight', 'dimensions', 'faqs'];
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
    $imgs = (array)($v['images'] ?? []);
    echo '<form method="post" enctype="multipart/form-data">' . csrf_field();
    echo '<input type="hidden" name="action" value="' . ($mode === 'update' ? 'update' : 'add') . '">';
    if ($mode === 'update') echo '<input type="hidden" name="id" value="' . (int)($v['id'] ?? 0) . '">';
    echo '<div class="mp-grid">';
    echo '<label>الاسم *<input name="name" required maxlength="255" value="' . $g('name') . '"></label>';
    echo '<label>السعر (ج.م) *<input name="price" required inputmode="decimal" value="' . $g('price') . '"></label>';
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
    echo '<p><button class="btn" type="submit">' . ($mode === 'update' ? 'حفظ التعديل' : 'إضافة المنتج') . '</button>';
    if ($mode === 'update') echo ' <a class="btn outline" href="manual_products.php">إلغاء</a>';
    echo '</p></form>';
}

layout_top('المنتجات اليدوية', 'home');
echo '<style>.mp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.mp-grid label,.card label{display:block;margin:8px 0}.mp-grid input,.mp-grid select,.card input,.card textarea,.card select{width:100%;box-sizing:border-box}.mp-imgs{display:flex;flex-wrap:wrap;gap:10px}.mp-img{display:block;width:110px;margin:0}.mp-img img{width:110px;height:110px;object-fit:cover;border-radius:8px;background:#f1f5f9}.mp-msg{padding:10px 12px;border-radius:8px;margin:8px 0}.mp-ok{background:#ecfdf5;color:#065f46}.mp-err{background:#fef2f2;color:#991b1b}</style>';

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
    echo '<p class="mut">الصف الأول لازم يكون العناوين: name,price,category,stock,sku,description,colors,sizes,material,weight,dimensions,images,faqs<br>';
    echo 'القيم المتعددة (الألوان، المقاسات، الصور) بتتفصل بـ | . الأسئلة: السؤال::الجواب|السؤال::الجواب . القسم لازم يكون اسم قسم من أقسام الموقع.<br>';
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
        echo '<form id="mpBulkForm" method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0">' . csrf_field()
            . '<input type="hidden" name="action" value="bulk_delete">'
            . '<label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" onchange="document.querySelectorAll(\'.mp-pick\').forEach(function(c){c.checked=this.checked;},this)"> تحديد الكل</label>'
            . '<button class="btn sm outline" type="submit" style="color:#b91c1c" onclick="return confirm(\'حذف المنتجات المحددة نهائيًا؟\')">🗑 حذف المحدد</button>'
            . '</form>';
    }
    foreach ($list as $r) {
        $d = manual_decode_details($r);
        $imgs = manual_row_images($r, $d);
        echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid #e2e8f0">';
        echo '<input type="checkbox" class="mp-pick" form="mpBulkForm" name="ids[]" value="' . (int)$r['id'] . '" style="width:18px;height:18px" aria-label="تحديد المنتج">';
        echo $imgs ? '<img src="' . esc(manual_img_src($imgs[0])) . '" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px">' : '<span style="width:56px;height:56px;background:#f1f5f9;border-radius:6px;display:inline-block"></span>';
        echo '<div style="flex:1;min-width:200px"><strong>' . esc($r['name']) . '</strong><br><span class="mut">' . esc($r['price']) . ' ج.م — ' . esc($r['category']) . ' — ' . count($imgs) . ' صورة</span></div>';
        echo '<a class="btn sm outline" href="?edit=' . (int)$r['id'] . '">تعديل</a> ';
        echo '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sm outline" type="submit">حذف</button></form>';
        echo '</div>';
    }
    echo '</div>';
}
layout_end();
