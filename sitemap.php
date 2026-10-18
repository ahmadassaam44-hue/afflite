<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/xml; charset=utf-8');
// استخدم النطاق الأساسي دائماً حتى لا تُنشأ نسخ sitemap مختلفة لكل نطاق/بيئة.
$host = 'https://wafirinhalk.com';
$cache = DATA_DIR . '/cache_sitemap.xml';
if (file_exists($cache) && filemtime($cache) > time() - 21600) { readfile($cache); exit; }
$x = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach (['/', '/affiliate-register.php', '/pages.php?p=faq', '/pages.php?p=privacy', '/pages.php?p=terms', '/pages.php?p=returns'] as $u) {
    $x .= '<url><loc>' . htmlspecialchars($host . $u, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc><changefreq>daily</changefreq></url>';
}
require_once __DIR__ . '/affiliate_programs.php';
foreach (array_keys(aff_programs()) as $program_key) {
    $u = '/affiliate-program.php?p=' . rawurlencode((string)$program_key);
    $x .= '<url><loc>' . htmlspecialchars($host . $u, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc><changefreq>monthly</changefreq></url>';
}
$items = [];
// حد أقصى للروابط حتى لا تتحول التوليد إلى استعراض كامل الكتالوج (~90 ألف منتج)
foreach (['vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart'] as $pl) {
    foreach (platform_products($pl, false) as $p) {
        if (empty($p['id'])) continue;
        $items[(string)$p['id']] = 1;
        if (count($items) >= 3000) break 2;
    }
}
foreach (cezma_recent_products(3000) as $p) {
    if (empty($p['id'])) continue;
    $items[(string)$p['id']] = 1;
}
foreach (array_keys($items) as $id) {
    $x .= '<url><loc>' . htmlspecialchars($host . '/dashboard/product.php?id=' . rawurlencode((string)$id)) . '</loc><changefreq>weekly</changefreq></url>';
}
$x .= '</urlset>';
@file_put_contents($cache, $x);
echo $x;
