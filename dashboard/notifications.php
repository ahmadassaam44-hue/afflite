<?php $PUBLIC_PAGE = true; require 'layout.php';
if (isset($_GET['read'])) {
    $n = jload('notifications.json', []);
    $me = current_user()['email'] ?? '';
    foreach ($n as &$x) if ($x['for'] === 'all' || $x['for'] === $me) $x['read'] = true;
    unset($x);
    jsave('notifications.json', $n);
    header('Location: notifications.php'); exit;
}
$me = current_user()['email'] ?? '';
$mine = array_reverse(my_notifications($me));
layout_top(' الإشعارات', 'home');
echo '<a class="btn" href="?read=1">تعليم الكل كمقروء</a><br><br><div class="card">';
if (!$mine) echo '<p class="mut">لا توجد إشعارات</p>';
foreach ($mine as $x) {
    echo '<article class="notification-row ' . (empty($x['read']) ? 'unread' : 'read') . '"><span class="badge ' . (empty($x['read']) ? 'blue' : 'neutral') . '">' . wf_svg_icon('bell',16) . (empty($x['read']) ? 'غير مقروء' : 'مقروء') . '</span><p>' . esc(wf_ui_plain($x['text'] ?? '')) . '</p><time class="mut" dir="ltr">' . esc(substr($x['at'] ?? '',0,16)) . '</time>';
    if (!empty($x['link'])) echo '<a class="btn sm outline" href="' . esc(strpos($x['link'], 'dashboard/') === 0 ? '../' . $x['link'] : $x['link']) . '">عرض التفاصيل</a>';
    echo '</article>';
}
echo '</div>';
layout_end();
