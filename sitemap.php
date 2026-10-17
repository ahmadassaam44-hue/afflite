<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/xml; charset=utf-8');
$host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$cache = DATA_DIR . '/cache_sitemap.xml';
if (file_exists($cache) && filemtime($cache) > time() - 21600) { readfile($cache); exit; }
$x = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach (['/', '/digital.php', '/social.php', '/pages.php?p=privacy', '/pages.php?p=terms', '/pages.php?p=returns'] as $u) {
    $x .= '<url><loc>' . htmlspecialchars($host . $u) . '</loc><changefreq>daily</changefreq></url>';
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
