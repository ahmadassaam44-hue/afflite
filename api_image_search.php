<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$req = json_decode($raw ?: '{}', true);
if (!is_array($req)) $req = [];

// نفس البحث متاح كذلك عبر GET للتجربة السريعة وللتشخيص:
// api_image_search.php?rgbs=210,35,42  (أكثر من لون مفصولة بفاصلة منقوطة)
if (!$req && isset($_GET['rgbs'])) {
    $trips = [];
    foreach (explode(';', (string)$_GET['rgbs']) as $chunk) {
        $nums = array_map('trim', explode(',', $chunk));
        if (count($nums) >= 3 && is_numeric($nums[0]) && is_numeric($nums[1]) && is_numeric($nums[2])) {
            $trips[] = [(int)$nums[0], (int)$nums[1], (int)$nums[2]];
        }
    }
    if ($trips) {
        $req = ['rgbs' => $trips];
        foreach (['cat', 'filename', 'keyword'] as $gk) {
            if (isset($_GET[$gk])) $req[$gk] = (string)$_GET[$gk];
        }
    }
}

$rgb_list = $req['rgbs'] ?? [];
$cat_filter = trim((string)($req['cat'] ?? ''));
$fn_hint = strtolower(trim((string)($req['filename'] ?? '')));
$kw_hint = trim((string)($req['keyword'] ?? ''));

$vindex = jload('visual_index.json', []);
if (!is_array($vindex)) $vindex = [];

// تحويل RGB المرسل إلى أقرب اسم لون عربي لمطابقة المنتجات التي تحتوي اللون في الوصف أو المتغيرات
function rgb_to_ar_colors($r, $g, $b) {
    static $pal = [
        ['أسود', 25, 25, 28], ['أبيض', 242, 242, 245], ['أحمر', 210, 35, 42], ['نبيتي', 115, 20, 34],
        ['أزرق', 35, 95, 200], ['كحلي', 22, 38, 82], ['لبني', 115, 180, 235], ['أخضر', 40, 145, 75],
        ['زيتي', 85, 102, 52], ['منت', 130, 215, 185], ['أصفر', 240, 200, 35], ['برتقالي', 235, 115, 35],
        ['وردي', 235, 130, 175], ['بنفسجي', 130, 70, 175], ['بني', 115, 70, 40], ['هافان', 155, 95, 45],
        ['جملي', 185, 130, 75], ['بيج', 220, 198, 165], ['رمادي', 140, 145, 152], ['ذهبي', 205, 165, 55],
        ['فضي', 185, 190, 198],
    ];
    $dists = [];
    foreach ($pal as $p) {
        $d = sqrt(pow($r - $p[1], 2) * 0.3 + pow($g - $p[2], 2) * 0.59 + pow($b - $p[3], 2) * 0.11);
        $dists[$p[0]] = $d;
    }
    asort($dists);
    return array_slice(array_keys($dists), 0, 2);
}

$detected_ar_colors = [];
if (is_array($rgb_list)) {
    foreach (array_slice($rgb_list, 0, 3) as $c) {
        if (is_array($c) && count($c) >= 3) {
            foreach (rgb_to_ar_colors((int)$c[0], (int)$c[1], (int)$c[2]) as $cn) {
                if (!in_array($cn, $detected_ar_colors, true)) $detected_ar_colors[] = $cn;
            }
        }
    }
}

$fn_clean = preg_replace('/\.(jpg|jpeg|png|webp|gif|bmp)$/i', '', basename($fn_hint));

// ===== الطريقة الجديدة: مقارنة الميزات البصرية (لون + شكل) من data/visual_features.json =====
// الميزات بيحسبها المحدّث (updater/visual_features.py) والمتصفح (tracker.js) بنفس الطريقة.
$vf = jload('visual_features.json', []);
$vf_items = (is_array($vf) && isset($vf['items']) && is_array($vf['items'])) ? $vf['items'] : [];
// المنتجات اليدوية (ملامحها محسوبة وقت الحفظ) بتتضاف للفهرس
if (function_exists('manual_visual_items')) {
    foreach (manual_visual_items() as $mi) $vf_items['manual#' . $mi['id']] = $mi;
}
$q_feat = $req['feat'] ?? null;
$use_feat = !empty($vf_items) && is_array($q_feat)
    && isset($q_feat['h'], $q_feat['t']) && is_array($q_feat['h']) && is_array($q_feat['t'])
    && count($q_feat['h']) === 64 && count($q_feat['t']) === 64;

