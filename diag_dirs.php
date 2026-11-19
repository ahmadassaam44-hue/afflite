<?php
// خريطة مجلدات data بالحجم وعدد الملفات — عشان نعرف فين المساحة بتتاكل
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'dr4v') { http_response_code(404); echo '{"ok":false}'; exit; }
$out = ['at' => date('c'), 'data' => [], 'assets' => [], 'root' => []];
foreach (['data', 'assets', '.'] as $base) {
    $root = $base === '.' ? __DIR__ : __DIR__ . '/' . $base;
    $map = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace(__DIR__ . '/', '', $f->getPathname());
        $dir = dirname($rel);
        if (!isset($map[$dir])) $map[$dir] = ['files' => 0, 'b' => 0];
        $map[$dir]['files']++; $map[$dir]['b'] += (int)$f->getSize();
    }
    $rows = [];
    foreach ($map as $d => $v) $rows[] = ['dir' => $d, 'files' => $v['files'], 'mb' => round($v['b'] / 1048576, 2)];
    usort($rows, function ($a, $b) { return $b['mb'] <=> $a['mb']; });
    $out[$base === '.' ? 'root' : $base] = array_slice($rows, 0, 14);
}
$tot = 0; $n = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)) as $f) {
    if (!$f->isFile() || strpos($f->getPathname(), '/.git/') !== false) continue;
    $tot += (int)$f->getSize(); $n++;
}
$out['site_total'] = ['files' => $n, 'mb' => round($tot / 1048576, 2)];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
