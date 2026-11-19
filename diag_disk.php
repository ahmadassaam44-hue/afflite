<?php
// تدقيق مساحة الاستضافة: أحجام المجلدات + ملفات ميتة (صور منتجات محذوفة) — قراءة فقط
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'dk3p') { http_response_code(404); echo '{"ok":false}'; exit; }

function dk_walk($dir, $limit = 400000) {
    $n = 0; $bytes = 0; $files = [];
    if (!is_dir($dir)) return [$n, $bytes, $files];
    $it = @new RecursiveIteratorIterator(@new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $n++; $s = (int)$f->getSize(); $bytes += $s;
        $files[] = [$f->getPathname(), $s];
        if ($n >= $limit) break;
    }
    return [$n, $bytes, $files];
}
$dirs = [
    'data', 'data/cezma_shards', 'data/zz_cezma_index', 'assets', 'assets/detail', 'assets/manual',
    'assets/detail/cezma', 'uploads', 'cache', 'tmp',
];
$out = ['at' => date('c'), 'dirs' => [], 'top_files' => [], 'orphans' => [], 'misc' => []];
$all = [];
foreach ($dirs as $d) {
    $p = __DIR__ . '/' . $d;
    if (!is_dir($p)) { $out['dirs'][$d] = null; continue; }
    [$n, $b, $files] = dk_walk($p);
    $out['dirs'][$d] = ['files' => $n, 'mb' => round($b / 1048576, 2)];
    if ($d === 'data' || $d === 'assets') foreach ($files as $f) $all[] = $f;
}
usort($all, function ($a, $b) { return $b[1] <=> $a[1]; });
foreach (array_slice($all, 0, 12) as $f) {
    $out['top_files'][] = ['p' => str_replace(__DIR__ . '/', '', $f[0]), 'mb' => round($f[1] / 1048576, 2)];
}

// المراجع الحالية من كل ملفات المنتجات
$refs = [];
$plats = ['vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'mnfean', 'g2g', 'social', 'shopmart'];
foreach ($plats as $plat) {
    foreach ([$plat . '.json', 'lite_' . $plat . '.json'] as $fn) {
        $rows = jload($fn, []);
        if (!is_array($rows)) continue;
        foreach ($rows as $it) {
            if (!is_array($it)) continue;
            $cand = [];
            foreach (['images', 'videos', 'files'] as $k) if (!empty($it[$k]) && is_array($it[$k])) $cand = array_merge($cand, $it[$k]);
            $ex = $it['extra'] ?? null;
            if (is_array($ex)) foreach (['detail_images', 'media_links'] as $k) if (!empty($ex[$k]) && is_array($ex[$k])) $cand = array_merge($cand, $ex[$k]);
            foreach ($cand as $u) {
                if (!is_string($u) || $u === '') continue;
                $clean = strtok($u, '?');
                $refs[basename($clean)] = 1;
            }
        }
    }
}
$out['misc']['catalog_refs'] = count($refs);

foreach (['assets/detail' => 'detail', 'assets/manual' => 'manual'] as $rel => $label) {
    $p = __DIR__ . '/' . $rel;
    if (!is_dir($p)) { $out['orphans'][$label] = null; continue; }
    $files = @scandir($p) ?: [];
    $live = 0; $dead = 0; $deadBytes = 0; $sample = [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..' || is_dir($p . '/' . $f)) continue;
        if (isset($refs[$f])) { $live++; continue; }
        $dead++; $deadBytes += (int)@filesize($p . '/' . $f);
        if (count($sample) < 5) $sample[] = $f;
    }
    $out['orphans'][$label] = ['live' => $live, 'orphan' => $dead, 'orphan_mb' => round($deadBytes / 1048576, 2), 'sample' => $sample];
}

// ملفات ميتة/مكررة في data
$junk = [];
foreach (glob(__DIR__ . '/data/*') ?: [] as $f) {
    $b = basename($f);
    if (preg_match('/\.(bak|old|tmp|zip|sql|log)$/i', $b) || preg_match('/^(lite_shopmart|shopmart)\.json$/', $b)
        || in_array($b, ['gamsgo_probe.json', 'gamsgo_probe.request', '_deploy_log.json', '_code_deploy.json', '_deploy_report.json', '_live_audit.json', '_site_probe.json', 'deploy_state.json'], true)) {
        $junk[] = ['p' => 'data/' . $b, 'mb' => round(((int)@filesize($f)) / 1048576, 3)];
    }
}
$out['misc']['junk'] = $junk;
$out['misc']['cezma_shards_list'] = array_slice(array_map('basename', glob(__DIR__ . '/data/cezma_shards/*') ?: []), 0, 8);
$out['misc']['zz_index_list'] = array_slice(array_map('basename', glob(__DIR__ . '/data/zz_cezma_index/*') ?: []), 0, 8);
$out['misc']['visual_cezma'] = array_map(function ($f) { return ['p' => basename($f), 'mb' => round(((int)@filesize($f)) / 1048576, 2)]; }, array_merge(glob(__DIR__ . '/data/visual_cezma*') ?: []));
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