// أقل درجة تشابه تتعرض (تحت كده النتيجة مش مطابقة فعلاً)
const VS_MIN_SCORE = 0.50;
const VS_CEZMA_MIN_PRICE_EGP = 100.0;

// ارتباط بيرسون بين شبكتين 8×8 (بيشيل فرق السطوع والخلفية)
function vs_centered_corr(array $a, array $b) {
    $n = min(count($a), count($b));
    if ($n === 0) return 0.0;
    $ma = array_sum($a) / count($a);
    $mb = array_sum($b) / count($b);
    $num = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = (float)$a[$i] - $ma;
        $y = (float)$b[$i] - $mb;
        $num += $x * $y; $da += $x * $x; $db += $y * $y;
    }
    $den = sqrt($da * $db);
    return $den > 0 ? $num / $den : 0.0;
}

// شبكة اللون 4×4×4: المسافة بين خانتين (شيفرون) — عشان الخانة المجاورة تعتبر مطابقة جزئيًا
function vs_bin_dist($ia, $ib) {
    $dr = abs((($ia >> 4) & 3) - (($ib >> 4) & 3));
    $dg = abs((($ia >> 2) & 3) - (($ib >> 2) & 3));
    $db = abs(($ia & 3) - ($ib & 3));
    return max($dr, max($dg, $db));
}

// مطابقة كتلية ناعمة بين هستوجرامين (أهم تحسين للمطابقة):
// التقاطع الخام بيقطع فورًا أي إضاءة/حد بني مختلف، فبنقارن الكتل مع تسامح لوني.
// بنرجّعها في الاتجاهين ونحسب التنكر (Dice) — القيمة 1 = تطابق كامل.
function vs_hist_soft(array $ha, array $hb) {
    $sa = array_sum($ha); $sb = array_sum($hb);
    if ($sa <= 0 || $sb <= 0) return 0.0;
    $tops = [];
    foreach ([[$ha, $sa, 'a'], [$hb, $sb, 'b']] as $side) {
        $h = $side[0]; $tot = $side[1];
        $bins = [];
        foreach ($h as $i => $v) { if ($v > $tot * 0.02) $bins[(int)$i] = $v / $tot; }
        arsort($bins); $bins = array_slice($bins, 0, 8, true);
        $tops[$side[2]] = $bins;
    }
    // عقوبة المسافة اللونية 0..3 (شيفرون على شبكة 4×4×4)
    $decay = [1.0, 0.55, 0.28, 0.05];
    foreach (['a', 'b'] as $s) {
        $other = $s === 'a' ? 'b' : 'a';
        $m = 0.0;
        foreach ($tops[$s] as $ia => $ma) {
            foreach ($tops[$other] as $ib => $mb) {
                if ($ma * $mb < 0.0004) continue;
                $d = vs_bin_dist((int)$ia, (int)$ib);
                $m += $ma * $mb * ($decay[$d] ?? 0.0);
            }
        }
        $res[$s] = $m;
    }
    // منح مطابقة "عالية" بكتل صغيرة جدًا (دجاج بلا لحمة): التنكُر لوحده مش كفاية
    $mn = min($res['a'], $res['b']);
    if ($mn <= 0) return 0.0;
    $dice = 2.0 * $mn / ($res['a'] + $res['b']);
    return $dice * min(1.0, $mn / 0.30);
}

// أفضل ارتباط شكل من 4 نافذات (كامل/مركز 6×6/زاوية يمين-أعلى/زاوية شمال-أسفل):
// بيحل مشكلة المؤطرة المختلفة والكروب اللي بيكسر الارتباط على الشبكة كاملة.
function vs_best_shape(array $qt, array $tt) {
    $idx = [];
    foreach ([[1, 6], [0, 6], [2, 6]] as $k => $conf) {
        $start = $conf[0]; $len = $conf[1];
        $qa = []; $ta = [];
        for ($r = $start; $r < $start + $len; $r++) {
            for ($c = $start; $c < $start + $len; $c++) {
                $i = $r * 8 + $c;
                $qa[] = $qt[$i]; $ta[] = $tt[$i];
            }
        }
        $idx[] = vs_centered_corr($qa, $ta);
    }
    $full = vs_centered_corr($qt, $tt);
    return max($full, max($idx));
}

