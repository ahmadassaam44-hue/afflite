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
$n = 0;
foreach (['vendoor', 'safqa', 'timehand', 'angazny', 'm5zoon', 'shopmart', 'cezma'] as $pl) {
    foreach (platform_products($pl, false) as $p) {
        if (empty($p['id']) || $n++ > 20000) continue;
        $x .= '<url><loc>' . htmlspecialchars($host . '/dashboard/product.php?id=' . rawurlencode((string)$p['id'])) . '</loc><changefreq>weekly</changefreq></url>';
    }
}
$x .= '</urlset>';
@file_put_contents($cache, $x);
echo $x;
