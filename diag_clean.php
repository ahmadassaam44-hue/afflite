<?php
// تنظيف الاستضافة: تقرير مجموعات + حذف البيانات الميتة (سيزما، أرشيفات الأحداث، بقايا منصات مقفولة، صور يتيمة)
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'cl9r') { http_response_code(404); echo '{"ok":false}'; exit; }
$GO = !empty($_GET['go']);
$D  = DATA_DIR;

function cg_files($pattern) { return glob($pattern) ?: []; }
function cg_mb($files) { $b = 0; foreach ($files as $f) $b += (int)@filesize($f); return round($b / 1048576, 2); }

// ---- مجموعات الملفات في data
$groups = [];
$all = array_values(array_filter(glob($D . '/*') ?: [], 'is_file'));
foreach ($all as $f) {
    $b = basename($f);
    $g = 'أخرى';
    if (strpos($b, 'wf_events_archive_') === 0) $g = 'أرشيف أحداث قديم';
    elseif (strpos($b, 'visual_') === 0) $g = 'فهرس الصور';
    elseif (strpos($b, 'price_history') === 0) $g = 'تاريخ الأسعار';
    elseif (preg_match('/^(lite_)?(vendoor|safqa|timehand|angazny|m5zoon|mnfean|g2g|social|shopmart)\.json$/', $b)) $g = 'كتالوج المنصات';
    elseif (preg_match('/^(lite_)?(cezma|storedooo)/', $b)) $g = 'سيزما';
    elseif (preg_match('/^_(deploy|code_deploy|live_audit|site_probe|admin_ok|safka_key)/', $b) || strpos($b, 'gamsgo_probe') === 0) $g = 'تقارير مؤقتة';
    elseif (preg_match('/\.(bak|old|tmp|zip|sql|log)$/i', $b)) $g = 'نسخ/لوجات';
    $groups[$g][] = $f;
}
$out = ['at' => date('c'), 'mode' => $GO ? 'delete' : 'dry-run', 'data_groups' => [], 'dirs' => []];
foreach ($groups as $g => $files) $out['data_groups'][$g] = ['files' => count($files), 'mb' => cg_mb($files)];

foreach (['data', 'data/cezma_shards', 'data/zz_cezma_index', 'assets', 'assets/detail'] as $rel) {
    $p = __DIR__ . '/' . $rel;
    if (!is_dir($p)) { $out['dirs'][$rel] = null; continue; }
    $n = 0; $b = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) continue; $n++; $b += (int)$f->getSize();
    }
    $out['dirs'][$rel] = ['files' => $n, 'mb' => round($b / 1048576, 2)];
}

// ---- قوائم الحذف
$del = [];
// 1) سيزما بالكامل (منتجاتها مش معروضة في المتجر؛ بيفضل بس فهرس بحث بيرجّع روابط ميتة)
foreach (glob($D . '/cezma_shards/*') ?: [] as $f) if (is_file($f)) $del['سيزما'][] = $f;
foreach (glob($D . '/zz_cezma_index/*') ?: [] as $f) if (is_file($f)) $del['فهرس سيزما'][] = $f;
foreach (glob($D . '/visual_cezma*') ?: [] as $f) if (is_file($f)) $del['فهرس سيزما'][] = $f;
foreach (glob($D . '/(lite_)?(cezma|storedooo)*.json', GLOB_BRACE) ?: [] as $f) if (is_file($f)) $del['سيزما'][] = $f;

// 1.b) باقي سيزما: باكتس البحث + كاش التفاصيل + الكاش العام
foreach (glob($D . '/cezma_buckets/*') ?: [] as $f) if (is_file($f)) $del['باكتس سيزما'][] = $f;
foreach (glob($D . '/cezma_details/*') ?: [] as $f) if (is_file($f)) $del['تفاصيل سيزما'][] = $f;
foreach (glob($D . '/cache/*') ?: [] as $f) if (is_file($f)) $del['كاش'][] = $f;

// 2) أرشيفات الأحداث: سيب آخر ملفين بس
$ev = cg_files($D . '/wf_events_archive_*.json');
sort($ev);
if (count($ev) > 2) foreach (array_slice($ev, 0, -2) as $f) $del['أرشيف أحداث'][] = $f;

// 3) بقايا منصات مقفولة (shopmart)
foreach (['shopmart.json', 'lite_shopmart.json'] as $b) if (is_file($D . '/' . $b)) $del['بقايا منصات'][] = $D . '/' . $b;

// 4) تقارير مؤقتة
foreach (['_deploy_log.json', '_deploy_report.json', '_live_audit.json', '_site_probe.json', '_admin_ok.json', 'gamsgo_probe.json', 'gamsgo_probe.request'] as $b) {
    if (is_file($D . '/' . $b)) $del['تقارير مؤقتة'][] = $D . '/' . $b;
}

// 5) صور المنتجات المحذوفة (assets/detail) — بنسيب _manifest.json
$refs = [];
foreach (['vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'mnfean', 'g2g', 'social'] as $plat) {
    foreach ([$plat . '.json', 'lite_' . $plat . '.json'] as $fn) {
        $rows = jload($fn, []);
        if (!is_array($rows)) continue;
        foreach ($rows as $it) {
            if (!is_array($it)) continue;
            $cand = [];
            foreach (['images', 'videos', 'files'] as $k) if (!empty($it[$k]) && is_array($it[$k])) $cand = array_merge($cand, $it[$k]);
            if (isset($it['extra']) && is_array($it['extra'])) foreach (['detail_images', 'media_links'] as $k) if (!empty($it['extra'][$k]) && is_array($it['extra'][$k])) $cand = array_merge($cand, $it['extra'][$k]);
            foreach ($cand as $u) if (is_string($u) && $u !== '') $refs[basename(strtok($u, '?'))] = 1;
        }
    }
}
foreach (glob(__DIR__ . '/assets/detail/*') ?: [] as $f) {
    if (!is_file($f)) continue;
    $b = basename($f);
    if ($b === '_manifest.json') continue;
    if (!isset($refs[$b])) $del['صور يتيمة'][] = $f;
}

$summary = [];
foreach ($del as $k => $files) $summary[$k] = ['files' => count($files), 'mb' => cg_mb($files)];
$out['to_delete'] = $summary;

if ($GO) {
    $freed = 0; $errors = []; $done = [];
    foreach ($del as $k => $files) {
        $cnt = 0;
        foreach ($files as $f) {
            $s = (int)@filesize($f);
            if (@unlink($f)) { $freed += $s; $cnt++; } else $errors[] = basename($f);
        }
        $done[$k] = $cnt;
    }
    $out['deleted'] = $done;
    $out['freed_mb'] = round($freed / 1048576, 2);
    $out['errors'] = array_slice($errors, 0, 5);
    $after = ['data' => 0, 'assets' => 0, 'files' => 0];
    foreach (['data', 'assets'] as $rel) {
        $p = __DIR__ . '/' . $rel;
        if (!is_dir($p)) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!$f->isFile()) continue;
            $after[$rel] += (int)$f->getSize(); $after['files']++;
        }
        $after[$rel] = round($after[$rel] / 1048576, 2);
    }
    $out['after'] = $after;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