// الدرجة المدمجة الجديدة (بتتعامل مع أعداد صحيحة 0..255)
// اللون = مزيج ناعم (تسامح إضاءة) + صارم (تفريق المنتجات المتشابهة في اللون العام)
function vs_hist_exact(array $ha, array $hb) {
    $sa = array_sum($ha); $sb = array_sum($hb);
    if ($sa <= 0 || $sb <= 0) return 0.0;
    $inter = 0;
    for ($i = 0; $i < 64; $i++) $inter += min((int)$ha[$i], (int)$hb[$i]);
    return 2.0 * $inter / ($sa + $sb);
}

function vs_pair_score(array $qh, array $qt, array $ph, array $pt) {
    $hist = 0.55 * vs_hist_soft($qh, $ph) + 0.45 * vs_hist_exact($qh, $ph);
    $shape = vs_best_shape($qt, $pt);
    return [0.52 * $hist + 0.48 * max(0.0, $shape), $hist, $shape];
}


// ===== فهرس سيزما (data/visual_cezma.bin + visual_cezma.idx) — سجل ثابت 132 بايت لكل منتج =====
// (بيتبني من updater/visual_cezma.py). بنقرا الـ bin كله، نحسب الدرجة، ونجيب بيانات أحسن النتايج من الـ idx.
function vs_scan_cezma(array $qh, array $qt, $cat_filter, $kw_hint, array $detected) {
    $bin_p = DATA_DIR . '/visual_cezma.bin';
    $idx_p = DATA_DIR . '/visual_cezma.idx';
    $bin_parts = glob($bin_p . '.part-*');
    $idx_parts = glob($idx_p . '.part-*');
    if (!is_array($bin_parts)) $bin_parts = [];
    if (!is_array($idx_parts)) $idx_parts = [];
    sort($bin_parts, SORT_STRING);
    sort($idx_parts, SORT_STRING);
    // During FTP rollout, fall back to the previous unsplit pair until both new sets exist.
    if (!$bin_parts || !$idx_parts) { $bin_parts = []; $idx_parts = []; }
    if ((!$bin_parts && !is_file($bin_p)) || (!$idx_parts && !is_file($idx_p))
        || count($qh) !== 64 || count($qt) !== 64) return [];

    if ($bin_parts) {
        $bin = '';
        foreach ($bin_parts as $part_path) {
            $part_data = @file_get_contents($part_path);
            if (!is_string($part_data)) return [];
            $bin .= $part_data;
        }
    } else {
        $bin = (string)@file_get_contents($bin_p);
    }
    $n = intdiv(strlen($bin), 132);
    if ($n === 0) return [];

    // جزء الاستعلام للارتباط (مرة واحدة)
    $qmean = array_sum($qt) / 64.0;
    $qc = [];
    $qn = 0.0;
    foreach ($qt as $v) { $d = (float)$v - $qmean; $qc[] = $d; $qn += $d * $d; }
    $qn = sqrt($qn);

    $filter_on = ($cat_filter !== '' && $cat_filter !== 'all');
    $cands = [];
    for ($i = 0; $i < $n; $i++) {
        $o = $i * 132;
        $off = unpack('V', substr($bin, $o + 128, 4))[1];
        if ($off >= 0xFFFFFFFE) continue;          // فشل أو لسه ماتحسبش
        $hv = unpack('C64', substr($bin, $o, 64));
        $tv = unpack('C64', substr($bin, $o + 64, 64));
        // فرز مبدئي رخيص فقط (تقاطع خام + ارتباط كامل) — التقييم الحقيقي بعدين على المرشحين
        $inter = 0;
        for ($k = 1; $k <= 64; $k++) {
            $a = $qh[$k - 1];
            $b = $hv[$k];
            $inter += ($a < $b) ? $a : $b;
        }
        $hist = $inter / 255.0;
        $sum = 0.0; $sq = 0.0; $num = 0.0;
        for ($k = 1; $k <= 64; $k++) {
            $t = $tv[$k];
            $sum += $t;
            $sq += $t * $t;
            $num += $qc[$k - 1] * $t;
        }
        $mb = $sum / 64.0;
        $db = $sq - 64.0 * $mb * $mb;
        $corr = ($qn > 0 && $db > 0) ? ($num / ($qn * sqrt($db))) : 0.0;
        if ($hist < 0.18 && $corr < 0.15) continue;   // مستبعد تمامًا قبل التقييم النافي
        $cands[] = [$hist, $corr, $i, $off, array_values($hv), array_values($tv)];
        if (count($cands) > 4000) {
            usort($cands, function ($a, $b) { return ($b[0] + $b[1]) <=> ($a[0] + $a[1]); });
            // Gallery photos may create several high-scoring rows for one product.
            // Keep a wider window so those rows don't crowd out other products.
            $cands = array_slice($cands, 0, 1200);
        }
    }
    unset($bin);
    usort($cands, function ($a, $b) { return ($b[0] + $b[1]) <=> ($a[0] + $a[1]); });
    $cands = array_slice($cands, 0, 600);

    // التقييم النافي: درجة اللون الناعمة + أفضل نافذة شكل على المرشحين القصار فقط
    $best_by_id = [];
    if ($idx_parts) {
        $fh = @fopen('php://temp', 'w+b');
        if (!$fh) return [];
        foreach ($idx_parts as $part_path) {
            $part = @fopen($part_path, 'rb');
            if (!$part) { fclose($fh); return []; }
            $copied = stream_copy_to_stream($part, $fh);
            fclose($part);
            if ($copied === false) { fclose($fh); return []; }
        }
        rewind($fh);
    } else {
        $fh = @fopen($idx_p, 'rb');
        if (!$fh) return [];
    }
    foreach ($cands as $c) {
        fseek($fh, $c[3]);
        $p = explode("\t", rtrim((string)fgets($fh), "\r\n"));
        if (count($p) < 4) continue;
        $id = $p[0]; $name = $p[1]; $raw_cat = $p[2]; $img = $p[3];
        $raw_price = (isset($p[4]) && is_numeric($p[4])) ? (float)$p[4] : 0.0;
        if ($raw_price < VS_CEZMA_MIN_PRICE_EGP) continue;
        $site_cat = wf_cezma_site_category($raw_cat, $name);
        if ($filter_on && $site_cat !== $cat_filter) continue;
        // بيانات الصف نفسها (متخزنة مع المرشح من الفرز المبدئي) للتقييم النافي
        if (!isset($c[4], $c[5])) continue;
        list($score, $hist_s, $shape_s) = vs_pair_score($qh, $qt, $c[4], $c[5]);
        if ($hist_s < 0.30 && $shape_s < 0.35) continue;   // لازم إشارة قوية واحدة
        if ($kw_hint !== '' && mb_stripos($name . ' ' . $site_cat, $kw_hint) !== false) $score += 0.06;
        foreach ($detected as $idx_c => $dcol) {
            if (mb_stripos($name, $dcol) !== false) { $score += ($idx_c === 0 ? 0.04 : 0.02); break; }
        }
        if ($score < VS_MIN_SCORE) continue;
        // One result card per product; show the gallery photo that matched best.
        if (isset($best_by_id[$id]) && $best_by_id[$id]['_s'] >= $score) continue;
        $best_by_id[$id] = [
            'id' => $id,
            'plat' => 'cezma',
            'name' => $name,
            'cat' => $site_cat,
            'img_url' => asset_url($img),
            'link' => (strpos($_SERVER['HTTP_REFERER'] ?? '', '/dashboard/') !== false ? 'product.php?id=' : 'dashboard/product.php?id=') . urlencode($id),
            '_s' => $score,
            'match_pct' => (int)max(1, min(97, round($score * 100))),
        ];
    }
    fclose($fh);
    return array_values($best_by_id);
}

