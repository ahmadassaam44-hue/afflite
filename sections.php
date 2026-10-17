<?php
// صفحة «أقسام الموقع»: منتجات (فيزيكال) / رقمي / سوشيال / صفحات — كل تبويب رابط بيفتح قسمه
$PUBLIC_PAGE = true;
require __DIR__ . '/dashboard/layout.php';

$tabs = wf_site_section_tabs('');
$want = (string)($_GET['tab'] ?? 'physical');
if (!isset($tabs[$want])) $want = 'physical';
$cur = $tabs[$want];

layout_top('أقسام الموقع', 'shop');
echo '<section class="sec-page" aria-label="أقسام الموقع">';
echo '<nav class="sec-tabs" aria-label="اختار قسم">';
foreach ($tabs as $k => $t) {
    $on = ($k === $want);
    echo '<a href="sections.php?tab=' . esc($k) . '"' . ($on ? ' class="on" aria-current="page"' : '') . '>' . esc($t['label']) . '</a>';
}
echo '</nav>';
echo '<h2 class="sec-title">' . esc($cur['title']) . '</h2>';
if ($want === 'pages') {
    echo '<ul class="sec-list">';
    foreach ($cur['links'] as $l) echo '<li><a href="' . esc($l[1]) . '">' . esc($l[0]) . '</a></li>';
    echo '</ul>';
} else {
    if (count($cur['collections']) > 1) echo '<p class="sec-sub">تجميعات</p>';
    echo '<ul class="sec-list">';
    foreach ($cur['collections'] as $l) echo '<li><a href="' . esc($l[1]) . '">' . esc($l[0]) . '</a></li>';
    echo '</ul>';
    if (!empty($cur['cats'])) {
        echo '<p class="sec-sub">الأقسام</p><ul class="sec-list">';
        foreach ($cur['cats'] as $l) echo '<li><a href="' . esc($l[1]) . '">' . esc($l[0]) . '</a></li>';
        echo '</ul>';
    }
}
echo '</section>';
layout_end();