$scored = [];
if ($use_feat) {
    $qh = array_map('intval', array_values($q_feat['h']));
    $qt = array_map('intval', array_values($q_feat['t']));
    foreach ($vf_items as $it) {
        if (!is_array($it) || !isset($it['h'], $it['t']) || !is_array($it['h']) || !is_array($it['t'])) continue;
        if (count($it['h']) !== 64 || count($it['t']) !== 64) continue;
        if ($cat_filter !== '' && $cat_filter !== 'all' && ($it['cat'] ?? '') !== $cat_filter) continue;
        // m5zoon منتجات خليجية (مش بتظهر في متجر مصر)
        if (($it['plat'] ?? '') === 'm5zoon') continue;

        // احسب التشابه لكل صور المنتج (الصورة الأساسية + الصور الإضافية)، وخد أعلى نتيجة.
        // كده المنتج يفضل قابلًا للمطابقة حتى لو الصورة المرفوعة هي صورة جانبية من المعرض.
        $photo_rows = [$it];
        $extra_photos = $it['gallery'] ?? ($it['photos'] ?? []);
        if (is_array($extra_photos)) {
            foreach ($extra_photos as $photo) {
                if (is_array($photo)) $photo_rows[] = $photo;
            }
        }
        $score = -1.0;
        $matched_photo = $it;
        foreach ($photo_rows as $photo) {
            if (!isset($photo['h'], $photo['t']) || !is_array($photo['h']) || !is_array($photo['t'])
                || count($photo['h']) !== 64 || count($photo['t']) !== 64) continue;
            // تقاطع خام كسريع للفرز المبدئي، وبعدها الدرجة الناعمة المحصّنة (لون بتسامح + أفضل نافذة شكل)
            $inter = 0;
            for ($i = 0; $i < 64; $i++) $inter += min($qh[$i], (int)$photo['h'][$i]);
            if ($inter / 255.0 < 0.15) continue; // مستبعد تمامًا قبل التقييم النافي
            list($photo_score, $hist_s, $shape_s) = vs_pair_score($qh, $qt, array_map('intval', $photo['h']), array_map('intval', $photo['t']));
            if ($photo_score > $score) {
                $score = $photo_score;
                $matched_photo = $photo;
                $matched_parts = [$hist_s, $shape_s];
            }
        }
        if ($score < 0) continue;
        // لازم إشارة قوية واحدة على الأقل — النتيجتين الضعاف = ضجيج
        if ($matched_parts[0] < 0.30 && $matched_parts[1] < 0.35) continue;

        if ($kw_hint !== '' && mb_stripos(($it['name'] ?? '') . ' ' . ($it['cat'] ?? ''), $kw_hint) !== false) {
            $score += 0.06;
        }
        foreach ($detected_ar_colors as $idx_c => $dcol) {
            if (mb_stripos($it['name'] ?? '', $dcol) !== false) {
                $score += ($idx_c === 0 ? 0.04 : 0.02);
                break;
            }
        }
        if ($score < VS_MIN_SCORE) continue;

        $scored[] = [
            'id' => (string)($it['id'] ?? ''),
            'plat' => (string)($it['plat'] ?? ''),
            'name' => (string)($it['name'] ?? ''),
            'cat' => (string)($it['cat'] ?? ''),
            '_s' => $score,
            'match_pct' => (int)max(1, min(97, round($score * 100))),
            'img_url' => asset_url($matched_photo['img'] ?? ($matched_photo['u'] ?? ($it['img'] ?? ($it['u'] ?? '')))),
            'link' => (strpos($_SERVER['HTTP_REFERER'] ?? '', '/dashboard/') !== false ? 'product.php?id=' : 'dashboard/product.php?id=') . urlencode((string)($it['id'] ?? '')),
        ];
    }
} else {
    // احتياطي: الفهرس القديم (visual_index.json) لو الملف الجديد مش موجود أو المتصفح ما بعتش ميزات
    $vindex_fb = $vindex;
    foreach ($vindex_fb as $it) {
        if ($cat_filter !== '' && $cat_filter !== 'all' && ($it['cat'] ?? '') !== $cat_filter) {
            continue;
        }
        $photo_rows = [$it];
        $extra_photos = $it['gallery'] ?? ($it['photos'] ?? []);
        if (is_array($extra_photos)) {
            foreach ($extra_photos as $photo) {
                if (is_array($photo)) $photo_rows[] = $photo;
            }
        }
        $score = -1.0;
        $matched_photo = $it;
        foreach ($photo_rows as $photo) {
            $photo_score = 40.0;
            // 1. تطابق اسم ملف أي صورة في المنتج
            if ($fn_clean !== '' && strlen($fn_clean) >= 4 && strpos((string)($photo['fn'] ?? ''), $fn_clean) !== false) {
                $photo_score += 60.0;
            }
            // 2. تطابق الكلمة المفتاحية الاختيارية (اسم/قسم المنتج)
            if ($kw_hint !== '' && mb_stripos(($it['name'] ?? '') . ' ' . ($it['cat'] ?? ''), $kw_hint) !== false) {
                $photo_score += 28.0;
            }
            // 3. أقرب لون بين صورة الاستعلام وأي صورة للمنتج
            $best_dist = 999.0;
            $photo_rgbs = is_array($photo['rgbs'] ?? null) ? $photo['rgbs'] : [];
            if (!empty($rgb_list) && $photo_rgbs) {
                foreach ($rgb_list as $qrgb) {
                    if (!is_array($qrgb) || count($qrgb) < 3) continue;
                    foreach ($photo_rgbs as $prgb) {
                        if (!is_array($prgb) || count($prgb) < 3) continue;
                        $dist = sqrt(
                            pow($qrgb[0] - $prgb[0], 2) * 0.30 +
                            pow($qrgb[1] - $prgb[1], 2) * 0.59 +
                            pow($qrgb[2] - $prgb[2], 2) * 0.11
                        );
                        if ($dist < $best_dist) $best_dist = $dist;
                    }
                }
                if ($best_dist < 140) {
                    $photo_score += max(0, (140 - $best_dist) / 140 * 42.0);
                }
            }
            if ($photo_score > $score) {
                $score = $photo_score;
                $matched_photo = $photo;
            }
        }

        // 4. تطابق اللون المستنتج مع ألوان المنتج أو اسمه
        foreach ($detected_ar_colors as $idx_c => $dcol) {
            if (in_array($dcol, $it['cols'] ?? [], true) || mb_stripos($it['name'] ?? '', $dcol) !== false) {
                $score += ($idx_c === 0 ? 18.0 : 10.0);
                break;
            }
        }

        if ($score > 45.0) {
            $it['match_pct'] = min(99, max(62, (int)round($score)));
            $it['img_url'] = asset_url($matched_photo['img'] ?? ($matched_photo['u'] ?? ($it['img'] ?? '')));
            $it['link'] = (strpos($_SERVER['HTTP_REFERER'] ?? '', '/dashboard/') !== false ? 'product.php?id=' : 'dashboard/product.php?id=') . urlencode($it['id']);
            $scored[] = $it;
        }
    }
}

if ($use_feat) {
    foreach (vs_scan_cezma($qh, $qt, $cat_filter, $kw_hint, $detected_ar_colors) as $ci) $scored[] = $ci;
}

usort($scored, function ($a, $b) {
    return ((float)($b['_s'] ?? ($b['match_pct'] ?? 0) / 100)) <=> ((float)($a['_s'] ?? ($a['match_pct'] ?? 0) / 100));
});

// أعلى 24 حسب الدرجة، بشرط إن المنتج لسه ظاهر في المتجر (عشان الرابط ميبقاش مكسور)
// + منع تكرار نفس المنتج بصيغ أدلة مختلفة (لوحة/بديل/موديل شريت الفرد نفسه)
$seen_names = [];
$final = [];
foreach ($scored as $fit) {
    if (count($final) >= 24) break;
    $fname = trim((string)($fit['name'] ?? ''));
    // Normalize: بلا مسافات/علامات، أول 14 حرف لكل الكلمة الأولى والثانية مثلاً
    $fkey = mb_strtolower(preg_replace('/[\s\-_\/\\\\|.,،()\[\]#+*]+/u', '', $fname));
    $fkey = mb_substr($fkey, 0, 22);
    if ($fkey !== '') {
        if (isset($seen_names[$fkey])) continue;
        $seen_names[$fkey] = 1;
    }
    $fplat = (string)($fit['plat'] ?? '');
    $fprod = find_product_by_id((string)($fit['id'] ?? ''), $fplat);
    if (!$fprod) continue;
    // دفاع إضافي لو فهرس الصور القديم لسه فيه منتج سيزما أقل من الحد.
    if ($fplat === 'cezma' && (float)($fprod['price'] ?? 0) < VS_CEZMA_MIN_PRICE_EGP) continue;
    // السعر المعروض لازم يطابق سعر صفحة المنتج (بعد الزيادة والتقريب)
    $fprice = (float)list_price($fprod, $fplat);
    if ($fprice <= 0 && isset($fit['price']) && is_numeric($fit['price'])) {
        $fprice = (float)wf_price_uplift($fplat, (float)$fit['price']);
    }
    if ($fprice > 0) {
        $fit['price'] = ($fprice == (int)$fprice) ? (int)$fprice : $fprice;
    }
    // العملة: من المنتج نفسه (الجنيه = ج.م زي باقي الموقع)
    if (!isset($fit['cur']) || $fit['cur'] === '') {
        $ucur = trim((string)($fprod['currency'] ?? ''));
        if ($ucur === '' || $ucur === 'جنيه') $ucur = 'ج.م';
        $fit['cur'] = $ucur;
    }
    if (!isset($fit['cat'])) $fit['cat'] = '';
    $final[] = $fit;
}

echo json_encode([
    'ok' => true,
    'detected_colors' => array_slice($detected_ar_colors, 0, 3),
    'count' => count($final),
    'items' => $final,
], JSON_UNESCAPED_UNICODE);
